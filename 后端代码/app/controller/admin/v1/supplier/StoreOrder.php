<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\controller\admin\v1\supplier;

use app\services\order\StoreOrderDeliveryServices;
use think\facade\App;
use app\controller\admin\AuthController;
use app\services\order\StoreOrderServices;
use app\common\controller\Order as CommonOrder;

/**
 * Class StoreOrder
 * @package app\controller\admin\v1\supplier
 */
class StoreOrder extends AuthController
{
    use CommonOrder;

    /**
     * @var StoreOrderServices
     */
    protected $services;

    /**
     * StoreOrder constructor.
     * @param App $app
     * @param StoreOrderServices $services
     */
    public function __construct(App $app, StoreOrderServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

	/**
	 * 获取订单类型数量
	 * @param Request $request
	 * @return mixed
	 */
	public function chart()
	{
		$where = $this->request->getMore([
			['status', ''],
			['real_name', ''],
			['is_del', ''],
			['data', '', '', 'time'],
			['order_type', ''],
			['type', ''],
			['pay_type', ''],
			['order', ''],
			['field_key', ''],
			['supplier_id', -1],
			['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
		]);
		if ($where['supplier_id'] < 1) {
			$where['supplier_id'] = -1;
		}
		$where['type'] = trim($where['type']);
		$where['status'] = trim($where['status']);
		$where['type'] = trim($where['type']);
		if (!in_array($where['status'], [-1, -2, -3])) {
			$where['pid'] = [0, -1];
		}
		$where['type'] = trim($where['type'], ' ');
		$data = $this->services->orderStoreCount($where);
		return app('json')->success($data);
	}

    /**
     * 订单列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['real_name', ''],
            ['is_del', ''],
            ['data', '', '', 'time'],
            ['type', ''],
            ['pay_type', ''],
            ['order', ''],
            ['field_key', ''],
            ['supplier_id', -1]
        ]);
		$where['type'] = trim($where['type']);
		$where['status'] = trim($where['status']);
        if ($where['supplier_id'] < 1) {
            $where['supplier_id'] = -1;
        }
        $where['type'] = trim($where['type']);
        $where['is_system_del'] = 0;

        $where['store_id'] = 0;
        $where['type'] = trim($where['type'], ' ');
        $where['plat_type'] = 2;//供应商订单
        return $this->success($this->services->getOrderList($where, ['*'], ['split' => function ($query) {
            $query->field('id,pid');
        }, 'pink', 'invoice', 'supplier']));
    }

	/**
	 * 提醒发货
	 * @param StoreOrderDeliveryServices $storeOrderDeliveryServices
	 * @param $supplierId
	 * @param $id
	 * @return mixed
	 * @throws \Psr\SimpleCache\InvalidArgumentException
	 */
    public function deliverRemind(StoreOrderDeliveryServices $storeOrderDeliveryServices, $supplierId, $id)
    {
        if (!$supplierId || !$id) return $this->fail('参数异常');
		$storeOrderDeliveryServices->deliverRemind((int)$id);
        return $this->success('提醒成功');
    }

}
