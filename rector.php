<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php84\Rector\Foreach_\ForeachToArrayAllRector;
use Rector\Php84\Rector\Foreach_\ForeachToArrayAnyRector;
use Rector\Php85\Rector\ArrayDimFetch\ArrayFirstLastRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // composer.json の require."php" (^8.5) から対象バージョンを自動判定する。
    // withPreparedSets() の codingStyle / naming は有効にしない。
    // バージョン起因でない書き換えが大量に混ざり、レビュー不能になるため。
    ->withPhpSets()
    ->withConfiguredRule(ClassPropertyAssignToConstructorPromotionRector::class, [
        // 既定 (true) だと、プロパティ名に合わせてコンストラクタ引数名をリネームする。
        // これは名前付き引数を壊す公開 API の破壊的変更になる。
        // 実例: LogManager の __construct(array $channels) が $channelConfigs に改名され、
        // new LogManager(channels: [...]) を使う呼び出しが 106 件のテストごと失敗した。
        // false にすると「引数名とプロパティ名が異なる場合は promotion 自体をスキップ」になる。
        ClassPropertyAssignToConstructorPromotionRector::RENAME_PROPERTY => false,
    ])
    ->withSkip([
        // docs/coding-standards.md および .claude/CLAUDE.md で
        // 「array_any() / array_all() は不採用（foreach の方が高速）」と定めているため、
        // PHP 8.4 セットに含まれるこの 2 ルールは適用しない。
        ForeachToArrayAnyRector::class,
        ForeachToArrayAllRector::class,

        // array_values($x)[0] → array_first($x) の変換。
        // array_first() は空配列で null を返すため戻り値の型が広がり、
        // 直後のプロパティアクセスが PHPStan level max で型エラーになる
        // （ConnectionTest で 11 箇所）。変換前のコードは既に型安全なので、
        // null 処理を足してまで置き換える利益がない。
        ArrayFirstLastRector::class,
    ]);
