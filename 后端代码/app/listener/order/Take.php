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
use app\jobs\store\StoreFinanceJob;
use app\jobs\supplier\SupplierFinanceJob;
use app\services\order\StoreOrderServices;
use app\services\user\UserBrokerageServices;
use mohe\interfaces\ListenerInterface;
use mohe\services\CacheService;


/**
 * 订单收货事件
 * Class Take
 * @package app\listener\order
 */
class Take implements ListenerInterface
{
    public function handle($event): void
    {
        [$orderInfo, $storeTitle, $isRecord] = $event;


        try {
            //修改收货状态
            /** @var UserBrokerageServices $userBrokerageServices */
            $userBrokerageServices = app()->make(UserBrokerageServices::class);
            $userBrokerageServices->takeUpdate((int)$orderInfo['uid'], (int)$orderInfo['id']);
            //配送员送货 用户自己点确认收货 写入核销记录
            if (isset($orderInfo['delivery_type']) && $orderInfo['delivery_type'] == 'send' && isset($orderInfo['verify_code']) && $orderInfo['verify_code'] && $isRecord) {
                OrderWriteoffJob::dispatch([(int)$orderInfo['id'], [], [], $orderInfo]);
            }
            //更新完成时间
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $order = $orderServices->get((int)$orderInfo['id']);
            if ($order['store_id']) {
                StoreFinanceJob::dispatchDo('takeDoJob', [$order, time()]);
            } else if ($order['supplier_id']) {
                SupplierFinanceJob::dispatchDo('takeDoJob', [$order, time()]);
            }

            //收货给用户发送消息
            event('notice.notice', [['order' => $orderInfo, 'storeTitle' => $storeTitle], 'order_takever']);
            //收货给客服发送消息
            event('notice.notice', [['order' => $orderInfo, 'storeTitle' => $storeTitle], 'send_admin_confirm_take_over']);

            //检测主订单 是否全部收货
            if ($orderInfo['pid']) {
                $id = (int)$orderInfo['pid'];
                /** @var StoreOrderServices $orderServices */
                $orderServices = app()->make(StoreOrderServices::class);
                //默认部分收货
                $take_data = ['status' => '5'];
                if ($orderServices->count(['pid' => $id]) == $orderServices->count(['pid' => $id, 'status' => 3])) {
                    $take_data = ['status' => 2];
                    $change_type = 'take_split';
                    $change_message = '已拆分收货';
                } else {
                    $change_type = 'take_part_split';
                    $change_message = '已拆分部分收货';
                }
				//
                //改变主订单状态
                $orderServices->update($id, $take_data);
                //记录主订单状态
				OrderStatusJob::dispatch([$id, $change_type, ['change_message' => $change_message]]);
            }
        } catch (\Throwable $e) {
        }
        //销毁锁
        $key = md5('lock_order_take_' . $orderInfo['id']);
        CacheService::unLock($key);
    }


}
