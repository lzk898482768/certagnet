<?php
// +----------------------------------------------------------------------
// | 数据库配置
// +----------------------------------------------------------------------
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'type'        => env('database.type', 'mysql'),
            'hostname'    => env('database.hostname', '127.0.0.1'),
            'database'    => env('database.database', 'cert_agent'),
            'username'    => env('database.username', 'root'),
            'password'    => env('database.password', ''),
            'hostport'    => env('database.hostport', '3306'),
            'charset'     => env('database.charset', 'utf8mb4'),
            'prefix'      => env('database.prefix', ''),
            'params'      => [],
            'debug'       => true,
            'deploy'      => 0,
            'rw_separate' => false,
            'master_num'  => 1,
            'slave_no'    => '',
            'fields_strict'   => true,
            'resultset_type'  => 'collection',
            'auto_timestamp'   => false,
            'datetime_format'  => 'Y-m-d H:i:s',
            'sql_explain'      => false,
        ],
    ],
];
