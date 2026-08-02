#!/usr/bin/env bash
#
# 品質ゲートを順に実行し、各コマンドの終了コードを集約して表示する。
#
# パイプで出力を絞ると上流の終了コードが失われる（`cmd | tail; echo $?` は tail の
# 0 を読む）。このスクリプトは各コマンドを単独で実行して終了コードを直接受け取り、
# 誤読が起きない形にすることだけを目的とする。検出そのものは各ツールが行う。
#
# 使い方:
#   tools/quality-gate.sh                      # 静的チェックとテストを実行（数秒）
#   tools/quality-gate.sh --with-mutation      # infection も実行（約 1 分）
#   tools/quality-gate.sh --with-integration   # Integration も実行（docker compose up -d が必要）
#   tools/quality-gate.sh --all                # 全て実行
#
# 終了コード: いずれかのゲートが失敗したら 1、全て通れば 0。

set -uo pipefail

# cd したあとも参照するため、スクリプト自身の絶対パスを先に確定させる。
script_dir=$(cd "$(dirname "$0")" && pwd) || exit 1
script_path="$script_dir/$(basename "$0")"

cd "$script_dir/.." || exit 1

# 出力先が端末でない場合（ログへのリダイレクト、CI 等）は色を付けない。
if [ -t 1 ]; then
    bold=$'\033[1m'; green=$'\033[32m'; red=$'\033[31m'; reset=$'\033[0m'
else
    bold=''; green=''; red=''; reset=''
fi

with_mutation=0
with_integration=0

# 冒頭のコメントブロック（2 行目から最初の空行まで）をそのままヘルプとして使う。
usage() {
    sed -n '2,/^$/{ s/^#\{1,\} \{0,1\}//; p; }' "$script_path"
}

for arg in "$@"; do
    case "$arg" in
        --with-mutation) with_mutation=1 ;;
        --with-integration) with_integration=1 ;;
        --all) with_mutation=1; with_integration=1 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "不明な引数: $arg" >&2; usage >&2; exit 2 ;;
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

# typos は composer 依存ではなく各自の環境に入れるものなので、無い場合は飛ばす。
if command -v typos > /dev/null 2>&1; then
    run_gate 'typos' typos
else
    printf '\n  (typos 未インストールのためスキップ。apk add typos / cargo install typos-cli)\n'
fi

if [ "$with_integration" -eq 1 ]; then
    run_gate 'Integration (3306)' vendor/bin/phpunit --testsuite=Integration
    run_gate 'Integration (3307)' env DB_PORT=3307 vendor/bin/phpunit --testsuite=Integration
fi

if [ "$with_mutation" -eq 1 ]; then
    run_gate 'Infection' vendor/bin/infection --threads=4 --no-progress
fi

printf '\n%s=== 終了コード ===%s\n' "$bold" "$reset"

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
    printf '\n  (Infection は未実行。--with-mutation で実行する)\n'
fi

if [ "$with_integration" -eq 0 ]; then
    printf '  (Integration は未実行。--with-integration で実行する)\n'
fi

exit "$failed"
