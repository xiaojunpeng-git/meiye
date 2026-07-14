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

namespace app\listener\store;


use mohe\interfaces\ListenerInterface;

/**
 * 加盟店入驻审核事件
 * Class ApplyVerify
 * @package app\listener\supplier
 */
class ApplyVerify implements ListenerInterface
{

    public function handle($event): void
    {
		[$storeInfo, $verifyStatus] = $event;

		if ($verifyStatus == 1) {//通过
			$mark = 'store_verify_success';
		} else {//未通过
			$mark = 'store_verify_fail';
		}
		//发送消息
		event('notice.notice', [$storeInfo, $mark]);
    }
}
