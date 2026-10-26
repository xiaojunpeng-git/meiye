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

namespace app\jobs\user;


use app\services\other\Import\ImportRecordServices;
use app\services\product\product\StoreProductServices;
use app\services\user\UserServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 导入用户数据
 * Class UserImportJob
 * @package app\jobs\user
 */
class UserImportJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 导入用户数据
     * @return bool
     */
    public function doJob(int $id, array $data, bool $end)
    {
        if (!$id || !is_array($data)) {
            return true;
        }
        try {
            app()->make(ImportRecordServices::class)->userSingleImport($data, $id, $end);
        } catch (\Throwable $e) {

            response_log_write([
                'message' => '导入用户数据失败,失败原因:' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        return true;
    }

    /**
     * 商品导入数据
     * @param int $id
     * @param int $type
     * @return bool
     */
    public function productImportSync(int $id, array $data, bool $end,int $type = 0,int $relation_id = 0)
    {
        try {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $productServices->productImport($data, $id, $end, $type, $relation_id);
        } catch (\Throwable $e) {
            response_log_write([
                'message' => '商品导入数据失败,失败原因:' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        return true;
    }

    /**
     * 商品导入数据
     * @param int $id
     * @param int $type
     * @return bool
     */
    public function userCardSync(int $id, array $data, bool $end)
    {
        if (!$id || !is_array($data)) {
            return true;
        }
        try {
            app()->make(ImportRecordServices::class)->userCardSingleImport($data, $id, $end);
        } catch (\Throwable $e) {

            response_log_write([
                'message' => '导入用户卡项数据失败,失败原因:' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        return true;
    }

    /**
     * 充值导入数据
     * @param int $id
     * @param int $type
     * @return bool
     */
    public function userRechargeSync(int $id, array $data, bool $end)
    {
        if (!$id || !is_array($data)) {
            return true;
        }
        try {
            app()->make(ImportRecordServices::class)->userRechargeSingleImport($data, $id, $end);
        } catch (\Throwable $e) {

            response_log_write([
                'message' => '导入用户卡项数据失败,失败原因:' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        return true;
    }
}
