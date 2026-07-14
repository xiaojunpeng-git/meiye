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
declare (strict_types=1);

namespace app\services\pc;


use app\services\BaseServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductServices;
use app\services\user\UserServices;

class HomeServices extends BaseServices
{
    /**
     * pc首页分类商品
     * @param int $uid
     * @return array
     */
    public function getCategoryProduct(int $uid = 0)
    {
        /** @var StoreProductCategoryServices $category */
        $category = app()->make(StoreProductCategoryServices::class);
        /** @var StoreProductServices $product */
        $product = app()->make(StoreProductServices::class);
        [$page, $limit] = $this->getPageValue();
        $list = $category->getCid($page, $limit);
        $where = ['type' => [0, 2], 'product_type' => [0, 1, 2, 3, 4], 'is_show' => 1, 'is_del' => 0, 'is_verify' => 1];
        $where['is_vip_product'] = 0;
        if ($uid) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $where['is_vip_product'] = $userServices->checkUserIsSvip($uid) ? -1 : 0;
        }
        $where['pid'] = 0;
        foreach ($list as &$info) {
            $productList = $product->getSearchList($where + ['cid' => $info['id']], 1, 8, ['id,type,product_type,store_name,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,ot_price,star']);
            $info['productList'] = get_thumb_water($productList, 'mid');
        }
        $data['list'] = $list;
        $data['count'] = $category->getCidCount();
        return $data;
    }
}
