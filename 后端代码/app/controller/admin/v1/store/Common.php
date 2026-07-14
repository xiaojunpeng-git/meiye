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
namespace app\controller\admin\v1\store;

use app\controller\admin\AuthController;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\store\BranchOrderServices;
use app\services\store\SystemStoreServices;
use function Symfony\Component\Translation\t;

/**
 * 平台门店公共
 * Class Common
 * @package app\controller\admin\v1\store
 */
class Common extends AuthController
{
    /**
     * 首页运营头部统计
     * @param BranchOrderServices $orderServices
     * @return mixed
     */
    public function homeStatics(BranchOrderServices $orderServices, SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],
			['store_id', 0]
        ]);
		if (!$where['store_id']) {
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
        $where['time'] = $orderServices->timeHandle($where['time']);
        return app('json')->success($orderServices->homeStatics($where));
    }

	/**
	 * 首页营业趋势图表
	 * @param BranchOrderServices $orderServices
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return \think\Response
	 */
    public function operateChart(BranchOrderServices $orderServices, SystemRegionAgentServices $regionAgentServices)
    {
		$where = $this->request->getMore([
			['data', '', '', 'time'],
			['store_id', 0]
		]);
		if (!$where['store_id']) {
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
		$time = $orderServices->timeHandle($where['time'], true);
		unset($where['time']);
        return app('json')->success($orderServices->operateChart($where, $time));
    }

	/**
	 * 首页交易统计
	 * @param BranchOrderServices $orderServices
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function orderChart(BranchOrderServices $orderServices, SystemRegionAgentServices $regionAgentServices)
    {
		$where = $this->request->getMore([
			['data', '', '', 'time'],
			['store_id', 0]
		]);
		if (!$where['store_id']) {
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
		$where['time'] = $orderServices->timeHandle($where['time']);
        return $this->success($orderServices->orderChart($where));
    }

    /**
     * 首页门店统计
     * @param SystemStoreServices $storeServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeChart(SystemStoreServices $storeServices, SystemRegionAgentServices $regionAgentServices)
    {
        [$time] = $this->request->getMore([
            ['data', '', '', 'time']
        ], true);
		if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
			if ($storeIds) {//区域代理商下门店ID
				$ids = $storeIds;
			} else {
				$ids[] = -2;
			}
		} else {
			$ids = [];
		}
        $time = $storeServices->timeHandle($time);
        return $this->success($storeServices->storeChart($ids, $time));
    }
}
