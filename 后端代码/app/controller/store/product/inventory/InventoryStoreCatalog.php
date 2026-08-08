<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStoreCatalogServices;
use app\services\product\inventory\InventoryStockRequestServices;
use think\facade\App;

class InventoryStoreCatalog extends AuthController
{
    public function __construct(App $app, InventoryStoreCatalogServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function search()
    {
        [$keyword, $categoryId, $page, $limit] = $this->request->getMore([
            ['keyword', ''],
            ['category_id', 0],
            ['page', 1],
            ['limit', 20],
        ], true);
        return $this->success($this->services->search(
            (int)$this->storeId,
            (string)$keyword,
            (int)$categoryId,
            (int)$page,
            (int)$limit
        ));
    }

    public function barcode()
    {
        try {
            return $this->success($this->services->barcode((int)$this->storeId, (string)$this->request->get('barcode', '')));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('商品条码不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($exception->getMessage() === 'inventory_catalog_barcode_ambiguous' ? '该条码对应多个规格，请通过商品选择确认。' : '没有找到该条码对应的库存商品。', ['code' => $exception->getMessage()]);
        }
    }

    public function requester(InventoryStockRequestServices $requests)
    {
        try { return $this->success($requests->requesterForStore((int)$this->storeId, (int)$this->storeStaffId)); }
        catch (\RuntimeException $exception) { return $this->fail('当前操作人信息无效，请重新登录。', ['code' => $exception->getMessage()]); }
    }
}
