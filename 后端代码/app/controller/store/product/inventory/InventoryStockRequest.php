<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStockRequestServices;
use think\facade\App;

final class InventoryStockRequest extends AuthController
{
    public function __construct(App $app, InventoryStockRequestServices $services) { parent::__construct($app); $this->services = $services; }
    public function apply() { $data = $this->request->postMore([['idempotency_key',''], ['business_date',''], ['remark',''], ['lines',[]]]); return $this->success($this->services->apply((int)$this->storeId, (int)$this->storeStaffId, $data)); }
    public function cancel($id) { return $this->success($this->services->cancel((int)$this->storeId, (int)$this->storeStaffId, (int)$id)); }
}
