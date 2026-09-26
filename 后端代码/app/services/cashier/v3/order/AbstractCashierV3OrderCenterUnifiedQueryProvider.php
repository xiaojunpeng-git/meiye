<?php

namespace app\services\cashier\v3\order;

use app\model\store\SystemStoreStaff;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
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
        $this->sourcePayload = $this->sourceQuery($payload);
        // 领域日期/权限/下钻参数由源 Reader 消费；不传入通用表达式白名单。
        $payload = array_intersect_key($payload, array_flip([
            'pageCode','page','pageSize','limit','keyword','topFilters','filters','filterRelation',
            'sorts','groups','groupBy','summaries','dataScope','businessStatus','quickFilters',
            'visibleFields','export','fieldVersions','keywordFilters','topFilterConditions',
        ]));
        $rows = $this->authorizedSourceRows($context);
        $payload = $this->identityFilters($payload);
        if (empty($payload['sorts'])) {
            $dates = ['sales'=>'payment_completed_at','recharge'=>'payment_completed_at','refund'=>'refund_completed_at',
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
            }
        );
    }

    protected function authorizedSourceRows(array $context): array
    {
        [$operatorScope, $dataScope] = $this->liveScopes ?? $this->authoritativeScopes($context);
        $type = CashierV3OrderCenterUnifiedQueryContract::typeForPage($this->pageCode());
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
        return (new CashierV3OrderCenterQueryIdentities())->attach($type, $this->projectRows($type, $records), (string)$operatorScope->tenantId());
    }

    protected function allSalesRows($operatorScope, $dataScope): array
    {
        $records = [];
        $cursor = '';
        $page = 1;
        do {
            $payload = array_replace($this->sourcePayload, ['recordType' => 'sales', 'page' => $page, 'pageSize' => 100]);
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
            'actual_received_amount' => ['actualReceivedAmount'], 'payment_method' => ['paymentSummary', 'paymentMethod'], 'salesperson' => ['salespersonSummary', 'salespersonName'],
            'cashier' => ['cashierName', 'operatorName'], 'source' => ['source', 'sourceLabel', 'sourceSecondary', 'sourcePrimary'],
            'payment_status' => ['paymentStatus'], 'order_status' => ['orderStatus', 'statusLabel'], 'supplement' => ['supplementLabel'],
            'occurred_at' => ['occurredAt'], 'payment_completed_at' => ['paymentCompletedAt', 'completedAt'], 'recharge_order_no' => ['rechargeOrderNo', 'orderNo'],
            'recharge_plan' => ['rechargePlan', 'planName'], 'recharge_amount' => ['rechargeAmount'], 'gift_amount' => ['giftAmount'],
            'operator' => ['operatorName', 'operator'], 'refund_order_no' => ['refundOrderNo', 'refundNo'], 'source_order_no' => ['sourceOrderNo'],
            'refund_summary' => ['refundSummary', 'summary'], 'refund_amount' => ['refundAmount'], 'refund_method' => ['refundMethod'],
            'refund_status' => ['refundStatus', 'statusLabel'], 'refund_completed_at' => ['refundCompletedAt', 'completedAt'],
            'debt_no' => ['debtNo'], 'source_type' => ['sourceType'], 'original_debt_amount' => ['originalDebtAmount', 'totalDebtAmount'],
            'repaid_amount' => ['repaidAmount'], 'remaining_amount' => ['remainingAmount'], 'debt_status' => ['debtStatus', 'statusLabel'], 'created_at' => ['createdAt'],
            'service_record_no' => ['serviceRecordNo', 'serviceFactId'], 'service_project' => ['serviceProject', 'projectName'],
            'entitlement_source' => ['entitlementSource'], 'source_card' => ['sourceCard', 'sourceCardName'], 'source_card_no' => ['sourceCardNo', 'sourceCode'],
            'used_times' => ['usedTimes', 'quantity'], 'craftsman' => ['craftsmenSummary', 'craftsmen'],
            'labor_fee_amount' => ['laborFeeAmount', 'manualLaborFeeAmount'],
            'labor_performance_type' => ['laborPerformanceTypeLabel', 'laborPerformanceType'],
            'labor_performance_ratio' => ['laborPerformanceRatio'],
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
                foreach (['item_name','unit_price','quantity','line_amount','craftsman','sales_manager','guide'] as $key) $row[$key] = [];
                foreach ($record['items'] ?? [] as $item) {
                    foreach (['item_name'=>'name','unit_price'=>'unitPrice','quantity'=>'quantity'] as $key=>$source) {
                        if (isset($item[$source]) && $item[$source] !== '') $row[$key][] = $item[$source];
                    }
                    $amount = $item[($item['businessTag'] ?? '') === '权益' ? 'entitlementAmount' : 'payableAmount'] ?? null;
                    if ($amount !== null) $row['line_amount'][] = $amount;
                    foreach (['craftsman'=>'craftsmenListAllocations','sales_manager'=>'salesManagers','guide'=>'guides'] as $key=>$source) {
                        $people = $item[$source] ?? ($key === 'craftsman' ? ($item['craftsmen'] ?? []) : []);
                        foreach ($people as $person) {
                            $name = $person['employeeName'] ?? $person['name'] ?? '';
                            if ($name !== '') $row[$key][] = $name;
                        }
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
        $operatorId = (int)($context['operator_id'] ?? 0);
        $storeId = (int)($context['store_id'] ?? 0);
        $tenantId = trim((string)($context['tenant_id'] ?? ''));
        $staff = SystemStoreStaff::where('id', $operatorId)->where('status', 1)->where('is_del', 0)->find();
        if (!$staff || $storeId <= 0 || $tenantId === '') throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '导出账号或门店已失效，任务已停止。', []);
        $profile = $staff->toArray();
        $employeeId = (int)($profile['employee_id'] ?? 0);
        if ($employeeId > 0 && !Db::name('employee')->where('id', $employeeId)->where('status', 1)->where('is_del', 0)->find()) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '导出员工档案已停用，任务已停止。', []);
        }
        $dispatcher = CashierV3Bootstrap::dispatcher();
        $operatorScope = $dispatcher->scopeResolver()->operatorScope($storeId, $operatorId);
        if (!hash_equals($operatorScope->tenantId(), $tenantId)) throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '导出任务所属商户与当前账号不一致，任务已停止。', []);
        return [$operatorScope, $dispatcher->dataScopeFactory()->build($storeId, $operatorId, $profile, $tenantId, $operatorScope->organizationId())];
    }
}
