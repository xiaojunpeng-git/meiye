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

namespace app\listener\supplier;


use app\jobs\order\CityDeliveryJob;
use app\jobs\product\ProductSyncStoreJob;
use mohe\interfaces\ListenerInterface;

/**
 * 供应商创建成功事件
 * Class SupplierSuccess
 * @package app\listener\supplier
 */
class SupplierSuccess implements ListenerInterface
{

    public function handle($event): void
    {

    }


}

