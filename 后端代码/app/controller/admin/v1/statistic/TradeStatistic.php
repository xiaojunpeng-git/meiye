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

namespace app\controller\admin\v1\statistic;


use app\controller\admin\AuthController;
use app\services\agent\SystemRegionAgentServices;
use app\services\statistic\TradeStatisticServices;
use think\facade\App;

/**
 * Class TradeStatistic
 * @package app\controller\admin\v1\statistic
 */
class TradeStatistic extends AuthController
{
    /**
     * TradeStatistic constructor.
     * @param App $app
     * @param TradeStatisticServices $services
     */
    public function __construct(App $app, TradeStatisticServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

	/**
	 * 顶部数据
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \Exception
	 */
    public function topTrade(SystemRegionAgentServices $regionAgentServices)
    {
		$where = $this->request->getMore([
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
        $leftToday = $this->services->getTopLeftTrade(['time' => 'today'] + $where);
        $leftyestoday = $this->services->getTopLeftTrade(['time' => 'yestoday'] + $where);
        $rightOne = $this->services->getTopRightOneTrade($where);
        $rightTwo = $this->services->getTopRightTwoTrade($where);
        $right = ['today' => $rightOne, 'month' => $rightTwo];
        $totalleft = [$leftToday, $leftyestoday];
        $left = [];
        foreach ($totalleft as $k => $v) {
            $left['name'] = "当日订单金额";
            $left['x'] = $v['curve']['x'];
            $left['series'][$k]['money'] = round($v['total_money'], 2);
            $left['series'][$k]['value'] = array_values($v['curve']['y']);
        }

        $data['left'] = $left;
        $data['right'] = $right;
        return $this->success($data);
    }

    /**
     * 底部数据
     * @return mixed
     */
    public function bottomTrade(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['data', ""],
        ]);
		if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
			if ($storeIds) {//区域代理商下门店ID
				$where['store_id'] = $storeIds;
			} else {
				$where['store_id'] = -2;
			}
		}
        $bottom = $this->services->getBottomTrade($where);
        return $this->success($bottom);
    }

}
