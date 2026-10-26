<?php
declare(strict_types=1);

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$gift = file_get_contents($root . '/app/services/cashier/v3/member/CashierV3RechargeGiftIssuanceServices.php');
$recharge = file_get_contents($root . '/app/services/cashier/v3/member/CashierV3RechargeModule.php');
$manifest = file_get_contents($root . '/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
if ($gift === false || $recharge === false || $manifest === false) throw new RuntimeException('无法读取充值赠送源码。');
$failed = 0;
$check = static function (bool $condition, string $name) use (&$failed): void {
    if (!$condition) { $failed++; fwrite(STDERR, "FAIL: {$name}\n"); return; }
    echo "PASS: {$name}\n";
};
$check(strpos($recharge, 'CashierV3RechargeGiftIssuanceServices') !== false && strpos($recharge, 'issueInTx(') !== false, '套餐充值在同一命令事务调用赠送权威服务');
$check(strpos($gift, "private const AUTHORITY_TABLE = 'cashier_v3_recharge_gift_authority'") !== false && strpos($gift, "private const ITEM_TABLE = 'cashier_v3_recharge_gift_item'") !== false, '赠送主记录与逐项发放记录独立存在');
$check(strpos($gift, "private const FACT_TABLE = 'cashier_v3_gift_fact'") !== false && strpos($gift, "'event_type' => 'gift.issued'") !== false, '每项赠送写入 gift_issued 事件与赠送事实');
$check(strpos($gift, "->where('recharge_id', \$rechargeId)") !== false && strpos($gift, "'uk_tenant_recharge'") === false, '充值重放以 tenant + recharge_id 权威记录防止重复发放');
$check(strpos($gift, "'gift_kind' => 'project'") === false && strpos($gift, "'kind' => \$declaredType === 6 ? 'project' : 'product'") !== false, '项目赠送与实物产品赠送按权威商品类型区分');
$check(strpos($gift, 'store_coupon_user') !== false && strpos($gift, 'store_coupon_issue_user') !== false, '赠券写入会员券与发放记录');
$check(strpos($gift, 'user_card_holder') !== false && strpos($gift, 'store_order_cart_info') !== false && strpos($gift, 'synchronizeProjectionVersion') !== false, '赠送项目写入可核销权益投影并同步资源版本');
$check(strpos($gift, 'UserRechargeServices') === false && strpos($gift, 'CashierOrderServices') === false, '未调用旧充值或旧订单赠送服务');
$check(strpos($manifest, "'gift.issued' => [") !== false && strpos($manifest, "'aggregate_type' => 'recharge_gift'") !== false, '充值事件合同显式允许充值赠送事件');
echo "RECHARGE_GIFT_AUTHORITY_CONTRACT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
