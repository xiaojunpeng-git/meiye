<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryBatchTransferServices;
use app\services\product\inventory\InventoryStoreAccessPolicy;
use app\services\product\inventory\InventoryV3RolloutPolicy;
use think\facade\App;

final class InventoryBatchTransfer extends AuthController
{
    public function __construct(App $app, InventoryBatchTransferServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function create()
    {
        if (!InventoryV3RolloutPolicy::MULTI_WAREHOUSE_ENABLED) {
            return $this->fail('多仓库调拨本期暂不开放。', ['code' => InventoryV3RolloutPolicy::MULTI_WAREHOUSE_DEFERRED_CODE]);
        }
        $input = $this->request->postMore([
            ['idempotency_key', ''], ['business_date', ''], ['remark', ''],
            ['target_location_id', 0], ['lines', []],
        ]);
        return $this->success($this->services->create((int)$this->storeId, (int)$this->storeStaffId, $input));
    }

    public function index()
    {
        if (!InventoryV3RolloutPolicy::MULTI_WAREHOUSE_ENABLED) {
            return $this->fail('多仓库调拨本期暂不开放。', ['code' => InventoryV3RolloutPolicy::MULTI_WAREHOUSE_DEFERRED_CODE]);
        }
        $input = $this->request->getMore([['keyword', ''], ['page', 1], ['limit', 20]]);
        $canViewCost = in_array('inventory.cost.view', (new InventoryStoreAccessPolicy())->features((int)$this->storeId, (int)$this->storeStaffId), true);
        return $this->success($this->services->list((int)$this->storeId, (int)$this->storeStaffId, (string)$input['keyword'], (int)$input['page'], (int)$input['limit'], $canViewCost));
    }
}
