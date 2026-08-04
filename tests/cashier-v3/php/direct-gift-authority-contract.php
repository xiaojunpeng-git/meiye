<?php
declare(strict_types=1);

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$frontendRoot = dirname($root) . '/前端代码/cashier-v3';
$files = [
    'service' => $root . '/app/services/cashier/v3/member/CashierV3DirectGiftIssuanceServices.php',
    'member' => $root . '/app/services/cashier/v3/member/CashierV3MemberModule.php',
    'backend_manifest' => $root . '/app/services/cashier/v3/manifest/CashierV3ActionManifest.php',
    'c5_manifest' => $root . '/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php',
    'order_center' => $root . '/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php',
    'projection' => $root . '/app/services/cashier/v3/member/CashierV3RechargeGiftIssuanceServices.php',
    'consumer' => $root . '/app/services/cashier/v3/member/CashierV3DirectGiftReconciliationConsumer.php',
    'bootstrap' => $root . '/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php',
    'frontend_manifest' => $frontendRoot . '/src/services/cashierV3ActionManifest.js',
    'shell' => $frontendRoot . '/src/layouts/CashierShell.vue',
    'overlay' => $frontendRoot . '/src/components/member/DirectGiftOverlay.vue',
    'migration' => $root . '/database/upgrades/2026-08-03-收银V3独立赠送权威/02-正式升级.sql',
];
$source = [];
foreach ($files as $key => $file) {
    $source[$key] = file_get_contents($file);
    if ($source[$key] === false) throw new RuntimeException("无法读取赠送权威源码：{$key}");
}
$failed = 0;
$check = static function (bool $condition, string $name) use (&$failed): void {
    if (!$condition) { $failed++; fwrite(STDERR, "FAIL: {$name}\n"); return; }
    echo "PASS: {$name}\n";
};
$check(strpos($source['frontend_manifest'], "'submit-direct-gift'") !== false && strpos($source['c5_manifest'], "'submit-direct-gift'") !== false, '前后端均登记独立赠送命令');
$check(strpos($source['backend_manifest'], "'aggregate_type' => 'direct_gift'") !== false && strpos($source['backend_manifest'], "'cashier_v3.direct_gift.reconcile'") !== false && strpos($source['bootstrap'], "'cashier_v3.direct_gift.reconcile'") !== false, '独立赠送事件合同限定 gift.issued 并登记 Outbox 核验消费者');
$check(strpos($source['member'], "registerProjection('open-gift'") !== false && strpos($source['member'], "registerCommand('submit-direct-gift'") !== false && strpos($source['member'], 'CashierV3DirectGiftIssuanceServices') !== false, '会员模块从入口命令路由到赠送权威服务');
$check(strpos($source['member'], "'uid' => (int)(\$row['uid'] ?? 0)") !== false && strpos($source['service'], "(int)\$member['uid']") !== false, '会员选择结果保留权威 uid，独立赠送可写入同一会员权益');
$check(strpos($source['member'], 'assertSelectedMemberInTx') !== false && strpos($source['member'], 'registerDirectGiftPolicy') !== false && strpos($source['member'], "trim((string)(\$item['stockText'] ?? '')) === ''") !== false, '独立赠送强制当前收银工作区会员和权限策略，并隐藏库存商品');
$directGiftPolicyStart = strpos($source['member'], 'private static function registerDirectGiftPolicy');
$directGiftPolicyEnd = $directGiftPolicyStart === false ? false : strpos($source['member'], 'private static function registerSelectionPolicy', $directGiftPolicyStart);
$directGiftPolicy = $directGiftPolicyStart === false ? '' : substr($source['member'], $directGiftPolicyStart, ($directGiftPolicyEnd === false ? strlen($source['member']) : $directGiftPolicyEnd) - $directGiftPolicyStart);
$check(strpos($source['member'], "'touched' => ['cashier_workspace'], 'message' => '赠送已生效。'") !== false && substr_count($directGiftPolicy, "['cashier_workspace'],") >= 2 && strpos($directGiftPolicy, "'required_touched_roles' => ['cashier_workspace'],") !== false && strpos($directGiftPolicy, "'required_touched_roles' => ['cashier_workspace', 'member']") === false, '独立赠送锁定会员作为只读依赖，仅推进工作台版本；新权益由同一事务创建并版本化');
$check(strpos($source['member'], "'store' => \$store") !== false && strpos($source['member'], "->whereIn('relation_id', [0, \$scope['operator_scope']->storeId()])") !== false, '弹层取得当前办理门店，并只加载总部或当前门店优惠券');
$check(strpos($source['service'], "private const AUTHORITY_TABLE = 'cashier_v3_direct_gift_authority'") !== false && strpos($source['service'], "private const ITEM_TABLE = 'cashier_v3_direct_gift_item'") !== false && strpos($source['service'], "private const FACT_TABLE = 'cashier_v3_gift_fact'") !== false, '独立赠送使用主记录、明细和统一赠送事实');
$check(strpos($source['service'], 'CashierV3TransactionGuard::assertInTransaction') !== false && strpos($source['service'], 'command_idempotency_key') !== false && strpos($source['service'], 'immutable_fingerprint') !== false, '独立赠送有事务边界与不可变幂等校验');
$check(strpos($source['service'], "preg_match('/^[1-9][0-9]{0,2}\$/D', \$quantityRaw)") !== false && strpos($source['service'], '(int)$quantityRaw') !== false, '服务端拒绝小数、负数和零数量，避免绕过界面被静默截断');
$check(strpos($source['service'], 'direct_gift_handling_store_invalid') !== false && strpos($source['service'], "whereIn('relation_id', [0, \$scope->storeId()])") !== false, '提交时强制当前办理门店并重读优惠券归属门店');
$check(strpos($source['service'], "'event_type' => 'gift.issued'") !== false && strpos($source['service'], 'recordInTx') !== false && strpos($source['service'], 'assertFactReplay') !== false, '逐项赠送写事件事实并校验重放完整性');
$check(strpos($source['consumer'], 'cashier_v3_direct_gift_authority') !== false && strpos($source['consumer'], 'cashier_v3_direct_gift_item') !== false && strpos($source['consumer'], 'cashier_v3_gift_fact') !== false, 'Outbox 消费者复核主记录、明细和赠送事实链');
$check(strpos($source['projection'], 'issueDirectProjectionInTx') !== false && strpos($source['service'], 'issueDirectProjectionInTx') !== false, '会员可见权益和优惠券仅由兼容投影写入');
$check(strpos($source['service'], 'direct_gift_inventory_product_not_supported') !== false, '库存管理商品未接库存权威扣减时失败关闭');
$check(strpos($source['service'], 'CashierOrderServices') === false && strpos($source['service'], 'UserRechargeServices') === false && strpos($source['service'], 'PaymentCollection') === false, '独立赠送不创建销售订单、充值或收款事实');
$check(strpos($source['order_center'], 'readV3DirectGifts') !== false && strpos($source['order_center'], "->where('gf.source_type', 'direct_gift')") !== false && strpos($source['order_center'], '%cashier_v3_direct_gift%') !== false, '订单中心从赠送事实读取独立赠送并排除兼容投影重复行');
$check(strpos($source['migration'], 'eb_cashier_v3_direct_gift_authority') !== false && strpos($source['migration'], 'eb_cashier_v3_direct_gift_item') !== false && strpos($source['migration'], 'uk_tenant_command') !== false && strpos($source['migration'], 'uk_gift_item_no') !== false, '迁移创建独立赠送表及幂等唯一约束');
$check(strpos($source['shell'], 'DirectGiftOverlay') !== false && strpos($source['shell'], "requestCashierV3Action('submit-direct-gift'") !== false && strpos($source['shell'], 'openWorkflowMemberSelector') !== false && strpos($source['shell'], "action === 'open-gift' && new URLSearchParams(window.location.search).get('preview') === '1'") === false, '收银壳未选会员时先进入会员选择，并使用真实赠送弹层');
$check(strpos($source['overlay'], "const memberId = computed(() => member.value.uid || member.value.memberId || member.value.id || '')") !== false && strpos($source['overlay'], 'const selectedCount = computed(() => selected.value.length)') !== false && strpos($source['overlay'], 'handlingStoreId') !== false && strpos($source['overlay'], '办理门店：') !== false && strpos($source['overlay'], 'catalogItems') !== false && strpos($source['overlay'], 'coupons') !== false && strpos($source['overlay'], "const reason = ref('')") !== false, '赠送弹层使用权威 uid、按已选内容计数，并提交办理门店、项目/产品/券、数量和必填原因');
echo "DIRECT_GIFT_AUTHORITY_CONTRACT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
