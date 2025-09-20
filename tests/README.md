# Test Suite

This repository keeps the PHPUnit configuration lightweight so the shared `Lib\\Common` helpers load through the small autoloader in `tests/bootstrap.php`.

## Suite Structure

* `Tests\\Lib\\Common\\LoggingTest` runs each logging helper in a fresh PHP process and checks for consistent timestamps, level tags, and formatting even when the input message becomes strange.
* `Tests\\Lib\\Common\\SystemTest` drives the command helpers through both successful and failing scenarios, including exit-code assertions that run in isolated PHP processes so PHPUnit itself does not terminate.

## Prerequisites

* PHP 8.1 or newer with the `pcntl` and `posix` extensions enabled.
* [PHPUnit 9](https://phpunit.de/) installed either via Composer (`composer require --dev phpunit/phpunit`) or by downloading the PHAR (`wget https://phar.phpunit.de/phpunit-9.phar`).
* Standard POSIX utilities such as `sh`, `env`, `true`, and `false` on the test host because the System helper coverage shells out to them directly.

## Running the Tests

1. Ensure PHPUnit is on your `$PATH` or available as `./vendor/bin/phpunit` when using Composer.
2. Execute the suite from the project root with whichever PHPUnit binary you installed:
   ```bash
   php phpunit.phar --configuration tests/phpunit.xml.dist
   ```
   *When using Composer's binaries, replace the command above with `./vendor/bin/phpunit --configuration tests/phpunit.xml.dist`.*
3. The tests intentionally assert on exit codes rather than stdout/stderr content because `System::run()` forwards output using `passthru`. This keeps the expectations stable across environments with different shell noise levels.

### Focused Execution

* To rerun only the System helper coverage, use `php phpunit.phar --configuration tests/phpunit.xml.dist --filter SystemTest`.
* When adding new cases, keep the pattern of pairing each success scenario with a failure-mode counterpart so regressions surface quickly.

### Exit-Handling Checks

* Tests that confirm forced exits use `System::capture()` to spawn a secondary PHP interpreter. This allows PHPUnit to receive the resulting exit code and log output without aborting the main process.
* If these tests begin failing with unexpected output, enable `-d display_errors=1` inside the helper script to inspect any additional PHP warnings emitted by the isolated run.

## Troubleshooting

* If PHPUnit cannot locate classes, confirm the repository is cloned with its relative directory layout intact so `tests/bootstrap.php` can map the `Lib\\Common` namespace.
* When commands like `sh`, `env`, `true`, or `false` are missing, install your distribution's POSIX shell package or update the tests to target equivalent binaries available on your platform.
