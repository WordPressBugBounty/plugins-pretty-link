<?php

declare(strict_types=1);

use PrettyLinks\GroundLevel\Database\Column;
use PrettyLinks\GroundLevel\Database\DataType;
use PrettyLinks\GroundLevel\Database\Table;
use PrettyLinks\GroundLevel\Resque\Database;
use PrettyLinks\GroundLevel\Resque\ResqueServiceProvider;

return [
    'name'        => Database::failedJobsTableNameNoDBPrefix(),
    'description' => 'Stores failed job information.',
    'version'     => '20240228.1',
    'database'    => ResqueServiceProvider::SERVICE_DATABASE,
    'columns'     => [
        'id'         => [
            'description'   => 'The failed job ID.',
            'type'          => Column::TYPE_PRIMARY_ID,
            'autoIncrement' => false,
        ],
        'runtime'    => [
            'description' => 'The failed job runtime date.',
            'type'        => DataType::DATETIME,
            'allowNull'   => false,
        ],
        'firstrun'   => [
            'description' => 'The failed job first run date.',
            'type'        => DataType::DATETIME,
            'allowNull'   => false,
        ],
        'lastrun'    => [
            'description' => 'The failed job first run date.',
            'type'        => DataType::DATETIME,
            'allowNull'   => false,
        ],
        'priority'   => [
            'description' => 'The failed job priority.',
            'type'        => DataType::BIGINT,
            'length'      => 20,
            'default'     => 10,
        ],
        'tries'      => [
            'description' => 'The failed job tries.',
            'type'        => DataType::BIGINT,
            'length'      => 20,
            'default'     => 0,
        ],
        'class'      => [
            'description' => 'The failed job class.',
            'type'        => DataType::VARCHAR,
            'length'      => 255,
            'allowNull'   => false,
        ],
        'batch'      => [
            'description' => 'The failed job batch.',
            'type'        => DataType::VARCHAR,
            'length'      => 255,
            'allowNull'   => false,
        ],
        'args'       => [
            'description' => 'Optional failed job arguments.',
            'type'        => DataType::TEXT,
        ],
        'reason'     => [
            'description' => 'Optional failed job reason.',
            'type'        => DataType::TEXT,
        ],
        'status'     => [
            'description' => 'The failed job status.',
            'type'        => DataType::VARCHAR,
            'length'      => 255,
        ],
        'created_at' => [
            'description' => 'The failed job creation date.',
            'type'        => DataType::DATETIME,
            'allowNull'   => false,
        ],
    ],
    'keys'        => [
        'id'         => Table::KEY_PRIMARY,
        'runtime'    => Table::KEY_DEFAULT,
        'firstrun'   => Table::KEY_DEFAULT,
        'lastrun'    => Table::KEY_DEFAULT,
        'status'     => [
            'length' => 191,
        ],
        'priority'   => Table::KEY_DEFAULT,
        'tries'      => Table::KEY_DEFAULT,
        'class'      => [
            'length' => 191,
        ],
        'batch'      => [
            'length' => 191,
        ],
        'created_at' => Table::KEY_DEFAULT,
    ],
];
