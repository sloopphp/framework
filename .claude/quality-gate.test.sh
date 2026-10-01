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
eval "$(sed -n '/^run_actionlint() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^gate_process_alive() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^stale_gate_servers() {/,/^}/p' "$script_dir/quality-gate.sh")"

for fn in gate_count run_actionlint gate_process_alive stale_gate_servers; do
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

check 'integration: same shape as phpunit' 'Integration (MySQL)' '35' \
    "${esc}[30;42mOK (35 tests, 46 assertions)${esc}[0m"

# Colour escapes sit between the word and the number here.
check 'composer deps: count behind escapes' 'composer deps' '156' \
    "${esc}[37m(scanned${esc}[0m 156 ${esc}[37mfiles in${esc}[0m 0.053 ${esc}[37ms)${esc}[0m"

check 'infection: mutations generated' 'Infection' '2044' \
    '2044 mutations were generated:
    2031 mutants were killed by Test Framework'

check 'actionlint: files collected' 'actionlint' '1' \
    'verbose: Collected 1 YAML files
verbose: Linting .github/workflows/ci.yml
verbose: Found total 0 errors in 3 ms for .github/workflows/ci.yml'

# actionlint exits 3 when it finds no workflow, so an empty run already fails on
# the exit code. This pins the other half of the contract: a reported 0 has to
# come back as 0 rather than as "not counted", which the run would read as a
# gate that does not report a count at all.
check 'actionlint: no workflow found' 'actionlint' '0' \
    'verbose: Collected 0 YAML files'

# gitleaks colours its log even when the output is not a terminal, and the
# escape sequence sits directly before the number.
check 'gitleaks: commits scanned' 'gitleaks' '247' \
    "${esc}[90m9:07PM${esc}[0m ${esc}[32mINF${esc}[0m ${esc}[1m247 commits scanned.${esc}[0m
${esc}[90m9:07PM${esc}[0m ${esc}[32mINF${esc}[0m ${esc}[1mno leaks found${esc}[0m"

check 'gitleaks: empty history' 'gitleaks' '0' \
    '9:07PM INF 0 commits scanned.'

# This gate prints nothing when it is clean, and the number of files it was
# given comes from git ls-files rather than from the tool itself.
check 'shellcheck: reports no count' 'shellcheck' '' \
    ''

# Gates that report no count have to stay empty rather than fall back to zero:
# zero would fail the run, and a gate that cannot be counted has not failed.
check 'phpstan: reports no count' 'PHPStan' '' \
    ' [OK] No errors'

check 'rector: reports no count' 'Rector' '' \
    '{"totals":{"changed_files":0,"errors":0}}'

check 'composer audit: reports no count' 'composer audit' '' \
    'No security vulnerability advisories found.'

check 'composer validate: reports no count' 'composer validate' '' \
    './composer.json is valid'

check 'unknown gate: reports no count' 'Some New Gate' '' \
    'whatever the tool printed'

# actionlint turns its shellcheck rule off without failing when it cannot run
# that tool, so run_actionlint reads the notice instead of the exit code. That
# makes the gate depend on a third piece of tool wording, alongside the two in
# gate_count, and it is the one that decides whether run: blocks are checked at
# all. Both tools are replaced by stubs on PATH so the case is about the
# reading, not about what the real actionlint happens to print today.
#
# Note that a comment line may not begin with the linter's own name: it reads
# that as one of its directives and fails to parse it (SC1072 / SC1073).
#
# $1 case name, $2 expected return code, $3 what the stub actionlint prints
check_actionlint() {
    local case_name="$1" want="$2" output="$3"

    local dir
    dir=$(mktemp -d) || exit 1
    printf '%s\n' "$output" > "$dir/captured"
    printf '#!/bin/sh\ncat "%s"\n' "$dir/captured" > "$dir/actionlint"
    printf '#!/bin/sh\nexit 0\n' > "$dir/shellcheck"
    chmod +x "$dir/actionlint" "$dir/shellcheck"

    local got=0
    PATH="$dir:$PATH" run_actionlint > /dev/null 2>&1 || got=$?
    rm -rf "$dir"

    if [ "$got" -eq "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

# The samples are captured output, so the $PATH in them is literal text and has
# to stay unexpanded (SC2016).
# shellcheck disable=SC2016
check_actionlint 'actionlint: the shellcheck rule was turned off' 1 \
    'verbose: Collected 1 YAML files
verbose: Rule "shellcheck" was disabled: exec: "shellcheck": executable file not found in $PATH
verbose: Found total 0 errors in 0 ms for .github/workflows/ci.yml'

# The samples are captured output, so the $PATH in them is literal text and has
# to stay unexpanded (SC2016).
# shellcheck disable=SC2016
check_actionlint 'actionlint: the shellcheck rule ran' 0 \
    'verbose: Collected 1 YAML files
verbose: Rule "pyflakes" was disabled: exec: "pyflakes": executable file not found in $PATH
verbose: Found total 0 errors in 0 ms for .github/workflows/ci.yml'

# Assert a yes/no answer.
#
# $1 case name, $2 expected ('yes' / 'no'), $3... the command to run
check_answer() {
    local case_name="$1" want="$2" got='no'
    shift 2
    if "$@"; then
        got='yes'
    fi

    if [ "$got" = "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

# A process that has exited: started, waited for, so its pid is free.
sleep 0 &
gone_pid=$!
wait "$gone_pid"

check_answer 'process alive: this test' 'yes' gate_process_alive "$$"
check_answer 'process alive: an exited process' 'no' gate_process_alive "$gone_pid"
# Anything that is not a pid counts as running, so its server is kept.
check_answer 'process alive: not a number' 'yes' gate_process_alive 'abc'
check_answer 'process alive: empty' 'yes' gate_process_alive ''

# The sweep decides which servers a run may remove, so a mistake here removes a
# server a parallel run is using. gate_process_alive is replaced by a stub that
# treats only the pids in $alive_pids as running, so the cases are about the
# reading of the label, not about which processes happen to exist.
#
# $1 case name, $2 the docker ps lines, $3 expected ids (newline-separated),
# $4 this host's name (default 'here')
check_sweep() {
    local case_name="$1" lines="$2" want="$3" host="${4:-here}" got
    got=$(
        # Called by stale_gate_servers, which the linter does not follow (SC2329).
        # shellcheck disable=SC2329
        gate_process_alive() { [[ " $alive_pids " == *" $1 "* ]]; }
        printf '%s\n' "$lines" | stale_gate_servers "$host"
    )

    if [ "$got" = "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

alive_pids='100 200'

check_sweep 'sweep: a run that is gone' 'aaa here 300' 'aaa'
check_sweep 'sweep: a run that is still going' 'aaa here 100' ''
check_sweep 'sweep: another host' 'aaa there 300' ''
check_sweep 'sweep: a label without a pid' 'aaa here' ''
check_sweep 'sweep: no label value' 'aaa' ''
check_sweep 'sweep: empty lines' '

' ''
check_sweep 'sweep: a pid that is not a number' 'aaa here x1' ''
check_sweep 'sweep: a host name with a space' 'aaa my host 300' 'aaa' 'my host'
check_sweep 'sweep: a host name that only starts the same' 'aaa my host 300' '' 'my'
check_sweep 'sweep: only the gone ones among several' 'aaa here 100
bbb here 300
ccc there 400
ddd here 200
eee here 500' 'bbb
eee'

printf '\n%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
