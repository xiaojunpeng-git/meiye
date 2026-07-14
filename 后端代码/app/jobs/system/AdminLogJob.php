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

namespace app\jobs\system;

use app\services\system\log\SystemLogServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 后台日志
 * Class AdminLogJob
 * @package app\jobs\system
 */
class AdminLogJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @return mixed
     */
    public static function queueName()
    {
        return 'MOHE_PRO_LOG';
    }

    /**
     * @param $adminId
     * @param $adminName
     * @param $method
     * @param $rule
     * @param $ip
     * @param $type
     */
    public function doJob($adminId, $adminName, $method, $rule, $ip, $type)
    {
        try {
            /** @var SystemLogServices $services */
            $services = app()->make(SystemLogServices::class);
            $services->recordAdminLog((int)$adminId, $adminName, $method, $rule, $ip, $type);
        } catch (\Exception $e) {

        }
        return true;
    }
}
