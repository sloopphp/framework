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
eval "$(sed -n '/^short_digest() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^integration_clone_id() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^integration_db_name() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^integration_dbs_in_use() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^prunable_databases() {/,/^}/p' "$script_dir/quality-gate.sh")"
eval "$(sed -n '/^run_actionlint() {/,/^}/p' "$script_dir/quality-gate.sh")"

for fn in gate_count short_digest integration_clone_id integration_db_name integration_dbs_in_use prunable_databases run_actionlint; do
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

check 'composer validate: reports no count' 'composer validate' '' \
    './composer.json is valid'

check 'unknown gate: reports no count' 'Some New Gate' '' \
    'whatever the tool printed'

# The clone id every database name below is built with. Any eight characters of
# [a-z0-9_] will do; what integration_clone_id derives is checked further down.
clone='c0ffee00'
prefix="sloop_test_${clone}_"

# Assert the database name derived from a worktree directory name.
#
# $1 case name, $2 directory name, $3 expected database name
check_db_name() {
    local case_name="$1" want="$3" got

    got=$(integration_db_name "$clone" "$2")

    if [ "$got" = "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

check_db_name 'db name: the repository itself' 'framework' "${prefix}framework"

# What the worktree tool produces: the name a session passes to it, which is
# allowed to hold dashes and dots.
check_db_name 'db name: dashes' 'fix-chunk-by-id' "${prefix}fix_chunk_by_id"
check_db_name 'db name: dots' 'v0.1.probe' "${prefix}v0_1_probe"
check_db_name 'db name: upper case' 'Feature-A' "${prefix}feature_a"

# The whole identifier has to fit in 64 characters and the prefix with the clone
# id takes 20, so a slug longer than 44 is cut. The digest is what keeps two
# worktrees whose names share a long prefix on separate databases; plain
# truncation would put them on the same one.
long_a='aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeeffff-1'
long_b='aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeeffff-2'

check_db_name 'db name: at the limit, kept whole' \
    'aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeee' \
    "${prefix}aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeee"

# One past the limit: the branch has to take over here and nowhere earlier.
one_over='aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeee'
check_db_name 'db name: one past the limit' "$one_over" \
    "${prefix}${one_over:0:35}_$(printf '%s' "$one_over" | sha256sum | cut -c1-8)"

check_db_name 'db name: past the limit, digest tail' "$long_a" \
    "${prefix}$(printf '%s' "${long_a:0:35}" | tr -c 'a-z0-9' '_')_$(printf '%s' "$long_a" | sha256sum | cut -c1-8)"

if [ "$(integration_db_name "$clone" "$long_a")" != "$(integration_db_name "$clone" "$long_b")" ]; then
    printf '  ok   %s\n' 'db name: names sharing a long prefix stay apart'
    passed=$((passed + 1))
else
    printf '  FAIL %s: both became [%s]\n' \
        'db name: names sharing a long prefix stay apart' "$(integration_db_name "$clone" "$long_a")"
    failed=$((failed + 1))
fi

full_name=$(integration_db_name "$clone" "$long_a")
if [ "${#full_name}" -eq 64 ]; then
    printf '  ok   %s\n' 'db name: fills the 64 character cap exactly'
    passed=$((passed + 1))
else
    printf '  FAIL %s: %d characters\n' \
        'db name: fills the 64 character cap exactly' "${#full_name}"
    failed=$((failed + 1))
fi

# The same worktree name in two clones: the reason the clone id is in the name.
if [ "$(integration_db_name 'c0ffee00' framework)" != "$(integration_db_name 'deadbeef' framework)" ]; then
    printf '  ok   %s\n' 'db name: two clones keep the same worktree name apart'
    passed=$((passed + 1))
else
    printf '  FAIL %s\n' 'db name: two clones keep the same worktree name apart'
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
# cut to 44, with nothing else added. Comparing against the whole string rather
# than its shape is what makes a broken stub fail instead of pass — with no
# hasher reached at all the name comes back as the bare prefix.
hash_free_want="${prefix}$(printf '%s' "$long_a" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '_' | cut -c1-44)"

# The caller reads this function through a command substitution, so anything it
# writes on stdout lands inside the database name. A notice about a missing hash
# command once did exactly that, and the name came back 127 characters long with
# the notice in it.
hash_free_dir=$(stub_path bash tr cut)
require_stub "$hash_free_dir"
hash_free_name=$(PATH="$hash_free_dir" integration_db_name "$clone" "$long_a" 2>/dev/null)
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
        got=$(PATH="$dir" integration_db_name "$clone" "$name" 2>/dev/null)

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

    a=$(PATH="$dir" integration_db_name "$clone" "$long_a" 2>/dev/null)
    b=$(PATH="$dir" integration_db_name "$clone" "$long_b" 2>/dev/null)
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

    got=$(prunable_databases "$2" "$3" "$clone")

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
all_databases="information_schema
mysql
performance_schema
sys
sloop_test
${prefix}framework
${prefix}cte
${prefix}union"

check_prunable 'prune: leftovers of removed worktrees' \
    "$all_databases" "${prefix}framework" \
    "${prefix}cte
${prefix}union"

# The reason this exists: a parallel session is running the gate in its own
# tree, and its database has to survive even though this process is not using it.
check_prunable 'prune: a parallel session keeps its database' \
    "$all_databases" "${prefix}framework
${prefix}cte" \
    "${prefix}union"

# Another clone's worktrees are not in this clone's `git worktree list`, so
# nothing here could protect them. Its databases are left to that clone.
check_prunable 'prune: another clone keeps its databases' \
    "${prefix}gone
sloop_test_deadbeef_framework
sloop_test_deadbeef_gone" "${prefix}framework" "${prefix}gone"

# Names from before the clone id was part of them could belong to any clone.
check_prunable 'prune: names without a clone id are left alone' \
    "${prefix}gone
sloop_test_framework
sloop_test_cte" "${prefix}framework" "${prefix}gone"

# `sloop_test` comes from compose, not from a worktree, so nothing derived from
# `git worktree list` would ever name it. Dropping it would take the server's
# default database with it.
check_prunable 'prune: the database compose creates is left alone' \
    "sloop_test
${prefix}gone" "${prefix}framework" "${prefix}gone"

check_prunable 'prune: databases outside the prefix are left alone' \
    'mysql
sys
sloopy_test_x
sloop_testing' "${prefix}framework" ''

check_prunable 'prune: nothing to drop' \
    "sloop_test
${prefix}framework" "${prefix}framework" ''

# An empty protected list is how an upstream failure shows up here -- the main
# worktree is always listed, so the only way to get one is a command that did
# not run. Returning every test database would then delete all of them.
if prunable_databases "$all_databases" '' "$clone" > /dev/null 2>&1; then
    printf '  FAIL %s\n' 'prune: an empty protected list is refused'
    failed=$((failed + 1))
else
    printf '  ok   %s\n' 'prune: an empty protected list is refused'
    passed=$((passed + 1))
fi

# The clone id goes into a pattern, so anything other than what
# integration_clone_id produces is refused rather than matched loosely. An empty
# one would otherwise select every `sloop_test__*` name.
for bad_clone in '' 'c0ffee0' 'c0ffee000' 'C0FFEE00' 'c0ff.e00'; do
    if prunable_databases "$all_databases" "${prefix}framework" "$bad_clone" > /dev/null 2>&1; then
        printf '  FAIL %s\n' "prune: a malformed clone id [$bad_clone] is refused"
        failed=$((failed + 1))
    else
        printf '  ok   %s\n' "prune: a malformed clone id [$bad_clone] is refused"
        passed=$((passed + 1))
    fi
done

# A name that is exactly the prefix has nothing after it to identify a worktree,
# so it is not something this gate created.
check_prunable 'prune: the bare prefix is not a worktree database' \
    "$prefix" "${prefix}framework" ''

# Run a function against a stubbed `git worktree list --porcelain -z`.
#
# The stub writes $1 with every newline turned into a NUL, which is the -z form
# of a listing written out line by line: each line ends in a NUL and a blank
# line becomes the extra NUL that ends a record. $3 writes it through printf %b
# instead, for a listing that needs a NUL and a newline side by side.
#
# $1 what the stub writes, $2 the stub's exit code, $3 'raw' for printf %b,
# $4... the command to run
with_worktrees() {
    # Named apart from anything the function under test declares: bash scopes
    # locals dynamically, so a `listing` here would be shadowed by the empty
    # `local listing` that integration_dbs_in_use declares before it calls the
    # stub, and the stub would write nothing.
    local stub_listing="$1" stub_rc="$2" stub_form="$3"
    shift 3

    # Reached by name from inside the functions under test, which shellcheck
    # cannot see (SC2329).
    # shellcheck disable=SC2329
    git() {
        if [ "$1" = 'worktree' ]; then
            if [ "$stub_form" = 'raw' ]; then
                printf '%b' "$stub_listing"
            else
                printf '%s' "$stub_listing" | tr '\n' '\0'
            fi
            return "$stub_rc"
        fi
        command git "$@"
    }

    "$@"
    local result=$?

    unset -f git
    return "$result"
}

# Assert what integration_dbs_in_use writes for a stubbed worktree listing.
#
# $1 case name, $2 stub output, $3 expected databases (newline-separated)
check_in_use() {
    local case_name="$1" want="$3" got

    got=$(with_worktrees "$2" 0 lines integration_dbs_in_use "$clone")

    if [ "$got" = "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

# Taken from a real `git worktree list --porcelain`: each record carries HEAD and
# branch lines and is followed by a blank one, including the last. Written out
# rather than reduced to the worktree lines so that a reader sees what else the
# format holds -- `prunable`, for one, which appears in a record whose directory
# was deleted without `git worktree remove` and which this deliberately does not
# filter on.
three_trees='worktree /home/x/framework
HEAD 50a13a621302be59f2809f2092053e69bfa2b531
branch refs/heads/main

worktree /home/x/framework/.claude/worktrees/cte
HEAD 50a13a621302be59f2809f2092053e69bfa2b531
branch refs/heads/feature/cte

worktree /home/x/framework/.claude/worktrees/union
HEAD 50a13a621302be59f2809f2092053e69bfa2b531
branch refs/heads/feature/union

'

# The first version of this wrote every name on one line, because
# integration_db_name ends without a newline. Whole-line matching then protected
# nothing, so every worktree's database was listed as unused -- including the one
# the gate had just created for itself.
check_in_use 'in use: one database per line' "$three_trees" \
    "${prefix}framework
${prefix}cte
${prefix}union"

check_in_use 'in use: the repository on its own' 'worktree /home/x/framework
HEAD 50a13a621302be59f2809f2092053e69bfa2b531
branch refs/heads/main

' "${prefix}framework"

# The porcelain format separates records with blank lines.
check_in_use 'in use: blank lines are skipped' 'worktree /home/x/framework

worktree /home/x/framework/.claude/worktrees/cte
' "${prefix}framework
${prefix}cte"

# A path holding a newline. Read line by line it came apart into `/home/x/a` and
# `b`, and neither name was the one that tree's own run derives from the whole
# path, so its database went unprotected.
newline_listing=$(with_worktrees 'worktree /home/x/framework\0\0worktree /home/x/a\nb\0\0' 0 raw \
    integration_dbs_in_use "$clone")
want_newline="${prefix}framework
$(integration_db_name "$clone" $'a\nb')"
if [ "$newline_listing" = "$want_newline" ]; then
    printf '  ok   %s\n' 'in use: a path holding a newline stays one worktree'
    passed=$((passed + 1))
else
    printf '  FAIL %s: want [%s], got [%s]\n' \
        'in use: a path holding a newline stays one worktree' "$want_newline" "$newline_listing"
    failed=$((failed + 1))
fi

# Assert that a listing yields no databases and says so in its exit code.
#
# Both halves are checked. The empty result is what stops the deletion at the
# call site; the non-zero return is the function's own contract, and pinning it
# here is what keeps the guard that produces it from being dropped as redundant.
#
# $1 case name, $2 stub output, $3 stub exit code, $4 clone id (default $clone)
check_in_use_refused() {
    local case_name="$1" out rc

    out=$(with_worktrees "$2" "$3" lines integration_dbs_in_use "${4-$clone}")
    rc=$?

    if [ "$rc" -ne 0 ] && [ -z "$out" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [rc!=0, no output], got [rc=%s, %s]\n' "$case_name" "$rc" "$out"
        failed=$((failed + 1))
    fi
}

# A directory removed with `rm -rf` keeps its record, with a `prunable` line
# saying why, until `git worktree prune` runs. Its database stays protected for
# that long. Filtering those out would be the unsafe direction -- the reason can
# be a mount that is briefly away, and the tree may still be in use.
check_in_use 'in use: a tree awaiting prune is still protected' 'worktree /home/x/framework
HEAD 50a13a621302be59f2809f2092053e69bfa2b531
branch refs/heads/main

worktree /home/x/framework/.claude/worktrees/cte
HEAD 50a13a621302be59f2809f2092053e69bfa2b531
branch refs/heads/feature/cte
prunable gitdir file points to non-existent location

' "${prefix}framework
${prefix}cte"

# A listing that cannot be read has to leave the caller with nothing, since an
# empty protected list is what stops the deletion.
check_in_use_refused 'in use: a failed listing is refused' '' 1

check_in_use_refused 'in use: a listing without worktree lines is refused' 'HEAD abc123
branch refs/heads/main
' 0

# git can write part of a listing and still fail. Without the check on its exit
# code the partial output would be read as the whole set of worktrees, and every
# tree missing from it would lose its database.
check_in_use_refused 'in use: a partial listing that failed is refused' 'worktree /home/x/framework
' 1

# Without a clone id there is no name to protect a tree under.
check_in_use_refused 'in use: no clone id is refused' "$three_trees" 0 ''

# Assert the clone id derived from a stubbed listing.
#
# $1 case name, $2 stub output, $3 expected clone id
check_clone_id() {
    local case_name="$1" want="$3" got

    got=$(with_worktrees "$2" 0 lines integration_clone_id)

    if [ "$got" = "$want" ]; then
        printf '  ok   %s\n' "$case_name"
        passed=$((passed + 1))
    else
        printf '  FAIL %s: want [%s], got [%s]\n' "$case_name" "$want" "$got"
        failed=$((failed + 1))
    fi
}

# Every worktree of a clone lists the main worktree first, so the id is a digest
# of that path wherever in the clone the gate runs.
check_clone_id 'clone id: digest of the main worktree' "$three_trees" \
    "$(short_digest '/home/x/framework')"

check_clone_id 'clone id: another clone differs' 'worktree /home/y/framework
HEAD 50a13a621302be59f2809f2092053e69bfa2b531
branch refs/heads/main

' "$(short_digest '/home/y/framework')"

if [ "$(short_digest '/home/x/framework')" != "$(short_digest '/home/y/framework')" ]; then
    printf '  ok   %s\n' 'clone id: two clones get different ids'
    passed=$((passed + 1))
else
    printf '  FAIL %s\n' 'clone id: two clones get different ids'
    failed=$((failed + 1))
fi

clone_out=$(with_worktrees '' 1 lines integration_clone_id)
clone_rc=$?
if [ "$clone_rc" -ne 0 ] && [ -z "$clone_out" ]; then
    printf '  ok   %s\n' 'clone id: a failed listing gives none'
    passed=$((passed + 1))
else
    printf '  FAIL %s: rc=%s [%s]\n' 'clone id: a failed listing gives none' "$clone_rc" "$clone_out"
    failed=$((failed + 1))
fi

# The two halves wired together, which is the pair the deletion actually runs on.
# Checking them apart is what let the concatenation through: the cases above hand
# prunable_databases a list written by hand, in a shape its producer could not
# yet write.
in_use_now=$(with_worktrees "$three_trees" 0 lines integration_dbs_in_use "$clone")
check_prunable 'prune: every live worktree survives the pair' \
    "sloop_test
${prefix}framework
${prefix}cte
${prefix}union
${prefix}gone" "$in_use_now" "${prefix}gone"

# The membership check the gate makes before it drops anything: the tree it is
# running in has to appear in the list it is about to protect.
if printf '%s\n' "$in_use_now" | grep -qxF -- "$(integration_db_name "$clone" framework)"; then
    printf '  ok   %s\n' 'prune: the running tree is in its own protected list'
    passed=$((passed + 1))
else
    printf '  FAIL %s: [%s] is missing from [%s]\n' \
        'prune: the running tree is in its own protected list' \
        "$(integration_db_name "$clone" framework)" "$in_use_now"
    failed=$((failed + 1))
fi

# A protected list larger than a pipe buffer. Reading it through a pipe made
# grep -q exit at its first match while the writer still had data, and under
# `set -o pipefail` the resulting SIGPIPE read as "not protected". 3000 entries
# is far past any real checkout; the point is that the size of the list cannot
# decide the answer.
many_protected=$(for i in $(seq 1 3000); do printf '%spadding_%040d\n' "$prefix" "$i"; done)
first_protected="${prefix}padding_$(printf '%040d' 1)"

check_prunable 'prune: a protected list past the pipe buffer still protects' \
    "$first_protected
${prefix}gone" "$many_protected" "${prefix}gone"

# Names outside what integration_db_name can produce are not this gate's to drop.
# A backtick in one would break the statement that drops it.
check_prunable 'prune: names the gate cannot produce are left alone' \
    "${prefix}ok
${prefix}UPPER
${prefix}with-dash
${prefix}back\`tick" "${prefix}framework" "${prefix}ok"

printf '\n%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
