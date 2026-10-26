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
namespace app\services\product\product;

use app\dao\product\product\StoreProductDao;
use app\services\BaseServices;
use app\services\user\UserServices;


/**
 * Class StoreProductRankServices
 * @package app\services\product\product
 * @mixin StoreProductDao
 */
class StoreProductRankServices extends BaseServices
{
	/**
 	* 排名最大名次
	* @var int
	*/
	protected $rankMax = 20;

	/**
	 * @param StoreProductDao $dao
	 */
	public function __construct(StoreProductDao $dao)
	{
		$this->dao = $dao;
	}


	/**
 	* 获取商品在排行榜中排名
	* @param int $uid
	* @param int $productId
	* @param int $type
	* @return int
	*/
	public function getProductRank(int $uid, int $productId, int $type = 1)
	{
		$where['is_verify'] = 1;
		$where['is_show'] = 1;
		$where['is_del'] = 0;
		$where['show_type'] = [0, 1];
		$where['is_vip_product'] = 0;
		/** @var StoreProductServices $storeProductServices */
		$storeProductServices = app()->make(StoreProductServices::class);
		$where = array_merge($where, $storeProductServices->getWhereByEntryRules($uid));
        if ($uid) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $is_vip = $userServices->value(['uid' => $uid], 'is_money_level');
            $where['is_vip_product'] = $is_vip ? -1 : 0;
        }
		$field = ['id', 'IFNULL(sales,0) + IFNULL(ficti,0) as sales', 'star'];
		switch((int)$type) {
			case 1: //销量
				$order = 'sales desc, sort desc, id desc';
				break;
			case 2: //评分
				$order =  'star desc, sort desc, id desc';
				break;
			case 3: //收藏
				$order =  'collect desc, sort desc, id desc';
				break;
			default :
				$order = 'sales desc, sort desc, id desc';
				break;
		}
		$list = $this->dao->getRecommendProduct($where, $field, $this->rankMax, 0, 0, [], $order);
		$rank = 0;
		if ($list) {
			$key = array_search($productId, array_column($list, 'id'));
			if ($key !== false) {
				$rank = (int)$key + 1;
			}
		}
		return $rank;
	}

	/**
 	* 获取商品排行数据
	* @param int $uid
	* @param int $type
	* @param array $where
	* @param int $limit
	* @return array|null
	* @throws \ReflectionException
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	* @throws \throwable
	*/
	public function getProductRankList(int $uid, int $type = 1, array $where = [], int $limit = 0)
	{
		$where['is_verify'] = 1;
		$where['is_show'] = 1;
		$where['is_del'] = 0;
		$where['show_type'] = [0, 1];
		$where['is_vip_product'] = 0;
		if ($uid) {
			/** @var UserServices $user */
			$user = app()->make(UserServices::class);
			$userInfo = $user->getUserCacheInfo($uid);
			$is_vip = $userInfo['is_money_level'] ?? 0;
			$where['is_vip_product'] = $is_vip ? -1 : 0;
		}
		/** @var StoreProductServices $productServices */
		$productServices = app()->make(StoreProductServices::class);
		switch((int)$type) {
			case 1: //销量
				$order = 'sales desc, sort desc, id desc';
				break;
			case 2: //评分
				$order =  'star desc, sort desc, id desc';
				break;
			case 3: //收藏
				$order =  'collect desc, sort desc, id desc';
				break;
			default :
				$order = 'sales desc, sort desc, id desc';
				break;
		}
		return $productServices->getRecommendProduct($uid, $where, $limit, 'mid', $order);
	}

}
