<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** 平台总部仓写边界；单据读取可缩小到授权门店仓，绝不扩展总部写命令。 */
final class InventoryPlatformHqInboundServices
{
    public function index(array $adminInfo, int $locationId, string $keyword, int $page = 1, int $limit = 20, string $dateFrom = '', string $dateTo = ''): array
    {
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->readableLocation($adminInfo, $locationId);
        $access = (array)$resolved['access'];
        $location = (array)$resolved['location'];
        $keyword = trim($keyword);
        $page = max(1, $page);
        $limit = max(1, min($limit, 100));

        $query = Db::name('inventory_batch_movement_fact')->alias('f')
            ->leftJoin('inventory_batch b', 'b.id=f.batch_id')
            ->leftJoin('inventory_location l', 'l.id=f.location_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', (string)$location['tenant_id'])
            ->where('f.store_id', 0)
            ->where('f.location_id', (int)$location['id'])
            ->where('f.fact_status', 'SETTLED')
            ->where('f.source_type', 'manual_inbound');
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('f.business_date', [$dateFrom, $dateTo]);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($inner) use ($like): void {
                $inner->whereLike('f.source_id|n.document_no|b.product_name_snapshot|b.sku_name_snapshot', $like);
            });
        }
        $grouped = (clone $query)->field('f.source_id')->group('f.source_id')->select()->toArray();
        $list = $query
            ->fieldRaw("f.source_id AS id, f.source_id AS source_id, COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, l.location_name, MAX(f.recorded_at) AS add_time, MAX(f.recorded_at) AS operation_at, MAX(f.operator_name_snapshot) AS operator_name, COUNT(f.id) AS detail_count, SUM(f.cost_amount_cents) AS cost_amount_cents, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(b.product_name_snapshot, '商品'), ' / ', IFNULL(b.sku_name_snapshot, '默认规格')) SEPARATOR '、') AS product_summary")
            ->group('f.source_id,n.document_no,f.business_date,l.location_name')
            ->order('add_time desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($list as &$row) {
            $row['order_type_name'] = '总部手工入库';
            if (!$canViewCost) $row['cost_amount_cents'] = null;
        }
        unset($row);
        $list = (new InventoryManualDocumentReversalProjectionServices())->apply(
            $list, (string)$location['tenant_id'], InventoryManualDocumentReversalServices::INBOUND, [(int)$location['id']]
        );
        // 页面入口只授权本页入库作废；业务状态与仓库范围仍由服务端控制。
        $canVoidInbound = (new InventoryPlatformAccessPolicy())->canVoidHqInbound($access);
        foreach ($list as &$row) $row['can_void'] = !empty($row['can_void']) && $canVoidInbound;
        unset($row);
        return ['count' => count($grouped), 'list' => $list];
    }

    /** 同一事实投影服务总部及授权门店展开；selection 仅缩小读取范围，不授予写能力。 */
    public function detail(array $adminInfo, int $locationId, string $sourceId, array $selection = []): array
    {
        $resolved = (new InventoryPlatformDocumentReadScope())->resolve($adminInfo, $locationId, 'inventory_inbound', $selection);
        $access = (array)$resolved['access'];
        $location = (array)$resolved['location'];
        $sourceId = trim($sourceId);
        if ($sourceId === '' || strlen($sourceId) > 96) throw new \InvalidArgumentException('inventory_hq_inbound_detail_invalid');

        $facts = Db::name('inventory_batch_movement_fact')->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')
            ->join('inventory_stock s', 's.id=f.stock_id')
            ->leftJoin('store_product p', 'p.id=s.consumable_product_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', (string)$location['tenant_id'])
            ->where('f.location_id', (int)$location['id'])
            ->where('f.store_id', (int)($location['store_id'] ?? 0))
            ->where('f.source_type', 'manual_inbound')
            ->where('f.source_id', $sourceId)
            ->where('f.fact_status', 'SETTLED')
            // Stable fact ID is returned only as a note target, not as permission to mutate the stock fact.
            // 仅向已授权详情补充当前规格身份，供新建复制表单使用。
            ->fieldRaw("f.id, s.consumable_product_id AS product_id, s.sku_id, s.product_unique AS sku_unique, COALESCE(p.no_expiry_management, 0) AS no_expiry_management, COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, f.recorded_at, f.detail_remark, b.product_name_snapshot AS product_name, b.sku_name_snapshot AS sku_name, b.barcode_snapshot AS barcode, b.batch_no, b.manufactured_date, b.expire_date, s.stock_unit, s.quantity_scale, f.quantity_units, f.unit_cost_cents, f.cost_amount_cents")
            ->order('f.id asc')
            ->select()->toArray();
        if (!$facts) throw new \InvalidArgumentException('inventory_hq_inbound_detail_missing');
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($facts as &$fact) {
            $fact['quantity'] = $this->quantity((int)$fact['quantity_units'], (int)$fact['quantity_scale']);
            if (!$canViewCost) {
                $fact['unit_cost_cents'] = null;
                $fact['cost_amount_cents'] = null;
            }
        }
        unset($fact);
        $reversal = (new InventoryManualDocumentReversalProjectionServices())->settledIndex(
            (string)$location['tenant_id'], InventoryManualDocumentReversalServices::INBOUND, [$sourceId], [(int)$location['id']]
        );
        $voided = $reversal[$sourceId] ?? null;
        return [
            'document' => [
                'order_sn' => (string)$facts[0]['order_sn'],
                'business_date' => (string)$facts[0]['business_date'],
                'recorded_at' => (int)$facts[0]['recorded_at'],
                'location_name' => (string)($location['location_name'] ?? '总部仓'),
                'status_name' => $voided ? '已作废' : '已完成',
                // 详情与列表采用同一入库入口授权，不借此开放其他库存命令。
                'can_void' => $voided === null && (new InventoryPlatformAccessPolicy())->canVoidHqInbound($access),
                'void_reason' => $voided ? (string)$voided['reason'] : '',
                'voided_at' => $voided ? (int)$voided['settled_at'] : 0,
            ],
            'lines' => $facts,
        ];
    }

    /** Effective outbound movements for batches originating in one HQ inbound document. */
    public function outboundDetails(array $adminInfo, int $locationId, string $sourceId, array $selection = []): array
    {
        // 出库追溯必须继承与入库展开相同的授权仓库，不能退回默认总部仓。
        $resolved = (new InventoryPlatformDocumentReadScope())->resolve($adminInfo, $locationId, 'inventory_inbound', $selection);
        $access = (array)$resolved['access'];
        return (new InventoryInboundOutboundTraceReadServices())->detail(
            (array)$resolved['location'], (int)($resolved['location']['store_id'] ?? 0), $sourceId,
            in_array('inventory.cost.view', (array)($access['features'] ?? []), true)
        );
    }

    public function create(array $adminInfo, int $locationId, array $input): array
    {
        $hq = new InventoryHqLocationServices();
        // 添加节点仅用于新建；作废按入库页面授权，并在冲销事务内核对库存。
        $resolved = $hq->writableLocation($adminInfo, $locationId, 'inbound');
        $scope = $hq->scope($resolved['location'], (int)($resolved['access']['admin_id'] ?? 0));
        $scope['operatorType'] = 'PLATFORM_ADMIN';
        $scope['operatorName'] = mb_substr(trim((string)($adminInfo['real_name'] ?? $adminInfo['name'] ?? $adminInfo['account'] ?? '')), 0, 128);
        return (new InventoryManualInboundServices())->createForHeadquarters($scope, $resolved['location'], $input);
    }

    public function reverse(array $adminInfo, int $locationId, array $input): array
    {
        $hq = new InventoryHqLocationServices();
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->resolve($adminInfo);
        $policy->assertCanVoidHqInbound($access);
        // 作废仅能操作当前账号可读的总部仓；冲销内再次锁仓并校验启用状态与库存。
        $resolved = $hq->readableLocation($adminInfo, $locationId);
        $scope = $hq->scope((array)$resolved['location'], (int)($resolved['access']['admin_id'] ?? 0));
        return (new InventoryManualDocumentReversalServices())->reverseForHeadquarters(
            $scope, (array)$resolved['location'], InventoryManualDocumentReversalServices::INBOUND, $input
        );
    }

    private function quantity(int $units, int $scale): string
    {
        $scale = max(0, min(4, $scale));
        if ($scale === 0) return (string)$units;
        $digits = str_pad((string)$units, $scale + 1, '0', STR_PAD_LEFT);
        return rtrim(rtrim(substr($digits, 0, -$scale) . '.' . substr($digits, -$scale), '0'), '.');
    }
}
