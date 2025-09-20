#!/usr/bin/env php
<?php
declare(strict_types=1);

// templateCloneRemote.php streams a live system archive over SSH for templates.
// The flow mirrors cloneLiveSystem.sh but keeps orchestration inside PHP.

require_once __DIR__ . '/lib/packageCommon.php';

// printUsage describes the positional arguments and optional SSH flags.
function printUsage(): void
{
    $script = basename(__FILE__);
    echo "Usage: {$script} <user@host> <output|-> [--ssh-option <option>]... [--force]\\n";
    echo "\n";
    echo "Arguments:\n";
    echo "  <user@host>   Remote SSH endpoint providing the root filesystem.\n";
    echo "  <output|->    Destination path or '-' to stream to STDOUT.\n";
    echo "\n";
    echo "Options:\n";
    echo "  --ssh-option <option>  Extra ssh(1) option, may repeat.\n";
    echo "  --force               Overwrite an existing output file.\n";
    echo "  --help                 Show this help message.\n";
}

// parseArguments collects positionals and optional SSH flags into config.
function parseArguments(array $argv): array
{
    $config = [
        'remote' => '',
        'output' => '',
        'sshOptions' => [],
        'force' => false,
    ];

    $positionals = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--ssh-option') {
            $option = $argv[++$i] ?? '';
            if ($option === '') {
                fail('Missing value for --ssh-option.');
            }
            // Tokenise values so combined flags like "-p 2222" remain valid.
            $tokens = tokenizeArgumentString($option);
            if (count($tokens) === 0) {
                fail('Empty --ssh-option value provided.');
            }
            foreach ($tokens as $token) {
                $config['sshOptions'][] = $token;
            }
            continue;
        }

        // Allow forced overwrite when rerunning against the same target.
        if ($arg === '--force') {
            $config['force'] = true;
            continue;
        }

        if ($arg === '--help') {
            printUsage();
            exit(0);
        }

        if (substr($arg, 0, 2) === '--') {
            fail("Unknown argument '{$arg}'.");
        }

        $positionals[] = $arg;
    }

    if (count($positionals) < 2) {
        printUsage();
        fail('Source and output arguments are required.');
    }

    $config['remote'] = $positionals[0];
    $config['output'] = $positionals[1];

    return $config;
}

// buildRemoteScript assembles the compression pipeline executed remotely.
function buildRemoteScript(): string
{
    $tarCommand = tarBaseCommandPrefix() . ' -cf - /';

    $lines = [
        'set -euo pipefail',
        'if command -v pigz >/dev/null 2>&1; then',
        "    COMPRESSOR='pigz -9'",
        'else',
        "    COMPRESSOR='gzip -9'",
        'fi',
        $tarCommand . " | \$COMPRESSOR",
    ];

    return implode("\n", $lines) . "\n";
}

// buildSshCommand prepares the ssh invocation with optional arguments.
function buildSshCommand(string $remote, array $options): string
{
    $parts = ['ssh'];
    // Options arrive pre-tokenised so they can include flags and values.
    foreach ($options as $option) {
        $parts[] = $option;
    }
    $parts[] = $remote;
    $parts[] = 'bash -s';

    $escaped = [];
    foreach ($parts as $part) {
        $escaped[] = escapeshellarg($part);
    }

    return implode(' ', $escaped);
}

// resolveOutput decides whether we stream to STDOUT or a named file.
function resolveOutput(string $output, bool $force): array
{
    if ($output === '-' || strtolower($output) === 'stdout') {
        return [
            'target' => 'php://stdout',
            'label' => 'STDOUT',
            'isStdout' => true,
        ];
    }

    if (strpos($output, 'php://') === 0) {
        return [
            'target' => $output,
            'label' => $output,
            'isStdout' => false,
        ];
    }

    $directory = dirname($output);
    ensureDirectory($directory);

    // Guard against silent overwrites unless the operator opts in.
    if (file_exists($output) && !$force) {
        fail("Output file already exists: {$output}. Use --force to overwrite.");
    }

    return [
        'target' => $output,
        'label' => $output,
        'isStdout' => false,
    ];
}

// streamArchive pipes the remote tarball into the resolved destination.
function streamArchive(array $config): array
{
    $destination = resolveOutput($config['output'], $config['force']);
    $command = buildSshCommand($config['remote'], $config['sshOptions']);
    $remoteScript = buildRemoteScript();

    $descriptors = [
        0 => ['pipe', 'w'],
        1 => ['file', $destination['target'], 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        fail('Unable to start ssh process.');
    }

    fwrite($pipes[0], $remoteScript);
    fclose($pipes[0]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail("Streaming failed with exit code {$exitCode}.");
    }

    return $destination;
}

// main validates requirements, performs streaming, and reports status.
function main(array $argv): void
{
    ensureBinary('ssh');

    $config = parseArguments($argv);
    $destination = streamArchive($config);

    $message = 'Archive stream completed';
    if ($destination['isStdout']) {
        fwrite(STDERR, $message . " (STDOUT).\n");
        return;
    }

    echo $message . ' at ' . $destination['label'] . "\n";
}

// Execute only when the script is run directly.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    main($argv);
}
