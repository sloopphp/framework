#!/usr/bin/env bash
#
# Refuse a commit message whose subject does not start with a type prefix.
#
# Accepted subjects:
#   <type>: summary            type is feat, fix, docs, refactor, test, chore,
#                              style or ci
#   <type>(<scope>): summary   such as fix(tests): ...
# Subjects git writes itself are let through: Merge ..., Revert "...",
# fixup! / squash! / amend!.
#
# Used by .githooks/commit-msg with the path git passes it, and by CI with a
# file holding one commit's message. Lines starting with # are ignored, as git
# strips them from the message it records.
#
# Exit code: 0 when the subject is accepted, 1 when it is refused, 2 when the
# file cannot be read.

set -uo pipefail

if [ "$#" -ne 1 ] || [ ! -r "$1" ]; then
    echo "commit-msg: expected one readable message file, got: $*" >&2
    exit 2
fi

subject=''
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        '#'*) continue ;;
    esac
    if [ -n "$line" ]; then
        subject="$line"
        break
    fi
done < "$1"

if [[ "$subject" =~ ^(feat|fix|docs|refactor|test|chore|style|ci)(\([^\)]+\))?:\ [^[:space:]] ]]; then
    exit 0
fi

if [[ "$subject" =~ ^(Merge\ |Revert\ \"|fixup!\ |squash!\ |amend!\ ) ]]; then
    exit 0
fi

{
    echo "commit-msg: the subject needs a type prefix: feat, fix, docs, refactor, test, chore, style or ci."
    echo "  <type>: summary  or  <type>(<scope>): summary"
    echo "  got: ${subject:-(empty)}"
} >&2
exit 1
