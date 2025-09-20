#!/usr/bin/env php
<?php
declare(strict_types=1);

// templateAssemble.php captures a live system into a staging directory.
// The script keeps sourcing, copying, and packaging responsibilities clear.

ini_set('display_errors', 'stderr');

require_once __DIR__ . '/lib/packageCommon.php';

// printUsage renders a short help message for operators running the tool.
function printUsage(): void
{
    // Use the invoked script name so wrappers stay in sync with the help text.
    $script = basename($_SERVER['argv'][0] ?? __FILE__);
    echo "Usage: {$script} --source-host <host> [options]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --source-host <host>   Hostname or IP for the SSH source.\n";
    echo "  --source-user <user>   SSH user. Defaults to root.\n";
    echo "  --output <file>        Target tar.gz file path.\n";
    echo "  --template <path>      Local templating engine directory.\n";
    echo "  --extra <path>         Extra local file or directory to embed.\n";
    echo "  --ssh-option <option>  Extra ssh(1) option forwarded to rsync.\n";
    echo "  --help                 Show this message and exit.\n";
}

// parseArguments converts CLI switches into a predictable config array.
function parseArguments(array $argv): array
{
    $config = [
        'sourceMode' => 'ssh',
        'sourceHost' => '',
        'sourceUser' => 'root',
        'outputPath' => '',
        'templatePath' => realpath(__DIR__ . '/../common') ?: '',
        'extras' => [],
        'sshOptions' => [],
    ];

    // Walk arguments sequentially to keep parsing simple and transparent.
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        switch ($arg) {
            case '--source-host':
                $config['sourceHost'] = $argv[++$i] ?? '';
                break;
            case '--source-user':
                $config['sourceUser'] = $argv[++$i] ?? 'root';
                break;
            case '--output':
                $config['outputPath'] = $argv[++$i] ?? '';
                break;
            case '--template':
                // Always resolve template path to an absolute directory.
                $config['templatePath'] = realpath($argv[++$i] ?? '') ?: '';
                break;
            case '--extra':
                $extraInput = $argv[++$i] ?? '';
                $extra = realpath($extraInput);
                if ($extra !== false) {
                    $config['extras'][] = $extra;
                } else {
                    fwrite(STDERR, "Skipping missing extra path: {$extraInput}\n");
                }
                break;
            case '--ssh-option':
                $option = $argv[++$i] ?? '';
                if ($option === '') {
                    fail('Missing value for --ssh-option.');
                }
                // Reuse the shared tokeniser so multi-part options survive.
                $tokens = tokenizeArgumentString($option);
                if (count($tokens) === 0) {
                    fail('Empty --ssh-option value provided.');
                }
                foreach ($tokens as $token) {
                    $config['sshOptions'][] = $token;
                }
                break;
            case '--help':
                printUsage();
                exit(0);
            default:
                fail("Unknown argument '{$arg}'.");
        }
    }

    if ($config['templatePath'] === '') {
        fail('Template path not found.');
    }

    return $config;
}

// createStagingDirectory prepares a temporary workspace under /tmp.
function createStagingDirectory(): string
{
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mcxTemplate-' . uniqid();
    if (!mkdir($path, 0755, true)) {
        fail('Unable to create staging directory.');
    }
    registerCleanup($path);
    return $path;
}

// registerCleanup ensures the staging directory is removed on exit.
function registerCleanup(string $path): void
{
    register_shutdown_function(static function () use ($path): void {
        removeDirectory($path);
    });
}

// removeDirectory recursively deletes the staging directory contents.
function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

// finalizeConfig enforces inputs and supplies defaults before work begins.
function finalizeConfig(array $config): array
{
    if ($config['sourceHost'] === '') {
        fail('Missing required --source-host value.');
    }

    if ($config['outputPath'] === '') {
        // Use a timestamped filename when the caller does not choose one.
        $config['outputPath'] = defaultOutputPath('template', $config['sourceHost']);
    }

    $outputDirectory = dirname($config['outputPath']);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true)) {
        fail('Unable to create output directory.');
    }

    // Validate toolchain binaries that the workflow depends on.
    ensureBinary('rsync');
    ensureBinary('tar');
    ensureBinary('pigz');

    if ($config['sourceMode'] === 'ssh') {
        ensureBinary('ssh');
    }

    return $config;
}

// fetchSource handles stage A by retrieving the rootfs from the source.
function fetchSource(array $config, string $stagingDir): void
{
    $target = $stagingDir . DIRECTORY_SEPARATOR . 'rootfs';
    if (!mkdir($target, 0755) && !is_dir($target)) {
        fail('Unable to create rootfs staging directory.');
    }

    // Stage A is fulfilled by rsync copying the remote root into rootfs/.
    $command = buildRsyncCommand($config, $target);
    runCommand($command);
}

// buildRsyncCommand assembles the rsync statement with exclusions.
function buildRsyncCommand(array $config, string $target): string
{
    $parts = ['rsync', '-aAXH', '--numeric-ids'];

    foreach (PACKAGE_EXCLUDES as $pattern) {
        $parts[] = '--exclude=' . escapeshellarg($pattern);
    }

    // Remote root is transferred via SSH using rsync's remote shell syntax.
    // Remote shell collects ssh options consistently with clone utilities.
    $parts[] = '-e ' . escapeshellarg(buildRsyncRemoteShell($config['sshOptions']));
    $remote = sprintf('%s@%s:/', $config['sourceUser'], $config['sourceHost']);
    $parts[] = escapeshellarg($remote);
    // Trailing slash keeps rsync copying into the prepared rootfs folder.
    $parts[] = escapeshellarg($target . DIRECTORY_SEPARATOR);

    return implode(' ', $parts);
}

// buildRsyncRemoteShell assembles an ssh command with forwarded options.
function buildRsyncRemoteShell(array $sshOptions): string
{
    $shell = 'ssh';
    foreach ($sshOptions as $option) {
        $shell .= ' ' . escapeshellarg($option);
    }
    return $shell;
}

// runCommand executes an external command and stops on failure.
function runCommand(string $command): void
{
    passthru($command, $status);
    if ($status !== 0) {
        fail("Command failed: {$command}");
    }
}

// copyTemplatingEngine mirrors the templating engine into staging.
function copyTemplatingEngine(array $config, string $stagingDir): void
{
    $destination = $stagingDir . DIRECTORY_SEPARATOR . 'template';
    if (!mkdir($destination, 0755) && !is_dir($destination)) {
        fail('Unable to create template staging directory.');
    }

    copyLocalPath($config['templatePath'], $destination);
}

// copyLocalPath reuses rsync for consistent attribute handling.
function copyLocalPath(string $source, string $destination): void
{
    $cleanSource = rtrim($source, DIRECTORY_SEPARATOR);
    // rsync preserves permissions, ACLs, xattrs, and hard links for parity.
    $command = sprintf('rsync -aAXH %s %s', escapeshellarg($cleanSource), escapeshellarg($destination));
    runCommand($command);
}

// copyExtras brings optional files under the extras directory.
function copyExtras(array $config, string $stagingDir): void
{
    if (count($config['extras']) === 0) {
        return;
    }

    $destination = $stagingDir . DIRECTORY_SEPARATOR . 'extras';
    if (!mkdir($destination, 0755) && !is_dir($destination)) {
        fail('Unable to create extras staging directory.');
    }

    // Copy each validated extra path into the extras staging area.
    foreach ($config['extras'] as $extra) {
        copyLocalPath($extra, $destination);
    }
}

// createArchive runs stage C by building the pigz compressed tarball.
function createArchive(array $config, string $stagingDir): void
{
    $output = $config['outputPath'];
    // Stage C compresses the staging directory into the requested archive.
    $command = sprintf(
        'tar --numeric-owner --xattrs --acls -I pigz -cf %s -C %s .',
        escapeshellarg($output),
        escapeshellarg($stagingDir)
    );

    runCommand($command);
    echo "Archive created at {$output}\n";
}

// main orchestrates the sourcing, preparation, and packaging layers.
function main(array $argv): void
{
    $config = parseArguments($argv);
    $config = finalizeConfig($config);
    $stagingDir = createStagingDirectory();
    fetchSource($config, $stagingDir);
    copyTemplatingEngine($config, $stagingDir);
    copyExtras($config, $stagingDir);
    createArchive($config, $stagingDir);
}

main($argv);
