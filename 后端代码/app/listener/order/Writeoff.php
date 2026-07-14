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
namespace app\listener\order;

use app\jobs\order\OrderStatusJob;
use app\jobs\order\OrderWriteoffJob;
use app\jobs\product\ProductLogJob;
use app\jobs\store\StoreFinanceJob;
use app\jobs\system\CapitalFlowJob;
use app\services\order\StoreOrderInvoiceServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderStatusServices;
use app\services\product\product\StoreProductServices;
use app\services\store\finance\StoreFinanceFlowServices;
use app\services\user\UserCardHolderServices;
use mohe\interfaces\ListenerInterface;
use mohe\services\CacheService;

/**
 * 订单核销事件
 * Class PriceRevision
 * @package app\listener\order
 */
class Writeoff implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        [$orderInfo, $auth, $data, $cartIds, $cartInfo] = $event;
        $staff_id = $data['staff_id'] ?? 0;
        $oid = (int)$orderInfo['id'];

        //核销记录
        OrderWriteoffJob::dispatch([$oid, $cartIds, $data, $orderInfo]);

        if ($data['status'] == 5) {
            $message = [];
            foreach ($cartInfo as $item) {
                foreach ($cartIds as $value) {
                    if ($value['cart_id'] === $item['cart_id']) {
                        $message[] = '商品id:' . $item['product_id'] . ',核销数量:' . $value['cart_num'];
                    }
                }
            }
            if ($orderInfo['type'] == 11) {
                /** @var UserCardHolderServices $cardServices */
                $cardServices = app()->make(UserCardHolderServices::class);
                $cardServices->update(['oid' => $orderInfo['id']], ['verify_code' => $data['verify_code']]);
            }
            //记录原订单状态
            OrderStatusJob::dispatch([$oid, 'writeoff_part', ['change_message' => '订单部分核销，核销成员:' . $staff_id . ',核销商品:' . implode(' ', $message)]]);
        } elseif ($data['service_type'] == 3) {
            OrderStatusJob::dispatch([$oid, 'writeoff', ['change_message' => '配送员完成核销', 'change_manager_type' => 'delivery', 'change_manager_id' => $data['staff_id']]]);
        } else {
            OrderStatusJob::dispatch([$oid, 'writeoff', ['change_message' => '订单核销已完成']]);
        }
        $message = [];
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        if ($data['status'] == 5) {
            foreach ($cartInfo as $item) {
                foreach ($cartIds as $value) {
                    if ($value['cart_id'] === $item['cart_id']) {
                        $store_name = $productServices->value(['id' => $item['product_id']], 'store_name');
                        $message[] = substrUTf8($store_name, 10, 'UTF-8', '');
                    }
                }
            }
        }else{
            foreach ($cartInfo as $item) {
                $store_name = $productServices->value(['id' => $item['product_id']], 'store_name');
                $message[] = substrUTf8($store_name, 10, 'UTF-8', '');
            }
        }
        $dat['store_name'] = implode(' ', $message);
        $dat['phone'] = $orderInfo['user_phone'];
        $dat['uid'] = $orderInfo['uid'];
        event('notice.notice', [$dat, 'reminder_verification_status']);

        if ($auth == 4 && $data['staff_id']) {
            //流水关联店员
            /** @var StoreFinanceFlowServices $storeFinanceFlow */
            $storeFinanceFlow = app()->make(StoreFinanceFlowServices::class);
            $storeFinanceFlow->setStaff($orderInfo['order_id'], $data['staff_id']);
        }
        //销毁锁
        $key = md5('lock_order_writeoff_' . $orderInfo['id']);
        CacheService::unLock($key);
    }
}
