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
use app\services\other\QrcodeServices;
use app\services\product\product\StoreProductServices;
use app\services\user\UserServices;

/**
 * Class ProductServices
 * @package app\services\pc
 */
class ProductServices extends BaseServices
{

    /**
     * PC端获取商品列表
     * @param array $where
     * @param int $uid
     * @return mixed
     */
    public function getProductList(array $where, int $uid)
    {
        /** @var StoreProductServices $product */
        $product = app()->make(StoreProductServices::class);

        $where['is_show'] = 1;
        $where['is_del'] = 0;
		$where['is_verify'] = 1;
        $data['count'] = $product->getCount($where);
        [$page, $limit] = $this->getPageValue();
        $where['is_vip_product'] = 0;
        if ($uid) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $where['is_vip_product'] = $userServices->checkUserIsSvip($uid) ? -1 : 0;
        }

        $list = $product->getSearchList($where, $page, $limit, ['id,type,product_type,pid,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,star'], '', ['couponId']);
        foreach ($list as &$item) {
			$item['presale_pay_status'] = $product->checkPresaleProductPay((int)$item['id'], $item);
			$item['activity'] = [];
			if (isset($item['couponId'])) {
				$item['checkCoupon'] = (bool)count($item['couponId']);
				unset($item['couponId']);
			} else {
				$item['checkCoupon'] = false;
			}
        }
//        $list = $product->getActivityList($list);
        $data['list'] = get_thumb_water($product->getProduceOtherList($list, $uid, !!$where['status']), 'mid');
        return $data;
    }

    /**
     * PC端商品详情小程序码
     * @param int $product_id
     * @return bool|int|mixed|string
     */
    public function getProductRoutineCode(int $product_id)
    {
		try {
			$namePath = 'routine_product_' . $product_id . '.jpg';
			/** @var QrcodeServices $QrcodeService */
			$QrcodeService = app()->make(QrcodeServices::class);
			//生成小程序地址
			return $QrcodeService->getRoutineQrcodePath($product_id, 0, 0, $namePath);
		} catch (\Exception $e) {
			return '';
		}
    }
}
