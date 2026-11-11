<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Validates analyze/check-template.php rejects incomplete or malformed layouts.
final class CheckTemplateAdversarialTest extends TestCase
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
            $this->rmTree($path);
        }
        parent::tearDown();
    }

    /**
     * @dataProvider provideAdversarialCases
     */
    public function testAdversarialScenarios(string $description, callable $setup): void
    {
        $path = $setup($this);
        $result = $this->runTool('tools/check-template.php', ['--path=' . $path, '--dir']);
        $this->assertSame(2, $result['status'], $description . ' expected auditor failure');
    }

    /**
     * @return array<string,array{string,callable}>
     */
    public static function provideAdversarialCases(): array
    {
        return [
            'empty directory' => ['empty directory', static function (self $t): string {
                return $t->registerPath($t->mkTempDir('ct-empty-'));
            }],
            'missing configure file' => ['missing configure file', static function (self $t): string {
                $root = $t->createBaseLayout();
                unlink($root . '/distros/configure.php');
                return $root;
            }],
            'missing distros directory' => ['missing distros directory', static function (self $t): string {
                $root = $t->mkTempDir('ct-nodistros-');
                $t->registerPath($root);
                return $root;
            }],
            'missing tasks directory' => ['missing tasks directory', static function (self $t): string {
                $root = $t->createBaseLayout();
                $t->rmTree($root . '/distros/debian/common/tasks');
                return $root;
            }],
            'empty tasks directory' => ['empty tasks directory', static function (self $t): string {
                $root = $t->createBaseLayout();
                foreach (glob($root . '/distros/debian/common/tasks/*') ?: [] as $file) {
                    @unlink($file);
                }
                return $root;
            }],
            'missing distro templates directory' => ['missing distro templates directory', static function (self $t): string {
                $root = $t->createBaseLayout();
                $t->rmTree($root . '/distros/debian/templates');
                return $root;
            }],
            'empty distro templates directory' => ['empty distro templates directory', static function (self $t): string {
                $root = $t->createBaseLayout();
                foreach (glob($root . '/distros/debian/templates/*') ?: [] as $file) {
                    @unlink($file);
                }
                return $root;
            }],
            'missing shared templates directory' => ['missing shared templates directory', static function (self $t): string {
                $root = $t->createBaseLayout();
                $t->rmTree($root . '/distros/common/templates');
                return $root;
            }],
            'empty shared templates directory' => ['empty shared templates directory', static function (self $t): string {
                $root = $t->createBaseLayout();
                foreach (glob($root . '/distros/common/templates/*') ?: [] as $file) {
                    @unlink($file);
                }
                return $root;
            }],
            'configure file unreadable' => ['configure file unreadable', static function (self $t): string {
                // root reads any file, so the scenario cannot be built there.
                $t->skipWhenRoot();
                $root = $t->createBaseLayout();
                chmod($root . '/distros/configure.php', 0000);
                return $root;
            }],
            'tasks file unreadable' => ['tasks file unreadable', static function (self $t): string {
                // root reads any file, so the scenario cannot be built there.
                $t->skipWhenRoot();
                $root = $t->createBaseLayout();
                chmod($root . '/distros/debian/common/tasks/00-stub.php', 0000);
                return $root;
            }],
            'templates file unreadable' => ['templates file unreadable', static function (self $t): string {
                // root reads any file, so the scenario cannot be built there.
                $t->skipWhenRoot();
                $root = $t->createBaseLayout();
                chmod($root . '/distros/debian/templates/hostname.tpl', 0000);
                return $root;
            }],
            'shared template unreadable' => ['shared template unreadable', static function (self $t): string {
                // root reads any file, so the scenario cannot be built there.
                $t->skipWhenRoot();
                $root = $t->createBaseLayout();
                chmod($root . '/distros/common/templates/fstab.tpl', 0000);
                return $root;
            }],
            'embedded layout missing configure' => ['embedded layout missing configure', static function (self $t): string {
                $root = $t->createEmbeddedLayout();
                unlink($root . '/rootfs/opt/mcxTemplate/distros/configure.php');
                return $root;
            }],
            'embedded layout missing tasks' => ['embedded layout missing tasks', static function (self $t): string {
                $root = $t->createEmbeddedLayout();
                $t->rmTree($root . '/rootfs/opt/mcxTemplate/distros/debian/common/tasks');
                return $root;
            }],
            'embedded layout missing templates' => ['embedded layout missing templates', static function (self $t): string {
                $root = $t->createEmbeddedLayout();
                $t->rmTree($root . '/rootfs/opt/mcxTemplate/distros/debian/templates');
                return $root;
            }],
            'template directory missing configure' => ['template directory missing configure', static function (self $t): string {
                $root = $t->createTemplateSubdirLayout();
                unlink($root . '/template/distros/configure.php');
                return $root;
            }],
            'template directory missing tasks' => ['template directory missing tasks', static function (self $t): string {
                $root = $t->createTemplateSubdirLayout();
                $t->rmTree($root . '/template/distros/debian/common/tasks');
                return $root;
            }],
            'template directory missing templates' => ['template directory missing templates', static function (self $t): string {
                $root = $t->createTemplateSubdirLayout();
                $t->rmTree($root . '/template/distros/debian/templates');
                return $root;
            }],
            'random files only' => ['random files only', static function (self $t): string {
                $root = $t->mkTempDir('ct-random-');
                $t->registerPath($root);
                file_put_contents($root . '/README', 'nothing useful');
                return $root;
            }],
            'template subdir empty' => ['template subdir empty', static function (self $t): string {
                $root = $t->mkTempDir('ct-empty-template-');
                $t->registerPath($root);
                mkdir($root . '/template', 0755, true);
                return $root;
            }],
            'embedded rootfs without template contents' => ['embedded rootfs without template contents', static function (self $t): string {
                $root = $t->mkTempDir('ct-rootfs-empty-');
                $t->registerPath($root);
                mkdir($root . '/rootfs/opt/mcxTemplate', 0755, true);
                return $root;
            }],
            'embedded shared template unreadable' => ['embedded shared template unreadable', static function (self $t): string {
                // root reads any file, so the scenario cannot be built there.
                $t->skipWhenRoot();
                $root = $t->createEmbeddedLayout();
                chmod($root . '/rootfs/opt/mcxTemplate/distros/common/templates/fstab.tpl', 0000);
                return $root;
            }],
            'template configure unreadable in template subdir' => ['template configure unreadable in template subdir', static function (self $t): string {
                // root reads any file, so the scenario cannot be built there.
                $t->skipWhenRoot();
                $root = $t->createTemplateSubdirLayout();
                chmod($root . '/template/distros/configure.php', 0000);
                return $root;
            }],
        ];
    }

    private function createBaseLayout(): string
    {
        $root = $this->registerPath($this->mkTempDir('ct-base-'));
        mkdir($root . '/distros/debian/common/tasks', 0755, true);
        mkdir($root . '/distros/debian/templates', 0755, true);
        mkdir($root . '/distros/common/templates', 0755, true);
        file_put_contents($root . '/distros/configure.php', "<?php // configure\n");
        file_put_contents($root . '/distros/debian/common/tasks/00-stub.php', "<?php // task\n");
        file_put_contents($root . '/distros/debian/templates/hostname.tpl', '{{FQDN}}');
        file_put_contents($root . '/distros/common/templates/fstab.tpl', '{{FSTAB_ENTRIES}}');
        return $root;
    }

    private function createEmbeddedLayout(): string
    {
        $root = $this->registerPath($this->mkTempDir('ct-embedded-'));
        $base = $root . '/rootfs/opt/mcxTemplate';
        mkdir($base . '/distros/debian/common/tasks', 0755, true);
        mkdir($base . '/distros/debian/templates', 0755, true);
        mkdir($base . '/distros/common/templates', 0755, true);
        file_put_contents($base . '/distros/configure.php', "<?php // configure\n");
        file_put_contents($base . '/distros/debian/common/tasks/00-stub.php', "<?php // task\n");
        file_put_contents($base . '/distros/debian/templates/hostname.tpl', '{{FQDN}}');
        file_put_contents($base . '/distros/common/templates/fstab.tpl', '{{FSTAB_ENTRIES}}');
        return $root;
    }

    private function createTemplateSubdirLayout(): string
    {
        $root = $this->registerPath($this->mkTempDir('ct-template-'));
        $base = $root . '/template';
        mkdir($base . '/distros/debian/common/tasks', 0755, true);
        mkdir($base . '/distros/debian/templates', 0755, true);
        mkdir($base . '/distros/common/templates', 0755, true);
        file_put_contents($base . '/distros/configure.php', "<?php // configure\n");
        file_put_contents($base . '/distros/debian/common/tasks/00-stub.php', "<?php // task\n");
        file_put_contents($base . '/distros/debian/templates/hostname.tpl', '{{FQDN}}');
        file_put_contents($base . '/distros/common/templates/fstab.tpl', '{{FSTAB_ENTRIES}}');
        return $root;
    }

    private function registerPath(string $path): string
    {
        $this->paths[] = $path;
        return $path;
    }
}
