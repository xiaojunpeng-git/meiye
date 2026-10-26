<?php
declare(strict_types=1);

use app\services\activity\coupon\StoreCouponUserServices;
use think\facade\Db;

if (($argv[1] ?? '') !== '--execute-local') {
    fwrite(STDERR, "Refused: pass --execute-local explicitly.\n");
    exit(2);
}

$targetPhone = (string)($argv[2] ?? '');
if (!preg_match('/^1\d{10}$/', $targetPhone)) {
    fwrite(STDERR, "Refused: target phone must be an exact 11-digit mobile number.\n");
    exit(2);
}

$backendRoot = getenv('MOHE_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
chdir($backendRoot);
require 'vendor/autoload.php';
$app = new think\App();
$app->initialize();

$database = Db::query('SELECT DATABASE() AS db');
if (($database[0]['db'] ?? '') !== 'ruihao') {
    fwrite(STDERR, "Refused: this acceptance writer is restricted to the local ruihao database.\n");
    exit(2);
}

$sourceAccount = 'coupon_transfer_test_sender';
$issueTitle = '转赠功能验收券';

try {
    $target = Db::name('user')->where('phone', $targetPhone)->field('uid,nickname,phone')->find();
    if (!$target) {
        throw new RuntimeException('接收会员不存在');
    }
    $requestId = 'local-acceptance-20260919-' . (int)$target['uid'];
    $existing = Db::name('store_coupon_transfer')->where('request_id', $requestId)->find();
    if ($existing) {
        echo json_encode([
            'result' => 'ALREADY_EXISTS',
            'transfer_no' => $existing['transfer_no'],
            'coupon_user_id' => (int)$existing['coupon_user_id'],
            'from_uid' => (int)$existing['from_uid'],
            'to_uid' => (int)$existing['to_uid'],
            'record_retained' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        exit(0);
    }

    Db::startTrans();
    $source = Db::name('user')->where('account', $sourceAccount)->field('uid,account')->find();
    if (!$source) {
        $sourceUid = (int)Db::name('user')->insertGetId([
            'account' => $sourceAccount,
            'pwd' => md5(bin2hex(random_bytes(16))),
            'nickname' => '优惠券转赠验收账号',
            'add_time' => time(),
            'status' => 0,
            'spread_open' => 0,
            'user_type' => 'system',
            'login_type' => 'system',
            'mark' => '本地优惠券转赠验收专用，禁止登录、无资金权益',
        ]);
    } else {
        $sourceUid = (int)$source['uid'];
    }

    $issue = Db::name('store_coupon_issue')->where('coupon_title', $issueTitle)->find();
    if (!$issue) {
        $template = Db::name('store_coupon_issue')->order('id desc')->find();
        if (!$template) {
            throw new RuntimeException('缺少优惠券发放配置模板');
        }
        unset($template['id']);
        $now = time();
        $template = array_merge($template, [
            'coupon_issue_type' => 0,
            'relation_id' => 0,
            'category' => 1,
            'type' => 0,
            'receive_type' => 3,
            'coupon_type' => 1,
            'coupon_title' => $issueTitle,
            'title' => $issueTitle,
            'start_time' => $now,
            'end_time' => $now + 86400,
            'total_count' => 1,
            'remain_count' => 0,
            'is_permanent' => 1,
            'is_claimed' => 0,
            'quantity_count' => 1,
            'status' => 1,
            'is_del' => 0,
            'add_time' => $now,
            'coupon_price' => '0.00',
            'top_discount_price' => '0.00',
            'use_min_price' => '0.00',
            'coupon_time' => 30,
            'start_use_time' => 0,
            'end_use_time' => 0,
            'product_id' => '',
            'category_id' => 0,
            'brand_id' => 0,
            'applicable_type' => 0,
            'applicable_store_id' => '',
            'allow_transfer' => 1,
            'rule' => '本地转赠功能验收用，面值0元，不产生资金权益。',
        ]);
        $issueId = (int)Db::name('store_coupon_issue')->insertGetId($template);
    } else {
        $issueId = (int)$issue['id'];
        Db::name('store_coupon_issue')->where('id', $issueId)->update(['allow_transfer' => 1]);
    }

    $now = time();
    $couponUserId = (int)Db::name('store_coupon_user')->insertGetId([
        'cid' => $issueId,
        'uid' => $sourceUid,
        'coupon_title' => $issueTitle,
        'coupon_price' => '0.00',
        'use_min_price' => '0.00',
        'add_time' => $now,
        'start_time' => strtotime(date('Y-m-d 00:00:00', $now)),
        'end_time' => strtotime(date('Y-m-d 23:59:59', strtotime('+30 days', $now))),
        'use_time' => 0,
        'type' => 'send',
        'status' => 0,
        'is_fail' => 0,
        'oid' => null,
    ]);

    /** @var StoreCouponUserServices $services */
    $services = app()->make(StoreCouponUserServices::class);
    $result = $services->transferCoupon($sourceUid, $couponUserId, $targetPhone, $requestId);
    Db::commit();

    $record = Db::name('store_coupon_transfer')->where('request_id', $requestId)->find();
    $coupon = Db::name('store_coupon_user')->where('id', $couponUserId)->find();
    if (!$record || !$coupon || (int)$coupon['uid'] !== (int)$target['uid']) {
        throw new RuntimeException('转赠后验证失败');
    }

    echo json_encode([
        'result' => 'TRANSFERRED',
        'transfer_no' => $result['transfer_no'],
        'coupon_user_id' => $couponUserId,
        'coupon_title' => $issueTitle,
        'coupon_value' => '0.00',
        'from_uid' => $sourceUid,
        'to_uid' => (int)$target['uid'],
        'target_phone_masked' => $result['target']['phone_masked'],
        'record_retained' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $exception) {
    try {
        Db::rollback();
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, "local acceptance transfer FAIL: {$exception->getMessage()}\n");
    exit(1);
}
