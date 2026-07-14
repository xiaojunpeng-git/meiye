<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\facade\Env;

return [
    // 默认使用的数据库连接配置
    'default'         => Env::get('database.driver', 'mysql'),

    // 数据库连接配置信息
    'connections'     => [
        'mysql' => [
            // 数据库类型
            'type'            => Env::get('database.type', 'mysql'),
            // 服务器地址
            'hostname'        => Env::get('database.hostname', '127.0.0.1'),
            // 数据库名
            'database'        => Env::get('database.database', 'mohe31'),
            // 用户名
            'username'        => Env::get('database.username', 'root'),
            // 密码
            'password'        => Env::get('database.password', 'root'),
            // 端口
            'hostport'        => Env::get('database.hostport', '3306'),
            // 连接dsn
            'dsn'             => '',
            // 数据库连接参数
            'params'          => [],
            // 数据库编码默认采用utf8
            'charset'         => Env::get('database.charset', 'utf8mb4'),
            // 数据库表前缀
            'prefix'          => Env::get('database.prefix', 'eb_'),
            // 数据库调试模式
            'debug'           => Env::get('database.debug', true),
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 0,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => false,
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => true,
            // 字段缓存
            'fields_cache'    => !env('APP_DEBUG', false),
            // 是否需要进行SQL性能分析
            'sql_explain'     => false,
            // Builder类
            'builder'         => '',
            // Query类
            'query'           => '',
            // 是否需要断线重连
            'break_reconnect' => true,
        ],

        // 旧系统库（php think migrate 只读，不影响默认 mysql 连接）
        'old' => [
            'type'            => Env::get('database.type', 'mysql'),
            'hostname'        => Env::get('database.old_hostname', Env::get('database.hostname', '127.0.0.1')),
            'database'        => Env::get('database.old_database', 'morestore'),
            'username'        => Env::get('database.old_username', Env::get('database.username', 'root')),
            'password'        => Env::get('database.old_password', Env::get('database.password', '')),
            'hostport'        => Env::get('database.old_hostport', Env::get('database.hostport', '3306')),
            'charset'         => Env::get('database.charset', 'utf8mb4'),
            'prefix'          => Env::get('database.prefix', 'eb_'),
            'debug'           => Env::get('database.debug', true),
            'deploy'          => 0,
            'rw_separate'     => false,
            'fields_strict'   => false,
            'fields_cache'    => false,
            'break_reconnect' => true,
        ],

        // 迁移目标库（php think migrate 写入；日常业务仍走 mysql=DATABASE）
        'migrate' => [
            'type'            => Env::get('database.type', 'mysql'),
            'hostname'        => Env::get('database.migrate_hostname', Env::get('database.hostname', '127.0.0.1')),
            'database'        => Env::get('database.migrate_database', 'lin12'),
            'username'        => Env::get('database.migrate_username', Env::get('database.username', 'root')),
            'password'        => Env::get('database.migrate_password', Env::get('database.password', '')),
            'hostport'        => Env::get('database.migrate_hostport', Env::get('database.hostport', '3306')),
            'charset'         => Env::get('database.charset', 'utf8mb4'),
            'prefix'          => Env::get('database.prefix', 'eb_'),
            'debug'           => Env::get('database.debug', true),
            'deploy'          => 0,
            'rw_separate'     => false,
            'fields_strict'   => false,
            'fields_cache'    => false,
            'break_reconnect' => true,
        ],

        // 更多的数据库配置信息
    ],

    // 自定义时间查询规则
    'time_query_rule' => [],
    // 自动写入时间戳字段
    'auto_timestamp'  => 'timestamp',
    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',
    //数据分页配置
    'page' => [
        //页码key
        'pageKey' => 'page',
        //每页截取key
        'limitKey' => 'limit',
        //每页截取最大值
        'limitMax' => 100,
        //默认条数
        'defaultLimit' => 10,
    ]
];
