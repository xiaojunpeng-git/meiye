<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\query\InventoryBatchStockQueryProvider;
use app\services\product\inventory\query\InventoryBatchReportProjectionServices;
use app\services\product\inventory\query\InventoryStoreBatchScopeResolver;
use think\facade\App;
use think\facade\Db;

/** Store-owned read endpoint for the new batch inventory authority. */
class InventoryBatchStockQuery extends AuthController
{
    public function __construct(App $app, InventoryBatchStockQueryProvider $provider)
    {
        parent::__construct($app);
        $this->services = $provider;
    }

    public function index(InventoryStoreBatchScopeResolver $scopeResolver)
    {
        [$cutoffDate, $includeZero] = $this->request->getMore([
            ['query_cutoff_date', date('Y-m-d')],
            ['include_zero', 0],
        ], true);
        $storeId = (int)$this->storeId;
        $locations = Db::name('inventory_location')
            ->where('store_id', $storeId)
            ->where('location_status', 'ACTIVE')
            ->field('id,tenant_id,store_id,location_status')
            ->select()
            ->toArray();
        if (!$locations) {
            return $this->success(['list' => [], 'count' => 0, 'query_cutoff_date' => (string)$cutoffDate]);
        }
        $scope = $scopeResolver->resolve($storeId, $locations, $this->canViewCost());
        $rows = $this->services->sourceRows([
            'queryCutoffDate' => (string)$cutoffDate,
            'locationIds' => [],
            'includeZero' => (int)$includeZero === 1,
        ], $scope);
        return $this->success([
            'list' => $rows,
            'count' => count($rows),
            'query_cutoff_date' => (string)$cutoffDate,
        ]);
    }

    public function analysis(
        InventoryStoreBatchScopeResolver $scopeResolver,
        InventoryBatchReportProjectionServices $projectionServices
    ) {
        [$cutoffDate, $includeZero] = $this->request->getMore([
            ['query_cutoff_date', date('Y-m-d')],
            ['include_zero', 0],
        ], true);
        $storeId = (int)$this->storeId;
        $locations = Db::name('inventory_location')
            ->where('store_id', $storeId)
            ->where('location_status', 'ACTIVE')
            ->field('id,tenant_id,store_id,location_status')
            ->select()
            ->toArray();
        if (!$locations) {
            return $this->success([
                'list' => [], 'count' => 0, 'expiry_buckets' => [], 'age_buckets' => [],
                'query_cutoff_date' => (string)$cutoffDate,
            ]);
        }
        $scope = $scopeResolver->resolve($storeId, $locations, $this->canViewCost());
        $rows = $this->services->sourceRows([
            'queryCutoffDate' => (string)$cutoffDate,
            'locationIds' => [],
            'includeZero' => (int)$includeZero === 1,
        ], $scope);
        return $this->success($projectionServices->project($rows, (string)$cutoffDate));
    }

    private function canViewCost(): bool
    {
        return in_array(
            'inventory.cost.view',
            (new \app\services\product\inventory\InventoryStoreAccessPolicy())->features((int)$this->storeId, (int)$this->storeStaffId),
            true
        );
    }
}
