<?php


namespace app\jobs\order;


use app\controller\api\v1\store\Store;
use app\jobs\notice\PrintJob;
use app\jobs\store\StoreFinanceJob;
use app\jobs\store\StoreUserJob;
use app\jobs\supplier\SupplierFinanceJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\product\product\StoreProduct;
use app\model\user\UserCardHolder;
use app\model\yeji\YejiCommission;
use app\services\order\StoreCartServices;
use app\services\order\store\WriteOffOrderServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderGiftServices;
use app\services\order\StoreOrderStatusServices;
use app\services\yeji\SatffYejiServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Db;
use think\facade\Log;

/**
 * 拆分订单后置队列
 * Class SpliteOrderAfterJob
 * @package app\jobs\order
 */
class SpliteOrderAfterJob extends BaseJobs
{
    use QueueTrait;


	/**
	 * 订单分配完成后置方法
	 * @param $orderInfo
	 * @param bool $only_print
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function splitAfter($orderInfo, bool $only_print = false)
	{
        try {
            if (!$orderInfo) {
                return true;
            }
            /** @var StoreCartServices $storeCartServices */
            $storeCartServices = app()->make(StoreCartServices::class);
            if ($storeCartServices->isGiftProjectShellOrder((int)$orderInfo['id'])) {
                $subCount = (int)StoreOrderCartInfo::where('oid', (int)$orderInfo['id'])->where('cart_type', 2)->count();
                if ($subCount <= 0) {
                    /** @var StoreOrderCartInfoServices $giftCartServices */
                    $giftCartServices = app()->make(StoreOrderCartInfoServices::class);
                    $giftCartServices->expandGiftProjectShellOrder(
                        (int)$orderInfo['id'],
                        (int)($orderInfo['uid'] ?? 0)
                    );
                }
            }
            // 拆单后：赠送子订单绑定主购单，便于主单退款时联动撤销
            try {
                app()->make(StoreOrderGiftServices::class)->bindGiftOrderLink((int)$orderInfo['id']);
            } catch (\Throwable $e) {
                // ignore
            }
            //分配好向用户设置标签
            OrderJob::dispatchDo('setUserLabel', [$orderInfo]);
            if ($only_print) {
                PrintJob::dispatch([(int)$orderInfo['id'], 2]);
            } else {
                $orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
                $storeName = $orderInfoServices->getCarIdByProductTitle((int)$orderInfo['id']);
                $orderInfo['storeName'] = substrUTf8($storeName, 20, 'UTF-8', '');
                $orderInfo['send_name'] = $orderInfo['real_name'];
                //小票打印
                if ($orderInfo['type'] != 10) {
                    PrintJob::dispatch([(int)$orderInfo['id'], 2]);
                } else {
                    PrintJob::dispatchDo('tableDoJob', [$orderInfo['activity_id'], $orderInfo['store_id'], 2]);
                }
                //分配完成用户推送消息事件
                event('notice.notice', [$orderInfo, 'order_pay_success']);
            }
            if (isset($orderInfo['store_id']) && $orderInfo['store_id']) {//门店订单
                //分配门店账单流水
                StoreFinanceJob::dispatch([$orderInfo, 1]);
                //记录门店用户
                StoreUserJob::dispatch([(int)$orderInfo['uid'], (int)$orderInfo['store_id']]);
            } elseif (isset($orderInfo['supplier_id']) && $orderInfo['supplier_id']) {//供应商订单
                //供应商账单流水
                SupplierFinanceJob::dispatch([$orderInfo['id'], 1]);
            } else {//平台订单
                //支付成功后向平台客服发送公众号消息
                OrderJob::dispatchDo('sendServicesAndTemplate', [$orderInfo]);
            }
            //如果主订单是赠送 那么子订单也要是赠送
            $oid=StoreOrderCartInfo::where("product_type",5)
                ->where("oid",$orderInfo['id'])
                ->where("cart_type",0)
                ->where("is_gift",1)
                ->value("oid");
            if(!empty($oid)){
                StoreOrderCartInfo::where("product_type",6)
                    ->where("oid",$orderInfo['id'])
                    ->where("cart_type",2)
                    ->update(['is_gift'=>1]);
            }
            //加入购卡业绩
            $theOrder = StoreOrder::where('id', $orderInfo['id'])->find();
            if (!empty($theOrder['pid']) && $theOrder['pid'] > 0) {
                $theOrder = StoreOrder::where('id', $theOrder['pid'])->find();
            }
            $setYejiAll=$serviceYejiAll=[];
            if(!empty($theOrder['yeji'])){
                $setYejiAll = json_decode($theOrder['yeji'], true);
            }
            if(!empty($theOrder['service_yeji'])) {
                $serviceYejiAll = json_decode($theOrder['service_yeji'], true);
            }
            $ids = StoreOrder::where("pid", $theOrder['id'])->column("id");
            if (empty($ids)) {
                $ids = [$theOrder['id']];
            }
            //如果有定制卡 那就放到定制卡里面去
            $cartInfos = StoreOrderCartInfo::whereIn("oid", $ids)->select();
            $mainId = 8154; //定制卡id
            $productIds = StoreProduct::where("pid", $mainId)->column("id");
            $productIds[] = $mainId;
            $dingzhiOrder = StoreOrderCartInfo::whereIn("oid", $ids)->whereIn("product_id", $productIds)->find();
            $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
            if (!empty($dingzhiOrder)) {
                //有定制卡
                $write_times = 0;
                foreach ($cartInfos as $dingzhiK => $dingzhiV) {
                    if ($dingzhiV['product_type'] != 5 && $dingzhiV['id'] != $dingzhiOrder['id']) {
                        //合并到定制项目中
                        //删除订单
                        if ($dingzhiOrder['oid'] != $dingzhiV['oid']) {
//                            Log::error('需要删除'.$dingzhiV['oid']."_".$dingzhiV['product_type']."_".$dingzhiV['cart_type']);
//                            StoreOrder::where("id", $dingzhiV['oid'])->delete();
//                            StoreOrder::where("id", $dingzhiV['oid'])->update(['pid'=>-1]);
                            //删除订单会报错
                        }
                        if ($dingzhiV['product_type'] == 6) {
                            $write_times = bcadd($dingzhiV['write_times'], $write_times);
                        }
                        //修改订单ID
                        $theInfo = $dingzhiV['cart_info'];
                        $theInfo['write_times'] = $dingzhiV['write_times'];
                        $theInfo['card_product_id'] = $dingzhiOrder['product_id'];
                        $theInfo['cart_type'] = 2;
                        $theInfo['is_card'] = 1;
                        $theInfo = json_encode($theInfo);
                        StoreOrderCartInfo::where("id", $dingzhiV['id'])->update(['oid' => $dingzhiOrder['oid'], 'cart_type' => 2, 'cart_info' => $theInfo]);
                    }
                }
                StoreOrder::where("id", $dingzhiOrder['oid'])->update(['type' => 11,'total_num'=>1,'product_type' => 5]);
                StoreOrderCartInfo::where("id", $dingzhiOrder['id'])->update(['product_type' => 5, 'cart_type' => 0, 'write_times' => $write_times, 'write_surplus_times' => $write_times]);
                $storeOrderCartInfoServices->syncCustomCardOrderPayPrice((int)$dingzhiOrder['oid']);
                $storeOrderCartInfoServices->clearOrderCartInfo($dingzhiOrder['oid']);
                $cartInfos = StoreOrderCartInfo::where("oid", $dingzhiOrder['oid'])->select();
                //创建user_card
                $theUserCard = UserCardHolder::where("uid", $dingzhiOrder['uid'])->where("oid", $dingzhiOrder['oid'])->find();
                if (empty($theUserCard)) {
                    $userCard = [];
                    $userCard['uid'] = $dingzhiOrder['uid'];
                    $userCard['oid'] = $dingzhiOrder['oid'];
                    $userCard['card_name'] = "定制卡";
                    $userCard['store_id'] = StoreOrder::where('id', $dingzhiOrder['oid'])->value('store_id');
                    $userCard['product_id'] = $dingzhiOrder['product_id'];
                    $userCard['product_type'] = 5;
                    $userCard['verify_code'] = $theOrder['verify_code'];
                    $userCard['write_valid'] = 3;
                    $userCard['write_days'] = 0;
                    $userCard['write_start'] = 0;
                    $userCard['write_end'] = 0;
                    $userCard['write_times'] = $write_times;
                    $userCard['write_surplus_times'] = $write_times;
                    $userCard['add_time'] = time();
                    if ($userCard['write_end'] == 0) {
                        $userCard['write_valid'] = 1;
                    }
                    if ($userCard['write_end'] > 0 && $userCard['write_start'] == 0) {
                        $userCard['write_start'] = time();
                    }
                    Db::name("user_card_holder")->insert($userCard);
                }
            }
            $cartNums=[];
            // 销售业绩在子订单后置时写入，避免赠送单重复触发
            if ($setYejiAll && !empty($setYejiAll) && $this->shouldPersistSaleYeji($orderInfo, $cartInfos)) {
                $staffYeji = app()->make(SatffYejiServices::class);
                $parentOrderId = (int)$theOrder['id'];
                foreach ($setYejiAll as $vvTwo) {
                    $cartRow = $this->findCardCartRowForYeji($vvTwo, $cartInfos, $parentOrderId);
                    if (!$cartRow) {
                        Log::warning('拆单后未找到销售业绩关联行 cart_id=' . ($vvTwo['cart_id'] ?? '') . ' goods_id=' . ($vvTwo['goods_id'] ?? ''));
                        continue;
                    }
                    $checkoutCartId = (string)($vvTwo['cart_id'] ?? '');
                    $splitCartId = (string)$cartRow['cart_id'];
                    $goodsId = (int)($vvTwo['goods_id'] ?? 0);
                    $cardOrderId = (int)$cartRow['oid'];
                    $this->cleanupStaleSaleYeji($parentOrderId, $goodsId, $checkoutCartId, $splitCartId, $cardOrderId);
                    $yejiData = $vvTwo;
                    // staff_yeji.cart_id 必须等于拆单后 C 订单 store_order_cart_info.cart_id
                    $yejiData['cart_id'] = $splitCartId;
                    $yejiData['link_id'] = $cartRow['oid'];
                    $yejiData['order_id'] = $cartRow['oid'];
                    $yejiData['checkout_cart_id'] = $checkoutCartId;
                    $yejiData['parent_order_id'] = $parentOrderId;
                    $staffYeji->saveYeji($yejiData);
                }
            }
            if (empty($dingzhiOrder)) {
                //核销订单
                foreach ($cartInfos as $cartkey => $cartOne) {
                    $writeOffOrderServices = app()->make(WriteOffOrderServices::class);
                    $product_id = $cartOne['product_id'];
                    $pid = StoreProduct::where('id', $product_id)->value("pid");
                    if (empty($pid)) {
                        $pid = $product_id;
                    }
                    $productType = StoreProduct::where('id', $pid)->value("product_type");
                    $lookOrder=StoreOrder::where("id", $cartOne['oid'])->find();
                    if (!$this->shouldCashierAutoWriteoffServiceCart($cartOne, (int)$productType, $lookOrder)) {
                        //普通商品和赠品不需要核销 只需要核销预约项目
                        continue;
                    }
                    $cartInfoDecoded = is_string($cartOne['cart_info'] ?? null)
                        ? (json_decode($cartOne['cart_info'], true) ?: [])
                        : (($cartOne['cart_info'] ?? []) ?: []);
                    $svcObj = trim((string)($cartInfoDecoded['service_object'] ?? ''));
                    $svcObj = $svcObj === '朋友' ? '朋友' : '本人';
                    if ($svcObj === '本人') {
                        $orderSo = trim((string)($lookOrder['service_object'] ?? $theOrder['service_object'] ?? ''));
                        if ($orderSo === '朋友') {
                            $svcObj = '朋友';
                        }
                    }
                    $cart_ids = [
                        [
                            'cart_id' => $cartOne['cart_id'],
                            'cart_num' => $cartOne['write_surplus_times'],
                            'service_object' => $svcObj,
                        ],
                    ];
                    $onePrice = YejiCommission::where("product_id", $pid)->value("yeji");
                    $syncAll = [];
                    $cartRowArr = is_array($cartOne) ? $cartOne : $cartOne->toArray();
                    $matchedServiceYeji = $this->findServiceYejiForCart($serviceYejiAll, $cartRowArr, (int)$cartOne['product_id']);
                    if ($matchedServiceYeji) {
                        $syncOne = $matchedServiceYeji;
                        $syncOne['type'] = 3;
                        $syncOne['write_times'] = $cartOne['write_times'];
                        $syncOne['true_price'] = $cartOne['pay_price'];
                        $syncOne['order_id'] = $cartOne['oid'];
                        $syncOne['value'] = $cartOne['write_surplus_times'];
                        $syncOne['link_id'] = 0;
                        $syncOne['once_price'] = $onePrice;
                        $syncOne['price'] = bcmul((string)$onePrice, (string)$cartOne['write_surplus_times'], 2);
                        $syncOne['staffChoose'] = $matchedServiceYeji['staffChoose'] ?? [];
                        $len = count($syncOne['staffChoose']);
                        if ($len > 0) {
                            if (bccomp((string)$syncOne['price'], '0', 2) > 0) {
                                $totalAssigned = '0';
                                foreach ($syncOne['staffChoose'] as $nvChoose) {
                                    $totalAssigned = bcadd($totalAssigned, (string)($nvChoose['yeji'] ?? 0), 2);
                                }
                                if (bccomp($totalAssigned, (string)$syncOne['price'], 2) !== 0) {
                                    $onceYeji = bcdiv((string)$syncOne['price'], (string)$len, 2);
                                    $yu = bcsub((string)$syncOne['price'], bcmul($onceYeji, (string)$len, 2), 2);
                                    foreach ($syncOne['staffChoose'] as $nkChoose => &$nvChoose) {
                                        $nvChoose['yeji'] = $onceYeji;
                                        if ($nkChoose === $len - 1 && bccomp($yu, '0', 2) > 0) {
                                            $nvChoose['yeji'] = bcadd($onceYeji, $yu, 2);
                                        }
                                    }
                                    unset($nvChoose);
                                }
                            } else {
                                foreach ($syncOne['staffChoose'] as &$nvChoose) {
                                    $nvChoose['yeji'] = $nvChoose['yeji'] ?? 0;
                                }
                                unset($nvChoose);
                            }
                            $syncAll[] = $syncOne;
                        }
                    }
                    $writerOrder = $writeOffOrderServices->getOrderCartInfo(0, (int)$cartOne['oid']);
                    $writerOrder['shipping_type'] = 2;
                    $staff_id = $theOrder['staff_id'];
                    $addTime=date("Y-m-d H:i:s",$orderInfo['add_time']);
                    $writeOffOrderServices->writeoffOrder(0, $writerOrder, $cart_ids, 'cashier', (int)$staff_id, $syncAll,$orderInfo['is_budan'],$addTime,1);
                }
            }
            //支付成功socket消息
            OrderJob::dispatchDo('newOrderSocketPush', [$orderInfo]);
            event('notice.notice', [$orderInfo, 'admin_pay_success_code']);
        }catch (\Exception $e){
            Log::error('定制卡错误原因：' . $e->getMessage()."行数:".$e->getLine());
        }
		return true;
	}

	/**
	 * 记录拆分订单状态
	 * @param $oid
	 * @param $orderData
	 * @return bool
	 */
    public function setOrderStatus($oid, $orderData)
    {
        if (!$oid || !$orderData || count($orderData) != 2) {
            return true;
        }
        [$orderInfo, $otherOrder] = $orderData;
        try {
            /** @var StoreOrderStatusServices $statusService */
            $statusService = app()->make(StoreOrderStatusServices::class);
            $statusData = $statusService->getColumn(['oid' => $oid], '*');
            if (!$statusData) {
                return true;
            }
            $ids = [];
            if ($orderInfo['id'] != $oid) {
                $ids[] = $orderInfo['id'];
            }
            if ($otherOrder['id'] != $oid) {
                $ids[] = $otherOrder['id'];
            }
            if ($ids) {
                $allData = [];
                foreach ($ids as $id) {
                    foreach ($statusData as $data) {
                        $data['oid'] = $id;
						unset($data['id']);
                        $allData[] = $data;
                    }
                }
                if ($allData) {
                    $statusService->saveAll($allData);
                }
            }

        } catch (\Throwable $e) {
            Log::error('处理拆分订单记录失败，原因：' . $e->getMessage());
        }
        return true;
    }

    /**
     * 是否为可挂销售业绩的主商品行（排除赠品、卡内子项等非主行）
     */
    protected function isCardSaleYejiCartLine(array $cartOne): bool
    {
        $cartType = (int)($cartOne['cart_type'] ?? 0);
        $isGift = (int)($cartOne['is_gift'] ?? 0);
        return $cartType === 0 && $isGift !== 1;
    }

    /**
     * 收银台支付后自动核销：
     * - 仅独立购买的项目单 product_type=6
     * - 预约单 type=12（项目预约消耗）
     * 卡项 product_type=5 购卡时不自动核销，关联项目须到店手动核销
     */
    protected function shouldCashierAutoWriteoffServiceCart($cartOne, int $productType, $lookOrder): bool
    {
        if ($this->isGiftProjectShellOrder($lookOrder)) {
            return false;
        }
        if ($productType !== 6 || (int)($cartOne['is_gift'] ?? 0) === 1) {
            return false;
        }
        if (!$lookOrder || ($lookOrder['channel_type'] ?? '') !== 'cashier') {
            return false;
        }
        $orderProductType = (int)($lookOrder['product_type'] ?? 0);
        $orderType = (int)($lookOrder['type'] ?? 0);
        if ($orderProductType === 6) {
            return true;
        }
        return $orderType === 12;
    }

    /**
     * 是否为「赠送项目」壳订单（购卡后不自动核销，须到店手动消耗）
     */
    protected function isGiftProjectShellOrder($lookOrder): bool
    {
        if (!$lookOrder) {
            return false;
        }
        $oid = (int)($lookOrder['id'] ?? 0);
        if ($oid <= 0) {
            return false;
        }
        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        return $storeCartServices->isGiftProjectShellOrder($oid);
    }

    /**
     * 匹配手艺人业绩：优先 cart_id / old_cart_id，拆单后兜底 goods_id
     */
    protected function findServiceYejiForCart(array $serviceYejiAll, array $cartRow, int $productId): ?array
    {
        foreach ($serviceYejiAll as $vv) {
            if (!is_array($vv)) {
                continue;
            }
            if ($this->matchYejiCartId($cartRow, (string)($vv['cart_id'] ?? ''))) {
                return $vv;
            }
        }
        if ($productId > 0) {
            foreach ($serviceYejiAll as $vv) {
                if (!is_array($vv)) {
                    continue;
                }
                if ((int)($vv['goods_id'] ?? 0) === $productId) {
                    return $vv;
                }
            }
        }
        return null;
    }

    /**
     * 拆单后 cart_id 会变更，用 cart_id / old_cart_id 匹配收银台业绩 cart_id
     */
    protected function matchYejiCartId(array $cartOne, string $yejiCartId): bool
    {
        if ($yejiCartId === '') {
            return false;
        }
        if ((string)($cartOne['cart_id'] ?? '') === $yejiCartId) {
            return true;
        }
        if ((string)($cartOne['old_cart_id'] ?? '') === $yejiCartId) {
            return true;
        }
        return false;
    }

    /**
     * 子订单后置时写入销售业绩，避免赠送单重复触发
     */
    protected function shouldPersistSaleYeji($orderInfo, $cartInfos): bool
    {
        $orderId = (int)($orderInfo['id'] ?? 0);
        if ($orderId <= 0) {
            return false;
        }
        foreach ($cartInfos as $cartOne) {
            $cartRow = is_array($cartOne) ? $cartOne : $cartOne->toArray();
            if ((int)($cartRow['oid'] ?? 0) === $orderId && $this->isCardSaleYejiCartLine($cartRow)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 匹配拆单后主商品行：优先内存 cartInfos，兜底查库 old_cart_id
     */
    protected function findCardCartRowForYeji(array $yejiItem, $cartInfos, int $parentOrderId): ?array
    {
        $checkoutCartId = (string)($yejiItem['cart_id'] ?? '');
        $goodsId = (int)($yejiItem['goods_id'] ?? 0);
        if ($checkoutCartId === '') {
            return null;
        }
        foreach ($cartInfos as $cartOne) {
            $cartRow = is_array($cartOne) ? $cartOne : $cartOne->toArray();
            if (!$this->isCardSaleYejiCartLine($cartRow)) {
                continue;
            }
            if ($goodsId > 0 && (int)($cartRow['product_id'] ?? 0) !== $goodsId) {
                continue;
            }
            if ($this->matchYejiCartId($cartRow, $checkoutCartId)) {
                return $cartRow;
            }
        }
        $orderIds = StoreOrder::where('pid', $parentOrderId)->column('id');
        if (empty($orderIds)) {
            $orderIds = [$parentOrderId];
        }
        return $this->findSplitCardCartRow($orderIds, $checkoutCartId, $goodsId);
    }

    /**
     * 清理拆单前落在父订单或收银台 cart_id 上的购卡业绩
     */
    protected function cleanupStaleSaleYeji(int $parentOrderId, int $goodsId, string $checkoutCartId, string $splitCartId, int $cardOrderId): void
    {
        if ($goodsId <= 0 || $splitCartId === '') {
            return;
        }
        if ($checkoutCartId !== '' && $checkoutCartId !== $splitCartId) {
            Db::name('staff_yeji')
                ->where('type', 2)
                ->where('goods_id', $goodsId)
                ->where('cart_id', $checkoutCartId)
                ->delete();
        }
        if ($parentOrderId > 0 && $parentOrderId !== $cardOrderId) {
            Db::name('staff_yeji')
                ->where('type', 2)
                ->where('goods_id', $goodsId)
                ->where('link_id', $parentOrderId)
                ->delete();
        }
    }

    /**
     * 按 old_cart_id 从库中兜底查找拆单后的主商品行
     */
    protected function findSplitCardCartRow(array $orderIds, string $checkoutCartId, int $goodsId): ?array
    {
        if ($checkoutCartId === '' || empty($orderIds)) {
            return null;
        }
        $query = StoreOrderCartInfo::whereIn('oid', $orderIds)
            ->where('cart_type', 0)
            ->where('is_gift', '<>', 1)
            ->where(function ($q) use ($checkoutCartId) {
                $q->where('cart_id', $checkoutCartId)->whereOr('old_cart_id', $checkoutCartId);
            });
        if ($goodsId > 0) {
            $query->where('product_id', $goodsId);
        }
        $row = $query->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }


}
