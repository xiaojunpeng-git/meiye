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

use app\services\product\category\StoreProductCategoryServices;
use app\services\store\SystemStoreServices;
use think\Request;

/**
 * 商品分类控制器
 * Class StoreProductCategory
 * @package app\controller\api\v1\product
 */
class StoreProductCategory
{
	/**
	 * @var StoreProductCategoryServices
	 */
    protected $services;

	/**
	 * StoreProductCategory constructor.
	 * @param StoreProductCategoryServices $services
	 */
    public function __construct(StoreProductCategoryServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取分类列表
     * @return mixed
     */
    public function category(SystemStoreServices $storeServices, Request $request)
    {
        $where = $request->getMore([
            ['relation_id', 0],
        ]);
		//单店模式 接受门店ID参数 返回当前门店分类
		if (sys_config('shop_operation_type', 1) == 3) {
			$store_id = $request->param('store_id', 0);
			$storeInfo = $storeServices->get((int)$store_id, ['id', 'product_category_status']);
			if ($storeInfo && $storeInfo['product_category_status']) {//有自建分类权限
				$where['relation_id'] = $store_id;
			}
		}
        $category = $this->services->getCategory($where);
        return app('json')->success($category);
    }

	/**
	 * 获取同级的所有分类
	 * @param Request $request
	 * @return \think\Response
	 */
	public function levelCategory(Request $request)
	{
		[$id] = $request->getMore([
			['id', 0],
		], true);
		if (!$id || !is_numeric($id)) {
            return app('json')->fail('分类ID参数错误');
        }
		return app('json')->success($this->services->getLevelCategory((int)$id));
	}

	/**
	 * @author 等风来
	 * @email 136327134@qq.com
	 * @date 2022/11/11
	 * @return mixed
	 */
	public function getCategoryVersion()
	{
		return app('json')->success(['version' => $this->services->getCategoryVersion()]);
	}
}
