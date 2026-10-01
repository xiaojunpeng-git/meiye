<?php
declare(strict_types=1);
namespace app\controller\store\product\inventory;
use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\InventoryStockCountDraftServices;
use app\services\product\inventory\InventoryStockCountCatalogException;
use think\facade\App;
final class InventoryStockCount extends AuthController
{
    public function __construct(App $app, InventoryStockCountServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /** The optional draft version is checked in the same transaction as final stock settlement. */
    public function confirm()
    {
        $data = $this->request->postMore([['idempotency_key',''],['business_date',''],['remark',''],['lines',[]],['draft_id',0],['draft_version',0]]);
        try {
            return $this->success($this->services->confirm((int)$this->storeId, (int)$this->storeStaffId, $data, (array)$this->storeStaffInfo));
        } catch (InventoryStockCountCatalogException $exception) {
            // 行号与稳定商品身份用于门店端定位；不返回数据库状态或允许客户端绕过目录校验。
            return $this->fail('部分盘点商品已从当前门店商品目录删除，请处理高亮行后再提交。', [
                'code' => $exception->getMessage(),
                'invalid_lines' => $exception->invalidLines(),
            ]);
        }
    }

    /** Saving does not call the confirming service or create inventory movement facts. */
    public function saveDraft()
    {
        $data = $this->request->postMore([['draft_id',0],['expected_version',0],['business_date',''],['remark',''],['lines',[]]]);
        return $this->success((new InventoryStockCountDraftServices())->save((int)$this->storeId, (int)$this->storeStaffId, $data, (array)$this->storeStaffInfo));
    }

    public function draftDetail(int $id)
    {
        return $this->success((new InventoryStockCountDraftServices())->detail((int)$this->storeId, (int)$this->storeStaffId, $id, (array)$this->storeStaffInfo));
    }

    /** Only the authenticated creator can permanently remove an unconfirmed draft at its current version. */
    public function deleteDraft(int $id)
    {
        $data = $this->request->postMore([['expected_version', 0]]);
        return $this->success((new InventoryStockCountDraftServices())->delete(
            (int)$this->storeId, (int)$this->storeStaffId, $id, (int)$data['expected_version'], (array)$this->storeStaffInfo
        ));
    }
}
