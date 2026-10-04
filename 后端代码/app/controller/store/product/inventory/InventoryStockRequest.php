<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryErrorMessage;
use app\services\product\inventory\InventoryStockRequestServices;
use think\facade\App;

final class InventoryStockRequest extends AuthController
{
    public function __construct(App $app, InventoryStockRequestServices $services) { parent::__construct($app); $this->services = $services; }
    public function apply()
    {
        try {
            $data = $this->request->postMore([['idempotency_key',''], ['business_date',''], ['remark',''], ['requester_name',''], ['supply_party_type',''], ['supply_party_id',0], ['acceptance_party_id',0], ['lines',[]]]);
            return $this->success($this->services->apply((int)$this->storeId, (int)$this->storeStaffId, $data));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            // 保存失败必须返回可执行的业务原因；技术错误码仅作为结构化上下文供前端与日志定位。
            $error = InventoryErrorMessage::from($exception);
            return $this->fail($error['message'] ?? '请货申请未完成，请刷新后重试。', ['code' => $error['code'] ?? $exception->getMessage()]);
        }
    }
    public function suppliers()
    {
        try { return $this->success($this->services->suppliersForStore((int)$this->storeId, (int)$this->storeStaffId)); }
        catch (\RuntimeException $exception) { return $this->fail('供货门店目录读取失败，请刷新后重试。', ['code' => $exception->getMessage()]); }
    }
    public function acceptanceParties()
    {
        try { return $this->success($this->services->acceptancePartiesForStore((int)$this->storeId, (int)$this->storeStaffId)); }
        catch (\RuntimeException $exception) { return $this->fail('受理部门目录读取失败，请刷新后重试。', ['code' => $exception->getMessage()]); }
    }
    public function updateApplied(int $id)
    {
        try {
            $data = $this->request->postMore([['idempotency_key',''], ['business_date',''], ['remark',''], ['requester_name',''], ['supply_party_type',''], ['supply_party_id',0], ['acceptance_party_id',0], ['lines',[]]]);
            return $this->success($this->services->updateApplied((int)$this->storeId, (int)$this->storeStaffId, $id, $data));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $messages = [
                'inventory_stock_request_edit_state_invalid' => '当前请货单不能编辑。',
                'inventory_stock_request_edit_fulfillment_started' => '请货单已关联调拨，不能再编辑。',
                'inventory_stock_request_not_found' => '请货单不存在或不属于当前门店。',
            ];
            $error = InventoryErrorMessage::from($exception);
            return $this->fail($messages[$exception->getMessage()] ?? $error['message'] ?? '请货修改未完成，请刷新后重试。', ['code' => $error['code'] ?? $exception->getMessage()]);
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
    /** 只允许尚未生成调拨的请货单作废，已生成调拨的单据及库存保持原样。 */
    public function void($id)
    {
        try {
            $data = $this->request->postMore([['idempotency_key',''], ['reason','']]);
            return $this->success('请货单已作废。', $this->services->voidForStore((int)$this->storeId, (int)$this->storeStaffId, (int)$id, $data));
        } catch (\InvalidArgumentException $exception) { return $this->fail('请填写作废原因。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->lifecycleMessage($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }
    private function lifecycleMessage(string $code): string
    {
        return ['inventory_stock_request_not_found'=>'请货单不存在或不属于当前门店。','inventory_stock_request_cancel_state_invalid'=>'只有未履约请货单可以取消。','inventory_stock_request_terminate_state_invalid'=>'只有部分履约请货单可以终止剩余数量。','inventory_stock_request_void_state_invalid'=>'当前请货单不可作废。','inventory_stock_request_void_supplier_denied'=>'请由有权限的供货方人员作废。','inventory_stock_request_void_transfer_exists'=>'这笔请货已生成调拨单，不能作废。','inventory_stock_request_void_fulfillment_remains'=>'请货单已有履约记录，不能作废。','inventory_stock_request_lifecycle_idempotency_conflict'=>'本次操作号已用于其他请货操作，请刷新后重试。'][$code] ?? '请货单操作未完成，请刷新后重试。';
    }
}
