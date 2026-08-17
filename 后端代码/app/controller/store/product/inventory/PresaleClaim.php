<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\App;

final class PresaleClaim extends AuthController
{
    public function __construct(App $app, CashierV3PresaleClaimServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        try {
            $criteria = [
                'page' => $this->request->get('page', 1), 'limit' => $this->request->get('limit', 20),
                'keyword' => $this->request->get('keyword', ''), 'status' => $this->request->get('status', ''),
                'source_kind' => $this->request->get('source_kind', 'PRESALE'),
                'start_date' => $this->request->get('start_date', ''), 'end_date' => $this->request->get('end_date', ''),
            ];
            return $this->success($this->services->listForStore((int)$this->storeId, (int)$this->storeStaffId, $criteria));
        } catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    public function detail(string $id)
    {
        try { return $this->success($this->services->detailForStore((int)$this->storeId, (int)$this->storeStaffId, $id)); }
        catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    public function claim()
    {
        try {
            $input = $this->request->postMore([['idempotency_key', ''], ['claimable_line_id', ''], ['quantity', 0], ['business_date', '']]);
            return $this->success('预售商品已领用并生成出库单。', $this->services->claimForStore((int)$this->storeId, (int)$this->storeStaffId, $input));
        } catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    public function void(string $id)
    {
        try {
            $input = $this->request->postMore([['idempotency_key', ''], ['reason', '']]);
            return $this->success('领用已作废，库存已按原批次退回。', $this->services->voidForStore((int)$this->storeId, (int)$this->storeStaffId, $id, $input));
        } catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    private function message(\Throwable $exception): string
    {
        return [
            'presale_claim_closed_after_sale_reversal' => '该预售订单已退款或作废，不能继续领用。',
            'presale_claim_fully_claimed' => '该预售商品已全部领用。',
            'presale_claim_exceeds_remaining' => '领用数量不能超过未领用数量。',
            'presale_claim_stock_insufficient' => '默认门店仓可用库存不足。',
            'presale_claim_source_kind_invalid' => '领用来源无效，请重新选择预售或赠送。',
            'presale_claim_sales_date_invalid' => '销售日期格式不正确。',
            'presale_claim_sales_date_range_invalid' => '销售开始日期不能晚于结束日期。',
            'presale_claim_already_voided' => '该领用记录已经作废。',
            'presale_claimable_line_not_found' => '预售明细不存在或不属于当前门店。',
        ][$exception->getMessage()] ?? '预售领用操作未完成，请刷新后重试。';
    }
}
