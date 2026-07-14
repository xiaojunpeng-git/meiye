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

namespace app\jobs\product;

use app\services\product\product\StoreProductServices;
use app\services\product\product\StoreCardRelatedServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

class ProductRelatedJob extends BaseJobs
{
    use QueueTrait;

    public function doJob($id, $related)
    {
        try {
            /** @var StoreCardRelatedServices $cardRelatedServices */
            $cardRelatedServices = app()->make(StoreCardRelatedServices::class);
            $cardRelatedServices->handleCardRelated($id, $related);
        } catch (\Throwable $e) {
            Log::error('写入卡项关联商品发生错误,错误原因:' . $e->getMessage());
        }
        return true;
    }
}
