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
namespace app\controller\api\pc;

use app\Request;
use app\services\pc\ProductServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductServices;
use mohe\services\SystemConfigService;

/**
 * 商品
 * Class Product
 * @package app\controller\api\pc
 */
class Product
{
    protected $services;

    public function __construct(ProductServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取商品列表
     * @param Request $request
     * @param StoreProductCategoryServices $services
     * @return mixed
     */
    public function getProductList(Request $request, StoreProductCategoryServices $services)
    {
        $where = $request->getMore([
            [['sid', 'd'], 0],
            [['cid', 'd'], 0],
            ['keyword', '', '', 'store_name'],
            ['priceOrder', ''],
            ['salesOrder', ''],
            [['news', 'd'], 0, '', 'timeOrder'],
            [['type', ''], '', '', 'status'],
            ['ids', ''],
            ['selectId', ''],
            ['brand_id', '']
        ]);
        if ($where['selectId'] && (!$where['sid'] || !$where['cid'])) {
			$where['selectId'] = stringToIntArray($where['selectId']);
            if ($services->value(['id' => $where['selectId']], 'pid')) {
                $where['sid'] = $where['selectId'];
            } else {
                $where['cid'] = $where['selectId'];
            }
        }
		$where['ids'] = stringToIntArray($where['ids']);
		$where['brand_id'] = stringToIntArray($where['brand_id']);
        if (!$where['ids']) {
            unset($where['ids']);
        }
		$where['type'] = [0, 2];
		$where['product_type'] = [0, 1, 2, 3, 4];
		if ($where['store_name']) {//搜索
			$where['type'] = [];
			$where['pid'] = 0;
		}

        return app('json')->successful($this->services->getProductList($where, $request->uid()));
    }

    /**
     * PC端商品详情小程序码
     * @param Request $request
     * @return mixed
     */
    public function getProductRoutineCode(Request $request)
    {
        [$product_id] = $request->getMore([
            ['product_id', 0],
        ], true);
        $data = SystemConfigService::more(['product_phone_buy_url', 'site_url']);
        $routineCode = '';
        if (isset($data['product_phone_buy_url']) && $data['product_phone_buy_url'] == 2) {//小程序
            $routineCode = $this->services->getProductRoutineCode((int)$product_id);
        }
        return app('json')->successful(['site_url' => $data['site_url'], 'routineCode' => $routineCode]);
    }

    /**
     * 推荐商品
     * @param Request $request
     * @param $type
     * @return mixed
     */
    public function getRecommendList(Request $request, $type)
    {
        /** @var StoreProductServices $product */
        $product = app()->make(StoreProductServices::class);
		$uid = (int)$request->uid();
        $data = [];
        $data['list'] = [];
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        if ($type == 1) {// 精品推荐
            $where['is_best'] = 1;
        } else if ($type == 2) {//  热门榜单
            $where['is_hot'] = 1;
        } else if ($type == 3) {// 首发新品
            $where['is_new'] = 1;
        } else if ($type == 4) {// 促销单品
            $where['is_benefit'] = 1;
        }
		$data['list'] = $product->getRecommendProduct($uid, $where, 0, 'mid');
        $data['count'] = $product->getCount($where);
        return app('json')->successful($data);
    }

    /**
     * 获取优品推荐
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getGoodProduct()
    {
        /** @var StoreProductServices $product */
        $product = app()->make(StoreProductServices::class);
        $list = get_thumb_water($product->getProducts(['store_label_id' => 5, 'is_del' => 0, 'is_show' => 1, 'is_verify' => 1, 'type' => [0, 2]], '', 0, ['couponId']), 'mid');
        return app('json')->successful(compact('list'));
    }
}
