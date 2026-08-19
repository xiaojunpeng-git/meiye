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

// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 执行用户（Windows下无效）
    'user' => null,
    // 指令定义
    'commands' => [
        'make:dao' => \mohe\command\Dao::class,
        'make:service' => \mohe\command\Service::class,
        'install' => \mohe\command\Install::class,
        'makeSalary' => \app\command\MakeSalary::class,
        'clear:cache' => \app\command\ClearCache::class,
        'reset:password' => \app\command\ResetAdminPwd::class,
		'get:version' => \app\command\GetVersion::class,
        'reservationJindu' => \app\command\ReservationJindu::class,
        'migrate' => \app\command\Migrate::class,
        'unified-query:export-worker' => \app\command\UnifiedQueryExportWorker::class,
        'group-dashboard:aggregate' => \app\command\GroupManagementDashboardAggregate::class,
    ],
];
