<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** Read-only projection of one confirmed headquarters stock count document. */
final class InventoryPlatformHqStockCountQueryServices
{
    /** 平台权限先约束总部仓和单据，再交由共用投影读取不可变盘点明细。 */
    public function detail(array $adminInfo, int $locationId, int $documentId): array
    {
        if ($documentId <= 0) throw new \InvalidArgumentException('inventory_hq_count_detail_invalid');

        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $locationId);
        $access = (array)$resolved['access'];
        $location = (array)$resolved['location'];
        $document = Db::name('inventory_stock_count_document')
            ->where('tenant_id', (string)$location['tenant_id'])
            ->where('location_id', (int)$location['id'])
            ->where('id', $documentId)
            ->find();
        if (!$document) throw new \InvalidArgumentException('inventory_hq_count_detail_missing');

        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        return (new InventoryStockCountDetailProjectionServices())->project($document, $location, $canViewCost);
    }
}
