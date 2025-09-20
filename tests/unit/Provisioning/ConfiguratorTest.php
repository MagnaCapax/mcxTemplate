<?php
declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Lib\Provisioning\Configurator;
use PHPUnit\Framework\TestCase;

final class ConfiguratorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MCX_MOUNT_SPEC');
        unset($_ENV['MCX_MOUNT_SPEC']);
        parent::tearDown();
    }

    public function testParseArgumentsCollectsOptionsAndMounts(): void
    {
        $args = ['--hostname=example.dc.local', '--mount=/,/dev/nvme0n1p2', '--mount=/home,/dev/nvme0n1p3', 'debian', '12'];
        $parsed = Configurator::parseArguments($args);

        $this->assertSame('example.dc.local', $parsed['options']['hostname']);
        $this->assertSame(['debian', '12'], $parsed['positionals']);
        $this->assertSame(['/,/dev/nvme0n1p2', '/home,/dev/nvme0n1p3'], $parsed['multi']['mount']);
        $this->assertFalse($parsed['help']);
    }

    public function testNormalizeMountEntriesAppliesDefaults(): void
    {
        $entries = [
            ['mount' => '/', 'device' => '/dev/root'],
            ['mount' => '/data', 'device' => '/dev/data', 'type' => 'xfs'],
            ['mount' => 'swap', 'device' => '/dev/swap0'],
        ];

        $normalized = Configurator::normalizeMountEntries($entries);

        $this->assertSame('/', $normalized[0]['mount']);
        $this->assertSame('ext4', $normalized[0]['type']);
        $this->assertSame('errors=remount-ro', $normalized[0]['options']);
        $this->assertFalse($normalized[0]['is_swap']);

        $this->assertSame('/data', $normalized[1]['mount']);
        $this->assertSame('xfs', $normalized[1]['type']);
        $this->assertSame('defaults', $normalized[1]['options']);
        $this->assertSame(2, $normalized[1]['pass']);

        $this->assertTrue($normalized[2]['is_swap']);
        $this->assertSame('swap', $normalized[2]['type']);
        $this->assertSame('sw', $normalized[2]['options']);
        $this->assertSame('none', $normalized[2]['mount']);
    }

    public function testNormalizeMountEntriesSkipsDuplicateMounts(): void
    {
        $entries = [
            ['mount' => '/', 'device' => '/dev/root1'],
            ['mount' => '/', 'device' => '/dev/root2'],
        ];

        $normalized = Configurator::normalizeMountEntries($entries);

        $this->assertCount(1, $normalized);
        $this->assertSame('/dev/root1', $normalized[0]['device']);
    }

    public function testBuildSkipTaskSetNormalizesFilenames(): void
    {
        $result = Configurator::buildSkipTaskSet('05-clear-log-files task-script.php extra');

        $this->assertArrayHasKey('05-clear-log-files', $result['set']);
        $this->assertArrayHasKey('05-clear-log-files.php', $result['set']);
        $this->assertArrayHasKey('task-script.php', $result['set']);
        $this->assertContains('05-clear-log-files', $result['display']);
        $this->assertContains('task-script.php', $result['display']);
    }

    public function testParseArgumentsRecognisesHelpFlag(): void
    {
        $parsed = Configurator::parseArguments(['--help']);

        $this->assertTrue($parsed['help']);
        $this->assertSame([], $parsed['options']);
    }

    public function testParseArgumentsIncludesPrimaryInterfaceOption(): void
    {
        $parsed = Configurator::parseArguments(['--primary-interface', 'ens3']);

        $this->assertSame('ens3', $parsed['options']['primary-interface']);
    }

    public function testStoreMountSpecificationPersistsEnvironment(): void
    {
        $normalized = [
            ['mount' => '/', 'original_mount' => '/', 'device' => '/dev/root', 'type' => 'ext4', 'options' => 'errors=remount-ro', 'dump' => 0, 'pass' => 1, 'is_swap' => false],
        ];

        $json = Configurator::storeMountSpecification($normalized);

        $this->assertJson($json);
        $this->assertSame($json, getenv('MCX_MOUNT_SPEC'));
    }
}
