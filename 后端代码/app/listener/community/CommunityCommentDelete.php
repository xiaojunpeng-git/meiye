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
 * 帖子评论取消删除事件
 * Class Cancel
 * @package app\listener\order
 */
class CommunityCommentDelete implements ListenerInterface
{
    /**
     * 帖子删除事件
     * @param $event
     */
    public function handle($event): void
    {
        [$id] = $event;
        if ($id) {
            CommunityJob::dispatchDo('CommunityCommentDelete', [$id]);
        }
    }
}
