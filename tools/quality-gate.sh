#!/usr/bin/env bash
#
# 品質ゲートを順に実行し、各コマンドの終了コードを集約して表示する。
#
# パイプで出力を絞ると上流の終了コードが失われる（`cmd | tail; echo $?` は tail の
# 0 を読む）。このスクリプトは各コマンドを単独で実行して終了コードを直接受け取り、
# 誤読が起きない形にすることだけを目的とする。検出そのものは各ツールが行う。
#
# 使い方:
#   tools/quality-gate.sh                      # 1〜5 を実行（数秒）
#   tools/quality-gate.sh --with-mutation      # infection も実行（約 1 分）
#   tools/quality-gate.sh --with-integration   # Integration も実行（docker compose up -d が必要）
#   tools/quality-gate.sh --all                # 全て実行
#
# 終了コード: いずれかのゲートが失敗したら 1、全て通れば 0。

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

with_mutation=0
with_integration=0

for arg in "$@"; do
    case "$arg" in
        --with-mutation) with_mutation=1 ;;
        --with-integration) with_integration=1 ;;
        --all) with_mutation=1; with_integration=1 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "不明な引数: $arg" >&2; exit 2 ;;
    esac
done

names=()
codes=()

run_gate() {
    local name="$1"
    shift

    printf '\n\033[1m▶ %s\033[0m\n' "$name"
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

if [ "$with_integration" -eq 1 ]; then
    run_gate 'Integration (3306)' vendor/bin/phpunit --testsuite=Integration
    run_gate 'Integration (3307)' env DB_PORT=3307 vendor/bin/phpunit --testsuite=Integration
fi

if [ "$with_mutation" -eq 1 ]; then
    run_gate 'Infection' vendor/bin/infection --threads=4 --no-progress
fi

printf '\n\033[1m=== 終了コード ===\033[0m\n'

failed=0
for i in "${!names[@]}"; do
    if [ "${codes[$i]}" -eq 0 ]; then
        printf '  \033[32mOK  \033[0m %-22s EXIT=%s\n' "${names[$i]}" "${codes[$i]}"
    else
        printf '  \033[31mFAIL\033[0m %-22s EXIT=%s\n' "${names[$i]}" "${codes[$i]}"
        failed=1
    fi
done

if [ "$with_mutation" -eq 0 ]; then
    printf '\n  (Infection は未実行。--with-mutation で実行する)\n'
fi

if [ "$with_integration" -eq 0 ]; then
    printf '  (Integration は未実行。--with-integration で実行する)\n'
fi

exit "$failed"
