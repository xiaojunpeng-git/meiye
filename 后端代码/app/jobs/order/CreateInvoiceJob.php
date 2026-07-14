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

namespace app\jobs\order;


use app\services\order\StoreOrderInvoiceServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 支付成功自动发送卡密
 * Class CreateInvoiceJob
 * @package app\jobs\order
 */
class CreateInvoiceJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @param $orderInfo
     * @return bool
     */
    public function doJob(int $uid, int $order_id, $invoice_id)
    {
        if (!$uid || !$order_id || !$invoice_id) {
            return true;
        }
        try {
            //创建开票数据
            /** @var StoreOrderInvoiceServices $storeOrderInvoiceServices */
            $storeOrderInvoiceServices = app()->make(StoreOrderInvoiceServices::class);
            $storeOrderInvoiceServices->makeUp($uid, $order_id, $invoice_id);

        } catch (\Throwable $e) {
            Log::error('创建订单发票信息失败失败，原因：' . $e->getMessage());
        }
        return true;
    }

}
