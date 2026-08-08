<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Read model for the V3 request authority; legacy request drafts are excluded. */
final class InventoryStockRequestQueryServices
{
    public function listForHeadquarters(array $adminInfo, int $locationId, string $keyword, int $page = 1, int $limit = 20, string $requestPartyType = '', int $requestPartyId = 0, string $status = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $locationId);
        $location = (array)$resolved['location']; $access = (array)$resolved['access'];
        // A platform request may be raised by the headquarters subject or by
        // an authorised store subject. Both belong to this organization root;
        // limiting this read to the HQ location made successfully created store
        // requests disappear immediately after submission.
        $query = $this->headquartersDocumentQuery($location, $access)
            ->leftJoin('inventory_stock_request_line l', 'l.document_id=d.id');
        $keyword = trim($keyword); if ($keyword !== '') { $like = '%' . $keyword . '%'; $query->where(function ($inner) use ($like): void { $inner->whereLike('d.request_no', $like)->whereOr('d.remark', 'like', $like); }); }
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('d.business_date', [$dateFrom, $dateTo]);
        if (in_array(strtoupper(trim($requestPartyType)), ['HQ', 'STORE'], true)) {
            $query->where('d.request_party_type', strtoupper(trim($requestPartyType)))->where('d.request_party_id', $requestPartyId);
        }
        if ($status !== '') $query->where('d.document_status', $status);
        $count = (int)(clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.request_no order_sn,d.document_status status_name,d.request_party_name_snapshot request_party_name,d.requester_name_snapshot,d.operator_name_snapshot,d.supply_party_type,d.supply_party_id,d.supply_party_name_snapshot supply_party_name,d.business_date request_date,d.recorded_at add_time,d.recorded_at operation_at,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.reference_unit_cost_cents)/POW(10,l.quantity_scale)) estimated_amount_cents')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($list as &$row) { $status=(string)$row['status_name']; $row['can_cancel']=$status==='APPLIED'; $row['can_terminate']=$status==='PARTIAL'; if (!$canViewCost) $row['estimated_amount_cents'] = null; $row['status_name'] = $this->statusName($status); }
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
        return ['document' => $document, 'lines' => $lines, 'can_edit' => false, 'can_cancel' => (string)$document['document_status'] === 'APPLIED', 'can_terminate' => (string)$document['document_status'] === 'PARTIAL'];
    }

    public function list(int $storeId, int $operatorId, string $keyword, int $page = 1, int $limit = 20, bool $canViewCost = false, string $dateFrom = '', string $dateTo = ''): array
    {
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        $location = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
        if (!$staff || !$location) throw new \RuntimeException('inventory_stock_request_query_scope_denied');
        $query = Db::name('inventory_stock_request_document')->alias('d')->leftJoin('inventory_stock_request_line l', 'l.document_id=d.id')->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('d.store_id', $storeId)->where('d.location_id', (int)$location['id']);
        $keyword = trim($keyword);
        if ($keyword !== '') { $like = '%' . $keyword . '%'; $query->where(function ($inner) use ($like): void { $inner->whereLike('d.request_no', $like)->whereOr('d.remark', 'like', $like); }); }
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('d.business_date', [$dateFrom, $dateTo]);
        $count = (clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.request_no order_sn,d.document_status status_name,d.store_id,s.name request_store_name,d.requester_name_snapshot,d.operator_name_snapshot,d.supply_party_type,d.supply_party_id,d.supply_party_name_snapshot supply_party_name,d.business_date request_date,d.recorded_at add_time,d.recorded_at operation_at,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.reference_unit_cost_cents)/POW(10,l.quantity_scale)) estimated_amount_cents')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        foreach ($list as &$row) {
            $documentStatus = (string)$row['status_name'];
            $row['can_edit'] = $documentStatus === 'APPLIED'
                && !(bool)Db::name('inventory_cross_transfer_document')
                    ->where('request_document_id', (int)$row['id'])
                    ->whereNotIn('document_status', ['CANCELLED', 'REVERSED'])
                    ->value('id');
            $row['can_cancel'] = $documentStatus === 'APPLIED';
            $row['can_terminate'] = $documentStatus === 'PARTIAL';
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
            ->where('d.store_id', $storeId)
            ->where('d.location_id', (int)$scope['location_id'])
            ->where('d.id', $requestId)
            ->field('d.id,d.request_no,d.document_status,d.store_id,s.name request_store_name,d.requester_name_snapshot,d.operator_name_snapshot,d.supply_party_type,d.supply_party_id,d.supply_party_name_snapshot,d.business_date,d.applied_at,d.recorded_at,d.remark')
            ->find();
        if (!$document) throw new \RuntimeException('inventory_stock_request_not_found');
        $document['status_name'] = $this->statusName((string)$document['document_status']);

        $lines = Db::name('inventory_stock_request_line')
            ->where('document_id', $requestId)
            ->order('line_no asc')
            ->field('id,line_no,product_id,sku_id,sku_unique,product_name_snapshot,sku_name_snapshot,product_code_snapshot,barcode_snapshot,stock_unit_snapshot,quantity_scale,requested_quantity_units,reference_unit_cost_cents,reference_cost_status,fulfilled_transfer_id')
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
        return [
            'document' => $document,
            'lines' => $lines,
            'can_edit' => (string)$document['document_status'] === 'APPLIED'
                && !$hasTransfer
                && !array_filter($lines, static fn(array $line): bool => (int)$line['fulfilled_transfer_id'] > 0),
            'can_cancel' => (string)$document['document_status'] === 'APPLIED',
            'can_terminate' => (string)$document['document_status'] === 'PARTIAL',
        ];
    }

    private function scope(int $storeId, int $operatorId): array
    {
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        $location = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
        if (!$staff || !$location) throw new \RuntimeException('inventory_stock_request_query_scope_denied');
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
                });
            });
    }

    private function decimal(int $units, int $scale): string
    {
        $value = $units / (10 ** $scale);
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.') ?: '0';
    }

    private function statusName(string $status): string
    {
        return ['APPLIED' => '申请中', 'PARTIAL' => '部分履约', 'DONE' => '已完成', 'CANCELLED' => '已取消', 'TERMINATED' => '已终止剩余请货'][$status] ?? $status;
    }
}
