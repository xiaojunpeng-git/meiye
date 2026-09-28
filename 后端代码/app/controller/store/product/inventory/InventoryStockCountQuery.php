<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStockCountQueryServices;
use app\services\product\inventory\InventoryStoreAccessPolicy;
use think\facade\App;

final class InventoryStockCountQuery extends AuthController
{
    public function __construct(App $app, InventoryStockCountQueryServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        $data = $this->request->getMore([['keyword', ''], ['page', 1], ['limit', 20]]);
        $canViewCost = in_array('inventory.cost.view', (new InventoryStoreAccessPolicy())->features((int)$this->storeId, (int)$this->storeStaffId), true);
        return $this->success($this->services->list(
            (int)$this->storeId,
            (int)$this->storeStaffId,
            (string)$data['keyword'],
            (int)$data['page'],
            (int)$data['limit'],
            $canViewCost
        ));
    }

    /** 与列表使用同一登录门店及成本权限；详情请求只接受单据 ID。 */
    public function detail(int $id)
    {
        try {
            $canViewCost = in_array('inventory.cost.view', (new InventoryStoreAccessPolicy())->features((int)$this->storeId, (int)$this->storeStaffId), true);
            return $this->success($this->services->detail((int)$this->storeId, (int)$this->storeStaffId, $id, $canViewCost));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('盘点单不存在或无权查看。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail('盘点单详情读取失败，请核对当前门店权限。', ['code' => $exception->getMessage()]);
        }
    }
}
