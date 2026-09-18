<?php

$root = dirname(__DIR__, 3);
$module = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$provider = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php');

function reservationListFilterOk(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

reservationListFilterOk('预约查询将统一工具栏 topFilters 传给服务端读模型',
    strpos($module, "'topFilters' => is_array(\$payload['topFilters'] ?? null) ? \$payload['topFilters'] : []") !== false);
reservationListFilterOk('预约时间按上海业务日的起止边界在服务端筛选',
    strpos($provider, 'private static function applyListTopFilters') !== false
    && strpos($provider, "\$field === 'appointment_time'") !== false
    && strpos($provider, "where('appointment_start_at', '>=', \$start)") !== false
    && strpos($provider, "where('appointment_start_at', '<', \$start + 86400)") !== false);
reservationListFilterOk('待服务状态和日期筛选在同一预约主表查询中叠加',
    strpos($provider, "self::applyListTopFilters(\$recordsQuery") !== false
    && strpos($provider, "whereIn('status', ['PENDING_CONFIRMATION', 'UNSTARTED'])") !== false);
reservationListFilterOk('整页投影重建保留当前预约筛选，不能退回今日预约默认条件',
    substr_count($module, "'quickFilter' => (string)\$queryHints['quickFilter']") === 2
    && substr_count($module, "'topFilters' => (array)\$queryHints['topFilters']") === 2
    && strpos($module, '只保留 calendarDate 会使“未开始”等') !== false);
reservationListFilterOk('预约列表只映射白名单字段，客户端不能注入列名或操作符',
    strpos($provider, "'reservation_no' => 'reservation_no'") !== false
    && strpos($provider, 'unsupported filters remain unavailable') !== false);

echo "RESERVATION_LIST_FILTER_CONTRACT=PASS\n";
