<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use Lib\Common\System;

/**
 * Shared helper methods for tool CLI tests.
 */
trait ToolTestHelpers
{
    /** @var array<int,string> */
    protected array $toolTempPaths = [];

    // skipWhenRoot ends the test as skipped where a permission-based scenario cannot be built:
    // root reads and writes through mode 0000 files and unwritable directories.
    protected function skipWhenRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('permission scenarios need an unprivileged user');
        }
    }

    protected function repoPath(string $relative): string
    {
        $path = realpath(__DIR__ . '/../../../' . $relative);
        if ($path === false) {
            $this->fail('Unable to resolve repository path: ' . $relative);
        }
        return $path;
    }

    protected function mkTempDir(string $prefix = 'mcx-tool-'): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $path = tempnam($base, $prefix);
        if ($path === false) {
            $this->fail('Unable to allocate temporary directory name.');
        }
        @unlink($path);
        if (!mkdir($path, 0755, true)) {
            $this->fail('Unable to create temporary directory: ' . $path);
        }
        $this->toolTempPaths[] = $path;
        return $path;
    }

    protected function registerTempPath(string $path): string
    {
        $this->toolTempPaths[] = $path;
        return $path;
    }

    protected function cleanupToolPaths(): void
    {
        foreach (array_reverse($this->toolTempPaths) as $path) {
            if (is_dir($path)) {
                $this->rmTree($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        $this->toolTempPaths = [];
    }

    protected function rmTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        // Permission scenarios leave directories at mode 0000 or 0555, which cannot be listed or emptied
        // until they are restored; the iterator-based walk this replaces threw on them in tearDown.
        @chmod($path, 0755);
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $name;
            if (is_dir($child) && !is_link($child)) {
                $this->rmTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    /**
     * Child environment: the parent's real environment plus overrides, or null to inherit it unchanged.
     *
     * getenv() is used instead of $_ENV because php.ini-development and php.ini-production set
     * variables_order to "GPCS", which leaves $_ENV empty; an override-only environment would then lose
     * PATH and HOME and make every tool behave differently from one PHP configuration to the next.
     *
     * @param array<string,string> $env
     * @return array<string,string>|null
     */
    protected function childEnvironment(array $env): ?array
    {
        if ($env === []) {
            return null;
        }
        return array_merge(getenv(), $env);
    }

    /**
     * Run a PHP tool with optional environment overrides, capturing stdout/stderr.
     *
     * @param string $scriptRelative
     * @param array<int,string> $args
     * @param array<string,string> $env
     * @param array<int,string> $phpArgs Extra arguments for the PHP binary itself, e.g. ['-d', 'disable_functions=posix_geteuid'].
     * @return array{status:int,stdout:string,stderr:string}
     */
    protected function runTool(string $scriptRelative, array $args, array $env = [], array $phpArgs = []): array
    {
        $script = $this->repoPath($scriptRelative);
        $commandParts = array_merge([PHP_BINARY], $phpArgs, [$script], $args);
        $command = implode(' ', array_map('escapeshellarg', $commandParts));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = $this->childEnvironment($env);

        $process = proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            return ['status' => -1, 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $status = proc_close($process);

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr, 'output' => $stdout . $stderr];
    }

    /**
     * Execute inline PHP code with optional environment overrides.
     *
     * @param string $code
     * @param array<string,string> $env
     * @return array{status:int,stdout:string,stderr:string}
     */
    protected function runPhpCode(string $code, array $env = []): array
    {
        $commandParts = [PHP_BINARY, '-r', $code];
        $command = implode(' ', array_map('escapeshellarg', $commandParts));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = $this->childEnvironment($env);

        $process = proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            return ['status' => -1, 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $status = proc_close($process);

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr, 'output' => $stdout . $stderr];
    }

    protected function createFakeBinaryDir(): string
    {
        return $this->mkTempDir('mcx-bin-');
    }

    protected function writeExecutable(string $dir, string $name, string $content): string
    {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, "#!/bin/sh\n" . $content);
        chmod($path, 0755);
        return $path;
    }
}
