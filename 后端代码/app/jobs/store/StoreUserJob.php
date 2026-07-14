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

namespace app\jobs\store;


use app\services\store\StoreUserServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

class StoreUserJob extends BaseJobs
{

    use QueueTrait;

    public function doJob($uid, $storeId)
    {
        try {
            /** @var StoreUserServices $storeUserServices */
            $storeUserServices = app()->make(StoreUserServices::class);
            $storeUserServices->setStoreUser((int)$uid, (int)$storeId);
        } catch (\Throwable $e) {

        }
        return true;
    }
}
