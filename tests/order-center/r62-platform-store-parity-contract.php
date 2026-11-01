<?php

/**
 * 订单中心平台/门店共用页面合同。不连接数据库，不修改任何实例。
 */
$root = dirname(__DIR__, 2);
$view = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue');
$router = (string)file_get_contents($root . '/前端代码/cashier-v3/src/router/index.js');
$frame = (string)file_get_contents($root . '/前端代码/admin/src/pages/store/order/center.vue');
$platform = (string)file_get_contents($root . '/后端代码/app/services/order/PlatformOrderCenterReadServices.php');
$records = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
$policy = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/projection/CashierV3DisplayPolicyServices.php');

function r62Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

r62Assert(substr_count($view, 'orderUiPolicy.value.debtPaymentEnabled === true') === 1
    && strpos($view, 'state.cashier?.debtPaymentEnabled === true') === false,
    '平台与门店的欠款字段必须共用同一权威显示政策');
r62Assert(strpos($view, 'debtPaymentEnabled !== false') === false,
    '欠款开关禁止缺省启用');
r62Assert(strpos($view, '平台只读查询；业务操作请前往门店端处理') === false,
    '共用页面不得另增平台只读页头');
r62Assert(strpos($view, 'isPlatformReadOnly') === false
    && strpos($router, 'platformReadOnly') === false
    && strpos($view, 'const isPlatformContext = computed(') !== false,
    '平台身份只能增加组织范围上下文，不得生成第二套只读页面');
r62Assert(strpos($view, '<th v-if="!isPlatform') === false
    && strpos($view, '<td v-if="!isPlatform') === false,
    '平台和门店不得使用不同的列结构');
r62Assert(strpos($view, 'const platformOrderCenterDefaultDateRanges = computed(() => ({}))') !== false,
    '平台不得另设日期默认值');
r62Assert(strpos($view, 'return activeUnifiedQuery.value?.capability?.value || {}') !== false,
    '平台与门店必须使用同一查询能力投影');
r62Assert(substr_count($frame, '20261031-final-v4') <= 1 && strpos($frame, '&v=${version}') !== false,
    '平台 iframe 不得固定第二个旧前端资源版本');
r62Assert(substr_count($platform, "'businessTypes' => \$this->businessTypes()") >= 2,
    '平台查询投影必须下发与门店相同的页签清单');
r62Assert(strpos($platform, 'CashierV3OrderCenterRecordQueryServices::businessTypes()') !== false
    && strpos($records, 'public static function businessTypes(): array') !== false,
    '平台与门店不得各自复制页签登记');
r62Assert(substr_count($platform, 'new CashierV3DisplayPolicyServices())->snapshot()') >= 2,
    '平台字段开关必须复用共用显示政策');
foreach (['debtPaymentEnabled', 'preSaleEnabled', 'salesAttributionEnabled', 'laborFeeVisible'] as $key) {
    r62Assert(strpos($policy, "'{$key}'") !== false, '共用显示政策缺少 ' . $key);
}

echo "R62_PLATFORM_STORE_PARITY=PASS\n";

r62Assert(strpos($view, 'orderUiPolicy.value.businessSourceEnabled === true') !== false
    && strpos($view, 'state.businessSourcePolicy?.enabled') === false
    && strpos($policy, 'CashierV3BusinessSourcePolicyServices') !== false,
    '来源开关必须随同一订单投影返回，不能依赖门店根分区');
