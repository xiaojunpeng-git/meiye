<?php

use app\http\middleware\AllowOriginMiddleware;
use app\http\middleware\InstallMiddleware;
use app\http\middleware\StationOpenMiddleware;
use app\http\middleware\store\AuthTokenMiddleware;
use app\http\middleware\store\ForceStoreSessionMiddleware;
use app\http\middleware\store\StoreCkeckRoleMiddleware;
use think\facade\Route;

// 独立路由文件避免把共享的 store.php 脏工作区改动夹入房间设置闭环。
Route::group('storeapi', function () {
    Route::group('room-settings', function () {
        Route::get('', 'system.RoomSettings/index')->option(['real_name' => '房间设置列表']);
        Route::post('', 'system.RoomSettings/create')->option(['real_name' => '新增房间']);
        Route::post('sort', 'system.RoomSettings/sort')->option(['real_name' => '房间排序']);
        Route::put(':id', 'system.RoomSettings/update')->option(['real_name' => '编辑房间']);
        Route::post(':id/status', 'system.RoomSettings/setStatus')->option(['real_name' => '启用停用房间']);
    })->middleware([
        AuthTokenMiddleware::class,
        ForceStoreSessionMiddleware::class,
        StoreCkeckRoleMiddleware::class,
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');
})->prefix('store.')->middleware([
    InstallMiddleware::class,
    AllowOriginMiddleware::class,
    StationOpenMiddleware::class,
]);
