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
 * 将订单中心现有的权威只读投影接入统一查询导出。
 *
 * 列表继续走原有分页投影；导出 worker 以当前账号、门店和 DataScope 重建权限后，
 * 再读取同一服务并执行被冻结的统一查询计划。不会相信任务中保存的门店范围。
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

    public function __construct(
        UnifiedQueryExecutionServices $execution,
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

    public function query(array $context, array $payload): array
    {
        $result = $this->run($context, $payload, $this->definitionsForQuery($context, $payload));
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
        return $this->run($context, $query, (array)($plan['custom_definitions'] ?? []));
    }

    /** @return array<string,mixed> */
    protected function run(array $context, array $payload, array $definitions): array
    {
        $payload['pageCode'] = $this->pageCode();
        unset($payload['queryCutoffDate'], $payload['query_cutoff_date']);
        return $this->execution->execute(
            $this->pageCode(),
            $this->authorizedSourceRows($context),
            $definitions,
            $payload,
            $context,
            static function (array $row, array $scope): bool {
                // Rows are loaded only through the authoritative domain services with
                // a freshly rebuilt DataScope. This final gate keeps status selection
                // consistent without allowing an exported task to broaden that scope.
                $requested = trim((string)($scope['requested_business_status'] ?? ''));
                return $requested === '' || $requested === (string)($row['_business_status'] ?? '');
            }
        );
    }

    protected function authorizedSourceRows(array $context): array
    {
        [$operatorScope, $dataScope] = $this->authoritativeScopes($context);
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
        return $this->projectRows($type, $records);
    }

    protected function allSalesRows($operatorScope, $dataScope): array
    {
        $records = [];
        $cursor = '';
        $page = 1;
        do {
            $payload = ['recordType' => 'sales', 'page' => $page, 'pageSize' => 100];
            if ($cursor !== '') $payload['queryCursor'] = $cursor;
            $result = $this->salesQueries->querySalesOrders($payload, $operatorScope, $dataScope);
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
            $result = $this->recordQueries->queryRecords([
                'recordType' => $type,
                'page' => $page,
                'pageSize' => 100,
            ], $operatorScope, $dataScope);
            foreach ((array)($result['records'] ?? []) as $record) if (is_array($record)) $records[] = $record;
            $total = (int)($result['total'] ?? count($records));
            if (count($records) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) break;
            if (empty($result['records'])) break;
            $page++;
        } while (count($records) < $total);
        return $records;
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
            'payment_completed_at' => ['paymentCompletedAt', 'completedAt'], 'recharge_order_no' => ['rechargeOrderNo', 'orderNo'],
            'recharge_plan' => ['rechargePlan', 'planName'], 'recharge_amount' => ['rechargeAmount'], 'gift_amount' => ['giftAmount'],
            'operator' => ['operatorName', 'operator'], 'refund_order_no' => ['refundOrderNo', 'refundNo'], 'source_order_no' => ['sourceOrderNo'],
            'refund_summary' => ['refundSummary', 'summary'], 'refund_amount' => ['refundAmount'], 'refund_method' => ['refundMethod'],
            'refund_status' => ['refundStatus', 'statusLabel'], 'refund_completed_at' => ['refundCompletedAt', 'completedAt'],
            'debt_no' => ['debtNo'], 'source_type' => ['sourceType'], 'original_debt_amount' => ['originalDebtAmount', 'totalDebtAmount'],
            'repaid_amount' => ['repaidAmount'], 'remaining_amount' => ['remainingAmount'], 'debt_status' => ['debtStatus', 'statusLabel'], 'created_at' => ['createdAt'],
            'service_record_no' => ['serviceRecordNo', 'serviceFactId'], 'service_project' => ['serviceProject', 'projectName'],
            'entitlement_source' => ['entitlementSource'], 'source_card' => ['sourceCard', 'sourceCardName'], 'source_card_no' => ['sourceCardNo', 'sourceCode'],
            'used_times' => ['usedTimes', 'quantity'], 'craftsman' => ['craftsmenSummary', 'craftsmen'], 'labor_performance_amount' => ['laborPerformanceAmount'],
            'service_status' => ['serviceStatus'], 'service_completed_at' => ['serviceCompletedAt', 'completedAt'],
            'supplement_order_no' => ['supplementOrderNo', 'repayNo', 'orderNo'], 'debt_summary' => ['debtSummary', 'summary'],
            'supplement_amount' => ['supplementAmount', 'repayAmount'], 'gift_record_no' => ['giftRecordNo', 'giftNo'], 'gift_source' => ['giftSource'],
            'gift_type' => ['giftType', 'typeLabel'], 'gift_content' => ['giftContent', 'contentSummary'], 'gift_quantity' => ['giftQuantity', 'quantity'],
            'effective_at' => ['effectiveAt'], 'expires_at' => ['expiresAt'], 'gift_status' => ['giftStatus', 'statusLabel'], 'gift_reason' => ['giftReason', 'reason'],
            'card_operation_no' => ['cardOperationNo', 'operationNo'], 'operation_type' => ['operationTypeLabel', 'operationType'],
            'target_content' => ['targetContent', 'targetCardName', 'targetProjectName'], 'operation_amount' => ['operationAmount', 'amount'],
            'record_status' => ['recordStatus', 'statusLabel', 'status'], 'operation_reason' => ['operationReason', 'reason'], 'completed_at' => ['completedAt'],
        ];
        $definition = CashierV3OrderCenterUnifiedQueryContract::definition($this->pageCode());
        $fields = array_column($definition['fields'], 'key');
        $rows = [];
        foreach ($records as $index => $record) {
            $row = $record;
            foreach ($fields as $field) $row[$field] = $this->firstValue($record, array_merge($aliases[$field] ?? [], [$field]));
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
            foreach (array_keys($row) as $key) if (strpos((string)$key, '_') === 0) unset($row[$key]);
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
