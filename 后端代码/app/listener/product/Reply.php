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

namespace app\listener\product;


use app\jobs\community\CommunityJob;
use app\jobs\product\ProductReplyJob;
use mohe\interfaces\ListenerInterface;

/**
 * 商品评价事件
 * Class Reply
 * @package app\listener\product
 */
class Reply implements ListenerInterface
{

    public function handle($event): void
    {
        $id = $event[0] ?? 0;
        $is_sync = $event[1] ?? '';
        $sync = $event[2] ?? '';
        //评价同步社区
        if ($is_sync && $sync) {
            CommunityJob::dispatchDo('computedSync', [$sync]);
        }

		//计算商品评分
		ProductReplyJob::dispatchDo('computedProductStar', [$id]);
    }
}
