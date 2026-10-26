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

use mohe\basic\BaseJobs;
use app\services\system\attachment\SystemAttachmentServices;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 自动清除海报
 * Class AutoClearPosterJob
 * @package app\jobs\user
 */
class AutoClearPosterJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @return string
     */
    protected static function queueName()
    {
        return 'MOHE_PRO_TASK';
    }

    /**
     * @param $event
     */
    public function doJob()
    {
        //清除昨日海报
        try {
            /** @var SystemAttachmentServices $attach */
            $attach = app()->make(SystemAttachmentServices::class);
            $attach->emptyYesterdayAttachment();
        } catch (\Throwable $e) {
            Log::error('清除昨日海报,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
        }
		return true;
    }
}
