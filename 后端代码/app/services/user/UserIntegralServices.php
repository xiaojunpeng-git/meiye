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

namespace app\services\user;

use app\dao\user\UserBillDao;
use app\jobs\user\UserIntegralJob;
use app\services\BaseServices;
use think\facade\Db;

/**
 * 用户积分
 * Class UserIntegralServices
 * @package app\services\user
 * @mixin UserBillDao
 */
class UserIntegralServices extends BaseServices
{

    /**
     * UserIntegralServices constructor.
     * @param UserBillDao $dao
     */
    public function __construct(UserBillDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 清空到期积分（分批加入队列）
     * @return bool
     */
    public function clearExpireIntegral()
    {
        //是否开启积分有效期
        if (!sys_config('integral_effective_status', 0)) {
            return true;
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $users = $userServices->getColumn([['integral', '>', 0]], 'uid');
        if ($users) {
            // 队列可能跨过清算日才消费，必须随任务固定原清算窗口，不能在消费时重新按当天推算。
            $period = $this->getTime();
            //拆分大数组
            $uidsArr = array_chunk($users, 100);
            foreach ($uidsArr as $uids) {
                //加入同步|更新用户队列
                if (!UserIntegralJob::dispatch([$uids, $period])) {
                    throw new \RuntimeException('积分到期清算任务入队失败');
                }
            }
        }
        return true;
    }

    /**
     * 执行清空到期积分
     * @param array $uids
     * @param array $period 固定的 [清算日零点, 积分取得起点, 积分取得终点]，不得在任务消费时重算
     * @return bool
     */
    public function doClearExpireIntegral(array $uids, array $period)
    {
        if (!$uids) return true;
        //是否开启积分有效期
        if (!sys_config('integral_effective_status', 0)) {
            return true;
        }
        $this->assertExpiryPeriod($period);
        [$clearTime, $startTime, $endTime] = array_map('intval', $period);
        /** @var UserBillServices $userBillServices */
        $userBillServices = app()->make(UserBillServices::class);
        // 稳定排序让批次重试和并发任务取得相同的会员锁顺序。
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static function ($uid) {
            return $uid > 0;
        })));
        sort($uids, SORT_NUMERIC);
        foreach ($uids as $uid) {
            Db::transaction(function () use ($uid, $clearTime, $startTime, $endTime, $userBillServices): void {
                $user = Db::name('user')->where('uid', $uid)->field('uid,integral')->lock(true)->find();
                if (!$user) return;

                // 一名会员在同一积分取得期间只可清算一次；数据库唯一键是重试和多工作进程的最终守卫。
                $guard = Db::name('user_integral_expiry')->where('uid', $uid)
                    ->where('period_start', $startTime)->find();
                if ($guard) return;

                // 升级前由旧处理器已经写出的清零流水继续有效，不能因缺少新守卫再扣一次。
                $legacyBill = Db::name('user_bill')->where('uid', $uid)
                    ->where('category', 'integral')->where('type', 'system_clear')
                    ->where('add_time', '>=', $clearTime)
                    ->where('add_time', '<', $clearTime + 86400)
                    ->order('id', 'desc')->find();
                if ($legacyBill) {
                    $this->saveExpiryGuard($uid, $startTime, $endTime, $clearTime,
                        (int)$legacyBill['number'], (int)$legacyBill['id']);
                    return;
                }

                // 旧账取得时间是到期归属依据；当前余额只作为扣减上限，避免历史账与现值不一致时透支。
                $expired = $this->periodNetIntegralForUser($uid, $startTime, $endTime);
                $number = min($expired, (int)$user['integral']);
                $billId = 0;
                if ($number > 0) {
                    $balance = (int)$user['integral'] - $number;
                    $changed = Db::name('user')->where('uid', $uid)
                        ->where('integral', '>=', $number)
                        ->update(['integral' => Db::raw('integral - ' . $number)]);
                    if ($changed !== 1) throw new \RuntimeException('会员积分到期扣减失败');
                    $bill = $userBillServices->income('system_clear_integral', $uid, $number, $balance, $uid);
                    $billId = is_object($bill) ? (int)$bill->id : 0;
                    if ($billId <= 0) throw new \RuntimeException('会员积分到期流水写入失败');
                }
                $this->saveExpiryGuard($uid, $startTime, $endTime, $clearTime, $number, $billId);
            });
        }
        return true;
    }

    /**
     * 固定窗口来自调度时的 getTime；拒绝不完整或跨度异常的队列参数，避免重试时错扣年度。
     */
    private function assertExpiryPeriod(array $period): void
    {
        if (count($period) !== 3) throw new \InvalidArgumentException('积分到期清算窗口缺失');
        [$clearTime, $startTime, $endTime] = array_map('intval', array_values($period));
        if ($clearTime <= 0 || $startTime <= 0 || $endTime < $startTime
            || $endTime - $startTime > 366 * 86400 || $clearTime < $startTime
            || $clearTime - $endTime > 366 * 86400) {
            throw new \InvalidArgumentException('积分到期清算窗口无效');
        }
    }

    /**
     * 清算守卫与会员余额及清零流水处于同一事务；零扣减也记一次完成，避免晚到任务重新计算。
     */
    private function saveExpiryGuard(int $uid, int $startTime, int $endTime, int $clearTime,
                                     int $number, int $billId): void
    {
        $inserted = Db::name('user_integral_expiry')->insert([
            'uid' => $uid,
            'period_start' => $startTime,
            'period_end' => $endTime,
            'clear_time' => $clearTime,
            'number' => $number,
            'bill_id' => $billId,
            'recorded_at' => time(),
        ]);
        if ($inserted !== 1) throw new \RuntimeException('会员积分到期幂等守卫写入失败');
    }

    /**
     * 按取得期间计算积分净额；上期积分的 system_clear 落在本期时不能冲减本期新积分。
     */
    private function periodNetIntegralForUser(int $uid, int $startTime, int $endTime): int
    {
        $query = Db::name('user_bill')->where('uid', $uid)
            ->where('category', 'integral')->where('status', 1)
            ->where('add_time', '>=', $startTime)->where('add_time', '<=', $endTime);
        $income = (string)(clone $query)->where('pm', 1)->sum('number');
        $expense = (string)(clone $query)->where('pm', 0)
            ->where('type', '<>', 'system_clear')->sum('number');
        return bccomp($income, $expense, 0) > 0
            ? (int)bcsub($income, $expense, 0) : 0;
    }

    /**
     * 获取用户清空到期积分
     * @param int $uid
     * @param array $user
     * @return array|int[]
     */
    public function getUserClearIntegral(int $uid, $user = [])
    {
        if (!$uid) return [0, 0];
        //是否开启积分有效期
        if (!sys_config('integral_effective_status', 0)) {
            return [0, 0];
        }
        [$clear_time, $start_time, $end_time] = $this->getTime();

        if (!$user) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $user = $userServices->get($uid, 'uid,integral');
        }

        $userSumIntegral = $this->periodNetIntegralForUser($uid, $start_time, $end_time);
        if ($userSumIntegral) {
            if ($userSumIntegral >= $user['integral']) {
                $userSumIntegral = $user['integral'];
            }
        }
        return [$userSumIntegral, $clear_time];
    }


    /**
     * 获取清空积分时间段
     * @param int $type
     * @return int[]
     */
    public function getTime(int $type = 0)
    {
        if (!$type) $type = (int)sys_config('integral_effective_time', 3);
        switch ($type) {
            case 1://月
                $start = date('Y-m-01 00:00:00', strtotime('-1 month'));
                $end = date("Y-m-d 23:59:59", strtotime(-date('d') . 'day'));
                $clear_end = date('Y-m-t', strtotime(date('Y-m-d')));
                break;
            case 2://季度
                $season = ceil((date('n')) / 3) - 1;//上季度是第几季度
                $start = date('Y-m-d 00:00:00', mktime(0, 0, 0, $season * 3 - 3 + 1, 1, date('Y')));
                $end = date('Y-m-d 23:59:59', mktime(23, 59, 59, $season * 3, date('t', mktime(0, 0, 0, $season * 3, 1, date("Y"))), date('Y')));
                $clear_end = date('Y-m-t', mktime(0, 0, 0, ($season + 1) * 3, 1, date('Y')));
                break;
            case 3://年
            default://默认年
                $start = date('Y-01-01 00:00:00', strtotime('-1 year'));
                $end = date('Y-m-d 23:59:59', strtotime($start . "+12 month -1 day"));
                $clear_end = date('Y-12-31');
                break;
        }
        return [strtotime($clear_end), strtotime($start), strtotime($end)];
    }

	/**
 	* 新人礼赠送积分
	* @param int $uid
	* @return bool
	 */
	public function newcomerGiveIntegral(int $uid)
	{
		if (!sys_config('newcomer_status')) {
			return false;
		}
		$status = sys_config('register_integral_status');
		if (!$status) {//未开启
			return false;
		}
		$integral = (int)sys_config('register_give_integral', []);
		if (!$integral) {
			return false;
		}
		/** @var UserServices $userServices */
		$userServices = app()->make(UserServices::class);
		$userInfo = $userServices->getUserInfo($uid);
		if (!$userInfo) {
			return false;
		}
		//不是新用户
		if (!$userServices->checkUserIsNew($uid)) {
			return false;
		}
		$balance = bcadd((string)$userInfo['integral'], (string)$integral, 2);
		/** @var UserBillServices $userBillServices */
		$userBillServices = app()->make(UserBillServices::class);
		$userBillServices->income('newcomer_give_integral', $uid, (int)$integral, (int)$balance);
		$userServices->update($uid, ['integral' => $balance]);
		return true;
	}

	/**
 	* 会员卡激活赠送积分
	* @param int $uid
	* @return bool
	 */
	public function levelGiveIntegral(int $uid)
	{
		/** @var UserServices $userServices */
		$userServices = app()->make(UserServices::class);
		$userInfo = $userServices->getUserInfo($uid);
		if (!$userInfo) {
			return true;
		}
		$status = sys_config('level_activate_status');
		if (!$status) {//是否需要激活
			return true;
		}
		$status = sys_config('level_integral_status');
		if (!$status) {//未开启
			return true;
		}
		$integral = (int)sys_config('level_give_integral', []);
		if (!$integral) {
			return true;
		}
		$balance = bcadd((string)$userInfo['integral'], (string)$integral, 2);
		/** @var UserBillServices $userBillServices */
		$userBillServices = app()->make(UserBillServices::class);
		$userBillServices->income('level_give_integral', $uid, (int)$integral, (int)$balance);
		$userServices->update($uid, ['integral' => $balance]);
		return true;
	}
}
