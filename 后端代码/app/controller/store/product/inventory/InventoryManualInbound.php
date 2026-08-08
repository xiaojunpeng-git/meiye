<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventoryManualInboundReadServices;
use app\services\product\inventory\InventoryManualDocumentReversalServices;
use app\services\product\inventory\InventoryStoreAccessPolicy;
use think\facade\App;

class InventoryManualInbound extends AuthController
{
    public function __construct(App $app, InventoryManualInboundServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function create()
    {
        $data = $this->request->postMore([
            ['idempotency_key', ''], ['business_date', ''], ['remark', ''], ['lines', []],
        ]);
        return $this->success($this->services->create((int)$this->storeId, (int)$this->storeStaffId, $data));
    }

    public function detail(string $id, InventoryManualInboundReadServices $readServices)
    {
        try {
            $canViewCost = in_array(
                'inventory.cost.view',
                (new InventoryStoreAccessPolicy())->features((int)$this->storeId, (int)$this->storeStaffId),
                true
            );
            return $this->success($readServices->detail(
                (int)$this->storeId,
                (int)$this->storeStaffId,
                $id,
                $canViewCost
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('入库详情不存在或无权查看。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail('当前门店库存范围不可用，请刷新后重试。', ['code' => $exception->getMessage()]);
        }
    }

    public function outboundDetails(string $id, InventoryManualInboundReadServices $readServices)
    {
        try {
            $canViewCost = in_array(
                'inventory.cost.view',
                (new InventoryStoreAccessPolicy())->features((int)$this->storeId, (int)$this->storeStaffId),
                true
            );
            return $this->success($readServices->outboundDetails(
                (int)$this->storeId, (int)$this->storeStaffId, $id, $canViewCost
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('入库单不存在或无权查看。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail('当前门店库存范围不可用，请刷新后重试。', ['code' => $exception->getMessage()]);
        }
    }

    public function void(string $id, InventoryManualDocumentReversalServices $reversalServices)
    {
        try {
            $posted = $this->request->postMore([['idempotency_key', ''], ['reason', '']]);
            $input = ['source_id' => $id, 'idempotency_key' => $posted['idempotency_key'], 'reason' => $posted['reason']];
            return $this->success('入库单已作废，库存已按原批次冲销。', $reversalServices->reverseForStore(
                (int)$this->storeId,
                (int)$this->storeStaffId,
                InventoryManualDocumentReversalServices::INBOUND,
                $input
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('请填写作废原因。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->voidMessage($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    private function voidMessage(string $code): string
    {
        return [
            'inventory_manual_reversal_document_missing' => '入库单不存在或不属于当前门店。',
            'inventory_manual_reversal_already_voided' => '该入库单已经作废。',
            'inventory_manual_reversal_inbound_batch_insufficient' => '原入库批次剩余库存不足，不能作废。',
            'inventory_manual_reversal_scope_denied' => '当前账号无权作废该入库单。',
            'inventory_manual_reversal_idempotency_conflict' => '本次操作号已用于其他作废操作，请刷新后重试。',
        ][$code] ?? '入库单作废未完成，请刷新后重试。';
    }
}
