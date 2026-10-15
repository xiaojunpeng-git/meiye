<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\App;

/** 将门店鉴权中间件确认的选店会话交给服务层，仅访问当前所选门店。 */
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
            return $this->success($this->services->listForStore((int)$this->storeId, (int)$this->storeStaffId, $criteria, (array)$this->storeStaffInfo));
        } catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    public function detail(string $id)
    {
        try { return $this->success($this->services->detailForStore((int)$this->storeId, (int)$this->storeStaffId, $id, (array)$this->storeStaffInfo)); }
        catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    public function claim()
    {
        try {
            $input = $this->request->postMore([['idempotency_key', ''], ['claimable_line_id', ''], ['quantity', 0], ['business_date', ''], ['remark', '']]);
            return $this->success('预售商品已领用并生成出库单。', $this->services->claimForStore((int)$this->storeId, (int)$this->storeStaffId, $input, (array)$this->storeStaffInfo));
        } catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    /** Edits the note on a store-scoped claim without replaying its inventory movement. */
    public function remark(string $id)
    {
        try {
            $input = $this->request->postMore([['remark', ''], ['expected_version', 0]]);
            return $this->success('领用备注已保存。', $this->services->updateRemarkForStore((int)$this->storeId, (int)$this->storeStaffId, $id, $input, (array)$this->storeStaffInfo));
        } catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    public function void(string $id)
    {
        try {
            $input = $this->request->postMore([['idempotency_key', ''], ['reason', '']]);
            return $this->success('领用已作废，库存已按原批次退回。', $this->services->voidForStore((int)$this->storeId, (int)$this->storeStaffId, $id, $input, (array)$this->storeStaffInfo));
        } catch (\Throwable $exception) { return $this->fail($this->message($exception), ['code' => $exception->getMessage()]); }
    }

    /** 作废列表来源记录的剩余领用资格；已出库的领用事实保留。 */
    public function voidClaimable(string $id)
    {
        try {
            $input = $this->request->postMore([['idempotency_key', ''], ['reason', '']]);
            return $this->success('该记录已作废，剩余领用额度已关闭。', $this->services->voidClaimableForStore(
                (int)$this->storeId, (int)$this->storeStaffId, $id, $input, (array)$this->storeStaffInfo));
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
            'presale_claimable_already_voided' => '该预售或赠送记录已经作废。',
            'presale_claim_scope_denied' => '当前岗位无权操作该门店记录。',
            'presale_claimable_line_not_found' => '记录不存在或不在当前岗位授权门店范围内。',
            'presale_claim_remark_invalid' => '备注不能超过500字。',
            'presale_claim_remark_version_changed' => '备注已被其他人修改，请刷新明细后重试。',
            'presale_claim_not_found' => '领用记录不存在或不属于当前门店。',
        ][$exception->getMessage()] ?? '预售领用操作未完成，请刷新后重试。';
    }
}
