<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryManualOutboundServices;
use think\facade\App;

class InventoryManualOutbound extends AuthController
{
    public function __construct(App $app, InventoryManualOutboundServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function create()
    {
        $data = $this->request->postMore([
            ['idempotency_key', ''], ['business_date', ''], ['remark', ''], ['lines', []],
        ]);
        return $this->success($this->services->create((int)$this->storeId, (int)$this->storeStaffId, $data));
    }
}
