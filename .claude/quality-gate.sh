#!/usr/bin/env bash
#
# Runs the quality gates in order and prints an aggregated view of each exit code.
#
# Piping output through a filter loses the upstream exit code (`cmd | tail; echo $?`
# reads tail's 0). This script runs each command on its own and receives its exit
# code directly; its only purpose is to make misreads impossible. The detection
# itself is done by each tool.
#
# A second failure mode is a gate that exits 0 without having inspected anything:
# break the file selection in phpunit.xml or phpcs.xml and every tool reports a
# pass over an empty set. An exit code cannot tell that apart from a real pass,
# so each gate's output is also read for the number of items it looked at, and a
# gate that inspected nothing fails. Output is captured to a file rather than a
# pipe so the exit code still arrives untouched.
#
# Tools that do not report a count are listed as excluded at the end of the run.
# Substituting an input-side number (files on disk, packages in the lock file)
# would only prove that work existed, not that the tool did it.
#
# The count check only runs here. CI calls each tool directly rather than going
# through this script, so a change that empties a tool's file selection still
# passes there.
#
# Usage:
#   .claude/quality-gate.sh                    # run static checks and tests (a few seconds)
#   .claude/quality-gate.sh --with-mutation    # also run infection (about 1 minute)
#   .claude/quality-gate.sh --with-integration # also run Integration (needs docker compose up -d)
#   .claude/quality-gate.sh --all              # run everything
#
# Parallel sessions: several git worktrees share one database server, so the
# Integration gates run against a database named after the worktree rather than
# the fixed sloop_test. Without that, one session drops a table while another
# reads it and both see failures that have nothing to do with their changes.
# The run is also serialised per worktree where flock is installed, since two
# gates in the same tree fight over the same caches and the same database.
#
# Exit code: 1 if any gate fails, 0 if all pass, 3 if another run holds the lock.

set -uo pipefail

# Resolve the script's own absolute path first; it is referenced after cd.
script_dir=$(cd "$(dirname "$0")" && pwd) || exit 1
script_path="$script_dir/$(basename "$0")"

cd "$script_dir/.." || exit 1

# The database is named after the worktree so that parallel sessions do not
# share tables.
#
# $1 the worktree directory name
integration_db_name() {
    # Anything outside [a-z0-9_] is not valid in an identifier unless the name
    # is quoted at every use. The server caps an identifier at 64 characters and
    # the prefix takes 11 of them, leaving 53.
    local slug
    slug=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '_')

    # Plain truncation would put two worktrees whose names share a long prefix
    # on one database, which is the interference this whole thing is for. Past
    # the limit the tail becomes a digest of the full name instead.
    if [ "${#slug}" -gt 53 ]; then
        if ! command -v sha256sum > /dev/null 2>&1; then
            printf '\n  (sha256sum not installed; long worktree names may share a database)\n'
        fi

        local digest
        digest=$(printf '%s' "$1" | sha256sum | cut -c1-8)
        slug="${slug:0:44}_$digest"
    fi

    printf 'sloop_test_%s' "$slug"
}

# Skip colors when stdout is not a terminal (redirect to a log, CI, etc.).
if [ -t 1 ]; then
    bold=$'\033[1m'; green=$'\033[32m'; red=$'\033[31m'; reset=$'\033[0m'
else
    bold=''; green=''; red=''; reset=''
fi

with_mutation=0
with_integration=0

# Use the leading comment block (line 2 to the first blank line) as the help text.
usage() {
    sed -n '2,/^$/{ s/^#\{1,\} \{0,1\}//; p; }' "$script_path"
}

for arg in "$@"; do
    case "$arg" in
        --with-mutation) with_mutation=1 ;;
        --with-integration) with_integration=1 ;;
        --all) with_mutation=1; with_integration=1 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "unknown argument: $arg" >&2; usage >&2; exit 2 ;;
    esac
done

# One run per worktree, taken after the arguments are read so that --help and a
# mistyped flag still answer while another run holds the lock. The lock is
# released when the script exits, since the descriptor dies with it; a killed
# run does not leave the tree locked.
lock_file='.phpunit.cache/quality-gate.lock'
mkdir -p "$(dirname "$lock_file")" || exit 1
exec 9> "$lock_file" || exit 1
if ! command -v flock > /dev/null 2>&1; then
    printf '\n  (flock not installed; a second run in this worktree is not blocked)\n'
elif ! flock -n 9; then
    echo 'another quality gate is running in this worktree' >&2
    exit 3
fi

db_name=$(integration_db_name "$(basename "$(git rev-parse --show-toplevel 2>/dev/null || pwd)")")

names=()
codes=()
counts=()

# Gates whose output carries no count, with the reason shown at the end of the run.
excluded_note='PHPStan, Rector and composer audit report no count (verified with -v,
       --error-format=json and --format=json). Mutation baseline runs its own
       check: it rejects an Infection report whose totalMutantsCount is 0.'

# Print how many items a gate inspected, or nothing when the tool reports no count.
#
# The patterns below were taken from real output rather than from documentation,
# so a tool that changes its wording stops reporting a count and lands in the
# excluded list; it does not silently report zero.
gate_count() {
    local name="$1" out="$2"

    # Some tools colour their output even when it is not a terminal, and the
    # escape sequences carry digits: composer-dependency-analyser writes
    # "(scanned<ESC>[0m 156" and the 0 of the reset code is read as the count.
    local plain
    plain=$(mktemp) || exit 1
    sed "s/$(printf '\033')\[[0-9;]*[a-zA-Z]//g" "$out" > "$plain"

    case "$name" in
        'PHP-CS-Fixer')
            # "Found 0 of 156 files that can be fixed"
            sed -n 's/.*Found [0-9][0-9]* of \([0-9][0-9]*\) files.*/\1/p' "$plain" | tail -n 1
            ;;
        'PHPCS')
            # Progress line ends with "156 / 156 (100%)".
            sed -n 's|.*[^0-9]\([0-9][0-9]*\) / \([0-9][0-9]*\) (100%).*|\2|p' "$plain" | tail -n 1
            ;;
        'PHPUnit' | 'Integration (3306)' | 'Integration (3307)')
            # "OK (1307 tests, 3332 assertions)" when green, "Tests: 1307, ..." when not.
            sed -n -e 's/.*OK (\([0-9][0-9]*\) tests\?,.*/\1/p' \
                   -e 's/^Tests: \([0-9][0-9]*\),.*/\1/p' "$plain" | tail -n 1
            ;;
        'composer deps')
            # "(scanned 156 files in 0.053 s)"
            sed -n 's/.*scanned \([0-9][0-9]*\) files.*/\1/p' "$plain" | tail -n 1
            ;;
        'typos')
            # typos prints nothing when it finds no typo, so the count comes from
            # its own listing. This is what it would check, not what it captured,
            # but it still catches the case this watchdog is for: a config or
            # ignore rule that leaves nothing to check.
            typos --files | wc -l
            ;;
        'Infection')
            # "2044 mutations were generated:"
            sed -n 's/^\([0-9][0-9]*\) mutations were generated.*/\1/p' "$plain" | tail -n 1
            ;;
    esac

    rm -f "$plain"
}

run_gate() {
    local name="$1"
    shift

    printf '\n%s▶ %s%s\n' "$bold" "$name" "$reset"

    # Capture to a file, not a pipe: a pipe would replace the exit code below.
    local out
    out=$(mktemp) || exit 1
    "$@" > "$out" 2>&1
    local code=$?

    cat "$out"

    local count
    count=$(gate_count "$name" "$out")
    rm -f "$out"

    names+=("$name")
    codes+=("$code")
    counts+=("$count")

    return 0
}

run_gate 'PHP-CS-Fixer' vendor/bin/php-cs-fixer fix --dry-run --diff
run_gate 'PHPCS'        vendor/bin/phpcs
run_gate 'PHPStan'      vendor/bin/phpstan analyse --no-progress
run_gate 'PHPUnit'      vendor/bin/phpunit --exclude-testsuite=Integration
run_gate 'Rector'       vendor/bin/rector process --dry-run --no-progress-bar
run_gate 'composer audit' composer audit
run_gate 'composer deps'  vendor/bin/composer-dependency-analyser

# typos is installed per environment, not via composer; skip when absent.
if command -v typos > /dev/null 2>&1; then
    run_gate 'typos' typos
else
    printf '\n  (typos not installed, skipped. apk add typos / cargo install typos-cli)\n'
fi

if [ "$with_integration" -eq 1 ]; then
    # Create the worktree's database on both engines before the tests connect.
    # The sloop user cannot create databases, so this goes through root.
    integration_ready=1
    for service in mysql mariadb; do
        # Captured rather than discarded: a guessed cause ("run docker compose
        # up -d") is wrong for every failure that is not a stopped container,
        # and it would be the only thing left on screen.
        prep=$(mktemp) || exit 1
        if ! docker compose exec -T "$service" mysql -uroot -proot \
            -e "CREATE DATABASE IF NOT EXISTS \`$db_name\`;
                GRANT ALL PRIVILEGES ON \`$db_name\`.* TO 'sloop'@'%';" > "$prep" 2>&1; then
            printf '\n  (could not prepare %s on %s)\n' "$db_name" "$service"
            cat "$prep"
            integration_ready=0
        fi
        rm -f "$prep"
    done

    if [ "$integration_ready" -eq 1 ]; then
        printf '\n  (integration database: %s)\n' "$db_name"
        run_gate 'Integration (3306)' env DB_NAME="$db_name" \
            vendor/bin/phpunit --testsuite=Integration
        run_gate 'Integration (3307)' env DB_NAME="$db_name" DB_PORT=3307 \
            vendor/bin/phpunit --testsuite=Integration
    else
        names+=('Integration')
        codes+=(1)
        counts+=('')
    fi
fi

if [ "$with_mutation" -eq 1 ]; then
    run_gate 'Infection' vendor/bin/infection --threads=4 --no-progress

    # The baseline reads the report Infection writes. When Infection stops
    # before writing one, an older report is still on disk, and checking it
    # would report a pass for a run that never happened.
    if [ "${codes[${#codes[@]} - 1]}" -eq 0 ]; then
        run_gate 'Mutation baseline' php "$script_dir/mutation-baseline.php"
    else
        printf '\n  (Infection did not finish, so the baseline was not checked)\n'
    fi
fi

printf '\n%s=== Exit codes ===%s\n' "$bold" "$reset"

failed=0
for i in "${!names[@]}"; do
    count="${counts[$i]}"
    shown="${count:---}"

    if [ "${codes[$i]}" -ne 0 ]; then
        printf '  %sFAIL%s %-22s EXIT=%s  items=%s\n' \
            "$red" "$reset" "${names[$i]}" "${codes[$i]}" "$shown"
        failed=1
    elif [ -n "$count" ] && [ "$count" -eq 0 ]; then
        # Exit 0 over an empty set: the gate ran but verified nothing.
        printf '  %sFAIL%s %-22s EXIT=%s  items=0  (inspected nothing)\n' \
            "$red" "$reset" "${names[$i]}" "${codes[$i]}"
        failed=1
    else
        printf '  %sOK  %s %-22s EXIT=%s  items=%s\n' \
            "$green" "$reset" "${names[$i]}" "${codes[$i]}" "$shown"
    fi
done

printf '\n  items=-- means the count is not checked for that gate:\n       %s\n' "$excluded_note"

if [ "$with_mutation" -eq 0 ]; then
    printf '\n  (Infection not run. Use --with-mutation to run it)\n'
fi

if [ "$with_integration" -eq 0 ]; then
    printf '  (Integration not run. Use --with-integration to run it)\n'
fi

exit "$failed"
