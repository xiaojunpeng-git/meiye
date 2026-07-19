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

namespace app\services\order\store;


use app\dao\order\StoreOrderDao;
use app\jobs\store\StoreFinanceJob;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\product\product\StoreProduct;
use app\model\user\UserCard;
use app\model\user\UserCardHolder;
use app\model\yeji\YejiCommission;
use app\services\message\service\StoreServiceServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderTakeServices;
use app\services\order\StoreOrderWriteOffServices;
use app\services\activity\combination\StorePinkServices;
use app\services\BaseServices;
use app\services\order\StoreReservationOrderServices;
use app\services\store\SystemStoreStaffServices;
use app\services\store\DeliveryServiceServices;
use app\services\user\UserCardHolderServices;
use app\services\user\UserServices;
use mohe\services\CacheService;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;

/**
 * 核销订单
 * Class StoreOrderWriteOffServices
 * @package app\sservices\order
 * @mixin StoreOrderDao
 */
class WriteOffOrderServices extends BaseServices
{

    /**
     * 构造方法
     * StoreOrderWriteOffServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 检测用户核销订单、预约单权限
     * @param int $id
     * @param int $oid
     * @param string $userType
     * @param string $orderType
     * @param $orderInfo
     * @return int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkWriteoffAuth(int $id, int $oid, string $userType = 'user', string $orderType = 'order',array $orderInfo = [],int $auth = 0)
    {
        if (!$id && !$oid) {
            throw new ValidateException('核销单不存在');
        }
        if (!$orderInfo) {
            switch ($orderType) {
                case 'order'://订单
                    $orderInfo = $this->dao->getOne(['id' => $oid, 'is_del' => 0], '*', ['user', 'pink']);
                    break;
                case 'reservation'://预约单
                    /** @var StoreReservationOrderServices $reservationOrderService */
                    $reservationOrderService = app()->make(StoreReservationOrderServices::class);
                    $orderInfo = $reservationOrderService->getReservationOrderInfo(0, (int)$id);
                    break;
            }
        }
        if (!$orderInfo) {
            throw new ValidateException('核销单不存在');
        }
        $storeId = $orderInfo['store_id'] ?? 0;
        $isAuth = false;
        $cross_store_verification = (int)sys_config('cross_store_verification', 1);//跨店核销
        try {
            switch ($userType) {
                case 'admin'://超级管理员
                    $isAuth = true;
                    break;
                case 'kefu'://平台客服
                    /** @var StoreServiceServices $storeService */
                    $storeService = app()->make(StoreServiceServices::class);
                    $userService = $storeService->checkoutIsService(['uid' => $id, 'status' => 1, 'account_status' => 1, 'customer' => 1]);
                    if ($userService) {//平台客服
                        $isAuth = true;
                        $auth = 1;
                    }
                    break;
                case 'user'://移动端用户
                    /** @var DeliveryServiceServices $deliverServiceServices */
                    $deliverServiceServices = app()->make(DeliveryServiceServices::class);
                    if ($auth == 2) {
                        $deliver = $deliverServiceServices->getDeliveryInfoByUid($id);
                        if (in_array($orderInfo['shipping_type'], [1, 3]) && $deliver && $orderInfo['delivery_type'] == 'send' && $orderInfo['delivery_uid'] == $id) {
                            $isAuth = true;
                            $auth = 2;
                        }
                    } else {
                        /** @var StoreServiceServices $storeService */
                        $storeService = app()->make(StoreServiceServices::class);
                        $userService = $storeService->checkoutIsService(['uid' => $id, 'status' => 1, 'account_status' => 1, 'customer' => 1]);
                        if ($userService) {//平台客服
                            $isAuth = true;
                            $auth = 1;
                        } else {
                            try {
                                /** @var SystemStoreStaffServices $storeStaffServices */
                                $storeStaffServices = app()->make(SystemStoreStaffServices::class);
                                $staff = $storeStaffServices->getStaffInfoByUid($id);
                            } catch (\Throwable $e) {
                                $staff = [];
                            }
                            //店员存在 && 开启核销权限 && 订单是前端门店的
                            if ($staff && $staff['verify_status'] == 1 && ($staff['store_id'] == $storeId || $cross_store_verification)) {
                                $isAuth = true;
                                $auth = 4;
                            } else {//配送员
                                $deliver = $deliverServiceServices->getDeliveryInfoByUid($id);
                                if (in_array($orderInfo['shipping_type'], [1, 3]) && $deliver && $orderInfo['delivery_type'] == 'send' && $orderInfo['delivery_uid'] == $id) {
                                    $isAuth = true;
                                    $auth = 2;
                                }
                            }
                        }
                    }

                    break;
                case 'store'://门店核销
                case 'cashier'://收银员
                    if ($id) {//传入id 就验证店员权限，不传默认门店后台管理员操作
                        //验证店员
                        /** @var SystemStoreStaffServices $storeStaffServices */
                        $storeStaffServices = app()->make(SystemStoreStaffServices::class);
                        $info = $storeStaffServices->getStaffInfo($id);
                        //店员存在 && 开启核销权限 && 订单是前端门店的
                        if ($info && $info['verify_status'] == 1 && ($info['store_id'] == $storeId || $cross_store_verification)) {
                            $isAuth = true;
                        }
                    } else {
                        $isAuth = true;
                    }
                    $auth = 4;
                    break;
            }
        } catch (\Throwable $e) {
        }
        if (!$isAuth) {
            throw new ValidateException('您无权限核销此订单，请联系管理员');
        }
        return $auth;
    }

    /**
     * 验证用户有哪些身份权限
     * @param int $uid
     * @return array 0  管理员  1  客服  2  配送员  3  用户微信扫码 4店员
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkUserAuth(int $uid)
    {
        $auth = [];
        $store_id = 0;
        /** @var StoreServiceServices $storeService */
        $storeService = app()->make(StoreServiceServices::class);
        $userService = $storeService->checkoutIsService(['uid' => $uid, 'status' => 1, 'account_status' => 1, 'customer' => 1]);
        if ($userService) {
            $auth[] = 1;
        }
        try {
            /** @var DeliveryServiceServices $deliverServiceServices */
            $deliverServiceServices = app()->make(DeliveryServiceServices::class);
            $info = $deliverServiceServices->getDeliveryInfoByUid($uid);
            if ($info) {
                $auth[] = 2;
            }
        } catch (\Throwable $e) {
        }
        try {
            /** @var SystemStoreStaffServices $storeStaffServices */
            $storeStaffServices = app()->make(SystemStoreStaffServices::class);
            $info = $storeStaffServices->getStaffInfoByUid($uid);
            if ($info && $info['verify_status'] == 1) {
                $auth[] = 4;
                $store_id = $info['store_id'];
            }
        } catch (\Throwable $e) {
        }
        return [$auth, $store_id];
    }

    /**
     * 用户码获取待核销订单列表
     * @param int $uid
     * @param string $code
     * @param int $auth
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function userUnWriteoffOrder(int $uid, string $code, string $userType = 'user')
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getOne(['bar_code' => $code]);
        if (!$userInfo) {
            throw new ValidateException('该用户不存在');
        }
        [$auth, $storeId] = $this->checkUserAuth($uid);
        if ($auth) {//有核销身份
            $where = [];
            if (in_array(1, $auth)) {//客服
            } elseif (in_array(4, $auth)) {//店员
                if ($storeId) $where = ['store_id' => $storeId];
            } elseif (in_array(2, $auth)) {//配送员
                $where = ['delivery_uid' => $uid];
            }
            $unWriteoffOrder = $this->dao->getUnWirteOffList(['uid' => $userInfo['uid']] + $where, ['id']);
        } else {
            $unWriteoffOrder = [];
        }
        $data = [];
        if ($unWriteoffOrder) {
            foreach ($unWriteoffOrder as $item) {
                try {
                    $orderInfo = $this->writeoffOrderInfo($uid, '', $userType, $item['id']);
                } catch (\Throwable $e) {//无权限或其他异常不返回订单信息
                    $orderInfo = [];
                }
                if ($orderInfo) $data[] = $orderInfo;
            }
        }
        return $data;
    }

    /**
     * 获取核销订单信息
     * @param int $uid
     * @param string $code
     * @param int $auth
     * @param int $oid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeoffOrderInfo(int $uid, string $code = '', string $userType = 'admin', int $oid = 0, int $staff_id = 0, int $auth = 0)
    {
        if ($oid) {
            //订单
            $orderInfo = $this->dao->getOne(['id' => $oid, 'is_del' => 0, 'is_user_del' => 0], '*', ['user', 'pink']);
            $order_type = 'order';
        } else {
            //订单
            $orderInfo = $this->dao->getOne(['verify_code' => $code, 'is_del' => 0, 'is_user_del' => 0], '*', ['user', 'pink']);
            $order_type = 'order';
        }

        if (!$orderInfo) {
            throw new ValidateException('Write off order does not exist');
        }
        $orderInfo = is_array($orderInfo) ? $orderInfo : $orderInfo->toArray();
        if (!empty($orderInfo['is_debt_repay'])) {
            throw new ValidateException('补交订单不可核销');
        }
        if ($order_type == 'order' && !$orderInfo['paid']) {
            throw new ValidateException('订单还未完成支付');
        }
        if ($order_type == 'order' && $orderInfo['refund_status'] != 0) {
            throw new ValidateException('该订单状态暂不支持核销');
        }
		if ($order_type == 'order' && $this->dao->count(['pid' => $orderInfo['id']])) {//订单已拆分
			throw new ValidateException('该订单已拆分，请使用子订单核销');
		}

        $orderInfo['order_type'] = $order_type;
        //验证权限
        if ($userType != 'admin') $this->checkWriteoffAuth($uid, (int)$orderInfo['id'], $userType, 'order', [], $auth);

        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $cartServices->getCartInfoList(['oid' => $orderInfo['id']], ['id', 'oid', 'write_times', 'write_surplus_times', 'write_start', 'write_end']);
        $orderInfo['write_off'] = $orderInfo['write_times'] = 0;
        $orderInfo['write_day'] = '';
        $cart = $cartInfo[0] ?? [];
        if ($orderInfo['product_type'] == 4 && $cart) {//次卡商品
            $orderInfo['write_off'] = max(bcsub((string)$cart['write_times'], (string)$cart['write_surplus_times'], 0), 0);
            $orderInfo['write_times'] = $cart['write_times'] ?? 0;
            $start = $cart['write_start'] ?? 0;
            $end = $cart['write_end'] ?? 0;
            if (!$start && !$end) {
                $orderInfo['write_day'] = '不限时';
            } else {
                $orderInfo['write_day'] = ($start ? date('Y-m-d', $start) : '') . '/' . ($end ? date('Y-m-d', $end) : '');
            }
        }
        return $orderInfo;
    }

    /**
     * 获取订单商品信息
     * @param int $uid
     * @param int $id
     * @param string $userType
     * @param int $staff_id
     * @param bool $isCasher
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderCartInfo(int $uid, int $id, string $userType = 'admin')
    {
        $orderInfo = $this->writeoffOrderInfo($uid, '', $userType, $id);
        $this->syncOrderWriteoffConsistency($orderInfo);
        $orderInfo = $this->writeoffOrderInfo($uid, '', $userType, $id);
        // 旧卡升级：已被用于卡升级的旧卡，视为失效卡，不可核销
        $orderInfo['is_card_upgrade_old'] = (int)(($orderInfo['card_upgrade_use_oid'] ?? 0) > 0);
        if ($orderInfo['is_card_upgrade_old']) {
            $useOid = (int)($orderInfo['card_upgrade_use_oid'] ?? 0);
            $orderInfo['card_upgrade_use_oid'] = $useOid;
            $orderInfo['card_upgrade_use_order_id'] = $useOid ? ($this->dao->value(['id' => $useOid], 'order_id') ?? '') : '';
        } else {
            $orderInfo['card_upgrade_use_order_id'] = '';
        }
        $writeoff_count = 0;
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        $isGiftProjectOrder = $storeCartServices->isGiftProjectShellOrder((int)$orderInfo['id']);
        $where = [];
        if ($orderInfo['type'] == 11 || $isGiftProjectOrder) {
            $where['cart_type'] = 2;
        }
        $mainCartValidity = $this->isCardPackageOrder($orderInfo)
            ? $this->getMainCartValidity((int)$orderInfo['id'])
            : null;
        $cartInfo = $cartInfoServices->getCartColunm(['oid' => $orderInfo['id']] + $where, 'id,product_id,cart_id,cart_num,surplus_num,is_writeoff,cart_info,product_type,is_support_refund,cart_type,write_times,write_surplus_times,sku_unique,is_gift,write_start,write_end,pay_price,debt_amount,repaid_debt_amount');
        /** @var StoreReservationOrderServices $reservationOrderServices */
        $reservationOrderServices = app()->make(StoreReservationOrderServices::class);
        $pay_price = 0;
        $count = count($cartInfo);
        $cha=0;
        $time=time();
        $pendingDebt = $this->resolveOrderPendingDebt($orderInfo);
        $orderInfo['is_full_debt_order'] = $this->isOrderFullyDebtPending($orderInfo, $pendingDebt);
        foreach ($cartInfo as $k => &$item) {
            $_info = is_string($item['cart_info']) ? json_decode($item['cart_info'], true) : $item['cart_info'];
            if (!is_array($_info)) {
                $_info = [];
            }
            $linePayPrice = $this->resolveCartInfoPayPrice($_info, $item);
            if (!isset($_info['pay_price']) || $_info['pay_price'] === '' || $_info['pay_price'] === null) {
                $_info['pay_price'] = $linePayPrice;
            }
            if ($item['write_times'] == 0) {
                $onePrice = 0;
            } else {
                $onePrice = (float)$linePayPrice / $item['write_times'];
            }
            $cha=bcadd($cha,$onePrice*$item['write_surplus_times'],2);
            $item['cha']=$cha;
            if (!isset($_info['productInfo'])) $_info['productInfo'] = [];
            //缩略图处理
            if (isset($_info['productInfo']['attrInfo'])) {
                $_info['productInfo']['attrInfo'] = get_thumb_water($_info['productInfo']['attrInfo']);
            }
            if ($orderInfo['type'] == 11) {
                if ($k > $count - 2) {
                    $_info['truePrice'] = bcsub((string)$orderInfo['pay_price'], (string)$pay_price, 2);
                } else {
                    $_info['truePrice'] = $cartInfoServices->setActualPaymentPrice($cartInfo, $item['product_id'],$item['sku_unique'], $orderInfo['pay_price']);
                    $pay_price = bcadd((string)$pay_price, (string)$_info['truePrice'], 4);
                }
            }
            $pid=$_info['productInfo']['pid'] ?? 0;
            if(empty($pid)){
                $pid=$_info['productInfo']['id'] ?? 0;
            }
            $_info['yeji']=YejiCommission::where("product_id",$pid)->value("yeji");
            $_info['productInfo'] = get_thumb_water($_info['productInfo']);
            $item['cart_info'] = $_info;
            $writeoffed_num = 0;
            if ($item['write_times'] > $item['write_surplus_times']) {
                $writeoffed_num = bcsub((string)$item['write_times'], (string)$item['write_surplus_times']);
                $writeoff_count = bcadd((string)$writeoff_count, (string)$writeoffed_num);
            }
            $item['surplus_num'] = $item['write_surplus_times'];
            $item['unservice_num'] = 0;
            if ($item['product_type'] == 6) {//项目
                $item['unservice_num'] = $reservationOrderServices->count(['oid' => $orderInfo['id'], 'cart_info_id' => $item['id'], 'status' => [0, 1, 3], 'is_del' => 0, 'is_system_del' => 0]);
            }
            $item['writeoffed_num'] = max((int)bcsub((string)$writeoffed_num, (string)$item['unservice_num']), 0);
            unset($_info);
            if ($item['writeoffed_num'] <= 0 && $item['write_surplus_times'] == 0 && $item['is_writeoff'] == 0) {
                $item['is_writeoff'] = 1;
            }
            if ($item['write_surplus_times'] > 0) {
                $item['is_writeoff'] = 0;
            }
            //判断是否失效（卡项订单以 cart_type=0 主卡有效期为准）
            if ($mainCartValidity) {
                if ($this->isOutOfWriteValidity($time, $mainCartValidity['write_start'], $mainCartValidity['write_end'])) {
                    $item['is_writeoff'] = 1;
                }
            } elseif ($item['write_start'] > $time || ($item['write_end'] < $time && $item['write_end'] > 0)) {
               $item['is_writeoff'] = 1;
           }
           // 旧卡升级：全部标记为不可核销（前端按“失效”样式遮罩）
           if ($orderInfo['is_card_upgrade_old']) {
               $item['is_writeoff'] = 1;
           }
           $this->enrichCartDebtWriteoff($item, $pendingDebt, $orderInfo);
        }
        $orderInfo['pending_debt'] = $pendingDebt;
        $orderInfo['has_pending_debt'] = $pendingDebt > 0;
        if($orderInfo['refund_status'] == 2){
            $orderInfo['cha']=0;
        }else{
            $orderInfo['cha']=$cha;
        }
        $orderInfo['cart_count'] = count($cartInfo);
        $orderInfo['writeoff_count'] = $writeoff_count;
        $orderInfo['cart_info'] = $cartInfo;
        return $orderInfo;
    }

    /**
     * 核销订单
     * @param int $uid
     * @param array $orderInfo
     * @param array $cartIds
     * @param int $auth
     * @return array|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeoffOrder(int $uid, array $orderInfo, array $cartIds = [], string $orderType = 'admin', int $staff_id = 0,array $syncAll=[],$is_budan=0,$budan_time='',$isAuto=0,$reservationOid=0)
    {
        if (!$orderInfo) {
            throw new ValidateException('订单不存在');
        }
        if (!empty($orderInfo['is_debt_repay'])) {
            throw new ValidateException('补交订单不可核销');
        }
        // 旧卡升级：已被用于卡升级的旧卡不可核销（与失效卡同逻辑）
        if ((int)($orderInfo['card_upgrade_use_oid'] ?? 0) > 0) {
            throw new ValidateException('失效卡不可被核销');
        }
        $key = md5('lock_order_writeoff_' . $orderInfo['id']);
        $isAutoWriteoff = (int)$isAuto === 1;
        // 收银自动核销：刚支付成功同事务，无售后/拆单；跳过跨店配置与售后全表扫描以缩短持锁
        $cross_store_verification = $isAutoWriteoff ? 1 : (int)sys_config('cross_store_verification', 1);
        //默认正常订单
        $orderInfo['order_type'] = $orderInfo['order_type'] ?? 'order';
        $time = time();
        //验证核销权限（自动核销：收银员路径 auth=4，避免重复查店员）
        if ($isAutoWriteoff && $orderType === 'cashier' && $staff_id > 0) {
            $auth = 4;
        } else {
            $auth = $this->checkWriteoffAuth($uid ?: $staff_id, (int)$orderInfo['id'], $orderType);
        }

		if (!$isAutoWriteoff && $this->dao->count(['pid' => $orderInfo['id']])) {//订单已拆分
			throw new ValidateException('该订单已拆分，请使用子订单核销');
		}
        if (!$orderInfo['verify_code'] || ($orderInfo['shipping_type'] != 2 && $orderInfo['delivery_type'] != 'send')) {
            throw new ValidateException('此订单不能被核销');
        }
        if (!$isAutoWriteoff) {
            /** @var StoreOrderRefundServices $storeOrderRefundServices */
            $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
            if ($storeOrderRefundServices->count(['store_order_id' => $orderInfo['id'], 'refund_type' => [0, 1, 2, 4, 5, 6], 'is_cancel' => 0, 'is_del' => 0])) {
                throw new ValidateException('订单有售后申请请先处理');
            }
        }
        if (isset($orderInfo['pinkStatus']) && $orderInfo['pinkStatus'] != 2) {
            throw new ValidateException('拼团未完成暂不能核销!');
        }
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        if ($orderInfo['status'] >= 2 && !$this->hasPendingWriteoffItems((int)$orderInfo['id'], (int)$orderInfo['type'])) {
            throw new ValidateException('订单已核销');
        }
        $store_id = $orderInfo['store_id'];
        if ($orderInfo['type'] == 3 && $orderInfo['activity_id'] && $orderInfo['pink_id']) {
            /** @var StorePinkServices $services */
            $services = app()->make(StorePinkServices::class);
            $res = $services->getCount([['id', '=', $orderInfo['pink_id']], ['status', '<>', 2]]);
            if ($res) throw new ValidateException('Failed to write off the group order');
        }

        $cartInfo = [];
        $where = [];
        if ($orderInfo['type'] == 11) {
            $where['cart_type'] = 2;
        }
        if ($cartIds) {//商城存在部分核销
            $ids = array_unique(array_column($cartIds, 'cart_id'));
            //订单下原商品信息
            $cartInfo = $cartInfoServices->getCartColunm(['oid' => $orderInfo['id'], 'cart_id' => $ids, 'is_writeoff' => 0] + $where, 'id,cart_id,cart_num,surplus_num,product_id,write_times,write_surplus_times,write_start,write_end,pay_price,debt_amount,repaid_debt_amount,cart_info', 'cart_id');
            if (count($ids) != count($cartInfo)) {
                throw new ValidateException('订单中有商品已核销');
            }
            $price = 0;
            foreach ($cartIds as $cart) {
                $info = $cartInfo[$cart['cart_id']] ?? [];
                if (!$info) {
                    throw new ValidateException('核销商品不存在');
                }
                // 收银台项目购买支付后自动核销：当场消耗服务，不受欠款次数限制
                if (!$isAuto) {
                    $this->assertWriteoffWithinDebtLimit($info, (int)$cart['cart_num'], $orderInfo);
                }
                $decodedInfo = is_string($info['cart_info'] ?? null) ? json_decode($info['cart_info'], true) : ($info['cart_info'] ?? []);
                if (!is_array($decodedInfo)) {
                    $decodedInfo = [];
                }
                $linePay = $this->resolveCartInfoPayPrice($decodedInfo, $info);
                $price = bcadd((string)$price, bcmul(bcdiv($linePay, (string)$info['write_times'], 4), (string)$cart['cart_num'], 2), 2);
            }
        } else {//整单核销
            $price = $orderInfo['pay_price'];
            $cartInfo = $cartInfoServices->getCartColunm(['oid' => $orderInfo['id'], 'is_writeoff' => 0] + $where, 'id,cart_id,cart_num,surplus_num,product_id,write_times,write_surplus_times,write_start,write_end,pay_price,debt_amount,repaid_debt_amount', 'cart_id');
            foreach ($cartIds ?: array_map(function ($row) {
                return ['cart_id' => $row['cart_id'], 'cart_num' => $row['write_surplus_times'] ?? 0];
            }, $cartInfo) as $cart) {
                if (empty($cart['cart_id'])) {
                    continue;
                }
                $info = $cartInfo[$cart['cart_id']] ?? [];
                if ($info && !$isAuto) {
                    $this->assertWriteoffWithinDebtLimit($info, (int)($cart['cart_num'] ?? 0), $orderInfo);
                }
            }
        }
        if ($this->isCardPackageOrder($orderInfo)) {
            $mainValidity = $this->getMainCartValidity((int)$orderInfo['id']);
            if ($mainValidity['write_start'] && $time < $mainValidity['write_start']) {
                throw new ValidateException('还未到指定核销的开始时间，无法核销');
            }
            if ($mainValidity['write_end'] && $time > $mainValidity['write_end']) {
                throw new ValidateException('已经超过指定核销的结束时间，无法核销');
            }
        } else {
            foreach ($cartInfo as $info) {
                if ($info['write_start'] && $time < $info['write_start']) {
                    throw new ValidateException('还未到指定核销的开始时间，无法核销');
                }
                if ($info['write_end'] && $time > $info['write_end']) {
                    throw new ValidateException('已经超过指定核销的结束时间，无法核销');
                }
            }
        }

        $data = ['clerk_id' => $uid];
        $data['staff_id'] = $staff_id;
        $cartData = ['writeoff_time' => $time];
        if ($auth == 1) {//前端客服  下面数据暂时记录前端客服uid
            $data['staff_id'] = $uid ?? 0;
            $data['service_type'] = 1;
            $cartData['staff_id'] = $uid ?? 0;
        } else if ($auth == 2) {//配送员
            /** @var DeliveryServiceServices $deliverServiceServices */
            $deliverServiceServices = app()->make(DeliveryServiceServices::class);
            try {
                $deliveryInfo = $deliverServiceServices->getDeliveryInfoByUid($uid, $store_id ? 1 : 0, $store_id);
            } catch (\Throwable $e) {
                $deliveryInfo = $deliverServiceServices->getDeliveryInfoByUid($uid, 0);
            }
            if (!$deliveryInfo) throw new ValidateException('配送员不存在');
            $cartData['delivery_id'] = $deliveryInfo['id'] ?? 0;
            $data['staff_id'] = $deliveryInfo['id'] ?? 0;
            $data['delivery_time'] = time();
            $data['service_type'] = 3;
        } else if ($auth == 4) {//店员
            $data['service_type'] = 0;
            if ($isAutoWriteoff && !$uid && $staff_id > 0) {
                // 收银支付后自动核销：结账员已在收银鉴权，直接落 staff_id
                $data['staff_id'] = $staff_id;
                $cartData['staff_id'] = $staff_id;
                $data['clerk_id'] = 0;
            } else {
                /** @var SystemStoreStaffServices $storeStaffServices */
                $storeStaffServices = app()->make(SystemStoreStaffServices::class);
                if ($uid) {//商城前端
                    try {
                        $staffInfo = $storeStaffServices->getStaffInfoByUid($uid, $store_id);
                    } catch (\Throwable $e) {
                        $staffInfo = $storeStaffServices->getStaffInfoByUid($uid);
                    }
                } else {//门店后台
                    $staffInfo = $storeStaffServices->getStaffInfo($staff_id);
                    if ($store_id != $staffInfo['store_id'] && !$cross_store_verification) {
                        throw new ValidateException('订单不存在');
                    }
                    if ($staffInfo['verify_status'] != 1) {
                        throw new ValidateException('您暂无核销权限');
                    }
                    $data['clerk_id'] = $staffInfo['uid'];
                }
                if ($store_id != $staffInfo['store_id'] && $cross_store_verification) {
                    $store_id = $staffInfo['store_id'];
                }
                $data['staff_id'] = $staffInfo['id'] ?? 0;
                $cartData['staff_id'] = $staffInfo['id'] ?? 0;
            }
        } else {
            $data['service_type'] = 2;
        }
        $staff_id = $data['staff_id'];
        unset($data['staff_id']);
        //判断商品类型（自动核销项目单不走几选几卡次累计）
        $isEnd=false;
        if (!$isAutoWriteoff) {
            $productId=StoreOrderCartInfo::where("oid",$orderInfo['id'])->where("cart_type",0)->value("product_id");
            if(!empty($productId)){
                $productInfo=StoreProduct::where("id",$productId)->find();
                if($productInfo['card_num'] > 0 && $productInfo['card_num_type'] == 1){
                      //几选几套餐按次数  判断核销次数是否大于
                     $need=StoreOrderWriteoff::where("oid",$orderInfo['id'])->where("status",0)->sum("writeoff_num");
                     foreach ($cartIds as $cartOne) {
                         $need=$need+$cartOne['cart_num'];
                     }
                     if($need > $productInfo['card_num']){
                         throw new ValidateException('该项目累计核销次数不能超过：'.$productInfo['card_num']."次");
                     }
                     if($need == $productInfo['card_num']){
                         $isEnd=true;
                     }
                }
            }
        }
        if (!CacheService::lock($key)) {
            throw new ValidateException('核销操作太过频繁，请稍后再试');
        }
        /** @var StoreOrderWriteOffServices $writeOffRecordServices */
        $writeOffRecordServices = app()->make(StoreOrderWriteOffServices::class);
        // 在改变权益状态前，按核心核销条件取「本次实际核销商品行快照」（is_writeoff=0；卡项 cart_type=2；部分核销限 cart_id）。
        // 同一份快照既用于「是否含项目」判断，也直接传入 saveWriteOff 写核销记录+院装扣料，禁止后置重查，
        // 从而杜绝整卡核销落入默认 cart_type 导致不扣院装耗材、或后置重查选行漂移造成重复扣料。
        $writeoffCartSnapshot = $writeOffRecordServices->getWriteoffCartRows($orderInfo, $cartIds, '*', 'cart_id');
        // 按「本次实际核销行是否含项目(product_type=6)」判断，覆盖卡项(头5/行6)、几选几、赠送项目、补单
        $salonHasProject = $writeOffRecordServices->snapshotContainsProject($writeoffCartSnapshot);
        // 含项目行时：核销记录 + 院装扣料在核销主事务内同步完成的入参
        $writeoffPayload = [
            'staff_id' => $staff_id,
            'store_id' => $store_id,
            'price' => $price,
            'service_type' => $data['service_type'] ?? 0,
            'sync_all' => $syncAll,
            'is_budan' => $is_budan,
            'budan_time' => $budan_time,
            'is_auto' => $isAuto,
            'reservation_oid' => (int)$reservationOid,
        ];
        $data = $this->transaction(function () use ($isEnd,$orderInfo, $staff_id, $data, $cartIds, $cartInfoServices, $cartData, $auth, $cartInfo, $price, $writeoffPayload, $reservationOid, $salonHasProject, $writeOffRecordServices, $writeoffCartSnapshot, $isAuto) {
            if ($cartIds) {//选择商品、件数核销
                $writeoffSum = 0;
                foreach ($cartIds as $cart) {
                    $write_surplus_num = $cartInfo[$cart['cart_id']]['write_surplus_times'] ?? 0;
                    if (!isset($cartInfo[$cart['cart_id']]) || !$write_surplus_num) continue;
                    if ($cart['cart_num'] >= $write_surplus_num) {//拆分完成
                        $cartData['write_surplus_times'] = 0;
                        $cartData['is_writeoff'] = 1;
                    } else {//拆分部分数量
                        $cartData['write_surplus_times'] = bcsub((string)$write_surplus_num, $cart['cart_num'], 0);
                        $cartData['is_writeoff'] = 0;
                    }
                    $writeoffSum += $cartData['write_surplus_times'];
                    //修改原来订单商品信息
                    $cartData['is_writeoff'] = $cartData['write_surplus_times'] > 0 ? 0 : 1;
                    $cartInfoServices->update(['oid' => $orderInfo['id'], 'cart_id' => $cart['cart_id']], $cartData);
                }
            } else {//整单核销
                //修改原来订单商品信息
                $cartData['is_writeoff'] = 1;
                $cartData['write_surplus_times'] = 0;
                $writeoffSum = 0;
                $cartInfoServices->update(['oid' => $orderInfo['id']], $cartData);

                if ($orderInfo['type'] == 12) {//预约单
                    /** @var StoreReservationOrderServices $reservationOrderService */
                    $reservationOrderService = app()->make(StoreReservationOrderServices::class);
                    //预约单中
                    $reservationOrderService->update(['oid' => $orderInfo['id'], 'status' => [0, 1]], ['status' => 2, 'service_end_time' => time()]);
                }
            }
            /** @var StoreOrderCreateServices $storeOrderCreateServices */
            $storeOrderCreateServices = app()->make(StoreOrderCreateServices::class);
            /** @var UserCardHolderServices $holderServices */
            $holderServices = app()->make(UserCardHolderServices::class);
            $verify_code = $storeOrderCreateServices->getStoreCode();
            if ($orderInfo['type'] == 11) {
                $writeoffSum = $cartInfoServices->sum(['oid' => $orderInfo['id'], 'cart_type' => 2], 'write_surplus_times');
                if ($writeoffSum <= 0) {
                    $cartData['is_writeoff'] = 1;
                    $cartData['write_surplus_times'] = 0;
                    $cartInfoServices->update(['oid' => $orderInfo['id'], 'cart_type' => 0], $cartData);
                }
            }
            $hasPending = $this->hasPendingWriteoffItems((int)$orderInfo['id'], (int)$orderInfo['type']);
            if (!$hasPending) {//全部核销
                if ($orderInfo['type'] == 8) {
                    $data['status'] = 3;
                } else {
                    $data['status'] = 2;
					$data['delivery_time'] = time();
                }
                // 收银自动核销：收货/返佣/通知延后到支付事务提交后，避免拉长 store_order 持锁
                // 订单 status/核销记录/院装扣料仍在本事务内完成，失败仍整单回滚
                if ((int)$isAuto === 1) {
                    \app\services\order\cashier\DeferredCashierPostWriteoff::pushTake($orderInfo);
                } else {
                    /** @var StoreOrderTakeServices $storeOrdeTask */
                    $storeOrdeTask = app()->make(StoreOrderTakeServices::class);
                    $re = $storeOrdeTask->storeProductOrderUserTakeDelivery($orderInfo, false);
                    if (!$re) {
                        throw new ValidateException('Write off failure');
                    }
                }
                if ($orderInfo['type'] == 12) {//预约单
                    $data['reservation_status'] = 2;
                }
                $code = [];
            } else {//部分核销
                $data['verify_code'] = $verify_code;
                $data['status'] = 5;
                $code['verify_code'] = $verify_code;
                if ($orderInfo['type'] == 12) {//预约单
                    $data['reservation_status'] = 1;
                }
            }
            if (!$this->dao->update($orderInfo['id'], $data)) {
                throw new ValidateException('Write off failure');
            }
            if ($orderInfo['type'] == 11 || $orderInfo['product_type'] == 4) {
                $holderServices->update(['oid' => $orderInfo['id']], ['write_surplus_times' => $writeoffSum] + $code);
            }
            if($isEnd){
                //几选几已结束---修改剩余项目数为0
                StoreOrderCartInfo::where("oid",$orderInfo['id'])->update(['write_surplus_times'=>0,'is_writeoff'=>1]);
                UserCardHolder::where('oid',$orderInfo['id'])->update(['write_surplus_times'=>0]);
            }
            if ($salonHasProject) {
                // 含项目行：核销记录 + 院装耗材扣料同事务同步完成；传入改权益前取到的「本次实际核销行快照」，
                // saveWriteOff 直接使用该快照、不再回表；院装不足/缺副本会抛异常，整单回滚
                $writeOffRecordServices->saveWriteOff((int)$orderInfo['id'], (int)$writeoffPayload['reservation_oid'], $cartIds, $writeoffPayload, $orderInfo, $writeoffCartSnapshot);
            }
            return $data;
        });
        unset($data['delivery_time']);
        $data['staff_id'] = $staff_id;
        $data['store_id'] = $store_id;
        $data['price'] = $price;
        $data['sync_all']=$syncAll;
        $data['is_budan']=$is_budan;
        $data['budan_time']=$budan_time;
        $data['is_auto']=$isAuto;
        $data['reservation_oid'] = (int)$reservationOid;
        // 含项目行已在主事务内同步写核销记录+扣料，通知监听器跳过异步 OrderWriteoffJob，避免重复写核销记录
        $data['salon_sync'] = $salonHasProject ? 1 : 0;
        event('order.writeoff', [$orderInfo, $auth, $data, $cartIds, $cartInfo]);
        return $orderInfo;
    }

    /**
     * 次卡商品核销表单
     * @param int $id
     * @param int $staffId
     * @param int $cart_num
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeOrderFrom(int $id, int $staffId, int $cart_num = 1)
    {
        $orderInfo = $this->getOrderCartInfo(0, (int)$id);
        $cartInfo = $orderInfo['cart_info'] ?? [];
        if (!$cartInfo) {
            throw new ValidateException('核销订单商品信息不存在');
        }
        if ($orderInfo['product_type'] != 4) {
            throw new ValidateException('订单商品不支持此类型核销');
        }
        $name = ($cartInfo[0]['write_surplus_times'] ?? 0) . '/' . ($cartInfo[0]['write_times'] ?? 0);
        $f[] = Form::hidden('cart_id', $cartInfo[0]['cart_id'] ?? 0);
        $f[] = Form::input('name', '核销数：', $name)->disabled(true);
        $f[] = Form::number('cart_num', '本次核销数量：', min(max($cart_num, 1), $cartInfo[0]['write_surplus_times'] ?? 0))->min(1)->max($cartInfo[0]['write_surplus_times'] ?? 1);
        return create_form('次卡核销', $f, $this->url('/order/write/form/' . $id), 'POST');
    }

    /**
     * 卡项商品订单（product_type=5）
     * @param array $orderInfo
     * @return bool
     */
    protected function isCardPackageOrder(array $orderInfo): bool
    {
        return (int)($orderInfo['product_type'] ?? 0) === 5;
    }

    /**
     * 卡项主卡（cart_type=0）核销有效期
     * @param int $oid
     * @return array{write_start:int,write_end:int}
     */
    protected function getMainCartValidity(int $oid): array
    {
        $main = StoreOrderCartInfo::where('oid', $oid)
            ->where('cart_type', 0)
            ->field('write_start,write_end')
            ->find();
        return [
            'write_start' => (int)($main['write_start'] ?? 0),
            'write_end' => (int)($main['write_end'] ?? 0),
        ];
    }

    /**
     * 是否超出核销有效期
     * @param int $time
     * @param int $writeStart
     * @param int $writeEnd
     * @return bool
     */
    protected function isOutOfWriteValidity(int $time, int $writeStart, int $writeEnd): bool
    {
        if ($writeStart > 0 && $time < $writeStart) {
            return true;
        }
        if ($writeEnd > 0 && $time > $writeEnd) {
            return true;
        }
        return false;
    }

    /**
     * 卡项/组合订单是否仍有可消耗次数（cart_type=2 明细）
     * @param int $oid
     * @param int $orderType
     * @return int
     */
    protected function countRemainingWriteoffTimes(int $oid, int $orderType): int
    {
        if ($orderType == 11) {
            return (int)StoreOrderCartInfo::where('oid', $oid)
                ->where('cart_type', 2)
                ->sum('write_surplus_times');
        }
        return (int)StoreOrderCartInfo::where('oid', $oid)->sum('write_surplus_times');
    }

    /**
     * 修正 is_writeoff 与剩余次数、订单状态不一致（历史核销遗留）
     * @param array $orderInfo
     */
    public function syncOrderWriteoffConsistency(array $orderInfo): void
    {
        $oid = (int)($orderInfo['id'] ?? 0);
        if ($oid <= 0) {
            return;
        }
        $orderType = (int)($orderInfo['type'] ?? 0);
        $productType = (int)($orderInfo['product_type'] ?? 0);
        $carts = StoreOrderCartInfo::where('oid', $oid)->select();
        foreach ($carts as $cart) {
            $cart = is_array($cart) ? $cart : (method_exists($cart, 'toArray') ? $cart->toArray() : []);
            $surplus = (int)($cart['write_surplus_times'] ?? 0);
            $shouldWriteoff = $surplus > 0 ? 0 : 1;
            if ((int)($cart['is_writeoff'] ?? 0) !== $shouldWriteoff) {
                StoreOrderCartInfo::where('id', (int)$cart['id'])->update(['is_writeoff' => $shouldWriteoff]);
            }
        }
        if ($orderType != 11 && $productType != 5 && $productType != 4) {
            return;
        }
        $remaining = $this->countRemainingWriteoffTimes($oid, $orderType);
        /** @var UserCardHolderServices $holderServices */
        $holderServices = app()->make(UserCardHolderServices::class);
        $holderServices->update(['oid' => $oid], ['write_surplus_times' => $remaining]);
        $status = (int)($orderInfo['status'] ?? 0);
        if ($remaining > 0 && in_array($status, [2, 3], true)) {
            $this->dao->update($oid, ['status' => 5]);
        } elseif ($remaining <= 0 && $status === 5) {
            $this->dao->update($oid, ['status' => 2, 'delivery_time' => time()]);
        }
    }

    /**
     * 订单是否仍有可核销商品（以剩余次数为准，不仅看 is_writeoff）
     * @param int $oid
     * @param int $orderType
     * @return bool
     */
    protected function hasPendingWriteoffItems(int $oid, int $orderType): bool
    {
        $query = StoreOrderCartInfo::where('oid', $oid)->where('write_surplus_times', '>', 0);
        if ($orderType == 11) {
            $query->where('cart_type', 2);
        }
        return $query->count() > 0;
    }

    /**
     * 欠款核销限制提示文案
     */
    public function getDebtWriteoffLimitMessage(): string
    {
        return '你当前订单还有欠款，可用次数已用完，是否去还款？';
    }

    /**
     * 整单欠款未还时的核销提示
     */
    public function getFullDebtWriteoffMessage(): string
    {
        return '你当前订单还有欠款，可用次数已用完，是否去还款？';
    }

    /**
     * 订单待还欠款
     */
    protected function resolveOrderPendingDebt(array $orderInfo): float
    {
        /** @var \app\services\order\StoreDebtServices $debtServices */
        $debtServices = app()->make(\app\services\order\StoreDebtServices::class);
        return $debtServices->resolveOrderActivePendingAmount((int)($orderInfo['id'] ?? 0), $orderInfo);
    }

    /**
     * 是否整单以欠款支付且仍有待还
     */
    protected function isOrderFullyDebtPending(array $orderInfo, float $pendingDebt): bool
    {
        if ($pendingDebt <= 0) {
            return false;
        }
        $nonDebtPaid = bcadd((string)($orderInfo['cash_pay_price'] ?? 0), (string)($orderInfo['yue_pay_price'] ?? 0), 2);
        if (bccomp($nonDebtPaid, '0', 2) > 0) {
            return false;
        }
        $orderDebt = (string)($orderInfo['debt_amount'] ?? 0);
        if (bccomp($orderDebt, '0', 2) > 0) {
            return bccomp((string)$pendingDebt, $orderDebt, 2) >= 0
                || bccomp($orderDebt, (string)($orderInfo['pay_price'] ?? 0), 2) >= 0;
        }
        return bccomp((string)$pendingDebt, (string)($orderInfo['pay_price'] ?? 0), 2) >= 0;
    }

    /**
     * 购物车行待还欠款
     */
    public function calcCartPendingDebt(array $cartRow): string
    {
        $debt = bcsub((string)($cartRow['debt_amount'] ?? 0), (string)($cartRow['repaid_debt_amount'] ?? 0), 2);
        return bccomp($debt, '0', 2) > 0 ? $debt : '0.00';
    }

    /**
     * 核销时解析购物车行待还欠款（含订单级欠款分摊）
     */
    protected function resolveCartPendingDebtForWriteoff(array $cartRow, float $orderPendingDebt = 0, array $orderInfo = []): float
    {
        $linePending = (float)$this->calcCartPendingDebt($cartRow);
        if ($linePending > 0 || $orderPendingDebt <= 0 || (int)($cartRow['is_gift'] ?? 0) === 1) {
            return $linePending;
        }
        if ($this->isOrderFullyDebtPending($orderInfo, $orderPendingDebt)) {
            return (float)($cartRow['pay_price'] ?? 0);
        }
        return $this->calcAllocatedCartPendingDebt($cartRow, $orderInfo, $orderPendingDebt);
    }

    /**
     * 按行实付占比分摊订单待还欠款
     */
    protected function calcAllocatedCartPendingDebt(array $cartRow, array $orderInfo, float $orderPendingDebt): float
    {
        $orderPay = (string)($orderInfo['pay_price'] ?? 0);
        $linePay = (string)($cartRow['pay_price'] ?? 0);
        if (bccomp($orderPay, '0', 2) <= 0 || bccomp($linePay, '0', 2) <= 0) {
            return 0.0;
        }
        return (float)bcmul((string)$orderPendingDebt, bcdiv($linePay, $orderPay, 4), 2);
    }

    /**
     * 欠款折算占用核销次数（向上取整）
     */
    public function calcDebtBlockedWriteTimes(array $cartRow, ?float $pendingOverride = null): int
    {
        $pending = $pendingOverride !== null
            ? bcadd((string)$pendingOverride, '0', 2)
            : $this->calcCartPendingDebt($cartRow);
        if (bccomp($pending, '0', 2) <= 0) {
            return 0;
        }
        $writeTimes = (int)($cartRow['write_times'] ?? 0);
        if ($writeTimes <= 0) {
            return 0;
        }
        $payPrice = (string)($cartRow['pay_price'] ?? 0);
        if (bccomp($payPrice, '0', 2) <= 0) {
            return $writeTimes;
        }
        if (bccomp($pending, $payPrice, 2) >= 0) {
            return $writeTimes;
        }
        $unitPrice = bcdiv($payPrice, (string)$writeTimes, 4);
        if (bccomp($unitPrice, '0', 4) <= 0) {
            return $writeTimes;
        }
        return (int)ceil((float)bcdiv($pending, $unitPrice, 4));
    }

    /**
     * 扣除欠款占用后的可核销次数
     */
    public function calcEffectiveWriteSurplusTimes(array $cartRow, float $orderPendingDebt = 0, array $orderInfo = []): int
    {
        $surplus = (int)($cartRow['write_surplus_times'] ?? 0);
        $pending = $this->resolveCartPendingDebtForWriteoff($cartRow, $orderPendingDebt, $orderInfo);
        $blocked = $this->calcDebtBlockedWriteTimes($cartRow, $pending);
        return max(0, $surplus - $blocked);
    }

    /**
     * 补充欠款核销相关字段
     */
    public function enrichCartDebtWriteoff(array &$item, float $orderPendingDebt = 0.0, array $orderInfo = []): void
    {
        $pending = $this->resolveCartPendingDebtForWriteoff($item, $orderPendingDebt, $orderInfo);
        $blocked = $this->calcDebtBlockedWriteTimes($item, $pending);
        $surplus = (int)($item['write_surplus_times'] ?? 0);
        $effective = max(0, $surplus - $blocked);
        $item['pending_debt'] = $pending;
        $item['debt_blocked_times'] = $blocked;
        $item['effective_write_surplus_times'] = $effective;
        $item['debt_limited'] = ($blocked > 0 && $effective < $surplus) ? 1 : 0;
    }

    /**
     * 校验核销次数（含欠款限制）
     */
    public function assertWriteoffWithinDebtLimit(array $cartRow, int $cartNum, array $orderInfo = []): void
    {
        if ($cartNum <= 0) {
            throw new ValidateException('请重新选择核销商品，或核销件数');
        }
        $surplus = (int)($cartRow['write_surplus_times'] ?? 0);
        if ($cartNum > $surplus) {
            throw new ValidateException('核销数量超出剩余总核销次数');
        }
        $orderPendingDebt = $orderInfo ? $this->resolveOrderPendingDebt($orderInfo) : 0.0;
        $effective = $this->calcEffectiveWriteSurplusTimes($cartRow, $orderPendingDebt, $orderInfo);
        if ($cartNum > $effective) {
            if ($orderInfo && $this->isOrderFullyDebtPending($orderInfo, $orderPendingDebt)) {
                throw new ValidateException($this->getFullDebtWriteoffMessage());
            }
            $pending = $this->resolveCartPendingDebtForWriteoff($cartRow, $orderPendingDebt, $orderInfo);
            $payPrice = (float)($cartRow['pay_price'] ?? 0);
            if ($effective <= 0 && $pending > 0 && ($payPrice <= 0 || $pending >= $payPrice)) {
                throw new ValidateException($this->getFullDebtWriteoffMessage());
            }
            throw new ValidateException($this->getDebtWriteoffLimitMessage());
        }
    }

    /**
     * 旧迁移 cart_info 可能无 pay_price，从行字段或 truePrice 等补全
     */
    protected function resolveCartInfoPayPrice(array $cartInfo, array $cartRow): string
    {
        $payPrice = (string)($cartInfo['pay_price'] ?? '');
        if ($payPrice !== '' && bccomp($payPrice, '0', 2) > 0) {
            return $payPrice;
        }
        return $this->resolveCartRowPayPrice($cartRow, $cartInfo);
    }

    protected function resolveCartRowPayPrice(array $cartRow, array $cartInfo = []): string
    {
        if ($cartInfo) {
            $fromInfo = $this->resolveCartInfoPayPriceOnly($cartInfo, $cartRow);
            if (bccomp($fromInfo, '0', 2) > 0) {
                return $fromInfo;
            }
        }
        $linePay = (string)($cartRow['pay_price'] ?? '');
        if ($linePay !== '' && bccomp($linePay, '0', 2) > 0) {
            return $linePay;
        }
        return '0.00';
    }

    protected function resolveCartInfoPayPriceOnly(array $cartInfo, array $cartRow): string
    {
        $payPrice = (string)($cartInfo['pay_price'] ?? '');
        if ($payPrice !== '' && bccomp($payPrice, '0', 2) > 0) {
            return $payPrice;
        }
        if (isset($cartInfo['sum_true_price']) && $cartInfo['sum_true_price'] !== '') {
            return (string)$cartInfo['sum_true_price'];
        }
        $cartNum = max((int)($cartRow['cart_num'] ?? ($cartInfo['cart_num'] ?? 1)), 1);
        if (isset($cartInfo['truePrice']) && $cartInfo['truePrice'] !== '') {
            return bcmul((string)$cartInfo['truePrice'], (string)$cartNum, 2);
        }
        if (isset($cartInfo['price']) && $cartInfo['price'] !== '') {
            return (string)$cartInfo['price'];
        }
        if (isset($cartInfo['productInfo']['pay_price']) && $cartInfo['productInfo']['pay_price'] !== '') {
            return (string)$cartInfo['productInfo']['pay_price'];
        }
        return '0.00';
    }

}
