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

    /** Count and outbound callers opt into their own stock-backed views; store scope still comes only from the session. */
    public function search()
    {
        [$keyword, $categoryId, $page, $limit, $forCount] = $this->request->getMore([
            ['keyword', ''],
            ['category_id', 0],
            ['page', 1],
            ['limit', 20],
            ['for_count', 0],
        ], true);
        try {
            $result = $this->services->search(
                (int)$this->storeId,
                (string)$keyword,
                (int)$categoryId,
                (int)$page,
                (int)$limit,
                (int)$forCount === 1,
                (int)$this->request->get('include_count_batches', 0) === 1,
                // 成本权限由当前员工实际权限解析，不能使用请求中的布尔值。
                (int)$this->request->get('include_count_batches', 0) === 1
                    && (new \app\services\product\inventory\InventoryStoreAccessPolicy())->canViewCostForFeature((int)$this->storeId, (int)$this->storeStaffId, 'cashier.v3.inventory.stock'),
                // 普通导入与清零共用本仓现存批次入口；旧清零参数保留给既有调用。
                (int)$this->request->get('for_existing_stock', 0) === 1
                    || (int)$this->request->get('for_zero_count', 0) === 1,
                // 出库选品始终限定实物库存；保持与服务签名的 inventoryOnly 参数对齐。
                true,
                (int)$this->request->get('for_outbound_stock', 0) === 1
            );
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'inventory_stock_count_batch_balance_mismatch') throw $exception;
            // 总余额与批次明细不一致时必须明示阻断，不能以空列表误导操作人认为已清零。
            return $this->fail('当前库存批次与总余额不一致，请先联系管理员核对库存；尚未修改系统库存。', ['code' => $exception->getMessage()]);
        }
        return $this->success($result);
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
