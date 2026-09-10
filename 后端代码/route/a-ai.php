<?php

use think\facade\Route;

// Alphabetically before admin.php/api-mobile.php/cashier.php catch-all routes.
// Deliberately outside SystemLogMiddleware: chat and API keys must never enter operation logs.
$aiActions=function () {
    Route::options(':path',function () { return response('ok'); })->pattern(['path'=>'.*']);
    Route::get('bootstrap','Ai/aiBootstrap')->completeMatch();
    Route::post('runs','Ai/aiCreate')->completeMatch();
    Route::get('runs/:runId','Ai/aiStatus')->pattern(['runId'=>'[a-f0-9]{48}'])->completeMatch();
    Route::post('runs/:runId/execute','Ai/aiExecute')->pattern(['runId'=>'[a-f0-9]{48}'])->completeMatch();
    Route::post('runs/:runId/clarify','Ai/aiClarify')->pattern(['runId'=>'[a-f0-9]{48}'])->completeMatch();
    Route::post('runs/:runId/cancel','Ai/aiCancel')->pattern(['runId'=>'[a-f0-9]{48}'])->completeMatch();
    Route::get('runs/:runId/export','Ai/aiExport')->pattern(['runId'=>'[a-f0-9]{48}'])->completeMatch();
    Route::get('config','Ai/aiConfigGet')->completeMatch();
    Route::put('config','Ai/aiConfigSave')->completeMatch();
    Route::post('config/check','Ai/aiConfigCheck')->completeMatch();
};
Route::group('adminapi/ai',function () use($aiActions) {
    $aiActions();
    Route::get('management','Ai/aiManagementGet')->completeMatch();
    Route::put('management/draft','Ai/aiManagementSave')->completeMatch();
    Route::post('management/validate','Ai/aiManagementValidate')->completeMatch();
    Route::post('management/publish','Ai/aiManagementPublish')->completeMatch();
    Route::post('management/rollback','Ai/aiManagementRollback')->completeMatch();
    Route::post('management/preview','Ai/aiManagementPreview')->completeMatch();
    Route::post('management/rebase','Ai/aiManagementRebase')->completeMatch();
    Route::get('metric-registry','Ai/aiMetricRegistryGet')->completeMatch();
})->prefix('admin.v1.ai.')->middleware([
    \app\http\middleware\AiRequestGuardMiddleware::class,
]);
Route::group('cashierapi/v3/ai',$aiActions)->prefix('cashier.v3.')->middleware([
    \app\http\middleware\AiRequestGuardMiddleware::class,
]);
Route::group('api/mobile/merchant/ai',$aiActions)->prefix('mobile.merchant.')->middleware([
    \app\http\middleware\AiRequestGuardMiddleware::class,
]);
