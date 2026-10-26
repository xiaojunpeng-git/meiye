<?php

/** 订单中心中文关键词与 ascii 历史编号共存的静态契约。 */

$root = dirname(__DIR__, 3);
$recordQuery = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php'
);
$salesQuery = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php'
);

$passed = 0;
$failed = 0;

function collationOk(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}\n";
}

foreach ([
    '订单中心七类记录' => $recordQuery,
    '销售订单权威与兼容查询' => $salesQuery,
] as $label => $source) {
    collationOk(
        $label . '的关键词过滤统一为 utf8mb4，不能把客户端关键字直接与 ascii_bin 编号比较',
        strpos($source, 'private function whereUtf8Like') !== false
        && strpos($source, 'CONVERT(') !== false
        && strpos($source, ' USING utf8mb4) COLLATE utf8mb4_general_ci LIKE ?') !== false
        && strpos($source, 'whereOrRaw($expression, [$like])') !== false
        && strpos($source, 'whereRaw($expression, [$like])') !== false
    );
}

collationOk(
    '服务记录的常用中文筛选与综合查询都经过同一兼容过滤',
    strpos($recordQuery, 'private function applyServiceTopFilters') !== false
    && strpos($recordQuery, '$this->whereUtf8Like(') !== false
);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
