<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryMovementAnalyticsServices;
use app\services\product\inventory\InventoryStoreAccessPolicy;
use think\facade\App;

final class InventoryMovementAnalytics extends AuthController
{
    public function __construct(App $app, InventoryMovementAnalyticsServices $services) { parent::__construct($app); $this->services = $services; }
    public function index()
    {
        $input = $this->request->getMore([['kind', 'inbound'], ['from', date('Y-m-01')], ['to', date('Y-m-d')]]);
        $canViewCost = in_array('inventory.cost.view', (new InventoryStoreAccessPolicy())->features((int)$this->storeId, (int)$this->storeStaffId), true);
        return $this->success($this->services->list((int)$this->storeId, (int)$this->storeStaffId, (string)$input['kind'], (string)$input['from'], (string)$input['to'], $canViewCost));
    }
}
