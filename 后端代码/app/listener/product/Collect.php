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


use app\jobs\product\ProductLogJob;
use app\services\user\UserRelationServices;
use mohe\interfaces\ListenerInterface;

/**
 * 商品评价事件
 * Class Reply
 * @package app\listener\product
 */
class Collect implements ListenerInterface
{

    public function handle($event): void
    {
        [$uid, $productIds, $isDec] = $event;

		//收藏记录
		if (!$isDec) {
			ProductLogJob::dispatch(['collect', ['uid' => $uid, 'relation_id' => $productIds, 'product_id' => $productIds]]);
		}
		//计算商品收藏数
		if ($productIds) {
			/** @var UserRelationServices $userRelationServices */
			$userRelationServices = app()->make(UserRelationServices::class);
			foreach ($productIds as $id) {
				$userRelationServices->computedProductCollect((int)$id);
			}
		}
    }
}
