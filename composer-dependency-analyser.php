<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// ext-apcu is optional at runtime. ApcuDeadReplicaCache::isAvailable() checks for
// it via function_exists() and falls back to InMemoryDeadReplicaCache when absent.
// It is not required of users, so it is not in require; composer.json advertises
// it via suggest instead. It sits in require-dev to run the apcu code path tests.
return (new Configuration())
    ->ignoreErrorsOnExtension('ext-apcu', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
