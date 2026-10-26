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

namespace app\services\pay;


use app\services\BaseServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderSuccessServices;
use think\exception\ValidateException;

/**
 * 线下支付
 * Class OrderOfflineServices
 * @package app\services\pay
 */
class OrderOfflineServices extends BaseServices
{

    /**
     * 线下支付
     * @param int $id
     * @return mixed
     */
    public function orderOffline(int $id)
    {
        /** @var StoreOrderServices $orderSerives */
        $orderSerives = app()->make(StoreOrderServices::class);
        $orderInfo = $orderSerives->get($id);
        if (!$orderInfo) {
            throw new ValidateException('订单不存在');
        }

        if ($orderInfo->paid) {
            throw new ValidateException('订单已支付');
        }
        /** @var \app\services\product\inventory\ProductInventoryChangeServices $inventoryChange */
        $inventoryChange = app()->make(\app\services\product\inventory\ProductInventoryChangeServices::class);
        $inventoryChange->assertOrderCanPay($orderInfo->toArray());
        /** @var StoreOrderSuccessServices $storeOrderSuccessServices */
        $storeOrderSuccessServices = app()->make(StoreOrderSuccessServices::class);
        $storeOrderSuccessServices->paySuccess($orderInfo->toArray(), PayServices::OFFLINE_PAY);
        return true;
    }
}
