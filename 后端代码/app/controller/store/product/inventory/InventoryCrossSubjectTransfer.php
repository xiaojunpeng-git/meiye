<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\product\inventory\InventoryStoreAccessPolicy;
use think\facade\App;

final class InventoryCrossSubjectTransfer extends AuthController
{
    public function __construct(App $app, InventoryCrossSubjectTransferServices $services) { parent::__construct($app); $this->services = $services; }

    public function index()
    {
        $input = $this->request->getMore([['keyword', ''], ['page', 1], ['limit', 20]]);
        return $this->run(fn (): array => $this->services->listForStore((int)$this->storeId, (int)$this->storeStaffId, (string)$input['keyword'], (int)$input['page'], (int)$input['limit'], $this->canViewCost()));
    }

    public function detail(int $id)
    {
        return $this->run(fn (): array => $this->services->detailForStore((int)$this->storeId, (int)$this->storeStaffId, $id, $this->canViewCost()));
    }

    public function counterparties()
    {
        return $this->run(fn (): array => $this->services->counterpartiesForStore((int)$this->storeId, (int)$this->storeStaffId));
    }

    public function incomingRequests()
    {
        return $this->run(fn (): array => $this->services->incomingRequestsForStore((int)$this->storeId, (int)$this->storeStaffId, $this->canViewCost()));
    }

    public function create()
    {
        $input = $this->request->postMore([
            ['idempotency_key', ''], ['business_date', ''], ['remark', ''], ['target_party_type', 'STORE'], ['target_store_id', 0], ['request_document_id', 0], ['lines', []],
        ]);
        return $this->run(fn (): array => $this->services->createDraftForStore((int)$this->storeId, (int)$this->storeStaffId, $input), '跨店调拨草稿已保存。');
    }

    /** Only the authenticated source store can replace a still-unshipped draft at its observed token. */
    public function update(int $id)
    {
        $input = $this->request->postMore([
            ['expected_edit_token', ''], ['idempotency_key', ''], ['business_date', ''], ['remark', ''],
            ['target_party_type', 'STORE'], ['target_store_id', 0], ['request_document_id', 0], ['lines', []],
        ]);
        return $this->run(fn (): array => $this->services->updateDraftForStore((int)$this->storeId, (int)$this->storeStaffId, $id, $input), '调拨草稿已更新。');
    }

    /** Draft removal is source-store only and requires the version shown in the confirmation dialog. */
    public function deleteDraft(int $id)
    {
        $input = $this->request->postMore([['expected_edit_token', '']]);
        return $this->run(fn (): array => $this->services->deleteDraftForStore((int)$this->storeId, (int)$this->storeStaffId, $id, (string)$input['expected_edit_token']), '调拨草稿已永久删除。');
    }

    public function dispatch(int $id)
    {
        return $this->run(fn (): array => $this->services->dispatchForStore((int)$this->storeId, (int)$this->storeStaffId, $id), '调拨已发货，正在等待收货。');
    }

    public function receive(int $id)
    {
        return $this->run(fn (): array => $this->services->receiveForStore((int)$this->storeId, (int)$this->storeStaffId, $id), '收货完成，库存已入账。');
    }

    public function cancel(int $id)
    {
        // 保留旧 URL 的明确拒绝响应，避免旧客户端绕过新页面继续取消调拨。
        return $this->fail('调拨单不支持作废。', ['code' => 'inventory_cross_transfer_void_disabled']);
    }

    public function reverse(int $id)
    {
        return $this->fail('调拨单不支持作废。', ['code' => 'inventory_cross_transfer_void_disabled']);
    }

    private function canViewCost(): bool
    {
        return (new InventoryStoreAccessPolicy())->canViewCostForFeature((int)$this->storeId, (int)$this->storeStaffId, 'cashier.v3.inventory.transfer');
    }

    private function run(callable $operation, string $successMessage = '')
    {
        try {
            $data = $operation();
            return $successMessage === '' ? $this->success($data) : $this->success($successMessage, $data);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('跨店调拨参数不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    private function message(string $code): string
    {
        return [
            'inventory_cross_transfer_not_found' => '未找到当前门店可查看的调拨单。',
            'inventory_cross_transfer_scope_denied' => '当前账号没有本门店的调拨操作权限，请切换有权限的账号或门店。',
            'inventory_cross_transfer_stock_insufficient' => '调出门店库存不足，无法发货。',
            'inventory_cross_transfer_target_sku_unavailable' => '调入门店缺少对应商品，请先同步商品后再调拨。',
            'inventory_cross_transfer_request_unavailable' => '关联请货单当前不可履约。',
            'inventory_cross_transfer_request_line_invalid' => '关联请货商品与调拨商品不一致。',
            'inventory_cross_transfer_request_quantity_exceeded' => '调拨数量超过请货单剩余数量。',
            'inventory_cross_transfer_dispatch_state_invalid' => '当前调拨单不可发货。',
            'inventory_cross_transfer_receive_state_invalid' => '当前调拨单不可收货。',
            'inventory_cross_transfer_edit_state_invalid' => '该调拨单已不再是可编辑草稿，请刷新列表。',
            'inventory_cross_transfer_delete_state_invalid' => '该调拨单不是当前调出门店的未发货草稿，不能删除。',
            'inventory_cross_transfer_delete_facts_exist' => '该调拨单已关联库存流水或请货履约，不能删除。',
            'inventory_cross_transfer_draft_changed' => '调拨草稿已被其他操作修改，请重新打开后编辑。',
            'inventory_cross_transfer_request_target_locked' => '关联请货单的调入方不能更改。',
            'inventory_cross_transfer_target_scope_denied' => '只能向同一组织范围内的门店调拨。',
            'inventory_cross_transfer_target_invalid' => '调入方不合法，不能调拨给当前库存主体。',
            'inventory_cross_transfer_hq_location_missing' => '当前组织尚未配置可用总部仓。',
            'inventory_cross_transfer_hq_location_ambiguous' => '当前组织存在多个默认总部仓，无法确认调拨目标。',
        ][$code] ?? '跨店调拨未完成，请刷新后重试。';
    }
}
