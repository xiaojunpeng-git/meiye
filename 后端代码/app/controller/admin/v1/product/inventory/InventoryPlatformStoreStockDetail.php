<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformStoreStockDetailServices;
use app\services\query\UnifiedQueryException;
use think\facade\App;

/** 平台库存查询的门店仓商品详情入口；仓库权限由领域服务按登录账号复核。 */
final class InventoryPlatformStoreStockDetail extends AuthController
{
    public function __construct(App $app, InventoryPlatformStoreStockDetailServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function productDetail(int $productId)
    {
        try {
            return $this->success($this->services->productDetail(
                (array)$this->adminInfo, (int)$this->request->get('location_id', 0), $productId
            ));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('请选择有效的门店仓和商品。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail('门店库存详情不存在或无权查看。', ['code' => $exception->getMessage()]);
        }
    }
}
