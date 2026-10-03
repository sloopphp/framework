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
#   .claude/quality-gate.sh --with-integration # also run Integration (needs docker)
#   .claude/quality-gate.sh --all              # run everything
#
# Parallel sessions: --with-integration starts its own MySQL and MariaDB for
# the run, with the data on tmpfs and a port the system picks, and removes them
# when the run ends. Nothing is shared between runs, so one session cannot drop a
# table, restart a server or wipe a data volume out from under another. The
# servers are started before the other gates and waited for just before
# Integration, so once the images are on the machine their boot overlaps the
# static checks. The first run, or one after an image changes, pulls the image
# before anything else starts.
#
# The containers carry a label naming the run that owns them: host, PID
# namespace, user and process. A run killed too hard to clean up (kill -9)
# leaves them behind; the next --with-integration run removes those whose owner
# it can see is gone and leaves everything else alone.
#
# The run is serialised per worktree where flock is installed, since two gates
# in the same tree fight over the same caches.
#
# Exit code: 0 if all pass, 1 if any gate fails, 2 for an unknown argument, 3 if
# another run holds the lock, 130 / 143 when interrupted (INT / TERM).

set -uo pipefail

# Resolve the script's own absolute path first; it is referenced after cd.
script_dir=$(cd "$(dirname "$0")" && pwd) || exit 1
script_path="$script_dir/$(basename "$0")"

cd "$script_dir/.." || exit 1

# Images the Integration servers run, the same versions CI tests against.
mysql_image='mysql:8.0'
mariadb_image='mariadb:10.11'

# Label put on every server this gate starts. The value names the run that owns
# it, which is what lets a later run tell a leftover from a server a parallel run
# is still using. A process id only means something to a run that sees the same
# processes, so the value carries the PID namespace and the user as well as the
# host: WSL distributions share the Windows host name and one docker daemon but
# each has its own PID namespace, and a process of another user can be hidden
# from /proc (hidepid).
gate_label='sloop.quality-gate.owner'

# The PID namespace of this shell, or "-" where there is none to read (macOS).
gate_pid_namespace() {
    local ns
    ns=$(readlink /proc/self/ns/pid 2> /dev/null) || ns=''
    [[ $ns =~ ^[^[:space:]]+$ ]] || ns='-'
    printf '%s' "$ns"
}

# Whether process $1 on this host is still running.
#
# Errs toward "running": a server left up a little longer costs memory, while
# one removed under a live run fails that run's tests for nothing. kill -0 alone
# would not do, since it also fails for a process owned by another user.
#
# $1 the process id
gate_process_alive() {
    [[ $1 =~ ^[1-9][0-9]*$ ]] || return 0

    if [ -d /proc/self ]; then
        [ -d "/proc/$1" ]
        return
    fi

    local err
    err=$(LC_ALL=C kill -0 "$1" 2>&1) && return 0
    case "$err" in
        *'No such process'*) return 1 ;;
        *) return 0 ;;
    esac
}

# The servers left behind by runs that this run can see are gone.
#
# Reads "<container id> <label value>" lines on stdin, as `docker ps` writes
# them below, and prints the ids to remove. The label value is
# "<host> <pid namespace> <uid> <pid>"; the host is read as everything before the
# last three words, so a host name with a space still compares whole.
#
# Removed only when the host, the PID namespace and the user all match this run
# and the process is gone. Anything else is left alone: a run elsewhere, one
# whose processes this run cannot see, or a line that does not have the shape
# this gate writes. The id has to look like a container id too, since a label
# value can hold a newline and so put a line of its own making here.
#
# $1 this host's name, $2 this run's PID namespace, $3 this run's uid
stale_gate_servers() {
    local host="$1" namespace="$2" uid="$3"
    local id owner rest owner_pid owner_uid owner_namespace owner_host
    while read -r id owner; do
        [[ $id =~ ^[0-9a-f]{12,64}$ ]] || continue
        owner_pid=${owner##* }
        rest=${owner% *}
        owner_uid=${rest##* }
        rest=${rest% *}
        owner_namespace=${rest##* }
        owner_host=${rest% *}
        # Fewer than four words: the trims above stop changing the value.
        [ "$owner_host" != "$rest" ] || continue
        [ "$owner_host" = "$host" ] || continue
        [ "$owner_namespace" = "$namespace" ] || continue
        [ "$owner_uid" = "$uid" ] || continue
        [[ $owner_pid =~ ^[1-9][0-9]*$ ]] || continue
        if ! gate_process_alive "$owner_pid"; then
            printf '%s\n' "$id"
        fi
    done
}

# Remove the servers this run started. Called only from the EXIT trap, which
# the linter does not follow (SC2329).
#
# Found by this run's label as well as by the ids it registered: an interrupt
# during `docker run` stops the client before the id comes back, while the
# daemon still creates and starts the server. Removing by id alone left one
# server running in each of 5 interrupts at that point; by label, none.
# shellcheck disable=SC2329
remove_gate_servers() {
    local ids
    ids=$(docker ps -aq --filter "label=$gate_label=$gate_owner" 2> /dev/null)
    if [ "${#gate_servers[@]}" -gt 0 ]; then
        # Expanded only after the count check: bash 3.2 treats an empty array
        # as unset under `set -u`.
        ids="$ids ${gate_servers[*]}"
    fi
    if [ -n "${ids// /}" ]; then
        # Word splitting is wanted: one id per word.
        # shellcheck disable=SC2086
        docker rm -f $ids > /dev/null 2>&1
    fi
}

# Start one Integration server and print its container id.
#
# Not --rm: a server that dies while starting would take its log with it, and
# the log is what says why. The EXIT trap and the sweep of leftovers remove it.
#
# The data directory is tmpfs: the schema is rebuilt by the tests on every run,
# so nothing on it is worth keeping, and a server that cannot be damaged by an
# unclean shutdown is one less thing to recover. The port is bound to loopback
# and picked by the system, so parallel runs never ask for the same one.
#
# $1 image, $2 the prefix of the image's environment variables (MYSQL / MARIADB)
start_gate_server() {
    docker run -d \
        --label "$gate_label=$gate_owner" \
        --tmpfs /var/lib/mysql \
        -p 127.0.0.1::3306 \
        -e "$2_ROOT_PASSWORD=root" \
        -e "$2_DATABASE=sloop_test" \
        -e "$2_USER=sloop" \
        -e "$2_PASSWORD=secret" \
        "$1"
}

# The host port of server $1, or nothing when it has none.
#
# $1 container id
gate_server_port() {
    docker port "$1" 3306/tcp 2> /dev/null | sed -n 's/^127\.0\.0\.1:\([0-9][0-9]*\)$/\1/p' | head -n 1
}

# Wait until server $1 accepts the test user over TCP, for at most $2 seconds.
#
# TCP rather than the socket: both images bring up a temporary server without
# networking while they create the database and the user, and the socket answers
# then, before the user exists. Gives up early when the container has stopped,
# which is how a server that fails to start shows itself.
#
# $1 container id, $2 seconds
wait_for_gate_server() {
    local deadline=$((SECONDS + $2))
    while [ "$SECONDS" -lt "$deadline" ]; do
        if docker exec "$1" mysql -h127.0.0.1 -usloop -psecret sloop_test \
            -e 'SELECT 1' > /dev/null 2>&1; then
            return 0
        fi
        if [ "$(docker inspect -f '{{.State.Running}}' "$1" 2> /dev/null)" != 'true' ]; then
            return 1
        fi
        sleep 1
    done
    return 1
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

# Start the Integration servers now and wait for them later, so that their boot
# overlaps the static checks instead of adding to the run.
gate_servers=()
gate_server_error=''
if [ "$with_integration" -eq 1 ]; then
    gate_host=$(hostname)
    gate_namespace=$(gate_pid_namespace)
    gate_owner="$gate_host $gate_namespace $(id -u) $$"

    # bash runs the EXIT trap when TERM or HUP ends it; the INT trap makes Ctrl-C
    # do the same on every bash this runs under. Only kill -9 skips it, and the
    # next run's sweep below picks that up. TERM is not trapped: a trap would
    # wait for the gate in the foreground (Infection, up to minutes) before
    # cleaning up. Untrapped, the servers go at once, but a TERM sent to this
    # script alone still leaves that foreground gate running to its end, with
    # the worktree lock it inherited; signal the process group to stop both.
    trap remove_gate_servers EXIT
    trap 'exit 130' INT

    if ! command -v docker > /dev/null 2>&1; then
        gate_server_error='docker is not installed'
    else
        leftovers=$(docker ps -a --filter "label=$gate_label" \
            --format "{{.ID}} {{.Label \"$gate_label\"}}" 2> /dev/null \
            | stale_gate_servers "$gate_host" "$gate_namespace" "$(id -u)")
        if [ -n "$leftovers" ]; then
            # Word splitting is wanted: one id per word.
            # shellcheck disable=SC2086
            docker rm -f $leftovers > /dev/null 2>&1
            printf '\n  (removed %s server(s) left by a run that is gone)\n' \
                "$(printf '%s\n' "$leftovers" | wc -l | tr -d ' ')"
        fi

        for spec in "$mysql_image MYSQL" "$mariadb_image MARIADB"; do
            # stdout carries the id and nothing else; stderr is kept apart because
            # docker writes the progress of an image pull there, and the id has to
            # be recognised even then. An id that comes back is registered whatever
            # stderr says, so the EXIT trap removes every server that did start.
            # stderr is shown only when no id came back: the reason docker gives
            # (no daemon, out of memory) is then the only useful thing to show.
            run_err=$(mktemp) || exit 1
            started=$(start_gate_server "${spec% *}" "${spec#* }" 2> "$run_err")
            if [[ $started =~ ^[0-9a-f]{64}$ ]]; then
                gate_servers+=("$started")
            else
                gate_server_error="could not start ${spec% *}: $(cat "$run_err")"
            fi
            rm -f "$run_err"
            [ -z "$gate_server_error" ] || break
        done
    fi
fi

names=()
codes=()
counts=()

# Gates whose output carries no count, with the reason shown at the end of the run.
excluded_note='PHPStan, Rector and composer audit report no count (verified with -v,
       --error-format=json and --format=json). composer validate reports
       none either (verified with -v; it has no --format). shellcheck reports
       none either; the number of files comes from git ls-files, which is an
       input-side number and would only show that work existed. Mutation
       baseline runs its own check: it rejects an Infection report whose
       totalMutantsCount is 0.'

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
        'PHPUnit' | 'Integration (MySQL)' | 'Integration (MariaDB)')
            # "OK (1307 tests, 3332 assertions)" when green, "Tests: 1307, ..." when not.
            sed -n -e 's/.*OK (\([0-9][0-9]*\) tests\?,.*/\1/p' \
                   -e 's/^Tests: \([0-9][0-9]*\),.*/\1/p' "$plain" | tail -n 1
            ;;
        'composer deps')
            # "(scanned 156 files in 0.053 s)"
            sed -n 's/.*scanned \([0-9][0-9]*\) files.*/\1/p' "$plain" | tail -n 1
            ;;
        'actionlint')
            # "verbose: Collected 1 YAML files", from the -verbose run below.
            sed -n 's/.*Collected \([0-9][0-9]*\) YAML files.*/\1/p' "$plain" | tail -n 1
            ;;
        'gitleaks')
            # "247 commits scanned."
            sed -n 's/.*[^0-9]\([0-9][0-9]*\) commits scanned.*/\1/p' "$plain" | tail -n 1
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
# A composer.lock that no longer matches composer.json installs what the lock
# says, not what composer.json declares, and nothing else here notices: a
# reordered require leaves a stale content-hash, an added extension a stale
# platform. --strict also fails on the warnings it would otherwise only print.
run_gate 'composer validate' composer validate --strict

# typos is installed per environment, not via composer; skip when absent.
if command -v typos > /dev/null 2>&1; then
    run_gate 'typos' typos
else
    printf '\n  (typos not installed, skipped. apk add typos / cargo install typos-cli)\n'
fi

# actionlint reads the run: blocks through shellcheck when it is on PATH, so the
# workflow's shell is checked here as well as the workflow syntax. -verbose is
# what makes it report how many files it collected.
#
# It also turns that rule off without failing when it cannot run shellcheck, and
# neither the exit code nor the file count changes when it does. A run that
# checked the shell and one that did not look the same, which is the state this
# script exists to catch, so the notice it writes under -verbose is read here.
# run_gate takes the command as its arguments, so this function is called by
# name and the call site is not visible to the linter (SC2329).
# shellcheck disable=SC2329
run_actionlint() {
    local out
    out=$(mktemp) || return 1
    actionlint -verbose > "$out" 2>&1
    local code=$?

    cat "$out"

    # Absence is already reported by the shellcheck gate below. What is left is
    # the case where shellcheck is installed and the rule was disabled anyway.
    if command -v shellcheck > /dev/null 2>&1 &&
        grep -q 'Rule "shellcheck" was disabled' "$out"; then
        echo 'shellcheck is installed but actionlint did not use it' >&2
        code=1
    fi

    rm -f "$out"
    return "$code"
}

if command -v actionlint > /dev/null 2>&1; then
    run_gate 'actionlint' run_actionlint
else
    printf '\n  (actionlint not installed, skipped. https://github.com/rhysd/actionlint/releases)\n'
fi

# The scripts in this directory decide whether every other gate passes, so a
# mistake in their shell is a mistake in all of them.
# run_gate takes the command as its arguments, so this function is called by
# name and the call site is not visible to the linter (SC2329).
# shellcheck disable=SC2329
run_shellcheck() {
    local files=()
    while IFS= read -r file; do
        files+=("$file")
    done < <(git ls-files '*.sh')

    # No files means the selection broke, not that there is nothing to check.
    if [ "${#files[@]}" -eq 0 ]; then
        echo 'git ls-files matched no shell script' >&2
        return 1
    fi

    shellcheck "${files[@]}"
}

if command -v shellcheck > /dev/null 2>&1; then
    run_gate 'shellcheck' run_shellcheck
else
    printf '\n  (shellcheck not installed, skipped. apk add shellcheck. actionlint cannot read run: blocks without it)\n'
fi

# GitHub's own secret scanning covers the pushed repository, but only for the
# patterns of providers it partners with; this adds the generic ones and reads
# the whole history. git mode, not dir: the working tree holds vendor/ and tool
# caches, whose contents match the generic patterns by chance.
if command -v gitleaks > /dev/null 2>&1; then
    run_gate 'gitleaks' gitleaks git --no-banner --redact .
else
    printf '\n  (gitleaks not installed, skipped. https://github.com/gitleaks/gitleaks/releases)\n'
fi

if [ "$with_integration" -eq 1 ]; then
    integration_ready=1
    if [ -n "$gate_server_error" ]; then
        printf '\n  (%s)\n' "$gate_server_error"
        integration_ready=0
    else
        ports=()
        for id in "${gate_servers[@]}"; do
            port=$(gate_server_port "$id")
            if [ -z "$port" ] || ! wait_for_gate_server "$id" 180; then
                printf '\n  (server %s did not become ready; its last log lines:)\n' "${id:0:12}"
                docker logs --tail 20 "$id" 2>&1
                integration_ready=0
                break
            fi
            ports+=("$port")
        done
    fi

    if [ "$integration_ready" -eq 1 ]; then
        # Every connection setting is passed, not only the port: env inherits the
        # caller's environment, and a DB_NAME or DB_USER exported in the shell
        # would otherwise reach the tests and point them at something else.
        printf '\n  (integration servers: MySQL on %s, MariaDB on %s)\n' "${ports[0]}" "${ports[1]}"
        run_gate 'Integration (MySQL)' env DB_HOST=127.0.0.1 DB_PORT="${ports[0]}" \
            DB_NAME=sloop_test DB_USER=sloop DB_PASS=secret \
            vendor/bin/phpunit --testsuite=Integration
        run_gate 'Integration (MariaDB)' env DB_HOST=127.0.0.1 DB_PORT="${ports[1]}" \
            DB_NAME=sloop_test DB_USER=sloop DB_PASS=secret \
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
