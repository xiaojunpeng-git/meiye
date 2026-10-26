<?php


namespace app\jobs\order;

use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderSplitServices;
use think\facade\Log;

/**
 *
 * Class SpliteStoreOrderJob
 * @package app\jobs
 */
class SpliteStoreOrderJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 拆分订单（按商品类型）
     * @param $id
     * @return bool
     */
    public function doJob($id)
    {
		$id = (int)$id;
        if (!$id) {
            return true;
        }
        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        $order = $storeOrderServices->get($id);
        if (!$order) {
            return true;
        }
        $orderInfo = $order->toArray();
        try {
            $id = (int)$orderInfo['id'];
            if (!empty($orderInfo['is_debt_repay'])) {
                SpliteOrderAfterJob::dispatchDo('splitAfter', [$orderInfo]);
                return true;
            }
            /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
            $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
            //订单下原商品信息 除开卡项商品（整体）
            $cartInfo = $storeOrderCartInfoServices->getCartColunm(['oid' => $id, 'split_status' => [0, 1], 'cart_type' => 0], 'cart_id,product_id,sku_unique,product_type,cart_num', 'cart_id');
            if (!$cartInfo) {
                return true;
            }
            //收银台+预约+普通+卡项商品可以同时加购结算 拆分
            $product_type = array_column($cartInfo, 'product_type');
            if (in_array(4, $product_type) || in_array(5, $product_type) || in_array(6, $product_type)) {//需要拆分
                $cart_ids = [];
                $other_cart_ids = [];
                $spiltCount = 0;
                $product_type = 0;
                foreach ($cartInfo as $cart_id => $cart) {
                    if (in_array($cart['product_type'], [4, 5, 6])) {//需要拆分出去的商品数量
                        $spiltCount++;
                    }
                    if (in_array($cart['product_type'], [4, 5, 6]) && !$cart_ids) {//拆分 按每一个预约、卡项商品
                        $cart_ids[] = ['cart_id' => $cart_id, 'cart_num' => $cart['cart_num']];
                        $product_type = $cart['product_type'];
                    } else {
                        $other_cart_ids[] = ['cart_id' => $cart_id, 'cart_num' => $cart['cart_num']];
                    }
                }
                //下单商品都是某一个供应商|| 门店商品，不用拆分
                if ($spiltCount) {
                    /** @var StoreOrderCreateServices $storeOrderCreateServices */
                    $storeOrderCreateServices = app()->make(StoreOrderCreateServices::class);

                    //订单类型
                    switch ($product_type) {
                        case 4://次卡
                            $type = 0;
                            $verify_code = $storeOrderCreateServices->getStoreCode();
                            break;
                        case 5://卡项
                            $type = 11;
                            $verify_code = $storeOrderCreateServices->getStoreCode();
                            break;
                        case 6://预约
                            $type = 12;
                            $verify_code = $storeOrderCreateServices->getStoreCode();
                            break;
                        default:
                            $type = 0;
                            $verify_code = '';
                            break;
                    }
                    //写入核销码 改变订单待核销 写入订单商品类型
                    $update = ['product_type' => $product_type, 'type' => $type, 'status' => 0, 'verify_code' => $verify_code, 'shipping_type' => 2];
                    if (!in_array($product_type, [4, 5, 6])) {
                        unset($update['status'], $update['shipping_type']);
                    }
                    $otherOrder = [];
                    if ($other_cart_ids || $spiltCount > 1) {
                        //分配订单
                        /** @var  StoreOrderSplitServices $storeOrderSplitServices */
                        $storeOrderSplitServices = app()->make(StoreOrderSplitServices::class);
                        $splitResult = $storeOrderSplitServices->equalSplit($id, $cart_ids);
                        if ($splitResult) {//拆分
                            [$orderInfo, $otherOrder] = $splitResult;
                        }
                    }
                    $storeOrderServices->update(['id' => $orderInfo['id']], $update);
                    $shellProductId = (int)$storeOrderCartInfoServices->value(['oid' => (int)$orderInfo['id'], 'cart_type' => 0], 'product_id');
                    /** @var StoreCartServices $storeCartServices */
                    $storeCartServices = app()->make(StoreCartServices::class);
                    $isGiftProjectShell = $storeCartServices->isGiftProjectShellByProductId($shellProductId);
                    if ($isGiftProjectShell) {
                        if ((int)($update['type'] ?? 0) !== 11) {
                            $storeOrderServices->update(['id' => $orderInfo['id']], [
                                'type' => 11,
                                'product_type' => 5,
                                'status' => 0,
                                'verify_code' => $verify_code ?: $storeOrderCreateServices->getStoreCode(),
                                'shipping_type' => 2,
                            ]);
                        }
                        $storeOrderCartInfoServices->setCartCartInfo($orderInfo['id'], (int)$orderInfo['uid'], $shellProductId);
                        $storeOrderCartInfoServices->clearOrderCartInfo((int)$orderInfo['id']);
                    } elseif ((int)($update['type'] ?? 0) == 11) {
                        $product_id = $shellProductId ?: (int)$storeOrderCartInfoServices->value(['oid' => $orderInfo['id']], 'product_id');
                        // 定制卡(8154)子项目由 SpliteOrderAfterJob 合并真实购物车行，不写卡项模板子明细
                        if ($product_id !== 8154) {
                            $storeOrderCartInfoServices->setCartCartInfo($orderInfo['id'], (int)$orderInfo['uid'], $product_id);
                            $storeOrderCartInfoServices->clearOrderCartInfo((int)$orderInfo['id']);
                        }
                    }
                    //次卡、卡项商品订单处理
                    if (in_array($product_type, [4, 5]) || $isGiftProjectShell) {
                        $orderInfo = $storeOrderServices->get($orderInfo['id']);
                        OrderPayHandelJob::dispatch([$orderInfo]);
                    }
                    //还有预约、卡项商品
                    if ($spiltCount > 1 && $otherOrder) {
                        SpliteStoreOrderJob::dispatch([$otherOrder['id']]);
                        SpliteOrderAfterJob::dispatchDo('splitAfter', [$orderInfo]);
                    } else if ($spiltCount == 1) {
                        SpliteOrderAfterJob::dispatchDo('splitAfter', [$orderInfo]);
                        if ($otherOrder) {
							//订单自动发货、收货
							OrderTakeJob::dispatchDo('autoDeliveryAndTake', [$otherOrder]);

                            SpliteOrderAfterJob::dispatchDo('splitAfter', [$otherOrder]);
                        }
                    } else {
						//订单自动发货、收货
						OrderTakeJob::dispatchDo('autoDeliveryAndTake', [$otherOrder]);

                        SpliteOrderAfterJob::dispatchDo('splitAfter', [$otherOrder]);
                    }
                }
            } else {
                SpliteOrderAfterJob::dispatchDo('splitAfter', [$orderInfo]);
            }
        } catch (\Throwable $e) {
            Log::error('自动拆分次卡、预约、卡项订单失败，原因：' . $e->getMessage() . $e->getFile() . $e->getLine());
        }
        return true;
    }

}
