# Test Suite

This repository keeps the PHPUnit configuration lightweight so the shared `Lib\\Common` helpers load through the small autoloader in `tests/bootstrap.php`.

## Prerequisites

* PHP 8.1 or newer with the `pcntl` and `posix` extensions enabled.
* [PHPUnit 9](https://phpunit.de/) installed either via Composer (`composer require --dev phpunit/phpunit`) or by downloading the PHAR (`wget https://phar.phpunit.de/phpunit-9.phar`).

## Running the Tests

1. Ensure PHPUnit is on your `$PATH` or available as `./vendor/bin/phpunit` when using Composer.
2. Execute the suite from the project root:
   ```bash
   ./vendor/bin/phpunit --configuration phpunit.xml.dist
   ```
   *When using the PHAR directly, replace the command above with `php phpunit-9.phar --configuration phpunit.xml.dist`.*
3. The tests intentionally assert on exit codes rather than stdout/stderr content because `System::run()` forwards output using `passthru`. This keeps the expectations stable across environments with different shell noise levels.

## Troubleshooting

* If PHPUnit cannot locate classes, confirm the repository is cloned with its relative directory layout intact so `tests/bootstrap.php` can map the `Lib\\Common` namespace.
* When commands like `sh` are missing, install your distribution's POSIX shell package or update the tests to target an equivalent binary available on your platform.
