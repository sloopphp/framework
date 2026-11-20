<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// ext-apcu is optional at runtime. ApcuDeadReplicaCache::isAvailable() checks for
// it via function_exists() and falls back to InMemoryDeadReplicaCache when absent.
// It is not required of users, so it is not in require; composer.json advertises
// it via suggest instead. It sits in require-dev to run the apcu code path tests.
//
// ext-bcmath is optional in the same way: DecimalRule::asNumber() needs it and
// throws when it is missing, while every other decimal feature works without it.
//
// sloop\http\request\REQUEST is Sloop\Http\Request\Request spelled in another
// case on purpose: RequestCaseInRulesRequest checks that rules() refuses it. PHP
// resolves it, but the analyser looks it up as a new file and finds none.
return (new Configuration())
    ->ignoreErrorsOnExtension('ext-apcu', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnExtension('ext-bcmath', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreUnknownClasses(['sloop\http\request\REQUEST']);
