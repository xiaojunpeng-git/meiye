<?php

namespace app\controller\api\store\order;

use app\Request;
use app\services\order\StoreOrderRefundServices;
use app\services\store\SystemStoreStaffServices;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

class RefundOrder
{

	/**
	 * @var
	 */
	protected $services;

	/**
	 * @var int
	 */
	protected $uid;
	/**
	 * 门店店员信息
	 * @var array
	 */
	protected $staffInfo;
	/**
	 * 门店id
	 * @var int|mixed
	 */
	protected $store_id;

	/**
	 * 门店店员ID
	 * @var int|mixed
	 */
	protected $staff_id;

    /**
     * RefundOrder constructor.
     * @param StoreOrderRefundServices $service
     */
    public function __construct(StoreOrderRefundServices $service,Request $request)
    {
        $this->services = $service;
		$this->uid = (int)$request->uid();
		$this->getStaffInfo();
    }

	/**
	 * @return void
	 */
	protected function getStaffInfo()
	{
		try {
			/** @var SystemStoreStaffServices $staffServices */
			$staffServices = app()->make(SystemStoreStaffServices::class);
			$staffInfo = $staffServices->getStaffInfoByUid($this->uid)->toArray();
		} catch (\Throwable $e) {
			$staffInfo = [];
		}
		$this->staffInfo = $staffInfo;
		$this->store_id = (int)($this->staffInfo['store_id'] ?? 0);
		$this->staff_id = (int)($this->staffInfo['id'] ?? 0);
	}

	/**
	 * 移动端订单管理退款列表
	 * @param Request $request
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
	public function refundOrderList(Request $request)
	{
		$where = $request->getMore([
			['order_id', ''],
			['time', ''],
			['refundTypes', ''],
			['apply_type', ''],
			['pay_type', ''],
		]);
		$where['store_id'] =$this->store_id;
		$data = $this->services->refundList($where)['list'] ?? [];
		return app('json')->success($data);
	}

	/**
	 * 订单详情
	 * @param $uni
	 * @return \think\Response
	 */
	public function refundOrderDetail($uni)
	{
		$data = $this->services->refundDetail($uni);
		return app('json')->successful('ok', $data);
	}

	/**
	 * 修改备注
	 * @param Request $request
	 * @return \think\Response
	 */
	public function refundRemark(Request $request)
	{
		[$remark, $order_id] = $request->postMore([
			['remark', ''],
			['order_id', ''],
		], true);
		if (!$remark)
			return app('json')->fail('请输入要备注的内容');
		if (!$order_id)
			return app('json')->fail('缺少参数');

		if (!$order = $this->services->get(['order_id' => $order_id])) {
			return app('json')->fail('修改的订单不存在!');
		}
		$order->remark = $remark;
		if ($order->save()) {
			return app('json')->success('备注成功');
		} else
			return app('json')->fail('备注失败');
	}

}
