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
use app\services\product\category\StoreProductCategoryServices;
use app\services\statistic\ProductStatisticServices;
use think\facade\App;

/**
 * Class ProductStatistic
 * @package app\controller\admin\v1\statistic
 */
class ProductStatistic extends AuthController
{
    /**
     * ProductStatistic constructor.
     * @param App $app
     * @param ProductStatisticServices $services
     */
    public function __construct(App $app, ProductStatisticServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 商品基础
     * @return mixed
     */
    public function getBasic(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],
			['store_id', 0]
        ]);
        $where['time'] = $this->getDay($where['time']);
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
        return $this->success($this->services->getBasic($where));
    }

	/**
	 * 商品趋势
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 */
    public function getTrend(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],
			['store_id', 0]
        ]);
        $where['time'] = $this->getDay($where['time']);
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
        return $this->success($this->services->getTrend($where));
    }

    /**
     * 商品排行
     * @return mixed
     */
    public function getProductRanking(StoreProductCategoryServices $storeProductCategoryServices, SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
			['cate_id', ''],
            ['data', '', '', 'time'],
            ['sort', ''],
			['store_id', 0]
        ]);
		$cateId = $where['cate_id'];
		if ($cateId) {
			$cateId = is_string($cateId) ? [$cateId] : $cateId;
			$cateId = array_merge($cateId, $storeProductCategoryServices->getColumn(['pid' => $cateId], 'id'));
			$cateId = array_unique(array_diff($cateId, [0]));
		}
		$where['cate_id'] = $cateId;
        $where['time'] = $this->getDay($where['time']);
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
        return $this->success($this->services->getProductRanking($where));
    }

    /**
     * 导出
     * @return mixed
     */
    public function getExcel(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],
			['store_id', 0]
        ]);
        $where['time'] = $this->getDay($where['time']);
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
        return $this->success($this->services->getTrend($where, true));
    }

    /**
     * 格式化时间
     * @param $time
     * @return string
     */
    public function getDay($time)
    {
        if (strstr($time, '-') !== false) {
            [$startTime, $endTime] = explode('-', $time);
            if (!$startTime && !$endTime) {
                return date("Y/m/d", strtotime("-30 days", time())) . '-' . date("Y/m/d", time());
            } else {
                return $startTime . '-' . $endTime;
            }
        } else {
            return date("Y/m/d", strtotime("-30 days", time())) . '-' . date("Y/m/d", time());
        }
    }
}
