<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStoreAccessPolicy;
use app\services\product\inventory\InventoryStoreReadModelServices;
use think\facade\App;

final class InventoryStoreReadModel extends AuthController
{
    public function __construct(App $app, InventoryStoreReadModelServices $services) { parent::__construct($app); $this->services = $services; }
    /** 首页和库存明细分别按自己的功能使用权限展示成本，不再默认放开首页金额。 */
    public function dashboard() { return $this->success($this->services->dashboard((int)$this->storeId, (int)$this->storeStaffId, $this->canViewCost('overview'))); }
    public function productSummary() { $input = $this->request->getMore([['keyword', ''], ['page', 1], ['limit', 20]]); return $this->success($this->services->productSummary((int)$this->storeId, (int)$this->storeStaffId, (string)$input['keyword'], (int)$input['page'], (int)$input['limit'], $this->canViewCost('stock'))); }
    public function productDetail(int $productId) { return $this->success($this->services->productDetail((int)$this->storeId, (int)$this->storeStaffId, $productId, $this->canViewCost('stock'))); }
    private function canViewCost(string $feature): bool { return (new InventoryStoreAccessPolicy())->canViewCostForFeature((int)$this->storeId, (int)$this->storeStaffId, 'cashier.v3.inventory.' . $feature); }
}
