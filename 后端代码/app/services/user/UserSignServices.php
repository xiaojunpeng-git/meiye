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
declare (strict_types=1);

namespace app\services\user;

use app\jobs\user\UserLevelJob;
use app\services\BaseServices;
use app\services\system\SystemSignRewardServices;
use app\services\user\member\MemberCardServices;
use app\dao\user\UserSignDao;
use mohe\exceptions\ApiException;
use think\exception\ValidateException;

/**
 *
 * Class UserSignServices
 * @package app\services\user
 * @mixin UserSignDao
 */
class UserSignServices extends BaseServices
{

    /**
     * UserSignServices constructor.
     * @param UserSignDao $dao
     */
    public function __construct(UserSignDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 返回签到列表数据
     * @param $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function signConfig($uid)
    {
        //获取周签到还是月签到
        $signMode = (int)sys_config('sign_mode', 1);

        //获取签到列表
        $startDate = $signMode == 1 ? strtotime('this week Monday') : strtotime('first day of this month midnight');
        $endDate = $signMode == 1 ? strtotime('this week Sunday') : strtotime('last day of this month midnight');
        $dateList = range($startDate, $endDate, 86400);

        $checkSign = $this->getIsSign($uid, 'today');


        $sign_give_point = (int)sys_config('sign_give_point', 0);
        $sign_exp = sys_config('member_func_status', 1) ? sys_config('sign_give_exp', 0) : 0;
        [$signList, $continuousSignDays, $cumulativeSignDays, $nextContinuousDays, $nextCumulativeDays] = $this->signDataFilling($signMode, $uid, $sign_give_point, $sign_exp, $dateList, '');

        //格式化签到数据
//        $signList = array_chunk($signList, 7);

        //获取用户签到提醒状态
        $signRemindStatus = 0;
		if ($uid) {
			$signRemindStatus = app()->make(UserServices::class)->value(['uid' => $uid], 'sign_remind');
		}

        //是否显示签到提醒开关
        $signRemindSwitch = (int)sys_config('sign_remind', 0);

        //签到功能是否关闭
        $signStatus = (int)sys_config('sign_status', 0);

        $signData['sign_point'] = $sign_give_point;
        $signData['sign_exp'] = $sign_exp;


        return compact('signList', 'continuousSignDays', 'cumulativeSignDays', 'nextContinuousDays', 'nextCumulativeDays', 'signMode', 'checkSign', 'signRemindStatus', 'signRemindSwitch', 'signStatus', 'signData');
    }

    /**
     * 日历数据
     * @param $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCalendarDate($uid, $time)
    {
		$time = $time ?: date('Y-m');
        $startDate = strtotime(date('Y-m-01', strtotime($time)));
        $endDate = strtotime(date('Y-m-d', strtotime(date('Y-m-01', strtotime($time)) . ' +1 month -1 day')));
        $dateList = range($startDate, $endDate, 86400);
        $checkSign = $this->getIsSign($uid, 'today');
        $sign_give_point = (int)sys_config('sign_give_point', 0);
        $sign_exp = sys_config('member_func_status', 1) ? sys_config('sign_give_exp', 0) : 0;
        $today = date("Y-m-d"); // 今日日期
        $w = date('w', $startDate); //月初周几
        [$signList, $continuousSignDays, $cumulativeSignDays, $nextContinuousDays, $nextCumulativeDays] = $this->signDataFilling(0, $uid, $sign_give_point, $sign_exp, $dateList, $time);
        return compact('today', 'w', 'signList', 'continuousSignDays', 'cumulativeSignDays', 'nextContinuousDays', 'nextCumulativeDays', 'checkSign');
    }

    /**
     * 整理签到列表数据
     * @param $signMode
     * @param $uid
     * @param $sign_give_point
     * @param $sign_exp
     * @param $dateList
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function signDataFilling($signMode, $uid, $sign_give_point, $sign_exp, $dateList, $time)
    {
        if (!$time) $time = date('Y-m', time());
		$list = [];
		$cumulativeSignDays = $continuousSignDays = 0;
		if ($uid) {//登录
			//获取已经签到的列表
			$list = $this->dao->getUserSignList($signMode, $uid, $time);

			//获取累积签到和连续签到
			$cumulativeSignDays = $this->dao->getCumulativeDays($uid); //获取累积签到天数
			$continuousSignDays = app()->make(UserServices::class)->value(['uid' => $uid], 'sign_num');//获取连续签到天数
		}

        //获取累积签到和连续签到奖励
        /** @var SystemSignRewardServices $systemSignRewardServices */
        $systemSignRewardServices = app()->make(SystemSignRewardServices::class);
        $nextCumulativeSignRewardList = $systemSignRewardServices->selectList(['type' => 1], '*', 1, 200, 'days asc'); //累计签到奖励
        $nextContinuousSignRewardList = $systemSignRewardServices->selectList(['type' => 0], '*', 1, 200, 'days asc'); //连续签到奖励

        //下一次连续签到奖励还需签到几天
        $nextContinuousDays = 0;
        foreach ($nextContinuousSignRewardList as $continuousNext) {
            if ($continuousSignDays < $continuousNext['days']) {
                $nextContinuousDays = $continuousNext['days'] - $continuousSignDays > 0 ?: 1;
                break;
            }
        }
        $nextCumulativeDays = 0;
        foreach ($nextCumulativeSignRewardList as $cumulativeNext) {
            if ($cumulativeSignDays < $cumulativeNext['days']) {
                $nextCumulativeDays = $cumulativeNext['days'] - $cumulativeSignDays > 0 ?: 1;
                break;
            }
        }
        //整理签到列表数据
        $signList = [];
        $i = 0;

        foreach ($dateList as $key => $time) {
            $day = date('m/d', $time);
            if ($day[0] == '0') $day = substr($day, 1);
            $signList[$key]['day'] = $day;
            $signList[$key]['is_sign'] = false;

            //判断当前签到日期
            $signList[$key]['sign_day'] = date('Y-m-d', $time) == date('Y-m-d', time());

            //判断今日是否签到
            foreach ($list as $value) {
                if (date('Y-m-d', $time) == date('Y-m-d', $value['add_time'])) {
                    $signList[$key]['is_sign'] = true;
                    break;
                }
            }

            //处理处理签到类型展示，type 1积分，2经验
            $signList[$key]['type'] = $sign_give_point == 0 && sys_config('member_func_status', 1) == 1 && $sign_exp > 0 ? 2 : 1;
            $signList[$key]['point'] = $sign_give_point;
            $signList[$key]['days'] = 0; //连续、累计 奖励天数
            $signList[$key]['sign_type'] = 0; // 3连续签到奖励，4累计签到奖励
            if (date('Y-m-d', $time) >= date('Y-m-d', time())) {
                // 连续签到奖励
                foreach ($nextContinuousSignRewardList as $continuous) {
                    if (($continuous['days'] - $continuousSignDays) == $i) {
                        $signList[$key]['sign_type'] = 3;
                        $signList[$key]['days'] = $continuous['days'];
                        $signList[$key]['point'] = $continuous['point'];
                    }
                }
                // 累计签到奖励
                foreach ($nextCumulativeSignRewardList as $cumulative) {
                    if (($cumulative['days'] - $cumulativeSignDays) == $i) {
                        $signList[$key]['sign_type'] = 4;
                        $signList[$key]['days'] = $cumulative['days'];
                        $signList[$key]['point'] = $cumulative['point'];
                    }
                }
                $i++;
            }
        }
        return [$signList, $continuousSignDays, $cumulativeSignDays, $nextContinuousDays, $nextCumulativeDays];
    }

	/**
	 * 获取用户是否签到
	 * @param int $uid
	 * @param string $type
	 * @return bool
	 */
    public function getIsSign(int $uid, string $type = 'today')
    {
        return $uid && (bool)$this->dao->count(['uid' => $uid, 'time' => $type]);
    }

    /**
     * 获取用户累计签到次数
     * @Parma int $uid 用户id
     * @return int
     * */
    public function getSignSumDay(int $uid)
    {
        return $uid ? $this->dao->count(['uid' => $uid]) : 0;
    }

    /**
     * 设置签到数据
     * @param int $uid 用户uid
     * @param string $title 签到说明
     * @param int $number 签到获得积分
     * @param int $integral_balance
     * @param int $exp_banlance
     * @param int $exp_num
     * @return bool
     * @throws \think\Exception
     */
    public function setSignData($uid, $title = '', $number = 0, $integral_balance = 0, $exp_banlance = 0, $exp_num = 0)
    {
        $data = [];
        $data['uid'] = $uid;
        $data['title'] = $title;
        $data['number'] = $number;
        $data['balance'] = bcadd((string)$integral_balance, (string)$number, 2);
        $data['exp_num'] = $exp_num;
        $data['exp_balance'] = bcadd((string)$exp_banlance, (string)$exp_num, 2);
        $data['add_time'] = time();
        if (!$this->dao->save($data)) {
            throw new ValidateException('添加签到数据失败');
        }
        /** @var UserBillServices $userBill */
        $userBill = app()->make(UserBillServices::class);
        $data['mark'] = $title;
        $userBill->incomeIntegral($uid, 'sign', $data);

        if ($exp_num) {
            $data['number'] = $exp_num;
            $data['category'] = 'exp';
            $data['type'] = 'sign';
            $data['title'] = $data['mark'] = '签到奖励';
            $data['balance'] = bcadd((string)$exp_banlance, (string)$exp_num, 2);
            $data['pm'] = 1;
            $data['status'] = 1;
            if (!$userBill->save($data)) {
                throw new ValidateException('赠送经验失败');
            }
            //检测会员等级
            UserLevelJob::dispatch([$uid]);
        }

        return true;
    }

    /**
     * 获取用户签到列表
     * @param int $uid
     * @param string $field
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserSignList(int $uid, string $field = '*')
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList(['uid' => $uid], $field, $page, $limit);
        foreach ($list as &$item) {
            $item['add_time'] = $item['add_time'] ? date('Y-m-d', $item['add_time']) : '';
        }
        return $list;
    }

    /**
     * 用户签到
     * @param $uid
     * @return bool|int|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function sign(int $uid)
    {
        //签到功能是否关闭
        $signStatus = (int)sys_config('sign_status', 0);
        if (!$signStatus) {
            throw new ApiException('签到功能已关闭');
        }

        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);

        //检测用户是否存在
        $user = $userServices->getUserInfo($uid);
        if (!$user) {
            throw new ApiException('用户不存在');
        }

        //检测今天是否已经签到
        if ($this->getIsSign($uid, 'today')) {
            throw new ApiException('今日已签到');
        }
        $title = '签到奖励';
        //检测昨天是否签到，如果没有签到，连续签到清0
        if (!$this->getIsSign($uid, 'yesterday')) $user->sign_num = 0;

        //获取签到周期配置，如果周签到，每周一清空连续签到记录，如果月签到，每月一日清空连续签到记录
        $sign_mode = sys_config('sign_mode', -1);
        if ($sign_mode == 1 && date('w') == 1) $user->sign_num = 0;
        if ($sign_mode == 0 && date('d') == 1) $user->sign_num = 0;

        //连续签到天数
        $user->sign_num += 1;
        $continuousDays = $user->sign_num;
        //累积签到天数
        $cumulativeDays = $this->dao->getCumulativeDays($uid);

        //基础签到奖励
        $sign_point = sys_config('sign_give_point', 0);
        $sign_exp = sys_config('member_func_status', 1) ? sys_config('sign_give_exp', 0) : 0;

        //连续签到和累积签到奖励
        $signRewardsServices = app()->make(SystemSignRewardServices::class);
        [$continuousStatus, $continuousRewardPoint, $continuousRewardExp] = $signRewardsServices->getSignRewards(0, $continuousDays);
        [$cumulativeStatus, $cumulativeRewardPoint, $cumulativeRewardExp] = $signRewardsServices->getSignRewards(1, $cumulativeDays);
        if ($continuousStatus && $cumulativeStatus) {
            $sign_point = $continuousRewardPoint + $cumulativeRewardPoint;
            $sign_exp = $continuousRewardExp + $cumulativeRewardExp;
        } elseif ($continuousStatus) {
            $sign_point = $continuousRewardPoint;
            $sign_exp = $continuousRewardExp;
        } elseif ($cumulativeStatus) {
            $sign_point = $cumulativeRewardPoint;
            $sign_exp = $cumulativeRewardExp;
        }

        //会员签到积分会员奖励
        if ($user->is_money_level > 0) {
            //看是否开启签到积分翻倍奖励
            /** @var MemberCardServices $memberCardService */
            $memberCardService = app()->make(MemberCardServices::class);
            $sign_rule_number = $memberCardService->isOpenMemberCard('sign');
            if ($sign_rule_number) {
                $up_num = (int)$sign_rule_number * $sign_point - $sign_point;
                $sign_point = (int)$sign_rule_number * $sign_point;
                if (!$this->getIsSign($uid, 'yesterday')) $title = '签到奖励(SVIP+' . $up_num . ')';
            }
        }

        //增加签到数据
        $this->transaction(function () use ($uid, $title, $sign_point, $user, $sign_exp) {
            if ($sign_exp && !$user['level_status']) {//有经验 && 用户没有激活 不送升级经验
                $sign_exp = 0;
            }
            $this->setSignData($uid, $title, $sign_point, $user['integral'], (int)$user['exp'], $sign_exp);
            if ($sign_point) $user->integral = (int)$user->integral + (int)$sign_point;
            if ($sign_exp) $user->exp = (int)$user->exp + (int)$sign_exp;
            if (!$user->save()) {
                throw new ApiException('修改用户数据失败');
            }
        });
        return compact('sign_point', 'sign_exp');
    }

    /**
     * 签到用户信息
     * @param int $uid
     * @param $sign
     * @param $integral
     * @param $all
     * @return mixed
     */
    public function signUser(int $uid, $sign, $integral, $all)
    {
		$data = ['uid' => 0, 'nickname' => '', 'phone' => '', 'now_money' => 0, 'integral' => 0, 'is_promoter' => 0];
		$user = [];
		if ($uid) {//登录
			/** @var UserServices $userServices */
			$userServices = app()->make(UserServices::class);
			$user = $userServices->getUserCacheInfo($uid);
			if (!$user) {
				throw new ValidateException('数据不存在');
			}
			$data = ['uid' => $user['uid'], 'nickname' => $user['nickname'], 'phone' => $user['phone'], 'now_money' => $user['now_money'], 'integral' => $user['integral'], 'is_promoter' => $user['is_promoter'] ?? 0];
		}

        //是否统计签到
        if ($sign || $all) {
            $data['sum_sgin_day'] = $this->getSignSumDay($uid);
            $data['is_day_sgin'] = $this->getIsSign($uid);
            $data['is_YesterDay_sgin'] = $this->getIsSign($uid, 'yesterday');
            if (!$data['is_day_sgin'] && !$data['is_YesterDay_sgin']) {
                $data['sign_num'] = 0;
            }
        }
        /** @var UserIntegralServices $userIntegralServices */
        $userIntegralServices = app()->make(UserIntegralServices::class);
        [$clear_integral, $clear_time] = $userIntegralServices->getUserClearIntegral($uid, $user);
        $data['clear_integral'] = $clear_integral;
        $data['clear_time'] = $clear_time;
		$data['sum_integral'] = $data['deduction_integral'] = $data['today_integral'] = $data['frozen_integral'] = $data['integral'] = 0;
        //是否统计积分使用情况
        if ($integral || $all) {
			if ($uid) {//登录
				/** @var UserBillServices $userBill */
				$userBill = app()->make(UserBillServices::class);
				$data['sum_integral'] = intval($userBill->getRecordCount($uid, 'integral', [], '', true));
				$data['deduction_integral'] = intval($userBill->getRecordCount($uid, 'integral') ?? 0);
				$data['today_integral'] = intval($userBill->getRecordCount($uid, 'integral', [], 'today', true));
				$data['frozen_integral'] = $userBill->getBillSum(['uid' => $uid, 'category' => 'integral', 'pm' => 1, 'is_frozen' => 1]);
				$data['integral'] = (int)max($userBill->countIntegralBalance((int)$user['integral'],(int)$uid), 0);
			}
            $data['integral_effective_status'] = (int)sys_config('integral_effective_status');
            $data['clear_end'] = '';
            if ($data['integral_effective_status']) {
                [$clear_end, $start, $end] = $userIntegralServices->getTime();
                $data['clear_end'] = date('Y-m-d', $clear_end);
            }
        }
        if (isset($user['is_promoter']) && !$user['is_promoter']) {
            $data['is_promoter'] = (int)sys_config('store_brokerage_statu') == 2;
        }
        return $data;
    }


    /**
     * 获取签到
     * @param $uid
     * @return array
     */
    public function getSignMonthList($uid)
    {
        [$page, $limit] = $this->getPageValue();
        $data = $this->dao->getListGroup(['uid' => $uid], 'FROM_UNIXTIME(add_time,"%Y-%m") as time,group_concat(id SEPARATOR ",") ids', $page, $limit, 'time');
        $list = [];
        if ($data) {
            $ids = array_unique(array_column($data, 'ids'));
            $dataIdsList = $this->dao->getList(['id' => $ids], 'FROM_UNIXTIME(add_time,"%Y-%m-%d") as add_time,title,number,exp_num,id,uid', 0, 0);
            foreach ($data as $item) {
                $value['month'] = $item['time'];
                $value['list'] = array_merge(array_filter($dataIdsList, function ($val) use ($item) {
                    if (in_array($val['id'], explode(',', $item['ids']))) {
                        return $val;
                    }
                }));
                array_push($list, $value);
            }
        }
        return $list;
    }

    /**
     * 签到提醒设置
     * @param $uid
     * @param $status
     * @return bool
     */
    public function setSignRemind($uid, $status)
    {
        app()->make(UserServices::class)->update($uid, ['sign_remind' => $status]);
        return true;
    }

    /**
     * 用户签到提醒
     * @return bool
     */
    public function userSignRemind()
    {
        //签到开关
        $signStatus = (int)sys_config('sign_status', 0);
        if(!$signStatus) return true;
        //签到提醒开关
        $signRemindSwitch = (int)sys_config('sign_remind', 0);
        if(!$signRemindSwitch) return true;
        /** @var  UserServices $userService */
        $userService = app()->make(UserServices::class);
        $where = ['status' => 1, 'sign_remind' => 1];
        $userList = $userService->getColumn($where, 'uid,phone,sign_remind', 'uid');
        foreach ($userList as $key => $item) {
            if (!$this->getIsSign($item['uid'], 'today')) {
                event('notice.notice', [$item, 'sign_remind_time']);
            }
        }
        return true;
    }

    /**
     * 首页diy签到数据
     * @param int $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function homeDiysignData(int $uid = 0)
    {
        //获取签到列表
        $startDate = strtotime('this week Monday');
        $endDate = strtotime('this week Sunday');
        $dateList = range($startDate, $endDate, 86400);

        $checkSign = $this->getIsSign($uid, 'today');

        $sign_give_point = (int)sys_config('sign_give_point', 0);
        $sign_exp = sys_config('member_func_status', 1) ? sys_config('sign_give_exp', 0) : 0;

        //获取连续签到奖励
        $nextContinuousSignRewardList = app()->make(SystemSignRewardServices::class)->selectList(['type' => 0], '*', 1, 1, 'days asc'); //连续签到奖励
        $list = [];

        if ($uid) {
            //获取已经签到的列表
            $list = $this->dao->getUserSignList(1, $uid, '');
        }
        //整理签到列表数据
        $signList = [];

        foreach ($dateList as $key => $time) {
            $day = date('m/d', $time);
            if ($day[0] == '0') $day = substr($day, 1);
            $signList[$key]['day'] = $day;
            $signList[$key]['is_sign'] = false;

            //判断当前签到日期
            $signList[$key]['sign_day'] = date('Y-m-d', $time) == date('Y-m-d', time());
            if ($list) {
                //判断今日是否签到
                foreach ($list as $value) {
                    if (date('Y-m-d', $time) == date('Y-m-d', $value['add_time'])) {
                        $signList[$key]['is_sign'] = true;
                        break;
                    }
                }
            }
            //处理处理签到类型展示，type 1积分，2经验
            $signList[$key]['type'] = $sign_give_point == 0 && sys_config('member_func_status', 1) == 1 && $sign_exp > 0 ? 2 : 1;
            $signList[$key]['point'] = $sign_give_point;
        }

        //格式化签到数据
        $signList = array_chunk($signList, 7);

        //签到功能是否关闭
        $signStatus = (int)sys_config('sign_status', 0);

        return compact('signList', 'nextContinuousSignRewardList', 'checkSign', 'signStatus', 'sign_give_point');
    }
}
