<?php

declare(strict_types=1);

use PrettyLinks\GroundLevel\Database\Column;
use PrettyLinks\GroundLevel\Database\DataType;
use PrettyLinks\GroundLevel\Database\Table;
use PrettyLinks\GroundLevel\Resque\Database;
use PrettyLinks\GroundLevel\Resque\ResqueServiceProvider;

return [
    'name'        => Database::completedJobsTableNameNoDBPrefix(),
    'description' => 'Stores completed job information.',
    'version'     => '20240228.1',
    'database'    => ResqueServiceProvider::SERVICE_DATABASE,
    'columns'     => [
        'id'         => [
            'description'   => 'The completed job ID.',
            'type'          => Column::TYPE_PRIMARY_ID,
            'autoIncrement' => false,
        ],
        'runtime'    => [
            'description' => 'The completed job runtime date.',
            'type'        => DataType::DATETIME,
            'allowNull'   => false,
        ],
        'firstrun'   => [
            'description' => 'The completed job first run date.',
            'type'        => DataType::DATETIME,
            'allowNull'   => false,
        ],
        'lastrun'    => [
            'description' => 'The completed job last run date.',
            'type'        => DataType::DATETIME,
            'allowNull'   => false,
        ],
        'priority'   => [
            'description' => 'The completed job priority.',
            'type'        => DataType::BIGINT,
            'length'      => 20,
            'default'     => 10,
        ],
        'tries'      => [
            'description' => 'The completed job tries.',
            'type'        => DataType::BIGINT,
            'length'      => 20,
            'default'     => 0,
        ],
        'class'      => [
            'description' => 'The completed job class.',
            'type'        => DataType::VARCHAR,
            'length'      => 255,
            'allowNull'   => false,
        ],
        'batch'      => [
            'description' => 'The completed job batch.',
            'type'        => DataType::VARCHAR,
            'length'      => 255,
            'allowNull'   => false,
        ],
        'args'       => [
            'description' => 'Optional completed job arguments.',
            'type'        => DataType::TEXT,
        ],
        'reason'     => [
            'description' => 'Optional completed job reason.',
            'type'        => DataType::TEXT,
        ],
        'status'     => [
            'description' => 'The completed job status.',
            'type'        => DataType::VARCHAR,
            'length'      => 255,
        ],
        'created_at' => [
            'description' => 'The completed job creation date.',
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
