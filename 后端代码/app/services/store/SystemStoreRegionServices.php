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
namespace app\services\store;

use app\dao\store\SystemStoreRegionDao;
use app\services\BaseServices;
use app\services\other\CityAreaServices;


/**
 * 门店区域
 * Class SystemStoreRegionServices
 * @package app\services\system\store
 * @mixin SystemStoreRegionDao
 */
class SystemStoreRegionServices extends BaseServices
{

    /**
     * 构造方法
     * SystemStoreRegionServices constructor.
     * @param SystemStoreRegionDao $dao
     */
    public function __construct(SystemStoreRegionDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 	获取所有区域
	 * @param array $where
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getAllRegion(array $where = [])
	{
		return $this->dao->getList($where, 'id,name');
	}


	/**
	 * 获取区域列表
	 * @param array $where
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getRegionList(array $where)
	{
		[$page, $limit] = $this->getPageValue();
		$list = $this->dao->getList($where, '*', $page, $limit);
		/** @var CityAreaServices $cityAreaServices */
		$cityAreaServices = app()->make(CityAreaServices::class);
		$city = $cityAreaServices->getColumn([], 'id,name', 'id');
		if ($list) {
			foreach ($list as &$item) {
				$recommend_region_name = [];
				if ($item['recommend_region']) {
					foreach ($item['recommend_region'] as $region) {
						$recommend_region_name_one = [];
						foreach ($region as $id) {
							$recommend_region_name_one[] = $city[$id]['name'] ?? '';
						}
						$recommend_region_name[] = implode('/', $recommend_region_name_one);
					}
				}
				$item['recommend_region'] = implode('；', $recommend_region_name);
			}
		}
		$count = $this->dao->count($where);
		return compact('list', 'count');
	}

	/**
	 * 根据城市查询所在门店区域
	 * @param array $cityIds
	 * @return int
	 */
	function getRegionByCity(array $cityIds)
	{
		$region = 0;
		if (!$cityIds) {
			return $region;
		}
		$count = count($cityIds);
		$keys = [3 => ['area_id', 'city_id', 'province_id'], '2' => ['city_id', 'province_id'], 1 => ['province_id']];
		$fileds = $keys[$count] ?? '';
		if (!$fileds) {
			return $region;
		}
		$ids = array_reverse($cityIds);

		foreach ($ids as $key => $id) {
			$filed = $fileds[$key] ?? '';
			$where = ['is_del' => 0];
			if ($filed) {
				$where[$filed] = $id;
				$regionInfo = $this->dao->getRegion($where);
				if ($regionInfo) {
					$region = $regionInfo['id'];
					break;
				}
			}
		}
		return $region;
	}

}
