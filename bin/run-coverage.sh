#!/bin/sh
# Orchestrate merged code coverage for workerman/redis.
#
# 1. clear + create build/coverage
# 2. Unit tier in-process  -> build/coverage/unit.cov  (pcov in PHPUnit process)
# 3. Feature tier          -> build/coverage/cov-*.cov (pcov in each worker child,
#                             dumped by tests/Support/run-in-worker.php)
# 4. merge everything      -> coverage.xml + text summary
#
# POSIX sh only. Run from the project root via `composer test:coverage`.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COVDIR="$ROOT/build/coverage"

# Coverage floor (Step 0.4). The merge script fails non-zero if the MERGED total
# line coverage drops below this. Start at 65 (just under the measured 68.62%
# baseline, to absorb minor nondeterminism). RATCHET this toward 95 as each
# group lands more tests (Group 9 sets it to the final achieved number).
# Override at the call site with COVERAGE_MIN=<pct>.
COVERAGE_MIN="${COVERAGE_MIN:-65}"

# 1. fresh coverage dir
rm -rf "$COVDIR"
mkdir -p "$COVDIR"

# 2. Unit tier in-process (captured by PHPUnit as a single .cov).
php -d pcov.enabled=1 "$ROOT/vendor/bin/pest" \
    --testsuite Unit \
    --coverage-php="$COVDIR/unit.cov"

# 3. Feature tier — children dump their own .cov into COVERAGE_DIR.
COVERAGE_DIR="$COVDIR" php -d pcov.enabled=1 "$ROOT/vendor/bin/pest" \
    --testsuite Feature

# 4. Merge + report + enforce the floor (the canonical coverage gate).
COVERAGE_DIR="$COVDIR" COVERAGE_XML="$ROOT/coverage.xml" \
    php "$ROOT/bin/merge-coverage.php" --min="$COVERAGE_MIN"
