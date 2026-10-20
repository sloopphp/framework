<?php

declare(strict_types=1);

return [
    'connections'    => [
        'main' => [
            'host' => 'db',
            'read' => [['host' => 'replica-dev']],
        ],
    ],
    'emptied'        => ['a', 'b'],
    'headers'        => ['X-Frame-Options' => 'DENY'],
    'list_to_assoc'  => ['a'],
    'assoc_to_list'  => ['key' => 'value'],
    'empty_to_list'  => [],
    'scalar_to_list' => 'single',
    'untouched'      => ['a', 'b'],
    'scalar_to_assoc' => 'single',
    'assoc_to_scalar' => ['key' => 'value'],
];
