<?php

declare(strict_types=1);

return [
    'connections'     => [
        'main' => [
            'read' => [['host' => 'replica-1'], ['host' => 'replica-2']],
        ],
    ],
    'emptied'         => [],
    'headers'         => [],
    'list_to_assoc'   => ['key' => 'value'],
    'assoc_to_list'   => ['a'],
    'empty_to_list'   => ['x'],
    'scalar_to_list'  => ['x'],
    'new_list'        => ['x', 'y'],
    'scalar_to_assoc' => ['key' => 'value'],
    'assoc_to_scalar' => 'single',
    'mixed'           => ['key' => 'value', 0 => ['x']],
    'gapped'          => array_filter(['', 'prod']),
    'int_map'         => [2 => 10],
];
