<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Read model for the V3 request authority; legacy request drafts are excluded. */
final class InventoryStockRequestQueryServices
{
    /**
     * Search the document's historical identity inside the caller's permission scope.
     * ASCII document numbers cannot be compared directly with Chinese keywords;
     * normalize only the comparison expression, keeping stored IDs and snapshots intact.
     * Fixed columns and bound values prevent keywords from becoming SQL fragments.
     */
    private function applyKeyword($query, string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') return;
        $columns = ['request_no', 'remark', 'request_party_name_snapshot', 'supply_party_name_snapshot', 'acceptance_party_name_snapshot'];
        $conditions = [];
        $bindings = [];
        foreach ($columns as $index => $column) {
            $parameter = 'request_search_' . $index;
            $conditions[] = "CONVERT(d.{$column} USING utf8mb4) COLLATE utf8mb4_general_ci LIKE :{$parameter}";
            $bindings[$parameter] = '%' . $keyword . '%';
        }
        // Enclose the OR search so it can only narrow tenant, organization and date filters.
        $query->whereRaw('(' . implode(' OR ', $conditions) . ')', $bindings);
    }

    public function listForHeadquarters(array $adminInfo, int $locationId, string $keyword, int $page = 1, int $limit = 20, string $requestPartyType = '', int $requestPartyId = 0, string $supplyPartyType = '', int $supplyPartyId = 0, int $acceptancePartyId = 0, string $status = '', string $dateFrom = '', string $dateTo = '', int $supplyLocationId = 0, int $requestLocationId = 0): array
    {
        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $locationId);
        $location = (array)$resolved['location']; $access = (array)$resolved['access'];
        // A platform request may be raised by the headquarters subject or by
        // an authorised store subject. Both belong to this organization root;
        // limiting this read to the HQ location made successfully created store
        // requests disappear immediately after submission.
        $query = $this->headquartersDocumentQuery($location, $access)
            ->leftJoin('inventory_stock_request_line l', 'l.document_id=d.id');
        $this->applyKeyword($query, $keyword);
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('d.business_date', [$dateFrom, $dateTo]);
        if (in_array(strtoupper(trim($requestPartyType)), ['HQ', 'STORE'], true)) {
            $query->where('d.request_party_type', strtoupper(trim($requestPartyType)))->where('d.request_party_id', $requestPartyId);
        }
        if (in_array(strtoupper(trim($supplyPartyType)), ['HQ', 'STORE'], true)) {
            $query->where('d.supply_party_type', strtoupper(trim($supplyPartyType)))->where('d.supply_party_id', $supplyPartyId);
        }
        // 受理部门只在总部供货范围内有意义，拒绝脱离总部供货条件的隐式筛选。
        if (strtoupper(trim($supplyPartyType)) === 'HQ' && $acceptancePartyId > 0) $query->where('d.acceptance_party_id', $acceptancePartyId);
        if ($supplyLocationId > 0) $query->where('d.supply_location_id', $supplyLocationId);
        if ($requestLocationId > 0) $query->where('d.location_id', $requestLocationId);
        if ($status !== '') $query->where('d.document_status', $status);
        $count = (int)(clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.request_no order_sn,d.document_status status_name,d.request_party_name_snapshot request_party_name,d.requester_name_snapshot,d.operator_name_snapshot operator_name,d.supply_party_type,d.supply_party_id,d.supply_location_id,d.location_id,d.supply_party_name_snapshot supply_party_name,d.acceptance_party_id,d.acceptance_party_name_snapshot acceptance_party_name,d.business_date request_date,d.recorded_at add_time,d.recorded_at operation_at,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.reference_unit_cost_cents)/POW(10,l.quantity_scale)) estimated_amount_cents')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($list as &$row) {
            $status=(string)$row['status_name'];
            // The shortcut is only an entry to an existing transfer command: it never confirms
            // or consumes stock. Source authority and current remaining lines are rechecked there.
            $row['can_bind_supply'] = (int)$row['supply_location_id'] === 0 && (string)$row['supply_party_type'] === 'HQ' && in_array($status, ['APPLIED', 'PARTIAL'], true) && (new InventoryPlatformAccessPolicy())->canCreate($access, 'transfer');
            $row['can_transfer'] = (int)$row['supply_location_id'] > 0 && in_array($status, ['APPLIED', 'PARTIAL'], true)
                && in_array('inventory.location.manage', (array)($access['features'] ?? []), true)
                && ((string)$row['supply_party_type'] === 'HQ'
                    || !empty($access['is_super_admin'])
                    || in_array((int)$row['supply_party_id'], array_map('intval', (array)($access['store_ids'] ?? [])), true));
            // 旧取消/终止入口也不能绕过“曾生成调拨即不可作废”的单据边界。
            $hasTransfer = $this->hasLinkedTransfer((int)$row['id']);
            // 单据状态不能替代平台写授权；旧菜单查询只展示详情。
            $canManage = in_array('inventory.location.manage', (array)$access['features'], true);
            $row['can_cancel']=$canManage && $status==='APPLIED' && !$hasTransfer; $row['can_terminate']=$canManage && $status==='PARTIAL' && !$hasTransfer;
            $row['can_void'] = $this->canVoidForPlatform((int)$row['id'], $status, (string)$row['supply_party_type'], (int)$row['supply_party_id'], $access, $hasTransfer);
            if (!$canViewCost) $row['estimated_amount_cents'] = null;
            $row['status_name'] = $this->statusName($status);
        }
        unset($row);
        return ['count' => $count, 'list' => $list];
    }

    public function detailForHeadquarters(array $adminInfo, int $locationId, int $requestId): array
    {
        if ($requestId <= 0) throw new \InvalidArgumentException('inventory_stock_request_detail_input_invalid');
        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $locationId);
        $location = (array)$resolved['location']; $access = (array)$resolved['access'];
        $document = $this->headquartersDocumentQuery($location, $access)
            ->where('d.id', $requestId)
            ->field('d.*')
            ->find();
        if (!$document) throw new \RuntimeException('inventory_stock_request_not_found');
        $lines = Db::name('inventory_stock_request_line')->where('document_id', $requestId)->order('line_no asc')->select()->toArray();
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($lines as &$line) { $line['quantity'] = $this->decimal((int)$line['requested_quantity_units'], max(0, min(4, (int)$line['quantity_scale']))); if (!$canViewCost) $line['reference_unit_cost_cents'] = null; }
        unset($line);
        $document['status_name'] = $this->statusName((string)$document['document_status']);
        $hasTransfer = $this->hasLinkedTransfer($requestId);
        // 详情与列表使用同一后端能力，防止只读用户出现取消/终止按钮。
        $canManage = in_array('inventory.location.manage', (array)$access['features'], true);
        return ['document' => $document, 'lines' => $lines, 'can_edit' => false, 'can_cancel' => $canManage && (string)$document['document_status'] === 'APPLIED' && !$hasTransfer, 'can_terminate' => $canManage && (string)$document['document_status'] === 'PARTIAL' && !$hasTransfer, 'can_void' => $this->canVoidForPlatform($requestId, (string)$document['document_status'], (string)$document['supply_party_type'], (int)$document['supply_party_id'], $access, $hasTransfer)];
    }

    public function list(int $storeId, int $operatorId, string $keyword, int $page = 1, int $limit = 20, bool $canViewCost = false, string $dateFrom = '', string $dateTo = ''): array
    {
        // 请货读模型沿用写入方的可信门店身份，不再把组织会话 ID 当员工行。
        try {
            (new InventoryStoreAccessPolicy())->writableActor($storeId, $operatorId, 'cashier.v3.inventory.request');
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException('inventory_stock_request_query_scope_denied', 0, $exception);
        }
        $location = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
        if (!$location) throw new \RuntimeException('inventory_stock_request_query_scope_denied');
        // 本店发起与供货给本店是两个独立的可见主体；仅用请求方仓库筛选会漏掉跨店请货。
        $query = Db::name('inventory_stock_request_document')->alias('d')->leftJoin('inventory_stock_request_line l', 'l.document_id=d.id')->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where(function ($subjects) use ($storeId, $location): void {
                $subjects->where(function ($requester) use ($storeId, $location): void {
                    $requester->where('d.store_id', $storeId)->where('d.location_id', (int)$location['id']);
                })->whereOr(function ($supplier) use ($storeId): void {
                    $supplier->where('d.supply_party_type', 'STORE')->where('d.supply_party_id', $storeId);
                });
            });
        $this->applyKeyword($query, $keyword);
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('d.business_date', [$dateFrom, $dateTo]);
        $count = (clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.request_no order_sn,d.document_status status_name,d.store_id,s.name request_store_name,d.requester_name_snapshot,d.operator_name_snapshot operator_name,d.supply_party_type,d.supply_party_id,d.supply_location_id,d.location_id,d.supply_party_name_snapshot supply_party_name,d.acceptance_party_id,d.acceptance_party_name_snapshot acceptance_party_name,d.business_date request_date,d.recorded_at add_time,d.recorded_at operation_at,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.reference_unit_cost_cents)/POW(10,l.quantity_scale)) estimated_amount_cents')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        foreach ($list as &$row) {
            $documentStatus = (string)$row['status_name'];
            $row['can_edit'] = (int)$row['store_id'] === $storeId && $documentStatus === 'APPLIED'
                && !(bool)Db::name('inventory_cross_transfer_document')
                    ->where('request_document_id', (int)$row['id'])
                    ->whereNotIn('document_status', ['CANCELLED', 'REVERSED'])
                    ->value('id');
            $hasTransfer = $this->hasLinkedTransfer((int)$row['id']);
            // 门店能看到的请货单都可进入“转调拨”；真正保存时仍以锁定原单的
            // 供货方、请货方和剩余明细为准，不能借列表能力扩大库存主体。
            $transferSourceReady = ((string)$row['supply_party_type'] === 'HQ' && (int)$row['supply_party_id'] === 0 && (int)$row['supply_location_id'] > 0)
                || ((string)$row['supply_party_type'] === 'STORE' && (int)$row['supply_party_id'] > 0);
            $row['can_transfer'] = $transferSourceReady && in_array($documentStatus, ['APPLIED', 'PARTIAL'], true);
            $row['can_cancel'] = $documentStatus === 'APPLIED' && !$hasTransfer;
            $row['can_terminate'] = $documentStatus === 'PARTIAL' && !$hasTransfer;
            $row['can_void'] = $this->canVoidForStore((int)$row['id'], $documentStatus, $storeId, $hasTransfer);
            if (!$canViewCost) $row['estimated_amount_cents'] = null;
            $row['status_name'] = $this->statusName($documentStatus);
        }
        unset($row);
        return ['count' => $count, 'list' => $list];
    }

    /**
     * A request is a demand document, not an inventory fact. Detail reads the
     * same store-scoped authority and exposes whether it is still editable.
     */
    public function detail(int $storeId, int $operatorId, int $requestId, bool $canViewCost = false): array
    {
        if ($requestId <= 0) throw new \InvalidArgumentException('inventory_stock_request_detail_input_invalid');
        $scope = $this->scope($storeId, $operatorId);
        $document = Db::name('inventory_stock_request_document')->alias('d')
            ->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where(function ($subjects) use ($storeId, $scope): void {
                $subjects->where(function ($requester) use ($storeId, $scope): void {
                    $requester->where('d.store_id', $storeId)->where('d.location_id', (int)$scope['location_id']);
                })->whereOr(function ($supplier) use ($storeId): void {
                    $supplier->where('d.supply_party_type', 'STORE')->where('d.supply_party_id', $storeId);
                });
            })
            ->where('d.id', $requestId)
            ->field('d.id,d.request_no,d.document_status,d.store_id,s.name request_store_name,d.requester_name_snapshot,d.operator_name_snapshot,d.supply_party_type,d.supply_party_id,d.supply_location_id,d.location_id,d.supply_party_name_snapshot,d.acceptance_party_id,d.acceptance_party_name_snapshot,d.business_date,d.applied_at,d.recorded_at,d.remark')
            ->find();
        if (!$document) throw new \RuntimeException('inventory_stock_request_not_found');
        $document['status_name'] = $this->statusName((string)$document['document_status']);

        $lines = Db::name('inventory_stock_request_line')
            ->where('document_id', $requestId)
            ->order('line_no asc')
            ->field('id,line_no,product_id,sku_id,sku_unique,product_name_snapshot,sku_name_snapshot,product_code_snapshot,barcode_snapshot,stock_unit_snapshot,quantity_scale,requested_quantity_units,reference_unit_cost_cents,reference_cost_status,fulfilled_transfer_id,detail_remark')
            ->select()->toArray();
        foreach ($lines as &$line) {
            $scale = max(0, min(4, (int)$line['quantity_scale']));
            $line['quantity'] = $this->decimal((int)$line['requested_quantity_units'], $scale);
            $line['reference_unit_cost_cents'] = $canViewCost ? (int)$line['reference_unit_cost_cents'] : null;
        }
        unset($line);

        $hasTransfer = Db::name('inventory_cross_transfer_document')
            ->where('request_document_id', $requestId)
            ->whereNotIn('document_status', ['CANCELLED', 'REVERSED'])
            ->value('id');
        $everTransfer = $this->hasLinkedTransfer($requestId);
        return [
            'document' => $document,
            'lines' => $lines,
            'can_edit' => (int)$document['store_id'] === $storeId && (string)$document['document_status'] === 'APPLIED'
                && !$hasTransfer
                && !array_filter($lines, static fn(array $line): bool => (int)$line['fulfilled_transfer_id'] > 0),
            'can_cancel' => (string)$document['document_status'] === 'APPLIED' && !$everTransfer,
            'can_terminate' => (string)$document['document_status'] === 'PARTIAL' && !$everTransfer,
            'can_transfer' => (((string)$document['supply_party_type'] === 'HQ' && (int)$document['supply_party_id'] === 0 && (int)$document['supply_location_id'] > 0)
                || ((string)$document['supply_party_type'] === 'STORE' && (int)$document['supply_party_id'] > 0))
                && in_array((string)$document['document_status'], ['APPLIED', 'PARTIAL'], true),
            'can_void' => $this->canVoidForStore($requestId, (string)$document['document_status'], $storeId, $everTransfer),
        ];
    }

    /** 门店可作废其可见的请货单；历史取消调拨仍算已生成并关闭作废。 */
    private function canVoidForStore(int $requestId, string $status, int $storeId, ?bool $hasTransfer = null): bool
    {
        if (!in_array($status, ['APPLIED', 'PARTIAL', 'DONE'], true)) return false;
        $document = Db::name('inventory_stock_request_document')->where('id', $requestId)
            ->field('store_id,request_party_type,request_party_id,supply_party_type,supply_party_id')->find();
        if (!$document) return false;
        $legacyRequester = (int)$document['store_id'] > 0 && (int)$document['request_party_id'] === 0;
        $requesterStoreId = $legacyRequester ? (int)$document['store_id']
            : (strtoupper((string)$document['request_party_type']) === 'STORE' ? (int)$document['request_party_id'] : 0);
        $isRequester = $requesterStoreId === $storeId;
        $isSupplier = strtoupper((string)$document['supply_party_type']) === 'STORE' && (int)$document['supply_party_id'] === $storeId;
        if (!$isRequester && !$isSupplier) return false;
        return !($hasTransfer ?? $this->hasLinkedTransfer($requestId));
    }

    /** 平台需同时满足供货方授权与零关联调拨；已取消或冲销的历史调拨也关闭作废。 */
    private function canVoidForPlatform(int $requestId, string $status, string $supplierType, int $supplierId, array $access, ?bool $hasTransfer = null): bool
    {
        // 供货方范围和状态都满足时，仍须具备原生仓库管理授权。
        if (!in_array('inventory.location.manage', (array)$access['features'], true)) return false;
        if (!in_array($status, ['APPLIED', 'PARTIAL', 'DONE'], true)) return false;
        $allowedStores = array_map('intval', (array)($access['store_ids'] ?? []));
        if ($supplierType === 'HQ' && $supplierId !== 0) return false;
        if ($supplierType !== 'HQ' && ($supplierType !== 'STORE' || $supplierId <= 0 || (empty($access['is_super_admin']) && !in_array($supplierId, $allowedStores, true)))) return false;
        return !($hasTransfer ?? $this->hasLinkedTransfer($requestId));
    }

    /** 只看是否曾经生成关联调拨，不能因后来取消或历史冲销而重新开放作废。 */
    private function hasLinkedTransfer(int $requestId): bool
    {
        return (bool)Db::name('inventory_cross_transfer_document')->where('request_document_id', $requestId)->value('id');
    }

    private function scope(int $storeId, int $operatorId): array
    {
        try {
            (new InventoryStoreAccessPolicy())->writableActor($storeId, $operatorId, 'cashier.v3.inventory.request');
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException('inventory_stock_request_query_scope_denied', 0, $exception);
        }
        $location = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
        if (!$location) throw new \RuntimeException('inventory_stock_request_query_scope_denied');
        return ['location_id' => (int)$location['id']];
    }

    /**
     * Platform visibility is scoped by the selected HQ's organization root and
     * then by the account's authorised stores.  The HQ subject itself is always
     * available within that root, while a store subject is never tenant-wide.
     */
    private function headquartersDocumentQuery(array $location, array $access)
    {
        $pathParts = array_values(array_filter(explode('/', trim((string)($location['organization_path'] ?? ''), '/'))));
        $rootId = (int)($pathParts[0] ?? 0);
        if ($rootId <= 0) throw new \RuntimeException('inventory_stock_request_query_scope_denied');
        $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', (array)($access['store_ids'] ?? [])))));
        $isSuperAdmin = !empty($access['is_super_admin']);

        return Db::name('inventory_stock_request_document')
            ->alias('d')
            ->where('d.tenant_id', (string)$location['tenant_id'])
            ->whereLike('d.organization_path', '/' . $rootId . '/%')
            ->where(function ($subjects) use ($location, $isSuperAdmin, $allowedStoreIds): void {
                $subjects->where(function ($hq) use ($location): void {
                    $hq->where('d.request_party_type', 'HQ')
                        ->where('d.request_party_id', 0)
                        ->where('d.store_id', 0)
                        ->where('d.location_id', (int)$location['id']);
                })->whereOr(function ($store) use ($isSuperAdmin, $allowedStoreIds): void {
                    $store->where('d.request_party_type', 'STORE')
                        ->where('d.request_party_id', '>', 0);
                    if (!$isSuperAdmin) {
                        if (!$allowedStoreIds) {
                            $store->where('d.store_id', -1);
                            return;
                        }
                        $store->whereIn('d.store_id', $allowedStoreIds)
                            ->whereIn('d.request_party_id', $allowedStoreIds);
                    }
                })->whereOr(function ($headquartersSupplier): void {
                    $headquartersSupplier->where('d.supply_party_type', 'HQ')->where('d.supply_party_id', 0);
                })->whereOr(function ($supplier) use ($isSuperAdmin, $allowedStoreIds): void {
                    $supplier->where('d.supply_party_type', 'STORE')->where('d.supply_party_id', '>', 0);
                    if (!$isSuperAdmin) $supplier->whereIn('d.supply_party_id', $allowedStoreIds ?: [-1]);
                });
            });
    }

    private function decimal(int $units, int $scale): string
    {
        $scale = max(0, min(4, $scale));
        $sign = $units < 0 ? '-' : '';
        $digits = str_pad((string)abs($units), $scale + 1, '0', STR_PAD_LEFT);
        // 只有小数部分允许裁掉尾零；整数数量的十位、百位等属于真实申请量，不能把 10000 回填成 1。
        if ($scale === 0) return $sign . $digits;
        $fraction = rtrim(substr($digits, -$scale), '0');
        return $sign . substr($digits, 0, -$scale) . ($fraction === '' ? '' : '.' . $fraction);
    }

    private function statusName(string $status): string
    {
        return ['APPLIED' => '申请中', 'PARTIAL' => '部分履约', 'DONE' => '已完成', 'CANCELLED' => '已取消', 'TERMINATED' => '已终止剩余请货', 'VOIDED' => '已作废'][$status] ?? $status;
    }
}
