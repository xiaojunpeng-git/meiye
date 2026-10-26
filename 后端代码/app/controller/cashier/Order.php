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
use app\model\activity\coupon\StoreCouponIssue;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\product\product\StoreProduct;
use app\model\store\SystemStoreStaff;
use app\model\user\UserCardHolder;
use app\model\yeji\CashSource;
use app\model\yeji\CashType;
use app\model\yeji\YejiCommission;
use app\Request;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\order\OtherOrderServices;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\cashier\OrderServices;
use app\services\order\cashier\CashierIntegerMoney;
use app\services\order\cashier\CashierOrderServices;
use app\services\order\cashier\StoreHangOrderServices;
use app\services\order\store\WriteOffOrderServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderDeliveryServices;
use app\services\order\StoreOrderCardOpsServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\pay\PayServices;
use app\services\store\DeliveryServiceServices;
use app\services\user\UserServices;
use app\services\user\UserRechargeServices;
use app\services\yeji\SatffYejiServices;
use mohe\services\AliPayService;
use mohe\services\CacheService;
use mohe\services\wechat\Payment;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;
use think\facade\App;
use think\facade\Db;

/**
 * 收银台订单控制器
 */
class Order extends AuthController
{
    use CommonOrder;

    /**
     * StoreOrder constructor.
     * @param App $app
     * @param StoreOrderServices $service
     */
    public function __construct(App $app, StoreOrderServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

    /**
     * 获取收银订单用户
     * @param OrderServices $services
     * @param $storeId
     * @param $cashierId
     * @return mixed
     */
    public function getUserList(OrderServices $services, $cashierId)
    {
        $data = $services->getOrderUserList($this->storeId);
        return $this->success($data);
    }

    /**
     * 获取门店订单列表
     * @param Request $request
     * @param StoreOrderServices $services
     * @param UserRechargeServices $rechargeServices
     * @param OtherOrderServices $otherOrderServices
     * @param $orderType
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function getOrderList(Request $request, StoreOrderServices $services, UserRechargeServices $rechargeServices, OtherOrderServices $otherOrderServices, $orderType = 1)
    {
        if (!$orderType) $orderType = 1;
        $where = $request->postMore([
            ['type', ''],
            ['pay_type', ''],
            ['status', ''],
            ['time', ''],
            ['staff_id', ''],
            ['keyword', '', '', 'real_name']
        ]);
        if ($where['time'] && is_array($where['time']) && count($where['time']) == 2) {
            [$start, $end] = $where['time'];
            if (strtotime($start) > strtotime($end)) {
                return $this->fail('开始时间不能大于结束时间，请重新选择时间');
            }
        }
        $where['store_id'] = $this->storeId;
        if (!in_array($where['status'], [-1, -2, -3])) {
            $where['pid'] = 0;
        }
        switch ($orderType) {
            case 1:
            case 5:
                $where['is_system_del'] = 0;
                $with = [
                    'user',
                    'split' => function ($query) {
                        $query->field('id,pid');
                    },
                    'pink',
                    'invoice',
                    'storeStaff'
                ];
                $data = $services->getOrderList($where, ['*'], $with, true, 'add_time desc,status asc,refund_status asc');
                $list = $data['data'] ?? [];
                if ($list) {
                    /** @var StoreCouponIssueServices $couponIssueService */
                    $couponIssueService = app()->make(StoreCouponIssueServices::class);
                    foreach ($list as $key => &$item) {
                        if ($item['give_coupon']) {
                            $couponIds = is_string($item['give_coupon']) ? explode(',', $item['give_coupon']) : $item['give_coupon'];

                            $item['give_coupon'] = $couponIssueService->getColumn([['id', 'IN', $couponIds]], 'id,coupon_title');
                        }
                    }
                }
                $count = $data['count'] ?? 0;
                return $this->success(compact('list', 'count'));
            case 2:
                return $this->success($rechargeServices->getRechargeList($where, '*', 0, ['staff', 'user']));
            case 3:
                $where['paid'] = 1;
                return $this->success($otherOrderServices->getMemberRecord($where));
        }
        return $this->success(['list' => [], 'count' => 0]);
    }

    /**
     * 获取收银台挂单列表
     * @param Request $request
     * @param StoreHangOrderServices $services
     * @param int $cashierId
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function getHangList(Request $request, StoreHangOrderServices $services, $cashierId = 0)
    {
        $search = $request->get('keyword', '');
        $data = $services->getHangOrderList((int)$this->storeId, 0, $search);
        $data['list'] = $data['data'];
        unset($data['data']);
        return $this->success($data);
    }

    /**
     * 收银台退款订单列表
     * @param Request $request
     * @param StoreOrderRefundServices $service
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function getRefundList(Request $request, StoreOrderRefundServices $service)
    {
        $where = $request->getMore([
            ['keyword', '', '', 'order_id'],
            ['time', ''],
            ['refund_type', '']
        ]);
        $where['store_id'] = $this->storeId;
        return $this->success($service->refundList($where));
    }

    /**
     * 收银台核销订单
     * @param Request $request
     * @param StoreOrderServices $services
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function getVerifyList(Request $request, StoreOrderServices $services)
    {
        $where = $request->postMore([
            ['status', ''],
            ['time', ''],
            ['search_type', ''],
            ['staff_id', ''],
            ['keyword', '', '', 'real_name']
        ]);
        if ($where['time'] && is_array($where['time']) && count($where['time']) == 2) {
            [$start, $end] = $where['time'];
            if (strtotime($start) > strtotime($end)) {
                return $this->fail('开始时间不能大于结束时间，请重新选择时间');
            }
        }
		$where['paid'] = 1;
		$where['is_del'] = 0;
        $where['is_system_del'] = 0;
        $where['is_user_del'] = 0;
        $where['type'] = 105;
        $cross_store_verification = (int)sys_config('cross_store_verification', 1);//跨店核销
        if(!$cross_store_verification) {
            $where['store_id'] = $this->storeId;
        }

        if (!in_array($where['status'], [-1, -2, -3])) {
            $where['pid'] = 0;
        }
        $result = $services->getOrderList($where, ['*'], ['split' => function ($query) {
            $query->field('id,pid');
        }, 'pink', 'invoice', 'storeStaff'], true);
        if ($result['data']) {
            /** @var StoreCouponIssueServices $couponIssueService */
            $couponIssueService = app()->make(StoreCouponIssueServices::class);
            /** @var WriteOffOrderServices $writeOffOrderServices */
            $writeOffOrderServices = app()->make(WriteOffOrderServices::class);
            $time=time();
            foreach ($result['data'] as $key => &$item) {
                $writeOffOrderServices->syncOrderWriteoffConsistency($item);
                $freshStatus = StoreOrder::where('id', (int)$item['id'])->value('status');
                if ($freshStatus !== null) {
                    $item['status'] = (int)$freshStatus;
                }
                if ($item['give_coupon']) {
                    $couponIds = is_string($item['give_coupon']) ? explode(',', $item['give_coupon']) : $item['give_coupon'];
                    $item['give_coupon'] = $couponIssueService->getColumn([['id', 'IN', $couponIds]], 'id,coupon_title');
                }
                //是否有效卡
                $item['is_yx']=0;
                if ((int)($item['product_type'] ?? 0) === 5) {
                    $mainCart = StoreOrderCartInfo::where('oid', $item['id'])
                        ->where('cart_type', 0)
                        ->field('write_start,write_end')
                        ->find();
                    $writeStart = (int)($mainCart['write_start'] ?? 0);
                    $writeEnd = (int)($mainCart['write_end'] ?? 0);
                    $validTime = ($writeEnd === 0 || $writeEnd > $time) && ($writeStart === 0 || $writeStart <= $time);
                    $successCount = StoreOrderCartInfo::where('oid', $item['id'])
                        ->where('cart_type', 2)
                        ->where('write_surplus_times', '>', 0)
                        ->count();
                    if ($item['refund_status'] == 0 && $successCount > 0 && $validTime) {
                        $item['is_yx'] = 1;
                    }
                } else {
                    $successCount=StoreOrderCartInfo::where("oid",$item['id'])
                        ->where("write_surplus_times",">",0)
                        ->where(function ($quetwo) use ($time){
                            $quetwo->where("write_end",0)->whereOr("write_end",">",$time);
                        })->count();
                    if($item['refund_status'] == 0 && $successCount > 0){
                        $item['is_yx']=1;
                    }
                }
                $item['sale_day'] = !empty($item['_pay_time'])
                    ? substr($item['_pay_time'], 0, 10)
                    : substr($item['add_time'], 0, 10);
                $item['validity_text'] = $this->formatVerifyValidityText((int)$item['id']);
            }
        }
        return $this->success($result);
    }

    /**
     * 消耗列表-有效期文案
     * @param int $oid
     * @return string
     */
    protected function formatVerifyValidityText(int $oid): string
    {
        $writeValid = 1;
        $writeEnd = 0;
        $writeDays = 0;

        $validityData = UserCardHolder::where('oid', $oid)
            ->field('write_valid,write_end')
            ->find();
        if ($validityData) {
            $writeValid = (int)($validityData['write_valid'] ?? 1);
            $writeEnd = (int)($validityData['write_end'] ?? 0);
        } else {
            $cartMain = StoreOrderCartInfo::where('oid', $oid)
                ->where('cart_type', 0)
                ->field('write_start,write_end,cart_info')
                ->find();
            if (!$cartMain) {
                return '不限制时间';
            }
            $writeEnd = (int)($cartMain['write_end'] ?? 0);
            $cartInfo = is_string($cartMain['cart_info']) ? json_decode($cartMain['cart_info'], true) : $cartMain['cart_info'];
            $attrInfo = $cartInfo['productInfo']['attrInfo'] ?? [];
            $writeValid = (int)($attrInfo['write_valid'] ?? 1);
            $writeDays = (int)($attrInfo['write_days'] ?? $attrInfo['days'] ?? 0);
            if ($writeEnd <= 0) {
                $writeEnd = (int)($attrInfo['write_end'] ?? 0);
            }
        }

        if ($writeValid === 1 || ($writeValid === 2 && $writeDays <= 0)) {
            return '不限制时间';
        }
        if ($writeEnd > 0) {
            return date('Y-m-d', $writeEnd);
        }
        return '不限制时间';
    }

    public function getService(Request $request){
        $data = $request->postMore([
            ['product_id', '']
        ]);
        $product_id=$data['product_id'];
        $pid=StoreProduct::where('id',$product_id)->value("pid");
        if(empty($pid)){
            $pid=$product_id;
        }
        $oncePrice=YejiCommission::where("product_id",$pid)->value("yeji");
        if(empty($oncePrice)){
            $oncePrice=0;
        }
        $result['price']=$oncePrice;
        return $this->success($result);
    }
    public function getPrice(Request $request){
        $data = $request->postMore([
            ['id', '']
        ]);
        $cha=0;
//        $totalPrice=StoreOrderCartInfo::where('oid',$data['id'])->where("cart_type",0)->sum("pay_price");
//        if($totalPrice == 0){
//            $cartInfo=StoreOrderCartInfo::where('oid',$data['id'])->where("cart_type",2)->select();
//        }else{
//            $cartInfo=StoreOrderCartInfo::where('oid',$data['id'])->where("cart_type",0)->select();
//        }
        $cartInfo=StoreOrderCartInfo::where('oid',$data['id'])->where("cart_type",2)->select();
        if(empty($cartInfo)){
            $cartInfo=StoreOrderCartInfo::where('oid',$data['id'])->where("cart_type",0)->select();
        }
        $onePrice=0;
        $yuNum=0;
        $isCard=0;  //是否几选几 按 次数
        $productId=StoreOrderCartInfo::where("oid",$data['id'])->where("cart_type",0)->value("product_id");
        if(!empty($productId)){
            $productInfo=StoreProduct::where("id",$productId)->find();
            // 兼容异常数据：订单商品在商品表中可能已删除/不存在，避免访问 null 下标报错
            if(!empty($productInfo) && isset($productInfo['card_num'],$productInfo['card_num_type']) && $productInfo['card_num'] > 0 && $productInfo['card_num_type'] == 1){
                //几选几套餐 按订单付款金额判断单次金额
                $totalPrice=StoreOrder::where("id",$data['id'])->value("pay_price");
                $onePrice=bcdiv($totalPrice,$productInfo['card_num'],2);
                $has=StoreOrderWriteoff::where("oid",$data['id'])->where("status",0)->sum("writeoff_num");
                $yuNum=bcsub((string)$productInfo['card_num'],(string)$has);
                $isCard=1;
            }
        }
        if($onePrice > 0){
            $cha=bcmul($onePrice,$yuNum,2);
        }else{
            foreach ($cartInfo as $k => $v) {
                if ($v['write_times'] == 0) {
                    $onePrice = 0;
                } else {
                    $onePrice = $v['pay_price'] / $v['write_times'];
                }
                $cha=bcadd($cha,$onePrice*$v['write_surplus_times'],2);
            }
        }
        $result['yu_num']=$yuNum;
        $result['cha']=$cha;
        $result['is_card_num']=$isCard;
        return $this->success($result);
    }
    /**
     * 退款订单详情
     * @param StoreOrderRefundServices $service
     * @param UserServices $userServices
     * @param $id
     * @return mixed
     */
    public function refundInfo(StoreOrderRefundServices $service, UserServices $userServices, $id)
    {
        $order = $service->refundDetail($id);
        $order['total_price'] = floatval(bcadd((string)$order['total_price'], (string)$order['vip_true_price'], 2));
        $data['orderInfo'] = $order;
        $userInfo = ['spread_uid' => '', 'spread_name' => '无'];
        if ($order['uid']) {
            $userInfo = $userServices->getUserWithTrashedInfo((int)$order['uid']);
            if (!$userInfo) return $this->fail('用户信息不存在');
            $userInfo = $userInfo->hidden(['pwd', 'add_ip', 'last_ip', 'login_type']);
            $userInfo = $userInfo->toArray();
            $userInfo['spread_name'] = '无';
            if ($order['spread_uid']) {
                $spreadName = $userServices->value(['uid' => $order['spread_uid']], 'nickname');
                if ($spreadName) {
                    $userInfo['spread_name'] = $order['uid'] == $order['spread_uid'] ? $spreadName . '(自购)' : $spreadName;
                    $userInfo['spread_uid'] = $order['spread_uid'];
                } else {
                    $userInfo['spread_uid'] = '';
                }
            } else {
                $userInfo['spread_uid'] = '';
            }
        }
        $data['userInfo'] = $userInfo;
        return $this->success('ok', $data);
    }

    /**
     * 收银台计算订单金额
     * @param Request $request
     * @param CashierOrderServices $services
     * @param $uid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderCompute(Request $request, CashierOrderServices $services, $uid)
    {
        [$integral, $coupon, $cartIds, $coupon_id, $new, $changeCartInfo, $isPrice, $changePrice, $cartCoupons] = $request->postMore([
            ['integral', 0],
            ['coupon', 0],
            ['cart_id', []],
            ['coupon_id', 0],
            ['new', 0],
            // 改价相关：用于“改价后选择优惠券”按改价后金额计算
            ['cart_info', []],
            ['is_price', 0],
            ['change_price', 0],
            // 明细行优惠券：[['cart_id'=>1,'coupon_id'=>2], ...]
            ['cart_coupons', []],
        ], true);
        if (!$cartIds) {
            return $this->fail('缺少购物车ID');
        }
        try {
            CashierIntegerMoney::assertRequestAmounts([
                'change_price' => $changePrice,
                'cart_info' => $changeCartInfo,
            ]);
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage());
        }

        $socket = $request->post('socket', '');
        //发送消息
        if (!$socket) {
            SocketPushJob::dispatch([$this->cashierId, 'changCompute', [
                'uid' => $uid,
                'post_data' => [
                    'integral' => $integral,
                    'coupon' => $coupon,
                    'cart_id' => $cartIds,
                    'coupon_id' => $coupon_id,
                    'new' => $new,
                    'cart_info' => $changeCartInfo,
                    'is_price' => $isPrice,
                    'change_price' => $changePrice,
                    'cart_coupons' => $cartCoupons,
                ],
            ], 'cashier']);
        }

        $computeData = $services->computeOrder(
            (int)$uid,
            (int)$this->storeId,
            $cartIds,
            !!$integral,
            (!!$coupon || (int)$coupon_id > 0 || !empty($cartCoupons)),
            [],
            (int)$coupon_id,
            !!$new,
            [],
            0,
            'yue',
            4,
            0,
            [],
            (array)$changeCartInfo,
            !!$isPrice,
            (float)$changePrice,
            (array)$cartCoupons
        );
        $computeData = CashierIntegerMoney::normalizeComputeData($computeData);
        CashierIntegerMoney::assertComputeData($computeData);
        return $this->success($computeData);
    }

    public function cashType(){
         $data=CashType::field("id as type,name")->where("status",1)->select();
         return $this->success('ok', $data);
    }
    public function cashSource(){
         $data=CashSource::field("id as type,name")->where("status",1)->select();
         return $this->success('ok', $data);
    }

    //删除会员优惠劵
    public function delCoupon($id, StoreCouponUserServices $services)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $info=$services->get($id);
        if ($services->delete($id)) {
            StoreCouponIssue::where("id",$info['cid'])->inc("remain_count",1)->update();
            return $this->success('删除成功');
        } else {
            return $this->fail('删除失败');
        }
    }
    /**
     * 生成订单
     * @param CashierOrderServices $services
     * @param $uid
     * @return mixed
     */
    public function createOrder(CashierOrderServices $services, $uid)
    {
        [$sendAll,$giveIds,$is_budan,$budan_time,$is_gendan,$gendan_staff_id,$serviceYejiAll,$source,$combinationInfo,$remarkInfo,$cashChoose,$selectedProduct,$setYejiAll,$integral, $coupon, $cartIds, $payType, $remarks, $staffId, $changePrice, $changeCartInfo, $isPrice, $userCode, $coupon_id, $authCode, $touristUid, $seckillId, $collate_code_id, $new, $cartCoupons] = $this->request->postMore([
            ['sendAll', []],
            ['giveIds', []],
            ['is_budan', 0],
            ['budan_time', ''],
            ['is_gendan', 0],
            ['gendan_staff_id', 0],
            ['serviceYejiAll', []],
            ['source', 0],
            ['combination_info',[]],
            ['remarkInfo',[]],
            ['cash_choose', 0],
            ['selectedProduct', []],
            ['setYejiAll', []],
            ['integral', 0],
            ['coupon', 0],
            ['cart_id', []],
            ['pay_type', ''],
            ['remarks', ''],
            ['staff_id', 0],
            ['change_price', 0],//改价金额
			['cart_info', []],//商品改价数组[['id' => 1, 'true_price' => 11.00],['id' => 2, 'true_price' => 11.00]]
            ['is_price', 0],
            ['userCode', ''],
            ['coupon_id', 0],
            ['auth_code', ''],
            ['tourist_uid', ''],
            ['seckill_id', 0],
            ['collate_code_id', 0],//拼单ID 、桌码ID
            ['new', 0],
            ['cart_coupons', []],
        ], true);
        if (!$staffId) {
            $staffId = $this->request->cashierId();
        }
        if (!$cartIds) {
            return $this->fail('缺少购物车ID');
        }
        try {
            CashierIntegerMoney::assertRequestAmounts([
                'change_price' => $changePrice,
                'cart_info' => $changeCartInfo,
                'combination_info' => $combinationInfo,
            ]);
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage());
        }
        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        $sendAllForOrder = is_array($sendAll) ? $sendAll : ['product' => [], 'coupon' => []];
        $sendAllForCart = $sendAllForOrder;
        $hasGiftProjectShell = $storeCartServices->cartIdsHasGiftProjectShell((array)$cartIds);
        if ($hasGiftProjectShell && !empty($sendAllForCart['product'])) {
            $fromSend = array_values(array_filter(array_map(function ($row) {
                return (int)($row['id'] ?? 0);
            }, is_array($sendAllForCart['product']) ? $sendAllForCart['product'] : [])));
            $selected = is_array($selectedProduct) ? $selectedProduct : [];
            $selectedProduct = array_values(array_unique(array_merge(
                array_values(array_filter(array_map('intval', $selected))),
                $fromSend
            )));
            // 赠送项目壳单：赠送品项写入 selectedProduct，不再通过 addSend 单独加购（避免互斥校验失败）
            $sendAllForCart['product'] = [];
        }
        try {
            $storeCartServices->validateCashierGiftProjectCheckout((array)$cartIds, $selectedProduct);
        } catch (\think\exception\ValidateException $e) {
            return $this->fail($e->getMessage());
        }
        $totalCartNumber=count($cartIds);
        if($cashChoose == 9){
            $source=7;
        }
        if(!$payType && !$authCode && !$userCode) return $this->fail('缺少参数');
		/** @var UserServices $userService */
		$userService = app()->make(UserServices::class);
		$uid = (int)$uid;
        //游客的情况下 不能选择 老客续卡和售后客来源
        if($uid == 0 && in_array($source,[6,11])){
            return $this->fail('游客下单不能选择该来源！');
        }
        // 游客（uid=0）不能赠送项目
        if ($uid == 0 && (!empty($sendAllForOrder['product']) || !empty($giveIds))) {
            return $this->fail('游客下单不能赠送项目！');
        }
        if (!in_array($payType, ['yue', 'cash']) && $authCode) {
            if (Payment::isWechatAuthCode((string)$authCode)) {
                $payType = PayServices::WEIXIN_PAY;
            } else if (AliPayService::isAliPayAuthCode((string)$authCode)) {
                $payType = PayServices::ALIAPY_PAY;
            } else if ($userService->isUserCode((int)$authCode, $uid)) {
				$payType = PayServices::YUE_PAY;
				$userCode = $authCode;
            } else {
				return $this->fail('未知,付款二维码');
			}
        }
        $userInfo = [];
        if ($uid) {
            $userInfo = $userService->getUserInfo($uid);
            if (!$userInfo) {
                return $this->fail('用户不存在');
            }
            $userInfo = $userInfo->toArray();
        }
        try {
            $sendCart=$services->addSend($sendAllForCart,$uid,$this->storeId);
            if(!empty($sendCart)){
                $cartIds=array_merge($cartIds,$sendCart);
                $giveIds=$sendCart;
            }
			$computeData = $services->computeOrder(
                (int)$uid,
                (int)$this->storeId,
                $cartIds,
                !!$integral,
                (!!$coupon || (int)$coupon_id > 0 || !empty($cartCoupons)),
                $userInfo,
                (int)$coupon_id,
                !!$new,
                [],
                0,
                'yue',
                4,
                0,
                [],
                (array)$changeCartInfo,
                !!$isPrice,
                (float)$changePrice,
                (array)$cartCoupons
            );
            // 结算后统一整数落库：订单总额优先，尾差落最后一行
            $computeData = CashierIntegerMoney::normalizeComputeData($computeData);
            CashierIntegerMoney::assertComputeData($computeData);
            $cartInfo = $computeData['cartInfo'];
            if(!empty($sendCart)){
               $payPrice=0;
               $totalPrice=0;
               $sumPrice=0;
                foreach ($cartInfo as $kk=>$vv){
                      if(in_array($vv['id'],$sendCart)){
                          $cartInfo[$kk]['pay_price']=0;
                          $cartInfo[$kk]['sum_price']=0;
                          $cartInfo[$kk]['truePrice']=0;
                          $cartInfo[$kk]['total_price']=0;
                          $cartInfo[$kk]['pay_price']=0;
                      }else{
                          $payPrice=bcadd((string)$payPrice,(string)$vv['pay_price'],2);
                          $totalPrice=bcadd((string)$totalPrice,(string)$vv['total_price'],2);
                          $sumPrice=bcadd((string)$sumPrice,(string)$vv['sum_price'],2);
                      }
                }
                $computeData['payPrice']=$payPrice;
                $computeData['totalPrice']=$totalPrice;
                $computeData['sumPrice']=$sumPrice;
                $computeData['cartInfo']=$cartInfo;
                $computeData = CashierIntegerMoney::normalizeComputeData($computeData);
                CashierIntegerMoney::assertComputeData($computeData);
                $cartInfo = $computeData['cartInfo'];
            }
            $cartGroup = $computeData['cartGroup'] ?? [];
            $other = $cartGroup['other'];
            $reservationInfo = $other['reservationInfo'] ?? [];
            $reservation_time=$reservationInfo['reservation_time'] ?? '';
            if(!empty($reservation_time)){
                $can_hx=0;
            }
            //只有商品类型那么 shippingType为 4
            //既有商品又有卡项 那么为2
            $shippingType=4;
            $types=[];
            $mainId = 8154; //定制卡id
            $productIds = StoreProduct::where("pid", $mainId)->column("id");
            $productIds[] = $mainId;
            $isDingzhi=false;
            foreach ($cartInfo as $k=>$v){
                 if($v['product_type'] != 0){
                     $shippingType=2;
                 }
                 $types[]=$v['product_type'];
                 if(in_array($v['product_id'],$productIds)){
                     $isDingzhi=true;
                 }
            }
            $types=array_unique($types);
            //有卡项就只能拆单
            if(in_array(5,$types)){
                if($totalCartNumber > 1){
                    return $this->fail('卡项只能单张购买，并且不能与其他商品同时购买!');
                }
                $shippingType=4;
                if($isDingzhi){
                    return $this->fail('有定制卡的情况不能添加其他卡项!');
                }
            }
            // 组合支付若明细全部为「余额」且不含卡升级，按余额支付落单（pay_type=yue），避免记为 combination
            if ($payType === PayServices::COMBINATION_PAY && is_array($combinationInfo) && $combinationInfo !== []) {
                CashType::validateCombinationInfo($combinationInfo);
                if (CashType::isOnlyBalanceCombination($combinationInfo)) {
                    $payType = PayServices::YUE_PAY;
                    $combinationInfo = [];
                }
            }
            $res = $services->transaction(function () use ($services, $userInfo, $computeData, $authCode, $uid, $staffId, $cartIds, $payType, $integral, $coupon, $remarks, $changePrice, $changeCartInfo, $isPrice, $userCode, $coupon_id, $seckillId, $collate_code_id,$setYejiAll,$selectedProduct,$cashChoose,$remarkInfo,$combinationInfo,$source,$shippingType,$serviceYejiAll,$is_budan,$budan_time,$is_gendan,$gendan_staff_id,$giveIds,$sendAllForOrder) {
                $orderInfo = $services->createOrder((int)$uid, $userInfo, $computeData, $this->storeId, (int)$staffId, $cartIds, $payType, !!$integral, !!$coupon, $remarks, $changePrice, $changeCartInfo, !!$isPrice, $coupon_id, $seckillId, $collate_code_id,0,[],$shippingType,0,'',0,$selectedProduct,$cashChoose,$remarkInfo,$combinationInfo,$source,$setYejiAll,$serviceYejiAll,$is_budan,$budan_time,$giveIds,$sendAllForOrder,$is_gendan,$gendan_staff_id);
                if (in_array($payType, [PayServices::YUE_PAY, PayServices::CASH_PAY, PayServices::ALIAPY_PAY, PayServices::WEIXIN_PAY,PayServices::COMBINATION_PAY])) {
                    if($payType == PayServices::COMBINATION_PAY){
                          $res=$services->combinationPay($orderInfo['order_id'],$combinationInfo);
                         if ($res['status'] !== true) {
                            throw new ValidateException('余额支付失败！');
                        }
                    }
                    $res = $services->paySuccess($orderInfo['order_id'], $payType, $userCode, $authCode);
                    $res['order_id'] = $orderInfo['order_id'];
                    $res['oid'] = $orderInfo['id'];
                    //加入购卡业绩
                    return $res;
                } else {
                    return ['status' => 'ORDER_CREATE', 'order_id' => $orderInfo['order_id'], 'oid' => $orderInfo['id']];
                }
            });

            if (isset($res['status']) && $res['status'] === 'SUCCESS') {
                //发送消息
                SocketPushJob::dispatch([$this->cashierId, 'changSuccess', [], 'cashier']);

                CacheService::redisHandler(CacheService::CASHIER_AUX_SCREEN_TAG . '_' . $this->storeId)->clear();
            }

            return app('json')->success($res);
        } catch (\Throwable $e) {
            //回退库存
            if ($seckillId) {
                foreach ($cartInfo as $item) {
                    if (!isset($item['product_attr_unique']) || !$item['product_attr_unique']) continue;
                    $type = $item['type'];
                    if (in_array($type, [1, 2, 3])) CacheService::setStock($item['product_attr_unique'], (int)$item['cart_num'], $type, false);
                }
            }

            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * 订单支付
     * @param CashierOrderServices $services
     * @param $orderId
     * @return mixed
     */
    public function payOrder(CashierOrderServices $services, $orderId)
    {
        if (!$orderId) {
            return $this->fail('缺少订单号');
        }
        $payType = $this->request->post('payType', 'yue');

        $userCode = $this->request->post('userCode', '');
        $authCode = $this->request->post('auth_code', '');
        $is_cashier_yue_pay_verify = (int)sys_config('is_cashier_yue_pay_verify'); // 收银台余额支付是否需要验证【是/否】
        if ($payType == PayServices::YUE_PAY && !$userCode && $is_cashier_yue_pay_verify) {
            return $this->fail('缺少用户余额支付CODE');
        }
        if (!in_array($payType, ['yue', 'cash']) && $authCode) {
            if (Payment::isWechatAuthCode($authCode)) {
                $payType = PayServices::WEIXIN_PAY;
            } else if (AliPayService::isAliPayAuthCode($authCode)) {
                $payType = PayServices::ALIAPY_PAY;
            } else {
                return $this->fail('未知,付款二维码');
            }
        }
        $res = $services->paySuccess($orderId, $payType, $userCode, $authCode, true);

        if (isset($res['status']) && $res['status'] === 'SUCCESS') {
            //发送消息
            SocketPushJob::dispatch([$this->cashierId, 'changSuccess', [], 'cashier']);

            CacheService::redisHandler(CacheService::CASHIER_AUX_SCREEN_TAG . '_' . $this->storeId)->clear();
        }

        return $this->success($res);
    }

    /**
     * 订单核销订单数据
     * @param Request $request
     * @param WriteOffOrderServices $writeOffOrderServices
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function verifyCartInfo(Request $request, WriteOffOrderServices $writeOffOrderServices)
    {
        [$oid] = $request->postMore([
            ['oid', '']
        ], true);
        return $this->success($writeOffOrderServices->getOrderCartInfo(0, (int)$oid));
    }

    /**
     * 订单核销
     * @param Request $request
     * @param StoreOrderServices $services
     * @param WriteOffOrderServices $writeOffOrderServices
     * @param $id
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function writeOff(Request $request, StoreOrderServices $services, WriteOffOrderServices $writeOffOrderServices, $id)
    {
        if (!$id) {
            return $this->fail('核销订单未查到!');
        }
        [$is_budan,$budan_time,$cart_ids,$syncAll] = $request->postMore([
            ['is_budan', 0],
            ['budan_time', ''],
            ['cart_ids', []],
            ['syncAll', []],
        ], true);
		$orderInfo = $writeOffOrderServices->getOrderCartInfo(0, (int)$id);
        if ($cart_ids) {
            foreach ($cart_ids as $cart) {
                if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num'] || $cart['cart_num'] <= 0) {
                    return $this->fail($orderInfo['type'] == 12 ? '您有待服务的预约单，请前往预约列表完成核销' : '请重新选择核销商品，或核销件数');
                }
            }
        }
        $writeOffOrderServices->writeoffOrder(0, $orderInfo, $cart_ids, 'cashier', (int)$this->cashierId,$syncAll,$is_budan,$budan_time);
        return $this->success('核销成功');
    }

    /**
     * 订单可用的优惠券列表
     * @param Request $request
     * @param CashierOrderServices $services
     * @param $uid
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function couponList(Request $request, CashierOrderServices $services, $uid)
    {
        [$cartIds] = $request->postMore([
            ['cart_id', []],
        ], true);
        if (!$uid) return $this->success([]);
        return $this->success($services->getCouponList((int)$uid, $this->storeId, $cartIds));
    }

    /**
     * 卡升级：获取会员旧的有效卡项（仅返回未退款/有效剩余次数）
     * @param Request $request
     * @param int $uid
     * @return mixed
     */
    public function validCardUpgradeList(Request $request, int $uid)
    {
        if (!$uid) return $this->success([]);
        $time = time();
        // 口径：选择“整张卡项”（cart_type=0 的卡项名称），剩余金额=该订单下 cart_type=2 的所有明细剩余金额合计
        // 1) 先取卡项订单（store_order.product_type=5）对应的“卡项头”（cart_type=0）
        $headers = Db::name('store_order')
            ->alias('o')
            ->join('store_order_cart_info c0', 'c0.oid = o.id')
            ->where('o.uid', $uid)
            ->where('o.product_type', 5)
            ->where('o.card_upgrade_use_oid', 0)
            ->where('o.refund_type', 0)
            ->where('o.refund_status', 0)
            ->where('c0.cart_type', 0)
            ->field('c0.id as cart_info_id,c0.oid,c0.product_id,c0.cart_info,o.order_id')
            ->order('c0.id desc')
            ->select();

        if (!$headers) return $this->success([]);

        $oids = [];
        foreach ($headers as $h) {
            $oids[] = (int)$h['oid'];
        }
        $oids = array_values(array_unique($oids));

        // 2) 按 oid 聚合 cart_type=2 的剩余价值
        $remainRows = Db::name('store_order_cart_info')
            ->whereIn('oid', $oids)
            ->where('cart_type', 2)
            ->where('write_surplus_times', '>', 0)
            ->where(function ($q) use ($time) {
                $q->where('write_end', 0)->whereOr('write_end', '>', $time);
            })
            ->field('oid,pay_price,write_times,write_surplus_times')
            ->select();

        $remainMap = [];
        foreach ($remainRows as $r) {
            $oid = (int)$r['oid'];
            $per = '0.00';
            if (!empty($r['write_times'])) {
                $per = bcdiv((string)$r['pay_price'], (string)$r['write_times'], 4);
            }
            $line = bcmul((string)$per, (string)$r['write_surplus_times'], 2);
            $remainMap[$oid] = isset($remainMap[$oid]) ? bcadd((string)$remainMap[$oid], (string)$line, 2) : $line;
        }

        $result = [];
        foreach ($headers as $row) {
            $oid = (int)$row['oid'];
            $remain = $remainMap[$oid] ?? '0.00';
            // 没有任何可用剩余金额则不返回
            if (bccomp((string)$remain, '0', 2) <= 0) continue;

            $cartInfo = is_string($row['cart_info']) ? json_decode($row['cart_info'], true) : ($row['cart_info'] ?? []);
            $result[] = [
                // 选择对象：整张卡项（cart_type=0）
                'cart_info_id' => (int)$row['cart_info_id'],
                'oid' => $oid,
                'old_oid' => $oid,
                'order_id' => $row['order_id'],
                'old_order_id' => $row['order_id'],
                'product_id' => (int)$row['product_id'],
                'store_name' => $cartInfo['productInfo']['store_name'] ?? '',
                'remain_value' => (float)$remain,
            ];
        }
        return $this->success($result);
    }
    /**
     * 领取优惠券
     *
     * @param Request $request
     * @return mixed
     */
    public function couponReceive(Request $request, StoreCouponIssueServices $storeCouponIssueServices, UserServices $userServices, $uid)
    {
        [$couponId] = $request->getMore([
            ['couponId', 0]
        ], true);
        if (!$uid || !$couponId || !is_numeric($couponId)) return app('json')->fail('参数错误!');

        $userInfo = $userServices->getUserInfo($uid);
        if (!$userInfo) {
            return app('json')->fail('请选择用户');
        }
        $coupon = $storeCouponIssueServices->issueUserCoupon((int)$couponId, $userInfo);
        if ($coupon) {
            $coupon = $coupon->toArray();
            return app('json')->success('领取成功', $coupon);
        }
        return app('json')->fail('领取失败');
    }

    /**
     * 收银台删除挂单
     * @param Request $request
     * @param StoreCartServices $services
     * @return mixed
     */
    public function deleteHangOrder(Request $request, StoreCartServices $services)
    {
        $id = $request->get('id');
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $id = explode(',', $id) ?: [];
        if ($services->search(['id' => $id])->delete()) {
            return $this->success('删除成功');
        } else {
            return $this->fail('删除失败');
        }
    }

    /**
     * 收银台售后订单退款
     * @param Request $request
     * @param StoreOrderServices $services
     * @param StoreOrderRefundServices $refundService
     * @param $id
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function agreeRefund(Request $request, StoreOrderServices $services, StoreOrderRefundServices $refundService, $id)
    {
        $data = $request->postMore([
            ['refund_price', 0],
            ['type', 1],
			['stock_in_type', '0'],//退款同步操作退货入库0:暂不入库1:良品入库2:残次品入库
        ]);
        if (!$id) {
            return $this->fail('Data does not exist!');
        }
        $orderRefund = $refundService->get($id);
        if (!$orderRefund) {
            return $this->fail('Data does not exist!');
        }
        if ($orderRefund['is_cancel'] == 1) {
            return $this->fail('用户已取消申请');
        }
        $order = $services->get((int)$orderRefund['store_order_id']);
        if (!$order) {
            return $this->fail('Data does not exist!');
        }
		if ($order['refund_status'] == 2) {
			return app('json')->fail('订单已完成退款，请勿重复操作!');
		}
        if (!in_array($orderRefund['refund_type'], [0, 1, 2, 5]) && !($orderRefund['refund_type'] == 4 && $orderRefund['apply_type'] == 3)) {
            return $this->fail('售后订单状态不支持该操作');
        }

        if ($data['type'] == 1) {
            $data['refund_type'] = 6;
        } else if ($data['type'] == 2) {
            $data['refund_type'] = 3;
        }
        $data['refunded_time'] = time();
        $type = $data['type'];
        //拒绝退款
        if ($type == 2) {
            $refundService->refuseRefund((int)$orderRefund['id'], $data, $orderRefund);
            return $this->success('修改退款状态成功!');
        } else {
            //0元退款
            if ($orderRefund['refund_price'] == 0) {
                $refund_price = 0;
            } else {
                if (!$data['refund_price']) {
                    return $this->fail('请输入退款金额');
                }
                if ($orderRefund['refund_price'] == $orderRefund['refunded_price']) {
                    return $this->fail('已退完支付金额!不能再退款了');
                }
                $refund_price = $data['refund_price'];

                $data['refunded_price'] = bcadd((string)$data['refund_price'], (string)$orderRefund['refunded_price'], 2);
                $bj = bccomp((string)$orderRefund['refund_price'], (string)$data['refunded_price'], 2);
                if ($bj < 0) {
                    return $this->fail('退款金额大于支付金额，请修改退款金额');
                }
            }
            unset($data['type']);
            $refund_data['pay_price'] = $order['pay_price'];
            $refund_data['refund_price'] = $refund_price;

            //修改订单退款状态
            unset($data['refund_price']);

			$refundService->setItem('change_manager_type', 'cashier')
				->setItem('change_manager_id', $request->cashierId())
				->setItem('stock_in_type', $data['stock_in_type'] ?? 0);
            $refundService->agreeRefund($id, $refund_data);
			$refundService->reset();

			//退款处理
			$refundService->update($id, $data);
			return $this->success('退款成功');
        }
    }

    /**
     * 收银台获取配送员
     * @param DeliveryServiceServices $services
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function getDeliveryList(DeliveryServiceServices $services)
    {
        $where = $this->request->getMore([
            ['field_key', ''],
            ['keyword', '']
        ]);
        return $this->success($services->getDeliveryList(1, $this->storeId,$where));
    }

    /**
     * 面单默认配置信息
     * @return mixed
     */
    public function getSheetInfo()
    {
        return $this->success([
            'express_temp_id' => store_config($this->storeId, 'store_config_export_temp_id'),
            'id' => store_config($this->storeId, 'store_config_export_id'),
            'to_name' => store_config($this->storeId, 'store_config_export_to_name'),
            'to_tel' => store_config($this->storeId, 'store_config_export_to_tel'),
            'to_add' => store_config($this->storeId, 'store_config_export_to_address'),
            'export_open' => (bool)store_config($this->storeId, 'store_config_export_open')
        ]);
    }

    /**
     * 收银台订单发货
     * @param Request $request
     * @param StoreOrderDeliveryServices $services
     * @param $id
     * @return mixed
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\db\exception\DbException
     */
    public function updateDelivery(Request $request, StoreOrderDeliveryServices $services, $id)
    {
        $data = $request->postMore([
            ['type', 1],
            ['delivery_name', ''],//快递公司名称
            ['delivery_id', ''],//快递单号
            ['delivery_code', ''],//快递公司编码

            ['express_record_type', 2],//发货记录类型
            ['express_temp_id', ""],//电子面单模板
            ['to_name', ''],//寄件人姓名
            ['to_tel', ''],//寄件人电话
            ['to_addr', ''],//寄件人地址

            ['sh_delivery_name', ''],//送货人姓名
            ['sh_delivery_id', ''],//送货人电话
            ['sh_delivery_uid', ''],//送货人ID

            ['fictitious_content', ''],//虚拟发货内容

            ['cart_ids', []]
        ]);
        if (!$id) {
            return $this->fail('缺少发货ID');
        }
        if (!$data['cart_ids']) {
            $msg = $data['type'] == 2 ? '派单成功' : '发货成功';
            $res = $services->delivery((int)$id, $data, $this->cashierId);
            return $this->success($msg, $res);
        }
        foreach ($data['cart_ids'] as $cart) {
            if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num']) {
                return $this->fail('请重新选择发货商品，或发货件数');
            }
        }
        $res = $services->splitDelivery((int)$id, $data, $this->cashierId);
        return $this->success('发货成功', $res);
    }

    /**
     * 获取次卡商品核销表单
     * @param WriteOffOrderServices $writeOffOrderServices
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeOrderFrom(WriteOffOrderServices $writeOffOrderServices, $id)
    {
        if (!$id) {
            return $this->fail('缺少核销订单ID');
        }
        [$cart_num] = $this->request->getMore([
            ['cart_num', 1]
        ], true);
        return $this->success($writeOffOrderServices->writeOrderFrom((int)$id, (int)$this->cashierId, (int)$cart_num));
    }

    /**
     * 次卡商品核销表单提交
     * @param WriteOffOrderServices $writeOffOrderServices
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeoffFrom(WriteOffOrderServices $writeOffOrderServices, $id)
    {
        if (!$id) {
            return $this->fail('缺少核销订单ID');
        }
        $orderInfo = $this->services->getOne(['id' => $id, 'is_del' => 0], '*', ['pink']);
        if (!$orderInfo) {
            return $this->fail('核销订单未查到!');
        }
        $data = $this->request->postMore([
            ['cart_id', ''],//核销订单商品cart_id
            ['cart_num', 0]
        ]);
        $cart_ids[] = $data;
        if ($cart_ids) {
            foreach ($cart_ids as $cart) {
                if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num'] || $cart['cart_num'] <= 0) {
					return $this->fail($orderInfo['type'] == 12 ? '您有待服务的预约单，请前往预约列表完成核销' : '请重新选择核销商品，或核销件数');
                }
            }
        }
        return app('json')->success('核销成功', $writeOffOrderServices->writeoffOrder(0, $orderInfo->toArray(), $cart_ids, 'cashier', (int)$this->cashierId));
    }

    /**
     * 易联云打印机打印
     * @param $id
     * @return mixed
     */
    public function order_print($id)
    {
        if (!$id) return app('json')->fail('缺少参数');
        $order = $this->services->get($id);
        if (!$order) {
            return app('json')->fail('订单没有查到,无法打印!');
        }
        $this->services->orderPrint((int)$id, 1, (int)$this->storeId);
		return app('json')->success('打印成功');
    }

    //获取服务人员
    public function getStaffYeji(){
        $data = $this->request->postMore([
            ['goods_id', 0],
            ['price', 0],
            ['staff_id', 0],
        ]);
        $yeji=[
            'link_id'=>0,
            'goods_id'=>$data['goods_id'],
            'price'=>$data['price'],
            'type'=>2,
            'staffChoose'=>[]
        ];
        $position=Db::name("position")->column("name","id");
        $positionLevel=Db::name("position_level")->column("name","id");
        $staff=SystemStoreStaff::where("id",$data['staff_id'])->find();
        $yeji['staffChoose']=[[
            'staff_id'=>$data['staff_id'],
            'staff_name'=>$staff['staff_name'],
            'position_label'=>$position[$staff['position']] ?? '',
            'position'=>$staff['position'],
            'position_level'=>$staff['position_level'],
            'position_level_label'=>$positionLevel[$staff['position_level']] ?? '',
            'yeji'=>$data['price'],
            'is_dian'=>0,
        ]];
        return app('json')->successful('成功！',$yeji);
    }
    //获取业绩
    public function getYeji(){
        $data = $this->request->postMore([
            ['link_id', 0],//核销订单商品cart_id
            ['goods_id', 0],
            ['type', 0],
        ]);
        $data=Db::name("staff_yeji")->where($data)->select();
        if(empty($data)){
            $data=[];
        }else{
            $data=$data->toArray();
        }
        return app('json')->successful('成功！',$data);
    }

    //保存业绩
    public function saveYeji(){
        $data = $this->request->postMore([
            ['link_id', 0],//核销订单商品cart_id
            ['goods_id', 0],
            ['cart_id', 0],
            ['type', 0],
            ['price', 0],
            ['staffChoose', []],
        ]);
        $staffYeji = app()->make(SatffYejiServices::class);
        $staffYeji->saveYeji($data);
        return app('json')->success('成功！');
    }

    /**
     * 卡项订单转让
     */
    public function cardTransfer(Request $request, StoreOrderCardOpsServices $services)
    {
        [$id, $toUid] = $request->postMore([
            ['id', 0],
            ['to_uid', 0],
        ], true);
        $id = (int)$id;
        $toUid = (int)$toUid;
        if ($id <= 0) {
            return $this->fail('订单不存在');
        }
        $managerId = ($request->hasMacro('cashierId') && $request->cashierId()) ? (int)$request->cashierId() : 0;
        $services->transfer($id, $toUid, $managerId, 'store');
        return $this->success('转让成功');
    }

    /**
     * 卡项订单延期
     */
    public function cardExtend(Request $request, StoreOrderCardOpsServices $services)
    {
        [$id, $writeEnd] = $request->postMore([
            ['id', 0],
            ['write_end', ''],
        ], true);
        $id = (int)$id;
        if ($id <= 0) {
            return $this->fail('订单不存在');
        }
        $managerId = ($request->hasMacro('cashierId') && $request->cashierId()) ? (int)$request->cashierId() : 0;
        $services->extend($id, (string)$writeEnd, $managerId, 'store');
        return $this->success('延期成功');
    }
}
