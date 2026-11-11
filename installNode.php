#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// installNode.php - unattended install core: disk match, layout plan, install.
// Dry run by default. Destructive execution needs BOTH --execute AND
// "wipeAuthorized": true in the plan. Every refusal has a named code and a
// non-zero exit (2). Usage errors exit 64. See docs/adr/0002-*.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use Lib\Install\Installer;
use Lib\Install\InstallRefusal;
use Lib\Install\PlanPrinter;
use Lib\Install\PlanValidator;
use Lib\Install\ProcessRunner;
use Lib\Install\RecordingRunner;
use Lib\Install\RootPassword;
use Lib\Install\SystemStateReader;

// Same Lib\ => src/Lib/ mapping as tests/bootstrap.php, for the runtime.
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Lib\\', 4) === 0) {
        $path = __DIR__ . '/src/Lib/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

/** Print the usage text and return the exit code. */
function usage(int $code): int
{
    fwrite($code === 0 ? STDOUT : STDERR, "Usage: installNode.php --plan=FILE [--execute --secret-out=/absolute/path]\n"
        . "  --plan=FILE         JSON install plan (untrusted input, validated).\n"
        . "  --execute           Really wipe and install. Needs wipeAuthorized=true in the plan.\n"
        . "  --secret-out=PATH   New file (mode 0600) that receives the generated root password.\n"
        . "Without --execute the plan is printed and nothing is touched.\n");
    return $code;
}

/** Parse the strict flag set; unknown flags are a usage error. */
function parseFlags(array $argv): ?array
{
    $flags = ['plan' => '', 'execute' => false, 'secret-out' => ''];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') {
            $flags['execute'] = true;
        } elseif (strncmp($arg, '--plan=', 7) === 0) {
            $flags['plan'] = substr($arg, 7);
        } elseif (strncmp($arg, '--secret-out=', 13) === 0) {
            $flags['secret-out'] = substr($arg, 13);
        } else {
            return null;
        }
    }
    return $flags['plan'] === '' ? null : $flags;
}

/** Load and validate the plan file. */
function loadPlan(string $file): array
{
    if (!is_file($file) || !is_readable($file) || filesize($file) > 1048576) {
        throw new InstallRefusal('planInvalid', 'plan file is missing, unreadable or larger than 1 MiB');
    }
    $json = json_decode((string) file_get_contents($file), true, 16);
    if ($json === null) {
        throw new InstallRefusal('planInvalid', 'plan file is not valid JSON');
    }
    return PlanValidator::validate($json);
}

$flags = parseFlags($argv);
if ($flags === null) {
    exit(usage(in_array('--help', $argv, true) ? 0 : 64));
}

try {
    $plan = loadPlan($flags['plan']);
    $execute = $flags['execute'];
    // The input checks come before the root check so each one can be proven on its own by an unprivileged test.
    if ($execute && $plan['wipeAuthorized'] !== true) {
        throw new InstallRefusal('wipeNotAuthorized', 'the plan does not carry wipeAuthorized=true');
    }
    if ($execute && $flags['secret-out'] === '') {
        throw new InstallRefusal('secretOutRequired', '--execute needs --secret-out');
    }
    if ($execute) {
        RootPassword::assertUsablePath($flags['secret-out']);
    }
    if ($execute && (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
        throw new InstallRefusal('notRoot', '--execute needs root');
    }

    // The reader runs read-only commands. In dry-run the writer is a recorder that runs nothing,
    // and prepare() verifies the template in place; an execute run stages a hashed private copy.
    $reader = new ProcessRunner();
    $writer = $execute ? new ProcessRunner() : new RecordingRunner();
    $installer = new Installer($reader, $writer, new SystemStateReader());
    $prepared = $installer->prepare($plan, $execute);

    echo PlanPrinter::render($plan, $prepared, $execute);
    if (!$execute) {
        echo "\nDry run complete. Nothing was changed.\n";
        exit(0);
    }
    $installer->execute($plan, $prepared, $flags['secret-out'], static function (string $id): void {
        echo "step: $id\n";
    });
    echo "Install steps and boot verification finished. Root password is in the --secret-out file.\n";
    exit(0);
} catch (InstallRefusal $refusal) {
    fwrite(STDERR, 'REFUSED ' . $refusal->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    // Class name only: a message or stack argument could carry plan values.
    fwrite(STDERR, "REFUSED internalError: " . get_class($error) . "\n");
    exit(3);
}
