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
namespace app\controller\api\store\product;

use app\services\product\category\StoreProductCategoryServices;
use think\Request;

/**
 * Class StoreProductCategory
 * @package app\api\controller\v1\store
 */
class StoreProductCategory
{

	protected $services;

	public function __construct(StoreProductCategoryServices $services)
	{
		$this->services = $services;
	}

    /**
     * 获取分类列表
     * @return mixed
     */
    public function category(Request $request)
    {
        $where = $request->getMore([
            ['relation_id', 0],
        ]);
        $category = $this->services->getCategory($where);
        return app('json')->success($category);
    }
}
