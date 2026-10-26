<?php

namespace app\jobs\system;

use app\services\system\form\SystemFormDataServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 系统表单数据收集
 * Class SystemFormDataJob
 * @package app\jobs\system
 */
class SystemFormDataJob extends BaseJobs
{
    use QueueTrait;

	/**
	 * @param $id
	 * @param $type
	 * @return bool
	 */
    public function doJob($id, $type = 1)
    {
		if (!$id || !(int)$type) {
			return true;
		}
		try {
			/** @var SystemFormDataServices $systemFormDataServices */
			$systemFormDataServices = app()->make(SystemFormDataServices::class);
			$systemFormDataServices->setFormData((int)$id, (int)$type);
        } catch (\Throwable $e) {
            Log::error('写入系统表单收集数据失败，错误:' . $e->getMessage());
        }
        return true;

    }
}
