<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use think\facade\Db;

/**
 * 订单中心非销售类记录的只读投影。
 *
 * 每类记录直接读取其业务权威表，并始终由服务端 DataScope 收紧门店范围。
 * 历史表缺少统一经营事实时只展示业务金额，不把旧字段包装成现金业绩。
 */
final class CashierV3OrderCenterRecordQueryServices
{
    public const CONTRACT_VERSION = 'cashier-v3.order-center.v3';
    public const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    public const MAX_PAGE_SIZE = 100;

    private const TYPES = [
        'recharge',
        'refund',
        'debt',
        'service',
        'supplement',
        'gift',
        'card_operation',
    ];

    private const CARD_OPERATION_TYPES = [
        'card_transfer',
        'card_upgrade',
        'card_disable',
        'card_enable',
        'card_extension',
        'project_replacement',
        'project_upgrade',
    ];

    /** @var callable|null function(string $operation, array $context): array */
    private $reader;

    public function __construct($reader = null)
    {
        if ($reader !== null && !is_callable($reader)) {
            throw new \InvalidArgumentException('order_center_record_reader_invalid');
        }
        $this->reader = $reader;
    }

    public function queryRecords(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $criteria = $this->criteria($payload, $dataScope);
        if (!$dataScope->hasFeature('cashier.v3.order_center')
            || $criteria['type'] === ''
            || $criteria['allowedStoreIds'] === []) {
            return $this->pagePayload($criteria, [], 0, 'permission_filtered');
        }

        if ($this->reader !== null) {
            $result = call_user_func($this->reader, 'query', [
                'criteria' => $criteria,
                'operatorScope' => $operatorScope,
                'dataScope' => $dataScope,
            ]);
            if (!is_array($result)) throw new \RuntimeException('order_center_record_reader_result_invalid');
            $records = is_array($result['records'] ?? null) ? $result['records'] : [];
            $total = max(0, (int)($result['total'] ?? count($records)));
        } else {
            [$records, $total] = $this->readType($criteria, $operatorScope);
        }
        return $this->pagePayload($criteria, $records, $total, 'authoritative_business_records');
    }

    public function counts(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $counts = array_fill_keys(self::TYPES, 0);
        if (!$dataScope->hasFeature('cashier.v3.order_center')) {
            return $counts;
        }
        $allowed = $this->canonicalStoreIds($dataScope->narrowVisibleStores(null));
        if ($allowed === []) {
            return $counts;
        }
        if ($this->reader !== null) {
            $result = call_user_func($this->reader, 'counts', [
                'allowedStoreIds' => $allowed,
                'operatorScope' => $operatorScope,
                'dataScope' => $dataScope,
            ]);
            if (!is_array($result)) throw new \RuntimeException('order_center_record_reader_result_invalid');
            foreach (self::TYPES as $type) {
                $counts[$type] = max(0, (int)($result[$type] ?? 0));
            }
            return $counts;
        }
        foreach (self::TYPES as $type) {
            $criteria = [
                'type' => $type,
                'page' => 1,
                'pageSize' => 1,
                'keyword' => '',
                'status' => '',
                'dataScope' => 'normal',
                'operationType' => '',
                // Counts traverse every record type, including service. Keep the
                // same normalized empty query shape as a service-page request.
                'topFilters' => [],
                'sorts' => [],
                'allowedStoreIds' => $allowed,
                'tenantId' => $dataScope->tenantId(),
            ];
            [, $counts[$type]] = $this->readType($criteria, $operatorScope, true);
        }
        return $counts;
    }

    public function augmentInitialPartition(
        array $partition,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $counts = $this->counts($operatorScope, $dataScope);
        $counts['sales'] = max(0, (int)($partition['total'] ?? 0));
        $partition['contractVersion'] = self::CONTRACT_VERSION;
        $partition['businessTypes'] = [
            ['key' => 'sales', 'label' => '销售订单', 'ready' => true],
            ['key' => 'recharge', 'label' => '充值订单', 'ready' => true],
            ['key' => 'refund', 'label' => '退款记录', 'ready' => true],
            ['key' => 'debt', 'label' => '欠款管理', 'ready' => true],
            ['key' => 'service', 'label' => '服务记录', 'ready' => true],
            ['key' => 'supplement', 'label' => '补交记录', 'ready' => true],
            ['key' => 'gift', 'label' => '赠送记录', 'ready' => true],
            ['key' => 'card_operation', 'label' => '卡操作记录', 'ready' => true],
        ];
        $partition['countsByType'] = $counts;
        $partition['recordsByType'] = array_merge([
            'sales' => [],
            'recharge' => [],
            'refund' => [],
            'debt' => [],
            'service' => [],
            'supplement' => [],
            'gift' => [],
            'card_operation' => [],
        ], is_array($partition['recordsByType'] ?? null) ? $partition['recordsByType'] : []);
        $partition['pagesByType'] = array_merge([
            'recharge' => $this->emptyPageMeta(),
            'refund' => $this->emptyPageMeta(),
            'debt' => $this->emptyPageMeta(),
            'service' => $this->emptyPageMeta(),
            'supplement' => $this->emptyPageMeta(),
            'gift' => $this->emptyPageMeta(),
            'card_operation' => $this->emptyPageMeta(),
        ], is_array($partition['pagesByType'] ?? null) ? $partition['pagesByType'] : []);
        $partition['statusOptionsByType'] = array_merge([
            'recharge' => $this->statusOptions('recharge'),
            'refund' => $this->statusOptions('refund'),
            'debt' => $this->statusOptions('debt'),
            'service' => $this->statusOptions('service'),
            'supplement' => $this->statusOptions('supplement'),
            'gift' => $this->statusOptions('gift'),
            'card_operation' => $this->statusOptions('card_operation'),
        ], is_array($partition['statusOptionsByType'] ?? null) ? $partition['statusOptionsByType'] : []);
        return $partition;
    }

    public function pagePartition(array $page): array
    {
        $type = (string)$page['recordType'];
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'dataStatus' => $page['dataStatus'],
            'dataAsOf' => $page['dataAsOf'],
            'businessTimezone' => self::BUSINESS_TIMEZONE,
            'recordsByType' => [$type => $page['records']],
            'pagesByType' => [$type => [
                'total' => $page['total'],
                'page' => $page['page'],
                'pageSize' => $page['pageSize'],
                'dataStatus' => $page['dataStatus'],
                'paginationMode' => 'offset',
                'hasMore' => $page['page'] * $page['pageSize'] < $page['total'],
            ]],
            'statusOptionsByType' => [$type => $page['statusOptions']],
        ];
    }

    private function criteria(array $payload, CashierV3DataScopeContext $dataScope): array
    {
        $type = $this->scalar($payload['recordType'] ?? $payload['record_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            $type = '';
        }
        $keyword = $this->scalar($payload['keyword'] ?? $payload['search'] ?? '');
        $keyword = function_exists('mb_substr')
            ? mb_substr($keyword, 0, 100, 'UTF-8')
            : substr($keyword, 0, 100);
        $status = $this->scalar($payload['businessStatus'] ?? $payload['status'] ?? '');
        $scopeMode = strtolower($this->scalar($payload['dataScope'] ?? $payload['data_scope'] ?? 'normal'));
        if (!in_array($scopeMode, ['normal', 'all'], true)) {
            $scopeMode = 'normal';
        }
        $validStatuses = array_column($this->statusOptions($type), 'value');
        if (!in_array($status, $validStatuses, true)) {
            $status = '';
        }
        $operationType = '';
        if ($type === 'card_operation') {
            $operationType = $this->cardOperationTypeFromLabel($this->topFilterValue($payload, 'operation_type'));
            if ($operationType === '') {
                $operationType = $this->cardOperationTypeFromLabel($keyword);
                if ($operationType !== '') $keyword = '';
            }
        }
        $requestedStores = $this->requestedStoreIds($payload);
        $businessDateRange = $this->businessDateRange($payload);
        return [
            'type' => $type,
            'page' => max(1, (int)($payload['page'] ?? 1)),
            'pageSize' => min(self::MAX_PAGE_SIZE, max(1, (int)($payload['pageSize'] ?? $payload['limit'] ?? 20))),
            'keyword' => $keyword,
            'status' => $status,
            'dataScope' => $scopeMode,
            'operationType' => $operationType,
            'businessDateFrom' => $businessDateRange['from'],
            'businessDateTo' => $businessDateRange['to'],
            'topFilters' => $type === 'service' ? $this->serviceTopFilters($payload) : [],
            'sorts' => $type === 'service' ? $this->serviceSorts($payload) : [],
            'allowedStoreIds' => $this->canonicalStoreIds($dataScope->narrowVisibleStores($requestedStores)),
            'tenantId' => $dataScope->tenantId(),
        ];
    }

    /** @return array{from:string,to:string} */
    private function businessDateRange(array $payload): array
    {
        $from = $this->validBusinessDate($payload['businessDateFrom'] ?? $payload['business_date_from'] ?? $payload['dateFrom'] ?? $payload['date_from'] ?? '');
        $to = $this->validBusinessDate($payload['businessDateTo'] ?? $payload['business_date_to'] ?? $payload['dateTo'] ?? $payload['date_to'] ?? '');
        foreach ((array)($payload['topFilters'] ?? $payload['top_filters'] ?? []) as $filter) {
            if (!is_array($filter) || $this->scalar($filter['field'] ?? '') !== 'business_date') continue;
            $value = $this->validBusinessDate($filter['value'] ?? '');
            if ($value === '') continue;
            $operator = strtolower($this->scalar($filter['operator'] ?? 'eq'));
            if ($operator === 'gte') $from = $value;
            elseif ($operator === 'lte') $to = $value;
            elseif ($operator === 'eq') {
                $from = $value;
                $to = $value;
            }
        }
        return ['from' => $from, 'to' => $to];
    }

    private function validBusinessDate($value): string
    {
        $date = $this->scalar($value);
        return preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $date) === 1 ? $date : '';
    }

    private function readType(array $criteria, CashierV3OperatorScope $scope, bool $countOnly = false): array
    {
        switch ($criteria['type']) {
            case 'recharge':
                return $this->readRecharge($criteria, $countOnly);
            case 'supplement':
                return $this->readSupplement($criteria, $countOnly);
            case 'refund':
                return $this->readRefund($criteria, $countOnly);
            case 'debt':
                return $this->readDebts($criteria, $countOnly);
            case 'service':
                return $this->readServices($criteria, $scope, $countOnly);
            case 'gift':
                return $this->readGift($criteria, $countOnly);
            case 'card_operation':
                return $this->readCardOperations($criteria, $scope, $countOnly);
            default:
                return [[], 0];
        }
    }

    private function readRecharge(array $criteria, bool $countOnly): array
    {
        $tenantId = (string)($criteria['tenantId'] ?? '');
        $query = Db::name('user_recharge')->alias('r')
            ->leftJoin('user u', 'u.uid = r.uid')
            ->leftJoin('system_store s', 's.id = r.store_id')
            ->leftJoin('system_store_staff st', 'st.id = r.staff_id');
        $this->applyStoreScope($query, 'r.store_id', $criteria['allowedStoreIds']);
        $this->applyTimestampBusinessDateRange($query, 'COALESCE(NULLIF(r.pay_time, 0), r.add_time)', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'r.order_id', 'u.real_name', 'u.nickname', 'u.phone', 's.name', 'st.staff_name',
        ]);
        if ($criteria['status'] === 'paid') {
            $query->where('r.paid', 1)->where('r.terminal_action', 0)->where('r.refund_price', '<=', 0)
                ->whereNotExists($this->rechargeLifecycleOperationQuery($tenantId, ['refund', 'void']));
        } elseif ($criteria['status'] === 'voided') {
            $query->where(function ($statusQuery) use ($tenantId): void {
                $statusQuery->where('r.terminal_action', '>', 0)
                    ->whereExists($this->rechargeLifecycleOperationQuery($tenantId, ['void']), 'OR');
            });
        } elseif ($criteria['status'] === 'refunded') {
            $query->where('r.terminal_action', 0)
                ->where(function ($statusQuery) use ($tenantId): void {
                    $statusQuery->where('r.refund_price', '>', 0)
                        ->whereExists($this->rechargeLifecycleOperationQuery($tenantId, ['refund']), 'OR');
                })
                ->whereNotExists($this->rechargeLifecycleOperationQuery($tenantId, ['void']));
        }
        $total = (int)(clone $query)->count('r.id');
        if ($countOnly) return [[], $total];
        $rows = $this->pageRows($query, $criteria, 'r.pay_time', 'r.id', implode(',', [
            'r.id', 'r.order_id', 'r.uid', 'r.store_id', 'r.staff_id', 'r.price', 'r.give_price',
            'r.refund_price', 'r.recharge_type', 'r.combination_info', 'r.channel_type', 'r.paid', 'r.terminal_action',
            'r.pay_time', 'r.add_time', 'u.real_name', 'u.nickname', 'u.phone',
            's.name AS store_name', 'st.staff_name',
        ]));
        $rechargeEconomics = $this->readRechargeEconomics($rows);
        $rechargeSalespeople = $this->readRechargeSalespeople($rows, $tenantId);
        $rechargeBusinessDates = $this->readRechargeBusinessDates($rows);
        $rechargeLifecycleOperations = $this->readRechargeLifecycleOperations($rows, $tenantId);
        return [array_map(function (array $row) use ($rechargeEconomics, $rechargeSalespeople, $rechargeBusinessDates, $rechargeLifecycleOperations): array {
            $time = (int)($row['pay_time'] ?: $row['add_time']);
            $economicsKey = $this->rechargeFactKey((int)$row['store_id'], (string)$row['order_id']);
            $hasV3PaymentFacts = array_key_exists($economicsKey, $rechargeEconomics);
            $lifecycleOperation = (string)($rechargeLifecycleOperations[(int)$row['id']] ?? '');
            return [
                'id' => 'recharge:' . $row['id'],
                'rechargeId' => (int)$row['id'],
                'memberId' => (int)$row['uid'],
                'rechargeOrderNo' => (string)$row['order_id'],
                'businessDate' => $rechargeBusinessDates[$economicsKey] ?? $this->date($time),
                'memberName' => $this->memberName($row),
                'phone' => (string)$row['phone'],
                'storeName' => (string)$row['store_name'],
                'rechargePlan' => '会员储值',
                'rechargeAmount' => (string)$row['price'],
                'giftAmount' => (string)$row['give_price'],
                // Only V3 payment facts are the authoritative cash-performance
                // source. A zero net amount remains ready when reversals offset
                // a real V3 collection; legacy recharge columns never fill it.
                'actualReceivedAmount' => $hasV3PaymentFacts
                    ? $this->centsToMoney((int)$rechargeEconomics[$economicsKey]) : null,
                'economicsDataStatus' => $hasV3PaymentFacts ? 'ready' : 'not_ready',
                'paymentMethod' => $this->rechargePaymentLabel($row),
                // user_recharge.staff_id is the operator, not the salesperson.
                // V3 salespeople are read from immutable performance facts;
                // legacy rows without that fact intentionally remain blank.
                'salespersonName' => (string)($rechargeSalespeople[(int)$row['id']] ?? ''),
                'operatorName' => (string)$row['staff_name'],
                'paymentStatus' => (int)$row['paid'] === 1 ? '已支付' : '未支付',
                'orderStatus' => $lifecycleOperation === 'void'
                    ? '已作废'
                    : ($lifecycleOperation === 'refund'
                        ? '已退款作废'
                        : ((int)$row['terminal_action'] > 0
                            ? '已作废'
                            : ((float)$row['refund_price'] > 0 ? '已退款' : '正常'))),
                'paymentCompletedAt' => $this->dateTime($time),
            ];
        }, $rows), $total];
    }

    private function rechargeLifecycleOperationQuery(string $tenantId, array $operationTypes): callable
    {
        return static function ($operation) use ($tenantId, $operationTypes): void {
            $operation->name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->alias('rlo')
                ->whereRaw('rlo.source_order_id = CAST(r.id AS CHAR)')
                ->where('rlo.tenant_id', $tenantId)
                ->where('rlo.source_type', 'recharge')
                ->where('rlo.status', 'succeeded')
                ->whereIn('rlo.operation_type', $operationTypes);
        };
    }

    /** @return array<int,string> keyed by recharge ID */
    private function readRechargeLifecycleOperations(array $recharges, string $tenantId): array
    {
        $rechargeIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int)($row['id'] ?? 0);
        }, $recharges))));
        if ($rechargeIds === []) return [];
        $rows = Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'recharge')
            ->where('status', 'succeeded')
            ->whereIn('operation_type', ['refund', 'void'])
            ->whereIn('source_order_id', array_map('strval', $rechargeIds))
            ->field('source_order_id,operation_type')
            ->select()
            ->toArray();
        $operations = [];
        foreach ($rows as $row) {
            $rechargeId = (int)($row['source_order_id'] ?? 0);
            $operationType = (string)($row['operation_type'] ?? '');
            if ($rechargeId <= 0 || !in_array($operationType, ['refund', 'void'], true)) continue;
            if ($operationType === 'void' || !isset($operations[$rechargeId])) {
                $operations[$rechargeId] = $operationType;
            }
        }
        return $operations;
    }

    /**
     * Reads the V3 cash-performance authority for the current recharge page.
     * Payment reversals have signed amount_cents, so the effective-fact sum is
     * the only valid net amount and must not filter to forward facts only.
     *
     * @return array<string,int> keyed by store ID and recharge order number
     */
    private function readRechargeEconomics(array $recharges): array
    {
        $storeIds = [];
        $orderNos = [];
        foreach ($recharges as $recharge) {
            $storeId = (int)($recharge['store_id'] ?? 0);
            $orderNo = trim((string)($recharge['order_id'] ?? ''));
            if ($storeId <= 0 || $orderNo === '') continue;
            $storeIds[$storeId] = $storeId;
            $orderNos[$orderNo] = $orderNo;
        }
        if ($storeIds === [] || $orderNos === []) return [];

        $facts = Db::name('cashier_v3_payment_fact')->alias('pf')
            ->where('pf.source_document_type', 'recharge')
            // 业务日期属于原充值单快照；作废反向事实按作废日入账，不能覆盖订单的原业务日期。
            ->where('pf.fact_direction', 'forward')
            ->where('pf.status', 'effective')
            ->whereIn('pf.store_id', array_values($storeIds))
            ->whereIn('pf.order_no_snapshot', array_values($orderNos))
            ->field('pf.store_id,pf.order_no_snapshot,SUM(pf.amount_cents) AS actual_received_cents')
            ->group('pf.store_id,pf.order_no_snapshot')
            ->select()
            ->toArray();

        $economics = [];
        foreach ($facts as $fact) {
            $storeId = (int)($fact['store_id'] ?? 0);
            $orderNo = trim((string)($fact['order_no_snapshot'] ?? ''));
            if ($storeId <= 0 || $orderNo === '') continue;
            $economics[$this->rechargeFactKey($storeId, $orderNo)] = (int)($fact['actual_received_cents'] ?? 0);
        }
        return $economics;
    }

    /** @return array<int,string> keyed by recharge ID */
    private function readRechargeSalespeople(array $recharges, string $tenantId): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int)($row['id'] ?? 0);
        }, $recharges))));
        if ($ids === []) return [];
        $rows = Db::name('cashier_v3_performance_fact')
            ->where('tenant_id', $tenantId)
            ->where('source_document_type', 'recharge')->where('performance_type', 'sales_performance_allocated')
            ->where('fact_direction', 'forward')->where('status', 'effective')
            ->whereIn('order_id', array_map(static function (int $id): string { return 'RCH:' . $id; }, $ids))
            ->field('fact_id,order_id,employee_name_snapshot')->order('id', 'asc')->select()->toArray();
        $names = [];
        foreach ($rows as $row) {
            // 订单详情展示的是原单快照，不是当期可计提业绩。作废会生成
            // 反向业绩事实抵销统计，但不能抹掉原充值单的销售人审计痕迹。
            $orderId = (string)($row['order_id'] ?? '');
            $name = trim((string)($row['employee_name_snapshot'] ?? ''));
            if (!preg_match('/^RCH:([1-9][0-9]*)$/D', $orderId, $m) || $name === '') continue;
            $id = (int)$m[1];
            $names[$id] = $names[$id] ?? [];
            if (!in_array($name, $names[$id], true)) $names[$id][] = $name;
        }
        $out = [];
        foreach ($names as $id => $people) $out[$id] = implode('、', $people);
        return $out;
    }

    /**
     * The selected recharge date is recorded in the immutable V3 fact context.
     * The legacy recharge row keeps payment time for compatibility, so order
     * center must prefer the fact date when one exists.
     *
     * @return array<string,string> keyed by store ID and recharge order number
     */
    private function readRechargeBusinessDates(array $recharges): array
    {
        $storeIds = [];
        $orderNos = [];
        foreach ($recharges as $recharge) {
            $storeId = (int)($recharge['store_id'] ?? 0);
            $orderNo = trim((string)($recharge['order_id'] ?? ''));
            if ($storeId <= 0 || $orderNo === '') continue;
            $storeIds[$storeId] = $storeId;
            $orderNos[$orderNo] = $orderNo;
        }
        if ($storeIds === [] || $orderNos === []) return [];
        $facts = Db::name('cashier_v3_payment_fact')->alias('pf')
            ->where('pf.source_document_type', 'recharge')
            // 原充值业务日期取正向事实；作废反向事实按作废日入账，不能覆盖原单日期快照。
            ->where('pf.fact_direction', 'forward')
            ->where('pf.status', 'effective')
            ->whereIn('pf.store_id', array_values($storeIds))
            ->whereIn('pf.order_no_snapshot', array_values($orderNos))
            ->field('pf.store_id,pf.order_no_snapshot,MAX(pf.business_date) AS business_date')
            ->group('pf.store_id,pf.order_no_snapshot')
            ->select()
            ->toArray();
        $dates = [];
        foreach ($facts as $fact) {
            $storeId = (int)($fact['store_id'] ?? 0);
            $orderNo = trim((string)($fact['order_no_snapshot'] ?? ''));
            $businessDate = trim((string)($fact['business_date'] ?? ''));
            if ($storeId > 0 && $orderNo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) === 1) {
                $dates[$this->rechargeFactKey($storeId, $orderNo)] = $businessDate;
            }
        }
        return $dates;
    }

    private function rechargeFactKey(int $storeId, string $orderNo): string
    {
        return $storeId . "\0" . trim($orderNo);
    }

    private function readSupplement(array $criteria, bool $countOnly): array
    {
        $sources = [
            $this->readV3SalesDebtRepayments($criteria, $countOnly),
            $this->readV3RechargeDebtRepayments($criteria, $countOnly),
            $this->readLegacySupplements($criteria, $countOnly),
        ];
        $total = array_sum(array_column($sources, 1));
        if ($countOnly) return [[], $total];
        $records = [];
        foreach ($sources as $source) {
            $records = array_merge($records, $source[0]);
        }
        usort($records, function (array $left, array $right): int {
            $time = (int)$right['_sortTime'] <=> (int)$left['_sortTime'];
            return $time !== 0 ? $time : strcmp((string)$right['id'], (string)$left['id']);
        });
        $offset = ($criteria['page'] - 1) * $criteria['pageSize'];
        $records = array_slice($records, $offset, $criteria['pageSize']);
        foreach ($records as &$record) unset($record['_sortTime']);
        unset($record);
        return [$records, $total];
    }

    /**
     * V3 销售欠款补交的权威来源。销售补交独立落账，不写入旧 store_debt_repay。
     */
    private function readV3SalesDebtRepayments(array $criteria, bool $countOnly): array
    {
        $query = Db::name('cashier_v3_debt_repayment')->alias('r')
            ->leftJoin('user u', 'u.uid = r.member_id')
            ->leftJoin('system_store s', 's.id = r.store_id')
            ->leftJoin('system_store_staff st', 'st.id = r.operator_id')
            ->where(function ($q) use ($criteria) {
                if ($criteria['status'] === 'voided') $q->where('r.status', 'voided');
                elseif ($criteria['dataScope'] === 'all' && $criteria['status'] === '') $q->whereIn('r.status', ['succeeded', 'voided']);
                else $q->where('r.status', 'succeeded');
            });
        $this->applyStoreScope($query, 'r.store_id', $criteria['allowedStoreIds']);
        $this->applyBusinessDateRange($query, 'r.business_date', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'r.repayment_no', 'r.debt_no', 'r.sales_order_no_snapshot',
            'u.real_name', 'u.nickname', 'u.phone',
        ]);
        $total = (int)(clone $query)->count('r.id');
        if ($countOnly) return [[], $total];

        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'r.id', 'r.repayment_id', 'r.repayment_no', 'r.debt_no',
            'r.sales_order_no_snapshot', 'r.repayment_amount_cents', 'r.status',
            'r.store_id', 'r.member_id', 'r.operator_id', 'r.business_date', 'r.settled_at',
            'u.real_name', 'u.nickname', 'u.phone', 's.name AS store_name',
            'st.staff_name',
        ]))->order('r.settled_at', 'desc')->order('r.id', 'desc')->limit($limit)->select()->toArray();
        if ($rows === []) return [[], $total];

        $salespeople = $this->supplementSalespeople(array_column($rows, 'repayment_id'), 'debt_repayment', $criteria['tenantId']);

        $repaymentIds = array_values(array_unique(array_filter(array_column($rows, 'repayment_id'))));
        $paymentMethods = [];
        if ($repaymentIds !== []) {
            $payments = Db::name('cashier_v3_debt_repayment_collection')
                ->whereIn('repayment_id', $repaymentIds)
                ->field('repayment_id,payment_line_no,payment_method')
                ->order('repayment_id', 'asc')->order('payment_line_no', 'asc')->select()->toArray();
            foreach ($payments as $payment) {
                $repaymentId = (string)($payment['repayment_id'] ?? '');
                if ($repaymentId !== '') $paymentMethods[$repaymentId][] = $this->paymentLabel((string)($payment['payment_method'] ?? ''));
            }
        }

        return [array_map(function (array $row) use ($paymentMethods, $salespeople): array {
            $repaymentId = (string)$row['repayment_id'];
            return [
                'id' => 'v3-sales-supplement:' . $repaymentId,
                'supplementOrderNo' => (string)$row['repayment_no'],
                'businessDate' => (string)$row['business_date'],
                'debtNo' => (string)$row['debt_no'],
                'sourceOrderNo' => (string)$row['sales_order_no_snapshot'],
                'memberId' => (int)$row['member_id'],
                'memberName' => $this->memberName($row),
                'phone' => (string)$row['phone'],
                'debtSummary' => '销售欠款补交',
                'supplementAmount' => $this->centsToMoney((int)$row['repayment_amount_cents']),
                'paymentMethod' => implode('、', array_values(array_unique($paymentMethods[$repaymentId] ?? []))) ?: '未标注',
                'salespersonName' => (string)($salespeople[$repaymentId] ?? ''),
                'storeName' => (string)$row['store_name'],
                'operatorName' => (string)$row['staff_name'],
                'paymentStatus' => (string)($row['status'] ?? '') === 'voided' ? '已作废' : '补交成功',
                'paymentCompletedAt' => $this->dateTime((int)$row['settled_at']),
                '_sortTime' => (int)$row['settled_at'],
            ];
        }, $rows), $total];
    }

    /**
     * V3 充值欠款补交的权威来源。不得为订单中心展示而回写旧 store_debt_repay。
     */
    private function readV3RechargeDebtRepayments(array $criteria, bool $countOnly): array
    {
        $query = Db::name('cashier_v3_recharge_debt_repayment')->alias('r')
            ->leftJoin('store_debt d', 'd.id = r.debt_id')
            ->leftJoin('user u', 'u.uid = r.member_id')
            ->leftJoin('system_store s', 's.id = r.store_id')
            ->leftJoin('system_store_staff st', 'st.id = r.operator_id')
            ->where(function ($q) use ($criteria) {
                if ($criteria['status'] === 'voided') $q->where('r.status', 'voided');
                elseif ($criteria['dataScope'] === 'all' && $criteria['status'] === '') $q->whereIn('r.status', ['succeeded', 'voided']);
                else $q->where('r.status', 'succeeded');
            });
        $this->applyStoreScope($query, 'r.store_id', $criteria['allowedStoreIds']);
        $this->applyBusinessDateRange($query, 'r.business_date', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'r.repayment_no', 'd.debt_no', 'd.order_sn', 'u.real_name', 'u.nickname', 'u.phone',
        ]);
        $total = (int)(clone $query)->count('r.id');
        if ($countOnly) return [[], $total];
        // Read enough records from each authority for the requested global page, then merge by settled time.
        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'r.id', 'r.repayment_id', 'r.repayment_no', 'r.debt_id', 'r.amount_cents',
            'r.store_id', 'r.member_id', 'r.operator_id', 'r.business_date', 'r.settled_at', 'd.debt_no',
            'd.order_sn', 'r.status', 'u.real_name', 'u.nickname', 'u.phone', 's.name AS store_name',
            'st.staff_name',
        ]))->order('r.settled_at', 'desc')->order('r.id', 'desc')->limit($limit)->select()->toArray();
        if ($rows === []) return [[], $total];

        $salespeople = $this->supplementSalespeople(array_column($rows, 'repayment_id'), 'recharge_debt_repayment', $criteria['tenantId']);

        $repaymentIds = array_values(array_unique(array_filter(array_column($rows, 'repayment_id'))));
        $paymentMethods = [];
        if ($repaymentIds !== []) {
            $payments = Db::name('cashier_v3_recharge_debt_repayment_payment')
                ->whereIn('repayment_id', $repaymentIds)
                ->field('repayment_id,payment_line_no,payment_method')
                ->order('repayment_id', 'asc')->order('payment_line_no', 'asc')->select()->toArray();
            foreach ($payments as $payment) {
                $repaymentId = (string)($payment['repayment_id'] ?? '');
                if ($repaymentId !== '') $paymentMethods[$repaymentId][] = $this->paymentLabel((string)($payment['payment_method'] ?? ''));
            }
        }
        return [array_map(function (array $row) use ($paymentMethods, $salespeople): array {
            $repaymentId = (string)$row['repayment_id'];
            return [
                'id' => 'v3-supplement:' . $repaymentId,
                'supplementOrderNo' => (string)$row['repayment_no'],
                'businessDate' => (string)$row['business_date'],
                'debtNo' => (string)$row['debt_no'],
                'sourceOrderNo' => (string)$row['order_sn'],
                'memberId' => (int)$row['member_id'],
                'memberName' => $this->memberName($row),
                'phone' => (string)$row['phone'],
                'debtSummary' => '充值欠款补交',
                'supplementAmount' => $this->centsToMoney((int)$row['amount_cents']),
                'paymentMethod' => implode('、', array_values(array_unique($paymentMethods[$repaymentId] ?? []))) ?: '未标注',
                'salespersonName' => (string)($salespeople[$repaymentId] ?? ''),
                'storeName' => (string)$row['store_name'],
                'operatorName' => (string)$row['staff_name'],
                'paymentStatus' => (string)($row['status'] ?? '') === 'voided' ? '已作废' : '补交成功',
                'paymentCompletedAt' => $this->dateTime((int)$row['settled_at']),
                '_sortTime' => (int)$row['settled_at'],
            ];
        }, $rows), $total];
    }

    private function readLegacySupplements(array $criteria, bool $countOnly): array
    {
        $query = Db::name('store_debt_repay')->alias('r')
            ->leftJoin('cashier_v3_business_document_no bd', "bd.source_type = 'legacy_store_debt_repayment' AND bd.source_id = CAST(r.id AS CHAR) AND bd.document_type = 'debt_repayment' AND bd.tenant_id = '0'")
            ->leftJoin('store_debt d', 'd.id = r.debt_id')
            ->leftJoin('user u', 'u.uid = r.uid')
            ->leftJoin('system_store s', 's.id = r.pay_store_id')
            ->leftJoin('system_store_staff st', 'st.id = r.staff_id');
        $this->applyStoreScope($query, 'r.pay_store_id', $criteria['allowedStoreIds']);
        $this->applyTimestampBusinessDateRange($query, 'r.add_time', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'r.repay_no', 'bd.document_no', 'd.debt_no', 'r.order_sn', 'u.real_name', 'u.nickname', 'u.phone',
        ]);
        $total = (int)(clone $query)->count('r.id');
        if ($countOnly) return [[], $total];
        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'r.id', 'COALESCE(bd.document_no, r.repay_no) AS repay_no', 'r.order_sn', 'r.repay_amount', 'r.pay_type',
            'r.pay_store_id', 'r.uid', 'r.staff_id', 'r.add_time', 'd.debt_no',
            'u.real_name', 'u.nickname', 'u.phone', 's.name AS store_name', 'st.staff_name',
        ]))->order('r.add_time', 'desc')->order('r.id', 'desc')->limit($limit)->select()->toArray();
        return [array_map(function (array $row): array {
            return [
                'id' => 'legacy-supplement:' . $row['id'],
                'supplementOrderNo' => (string)$row['repay_no'],
                'businessDate' => $this->date((int)$row['add_time']),
                'debtNo' => (string)$row['debt_no'],
                'sourceOrderNo' => (string)$row['order_sn'],
                'memberId' => (int)$row['uid'],
                'memberName' => $this->memberName($row),
                'phone' => (string)$row['phone'],
                'debtSummary' => '欠款补交',
                'supplementAmount' => (string)$row['repay_amount'],
                'paymentMethod' => $this->paymentLabel((string)$row['pay_type']),
                'storeName' => (string)$row['store_name'],
                'operatorName' => (string)$row['staff_name'],
                'paymentStatus' => '补交成功',
                'paymentCompletedAt' => $this->dateTime((int)$row['add_time']),
                '_sortTime' => (int)$row['add_time'],
            ];
        }, $rows), $total];
    }

    /** @return array<string,string> keyed by repayment id */
    private function supplementSalespeople(array $repaymentIds, string $sourceDocumentType, string $tenantId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $repaymentIds))));
        if ($ids === []) return [];
        $rows = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)
            ->where('source_document_type', $sourceDocumentType)->whereIn('order_id', $ids)
            ->where('performance_type', 'sales_performance_allocated')->where('fact_direction', 'forward')->where('status', 'effective')
            ->field('fact_id,order_id,employee_id,employee_name_snapshot,allocation_weight_numerator')->order('id asc')->select()->toArray();
        $factIds = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['fact_id'] ?? ''), $rows)));
        $reversed = $factIds === [] ? [] : Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)->where('fact_direction', 'reversal')->whereIn('reversal_of', $factIds)->column('reversal_of');
        $reversed = array_fill_keys(array_map('strval', $reversed), true);
        $names = [];
        foreach ($rows as $row) {
            if (isset($reversed[(string)($row['fact_id'] ?? '')])) continue;
            $id = trim((string)($row['order_id'] ?? '')); $name = trim((string)($row['employee_name_snapshot'] ?? ''));
            if ($id === '' || $name === '') continue;
            $weight = (int)($row['allocation_weight_numerator'] ?? 0);
            $names[$id][] = $weight > 0 && $weight < 100 ? $name . ' (' . $weight . '%)' : $name;
        }
        $out = [];
        foreach ($names as $id => $values) $out[$id] = implode('、', array_values(array_unique($values)));
        return $out;
    }

    /**
     * 欠款管理只读取欠款主表及 V3 authority/repaid facts。销售订单只作为
     * authority 记录的来源快照，绝不用于临时汇总欠款余额。
     */
    private function readDebts(array $criteria, bool $countOnly): array
    {
        $query = Db::name('store_debt')->alias('d')
            ->leftJoin('user u', 'u.uid = d.uid')
            ->leftJoin('system_store s', 's.id = d.store_id')
            ->leftJoin('cashier_v3_debt_authority a', 'a.debt_id = d.id')
            ->leftJoin('cashier_v3_recharge_debt_authority ra', 'ra.debt_id = d.id');
        $this->applyStoreScope($query, 'd.store_id', $criteria['allowedStoreIds']);
        $this->applyTimestampBusinessDateRange($query, 'd.add_time', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'd.debt_no', 'd.order_sn', 'a.sales_order_no_snapshot',
            'ra.recharge_order_no_snapshot', 'u.real_name', 'u.nickname', 'u.phone',
        ]);
        if ($criteria['status'] === 'outstanding') {
            $query->where('d.status', 0);
        } elseif ($criteria['status'] === 'settled') {
            $query->where('d.status', 1);
        }
        // New V3 debts have either sales or recharge authority. Keep legacy
        // store_debt readable as well, but label it rather than pretending it
        // carries V3 source precision.
        $total = (int)(clone $query)->count('d.id');
        if ($countOnly) return [[], $total];
        $rows = $this->pageRows($query, $criteria, 'd.add_time', 'd.id', implode(',', [
            'd.id,d.debt_no,d.order_id,d.order_sn,d.uid,d.store_id,d.total_debt,d.repaid_debt,d.status,d.remark,d.add_time,d.update_time',
            'u.real_name,u.nickname,u.phone,s.name AS store_name',
            'a.sales_order_id,a.sales_order_no_snapshot,a.checkout_request_id',
            'ra.recharge_id,ra.recharge_order_no_snapshot',
        ]));
        return [array_map(function (array $row): array {
            $totalDebt = (string)($row['total_debt'] ?? '0.00');
            $repaidDebt = (string)($row['repaid_debt'] ?? '0.00');
            $remaining = bcsub($totalDebt, $repaidDebt, 2);
            $salesOrderNo = trim((string)($row['sales_order_no_snapshot'] ?? ''));
            $rechargeOrderNo = trim((string)($row['recharge_order_no_snapshot'] ?? ''));
            $sourceOrderNo = $salesOrderNo !== '' ? $salesOrderNo
                : ($rechargeOrderNo !== '' ? $rechargeOrderNo : (string)($row['order_sn'] ?? ''));
            $sourceType = $salesOrderNo !== '' ? '销售订单'
                : ($rechargeOrderNo !== '' ? '充值订单' : '历史欠款');
            return [
                'id' => 'debt:' . (int)$row['id'],
                'debtId' => (int)$row['id'],
                'debtNo' => (string)$row['debt_no'],
                'memberId' => (int)$row['uid'],
                'memberName' => $this->memberName($row),
                'phone' => (string)($row['phone'] ?? ''),
                'sourceOrderNo' => $sourceOrderNo,
                'sourceType' => $sourceType,
                'originalDebtAmount' => $totalDebt,
                'repaidAmount' => $repaidDebt,
                'remainingAmount' => $remaining,
                'debtStatus' => (int)$row['status'] === 1 ? '已结清' : '待补交',
                'storeName' => (string)($row['store_name'] ?? ''),
                // 欠款主表没有独立的业务日字段；其入账时间就是该条欠款
                // 事实的发生时间，筛选与列表展示必须使用同一来源。
                'businessDate' => $this->date((int)($row['add_time'] ?? 0)),
                'createdAt' => $this->dateTime((int)($row['add_time'] ?? 0)),
                'updatedAt' => $this->dateTime((int)($row['update_time'] ?? 0)),
                'remark' => (string)($row['remark'] ?? ''),
                'canRepay' => (int)$row['status'] === 0 && bccomp($remaining, '0.00', 2) > 0,
            ];
        }, $rows), $total];
    }

    private function readRefund(array $criteria, bool $countOnly): array
    {
        // Refund records are append-only V3 lifecycle facts.  The retired
        // store_order_refund table is intentionally not a source or a
        // compatibility projection for this workbench.
        $query = Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->alias('rlo')
            ->leftJoin('user u', 'u.uid = rlo.member_id')
            ->leftJoin('system_store s', 's.id = rlo.store_id')
            ->leftJoin('system_store_staff st', 'st.id = rlo.operator_id')
            ->where('rlo.tenant_id', (string)$criteria['tenantId'])
            ->where('rlo.operation_type', 'refund')
            ->where('rlo.status', 'succeeded');
        $this->applyStoreScope($query, 'rlo.store_id', $criteria['allowedStoreIds']);
        $this->applyBusinessDateRange($query, 'rlo.business_date', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'rlo.operation_no', 'rlo.source_order_no_snapshot', 'u.real_name', 'u.nickname', 'u.phone',
            'rlo.reason_snapshot',
        ]);
        $total = (int)(clone $query)->count('rlo.id');
        if ($countOnly) return [[], $total];
        $rows = $this->pageRows($query, $criteria, 'rlo.settled_at', 'rlo.id', implode(',', [
            'rlo.id', 'rlo.operation_id', 'rlo.operation_no', 'rlo.source_type', 'rlo.source_order_no_snapshot',
            'rlo.member_id', 'rlo.store_id', 'rlo.reason_snapshot', 'rlo.cash_refund_cents',
            'rlo.status', 'rlo.business_date', 'rlo.settled_at', 'u.real_name', 'u.nickname', 'u.phone',
            's.name AS store_name', 'st.staff_name',
        ]));
        return [array_map(function (array $row): array {
            return [
                'id' => 'refund:' . (string)$row['operation_id'],
                'refundOrderNo' => (string)$row['operation_no'],
                'businessDate' => (string)$row['business_date'],
                'sourceOrderNo' => (string)$row['source_order_no_snapshot'],
                'memberId' => (int)$row['member_id'],
                'memberName' => $this->memberName($row),
                'phone' => (string)$row['phone'],
                'refundSummary' => (string)$row['reason_snapshot'],
                'refundAmount' => $this->centsToMoney((int)$row['cash_refund_cents']),
                'refundMethod' => '原记账方式退回',
                'storeName' => (string)$row['store_name'],
                'operatorName' => (string)$row['staff_name'],
                'refundStatus' => '已退款',
                'refundCompletedAt' => $this->dateTime((int)$row['settled_at']),
                'refundSourceType' => (string)$row['source_type'],
            ];
        }, $rows), $total];
    }

    /**
     * 服务记录的最小粒度是一条已完成服务项目事实，而非销售订单或服务单表头。
     * 核销快照和劳动业绩均由同一完成事务写入，只用于补充展示，不能反向决定服务是否存在。
     */
    private function readServices(
        array $criteria,
        CashierV3OperatorScope $scope,
        bool $countOnly
    ): array {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->leftJoin(
                'cashier_v3_entitlement_writeoff_fact wf',
                'wf.tenant_id = sf.tenant_id AND wf.checkout_request_id = sf.checkout_request_id AND wf.source_line_id = sf.source_line_id'
            )
            ->leftJoin(
                'cashier_v3_service_record_void_operation vo',
                'vo.tenant_id = sf.tenant_id AND vo.service_fact_id = sf.id AND vo.status = \'succeeded\''
            )
            ->where('sf.tenant_id', $scope->tenantId())
            ->where('sf.service_status', 'completed');
        $this->applyStoreScope($query, 'sf.store_id', $criteria['allowedStoreIds']);
        $this->applyBusinessDateRange($query, 'sf.business_date', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'sf.service_record_no', 'sf.member_name_snapshot', 'sf.project_name_snapshot',
            'sf.store_name_snapshot', 'sf.operator_name_snapshot', 'sf.craftsmen_snapshot_json',
            'wf.source_name_snapshot', 'wf.source_code_snapshot',
        ]);
        $this->applyServiceTopFilters($query, $criteria['topFilters']);
        // “正常数据” is the default view and must contain completed service
        // facts that have not been voided. “全部数据” is the explicit audit
        // view where the status selector can narrow to completed/voided.
        if (($criteria['dataScope'] ?? 'normal') !== 'all' && $criteria['status'] === '') {
            $query->whereNull('vo.id');
        }
        if ($criteria['status'] !== '' && !in_array($criteria['status'], ['completed', 'voided', '已完成', '已作废'], true)) {
            return [[], 0];
        }
        $total = (int)(clone $query)->count('sf.id');
        if ($countOnly) return [[], $total];

        $rows = $this->pageServiceRows($query, $criteria, implode(',', [
            'sf.id', 'sf.service_fact_id', 'sf.service_record_no', 'sf.tenant_id', 'sf.checkout_request_id', 'sf.source_line_id',
            'sf.business_date', 'sf.member_id', 'sf.member_name_snapshot', 'sf.project_id',
            'sf.project_name_snapshot', 'sf.quantity', 'sf.project_count', 'sf.store_id', 'sf.store_name_snapshot',
            'sf.operator_id', 'sf.operator_name_snapshot', 'sf.settled_at', 'sf.occurred_at',
            'sf.craftsmen_snapshot_json', 'sf.service_status', 'sf.source_document_type', 'wf.is_gift', 'wf.source_kind',
            'sf.detail_remark_snapshot',
            'wf.source_name_snapshot AS source_name_snapshot',
            'wf.source_code_snapshot AS source_code_snapshot',
            'sf.labor_amount_cents', 'sf.labor_fee_amount_cents', 'sf.labor_mode',
            'vo.id AS void_operation_id', 'vo.occurred_at AS voided_at', 'vo.reason_snapshot AS void_reason',
            'vo.operator_name_snapshot AS void_operator_name', 'vo.operation_no AS void_operation_no',
        ]));
        $laborByLine = $this->laborPerformanceByServiceLine($rows, $scope->tenantId());

        return [array_map(function (array $row) use ($laborByLine): array {
            $key = $this->serviceLineKey($row);
            $labor = $laborByLine[$key] ?? [
                'amountCents' => 0, 'laborFeeCents' => 0, 'projectCountHalfUnits' => 0,
                'hasExplicitProjectCount' => false, 'names' => [],
            ];
            // 调整后优先展示当前有效员工事实；原始 craftsmen 快照只用于
            // 尚未形成可读业绩分配的历史服务记录。
            $craftsmen = implode('、', $labor['names']);
            if ($craftsmen === '') $craftsmen = $this->craftsmenSummary((string)$row['craftsmen_snapshot_json']);
            return [
                'id' => 'service:' . $row['id'],
                'serviceFactId' => (int)$row['id'],
                // The member-detail writeoff tab displays the immutable visible
                // service document number. ESF remains only an internal fallback
                // for historical rows created before that number was allocated.
                'serviceRecordNo' => (string)($row['service_record_no'] ?: $row['service_fact_id']),
                'businessDate' => (string)$row['business_date'],
                'memberId' => (int)$row['member_id'],
                'memberName' => (string)$row['member_name_snapshot'],
                'serviceProject' => (string)$row['project_name_snapshot'],
                'detailRemark' => (string)($row['detail_remark_snapshot'] ?? ''),
                'entitlementSource' => $this->serviceEntitlementSource($row),
                'sourceCardName' => (string)$row['source_name_snapshot'],
                'sourceCardNo' => (string)$row['source_code_snapshot'],
                'usedTimes' => (int)$row['quantity'],
                'storeName' => (string)$row['store_name_snapshot'],
                'craftsmenSummary' => $craftsmen,
                // A void is an adjustment fact. Keep the original service
                // snapshot visible in the detail/list and expose the void
                // audit fields separately; do not overwrite historical facts
                // with the reversal amount.
                'laborPerformanceAmount' => $this->centsToMoney((int)$labor['amountCents']),
                'laborFeeAmount' => $this->centsToMoney(
                    (int)($labor['laborFeeCents'] ?? 0) > 0
                        ? (int)$labor['laborFeeCents']
                        : (int)($row['labor_fee_amount_cents'] ?? $row['labor_amount_cents'] ?? 0)
                ),
                'laborPerformanceType' => (string)($row['labor_mode'] ?? 'project_rule'),
                'laborPerformanceTypeLabel' => $this->laborPerformanceTypeLabel((string)($row['labor_mode'] ?? 'project_rule')),
                'laborPerformanceRatio' => $this->laborPerformanceRatio($labor['allocations'] ?? []),
                'laborPerformanceAllocations' => ($labor['allocations'] ?? []),
                'projectCount' => number_format(
                    !empty($labor['hasExplicitProjectCount'])
                        ? (int)$labor['projectCountHalfUnits'] / 2
                        : (int)($row['project_count'] ?: $row['quantity']),
                    1, '.', ''
                ),
                'operatorName' => (string)$row['operator_name_snapshot'],
                'serviceStatus' => !empty($row['void_operation_id']) ? '已作废' : '已完成',
                'serviceCompletedAt' => $this->dateTime((int)($row['settled_at'] ?: $row['occurred_at'])),
                'voidedAt' => !empty($row['voided_at']) ? $this->dateTime((int)$row['voided_at']) : '',
                'voidReason' => (string)($row['void_reason'] ?? ''),
                'voidOperatorName' => (string)($row['void_operator_name'] ?? ''),
                'voidOperationNo' => (string)($row['void_operation_no'] ?? ''),
            ];
        }, $rows), $total];
    }

    /**
     * @param array<int,array<string,mixed>> $serviceRows
     * @return array<string,array{amountCents:int,laborFeeCents:int,names:array<int,string>,allocations:array<int,array<string,mixed>>}>
     */
    private function laborPerformanceByServiceLine(array $serviceRows, string $tenantId): array
    {
        if ($serviceRows === [] || $tenantId === '') return [];
        $checkoutIds = [];
        $lineIds = [];
        // Earlier reservation completions created labor facts before the
        // point/round role was stored there. The completed service snapshot
        // is immutable and authoritative for those records, so use it only
        // as a historical compatibility projection while new labor facts
        // carry the same role directly in role_snapshot.
        $pointCustomerByLineAndEmployeeId = [];
        foreach ($serviceRows as $row) {
            $checkoutId = trim((string)($row['checkout_request_id'] ?? ''));
            $lineId = trim((string)($row['source_line_id'] ?? ''));
            if ($checkoutId === '' || $lineId === '') continue;
            $checkoutIds[$checkoutId] = $checkoutId;
            $lineIds[$lineId] = $lineId;
            $key = $this->serviceLineKey($row);
            foreach ((array)json_decode((string)($row['craftsmen_snapshot_json'] ?? '[]'), true) as $craftsman) {
                if (!is_array($craftsman)) continue;
                $employeeId = max(0, (int)($craftsman['employeeId'] ?? $craftsman['employee_id'] ?? 0));
                if ($employeeId <= 0) continue;
                if (array_key_exists('isPointCustomer', $craftsman)) {
                    $pointCustomerByLineAndEmployeeId[$key][$employeeId] = (bool)$craftsman['isPointCustomer'];
                } elseif (array_key_exists('is_point_customer', $craftsman)) {
                    $pointCustomerByLineAndEmployeeId[$key][$employeeId] = (bool)$craftsman['is_point_customer'];
                }
            }
        }
        if ($checkoutIds === [] || $lineIds === []) return [];
        $facts = Db::name('cashier_v3_performance_fact')
            ->where('tenant_id', $tenantId)
            ->where('performance_type', 'labor_performance_allocated')
            ->where('status', 'effective')
            ->whereIn('checkout_request_id', array_values($checkoutIds))
            ->whereIn('source_line_id', array_values($lineIds))
            ->field('id,checkout_request_id,source_line_id,fact_direction,amount_cents,labor_fee_amount_cents,project_count_half_units,employee_id,employee_name_snapshot,employee_type_snapshot,role_snapshot,allocation_weight_numerator,allocation_weight_denominator,rule_code_snapshot,rule_name_snapshot,rule_version_snapshot')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $grouped = [];
        foreach ($facts as $fact) {
            $key = $this->serviceLineKey($fact);
            if ($key === '') continue;
            $employeeId = (int)($fact['employee_id'] ?? 0);
            if ($employeeId <= 0) continue;
            $groupKey = $key . '|' . $employeeId;
            if (!isset($grouped[$groupKey])) $grouped[$groupKey] = [
                'key' => $key, 'employeeId' => $employeeId, 'employeeName' => '', 'employeeType' => '',
                'roleSnapshot' => '', 'amountCents' => 0, 'laborFeeCents' => 0,
                'projectCountHalfUnits' => 0, 'hasExplicitProjectCount' => false,
                'allocationWeightNumerator' => 0, 'allocationWeightDenominator' => 0,
                'hasExplicitAllocationWeight' => false,
                'ruleCodeSnapshot' => '', 'ruleNameSnapshot' => '',
                'ruleVersionSnapshot' => '',
            ];
            $grouped[$groupKey]['amountCents'] += (int)($fact['amount_cents'] ?? 0);
            $grouped[$groupKey]['laborFeeCents'] += (int)($fact['labor_fee_amount_cents'] ?? 0);
            $grouped[$groupKey]['projectCountHalfUnits'] += (int)($fact['project_count_half_units'] ?? 0);
            if ((string)($fact['fact_direction'] ?? '') === 'forward') {
                $grouped[$groupKey]['employeeName'] = trim((string)($fact['employee_name_snapshot'] ?? ''));
                $grouped[$groupKey]['employeeType'] = (string)($fact['employee_type_snapshot'] ?? '');
                $grouped[$groupKey]['roleSnapshot'] = (string)($fact['role_snapshot'] ?? '');
                $grouped[$groupKey]['ruleCodeSnapshot'] = (string)($fact['rule_code_snapshot'] ?? '');
                $grouped[$groupKey]['ruleNameSnapshot'] = (string)($fact['rule_name_snapshot'] ?? '');
                $grouped[$groupKey]['ruleVersionSnapshot'] = (string)($fact['rule_version_snapshot'] ?? '');
                // The allocation denominator is part of the immutable fact.
                // An independent position is deliberately stored as 100/100,
                // while a normal split is stored as e.g. 50/100. Do not
                // recalculate this against the line-wide amount: that would
                // collapse independent and normal groups into 50/50.
                $weightDenominator = (int)($fact['allocation_weight_denominator'] ?? 0);
                $weightNumerator = (int)($fact['allocation_weight_numerator'] ?? 0);
                if ($weightDenominator > 0 && $weightNumerator >= 0) {
                    $grouped[$groupKey]['allocationWeightNumerator'] = $weightNumerator;
                    $grouped[$groupKey]['allocationWeightDenominator'] = $weightDenominator;
                    $grouped[$groupKey]['hasExplicitAllocationWeight'] = true;
                }
                $grouped[$groupKey]['hasExplicitProjectCount'] =
                    (string)($fact['rule_code_snapshot'] ?? '') === 'SERVICE-RECORD-CRAFTSMAN-ADJUST-V1'
                    || (int)($fact['project_count_half_units'] ?? 0) !== 0;
            }
        }
        $result = [];
        foreach ($grouped as $allocation) {
            if ((int)$allocation['amountCents'] === 0 && (int)$allocation['laborFeeCents'] === 0
                && (int)$allocation['projectCountHalfUnits'] === 0
                && empty($allocation['hasExplicitProjectCount'])) continue;
            $key = (string)$allocation['key'];
            if (!isset($result[$key])) $result[$key] = [
                'amountCents' => 0, 'laborFeeCents' => 0, 'projectCountHalfUnits' => 0,
                'hasExplicitProjectCount' => false, 'names' => [], 'allocations' => [],
            ];
            $result[$key]['amountCents'] += (int)$allocation['amountCents'];
            $result[$key]['laborFeeCents'] += (int)$allocation['laborFeeCents'];
            $result[$key]['projectCountHalfUnits'] += (int)$allocation['projectCountHalfUnits'];
            $result[$key]['hasExplicitProjectCount'] = $result[$key]['hasExplicitProjectCount']
                || !empty($allocation['hasExplicitProjectCount']);
            $name = (string)$allocation['employeeName'];
            $pointCustomer = $this->pointCustomerFromRoleSnapshot((string)$allocation['roleSnapshot']);
            if ($pointCustomer === null) {
                $pointCustomer = $pointCustomerByLineAndEmployeeId[$key][(int)$allocation['employeeId']] ?? null;
            }
            $displayName = $this->craftsmanDisplayName($name, $pointCustomer);
            if ($displayName !== '' && !in_array($displayName, $result[$key]['names'], true)) $result[$key]['names'][] = $displayName;
            $baseAmount = max(0, (int)$result[$key]['amountCents']);
            $result[$key]['allocations'][] = [
                'employeeId' => (int)$allocation['employeeId'],
                'employeeName' => $name,
                'employeeType' => (string)$allocation['employeeType'],
                'roleSnapshot' => (string)$allocation['roleSnapshot'],
                'isPointCustomer' => $pointCustomer,
                'allocationWeightNumerator' => !empty($allocation['hasExplicitAllocationWeight'])
                    ? (int)$allocation['allocationWeightNumerator']
                    : (int)$allocation['amountCents'],
                'allocationWeightDenominator' => !empty($allocation['hasExplicitAllocationWeight'])
                    ? max(1, (int)$allocation['allocationWeightDenominator'])
                    : max(1, $baseAmount),
                'allocationRatio' => '',
                'allocationRatioPercent' => 0,
                'amount' => $this->centsToMoney((int)$allocation['amountCents']),
                'laborFeeAmount' => $this->centsToMoney((int)$allocation['laborFeeCents']),
                'projectCount' => number_format((int)$allocation['projectCountHalfUnits'] / 2, 1, '.', ''),
                'projectCountHalfUnits' => (int)$allocation['projectCountHalfUnits'],
                'ruleCodeSnapshot' => (string)$allocation['ruleCodeSnapshot'],
                'ruleNameSnapshot' => (string)$allocation['ruleNameSnapshot'],
                'ruleVersionSnapshot' => (string)$allocation['ruleVersionSnapshot'],
            ];
        }
        foreach ($result as &$line) {
            foreach ($line['allocations'] as &$allocation) {
                $denominator = max(1, (int)($allocation['allocationWeightDenominator'] ?? 0));
                $numerator = max(0, (int)($allocation['allocationWeightNumerator'] ?? 0));
                $allocation['allocationRatio'] = $numerator . '/' . $denominator;
                $allocation['allocationRatioPercent'] = round($numerator * 100 / $denominator, 2);
            }
            unset($allocation);
        }
        unset($line);
        return $result;
    }

    /** @return bool|null null means an older fact with no role label. */
    private function pointCustomerFromRoleSnapshot(string $roleSnapshot): ?bool
    {
        $role = strtolower(trim($roleSnapshot));
        if ($role === '' || $role === 'craftsman') return null;
        if (str_contains($role, 'point')) return true;
        if (str_contains($role, 'round')) return false;
        return null;
    }

    private function craftsmanDisplayName(string $name, ?bool $isPointCustomer): string
    {
        $name = trim($name);
        if ($name === '' || $isPointCustomer === null) return $name;
        return $name . ($isPointCustomer ? '（点）' : '（轮）');
    }

    private function laborPerformanceTypeLabel(string $mode): string
    {
        return [
            'checkout_manual_override' => '本次手工费',
            'project_rule' => '项目固定手工费',
            'actual_entitlement_amount' => '按实际项目金额',
            'project_configured_amount' => '按项目配置金额',
        ][$mode] ?? ($mode !== '' ? $mode : '项目固定手工费');
    }

    private function laborPerformanceRatio(array $allocations): string
    {
        $parts = [];
        foreach ($allocations as $allocation) {
            $name = trim((string)($allocation['employeeName'] ?? '')) ?: '未命名手艺人';
            $percent = (float)($allocation['allocationRatioPercent'] ?? 0);
            $parts[] = $name . ' ' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '%';
        }
        return implode('、', $parts);
    }

    private function serviceLineKey(array $row): string
    {
        $checkoutId = trim((string)($row['checkout_request_id'] ?? ''));
        $lineId = trim((string)($row['source_line_id'] ?? ''));
        return $checkoutId === '' || $lineId === '' ? '' : $checkoutId . ':' . $lineId;
    }

    private function craftsmenSummary(string $json): string
    {
        $items = json_decode($json, true);
        if (!is_array($items)) return '';
        $names = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $name = trim((string)(
                $item['staff_name_snapshot']
                ?? $item['staffName']
                ?? $item['staff_name']
                ?? $item['name']
                ?? ''
            ));
            if ($name === '') continue;
            // “点/轮” is frozen at checkout in the craftsmen snapshot. Never
            // infer it from a current member or staff record when reading a
            // historical completed service.
            if (array_key_exists('isPointCustomer', $item)) {
                $label = $name . ((bool)$item['isPointCustomer'] ? '（点）' : '（轮）');
            } elseif (array_key_exists('is_point_customer', $item)) {
                $label = $name . ((bool)$item['is_point_customer'] ? '（点）' : '（轮）');
            } else {
                $label = $name;
            }
            if (!in_array($label, $names, true)) $names[] = $label;
        }
        return implode('、', $names);
    }

    private function serviceEntitlementSource(array $row): string
    {
        if ((string)($row['source_document_type'] ?? '') === 'sales_order') {
            return '现金购买项目';
        }
        if ((int)($row['is_gift'] ?? 0) === 1) return '赠送权益';
        $kind = trim((string)($row['source_kind'] ?? ''));
        $labels = [
            'time_card' => '时间卡权益',
            'count_card' => '次数卡权益',
            'custom_card' => '定制卡权益',
            'gift' => '赠送权益',
        ];
        return $labels[$kind] ?? '卡项权益';
    }

    /** @return array<string,string> */
    private function serviceTopFilters(array $payload): array
    {
        $allowed = [
            'service_record_no', 'member_name', 'service_project',
            'entitlement_source', 'source_card', 'source_card_no', 'craftsman',
            'service_status',
        ];
        $filters = [];
        foreach ((array)($payload['topFilters'] ?? $payload['top_filters'] ?? []) as $filter) {
            if (!is_array($filter)) continue;
            $field = $this->scalar($filter['field'] ?? '');
            $value = $this->scalar($filter['value'] ?? '');
            if ($value === '' || !in_array($field, $allowed, true) || isset($filters[$field])) continue;
            if ($field === 'service_status' && !in_array($value, ['completed', 'voided', '已完成', '已作废'], true)) continue;
            $filters[$field] = $value;
        }
        return $filters;
    }

    /** @return array<int,array{field:string,direction:string}> */
    private function serviceSorts(array $payload): array
    {
        $allowed = [
            'service_record_no', 'business_date', 'member_name', 'service_project',
            'used_times', 'service_completed_at',
        ];
        $sorts = [];
        foreach ((array)($payload['sorts'] ?? []) as $sort) {
            if (!is_array($sort) || count($sorts) >= 3) continue;
            $field = $this->scalar($sort['field'] ?? '');
            $direction = strtolower($this->scalar($sort['direction'] ?? ''));
            if (!in_array($field, $allowed, true)
                || !in_array($direction, ['asc', 'desc'], true)
                || isset($sorts[$field])) continue;
            $sorts[$field] = ['field' => $field, 'direction' => $direction];
        }
        return array_values($sorts);
    }

    private function applyServiceTopFilters($query, array $filters): void
    {
        $likeFields = [
            'service_record_no' => 'sf.service_record_no',
            'member_name' => 'sf.member_name_snapshot',
            'service_project' => 'sf.project_name_snapshot',
            'entitlement_source' => 'wf.source_name_snapshot',
            'source_card' => 'wf.source_name_snapshot',
            'source_card_no' => 'wf.source_code_snapshot',
            'craftsman' => 'sf.craftsmen_snapshot_json',
        ];
        foreach ($filters as $field => $value) {
            if ($field === 'service_status') {
                if (in_array($value, ['voided', '已作废'], true)) {
                    $query->whereNotNull('vo.id');
                } else {
                    $query->whereNull('vo.id');
                }
            } elseif (isset($likeFields[$field])) {
                $this->whereUtf8Like(
                    $query,
                    $likeFields[$field],
                    '%' . addcslashes($value, "\\%_") . '%'
                );
            }
        }
    }

    private function pageServiceRows($query, array $criteria, string $fields): array
    {
        $columns = [
            'service_record_no' => 'sf.service_record_no',
            'business_date' => 'sf.business_date',
            'member_name' => 'sf.member_name_snapshot',
            'service_project' => 'sf.project_name_snapshot',
            'used_times' => 'sf.quantity',
            'service_completed_at' => 'sf.settled_at',
        ];
        foreach ($criteria['sorts'] as $sort) {
            $query->order($columns[$sort['field']], $sort['direction']);
        }
        return $query->field($fields)
            ->order('sf.settled_at', 'desc')
            ->order('sf.id', 'desc')
            ->page($criteria['page'], $criteria['pageSize'])
            ->select()
            ->toArray();
    }

    private function readGift(array $criteria, bool $countOnly): array
    {
        $sources = [
            $this->readV3RechargeGifts($criteria, $countOnly),
            $this->readV3DirectGifts($criteria, $countOnly),
            $this->readLegacyGifts($criteria, $countOnly),
        ];
        $total = array_sum(array_column($sources, 1));
        if ($countOnly) return [[], $total];
        $records = [];
        foreach ($sources as $source) {
            $records = array_merge($records, $source[0]);
        }
        usort($records, function (array $left, array $right): int {
            $time = (int)$right['_sortTime'] <=> (int)$left['_sortTime'];
            return $time !== 0 ? $time : strcmp((string)$right['id'], (string)$left['id']);
        });
        $offset = ($criteria['page'] - 1) * $criteria['pageSize'];
        $records = array_slice($records, $offset, $criteria['pageSize']);
        foreach ($records as &$record) unset($record['_sortTime']);
        unset($record);
        return [$records, $total];
    }

    /**
     * 充值套餐赠送以 V3 gift fact 为展示权威；一项项目或一张券均是一条可追溯记录。
     */
    private function readV3RechargeGifts(array $criteria, bool $countOnly): array
    {
        $query = Db::name('cashier_v3_gift_fact')->alias('gf')
            ->join('cashier_v3_recharge_gift_authority ga', 'ga.gift_id = gf.source_id')
            ->leftJoin('user u', 'u.uid = gf.member_id')
            ->leftJoin('system_store s', 's.id = gf.store_id')
            ->leftJoin('system_store_staff st', 'st.id = gf.operator_id')
            ->where('gf.source_type', 'recharge_gift')
            ->where('gf.status', 'effective')
            ->where(function ($q) use ($criteria) {
                if ($criteria['status'] === 'voided') $q->where('ga.status', 'voided');
                elseif ($criteria['dataScope'] === 'all' && $criteria['status'] === '') $q->whereIn('ga.status', ['issued', 'voided']);
                else $q->where('ga.status', 'issued');
            });
        $this->applyStoreScope($query, 'gf.store_id', $criteria['allowedStoreIds']);
        $this->applyTimestampBusinessDateRange($query, 'gf.settled_at', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'gf.gift_fact_id', 'ga.gift_no', 'ga.recharge_order_no_snapshot', 'gf.content_name_snapshot',
            'u.real_name', 'u.nickname', 'u.phone',
        ]);
        if ($criteria['status'] === 'refunded') return [[], 0];
        $total = (int)(clone $query)->count('gf.id');
        if ($countOnly) return [[], $total];
        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'gf.id', 'gf.gift_fact_id', 'gf.source_id', 'gf.source_detail_id', 'gf.gift_kind',
            'gf.quantity', 'gf.content_name_snapshot', 'gf.content_snapshot_json', 'gf.settled_at',
            'gf.store_id', 'gf.member_id', 'gf.operator_id', 'ga.gift_no', 'ga.recharge_order_no_snapshot', 'ga.status AS authority_status', 'u.real_name',
            'u.nickname', 'u.phone', 's.name AS store_name', 'st.staff_name',
        ]))->order('gf.settled_at', 'desc')->order('gf.id', 'desc')->limit($limit)->select()->toArray();
        return [array_map(function (array $row): array {
            $snapshot = json_decode((string)$row['content_snapshot_json'], true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $kind = (string)$row['gift_kind'];
            $time = (int)$row['settled_at'];
            return [
                'id' => 'v3-gift:' . (string)$row['gift_fact_id'],
                'giftRecordNo' => trim((string)($row['gift_no'] ?? '')) ?: (string)$row['gift_fact_id'],
                'businessDate' => $this->date($time),
                'memberId' => (int)$row['member_id'],
                'memberName' => $this->memberName($row),
                'giftSource' => '充值订单 ' . (string)$row['recharge_order_no_snapshot'],
                'giftType' => $kind === 'coupon' ? '赠送优惠券' : ($kind === 'project' ? '赠送项目' : '赠送商品'),
                'giftContent' => (string)$row['content_name_snapshot'],
                'giftQuantity' => (int)$row['quantity'],
                'effectiveAt' => $this->dateTime($time),
                'expiresAt' => (int)($snapshot['validityEnd'] ?? 0) > 0 ? $this->dateTime((int)$snapshot['validityEnd']) : null,
                'giftStatus' => (string)($row['authority_status'] ?? '') === 'voided' ? '已作废' : '有效',
                'storeName' => (string)$row['store_name'],
                'operatorName' => (string)$row['staff_name'],
                'giftReason' => '充值套餐赠送',
                'createdAt' => $this->dateTime($time),
                '_sortTime' => $time,
            ];
        }, $rows), $total];
    }

    /**
     * 独立赠送以 V3 gift fact 为唯一展示来源；兼容权益投影只供会员资产读取。
     */
    private function readV3DirectGifts(array $criteria, bool $countOnly): array
    {
        $query = Db::name('cashier_v3_gift_fact')->alias('gf')
            ->join('cashier_v3_direct_gift_authority ga', 'ga.gift_id = gf.source_id')
            // Each direct-gift item has its own expiry. Read that immutable item
            // snapshot instead of inferring a line expiry from the parent gift.
            ->leftJoin('cashier_v3_direct_gift_item gi', 'gi.gift_id = gf.source_id AND gi.item_id = gf.source_detail_id')
            ->leftJoin('user u', 'u.uid = gf.member_id')
            ->leftJoin('system_store s', 's.id = gf.store_id')
            ->leftJoin('system_store_staff st', 'st.id = gf.operator_id')
            ->where('gf.source_type', 'direct_gift')
            ->where('gf.status', 'effective')
            ->where(function ($q) use ($criteria) {
                $allData = $criteria['dataScope'] === 'all' && $criteria['status'] === '';
                if ($criteria['status'] === 'voided') {
                    $q->where(function ($inner) {
                        $inner->where('ga.status', 'voided')->whereOr('gi.status', 'voided');
                    });
                } elseif ($allData) {
                    $q->where(function ($inner) {
                        $inner->whereIn('ga.status', ['issued', 'voided'])
                            ->whereIn('gi.status', ['issued', 'voided']);
                    });
                } else {
                    $q->where('ga.status', 'issued')->where('gi.status', 'issued');
                }
            });
        $this->applyStoreScope($query, 'gf.store_id', $criteria['allowedStoreIds']);
        $this->applyTimestampBusinessDateRange($query, 'gf.settled_at', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'gf.gift_fact_id', 'ga.gift_no', 'ga.reason_snapshot', 'gf.content_name_snapshot',
            'u.real_name', 'u.nickname', 'u.phone',
        ]);
        if ($criteria['status'] === 'refunded') return [[], 0];
        $total = (int)(clone $query)->count('gf.id');
        if ($countOnly) return [[], $total];
        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'gf.id', 'gf.gift_fact_id', 'gf.source_id', 'gf.source_detail_id', 'gf.gift_kind',
            'gf.quantity', 'gf.content_name_snapshot', 'gf.content_snapshot_json', 'gf.settled_at',
            'gf.store_id', 'gf.member_id', 'gf.operator_id', 'ga.gift_no', 'ga.reason_snapshot', 'ga.validity_end AS authority_validity_end',
            'gi.content_snapshot_json AS item_content_snapshot_json', 'ga.status AS authority_status', 'u.real_name',
            'gi.status AS item_status', 'u.nickname', 'u.phone', 's.name AS store_name', 'st.staff_name',
        ]))->order('gf.settled_at', 'desc')->order('gf.id', 'desc')->limit($limit)->select()->toArray();
        return [array_map(function (array $row): array {
            $snapshot = json_decode((string)($row['item_content_snapshot_json'] ?: $row['content_snapshot_json']), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $kind = (string)$row['gift_kind'];
            $time = (int)$row['settled_at'];
            $validityEnd = (int)($snapshot['validityEnd'] ?? $snapshot['validity_end'] ?? 0);
            if ($validityEnd <= 0) $validityEnd = (int)($row['authority_validity_end'] ?? 0);
            return [
                'id' => 'v3-direct-gift:' . (string)$row['gift_fact_id'],
                // Lifecycle commands must use the immutable V3 gift fact identity,
                // not the display number (a single gift number can contain
                // multiple project/product rows).
                'voidRecordId' => 'v3-direct-gift:' . (string)$row['gift_fact_id'],
                // `record_id` is the hidden, stable key declared by the unified
                // order-center contract. Keep it populated on the direct query
                // path as well as on the export/provider path so the frontend
                // never falls back to the human-facing ZS number.
                'record_id' => 'gift:v3-direct-gift:' . (string)$row['gift_fact_id'],
                'giftRecordNo' => trim((string)($row['gift_no'] ?? '')) ?: (string)$row['gift_fact_id'],
                'businessDate' => $this->date($time),
                'memberId' => (int)$row['member_id'],
                'memberName' => $this->memberName($row),
                'giftSource' => '独立赠送',
                'giftType' => $kind === 'coupon' ? '赠送优惠券' : ($kind === 'project' ? '赠送项目' : '赠送商品'),
                'giftContent' => (string)$row['content_name_snapshot'],
                'giftQuantity' => (int)$row['quantity'],
                'effectiveAt' => $this->dateTime($time),
                'expiresAt' => $validityEnd > 0 ? $this->dateTime($validityEnd) : null,
                'giftStatus' => (string)($row['authority_status'] ?? '') === 'voided'
                    || (string)($row['item_status'] ?? '') === 'voided' ? '已作废' : '有效',
                'storeName' => (string)$row['store_name'],
                'operatorName' => (string)$row['staff_name'],
                'giftReason' => (string)$row['reason_snapshot'],
                'createdAt' => $this->dateTime($time),
                '_sortTime' => $time,
            ];
        }, $rows), $total];
    }

    private function readLegacyGifts(array $criteria, bool $countOnly): array
    {
        $query = Db::name('store_order_cart_info')->alias('ci')
            ->join('store_order o', 'o.id = ci.oid')
            ->leftJoin('user u', 'u.uid = o.uid')
            ->leftJoin('system_store s', 's.id = o.store_id')
            ->leftJoin('system_store_staff st', 'st.id = o.staff_id')
            ->where('ci.is_gift', 1)
            ->where('o.paid', 1)
            ->where('o.is_system_del', 0)
            // V3 recharge gifts use the authority and fact tables above. Their projection rows
            // remain for legacy benefit readers, but must not create duplicate order-center rows.
            ->where('ci.cart_info', 'not like', '%cashier_v3_recharge_gift%')
            ->where('ci.cart_info', 'not like', '%cashier_v3_direct_gift%');
        $this->applyStoreScope($query, 'o.store_id', $criteria['allowedStoreIds']);
        $this->applyTimestampBusinessDateRange($query, 'COALESCE(NULLIF(o.pay_time, 0), o.add_time)', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'o.order_id', 'u.real_name', 'u.nickname', 'u.phone', 'ci.cart_info',
        ]);
        if ($criteria['status'] === 'active') {
            $query->where('o.refund_status', 0)->where('o.terminal_action', 0);
        } elseif ($criteria['status'] === 'refunded') {
            $query->where(function ($q) {
                $q->where('o.refund_status', '>', 0)->whereOr('o.terminal_action', '>', 0);
            });
        }
        $total = (int)(clone $query)->count('ci.id');
        if ($countOnly) return [[], $total];
        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'ci.id', 'ci.oid', 'ci.cart_num', 'ci.product_type', 'ci.cart_info',
            'ci.write_end', 'ci.is_writeoff', 'o.order_id', 'o.uid', 'o.store_id',
            'o.refund_status', 'o.terminal_action', 'o.pay_time', 'o.add_time',
            'u.real_name', 'u.nickname', 'u.phone', 's.name AS store_name', 'st.staff_name',
        ]))->order('o.pay_time', 'desc')->order('ci.id', 'desc')->limit($limit)->select()->toArray();
        return [array_map(function (array $row): array {
            $product = $this->productSnapshot((string)$row['cart_info']);
            $time = (int)($row['pay_time'] ?: $row['add_time']);
            $refunded = (int)$row['refund_status'] > 0 || (int)$row['terminal_action'] > 0;
            return [
                'id' => 'gift:' . $row['id'],
                'giftRecordNo' => 'GIFT-' . $row['id'],
                'businessDate' => $this->date($time),
                'memberId' => (int)$row['uid'],
                'memberName' => $this->memberName($row),
                'giftSource' => '销售订单 ' . (string)$row['order_id'],
                'giftType' => (int)$row['product_type'] === 6 ? '赠送项目' : '赠送商品',
                'giftContent' => $product['name'],
                'giftQuantity' => (int)$row['cart_num'],
                'effectiveAt' => $this->dateTime($time),
                'expiresAt' => (int)$row['write_end'] > 0 ? $this->dateTime((int)$row['write_end']) : null,
                'giftStatus' => $refunded ? '已失效' : ((int)$row['is_writeoff'] === 1 ? '已使用' : '有效'),
                'storeName' => (string)$row['store_name'],
                'operatorName' => (string)$row['staff_name'],
                'giftReason' => '随单赠送',
                'createdAt' => $this->dateTime($time),
                '_sortTime' => $time,
            ];
        }, $rows), $total];
    }

    private function readCardOperations(
        array $criteria,
        CashierV3OperatorScope $scope,
        bool $countOnly
    ): array {
        $sources = [
            $this->readV3CardOperations($criteria, $scope, $countOnly),
            $this->readLegacyReplacements($criteria, $countOnly),
            $this->readLegacyCardUpgrades($criteria, $countOnly),
        ];
        $total = array_sum(array_column($sources, 1));
        if ($countOnly) return [[], $total];
        $rows = [];
        foreach ($sources as $source) {
            $rows = array_merge($rows, $source[0]);
        }
        usort($rows, function (array $left, array $right): int {
            $time = (int)$right['_sortTime'] <=> (int)$left['_sortTime'];
            return $time !== 0 ? $time : strcmp((string)$right['id'], (string)$left['id']);
        });
        $offset = ($criteria['page'] - 1) * $criteria['pageSize'];
        $rows = array_slice($rows, $offset, $criteria['pageSize']);
        foreach ($rows as &$row) unset($row['_sortTime']);
        unset($row);
        return [$rows, $total];
    }

    private function readV3CardOperations(array $criteria, CashierV3OperatorScope $scope, bool $countOnly): array
    {
        $query = Db::name('cashier_v3_card_operation')->alias('c')
            // A checkout request id is an idempotency/processing reference, not
            // the customer-facing sales order number. Upgrade settlement is the
            // authoritative bridge from a card operation to its sales order.
            ->leftJoin('cashier_v3_card_operation_settlement s', 's.tenant_id = c.tenant_id AND s.operation_id = c.operation_id AND s.settlement_status = \'settled\'')
            ->leftJoin('cashier_v3_sales_order o', 'o.tenant_id = s.tenant_id AND o.order_id = s.sales_order_id')
            ->where('c.tenant_id', $scope->tenantId())
            ->whereIn('c.operation_type', self::CARD_OPERATION_TYPES);
        if ($criteria['operationType'] !== '') {
            $query->where('c.operation_type', $criteria['operationType']);
        }
        $this->applyStoreScope($query, 'c.store_id', $criteria['allowedStoreIds']);
        $this->applyBusinessDateRange($query, 'c.business_date', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'c.operation_no', 'c.member_name_after_snapshot', 'c.card_name_snapshot',
            'c.card_no_snapshot', 'c.target_catalog_name_snapshot', 'c.operator_name_snapshot',
        ]);
        if ($criteria['status'] !== '') {
            $query->where('c.operation_status', $criteria['status']);
        }
        $total = (int)(clone $query)->count('c.id');
        if ($countOnly) return [[], $total];
        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'c.id', 'c.operation_no', 'c.operation_type', 'c.operation_status', 'c.store_id',
            'c.origin_member_id', 'c.member_id_before', 'c.member_id_after',
            'c.store_name_snapshot', 'c.member_name_after_snapshot', 'c.card_name_snapshot',
            'c.card_no_snapshot', 'c.target_catalog_name_snapshot', 'c.source_remaining_value_cents',
            'c.settlement_delta_cents', 'o.order_no AS sales_order_no', 'c.reason_snapshot',
            'c.operator_name_snapshot', 'c.business_date', 'c.occurred_at', 'c.settled_at',
        ]))->order('c.occurred_at', 'desc')->order('c.id', 'desc')->limit($limit)->select()->toArray();
        return [array_map(function (array $row): array {
            $time = (int)($row['settled_at'] ?: $row['occurred_at']);
            return $this->cardOperationRecord([
                'id' => 'card-operation:' . $row['id'],
                'operationNo' => $row['operation_no'],
                'operationType' => $row['operation_type'],
                'operationStatus' => $row['operation_status'],
                'businessDate' => (string)$row['business_date'],
                'memberId' => (int)($row['member_id_after'] ?: $row['member_id_before'] ?: $row['origin_member_id']),
                'memberName' => $row['member_name_after_snapshot'],
                'sourceCard' => $row['card_name_snapshot'],
                'targetContent' => $row['target_catalog_name_snapshot'],
                'amount' => $this->centsToMoney((int)$row['settlement_delta_cents']),
                'salesOrderNo' => $row['sales_order_no'],
                'storeName' => $row['store_name_snapshot'],
                'operatorName' => $row['operator_name_snapshot'],
                'reason' => $row['reason_snapshot'],
                'completedAt' => $time > 0 ? $this->dateTime($time) : null,
                '_sortTime' => (int)$row['occurred_at'],
            ]);
        }, $rows), $total];
    }

    private function readLegacyReplacements(array $criteria, bool $countOnly): array
    {
        if ($criteria['operationType'] !== '' && $criteria['operationType'] !== 'project_replacement') {
            return [[], 0];
        }
        $query = Db::name('card_project_replacement')->alias('r')
            ->leftJoin('user u', 'u.uid = r.uid')
            ->leftJoin('system_store s', 's.id = r.store_id')
            ->leftJoin('system_store_staff st', 'st.id = r.staff_id');
        $this->applyStoreScope($query, 'r.store_id', $criteria['allowedStoreIds']);
        $this->applyTimestampBusinessDateRange($query, 'r.business_time', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'r.replacement_no', 'u.real_name', 'u.nickname', 'u.phone', 'r.snapshot_json',
        ]);
        if ($criteria['status'] === 'completed') $query->where('r.status', 0);
        if ($criteria['status'] !== '' && $criteria['status'] !== 'completed') {
            return [[], 0];
        }
        $total = (int)(clone $query)->count('r.id');
        if ($countOnly) return [[], $total];
        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'r.id', 'r.replacement_no', 'r.oid', 'r.target_amount', 'r.snapshot_json',
            'r.status', 'r.business_time', 'r.operate_time', 'u.real_name', 'u.nickname',
            'u.uid', 'u.phone', 's.name AS store_name', 'st.staff_name',
        ]))->order('r.business_time', 'desc')->order('r.id', 'desc')->limit($limit)->select()->toArray();
        return [array_map(function (array $row): array {
            $snapshot = json_decode((string)$row['snapshot_json'], true);
            $sources = is_array($snapshot['sources'] ?? null) ? $snapshot['sources'] : [];
            $sourceNames = [];
            foreach ($sources as $source) {
                $name = trim((string)($source['name'] ?? $source['product_name'] ?? ''));
                if ($name !== '') $sourceNames[] = $name;
            }
            $target = is_array($snapshot['target'] ?? null) ? $snapshot['target'] : [];
            return $this->cardOperationRecord([
                'id' => 'legacy-replacement:' . $row['id'],
                'operationNo' => $row['replacement_no'],
                'operationType' => 'project_replacement',
                'operationStatus' => 'completed',
                'businessDate' => $this->date((int)$row['business_time']),
                'memberId' => (int)($row['uid'] ?? 0),
                'memberName' => $this->memberName($row),
                'sourceCard' => implode('、', array_unique($sourceNames)),
                'targetContent' => (string)($target['name'] ?? $target['product_name'] ?? ''),
                'amount' => (string)$row['target_amount'],
                'salesOrderNo' => (string)$row['oid'],
                'storeName' => $row['store_name'],
                'operatorName' => $row['staff_name'],
                'reason' => (string)($snapshot['remark'] ?? ''),
                'completedAt' => $this->dateTime((int)$row['operate_time']),
                '_sortTime' => (int)$row['business_time'],
            ]);
        }, $rows), $total];
    }

    private function readLegacyCardUpgrades(array $criteria, bool $countOnly): array
    {
        if ($criteria['operationType'] !== '' && $criteria['operationType'] !== 'card_upgrade') {
            return [[], 0];
        }
        $query = Db::name('store_order')->alias('o')
            ->leftJoin('store_order target', 'target.id = o.card_upgrade_use_oid')
            ->leftJoin('user u', 'u.uid = o.uid')
            ->leftJoin('system_store s', 's.id = o.store_id')
            ->leftJoin('system_store_staff st', 'st.id = o.staff_id')
            ->where('o.card_upgrade_use_oid', '>', 0)
            ->where('o.paid', 1)
            ->where('o.is_system_del', 0);
        $this->applyStoreScope($query, 'o.store_id', $criteria['allowedStoreIds']);
        $this->applyTimestampBusinessDateRange($query, 'COALESCE(NULLIF(o.pay_time, 0), o.add_time)', $criteria);
        $this->applyKeyword($query, $criteria['keyword'], [
            'o.order_id', 'target.order_id', 'u.real_name', 'u.nickname', 'u.phone',
        ]);
        if ($criteria['status'] === 'completed') {
            $query->where('o.terminal_action', 0);
        } elseif ($criteria['status'] === 'cancelled') {
            $query->where('o.terminal_action', '>', 0);
        } elseif ($criteria['status'] !== '') {
            return [[], 0];
        }
        $total = (int)(clone $query)->count('o.id');
        if ($countOnly) return [[], $total];
        $limit = $criteria['page'] * $criteria['pageSize'];
        $rows = $query->field(implode(',', [
            'o.id', 'o.order_id', 'o.card_upgrade_use_oid', 'o.pay_price', 'o.debt_amount',
            'o.terminal_action', 'o.pay_time', 'o.add_time', 'target.order_id AS target_order_no',
            'o.uid', 'u.real_name', 'u.nickname', 'u.phone', 's.name AS store_name', 'st.staff_name',
        ]))->order('o.pay_time', 'desc')->order('o.id', 'desc')->limit($limit)->select()->toArray();
        return [array_map(function (array $row): array {
            $time = (int)($row['pay_time'] ?: $row['add_time']);
            return $this->cardOperationRecord([
                'id' => 'legacy-card-upgrade:' . $row['id'],
                'operationNo' => 'UP-' . $row['id'],
                'operationType' => 'card_upgrade',
                'operationStatus' => (int)$row['terminal_action'] > 0 ? 'cancelled' : 'completed',
                'businessDate' => $this->date($time),
                'memberId' => (int)($row['uid'] ?? 0),
                'memberName' => $this->memberName($row),
                'sourceCard' => '原卡订单 ' . $row['order_id'],
                'targetContent' => '升级订单 ' . ($row['target_order_no'] ?: $row['card_upgrade_use_oid']),
                'amount' => (string)$row['pay_price'],
                'salesOrderNo' => (string)$row['target_order_no'],
                'storeName' => $row['store_name'],
                'operatorName' => $row['staff_name'],
                'reason' => (float)$row['debt_amount'] > 0 ? '含欠款 ' . $row['debt_amount'] . ' 元' : '',
                'completedAt' => $this->dateTime($time),
                '_sortTime' => $time,
            ]);
        }, $rows), $total];
    }

    private function cardOperationRecord(array $row): array
    {
        $type = (string)$row['operationType'];
        return [
            'id' => $row['id'],
            'cardOperationNo' => (string)$row['operationNo'],
            'businessDate' => (string)$row['businessDate'],
            'memberName' => (string)$row['memberName'],
            'memberId' => (int)($row['memberId'] ?? 0),
            'operationType' => $type,
            'operationTypeLabel' => $this->cardOperationLabel($type),
            'sourceCard' => (string)$row['sourceCard'],
            'targetContent' => (string)$row['targetContent'],
            'operationAmount' => (string)$row['amount'],
            'salesOrderNo' => (string)$row['salesOrderNo'],
            'storeName' => (string)$row['storeName'],
            'operatorName' => (string)$row['operatorName'],
            'recordStatus' => $this->cardOperationStatusLabel((string)$row['operationStatus']),
            'operationReason' => (string)$row['reason'],
            'completedAt' => $row['completedAt'],
            '_sortTime' => (int)$row['_sortTime'],
        ];
    }

    private function pageRows($query, array $criteria, string $timeField, string $idField, string $fields): array
    {
        return $query->field($fields)
            ->order($timeField, 'desc')
            ->order($idField, 'desc')
            ->page($criteria['page'], $criteria['pageSize'])
            ->select()
            ->toArray();
    }

    private function applyStoreScope($query, string $field, $allowedStoreIds): void
    {
        if ($allowedStoreIds !== null) {
            $query->whereIn($field, $allowedStoreIds);
        }
    }

    private function applyBusinessDateRange($query, string $field, array $criteria): void
    {
        $from = (string)($criteria['businessDateFrom'] ?? '');
        $to = (string)($criteria['businessDateTo'] ?? '');
        if ($from !== '') $query->where($field, '>=', $from);
        if ($to !== '') $query->where($field, '<=', $to);
    }

    private function applyTimestampBusinessDateRange($query, string $field, array $criteria): void
    {
        $from = (string)($criteria['businessDateFrom'] ?? '');
        $to = (string)($criteria['businessDateTo'] ?? '');
        $zone = new \DateTimeZone(self::BUSINESS_TIMEZONE);
        if ($from !== '') {
            $start = new \DateTimeImmutable($from . ' 00:00:00', $zone);
            $this->whereTimestampBoundary($query, $field, '>=', $start->getTimestamp());
        }
        if ($to !== '') {
            $end = new \DateTimeImmutable($to . ' 23:59:59', $zone);
            $this->whereTimestampBoundary($query, $field, '<=', $end->getTimestamp());
        }
    }

    private function whereTimestampBoundary($query, string $field, string $operator, int $value): void
    {
        if (str_contains($field, '(')) {
            $query->whereRaw($field . ' ' . $operator . ' ?', [$value]);
            return;
        }
        $query->where($field, $operator, $value);
    }

    private function applyKeyword($query, string $keyword, array $fields): void
    {
        if ($keyword === '') return;
        $like = '%' . addcslashes($keyword, "\\%_") . '%';
        $query->where(function ($nested) use ($fields, $like) {
            foreach ($fields as $index => $field) {
                $this->whereUtf8Like($nested, $field, $like, $index !== 0);
            }
        });
    }

    /**
     * 历史订单编号等字段仍是 ascii_bin，而姓名和项目名称是 utf8mb4。
     * 关键词检索会把它们放在同一个 LIKE 条件组中，必须显式统一表达式
     * 字符集，避免中文关键词触发 MySQL 的 Illegal mix of collations。
     * $field 仅来自本类固定白名单，绝不接收客户端字段名。
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

    private function pagePayload(array $criteria, array $records, int $total, string $status): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'recordType' => $criteria['type'],
            'dataStatus' => $status,
            'dataAsOf' => $this->dateTime(time(), DATE_ATOM),
            'records' => $records,
            'total' => max(0, $total),
            'page' => (int)$criteria['page'],
            'pageSize' => (int)$criteria['pageSize'],
            'statusOptions' => $this->statusOptions($criteria['type']),
        ];
    }

    private function emptyPageMeta(): array
    {
        return [
            'total' => 0,
            'page' => 1,
            'pageSize' => 20,
            'dataStatus' => 'not_loaded',
            'paginationMode' => 'offset',
            'hasMore' => false,
        ];
    }

    private function statusOptions(string $type): array
    {
        $options = [['value' => '', 'label' => '全部']];
        if ($type === 'recharge') {
            return array_merge($options, [
                ['value' => 'paid', 'label' => '已支付'],
                ['value' => 'voided', 'label' => '已作废'],
                ['value' => 'refunded', 'label' => '已退款'],
            ]);
        }
        if ($type === 'refund') {
            return array_merge($options, [
                ['value' => 'completed', 'label' => '已退款'],
            ]);
        }
        if ($type === 'debt') {
            return array_merge($options, [
                ['value' => 'outstanding', 'label' => '待补交'],
                ['value' => 'settled', 'label' => '已结清'],
            ]);
        }
        if ($type === 'gift') {
            return array_merge($options, [
                ['value' => 'active', 'label' => '有效'],
                ['value' => 'voided', 'label' => '已作废'],
                ['value' => 'refunded', 'label' => '已失效'],
            ]);
        }
        if ($type === 'supplement') {
            return array_merge($options, [
                ['value' => 'succeeded', 'label' => '补交成功'],
                ['value' => 'voided', 'label' => '已作废'],
            ]);
        }
        if ($type === 'card_operation') {
            return array_merge($options, [
                ['value' => 'completed', 'label' => '已完成'],
                ['value' => 'awaiting_checkout', 'label' => '待结账'],
                ['value' => 'cancelled', 'label' => '已取消'],
            ]);
        }
        if ($type === 'service') {
            return array_merge($options, [
                ['value' => 'completed', 'label' => '已完成'],
                ['value' => 'voided', 'label' => '已作废'],
            ]);
        }
        return $options;
    }

    /** @return int[]|null */
    private function requestedStoreIds(array $payload)
    {
        if (!array_key_exists('storeIds', $payload) && !array_key_exists('store_ids', $payload)) {
            return null;
        }
        $value = $payload['storeIds'] ?? $payload['store_ids'];
        return is_array($value) ? ($this->canonicalStoreIds($value) ?: []) : [];
    }

    /** @return int[]|null */
    private function canonicalStoreIds($ids)
    {
        if ($ids === null) return null;
        $result = [];
        foreach ((array)$ids as $id) {
            if (is_bool($id) || is_array($id) || is_object($id)) continue;
            $raw = trim((string)$id);
            if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) continue;
            $result[(int)$raw] = (int)$raw;
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    private function scalar($value): string
    {
        return is_scalar($value) && !is_bool($value) ? trim((string)$value) : '';
    }

    private function memberName(array $row): string
    {
        return trim((string)($row['real_name'] ?: $row['nickname'] ?: ''));
    }

    private function productSnapshot(string $json): array
    {
        $decoded = json_decode($json, true);
        $product = is_array($decoded['productInfo'] ?? null) ? $decoded['productInfo'] : [];
        return ['name' => (string)($product['store_name'] ?? $product['name'] ?? '赠送内容')];
    }

    private function paymentLabel(string $code): string
    {
        $labels = [
            'cash' => '现金',
            'weixin' => '微信',
            'wechat' => '微信',
            'alipay' => '支付宝',
            'unionpay' => '银联刷卡',
            'bank' => '银联刷卡',
            'yue' => '余额',
            'offline' => '线下记账',
        ];
        $normalized = strtolower(trim($code));
        return $labels[$normalized] ?? ($code !== '' ? $code : '未标注');
    }

    /**
     * V3 recharge commands persist every real collection method in
     * combination_info. channel_type describes the member channel, not how
     * this recharge was collected, so it must never win the display value.
     */
    private function rechargePaymentLabel(array $row): string
    {
        $decoded = json_decode((string)($row['combination_info'] ?? ''), true);
        $labels = [];
        if (is_array($decoded)) {
            foreach ($decoded as $line) {
                if (!is_array($line)) continue;
                $method = trim((string)($line['paymentMethod'] ?? $line['payment_method'] ?? ''));
                if ($method === '') continue;
                $labels[$method] = $this->paymentLabel($method);
            }
        }
        if ($labels !== []) return implode('、', array_values($labels));
        return $this->paymentLabel((string)($row['recharge_type'] ?? ''));
    }

    private function cardOperationLabel(string $type): string
    {
        $labels = [
            'card_transfer' => '卡转让',
            'card_upgrade' => '卡升级',
            'card_disable' => '卡停用',
            'card_enable' => '卡启用',
            'card_extension' => '卡延期',
            'project_replacement' => '项目替换',
            'project_upgrade' => '项目升级',
        ];
        return $labels[$type] ?? $type;
    }

    private function cardOperationTypeFromLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        foreach (self::CARD_OPERATION_TYPES as $type) {
            if ($value === $type || $value === $this->cardOperationLabel($type)) return $type;
        }
        return '';
    }

    private function topFilterValue(array $payload, string $field): string
    {
        foreach ((array)($payload['topFilters'] ?? $payload['top_filters'] ?? []) as $filter) {
            if (!is_array($filter) || (string)($filter['field'] ?? '') !== $field) continue;
            return $this->scalar($filter['value'] ?? '');
        }
        return '';
    }

    private function cardOperationStatusLabel(string $status): string
    {
        $labels = [
            'completed' => '已完成',
            'succeeded' => '已完成',
            'awaiting_checkout' => '待结账',
            'cancelled' => '已取消',
            'failed' => '失败',
        ];
        return $labels[$status] ?? $status;
    }

    private function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $money = intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
        return $negative ? '-' . $money : $money;
    }

    private function date(int $timestamp): string
    {
        return $this->dateTime($timestamp, 'Y-m-d') ?: '';
    }

    private function dateTime(int $timestamp, string $format = 'Y-m-d H:i:s')
    {
        if ($timestamp <= 0) return null;
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE))
            ->format($format);
    }
}
