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

    // 门店端独立登录：统一员工账号 → 可进入门店列表 → 门店绑定会话。
    Route::post('login', 'StoreLogin/login')->option(['real_name' => '门店端账号登录']);

    /**
     * 需登录、强制门店、按角色校验
     */
    Route::group(function () {
        Route::post('session/switch-store', 'StoreLogin/switchStore')
            ->option(['real_name' => '门店端切换门店']);
        Route::post('session/change-password', 'StoreLogin/changePassword')
            ->option(['real_name' => '门店端修改当前账号密码']);
        Route::post('session/logout', 'StoreLogin/logout')
            ->option(['real_name' => '门店端退出登录']);
        Route::get('business-config/checkout-catalog', 'BusinessConfig/checkoutCatalog')
            ->option(['real_name' => '读取收银来源与记账方式']);
        // 报表只读统一事实服务；门店范围由 V3 会话权限生成，客户端只能缩小范围。
        Route::get('report/unified/catalog', 'Report/catalog')
            ->option(['real_name' => '收银V3经营报表目录']);
        Route::get('report/unified/scope', 'Report/scope')
            ->option(['real_name' => '收银V3经营报表权限范围']);
        Route::get('report/unified/query', 'Report/query')
            ->option(['real_name' => '收银V3经营报表查询']);
        Route::get('report/unified/export', 'Report/export')
            ->option(['real_name' => '收银V3经营报表导出']);
        Route::get('report/operations/categories', 'Report/operationsCategories')
            ->option(['real_name' => '收银V3门店运营商品分类']);
        Route::post('report/operations/category', 'Report/saveCategory')
            ->option(['real_name' => '收银V3保存合作方分类配置']);
        Route::get('report/operations/annotations', 'Report/annotations')
            ->option(['real_name' => '收银V3门店运营补充记录']);
        Route::post('report/operations/annotation', 'Report/saveAnnotation')
            ->option(['real_name' => '收银V3保存门店运营补充记录']);
        // 统一命令网关：白名单 action + 幂等键 + 多对象 contexts 严格校验
        Route::post('workbenches/actions', 'Command/dispatchAction')
            ->option(['real_name' => '收银V3命令网关']);
        Route::post('hang-drafts/delete', 'HangDraft/delete')
            ->option(['real_name' => '挂单草稿直接删除']);
        Route::post('hang-drafts/save', 'HangDraft/save')
            ->option(['real_name' => '挂单草稿直接保存']);
        Route::post('hang-drafts/resume', 'HangDraft/resume')
            ->option(['real_name' => '挂单草稿直接提取']);
        Route::post('cashier-drafts/clear', 'HangDraft/clearCart')
            ->option(['real_name' => '收银草稿直接清空']);
        Route::post('cashier-drafts/discard-checkout', 'HangDraft/discardCheckout')
            ->option(['real_name' => '收银失败结账草稿废弃']);
        Route::post('cashier-drafts/validate-guide-round', 'HangDraft/validateGuideRound')
            ->option(['real_name' => '收银导购轮次结账前校验']);
        Route::post('customer-care/actions', 'CustomerCare/action')
            ->option(['real_name' => '门店PC客情工作台']);
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

// 这些入口复用既有门店控制器，必须放在 cashier.v3.* 前缀组外，
// 否则会被解析为 app\\controller\\cashier\\v3\\store\\staff\\StoreStaff。
Route::group('cashierapi/v3/management', function () {
    Route::get('staff/read/:id', 'store.staff.StoreStaff/read')
        ->option(['real_name' => 'V3读取门店员工']);
    Route::get('staff/person-complete/:id', 'store.staff.StoreStaff/personComplete')
        ->option(['real_name' => 'V3读取员工完整资料']);
    Route::get('staff/positions', 'store.staff.StoreStaff/selectablePositions')
        ->option(['real_name' => 'V3读取可选岗位']);
    Route::get('staff/work-members', 'store.staff.StoreStaff/getWorkMemberList')
        ->option(['real_name' => 'V3读取企业微信成员']);
    Route::post('staff/:id', 'store.staff.StoreStaff/save')
        ->option(['real_name' => 'V3保存门店员工']);
    Route::post('file/upload', 'store.file.SystemAttachment/upload')
        ->option(['real_name' => 'V3上传员工头像']);
})->middleware([
    AuthTokenMiddleware::class,
    ForceStoreSessionMiddleware::class,
    CashierCheckRoleMiddleware::class,
    InstallMiddleware::class,
    AllowOriginMiddleware::class,
    StationOpenMiddleware::class,
])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'cashier');

// 房间资料控制器属于既有门店控制器命名空间 app\controller\store，不能放在
// 上面的 cashier.v3.* 控制器前缀组内，否则会被解析为
// app\controller\cashier\v3\store\system\RoomSettings。
Route::group('cashierapi/v3/management/room-settings', function () {
    Route::get('', 'store.system.RoomSettings/index')
        ->option(['real_name' => 'V3房间设置列表']);
    Route::post('', 'store.system.RoomSettings/create')
        ->option(['real_name' => 'V3新增房间']);
    Route::post('sort', 'store.system.RoomSettings/sort')
        ->option(['real_name' => 'V3房间排序']);
    Route::put(':id', 'store.system.RoomSettings/update')
        ->option(['real_name' => 'V3编辑房间']);
    Route::post(':id/status', 'store.system.RoomSettings/setStatus')
        ->option(['real_name' => 'V3更新房间状态']);
})->middleware([
    AuthTokenMiddleware::class,
    ForceStoreSessionMiddleware::class,
    CashierCheckRoleMiddleware::class,
    InstallMiddleware::class,
    AllowOriginMiddleware::class,
    StationOpenMiddleware::class,
])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'cashier');
