<?php

declare(strict_types=1);

use PrettyLinks\GroundLevel\Database\Column;
use PrettyLinks\GroundLevel\Database\DataType;
use PrettyLinks\GroundLevel\Database\Table;
use PrettyLinks\GroundLevel\Events\EventsServiceProvider;
use PrettyLinks\GroundLevel\Events\Models\Event;

return [
    'name'        => Event::TABLE_NAME,
    'description' => 'Stores event information.',
    'version'     => '20240130.1',
    'database'    => EventsServiceProvider::SERVICE_DATABASE,
    'columns'     => [
        'id'       => [
            'description' => 'The event ID.',
            'type'        => Column::TYPE_PRIMARY_ID,
        ],
        'event'    => [
            'description' => 'The event type.',
            'type'        => DataType::VARCHAR,
            'length'      => 255,
        ],
        'args'     => [
            'description' => 'Optional event arguments.',
            'type'        => DataType::LONGTEXT,
        ],
        'obj_id'   => [
            'description' => 'The object ID.',
            'type'        => Column::TYPE_ID,
        ],
        'obj_type' => [
            'description' => 'The object type.',
            'type'        => DataType::VARCHAR,
            'length'      => 255,
            'allowNull'   => false,
        ],
        'created'  => [
            'description' => 'The event creation date.',
            'type'        => DataType::DATETIME,
            'allowNull'   => false,
        ],
    ],
    'keys'        => [
        'id'            => Table::KEY_PRIMARY,
        'event'         => [
            'length' => 191,
        ],
        'obj_id'        => Table::KEY_DEFAULT,
        'obj_type'      => [
            'length' => 191,
        ],
        'created'       => Table::KEY_DEFAULT,
        'obj_id_type'   => [
            'parts' => [
                'obj_id'   => null,
                'obj_type' => 191,
            ],
        ],
        'event_created' => [
            'parts' => [
                'event'   => 191,
                'created' => null,
            ],
        ],
    ],
];
