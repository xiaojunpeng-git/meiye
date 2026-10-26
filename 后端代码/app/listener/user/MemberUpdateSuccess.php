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

namespace app\listener\user;


use app\services\user\member\MemberRightServices;
use mohe\interfaces\ListenerInterface;

/**
 * Class MemberUpdateSuccess
 * @package app\listener\user
 */
class MemberUpdateSuccess implements ListenerInterface
{

    public function handle($event): void
    {
		/** @var MemberRightServices $memberRightService */
		$memberRightService = app()->make(MemberRightServices::class);
		$memberRightService->cacheTag()->clear();
    }
}
