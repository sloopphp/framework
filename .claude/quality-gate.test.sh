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
eval "$(sed -n '/^prunable_databases() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^run_actionlint() {/,/^}/p' "$script_dir/quality-gate.sh")"

for fn in gate_count integration_db_name prunable_databases run_actionlint; do
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

# The whole identifier has to fit in 64 characters and the prefix takes 11, so
# a slug longer than 53 is cut. The digest is what keeps two worktrees whose
# names share a long prefix on separate databases; plain truncation would put
# them on the same one.
long_a='aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeeffff-1'
long_b='aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeeffff-2'

check_db_name 'db name: at the limit, kept whole' \
    'aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeefff' \
    'sloop_test_aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeefff'

# One past the limit: the branch has to take over here and nowhere earlier.
one_over='aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeefffg'
check_db_name 'db name: one past the limit' "$one_over" \
    "sloop_test_${one_over:0:44}_$(printf '%s' "$one_over" | sha256sum | cut -c1-8)"

check_db_name 'db name: past the limit, digest tail' "$long_a" \
    "sloop_test_$(printf '%s' "${long_a:0:44}" | tr -c 'a-z0-9' '_')_$(printf '%s' "$long_a" | sha256sum | cut -c1-8)"

if [ "$(integration_db_name "$long_a")" != "$(integration_db_name "$long_b")" ]; then
    printf '  ok   %s\n' 'db name: names sharing a long prefix stay apart'
    passed=$((passed + 1))
else
    printf '  FAIL %s: both became [%s]\n' \
        'db name: names sharing a long prefix stay apart' "$(integration_db_name "$long_a")"
    failed=$((failed + 1))
fi

full_name=$(integration_db_name "$long_a")
if [ "${#full_name}" -le 64 ]; then
    printf '  ok   %s\n' 'db name: stays within the 64 character cap'
    passed=$((passed + 1))
else
    printf '  FAIL %s: %d characters\n' \
        'db name: stays within the 64 character cap' "${#full_name}"
    failed=$((failed + 1))
fi

# Build a PATH holding only the named commands, so that the fallback chain can
# be walked one hasher at a time. Every link is checked: a stub that silently
# failed to build would send the function down a different path than the one the
# case is about, and the assertions below would pass without touching it.
#
# $@ external commands to expose
stub_path() {
    local dir tool target
    dir=$(mktemp -d) || exit 1

    for tool in "$@"; do
        target=$(command -v "$tool")
        case "$target" in
            /*) ln -s "$target" "$dir/$tool" || exit 1 ;;
            *)  echo "stub_path: $tool is not an external command" >&2; exit 1 ;;
        esac
    done

    printf '%s' "$dir"
}

# stub_path runs in a command substitution, so its exit only ends that subshell.
# An empty answer is how a rejected command reaches the caller.
#
# $1 the directory stub_path returned
require_stub() {
    [ -n "$1" ] && return 0

    echo 'stub_path could not build a PATH; the cases below would not test what they name' >&2
    exit 1
}

# The database name the function has to produce when it cannot hash: the slug
# cut to 53, with nothing else added. Comparing against the whole string rather
# than its shape is what makes a broken stub fail instead of pass — with no
# hasher reached at all the name comes back as the bare prefix.
hash_free_want="sloop_test_$(printf '%s' "$long_a" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '_' | cut -c1-53)"

# The caller reads this function through a command substitution, so anything it
# writes on stdout lands inside the database name. A notice about a missing hash
# command once did exactly that, and the name came back 127 characters long with
# the notice in it.
hash_free_dir=$(stub_path bash tr cut)
require_stub "$hash_free_dir"
hash_free_name=$(PATH="$hash_free_dir" integration_db_name "$long_a" 2>/dev/null)
rm -rf "$hash_free_dir"

if [ "$hash_free_name" = "$hash_free_want" ]; then
    printf '  ok   %s\n' 'db name: no stray output with no hash command'
    passed=$((passed + 1))
else
    printf '  FAIL %s: want [%s], got [%s]\n' \
        'db name: no stray output with no hash command' "$hash_free_want" "$hash_free_name"
    failed=$((failed + 1))
fi

# Each hasher in the chain, reached by hiding the ones before it. cksum is the
# reason the digest goes through tr: it prints "<crc> <bytes>", so without that
# step a space lands inside the identifier. The input below is chosen for a
# 7-digit crc, which puts the space within the first 8 characters.
cksum_input='aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeeff-27'

for hasher in md5sum cksum; do
    dir=$(stub_path bash tr cut "$hasher")
    require_stub "$dir"

    for name in "$long_a" "$long_b" "$cksum_input"; do
        got=$(PATH="$dir" integration_db_name "$name" 2>/dev/null)

        case "$got" in
            sloop_test_*[!a-z0-9_]*)
                printf '  FAIL %s: got [%s]\n' "db name: $hasher digest charset" "$got"
                failed=$((failed + 1))
                ;;
            *)
                if [ "${#got}" -eq 64 ]; then
                    printf '  ok   %s\n' "db name: $hasher on ${#name} characters"
                    passed=$((passed + 1))
                else
                    printf '  FAIL %s: %d characters [%s]\n' \
                        "db name: $hasher on ${#name} characters" "${#got}" "$got"
                    failed=$((failed + 1))
                fi
                ;;
        esac
    done

    a=$(PATH="$dir" integration_db_name "$long_a" 2>/dev/null)
    b=$(PATH="$dir" integration_db_name "$long_b" 2>/dev/null)
    rm -rf "$dir"

    if [ "$a" != "$b" ]; then
        printf '  ok   %s\n' "db name: $hasher keeps a shared prefix apart"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: both became [%s]\n' \
            "db name: $hasher keeps a shared prefix apart" "$a"
        failed=$((failed + 1))
    fi
done

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

# Assert which databases are prunable.
#
# $1 case name, $2 all databases (newline-separated), $3 the ones in use,
# $4 expected result (newline-separated, '' for none)
check_prunable() {
    local case_name="$1" want="$4" got

    got=$(prunable_databases "$2" "$3")

    if [ "$got" = "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

# What a server holds in practice: its own databases, the one compose creates,
# the tree the gate is running in, and the leftovers of trees that are gone.
all_databases='information_schema
mysql
performance_schema
sys
sloop_test
sloop_test_framework
sloop_test_cte
sloop_test_union'

check_prunable 'prune: leftovers of removed worktrees' \
    "$all_databases" 'sloop_test_framework' \
    'sloop_test_cte
sloop_test_union'

# The reason this exists: a parallel session is running the gate in its own
# tree, and its database has to survive even though this process is not using it.
check_prunable 'prune: a parallel session keeps its database' \
    "$all_databases" 'sloop_test_framework
sloop_test_cte' \
    'sloop_test_union'

# `sloop_test` comes from compose, not from a worktree, so nothing derived from
# `git worktree list` would ever name it. Dropping it would take the server's
# default database with it.
check_prunable 'prune: the database compose creates is left alone' \
    'sloop_test
sloop_test_gone' 'sloop_test_framework' 'sloop_test_gone'

check_prunable 'prune: databases outside the prefix are left alone' \
    'mysql
sys
sloopy_test_x
sloop_testing' 'sloop_test_framework' ''

check_prunable 'prune: nothing to drop' \
    'sloop_test
sloop_test_framework' 'sloop_test_framework' ''

# An empty protected list is how an upstream failure shows up here -- the main
# worktree is always listed, so the only way to get one is a command that did
# not run. Returning every test database would then delete all of them.
if prunable_databases "$all_databases" '' > /dev/null 2>&1; then
    printf '  FAIL %s\n' 'prune: an empty protected list is refused'
    failed=$((failed + 1))
else
    printf '  ok   %s\n' 'prune: an empty protected list is refused'
    passed=$((passed + 1))
fi

# A name that is exactly the prefix has nothing after it to identify a worktree,
# so it is not something this gate created.
check_prunable 'prune: the bare prefix is not a worktree database' \
    'sloop_test_' 'sloop_test_framework' ''

printf '\n%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
