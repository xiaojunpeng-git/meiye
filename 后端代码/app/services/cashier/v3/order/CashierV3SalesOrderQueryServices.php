<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\settlement\CashierV3CheckoutCraftsmenSnapshot;
use think\facade\Db;

/**
 * 销售订单历史只读模型。
 *
 * 第一阶段只读取 order_type=0 的已结算旧销售主单与订单行快照。旧订单表
 * 没有统一收款／经营事实的完整口径，因此所有经营金额保持 not_ready，禁止
 * 把 pay_price 直接包装成现金业绩、销售额或应收金额。
 */
final class CashierV3SalesOrderQueryServices
{
    public const CONTRACT_VERSION = 'cashier-v3.order-center.v3';
    public const ECONOMICS_STATUS = 'not_ready';
    public const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    public const KEYWORD_SEARCH_WINDOW_DAYS = 366;
    public const MAX_PAGE_SIZE = 100;
    public const CURSOR_TTL_SECONDS = 1800;
    private const CURSOR_VERSION = 1;
    private const PRIMARY_SALE_CART_TYPES = [0, 3];

    /** @var callable|null function(string $operation, array $criteria): array */
    private $reader;
    /** @var callable|null function(): int */
    private $clock;
    /** @var CashierV3SalesOrderCursorCodec|null */
    private $cursorCodec;

    public function __construct(
        callable $reader = null,
        callable $clock = null,
        CashierV3SalesOrderCursorCodec $cursorCodec = null
    )
    {
        $this->reader = $reader;
        $this->clock = $clock;
        $this->cursorCodec = $cursorCodec;
    }

    public function querySalesOrders(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $criteria = $this->listCriteria($payload, $operatorScope, $dataScope);
        if ($criteria['allowedStoreIds'] === [] || !$dataScope->hasFeature('cashier.v3.order_center')) {
            return $this->emptyPage($criteria, 'permission_filtered');
        }

        // New cashier orders have no legacy store_order mirror. The V3 sales
        // order, line, and collection records are therefore the only source
        // that can faithfully show a newly settled checkout.
        if ($this->reader === null) {
            return $this->queryAuthoritySalesOrders($payload, $criteria, $operatorScope, $dataScope);
        }

        $criteria = $this->resolveQueryCursor($criteria, $payload, $operatorScope, $dataScope);
        if ($criteria['snapshotMaxOrderId'] === 0) {
            return $this->emptyPage($criteria, 'partial_legacy_snapshot');
        }

        $raw = $this->read('list', $criteria);
        $rows = is_array($raw['rows'] ?? null) ? array_values($raw['rows']) : [];
        $lineRows = is_array($raw['lineRows'] ?? null) ? array_values($raw['lineRows']) : [];
        $v3ByOrder = is_array($raw['v3ByOrder'] ?? null) ? $raw['v3ByOrder'] : [];
        $total = max(0, (int)($raw['total'] ?? 0));
        $hasMore = ($raw['hasMore'] ?? false) === true;
        if (count($rows) > (int)$criteria['pageSize']) {
            throw new \RuntimeException('sales_order_reader_page_size_invalid');
        }
        if ($total < count($rows)) {
            throw new \RuntimeException('sales_order_reader_total_invalid');
        }
        if ($hasMore && count($rows) !== (int)$criteria['pageSize']) {
            throw new \RuntimeException('sales_order_reader_has_more_invalid');
        }

        $this->assertRowsAuthorized($rows, $criteria, true);
        usort($rows, [self::class, 'compareSettledRows']);
        $linesByOrder = $this->groupLineRows($lineRows, array_column($rows, 'id'));
        $this->assertPrimaryLinesPresent($rows, $linesByOrder);
        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->mapOrder(
                $row,
                $linesByOrder[(int)$row['id']] ?? [],
                false,
                is_array($v3ByOrder[(int)$row['id']] ?? null) ? $v3ByOrder[(int)$row['id']] : []
            );
        }

        $criteria = $this->withPaginationCursors($criteria, $rows, $hasMore);

        return $this->pagePayload($criteria, $records, $total, 'partial_legacy_snapshot');
    }

    public function salesOrderDetail(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $criteria = $this->detailCriteria($payload, $operatorScope, $dataScope);
        if ($criteria === null
            || $criteria['allowedStoreIds'] === []
            || !$dataScope->hasFeature('cashier.v3.order_center')) {
            return null;
        }

        if ($this->reader === null) {
            return $this->authoritySalesOrderDetail($criteria);
        }

        $raw = $this->read('detail', $criteria);
        $row = is_array($raw['row'] ?? null) ? $raw['row'] : null;
        if ($row === null) {
            return null;
        }
        $this->assertRowsAuthorized([$row], $criteria, false);
        $lineRows = is_array($raw['lineRows'] ?? null) ? array_values($raw['lineRows']) : [];
        $linesByOrder = $this->groupLineRows($lineRows, [(int)$row['id']]);
        $this->assertPrimaryLinesPresent([$row], $linesByOrder);
        $v3ByOrder = is_array($raw['v3ByOrder'] ?? null) ? $raw['v3ByOrder'] : [];
        return $this->mapOrder(
            $row,
            $linesByOrder[(int)$row['id']] ?? [],
            true,
            is_array($v3ByOrder[(int)$row['id']] ?? null) ? $v3ByOrder[(int)$row['id']] : []
        );
    }

    public function initialPartition(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array {
        $payload = is_array($hints['salesQuery'] ?? null) ? $hints['salesQuery'] : [];
        // 订单中心首次进入默认展示正常数据；全部数据必须由工具栏显式发空状态。
        $scope = $this->scalarString($payload['dataScope'] ?? $payload['data_scope'] ?? '');
        $businessStatus = $this->scalarString($payload['businessStatus'] ?? $payload['business_status'] ?? '');
        if ($scope === 'all') {
            // 全部数据只能由工具栏显式选择；其业务状态为空时保留全量。
            $payload['status'] = $businessStatus;
        } elseif ($this->scalarString($payload['status'] ?? '') === '') {
            // 根快照或旧调用即使带了空 status，也不能绕过正常范围。
            $payload['status'] = $businessStatus !== '' ? $businessStatus : 'normal';
        }
        $payload['page'] = max(1, (int)($payload['page'] ?? 1));
        $payload['pageSize'] = max(1, (int)($payload['pageSize'] ?? 20));
        $page = $this->querySalesOrders($payload, $operatorScope, $dataScope);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'dataStatus' => $page['dataStatus'],
            'dataAsOf' => $page['dataAsOf'],
            'businessTimezone' => $page['businessTimezone'],
            'paginationMode' => $page['paginationMode'],
            'paginationCursor' => $page['paginationCursor'],
            'hasMore' => $page['hasMore'],
            'freshnessPolicy' => $page['freshnessPolicy'],
            'performancePolicy' => $page['performancePolicy'],
            'businessTypes' => [[
                'key' => 'sales',
                'label' => '销售订单',
                'ready' => true,
            ]],
            'statusOptions' => $page['statusOptions'],
            'statusOptionsByType' => ['sales' => $page['statusOptions']],
            'salesOrders' => $page['records'],
            'recordsByType' => ['sales' => $page['records']],
            'pagesByType' => [
                'sales' => [
                    'total' => $page['total'],
                    'page' => $page['page'],
                    'pageSize' => $page['pageSize'],
                    'dataStatus' => $page['dataStatus'],
                    'paginationMode' => $page['paginationMode'],
                    'paginationCursor' => $page['paginationCursor'],
                    'hasMore' => $page['hasMore'],
                ],
            ],
            'total' => $page['total'],
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
            'salesOrderDetail' => null,
        ];
    }

    private function listCriteria(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $nowTimestamp = $this->nowTimestamp();
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int)($payload['pageSize'] ?? $payload['limit'] ?? 20)));
        $keyword = $this->scalarString($payload['keyword'] ?? $payload['search'] ?? '');
        if (function_exists('mb_substr')) {
            $keyword = mb_substr($keyword, 0, 100, 'UTF-8');
        } else {
            $keyword = substr($keyword, 0, 100);
        }
        $status = $this->scalarString($payload['status'] ?? '');
        if ($status === '') {
            $status = $this->scalarString($payload['businessStatus'] ?? '');
        }
        if (!in_array($status, [
            '', 'normal', 'refund_pending', 'refunded', 'partially_refunded',
            'cancelled', 'voided',
        ], true)) {
            $status = '';
        }
        $dateFrom = $this->validBusinessDate($payload['dateFrom'] ?? $payload['businessDateFrom'] ?? '');
        $dateTo = $this->validBusinessDate($payload['dateTo'] ?? $payload['businessDateTo'] ?? '');
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        $recordType = $this->scalarString($payload['recordType'] ?? $payload['record_type'] ?? 'sales');
        $memberFilterPresent = array_key_exists('memberId', $payload) || array_key_exists('member_id', $payload);
        $memberId = $memberFilterPresent
            ? $this->positiveInteger($payload['memberId'] ?? $payload['member_id'])
            : null;
        $requestedStores = $this->requestedStoreIds($payload);
        $allowedStores = $recordType === 'sales'
            ? $dataScope->narrowVisibleStores($requestedStores)
            : [];
        if ($memberFilterPresent && $memberId === null) {
            $allowedStores = [];
        }

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'keyword' => $keyword,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'memberId' => $memberId,
            'allowedStoreIds' => $this->canonicalStoreIds($allowedStores),
            'operatorStoreId' => $operatorScope->storeId(),
            'operatorId' => $operatorScope->operatorId(),
            'tenantId' => $operatorScope->tenantId(),
            'authorizationMode' => $dataScope->authorizationMode(),
            'requiredOrderType' => 0,
            'excludeDebtRepay' => true,
            'requirePaid' => true,
            'requireSettledAt' => true,
            'includeCancelledSoftDeleted' => true,
            'excludeSplitParent' => true,
            'allowedNegativePids' => [-2],
            'primarySaleCartTypes' => self::PRIMARY_SALE_CART_TYPES,
            'excludeGiftLines' => true,
            'preserveOrdersWithGiftEntitlementLines' => true,
            'businessTimezone' => self::BUSINESS_TIMEZONE,
            'nowTimestamp' => $nowTimestamp,
            'queryCutoffTimestamp' => null,
            'snapshotMaxOrderId' => null,
            'keywordWindowStartTimestamp' => null,
            'afterPayTime' => null,
            'afterOrderId' => null,
            'cursorIssuedAt' => null,
            'cursorExpiresAt' => null,
            'currentCursor' => null,
            'nextCursor' => null,
            'hasMore' => false,
            'sort' => [['pay_time', 'desc'], ['id', 'desc']],
        ];
    }

    private function detailCriteria(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $basePayload = [
            'recordType' => 'sales',
            'page' => 1,
            'pageSize' => 1,
        ];
        if (array_key_exists('storeIds', $payload)) {
            $basePayload['storeIds'] = $payload['storeIds'];
        } elseif (array_key_exists('store_ids', $payload)) {
            $basePayload['store_ids'] = $payload['store_ids'];
        }
        $base = $this->listCriteria($basePayload, $operatorScope, $dataScope);
        $idValue = $payload['orderId'] ?? $payload['salesOrderId'] ?? $payload['id'] ?? null;
        $authorityOrderId = $this->scalarString($idValue);
        $id = $this->positiveInteger($idValue);
        $orderNo = $this->scalarString($payload['salesOrderNo'] ?? $payload['orderNo'] ?? '');
        if ($id === null
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $authorityOrderId) !== 1
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $orderNo) !== 1) {
            return null;
        }
        $base['orderId'] = $id;
        $base['authorityOrderId'] = $id === null && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $authorityOrderId) === 1
            ? $authorityOrderId : '';
        $base['orderNo'] = $id === null ? $orderNo : '';
        return $base;
    }

    /** @return int[]|null */
    private function requestedStoreIds(array $payload)
    {
        $present = array_key_exists('storeIds', $payload) || array_key_exists('store_ids', $payload);
        if (!$present) {
            return null;
        }
        $value = $payload['storeIds'] ?? $payload['store_ids'];
        if (!is_array($value)) {
            return [];
        }
        return $this->canonicalStoreIds($value) ?: [];
    }

    /** @return int[]|null */
    private function canonicalStoreIds($storeIds)
    {
        if ($storeIds === null) {
            return null;
        }
        $normalized = [];
        foreach ((array)$storeIds as $storeId) {
            $id = $this->positiveInteger($storeId);
            if ($id !== null) {
                $normalized[$id] = $id;
            }
        }
        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }

    private function positiveInteger($value)
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            return null;
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
            return null;
        }
        $value = (int)$raw;
        return $value > 0 && (string)$value === $raw ? $value : null;
    }

    private function nonNegativeInteger($value)
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            return null;
        }
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $raw) !== 1) {
            return null;
        }
        $integer = (int)$raw;
        return $integer >= 0 && (string)$integer === $raw ? $integer : null;
    }

    private function scalarString($value): string
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            return '';
        }
        return trim((string)$value);
    }

    private function nowTimestamp(): int
    {
        $now = $this->clock === null ? time() : call_user_func($this->clock);
        $now = $this->positiveInteger($now);
        if ($now === null) {
            throw new \RuntimeException('sales_order_clock_invalid');
        }
        return $now;
    }

    private function resolveQueryCursor(
        array $criteria,
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        foreach (['querySnapshot', 'queryCutoffTimestamp', 'snapshotMaxOrderId'] as $legacyKey) {
            if (array_key_exists($legacyKey, $payload)) {
                throw new \InvalidArgumentException('sales_order_legacy_snapshot_rejected');
            }
        }

        $now = (int)$criteria['nowTimestamp'];
        $fingerprint = $this->cursorFingerprint($criteria, $operatorScope, $dataScope);
        $token = $this->scalarString($payload['queryCursor'] ?? $payload['query_cursor'] ?? '');
        if ($token === '') {
            if ((int)$criteria['page'] !== 1) {
                throw new \InvalidArgumentException('sales_order_query_cursor_required');
            }
            $criteria['queryCutoffTimestamp'] = $now;
            $snapshotCriteria = $criteria;
            $raw = $this->read('snapshot', $snapshotCriteria);
            $maxOrderId = $this->nonNegativeInteger($raw['maxOrderId'] ?? null);
            if ($maxOrderId === null) {
                throw new \RuntimeException('sales_order_reader_snapshot_invalid');
            }
            $criteria['snapshotMaxOrderId'] = $maxOrderId;
            $criteria['cursorIssuedAt'] = $now;
            $criteria['cursorExpiresAt'] = $now + self::CURSOR_TTL_SECONDS;
            $criteria['currentCursor'] = $this->issueCursor($criteria, $fingerprint, 1, null, null);
        } else {
            $decoded = $this->cursorCodec()->decode($token);
            $this->assertCursorPayload($decoded, $fingerprint, (int)$criteria['page'], $now);
            $criteria['queryCutoffTimestamp'] = (int)$decoded['cutoff'];
            $criteria['snapshotMaxOrderId'] = (int)$decoded['maxOrderId'];
            $criteria['afterPayTime'] = (int)$decoded['afterPayTime'] > 0
                ? (int)$decoded['afterPayTime']
                : null;
            $criteria['afterOrderId'] = (int)$decoded['afterOrderId'] > 0
                ? (int)$decoded['afterOrderId']
                : null;
            $criteria['cursorIssuedAt'] = (int)$decoded['issuedAt'];
            $criteria['cursorExpiresAt'] = (int)$decoded['expiresAt'];
            $criteria['currentCursor'] = $token;
        }

        if ($criteria['keyword'] !== '') {
            $criteria['keywordWindowStartTimestamp'] = max(
                1,
                (int)$criteria['queryCutoffTimestamp'] - (self::KEYWORD_SEARCH_WINDOW_DAYS * 86400) + 1
            );
        }
        $criteria['cursorFingerprint'] = $fingerprint;
        return $criteria;
    }

    private function cursorFingerprint(
        array $criteria,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): string {
        $payload = [
            'contract' => self::CONTRACT_VERSION,
            'tenantId' => $operatorScope->tenantId(),
            'organizationId' => $operatorScope->organizationId(),
            'operatorId' => $operatorScope->operatorId(),
            'forcedStoreId' => $operatorScope->storeId(),
            'authorizationMode' => $dataScope->authorizationMode(),
            'permissionVersion' => $dataScope->permissionVersion(),
            'allowedStoreIds' => $criteria['allowedStoreIds'],
            'keyword' => $criteria['keyword'],
            'status' => $criteria['status'],
            'memberId' => $criteria['memberId'],
            'pageSize' => (int)$criteria['pageSize'],
            'orderType' => 0,
            'primarySaleCartTypes' => self::PRIMARY_SALE_CART_TYPES,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('sales_order_cursor_fingerprint_failed');
        }
        return hash('sha256', $json);
    }

    private function issueCursor(
        array $criteria,
        string $fingerprint,
        int $targetPage,
        $afterPayTime,
        $afterOrderId
    ): string {
        return $this->cursorCodec()->encode([
            'version' => self::CURSOR_VERSION,
            'fingerprint' => $fingerprint,
            'issuedAt' => (int)$criteria['cursorIssuedAt'],
            'expiresAt' => (int)$criteria['cursorExpiresAt'],
            'cutoff' => (int)$criteria['queryCutoffTimestamp'],
            'maxOrderId' => (int)$criteria['snapshotMaxOrderId'],
            'page' => $targetPage,
            'afterPayTime' => $afterPayTime === null ? 0 : (int)$afterPayTime,
            'afterOrderId' => $afterOrderId === null ? 0 : (int)$afterOrderId,
        ]);
    }

    private function assertCursorPayload(array $payload, string $fingerprint, int $page, int $now): void
    {
        $required = [
            'version', 'fingerprint', 'issuedAt', 'expiresAt', 'cutoff',
            'maxOrderId', 'page', 'afterPayTime', 'afterOrderId',
        ];
        $keys = array_keys($payload);
        sort($keys);
        $expectedKeys = $required;
        sort($expectedKeys);
        if ($keys !== $expectedKeys
            || (int)($payload['version'] ?? 0) !== self::CURSOR_VERSION
            || !hash_equals($fingerprint, (string)($payload['fingerprint'] ?? ''))
            || $this->positiveInteger($payload['issuedAt'] ?? null) === null
            || $this->positiveInteger($payload['expiresAt'] ?? null) === null
            || $this->positiveInteger($payload['cutoff'] ?? null) === null
            || $this->nonNegativeInteger($payload['maxOrderId'] ?? null) === null
            || $this->positiveInteger($payload['page'] ?? null) !== $page
            || $this->nonNegativeInteger($payload['afterPayTime'] ?? null) === null
            || $this->nonNegativeInteger($payload['afterOrderId'] ?? null) === null) {
            throw new \InvalidArgumentException('sales_order_query_cursor_invalid');
        }
        $issuedAt = (int)$payload['issuedAt'];
        $expiresAt = (int)$payload['expiresAt'];
        $cutoff = (int)$payload['cutoff'];
        $afterPayTime = (int)$payload['afterPayTime'];
        $afterOrderId = (int)$payload['afterOrderId'];
        if ($issuedAt > $now + 5
            || $expiresAt < $now
            || $expiresAt - $issuedAt !== self::CURSOR_TTL_SECONDS
            || $cutoff > $issuedAt
            || (($afterPayTime === 0) !== ($afterOrderId === 0))
            || ($page === 1 && ($afterPayTime !== 0 || $afterOrderId !== 0))
            || ($page > 1 && ($afterPayTime === 0 || $afterOrderId === 0))) {
            throw new \InvalidArgumentException('sales_order_query_cursor_invalid');
        }
    }

    private function withPaginationCursors(array $criteria, array $rows, bool $hasMore): array
    {
        $criteria['hasMore'] = $hasMore;
        if ($hasMore && $rows) {
            $last = $rows[count($rows) - 1];
            $criteria['nextCursor'] = $this->issueCursor(
                $criteria,
                (string)$criteria['cursorFingerprint'],
                (int)$criteria['page'] + 1,
                (int)($last['pay_time'] ?? 0),
                (int)($last['id'] ?? 0)
            );
        }
        return $criteria;
    }

    private function cursorCodec(): CashierV3SalesOrderCursorCodec
    {
        if ($this->cursorCodec === null) {
            $this->cursorCodec = CashierV3SalesOrderCursorCodec::production();
        }
        return $this->cursorCodec;
    }

    private function read(string $operation, array $criteria): array
    {
        if ($this->reader !== null) {
            $result = call_user_func($this->reader, $operation, $criteria);
            if (!is_array($result)) {
                throw new \RuntimeException('sales_order_reader_result_invalid');
            }
            return $result;
        }
        return $this->readDatabase($operation, $criteria);
    }

    /**
     * V3 sales orders are authoritative for every new checkout. Do not wait
     * for a legacy order projection that V3 deliberately does not write.
     */
    private function queryAuthoritySalesOrders(
        array $payload,
        array $criteria,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $criteria = $this->resolveAuthorityQueryCursor($criteria, $payload, $operatorScope, $dataScope);
        if ((int)$criteria['snapshotMaxOrderId'] === 0
            || !in_array((string)$criteria['status'], ['', 'normal', 'refunded', 'voided'], true)) {
            return $this->authorityPagePayload($criteria, [], 0);
        }

        $base = $this->authorityOrderQuery($criteria);
        $total = (int)(clone $base)->count('o.id');
        $rows = $base->field($this->authorityOrderFields())
            ->order('o.settled_at', 'desc')
            ->order('o.id', 'desc')
            ->limit((int)$criteria['pageSize'] + 1)
            ->select()
            ->toArray();
        $hasMore = count($rows) > (int)$criteria['pageSize'];
        if ($hasMore) {
            $rows = array_slice($rows, 0, (int)$criteria['pageSize']);
        }

        $snapshots = $this->authoritySnapshots($rows);
        $records = [];
        foreach ($rows as $row) {
            $orderId = (string)($row['order_id'] ?? '');
            if ($orderId === '' || !isset($snapshots[$orderId])) {
                throw new \RuntimeException('sales_order_authority_snapshot_incomplete');
            }
            $records[] = $this->mapAuthorityOrder($snapshots[$orderId], false);
        }

        $criteria['hasMore'] = $hasMore;
        if ($hasMore && $rows) {
            $last = $rows[count($rows) - 1];
            $criteria['nextCursor'] = $this->issueCursor(
                $criteria,
                (string)$criteria['cursorFingerprint'],
                (int)$criteria['page'] + 1,
                (int)$last['settled_at'],
                (int)$last['id']
            );
        }
        return $this->authorityPagePayload($criteria, $records, $total);
    }

    private function authoritySalesOrderDetail(array $criteria)
    {
        $query = $this->authorityOrderBaseQuery($criteria);
        $authorityOrderId = (string)($criteria['authorityOrderId'] ?? '');
        if ($authorityOrderId !== '') {
            $query->where('o.order_id', $authorityOrderId);
        } else {
            $orderNo = (string)($criteria['orderNo'] ?? '');
            if ($orderNo === '') {
                return null;
            }
            $query->where('o.order_no', $orderNo);
        }
        $row = $query->field($this->authorityOrderFields())->find();
        $row = $row ? (is_array($row) ? $row : $row->toArray()) : null;
        if ($row === null) {
            return null;
        }
        $snapshots = $this->authoritySnapshots([$row]);
        $orderId = (string)$row['order_id'];
        return isset($snapshots[$orderId]) ? $this->mapAuthorityOrder($snapshots[$orderId], true) : null;
    }

    private function resolveAuthorityQueryCursor(
        array $criteria,
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        foreach (['querySnapshot', 'queryCutoffTimestamp', 'snapshotMaxOrderId'] as $legacyKey) {
            if (array_key_exists($legacyKey, $payload)) {
                throw new \InvalidArgumentException('sales_order_legacy_snapshot_rejected');
            }
        }
        $now = (int)$criteria['nowTimestamp'];
        $fingerprint = $this->cursorFingerprint($criteria, $operatorScope, $dataScope);
        $token = $this->scalarString($payload['queryCursor'] ?? $payload['query_cursor'] ?? '');
        if ($token === '') {
            if ((int)$criteria['page'] !== 1) {
                throw new \InvalidArgumentException('sales_order_query_cursor_required');
            }
            $criteria['queryCutoffTimestamp'] = $now;
            $criteria['snapshotMaxOrderId'] = max(0, (int)$this->authorityOrderBaseQuery($criteria)->max('o.id'));
            $criteria['cursorIssuedAt'] = $now;
            $criteria['cursorExpiresAt'] = $now + self::CURSOR_TTL_SECONDS;
            $criteria['currentCursor'] = $this->issueCursor($criteria, $fingerprint, 1, null, null);
        } else {
            $decoded = $this->cursorCodec()->decode($token);
            $this->assertCursorPayload($decoded, $fingerprint, (int)$criteria['page'], $now);
            $criteria['queryCutoffTimestamp'] = (int)$decoded['cutoff'];
            $criteria['snapshotMaxOrderId'] = (int)$decoded['maxOrderId'];
            $criteria['afterPayTime'] = (int)$decoded['afterPayTime'] > 0 ? (int)$decoded['afterPayTime'] : null;
            $criteria['afterOrderId'] = (int)$decoded['afterOrderId'] > 0 ? (int)$decoded['afterOrderId'] : null;
            $criteria['cursorIssuedAt'] = (int)$decoded['issuedAt'];
            $criteria['cursorExpiresAt'] = (int)$decoded['expiresAt'];
            $criteria['currentCursor'] = $token;
        }
        if ($criteria['keyword'] !== '') {
            $criteria['keywordWindowStartTimestamp'] = max(1, (int)$criteria['queryCutoffTimestamp'] - (self::KEYWORD_SEARCH_WINDOW_DAYS * 86400) + 1);
        }
        $criteria['cursorFingerprint'] = $fingerprint;
        return $criteria;
    }

    private function authorityOrderBaseQuery(array $criteria)
    {
        $query = Db::name('cashier_v3_sales_order')->alias('o')
            ->where('o.tenant_id', (string)$criteria['tenantId'])
            ->where('o.order_status', 'settled')
            ->where('o.order_direction', 'forward')
            ->where('o.settled_at', '>', 0);
        if ($criteria['allowedStoreIds'] !== null) {
            $query->whereIn('o.store_id', $criteria['allowedStoreIds']);
        }
        if ($criteria['memberId'] !== null) {
            $query->where('o.member_id', (int)$criteria['memberId']);
        }
        if (($criteria['dateFrom'] ?? '') !== '') $query->where('o.business_date', '>=', $criteria['dateFrom']);
        if (($criteria['dateTo'] ?? '') !== '') $query->where('o.business_date', '<=', $criteria['dateTo']);
        return $query;
    }

    private function authorityOrderQuery(array $criteria)
    {
        $query = $this->authorityOrderBaseQuery($criteria)
            ->where('o.settled_at', '<=', (int)$criteria['queryCutoffTimestamp'])
            ->where('o.id', '<=', (int)$criteria['snapshotMaxOrderId']);
        $this->applyAuthorityStatusFilter($query, (string)$criteria['status']);
        if ($criteria['afterPayTime'] !== null && $criteria['afterOrderId'] !== null) {
            $query->where(function ($anchor) use ($criteria) {
                $anchor->where('o.settled_at', '<', (int)$criteria['afterPayTime'])
                    ->whereOr(function ($sameSecond) use ($criteria) {
                        $sameSecond->where('o.settled_at', (int)$criteria['afterPayTime'])
                            ->where('o.id', '<', (int)$criteria['afterOrderId']);
                    });
            });
        }
        if ($criteria['keyword'] !== '') {
            $like = '%' . addcslashes((string)$criteria['keyword'], "\\%_") . '%';
            $query->where('o.settled_at', '>=', (int)$criteria['keywordWindowStartTimestamp'])
                ->where(function ($nested) use ($like) {
                    $this->whereUtf8Like($nested, 'o.order_no', $like);
                    $this->whereUtf8Like($nested, 'o.member_name_snapshot', $like, true);
                    $nested
                        ->whereOr(function ($lineMatch) use ($like) {
                            $lineMatch->whereExists(function ($line) use ($like) {
                                $line->name('cashier_v3_sales_order_line')->alias('l')
                                    ->whereRaw('l.order_id = o.order_id')
                                    ->where('l.line_status', 'settled')
                                    ->where('l.line_direction', 'forward');
                                $this->whereUtf8Like($line, 'l.item_name_snapshot', $like);
                            });
                        })
                        ->whereOr(function ($personMatch) use ($like) {
                            $personMatch->whereExists(function ($person) use ($like) {
                                $person->name('cashier_v3_performance_fact')->alias('pf')
                                    ->whereRaw('pf.order_id = o.order_id')
                                    ->where('pf.fact_direction', 'forward')
                                    ->where('pf.status', 'effective');
                                $this->whereUtf8Like($person, 'pf.employee_name_snapshot', $like);
                            });
                        });
                });
        }
        return $query;
    }

    private function applyAuthorityStatusFilter($query, string $status): void
    {
        if ($status === '') {
            return;
        }
        if ($status === 'normal') {
            $query->whereNotExists(function ($operation) {
                $operation->name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->alias('olo')
                    ->whereRaw('olo.source_order_id = o.order_id')
                    ->whereRaw('olo.tenant_id = o.tenant_id')
                    ->where('olo.source_type', 'sales')
                    ->where('olo.status', 'succeeded')
                    ->whereIn('olo.operation_type', ['refund', 'void']);
            });
            return;
        }
        $operationType = $status === 'voided' ? 'void' : 'refund';
        $query->whereExists(function ($operation) use ($operationType) {
            $operation->name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->alias('olo')
                ->whereRaw('olo.source_order_id = o.order_id')
                ->whereRaw('olo.tenant_id = o.tenant_id')
                ->where('olo.source_type', 'sales')
                ->where('olo.status', 'succeeded')
                ->where('olo.operation_type', $operationType);
        });
    }

    private function authorityOrderFields(): string
    {
        return implode(',', [
            'o.id,o.order_id,o.order_no,o.tenant_id,o.store_id,o.store_name_snapshot',
            'o.member_id,o.member_name_snapshot,o.operator_id,o.operator_name_snapshot',
            'o.checkout_request_id,o.business_date,o.business_timezone,o.occurred_at,o.settled_at,o.recorded_at',
            'o.business_source_primary_id,o.business_source_primary_name_snapshot',
            'o.business_source_secondary_id,o.business_source_secondary_name_snapshot,o.business_source_label_snapshot',
            // order_note is an order-header snapshot. The authority mapper
            // already exposes it as orderNote, so the base projection must
            // select it for both list and detail reads; otherwise a freshly
            // settled order silently appears to have no main-order remark.
            'o.original_amount_cents,o.discount_amount_cents,o.sale_amount_cents,o.order_version,o.order_note',
        ]);
    }

    private function readDatabase(string $operation, array $criteria): array
    {
        $query = Db::name('store_order')->alias('o')
            ->where('o.order_type', 0)
            ->where('o.is_debt_repay', 0)
            ->where('o.paid', 1)
            ->where('o.pay_time', '>', 0)
            ->where('o.is_system_del', 0)
            ->where(function ($nested) {
                $nested->where('o.pid', '>=', 0)->whereOr('o.pid', -2);
            })
            ->whereExists(function ($primaryLine) {
                $primaryLine->name('store_order_cart_info')->alias('primary_ci')
                    ->whereRaw('primary_ci.oid = o.id')
                    ->whereRaw('IFNULL(primary_ci.cart_type, 0) IN (0,3)')
                    ->whereRaw('IFNULL(primary_ci.is_gift, 0) = 0');
            });
        if ($criteria['allowedStoreIds'] !== null) {
            $query->whereIn('o.store_id', $criteria['allowedStoreIds']);
        }
        if ($criteria['memberId'] !== null) {
            $query->where('o.uid', (int)$criteria['memberId']);
        }
        if ($operation === 'snapshot') {
            $query->where('o.pay_time', '<=', (int)$criteria['queryCutoffTimestamp']);
            return ['maxOrderId' => max(0, (int)$query->max('o.id'))];
        }
        if ($operation === 'detail') {
            if ($criteria['orderId'] !== null) {
                $query->where('o.id', $criteria['orderId']);
            } else {
                $query->where('o.order_id', $criteria['orderNo']);
            }
        } else {
            $query->where('o.pay_time', '<=', (int)$criteria['queryCutoffTimestamp'])
                ->where('o.id', '<=', (int)$criteria['snapshotMaxOrderId']);
            $keyword = (string)$criteria['keyword'];
            if ($keyword !== '') {
                $like = '%' . addcslashes($keyword, "\\%_") . '%';
                $query->where('o.pay_time', '>=', (int)$criteria['keywordWindowStartTimestamp'])
                    ->where(function ($nested) use ($like) {
                        $this->whereUtf8Like($nested, 'o.order_id', $like);
                        $this->whereUtf8Like($nested, 'o.real_name', $like, true);
                        $this->whereUtf8Like($nested, 'o.user_phone', $like, true);
                        $nested
                            ->whereOr(function ($lineMatch) use ($like) {
                                $lineMatch->whereExists(function ($searchLine) use ($like) {
                                    $searchLine->name('store_order_cart_info')->alias('search_ci')
                                        ->whereRaw('search_ci.oid = o.id')
                                        ->whereRaw('IFNULL(search_ci.cart_type, 0) IN (0,3)')
                                        ->whereRaw('IFNULL(search_ci.is_gift, 0) = 0');
                                    $this->whereUtf8Like($searchLine, 'search_ci.cart_info', $like);
                                });
                            });
                    });
            }
            $this->applyStatusFilter($query, (string)$criteria['status']);
        }

        $fields = implode(',', [
            'o.id', 'o.order_id', 'o.uid', 'o.real_name', 'o.user_phone',
            'o.store_id', 'o.order_type', 'o.is_debt_repay', 'o.paid',
            'o.is_del', 'o.is_system_del', 'o.pid',
            'o.terminal_action', 'o.refund_status', 'o.status', 'o.total_num',
            'o.add_time', 'o.pay_time', 'o.remark', 'o.order_note', 'o.total_price', 'o.pay_price',
            'o.cash_pay_price', 'o.debt_amount',
        ]);
        if ($operation === 'detail') {
            $row = $query->field($fields)->find();
            $row = $row ? (is_array($row) ? $row : $row->toArray()) : null;
            $lineRows = $row ? $this->databaseLineRows([(int)$row['id']]) : [];
            return [
                'row' => $row,
                'lineRows' => $lineRows,
                'v3ByOrder' => $this->databaseV3Snapshots($lineRows),
            ];
        }

        // total 在键集锚点之前计算，表示首页冻结插入集合中的当前筛选总数。
        $total = (int)(clone $query)->count('o.id');
        if ($criteria['afterPayTime'] !== null && $criteria['afterOrderId'] !== null) {
            $afterPayTime = (int)$criteria['afterPayTime'];
            $afterOrderId = (int)$criteria['afterOrderId'];
            $query->where(function ($anchor) use ($afterPayTime, $afterOrderId) {
                $anchor->where('o.pay_time', '<', $afterPayTime)
                    ->whereOr(function ($sameSecond) use ($afterPayTime, $afterOrderId) {
                        $sameSecond->where('o.pay_time', $afterPayTime)
                            ->where('o.id', '<', $afterOrderId);
                    });
            });
        }
        $limit = (int)$criteria['pageSize'] + 1;
        $rows = $query->field($fields)
            ->order('o.pay_time', 'desc')
            ->order('o.id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
        $hasMore = count($rows) > (int)$criteria['pageSize'];
        if ($hasMore) {
            $rows = array_slice($rows, 0, (int)$criteria['pageSize']);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'id')))));
        $lineRows = $this->databaseLineRows($ids);
        return [
            'rows' => $rows,
            'total' => $total,
            'hasMore' => $hasMore,
            'lineRows' => $lineRows,
            'v3ByOrder' => $this->databaseV3Snapshots($lineRows),
        ];
    }

    private function applyStatusFilter($query, string $status): void
    {
        if ($status === 'normal') {
            $query->where('o.is_del', 0)
                ->where('o.refund_status', 0)
                ->whereRaw('IFNULL(o.terminal_action, 0) <> 2');
        } elseif ($status === 'refund_pending') {
            $query->whereIn('o.refund_status', [1, 4])
                ->whereRaw('IFNULL(o.terminal_action, 0) <> 2');
        } elseif ($status === 'refunded') {
            $query->where('o.refund_status', 2)
                ->whereRaw('IFNULL(o.terminal_action, 0) <> 2');
        } elseif ($status === 'partially_refunded') {
            $query->where('o.refund_status', 3)
                ->whereRaw('IFNULL(o.terminal_action, 0) <> 2');
        } elseif ($status === 'cancelled') {
            $query->where('o.is_del', 1)
                ->where('o.refund_status', 0)
                ->whereRaw('IFNULL(o.terminal_action, 0) <> 2');
        } elseif ($status === 'voided') {
            $query->where('o.terminal_action', 2);
        }
    }

    /**
     * 订单编号存在 ascii_bin 历史列，关键词可为中文。统一转换 LIKE 两边
     * 的表达式，避免同一 OR 查询组中发生字符集／排序规则冲突。
     * 调用处字段均为本服务固定 SQL 字段，不接受客户端输入。
     */
    private function whereUtf8Like($query, string $field, string $like, bool $or = false): void
    {
        $expression = 'CONVERT(' . $field . ' USING utf8mb4) COLLATE utf8mb4_general_ci LIKE ?';
        if ($or) {
            $query->whereOrRaw($expression, [$like]);
            return;
        }
        $query->whereRaw($expression, [$like]);
    }

    private function databaseLineRows(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }
        return Db::name('store_order_cart_info')
            ->whereIn('oid', $orderIds)
            ->whereRaw('IFNULL(cart_type, 0) IN (0,3)')
            ->whereRaw('IFNULL(is_gift, 0) = 0')
            ->field(
                'id,oid,cart_num,product_type,cart_type,is_gift,cart_info,'
                . 'cash_pay_amount,debt_amount,yue_pay_amount'
            )
            ->order('oid', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * Enrich only rows that carry an immutable V3 sales-order identity in the
     * legacy projection. Historical orders remain on the explicit not_ready
     * economics contract.
     *
     * @return array<int,array<string,mixed>>
     */
    private function databaseV3Snapshots(array $lineRows): array
    {
        $salesOrderIdByLegacyOrder = [];
        $salesLineIdByLegacyLine = [];
        foreach ($lineRows as $line) {
            $snapshot = $this->decodeCartSnapshot($line['cart_info'] ?? []);
            $salesOrderId = trim((string)($snapshot['salesOrderId'] ?? ''));
            $salesOrderLineId = trim((string)($snapshot['salesOrderLineId'] ?? ''));
            if ($salesOrderId === '' || $salesOrderLineId === '') {
                continue;
            }
            $legacyOrderId = (int)($line['oid'] ?? 0);
            $legacyLineId = (int)($line['id'] ?? 0);
            if ($legacyOrderId <= 0 || $legacyLineId <= 0) {
                throw new \RuntimeException('sales_order_v3_projection_identity_invalid');
            }
            if (isset($salesOrderIdByLegacyOrder[$legacyOrderId])
                && $salesOrderIdByLegacyOrder[$legacyOrderId] !== $salesOrderId) {
                throw new \RuntimeException('sales_order_v3_projection_order_conflict');
            }
            $salesOrderIdByLegacyOrder[$legacyOrderId] = $salesOrderId;
            $salesLineIdByLegacyLine[$legacyLineId] = $salesOrderLineId;
        }
        if ($salesOrderIdByLegacyOrder === []) {
            return [];
        }

        $salesOrderIds = array_values(array_unique(array_values($salesOrderIdByLegacyOrder)));
        $headers = Db::name('cashier_v3_sales_order')
            ->whereIn('order_id', $salesOrderIds)
            ->where('order_status', 'settled')
            ->where('order_direction', 'forward')
            ->field(
                'order_id,order_no,tenant_id,store_id,store_name_snapshot,member_id,member_name_snapshot,'
                . 'operator_id,operator_name_snapshot,business_date,business_timezone,occurred_at,settled_at,'
                . 'recorded_at,source_document_type,source_document_id,source_document_no_snapshot,'
                . 'business_source_primary_id,business_source_primary_name_snapshot,'
                . 'business_source_secondary_id,business_source_secondary_name_snapshot,business_source_label_snapshot,'
                . 'original_amount_cents,discount_amount_cents,sale_amount_cents,order_version,order_note'
            )
            ->select()
            ->toArray();
        $headersById = [];
        foreach ($headers as $header) {
            $headersById[(string)$header['order_id']] = $header;
        }
        $snapshotTenantId = trim((string)($headers[0]['tenant_id'] ?? ''));

        $formalLines = Db::name('cashier_v3_sales_order_line')
            ->whereIn('order_id', $salesOrderIds)
            ->where('line_status', 'settled')
            ->where('line_direction', 'forward')
            ->field(
                'order_line_id,order_id,line_no,item_type,item_type_name_snapshot,item_id,'
                . 'checkout_line_id,item_code_snapshot,item_name_snapshot,category_name_snapshot,service_object,is_experience,craftsmen_snapshot_json,quantity,'
                . 'original_amount_cents,discount_amount_cents,sale_amount_cents,card_purchase_snapshot_json,line_version'
            )
            ->select()
            ->toArray();
        $formalLinesById = [];
        foreach ($formalLines as $formalLine) {
            $formalLinesById[(string)$formalLine['order_line_id']] = $formalLine;
        }

        $batches = Db::name('cashier_v3_payment_collection_batch')
            ->whereIn('sales_order_id', $salesOrderIds)
            ->where('batch_status', 'settled')
            ->where('batch_direction', 'forward')
            ->field(
                'batch_id,sales_order_id,collected_amount_cents,cash_performance_amount_cents,'
                . 'receivable_amount_cents,business_date,business_timezone,occurred_at,settled_at,recorded_at'
            )
            ->select()
            ->toArray();
        $batchByOrder = [];
        foreach ($batches as $batch) {
            $orderId = (string)$batch['sales_order_id'];
            if (isset($batchByOrder[$orderId])) {
                throw new \RuntimeException('sales_order_v3_payment_batch_conflict');
            }
            $batchByOrder[$orderId] = $batch;
        }

        $collections = Db::name('cashier_v3_payment_collection')
            ->whereIn('sales_order_id', $salesOrderIds)
            ->where('collection_status', 'settled')
            ->where('collection_direction', 'forward')
            ->field(
                'collection_id,sales_order_id,payment_line_no,payment_method,'
                . 'payment_method_name_snapshot,amount_cents,external_transaction_no_snapshot,'
                . 'remark_snapshot,occurred_at,settled_at'
            )
            ->order('sales_order_id', 'asc')
            ->order('payment_line_no', 'asc')
            ->select()
            ->toArray();
        $collectionsByOrder = [];
        foreach ($collections as $collection) {
            $collectionsByOrder[(string)$collection['sales_order_id']][] = $collection;
        }

        $performanceRows = Db::name('cashier_v3_performance_fact')
            ->whereIn('order_id', $salesOrderIds)
            ->where('fact_type', 'sales_performance_allocated')
            ->where('performance_type', 'sales_performance_allocated')
            ->where('status', 'effective')
            ->where('fact_direction', 'forward')
            ->field(
                'fact_id,order_id,source_line_id,employee_id,employee_name_snapshot,'
                . 'employee_type_snapshot,allocation_weight_numerator,allocation_weight_denominator,'
                . 'allocation_base_amount_cents,amount_cents,role_snapshot,rule_code_snapshot'
            )
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $salespeopleByOrderAndLine = [];
        foreach ($performanceRows as $performance) {
            $salespeopleByOrderAndLine[(string)$performance['order_id']]
                [(string)$performance['source_line_id']][] = $performance;
        }
        $guidesByOrderAndLine = [];
        foreach (Db::name('cashier_v3_customer_guide_round_fact')
            ->where('tenant_id', $snapshotTenantId)->whereIn('order_id', $salesOrderIds)->where('status', 'effective')
            ->field('order_id,source_line_id,guide_employee_id,guide_employee_name_snapshot,guide_round_no')
            ->order('id', 'asc')->select()->toArray() as $fact) {
            $guidesByOrderAndLine[(string)$fact['order_id']][(string)$fact['source_line_id']][] = [
                'id' => (string)$fact['guide_employee_id'], 'employeeId' => (int)$fact['guide_employee_id'],
                'name' => (string)$fact['guide_employee_name_snapshot'], 'guideRoundNo' => (int)$fact['guide_round_no'],
            ];
        }
        $salesManagersByOrderAndLine = [];
        foreach (Db::name('cashier_v3_sales_manager_fact')
            ->where('tenant_id', $snapshotTenantId)->whereIn('order_id', $salesOrderIds)->where('status', 'effective')
            ->field('order_id,source_line_id,sales_manager_employee_id,sales_manager_name_snapshot')
            ->order('id', 'asc')->select()->toArray() as $fact) {
            $salesManagersByOrderAndLine[(string)$fact['order_id']][(string)$fact['source_line_id']][] = [
                'id' => (string)$fact['sales_manager_employee_id'], 'employeeId' => (int)$fact['sales_manager_employee_id'],
                'name' => (string)$fact['sales_manager_name_snapshot'],
            ];
        }

        $result = [];
        foreach ($salesOrderIdByLegacyOrder as $legacyOrderId => $salesOrderId) {
            if (!isset($headersById[$salesOrderId], $batchByOrder[$salesOrderId])) {
                throw new \RuntimeException('sales_order_v3_authority_snapshot_incomplete');
            }
            $linkedLines = [];
            foreach ($lineRows as $line) {
                if ((int)($line['oid'] ?? 0) !== (int)$legacyOrderId) {
                    continue;
                }
                $legacyLineId = (int)($line['id'] ?? 0);
                $formalLineId = (string)($salesLineIdByLegacyLine[$legacyLineId] ?? '');
                if ($formalLineId === '' || !isset($formalLinesById[$formalLineId])) {
                    throw new \RuntimeException('sales_order_v3_line_snapshot_incomplete');
                }
                $checkoutLineId = trim((string)($formalLinesById[$formalLineId]['checkout_line_id'] ?? ''));
                $guideFacts = $guidesByOrderAndLine[$salesOrderId][$formalLineId] ?? [];
                if ($guideFacts === [] && $checkoutLineId !== '') {
                    $guideFacts = $guidesByOrderAndLine[$salesOrderId][$checkoutLineId] ?? [];
                }
                $salesManagerFacts = $salesManagersByOrderAndLine[$salesOrderId][$formalLineId] ?? [];
                if ($salesManagerFacts === [] && $checkoutLineId !== '') {
                    $salesManagerFacts = $salesManagersByOrderAndLine[$salesOrderId][$checkoutLineId] ?? [];
                }
                $linkedLines[$legacyLineId] = [
                    'authority' => $formalLinesById[$formalLineId],
                    'salespeople' => $salespeopleByOrderAndLine[$salesOrderId][$formalLineId] ?? [],
                    'salesManagers' => $salesManagerFacts,
                    'guides' => $guideFacts,
                ];
            }
            $result[(int)$legacyOrderId] = [
                'header' => $headersById[$salesOrderId],
                'batch' => $batchByOrder[$salesOrderId],
                'collections' => $collectionsByOrder[$salesOrderId] ?? [],
                'lines' => $linkedLines,
            ];
        }
        return $result;
    }

    /** @return array<string,array<string,mixed>> keyed by V3 order_id */
    private function authoritySnapshots(array $headers): array
    {
        if ($headers === []) {
            return [];
        }
        $headersByOrder = [];
        foreach ($headers as $header) {
            $orderId = trim((string)($header['order_id'] ?? ''));
            if ($orderId === '') {
                throw new \RuntimeException('sales_order_authority_id_invalid');
            }
            $headersByOrder[$orderId] = $header;
        }
        $orderIds = array_keys($headersByOrder);
        $tenantIds = array_values(array_unique(array_map(static function (array $header): string {
            return trim((string)($header['tenant_id'] ?? ''));
        }, $headers)));
        if (count($tenantIds) !== 1 || $tenantIds[0] === '') {
            throw new \RuntimeException('sales_order_authority_tenant_scope_invalid');
        }
        // Checkout events are the authoritative composition index.  Read it
        // once so optional domains (debt and refund details) are not queried
        // for a plain product sale.  Empty event history keeps the legacy
        // fallback conservative and lets the domain query its own facts.
        $eventTypesByOrder = [];
        $requestToOrder = [];
        foreach ($headersByOrder as $headerOrderId => $header) {
            $headerRequestId = trim((string)($header['checkout_request_id'] ?? ''));
            if ($headerRequestId !== '') $requestToOrder[$headerRequestId] = $headerOrderId;
        }
        $requestIds = array_values(array_unique(array_filter(array_map(function (array $header): string {
            return trim((string)($header['checkout_request_id'] ?? ''));
        }, $headers))));
        $eventQuery = Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)
            ->where('tenant_id', $tenantIds[0] ?? '')
            ->where(function ($nested) use ($orderIds, $requestIds): void {
                if ($requestIds !== []) $nested->whereIn('source_id', $requestIds);
                if ($orderIds !== []) {
                    if ($requestIds !== []) $nested->whereOr(function ($aggregate) use ($orderIds): void {
                        $aggregate->whereIn('aggregate_id', $orderIds);
                    });
                    else $nested->whereIn('aggregate_id', $orderIds);
                }
            })
            ->field('aggregate_id,source_id,event_type')
            ->select()
            ->toArray();
        foreach ($eventQuery as $eventRow) {
            $eventType = trim((string)($eventRow['event_type'] ?? ''));
            $aggregateId = trim((string)($eventRow['aggregate_id'] ?? ''));
            $sourceId = trim((string)($eventRow['source_id'] ?? ''));
            $sourceOrderId = $sourceId !== '' ? ($requestToOrder[$sourceId] ?? '') : '';
            if ($sourceOrderId !== '' && isset($headersByOrder[$sourceOrderId])) {
                $eventTypesByOrder[$sourceOrderId][$eventType] = true;
            }
            if ($aggregateId !== '' && isset($headersByOrder[$aggregateId])) {
                $eventTypesByOrder[$aggregateId][$eventType] = true;
            }
        }
        $linesByOrder = [];
        foreach (Db::name('cashier_v3_sales_order_line')
            ->whereIn('order_id', $orderIds)
            ->where('line_status', 'settled')
            ->where('line_direction', 'forward')
            ->field('order_line_id,checkout_line_id,order_id,line_no,item_type,item_type_name_snapshot,item_id,item_code_snapshot,item_name_snapshot,category_name_snapshot,service_object,is_experience,craftsmen_snapshot_json,quantity,original_amount_cents,discount_amount_cents,sale_amount_cents,debt_amount_cents,coupon_user_id,coupon_name_snapshot,coupon_discount_cents,card_purchase_snapshot_json,detail_remark_snapshot,line_version')
            ->order('order_id', 'asc')->order('line_no', 'asc')->select()->toArray() as $line) {
            $linesByOrder[(string)$line['order_id']][] = $line;
        }
        $batchesByOrder = [];
        foreach (Db::name('cashier_v3_payment_collection_batch')
            ->whereIn('sales_order_id', $orderIds)
            ->where('batch_status', 'settled')
            ->where('batch_direction', 'forward')
            ->field('batch_id,sales_order_id,collected_amount_cents,cash_performance_amount_cents,receivable_amount_cents,settled_at')
            ->select()->toArray() as $batch) {
            $orderId = (string)$batch['sales_order_id'];
            if (isset($batchesByOrder[$orderId])) {
                throw new \RuntimeException('sales_order_authority_payment_batch_conflict');
            }
            $batchesByOrder[$orderId] = $batch;
        }
        $collectionsByOrder = [];
        foreach (Db::name('cashier_v3_payment_collection')
            ->whereIn('sales_order_id', $orderIds)
            ->where('collection_status', 'settled')
            ->where('collection_direction', 'forward')
            ->field('collection_id,sales_order_id,payment_line_no,payment_method,payment_method_name_snapshot,amount_cents,external_transaction_no_snapshot,remark_snapshot,occurred_at')
            ->order('sales_order_id', 'asc')->order('payment_line_no', 'asc')->select()->toArray() as $collection) {
            $collectionsByOrder[(string)$collection['sales_order_id']][] = $collection;
        }
        $requestsById = [];
        if ($requestIds !== []) {
            foreach (Db::name('cashier_v3_checkout_request')->whereIn('request_id', $requestIds)
                ->field('request_id,debt_amount_cents,balance_deduction_amount_cents')->select()->toArray() as $request) {
                $requestsById[(string)$request['request_id']] = $request;
            }
        }
        // A card/project upgrade is a formal sale of the target item.  Its
        // old entitlement value is neither a discount nor cash collection;
        // the immutable settlement record is the only authority for that
        // third settlement component.
        $upgradeSettlementsByOrder = [];
        foreach (Db::name('cashier_v3_card_operation_settlement')
            ->where('tenant_id', $tenantIds[0])
            ->whereIn('sales_order_id', $orderIds)
            ->field('sales_order_id,operation_id,operation_type,entitlement_credit_cents,cash_delta_cents,settlement_status')
            ->order('id', 'asc')->select()->toArray() as $settlement) {
            $orderId = (string)$settlement['sales_order_id'];
            if (isset($upgradeSettlementsByOrder[$orderId])) {
                throw new \RuntimeException('sales_order_authority_upgrade_settlement_conflict');
            }
            $upgradeSettlementsByOrder[$orderId] = $settlement;
        }
        // A multi-card upgrade deliberately has no card-operation/version
        // ledger. Its single immutable upgrade snapshot is the authority for
        // the third settlement component, so normalize it only for the
        // order-centre read model. The snapshot itself is never updated by
        // this projection or by a later lifecycle reversal.
        $multiCardUpgrades = Db::name('cashier_v3_multi_card_upgrade')
            ->where('tenant_id', $tenantIds[0])
            ->whereIn('sales_order_id', $orderIds)
            ->field('sales_order_id,upgrade_id,target_sale_amount_cents,entitlement_credit_cents,excess_writeoff_cents,upgrade_status')
            ->order('id', 'asc')->select()->toArray();
        $multiCardUpgradeSources = [];
        $multiCardUpgradeIds = array_values(array_unique(array_filter(array_map(static function (array $upgrade): string {
            return trim((string)($upgrade['upgrade_id'] ?? ''));
        }, $multiCardUpgrades))));
        if ($multiCardUpgradeIds !== []) {
            foreach (Db::name('cashier_v3_multi_card_upgrade_source')
                ->whereIn('upgrade_id', $multiCardUpgradeIds)
                ->field('upgrade_id,line_no,source_card_name_snapshot,source_card_no_snapshot,source_remaining_value_cents,credit_cents,excess_writeoff_cents')
                ->order('upgrade_id', 'asc')->order('line_no', 'asc')->select()->toArray() as $source) {
                $multiCardUpgradeSources[(string)$source['upgrade_id']][] = $source;
            }
        }
        foreach ($multiCardUpgrades as $upgrade) {
            $orderId = (string)$upgrade['sales_order_id'];
            if (isset($upgradeSettlementsByOrder[$orderId])) {
                throw new \RuntimeException('sales_order_authority_upgrade_settlement_conflict');
            }
            $targetAmount = max(0, (int)$upgrade['target_sale_amount_cents']);
            $creditAmount = max(0, (int)$upgrade['entitlement_credit_cents']);
            $upgradeSettlementsByOrder[$orderId] = [
                'sales_order_id' => $orderId,
                'operation_id' => (string)$upgrade['upgrade_id'],
                'operation_type' => 'card_upgrade',
                'entitlement_credit_cents' => $creditAmount,
                // This is the pre-coupon/pre-price-change cash component,
                // matching the regular upgrade settlement contract.
                'cash_delta_cents' => max(0, $targetAmount - $creditAmount),
                'settlement_status' => (string)$upgrade['upgrade_status'],
                // The source rows are immutable snapshots made at successful
                // settlement. They are display-only evidence for the order
                // detail and never reopen card versions or current balances.
                'source_cards' => $multiCardUpgradeSources[(string)$upgrade['upgrade_id']] ?? [],
                'excess_writeoff_cents' => max(0, (int)$upgrade['excess_writeoff_cents']),
            ];
        }
        $salespeopleByOrderAndLine = [];
        $craftsmenByOrderAndLine = [];
        $guidesByOrderAndLine = [];
        $salesManagersByOrderAndLine = [];
        foreach ($this->effectivePersonnelFacts($orderIds, $tenantIds[0]) as $fact) {
            $lineKey = (string)$fact['source_line_id'];
            if ((string)$fact['performance_type'] === 'sales_performance_allocated') {
                $salespeopleByOrderAndLine[(string)$fact['order_id']][$lineKey][] = $fact;
            } elseif ((string)$fact['performance_type'] === 'labor_performance_allocated') {
                $craftsmenByOrderAndLine[(string)$fact['order_id']][$lineKey][] = $fact;
            }
        }
        foreach (Db::name('cashier_v3_customer_guide_round_fact')
            ->whereIn('order_id', $orderIds)->where('tenant_id', $tenantIds[0])->where('status', 'effective')
            ->field('order_id,source_line_id,guide_employee_id,guide_employee_name_snapshot,guide_round_no')
            ->order('id', 'asc')->select()->toArray() as $fact) {
            $guidesByOrderAndLine[(string)$fact['order_id']][(string)$fact['source_line_id']][] = [
                'id' => (string)$fact['guide_employee_id'], 'employeeId' => (int)$fact['guide_employee_id'],
                'name' => (string)$fact['guide_employee_name_snapshot'], 'guideRoundNo' => (int)$fact['guide_round_no'],
            ];
        }
        foreach (Db::name('cashier_v3_sales_manager_fact')
            ->whereIn('order_id', $orderIds)->where('tenant_id', $tenantIds[0])->where('status', 'effective')
            ->field('order_id,source_line_id,sales_manager_employee_id,sales_manager_name_snapshot')
            ->order('id', 'asc')->select()->toArray() as $fact) {
            $salesManagersByOrderAndLine[(string)$fact['order_id']][(string)$fact['source_line_id']][] = [
                'id' => (string)$fact['sales_manager_employee_id'], 'employeeId' => (int)$fact['sales_manager_employee_id'],
                'name' => (string)$fact['sales_manager_name_snapshot'],
            ];
        }
        $operationsByOrder = [];
        foreach (Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $tenantIds[0])->where('source_type', 'sales')
            ->whereIn('source_order_id', $orderIds)->where('status', 'succeeded')
            ->field('operation_id,operation_no,source_order_id,operation_type,reason_snapshot,cash_refund_cents,reversed_cash_cents,restored_principal_cents,restored_bonus_cents,operator_id,business_date,occurred_at,status')
            ->order('id', 'asc')->select()->toArray() as $operation) {
            $operationsByOrder[(string)$operation['source_order_id']][] = $operation;
        }
        $refundLinesByOperation = [];
        $refundOrderIds = [];
        foreach ($operationsByOrder as $orderId => $operations) {
            foreach ($operations as $operation) {
                if ((string)($operation['operation_type'] ?? '') === 'refund') {
                    $refundOrderIds[] = $orderId;
                    break;
                }
            }
        }
        if ($refundOrderIds !== []) {
            foreach (Db::name(CashierV3OrderLifecycleServices::REFUND_LINE_TABLE)
                ->where('tenant_id', $tenantIds[0])->whereIn('source_order_id', array_values(array_unique($refundOrderIds)))->where('status', 'succeeded')
                ->field('operation_id,refund_line_id,sales_order_line_id,line_no,item_type_snapshot,item_name_snapshot,original_quantity,selected_sale_amount_cents,cash_refund_cents,restored_principal_cents,restored_bonus_cents,total_refund_cents,occurred_at')
                ->order('id', 'asc')->select()->toArray() as $refundLine) {
                $refundLinesByOperation[(string)$refundLine['operation_id']][] = $refundLine;
            }
        }
        foreach ($operationsByOrder as $orderId => $operations) {
            foreach ($operations as $index => $operation) {
                $operations[$index]['refund_lines'] = $refundLinesByOperation[(string)$operation['operation_id']] ?? [];
            }
            $operationsByOrder[$orderId] = $operations;
        }
        $debtAuthoritiesByOrder = [];
        $debtOrderIds = [];
        foreach ($orderIds as $orderId) {
            $eventSetComplete = isset($eventTypesByOrder[$orderId]['checkout.completed']);
            if (!$eventSetComplete
                || isset($eventTypesByOrder[$orderId]['debt.recorded'])
                || isset($eventTypesByOrder[$orderId]['debt.repaid'])) {
                $debtOrderIds[] = $orderId;
            }
        }
        if ($debtOrderIds !== []) {
            foreach (Db::name('cashier_v3_debt_authority')
                ->whereIn('sales_order_id', $debtOrderIds)
                ->field('debt_id,debt_no,sales_order_id,sales_order_no_snapshot,member_id,created_at,updated_at')
                ->order('id', 'asc')->select()->toArray() as $debtAuthority) {
                $debtAuthoritiesByOrder[(string)$debtAuthority['sales_order_id']][] = $debtAuthority;
            }
        }
        $snapshots = [];
        foreach ($headersByOrder as $orderId => $header) {
            if (empty($linesByOrder[$orderId]) || !isset($batchesByOrder[$orderId])) {
                throw new \RuntimeException('sales_order_authority_snapshot_incomplete');
            }
            $requestId = trim((string)($header['checkout_request_id'] ?? ''));
            if ($requestId !== '' && !isset($requestsById[$requestId])) {
                throw new \RuntimeException('sales_order_authority_checkout_request_missing');
            }
            $snapshots[$orderId] = [
                'header' => $header,
                'batch' => $batchesByOrder[$orderId],
                'request' => $requestId === '' ? [] : $requestsById[$requestId],
                'lines' => $linesByOrder[$orderId],
                'collections' => $collectionsByOrder[$orderId] ?? [],
                'salespeopleByLine' => $salespeopleByOrderAndLine[$orderId] ?? [],
                'craftsmenByLine' => $craftsmenByOrderAndLine[$orderId] ?? [],
                'guidesByLine' => $guidesByOrderAndLine[$orderId] ?? [],
                'salesManagersByLine' => $salesManagersByOrderAndLine[$orderId] ?? [],
                'lifecycleOperations' => $operationsByOrder[$orderId] ?? [],
                'upgradeSettlement' => $upgradeSettlementsByOrder[$orderId] ?? [],
                'debtAuthorities' => $debtAuthoritiesByOrder[$orderId] ?? [],
                'eventTypes' => array_keys($eventTypesByOrder[$orderId] ?? []),
            ];
        }
        return $snapshots;
    }

    private function mapAuthorityOrder(array $snapshot, bool $detail): array
    {
        $header = $snapshot['header'];
        $batch = $snapshot['batch'];
        $request = $snapshot['request'];
        // The payment batch is the amount still due after a card/project
        // upgrade's entitlement credit and after any debt allocation.  The
        // sales order itself remains a formal purchase of the target item, so
        // its receivable amount is the settled sales amount.
        $cashReceivableCents = (int)$batch['receivable_amount_cents'];
        $receivableCents = (int)$header['sale_amount_cents'];
        $cashPerformanceCents = (int)$batch['cash_performance_amount_cents'];
        $debtCents = (int)($request['debt_amount_cents'] ?? 0);
        $balanceCents = (int)($request['balance_deduction_amount_cents'] ?? 0);
        $couponDiscountCents = 0;
        $priceChangeDiscountCents = 0;
        foreach ((array)($snapshot['lines'] ?? []) as $line) {
            $coupon = max(0, (int)($line['coupon_discount_cents'] ?? 0));
            $discount = max(0, (int)($line['discount_amount_cents'] ?? 0));
            $couponDiscountCents += $coupon;
            $priceChangeDiscountCents += max(0, $discount - $coupon);
        }
        $headerDiscountCents = max(0, (int)($header['discount_amount_cents'] ?? 0));
        // card_operation_settlement.cash_delta_cents is the immutable cash
        // delta before order-level discounts (coupon/price-change).  The
        // formal sales order is recorded after those discounts, so include
        // the snapshot discount when validating the upgrade component.
        $discountCents = max($headerDiscountCents, $couponDiscountCents + $priceChangeDiscountCents);
        $upgradeSettlement = is_array($snapshot['upgradeSettlement'] ?? null)
            ? $snapshot['upgradeSettlement'] : [];
        $entitlementCreditCents = (int)($upgradeSettlement['entitlement_credit_cents'] ?? 0);
        $hasUpgradeSettlement = $upgradeSettlement !== [];
        $upgradeType = (string)($upgradeSettlement['operation_type'] ?? '');
        $upgradeTypeLabel = (string)($upgradeSettlement['settlement_status'] ?? '') === 'settled'
            ? ($upgradeType === 'project_upgrade' ? '项目升级' : ($upgradeType === 'card_upgrade' ? '卡升级' : ''))
            : '';
        $upgradeSettlementValid = !$hasUpgradeSettlement || (
            (string)($upgradeSettlement['settlement_status'] ?? '') === 'settled'
            && in_array((string)($upgradeSettlement['operation_type'] ?? ''), ['card_upgrade', 'project_upgrade'], true)
            && $entitlementCreditCents >= 0
            && (int)($upgradeSettlement['cash_delta_cents'] ?? -1) === $receivableCents + $discountCents - $entitlementCreditCents
        );
        $settlementEquationValid = $upgradeSettlementValid
            && $entitlementCreditCents >= 0
            && (int)$batch['collected_amount_cents'] === $cashPerformanceCents
            && $debtCents >= 0
            && $balanceCents >= 0
            && $cashReceivableCents >= 0
            && $cashPerformanceCents + $debtCents + $balanceCents + $entitlementCreditCents === $receivableCents
            && $cashReceivableCents + $debtCents + $balanceCents + $entitlementCreditCents === $receivableCents;
        if (!$settlementEquationValid) {
            // A bad historical snapshot must stay visible for reconciliation,
            // but cannot make unrelated business records disappear or expose
            // untrustworthy amounts as normal settlement data.
            $receivableCents = 0;
            $cashPerformanceCents = 0;
            $debtCents = 0;
            $balanceCents = 0;
        }
        $items = [];
        $salespersonNames = [];
        foreach ($snapshot['lines'] as $line) {
            $lineId = (string)$line['order_line_id'];
            $checkoutLineId = trim((string)($line['checkout_line_id'] ?? ''));
            $salesManagers = $snapshot['salesManagersByLine'][$lineId] ?? [];
            if ($salesManagers === [] && $checkoutLineId !== '') {
                $salesManagers = $snapshot['salesManagersByLine'][$checkoutLineId] ?? [];
            }
            $guides = $snapshot['guidesByLine'][$lineId] ?? [];
            if ($guides === [] && $checkoutLineId !== '') {
                $guides = $snapshot['guidesByLine'][$checkoutLineId] ?? [];
            }
            $items[] = $this->mapAuthorityLine(
                $line,
                $snapshot['salespeopleByLine'][$lineId] ?? [],
                $snapshot['craftsmenByLine'][$lineId] ?? [],
                $salesManagers,
                $guides
            );
            foreach ($snapshot['salespeopleByLine'][$lineId] ?? [] as $person) {
                $name = $this->salespersonDisplayName($person);
                if ($name !== '') $salespersonNames[$name] = true;
            }
        }
        $itemNames = array_values(array_unique(array_filter(array_map(function (array $item): string {
            return trim((string)$item['name']);
        }, $items))));
        $itemSummary = implode('、', array_slice($itemNames, 0, 3));
        if (count($itemNames) > 3) $itemSummary .= ' 等' . count($itemNames) . '项';
        $paymentNames = [];
        foreach ($snapshot['collections'] as $collection) $paymentNames[$this->paymentMethodName($collection)] = true;
        $memberName = trim((string)$header['member_name_snapshot']);
        $operations = (array)($snapshot['lifecycleOperations'] ?? []);
        $terminal = null;
        foreach ($operations as $operation) {
            if (in_array((string)$operation['operation_type'], ['refund', 'void'], true)) $terminal = $operation;
        }
        $refundLineCount = $terminal && (string)$terminal['operation_type'] === 'refund'
            ? count((array)($terminal['refund_lines'] ?? [])) : 0;
        $refundTotalCents = 0;
        foreach ((array)($terminal['refund_lines'] ?? []) as $refundLine) $refundTotalCents += (int)($refundLine['total_refund_cents'] ?? 0);
        $isPartialRefund = $terminal && (string)$terminal['operation_type'] === 'refund'
            && ($refundLineCount < count($items) || $refundTotalCents < (int)$header['sale_amount_cents']);
        $orderStatus = !$settlementEquationValid
            ? '数据异常'
            : ($terminal ? ((string)$terminal['operation_type'] === 'void' ? '已作废' : ($isPartialRefund ? '部分退款' : '已退款')) : '正常');
        $paymentStatus = !$settlementEquationValid
            ? '数据异常'
            : ($debtCents > 0 ? '部分支付（含欠款）' : '已支付');
        $economicsStatus = $settlementEquationValid ? 'ready' : 'integrity_failed';
        $mapped = [
            'id' => (string)$header['order_id'], 'orderId' => (string)$header['order_id'],
            // 订单中心可由旧订单投影展示，但所有退款、作废、备注和人员
            // 调整命令必须绑定 V3 销售订单资源，而不是浏览器展示行 ID。
            'lifecycleOrderId' => (string)$header['order_id'],
            'revision' => 1 + count($operations),
            'salesOrderNo' => (string)$header['order_no'], 'sales_order_no' => (string)$header['order_no'],
            // 主项标签只来自已结算的升级事实，避免根据商品名称猜测升级类型。
            'upgradeType' => $upgradeType,
            'upgradeTypeLabel' => $upgradeTypeLabel,
            'memberId' => (int)$header['member_id'], 'memberName' => $memberName !== '' ? $memberName : '游客',
            'member_name' => $memberName !== '' ? $memberName : '游客', 'phone' => '',
            'isGuest' => (int)$header['member_id'] <= 0,
            'storeId' => (int)$header['store_id'], 'storeName' => (string)$header['store_name_snapshot'],
            'storeSnapshotStatus' => 'ready', 'businessDate' => (string)$header['business_date'],
            'business_date' => (string)$header['business_date'], 'businessTimezone' => (string)$header['business_timezone'],
            'businessDateBasis' => 'v3_sales_order_authority',
            'occurredAt' => $this->formatTimestamp((int)$header['occurred_at'], 'Y-m-d H:i:s'),
            'paymentCompletedAt' => $this->formatTimestamp((int)$batch['settled_at'], 'Y-m-d H:i:s'),
            'settledAt' => $this->formatTimestamp((int)$header['settled_at'], 'Y-m-d H:i:s'),
            'itemSummary' => $itemSummary, 'item_summary' => $itemSummary,
            'itemCount' => array_sum(array_map(function (array $item): int { return (int)$item['quantity']; }, $items)),
            'item_count' => array_sum(array_map(function (array $item): int { return (int)$item['quantity']; }, $items)),
            'orderStatus' => $orderStatus, 'order_status' => $orderStatus,
            'paymentStatus' => $paymentStatus, 'payment_status' => $paymentStatus,
            'receivableAmount' => $settlementEquationValid ? $this->moneyFromCents($receivableCents) : null,
            'receivable_amount' => $settlementEquationValid ? $this->moneyFromCents($receivableCents) : null,
            'discountAmount' => $settlementEquationValid ? $this->moneyFromCents((int)$header['discount_amount_cents']) : null,
            'discount_amount' => $settlementEquationValid ? $this->moneyFromCents((int)$header['discount_amount_cents']) : null,
            'debtAmount' => $settlementEquationValid ? $this->moneyFromCents($debtCents) : null,
            'debt_amount' => $settlementEquationValid ? $this->moneyFromCents($debtCents) : null,
            'actualReceivedAmount' => $settlementEquationValid ? $this->moneyFromCents($cashPerformanceCents) : null,
            'actual_received_amount' => $settlementEquationValid ? $this->moneyFromCents($cashPerformanceCents) : null,
            'paymentMethod' => implode('、', array_keys($paymentNames)), 'payment_method' => implode('、', array_keys($paymentNames)),
            'salespersonSummary' => implode('、', array_keys($salespersonNames)), 'cashierName' => (string)$header['operator_name_snapshot'],
            'orderNote' => (string)($header['order_note'] ?? ''),
            'source' => $this->businessSourceLabel($header),
            'economicsDataStatus' => $economicsStatus, 'cashPerformanceDataStatus' => $economicsStatus,
            'dataIntegrityStatus' => $settlementEquationValid ? 'valid' : 'settlement_equation_invalid',
            'contractVersion' => self::CONTRACT_VERSION,
            'availableActions' => !$settlementEquationValid ? [] : ($terminal ? ['reopen-sales-order'] : array_values(array_filter([
                'open-sales-order-personnel-adjustment', 'adjust-sales-order-personnel',
                'update-sales-order-note',
                'reopen-sales-order',
                // 游客没有会员欠款账户，不能进入补交链路。
                (int)$header['member_id'] > 0 ? 'open-order-debt-settlements' : null,
                // 退款只写人工确认的财务冲销，不撤回库存、卡项或后续服务，
                // 因此所有完整结算订单都可进入一次退款。
                'refund-sales-order',
                // 作废仍是严格的全量回滚路径，具体资格由服务端二次校验。
                'void-sales-order',
            ]))),
        ];
        // 查询列表也需要明细行来保持“正常列表”的分组展示；明细页继续复用同一份权威快照。
        $mapped['items'] = $items;
        // 列表与详情复用同一批已结算记账收款事实，避免前端按金额反推收款方式。
        $mapped['paymentDetails'] = !$settlementEquationValid ? [] : array_map(function (array $collection): array {
            return [
                'id' => (string)$collection['collection_id'],
                'paymentMethodCode' => (string)$collection['payment_method'],
                'methodName' => $this->paymentMethodName($collection),
                'amount' => $this->moneyFromCents((int)$collection['amount_cents']),
                'externalTransactionNo' => (string)$collection['external_transaction_no_snapshot'],
                'remark' => (string)$collection['remark_snapshot'],
                'occurredAt' => $this->formatTimestamp((int)$collection['occurred_at'], 'Y-m-d H:i:s'),
            ];
        }, $snapshot['collections']);
        if (!$detail) return $mapped;
        // The order note is a sales-order header fact. Do not replace it with
        // payment collection remarks when opening the detail projection.
        $mapped['orderNote'] = (string)($header['order_note'] ?? '');
        $mapped['amountSummary'] = [
            'originalAmount' => $settlementEquationValid ? $this->moneyFromCents((int)$header['original_amount_cents']) : null,
            'priceChangeDiscountAmount' => $settlementEquationValid ? $this->moneyFromCents($priceChangeDiscountCents) : null,
            'couponDiscountAmount' => $settlementEquationValid ? $this->moneyFromCents($couponDiscountCents) : null,
            'otherDiscountAmount' => $settlementEquationValid
                ? $this->moneyFromCents(max(0, $headerDiscountCents - $priceChangeDiscountCents - $couponDiscountCents)) : null,
            'entitlementCreditAmount' => $settlementEquationValid ? $this->moneyFromCents($entitlementCreditCents) : null,
            'payableAmount' => $settlementEquationValid ? $this->moneyFromCents($receivableCents) : null,
            'debtAmount' => $settlementEquationValid ? $this->moneyFromCents($debtCents) : null,
            'actualReceivedAmount' => $settlementEquationValid ? $this->moneyFromCents($cashPerformanceCents) : null,
            'balancePaymentAmount' => $settlementEquationValid ? $this->moneyFromCents($balanceCents) : null,
            'dataStatus' => $economicsStatus,
        ];
        $mapped['paymentDetails'] = !$settlementEquationValid ? [] : array_map(function (array $collection): array {
            return ['id' => (string)$collection['collection_id'], 'paymentMethodCode' => (string)$collection['payment_method'],
                'methodName' => $this->paymentMethodName($collection), 'amount' => $this->moneyFromCents((int)$collection['amount_cents']),
                'externalTransactionNo' => (string)$collection['external_transaction_no_snapshot'], 'remark' => (string)$collection['remark_snapshot'],
                'occurredAt' => $this->formatTimestamp((int)$collection['occurred_at'], 'Y-m-d H:i:s')];
        }, $snapshot['collections']);
        $mapped['paymentDetailsDataStatus'] = $economicsStatus;
        $mapped['cardBatches'] = [];
        $operationRecords = array_map(function (array $operation): array {
            $labels = ['personnel_adjustment' => '人员调整', 'refund' => '退款', 'void' => '作废', 'reopen' => '重开'];
            $type = (string)$operation['operation_type'];
            $status = (string)$operation['status'];
            return ['id' => (string)$operation['operation_id'], 'operationNo' => (string)$operation['operation_no'],
                'operationType' => (string)$operation['operation_type'], 'status' => $status,
                'statusLabel' => $this->operationStatusLabel($status),
                'actionLabel' => $labels[$type] ?? '订单操作',
                'reason' => (string)$operation['reason_snapshot'], 'content' => (string)$operation['reason_snapshot'],
                'cashRefundAmount' => $this->moneyFromCents((int)$operation['cash_refund_cents']),
                'reversedCashAmount' => $this->moneyFromCents((int)$operation['reversed_cash_cents']),
                'restoredPrincipalAmount' => $this->moneyFromCents((int)$operation['restored_principal_cents']),
                'restoredBonusAmount' => $this->moneyFromCents((int)$operation['restored_bonus_cents']),
                'refundLines' => array_map(function (array $line): array {
                    return [
                        'id' => (string)$line['refund_line_id'], 'orderLineId' => (string)$line['sales_order_line_id'],
                        'itemName' => (string)$line['item_name_snapshot'], 'itemType' => (string)$line['item_type_snapshot'],
                        'quantity' => (int)$line['original_quantity'], 'saleAmount' => $this->moneyFromCents((int)$line['selected_sale_amount_cents']),
                        'cashRefundAmount' => $this->moneyFromCents((int)$line['cash_refund_cents']),
                        'restoredPrincipalAmount' => $this->moneyFromCents((int)$line['restored_principal_cents']),
                        'restoredBonusAmount' => $this->moneyFromCents((int)$line['restored_bonus_cents']),
                        'totalRefundAmount' => $this->moneyFromCents((int)$line['total_refund_cents']),
                    ];
                }, (array)($operation['refund_lines'] ?? [])),
                'operatorId' => (int)$operation['operator_id'], 'operatorName' => '操作人#' . (int)$operation['operator_id'],
                'businessDate' => (string)$operation['business_date'],
                'occurredAt' => $this->formatTimestamp((int)$operation['occurred_at'], 'Y-m-d H:i:s')];
        }, $operations);
        $upgradeRecord = [];
        if ($hasUpgradeSettlement) {
            $operationId = (string)($upgradeSettlement['operation_id'] ?? '');
            $operation = null;
            foreach ($operations as $candidate) {
                if ((string)($candidate['operation_id'] ?? '') === $operationId) {
                    $operation = $candidate;
                    break;
                }
            }
            $upgradeRecord[] = [
                'id' => $operationId,
                'recordId' => $operationId,
                'recordNo' => (string)($operation['operation_no'] ?? $operationId),
                'operationType' => (string)($upgradeSettlement['operation_type'] ?? 'card_upgrade'),
                'status' => (string)($upgradeSettlement['settlement_status'] ?? 'settled'),
                'statusLabel' => '已完成',
                'amount' => $this->moneyFromCents((int)($upgradeSettlement['cash_delta_cents'] ?? 0)),
                'entitlementCreditAmount' => $this->moneyFromCents((int)($upgradeSettlement['entitlement_credit_cents'] ?? 0)),
                'excessWriteoffAmount' => $this->moneyFromCents((int)($upgradeSettlement['excess_writeoff_cents'] ?? 0)),
                'sourceCards' => array_map(function (array $source): array {
                    return [
                        'lineNo' => (int)($source['line_no'] ?? 0),
                        'name' => (string)($source['source_card_name_snapshot'] ?? ''),
                        'cardNo' => (string)($source['source_card_no_snapshot'] ?? ''),
                        'remainingValue' => $this->moneyFromCents((int)($source['source_remaining_value_cents'] ?? 0)),
                        'creditAmount' => $this->moneyFromCents((int)($source['credit_cents'] ?? 0)),
                        'excessWriteoffAmount' => $this->moneyFromCents((int)($source['excess_writeoff_cents'] ?? 0)),
                    ];
                }, (array)($upgradeSettlement['source_cards'] ?? [])),
            ];
        }
        $debtRecords = array_map(function (array $debt) use ($debtCents): array {
            return [
                'id' => (string)($debt['debt_id'] ?? ''),
                'recordId' => (string)($debt['debt_id'] ?? ''),
                'recordNo' => (string)($debt['debt_no'] ?? ''),
                'salesOrderNo' => (string)($debt['sales_order_no_snapshot'] ?? ''),
                'status' => 'open',
                'statusLabel' => '待还款',
                'amount' => $this->moneyFromCents($debtCents),
            ];
        }, (array)($snapshot['debtAuthorities'] ?? []));
        $mapped['related'] = [
            'debtSettlements' => $debtRecords,
            'refunds' => array_values(array_filter($operationRecords, static function (array $row): bool { return $row['operationType'] === 'refund'; })),
            'voids' => array_values(array_filter($operationRecords, static function (array $row): bool { return $row['operationType'] === 'void'; })),
            'reopenings' => array_values(array_filter($operationRecords, static function (array $row): bool { return $row['operationType'] === 'reopen'; })),
            'upgrades' => $upgradeRecord, 'gifts' => [], 'services' => [], 'writeoffs' => [], 'operationLogs' => $operationRecords,
        ];
        $refundLineDetails = [];
        foreach ($operationRecords as $operation) {
            if ($operation['operationType'] !== 'refund') continue;
            foreach ((array)($operation['refundLines'] ?? []) as $refundLine) {
                $refundLineDetails[] = $refundLine;
            }
        }
        $mapped['refundLineDetails'] = $refundLineDetails;
        $mapped['relatedDataStatus'] = 'ready';
        return $mapped;
    }

    private function operationStatusLabel(string $status): string
    {
        return [
            'succeeded' => '已完成',
            'success' => '已完成',
            'failed' => '失败',
            'conflict' => '版本冲突',
            'result_unknown' => '结果未知',
            'pending' => '处理中',
            'processing' => '处理中',
        ][$status] ?? (preg_match('/[\\x{4e00}-\\x{9fff}]/u', $status) === 1 ? $status : '处理中');
    }

    private function mapAuthorityLine(array $line, array $salespeople, array $craftsmen = [], array $salesManagers = [], array $guides = []): array
    {
        $quantity = max(0, (int)$line['quantity']);
        $type = strtolower((string)$line['item_type']) === 'card' ? '卡项' : (strtolower((string)$line['item_type']) === 'project' ? '项目' : '商品');
        return [
            'id' => (string)$line['order_line_id'], 'orderItemId' => (string)$line['order_line_id'], 'itemType' => $type,
            'name' => (string)$line['item_name_snapshot'], 'purchaseSpec' => '', 'quantity' => $quantity,
            'unitPrice' => $quantity > 0 ? $this->moneyFromCents((int)$line['original_amount_cents']) / $quantity : null,
            'originalAmount' => $this->moneyFromCents((int)$line['original_amount_cents']),
            'priceChangeDiscountAmount' => $this->moneyFromCents(max(0, (int)($line['discount_amount_cents'] ?? 0) - (int)($line['coupon_discount_cents'] ?? 0))),
            'couponDiscountAmount' => $this->moneyFromCents((int)($line['coupon_discount_cents'] ?? 0)),
            'couponName' => (string)($line['coupon_name_snapshot'] ?? ''),
            'payableAmount' => $this->moneyFromCents((int)$line['sale_amount_cents']),
            'debtAmount' => $this->moneyFromCents((int)($line['debt_amount_cents'] ?? 0)),
            'actualReceivedAmount' => null, 'economicsDataStatus' => 'ready', 'snapshotStatus' => 'ready',
            // 购买次数只读取销售明细在结账时冻结的购卡快照；绝不从当前
            // 剩余权益、当前卡项配置或服务记录反推，避免历史订单被后续
            // 核销、作废以外的配置变更改写。
            'cardPurchaseTimes' => $type === '卡项'
                ? $this->cardPurchaseTimesSnapshot($line['card_purchase_snapshot_json'] ?? null)
                : [],
            'serviceRecipientType' => (string)($line['service_object'] ?? ''),
            'isExperience' => (int)($line['is_experience'] ?? 0) === 1,
            'detailRemark' => (string)($line['detail_remark_snapshot'] ?? ''),
            'salespeople' => array_map(function (array $person): array {
                return ['id' => (string)$person['fact_id'], 'employeeId' => (int)$person['employee_id'], 'name' => (string)$person['employee_name_snapshot'],
                    'employeeType' => (string)$person['employee_type_snapshot'], 'roleSnapshot' => (string)($person['role_snapshot'] ?? ''), 'allocationWeight' => (int)$person['allocation_weight_numerator'],
                    'allocationWeightDenominator' => (int)$person['allocation_weight_denominator'],
                    'salesPerformanceAmount' => $this->moneyFromCents((int)$person['amount_cents']),
                    'performanceAmountCents' => max(0, (int)$person['amount_cents']),
                    'performanceAmountManual' => strpos((string)($person['rule_code_snapshot'] ?? ''), 'MANUAL-AMOUNT') !== false,
                ];
            }, $salespeople), 'craftsmen' => $craftsmen !== []
                ? array_map(function (array $person): array {
                    return [
                        'id' => (string)$person['fact_id'],
                        'employeeId' => (int)$person['employee_id'],
                        'name' => (string)$person['employee_name_snapshot'],
                        'isPointCustomer' => strpos((string)($person['role_snapshot'] ?? ''), ':point') !== false,
                        'laborPerformanceAmount' => $this->moneyFromCents((int)$person['amount_cents']),
                        'laborFeeAmount' => $this->moneyFromCents((int)($person['labor_fee_amount_cents'] ?? 0)),
                    ];
            }, $craftsmen)
                : $this->craftsmenForLine($line),
            'salesManagers' => array_values($salesManagers),
            'guides' => array_values($guides),
        ];
    }

    /**
     * Only a personnel-adjustment reversal replaces the displayed assignment.
     * Refund and void reversals affect accounting totals but must preserve the
     * last salesperson and craftsman snapshots on the immutable source order.
     *
     * @return array<int,array<string,mixed>>
     */
    private function effectivePersonnelFacts(array $orderIds, string $tenantId): array
    {
        $adjustmentCommandKeys = [];
        foreach (Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $tenantId)->where('source_type', 'sales')
            ->whereIn('source_order_id', $orderIds)->where('operation_type', 'personnel_adjustment')
            ->where('status', 'succeeded')->field('command_idempotency_key')->select()->toArray() as $operation) {
            $commandKey = trim((string)($operation['command_idempotency_key'] ?? ''));
            if ($commandKey !== '') $adjustmentCommandKeys[$commandKey] = true;
        }
        $rows = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)->whereIn('order_id', $orderIds)
            ->whereIn('performance_type', ['sales_performance_allocated', 'labor_performance_allocated'])
            ->where('status', 'effective')
            ->whereIn('fact_direction', ['forward', 'reversal'])
            ->field('id,fact_id,order_id,source_line_id,performance_type,employee_id,employee_name_snapshot,employee_type_snapshot,role_snapshot,allocation_weight_numerator,allocation_weight_denominator,amount_cents,labor_fee_amount_cents,rule_code_snapshot,fact_direction,reversal_of,command_idempotency_key')
            ->order('id', 'asc')->select()->toArray();
        return $this->displayedPersonnelFacts($rows, $adjustmentCommandKeys);
    }

    /** @return array<int,array<string,mixed>> */
    private function displayedPersonnelFacts(array $rows, array $adjustmentCommandKeys): array
    {
        $reversed = [];
        foreach ($rows as $row) {
            if ((string)$row['fact_direction'] === 'reversal'
                && isset($adjustmentCommandKeys[(string)($row['command_idempotency_key'] ?? '')])
                && (string)($row['reversal_of'] ?? '') !== '') {
                $reversed[(string)$row['reversal_of']] = true;
            }
        }
        return array_values(array_filter($rows, static function (array $row) use ($reversed): bool {
            return (string)$row['fact_direction'] === 'forward' && !isset($reversed[(string)$row['fact_id']]);
        }));
    }

    private function authorityPagePayload(array $criteria, array $records, int $total): array
    {
        $payload = $this->pagePayload($criteria, $records, $total, 'authority_v3');
        $payload['economicsDataStatus'] = 'ready';
        return $payload;
    }

    private function assertRowsAuthorized(array $rows, array $criteria, bool $listQuery): void
    {
        foreach ($rows as $row) {
            $pid = (int)($row['pid'] ?? -999);
            $authorized = is_array($row)
                && (int)($row['id'] ?? 0) > 0
                && (int)($row['order_type'] ?? -1) === 0
                && (int)($row['is_debt_repay'] ?? 1) === 0
                && (int)($row['paid'] ?? 0) === 1
                && (int)($row['pay_time'] ?? 0) > 0
                && in_array((int)($row['is_del'] ?? -1), [0, 1], true)
                && (int)($row['is_system_del'] ?? 1) === 0
                && ($pid >= 0 || $pid === -2);
            if ($authorized && $criteria['allowedStoreIds'] !== null) {
                $authorized = in_array((int)($row['store_id'] ?? 0), $criteria['allowedStoreIds'], true);
            }
            if ($authorized && !$listQuery) {
                $authorized = $criteria['orderId'] !== null
                    ? (int)$row['id'] === (int)$criteria['orderId']
                    : (string)($row['order_id'] ?? '') === (string)$criteria['orderNo'];
            }
            if ($authorized && $listQuery) {
                $authorized = (int)$row['pay_time'] <= (int)$criteria['queryCutoffTimestamp']
                    && (int)$row['id'] <= (int)$criteria['snapshotMaxOrderId']
                    && ($criteria['status'] === '' || $this->rowStatusCode($row) === $criteria['status']);
                if ($authorized && $criteria['afterPayTime'] !== null && $criteria['afterOrderId'] !== null) {
                    $payTime = (int)$row['pay_time'];
                    $id = (int)$row['id'];
                    $authorized = $payTime < (int)$criteria['afterPayTime']
                        || ($payTime === (int)$criteria['afterPayTime']
                            && $id < (int)$criteria['afterOrderId']);
                }
                if ($authorized && $criteria['keyword'] !== '') {
                    $authorized = (int)$row['pay_time'] >= (int)$criteria['keywordWindowStartTimestamp'];
                }
            }
            if (!$authorized) {
                throw new \RuntimeException('sales_order_reader_scope_violation');
            }
        }
    }

    private static function compareSettledRows(array $left, array $right): int
    {
        $timeCompare = (int)($right['pay_time'] ?? 0) <=> (int)($left['pay_time'] ?? 0);
        return $timeCompare !== 0
            ? $timeCompare
            : ((int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0));
    }

    private function groupLineRows(array $lineRows, array $allowedOrderIds): array
    {
        $allowed = array_fill_keys(array_map('intval', $allowedOrderIds), true);
        $grouped = [];
        foreach ($lineRows as $line) {
            if (!is_array($line)) {
                continue;
            }
            $orderId = (int)($line['oid'] ?? 0);
            $cartType = (int)($line['cart_type'] ?? 0);
            $isGift = (int)($line['is_gift'] ?? 0);
            if ($orderId <= 0
                || !isset($allowed[$orderId])
                || !in_array($cartType, self::PRIMARY_SALE_CART_TYPES, true)
                || $isGift !== 0) {
                continue;
            }
            $grouped[$orderId][] = $line;
        }
        return $grouped;
    }

    private function assertPrimaryLinesPresent(array $rows, array $linesByOrder): void
    {
        foreach ($rows as $row) {
            $orderId = (int)($row['id'] ?? 0);
            if ($orderId <= 0 || empty($linesByOrder[$orderId])) {
                throw new \RuntimeException('sales_order_reader_primary_line_violation');
            }
        }
    }

    private function mapOrder(array $row, array $lineRows, bool $detail, array $v3 = []): array
    {
        $v3Header = is_array($v3['header'] ?? null) ? $v3['header'] : [];
        $v3Batch = is_array($v3['batch'] ?? null) ? $v3['batch'] : [];
        $v3Ready = $v3Header !== [] && $v3Batch !== [];
        if ($v3Ready) {
            $this->assertV3SnapshotMatchesLegacy($row, $v3Header, $v3Batch);
        }
        $items = [];
        foreach ($lineRows as $line) {
            $legacyLineId = (int)($line['id'] ?? 0);
            $items[] = $this->mapLine(
                $line,
                is_array($v3['lines'][$legacyLineId] ?? null) ? $v3['lines'][$legacyLineId] : []
            );
        }
        $names = array_values(array_filter(array_map(function (array $item): string {
            return trim((string)($item['name'] ?? ''));
        }, $items)));
        $payTime = (int)($row['pay_time'] ?? 0);
        $memberName = trim((string)($row['real_name'] ?? ''));
        $status = $this->orderStatus($row);
        $itemCount = 0;
        foreach ($items as $item) {
            $itemCount += max(0, (int)($item['quantity'] ?? 0));
        }
        $summaryNames = array_slice(array_values(array_unique($names)), 0, 3);
        $itemSummary = implode('、', $summaryNames);
        if (count(array_unique($names)) > count($summaryNames)) {
            $itemSummary .= ' 等' . count(array_unique($names)) . '项';
        }
        $collections = $v3Ready && is_array($v3['collections'] ?? null)
            ? array_values($v3['collections']) : [];
        $salespersonNames = [];
        foreach ($items as $item) {
            foreach (($item['salespeople'] ?? []) as $salesperson) {
                $name = $this->salespersonDisplayName($salesperson);
                if ($name !== '') {
                    $salespersonNames[$name] = true;
                }
            }
        }
        $paymentNames = [];
        foreach ($collections as $collection) {
            $paymentNames[$this->paymentMethodName($collection)] = true;
        }
        $receivable = $v3Ready ? $this->moneyFromCents((int)$v3Batch['receivable_amount_cents']) : null;
        $actualReceived = $v3Ready
            ? $this->moneyFromCents((int)$v3Batch['cash_performance_amount_cents']) : null;
        $debt = $v3Ready ? $this->decimalMoney($row['debt_amount'] ?? '0') : null;
        $balancePayment = $v3Ready ? $receivable - $actualReceived - $debt : null;
        if ($v3Ready && $balancePayment < 0) {
            throw new \RuntimeException('sales_order_v3_settlement_equation_invalid');
        }

        $mapped = [
            'id' => (string)(int)$row['id'],
            'orderId' => (int)$row['id'],
            // 旧订单列表的 id 仅供详情定位；V3 生命周期命令必须使用
            // 对应权威销售单 order_id，否则客户端会取到错误版本并冲突。
            'lifecycleOrderId' => $v3Ready ? (string)$v3Header['order_id'] : '',
            'salesOrderNo' => $v3Ready
                ? (string)$v3Header['order_no'] : (string)($row['order_id'] ?? ''),
            'sales_order_no' => $v3Ready
                ? (string)$v3Header['order_no'] : (string)($row['order_id'] ?? ''),
            'memberId' => (int)($row['uid'] ?? 0),
            'memberName' => $v3Ready
                ? ((string)$v3Header['member_name_snapshot'] !== ''
                    ? (string)$v3Header['member_name_snapshot'] : '游客')
                : ($memberName !== '' ? $memberName : '游客'),
            'member_name' => $v3Ready
                ? ((string)$v3Header['member_name_snapshot'] !== ''
                    ? (string)$v3Header['member_name_snapshot'] : '游客')
                : ($memberName !== '' ? $memberName : '游客'),
            'phone' => (string)($row['user_phone'] ?? ''),
            'isGuest' => (int)($row['uid'] ?? 0) <= 0,
            'storeId' => (int)($row['store_id'] ?? 0),
            'storeName' => $v3Ready ? (string)$v3Header['store_name_snapshot'] : null,
            'storeSnapshotStatus' => $v3Ready ? 'ready' : self::ECONOMICS_STATUS,
            'businessDate' => $v3Ready
                ? (string)$v3Header['business_date'] : $this->formatTimestamp($payTime, 'Y-m-d'),
            'business_date' => $v3Ready
                ? (string)$v3Header['business_date'] : $this->formatTimestamp($payTime, 'Y-m-d'),
            'businessTimezone' => $v3Ready
                ? (string)$v3Header['business_timezone'] : self::BUSINESS_TIMEZONE,
            'businessDateBasis' => $v3Ready ? 'v3_sales_order_authority' : 'legacy_payment_completed_at',
            // 旧单没有 V3 occurred_at，以原订单创建时间追溯实际下单；不可用支付时间冒充。
            'occurredAt' => $v3Ready
                ? $this->formatTimestamp((int)$v3Header['occurred_at'], 'Y-m-d H:i:s')
                : $this->formatTimestamp((int)($row['add_time'] ?? 0), 'Y-m-d H:i:s'),
            'paymentCompletedAt' => $v3Ready
                ? $this->formatTimestamp((int)$v3Batch['settled_at'], 'Y-m-d H:i:s')
                : $this->formatTimestamp($payTime, 'Y-m-d H:i:s'),
            'settledAt' => $v3Ready
                ? $this->formatTimestamp((int)$v3Header['settled_at'], 'Y-m-d H:i:s')
                : $this->formatTimestamp($payTime, 'Y-m-d H:i:s'),
            'itemSummary' => $itemSummary,
            'item_summary' => $itemSummary,
            'itemCount' => $itemCount,
            'item_count' => $itemCount,
            'orderStatus' => $status,
            'order_status' => $status,
            'paymentStatus' => '已支付',
            'payment_status' => '已支付',
            'receivableAmount' => $receivable,
            'receivable_amount' => $receivable,
            'discountAmount' => $v3Ready
                ? $this->moneyFromCents((int)$v3Header['discount_amount_cents']) : null,
            'discount_amount' => $v3Ready
                ? $this->moneyFromCents((int)$v3Header['discount_amount_cents']) : null,
            'debtAmount' => $debt,
            'debt_amount' => $debt,
            'actualReceivedAmount' => $actualReceived,
            'actual_received_amount' => $actualReceived,
            'paymentMethod' => $v3Ready ? implode('、', array_keys($paymentNames)) : null,
            'payment_method' => $v3Ready ? implode('、', array_keys($paymentNames)) : null,
            'salespersonSummary' => $v3Ready ? implode('、', array_keys($salespersonNames)) : null,
            'cashierName' => $v3Ready ? (string)$v3Header['operator_name_snapshot'] : null,
            'source' => $v3Ready ? $this->businessSourceLabel($v3Header) : null,
            'economicsDataStatus' => $v3Ready ? 'ready' : self::ECONOMICS_STATUS,
            'cashPerformanceDataStatus' => $v3Ready ? 'ready' : self::ECONOMICS_STATUS,
            'contractVersion' => self::CONTRACT_VERSION,
            'availableActions' => [],
        ];
        // 列表投影保留同一批商品明细，前端只做表格展示，不重新计算金额。
        $mapped['items'] = $items;
        // 列表与详情复用同一批已结算记账收款事实，避免前端按金额反推收款方式。
        $mapped['paymentDetails'] = !$v3Ready ? [] : array_map(function (array $collection): array {
            return [
                'id' => (string)$collection['collection_id'],
                'paymentMethodCode' => (string)$collection['payment_method'],
                'methodName' => $this->paymentMethodName($collection),
                'amount' => $this->moneyFromCents((int)$collection['amount_cents']),
                'externalTransactionNo' => (string)$collection['external_transaction_no_snapshot'],
                'remark' => (string)$collection['remark_snapshot'],
                'occurredAt' => $this->formatTimestamp((int)$collection['occurred_at'], 'Y-m-d H:i:s'),
            ];
        }, $collections);
        if (!$detail) {
            return $mapped;
        }

        // 订单备注与每笔记账收款的 remark_snapshot 是两个不同事实，
        // 详情只能读取订单自身 order_note，不能把收款备注冒充订单备注。
        $mapped['orderNote'] = (string)($row['order_note'] ?? '');
        $mapped['amountSummary'] = [
            'originalAmount' => $v3Ready
                ? $this->moneyFromCents((int)$v3Header['original_amount_cents']) : null,
            'priceChangeDiscountAmount' => null,
            'couponDiscountAmount' => null,
            'otherDiscountAmount' => $v3Ready
                ? $this->moneyFromCents((int)$v3Header['discount_amount_cents']) : null,
            'payableAmount' => $receivable,
            'debtAmount' => $debt,
            'actualReceivedAmount' => $actualReceived,
            'balancePaymentAmount' => $v3Ready ? $balancePayment : null,
            'dataStatus' => $v3Ready ? 'ready' : self::ECONOMICS_STATUS,
        ];
        $mapped['paymentDetails'] = array_map(function (array $collection): array {
            return [
                'id' => (string)$collection['collection_id'],
                'paymentMethodCode' => (string)$collection['payment_method'],
                'methodName' => $this->paymentMethodName($collection),
                'amount' => $this->moneyFromCents((int)$collection['amount_cents']),
                'externalTransactionNo' => (string)$collection['external_transaction_no_snapshot'],
                'remark' => (string)$collection['remark_snapshot'],
                'occurredAt' => $this->formatTimestamp(
                    (int)$collection['occurred_at'],
                    'Y-m-d H:i:s'
                ),
            ];
        }, $collections);
        $mapped['paymentDetailsDataStatus'] = $v3Ready ? 'ready' : self::ECONOMICS_STATUS;
        $mapped['cardBatches'] = [];
        $mapped['related'] = [
            'debtSettlements' => [],
            'refunds' => [],
            'voids' => [],
            'reopenings' => [],
            'upgrades' => [],
            'gifts' => [],
            'services' => [],
            'writeoffs' => [],
            'operationLogs' => [],
        ];
        $mapped['relatedDataStatus'] = $v3Ready ? 'ready' : self::ECONOMICS_STATUS;
        return $mapped;
    }

    private function mapLine(array $line, array $v3 = []): array
    {
        $snapshot = $this->decodeCartSnapshot($line['cart_info'] ?? []);
        $authority = is_array($v3['authority'] ?? null) ? $v3['authority'] : [];
        $v3Ready = $authority !== [];
        $product = is_array($snapshot['productInfo'] ?? null) ? $snapshot['productInfo'] : [];
        $attr = is_array($product['attrInfo'] ?? null) ? $product['attrInfo'] : [];
        $name = $v3Ready
            ? trim((string)$authority['item_name_snapshot'])
            : trim((string)($product['store_name'] ?? $product['title'] ?? $snapshot['name'] ?? ''));
        $productType = (int)($product['product_type'] ?? $line['product_type'] ?? 0);
        $authorityType = strtolower(trim((string)($authority['item_type'] ?? '')));
        $type = $v3Ready
            ? ($authorityType === 'card' ? '卡项' : ($authorityType === 'project' ? '项目' : '商品'))
            : (in_array($productType, [4, 5], true)
                ? '卡项'
                : ($productType === 6 ? '项目' : '商品'));
        $quantity = $v3Ready
            ? max(0, (int)$authority['quantity']) : max(0, (int)($line['cart_num'] ?? 0));
        $originalAmount = $v3Ready
            ? $this->moneyFromCents((int)$authority['original_amount_cents']) : null;
        $payableAmount = $v3Ready
            ? $this->moneyFromCents((int)$authority['sale_amount_cents']) : null;
        $salespeople = [];
        foreach (($v3['salespeople'] ?? []) as $person) {
            if (!is_array($person)) {
                continue;
            }
            $salespeople[] = [
                'id' => (string)$person['fact_id'],
                'employeeId' => (int)$person['employee_id'],
                'name' => (string)$person['employee_name_snapshot'],
                'employeeType' => (string)$person['employee_type_snapshot'],
                'allocationWeight' => (int)$person['allocation_weight_numerator'],
                'allocationWeightDenominator' => (int)$person['allocation_weight_denominator'],
                'salesPerformanceAmount' => $this->moneyFromCents((int)$person['amount_cents']),
                'performanceAmountCents' => max(0, (int)$person['amount_cents']),
                'performanceAmountManual' => strpos((string)($person['rule_code_snapshot'] ?? ''), 'MANUAL-AMOUNT') !== false,
            ];
        }

        return [
            'id' => $v3Ready
                ? (string)$authority['order_line_id'] : (string)(int)($line['id'] ?? 0),
            'orderItemId' => (int)($line['id'] ?? 0),
            'itemType' => $type,
            'name' => $name,
            'purchaseSpec' => trim((string)($attr['suk'] ?? '')),
            'quantity' => $quantity,
            'unitPrice' => $v3Ready && $quantity > 0 ? $originalAmount / $quantity : null,
            'originalAmount' => $originalAmount,
            'priceChangeDiscountAmount' => null,
            'couponDiscountAmount' => null,
            'payableAmount' => $payableAmount,
            'debtAmount' => $v3Ready ? $this->decimalMoney($line['debt_amount'] ?? '0') : null,
            'actualReceivedAmount' => $v3Ready
                ? $this->decimalMoney($line['cash_pay_amount'] ?? '0') : null,
            'cardPurchaseTimes' => $type === '卡项' && $v3Ready
                ? $this->cardPurchaseTimesSnapshot($authority['card_purchase_snapshot_json'] ?? null)
                : [],
            'serviceRecipientType' => $v3Ready ? (string)($authority['service_object'] ?? '') : '',
            'isExperience' => $v3Ready && (int)($authority['is_experience'] ?? 0) === 1,
            'economicsDataStatus' => $v3Ready ? 'ready' : self::ECONOMICS_STATUS,
            'snapshotStatus' => $name === '' ? 'invalid' : 'ready',
            'salespeople' => $salespeople,
            'salesManagers' => array_values((array)($v3['salesManagers'] ?? [])),
            'guides' => array_values((array)($v3['guides'] ?? [])),
            'craftsmen' => $v3Ready ? $this->craftsmenForLine($authority) : [],
        ];
    }

    /**
     * Map the immutable card-purchase snapshot to the small, display-only
     * purchase-count contract consumed by the order detail.  This method
     * deliberately returns no current balance, remaining count, catalogue
     * identity or raw snapshot so this historical read model cannot be
     * mistaken for the live entitlement authority.
     */
    private function cardPurchaseTimesSnapshot($rawSnapshot): array
    {
        if (!is_string($rawSnapshot) || trim($rawSnapshot) === '') {
            return [];
        }
        $snapshot = json_decode($rawSnapshot, true);
        if (!is_array($snapshot) || array_values($snapshot) === $snapshot) {
            return [];
        }
        $ruleType = trim((string)($snapshot['ruleType'] ?? ''));
        $components = [];
        foreach ((array)($snapshot['components'] ?? []) as $component) {
            if (!is_array($component)) {
                continue;
            }
            $name = trim((string)($component['nameSnapshot'] ?? ''));
            $times = max(0, (int)($component['writeTimes'] ?? 0));
            if ($name === '' || $times <= 0) {
                continue;
            }
            $components[] = ['name' => $name, 'times' => $times];
        }
        // “任选次数”是一个共享次数池，不能错误地展示成每个内含项目
        // 各自拥有该次数；按时长卡不存在可展示的购买次数。
        if ($ruleType === 'choice_count') {
            $sharedTimes = max(0, (int)($snapshot['sharedTimes'] ?? 0));
            return $sharedTimes > 0
                ? ['mode' => 'shared', 'times' => $sharedTimes, 'components' => $components]
                : [];
        }
        if ($ruleType === 'time') {
            return [];
        }
        return $components === [] ? [] : ['mode' => 'independent', 'components' => $components];
    }

    private function craftsmenForLine(array $line): array
    {
        try {
            return CashierV3CheckoutCraftsmenSnapshot::decode(
                $line['craftsmen_snapshot_json'] ?? null
            );
        } catch (\Throwable $exception) {
            throw new \RuntimeException('sales_order_craftsmen_snapshot_invalid');
        }
    }

    private function assertV3SnapshotMatchesLegacy(array $legacy, array $header, array $batch): void
    {
        $receivable = (int)($batch['receivable_amount_cents'] ?? -1);
        $collected = (int)($batch['collected_amount_cents'] ?? -1);
        $cashPerformance = (int)($batch['cash_performance_amount_cents'] ?? -1);
        $debt = $this->decimalToCents($legacy['debt_amount'] ?? '0');
        $balance = $receivable - $collected - $debt;
        if ((int)($legacy['store_id'] ?? 0) !== (int)($header['store_id'] ?? -1)
            || (int)($legacy['uid'] ?? 0) !== (int)($header['member_id'] ?? -1)
            || (string)($header['order_id'] ?? '') !== (string)($batch['sales_order_id'] ?? '')
            || (int)($header['sale_amount_cents'] ?? -1) !== $receivable
            || $collected < 0
            || $cashPerformance !== $collected
            || $debt < 0
            || $balance < 0) {
            throw new \RuntimeException('sales_order_v3_authority_snapshot_mismatch');
        }
    }

    private function decodeCartSnapshot($snapshot): array
    {
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }
        return is_array($snapshot) ? $snapshot : [];
    }

    private function paymentMethodName(array $collection): string
    {
        $snapshot = trim((string)($collection['payment_method_name_snapshot'] ?? ''));
        if ($snapshot !== '') {
            return $snapshot;
        }
        $labels = [
            'unionpay' => '银联',
            'wechat' => '微信',
            'alipay' => '支付宝',
            'dianping_voucher' => '大众验券',
            'douyin_voucher' => '抖音验券',
            'partner' => '合作方收款',
            'other' => '其他收款',
        ];
        $code = strtolower(trim((string)($collection['payment_method'] ?? '')));
        return $labels[$code] ?? ($code !== '' ? $code : '未知收款方式');
    }

    /**
     * 销售来源由结账时选定的快照决定。二级来源被选择时优先显示它，
     * 否则显示一级来源；旧订单缺少快照时保留最小可读兜底。
     */
    private function businessSourceLabel(array $header): string
    {
        foreach ([
            $header['business_source_secondary_name_snapshot'] ?? '',
            $header['business_source_primary_name_snapshot'] ?? '',
            $header['business_source_label_snapshot'] ?? '',
        ] as $value) {
            $label = trim((string)$value);
            if ($label !== '') {
                return $label;
            }
        }
        return '—';
    }

    private function moneyFromCents(int $cents): float
    {
        return $cents / 100;
    }

    private function decimalMoney($value): float
    {
        return $this->decimalToCents($value) / 100;
    }

    private function decimalToCents($value): int
    {
        $value = trim((string)$value);
        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $matches)) {
            throw new \RuntimeException('sales_order_v3_legacy_money_invalid');
        }
        $fraction = str_pad((string)($matches[2] ?? ''), 2, '0');
        $cents = bcmul((string)$matches[1], '100', 0);
        return (int)bcadd($cents, $fraction, 0);
    }

    private function rowStatusCode(array $row): string
    {
        if ((int)($row['terminal_action'] ?? 0) === 2) {
            return 'voided';
        }
        $refundStatus = (int)($row['refund_status'] ?? 0);
        if (in_array($refundStatus, [1, 4], true)) {
            return 'refund_pending';
        }
        if ($refundStatus === 2) {
            return 'refunded';
        }
        if ($refundStatus === 3) {
            return 'partially_refunded';
        }
        if ((int)($row['is_del'] ?? 0) === 1) {
            return 'cancelled';
        }
        return 'normal';
    }

    private function orderStatus(array $row): string
    {
        $labels = [
            'normal' => '正常',
            'refund_pending' => (int)($row['refund_status'] ?? 0) === 1 ? '申请退款' : '退款中',
            'refunded' => '已退款作废',
            'partially_refunded' => '部分退款',
            'cancelled' => '已取消',
            'voided' => '已作废',
        ];
        return $labels[$this->rowStatusCode($row)] ?? '状态异常';
    }

    private function pagePayload(array $criteria, array $records, int $total, string $dataStatus): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'dataStatus' => $dataStatus,
            'economicsDataStatus' => self::ECONOMICS_STATUS,
            'dataAsOf' => $this->formatTimestamp((int)$criteria['nowTimestamp'], DATE_ATOM),
            'businessTimezone' => self::BUSINESS_TIMEZONE,
            'records' => $records,
            'total' => $total,
            'page' => (int)$criteria['page'],
            'pageSize' => (int)$criteria['pageSize'],
            'isLoading' => false,
            'statusOptions' => $this->statusOptions(),
            'paginationMode' => 'keyset',
            'paginationCursor' => [
                'current' => $criteria['currentCursor'],
                'next' => $criteria['nextCursor'],
            ],
            'hasMore' => ($criteria['hasMore'] ?? false) === true,
            'freshnessPolicy' => [
                'membership' => 'insertions_frozen_at_first_page',
                'status' => 'current_at_page_read',
            ],
            'performancePolicy' => [
                'maxPageSize' => self::MAX_PAGE_SIZE,
                'keywordSearchWindowDays' => self::KEYWORD_SEARCH_WINDOW_DAYS,
                'keywordWindowApplied' => $criteria['keyword'] !== '',
                'keywordWindowStartAt' => $criteria['keywordWindowStartTimestamp'] === null
                    ? null
                    : $this->formatTimestamp((int)$criteria['keywordWindowStartTimestamp'], DATE_ATOM),
            ],
        ];
    }

    private function emptyPage(array $criteria, string $dataStatus): array
    {
        return $this->pagePayload($criteria, [], 0, $dataStatus);
    }

    private function statusOptions(): array
    {
        return [
            ['value' => '', 'label' => '全部'],
            ['value' => 'normal', 'label' => '正常'],
            ['value' => 'refund_pending', 'label' => '退款中'],
            ['value' => 'refunded', 'label' => '已退款作废'],
            ['value' => 'partially_refunded', 'label' => '部分退款'],
            ['value' => 'cancelled', 'label' => '已取消'],
            ['value' => 'voided', 'label' => '已作废'],
        ];
    }

    private function validBusinessDate($value): string
    {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function salespersonDisplayName(array $person): string
    {
        $name = trim((string)($person['employee_name_snapshot'] ?? $person['name'] ?? ''));
        if ($name === '') return '';
        $role = strtolower(trim((string)($person['role_snapshot'] ?? $person['roleSnapshot'] ?? '')));
        if ($role === '') $role = strtolower(trim((string)($person['employee_type_snapshot'] ?? $person['employeeType'] ?? '')));
        if (str_contains($role, 'presale') || str_contains($role, 'pre_sale') || str_contains($role, '售前')) return $name . '（售前）';
        if (str_contains($role, 'postsale') || str_contains($role, 'post_sale') || str_contains($role, '售后')) return $name . '（售后）';
        return $name;
    }

    private function formatTimestamp(int $timestamp, string $format)
    {
        if ($timestamp <= 0) {
            return null;
        }
        $date = (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE));
        return $date->format($format);
    }
}
