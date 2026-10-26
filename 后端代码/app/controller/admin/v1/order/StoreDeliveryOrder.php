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

use app\controller\admin\AuthController;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\StoreDeliveryOrderServices;
use think\facade\App;

/**
 * 配送订单
 * Class StoreDeliveryOrder
 * @package app\controller\admin\v1\store
 */
class StoreDeliveryOrder extends AuthController
{
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
	 * 配送单列表
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index(SystemRegionAgentServices $regionAgentServices)
    {
		$where = $this->request->getMore([
            ['keyword', ''],
            ['store_id',],
            ['status', ''],
            ['data', '', '', 'time'],
            ['station_type', '']
        ]);
		if ($where['store_id']) {//筛选门店
			$where['type'] = 1;
			$where['relation_id'] = $where['store_id'];
			unset($where['store_id']);
		} else {//无筛选
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {
					$where['type'] = 1;
					$where['relation_id'] = $storeIds;
				} else {
					return $this->success(['list' => [], 'count' => 0]);
				}
			}
		}
        return $this->success($this->services->systemPage($where));
    }

    /**
     * 统计数据
     * @param SystemRegionAgentServices $regionAgentServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getData(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['keyword', ''],
            ['store_id',],
            ['status', ''],
            ['data', '', '', 'time'],
            ['station_type', '']
        ]);
        if ($where['store_id']) {//筛选门店
            $where['type'] = 1;
            $where['relation_id'] = $where['store_id'];
            unset($where['store_id']);
        } else {//无筛选
            if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
                $storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
                if ($storeIds) {
                    $where['type'] = 1;
                    $where['relation_id'] = $storeIds;
                } else {
                    return $this->success(['list' => [], 'count' => 0]);
                }
            }
        }
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
		if (!$id) {
			return app('json')->fail('缺少参数ID');
		}
        $data = $this->services->detail($id);
        return app('json')->success($data);
    }

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
        $this->services->cancel($id, $reason);
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
