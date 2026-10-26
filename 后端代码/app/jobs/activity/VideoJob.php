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

namespace app\jobs\activity;


use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\video\VideoServices;use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 营销：短视频
 * Class VideoJob
 * @package app\jobs\activity
 */
class VideoJob extends BaseJobs
{

    use QueueTrait;

    /**
 	* 增加短视频浏览播放量
	* @param array $ids
	* @param int $num
	* @return bool
	*/
    public function setVideoPlayNum(array $ids, int $uid = 0, int $num = 1)
    {
		if (!$ids) {
			return true;
		}
        try {
			/** @var VideoServices $videoServices */
            $videoServices = app()->make(VideoServices::class);
			foreach ($ids as $id) {
				$videoServices->userRelationVideo($uid, (int)$id, 'play', $num);
			}
        } catch (\Throwable $e) {
            Log::error('增加短视频浏览播放量失败,失败原因:' . $e->getMessage());
        }
        return true;
    }
}
