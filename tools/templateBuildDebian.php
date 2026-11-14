#!/usr/bin/env php
<?php
declare(strict_types=1);

// templateBuildDebian.php - build a minimal Debian or Ubuntu root filesystem archive from the distribution's
// archive with mmdebstrap (no golden machine to capture). The archive is the --rootfs input of
// tools/templateRelease.php. All logic lives in src/Lib/Release/DebianRootfsBuilder.php; this file
// resolves mmdebstrap, parses flags and prints one JSON line. Run it as root on a Debian host (an Ubuntu build also needs ubuntu-keyring there).

ini_set('display_errors', 'stderr');

use Lib\Install\InstallRefusal;
use Lib\Install\ProcessRunner;
use Lib\Release\DebianRootfsBuilder;

// Same Lib\ => src/Lib/ mapping as templateRelease.php.
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
    fwrite($code === 0 ? STDOUT : STDERR, "Usage: templateBuildDebian.php --suite=bookworm --packages=FILE --out=/abs/path.tar.gz [--mirror=URL]\n"
        . "         [--components=main,universe --pockets=updates,security --keyring=/usr/share/keyrings/ubuntu-archive-keyring.gpg]\n"
        . "  --suite     suite to build (bookworm for distros/debian/12/minimal.packages, noble for distros/ubuntu/24.04/minimal.packages)\n"
        . "  --packages  reviewed package list: one package per line, each with a \"# needed by\" comment\n"
        . "  --out       new archive (absolute, .tar.gz, its directory must exist); never replaced\n"
        . "  --mirror    mirror URL (default deb.debian.org); it is written into the image's apt sources\n"
        . "  --components, --pockets, --keyring  Ubuntu: each apt source is written out (the suite and its <suite>-<pocket> suites, with\n"
        . "              the components and signed-by keyring file); all need --mirror, the keyring file must exist here and in the image\n"
        . "Variant minbase plus the list, without Recommends. Refuses a banned package in the list or in the\n"
        . "built image, an existing --out, a non-root run and a missing mmdebstrap. Prints one JSON line.\n");
    return $code;
}

// findBinary looks a program up in PATH without a shell; '' when it is not there.
function findBinary(string $name): string
{
    foreach (explode(':', (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && $dir[0] === '/' && is_file($dir . '/' . $name) && is_executable($dir . '/' . $name)) {
            return $dir . '/' . $name;
        }
    }
    return '';
}

$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    exit(usage(0));
}

try {
    $want = DebianRootfsBuilder::parseArguments($args);
} catch (InstallRefusal $refusal) {
    fwrite(STDERR, 'REFUSED ' . $refusal->getMessage() . "\n");
    exit(usage(64));
}

$builder = new DebianRootfsBuilder(new ProcessRunner(), function_exists('posix_geteuid') && posix_geteuid() === 0, findBinary('mmdebstrap'));
try {
    $result = $builder->build($want['suite'], $want['packages'], $want['out'], $want['mirror'],
        ['components' => $want['components'], 'pockets' => $want['pockets'], 'keyring' => $want['keyring']]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (InstallRefusal $refusal) {
    if ($builder->lastError() !== '') {
        fwrite(STDERR, $builder->lastError() . "\n"); // mmdebstrap's own last lines, for the operator
    }
    fwrite(STDERR, 'REFUSED ' . $refusal->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, 'REFUSED internalError: ' . get_class($error) . "\n");
    exit(3);
}
