<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStoreWarehouseServices;
use think\facade\App;

final class InventoryStoreWarehouse extends AuthController
{
    public function __construct(App $app, InventoryStoreWarehouseServices $services) { parent::__construct($app); $this->services = $services; }
    public function index() { return $this->success(['list' => $this->services->list((int)$this->storeId, (int)$this->storeStaffId)]); }
}
