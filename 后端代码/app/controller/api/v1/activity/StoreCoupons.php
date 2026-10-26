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
namespace app\controller\api\v1\activity;

use app\Request;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\store\SystemStoreServices;

/**
 * 优惠券类
 * Class StoreCoupons
 * @package app\controller\api\store
 */
class StoreCoupons
{
    protected $services;

    public function __construct(StoreCouponIssueServices $services)
    {
        $this->services = $services;
    }

    /**
     * 可领取优惠券列表
     * @param Request $request
     * @return mixed
     */
    public function lst(Request $request)
    {
        $where = $request->getMore([
            [['type', 'd'], ''],
            [['product_id', 'd'], 0],
            [['brand_id', 'd'], 0],
            [['coupon_issue_type', 'd'], 0],
        ]);
//        if ($request->getFromType() == 'pc') $where['type'] = -1;
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        return app('json')->successful($this->services->getIssueCouponList($uid, $where)['list']);
    }

    /**
     * 领取优惠券
     *
     * @param Request $request
     * @return mixed
     */
    public function receive(Request $request)
    {
        [$couponId] = $request->getMore([
            ['couponId', 0]
        ], true);
        if (!$couponId || !is_numeric($couponId)) return app('json')->fail('参数错误!');

        /** @var StoreCouponIssueServices $couponIssueService */
        $couponIssueService = app()->make(StoreCouponIssueServices::class);
        $coupon = $couponIssueService->issueUserCoupon((int)$couponId, $request->user());
        if ($coupon) {
            $coupon = $coupon->toArray();
            return app('json')->success('领取成功', $coupon);
        }
        return app('json')->fail('领取失败');
    }

    /**
     * 我的优惠券数量
     * @param Request $request
     * @param StoreCouponUserServices $storeCouponUserService
     * @return \think\Response
     * @throws \think\db\exception\DbException
     */
    public function userCount(Request $request, StoreCouponUserServices $storeCouponUserService)
    {
        $uid = (int)$request->uid();
        return app('json')->successful($storeCouponUserService->getUserCounponNum($uid));
    }

    /**
     * 用户已领取优惠券
     * @param Request $request
     * @param $types
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function user(Request $request, StoreCouponUserServices $storeCouponUserService, $types)
    {
        $uid = (int)$request->uid();
        [$type] = $request->getMore([
            ['type', ''],
        ], true);
        return app('json')->successful($storeCouponUserService->getUserCounpon($uid, $types, $type));
    }

    /**
     * 转赠前按完整手机号精确查询接收会员
     */
    public function transferTarget(Request $request, StoreCouponUserServices $services)
    {
        [$couponUserId, $phone] = $request->postMore([
            ['coupon_user_id', 0],
            ['phone', ''],
        ], true);
        return app('json')->successful($services->getTransferTargetByPhone(
            (int)$request->uid(),
            (int)$couponUserId,
            (string)$phone
        ));
    }

    /**
     * 转赠一张会员优惠券
     */
    public function transfer(Request $request, StoreCouponUserServices $services)
    {
        [$couponUserId, $phone, $requestId] = $request->postMore([
            ['coupon_user_id', 0],
            ['phone', ''],
            ['request_id', ''],
        ], true);
        return app('json')->success('转赠成功', $services->transferCoupon(
            (int)$request->uid(),
            (int)$couponUserId,
            (string)$phone,
            (string)$requestId
        ));
    }

    /**
     * 优惠券 订单获取
     * @param Request $request
     * @param $price
     * @return mixed
     */
    public function order(Request $request, StoreCouponIssueServices $service, $cartId, $new)
    {
        [$shipping_type,$is_store_delivery_type, $storeId] = $request->getMore([
            ['shipping_type', 1],//配送方式 1=商家发货，2=到店自提，3=门店配送
            ['is_store_delivery_type', 0],//配送方式:1、快递发货，2、同城配送
            ['store_id', 0],
        ], true);
        return app('json')->successful($service->beUsableCouponList((int)$request->uid(), $cartId, !!$new, (int)$shipping_type, (int)$storeId, (int)$is_store_delivery_type));
    }
}
