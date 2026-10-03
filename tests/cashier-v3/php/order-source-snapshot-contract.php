<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$readerPath = $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php';
$contractPath = $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php';
$rechargePath = $root . '/后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php';
$viewPath = $root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue';
$workbenchPath = $root . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue';
$shellPath = $root . '/前端代码/cashier-v3/src/layouts/CashierShell.vue';

foreach ([$readerPath, $contractPath, $rechargePath, $viewPath, $workbenchPath, $shellPath] as $path) {
    if (!is_file($path)) throw new RuntimeException('missing source: ' . $path);
}
$reader = file_get_contents($readerPath);
$contract = file_get_contents($contractPath);
$recharge = file_get_contents($rechargePath);
$view = file_get_contents($viewPath);
$workbench = file_get_contents($workbenchPath);
$shell = file_get_contents($shellPath);

$required = [
    [$reader, "'r.business_source_primary_name_snapshot'", 'order source selection'],
    [$reader, "'source' => \$this->businessSourceLabel(\$row)", 'order row source'],
    [$contract, "['source', '客户来源']", 'unified query field'],
    [$view, "field('source', '客户来源')", 'visible field'],
    [$view, "fields.splice(orderNoIndex >= 0 ? orderNoIndex + 1 : 0, 0, available.get('source'))", 'saved column settings'],
    [$workbench, 'localCheckoutBusinessSource.value = {', 'ordinary source edit snapshot'],
    [$workbench, 'source: isRecord(snapshot.source)', 'repayment original source preservation'],
    [$shell, 'const source = payload.record?.originalBusinessSource || {}', 'repayment handoff from original order'],
    [$workbench, 'source: clonePlain(intent.businessSource || {})', 'local repayment source freeze'],
    [$workbench, "if (preview.businessType === 'debt_repayment')", 'repayment source isolation'],
    [$recharge, "'businessSourcePrimaryId' => (int)\$input['businessSource']['primarySourceId']", 'recharge fact source'],
];
foreach ($required as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('missing ' . $label);
}
if (substr_count($contract, "['source', '客户来源']") < 3
    || substr_count($view, "field('source', '客户来源')") < 3
    || substr_count($reader, "'source' => \$this->businessSourceLabel(\$row)") !== 3) {
    throw new RuntimeException('source must appear on sales, recharge, and both repayment rows');
}
foreach (['recharge_order_no' => '充值订单号', 'supplement_order_no' => '补交单号'] as $key => $label) {
    $viewNeedle = "field('{$key}', '{$label}', 'text', { quickFilterHidden: true }), field('source', '客户来源')";
    $contractNeedle = "['{$key}', '{$label}', 'text', true, true], ['source', '客户来源']";
    if (!str_contains($view, $viewNeedle) || !str_contains($contract, $contractNeedle)) {
        throw new RuntimeException($key . ' must be immediately followed by source');
    }
}

require $readerPath;
$service = new \app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices();
$paymentLabel = new ReflectionMethod($service, 'paymentLabel');
foreach (['other_collection' => '其他收款', 'partner_collection' => '合作方收款',
    'dianping_voucher' => '大众验券', 'douyin_voucher' => '抖音验券',
    'cash' => '现金', 'wechat' => '微信'] as $code => $label) {
    if ($paymentLabel->invoke($service, $code) !== $label) {
        throw new RuntimeException('payment label mismatch: ' . $code);
    }
}
$method = new ReflectionMethod($service, 'businessSourceLabel');
if ($method->invoke($service, [
    'business_source_primary_name_snapshot' => 'A老客首护',
    'business_source_secondary_name_snapshot' => '二级渠道',
    'business_source_label_snapshot' => 'A老客首护 / 二级渠道',
]) !== '二级渠道' || $method->invoke($service, []) !== '') {
    throw new RuntimeException('source display must prefer the frozen secondary label and keep legacy blank');
}
echo "ORDER_SOURCE_SNAPSHOT_CONTRACT_OK\n";
