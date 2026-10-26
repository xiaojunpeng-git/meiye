<?php


namespace app\jobs\order;


use app\services\order\StoreOrderCreateServices;
use app\services\user\UserServices;
use mohe\basic\BaseJobs;
use mohe\services\CacheService;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 订单创建
 * Class OrderCreateAfterJob
 * @package app\jobs
 */
class OrderCreateAfterJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 清理订单确认生成缓存
     * @param int $uid
     * @param string $unique
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function delOrderCache(int $uid, string $unique)
    {
        CacheService::redisHandler()->delete('user_order_' . $uid . $unique);
        return true;
    }

	/**
	 * 设置默认地址、更新核销人信息
	 * @return bool
	 */
	public function updateUser($orderInfo, $group = [])
	{
		$uid = (int)($orderInfo['uid'] ?? 0);
		if (!$uid) return true;
		try {
			/** @var StoreOrderCreateServices $orderCreate */
			$orderCreate = app()->make(StoreOrderCreateServices::class);
			$orderCreate->updateUserAddress($uid, $group);
		} catch (\Throwable $e) {
			response_log_write([
				'message' => '设置用户默认地址信息失败,失败原因:' . $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
		}

		try {
			/** @var UserServices $userService */
			$userService = app()->make(UserServices::class);
			$userInfo = $userService->getUserCacheInfo($uid);
			//记录核销人电话和姓名
			if ($userInfo['real_name'] != $orderInfo['real_name'] || $userInfo['record_phone'] != $orderInfo['user_phone']) {
				$userService->update(['uid' => $uid], ['real_name' => $orderInfo['real_name'], 'record_phone' => $orderInfo['user_phone']]);
				$userService->cacheTag()->clear();
			}
		} catch (\Throwable $e) {
			response_log_write([
				'message' => '更新用户核销人信息失败,失败原因:' . $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
		}
		return true;
	}

	/**
	 * 删除购物车
	 * @return bool
	 */
	public function delCart($group)
	{
		try {
			/** @var StoreOrderCreateServices $orderCreate */
			$orderCreate = app()->make(StoreOrderCreateServices::class);
			$orderCreate->delCart($group);
		} catch (\Throwable $e) {
			response_log_write([
				'message' => '删除购物车失败,失败原因:' . $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
		}
		return true;
	}

    /**
     * 删除购物车和更新用户收货地址
     * @return bool
     */
    public function delCartAndUpdateAddres($orderInfo, $group)
    {
        try {
            /** @var StoreOrderCreateServices $orderCreate */
            $orderCreate = app()->make(StoreOrderCreateServices::class);
            $orderCreate->orderCreateAfter($orderInfo, $group);
        } catch (\Throwable $e) {
            Log::error('更新用户信息和删除购物车失败,失败原因:' . $e->getMessage());
        }
        return true;
    }

}
