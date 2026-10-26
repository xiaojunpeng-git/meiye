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
namespace app\controller\api\v2\activity;

use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\order\StoreOrderServices;
use app\services\product\product\StoreProductCouponServices;
use app\services\store\SystemStoreServices;
use app\Request;


/**
 * 优惠券
 * Class StoreCoupons
 * @package app\controller\api\v2\store
 */
class StoreCoupons
{
    /**
     * @var StoreCouponIssueServices
     */
    protected $services;

    /**
     * StoreCoupons constructor.
     * @param StoreCouponIssueServices $services
     */
    public function __construct(StoreCouponIssueServices $services)
    {
        $this->services = $services;
    }

    /**
     * 可领取优惠券列表
     * @param \app\Request $request
     * @return mixed
     */
    public function lst(Request $request)
    {
        $where = $request->getMore([
            [['type', 'd'], ''],
            [['product_id', 'd'], 0],
            [['brand_id', 'd'], 0],
            [['relation_id', 'd'], 0],
            ['defaultOrder', ''],
            ['timeOrder', ''],
            ['priceOrder', '']
        ]);
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        return app('json')->successful($this->services->getIssueCouponList($uid, $where, '*', true));
    }

    /**
     * 获取新人券
     * @return mixed
     */
    public function getNewCoupon(Request $request)
    {
        $userInfo = $request->user();
        $data = [];
        /** @var StoreCouponIssueServices $couponService */
        $couponService = app()->make(StoreCouponIssueServices::class);
        $data['list'] = $couponService->getNewCoupon();
        $data['image'] = '';
        if ($userInfo->add_time === $userInfo->last_time) {
            $data['show'] = 1;
        } else {
            $data['show'] = 0;
        }
        //会员领取优惠券
        //$couponService->sendMemberCoupon($userInfo->uid);
        return app('json')->success($data);
    }

	/**
	 * 赠送下单之后订单中 关联优惠劵
	 * @param Request $request
	 * @param StoreProductCouponServices $productCouponServices
	 * @param $orderId
	 * @return \think\Response
	 * @throws \Psr\SimpleCache\InvalidArgumentException
	 */
    public function getOrderProductCoupon(Request $request, StoreProductCouponServices $productCouponServices, $orderId)
    {
        if (!$orderId) {
            return app('json')->fail('参数错误');
        }
		$uid = (int)$request->uid();
		/** @var StoreOrderServices $storeOrder */
		$storeOrder = app()->make(StoreOrderServices::class);
		$order = $storeOrder->getOne(['order_id' => $orderId]);
		if (!$order || $order['uid'] != $uid) {
			return app('json')->fail('订单不存在');
		}
        return app('json')->success($productCouponServices->getOrderProductCoupon($uid, (int)$order['id']));
    }

    /**
     * 获取每日新增的优惠券
     * @return mixed
     */
    public function getTodayCoupon(Request $request)
    {
        $uid = 0;
        if ($request->hasMacro('uid')) $uid = (int)$request->uid();
        /** @var StoreCouponIssueServices $couponService */
        $couponService = app()->make(StoreCouponIssueServices::class);
        $data['list'] = $couponService->getTodayCoupon($uid);
        $data['image'] = '';
        return app('json')->success($data);
    }

    /**
     * 优惠券详情
     * @param $id
     * @param StoreCouponIssueServices $service
     * @return \think\Response
     */
    public function detail(Request $request, $id, StoreCouponIssueServices $service)
    {
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $issueCouponInfo = $service->getInfo((int)$id, $uid);
        if(!$issueCouponInfo) {
            return app('json')->fail('优惠券不存在');
        }
        $issueCouponInfo = $issueCouponInfo->toArray();
        if (isset($issueCouponInfo['start_time']) && $issueCouponInfo['start_time']) {
            $issueCouponInfo['start_time'] = date('Y-m-d H:i:s', $issueCouponInfo['start_time']);
        }
        if (isset($issueCouponInfo['end_time']) && $issueCouponInfo['end_time']) {
            $issueCouponInfo['end_time'] = date('Y-m-d H:i:s', $issueCouponInfo['end_time']);
        }
        if (isset($issueCouponInfo['start_use_time']) && $issueCouponInfo['start_use_time']) {
            $issueCouponInfo['start_use_time'] = date('Y-m-d H:i:s', $issueCouponInfo['start_use_time']);
        }
        if (isset($issueCouponInfo['end_use_time']) && $issueCouponInfo['end_use_time']) {
            $issueCouponInfo['end_use_time'] = date('Y-m-d H:i:s', $issueCouponInfo['end_use_time']);
        }
        if (count($issueCouponInfo['used']) > 0) {
            foreach ($issueCouponInfo['used'] as &$item) {
                $item['start_time'] = date('Y-m-d H:i:s', $item['start_time']);
                $item['end_time'] = date('Y-m-d H:i:s', $item['end_time']);
            }
        }
        return app('json')->success($issueCouponInfo);
    }

    /**
     * 获取该优惠券的适用门店
     * @param $store_ids
     * @param SystemStoreServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function applicableCoupon(Request $request, SystemStoreServices $services)
    {
        $data = $request->postMore([
            ['store_ids', ''],
            ['latitude', ''],
            ['longitude', ''],
        ]);
        $where = ['is_del' => 0, 'is_show' => 1];
        if($data['store_ids']) {
            $store_ids = explode(',',$data['store_ids']);
            $where['ids'] = $store_ids;
        }
        $storeList = $services->getStoreList($where,['*'],$data['latitude'],$data['longitude']);
        return app('json')->success($storeList);
    }

    /**
     * 门店首页优惠券
     * @param Request $request
     * @param StoreCouponIssueServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeCoupon(Request $request, StoreCouponIssueServices $services)
    {
        $data = $request->postMore([
            [['relation_id', 'd'], 0],
        ]);
        $uid = 0;
        if ($request->hasMacro('uid')) $uid = (int)$request->uid();
        $where = ['relation_id' => $data['relation_id']];
        $list = $services->getIssueCouponListNew($uid, $where, '*', 0, 5);
        return app('json')->success($list);
    }
}
