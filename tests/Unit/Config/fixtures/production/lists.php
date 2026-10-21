<?php

declare(strict_types=1);

return [
    'connections'     => [
        'main' => [
            'read' => [['host' => 'replica-1'], ['host' => 'replica-2']],
        ],
    ],
    'emptied'         => [],
    'empty_to_list'   => ['x'],
    'scalar_to_list'  => ['x'],
    'new_list'        => ['x', 'y'],
    'scalar_to_assoc' => ['key' => 'value'],
    'assoc_to_scalar' => 'single',
    'gapped'          => array_filter(['', 'prod']),
    'int_map'         => [2 => 10],
];
