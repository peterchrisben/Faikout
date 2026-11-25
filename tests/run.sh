#!/bin/sh
# All checks: sh tests/run.sh
cd "$(dirname "$0")/.." || exit 1
set -e
for f in FaikoutDaikin/module.php libs/*.php; do php -l "$f" >/dev/null; done
python3 tests/check_locale.py
python3 tests/check_captions.py
php tests/sim.php
