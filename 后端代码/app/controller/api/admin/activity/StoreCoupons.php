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
namespace app\controller\api\admin\activity;

use app\Request;
use app\services\activity\coupon\StoreCouponIssueServices;

/**
 * 优惠券类
 * Class StoreCoupons
 * @package app\api\controller\store
 */
class StoreCoupons
{

	protected $services;

	public function __construct(StoreCouponIssueServices $services)
	{
		$this->services = $services;
	}



    /**
 	* 下单获取用户可使用优惠券
	* @param Request $request
	* @param StoreCouponIssueServices $service
	* @param $uid
	* @return \think\Response
	* @throws \Psr\SimpleCache\InvalidArgumentException
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	*/
    public function order(Request $request, StoreCouponIssueServices $service, $uid)
    {
		[$cartId, $new, $shipping_type,$is_store_delivery_type, $store_id] = $request->getMore([
			'cartId',
            'new',
            ['shipping_type', 1],//配送方式 1=商家发货，2=到店自提，3=门店配送
            ['is_store_delivery_type', 0],//配送方式:1、快递发货，2、同城配送
            ['store_id', 0],
        ], true);
		$uid = (int)$uid;
		$coupons = [];
		if ($uid) {
			$coupons = $service->beUsableCouponList((int)$uid, $cartId, !!$new, (int)$shipping_type, (int)$store_id, (int)$is_store_delivery_type);
		}
        return app('json')->successful($coupons);
    }
}
