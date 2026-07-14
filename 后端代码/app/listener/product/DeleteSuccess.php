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


use app\services\order\StoreCartServices;
use mohe\interfaces\ListenerInterface;

/**
 * 删除商品成功事件
 * Class DeleteSuccess
 * @package app\listener\product
 */
class DeleteSuccess implements ListenerInterface
{

    public function handle($event): void
    {
        [$id] = $event;
		if (!is_array($id)) {
			$id = [$id];
		}
        /** @var StoreCartServices $cartService */
        $cartService = app()->make(StoreCartServices::class);
        $cartService->changeStatus($id, 0);
        event('get.config');
    }
}
