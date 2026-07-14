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


use app\services\product\product\StoreProductCateServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 商品分类
 * Class ProductCateJob
 * @package app\jobs\product
 */
class ProductCateJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @param int $id
     * @param array $cate_id
     * @param int $is_show
     * @return bool
     */
    public function doJob(int $id, array $cate_id, int $is_show = 0)
    {
        try {
            /** @var StoreProductCateServices $storeProductCateServices */
            $storeProductCateServices = app()->make(StoreProductCateServices::class);
            //商品分类关联
            $storeProductCateServices->saveCate($id, $cate_id, $is_show);
        } catch (\Throwable $e) {
            Log::error('写入商品分类关联发生错误,错误原因:' . $e->getMessage());
        }
        return true;
    }

}
