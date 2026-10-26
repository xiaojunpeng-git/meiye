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


use app\services\product\product\StoreProductRelationServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 商品关联关系
 * Class ProductRelationJob
 * @package app\jobs\product
 */
class ProductRelationJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @param int $id
     * @param array $relation_id
     * @param int $type
     * @param int $is_show
     * @return bool
     */
    public function doJob(int $id, array $relation_id, int $type = 1, int $is_show = 1)
    {
        try {
            /** @var StoreProductRelationServices $storeProductRelationServices */
            $storeProductRelationServices = app()->make(StoreProductRelationServices::class);
            //商品关联
            $storeProductRelationServices->saveRelation($id, $relation_id, $type, $is_show);
        } catch (\Throwable $e) {
            response_log_write([
                'message' => '写入商品关联[type：' . $type . ']发生错误,错误原因:' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        return true;
    }

}
