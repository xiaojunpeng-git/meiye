<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

use app\http\middleware\AllowOriginMiddleware;
use app\http\middleware\InstallMiddleware;
use app\http\middleware\StationOpenMiddleware;
use app\http\middleware\cashier\AuthTokenMiddleware;
use app\http\middleware\cashier\CashierCheckRoleMiddleware;
use app\http\middleware\cashier\ForceStoreSessionMiddleware;
use think\facade\Route;

/**
 * 收银台 V3 路由（独立文件）。
 *
 * 单独成文件而不是并入 route/cashier.php：后者当前是脏工作区文件（核销批量、
 * 项目替换、组织登录选店专题未提交），并入会把未验收改动夹带进本任务。
 *
 * ⚠ 文件名必须排在 `cashier.php` 之前，不得改回 `cashier_v3.php`。
 * ThinkPHP 用 `glob(route/*.php)`（`vendor/topthink/framework/src/think/Http.php:240`）
 * 按文件名字母序加载路由；`route/cashier.php:386` 在 `cashierapi` 分组内注册了
 * `Route::miss()`，会把一切未在该分组内命中的 `cashierapi/*` 直接吞成空 404。
 * 只有本文件先于 `cashier.php` 注册，`cashierapi/v3/*` 才能命中本分组。
 * `-`(0x2D) < `.`(0x2E) < `_`(0x5F)，所以 `cashier-v3.php` 先加载，
 * 而 `cashier_v3.php` 会后加载并被 miss 路由吞掉。
 * 回归断言见 `任务管理/进行中/_local_evidence/20260727-C1A-命令幂等底座/route-smoke.php`。
 *
 * 门店与操作人一律来自会话：ForceStoreSessionMiddleware 会拒绝并覆盖客户端
 * 自带的 store_id，V3 接口不接受任何客户端传入的门店参数。
 */
Route::group('cashierapi/v3', function () {

    /**
     * 需登录、强制门店、按角色校验
     */
    Route::group(function () {
        // 统一命令网关：白名单 action + 幂等键 + 多对象 contexts 严格校验
        Route::post('workbenches/actions', 'Command/dispatchAction')
            ->option(['real_name' => '收银V3命令网关']);
        Route::get('unified-query/exports/:taskNo/download', 'Command/downloadUnifiedQueryExport')
            ->option(['real_name' => '统一查询导出下载']);
    })->middleware([
        AuthTokenMiddleware::class,
        ForceStoreSessionMiddleware::class,
        CashierCheckRoleMiddleware::class,
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'cashier');

})->prefix('cashier.v3.')->middleware([
    InstallMiddleware::class,
    AllowOriginMiddleware::class,
    StationOpenMiddleware::class,
]);
