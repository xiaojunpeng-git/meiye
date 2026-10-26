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

use mohe\interfaces\ListenerInterface;

/**
 * 订单改价
 * Class PriceRevision
 * @package app\listener\order
 */
class PriceRevision implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        [$order, $pay_price] = $event;
        //消息推送
        event('notice.notice', [['order' => $order, 'pay_price' => $pay_price], 'price_revision']);


    }
}
