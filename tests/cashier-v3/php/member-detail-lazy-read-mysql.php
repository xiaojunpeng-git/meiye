<?php
declare(strict_types=1);

/** Read-only comparison of the complete legacy projection and explicit tab reads. */
$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
$phone = trim((string)getenv('MEMBER_DETAIL_TEST_PHONE'));
if ($phone === '') throw new RuntimeException('MEMBER_DETAIL_TEST_PHONE_REQUIRED');
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->initialize();

$member = (array)Db::name('user')->where('phone', $phone)
    ->field('uid,belong_store_id')->find();
$memberId = (int)($member['uid'] ?? 0);
$storeId = (int)($member['belong_store_id'] ?? 0);
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
if ($memberId <= 0 || $storeId <= 0 || $organizationId === '' || $organizationId === '0') {
    throw new RuntimeException('MEMBER_DETAIL_TEST_SCOPE_MISSING');
}

$operator = new CashierV3OperatorScope($storeId, 1, $organizationId, '0');
$scope = new CashierV3DataScopeContext(
    1, 1, $storeId, '0', $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'member-detail-lazy-read-test', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$reader = new CashierV3MemberDetailQueryServices();
$started = microtime(true);
$profile = $reader->read($memberId, $operator, $scope, ['tab' => 'profile']);
$profileMs = (int)round((microtime(true) - $started) * 1000);
$gift = $reader->read($memberId, $operator, $scope, ['tab' => 'gift', 'status' => 'active']);
$complete = $reader->read($memberId, $operator, $scope);

$checks = [
    'profile keeps authoritative summary' => $profile['summary'] === $complete['summary'],
    'profile keeps current care reminder' => $profile['careReminder'] === $complete['careReminder'],
    'profile omits unrelated histories' => !isset($profile['giftRecords'], $profile['serviceRecords'], $profile['balanceChanges'], $profile['cardOperations']),
    'gift tab returns unchanged active gifts' => $gift['giftRecords'] === $complete['giftRecords'],
    'gift tab does not claim other histories loaded' => !isset($gift['serviceRecords'], $gift['balanceChanges'], $gift['cardOperations']),
    'both reads keep exact member scope' => (int)$profile['member']['memberId'] === $memberId
        && (int)$gift['member']['memberId'] === $memberId,
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo 'PROFILE_MS=' . $profileMs . ' GIFT_COUNT=' . count($gift['giftRecords']) . ' FAILED=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
