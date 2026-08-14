<?php

use app\http\middleware\AllowOriginMiddleware;
use app\http\middleware\InstallMiddleware;
use app\http\middleware\StationOpenMiddleware;
use app\http\middleware\mobile\MobileAppSessionMiddleware;
use app\http\middleware\mobile\MobileMerchantPasswordMiddleware;
use app\http\middleware\mobile\MobileMerchantSessionMiddleware;
use app\http\middleware\mobile\MobilePublicMiddleware;
use app\http\middleware\mobile\MobileTransportEnvelopeMiddleware;
use app\services\mobile\protocol\MobileApiException;
use app\services\mobile\protocol\MobileApiResponse;
use think\facade\Route;

/* New mobile APIs use only mobile-auth-v1 / mobile-merchant-v1 credentials. */
Route::group('api/mobile/auth', function () {
    Route::post('captcha/challenges', 'MobileAuth/createCaptcha')->option(['real_name' => '手机验证码人机挑战']);
    Route::post('captcha/verify', 'MobileAuth/verifyCaptcha')->option(['real_name' => '手机验证码人机验证']);
    Route::post('sms/challenges', 'MobileAuth/createSmsChallenge')->option(['real_name' => '手机短信挑战']);
    Route::post('sms/verify', 'MobileAuth/verifySmsChallenge')->option(['real_name' => '手机短信验证']);
})->prefix('mobile.')->middleware([
    InstallMiddleware::class,
    AllowOriginMiddleware::class,
    StationOpenMiddleware::class,
    MobilePublicMiddleware::class,
    // ThinkPHP runs grouped middleware from right to left; keep the renderer outermost.
    MobileTransportEnvelopeMiddleware::class,
]);

Route::group('api/mobile/merchant', function () {
    Route::post('password-login', 'MerchantSession/passwordLogin')->option(['real_name' => '手机商家端员工账号登录'])
        ->middleware([MobileMerchantPasswordMiddleware::class]);
    Route::group('', function () {
        Route::post('session', 'MerchantSession/create')->option(['real_name' => '手机商家端创建会话']);
    })->middleware([MobileAppSessionMiddleware::class]);
    Route::group('', function () {
        Route::get('bootstrap', 'MerchantSession/bootstrap')->option(['real_name' => '手机商家端根状态']);
        Route::post('context/switch', 'MerchantSession/switchContext')->option(['real_name' => '手机商家端切换上下文']);
        Route::post('logout', 'MerchantSession/logout')->option(['real_name' => '手机商家端退出']);
		Route::get('personal-monthly-target/current', 'PersonalMonthlyTarget/current')->option(['real_name' => '手机端本月个人目标']);
		Route::post('personal-monthly-target', 'PersonalMonthlyTarget/save')->option(['real_name' => '手机端保存个人目标']);
        Route::post('customers/query', 'Customer/query')->option(['real_name' => '手机商家端客户列表']);
        Route::post('customers/exclusive/query', 'Customer/exclusive')->option(['real_name' => '手机商家端专属客户']);
        Route::post('customers/service-records', 'Customer/serviceRecords')->option(['real_name' => '手机商家端客户服务记录']);
        Route::post('customers/recent-summary', 'Customer/recentSummary')->option(['real_name' => '手机商家端客户最近业务摘要']);
        Route::post('customers/order-records', 'Customer/orderRecords')->option(['real_name' => '手机商家端客户全部订单记录']);
        Route::post('customers/order-record-detail', 'Customer/orderRecordDetail')->option(['real_name' => '手机商家端客户订单详情']);
        Route::post('customers/asset-records', 'Customer/assetRecords')->option(['real_name' => '手机商家端客户资产明细']);
        Route::get('customers/profile-draft', 'Customer/profileDraft')->option(['real_name' => '手机商家端完整客户建档草稿']);
        Route::post('customers/profile-avatar', 'Customer/profileAvatarUpload')->option(['real_name' => '手机商家端客户头像上传']);
        Route::get('customers/:memberId/profile', 'Customer/profileDetail')->pattern(['memberId' => '\\d+'])->option(['real_name' => '手机商家端完整客户档案']);
        Route::post('customers/profile', 'Customer/profileCreate')->option(['real_name' => '手机商家端完整新建客户']);
        Route::put('customers/:memberId/profile', 'Customer/profileUpdate')->pattern(['memberId' => '\\d+'])->option(['real_name' => '手机商家端完整更新客户']);
        Route::post('customers', 'Customer/create')->option(['real_name' => '手机商家端新建客户']);
        Route::get('customers/unified-query-capabilities', 'Customer/unifiedQueryCapabilities')->option(['real_name' => '手机商家端客户统一查询能力']);
        Route::post('customers/unified-query/commands', 'Customer/unifiedQueryCommand')->option(['real_name' => '手机商家端客户统一查询操作']);
        Route::get('customer-audiences', 'Customer/audiences')->option(['real_name' => '手机商家端我的客群']);
        Route::post('customer-audiences', 'Customer/createAudience')->option(['real_name' => '手机商家端创建客群']);
        Route::get('customer-audiences/:audienceId/overview', 'Customer/audienceOverview')->pattern(['audienceId' => '\\d+'])->option(['real_name' => '手机商家端客群概况']);
        Route::post('customer-audiences/:audienceId/members/query', 'Customer/audienceMembers')->pattern(['audienceId' => '\\d+'])->option(['real_name' => '手机商家端客群实时成员']);
        Route::patch('customer-audiences/:audienceId', 'Customer/updateAudience')->option(['real_name' => '手机商家端更新客群']);
        Route::delete('customer-audiences/:audienceId', 'Customer/archiveAudience')->option(['real_name' => '手机商家端归档客群']);
        Route::post('customer-care/workbench', 'CustomerCare/workbench')->option(['real_name' => '手机商家端客情工作台']);
        Route::post('customer-care/actions/:action', 'CustomerCare/action')
            ->pattern(['action' => '[A-Za-z0-9-]+'])
            ->option(['real_name' => '手机商家端客情操作']);
        Route::post('warehouse/overview', 'Warehouse/overview')->option(['real_name' => '手机商家端组织业绩数仓']);
        Route::get('reservations', 'Reservation/listing')->option(['real_name' => '手机商家端预约列表']);
        Route::get('reservations/:reservationId', 'Reservation/detail')->pattern(['reservationId' => '\\d+'])->option(['real_name' => '手机商家端预约详情']);
        Route::post('reservations/editor', 'Reservation/editor')->option(['real_name' => '手机商家端预约编辑准备']);
        Route::post('reservations/project-catalog', 'Reservation/projectCatalog')->option(['real_name' => '手机商家端预约项目目录']);
        Route::post('reservations/member-candidates', 'Reservation/memberCandidates')->option(['real_name' => '手机商家端预约会员选择']);
        Route::post('reservations/recalculate', 'Reservation/recalculate')->option(['real_name' => '手机商家端预约时间计算']);
        Route::post('reservations', 'Reservation/create')->option(['real_name' => '手机商家端创建预约']);
        Route::put('reservations/:reservationId', 'Reservation/update')->pattern(['reservationId' => '\\d+'])->option(['real_name' => '手机商家端编辑预约']);
        Route::delete('reservations/:reservationId', 'Reservation/delete')->pattern(['reservationId' => '\\d+'])->option(['real_name' => '手机商家端删除预约']);
        Route::post('reservations/:reservationId/start-service', 'Reservation/startService')->pattern(['reservationId' => '\\d+'])->option(['real_name' => '手机商家端开始服务']);
        Route::post('reservations/:reservationId/end-service', 'Reservation/endService')->pattern(['reservationId' => '\\d+'])->option(['real_name' => '手机商家端结束服务']);
    })->middleware([MobileMerchantSessionMiddleware::class]);
})->prefix('mobile.merchant.')->middleware([
    InstallMiddleware::class,
    AllowOriginMiddleware::class,
    StationOpenMiddleware::class,
    // This must also capture failures from the app/merchant session middleware.
    MobileTransportEnvelopeMiddleware::class,
]);

Route::any('api/mobile/:path', function () {
    throw MobileApiException::protocol('ROUTE_NOT_FOUND', '移动接口不存在。', 'path');
})->pattern(['path' => '.*'])->middleware([MobileTransportEnvelopeMiddleware::class]);
