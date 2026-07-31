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
    public function index() { $data = $this->request->getMore([['keyword', ''], ['page', 1], ['limit', 20]]); $canViewCost = in_array('inventory.cost.view', (new InventoryStoreAccessPolicy())->features((int)$this->storeId, (int)$this->storeStaffId), true); return $this->success($this->services->list((int)$this->storeId, (int)$this->storeStaffId, (string)$data['keyword'], (int)$data['page'], (int)$data['limit'], $canViewCost)); }
}
