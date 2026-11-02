<?php

use think\facade\Env;

return [
    'default' => 'redis',
    'connections' => [
        'sync' => [
            'type' => 'sync',
        ],
        'database' => [
            'type' => 'database',
            'queue' => 'default',
            'table' => 'jobs',
        ],
        'redis' => [
            'type' => 'redis',
            'queue' => Env::get('queue.listen_name', 'MOHE_PRO'),
            'batch_queue' => Env::get('queue.batch_listen_name', 'MOHE_PRO_BATCH'),
            'host' => Env::get('redis.redis_hostname', '127.0.0.1'),
            'port' => Env::get('redis.port', 6379),
            'password' => Env::get('redis.redis_password', ''),
            'select' => Env::get('redis.select', 0),
            'timeout' => 0,
            'persistent' => true,
        ],
        // 未消耗全门店快照可运行数分钟；预留时间必须长于专用 worker 的
        // 2400 秒上限，避免 Redis 在任务尚未完成时再次交付同一消息。
        'unconsumed_redis' => [
            'type' => 'redis',
            'queue' => 'MOHE_PRO_BASIC_UNCONSUMED',
            'host' => Env::get('redis.redis_hostname', '127.0.0.1'),
            'port' => Env::get('redis.port', 6379),
            'password' => Env::get('redis.redis_password', ''),
            'select' => Env::get('redis.select', 0),
            'timeout' => 0,
            'persistent' => true,
            'retry_after' => 2520,
        ],
    ],
    'failed' => [
        'type' => 'none',
        'table' => 'failed_jobs',
    ],
];
