<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\query\UnifiedQueryCustomFieldKeyCollector;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\UnifiedQueryProvider;
use think\facade\Db;

/** Shared UQ adapter for V3 operational read models. */
abstract class InventoryOperationalUnifiedQueryProvider implements UnifiedQueryProvider
{
    private $execution;
    private $customFields;
    private $preferences;

    public function __construct(UnifiedQueryExecutionServices $execution, UnifiedQueryCustomFieldServices $customFields, UnifiedQueryPreferenceServices $preferences)
    {
        $this->execution = $execution;
        $this->customFields = $customFields;
        $this->preferences = $preferences;
    }

    abstract public function pageCode(): string;

    public function query(array $context, array $payload): array
    {
        $payload['pageCode'] = $this->pageCode();
        $plan = $this->execution->validatedPlan($this->pageCode(), $this->customDefinitions($context, $payload), $payload, $context);
        $result = $this->execute($context, $this->payloadFromPlan($plan), (array)$plan['custom_definitions']);
        return [
            'records' => $result['rows'], 'total' => (int)$result['pagination']['total'],
            'page' => (int)$result['pagination']['page'], 'pageSize' => (int)$result['pagination']['limit'],
            'querySettings' => $this->preferences->load($context, $this->pageCode()),
            'summaries' => $result['summaries'], 'groups' => $result['groups'],
            'queryCutoffDate' => $result['queryCutoffDate'], 'dataAsOf' => $result['dataAsOf'],
            'metricVersion' => 'inventory-operational-2026-08-02-v1', 'aggregationCaughtUp' => true,
            'consistencyFingerprint' => $result['consistencyFingerprint'], 'security' => $result['security'],
        ];
    }

    public function executeFrozenPlan(array $context, array $plan, string $exportScope, array $fieldKeys): array
    {
        if ((string)($plan['page_code'] ?? '') !== $this->pageCode() || empty($plan['permission_must_be_injected_before_calculation'])) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PLAN_INVALID', '库存业务导出计划不合法。', []);
        }
        $plan['visible_fields'] = array_values(array_unique(array_map('strval', $fieldKeys)));
        $payload = $this->payloadFromPlan($plan);
        $payload['export'] = ['scope' => $exportScope, 'fields' => $plan['visible_fields']];
        return $this->execute($context, $payload, (array)($plan['custom_definitions'] ?? []));
    }

    private function execute(array $context, array $payload, array $definitions): array
    {
        $rows = $this->sourceRows($context);
        return $this->execution->execute($this->pageCode(), $rows, $definitions, $payload, $context,
            static function (array $row) use ($context): bool {
                return (string)($row['tenant_id'] ?? '') === (string)$context['tenant_id']
                    && (int)($row['store_id'] ?? 0) === (int)$context['store_id'];
            });
    }

    protected function sourceRows(array $context): array
    {
        $storeId = (int)$context['store_id'];
        $tenantId = (string)$context['tenant_id'];
        $locationIds = array_values(array_unique(array_map('intval', (array)(($context['scope_dimensions'] ?? [])['location_id'] ?? []))));
        if (!$locationIds) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '库存业务查询缺少服务端仓库范围。', []);
        $canViewCost = in_array(InventoryBatchStockQueryContract::PERMISSION_COST, (array)$context['permissions'], true);
        switch ($this->pageCode()) {
            case 'inventory_inbound': $rows = $this->documentRows($tenantId, $storeId, $locationIds, 'manual_inbound', $canViewCost); break;
            case 'inventory_outbound': $rows = $this->documentRows($tenantId, $storeId, $locationIds, ['manual_outbound', 'presale_claim_outbound'], $canViewCost); break;
            case 'inventory_movement': $rows = $this->movementRows($tenantId, $storeId, $locationIds, $canViewCost); break;
            case 'inventory_count': $rows = $this->countRows($tenantId, $storeId, $locationIds, $canViewCost); break;
            case 'inventory_request': $rows = $this->requestRows($tenantId, $storeId, $locationIds, $canViewCost); break;
            case 'inventory_transfer': $rows = $this->transferRows($tenantId, $storeId, $locationIds, $canViewCost); break;
            case 'inventory_salon_usage': $rows = $this->usageRows($tenantId, $storeId, $locationIds); break;
            case 'inventory_import': $rows = $this->importRows($tenantId, $storeId); break;
            default: throw new \LogicException('inventory_operational_page_code_invalid');
        }
        $normalized = [];
        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : [];
            $row['record_id'] = $this->pageCode() . ':' . (string)($row['id'] ?? $row['order_sn'] ?? $row['usage_no'] ?? $index);
            $row['tenant_id'] = (string)$context['tenant_id'];
            $row['store_id'] = $storeId;
            $row['business_date'] = (string)($row['business_date'] ?? $row['transfer_date'] ?? $row['request_date'] ?? '');
            $row['order_sn'] = (string)($row['order_sn'] ?? $row['usage_no'] ?? '');
            $row['status_name'] = (string)($row['status_name'] ?? $row['document_status'] ?? '');
            $row['operation_at'] = (int)($row['operation_at'] ?? $row['occurred_at'] ?? $row['recorded_at'] ?? $row['add_time'] ?? 0);
            if ($this->pageCode() === 'inventory_request') {
                $row['request_party_name'] = (string)($row['request_party_name'] ?? $row['request_store_name'] ?? '');
                $row['supply_party_name'] = (string)($row['supply_party_name'] ?? $row['supply_store_name'] ?? '');
            }
            if ($this->pageCode() === 'inventory_salon_usage') {
                $row['operation_name'] = (string)($row['operation_type'] ?? '') === 'RETURN' ? '退回' : '领用';
            }
            if ($this->pageCode() === 'inventory_import') {
                $row['direction_name'] = (string)($row['direction'] ?? '') === 'inbound' ? '入库导入' : '出库导入';
                $row['status_name'] = ['SUCCEEDED' => '成功', 'FAILED' => '失败', 'PROCESSING' => '处理中'][(string)($row['status'] ?? '')] ?? '';
                $row['business_date'] = date('Y-m-d', (int)($row['updated_at'] ?? 0));
                $row['order_sn'] = (string)($row['document_no'] ?? '');
                $row['updated_at_display'] = (int)($row['updated_at'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int)$row['updated_at']) : '';
            }
            $normalized[] = $row;
        }
        return $normalized;
    }

    private function documentRows(string $tenantId, int $storeId, array $locationIds, $types, bool $canViewCost): array
    {
        $types = is_array($types) ? $types : [(string)$types];
        $rows = Db::name('inventory_batch_movement_fact')->alias('f')->leftJoin('inventory_batch b', 'b.id=f.batch_id')
            ->leftJoin('inventory_location l', 'l.id=f.location_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->leftJoin('cashier_v3_presale_claim pc', 'pc.tenant_id=f.tenant_id AND pc.claim_id=f.source_id AND f.source_type=\'presale_claim_outbound\'')->where('f.tenant_id', $tenantId)->where('f.store_id', $storeId)
            ->whereIn('f.location_id', $locationIds)->where('f.fact_status', 'SETTLED')->whereIn('f.source_type', $types)
            // Query expressions cannot be used as PHP array keys. Use one raw
            // projection so ThinkORM receives the SQL aliases directly.
            ->fieldRaw("f.source_id AS source_id, f.source_type AS source_type, MAX(pc.claim_status) AS presale_claim_status, COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, f.location_id, l.location_name, MAX(f.recorded_at) AS add_time, MAX(COALESCE(NULLIF(f.occurred_at, 0), f.recorded_at)) AS operation_at, COUNT(f.id) AS detail_count, SUM(f.cost_amount_cents) AS cost_amount_cents, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(b.product_name_snapshot, '商品'), ' / ', IFNULL(b.sku_name_snapshot, '默认规格')) SEPARATOR '、') AS product_summary")
            ->group('f.source_id,f.source_type,n.document_no,f.business_date,f.location_id,l.location_name')->order('add_time desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        foreach ($rows as &$row) {
            $row['source_id'] = (string)($row['source_id'] ?? '');
            $presale = (string)$row['source_type'] === 'presale_claim_outbound';
            $row['order_type_name'] = $types === ['manual_inbound'] ? '手工入库' : ($presale ? '预售领用出库' : '手工出库');
            if ($presale) { $row['status_name'] = (string)$row['presale_claim_status'] === 'VOIDED' ? '已作废' : '已完成'; $row['can_void'] = false; }
            if (!$canViewCost) $row['cost_amount_cents'] = null;
        }
        unset($row);
        if ($types === ['manual_inbound'] || $types === ['manual_outbound']) {
            $rows = (new \app\services\product\inventory\InventoryManualDocumentReversalProjectionServices())->apply($rows, $tenantId, $types[0], $locationIds);
        } elseif (in_array('manual_outbound', $types, true)) {
            $manualRows = [];
            foreach ($rows as $index => $row) if ((string)$row['source_type'] === 'manual_outbound') $manualRows[$index] = $row;
            $manualRows = (new \app\services\product\inventory\InventoryManualDocumentReversalProjectionServices())->apply(array_values($manualRows), $tenantId, 'manual_outbound', $locationIds);
            $manualIndex = 0;
            foreach ($rows as &$row) if ((string)$row['source_type'] === 'manual_outbound') $row = $manualRows[$manualIndex++];
            unset($row);
        }
        return $this->bounded($rows);
    }

    private function movementRows(string $tenantId, int $storeId, array $locationIds, bool $canViewCost): array
    {
        $rows = Db::name('inventory_batch_movement_fact')->alias('f')->leftJoin('inventory_batch b', 'b.id=f.batch_id')->leftJoin('inventory_stock s', 's.id=f.stock_id')->leftJoin('inventory_location l', 'l.id=f.location_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', $tenantId)->where('f.store_id', $storeId)->whereIn('f.location_id', $locationIds)->where('f.fact_status', 'SETTLED')
            ->fieldRaw('f.id, COALESCE(n.document_no, f.source_id) AS order_sn, f.source_type, f.business_date, f.occurred_at, f.recorded_at, COALESCE(NULLIF(f.occurred_at, 0), f.recorded_at) AS operation_at, f.direction, f.quantity_units, f.cost_amount_cents, f.location_id, s.quantity_scale, b.product_name_snapshot AS product_name, b.sku_name_snapshot AS sku_name, b.batch_no, l.location_name')->order('f.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        foreach ($rows as &$row) {
            $row['movement_type_name'] = $this->movementName((string)$row['source_type']);
            $scale = max(0, min(4, (int)($row['quantity_scale'] ?? 0))); $quantity = (int)$row['quantity_units'] / (10 ** $scale);
            $row['quantity_display'] = ((int)$row['direction'] > 0 ? '+' : '-') . rtrim(rtrim(number_format($quantity, $scale, '.', ''), '0'), '.');
            $row['status_name'] = '已完成'; if (!$canViewCost) $row['cost_amount_cents'] = null;
        }
        unset($row); return $this->bounded($rows);
    }

    private function countRows(string $tenantId, int $storeId, array $locationIds, bool $canViewCost): array
    {
        $rows = Db::name('inventory_stock_count_document')->alias('d')->leftJoin('inventory_stock_count_line l', 'l.document_id=d.id')->leftJoin('inventory_location p', 'p.id=d.location_id')
            ->where('d.tenant_id', $tenantId)->whereIn('d.location_id', $locationIds)
            ->field(['d.id', 'd.count_no' => 'order_sn', 'd.document_status' => 'status_name', 'd.business_date', 'd.recorded_at' => 'add_time', 'd.recorded_at' => 'operation_at', 'd.location_id', 'p.location_name', 'COUNT(l.id)' => 'detail_count'])
            ->group('d.id,d.count_no,d.document_status,d.business_date,d.recorded_at,d.location_id,p.location_name')->order('d.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        if ($canViewCost && $rows) {
            $amounts = Db::name('inventory_batch_movement_fact')->where('tenant_id', $tenantId)->where('store_id', $storeId)->whereIn('location_id', $locationIds)->where('fact_status', 'SETTLED')->whereIn('source_type', ['stock_count_gain', 'stock_count_loss'])
                ->field('source_id, SUM(CASE WHEN direction=1 THEN cost_amount_cents ELSE -cost_amount_cents END) change_amount_cents')->group('source_id')->select()->toArray();
            $index = array_column($amounts, 'change_amount_cents', 'source_id'); foreach ($rows as &$row) $row['change_amount_cents'] = (int)($index[(string)$row['order_sn']] ?? 0); unset($row);
        } else foreach ($rows as &$row) $row['change_amount_cents'] = null;
        unset($row); return $this->bounded($rows);
    }

    private function requestRows(string $tenantId, int $storeId, array $locationIds, bool $canViewCost): array
    {
        $rows = Db::name('inventory_stock_request_document')->alias('d')->leftJoin('inventory_stock_request_line l', 'l.document_id=d.id')->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', $tenantId)->where('d.store_id', $storeId)->whereIn('d.location_id', $locationIds)
            ->field('d.id,d.request_no,d.request_no order_sn,d.document_status status_name,d.business_date,d.recorded_at add_time,d.recorded_at operation_at,d.location_id,s.name request_store_name,d.supply_party_name_snapshot supply_party_name,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.reference_unit_cost_cents)/POW(10,l.quantity_scale)) estimated_amount_cents')
            ->group('d.id')->order('d.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        foreach ($rows as &$row) {
            $status = (string)$row['status_name'];
            $row['status_name'] = $this->requestStatusName($status);
            $row['can_edit'] = $status === 'APPLIED'
                && !(bool)Db::name('inventory_cross_transfer_document')
                    ->where('request_document_id', (int)$row['id'])
                    ->whereNotIn('document_status', ['CANCELLED', 'REVERSED'])
                    ->value('id');
            $row['can_cancel'] = $status === 'APPLIED';
            $row['can_terminate'] = $status === 'PARTIAL';
            if (!$canViewCost) $row['estimated_amount_cents'] = null;
        }
        unset($row);
        return $this->bounded($rows);
    }

    private function transferRows(string $tenantId, int $storeId, array $locationIds, bool $canViewCost): array
    {
        $rows = Db::name('inventory_cross_transfer_document')->alias('d')->leftJoin('inventory_cross_transfer_line l', 'l.document_id=d.id')->leftJoin('inventory_cross_transfer_batch_allocation a', 'a.line_id=l.id')
            ->where('d.tenant_id', $tenantId)->where(function ($query) use ($storeId, $locationIds): void { $query->where(function ($q) use ($storeId, $locationIds): void { $q->where('d.from_party_type', 'STORE')->where('d.from_party_id', $storeId)->whereIn('d.from_location_id', $locationIds); })->whereOr(function ($q) use ($storeId, $locationIds): void { $q->where('d.to_party_type', 'STORE')->where('d.to_party_id', $storeId)->whereIn('d.to_location_id', $locationIds); }); })
            ->field('d.id,d.transfer_no order_sn,d.document_status,d.document_status status_name,d.business_date,d.recorded_at add_time,d.recorded_at operation_at,d.from_party_type,d.from_party_id,d.from_location_id,d.to_party_type,d.to_party_id,d.to_location_id,d.initiator_store_id,d.from_party_name_snapshot from_party_name,d.to_party_name_snapshot to_party_name,COUNT(DISTINCT l.id) detail_count,SUM((a.quantity_units*a.unit_cost_cents)/POW(10,a.quantity_scale)) transfer_amount_cents')
            ->group('d.id')->order('d.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        foreach ($rows as &$row) {
            $fromCurrentStore = (string)$row['from_party_type'] === 'STORE'
                && (int)$row['from_party_id'] === $storeId
                && in_array((int)$row['from_location_id'], $locationIds, true);
            $toCurrentStore = (string)$row['to_party_type'] === 'STORE'
                && (int)$row['to_party_id'] === $storeId
                && in_array((int)$row['to_location_id'], $locationIds, true);
            $row['location_id'] = $fromCurrentStore ? (int)$row['from_location_id'] : (int)$row['to_location_id'];
            $row['status_name'] = $this->transferStatusName((string)$row['document_status']);
            $row['can_dispatch'] = (string)$row['document_status'] === 'DRAFT' && $fromCurrentStore;
            $row['can_receive'] = (string)$row['document_status'] === 'DISPATCHED' && $toCurrentStore;
            $row['can_cancel'] = (string)$row['document_status'] === 'DRAFT'
                && (int)$row['initiator_store_id'] === $storeId;
            $row['can_reverse'] = in_array((string)$row['document_status'], ['DISPATCHED', 'RECEIVED'], true)
                && $fromCurrentStore;
            if (!$canViewCost) $row['transfer_amount_cents'] = null;
            unset($row['from_party_type'], $row['from_party_id'], $row['from_location_id'], $row['to_party_type'], $row['to_party_id'], $row['to_location_id'], $row['initiator_store_id']);
        }
        unset($row); return $this->bounded($rows);
    }

    private function usageRows(string $tenantId, int $storeId, array $locationIds): array
    {
        return $this->bounded(Db::name('inventory_salon_usage_document')->alias('d')->leftJoin('inventory_salon_usage_line l', 'l.document_id=d.id')
            ->where('d.tenant_id', $tenantId)->where('d.store_id', $storeId)->whereIn('d.location_id', $locationIds)
            ->field('d.id,d.usage_no,d.operation_type,d.project_name_snapshot,d.location_id,d.document_status,d.business_date,d.recorded_at operation_at,d.remark,COUNT(l.id) detail_count')
            ->group('d.id')->order('d.business_date desc,d.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray());
    }

    private function importRows(string $tenantId, int $storeId): array
    {
        return $this->bounded(Db::name('inventory_v3_import_record')
            ->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->field('id,direction,source_file_name,business_type,total_count,success_count,failure_count,document_no,status,failure_message,updated_at')
            ->order('id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray());
    }

    private function bounded(array $rows): array { if (count($rows) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) throw new UnifiedQueryException('UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE', '当前数据量较大，请缩小业务日期范围后再查询。', []); return $rows; }
    private function movementName(string $type): string { return ['manual_inbound' => '手工入库', 'manual_outbound' => '手工出库', 'presale_claim_outbound' => '预售领用出库', 'presale_claim_void' => '预售领用作废退库', 'batch_transfer_in' => '调拨入库', 'batch_transfer_out' => '调拨出库', 'cross_transfer_in_reversal' => '调拨入库冲销', 'cross_transfer_out_reversal' => '调拨出库冲销', 'stock_count_gain' => '盘盈', 'stock_count_loss' => '盘亏', 'salon_usage_issue' => '院装领用', 'salon_usage_return' => '院装退回', 'completion_batch' => '项目耗材核销'][$type] ?? $type; }
    private function transferStatusName(string $status): string { return ['DRAFT' => '草稿', 'DISPATCHED' => '在途', 'RECEIVED' => '已收货', 'CANCELLED' => '已取消', 'REVERSED' => '已作废'][$status] ?? $status; }
    private function requestStatusName(string $status): string { return ['APPLIED' => '申请中', 'PARTIAL' => '部分履约', 'DONE' => '已完成', 'CANCELLED' => '已取消', 'TERMINATED' => '已终止剩余请货'][$status] ?? $status; }

    private function payloadFromPlan(array $plan): array
    {
        return ['page' => (int)($plan['pagination']['page'] ?? 1), 'limit' => (int)($plan['pagination']['limit'] ?? 20),
            'filters' => (array)($plan['filters'] ?? []), 'topFilterConditions' => (array)($plan['top_filters'] ?? []),
            'keywordFilters' => (array)($plan['keyword_filters'] ?? []), 'filterRelation' => (string)($plan['filter_relation'] ?? 'all'),
            'sorts' => (array)($plan['sorts'] ?? []), 'groupBy' => (array)($plan['groups'] ?? []),
            'summaries' => (array)($plan['summaries'] ?? []), 'dataScope' => (string)($plan['domain_scope']['data_scope'] ?? 'normal'),
            'businessStatus' => (string)($plan['domain_scope']['business_status'] ?? ''), 'quickFilters' => (array)($plan['quick_filters'] ?? []),
            'visibleFields' => (array)($plan['visible_fields'] ?? []), 'queryCutoffDate' => (string)($plan['query_cutoff_date'] ?? '')];
    }

    private function customDefinitions(array $context, array $payload): array
    {
        $candidate = $payload; unset($candidate['fieldVersions'], $candidate['field_versions']);
        $keys = (new UnifiedQueryCustomFieldKeyCollector())->collectFromQuery($candidate);
        if (!$keys) return [];
        $preference = $this->preferences->load($context, $this->pageCode());
        $pinned = (array)($preference['customFieldVersions'] ?? []); $visible = [];
        foreach ($this->customFields->listVisible($context, $this->pageCode(), true) as $field) $visible[(string)$field['key']] = $field;
        $definitions = [];
        foreach ($keys as $key) {
            if (!isset($visible[$key])) throw new UnifiedQueryException('UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE', '查询使用的自定义字段已不可用，请重新设置。', ['field_key' => $key]);
            $definition = $this->customFields->versionDefinition($context, $this->pageCode(), $key, (int)($pinned[$key] ?? $visible[$key]['version'] ?? 0));
            $definition['page_code'] = $this->pageCode(); $definitions[] = $definition;
        }
        return $definitions;
    }
}
