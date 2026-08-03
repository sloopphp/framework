#!/usr/bin/env bash
#
# Runs the quality gates in order and prints an aggregated view of each exit code.
#
# Piping output through a filter loses the upstream exit code (`cmd | tail; echo $?`
# reads tail's 0). This script runs each command on its own and receives its exit
# code directly; its only purpose is to make misreads impossible. The detection
# itself is done by each tool.
#
# Usage:
#   .claude/quality-gate.sh                    # run static checks and tests (a few seconds)
#   .claude/quality-gate.sh --with-mutation    # also run infection (about 1 minute)
#   .claude/quality-gate.sh --with-integration # also run Integration (needs docker compose up -d)
#   .claude/quality-gate.sh --all              # run everything
#
# Exit code: 1 if any gate fails, 0 if all pass.

set -uo pipefail

# Resolve the script's own absolute path first; it is referenced after cd.
script_dir=$(cd "$(dirname "$0")" && pwd) || exit 1
script_path="$script_dir/$(basename "$0")"

cd "$script_dir/.." || exit 1

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

names=()
codes=()

run_gate() {
    local name="$1"
    shift

    printf '\n%s▶ %s%s\n' "$bold" "$name" "$reset"
    "$@"
    local code=$?

    names+=("$name")
    codes+=("$code")

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
    run_gate 'Integration (3306)' vendor/bin/phpunit --testsuite=Integration
    run_gate 'Integration (3307)' env DB_PORT=3307 vendor/bin/phpunit --testsuite=Integration
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
    if [ "${codes[$i]}" -eq 0 ]; then
        printf '  %sOK  %s %-22s EXIT=%s\n' "$green" "$reset" "${names[$i]}" "${codes[$i]}"
    else
        printf '  %sFAIL%s %-22s EXIT=%s\n' "$red" "$reset" "${names[$i]}" "${codes[$i]}"
        failed=1
    fi
done

if [ "$with_mutation" -eq 0 ]; then
    printf '\n  (Infection not run. Use --with-mutation to run it)\n'
fi

if [ "$with_integration" -eq 0 ]; then
    printf '  (Integration not run. Use --with-integration to run it)\n'
fi

exit "$failed"
