<?php

declare(strict_types=1);

/** 挂单联系方式只读创建时的服务端快照，不从现值回填旧单。 */

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangMemberPhoneSnapshot.php';

use app\services\cashier\v3\hang\CashierV3HangMemberPhoneSnapshot;

$failed = 0;
function expectHangPhone(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
    if (!$condition) $failed++;
}

$contact = CashierV3HangMemberPhoneSnapshot::capture(1001, [
    'memberId' => 1001,
    'phone' => ' 13800138000 ',
]);
$saved = json_encode([
    'checkoutBusinessSource' => ['primarySourceId' => 7, 'primarySourceNameSnapshot' => '老客介绍'],
    'memberContactSnapshot' => $contact,
], JSON_UNESCAPED_UNICODE);
expectHangPhone(CashierV3HangMemberPhoneSnapshot::displayFromJson($saved, 1001) === '13800138000',
    'new hang displays the server-sourced phone captured at creation');
expectHangPhone(CashierV3HangMemberPhoneSnapshot::displayFromJson($saved, 1002) === '',
    'another member cannot inherit the saved phone');
expectHangPhone(CashierV3HangMemberPhoneSnapshot::displayFromJson($saved, 0) === '',
    'guest hang never exposes a member phone');
expectHangPhone(CashierV3HangMemberPhoneSnapshot::displayFromJson('{"checkoutBusinessSource":{}}', 1001) === ''
    && CashierV3HangMemberPhoneSnapshot::displayFromJson('', 1001) === '',
    'old hangs without a phone snapshot stay empty');
expectHangPhone(CashierV3HangMemberPhoneSnapshot::capture(1001, [
    'memberId' => 1002, 'phone' => '13900139000',
])['phone'] === '', 'mismatched member summary fails closed');

$submission = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangSubmissionServices.php');
$listing = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangOrderListServices.php');
$view = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/HangOrderListView.vue');
expectHangPhone(substr_count($submission, 'CashierV3HangMemberPhoneSnapshot::capture(') === 3
    && strpos($submission, "\$local['memberContactSnapshot'] = CashierV3HangMemberPhoneSnapshot::capture(") !== false,
    'legacy, direct, and local hangs freeze server-sourced contact at write time');
expectHangPhone(strpos($submission, "if (empty(\$persisted['replayed']))") !== false
    && strpos($listing, 'CashierV3HangMemberPhoneSnapshot::displayFromJson(') !== false,
    'replays preserve the original sidecar and list reads only that snapshot');
expectHangPhone(strpos($view, "if (key === 'source') return value || '未选择'") !== false,
    'unselected source is explicit without changing the stored snapshot');

exit($failed > 0 ? 1 : 0);
