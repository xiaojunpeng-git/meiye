<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\controller\admin\v1\statistic;

use app\controller\admin\AuthController;
use app\services\agent\SystemRegionAgentServices;
use app\services\statistic\OrderStatisticServices;
use think\facade\App;

class OrderStatistic extends AuthController
{
    public function __construct(App $app, OrderStatisticServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

	/**
	 * 订单统计基础信息
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return \think\Response
	 */
    public function getBasic(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['time', ''],
			['store_id', 0]
        ]);
		if (!$where['store_id']) {
			$where['store_id'] = '';
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
        $data = $this->services->getBasic($where);
        return app('json')->success($data);
    }

	/**
	 * 订单统计趋势图
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return \think\Response
	 */
    public function getTrend(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['time', ''],
			['store_id', 0]
        ]);
		if (!$where['store_id']) {
			$where['store_id'] = '';
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
        $data = $this->services->getTrend($where);
        return app('json')->success($data);
    }

	/**
	 * 订单来源
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return \think\Response
	 */
    public function getChannel(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['time', ''],
			['store_id', 0]
        ]);
		if (!$where['store_id']) {
			$where['store_id'] = '';
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
        $data = $this->services->getChannel($where);
        return app('json')->success($data);
    }

	/**
	 * 订单类型
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return \think\Response
	 */
    public function getType(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['time', ''],
			['store_id', 0]
        ]);
		if (!$where['store_id']) {
			$where['store_id'] = '';
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
        $data = $this->services->getType($where);
        return app('json')->success($data);
    }
}
