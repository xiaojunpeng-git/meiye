<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\product\inventory\query\InventoryBatchStockQueryProvider;
use think\facade\Db;

/** Read-only catalog for the platform-owned headquarters inventory subject. */
final class InventoryPlatformHqCatalogServices
{
    /** Resolve one HQ SKU by barcode; scanning must never depend on a catalog page. */
    public function barcode(array $adminInfo, int $locationId, string $barcode, string $sourcePartyType = 'HQ', int $sourceStoreId = 0, string $availabilityPartyType = '', int $availabilityStoreId = 0): array
    {
        $source = $this->source($adminInfo, $locationId, $sourcePartyType, $sourceStoreId);
        $availabilitySource = $availabilityPartyType === ''
            ? $source
            : $this->source($adminInfo, $locationId, $availabilityPartyType, $availabilityStoreId);
        $barcode = trim($barcode);
        if ($barcode === '' || mb_strlen($barcode) > 64) throw new \InvalidArgumentException('inventory_catalog_barcode_invalid');
        $rows = Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')
            ->where('p.type', $source['type'] === 'HQ' ? 0 : 1)->where('p.relation_id', $source['type'] === 'HQ' ? 0 : $source['storeId'])->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.type', 0)
            ->where(function ($query) use ($barcode): void {
                // Physical scanners may send the product code or SKU code when
                // the source barcode field has not been populated.
                $query->where('a.bar_code', $barcode)
                    ->whereOr('p.bar_code', $barcode)
                    ->whereOr('a.code', $barcode)
                    ->whereOr('p.code', $barcode);
            })
            ->fieldRaw("p.id AS product_id,p.pid AS parent_product_id,p.store_name AS product_name,p.code AS product_code,a.id AS sku_id,a.unique AS sku_unique,a.suk AS sku_name,COALESCE(NULLIF(a.bar_code, ''), p.bar_code) AS barcode,a.code AS sku_code,a.stock_unit")
            ->group('a.id')->order('a.id asc')->limit(2)->select()->toArray();
        if (!$rows) throw new \RuntimeException('inventory_catalog_barcode_not_found');
        if (count($rows) !== 1) throw new \RuntimeException('inventory_catalog_barcode_ambiguous');
        return $this->withSupplierAvailableQuantity($source, $availabilitySource, $rows)[0];
    }

    public function search(array $adminInfo, int $locationId, string $keyword, int $page = 1, int $limit = 20, string $sourcePartyType = 'HQ', int $sourceStoreId = 0, string $availabilityPartyType = '', int $availabilityStoreId = 0): array
    {
        // Resolve the selected source subject first. The catalog is never a
        // tenant-wide anonymous product search, even though it contains no stock figures.
        $source = $this->source($adminInfo, $locationId, $sourcePartyType, $sourceStoreId);
        $availabilitySource = $availabilityPartyType === ''
            ? $source
            : $this->source($adminInfo, $locationId, $availabilityPartyType, $availabilityStoreId);

        $keyword = trim($keyword);
        if (mb_strlen($keyword) > 64) {
            throw new \InvalidArgumentException('inventory_catalog_search_invalid');
        }
        $page = max(1, $page);
        $limit = max(1, min($limit, 50));

        $query = Db::name('store_product_attr_value')
            ->alias('a')
            ->join('store_product p', 'p.id=a.product_id')
            ->where('p.type', $source['type'] === 'HQ' ? 0 : 1)
            ->where('p.relation_id', $source['type'] === 'HQ' ? 0 : $source['storeId'])
            ->where('p.is_del', 0)
            ->where('p.is_inventory', 1)
            ->where('a.type', 0)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $like = '%' . $keyword . '%';
                $query->where(function ($keywordQuery) use ($like): void {
                    $keywordQuery->whereLike('a.bar_code|a.code|a.unique', $like)
                        ->whereOr('p.store_name|p.keyword|p.bar_code|p.code', 'like', $like);
                });
            });

        $total = (int)(clone $query)->count('DISTINCT a.id');
        $list = $query
            ->field([
                'p.id' => 'product_id',
                'p.pid' => 'parent_product_id',
                'p.store_name' => 'product_name',
                'p.code' => 'product_code',
                'a.id' => 'sku_id',
                'a.unique' => 'sku_unique',
                'a.suk' => 'sku_name',
                'a.bar_code' => 'barcode',
                'a.code' => 'sku_code',
                'a.stock_unit',
            ])
            ->group('a.id')
            ->order('p.sort desc,p.id desc,a.id asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return [
            'list' => $this->withSupplierAvailableQuantity($source, $availabilitySource, $list),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            // HQ inventory does not maintain a separate category hierarchy.
            'categories' => [],
        ];
    }

    private function source(array $adminInfo, int $locationId, string $sourcePartyType, int $sourceStoreId): array
    {
        $resolved = (new InventoryHqLocationServices())->writableLocation($adminInfo, $locationId);
        $location = (array)$resolved['location'];
        $access = (array)$resolved['access'];
        $type = strtoupper(trim($sourcePartyType));
        if ($type === 'HQ' && $sourceStoreId === 0) {
            return ['type' => 'HQ', 'storeId' => 0, 'locationId' => (int)$location['id'], 'tenantId' => (string)$location['tenant_id'], 'features' => (array)($access['features'] ?? [])];
        }
        if ($type !== 'STORE' || $sourceStoreId <= 0) throw new \InvalidArgumentException('inventory_catalog_source_invalid');
        if (empty($access['is_super_admin']) && !in_array($sourceStoreId, array_map('intval', (array)($access['store_ids'] ?? [])), true)) throw new \RuntimeException('inventory_catalog_source_scope_denied');
        $store = Db::name('system_store')->where('id', $sourceStoreId)->where('is_del', 0)->where('is_show', 1)->field('id')->find();
        $storeLocation = Db::name('inventory_location')
            ->where('tenant_id', (string)$location['tenant_id'])
            ->where('store_id', $sourceStoreId)
            ->where('location_type', 'STORE')
            ->where('is_default', 1)
            ->where('location_status', 'ACTIVE')
            ->field('id,organization_path')
            ->find();
        if (!$store || !$storeLocation) throw new \RuntimeException('inventory_catalog_source_scope_denied');
        $hqRoot = explode('/', trim((string)($location['organization_path'] ?? ''), '/'))[0] ?? '';
        $storeRoot = explode('/', trim((string)($storeLocation['organization_path'] ?? ''), '/'))[0] ?? '';
        if ($hqRoot === '' || $hqRoot !== $storeRoot) throw new \RuntimeException('inventory_catalog_source_scope_denied');
        return ['type' => 'STORE', 'storeId' => $sourceStoreId, 'locationId' => (int)$storeLocation['id'], 'tenantId' => (string)$location['tenant_id'], 'features' => (array)($access['features'] ?? [])];
    }

    /** Decorate catalog rows from settled inventory facts; never infer stock in the browser. */
    private function withAvailableQuantity(array $source, array $rows): array
    {
        if (!$rows) return [];
        $features = array_values(array_unique(array_merge(
            (array)($source['features'] ?? []),
            [InventoryBatchStockQueryContract::PERMISSION_VIEW]
        )));
        $scope = new InventoryBatchStockDataScope(
            (string)$source['tenantId'],
            [(int)$source['locationId']],
            $features
        );
        $stockRows = (new InventoryBatchStockQueryProvider())->sourceRows([
            'queryCutoffDate' => date('Y-m-d'),
            'locationIds' => [(int)$source['locationId']],
            'includeZero' => false,
        ], $scope);
        $available = [];
        foreach ($stockRows as $stockRow) {
            $available[(int)$stockRow['product_id'] . ':' . (int)$stockRow['sku_id']] = (string)$stockRow['available_quantity'];
        }
        foreach ($rows as &$row) {
            $key = (int)$row['product_id'] . ':' . (int)$row['sku_id'];
            $row['available_quantity'] = $available[$key] ?? '0';
        }
        unset($row);
        return $rows;
    }

    /**
     * Request lines keep the request-party catalogue identity, while displayed
     * availability comes from the selected supplier. Match the two subjects by
     * their shared base product and SKU name, never by an unscoped browser value.
     */
    private function withSupplierAvailableQuantity(array $catalogSource, array $availabilitySource, array $rows): array
    {
        if (!$rows) return [];

        $baseProductIds = array_values(array_unique(array_filter(array_map(
            fn (array $row): int => $this->baseProductId($catalogSource, $row),
            $rows
        ))));
        if (!$baseProductIds) {
            foreach ($rows as &$row) $row['available_quantity'] = '0';
            unset($row);
            return $rows;
        }

        $supplierRows = Db::name('store_product_attr_value')->alias('a')
            ->join('store_product p', 'p.id=a.product_id')
            ->where('p.type', $availabilitySource['type'] === 'HQ' ? 0 : 1)
            ->where('p.relation_id', $availabilitySource['type'] === 'HQ' ? 0 : $availabilitySource['storeId'])
            ->where('p.is_del', 0)
            ->where('p.is_inventory', 1)
            ->where('a.type', 0)
            ->whereIn($availabilitySource['type'] === 'HQ' ? 'p.id' : 'p.pid', $baseProductIds)
            ->fieldRaw('p.id AS product_id,p.pid AS parent_product_id,a.id AS sku_id,a.suk AS sku_name')
            ->select()
            ->toArray();
        $supplierRows = $this->withAvailableQuantity($availabilitySource, $supplierRows);
        $availability = [];
        foreach ($supplierRows as $supplierRow) {
            $availability[$this->baseProductId($availabilitySource, $supplierRow) . ':' . (string)$supplierRow['sku_name']] = (string)$supplierRow['available_quantity'];
        }
        foreach ($rows as &$row) {
            $key = $this->baseProductId($catalogSource, $row) . ':' . (string)$row['sku_name'];
            $row['available_quantity'] = $availability[$key] ?? '0';
        }
        unset($row);
        return $rows;
    }

    private function baseProductId(array $source, array $row): int
    {
        return $source['type'] === 'HQ'
            ? (int)($row['product_id'] ?? 0)
            : (int)($row['parent_product_id'] ?? 0);
    }
}
