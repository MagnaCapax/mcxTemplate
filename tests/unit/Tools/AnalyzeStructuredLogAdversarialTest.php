<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;

// Covers analyze-structured-log.php CLI with invalid paths, formats, and outputs.
final class AnalyzeStructuredLogAdversarialTest extends TestCase
{
    use ToolTestHelpers;

    /** @var array<int,string> */
    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paths = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_dir($path)) {
                $this->rmTree($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * @dataProvider provideAdversarialCases
     * @param array<int,string> $args
     */
    public function testAdversarialScenarios(string $description, array $args, ?callable $setup = null): void
    {
        // Expect CLI to fail gracefully for malformed inputs or output targets.
        if ($setup !== null) {
            $args = $setup($args);
        }

        $script = $this->repoPath('tools/analyze-structured-log.php');
        $command = array_merge([PHP_BINARY, $script], $args);
        $output = System::capture($command, $status);
        $this->assertNotSame(0, $status, $description . ' expected non-zero exit');
    }

    /**
     * @return array<string,array{string,array<int,string>,callable|null}>
     */
    public function provideAdversarialCases(): array
    {
        return [
            'no arguments' => ['no arguments', [], null],
            'empty input option' => ['empty input option', ['--input='], null],
            'input path missing' => ['input path missing', ['--input=/tmp/not-present.log'], null],
            'input path directory' => ['input path directory', ['--input=' . sys_get_temp_dir()], null],
            'input unreadable file' => ['input unreadable file', ['--input=__UNREADABLE__'], function (array $args): array {
                $file = $this->createLogFile();
                chmod($file, 0000);
                $args[0] = '--input=' . $file;
                return $args;
            }],
            'invalid format xml' => ['invalid format xml', ['--input=__VALID__', '--format=xml'], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'invalid format empty string' => ['invalid format empty string', ['--input=__VALID__', '--format='], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'invalid format whitespace' => ['invalid format whitespace', ['--input=__VALID__', '--format=   '], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'output unwritable path' => ['output unwritable path', ['--input=__VALID__', '--output=/root/out.json'], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'output directory path' => ['output directory path', ['--input=__VALID__', '--output=' . sys_get_temp_dir()], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'output nested unwritable path' => ['output nested unwritable path', ['--input=__VALID__', '--output=/root/mcx/out.json'], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'table format with unwritable output' => ['table format with unwritable output', ['--input=__VALID__', '--format=table', '--output=/root/out.txt'], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'input removed before read' => ['input removed before read', ['--input=__VALID__'], function (array $args): array {
                $file = $this->createLogFile();
                unlink($file);
                $args[0] = '--input=' . $file;
                return $args;
            }],
            'input symlink to missing target' => ['input symlink to missing target', ['--input=__VALID__'], function (array $args): array {
                $target = $this->tmpDir() . '/missing.log';
                $link = $this->tmpDir() . '/link.log';
                @symlink($target, $link);
                $args[0] = '--input=' . $link;
                return $args;
            }],
            'input php stream not file' => ['input php stream not file', ['--input=php://memory'], null],
            'format combined invalid spec' => ['format combined invalid spec', ['--input=__VALID__', '--format=json,table'], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'output path is existing directory file' => ['output path existing file', ['--input=__VALID__', '--output=__EXISTING_FILE__'], function (array $args): array {
                $input = $this->createLogFile();
                $out = tempnam(sys_get_temp_dir(), 'asl-');
                $this->paths[] = $out;
                $args[0] = '--input=' . $input;
                $args[1] = '--output=' . $out;
                return $args;
            }],
            'table format with directory output' => ['table format with directory output', ['--input=__VALID__', '--format=table', '--output=' . sys_get_temp_dir()], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'format invalid uppercase' => ['format invalid uppercase', ['--input=__VALID__', '--format=JSON'], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'input unreadable directory symlink' => ['input unreadable directory symlink', ['--input=__VALID__'], function (array $args): array {
                $dir = $this->tmpDir();
                $target = $dir . '/dir';
                mkdir($target, 0000, true);
                $link = $dir . '/link';
                @symlink($target, $link);
                $args[0] = '--input=' . $link;
                return $args;
            }],
            'output path invalid scheme' => ['output path invalid scheme', ['--input=__VALID__', '--output=ftp://server/result'], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
            'input file with invalid json lines' => ['input file with invalid json lines', ['--input=__VALID__'], function (array $args): array {
                $file = $this->createLogFile();
                file_put_contents($file, "{invalid json}\n", FILE_APPEND);
                $args[0] = '--input=' . $file;
                return $args;
            }],
            'table format with unwritable nested output' => ['table format with unwritable nested output', ['--input=__VALID__', '--format=table', '--output=/root/mcx/summary.txt'], function (array $args): array {
                $args[0] = '--input=' . $this->createLogFile();
                return $args;
            }],
        ];
    }

    private function createLogFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'asl-log-');
        if ($file === false) {
            $this->fail('Unable to create temporary log file.');
        }
        $this->paths[] = $file;
        file_put_contents($file, json_encode(['event' => 'start']) . PHP_EOL);
        return $file;
    }

    private function tmpDir(): string
    {
        $dir = $this->mkTempDir('asl-');
        $this->paths[] = $dir;
        return $dir;
    }
}
