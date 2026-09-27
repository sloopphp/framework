#!/usr/bin/env bash
#
# Scan what is about to be committed for secrets, and refuse the commit if any
# are found.
#
# The quality gate runs gitleaks over the history, which is too late for this:
# the gate is run before the commit, when what was just written is not in the
# history yet, and CI runs after the push, when it is already on the remote.
# This is the one point where a secret can be stopped before it leaves the
# machine.
#
# Enabled per clone with `git config core.hooksPath .githooks` (SETUP.md). The
# setting is shared by every worktree of the clone.
#
# Exit code: gitleaks' own, 1 when a secret is found. 0 when gitleaks is not
# installed, matching the quality gate, which skips it in that case too.

set -uo pipefail

if ! command -v gitleaks > /dev/null 2>&1; then
    echo 'pre-commit: gitleaks is not installed, so the staged changes were not scanned for secrets.' >&2
    echo '  https://github.com/gitleaks/gitleaks/releases' >&2
    exit 0
fi

exec gitleaks git --staged --no-banner --redact
