#!/usr/bin/env bash
set -euo pipefail

# Minimal local runner for mcxTemplate
# - Syntax check all PHP files
# - Optionally run PHPUnit if available

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)"

echo "[+] PHP syntax check"
if command -v rg >/dev/null 2>&1; then
  mapfile -t php_files < <(cd "$root_dir" && rg -n --no-config --hidden -g '!vendor/*' -g '!node_modules/*' -g '*.php' -l '')
else
  mapfile -t php_files < <(cd "$root_dir" && find . -type f -name '*.php' ! -path './vendor/*' ! -path './node_modules/*' -print)
fi
for f in "${php_files[@]}"; do
  php -l "$root_dir/$f" >/dev/null
done
echo "[+] PHP syntax OK (${#php_files[@]} files)"

echo "[+] PHPUnit (if available)"
if [[ -x "$root_dir/vendor/bin/phpunit" ]]; then
  "$root_dir/vendor/bin/phpunit" --configuration "$root_dir/tests/phpunit.xml.dist"
elif command -v phpunit >/dev/null 2>&1; then
  phpunit --configuration "$root_dir/tests/phpunit.xml.dist"
elif [[ -f "$root_dir/phpunit-9.phar" ]]; then
  php "$root_dir/phpunit-9.phar" --configuration "$root_dir/tests/phpunit.xml.dist"
else
  echo "[!] PHPUnit not found; skipping unit tests."
fi

echo "[+] Done"
