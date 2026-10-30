<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../../后端代码/vendor/autoload.php';

use app\services\pay\SuixingPayGateway;
use app\services\pay\SuixingPayMemberService;

// Exercise the official RSA canonicalization with an in-memory key only. The
// customer merchant private key is never loaded by a source-level test.
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$key || !openssl_pkey_export($key, $private)) throw new RuntimeException('test key unavailable');
$public = openssl_pkey_get_details($key)['key'];
$fields = ['version' => '1.2', 'reqData' => ['mno' => '123', 'ordNo' => 'MC-123'],
    'orgId' => '01234567', 'signType' => 'RSA', 'timestamp' => 1601257444767, 'reqId' => 'abc'];
$canonical = 'orgId=01234567&reqData={"mno":"123","ordNo":"MC-123"}&reqId=abc&signType=RSA&timestamp=1601257444767&version=1.2';
if (SuixingPayGateway::canonical($fields) !== $canonical) throw new RuntimeException('canonical mismatch');
$utf8Fields = ['version' => '1.2', 'reqData' => ['ordNo' => 'MC_123', 'mno' => '399',
    'subject' => '会员/支付', 'optional' => ''], 'orgId' => '01234567', 'reqId' => 'abc',
    'timestamp' => '20261030190000', 'signType' => 'RSA'];
$utf8Canonical = 'orgId=01234567&reqData={"ordNo":"MC_123","mno":"399","subject":"会员/支付","optional":""}'
    . '&reqId=abc&signType=RSA&timestamp=20261030190000&version=1.2';
if (SuixingPayGateway::canonical($utf8Fields) !== $utf8Canonical
    || preg_match('//u', $utf8Canonical) !== 1
    || str_contains($utf8Canonical, "\n") || str_contains($utf8Canonical, "\r"))
    throw new RuntimeException('UTF-8, empty field, nested order, or newline mismatch');
$utf8Fields['sign'] = SuixingPayGateway::sign($utf8Fields, $private);
if (!SuixingPayGateway::verify($utf8Fields, $public)) throw new RuntimeException('UTF-8 signature rejected');
$fields['sign'] = SuixingPayGateway::sign($fields, $private);
if (!SuixingPayGateway::verify($fields, $public)) throw new RuntimeException('valid signature rejected');
$fields['reqData']['ordNo'] = 'MC-124';
if (SuixingPayGateway::verify($fields, $public)) throw new RuntimeException('tampered order accepted');
$errorReply = ['code' => '0005', 'msg' => '验签失败', 'reqId' => 'abc'];
$errorReply['sign'] = SuixingPayGateway::sign($errorReply, $private);
if (!SuixingPayGateway::validReply($errorReply, $public, '01234567', 'abc'))
    throw new RuntimeException('signed provider error rejected');
if (SuixingPayGateway::validReply($errorReply, $public, '01234567', 'other'))
    throw new RuntimeException('wrong request ID accepted');
$minimalError = ['code' => '0002', 'msg' => '上送订单信息不匹配'];
$minimalError['sign'] = SuixingPayGateway::sign($minimalError, $private);
if (!SuixingPayGateway::validReply($minimalError, $public, '01234567', 'abc'))
    throw new RuntimeException('signed production-style error without echoed IDs rejected');
$wrongOrgError = ['code' => '0002', 'orgId' => 'other'];
$wrongOrgError['sign'] = SuixingPayGateway::sign($wrongOrgError, $private);
if (SuixingPayGateway::validReply($wrongOrgError, $public, '01234567', 'abc'))
    throw new RuntimeException('wrong echoed organization accepted');
$successReply = ['code' => '0000', 'reqId' => 'abc'];
$successReply['sign'] = SuixingPayGateway::sign($successReply, $private);
if (SuixingPayGateway::validReply($successReply, $public, '01234567', 'abc'))
    throw new RuntimeException('success without organization accepted');
$successWithoutRequest = ['code' => '0000', 'orgId' => '01234567'];
$successWithoutRequest['sign'] = SuixingPayGateway::sign($successWithoutRequest, $private);
if (SuixingPayGateway::validReply($successWithoutRequest, $public, '01234567', 'abc'))
    throw new RuntimeException('success without request ID accepted');
if (SuixingPayGateway::cents('398.00') !== 39800) throw new RuntimeException('amount conversion failed');
$localOrder = 'MC-' . str_repeat('a', 24);
if (SuixingPayMemberService::providerOrderNo($localOrder) !== 'MC_' . str_repeat('a', 24)
    || SuixingPayMemberService::localOrderNo('MC_' . str_repeat('a', 24)) !== $localOrder
    || SuixingPayMemberService::localOrderNo('MC_other') !== '') {
    throw new RuntimeException('provider order number mapping failed');
}
try {
    SuixingPayGateway::cents('398.01');
    throw new RuntimeException('non-whole-yuan amount accepted');
} catch (\think\exception\ValidateException $expected) {
    // The cashier's whole-yuan rule also applies before provider settlement.
}
echo "PASS SuixingPay RSA and amount contract\n";
