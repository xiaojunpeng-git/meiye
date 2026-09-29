<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryMovementQueryServices;
use app\services\product\inventory\InventoryStoreAccessPolicy;
use think\facade\App;

final class InventoryMovementQuery extends AuthController
{
    public function __construct(App $app, InventoryMovementQueryServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        $input = $this->request->getMore([['kind', 'movement'], ['keyword', ''], ['page', 1], ['limit', 20]]);
        $kind = (string)$input['kind'];
        // 同一旧接口承载入库、出库与流水列表，金额按所请求功能的岗位权限分别判断。
        $canViewCost = in_array($kind, ['inbound', 'outbound', 'movement'], true)
            && (new InventoryStoreAccessPolicy())->canViewCostForFeature((int)$this->storeId, (int)$this->storeStaffId, 'cashier.v3.inventory.' . $kind);
        return $this->success($this->services->list(
            (int)$this->storeId,
            (int)$this->storeStaffId,
            $kind,
            (string)$input['keyword'],
            (int)$input['page'],
            (int)$input['limit'],
            $canViewCost
        ));
    }
}
