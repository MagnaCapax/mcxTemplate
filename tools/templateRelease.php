#!/usr/bin/env php
<?php
declare(strict_types=1);

// templateRelease.php - turn a captured rootfs archive into a published template release:
// the template (engine from git HEAD embedded), its .sha256 and .manifest.json, the engine
// tarball the bootstrap downloads, and bootstrap/mcxInstall.sh with its constants filled.
// All logic lives in src/Lib/Release; this file parses flags and prints the result.
// Run it as root (tar keeps file owners only for root).

ini_set('display_errors', 'stderr');

use Lib\Install\InstallRefusal;
use Lib\Install\ProcessRunner;
use Lib\Release\ReleaseBuilder;

// Same Lib\ => src/Lib/ mapping as installNode.php.
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Lib\\', 4) === 0) {
        $path = dirname(__DIR__) . '/src/Lib/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

function usage(int $code): int
{
    fwrite($code === 0 ? STDOUT : STDERR, "Usage: templateRelease.php --rootfs=FILE.tar.gz --name=NAME --version=YYYYMMDD[.N] --out=/abs/dir\n"
        . "         --api-url=https://nodecore.example/ --artifact-base=https://files.example/templates [--unsafe-allow-non-root]\n"
        . "  --rootfs        captured root filesystem archive (no rootfs/ prefix)\n"
        . "  --out           new output directory (absolute); existing release files are never overwritten\n"
        . "  --api-url       nodeCore base URL written into the bootstrap\n"
        . "  --artifact-base https base URL (no trailing slash) the artifacts will be published under\n"
        . "  --unsafe-allow-non-root  test builds only: file owners in the archive will be wrong\n"
        . "Refuses a dirty engine repository and a rootfs holding private key blocks (other than SSH host keys),\n"
        . "authorized_keys files, an unlocked root password, or an installed fail2ban, ufw, crowdsec or sshguard package (ADR-0006). A known-public package key fixture does not\n"
        . "refuse; it is printed as NOTICE publicFixture <path> on stderr. Prints the mcxSha256 pin of the bootstrap.\n");
    return $code;
}

$want = ['rootfs' => '', 'name' => '', 'version' => '', 'out' => '', 'api-url' => '', 'artifact-base' => ''];
$unsafe = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        exit(usage(0));
    }
    if ($arg === '--unsafe-allow-non-root') {
        $unsafe = true;
        continue;
    }
    $eq = strpos($arg, '=');
    $key = $eq === false ? '' : substr($arg, 2, $eq - 2);
    if (strncmp($arg, '--', 2) !== 0 || !array_key_exists($key, $want)) {
        exit(usage(64));
    }
    $want[$key] = substr($arg, $eq + 1);
}
if (in_array('', $want, true)) {
    exit(usage(64));
}

try {
    $builder = new ReleaseBuilder(new ProcessRunner(), dirname(__DIR__), function_exists('posix_geteuid') && posix_geteuid() === 0, $unsafe);
    $r = $builder->build($want['rootfs'], $want['name'], $want['version'], $want['out'], $want['api-url'], $want['artifact-base']);
    foreach ($r['scanNotices'] as $notice) {
        fwrite(STDERR, 'NOTICE ' . $notice . "\n"); // reported, not refused (ContentScan::NOTICE_CODES)
    }
    echo "template=" . $r['template'] . "\ntemplateSha256=" . $r['sha256'] . "\ntemplateSizeBytes=" . $r['manifest']['sizeBytes'] . "\n"
        . "engine=" . $r['engine'] . "\nengineSha256=" . $r['engineSha256'] . "\n"
        . "bootstrap=" . $r['bootstrap'] . "\nmcxSha256=" . $r['bootstrapSha256'] . "\n";
    exit(0);
} catch (InstallRefusal $refusal) {
    fwrite(STDERR, 'REFUSED ' . $refusal->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, 'REFUSED internalError: ' . get_class($error) . "\n");
    exit(3);
}
