#!/bin/sh
# mcxInstall.sh - mcxRescue bootstrap (ADR 0002). tools/templateRelease.php fills the three constants.
ENGINE_URL='@ENGINE_URL@'; ENGINE_SHA256='@ENGINE_SHA256@'; API_URL='@API_URL@'
set -eu
fail() { echo "mcxInstall: $*" >&2; exit 1; }
mcxMac=$(tr ' ' '\n' < /proc/cmdline | sed -n 's/^mcxMac=//p' | head -n 1)
[ -n "$mcxMac" ] || fail "no mcxMac on the kernel command line"
command -v php > /dev/null 2>&1 || fail "php is missing in this rescue image"
php -r 'exit(extension_loaded("curl") ? 0 : 1);' || fail "php-curl is missing in this rescue image"
rm -rf /run/mcxInstall /run/mcxInstall.tar.gz
# The engine is public and pinned by sha256 below, so the download itself needs no certificate check.
wget -q -T 30 -t 5 --no-check-certificate -O /run/mcxInstall.tar.gz "$ENGINE_URL" || fail "engine download failed"
echo "$ENGINE_SHA256  /run/mcxInstall.tar.gz" | sha256sum -c - > /dev/null || fail "engine sha256 mismatch"
mkdir /run/mcxInstall
tar -xzf /run/mcxInstall.tar.gz -C /run/mcxInstall || fail "engine extract failed"
exec php /run/mcxInstall/installNode.php --api="$API_URL" --mac="$mcxMac" --execute --reboot
