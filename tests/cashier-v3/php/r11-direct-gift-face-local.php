<?php
declare(strict_types=1);
/** Local transaction-only integration: real issuance, immutable snapshots, repeat submission and allowance use/restore. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\activity\coupon\StoreCouponAllowanceServices as Allowance;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\member\CashierV3DirectGiftIssuanceServices;
use app\services\cashier\v3\member\CashierV3RechargeGiftIssuanceServices;
$app = new think\App('/var/www/html/'); $app->initialize();
set_exception_handler(static function (Throwable $e): void { file_put_contents('php://stderr', $e->getMessage() . "\n" . $e->getTraceAsString()); exit(1); });
if (Db::connect()->getConfig('hostname') !== 'mysql' || getenv('R11_LOCAL_ONLY') !== '1') throw new RuntimeException('local only');
$passed = [];
$check = static function (bool $ok, string $name) use (&$passed): void { if (!$ok) throw new RuntimeException($name); $passed[] = $name; };
$reject = static function (callable $call, string $name) use ($check): void {
    Db::startTrans(); $denied = false;
    try { $call(); } catch (\app\services\cashier\v3\CashierV3CommandException $e) { $denied = true; }
    finally { Db::rollback(); } $check($denied, $name);
};
$countBefore = (int)Db::name('store_coupon_user')->count();
Db::startTrans();
try {
    $now = time(); $tag = 'R11-' . bin2hex(random_bytes(6));
    $member = (array)Db::name('user')->where('is_del', 0)->where('status', 1)->find();
    $org = (string)Db::name('organization_store')->where('store_id', 1)->value('org_id');
    $operator = new CashierV3OperatorScope(1, 1, $org, '0');
    $scope = new CashierV3DataScopeContext(1, 1, 1, '0', $org, [1], 'stores', [], true, '', 'r11-local', ['cashier.v3.member'], ['id' => 1, 'staff_name' => 'local rollback']);
    $issue = (int)Db::name('store_coupon_issue')->insertGetId(['title' => $tag, 'coupon_title' => $tag,
        'coupon_price' => '1000.00', 'use_min_price' => '0.00', 'coupon_type' => 3, 'type' => 0,
        'use_mode' => 0, 'category' => 1, 'applicable_type' => 1, 'coupon_issue_type' => 0,
        'relation_id' => 0, 'total_count' => 0, 'remain_count' => 0, 'status' => 1, 'is_del' => 0, 'add_time' => $now]);
    $check((int)Db::name('store_coupon_issue')->where('id', $issue)->value('allow_gift_face_edit') === 0, 'existing/new templates default off');
    $newData = (array)Db::name('store_coupon_issue')->where('id', $issue)->find(); unset($newData['id']);
    $newData['coupon_title'] = $tag . '-create'; $newData['allow_gift_face_edit'] = 1; $newData['product_id'] = '';
    foreach (['start_use_time', 'end_use_time', 'start_time', 'end_time'] as $field) $newData[$field] = '2026-11-05 00:00:00';
    app()->make(StoreCouponIssueServices::class)->saveCoupon($newData, 0, 0);
    $check((int)Db::name('store_coupon_issue')->where('title', $newData['coupon_title'])->value('allow_gift_face_edit') === 1, 'new template creation persists enabled switch');
    $newData['coupon_type'] = 1; $newData['coupon_title'] = $tag . '-ordinary-create';
    app()->make(StoreCouponIssueServices::class)->saveCoupon($newData, 0, 0);
    $check((int)Db::name('store_coupon_issue')->where('title', $newData['coupon_title'])->value('allow_gift_face_edit') === 0, 'non-reusable template creation always keeps switch off');
    $service = new CashierV3DirectGiftIssuanceServices(); $recorder = new CashierV3BusinessEventRecorder();
    $contract = CashierV3ActionManifest::eventContractFor('submit-direct-gift');
    $send = static function (string $key, $face, int $quantity = 1) use ($service, $recorder, $contract, $member, $operator, $scope, $issue): array {
        $item = ['kind' => 'coupon', 'couponIssueId' => $issue, 'quantity' => $quantity];
        if ($face !== null) $item['couponFaceValue'] = $face;
        return $service->issueInTx(['handlingStoreId' => 1, 'reason' => 'local rollback', 'items' => [$item]],
            $member, $operator, $scope, $key, $recorder, $recorder->newExecution('submit-direct-gift', $key, $operator, $scope, 'r11-local'), $contract);
    };
    $claims = static function (array $gift): array {
        $item = Db::name('cashier_v3_direct_gift_item')->where('gift_id', $gift['giftId'])->find();
        return Db::name('store_coupon_user')->whereIn('id', json_decode($item['coupon_user_ids_json'], true))->select()->toArray();
    };
    $off = $send($tag . '-off', '250'); $check($claims($off)[0]['coupon_price'] === '1000.00', 'off ignores forged face and issues template value');
    app()->make(StoreCouponIssueServices::class)->setGiftFaceEditing($issue, 1);
    $check((int)Db::name('store_coupon_issue')->where('id', $issue)->value('allow_gift_face_edit') === 1, 'existing-template setting saves');
    $low = $send($tag . '-low', '250', 2); $rows = $claims($low);
    $check(count($rows) === 2 && $rows[0]['coupon_price'] === '250.00' && $rows[1]['coupon_price'] === '250.00', 'each claim uses edited lower face');
    $beforeReplay = (int)Db::name('store_coupon_user')->count();
    usleep(1100000); // Cross a real clock tick to verify the original issuance timestamp is stable.
    $replay = $send($tag . '-low', '250', 2);
    $check($replay['replayed'] && (int)Db::name('store_coupon_user')->count() === $beforeReplay, 'same request issues no duplicate coupons');
    $reject(static fn() => $send($tag . '-low', '300', 2), 'same key with changed face is not reissued');
    $high = $send($tag . '-high', '2000'); $check($claims($high)[0]['coupon_price'] === '2000.00', 'higher than template is allowed');
    $legacy = $send($tag . '-legacy', null); $check($claims($legacy)[0]['coupon_price'] === '1000.00', 'old client without face keeps template amount');
    foreach (['0', '-1', '2.5', '', 'abc'] as $value) $reject(static fn() => $send($tag . '-invalid-' . $value, $value), 'invalid whole-yuan face ' . $value);
    $saved = Db::name('cashier_v3_direct_gift_item')->where('gift_id', $low['giftId'])->find();
    $snapshot = json_decode($saved['content_snapshot_json'], true);
    $fact = Db::name('cashier_v3_gift_fact')->where('source_detail_id', $saved['item_id'])->find();
    $check($snapshot['couponFaceValue'] === '250.00' && $fact['content_snapshot_json'] === $saved['content_snapshot_json'], 'authority and fact freeze actual face');
    $id = (int)$rows[0]['id']; $allowance = new Allowance();
    $workspace = (new ReflectionClass(\app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices::class))->newInstanceWithoutConstructor();
    $options = new ReflectionMethod($workspace, 'couponOptions'); $options->setAccessible(true);
    $readFace = static function () use ($workspace, $options, $member, $tag, $id): int {
        $result = $options->invoke($workspace, (int)$member['uid'], 1, ['threshold' => 100000, 'base' => 100000, 'cap' => 100000], false, $tag, 'line', $id);
        return (int)$result['available'][0]['remainingAmountCents'];
    };
    $check($readFace() === 25000, 'checkout selector reads edited face before first use');
    $identity = ['tenant_id' => '0', 'member_id' => (int)$member['uid'], 'store_id' => 1, 'order_id' => $tag . '-use', 'order_line_id' => $tag . '-line'];
    $allowance->consumeInTx($id, 10000, $identity, $now);
    $check((int)Db::name(Allowance::ACCOUNT)->where('coupon_user_id', $id)->value('remaining_cents') === 15000, 'consume 100 from edited 250 leaves 150');
    $check($readFace() === 15000, 'checkout selector reads remaining edited allowance');
    $allowance->restoreInTx($id, 10000, $identity, $now);
    $check((int)Db::name(Allowance::ACCOUNT)->where('coupon_user_id', $id)->value('remaining_cents') === 25000, 'void restores actual face account');
    app()->make(StoreCouponIssueServices::class)->setGiftFaceEditing($issue, 0);
    $check(Db::name('store_coupon_user')->where('id', $id)->value('coupon_price') === '250.00', 'closing switch never rewrites issued claims');
    $recharge = new CashierV3RechargeGiftIssuanceServices(); $writer = new ReflectionMethod($recharge, 'issueCouponInTx'); $writer->setAccessible(true);
    app()->make(StoreCouponIssueServices::class)->setGiftFaceEditing($issue, 1);
    $result = $writer->invoke($recharge, ['couponIssueId' => $issue, 'couponFaceValue' => '77', 'quantity' => 1, 'validityStart' => $now, 'validityEnd' => 0], (int)$member['uid'], $now);
    $check(Db::name('store_coupon_user')->where('id', $result['couponUserIds'][0])->value('coupon_price') === '1000.00', 'recharge gift remains template face');
    Db::name('store_coupon_issue')->where('id', $issue)->update(['coupon_type' => 1]);
    $ordinary = $send($tag . '-ordinary', '77'); $check($claims($ordinary)[0]['coupon_price'] === '1000.00', 'ordinary coupon ignores override even with stored switch');
    $check(Db::name('store_coupon_issue')->where('id', $issue)->value('coupon_price') === '1000.00', 'template face remains unchanged');
} finally { Db::rollback(); }
$check((int)Db::name('store_coupon_user')->count() === $countBefore, 'all local business fixtures rolled back');
echo json_encode(['result' => 'PASS', 'checks' => $passed, 'businessRowsRetained' => 0], JSON_UNESCAPED_UNICODE), "\n";
