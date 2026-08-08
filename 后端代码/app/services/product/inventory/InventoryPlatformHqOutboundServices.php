<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** Platform-only command and document projection for headquarters outbound. */
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
        $list = $query->fieldRaw("f.source_id AS id, f.source_id AS source_id, COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, MAX(f.recorded_at) AS operation_at, COUNT(f.id) AS detail_count, SUM(f.cost_amount_cents) AS cost_amount_cents, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(b.product_name_snapshot, '商品'), ' / ', IFNULL(b.sku_name_snapshot, '默认规格')) SEPARATOR '、') AS product_summary")
            ->group('f.source_id,n.document_no,f.business_date')->order('operation_at desc')->page($page, $limit)->select()->toArray();
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($list as &$row) {
            $row['order_type_name'] = '总部手工出库';
            if (!$canViewCost) $row['cost_amount_cents'] = null;
        }
        unset($row);
        $list = (new InventoryManualDocumentReversalProjectionServices())->apply(
            $list, (string)$location['tenant_id'], InventoryManualDocumentReversalServices::OUTBOUND, [(int)$location['id']]
        );
        return ['count' => $count, 'list' => $list];
    }

    /** Read-only detail projected from the settled outbound facts. */
    public function detail(array $adminInfo, int $locationId, string $sourceId): array
    {
        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $locationId);
        $access = (array)$resolved['access'];
        $location = (array)$resolved['location'];
        $sourceId = trim($sourceId);
        if ($sourceId === '' || strlen($sourceId) > 96) throw new \InvalidArgumentException('inventory_hq_outbound_detail_invalid');
        $facts = $this->facts($location, 'manual_outbound')->where('f.source_id', $sourceId)
            ->join('inventory_stock s', 's.id=f.stock_id')
            ->fieldRaw("COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, f.recorded_at, b.product_name_snapshot AS product_name, b.sku_name_snapshot AS sku_name, b.barcode_snapshot AS barcode, b.batch_no, b.manufactured_date, b.expire_date, s.stock_unit, s.quantity_scale, f.quantity_units, f.unit_cost_cents, f.cost_amount_cents")
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
        return ['document' => [
            'order_sn' => (string)$facts[0]['order_sn'], 'business_date' => (string)$facts[0]['business_date'],
            'recorded_at' => (int)$facts[0]['recorded_at'], 'location_name' => (string)($location['location_name'] ?? '总部仓'),
            'status_name' => $voided ? '已作废' : '已完成', 'can_void' => $voided === null,
            'void_reason' => $voided ? (string)$voided['reason'] : '', 'voided_at' => $voided ? (int)$voided['settled_at'] : 0,
        ], 'lines' => $facts];
    }

    public function create(array $adminInfo, int $locationId, array $input): array
    {
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $locationId);
        $scope = $hq->scope((array)$resolved['location'], (int)($resolved['access']['admin_id'] ?? 0));
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
