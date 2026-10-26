<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStockRequestQueryServices;
use app\services\product\inventory\InventoryStoreAccessPolicy;
use think\facade\App;

final class InventoryStockRequestQuery extends AuthController
{
    public function __construct(App $app, InventoryStockRequestQueryServices $services) { parent::__construct($app); $this->services = $services; }
    public function index() { $data = $this->request->getMore([['keyword', ''], ['page', 1], ['limit', 20]]); $canViewCost = (new InventoryStoreAccessPolicy())->canViewCostForFeature((int)$this->storeId, (int)$this->storeStaffId, 'cashier.v3.inventory.request'); return $this->success($this->services->list((int)$this->storeId, (int)$this->storeStaffId, (string)$data['keyword'], (int)$data['page'], (int)$data['limit'], $canViewCost)); }
    public function detail(int $id) { $canViewCost = (new InventoryStoreAccessPolicy())->canViewCostForFeature((int)$this->storeId, (int)$this->storeStaffId, 'cashier.v3.inventory.request'); return $this->success($this->services->detail((int)$this->storeId, (int)$this->storeStaffId, $id, $canViewCost)); }
}
