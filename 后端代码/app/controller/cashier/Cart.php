<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\controller\cashier;

use app\common\controller\Order as CommonOrder;
use app\jobs\system\SocketPushJob;
use app\Request;
use app\services\order\OtherOrderServices;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\cashier\OrderServices;
use app\services\order\cashier\CashierOrderServices;
use app\services\order\cashier\StoreHangOrderServices;
use app\services\order\store\WriteOffOrderServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderDeliveryServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\pay\PayServices;
use app\services\store\DeliveryServiceServices;
use app\services\user\UserServices;
use app\services\user\UserRechargeServices;
use mohe\services\AliPayService;
use mohe\services\CacheService;
use mohe\services\wechat\Payment;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\App;

/**
 * 收银台购物车控制器
 */
class Cart extends AuthController
{

	/**
	 * @param App $app
	 * @param StoreCartServices $service
	 */
    public function __construct(App $app, StoreCartServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }


    /**
     * 加入购物车
     * @param Request $request
     * @param StoreCartServices $services
     * @param $uid
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function addCart(Request $request, StoreCartServices $services, $uid)
    {
        $where = $request->postMore([
			['cart_type', 0],//加入购物车商品类型0普通3无码商品
            ['productId', 0],//普通商品编号
            [['cartNum', 'd'], 1], //购物车数量
            ['uniqueId', ''],//属性唯一值
			['price', 0],//商品金额
            ['staff_id', ''],//店员ID
            ['new', 1],//1直接购买,0=加入购物车
            ['tourist_uid', ''],//虚拟用户uid
            [['secKillId', 'd'], 0],//秒杀商品编号
			['reservation_time', ''],//预约日期
			[['reservation_time_id', 'd'], 0],//预约商品时段ID
			['real_name', ''],//预约用户昵称
			['phone', ''],//预约用户手机号
			['service_staff_id', 0],//服务人员ID
        ]);
		if ($where['cart_type'] == 3) {//无码商品 随机属性唯一值
			$where['uniqueId'] = substr(md5($uid . time() . uniqid(true)), 12, 8);
		}

        $new = !!$where['new'];

        if (!$where['cart_type'] && !$where['productId']) {
            return app('json')->fail('参数错误');
        }
        //真实用户存在，虚拟用户uid为空
        if ($uid) {
            $where['tourist_uid'] = '';
            /** @var UserServices $userservice */
            $userServices = app()->make(UserServices::class);
            $userInfo = $userServices->getUserInfo($uid);
            if (!$userInfo) {
                return $this->fail('用户不存在或已注销');
            }
        }
        if (!$uid && !$where['tourist_uid']) {
            return $this->fail('缺少用户UID');
        }
        $services->setItem('store_id', $this->storeId)->setItem('tourist_uid', $where['tourist_uid'])->setItem('staff_id', $where['staff_id']);
		//预约相关参数
		$services->setItem('reservation_type', 2)->setItem('reservation_time', $where['reservation_time'] ?? '')->setItem('reservation_time_id', $where['reservation_time_id'] ?? 0)
			->setItem('real_name', $where['real_name'] ?? '')->setItem('phone', $where['phone'] ?? '')->setItem('service_staff_id', $where['service_staff_id'] ?? 0)->setItem('is_check_reservation_time', 0);
		//无码商品
		$services->setItem('cart_type', $where['cart_type'])->setItem('price', $where['price']);

        $activityId = $type = 0;

        if ($where['secKillId']) {
            $type = 1;
            $activityId = $where['secKillId'];
        }

        [$cartId, $cartNum] = $services->setCart($uid, (int)$where['productId'], (int)$where['cartNum'], $where['uniqueId'], $type, $new, (int)$activityId);

        $services->reset();

        SocketPushJob::dispatch([$this->cashierId, 'changCart', ['uid' => $uid], 'cashier']);

        return $this->success(['cartId' => $cartId]);
    }

    /**
     * 收银台更改购物车数量
     * @param Request $request
     * @param StoreCartServices $services
     * @param $uid
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function numCart(Request $request, StoreCartServices $services, $uid)
    {
        $where = $request->postMore([
            ['id', 0],//购物车编号
            ['number', 0],//购物数量
        ]);
        if (!$where['id'] || !$where['number'] || !is_numeric($where['id']) || !is_numeric($where['number'])) {
            return $this->fail('参数错误!');
        }
		$services->setItem('is_check_reservation_time', 0);
        if ($services->changeCashierCartNum((int)$where['id'], (int)$where['number'], $uid, $this->storeId)) {
			$services->reset();
            //发送消息
            SocketPushJob::dispatch([$this->cashierId, 'changCart', ['uid' => $uid], 'cashier']);

            return $this->success('修改成功');
        } else {
            return $this->fail('修改失败');
        }
    }

    /**
     * 收银台删除购物车信息
     * @param Request $request
     * @param StoreCartServices $services
     * @param $uid
     * @return mixed
     */
    public function delCart(Request $request, StoreCartServices $services, $uid)
    {
        $where = $request->postMore([
            ['ids', []],//购物车编号
        ]);
        if (!count($where['ids'])) {
            return $this->fail('参数错误!');
        }
        if ($services->removeUserCart((int)$uid, $where['ids'])) {

            //发送消息
            SocketPushJob::dispatch([$this->cashierId, 'changCart', ['uid' => $uid], 'cashier']);

            return $this->success('删除成功');
        } else {
            return $this->fail('清除失败！');
        }
    }

    /**
     * 收银台重选商品规格
     * @param Request $request
     * @param StoreCartServices $services
     * @return mixed
     */
    public function changeCart(Request $request, StoreCartServices $services)
    {
        [$cart_id, $product_id, $unique] = $request->postMore([
            ['cart_id', 0],
            ['product_id', 0],
            ['unique', '']
        ], true);
        $services->modifyCashierCart($this->storeId, (int)$cart_id, (int)$product_id, $unique);

        //发送消息
        SocketPushJob::dispatch([$this->cashierId, 'changCart', [], 'cashier']);

        return $this->success('重选成功');
    }

    /**
     * 获取购物车数据
     * @param Request $request
     * @param StoreCartServices $services
     * @param $uid
     * @param $cashierId
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCartList(Request $request, StoreCartServices $services, $uid, $cashierId)
    {
        $cartIds = $request->get('cart_ids', '');
        $touristUid = $request->get('tourist_uid', '');
        $new = $request->get('new', false);
        $cartIds = $cartIds ? explode(',', $cartIds) : [];
        if (!$touristUid && !$uid) {
            return $this->fail('缺少用户信息');
        }
		$where = ['store_id' => $this->storeId, 'tourist_uid' => $touristUid];
        $result = $services->getUserCartList((int)$uid, $where, $cartIds, 4, 0, !!$new);
        /** @var \app\services\product\product\ProductGiftServices $productGiftServices */
        $productGiftServices = app()->make(\app\services\product\product\ProductGiftServices::class);
        $result['valid'] = $productGiftServices->attachToCartList($result['valid'] ?? []);
        $result['valid'] = $services->getReturnCartList($result['valid'] ?? [], $result['promotions'] ?? []);
        unset($result['promotions']);
        return $this->success($result);
    }

}
