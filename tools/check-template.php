#!/usr/bin/env php
<?php
declare(strict_types=1);

ini_set('display_errors', 'stderr');

const GREEN = "\033[32m";
const RED = "\033[31m";
const YELLOW = "\033[33m";
const RESET = "\033[0m";

function printUsage(): void
{
    $script = basename($_SERVER['argv'][0] ?? __FILE__);
    echo "Usage: {$script} --path=/path/to/template [--tar|--dir]" . PHP_EOL . PHP_EOL;
    echo "  --path   Path to a template tarball or extracted directory." . PHP_EOL;
    echo "  --tar    Force tarball inspection (auto-detected by default)." . PHP_EOL;
    echo "  --dir    Force directory inspection." . PHP_EOL;
}

function parseArguments(array $argv): array
{
    $path = '';
    $mode = 'auto';

    foreach ($argv as $arg) {
        if (strncmp($arg, '--path=', 7) === 0) {
            $path = substr($arg, 7);
            continue;
        }
        if ($arg === '--tar') {
            $mode = 'tar';
            continue;
        }
        if ($arg === '--dir') {
            $mode = 'dir';
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            printUsage();
            exit(0);
        }
    }

    if ($path === '') {
        fwrite(STDERR, "--path is required.\n");
        printUsage();
        exit(1);
    }

    return ['path' => $path, 'mode' => $mode];
}

function loadDirectoryEntries(string $root): array
{
    if (!is_dir($root)) {
        throw new RuntimeException("Directory not found: {$root}");
    }

    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
        $entries[] = str_replace('\\', '/', $relative);
    }

    return $entries;
}

function loadTarEntries(string $tarPath): array
{
    if (!is_file($tarPath)) {
        throw new RuntimeException("Tarball not found: {$tarPath}");
    }

    $command = sprintf('tar -tzf %s', escapeshellarg($tarPath));
    exec($command, $lines, $status);
    if ($status !== 0) {
        throw new RuntimeException('Failed to list tarball contents; ensure it is a gzip-compressed tar archive.');
    }

    return array_map(static function (string $entry): string {
        $trimmed = rtrim($entry, '/');
        $trimmed = preg_replace('#^\.\/+#', '', $trimmed);
        return $trimmed === null ? $entry : $trimmed;
    }, $lines);
}

function checkTemplate(array $entries): array
{
    $requirements = [];
    $requirements['distros/configure.php'] = in_array('distros/configure.php', $entries, true);

    $distroDirs = [];
    foreach ($entries as $entry) {
        if (preg_match('#^distros/([^/]+)/common/tasks(?:/|$)#', $entry, $match)) {
            $distroDirs[$match[1]] = true;
        }
    }

    foreach ($distroDirs as $distro => $_) {
        $requirements["distros/{$distro}/templates"] = hasDirectory($entries, "distros/{$distro}/templates");
        $requirements["distros/{$distro}/common/tasks"] = hasDirectory($entries, "distros/{$distro}/common/tasks");
    }

    $requirements['distros/common/templates'] = hasDirectory($entries, 'distros/common/templates');

    return $requirements;
}

function hasDirectory(array $entries, string $prefix): bool
{
    $prefix = rtrim($prefix, '/');
    foreach ($entries as $entry) {
        if (strpos($entry, $prefix . '/') === 0 || $entry === $prefix) {
            return true;
        }
    }
    return false;
}

function printReport(array $requirements): void
{
    echo "Template audit results:" . PHP_EOL;
    foreach ($requirements as $label => $present) {
        $color = $present ? GREEN : RED;
        $status = $present ? 'OK' : 'MISSING';
        echo sprintf("  %s%-8s%s %s%s%s\n",
            $color,
            $status,
            RESET,
            $present ? '' : YELLOW,
            $label,
            RESET
        );
    }
}

function main(array $argv): void
{
    $parsed = parseArguments($argv);
    $path = $parsed['path'];

    try {
        $entries = matchModeAndLoad($path, $parsed['mode']);
    } catch (RuntimeException $e) {
        fwrite(STDERR, RED . 'Error: ' . $e->getMessage() . RESET . PHP_EOL);
        exit(1);
    }

    $requirements = checkTemplate($entries);
    printReport($requirements);

    if (in_array(false, $requirements, true)) {
        exit(2);
    }
}

function matchModeAndLoad(string $path, string $mode): array
{
    if ($mode === 'dir') {
        return loadDirectoryEntries($path);
    }

    if ($mode === 'tar') {
        return loadTarEntries($path);
    }

    if (is_dir($path)) {
        return loadDirectoryEntries($path);
    }

    if (is_file($path)) {
        return loadTarEntries($path);
    }

    throw new RuntimeException('Path not found: ' . $path);
}

main(array_slice($argv, 1));
