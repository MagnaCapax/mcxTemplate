#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// installNode.php - unattended install core: disk match, layout plan, install.
// Two modes: --plan=FILE (local) and --api=URL --mac=MAC (network: the plan comes from
// nodeCore, progress and the root password go back to it).
// Dry run by default. Destructive execution needs BOTH --execute AND
// "wipeAuthorized": true in the plan. Every refusal has a named code and a
// non-zero exit (2). Usage errors exit 64. See docs/adr/0002-*.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use Lib\Install\CurlTransport;
use Lib\Install\Installer;
use Lib\Install\InstallRefusal;
use Lib\Install\NetworkInstall;
use Lib\Install\NodeCoreClient;
use Lib\Install\PlanPrinter;
use Lib\Install\PlanValidator;
use Lib\Install\ProcessRunner;
use Lib\Install\RecordingRunner;
use Lib\Install\RootPassword;
use Lib\Install\SystemStateReader;
use Lib\Install\TemplateFetcher;

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
        . "       installNode.php --api=https://host/ --mac=aa:bb:cc:dd:ee:ff [--execute [--reboot]]\n"
        . "  --plan=FILE         JSON install plan (untrusted input, validated).\n"
        . "  --execute           Really wipe and install. Needs wipeAuthorized=true in the plan.\n"
        . "  --secret-out=PATH   New file (mode 0600) that receives the generated root password (--plan mode only).\n"
        . "  --api=URL --mac=MAC Network mode: ask nodeCore for the plan, report progress, send the root password.\n"
        . "  --reboot            Network mode: reboot after nodeCore acknowledged the result.\n"
        . "Without --execute the plan is printed and nothing is touched.\n"
        . "(Network mode without --execute still says hello, which nodeCore counts as the job's one hello.)\n");
    return $code;
}

/** Parse the strict flag set; unknown flags are a usage error. */
function parseFlags(array $argv): ?array
{
    $flags = ['plan' => '', 'execute' => false, 'secret-out' => '', 'api' => '', 'mac' => '', 'reboot' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') {
            $flags['execute'] = true;
        } elseif (strncmp($arg, '--plan=', 7) === 0) {
            $flags['plan'] = substr($arg, 7);
        } elseif (strncmp($arg, '--secret-out=', 13) === 0) {
            $flags['secret-out'] = substr($arg, 13);
        } elseif (strncmp($arg, '--api=', 6) === 0) {
            $flags['api'] = substr($arg, 6);
        } elseif (strncmp($arg, '--mac=', 6) === 0) {
            $flags['mac'] = substr($arg, 6);
        } elseif ($arg === '--reboot') {
            $flags['reboot'] = true;
        } else {
            return null;
        }
    }
    // Exactly one mode: --plan (local; no --api, --mac, --reboot) or --api with --mac (network; no --plan, --secret-out).
    $local = $flags['plan'] !== '' && $flags['api'] === '' && $flags['mac'] === '' && !$flags['reboot'];
    $network = $flags['api'] !== '' && $flags['mac'] !== '' && $flags['plan'] === '' && $flags['secret-out'] === ''
        && ($flags['execute'] || !$flags['reboot']);
    return $local || $network ? $flags : null;
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

/** Network mode: the plan comes from nodeCore; see Lib\Install\NetworkInstall. */
function runNetwork(array $flags): int
{
    if (!extension_loaded('curl')) {
        throw new InstallRefusal('curlMissing', 'network mode needs the php curl extension');
    }
    $execute = $flags['execute'];
    $reader = new ProcessRunner();
    // Without --execute nothing may run: the writer is a recorder (and --reboot needs --execute).
    $writer = $execute ? new ProcessRunner() : new RecordingRunner();
    $state = new SystemStateReader();
    // Only endpoint names, attempt numbers and status codes reach stderr; never a URL query, token or password.
    $client = new NodeCoreClient($flags['api'], new CurlTransport(), null, static function (string $line): void {
        fwrite(STDERR, $line . "\n");
    });
    $network = new NetworkInstall($client, new TemplateFetcher(new CurlTransport()), new Installer($reader, $writer, $state), $reader,
        $writer, $state, static function (string $line): void {
            echo $line . "\n";
        }, ['isRoot' => function_exists('posix_geteuid') && posix_geteuid() === 0]);
    return $network->run($flags['mac'], $execute, $flags['reboot']);
}

$flags = parseFlags($argv);
if ($flags === null) {
    exit(usage(in_array('--help', $argv, true) ? 0 : 64));
}

try {
    if ($flags['api'] !== '') {
        exit(runNetwork($flags));
    }
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
