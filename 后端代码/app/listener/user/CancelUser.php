<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\listener\user;

use app\services\community\CommunityUserServices;
use app\services\message\service\StoreServiceServices;
use app\services\message\SystemMessageServices;
use app\services\order\StoreCartServices;
use app\services\store\DeliveryServiceServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\CancelUserServices;
use app\services\work\WorkClientServices;
use app\services\work\WorkMemberServices;
use mohe\interfaces\ListenerInterface;
use app\services\activity\collage\UserCollagePartakeServices;
use think\facade\Log;

/**
 * 注销用户事件
 */
class CancelUser implements ListenerInterface
{
    public function handle($event): void
    {
        [$uid] = $event;
        /** @var CancelUserServices $cancelUserServices */
        $cancelUserServices = app()->make(CancelUserServices::class);
        $cancelUserServices->cancelUser((int)$uid);
        /** @var WorkClientServices $service */
        $service = app()->make(WorkClientServices::class);
        $service->unboundUser((int)$uid);
        /** @var WorkMemberServices $memberService */
        $memberService = app()->make(WorkMemberServices::class);
        $memberService->unboundUser((int)$uid);
		/** @var SystemStoreStaffServices $storeStaffServices */
		$storeStaffServices = app()->make(SystemStoreStaffServices::class);
		$storeStaffServices->update(['uid' => $uid], ['uid' => 0]);
		/** @var SystemMessageServices $systemMessageServices */
		$systemMessageServices = app()->make(SystemMessageServices::class);
		$systemMessageServices->update(['uid' => $uid], ['is_del' => 1]);
        /** @var UserCollagePartakeServices $partakeService */
        $partakeService = app()->make(UserCollagePartakeServices::class);
        $partakeService->logOffUserCollagePartake((int)$uid);
		/** @var StoreServiceServices $StoreServiceServices */
		$StoreServiceServices = app()->make(StoreServiceServices::class);
		$StoreServiceServices->update(['uid' => $uid], ['is_del' => 1]);
		/** @var SystemStoreStaffServices $staffServices */
		$staffServices = app()->make(SystemStoreStaffServices::class);
		$staffServices->cancelUserDel((int)$uid);
		/** @var DeliveryServiceServices $deliveryServices */
		$deliveryServices = app()->make(DeliveryServiceServices::class);
		$deliveryServices->update(['uid' => $uid], ['is_del' => 1]);
		/** @var StoreCartServices $storeCartServices */
		$storeCartServices = app()->make(StoreCartServices::class);
		$storeCartServices->delete(['uid' => $uid]);
        try {
            //社区
            app()->make(CommunityUserServices::class)->logoutAfter($uid);
        }catch (\Throwable $e){
            Log::error('注销用户事件失败：' . $e->getMessage());
        }
        event('user.update');
    }
}
