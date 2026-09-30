#!/bin/bash -e
#
# Run PHPUnit tests with code coverage
#
# Usage:
#   ./run-coverage.sh              # Run all tests with coverage
#   ./run-coverage.sh --filter X   # Run specific test
#
# Coverage report is written to ${TEST_TMP} or ${TMPDIR} or /tmp:
#     newspack-intelligence-coverage/
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

OUT=/tmp
[ -n "$TMPDIR"   ] && OUT="$TMPDIR"
[ -n "$TEST_TMP" ] && OUT="$TEST_TMP"

# Pin phpunit to the project's vendor binary rather than whatever
# /usr/bin/phpunit happens to be. The container's system phpunit is
# 11.x; the project pins 10.5.x in composer.json. Mixing them causes
# "Call to undefined method PHPUnit\Event\DispatchingEmitter::exportsObjects"
# because the system loader pulls 11.x classes while the vendor tree
# is wired for 10.x.
PHPUNIT="$SCRIPT_DIR/../vendor/bin/phpunit"

# Ensure xdebug coverage mode is enabled
export XDEBUG_MODE=coverage

# Each run's bootstrap names a per-PID base; sweep only what a crashed run
# left, never a tree a concurrent suite is using.
find "${TMPDIR:-/tmp}" -maxdepth 1 -name 'newspack-intelligence-test-*' -mmin +120 -exec rm -rf {} +

# Run PHPUnit with coverage
"$PHPUNIT" --configuration phpunit.xml \
    --coverage-clover "${OUT}"/newspack-intelligence-coverage/clover.xml \
    --coverage-html "${OUT}"/newspack-intelligence-coverage \
	--enforce-time-limit \
    "$@"

echo ""
echo "Coverage report: ${OUT}/newspack-intelligence-coverage/index.html"
