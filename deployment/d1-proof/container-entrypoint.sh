#!/bin/sh
set -eu

mkdir -p /run/apache2 /run/lock/apache2 /run/php
rm -f /run/familyfunds-stdout /run/familyfunds-stderr
mkfifo /run/familyfunds-stdout /run/familyfunds-stderr
cat /run/familyfunds-stdout &
cat /run/familyfunds-stderr >&2 &
exec > /run/familyfunds-stdout 2> /run/familyfunds-stderr
exec /entrypoint "$@"
