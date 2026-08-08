<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStockRequestServices;
use think\facade\App;

final class InventoryStockRequest extends AuthController
{
    public function __construct(App $app, InventoryStockRequestServices $services) { parent::__construct($app); $this->services = $services; }
    public function apply()
    {
        try {
            $data = $this->request->postMore([['idempotency_key',''], ['business_date',''], ['remark',''], ['supply_party_type',''], ['supply_party_id',0], ['lines',[]]]);
            return $this->success($this->services->apply((int)$this->storeId, (int)$this->storeStaffId, $data));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('请货参数不合法，请检查供货方和商品。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($exception->getMessage() === 'inventory_stock_request_supplier_invalid' ? '供货方不存在，或不在当前组织范围内。' : '请货申请未完成，请刷新后重试。', ['code' => $exception->getMessage()]);
        }
    }
    public function suppliers()
    {
        try { return $this->success($this->services->suppliersForStore((int)$this->storeId, (int)$this->storeStaffId)); }
        catch (\RuntimeException $exception) { return $this->fail('供货门店目录读取失败，请刷新后重试。', ['code' => $exception->getMessage()]); }
    }
    public function updateApplied(int $id)
    {
        try {
            $data = $this->request->postMore([['idempotency_key',''], ['business_date',''], ['remark',''], ['supply_party_type',''], ['supply_party_id',0], ['lines',[]]]);
            return $this->success($this->services->updateApplied((int)$this->storeId, (int)$this->storeStaffId, $id, $data));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('请货修改参数不合法，请检查供货方和商品。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            $messages = [
                'inventory_stock_request_edit_state_invalid' => '当前请货单不能编辑。',
                'inventory_stock_request_edit_fulfillment_started' => '请货单已关联调拨，不能再编辑。',
                'inventory_stock_request_not_found' => '请货单不存在或不属于当前门店。',
            ];
            return $this->fail($messages[$exception->getMessage()] ?? '请货修改未完成，请刷新后重试。', ['code' => $exception->getMessage()]);
        }
    }
    public function cancel($id)
    {
        try {
            $data = $this->request->postMore([['idempotency_key',''], ['reason','']]);
            return $this->success('请货单已取消。', $this->services->cancelForStore((int)$this->storeId, (int)$this->storeStaffId, (int)$id, $data));
        } catch (\InvalidArgumentException $exception) { return $this->fail('请填写取消原因。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->lifecycleMessage($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }
    public function terminate($id)
    {
        try {
            $data = $this->request->postMore([['idempotency_key',''], ['reason','']]);
            return $this->success('剩余请货已终止，已履约数量保持不变。', $this->services->terminateForStore((int)$this->storeId, (int)$this->storeStaffId, (int)$id, $data));
        } catch (\InvalidArgumentException $exception) { return $this->fail('请填写终止原因。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->lifecycleMessage($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }
    private function lifecycleMessage(string $code): string
    {
        return ['inventory_stock_request_not_found'=>'请货单不存在或不属于当前门店。','inventory_stock_request_cancel_state_invalid'=>'只有未履约请货单可以取消。','inventory_stock_request_terminate_state_invalid'=>'只有部分履约请货单可以终止剩余数量。','inventory_stock_request_lifecycle_idempotency_conflict'=>'本次操作号已用于其他请货操作，请刷新后重试。'][$code] ?? '请货单操作未完成，请刷新后重试。';
    }
}
