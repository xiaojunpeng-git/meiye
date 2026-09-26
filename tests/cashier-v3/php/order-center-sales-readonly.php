<?php

/** C5-O1 销售订单只读服务孤立永久契约（PHP 7.4）。 */

$root = dirname(__DIR__, 3);
$backendRoot = trim((string)getenv('CASHIER_V3_BACKEND_ROOT'));
if ($backendRoot === '') {
    $backendRoot = $root . '/后端代码';
}
require $backendRoot . '/app/services/cashier/v3/CashierV3ResultCode.php';
require $backendRoot . '/app/services/cashier/v3/CashierV3CommandException.php';
require $backendRoot . '/app/services/cashier/v3/CashierV3ResourceScope.php';
require $backendRoot . '/app/services/cashier/v3/CashierV3OperatorScope.php';
require $backendRoot . '/app/services/cashier/v3/CashierV3DataScopeContext.php';
require $backendRoot . '/app/services/cashier/v3/projection/CashierV3RootPartitionProvider.php';
require $backendRoot . '/app/services/cashier/v3/order/CashierV3SalesOrderCursorCodec.php';
require $backendRoot . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php';
require $backendRoot . '/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php';
require $backendRoot . '/app/services/cashier/v3/order/CashierV3OrderCenterPartitionProvider.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3OrderCenterPartitionProvider;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3SalesOrderCursorCodec;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;

$passed = 0;
$failed = 0;

function orderOk(string $label, bool $condition, $detail = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}";
    if ($detail !== null) {
        echo ' :: ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    echo "\n";
}

function orderScope(
    string $mode,
    $stores,
    array $features = ['cashier.v3.order_center'],
    string $permissionVersion = 'permission-v1'
): CashierV3DataScopeContext
{
    return new CashierV3DataScopeContext(
        71,
        701,
        8,
        'tenant:default',
        'organization:8',
        $stores,
        $mode,
        [],
        $mode === CashierV3DataScopeContext::MODE_ALL,
        $mode === CashierV3DataScopeContext::MODE_ALL ? 'test-super' : '',
        $permissionVersion,
        $features,
        ['account' => 'C5-O1 tester']
    );
}

function orderRow(
    int $id,
    int $storeId,
    int $payTime,
    array $overrides = []
): array {
    return array_merge([
        'id' => $id,
        'order_id' => 'SO-' . $id,
        'uid' => $id + 1000,
        'real_name' => '会员' . $id,
        'user_phone' => '1380000' . str_pad((string)$id, 4, '0', STR_PAD_LEFT),
        'store_id' => $storeId,
        'order_type' => 0,
        'is_debt_repay' => 0,
        'paid' => 1,
        'is_del' => 0,
        'is_system_del' => 0,
        'is_user_del' => 0,
        'pid' => 0,
        'terminal_action' => 0,
        'refund_status' => 0,
        'status' => 1,
        'total_num' => 1,
        'pay_time' => $payTime,
        'remark' => '历史订单备注 ' . $id,
        // 该字段故意与 pay_time 不同，验证实现没有把创建时间冒充业务日期。
        'add_time' => $payTime - 86400,
    ], $overrides);
}

function lineRow(
    int $id,
    int $orderId,
    string $name,
    int $quantity = 1,
    int $productType = 6,
    int $cartType = 0,
    int $isGift = 0
): array
{
    return [
        'id' => $id,
        'oid' => $orderId,
        'cart_num' => $quantity,
        'product_type' => $productType,
        'cart_type' => $cartType,
        'is_gift' => $isGift,
        'cart_info' => json_encode([
            'productInfo' => [
                'store_name' => $name,
                'product_type' => $productType,
                'attrInfo' => ['suk' => '历史规格'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function fixtureOrderStatus(array $row): string
{
    if ((int)($row['terminal_action'] ?? 0) === 2) return 'voided';
    $refund = (int)($row['refund_status'] ?? 0);
    if (in_array($refund, [1, 4], true)) return 'refund_pending';
    if ($refund === 2) return 'refunded';
    if ($refund === 3) return 'partially_refunded';
    if ((int)($row['is_del'] ?? 0) === 1) return 'cancelled';
    return 'normal';
}

function fixtureBusinessDate(int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone(CashierV3SalesOrderQueryServices::BUSINESS_TIMEZONE))
        ->format('Y-m-d');
}

$settled = 1785117600;
$clockNow = $settled + 86400;
$outsideKeywordWindow = $clockNow - ((CashierV3SalesOrderQueryServices::KEYWORD_SEARCH_WINDOW_DAYS + 1) * 86400);
$orders = [
    orderRow(101, 8, $settled, ['total_num' => 99]),
    orderRow(102, 8, $settled),
    orderRow(103, 9, $settled - 60),
    orderRow(104, 8, $settled - 120, ['refund_status' => 2]),
    orderRow(105, 8, $settled - 180, ['is_del' => 1]),
    orderRow(106, 8, $settled - 240, ['terminal_action' => 2]),
    orderRow(107, 8, $settled - 300, ['refund_status' => 1]),
    orderRow(108, 8, $settled - 360, ['refund_status' => 3]),
    orderRow(109, 8, $settled - 420, ['refund_status' => 4]),
    orderRow(110, 8, $settled - 480, ['refund_status' => 2, 'terminal_action' => 2]),
    orderRow(111, 8, $settled - 540, ['pid' => -2]),
    orderRow(112, 8, $outsideKeywordWindow),
    orderRow(113, 8, $settled - 600),
    orderRow(114, 8, $settled - 660),
    orderRow(201, 8, $settled + 100, ['order_type' => 1]),
    orderRow(202, 8, $settled + 100, ['order_type' => 2]),
    orderRow(203, 8, $settled + 100, ['is_debt_repay' => 1]),
    orderRow(204, 8, $settled + 100, ['paid' => 0]),
    orderRow(205, 8, $settled + 100, ['is_debt_repay' => 1, 'is_del' => 1]),
    orderRow(206, 8, $settled + 100, ['is_system_del' => 1]),
    orderRow(207, 8, $settled + 100, ['pid' => -1]),
    orderRow(208, 8, 0),
];
$lines = [
    lineRow(1001, 101, '水光护理', 2),
    lineRow(1016, 101, '无码产品', 3, 0, 3),
    lineRow(1002, 102, '年度护理次卡', 1, 4),
    lineRow(1012, 102, '卡内水光权益', 12, 6, 2),
    lineRow(1013, 101, '随单赠送项目', 1, 6, 1, 1),
    lineRow(1003, 103, '门店九商品', 2, 0),
    lineRow(1004, 104, '已退款商品'),
    lineRow(1005, 105, '已取消商品'),
    lineRow(1006, 106, '已作废商品'),
    lineRow(1007, 107, '申请退款商品'),
    lineRow(1008, 108, '部分退款商品'),
    lineRow(1009, 109, '退款中商品'),
    lineRow(1010, 110, '作废退款重叠商品'),
    lineRow(1011, 111, '历史负二正式销售'),
    lineRow(1014, 112, '水光远期历史商品'),
    lineRow(1015, 113, '纯赠送项目', 1, 6, 1, 1),
    lineRow(1017, 114, '正式购买项目', 2, 6, 0, 0),
    lineRow(1018, 114, '随单赠送项目', 9, 6, 2, 1),
];
$readerCalls = [];

$reader = function (string $operation, array $criteria) use (&$readerCalls, &$orders, &$lines): array {
    $readerCalls[] = ['operation' => $operation, 'criteria' => $criteria];
    $eligible = array_values(array_filter($orders, function (array $row) use ($criteria, $lines, $operation): bool {
        $pid = (int)$row['pid'];
        if ((int)$row['order_type'] !== 0
            || (int)$row['is_debt_repay'] !== 0
            || (int)$row['paid'] !== 1
            || (int)$row['pay_time'] <= 0
            || (int)$row['is_system_del'] !== 0
            || !($pid >= 0 || $pid === -2)) {
            return false;
        }
        $hasPrimarySaleLine = false;
        foreach ($lines as $line) {
            if ((int)$line['oid'] !== (int)$row['id']) continue;
            if (in_array((int)($line['cart_type'] ?? 0), [0, 3], true)
                && (int)($line['is_gift'] ?? 0) === 0) {
                $hasPrimarySaleLine = true;
            }
        }
        if (!$hasPrimarySaleLine) return false;
        if ($criteria['allowedStoreIds'] !== null
            && !in_array((int)$row['store_id'], $criteria['allowedStoreIds'], true)) {
            return false;
        }
        if (($criteria['memberId'] ?? null) !== null
            && (int)$row['uid'] !== (int)$criteria['memberId']) {
            return false;
        }
        if (($criteria['queryCutoffTimestamp'] ?? null) !== null
            && (int)$row['pay_time'] > (int)$criteria['queryCutoffTimestamp']) {
            return false;
        }
        if ($operation === 'list' && (int)$row['id'] > (int)$criteria['snapshotMaxOrderId']) return false;
        if ($operation === 'detail' && ($criteria['orderId'] ?? null) !== null && (int)$row['id'] !== (int)$criteria['orderId']) {
            return false;
        }
        if ($operation === 'detail' && ($criteria['orderNo'] ?? '') !== '' && (string)$row['order_id'] !== (string)$criteria['orderNo']) {
            return false;
        }
        $status = (string)($criteria['status'] ?? '');
        if ($operation === 'list' && $status !== '' && fixtureOrderStatus($row) !== $status) return false;
        $keyword = (string)($criteria['keyword'] ?? '');
        if ($operation === 'list' && $keyword !== '') {
            if ((int)$row['pay_time'] < (int)$criteria['keywordWindowStartTimestamp']) return false;
            $haystacks = [(string)$row['order_id'], (string)$row['real_name'], (string)$row['user_phone']];
            foreach ($lines as $line) {
                if ((int)$line['oid'] === (int)$row['id']
                    && in_array((int)($line['cart_type'] ?? 0), [0, 3], true)
                    && (int)($line['is_gift'] ?? 0) === 0) {
                    $haystacks[] = (string)$line['cart_info'];
                }
            }
            foreach ($haystacks as $haystack) {
                if (strpos($haystack, $keyword) !== false) return true;
            }
            return false;
        }
        return true;
    }));
    usort($eligible, function (array $left, array $right): int {
        $time = (int)$right['pay_time'] <=> (int)$left['pay_time'];
        return $time !== 0 ? $time : ((int)$right['id'] <=> (int)$left['id']);
    });
    if ($operation === 'snapshot') {
        return ['maxOrderId' => $eligible ? max(array_map('intval', array_column($eligible, 'id'))) : 0];
    }
    if ($operation === 'detail') {
        $row = $eligible[0] ?? null;
        return [
            'row' => $row,
            'lineRows' => $row ? array_values(array_filter($lines, function (array $line) use ($row): bool {
                return (int)$line['oid'] === (int)$row['id']
                    && in_array((int)($line['cart_type'] ?? 0), [0, 3], true)
                    && (int)($line['is_gift'] ?? 0) === 0;
            })) : [],
        ];
    }
    $total = count($eligible);
    $afterPayTime = $criteria['afterPayTime'] ?? null;
    $afterOrderId = $criteria['afterOrderId'] ?? null;
    $pageEligible = array_values(array_filter($eligible, function (array $row) use ($afterPayTime, $afterOrderId): bool {
        if ($afterPayTime === null || $afterOrderId === null) return true;
        return (int)$row['pay_time'] < (int)$afterPayTime
            || ((int)$row['pay_time'] === (int)$afterPayTime && (int)$row['id'] < (int)$afterOrderId);
    }));
    $candidateRows = array_slice($pageEligible, 0, (int)$criteria['pageSize'] + 1);
    $hasMore = count($candidateRows) > (int)$criteria['pageSize'];
    $pageRows = array_slice($candidateRows, 0, (int)$criteria['pageSize']);
    $ids = array_column($pageRows, 'id');
    return [
        'rows' => $pageRows,
        'total' => $total,
        'hasMore' => $hasMore,
        'lineRows' => array_values(array_filter($lines, function (array $line) use ($ids): bool {
            return in_array((int)$line['oid'], array_map('intval', $ids), true)
                && in_array((int)($line['cart_type'] ?? 0), [0, 3], true)
                && (int)($line['is_gift'] ?? 0) === 0;
        })),
    ];
};

$service = new CashierV3SalesOrderQueryServices($reader, function () use ($clockNow): int {
    return $clockNow;
}, new CashierV3SalesOrderCursorCodec('c5-o1-isolated-test-secret'));
$operator = new CashierV3OperatorScope(8, 71, 'organization:8', 'tenant:default');
$storesScope = orderScope(CashierV3DataScopeContext::MODE_STORES, [8]);

echo "== authority and stable pagination ==\n";
$first = $service->querySalesOrders(['page' => 1, 'pageSize' => 1, 'storeIds' => [8, 9]], $operator, $storesScope);
$callsAfterFirst = count($readerCalls);
$orders[] = orderRow(999, 8, $settled - 30);
$lines[] = lineRow(1999, 999, '游标后新增订单');
foreach ($orders as &$mutableOrder) {
    if ((int)$mutableOrder['id'] === 101) $mutableOrder['is_del'] = 1;
}
unset($mutableOrder);
$second = $service->querySalesOrders([
    'page' => 2,
    'pageSize' => 1,
    'storeIds' => [8, 9],
    'queryCursor' => $first['paginationCursor']['next'],
], $operator, $storesScope);
array_pop($orders);
array_pop($lines);
foreach ($orders as &$mutableOrder) {
    if ((int)$mutableOrder['id'] === 101) $mutableOrder['is_del'] = 0;
}
unset($mutableOrder);
orderOk('数据权限与客户端门店取交集', ($readerCalls[0]['criteria']['allowedStoreIds'] ?? null) === [8]);
orderOk('同一结算时间按 id 倒序形成稳定分页', ($first['records'][0]['orderId'] ?? 0) === 102 && ($second['records'][0]['orderId'] ?? 0) === 101, [$first, $second]);
orderOk('首页后新插入的大 ID 不进入旧游标，当页状态仍反映最新值', ($second['records'][0]['orderStatus'] ?? '') === '已取消' && !in_array(999, array_column($second['records'], 'orderId'), true), $second);
orderOk('复用服务端签名游标时不重新取首页锚点', count($readerCalls) === $callsAfterFirst + 1, $readerCalls);
orderOk('响应只暴露不透明游标，不返回 cutoff 或最大订单 ID', ($first['paginationMode'] ?? '') === 'keyset'
    && strpos((string)($first['paginationCursor']['current'] ?? ''), 'c5o1.') === 0
    && !array_key_exists('querySnapshot', $first)
    && !array_key_exists('snapshotMaxOrderId', $first), $first);
orderOk('充值、核销子单、补交、未支付、系统删除、拆分父单、纯赠送单和 settled_at=0 均排除', $first['total'] === 12, $first);
orderOk('pid=-2 且 order_type=0 的历史正式销售纳入，其他负 pid 仍排除', $service->querySalesOrders(['keyword' => '负二正式销售'], $operator, $storesScope)['total'] === 1);
orderOk('固定排序合同传给真实读取器', ($readerCalls[0]['criteria']['sort'] ?? null) === [['pay_time', 'desc'], ['id', 'desc']]);
$memberFiltered = $service->querySalesOrders(['memberId' => 1102], $operator, $storesScope);
orderOk('会员详情按 memberId 精确读取本人的销售订单',
    $memberFiltered['total'] === 1
    && ($memberFiltered['records'][0]['memberId'] ?? 0) === 1102
    && ($memberFiltered['records'][0]['orderId'] ?? 0) === 102,
    $memberFiltered
);

echo "== opaque cursor security ==\n";
$nextCursor = (string)($first['paginationCursor']['next'] ?? '');
$tamperedCursor = substr($nextCursor, 0, -1)
    . (substr($nextCursor, -1) === 'A' ? 'B' : 'A');
$freshFirst = $service->querySalesOrders([
    'page' => 1,
    'pageSize' => 1,
    'storeIds' => [8, 9],
    'query_cursor' => $tamperedCursor,
], $operator, $storesScope);
orderOk('首页忽略写入前旧游标并重建新快照',
    ($freshFirst['records'][0]['orderId'] ?? 0) === 102
    && ($freshFirst['paginationCursor']['current'] ?? '') !== $tamperedCursor,
    $freshFirst
);
$cursorCases = [
    ['篡改签名', ['page' => 2, 'pageSize' => 1, 'storeIds' => [8, 9], 'queryCursor' => $tamperedCursor], $storesScope],
    ['跨筛选重放', ['page' => 2, 'pageSize' => 1, 'storeIds' => [8, 9], 'status' => 'normal', 'queryCursor' => $nextCursor], $storesScope],
    ['跨权限版本重放', ['page' => 2, 'pageSize' => 1, 'storeIds' => [8, 9], 'queryCursor' => $nextCursor], orderScope(CashierV3DataScopeContext::MODE_STORES, [8], ['cashier.v3.order_center'], 'permission-v2')],
    ['页码跳跃', ['page' => 3, 'pageSize' => 1, 'storeIds' => [8, 9], 'queryCursor' => $nextCursor], $storesScope],
];
foreach ($cursorCases as [$label, $payload, $scope]) {
    $rejectedCursor = false;
    try {
        $service->querySalesOrders($payload, $operator, $scope);
    } catch (InvalidArgumentException $exception) {
        $rejectedCursor = $exception->getMessage() === 'sales_order_query_cursor_invalid';
    }
    orderOk($label . '均被拒绝', $rejectedCursor);
}
$legacyRejected = 0;
foreach ([
    ['querySnapshot' => ['cutoffTimestamp' => $clockNow]],
    ['queryCutoffTimestamp' => $clockNow],
    ['snapshotMaxOrderId' => 112],
] as $legacyPayload) {
    try {
        $service->querySalesOrders($legacyPayload, $operator, $storesScope);
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() === 'sales_order_legacy_snapshot_rejected') $legacyRejected++;
    }
}
orderOk('客户端伪造原始 cutoff/max/快照字段全部拒绝', $legacyRejected === 3);

$expiredService = new CashierV3SalesOrderQueryServices(
    $reader,
    function () use ($clockNow): int { return $clockNow + CashierV3SalesOrderQueryServices::CURSOR_TTL_SECONDS + 1; },
    new CashierV3SalesOrderCursorCodec('c5-o1-isolated-test-secret')
);
$expiredRejected = false;
try {
    $expiredService->querySalesOrders([
        'page' => 2,
        'pageSize' => 1,
        'storeIds' => [8, 9],
        'queryCursor' => $nextCursor,
    ], $operator, $storesScope);
} catch (InvalidArgumentException $exception) {
    $expiredRejected = $exception->getMessage() === 'sales_order_query_cursor_invalid';
}
orderOk('30 分钟过期游标被拒绝', $expiredRejected);

echo "== snapshot and economics semantics ==\n";
$snapshot = $service->querySalesOrders(['keyword' => '水光', 'pageSize' => 20], $operator, $storesScope);
$record = $snapshot['records'][0] ?? [];
orderOk('商品搜索命中订单行历史快照', count($snapshot['records']) === 1 && ($record['itemSummary'] ?? '') === '水光护理、无码产品', $snapshot);
orderOk('关键词只搜索主购买行且受 366 日窗口限制', ($snapshot['performancePolicy']['keywordWindowApplied'] ?? false) === true && ($snapshot['performancePolicy']['keywordSearchWindowDays'] ?? 0) === 366, $snapshot);
orderOk('会员姓名和手机号使用订单发生时快照', ($record['memberName'] ?? '') === '会员101' && ($record['phone'] ?? '') === '13800000101', $record);
orderOk('业务日期显式按 Asia/Shanghai 的支付终态推导', ($record['businessDate'] ?? '') === fixtureBusinessDate($settled) && ($record['businessTimezone'] ?? '') === 'Asia/Shanghai' && ($record['businessDateBasis'] ?? '') === 'legacy_payment_completed_at', $record);
orderOk('旧 pay_price 不冒充应收或现金业绩', array_key_exists('actualReceivedAmount', $record) && $record['actualReceivedAmount'] === null && $record['receivableAmount'] === null && ($record['cashPerformanceDataStatus'] ?? '') === 'not_ready', $record);
orderOk('件数只从过滤后正式行求和，不使用混合 total_num', ($record['itemCount'] ?? 0) === 5, $record);

$noCodeDetail = $service->salesOrderDetail(['orderId' => '101'], $operator, $storesScope);
orderOk('cart_type=3 旧无码商品是正式销售明细', array_column($noCodeDetail['items'] ?? [], 'name') === ['水光护理', '无码产品'], $noCodeDetail);

$cardDetail = $service->salesOrderDetail(['orderId' => '102'], $operator, $storesScope);
orderOk('卡项详情只返回主购买行，不把 cart_type=2 权益当作商品', count($cardDetail['items'] ?? []) === 1 && ($cardDetail['items'][0]['name'] ?? '') === '年度护理次卡', $cardDetail);
$mixedGiftDetail = $service->salesOrderDetail(['orderId' => '114'], $operator, $storesScope);
orderOk('正式购买加随单赠送的混合订单仍保留，仅排除赠送权益子行',
    array_column($mixedGiftDetail['items'] ?? [], 'name') === ['正式购买项目']
    && ($mixedGiftDetail['itemCount'] ?? 0) === 2
    && ($mixedGiftDetail['itemSummary'] ?? '') === '正式购买项目', $mixedGiftDetail);
$emptyAuthorized = $service->querySalesOrders(['keyword' => '永远不会命中'], $operator, $storesScope);
orderOk('授权查询结果为空时仍返回合法当前游标', $emptyAuthorized['total'] === 0
    && strpos((string)($emptyAuthorized['paginationCursor']['current'] ?? ''), 'c5o1.') === 0, $emptyAuthorized);

echo "== historical terminal states ==\n";
$refundPending = $service->querySalesOrders(['businessStatus' => 'refund_pending'], $operator, $storesScope);
$refunded = $service->querySalesOrders(['status' => 'refunded'], $operator, $storesScope);
$partiallyRefunded = $service->querySalesOrders(['status' => 'partially_refunded'], $operator, $storesScope);
$cancelled = $service->querySalesOrders(['status' => 'cancelled'], $operator, $storesScope);
$voided = $service->querySalesOrders(['status' => 'voided'], $operator, $storesScope);
orderOk('退款申请与退款中通过 businessStatus 进入互斥筛选', array_column($refundPending['records'], 'orderStatus') === ['申请退款', '退款中'], $refundPending);
orderOk('退款历史保留且明确标记', array_column($refunded['records'], 'orderStatus') === ['已退款'], $refunded);
orderOk('历史部分退款不再冒充正常订单', array_column($partiallyRefunded['records'], 'orderStatus') === ['部分退款'], $partiallyRefunded);
orderOk('取消历史保留且明确标记', array_column($cancelled['records'], 'orderStatus') === ['已取消'], $cancelled);
orderOk('作废优先级高于退款且各筛选严格互斥', array_column($voided['records'], 'orderStatus') === ['已作废', '已作废'] && !in_array(110, array_column($refunded['records'], 'orderId'), true), [$voided, $refunded]);

echo "== all/none/self scopes and malformed narrowing ==\n";
$allNarrowed = $service->querySalesOrders(['storeIds' => [9]], $operator, orderScope(CashierV3DataScopeContext::MODE_ALL, null));
$callsBeforeClosed = count($readerCalls);
$none = $service->querySalesOrders([], $operator, orderScope(CashierV3DataScopeContext::MODE_NONE, []));
$self = $service->querySalesOrders([], $operator, orderScope(CashierV3DataScopeContext::MODE_SELF_PARTICIPANT, null));
$malformed = $service->querySalesOrders(['storeIds' => '9'], $operator, orderScope(CashierV3DataScopeContext::MODE_ALL, null));
$noFeature = $service->querySalesOrders([], $operator, orderScope(CashierV3DataScopeContext::MODE_STORES, [8], []));
orderOk('ALL 仍被客户端门店条件缩小', $allNarrowed['total'] === 1 && ($allNarrowed['records'][0]['storeId'] ?? 0) === 9, $allNarrowed);
orderOk('NONE 与 SELF_PARTICIPANT 无专属 provider 时 fail-closed', $none['total'] === 0 && $self['total'] === 0 && $none['dataStatus'] === 'permission_filtered' && $self['dataStatus'] === 'permission_filtered');
orderOk('畸形门店条件与缺失功能权限均 fail-closed 且不访问数据源', $malformed['total'] === 0 && $noFeature['total'] === 0 && count($readerCalls) === $callsBeforeClosed, ['calls' => count($readerCalls), 'before' => $callsBeforeClosed]);

echo "== detail isolation and immutable snapshots ==\n";
$detail = $service->salesOrderDetail(['orderId' => '101'], $operator, $storesScope);
$crossStore = $service->salesOrderDetail(['orderId' => '103'], $operator, $storesScope);
$invalidId = $service->salesOrderDetail(['orderId' => '1 OR 1=1'], $operator, $storesScope);
orderOk('详情按同一 DataScope 读取，跨店与非法 ID 不暴露', is_array($detail) && $crossStore === null && $invalidId === null, [$detail, $crossStore, $invalidId]);
orderOk('详情商品名来自 cart_info 历史快照', ($detail['items'][0]['name'] ?? '') === '水光护理' && ($detail['items'][0]['snapshotStatus'] ?? '') === 'ready', $detail);
orderOk('详情金额与收款明细保持 not_ready，且不开放写动作', ($detail['amountSummary']['dataStatus'] ?? '') === 'not_ready' && ($detail['paymentDetailsDataStatus'] ?? '') === 'not_ready' && ($detail['availableActions'] ?? null) === [], $detail);

echo "== defensive repository gate ==\n";
$malicious = new CashierV3SalesOrderQueryServices(function (string $operation): array {
    if ($operation === 'snapshot') return ['maxOrderId' => 999];
    return ['rows' => [orderRow(999, 8, 1785117600, ['order_type' => 1])], 'total' => 1, 'lineRows' => []];
}, function () use ($clockNow): int { return $clockNow; }, new CashierV3SalesOrderCursorCodec('c5-o1-malicious-test-secret'));
$rejected = false;
try {
    $malicious->querySalesOrders([], $operator, $storesScope);
} catch (RuntimeException $exception) {
    $rejected = $exception->getMessage() === 'sales_order_reader_scope_violation';
}
orderOk('读取器返回越权业务类型时服务层再次 fail-closed', $rejected);

$lateInsert = new CashierV3SalesOrderQueryServices(function (string $operation): array {
    if ($operation === 'snapshot') return ['maxOrderId' => 998];
    return ['rows' => [orderRow(999, 8, 1785117600)], 'total' => 1, 'lineRows' => []];
}, function () use ($clockNow): int { return $clockNow; }, new CashierV3SalesOrderCursorCodec('c5-o1-late-insert-secret'));
$lateRejected = false;
try {
    $lateInsert->querySalesOrders([], $operator, $storesScope);
} catch (RuntimeException $exception) {
    $lateRejected = $exception->getMessage() === 'sales_order_reader_scope_violation';
}
orderOk('快照后插入或读取器越过最大订单 ID 时服务层拒绝', $lateRejected);

$giftOnlyReader = new CashierV3SalesOrderQueryServices(function (string $operation): array {
    if ($operation === 'snapshot') return ['maxOrderId' => 120];
    return [
        'rows' => [orderRow(120, 8, 1785117600)],
        'total' => 1,
        'lineRows' => [lineRow(1200, 120, '纯赠送', 1, 6, 1, 1)],
    ];
}, function () use ($clockNow): int { return $clockNow; }, new CashierV3SalesOrderCursorCodec('c5-o1-gift-only-secret'));
$giftOnlyRejected = false;
try {
    $giftOnlyReader->querySalesOrders([], $operator, $storesScope);
} catch (RuntimeException $exception) {
    $giftOnlyRejected = $exception->getMessage() === 'sales_order_reader_primary_line_violation';
}
orderOk('读取器只返回赠送或权益子行时服务层再次 fail-closed', $giftOnlyRejected);

echo "== root partition and isolated module boundary ==\n";
$recordQueries = new CashierV3OrderCenterRecordQueryServices(function (string $operation): array {
    return ['records' => [], 'total' => 0];
});
$provider = new CashierV3OrderCenterPartitionProvider($service, $recordQueries);
$partition = $provider->readPartition('ctx:test', '1', $operator, $storesScope);
orderOk('orderCenter provider 返回八类正式记录入口但不返回导航数量', $provider->partitionKey() === 'orderCenter'
    && ($partition['ready'] ?? false) === true
    && isset($partition['payload']['recordsByType']['sales'])
    && count($partition['payload']['businessTypes'] ?? []) === 8
    && !isset($partition['payload']['countsByType']), $partition);
$brokenProvider = new CashierV3OrderCenterPartitionProvider(new CashierV3SalesOrderQueryServices(function (): array {
    throw new RuntimeException('database unavailable');
}), $recordQueries);
$broken = $brokenProvider->readPartition('ctx:test', '2', $operator, $storesScope);
orderOk('数据源异常时根分区 ready=false，禁止空壳冒充', ($broken['ready'] ?? true) === false && array_key_exists('payload', $broken) && $broken['payload'] === null, $broken);

$moduleSource = file_get_contents($backendRoot . '/app/services/cashier/v3/order/CashierV3OrderQueryModule.php');
$querySource = file_get_contents($backendRoot . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$cursorSource = file_get_contents($backendRoot . '/app/services/cashier/v3/order/CashierV3SalesOrderCursorCodec.php');
$bootstrapSource = file_get_contents($backendRoot . '/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php');
orderOk('孤立模块注册销售、七类记录和销售详情三个只读 projection', substr_count($moduleSource, "registerProjection('query-sales-orders'") === 1
    && substr_count($moduleSource, "registerProjection('query-order-center-records'") === 1
    && substr_count($moduleSource, "registerProjection('open-sales-order-detail'") === 1);
orderOk('详情 projection 只返回局部数据，不通过全局 overlay 绕过页面请求序号', strpos($moduleSource, "'contractVersion' => CashierV3SalesOrderQueryServices::CONTRACT_VERSION") !== false
    && strpos($moduleSource, "'overlay' =>") === false);
orderOk('真实数据库路径纳入 0/3 正式行且关键词同口径，不因随单赠送整单排除', strpos($querySource, '->whereExists(function ($primaryLine)') !== false
    && strpos($querySource, "IFNULL(primary_ci.cart_type, 0) IN (0,3)") !== false
    && strpos($querySource, '->whereNotExists(function ($giftProjectLine)') === false
    && strpos($querySource, "IFNULL(search_ci.cart_type, 0) IN (0,3)") !== false);
orderOk('取消使用真实 is_del，键集查询不再使用 offset/page', strpos($querySource, "where('o.is_del', 1)") !== false
    && strpos($querySource, "where('o.is_user_del'") === false
    && strpos($querySource, "'afterPayTime'") !== false
    && strpos($querySource, '->page(') === false);
orderOk('不透明游标使用 HMAC-SHA256 与 hash_equals 验签', strpos($cursorSource, "hash_hmac('sha256'") !== false
    && strpos($cursorSource, 'hash_equals(') !== false);
orderOk('生产游标密钥兼容 ThinkPHP 的独立订单密钥配置且缺失时继续 fail-closed', strpos($cursorSource, "'cashier_v3.order_cursor_secret'") !== false
    && strpos($cursorSource, "'app.cashier_v3_order_cursor_secret'") !== false
    && strpos($cursorSource, "sales_order_cursor_secret_unavailable") !== false);
orderOk('生产 Bootstrap 真实安装 C5-O1 模块且仅安装一次', strpos($bootstrapSource, 'use app\\services\\cashier\\v3\\order\\CashierV3OrderQueryModule;') !== false
    && substr_count($bootstrapSource, 'CashierV3OrderQueryModule::install($dispatcher, $assembler);') === 1);
orderOk('孤立模块没有注册退款、作废、升级或卡操作写命令', strpos($moduleSource, "registerCommand(") === false && strpos($moduleSource, "refund-sales-order") === false && strpos($moduleSource, "upgrade-sales-order") === false);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
echo $failed === 0 ? "C5_O1_SALES_READONLY=PASS\n" : "C5_O1_SALES_READONLY=FAIL\n";
exit($failed === 0 ? 0 : 1);
