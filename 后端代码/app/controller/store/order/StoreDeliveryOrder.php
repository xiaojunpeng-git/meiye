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
namespace app\controller\store\order;

use app\controller\store\AuthController;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\StoreDeliveryOrderServices;
use think\facade\App;

/**
 * 配送订单
 * Class StoreDeliveryOrder
 * @package app\controller\store\order
 */
class StoreDeliveryOrder extends AuthController
{
    /**
     * @var StoreDeliveryOrderServices
     */
    protected $services;

    /**
     * StoreDeliveryOrder constructor.
     * @param App $app
     * @param StoreDeliveryOrderServices $services
     */
    public function __construct(App $app, StoreDeliveryOrderServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index()
    {
		$where = $this->request->getMore([
            ['keyword', ''],
            ['status', ''],
            ['data', '', '', 'time'],
            ['station_type', '']
        ]);
		$where['type'] = 1;
		$where['relation_id'] = $this->storeId;
        return $this->success($this->services->systemPage($where));
    }

    /**
     * 统计数据
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getData()
    {
        $where = $this->request->getMore([
            ['keyword', ''],
            ['status', ''],
            ['data', '', '', 'time'],
            ['station_type', '']
        ]);
        $where['type'] = 1;
        $where['relation_id'] = $this->storeId;
        return $this->success($this->services->getDataInfo($where));
    }
	/**
 	* 详情
	* @param $id
	* @return mixed
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	 */
	public function detail($id)
    {
		if (!$id || !is_numeric($id)) {
            return app('json')->fail('参数错误');
        }
        $data = $this->services->detail((int)$id);
        return app('json')->success($data);
    }

    /**
     * 取消发单表单
     * @param $id
     * @return mixed
     */
    public function cancelForm($id)
    {
		if (!$id) {
			return app('json')->fail('缺少参数ID');
		}
        return app('json')->success($this->services->cancelForm($id));
    }

	/**
 	* 取消发单
	* @param $id
	* @return mixed
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	 */
    public function cancel($id)
    {
        $reason = $this->request->getMore([
			['reason', ''],
			['cancel_reason', '']
		]);
		if (!$id) {
			return app('json')->fail('缺少参数ID');
		}
        if (empty($reason['reason']))
            return app('json')->fail('取消理由不能为空');
        $this->services->cancel((int)$id, $reason);
        return app('json')->success('取消成功');
    }

	/**
	* @param $id
	* @return mixed
	*/
    public function delete($id)
    {
		if (!$id) {
			return app('json')->fail('缺少参数ID');
		}
        $this->services->delete((int)$id);
        return app('json')->success('删除成功');
    }


}
