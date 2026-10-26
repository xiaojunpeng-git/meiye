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

namespace app\listener\store;


use app\jobs\order\CityDeliveryJob;
use app\jobs\product\ProductSyncStoreJob;
use mohe\interfaces\ListenerInterface;

/**
 * 门店创建成功事件
 * Class StoreSuccess
 * @package app\listener\store
 */
class StoreSuccess implements ListenerInterface
{

    public function handle($event): void
    {
        //提交数据,门店id
        [$data, $id, $is_new, $is_synchronous, $applicable_type, $product_ids] = $event;
        //同步平台商品分类
        if($is_synchronous) {
            ProductSyncStoreJob::dispatchDo('syncProductCate', [$id]);
        }
        if ($is_new) {
            ProductSyncStoreJob::dispatchSece(30,'syncProducts', [$id, $applicable_type, $product_ids, $is_synchronous]);
        }
        //同步平台的商品关联分类
        if($data['product_category_status'] && !$is_synchronous && !$is_new) {
            ProductSyncStoreJob::dispatchSece(30,'syncProductAddCate', [$id]);
        }
		//修改、保存uu、达达门店
		CityDeliveryJob::dispatchDo('syncCityShop', [$id, $is_new]);
    }
}
