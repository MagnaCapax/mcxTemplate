#!/usr/bin/env php
<?php
declare(strict_types=1);

// templateCloneImage.php clones a local disk image into a template archive.
// The implementation matches the Bash tooling flow while staying maintainable.

require_once __DIR__ . '/lib/packageCommon.php';

// Shared state tracks mounted resources for final cleanup.
$IMAGE_STATE = [
    'device' => '',
    'type' => '',
    'mountDir' => '',
];

// Register cleanup immediately so unexpected exits free resources.
register_shutdown_function(static function () use (&$IMAGE_STATE): void {
    performCleanup($IMAGE_STATE);
});

// printUsage summarises supported flags for the operator.
function printUsage(): void
{
    $script = basename(__FILE__);
    echo "Usage: {$script} --image <path> [--output <file>] [--partition <num>]\\n";
    echo "\n";
    echo "Options:\n";
    echo "  --image <path>       Local raw or qcow2 image file.\n";
    echo "  --output <file>      Destination archive path.\n";
    echo "  --partition <value>  Partition number or device path.\n";
    echo "  --help               Show this message.\n";
}

// parseArguments converts argv entries into a structured config array.
function parseArguments(array $argv): array
{
    $config = [
        'image' => '',
        'output' => '',
        'partition' => '',
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        switch ($arg) {
            case '--image':
                $config['image'] = $argv[++$i] ?? '';
                break;
            case '--output':
                $config['output'] = $argv[++$i] ?? '';
                break;
            case '--partition':
                $config['partition'] = $argv[++$i] ?? '';
                break;
            case '--help':
                printUsage();
                exit(0);
            default:
                fail("Unknown argument '{$arg}'.");
        }
    }

    if ($config['image'] === '') {
        printUsage();
        fail('Missing required --image value.');
    }

    if (!is_file($config['image'])) {
        fail("Image file not found: {$config['image']}");
    }

    if ($config['output'] === '') {
        $config['output'] = defaultOutputPath('image', basename($config['image']));
    }

    return $config;
}

// requireRoot ensures the process has privileges to attach loop devices.
function requireRoot(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fail('Root privileges are required.');
    }
}

// runCommand wraps exec() while providing uniform error handling.
function runCommand(string $command, bool $allowFailure = false): array
{
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0 && !$allowFailure) {
        fail("Command failed ({$command}) with exit code {$status}.");
    }
    return ['status' => $status, 'output' => $output];
}

// detectImageType uses qemu-img to decide between raw and qcow2.
function detectImageType(string $image): string
{
    $result = runCommand('qemu-img info ' . escapeshellarg($image));
    $info = strtolower(implode("\n", $result['output']));
    return strpos($info, 'file format: qcow2') !== false ? 'qcow2' : 'raw';
}

// attachRaw uses losetup --partscan to expose raw partitions.
function attachRaw(string $image, array &$state): string
{
    $command = 'losetup --find --show --partscan --read-only ' . escapeshellarg($image);
    $result = runCommand($command);
    $device = trim($result['output'][0] ?? '');
    if ($device === '') {
        fail('losetup did not return a device path.');
    }
    if (!waitForDeviceReadiness($device)) {
        runCommand('losetup -d ' . escapeshellarg($device), true);
        fail('Timed out waiting for partitions on attached loop device.');
    }
    $state['device'] = $device;
    $state['type'] = 'raw';
    return $device;
}

// attachQcow2 connects the qcow2 image through qemu-nbd.
function attachQcow2(string $image, array &$state): string
{
    runCommand('modprobe nbd max_part=16', true);
    $candidates = glob('/dev/nbd*');
    if ($candidates === false) {
        fail('Unable to enumerate nbd devices.');
    }

    foreach ($candidates as $device) {
        if (!preg_match('/^\/dev\/nbd[0-9]+$/', $device)) {
            continue;
        }
        $command = 'qemu-nbd --connect=' . escapeshellarg($device) . ' --read-only ' . escapeshellarg($image);
        $result = runCommand($command, true);
        if ($result['status'] === 0) {
            $state['device'] = $device;
            $state['type'] = 'qcow2';
            if (!waitForDeviceReadiness($device)) {
                runCommand('qemu-nbd --disconnect ' . escapeshellarg($device), true);
                $state['device'] = '';
                $state['type'] = '';
                continue;
            }
            return $device;
        }
    }

    fail('Unable to allocate an nbd device.');
}

// waitForDeviceReadiness pauses until partitions or filesystems appear.
function waitForDeviceReadiness(string $device, int $timeoutSeconds = 10): bool
{
    $deadline = time() + $timeoutSeconds;
    $baseName = basename($device);

    while (time() <= $deadline) {
        $result = runCommand('lsblk -ln -o NAME,TYPE,FSTYPE ' . escapeshellarg($device), true);
        if ($result['status'] === 0) {
            foreach ($result['output'] as $line) {
                $parts = preg_split('/\s+/', trim($line));
                $name = $parts[0] ?? '';
                $type = $parts[1] ?? '';
                $fstype = $parts[2] ?? '';
                if ($type === 'part' && $fstype !== '') {
                    return true;
                }
                if ($name === $baseName && $fstype !== '') {
                    return true;
                }
            }
        }

        // Sleep briefly so udev can create any pending device nodes.
        usleep(200000);
    }

    return false;
}

// selectPartition picks the partition path either manually or automatically.
function selectPartition(string $baseDevice, string $override): string
{
    if ($override !== '') {
        if ($override[0] === '/') {
            $candidate = $override;
        } else {
            $candidate = $baseDevice . 'p' . $override;
        }
        if (!is_readable($candidate)) {
            fail("Specified partition not found: {$candidate}");
        }
        return $candidate;
    }

    $command = 'lsblk -ln -o NAME,TYPE,FSTYPE ' . escapeshellarg($baseDevice);
    $result = runCommand($command);

    $baseName = preg_replace('/^\\/dev\\//', '', $baseDevice);
    $baseCandidate = '';

    foreach ($result['output'] as $line) {
        $parts = preg_split('/\s+/', trim($line));
        $name = $parts[0] ?? '';
        $type = $parts[1] ?? '';
        $fstype = $parts[2] ?? '';
        if ($type === 'part' && $fstype !== '') {
            return '/dev/' . $name;
        }
        // Track the base device when it exposes a filesystem directly.
        if ($name === $baseName && $fstype !== '') {
            $baseCandidate = '/dev/' . $name;
        }
    }

    if ($baseCandidate !== '') {
        return $baseCandidate;
    }

    fail('Could not auto-detect a mountable partition.');
}

// mountPartition mounts the partition read-only and records the directory.
function mountPartition(string $partition, array &$state): string
{
    $mountDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcx-image-' . uniqid();
    ensureDirectory($mountDir);
    runCommand('mount -o ro ' . escapeshellarg($partition) . ' ' . escapeshellarg($mountDir));
    $state['mountDir'] = $mountDir;
    return $mountDir;
}

// determineCompressor selects pigz when available and falls back to gzip.
function determineCompressor(): string
{
    if (commandExists('pigz')) {
        return 'pigz -9';
    }
    ensureBinary('gzip');
    fwrite(STDERR, "pigz not found locally, falling back to gzip.\n");
    return 'gzip -9';
}

// createArchive compresses the mounted root filesystem into a tarball.
function createArchive(string $mountDir, string $output, string $compressor): void
{
    ensureDirectory(dirname($output));
    $command = tarBaseCommandPrefix() .
        ' -I ' . escapeshellarg($compressor) .
        ' -cf ' . escapeshellarg($output) .
        ' -C ' . escapeshellarg($mountDir) . ' .';

    runCommand($command);
}

// performCleanup unmounts and detaches resources registered in state.
function performCleanup(array &$state): void
{
    if ($state['mountDir'] !== '' && is_dir($state['mountDir'])) {
        if (isMounted($state['mountDir'])) {
            runCommand('umount ' . escapeshellarg($state['mountDir']), true);
        }
        @rmdir($state['mountDir']);
        $state['mountDir'] = '';
    }

    if ($state['device'] !== '') {
        if ($state['type'] === 'raw') {
            runCommand('losetup -d ' . escapeshellarg($state['device']), true);
        } elseif ($state['type'] === 'qcow2') {
            runCommand('qemu-nbd --disconnect ' . escapeshellarg($state['device']), true);
        }
        $state['device'] = '';
        $state['type'] = '';
    }
}

// isMounted checks /proc/mounts to confirm a mountpoint is active.
function isMounted(string $path): bool
{
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($mounts === false) {
        return false;
    }
    foreach ($mounts as $entry) {
        $parts = preg_split('/\s+/', trim($entry));
        if (($parts[1] ?? '') === $path) {
            return true;
        }
    }
    return false;
}

// main executes the detection, mounting, and archiving workflow.
function main(array $argv): void
{
    global $IMAGE_STATE;

    requireRoot();
    ensureBinary('qemu-img');
    ensureBinary('tar');
    ensureBinary('mount');
    ensureBinary('losetup');
    ensureBinary('lsblk');

    $config = parseArguments($argv);
    $type = detectImageType($config['image']);

    if ($type === 'qcow2') {
        ensureBinary('qemu-nbd');
        $device = attachQcow2($config['image'], $IMAGE_STATE);
    } else {
        $device = attachRaw($config['image'], $IMAGE_STATE);
    }

    $partition = selectPartition($device, $config['partition']);
    $mountDir = mountPartition($partition, $IMAGE_STATE);

    $compressor = determineCompressor();
    createArchive($mountDir, $config['output'], $compressor);

    echo "Archive stored at {$config['output']}\n";
}

// Execute only when run directly from the command line.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    main($argv);
}
