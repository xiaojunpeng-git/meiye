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
        return $this->run(fn (): array => $this->services->cancelForStore((int)$this->storeId, (int)$this->storeStaffId, $id), '调拨草稿已取消。');
    }

    public function reverse(int $id)
    {
        $input = $this->request->postMore([['idempotency_key', ''], ['reason', '']]);
        return $this->run(fn (): array => $this->services->reverseForStore((int)$this->storeId, (int)$this->storeStaffId, $id, $input), '调拨单已作废，库存已按原批次冲销。');
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
            'inventory_cross_transfer_stock_insufficient' => '调出门店库存不足，无法发货。',
            'inventory_cross_transfer_target_sku_unavailable' => '调入门店缺少对应商品，请先同步商品后再调拨。',
            'inventory_cross_transfer_request_unavailable' => '关联请货单当前不可履约。',
            'inventory_cross_transfer_request_line_invalid' => '关联请货商品与调拨商品不一致。',
            'inventory_cross_transfer_request_quantity_exceeded' => '调拨数量超过请货单剩余数量。',
            'inventory_cross_transfer_dispatch_state_invalid' => '当前调拨单不可发货。',
            'inventory_cross_transfer_receive_state_invalid' => '当前调拨单不可收货。',
            'inventory_cross_transfer_cancel_state_invalid' => '当前调拨单不可取消。',
            'inventory_cross_transfer_reverse_state_invalid' => '只有在途或已收货调拨单可以作废。',
            'inventory_cross_transfer_reverse_scope_denied' => '只有调出方可以作废该调拨单。',
            'inventory_cross_transfer_target_reversal_stock_insufficient' => '调入方对应批次剩余库存不足，无法作废。',
            'inventory_cross_transfer_reverse_idempotency_conflict' => '本次操作号已用于其他调拨操作，请刷新后重试。',
            'inventory_cross_transfer_target_scope_denied' => '只能向同一组织范围内的门店调拨。',
            'inventory_cross_transfer_target_invalid' => '调入方不合法，不能调拨给当前库存主体。',
            'inventory_cross_transfer_hq_location_missing' => '当前组织尚未配置可用总部仓。',
            'inventory_cross_transfer_hq_location_ambiguous' => '当前组织存在多个默认总部仓，无法确认调拨目标。',
        ][$code] ?? '跨店调拨未完成，请刷新后重试。';
    }
}
