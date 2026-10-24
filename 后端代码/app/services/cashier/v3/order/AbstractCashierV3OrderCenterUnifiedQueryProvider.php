<?php

namespace app\services\cashier\v3\order;

use app\services\query\UnifiedQueryCustomFieldKeyCollector;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\UnifiedQueryProvider;
use think\facade\Db;

/**
 * 将订单中心现有的权威只读投影接入统一查询与导出。
 *
 * 列表按实时可信范围、导出 worker 按重建的范围读取有界权威候选，再共用筛选和分页。
 * 超过安全窗口明确失败，不对截断候选返回误导性总数；不相信客户端保存的门店权限。
 */
abstract class AbstractCashierV3OrderCenterUnifiedQueryProvider implements UnifiedQueryProvider
{
    /** @var UnifiedQueryExecutionServices */
    protected $execution;
    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;
    /** @var UnifiedQueryPreferenceServices */
    protected $preferences;
    /** @var CashierV3SalesOrderQueryServices */
    protected $salesQueries;
    /** @var CashierV3OrderCenterRecordQueryServices */
    protected $recordQueries;
    /** 请求内可信权限与首批分页元数据；不从浏览器反序列化。 */
    private $liveScopes;
    private $sourcePayload = [];
    private $sourcePage = [];

    public function __construct(
        CashierV3OrderCenterQueryExecutionServices $execution,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryPreferenceServices $preferences
    ) {
        $this->execution = $execution;
        $this->customFields = $customFields;
        $this->preferences = $preferences;
        // 统一查询注册器由容器按类型创建 provider。若把订单查询服务也声明为
        // 构造参数，容器会继续递归构造游标签名器（其 secret 不是容器依赖），
        // 从而让登录后的工作台初始化失败。订单中心既有模块也是在服务内部创建
        // 这两个只读查询服务，保持相同边界即可。
        $this->salesQueries = new CashierV3SalesOrderQueryServices();
        $this->recordQueries = new CashierV3OrderCenterRecordQueryServices();
    }

    abstract public function pageCode(): string;

    /** 列表与导出复用同一执行路径；仅替换只读结果，不接触写命令和资源版本。 */
    public function queryPage(array $context, array $payload, $operatorScope, $dataScope): array
    {
        $this->liveScopes = [$operatorScope, $dataScope];
        try {
            $result = $this->run($context, $payload, $this->definitionsForQuery($context, $payload));
            return array_replace($this->sourcePage, [
                'records' => $this->publicRows($result['rows']),
                'total' => (int)$result['pagination']['total'],
                'page' => (int)$result['pagination']['page'],
                'pageSize' => (int)$result['pagination']['limit'],
                'paginationMode' => 'offset', 'paginationCursor' => ['current' => '', 'next' => ''],
                'hasMore' => (int)$result['pagination']['page'] * (int)$result['pagination']['limit'] < (int)$result['pagination']['total'],
            ]);
        } finally {
            $this->liveScopes = null;
            $this->sourcePayload = [];
            $this->sourcePage = [];
        }
    }

    public function query(array $context, array $payload): array
    {
        $request = clone $this;
        $result = $request->run($context, $payload, $this->definitionsForQuery($context, $payload));
        return [
            'records' => $this->publicRows($result['rows']),
            'total' => (int)$result['pagination']['total'],
            'page' => (int)$result['pagination']['page'],
            'pageSize' => (int)$result['pagination']['limit'],
            'querySettings' => $this->preferences->load($context, $this->pageCode()),
            'summaries' => $result['summaries'],
            'groups' => $result['groups'],
            'queryCutoffDate' => $result['queryCutoffDate'],
            'dataAsOf' => $result['dataAsOf'],
            'metricVersion' => $result['schemaVersion'],
            'aggregationCaughtUp' => true,
            'consistencyFingerprint' => $result['consistencyFingerprint'],
        ];
    }

    public function executeFrozenPlan(
        array $context,
        array $plan,
        string $exportScope,
        array $fieldKeys
    ): array {
        if ((string)($plan['page_code'] ?? '') !== $this->pageCode()) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PLAN_INVALID', '导出查询计划与订单记录不匹配。', []);
        }
        $query = [
            'page' => (int)($plan['pagination']['page'] ?? 1),
            'limit' => (int)($plan['pagination']['limit'] ?? 20),
            'filters' => (array)($plan['filters'] ?? []),
            'topFilterConditions' => (array)($plan['top_filters'] ?? []),
            'keywordFilters' => (array)($plan['keyword_filters'] ?? []),
            'filterRelation' => (string)($plan['filter_relation'] ?? 'all'),
            'sorts' => (array)($plan['sorts'] ?? []),
            'groupBy' => (array)($plan['groups'] ?? []),
            'summaries' => (array)($plan['summaries'] ?? []),
            'dataScope' => (string)($plan['domain_scope']['data_scope'] ?? 'normal'),
            'businessStatus' => (string)($plan['domain_scope']['business_status'] ?? ''),
            'quickFilters' => (array)($plan['quick_filters'] ?? []),
            'visibleFields' => (array)($plan['visible_fields'] ?? []),
            'export' => [
                'scope' => $exportScope,
                'fields' => array_values(array_unique(array_map('strval', $fieldKeys))),
            ],
        ];
        $request = clone $this;
        return $request->run($context, $query, (array)($plan['custom_definitions'] ?? []));
    }

    /** @return array<string,mixed> */
    protected function run(array $context, array $payload, array $definitions): array
    {
        $payload['pageCode'] = $this->pageCode();
        unset($payload['queryCutoffDate'], $payload['query_cutoff_date']);
        $memberInitials = $this->memberInitialKeyword($payload);
        $this->sourcePayload = $this->sourceQuery($payload);
        // 领域日期/权限/下钻参数由源 Reader 消费；不传入通用表达式白名单。
        $payload = array_intersect_key($payload, array_flip([
            'pageCode','page','pageSize','limit','keyword','topFilters','filters','filterRelation',
            'sorts','groups','groupBy','summaries','dataScope','businessStatus','quickFilters',
            'visibleFields','export','fieldVersions','keywordFilters','topFilterConditions',
        ]));
        $rows = $this->authorizedSourceRows($context);
        $rows = $this->attachMemberInitials($rows, $memberInitials);
        $payload = $this->identityFilters($payload);
        if (empty($payload['sorts'])) {
            $dates = ['all'=>'event_completed_at','sales'=>'payment_completed_at','recharge'=>'payment_completed_at','refund'=>'refund_completed_at',
                'debt'=>'created_at','service'=>'service_completed_at','supplement'=>'payment_completed_at','gift'=>'created_at','card_operation'=>'completed_at'];
            $payload['sorts'] = [['field'=>$dates[CashierV3OrderCenterUnifiedQueryContract::typeForPage($this->pageCode())], 'direction'=>'desc']];
        }
        return $this->execution->execute(
            $this->pageCode(),
            $rows,
            $definitions,
            $payload,
            $context,
            static function (array $row, array $scope): bool {
                // Rows are loaded only through the authoritative domain services with
                // a freshly rebuilt DataScope. This final gate keeps status selection
                // consistent without allowing an exported task to broaden that scope.
                // 状态代码已在权威 Reader 中执行，不能再与中文展示标签比较。
                return true;
            },
            $memberInitials === '' ? null : static function (array $row) use ($memberInitials): bool {
                return strncmp((string)($row['_member_name_initials'] ?? ''), $memberInitials, strlen($memberInitials)) === 0;
            }
        );
    }

    /** 只解释综合查询的标准关键词组，不把高级筛选或客户端自造字段当成会员搜索。 */
    protected function memberInitialKeyword(array $payload): string
    {
        $keyword = trim((string)($payload['keyword'] ?? ''));
        $filters = (array)($payload['keywordFilters'] ?? []);
        if ($keyword !== '' && $filters) {
            return '';
        }
        if ($keyword === '' && $filters) {
            $expected = (array)CashierV3OrderCenterUnifiedQueryContract::definition($this->pageCode())['keywordFields'];
            if (count($filters) !== count($expected)) {
                return '';
            }
            $values = [];
            foreach ($filters as $filter) {
                if (!is_array($filter) || (string)($filter['operator'] ?? '') !== 'contains'
                    || !is_scalar($filter['value'] ?? null)) {
                    return '';
                }
                $field = (string)($filter['field_key'] ?? ($filter['fieldKey'] ?? ($filter['field'] ?? '')));
                if (!in_array($field, $expected, true) || isset($values[$field])) {
                    return '';
                }
                $values[$field] = (string)$filter['value'];
            }
            if (count($values) !== count($expected) || count(array_unique(array_values($values), SORT_STRING)) !== 1) {
                return '';
            }
            $keyword = trim((string)reset($values));
        }
        return preg_match('/^[A-Za-z]{1,60}$/D', $keyword) === 1 ? strtoupper($keyword) : '';
    }

    /** 候选订单已被权威 Reader 按门店裁剪；只按这些会员 ID 批读可重建索引，不改历史姓名快照。 */
    protected function attachMemberInitials(array $rows, string $keyword): array
    {
        if ($keyword === '' || !$rows) {
            return $rows;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'memberId')))));
        $initials = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $initials += Db::name('member_name_initial_search')->whereIn('uid', $batch)->column('initials', 'uid');
        }
        foreach ($rows as &$row) {
            $row['_member_name_initials'] = (string)($initials[(int)($row['memberId'] ?? 0)] ?? '');
        }
        unset($row);
        return $rows;
    }

    protected function authorizedSourceRows(array $context): array
    {
        [$operatorScope, $dataScope] = $this->liveScopes ?? $this->authoritativeScopes($context);
        $type = CashierV3OrderCenterUnifiedQueryContract::typeForPage($this->pageCode());
        if ($type === 'all') return $this->allBusinessRows($operatorScope, $dataScope);
        $records = $type === 'sales'
            ? $this->allSalesRows($operatorScope, $dataScope)
            : $this->allRecordRows($type, $operatorScope, $dataScope);
        if (count($records) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE',
                '当前导出数据量超过安全上限，请使用查询条件缩小范围后再导出。',
                ['max_rows' => UnifiedQueryExecutionServices::MAX_SOURCE_ROWS]
            );
        }
        // 消费订单与全部订单复用同一逐明细分类解析，筛选、列表及导出才不会出现两套口径。
        if ($type === 'sales') $records = $this->salesQueries->attachCurrentItemCategories($records);
        return (new CashierV3OrderCenterQueryIdentities())->attach($type, $this->projectRows($type, $records), (string)$operatorScope->tenantId());
    }

    /** 先按同一可信门店范围读取四类事实，再由统一引擎全局筛选/排序/分页；不拼接各业务的第一页。 */
    protected function allBusinessRows($operatorScope, $dataScope): array
    {
        $rows = [];
        $identities = new CashierV3OrderCenterQueryIdentities();
        foreach (['sales' => '消费', 'recharge' => '充值', 'supplement' => '补交', 'refund' => '退款'] as $type => $label) {
            // 类型等值条件来自顶栏的 AND 过滤；未选类型仍读取全部，不能按单号前缀猜测业务。
            if (isset($this->sourcePayload['_allOrderTypes']) && !in_array($label, $this->sourcePayload['_allOrderTypes'], true)) continue;
            $records = $type === 'sales' ? $this->allSalesRows($operatorScope, $dataScope)
                : $this->allRecordRows($type, $operatorScope, $dataScope);
            if ($type === 'sales') $records = $this->salesQueries->attachCurrentItemCategories($records);
            $projected = $identities->attach($type, $this->projectRows($type, $records), (string)$operatorScope->tenantId());
            foreach ($projected as $row) {
                // ID 保留给原业务命令，record_id 用于跨类型的排序和页面行键。
                $row['recordType'] = $type;
                $row['business_type'] = $label;
                $row['order_no'] = $row[$type === 'sales' ? 'sales_order_no' : $type . '_order_no'];
                $row['event_completed_at'] = $row[$type === 'refund' ? 'refund_completed_at' : 'payment_completed_at'];
                $row = self::mixedDisplayFields($type, $row);
                $rows[] = $row;
            }
            if (count($rows) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) {
                throw new UnifiedQueryException('UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE', '查询范围超过安全上限，请缩小日期范围。', []);
            }
        }
        $this->sourcePage = ['recordType' => 'all', 'dataStatus' => 'ready', 'dataAsOf' => date('c'), 'statusOptions' => []];
        return $rows;
    }

    /** 混合页的通用列与其列表展示读取同一业务字段；专用列及原始金额保持不变。 */
    private static function mixedDisplayFields(string $type, array $row): array
    {
        if ($type === 'sales') return $row;
        $nameField = $type === 'recharge' ? 'recharge_plan' : 'product_name';
        $amountField = $type . '_amount';
        $row['item_name'] = $row[$nameField] ?? null;
        $row['line_amount'] = $row[$amountField] ?? null;
        if ($type === 'refund') {
            $row['payment_method'] = $row['refund_method'] ?? null;
            $row['order_status'] = $row['refund_status'] ?? null;
        } elseif ($type === 'supplement') {
            $row['order_status'] = $row['payment_status'] ?? null;
        }
        return $row;
    }

    protected function allSalesRows($operatorScope, $dataScope): array
    {
        $records = [];
        $cursor = '';
        $page = 1;
        do {
            $payload = array_replace($this->sourcePayload, ['recordType' => 'sales', 'page' => $page, 'pageSize' => 100]);
            // 混合页的正常/全部范围同样下推至销售 Reader，不能让作废单混入正常列表。
            $payload['status'] = ($payload['dataScope'] ?? 'normal') === 'normal' ? 'normal' : ($payload['businessStatus'] ?? '');
            if ($cursor !== '') $payload['queryCursor'] = $cursor;
            $result = $this->salesQueries->querySalesOrders($payload, $operatorScope, $dataScope);
            if ($page === 1) $this->sourcePage = $result;
            if ((int)$result['total'] > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) {
                throw new UnifiedQueryException('UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE', '查询范围超过安全上限，请缩小日期范围。', []);
            }
            foreach ((array)($result['records'] ?? []) as $record) if (is_array($record)) $records[] = $record;
            if (count($records) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) break;
            $cursor = trim((string)($result['paginationCursor']['next'] ?? ''));
            $page++;
        } while ($cursor !== '');
        return $records;
    }

    protected function allRecordRows(string $type, $operatorScope, $dataScope): array
    {
        $records = [];
        $page = 1;
        do {
            $result = $this->recordQueries->queryRecords(array_replace($this->sourcePayload, [
                'recordType' => $type,
                'page' => $page,
                'pageSize' => 100,
            ]), $operatorScope, $dataScope);
            if ($page === 1) $this->sourcePage = $result;
            foreach ((array)($result['records'] ?? []) as $record) if (is_array($record)) $records[] = $record;
            $total = (int)($result['total'] ?? count($records));
            if ($total > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) {
                throw new UnifiedQueryException('UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE', '查询范围超过安全上限，请缩小日期范围。', []);
            }
            if (count($records) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) break;
            if (empty($result['records'])) break;
            $page++;
        } while (count($records) < $total);
        return $records;
    }

    /** 只下推恒为 AND 的业务日期/授权范围；组合 OR 不能提前裁剪丢失候选。 */
    private function sourceQuery(array $payload): array
    {
        $source = array_intersect_key($payload, array_flip(['storeIds','store_ids','memberId','member_id',
            'dateFrom','dateTo','businessDateFrom','businessDateTo','salesPerformanceDrilldown','servicePerformanceDrilldown']));
        foreach (($payload['topFilters'] ?? $payload['topFilterConditions'] ?? []) as $filter) {
            $key = $filter['field'] ?? $filter['field_key'] ?? $filter['fieldKey'] ?? '';
            if ($key === 'business_type' && in_array($filter['operator'] ?? '', ['eq', 'equal'], true)
                && is_scalar($filter['value'] ?? null)) {
                // 仅下推严格等值，保留统一引擎的原始过滤作最终判定；高级 OR 条件不提前裁剪。
                $allowed = $source['_allOrderTypes'] ?? ['消费', '充值', '补交', '退款'];
                $source['_allOrderTypes'] = array_values(array_intersect($allowed, [(string)$filter['value']]));
            }
            if ($key !== 'business_date') continue;
            $op = $filter['operator'] ?? '';
            if (in_array($op, ['gte','greater_or_equal','eq','equal'], true)) $source['dateFrom'] = $filter['value'];
            if (in_array($op, ['lte','less_or_equal','eq','equal'], true)) $source['dateTo'] = $filter['value'];
        }
        $source['dataScope'] = $payload['dataScope'] ?? 'normal';
        $source['businessStatus'] = $payload['businessStatus'] ?? '';
        if (CashierV3OrderCenterUnifiedQueryContract::typeForPage($this->pageCode()) === 'sales') {
            $source['status'] = $source['dataScope'] === 'normal' ? 'normal' : $source['businessStatus'];
        }
        return $source;
    }

    /** 数字选择值只能解释为身份；姓名文本仍支持旧组合文本条件，绝不反查姓名成 ID。 */
    private function identityFilters(array $payload): array
    {
        foreach (['topFilters','topFilterConditions','filters','quickFilters'] as $group) {
            if (!isset($payload[$group])) continue;
            foreach ($payload[$group] as &$filter) {
                $keyName = isset($filter['fieldKey']) ? 'fieldKey' : (isset($filter['field_key']) ? 'field_key' : 'field');
                $field = $filter[$keyName] ?? '';
                if (!in_array($field, ['salesperson','cashier','operator','craftsman','sales_manager','guide','void_operator','store'], true)) continue;
                $values = (array)($filter['value'] ?? []);
                if (!$values) continue;
                $numeric = true;
                foreach ($values as $value) if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string)$value)) $numeric = false;
                if (!$numeric) continue;
                if (!in_array($filter['operator'] ?? '', ['eq','equal','neq','not_equal','in'], true)) {
                    throw new UnifiedQueryException('UNIFIED_QUERY_PERSON_OPERATOR_INVALID', '人员和门店请选择等于、不等于或多选条件。', []);
                }
                $filter[$keyName] = $field . '_query_ids';
            }
            unset($filter);
        }
        return $payload;
    }

    protected function projectRows(string $type, array $records): array
    {
        $aliases = [
            'sales_order_no' => ['salesOrderNo', 'orderNo'], 'business_date' => ['businessDate'], 'member_name' => ['memberName'],
            'store' => ['storeName', 'businessStoreName'], 'item_summary' => ['itemSummary'], 'item_count' => ['itemCount'],
            'receivable_amount' => ['receivableAmount', 'payableAmount'], 'discount_amount' => ['discountAmount'], 'debt_amount' => ['debtAmount', 'outstandingDebtAmount'],
            'coupon_discount_amount' => ['couponDiscountAmount'],
            'actual_received_amount' => ['actualReceivedAmount'], 'payment_method' => ['paymentSummary', 'paymentMethod'], 'salesperson' => ['salespersonSummary', 'salespersonName'],
            'cashier' => ['cashierName', 'operatorName'], 'source' => ['source', 'sourceLabel', 'sourceSecondary', 'sourcePrimary'],
            'payment_status' => ['paymentStatus'], 'order_status' => ['orderStatus', 'statusLabel'], 'supplement' => ['supplementLabel'],
            'occurred_at' => ['occurredAt'], 'payment_completed_at' => ['paymentCompletedAt', 'completedAt'], 'recharge_order_no' => ['rechargeOrderNo', 'orderNo'],
            'recharge_plan' => ['rechargePlan', 'planName'], 'recharge_amount' => ['rechargeAmount'], 'gift_amount' => ['giftAmount'],
            'operator' => ['operatorName', 'operator'], 'refund_order_no' => ['refundOrderNo', 'refundNo'], 'source_order_no' => ['sourceOrderNo'],
            'refund_summary' => ['refundSummary', 'summary'], 'product_category' => ['productCategory'],
            'refund_amount' => ['refundAmount'], 'refund_attribution' => ['refundAttributionSummary'], 'refund_method' => ['refundMethod'],
            'supplement_attribution' => ['supplementAttributionSummary'],
            'refund_status' => ['refundStatus', 'statusLabel'], 'refund_completed_at' => ['refundCompletedAt', 'completedAt'],
            'debt_no' => ['debtNo'], 'source_type' => ['sourceType'], 'product_name' => ['productName'], 'original_debt_amount' => ['originalDebtAmount', 'totalDebtAmount'],
            'repaid_amount' => ['repaidAmount'], 'remaining_amount' => ['remainingAmount'], 'debt_status' => ['debtStatus', 'statusLabel'], 'created_at' => ['createdAt'],
            'service_record_no' => ['serviceRecordNo', 'serviceFactId'], 'service_project' => ['serviceProject', 'projectName'],
            'entitlement_source' => ['entitlementSource'], 'source_card' => ['sourceCard', 'sourceCardName'], 'source_card_no' => ['sourceCardNo', 'sourceCode'],
            'used_times' => ['usedTimes', 'quantity'], 'craftsman' => ['craftsmenSummary', 'craftsmen'],
            'labor_fee_amount' => ['laborFeeAmount', 'manualLaborFeeAmount'],
            'labor_performance_type' => ['laborPerformanceTypeLabel', 'laborPerformanceType'],
            'labor_performance_ratio' => ['laborPerformanceRatio'],
            'consumption_performance_amount' => ['consumptionPerformanceAmount'],
            'labor_performance_amount' => ['laborPerformanceAmount'],
            'service_status' => ['serviceStatus'], 'service_completed_at' => ['serviceCompletedAt', 'completedAt'],
            'voided_at' => ['voidedAt'], 'void_reason' => ['voidReason'], 'void_operator' => ['voidOperatorName'],
            'supplement_order_no' => ['supplementOrderNo', 'repayNo', 'orderNo'], 'debt_summary' => ['debtSummary', 'summary'],
            'supplement_amount' => ['supplementAmount', 'repayAmount'], 'gift_record_no' => ['giftRecordNo', 'giftNo'], 'gift_source' => ['giftSource'],
            'gift_type' => ['giftType', 'typeLabel'], 'gift_content' => ['giftContent', 'contentSummary'], 'gift_quantity' => ['giftQuantity', 'quantity'],
            'effective_at' => ['effectiveAt'], 'expires_at' => ['expiresAt'], 'gift_status' => ['giftStatus', 'statusLabel'], 'gift_reason' => ['giftReason', 'reason'],
            'card_operation_no' => ['cardOperationNo', 'operationNo'], 'operation_type' => ['operationTypeLabel', 'operationType'],
            'target_content' => ['targetContent', 'targetCardName', 'targetProjectName'], 'operation_amount' => ['operationAmount', 'amount'],
            'record_status' => ['recordStatus', 'statusLabel', 'status'], 'operation_reason' => ['operationReason', 'reason'], 'completed_at' => ['completedAt'],
            'project_count' => ['projectCount'], 'detail_remark' => ['detailRemark'],
        ];
        $definition = CashierV3OrderCenterUnifiedQueryContract::definition($this->pageCode());
        $fields = array_column($definition['fields'], 'key');
        $types = array_column($definition['fields'], 'type', 'key');
        $rows = [];
        foreach ($records as $index => $record) {
            $row = $record;
            foreach ($fields as $field) {
                $row[$field] = $this->firstValue($record, array_merge($aliases[$field] ?? [], [$field]));
                // 未就绪的金额/日期不是零，保留空值语义，避免区间筛选把缺失值当零。
                if ($row[$field] === '' && in_array($types[$field], ['integer','decimal','amount','date','datetime'], true)) $row[$field] = null;
            }
            if ($type === 'sales') {
                // 直接使用表格的权威明细，不重算价格、不把权益价值计入整单收款。
                foreach (['item_name','product_category','unit_price','quantity','line_amount','craftsman','sales_manager','guide'] as $key) $row[$key] = [];
                foreach ($record['items'] ?? [] as $item) {
                    foreach (['item_name'=>'name','unit_price'=>'unitPrice','quantity'=>'quantity'] as $key=>$source) {
                        if (isset($item[$source]) && $item[$source] !== '') $row[$key][] = $item[$source];
                    }
                    $row['product_category'][] = (string)($item['category'] ?? '未分类');
                    // 导出与表格同取用券前金额；权益行仍展示独立核销价值。
                    $amount = $item[($item['businessTag'] ?? '') === '权益' ? 'entitlementAmount' : 'amountBeforeCoupon'] ?? null;
                    if ($amount !== null) $row['line_amount'][] = $amount;
                    foreach (['craftsman'=>'craftsmenListAllocations','sales_manager'=>'salesManagers','guide'=>'guides'] as $key=>$source) {
                        $people = $item[$source] ?? ($key === 'craftsman' ? ($item['craftsmen'] ?? []) : []);
                        foreach ($people as $person) {
                            $name = $person['employeeName'] ?? $person['name'] ?? '';
                            if ($name !== '') $row[$key][] = $name;
                        }
                    }
                }
                if (is_array($row['items'] ?? null)) {
                    foreach ($row['items'] as &$item) unset($item['_categoryProductIds']);
                    unset($item);
                }
            }
            if (in_array($type, ['refund', 'supplement'], true)) {
                foreach (['guide', 'sales_manager'] as $role) {
                    $row[$role] = [];
                    foreach ($record['attributionPeople'] ?? [] as $person) {
                        if (($person['role'] ?? '') !== $role) continue;
                        $name = (string)($person['employee_name_snapshot'] ?? '');
                        $round = (int)($person['guide_round_no'] ?? 0);
                        $row[$role][] = $name . ($role === 'guide' && $round > 0 ? '（第' . $round . '轮）' : '');
                    }
                }
            }
            if (($row['supplement'] ?? null) === null) $row['supplement'] = ($record['isSupplement'] ?? null) === true ? '补单' : (($record['isSupplement'] ?? null) === false ? '正常办理' : '');
            $row['record_id'] = $type . ':' . (string)($record['id'] ?? $row[$definition['keywordFields'][0]] ?? $index + 1);
            $row['_business_status'] = $this->firstValue($row, ['order_status', 'refund_status', 'debt_status', 'service_status', 'payment_status', 'gift_status', 'record_status']) ?: '';
            $rows[] = $row;
        }
        return $rows;
    }

    protected function publicRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            foreach (array_keys($row) as $key) if (strpos((string)$key, '_') === 0 || substr((string)$key, -10) === '_query_ids') unset($row[$key]);
            return $row;
        }, $rows);
    }

    protected function firstValue(array $record, array $keys)
    {
        foreach ($keys as $key) if (array_key_exists($key, $record) && $record[$key] !== null && $record[$key] !== '') return $record[$key];
        return '';
    }

    protected function definitionsForQuery(array $context, array $payload): array
    {
        $candidate = $payload;
        unset($candidate['fieldVersions'], $candidate['field_versions']);
        $keys = (new UnifiedQueryCustomFieldKeyCollector())->collectFromQuery($candidate);
        if (!$keys) return [];
        $preference = $this->preferences->load($context, $this->pageCode());
        $pinned = (array)($preference['customFieldVersions'] ?? []);
        $visible = [];
        foreach ($this->customFields->listVisible($context, $this->pageCode(), true) as $field) $visible[(string)$field['key']] = $field;
        $definitions = [];
        foreach ($keys as $fieldKey) {
            if (!isset($visible[$fieldKey])) throw new UnifiedQueryException('UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE', '查询使用的自定义字段已不可用，请重新设置。', ['field_key' => $fieldKey]);
            $definition = $this->customFields->versionDefinition($context, $this->pageCode(), $fieldKey, (int)($pinned[$fieldKey] ?? $visible[$fieldKey]['version'] ?? 0));
            $definition['page_code'] = $this->pageCode();
            $definitions[] = $definition;
        }
        return $definitions;
    }

    protected function authoritativeScopes(array $context): array
    {
        // 列表使用请求内的可信 scope；后台任务必须按领取任务时识别出的同一
        // 员工/选店会话再构建 Reader scope，避免数字 ID 碰撞导致空文件。
        return (new CashierV3OrderCenterUnifiedQueryWorkerContextResolver())->scopesForContext($context);
    }
}
