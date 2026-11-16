<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Smoke test ensuring check-template.php accepts a fully populated template layout.
final class CheckTemplateSuccessTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    public function testCheckTemplateReportsSuccess(): void
    {
        // A minimal but complete template should pass with exit status 0.
        $layout = $this->buildValidTemplateLayout();
        $result = $this->runTool('tools/check-template.php', ['--path=' . $layout, '--dir']);

        $this->assertSame(0, $result['status'], 'check-template should report success: ' . $result['output']);
        $this->assertStringContainsString('OK', $result['stdout']);
    }

    private function buildValidTemplateLayout(): string
    {
        $root = $this->mkTempDir('ct-success-');
        $this->registerTempPath($root);

        $paths = [
            '/distros/debian/common/tasks',
            '/distros/debian/templates',
            '/distros/common/templates',
        ];
        foreach ($paths as $path) {
            mkdir($root . $path, 0755, true);
        }

        file_put_contents($root . '/distros/configure.php', "<?php // configure\n");
        file_put_contents($root . '/distros/debian/common/tasks/00-stub.php', "<?php // task\n");
        file_put_contents($root . '/distros/debian/templates/hostname.tpl', '{{FQDN}}');
        file_put_contents($root . '/distros/common/templates/fstab.tpl', '{{FSTAB_ENTRIES}}');

        return $root;
    }
}

