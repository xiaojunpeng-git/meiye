<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
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
    public const CONTRACT_VERSION = 'cashier-v3.order-center.v2';
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
        $recordType = $this->scalarString($payload['recordType'] ?? $payload['record_type'] ?? 'sales');
        $requestedStores = $this->requestedStoreIds($payload);
        $allowedStores = $recordType === 'sales'
            ? $dataScope->narrowVisibleStores($requestedStores)
            : [];

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'keyword' => $keyword,
            'status' => $status,
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
        if ((int)$criteria['snapshotMaxOrderId'] === 0 || !in_array((string)$criteria['status'], ['', 'normal'], true)) {
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
        return $query;
    }

    private function authorityOrderQuery(array $criteria)
    {
        $query = $this->authorityOrderBaseQuery($criteria)
            ->where('o.settled_at', '<=', (int)$criteria['queryCutoffTimestamp'])
            ->where('o.id', '<=', (int)$criteria['snapshotMaxOrderId']);
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
                    $nested->where('o.order_no', 'like', $like)
                        ->whereOr('o.member_name_snapshot', 'like', $like)
                        ->whereOr(function ($lineMatch) use ($like) {
                            $lineMatch->whereExists(function ($line) use ($like) {
                                $line->name('cashier_v3_sales_order_line')->alias('l')
                                    ->whereRaw('l.order_id = o.order_id')
                                    ->where('l.line_status', 'settled')
                                    ->where('l.line_direction', 'forward')
                                    ->where('l.item_name_snapshot', 'like', $like);
                            });
                        });
                });
        }
        return $query;
    }

    private function authorityOrderFields(): string
    {
        return implode(',', [
            'o.id,o.order_id,o.order_no,o.tenant_id,o.store_id,o.store_name_snapshot',
            'o.member_id,o.member_name_snapshot,o.operator_id,o.operator_name_snapshot',
            'o.checkout_request_id,o.business_date,o.business_timezone,o.occurred_at,o.settled_at,o.recorded_at',
            'o.original_amount_cents,o.discount_amount_cents,o.sale_amount_cents,o.order_version',
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
                        $nested->where('o.order_id', 'like', $like)
                            ->whereOr('o.real_name', 'like', $like)
                            ->whereOr('o.user_phone', 'like', $like)
                            ->whereOr(function ($lineMatch) use ($like) {
                                $lineMatch->whereExists(function ($searchLine) use ($like) {
                                    $searchLine->name('store_order_cart_info')->alias('search_ci')
                                        ->whereRaw('search_ci.oid = o.id')
                                        ->whereRaw('IFNULL(search_ci.cart_type, 0) IN (0,3)')
                                        ->whereRaw('IFNULL(search_ci.is_gift, 0) = 0')
                                        ->where('search_ci.cart_info', 'like', $like);
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
            'o.pay_time', 'o.remark', 'o.total_price', 'o.pay_price',
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
                'order_id,order_no,store_id,store_name_snapshot,member_id,member_name_snapshot,'
                . 'operator_id,operator_name_snapshot,business_date,business_timezone,occurred_at,settled_at,'
                . 'recorded_at,source_document_type,source_document_id,source_document_no_snapshot,'
                . 'original_amount_cents,discount_amount_cents,sale_amount_cents,order_version'
            )
            ->select()
            ->toArray();
        $headersById = [];
        foreach ($headers as $header) {
            $headersById[(string)$header['order_id']] = $header;
        }

        $formalLines = Db::name('cashier_v3_sales_order_line')
            ->whereIn('order_id', $salesOrderIds)
            ->where('line_status', 'settled')
            ->where('line_direction', 'forward')
            ->field(
                'order_line_id,order_id,line_no,item_type,item_type_name_snapshot,item_id,'
                . 'item_code_snapshot,item_name_snapshot,category_name_snapshot,service_object,is_experience,craftsmen_snapshot_json,quantity,'
                . 'original_amount_cents,discount_amount_cents,sale_amount_cents,line_version'
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
                . 'allocation_base_amount_cents,amount_cents,role_snapshot'
            )
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $salespeopleByOrderAndLine = [];
        foreach ($performanceRows as $performance) {
            $salespeopleByOrderAndLine[(string)$performance['order_id']]
                [(string)$performance['source_line_id']][] = $performance;
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
                $linkedLines[$legacyLineId] = [
                    'authority' => $formalLinesById[$formalLineId],
                    'salespeople' => $salespeopleByOrderAndLine[$salesOrderId][$formalLineId] ?? [],
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
        $linesByOrder = [];
        foreach (Db::name('cashier_v3_sales_order_line')
            ->whereIn('order_id', $orderIds)
            ->where('line_status', 'settled')
            ->where('line_direction', 'forward')
            ->field('order_line_id,order_id,line_no,item_type,item_type_name_snapshot,item_id,item_code_snapshot,item_name_snapshot,category_name_snapshot,service_object,is_experience,craftsmen_snapshot_json,quantity,original_amount_cents,discount_amount_cents,sale_amount_cents,line_version')
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
        $requestIds = array_values(array_unique(array_filter(array_map(function (array $header): string {
            return trim((string)($header['checkout_request_id'] ?? ''));
        }, $headers))));
        if ($requestIds !== []) {
            foreach (Db::name('cashier_v3_checkout_request')->whereIn('request_id', $requestIds)
                ->field('request_id,debt_amount_cents,balance_deduction_amount_cents')->select()->toArray() as $request) {
                $requestsById[(string)$request['request_id']] = $request;
            }
        }
        $salespeopleByOrderAndLine = [];
        foreach (Db::name('cashier_v3_performance_fact')->whereIn('order_id', $orderIds)
            ->where('fact_type', 'sales_performance_allocated')
            ->where('performance_type', 'sales_performance_allocated')
            ->where('status', 'effective')->where('fact_direction', 'forward')
            ->field('fact_id,order_id,source_line_id,employee_id,employee_name_snapshot,employee_type_snapshot,allocation_weight_numerator,allocation_weight_denominator,amount_cents')
            ->order('id', 'asc')->select()->toArray() as $fact) {
            $salespeopleByOrderAndLine[(string)$fact['order_id']][(string)$fact['source_line_id']][] = $fact;
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
            ];
        }
        return $snapshots;
    }

    private function mapAuthorityOrder(array $snapshot, bool $detail): array
    {
        $header = $snapshot['header'];
        $batch = $snapshot['batch'];
        $request = $snapshot['request'];
        $receivableCents = (int)$batch['receivable_amount_cents'];
        $cashPerformanceCents = (int)$batch['cash_performance_amount_cents'];
        $debtCents = (int)($request['debt_amount_cents'] ?? 0);
        $balanceCents = (int)($request['balance_deduction_amount_cents'] ?? 0);
        if ((int)$header['sale_amount_cents'] !== $receivableCents
            || (int)$batch['collected_amount_cents'] !== $cashPerformanceCents
            || $debtCents < 0 || $balanceCents < 0
            || $cashPerformanceCents + $debtCents + $balanceCents !== $receivableCents) {
            throw new \RuntimeException('sales_order_authority_settlement_equation_invalid');
        }
        $items = [];
        $salespersonNames = [];
        foreach ($snapshot['lines'] as $line) {
            $items[] = $this->mapAuthorityLine($line, $snapshot['salespeopleByLine'][(string)$line['order_line_id']] ?? []);
            foreach ($snapshot['salespeopleByLine'][(string)$line['order_line_id']] ?? [] as $person) {
                $name = trim((string)($person['employee_name_snapshot'] ?? ''));
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
        $mapped = [
            'id' => (string)$header['order_id'], 'orderId' => (string)$header['order_id'],
            'revision' => (int)$header['order_version'],
            'salesOrderNo' => (string)$header['order_no'], 'sales_order_no' => (string)$header['order_no'],
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
            'orderStatus' => '正常', 'order_status' => '正常',
            'paymentStatus' => $debtCents > 0 ? '部分支付（含欠款）' : '已支付',
            'payment_status' => $debtCents > 0 ? '部分支付（含欠款）' : '已支付',
            'receivableAmount' => $this->moneyFromCents($receivableCents), 'receivable_amount' => $this->moneyFromCents($receivableCents),
            'discountAmount' => $this->moneyFromCents((int)$header['discount_amount_cents']), 'discount_amount' => $this->moneyFromCents((int)$header['discount_amount_cents']),
            'debtAmount' => $this->moneyFromCents($debtCents), 'debt_amount' => $this->moneyFromCents($debtCents),
            'actualReceivedAmount' => $this->moneyFromCents($cashPerformanceCents), 'actual_received_amount' => $this->moneyFromCents($cashPerformanceCents),
            'paymentMethod' => implode('、', array_keys($paymentNames)), 'payment_method' => implode('、', array_keys($paymentNames)),
            'salespersonSummary' => implode('、', array_keys($salespersonNames)), 'cashierName' => (string)$header['operator_name_snapshot'],
            'sourcePrimary' => '收银台', 'sourceSecondary' => 'V3 结账',
            'economicsDataStatus' => 'ready', 'cashPerformanceDataStatus' => 'ready',
            'contractVersion' => self::CONTRACT_VERSION, 'availableActions' => [],
        ];
        if (!$detail) return $mapped;
        $mapped['orderNote'] = '';
        $mapped['items'] = $items;
        $mapped['amountSummary'] = [
            'originalAmount' => $this->moneyFromCents((int)$header['original_amount_cents']),
            'priceChangeDiscountAmount' => null, 'couponDiscountAmount' => null,
            'otherDiscountAmount' => $this->moneyFromCents((int)$header['discount_amount_cents']),
            'payableAmount' => $this->moneyFromCents($receivableCents), 'debtAmount' => $this->moneyFromCents($debtCents),
            'actualReceivedAmount' => $this->moneyFromCents($cashPerformanceCents),
            'balancePaymentAmount' => $this->moneyFromCents($balanceCents), 'dataStatus' => 'ready',
        ];
        $mapped['paymentDetails'] = array_map(function (array $collection): array {
            return ['id' => (string)$collection['collection_id'], 'paymentMethodCode' => (string)$collection['payment_method'],
                'methodName' => $this->paymentMethodName($collection), 'amount' => $this->moneyFromCents((int)$collection['amount_cents']),
                'externalTransactionNo' => (string)$collection['external_transaction_no_snapshot'], 'remark' => (string)$collection['remark_snapshot'],
                'occurredAt' => $this->formatTimestamp((int)$collection['occurred_at'], 'Y-m-d H:i:s')];
        }, $snapshot['collections']);
        $mapped['paymentDetailsDataStatus'] = 'ready';
        $mapped['cardBatches'] = [];
        $mapped['related'] = ['debtSettlements' => [], 'refunds' => [], 'voids' => [], 'reopenings' => [], 'upgrades' => [], 'gifts' => [], 'services' => [], 'writeoffs' => [], 'operationLogs' => []];
        $mapped['relatedDataStatus'] = 'ready';
        return $mapped;
    }

    private function mapAuthorityLine(array $line, array $salespeople): array
    {
        $quantity = max(0, (int)$line['quantity']);
        $type = strtolower((string)$line['item_type']) === 'card' ? '卡项' : (strtolower((string)$line['item_type']) === 'project' ? '项目' : '商品');
        return [
            'id' => (string)$line['order_line_id'], 'orderItemId' => (string)$line['order_line_id'], 'itemType' => $type,
            'name' => (string)$line['item_name_snapshot'], 'purchaseSpec' => '', 'quantity' => $quantity,
            'unitPrice' => $quantity > 0 ? $this->moneyFromCents((int)$line['original_amount_cents']) / $quantity : null,
            'originalAmount' => $this->moneyFromCents((int)$line['original_amount_cents']),
            'priceChangeDiscountAmount' => null, 'couponDiscountAmount' => null,
            'payableAmount' => $this->moneyFromCents((int)$line['sale_amount_cents']), 'debtAmount' => null,
            'actualReceivedAmount' => null, 'economicsDataStatus' => 'ready', 'snapshotStatus' => 'ready',
            'serviceRecipientType' => (string)($line['service_object'] ?? ''),
            'isExperience' => (int)($line['is_experience'] ?? 0) === 1,
            'salespeople' => array_map(function (array $person): array {
                return ['id' => (string)$person['fact_id'], 'employeeId' => (int)$person['employee_id'], 'name' => (string)$person['employee_name_snapshot'],
                    'employeeType' => (string)$person['employee_type_snapshot'], 'allocationWeight' => (int)$person['allocation_weight_numerator'],
                    'allocationWeightDenominator' => (int)$person['allocation_weight_denominator'], 'salesPerformanceAmount' => $this->moneyFromCents((int)$person['amount_cents'])];
            }, $salespeople), 'craftsmen' => $this->craftsmenForLine($line),
        ];
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
                $name = trim((string)($salesperson['name'] ?? ''));
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
            'occurredAt' => $v3Ready
                ? $this->formatTimestamp((int)$v3Header['occurred_at'], 'Y-m-d H:i:s') : null,
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
            'sourcePrimary' => $v3Ready ? '收银台' : null,
            'sourceSecondary' => $v3Ready ? 'V3 结账' : null,
            'economicsDataStatus' => $v3Ready ? 'ready' : self::ECONOMICS_STATUS,
            'cashPerformanceDataStatus' => $v3Ready ? 'ready' : self::ECONOMICS_STATUS,
            'contractVersion' => self::CONTRACT_VERSION,
            'availableActions' => [],
        ];
        if (!$detail) {
            return $mapped;
        }

        $mapped['orderNote'] = (string)($row['remark'] ?? '');
        $mapped['items'] = $items;
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
            'serviceRecipientType' => $v3Ready ? (string)($authority['service_object'] ?? '') : '',
            'isExperience' => $v3Ready && (int)($authority['is_experience'] ?? 0) === 1,
            'economicsDataStatus' => $v3Ready ? 'ready' : self::ECONOMICS_STATUS,
            'snapshotStatus' => $name === '' ? 'invalid' : 'ready',
            'salespeople' => $salespeople,
            'craftsmen' => $v3Ready ? $this->craftsmenForLine($authority) : [],
        ];
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
            'refunded' => '已退款',
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
            ['value' => 'refunded', 'label' => '已退款'],
            ['value' => 'partially_refunded', 'label' => '部分退款'],
            ['value' => 'cancelled', 'label' => '已取消'],
            ['value' => 'voided', 'label' => '已作废'],
        ];
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
