<?php
declare(strict_types=1);
namespace app\controller\store\product\inventory;
use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\InventoryStockCountDraftServices;
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
        return $this->success($this->services->confirm((int)$this->storeId, (int)$this->storeStaffId, $data));
    }

    /** Saving does not call the confirming service or create inventory movement facts. */
    public function saveDraft()
    {
        $data = $this->request->postMore([['draft_id',0],['expected_version',0],['business_date',''],['remark',''],['lines',[]]]);
        return $this->success((new InventoryStockCountDraftServices())->save((int)$this->storeId, (int)$this->storeStaffId, $data));
    }

    public function draftDetail(int $id)
    {
        return $this->success((new InventoryStockCountDraftServices())->detail((int)$this->storeId, (int)$this->storeStaffId, $id));
    }
}
