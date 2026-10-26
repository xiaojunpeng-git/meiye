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
namespace app\controller\api\v1\product;

use app\Request;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductRankServices;
use app\services\product\product\StoreProductServices;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

/**
 * 商品榜单
 * Class StoreProductRank
 * @package app\controller\api\v1\product
 */
class StoreProductRank
{
	/**
	 * @var StoreProductServices
	 */
	protected $services;

	/**
	 * StoreProductRank constructor.
	 * @param StoreProductServices $services
	 */
	public function __construct(StoreProductServices $services)
	{
		$this->services = $services;
	}


	/**
 	* 获取绑定分类
	* @param StoreProductCategoryServices $services
	* @return \think\Response
	* @throws DataNotFoundException
	* @throws DbException
	* @throws ModelNotFoundException
	* @throws \ReflectionException
	*/
	public function rankCategory(StoreProductCategoryServices $services)
	{
		return app('json')->successful($services->getRankCategory());
	}

	/**
 	* 获取榜单列表
	* @param Request $request
	* @param StoreProductCategoryServices $services
	* @param StoreProductRankServices $productRankServices
	* @param $type
	* @return \think\Response
	* @throws DataNotFoundException
	* @throws DbException
	* @throws ModelNotFoundException
	* @throws \throwable
	*/
	public function rankList(Request $request, StoreProductCategoryServices $services, StoreProductRankServices $productRankServices, StoreProductServices $storeProductServices, $type)
	{
		$where = $request->getMore([
            [['selectId', 'd'], ''],
        ]);
        if ($where['selectId']) {
			$level = $services->value(['id' => (int)$where['selectId']], 'level') ?? 0;
			$levelArr = $services->cateField;
			$where[$levelArr[$level] ?? 'cid'] = $where['selectId'];
			unset($where['selectId']);
        }
		$uid = 0;
        if ($request->hasMacro('uid')) $uid = (int)$request->uid();
		$where = array_merge($where, $storeProductServices->getWhereByEntryRules($uid));
		return app('json')->successful($productRankServices->getProductRankList($uid, (int)$type, $where));
	}


}
