#!/usr/bin/env php
<?php
declare(strict_types=1);

// templateRehydrate.php
// Standalone CLI that produces a rehydrated archive (rootfs/ + provisioning engine)
// from an existing rootfs tarball. Uses the shared rehydration library so the
// behaviour matches templateAssemble.php and templateCloneRemote.php --rehydrate.

ini_set('display_errors', 'stderr');

require_once __DIR__ . '/lib/packageCommon.php';
require_once __DIR__ . '/lib/rehydration.php';

/**
 * Print usage information for the CLI.
 *
 * @return void
 */
function printUsage(): void
{
    $script = basename($_SERVER['argv'][0] ?? __FILE__);
    echo "Usage: {$script} --rootfs <rootfs.tar.gz> [--output <file|->] [--template <path>] [--embed-into-rootfs|--no-embed-into-rootfs] [--embed-force]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --rootfs <path>          Path to a gzip-compressed tar archive of a root filesystem.\n";
    echo "  --output <file|->        Destination tar.gz path or '-' for STDOUT.\n";
    echo "  --template <path>        Engine directory to embed (defaults to repository root).\n";
    echo "  --embed-into-rootfs      Embed engine into rootfs/opt/mcxTemplate (default).\n";
    echo "  --no-embed-into-rootfs   Place engine under template/ instead of rootfs.\n";
    echo "  --embed-force            Overwrite existing rootfs/opt/mcxTemplate if present.\n";
    echo "  --help                   Show this help message.\n";
}

/**
 * Parse CLI arguments into a config array.
 *
 * @param array $argv Raw argv array.
 * @return array{rootfs:string,output:string,templatePath:string,embedIntoRootfs:bool,embedForce:bool}
 */
function parseArguments(array $argv): array
{
    $config = [
        'rootfs' => '',
        'output' => '',
        'templatePath' => realpath(dirname(__DIR__)) ?: '',
        'embedIntoRootfs' => true,
        'embedForce' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        switch ($arg) {
            case '--rootfs':
                $config['rootfs'] = $argv[++$i] ?? '';
                break;
            case '--output':
                $config['output'] = $argv[++$i] ?? '';
                break;
            case '--template':
                $config['templatePath'] = realpath($argv[++$i] ?? '') ?: '';
                break;
            case '--embed-into-rootfs':
                $config['embedIntoRootfs'] = true;
                break;
            case '--no-embed-into-rootfs':
                $config['embedIntoRootfs'] = false;
                break;
            case '--embed-force':
                $config['embedForce'] = true;
                break;
            case '--help':
                printUsage();
                exit(0);
            default:
                if (substr($arg, 0, 2) === '--') {
                    fail("Unknown argument '{$arg}'.");
                }
        }
    }

    if ($config['rootfs'] === '') {
        fail('Missing required --rootfs <path> argument.');
    }

    if ($config['output'] === '') {
        // Default to a timestamped path in the current directory.
        $config['output'] = defaultOutputPath('template', 'rehydrated');
    }

    if ($config['templatePath'] === '') {
        fail('Template path not found.');
    }

    return $config;
}

/**
 * Main entrypoint: validates inputs and runs rehydration.
 *
 * @param array $argv Raw argv array.
 * @return void
 */
function main(array $argv): void
{
    ensureBinary('tar');
    ensureBinary('pigz');

    $config = parseArguments($argv);

    // Stream to STDOUT when requested, else write to file.
    if ($config['output'] === '-' || strtolower($config['output']) === 'stdout') {
        rehydrate_archive($config['rootfs'], $config['templatePath'], 'php://stdout', $config['embedIntoRootfs'], $config['embedForce']);
        fwrite(STDERR, "Rehydrated archive stream completed (STDOUT).\n");
        return;
    }

    $outDir = dirname($config['output']);
    if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
        fail('Unable to create output directory: ' . $outDir);
    }

    rehydrate_archive($config['rootfs'], $config['templatePath'], $config['output'], $config['embedIntoRootfs'], $config['embedForce']);
    echo 'Rehydrated archive created at ' . $config['output'] . "\n";
}

main($argv);

