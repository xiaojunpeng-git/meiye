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
namespace app\listener\community;

use app\jobs\activity\StorePromotionsJob;
use app\jobs\community\CommunityJob;
use app\services\community\CommunityServices;
use app\services\order\StoreOrderInvoiceServices;
use mohe\interfaces\ListenerInterface;

/**
 * 帖子操作事件
 * Class CommunityOperate
 * @package app\listener\community
 */
class CommunityOperate implements ListenerInterface
{
    /**
     * 帖子事件
     * @param $event  $type 类型：0:平台1:门店2:用户
     */
    public function handle($event): void
    {
        [$id, $is_new, $type] = $event;
        if ($id) {
            //用户发帖数数据矫正
            CommunityJob::dispatchDo('communityUserSync', [$id, $type]);
            //话题帖子数矫正
            CommunityJob::dispatchDo('communityTopicSync', [$id]);
            if ($is_new == 1) {
                //新增帖子增加积分经验
                CommunityJob::dispatchDo('communityIncome', [$id]);
                //修正帖子话题
                CommunityJob::dispatchDo('communityTopicStatus', [$id]);
            }
        }
    }
}
