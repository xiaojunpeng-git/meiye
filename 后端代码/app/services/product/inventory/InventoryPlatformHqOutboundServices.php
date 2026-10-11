<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** 平台总部出库写边界及共用详情入口；门店详情复用原事实投影并保持只读。 */
final class InventoryPlatformHqOutboundServices
{
    public function index(array $adminInfo, int $locationId, string $keyword, int $page = 1, int $limit = 20, string $dateFrom = '', string $dateTo = ''): array
    {
        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $locationId);
        $access = (array)$resolved['access'];
        $location = (array)$resolved['location'];
        $keyword = trim($keyword);
        $page = max(1, $page);
        $limit = max(1, min($limit, 100));

        $query = $this->facts((array)$location, 'manual_outbound');
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('f.business_date', [$dateFrom, $dateTo]);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($inner) use ($like): void {
                $inner->whereLike('f.source_id|n.document_no|b.product_name_snapshot|b.sku_name_snapshot', $like);
            });
        }
        $count = count((clone $query)->field('f.source_id')->group('f.source_id')->select()->toArray());
        $list = $query->fieldRaw("f.source_id AS id, f.source_id AS source_id, COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, l.location_name, MAX(f.recorded_at) AS operation_at, MAX(f.operator_name_snapshot) AS operator_name, COUNT(f.id) AS detail_count, SUM(f.cost_amount_cents) AS cost_amount_cents, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(b.product_name_snapshot, '商品'), ' / ', IFNULL(b.sku_name_snapshot, '默认规格')) SEPARATOR '、') AS product_summary")
            ->group('f.source_id,n.document_no,f.business_date,l.location_name')->order('operation_at desc')->page($page, $limit)->select()->toArray();
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($list as &$row) {
            $row['source_type'] = 'manual_outbound';
            $row['order_type_name'] = '总部手工出库';
            if (!$canViewCost) $row['cost_amount_cents'] = null;
        }
        unset($row);
        $list = (new InventoryManualDocumentReversalProjectionServices())->apply(
            $list, (string)$location['tenant_id'], InventoryManualDocumentReversalServices::OUTBOUND, [(int)$location['id']]
        );
        // 冲销状态与平台写授权共同决定按钮，查询菜单本身不提供写能力。
        foreach ($list as &$row) $row['can_void'] = !empty($row['can_void']) && in_array('inventory.location.manage', $access['features'], true);
        unset($row);
        // Shared form attributes must survive read-back for headquarters callers as well.
        $list = (new InventoryOutboundHeaderServices())->apply($list, (string)$location['tenant_id'], [(int)$location['id']]);
        return ['count' => $count, 'list' => $list];
    }

    /** 共用展开支持授权门店；总部仍沿用原鉴权和冲销能力，门店复用原事实投影。 */
    public function detail(array $adminInfo, int $locationId, string $sourceId, array $selection = []): array
    {
        if (($selection['subject'] ?? 'HQ') === 'STORE') {
            return (new InventoryMovementQueryServices())->outboundDetailForPlatform($adminInfo, $sourceId, $selection);
        }
        $resolved = (new InventoryPlatformDocumentReadScope())->resolve($adminInfo, $locationId, 'inventory_outbound', $selection);
        $access = (array)$resolved['access'];
        $location = (array)$resolved['location'];
        $sourceId = trim($sourceId);
        if ($sourceId === '' || strlen($sourceId) > 96) throw new \InvalidArgumentException('inventory_hq_outbound_detail_invalid');
        $facts = $this->facts($location, 'manual_outbound')->where('f.source_id', $sourceId)
            ->join('inventory_stock s', 's.id=f.stock_id')
            ->fieldRaw("COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, f.recorded_at, f.detail_remark, b.product_name_snapshot AS product_name, b.sku_name_snapshot AS sku_name, b.barcode_snapshot AS barcode, b.batch_no, b.manufactured_date, b.expire_date, s.stock_unit, s.quantity_scale, f.quantity_units, f.unit_cost_cents, f.cost_amount_cents")
            ->order('f.id asc')->select()->toArray();
        if (!$facts) throw new \InvalidArgumentException('inventory_hq_outbound_detail_missing');
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($facts as &$fact) {
            $fact['quantity'] = $this->quantity((int)$fact['quantity_units'], (int)$fact['quantity_scale']);
            if (!$canViewCost) { $fact['unit_cost_cents'] = null; $fact['cost_amount_cents'] = null; }
        }
        unset($fact);
        $reversal = (new InventoryManualDocumentReversalProjectionServices())->settledIndex(
            (string)$location['tenant_id'], InventoryManualDocumentReversalServices::OUTBOUND, [$sourceId], [(int)$location['id']]
        );
        $voided = $reversal[$sourceId] ?? null;
        $document = [
            'source_type' => 'manual_outbound', 'source_id' => $sourceId, 'order_type_name' => '总部手工出库',
            'order_sn' => (string)$facts[0]['order_sn'], 'business_date' => (string)$facts[0]['business_date'],
            'recorded_at' => (int)$facts[0]['recorded_at'], 'location_name' => (string)($location['location_name'] ?? '总部仓'),
            // 详情与列表保持相同的作废授权，不改变真实冲销命令。
            'status_name' => $voided ? '已作废' : '已完成', 'can_void' => $voided === null && in_array('inventory.location.manage', $access['features'], true),
            'void_reason' => $voided ? (string)$voided['reason'] : '', 'voided_at' => $voided ? (int)$voided['settled_at'] : 0,
        ];
        $document = (new InventoryOutboundHeaderServices())->apply([$document], (string)$location['tenant_id'], [(int)$location['id']])[0];
        return ['document' => $document, 'lines' => $facts];
    }

    public function create(array $adminInfo, int $locationId, array $input): array
    {
        $hq = new InventoryHqLocationServices();
        // 添加出库单功能只授权本次新建，不替代其他库存操作权限。
        $resolved = $hq->writableLocation($adminInfo, $locationId, 'outbound');
        $scope = $hq->scope((array)$resolved['location'], (int)($resolved['access']['admin_id'] ?? 0));
        $scope['operatorType'] = 'PLATFORM_ADMIN';
        $scope['operatorName'] = mb_substr(trim((string)($adminInfo['real_name'] ?? $adminInfo['name'] ?? $adminInfo['account'] ?? '')), 0, 128);
        return (new InventoryManualOutboundServices())->createForHeadquarters($scope, (array)$resolved['location'], $input);
    }

    public function reverse(array $adminInfo, int $locationId, array $input): array
    {
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $locationId);
        $scope = $hq->scope((array)$resolved['location'], (int)($resolved['access']['admin_id'] ?? 0));
        return (new InventoryManualDocumentReversalServices())->reverseForHeadquarters(
            $scope, (array)$resolved['location'], InventoryManualDocumentReversalServices::OUTBOUND, $input
        );
    }

    private function facts(array $location, string $sourceType)
    {
        return Db::name('inventory_batch_movement_fact')->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')
            ->leftJoin('inventory_location l', 'l.id=f.location_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', (string)$location['tenant_id'])->where('f.store_id', 0)
            ->where('f.location_id', (int)$location['id'])->where('f.fact_status', 'SETTLED')->where('f.source_type', $sourceType);
    }

    private function quantity(int $units, int $scale): string
    {
        $scale = max(0, min(4, $scale));
        if ($scale === 0) return (string)$units;
        $digits = str_pad((string)$units, $scale + 1, '0', STR_PAD_LEFT);
        return rtrim(rtrim(substr($digits, 0, -$scale) . '.' . substr($digits, -$scale), '0'), '.');
    }
}
