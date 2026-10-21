<?php

declare(strict_types=1);

return [
    'connections'     => [
        'main' => [
            'host' => 'db',
            'read' => [['host' => 'replica-dev']],
        ],
    ],
    'emptied'         => ['a', 'b'],
    'empty_to_list'   => [],
    'scalar_to_list'  => 'single',
    'untouched'       => ['a', 'b'],
    'scalar_to_assoc' => 'single',
    'assoc_to_scalar' => ['key' => 'value'],
    'gapped'          => ['dev'],
    'int_map'         => [2 => 5, 3 => 1, 0 => true],
];
