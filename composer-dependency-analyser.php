<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// ext-apcu は実行時に任意の拡張。ApcuDeadReplicaCache::isAvailable() が
// function_exists() で存在を確認し、無ければ InMemoryDeadReplicaCache に
// フォールバックする。利用者に必須とはしないため require には入れず、
// composer.json の suggest で案内している。
// require-dev にあるのは apcu 経路のテストを動かすため。
return (new Configuration())
    ->ignoreErrorsOnExtension('ext-apcu', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
