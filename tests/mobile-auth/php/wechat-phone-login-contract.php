<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/protocol/MobileApiException.php';
require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/merchant/MobileMerchantWechatPhoneServices.php';

use app\services\mobile\merchant\MobileMerchantWechatPhoneServices;
use app\services\mobile\protocol\MobileApiException;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$rejects = static function (callable $work, string $code) use ($assert): void {
    try {
        $work();
        throw new RuntimeException('expected rejection: ' . $code);
    } catch (MobileApiException $exception) {
        $assert($exception->mobileCode() === $code, 'unexpected failure code: ' . $exception->mobileCode());
    }
};

$phoneInfo = ['purePhoneNumber' => '13800138000', 'countryCode' => '86', 'watermark' => ['appid' => 'wx-test']];
$assert(MobileMerchantWechatPhoneServices::phoneFromResponse(['errcode' => 0, 'phone_info' => $phoneInfo], 'wx-test') === '13800138000', 'verified phone');
$rejects(static function () use ($phoneInfo): void {
    MobileMerchantWechatPhoneServices::phoneFromResponse(['errcode' => 40029, 'phone_info' => $phoneInfo], 'wx-test');
}, 'WECHAT_PHONE_VERIFICATION_FAILED');
$rejects(static function () use ($phoneInfo): void {
    MobileMerchantWechatPhoneServices::phoneFromResponse(['errcode' => 0, 'phone_info' => $phoneInfo], 'another-app');
}, 'WECHAT_PHONE_VERIFICATION_FAILED');
$rejects(static function () use ($phoneInfo): void {
    MobileMerchantWechatPhoneServices::phoneFromResponse(['errcode' => 0, 'phone_info' => array_replace($phoneInfo, ['countryCode' => '1'])], 'wx-test');
}, 'WECHAT_PHONE_VERIFICATION_FAILED');
$assert(MobileMerchantWechatPhoneServices::employeeIdFromRows([['id' => 714, 'status' => 1]]) === 714, 'one active employee');
$rejects(static function (): void { MobileMerchantWechatPhoneServices::employeeIdFromRows([]); }, 'EMPLOYEE_PHONE_NOT_UNIQUE');
$rejects(static function (): void { MobileMerchantWechatPhoneServices::employeeIdFromRows([['id' => 1, 'status' => 1], ['id' => 2, 'status' => 1]]); }, 'EMPLOYEE_PHONE_NOT_UNIQUE');
$rejects(static function (): void { MobileMerchantWechatPhoneServices::employeeIdFromRows([['id' => 1, 'status' => 0]]); }, 'EMPLOYEE_DISABLED');

$root = dirname(__DIR__, 3);
$page = file_get_contents($root . '/前端代码/mobile-vue3/src/app/pages/bootstrap/index.uvue');
$client = file_get_contents($root . '/前端代码/mobile-vue3/src/shared/api/mobile-login-client.uts');
$route = file_get_contents($root . '/后端代码/route/api-mobile.php');
$contract = json_decode((string)file_get_contents($root . '/前端代码/mobile-vue3/src/shared/contracts/mobile-merchant-v1.contract.json'), true);
$assert(strpos($page, 'open-type="getPhoneNumber"') !== false && strpos($page, 'enterMerchantWithPassword') !== false, 'WeChat and password buttons remain available');
$assert(strpos($page, 'v-if="passwordLoginVisible" class="password-fields"') !== false
    && strpos($page, "if (code.length == 0) { choosePasswordLogin(") !== false
    && strpos($page, "if (!result.ok) { choosePasswordLogin(") !== false
    && strpos($page, 'function choosePasswordLogin(reason : string)') !== false,
    'refusal and unresolved WeChat identity open the account-password form');
$assert(strpos($page, 'open-type="getPhoneNumber"') < strpos($page, 'v-if="passwordLoginVisible" class="password-fields"'),
    'the account-password view retains a WeChat authorization button');
$assert(strpos($client, "'/mobile/merchant/wechat-phone-login'") !== false && strpos($client, 'phoneCode: code') !== false, 'client sends provider code, not a user-entered phone');
$assert(strpos($route, "Route::post('wechat-phone-login'") !== false && strpos($route, 'MobileMerchantWechatPhoneMiddleware::class') !== false, 'public WeChat login route');
$assert(($contract['endpoints']['wechatPhoneLogin']['authentication'] ?? '') === 'WECHAT_SERVER_VERIFIED_PHONE_AND_UNIQUE_ACTIVE_EMPLOYEE_WITH_CURRENT_MOBILE_AUTHORIZATION', 'contract preserves employee authorization');
echo "mobile merchant WeChat phone login contract: PASS\n";
