<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/protocol/MobileApiException.php';
require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/merchant/MobileMerchantWechatPhoneServices.php';

use app\services\mobile\merchant\MobileMerchantWechatPhoneServices as Phone;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// The existing customer keeps its already verified routine credentials.
$rh = Phone::miniProgramCredentials('', '', 'wx1111111111111111', 'rh-secret');
$assert($rh === ['wx1111111111111111', 'rh-secret', false], 'existing routine fallback');

// A customer with a separate merchant mini-program must not use the member
// AppID or secret to redeem a merchant phone code.
$merchant = Phone::miniProgramCredentials(' wx2222222222222222 ', ' merchant-secret ', 'wx1111111111111111', 'member-secret');
$assert($merchant === ['wx2222222222222222', 'merchant-secret', true], 'merchant pair isolation');
$assert(Phone::miniProgramCredentials('wx2222222222222222', '', 'wx1111111111111111', 'member-secret')
    === ['wx2222222222222222', '', true], 'incomplete merchant config fails closed');

$response = ['errcode' => 0, 'phone_info' => [
    'purePhoneNumber' => '13800138000',
    'countryCode' => '86',
    'watermark' => ['appid' => 'wx2222222222222222'],
]];
$assert(Phone::phoneFromResponse($response, $merchant[0]) === '13800138000', 'merchant watermark');
try {
    Phone::phoneFromResponse($response, $rh[0]);
    throw new RuntimeException('member app must not accept merchant watermark');
} catch (\app\services\mobile\protocol\MobileApiException $exception) {
    $assert($exception->mobileCode() === 'WECHAT_PHONE_VERIFICATION_FAILED', 'wrong-app rejection');
}

echo "merchant mini-program credential isolation: PASS\n";
