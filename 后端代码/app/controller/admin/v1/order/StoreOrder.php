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
namespace app\controller\admin\v1\order;

use app\common\controller\Order;
use app\controller\admin\AuthController;
use app\Request;
use app\services\yeji\SatffYejiServices;
use app\services\order\{
    StoreOrderServices
};
use think\facade\App;

/**
 * 订单管理
 * Class StoreOrder
 * @package app\controller\admin\v1\order
 */
class StoreOrder extends AuthController
{

    use Order;

    /**
     * StoreOrder constructor.
     * @param App $app
     * @param StoreOrderServices $service
     */
    public function __construct(App $app, StoreOrderServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

    /**
     * 订单列表（总后台 /adminapi/order/list）
     * 与门店订单列表一致：商品名前拼接服务对象、规格去「默认」等（见 StoreOrderServices::formatStoreBackendOrderListCartRows）
     *
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function lst(Request $request)
    {
        $where = $request->getMore([
            ['status', ''],
            ['real_name', ''],
            ['search_order_id', ''],
            ['search_product', ''],
            ['search_user', ''],
            ['is_del', ''],
            ['data', '', '', 'time'],
            ['pay_time', ''],
            ['take_time', ''],
            ['deliveryType', ''],
            ['interval_price_min', ''],
            ['interval_price_max', ''],
            ['type', ''],
            ['pay_type', ''],
            ['plat_type', -1],
            ['order', ''],
            ['field_key', ''],
            ['store_id', ''],
            ['supplier_id', '']
        ]);
        $where['type'] = trim($where['type']);
        $where['status'] = trim($where['status']);
        $where['is_system_del'] = 0;
        $where['plat_type'] = 0;
        if (!in_array($where['status'], [-1, -2, -3])) {
            $where['pid'] = [0, -1];
        }
        $where['type'] = trim($where['type'], ' ');
        return app('json')->success($this->services->getOrderList($where, ['*'], ['split' => function ($query) {
            $query->field('id,pid');
        }, 'pink', 'invoice'], false, 'add_time DESC,id DESC', true));
    }

    /**
     * 拆分子订单列表：商品展示与主列表一致
     *
     * @param Request $request
     * @param int|string $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function split_order(Request $request, $id)
    {
        [$status] = $request->getMore([
            ['status', -1]
        ], true);
        if (!$id) {
            return app('json')->fail('缺少订单ID');
        }
        $where = ['pid' => $id, 'is_system_del' => 0];
        if (!$this->services->count($where)) {
            $where = ['id' => $id, 'is_system_del' => 0];
        }
        return app('json')->success($this->services->getSplitOrderList($where, ['*'], ['split', 'pink', 'invoice', 'supplier', 'store' => function ($query) {
            $query->field('id,name')->bind(['store_name' => 'name']);
        }], true));
    }

	/**
	 * 易联云打印机打印
	 * @param $id
	 * @return mixed
	 */
	public function order_print($id)
	{
		if (!$id) return app('json')->fail('缺少参数');
		$order = $this->services->get($id);
		if (!$order) {
			return app('json')->fail('订单没有查到,无法打印!');
		}
		$this->services->orderPrint((int)$id, 0, 0);
		return app('json')->success('打印成功');
	}

    /**
     * 订单列表
     * @param Request $request
     * @return mixed
     */
    public function yeji(Request $request)
    {
        $where = $request->getMore([
            ['keyword', ''],
            ['created_time'],//时间
            ['type', ''],
            ['link_id', ''],
            ['order_id', ''],
        ]);
        $yejiService=app()->make(SatffYejiServices::class);
        return app('json')->success($yejiService->getList($where));
    }
}
