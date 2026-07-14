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


use app\services\product\label\StoreProductLabelAuxiliaryServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 商品标签
 * Class ProductLabelJob
 * @package app\jobs\product
 */
class ProductLabelJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @param int $id
     * @param array $storeLabelId
     * @return bool
     */
    public function doJob(int $id, array $storeLabelId)
    {
        if (!$id) {
            return true;
        }
        try {
            /** @var StoreProductLabelAuxiliaryServices $auxiliaryService */
            $auxiliaryService = app()->make(StoreProductLabelAuxiliaryServices::class);
            //标签关联
            $auxiliaryService->saveLabelRelation($id, $storeLabelId);
        } catch (\Throwable $e) {
            Log::error('写入商品标签发生错误,错误原因:' . $e->getMessage());
        }
        return true;
    }

}
