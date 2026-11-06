<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\product\inventory\InventoryDocumentCreatorScope;

use app\services\query\UnifiedQueryCustomFieldKeyCollector;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\UnifiedQueryProvider;
use app\services\product\inventory\InventoryStockCountDate;
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
        // 已验证的查询条件传入领域投影，允许统计在事实层按同一周期先聚合，再交给统一查询做权限和分页。
        $payload = $this->withoutInTransitTransferPeriod($payload);
        $rows = $this->sourceRows($context, $payload);
        [$rows, $payload] = $this->includeDraftsOutsideCompletionPeriod($rows, $payload);
        return $this->execution->execute($this->pageCode(), $rows, $definitions, $payload, $context,
            static function (array $row) use ($context): bool {
                $storeInScope = !empty($context['inventory_organization_read'])
                    ? in_array((int)($row['store_id'] ?? 0), (array)$context['inventory_scope_store_ids'], true)
                    : (int)($row['store_id'] ?? 0) === (int)$context['store_id'];
                return (string)($row['tenant_id'] ?? '') === (string)$context['tenant_id']
                    && $storeInScope;
            });
    }

    /** 在途是未完结调拨清单；只对已验证的明确状态条件忽略顶部调拨日期，不改变其他筛选或权限。 */
    private function withoutInTransitTransferPeriod(array $payload): array
    {
        if (!$this->hasInTransitTransferFilter($payload)) return $payload;
        $payload['topFilterConditions'] = array_values(array_filter(
            (array)($payload['topFilterConditions'] ?? []),
            static fn (array $condition): bool => (string)($condition['field_key'] ?? '') !== 'business_date'
        ));
        return $payload;
    }

    /** Only a validated, conjunctive top-level status permits the SQL status pushdown. */
    private function hasInTransitTransferFilter(array $payload): bool
    {
        if ($this->pageCode() !== 'inventory_transfer' || (string)($payload['filterRelation'] ?? 'all') !== 'all') return false;
        foreach ((array)($payload['topFilterConditions'] ?? []) as $condition) {
            if ((string)($condition['field_key'] ?? '') === 'status_name'
                && (string)($condition['operator'] ?? '') === 'equal'
                && (string)($condition['value'] ?? '') === '在途') return true;
        }
        return false;
    }

    /** 盘点周期只约束已完成单据；创建人草稿没有完成日期，仍须留在列表供查看和续编。
     * 只消费已校验的顶部盘点日期区间，其他状态/关键字/权限筛选仍交统一查询执行器处理。
     */
    private function includeDraftsOutsideCompletionPeriod(array $rows, array $payload): array
    {
        if ($this->pageCode() !== 'inventory_count') return [$rows, $payload];
        $remaining = [];
        foreach ((array)($payload['topFilterConditions'] ?? []) as $condition) {
            if (($condition['field_key'] ?? '') !== 'count_date' || ($condition['operator'] ?? '') !== 'between') {
                $remaining[] = $condition;
                continue;
            }
            [$from, $to] = $condition['value'];
            $rows = array_values(array_filter($rows, static function (array $row) use ($from, $to): bool {
                if ((int)($row['draft_id'] ?? 0) > 0) return true;
                $date = (string)($row['count_date'] ?? '');
                return $date !== '' && $date >= $from && $date <= $to;
            }));
        }
        $payload['topFilterConditions'] = $remaining;
        return [$rows, $payload];
    }

    /** 从服务端权限上下文读取业务行；子类可消费已验证的筛选计划进行安全下推。 */
    protected function sourceRows(array $context, array $payload = []): array
    {
        $storeId = (int)$context['store_id'];
        $organizationRead = !empty($context['inventory_organization_read']);
        $scopeStoreIds = $organizationRead ? array_values(array_map('intval', (array)$context['inventory_scope_store_ids'])) : [$storeId];
        $locationByStore = $organizationRead ? (array)$context['inventory_location_by_store'] : [$storeId => (int)(($context['scope_dimensions']['location_id'] ?? [0])[0] ?? 0)];
        $tenantId = (string)$context['tenant_id'];
        $locationIds = array_values(array_unique(array_map('intval', (array)(($context['scope_dimensions'] ?? [])['location_id'] ?? []))));
        if (!$locationIds) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '库存业务查询缺少服务端仓库范围。', []);
        $dateRange = $this->businessDateRange($payload);
        $creator = $this->selectedCreator($context, $payload);
        $canViewCost = in_array(InventoryBatchStockQueryContract::PERMISSION_COST, (array)$context['permissions'], true);
        switch ($this->pageCode()) {
            case 'inventory_inbound': $rows = $this->documentRows($tenantId, $scopeStoreIds, $locationIds, 'manual_inbound', $canViewCost, $dateRange, $creator); break;
            case 'inventory_outbound': $rows = $this->documentRows($tenantId, $scopeStoreIds, $locationIds, ['manual_outbound', 'presale_claim_outbound'], $canViewCost, $dateRange, $creator); break;
            case 'inventory_movement': $rows = $this->movementRows($tenantId, $storeId, $locationIds, $canViewCost); break;
            case 'inventory_count': $rows = $this->countRows($tenantId, $storeId, $locationIds, $canViewCost, $context); break;
            case 'inventory_request': $rows = $this->requestRows($tenantId, $scopeStoreIds, $locationIds, $locationByStore, $canViewCost, $dateRange); break;
            case 'inventory_transfer': $rows = $this->transferRows($tenantId, $scopeStoreIds, $locationIds, $canViewCost, $this->hasInTransitTransferFilter($payload), $dateRange, $creator); break;
            case 'inventory_salon_usage': $rows = $this->usageRows($tenantId, $scopeStoreIds, $locationIds, $dateRange, $creator); break;
            case 'inventory_import': $rows = $this->importRows($tenantId, $storeId); break;
            default: throw new \LogicException('inventory_operational_page_code_invalid');
        }
        $normalized = [];
        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : [];
            if ($creator !== null) $row[InventoryDocumentCreatorScope::FIELD] = true;
            // Movement facts may split one business document by warehouse; include its trusted store/location identity.
            $row['record_id'] = $organizationRead && in_array($this->pageCode(), ['inventory_inbound', 'inventory_outbound'], true)
                ? $this->pageCode() . ':' . (int)($row['store_id'] ?? $storeId) . ':' . (string)($row['source_type'] ?? '') . ':' . (string)($row['source_id'] ?? '') . ':' . (int)($row['location_id'] ?? 0)
                : $this->pageCode() . ':' . (string)($row['id'] ?? $row['order_sn'] ?? $row['usage_no'] ?? $index);
            $row['tenant_id'] = (string)$context['tenant_id'];
            $row['store_id'] = $organizationRead ? (int)($row['store_id'] ?? 0) : $storeId;
            $row['business_date'] = (string)($row['business_date'] ?? $row['transfer_date'] ?? $row['request_date'] ?? '');
            $row['order_sn'] = (string)($row['order_sn'] ?? $row['usage_no'] ?? '');
            $row['status_name'] = (string)($row['status_name'] ?? $row['document_status'] ?? '');
            $row['operation_at'] = (int)($row['operation_at'] ?? $row['occurred_at'] ?? $row['recorded_at'] ?? $row['add_time'] ?? 0);
            $row['operator_name'] = (string)($row['operator_name'] ?? '');
            if ($this->pageCode() === 'inventory_request') {
                $row['request_party_name'] = (string)($row['request_party_name'] ?? $row['request_store_name'] ?? '');
                $row['supply_party_name'] = (string)($row['supply_party_name'] ?? $row['supply_store_name'] ?? '');
                $row['acceptance_party_name'] = (string)($row['acceptance_party_name'] ?? '');
            }
            if ($this->pageCode() === 'inventory_salon_usage') {
                $row['operation_name'] = (string)($row['operation_type'] ?? '') === 'RETURN' ? '退回' : '领用';
            }
            if ($this->pageCode() === 'inventory_import') {
                $row['direction_name'] = (string)($row['direction'] ?? '') === 'inbound' ? '入库导入' : '出库导入';
                $row['status_name'] = ['SUCCEEDED' => '成功', 'FAILED' => '失败', 'PROCESSING' => '处理中'][(string)($row['status'] ?? '')] ?? '';
                $row['business_date'] = date('Y-m-d', (int)($row['updated_at'] ?? 0));
                $row['order_sn'] = (string)($row['document_no'] ?? '');
                $row['operation_at'] = (int)($row['updated_at'] ?? 0);
            }
            if ($organizationRead) foreach (['can_edit','can_delete','can_cancel','can_terminate','can_transfer','can_bind_supply','can_dispatch','can_withdraw','can_receive','can_reverse','can_void'] as $capability) {
                $row[$capability] = false;
            }
            $normalized[] = $row;
        }
        return $normalized;
    }

    /** 只将已校验的顶层「本人」条件下推，冻结导出使用同一计划和认证身份。 */
    private function selectedCreator(array $context, array $payload): ?array
    {
        foreach ((array)($payload['topFilterConditions'] ?? []) as $filter) {
            if (($filter['field_key'] ?? '') === InventoryDocumentCreatorScope::FIELD
                && ($filter['operator'] ?? '') === 'equal' && ($filter['value'] ?? null) === true) {
                return $context['inventory_document_creator'] ?? ['id' => 0, 'type' => ''];
            }
        }
        return null;
    }

    /** Push down only a validated conjunctive date interval; OR filters still run wholly in the UQ executor. */
    private function businessDateRange(array $payload): ?array
    {
        if ((string)($payload['filterRelation'] ?? 'all') !== 'all') return null;
        foreach ((array)($payload['topFilterConditions'] ?? []) as $condition) {
            if (($condition['field_key'] ?? '') === 'business_date' && ($condition['operator'] ?? '') === 'between') {
                $value = (array)($condition['value'] ?? []);
                if (count($value) === 2) return array_values($value);
            }
        }
        return null;
    }

    private function documentRows(string $tenantId, array $storeIds, array $locationIds, $types, bool $canViewCost, ?array $dateRange, ?array $creator): array
    {
        $types = is_array($types) ? $types : [(string)$types];
        // Resolve the document's store, not the logged-in store or warehouse label.
        // Preserve the location's store snapshot; legacy empty snapshots use that same store ID.
        $storeName = "COALESCE(NULLIF(l.store_name_snapshot, ''), s.name, '')";
        $query = Db::name('inventory_batch_movement_fact')->alias('f')->leftJoin('inventory_batch b', 'b.id=f.batch_id')
            ->leftJoin('inventory_location l', 'l.id=f.location_id')
            ->leftJoin('system_store s', 's.id=l.store_id AND l.store_id=f.store_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->leftJoin('cashier_v3_presale_claim pc', 'pc.tenant_id=f.tenant_id AND pc.claim_id=f.source_id AND f.source_type=\'presale_claim_outbound\'')->where('f.tenant_id', $tenantId)->whereIn('f.store_id', $storeIds)
            ->whereIn('f.location_id', $locationIds)->where('f.fact_status', 'SETTLED')->whereIn('f.source_type', $types);
        if ($dateRange !== null) $query->whereBetween('f.business_date', $dateRange);
        if ($creator !== null) {
            // 预售领用的原录入人在领用单，旧批次流水没有复制该身份；手工单继续取原流水。
            if (in_array('presale_claim_outbound', $types, true)) {
                $query->where(static function ($source) use ($creator): void {
                    $source->where(static function ($manual) use ($creator): void {
                        $manual->where('f.source_type', 'manual_outbound');
                        InventoryDocumentCreatorScope::applyFacts($manual, $creator);
                    })->whereOr(static function ($claim) use ($creator): void {
                        $claim->where('f.source_type', 'presale_claim_outbound');
                        InventoryDocumentCreatorScope::applyFacts($claim, $creator, 'pc');
                    });
                });
            } else {
                InventoryDocumentCreatorScope::applyFacts($query, $creator);
            }
        }
        $rows = $query
            // Query expressions cannot be used as PHP array keys. Use one raw
            // projection so ThinkORM receives the SQL aliases directly.
            ->fieldRaw("f.source_id AS source_id, f.source_type AS source_type, f.store_id AS store_id, MAX(pc.claim_status) AS presale_claim_status, COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, f.location_id, l.location_name, MAX(f.recorded_at) AS add_time, MAX(COALESCE(NULLIF(f.occurred_at, 0), f.recorded_at)) AS operation_at, MAX(f.operator_name_snapshot) AS operator_name, COUNT(f.id) AS detail_count, SUM(f.cost_amount_cents) AS cost_amount_cents, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(b.product_name_snapshot, '商品'), ' / ', IFNULL(b.sku_name_snapshot, '默认规格')) SEPARATOR '、') AS product_summary")
            ->fieldRaw($storeName . ' AS store_name')
            ->group('f.source_id,f.source_type,f.store_id,n.document_no,f.business_date,f.location_id,l.location_name,l.store_name_snapshot,s.name')->order('add_time desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
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
        // Lists and frozen exports share the same persisted type/recipient projection.
        $rows = (new \app\services\product\inventory\InventoryOutboundHeaderServices())->apply($rows, $tenantId, $locationIds);
        return $this->bounded($rows);
    }

    private function movementRows(string $tenantId, int $storeId, array $locationIds, bool $canViewCost): array
    {
        $rows = Db::name('inventory_batch_movement_fact')->alias('f')->leftJoin('inventory_batch b', 'b.id=f.batch_id')->leftJoin('inventory_stock s', 's.id=f.stock_id')->leftJoin('inventory_location l', 'l.id=f.location_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', $tenantId)->where('f.store_id', $storeId)->whereIn('f.location_id', $locationIds)->where('f.fact_status', 'SETTLED')
            ->fieldRaw('f.id, COALESCE(n.document_no, f.source_id) AS order_sn, f.source_type, f.business_date, f.occurred_at, f.recorded_at, COALESCE(NULLIF(f.occurred_at, 0), f.recorded_at) AS operation_at, f.operator_name_snapshot AS operator_name, f.direction, f.quantity_units, f.cost_amount_cents, f.location_id, s.quantity_scale, b.product_name_snapshot AS product_name, b.sku_name_snapshot AS sku_name, b.batch_no, l.location_name')->order('f.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        foreach ($rows as &$row) {
            $row['movement_type_name'] = $this->movementName((string)$row['source_type']);
            $scale = max(0, min(4, (int)($row['quantity_scale'] ?? 0))); $quantity = (int)$row['quantity_units'] / (10 ** $scale);
            $row['quantity_display'] = ((int)$row['direction'] > 0 ? '+' : '-') . rtrim(rtrim(number_format($quantity, $scale, '.', ''), '0'), '.');
            $row['status_name'] = '已完成'; if (!$canViewCost) $row['cost_amount_cents'] = null;
        }
        unset($row); return $this->bounded($rows);
    }

    private function countRows(string $tenantId, int $storeId, array $locationIds, bool $canViewCost, array $context): array
    {
        // 列表“差异项”仅统计实际盈亏行；持平盘点仍有完整明细，不能把全部明细条数当成差异。
        // 门店仓优先保留已有门店名称快照，旧空快照才按仓库所属门店 ID 补读；总部仓仍显示其仓名。
        // 不修改原 location_name，也不使用当前登录门店替代行归属；已确认单据与草稿复用同一表达式。
        $storeName = "CASE WHEN p.location_type='HQ' THEN p.location_name ELSE COALESCE(NULLIF(p.store_name_snapshot, ''), s.name, '') END";
        $rows = Db::name('inventory_stock_count_document')->alias('d')->leftJoin('inventory_stock_count_line l', 'l.document_id=d.id')->leftJoin('inventory_location p', 'p.id=d.location_id')
            ->leftJoin('system_store s', 's.id=p.store_id')
            ->where('d.tenant_id', $tenantId)->whereIn('d.location_id', $locationIds)
            ->field(['d.id', 'd.count_no' => 'order_sn', 'd.document_status' => 'status_name', 'd.business_date', 'd.confirmed_at', 'd.recorded_at' => 'add_time', 'd.confirmed_at' => 'operation_at', 'd.operator_name_snapshot' => 'operator_name', 'd.location_id', 'p.store_id' => 'store_id', 'p.location_name', 'SUM(CASE WHEN l.difference_quantity_units <> 0 THEN 1 ELSE 0 END)' => 'detail_count'])
            ->fieldRaw($storeName . ' AS store_name')
            ->group('d.id,d.count_no,d.document_status,d.business_date,d.confirmed_at,d.recorded_at,d.location_id,p.store_id,p.location_name,p.location_type,p.store_name_snapshot,s.name')->order('d.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        // 已确认单据只用服务端完成时间形成盘点日期；草稿不借用保存日伪造完成日期。
        foreach ($rows as &$row) {
            $row['count_date'] = InventoryStockCountDate::fromConfirmedAt((int)$row['confirmed_at']);
        }
        unset($row);
        if ($canViewCost && $rows) {
            $amountQuery = Db::name('inventory_batch_movement_fact')->where('tenant_id', $tenantId)->whereIn('location_id', $locationIds)->where('fact_status', 'SETTLED')->whereIn('source_type', ['stock_count_gain', 'stock_count_loss']);
            // 跨店首页下钻的 store_id=0 是查询哨兵，金额仍按可信门店集合与仓库交集读取。
            if (!empty($context['inventory_organization_read'])) $amountQuery->whereIn('store_id', (array)$context['inventory_scope_store_ids']);
            else $amountQuery->where('store_id', $storeId);
            $amounts = $amountQuery
                ->field('source_id, SUM(CASE WHEN direction=1 THEN cost_amount_cents ELSE -cost_amount_cents END) change_amount_cents')->group('source_id')->select()->toArray();
            $index = array_column($amounts, 'change_amount_cents', 'source_id'); foreach ($rows as &$row) $row['change_amount_cents'] = (int)($index[(string)$row['order_sn']] ?? 0); unset($row);
        } else foreach ($rows as &$row) $row['change_amount_cents'] = null;
        unset($row);
        // Drafts are editing snapshots, not confirmed facts. A typed, stable creator sees only
        // their own draft even after re-login. HQ uses store_id=0 plus PLATFORM_ADMIN;
        // the trusted context factory grants this discriminator only for managed HQ scope.
        if ((string)($context['inventory_count_operator_type'] ?? '') !== '') {
            $drafts = Db::name('inventory_stock_count_draft')->alias('d')
                ->leftJoin('inventory_location p', 'p.id=d.location_id')
                ->leftJoin('system_store s', 's.id=p.store_id')
                ->where('d.tenant_id', $tenantId)->where('d.store_id', $storeId)
                ->whereIn('d.location_id', $locationIds)
                ->where('d.operator_id', (int)($context['inventory_count_creator_id'] ?? 0))
                ->where('d.operator_type', (string)$context['inventory_count_operator_type'])
                ->where('d.document_status', 'DRAFT')
                ->field('d.id draft_id,d.business_date,d.updated_at operation_at,d.updated_at add_time,d.location_id,d.line_count detail_count,p.location_name')
                ->fieldRaw($storeName . ' AS store_name')
                ->order('d.updated_at desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
            foreach ($drafts as $draft) {
                $rows[] = [
                    'id' => 'draft-' . (int)$draft['draft_id'], 'draft_id' => (int)$draft['draft_id'],
                    'order_sn' => '草稿-' . (int)$draft['draft_id'], 'status_name' => 'DRAFT',
                    'business_date' => (string)$draft['business_date'], 'operation_at' => (int)$draft['operation_at'],
                    'count_date' => '',
                    'add_time' => (int)$draft['add_time'], 'location_id' => (int)$draft['location_id'],
                    'location_name' => (string)$draft['location_name'], 'detail_count' => (int)$draft['detail_count'],
                    'store_name' => (string)$draft['store_name'],
                    'change_amount_cents' => null,
                    'operator_name' => (string)($context['inventory_count_operator_name'] ?? ''),
                ];
            }
        }
        return $this->bounded($rows);
    }

    private function requestRows(string $tenantId, array $storeIds, array $locationIds, array $locationByStore, bool $canViewCost, ?array $dateRange): array
    {
        // 供货给本店的跨店请货也进入同一权威列表；当前门店仓是查询维度，原单仓仍单独保留。
        $query = Db::name('inventory_stock_request_document')->alias('d')->leftJoin('inventory_stock_request_line l', 'l.document_id=d.id')->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', $tenantId)->where(function ($subjects) use ($storeIds, $locationIds): void {
                $subjects->where(function ($requester) use ($storeIds, $locationIds): void {
                    $requester->whereIn('d.store_id', $storeIds)->whereIn('d.location_id', $locationIds);
                })->whereOr(function ($supplier) use ($storeIds): void {
                    $supplier->where('d.supply_party_type', 'STORE')->whereIn('d.supply_party_id', $storeIds);
                });
            });
        if ($dateRange !== null) $query->whereBetween('d.business_date', $dateRange);
        $rows = $query
            ->field('d.id,d.request_no,d.request_no order_sn,d.document_status status_name,d.store_id requester_store_id,d.business_date,d.recorded_at add_time,d.recorded_at operation_at,d.location_id request_location_id,s.name request_store_name,d.supply_party_type,d.supply_party_id,d.supply_location_id,d.supply_party_name_snapshot supply_party_name,d.acceptance_party_id,d.acceptance_party_name_snapshot acceptance_party_name,d.operator_name_snapshot operator_name,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.reference_unit_cost_cents)/POW(10,l.quantity_scale)) estimated_amount_cents')
            ->group('d.id')->order('d.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        foreach ($rows as &$row) {
            $status = (string)$row['status_name'];
            $row['status_name'] = $this->requestStatusName($status);
            $isRequester = in_array((int)$row['requester_store_id'], $storeIds, true) && in_array((int)$row['request_location_id'], $locationIds, true);
            $isSupplier = (string)$row['supply_party_type'] === 'STORE' && in_array((int)$row['supply_party_id'], $storeIds, true);
            // When both parties are in the organization, the request remains one document, owned by its requester.
            $row['store_id'] = $isRequester ? (int)$row['requester_store_id'] : (int)$row['supply_party_id'];
            $row['location_id'] = $isRequester ? (int)$row['request_location_id'] : (int)($locationByStore[$row['store_id']] ?? 0);
            $row['can_edit'] = $isRequester && $status === 'APPLIED'
                && !(bool)Db::name('inventory_cross_transfer_document')
                    ->where('request_document_id', (int)$row['id'])
                    ->whereNotIn('document_status', ['CANCELLED', 'REVERSED'])
                    ->value('id');
            // 只要曾生成调拨（包括后来取消/冲销），请货单即锁定所有关闭入口。
            $linkedTransfer = Db::name('inventory_cross_transfer_document')->where('request_document_id', (int)$row['id'])->value('id');
            // 列表可见即允许进入绑定原单的调拨草稿；服务端保存时重新锁定
            // 原供货方、请货方和剩余数量，不能改造成任意仓库调拨。
            $transferSourceReady = ((string)$row['supply_party_type'] === 'HQ' && (int)$row['supply_party_id'] === 0 && (int)$row['supply_location_id'] > 0)
                || ((string)$row['supply_party_type'] === 'STORE' && (int)$row['supply_party_id'] > 0);
            $row['can_transfer'] = ($isRequester || $isSupplier) && $transferSourceReady && in_array($status, ['APPLIED', 'PARTIAL'], true);
            $row['can_cancel'] = $isRequester && $status === 'APPLIED' && !$linkedTransfer;
            $row['can_terminate'] = $isRequester && $status === 'PARTIAL' && !$linkedTransfer;
            $row['can_void'] = ($isRequester || $isSupplier) && in_array($status, ['APPLIED', 'PARTIAL', 'DONE'], true) && !$linkedTransfer;
            if (!$canViewCost) $row['estimated_amount_cents'] = null;
        }
        unset($row);
        return $this->bounded($rows);
    }

    private function transferRows(string $tenantId, array $storeIds, array $locationIds, bool $canViewCost, bool $onlyInTransit, ?array $dateRange, ?array $creator): array
    {
        $query = Db::name('inventory_cross_transfer_document')->alias('d')->leftJoin('inventory_cross_transfer_line l', 'l.document_id=d.id AND l.retired_at=0')->leftJoin('inventory_cross_transfer_batch_allocation a', 'a.line_id=l.id AND a.withdrawn_at=0')
            ->where('d.tenant_id', $tenantId)->where(function ($query) use ($storeIds, $locationIds): void {
                $query->where(function ($source) use ($storeIds, $locationIds): void { $source->where('d.from_party_type', 'STORE')->whereIn('d.from_party_id', $storeIds)->whereIn('d.from_location_id', $locationIds); })
                    // 接收方不能通过统一查询、合计或导出提前看到未发货草稿。
                    ->whereOr(function ($target) use ($storeIds, $locationIds): void { $target->where('d.to_party_type', 'STORE')->whereIn('d.to_party_id', $storeIds)->whereIn('d.to_location_id', $locationIds)->whereIn('d.document_status', ['DISPATCHED', 'RECEIVED']); });
            });
        // 先限制在途状态再读取，避免大量其他状态占满统一查询的来源窗口。
        if ($onlyInTransit) $query->where('d.document_status', 'DISPATCHED');
        if ($dateRange !== null) $query->whereBetween('d.business_date', $dateRange);
        if ($creator !== null) InventoryDocumentCreatorScope::applyTransfer($query, $creator);
        $rows = $query
            ->field('d.id,d.transfer_no order_sn,d.document_status,d.document_status status_name,d.withdrawal_count,d.business_date,d.recorded_at add_time,d.recorded_at operation_at,d.created_by_operator_name_snapshot operator_name,d.from_party_type,d.from_party_id,d.from_location_id,d.to_party_type,d.to_party_id,d.to_location_id,d.initiator_store_id,d.from_party_name_snapshot from_party_name,d.to_party_name_snapshot to_party_name,COUNT(DISTINCT l.id) detail_count,SUM((a.quantity_units*a.unit_cost_cents)/POW(10,a.quantity_scale)) transfer_amount_cents')
            ->group('d.id')->order('d.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        foreach ($rows as &$row) {
            $fromCurrentStore = (string)$row['from_party_type'] === 'STORE'
                && in_array((int)$row['from_party_id'], $storeIds, true)
                && in_array((int)$row['from_location_id'], $locationIds, true);
            $toCurrentStore = (string)$row['to_party_type'] === 'STORE'
                && in_array((int)$row['to_party_id'], $storeIds, true)
                && in_array((int)$row['to_location_id'], $locationIds, true);
            $row['store_id'] = $fromCurrentStore ? (int)$row['from_party_id'] : (int)$row['to_party_id'];
            $row['location_id'] = $fromCurrentStore ? (int)$row['from_location_id'] : (int)$row['to_location_id'];
            $row['status_name'] = $this->transferStatusName((string)$row['document_status']);
            $row['can_dispatch'] = (string)$row['document_status'] === 'DRAFT' && $fromCurrentStore;
            $row['can_edit'] = $row['can_dispatch'];
            $row['can_delete'] = $row['can_edit'] && (int)$row['withdrawal_count'] === 0;
            $row['can_withdraw'] = (string)$row['document_status'] === 'DISPATCHED' && $fromCurrentStore;
            $row['can_receive'] = (string)$row['document_status'] === 'DISPATCHED' && $toCurrentStore;
            // 撤回后的草稿保留旧流水，提供记名作废而非物理删除。
            $row['can_cancel'] = false;
            $row['can_reverse'] = $row['can_edit'] && (int)$row['withdrawal_count'] > 0;
            if (!$canViewCost) $row['transfer_amount_cents'] = null;
            unset($row['from_party_type'], $row['from_party_id'], $row['from_location_id'], $row['to_party_type'], $row['to_party_id'], $row['to_location_id'], $row['initiator_store_id']);
        }
        unset($row); return $this->bounded($rows);
    }

    private function usageRows(string $tenantId, array $storeIds, array $locationIds, ?array $dateRange, ?array $creator): array
    {
        // 列表显示单据原仓，不从当前页面筛选器反推；查询范围仍以服务端授权 location_id 集合为准。
        $query = Db::name('inventory_salon_usage_document')->alias('d')->leftJoin('inventory_salon_usage_line l', 'l.document_id=d.id')
            ->leftJoin('inventory_location loc', 'loc.id=d.location_id')
            ->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', $tenantId)->whereIn('d.store_id', $storeIds)->whereIn('d.location_id', $locationIds);
        if ($dateRange !== null) $query->whereBetween('d.business_date', $dateRange);
        if ($creator !== null) InventoryDocumentCreatorScope::applyUsage($query, $creator);
        $rows = $query
            ->field('d.id,d.usage_no,d.operation_type,d.project_name_snapshot,d.store_id,d.location_id,loc.location_name,d.document_status,d.business_date,d.recorded_at operation_at,d.operator_name_snapshot operator_name,d.remark,COUNT(l.id) detail_count')
            ->fieldRaw("COALESCE(NULLIF(loc.store_name_snapshot, ''), s.name, '') AS store_name")
            ->group('d.id')->order('d.business_date desc,d.id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        $issueIds = array_values(array_map('intval', array_column(array_filter($rows,
            static fn (array $row): bool => (string)$row['operation_type'] === 'ISSUE'
                && (string)$row['document_status'] === 'SETTLED'), 'id')));
        $returned = $issueIds ? array_fill_keys(array_map('intval', Db::name('inventory_salon_usage_line')->alias('source')
            ->join('inventory_salon_usage_line returned', 'returned.source_usage_line_id=source.id')
            ->join('inventory_salon_usage_document rd', 'rd.id=returned.document_id')
            ->whereIn('source.document_id', $issueIds)->where('rd.operation_type', 'RETURN')
            ->group('source.document_id')->column('source.document_id')), true) : [];
        foreach ($rows as &$row) {
            $row['can_void'] = (string)$row['operation_type'] === 'ISSUE'
                && (string)$row['document_status'] === 'SETTLED' && !isset($returned[(int)$row['id']]);
        }
        unset($row);
        return $this->bounded($rows);
    }

    private function importRows(string $tenantId, int $storeId): array
    {
        return $this->bounded(Db::name('inventory_v3_import_record')
            ->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->field('id,direction,source_file_name,business_type,total_count,success_count,failure_count,document_no,status,failure_message,operator_name_snapshot operator_name,updated_at')
            ->order('id desc')->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray());
    }

    private function bounded(array $rows): array { if (count($rows) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) throw new UnifiedQueryException('UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE', '当前数据量较大，请缩小业务日期范围后再查询。', []); return $rows; }
    /** 统一中文投影，防止同一事实在流水和统计页面使用不同名称。 */
    private function movementName(string $type): string { return \app\services\product\inventory\InventoryMovementDisplayServices::typeName($type); }
    private function transferStatusName(string $status): string { return ['DRAFT' => '草稿', 'DISPATCHED' => '在途', 'RECEIVED' => '已收货', 'CANCELLED' => '已取消', 'REVERSED' => '已作废'][$status] ?? '未知状态'; }
    private function requestStatusName(string $status): string { return ['APPLIED' => '申请中', 'PARTIAL' => '部分履约', 'DONE' => '已完成', 'CANCELLED' => '已取消', 'TERMINATED' => '已终止剩余请货', 'VOIDED' => '已作废'][$status] ?? '未知状态'; }

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
