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
use app\services\order\StoreOrderInvoiceServices;
use mohe\interfaces\ListenerInterface;

/**
 * 帖子取消点赞
 * Class Cancel
 * @package app\listener\order
 */
class CommunityLike implements ListenerInterface
{
    /**
     * 帖子取消点赞事件
     * @param $event
     */
    public function handle($event): void
    {
        //type 1帖子,2评论
        //status 1点赞,2取消的点赞
        [$info, $uid, $type, $status] = $event;
        if ($info) {
            CommunityJob::dispatchDo('communityLike', [$info, $uid, $type, $status]);
        }
    }
}
