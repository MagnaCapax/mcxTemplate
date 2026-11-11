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
     * Data providers run on a bare instance without setUp(), so every scenario gets the running test
     * instance as its first argument instead of capturing $this (PHPUnit 10+ also requires static providers).
     *
     * @dataProvider provideAdversarialCases
     * @param array<int,string> $args
     */
    public function testAdversarialScenarios(string $description, array $args, ?callable $setup = null): void
    {
        // Expect CLI to fail gracefully for malformed inputs or output targets.
        if ($setup !== null) {
            $args = $setup($this, $args);
        }

        $script = $this->repoPath('tools/analyze-structured-log.php');
        $command = array_merge([PHP_BINARY, $script], $args);
        System::capture($command, $status);
        $this->assertNotSame(0, $status, $description . ' expected non-zero exit');
    }

    /**
     * @return array<string,array{string,array<int,string>,callable|null}>
     */
    public static function provideAdversarialCases(): array
    {
        return [
            'no arguments' => ['no arguments', [], null],
            'empty input option' => ['empty input option', ['--input='], null],
            'input path missing' => ['input path missing', ['--input=/tmp/not-present.log'], null],
            'input path directory' => ['input path directory', ['--input=' . sys_get_temp_dir()], null],
            'input unreadable file' => ['input unreadable file', ['--input=__UNREADABLE__'], static function (self $t, array $args): array {
                // root reads any file, so the scenario cannot be built there.
                $t->skipWhenRoot();
                $file = $t->createLogFile();
                chmod($file, 0000);
                $args[0] = '--input=' . $file;
                return $args;
            }],
            'invalid format xml' => ['invalid format xml', ['--input=__VALID__', '--format=xml'], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                return $args;
            }],
            'invalid format empty string' => ['invalid format empty string', ['--input=__VALID__', '--format='], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                return $args;
            }],
            'invalid format whitespace' => ['invalid format whitespace', ['--input=__VALID__', '--format=   '], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                return $args;
            }],
            'output empty string' => ['output empty string', ['--input=__VALID__', '--output='], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                return $args;
            }],
            // The unwritable targets below sit under a regular file, so the write fails for every user,
            // root included (a fixed /root/... path would be written by a root test run).
            'output unwritable path' => ['output unwritable path', ['--input=__VALID__', '--output=__UNWRITABLE__'], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                $args[1] = '--output=' . $t->unwritableTarget('out.json');
                return $args;
            }],
            'output directory path' => ['output directory path', ['--input=__VALID__', '--output=' . sys_get_temp_dir()], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                return $args;
            }],
            'output nested unwritable path' => ['output nested unwritable path', ['--input=__VALID__', '--output=__UNWRITABLE__'], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                $args[1] = '--output=' . $t->unwritableTarget('nested/out.json');
                return $args;
            }],
            'table format with unwritable output' => ['table format with unwritable output', ['--input=__VALID__', '--format=table', '--output=__UNWRITABLE__'], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                $args[2] = '--output=' . $t->unwritableTarget('out.txt');
                return $args;
            }],
            'input removed before read' => ['input removed before read', ['--input=__VALID__'], static function (self $t, array $args): array {
                $file = $t->createLogFile();
                unlink($file);
                $args[0] = '--input=' . $file;
                return $args;
            }],
            'input symlink to missing target' => ['input symlink to missing target', ['--input=__VALID__'], static function (self $t, array $args): array {
                $dir = $t->tmpDir();
                $target = $dir . '/missing.log';
                $link = $dir . '/link.log';
                symlink($target, $link);
                $args[0] = '--input=' . $link;
                return $args;
            }],
            'input php stream not file' => ['input php stream not file', ['--input=php://memory'], null],
            'format combined invalid spec' => ['format combined invalid spec', ['--input=__VALID__', '--format=json,table'], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                return $args;
            }],
            'table format with directory output' => ['table format with directory output', ['--input=__VALID__', '--format=table', '--output=' . sys_get_temp_dir()], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                return $args;
            }],
            'input unreadable directory symlink' => ['input unreadable directory symlink', ['--input=__VALID__'], static function (self $t, array $args): array {
                $t->skipWhenRoot();
                $dir = $t->tmpDir();
                $target = $dir . '/dir';
                mkdir($target, 0000, true);
                $link = $dir . '/link';
                symlink($target, $link);
                $args[0] = '--input=' . $link;
                return $args;
            }],
            'output path invalid scheme' => ['output path invalid scheme', ['--input=__VALID__', '--output=ftp://server/result'], static function (self $t, array $args): array {
                $args[0] = '--input=' . $t->createLogFile();
                return $args;
            }],
        ];
    }

    // The format name is deliberately case-insensitive (the tool lower-cases it), so "JSON" is accepted.
    public function testFormatNameIsCaseInsensitive(): void
    {
        $result = $this->runTool('tools/analyze-structured-log.php', ['--input=' . $this->createLogFile(), '--format=JSON']);
        $this->assertSame(0, $result['status'], $result['output']);
        $decoded = json_decode($result['stdout'], true);
        $this->assertIsArray($decoded, 'stdout should be the JSON summary');
        $this->assertSame(1, $decoded['total_events']);
    }

    // Lines that are not JSON are skipped, not fatal: the summary counts only the events that parsed.
    public function testInvalidJsonLinesAreSkipped(): void
    {
        $file = $this->createLogFile();
        file_put_contents($file, "{invalid json}\n", FILE_APPEND);

        $result = $this->runTool('tools/analyze-structured-log.php', ['--input=' . $file]);
        $this->assertSame(0, $result['status'], $result['output']);
        $decoded = json_decode($result['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['total_events'], 'the invalid line must not be counted as an event');
    }

    // An existing regular file is a valid --output target and is overwritten with the summary.
    public function testExistingOutputFileIsOverwritten(): void
    {
        $out = $this->createLogFile();
        file_put_contents($out, 'stale contents');

        $result = $this->runTool('tools/analyze-structured-log.php', ['--input=' . $this->createLogFile(), '--output=' . $out]);
        $this->assertSame(0, $result['status'], $result['output']);
        $decoded = json_decode((string) file_get_contents($out), true);
        $this->assertIsArray($decoded, 'the old contents must be replaced by the JSON summary');
        $this->assertSame(1, $decoded['total_events']);
    }

    // unwritableTarget returns a path below a regular file, which no user can create or write.
    private function unwritableTarget(string $relative): string
    {
        $file = $this->createLogFile();
        return $file . '/' . $relative;
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
