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
namespace app\listener\system\timer;

use app\services\activity\combination\StorePinkServices;
use app\services\activity\live\LiveGoodsServices;
use app\services\activity\live\LiveRoomServices;
use app\services\activity\collage\UserCollageCodeServices;
use app\services\spread\AgentManageServices;
use app\services\order\StoreOrderCommentServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderTakeServices;
use app\services\product\product\StoreProductServices;
use app\services\system\attachment\SystemAttachmentServices;
use app\services\system\timer\SystemTimerServices;
use app\services\user\UserIntegralServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\UserServices;
use app\services\user\UserSignServices;
use app\services\work\WorkChannelCodeServices;
use app\services\work\WorkGroupTemplateServices;
use app\services\work\WorkMomentServices;
use mohe\utils\Cron;
use mohe\interfaces\ListenerInterface;

/**
 * 定时任务
 * Class Create
 * @package app\listener\system\timer
 */
class SystemTimer extends Cron implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        $this->tick(1000, function () {
            $time = time();
            /** @var SystemTimerServices $timerServices */
            $timerServices = app()->make(SystemTimerServices::class);
            $cacheCount = $timerServices->cacheCount();
            if (!$cacheCount) {
                $timerServices->setAllTimerCache();
            }
            $list = $timerServices->cacheList();
            foreach ($list as $item) {
				$mark = (string)($item['mark'] ?? '');
                if (!$mark || !isset($item['is_open'])) {
					\think\facade\Log::error('定时任务数据异常，数据：' . json_encode($item));
					continue;
				}
				if ($item['is_open'] == 1) {
					$data = $timerServices->getTimerCycleTime($item['type'], $item['cycle'], $time, $item['update_execution_time']);
					if ($time == $data['cycle_time']) {
						$this->after(1000, function () use ($timerServices, $mark, $time) {
							$timerServices->cacheTag()->set($mark, $time);//上次执行时间保存
							$this->implement_timer($mark);
						});
					}
				}
            }
        });
    }

	/**
	 * 执行定时任务
	 * @param string $mark
	 * @return bool|void
	 */
    public function implement_timer(string $mark)
    {
		try{
			switch ($mark) {
				case 'auto_cancel': //自动取消订单
					/** @var StoreOrderServices $orderServices */
					$orderServices = app()->make(StoreOrderServices::class);
					return $orderServices->orderUnpaidCancel();
					break;
				case 'auto_take' : //自动确认收货
					/** @var StoreOrderTakeServices $services */
					$services = app()->make(StoreOrderTakeServices::class);
					return $services->autoTakeOrder();
					break;
				case 'auto_comment' : //自动好评
					/** @var StoreOrderCommentServices $services */
					$services = app()->make(StoreOrderCommentServices::class);
					return $services->autoCommentOrder();
					break;
				case 'auto_clear_integral' : // 自动清空用户积分
					/** @var UserIntegralServices $userIntegralServices */
					$userIntegralServices = app()->make(UserIntegralServices::class);
					[$clear_time, $start_time, $end_time] = $userIntegralServices->getTime();
					//到清空积分的最后一天
					if ($clear_time == strtotime(date('Y-m-d', time()))) {
						return $userIntegralServices->clearExpireIntegral();
					}
					return true;
					break;
				case 'auto_off_user_svip' : //自动取消用户到期svip
					/** @var UserServices $userServices */
					$userServices = app()->make(UserServices::class);
					return $userServices->offUserSvip();
					break;
				case 'auto_agent' : // 自动解绑上下级
					/** @var AgentManageServices $agentManage */
					$agentManage = app()->make(AgentManageServices::class);
					return $agentManage->removeSpread();
					break;
				case 'auto_clear_poster' : // 自动清除昨日海报
					/** @var SystemAttachmentServices $attach */
					$attach = app()->make(SystemAttachmentServices::class);
					return $attach->emptyYesterdayAttachment();
					break;
				case 'auto_live' : // 自动更新直播产品状态和直播间状态
					/** @var LiveGoodsServices $liveGoods */
					$liveGoods = app()->make(LiveGoodsServices::class);
					$liveGoods->syncGoodStatus();
					/** @var LiveRoomServices $liveRoom */
					$liveRoom = app()->make(LiveRoomServices::class);
					$liveRoom->syncRoomStatus();
					return true;
				case 'auto_pink' : // 拼团状态自动更新
					/** @var StorePinkServices $storePinkServices */
					$storePinkServices = app()->make(StorePinkServices::class);
					return $storePinkServices->useStatusPink();
					break;
				case 'auto_show' :  // 自动上下架商品
					/** @var StoreProductServices $storeProductServices */
					$storeProductServices = app()->make(StoreProductServices::class);
					$storeProductServices->autoUpperShelves();
					break;
				case 'auto_channel' : // 渠道码定时任务
					/** @var WorkChannelCodeServices $service */
					$service = app()->make(WorkChannelCodeServices::class);
					return $service->cronHandle();
					break;
				case 'auto_moment' : // 定时创建发送朋友圈任务
					/** @var WorkMomentServices $make */
					$make = app()->make(WorkMomentServices::class);
					return $make->cronHandle();
					break;
				case 'auto_group_task' : // 定时发送群发任务
					/** @var WorkGroupTemplateServices $service */
					$service = app()->make(WorkGroupTemplateServices::class);
					return $service->cornHandle();
					break;
				case 'code_not_operating' : // 桌码长期未操作取消桌码记录
					/** @var UserCollageCodeServices $service */
					$service = app()->make(UserCollageCodeServices::class);
					return $service->tableCodeNotOperating(10);
					break;
				case 'collate_not_operating' : // 拼单长期未操作取消拼单记录
					/** @var UserCollageCodeServices $service */
					$service = app()->make(UserCollageCodeServices::class);
					return $service->tableCodeNotOperating(9);
					break;
				case 'reminder_unverified_remind' : // 次卡商品未核销短信提醒
					/** @var StoreOrderCartInfoServices $service */
					$service = app()->make(StoreOrderCartInfoServices::class);
					return $service->reminderUnverifiedRemind();
					break;
                case 'sign_remind_time' : // 用户签到提醒
                    /** @var UserSignServices $service */
                    $service = app()->make(UserSignServices::class);
                    return $service->userSignRemind();
                    break;
				case 'presale_product_status': //预售商品结束商品状态处理
					/** @var StoreProductServices $storeProductServices */
					$storeProductServices = app()->make(StoreProductServices::class);
                    return $storeProductServices->autoSetPresaleProductStatus();
					break;
                case 'shop_assistant_enterprise_we_chat_binding': //店员企业微信绑定处理
					/** @var SystemStoreStaffServices $staffServices */
                    $staffServices = app()->make(SystemStoreStaffServices::class);
                    return $staffServices->systemOperateWeComBelong();
					break;
                case 'untamed_shop_assistant_time': //临期解绑店员处理
					/** @var SystemStoreStaffServices $staffServices */
                    $staffServices = app()->make(SystemStoreStaffServices::class);
                    return $staffServices->systemOperateUnbind();
					break;
			}
		} catch (\Throwable $e) {
			/** @var SystemTimerServices $timerServices */
			$timerServices = app()->make(SystemTimerServices::class);
			$taskName = $timerServices->getTasKName();
			response_log_write([
			'message' => '定时任务：['. $taskName[$mark] ?? '未知' .'],失败原因:[' . class_basename($this) . ']' . $e->getMessage(),
			'file' => $e->getFile(),
			'line' => $e->getLine()
			]);
		}
    }
}
