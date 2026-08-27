#!/usr/bin/env bash
#
# Regression test for the item-count extraction in quality-gate.sh.
#
# The patterns in gate_count were written against real tool output, so they
# break when a tool changes its wording. A gate that stops reporting a count
# falls back to "not checked" rather than to zero, which is safe but silent;
# this test is what makes such a change visible.
#
# The samples below are captured output, including the colour escapes some
# tools emit even when writing to a file. composer-dependency-analyser is the
# reason the escapes are stripped before matching: it writes "(scanned<ESC>[0m
# 156" and the 0 of the reset code was read as the count.
#
# Usage: .claude/quality-gate.test.sh
# Exit code: 1 if any case fails, 0 if all pass.

set -uo pipefail

script_dir=$(cd "$(dirname "$0")" && pwd) || exit 1

# Load the functions under test without running the gates: take just their
# definitions.
eval "$(sed -n '/^gate_count() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^integration_db_name() {/,/^}/p' "$script_dir/quality-gate.sh")"

for fn in gate_count integration_db_name; do
    if ! declare -f "$fn" > /dev/null; then
        echo "$fn could not be loaded from quality-gate.sh" >&2
        exit 1
    fi
done

esc=$(printf '\033')
passed=0
failed=0

# Assert that a gate's output yields the expected count.
#
# $1 case name, $2 gate name, $3 expected count ('' for not reported), $4 output
check() {
    local case_name="$1" gate="$2" want="$3" sample="$4"

    local file
    file=$(mktemp) || exit 1
    printf '%s' "$sample" > "$file"

    local got
    got=$(gate_count "$gate" "$file")
    rm -f "$file"

    if [ "$got" = "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

check 'php-cs-fixer: files inspected' 'PHP-CS-Fixer' '156' \
    'Found 0 of 156 files that can be fixed in 0.321 seconds, 24.00 MB memory used'

# The case the watchdog exists for: this tool exits 0 over an empty set.
check 'php-cs-fixer: empty set' 'PHP-CS-Fixer' '0' \
    'Found 0 of 0 files that can be fixed in 0.000 seconds, 22.00 MB memory used'

check 'phpcs: progress line' 'PHPCS' '156' \
    '............ 60 / 156 (38%)
............ 156 / 156 (100%)

Time: 7.51 secs; Memory: 60MB'

check 'phpunit: green summary' 'PHPUnit' '1307' \
    "${esc}[30;42mOK (1307 tests, 3332 assertions)${esc}[0m"

check 'phpunit: failing summary' 'PHPUnit' '1307' \
    'Tests: 1307, Assertions: 3332, Failures: 1.'

# PHPUnit reports no count when it runs nothing, and it already exits non-zero
# there, so the gate fails on the exit code rather than on the count.
check 'phpunit: no tests executed' 'PHPUnit' '' \
    "${esc}[30;43mNo tests executed!${esc}[0m"

check 'integration: same shape as phpunit' 'Integration (3306)' '35' \
    "${esc}[30;42mOK (35 tests, 46 assertions)${esc}[0m"

# Colour escapes sit between the word and the number here.
check 'composer deps: count behind escapes' 'composer deps' '156' \
    "${esc}[37m(scanned${esc}[0m 156 ${esc}[37mfiles in${esc}[0m 0.053 ${esc}[37ms)${esc}[0m"

check 'infection: mutations generated' 'Infection' '2044' \
    '2044 mutations were generated:
    2031 mutants were killed by Test Framework'

# Gates that report no count have to stay empty rather than fall back to zero:
# zero would fail the run, and a gate that cannot be counted has not failed.
check 'phpstan: reports no count' 'PHPStan' '' \
    ' [OK] No errors'

check 'rector: reports no count' 'Rector' '' \
    '{"totals":{"changed_files":0,"errors":0}}'

check 'composer audit: reports no count' 'composer audit' '' \
    'No security vulnerability advisories found.'

check 'unknown gate: reports no count' 'Some New Gate' '' \
    'whatever the tool printed'

# Assert the database name derived from a worktree directory name.
#
# $1 case name, $2 directory name, $3 expected database name
check_db_name() {
    local case_name="$1" want="$3" got

    got=$(integration_db_name "$2")

    if [ "$got" = "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

check_db_name 'db name: the repository itself' 'framework' 'sloop_test_framework'

# What the worktree tool produces: the name a session passes to it, which is
# allowed to hold dashes and dots.
check_db_name 'db name: dashes' 'fix-chunk-by-id' 'sloop_test_fix_chunk_by_id'
check_db_name 'db name: dots' 'v0.1.probe' 'sloop_test_v0_1_probe'
check_db_name 'db name: upper case' 'Feature-A' 'sloop_test_feature_a'

# A name the server would refuse: 64 characters is the cap for the whole
# identifier, and the prefix takes 11 of them.
check_db_name 'db name: truncated to fit' \
    'aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeee' \
    'sloop_test_aaaaaaaaaabbbbbbbbbbccccccccccdddddddddd'

printf '\n%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
