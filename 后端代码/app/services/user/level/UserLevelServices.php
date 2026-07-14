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

namespace app\services\user\level;

use app\jobs\user\UserLevelJob;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\BaseServices;
use app\services\user\UserMoneyServices;
use app\services\user\UserServices;
use app\services\user\UserBillServices;
use app\services\user\UserSignServices;
use app\dao\user\level\UserLevelDao;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use mohe\services\SystemConfigService;
use FormBuilder\Factory\Iview;
use think\exception\ValidateException;
use think\facade\Route as Url;

/**
 * 用户等级
 * Class UserLevelServices
 * @package app\services\user\level
 * @mixin UserLevelDao
 */
class UserLevelServices extends BaseServices
{

    /**
     * UserLevelServices constructor.
     * @param UserLevelDao $dao
     */
    public function __construct(UserLevelDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 根据uid获取用户信息 登记会员折扣
	 * @param int $uid
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getUserInfoAndLevelDiscount(int $uid)
	{
		$userInfo = [];
		$discount = 100;
		if ($uid) {
			/** @var UserServices $user */
			$user = app()->make(UserServices::class);
			$userInfo = $user->getUserCacheInfo($uid);
			if ($userInfo) {
				$userInfo = $userInfo->toArray();
				//用户等级是否开启
				if (sys_config('member_func_status', 1)) {
					/** @var SystemUserLevelServices $systemLevel */
					$systemLevel = app()->make(SystemUserLevelServices::class);
					$discount = $systemLevel->getDiscount($uid, (int)$userInfo['level'] ?? 0, $userInfo);
				}
			}
		}
		return [$userInfo, $discount];
	}

    /**
     * 获取一些用户等级信息
     * @param array $uids
     * @param string $field
     * @param string $key
     * @return array
     */
    public function getUsersLevelInfo(array $uids)
    {
        return $this->dao->getColumn([['uid', 'in', $uids]], 'level_id,is_forever,valid_time,grade', 'uid');
    }

    /**
     * 清除会员等级
     * @param $uids
     * @return \mohe\basic\BaseModel|mixed
     */
    public function delUserLevel($uids)
    {
        $where = [];
        if (is_array($uids)) {
            $where[] = ['uid', 'IN', $uids];
            $re = $this->dao->batchUpdate($uids, ['is_del' => 1, 'status' => 0], 'uid');
        } else {
            $where[] = ['uid', '=', $uids];
            $re = $this->dao->update($uids, ['is_del' => 1, 'status' => 0], 'uid');
        }
        if (!$re)
            throw new AdminException('修改会员信息失败');
        $where[] = ['category', 'IN', ['exp']];
        /** @var UserBillServices $userbillServices */
        $userbillServices = app()->make(UserBillServices::class);
        $userbillServices->update($where, ['status' => -1]);
        return true;
    }

    /**
     * 个人中心获取用户等级信息
     * @param int $uid
     * @return array
     */
    public function homeGetUserLevel(int $uid, $userInfo = [])
    {
        $data = ['vip' => false, 'vip_id' => 0, 'vip_icon' => '', 'vip_name' => '', 'vip_discount' => 100];
        //用户存在
        if ($uid && sys_config('member_func_status', 0)) {
            if (!$userInfo) {
                /** @var UserServices $userServices */
                $userServices = app()->make(UserServices::class);
                $userInfo = $userServices->getUserInfo($uid);
            }
            if ($userInfo) {
                $levelInfo = $this->getUerLevelInfoByUid($uid);
                if ($levelInfo) {
                    $data['vip'] = true;
                    $data['vip_id'] = $levelInfo['id'];
                    $data['vip_icon'] = $levelInfo['icon'];
                    $data['vip_name'] = $levelInfo['name'];
                    $data['vip_discount'] = $levelInfo['discount'] ? bcdiv((string)$levelInfo['discount'], '10', 1) : 0;
                }
            }
        }
        return $data;
    }

    /**
     * 根据用户uid 获取会员详细信息
     * @param int $uid
     * @param string $field
     */
    public function getUerLevelInfoByUid(int $uid, string $field = '')
    {
        $userLevelInfo = $this->dao->getUserLevel($uid);
        $data = [];
        if ($userLevelInfo) {
            $data = ['id' => $userLevelInfo['id'], 'level_id' => $userLevelInfo['level_id'], 'add_time' => $userLevelInfo['add_time']];
            $data['discount'] = $userLevelInfo['levelInfo']['discount'] ?? 0;
            $data['name'] = $userLevelInfo['levelInfo']['name'] ?? '';
            $data['money'] = $userLevelInfo['levelInfo']['money'] ?? 0;
            $data['icon'] = set_file_url($userLevelInfo['levelInfo']['icon'] ?? '');
            $data['image'] = set_file_url($userLevelInfo['levelInfo']['image'] ?? '');
            $data['is_pay'] = $userLevelInfo['levelInfo']['is_pay'] ?? 0;
            $data['grade'] = $userLevelInfo['levelInfo']['grade'] ?? 0;
            $data['exp_num'] = $userLevelInfo['levelInfo']['exp_num'] ?? 0;
        }
        if ($field) return $data[$field] ?? '';
        return $data;
    }

	/**
	 * 设置会员等级
	 * @param int $uid
	 * @param int $level_id
	 * @param $vipinfo
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function setUserLevel(int $uid, int $level_id, $vipinfo = [])
    {
		/** @var UserServices $user */
		$user = app()->make(UserServices::class);
		//把之前等级作废
		$this->dao->update(['uid' => $uid], ['status' => 0, 'is_del' => 1]);
		if ($level_id) {//修改为新的等级
			/** @var SystemUserLevelServices $systemLevelServices */
			$systemLevelServices = app()->make(SystemUserLevelServices::class);
			if (!$vipinfo) {
				$vipinfo = $systemLevelServices->getLevel($level_id);
				if (!$vipinfo) {
					throw new AdminException('会员等级不存在');
				}
			}
			$userinfo = $user->getUserInfo($uid);
			//检查是否购买过
			$uservipinfo = $this->dao->getOne(['uid' => $uid, 'level_id' => $level_id]);
			$data['mark'] = '尊敬的用户' . $userinfo['nickname'] . '在' . date('Y-m-d H:i:s', time()) . '成为了' . $vipinfo['name'];
			$data['add_time'] = time();
			if ($uservipinfo) {
				$data['status'] = 1;
				$data['is_del'] = 0;
				if (!$this->dao->update(['id' => $uservipinfo['id']], $data))
					throw new AdminException('修改会员信息失败');
			} else {
				$data = array_merge($data, [
					'is_forever' => $vipinfo->is_forever,
					'status' => 1,
					'is_del' => 0,
					'grade' => $vipinfo->grade,
					'uid' => $uid,
					'level_id' => $level_id,
					'discount' => $vipinfo->discount,
				]);
				$data['valid_time'] = 0;
				if (!$this->dao->save($data)) throw new AdminException('写入会员信息失败');
			}
		}//else 清空用户等级
        if (!$user->update(['uid' => $uid], ['level' => $level_id, 'exp' => $vipinfo['exp_num'] ?? 0, 'level_status' => 1]))
            throw new AdminException('修改用户会员等级失败');
        return true;
    }

    /**
     * 会员列表
     * @param $where
     * @return mixed
     */
    public function getSytemList($where)
    {
        /** @var SystemUserLevelServices $systemLevelServices */
        $systemLevelServices = app()->make(SystemUserLevelServices::class);
        return $systemLevelServices->getLevelList($where);
    }

	/**
	 * 获取添加修改需要表单数据
	 * @param int $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function edit(int $id)
    {

        if ($id) {
            $vipinfo = app()->make(SystemUserLevelServices::class)->getlevel($id);
            if (!$vipinfo) {
                throw new AdminException('数据不存在');
            }
            $field[] = Form::hidden('id', $id);
            $msg = '编辑会员等级';
        } else {
            $msg = '添加会员等级';
        }
        $field[] = Form::input('name', '等级名称：', $vipinfo['name'] ?? '')->required('请填写等级名称');
//        $field[] = Form::number('valid_date', '有效时间(天)：', $vipinfo['valid_date'] ?? 0)->min(0)->col(12);
        $field[] = Form::number('grade', '等级：', $vipinfo['grade'] ?? 0)->min(0)->precision(0);
        $field[] = Form::number('discount', '享受折扣：', $vipinfo['discount'] ?? 100)->min(0)->max(100)->info('输入折扣数100，代表原价，90代表9折')->placeholder('输入折扣数100，代表原价，90代表9折');
        $field[] = Form::number('exp_num', '解锁需经验值达到：', $vipinfo['exp_num'] ?? 0)->min(0)->precision(0);
        $field[] = Form::frameImage('icon', '图标（656*300）：', Url::buildUrl('admin/widget.images/index', array('fodder' => 'icon')), $vipinfo['icon'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true])->appendValidate(Iview::validateStr()->required()->message('请选择图标'));
        $field[] = Form::frameImage('image', '背景（750*990）：', Url::buildUrl('admin/widget.images/index', array('fodder' => 'image')), $vipinfo['image'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true])->appendValidate(Iview::validateStr()->required()->message('请选择背景'));
        $field[] = Form::color('color', '字体颜色：', $vipinfo['color'] ?? '')->required('请选择字体颜色');
        $field[] = Form::radio('is_show', '是否显示：', $vipinfo['is_show'] ?? 0)->options([['label' => '显示', 'value' => 1], ['label' => '隐藏', 'value' => 0]]);
        $field[] = Form::textarea('explain', '等级说明：', $vipinfo['explain'] ?? '')->required('请输入等级说明');
        return create_form($msg, $field, Url::buildUrl('/user/user_level'), 'POST');
    }

    /*
     * 会员等级添加或者修改
     * @param $id 修改的等级id
     * @return json
     * */
    public function save(int $id, array $data)
    {
        /** @var SystemUserLevelServices $systemUserLevel */
        $systemUserLevel = app()->make(SystemUserLevelServices::class);
        $levelOne = $systemUserLevel->getWhereLevel(['is_del' => 0, 'grade' => $data['grade']]);
        $levelTwo = $systemUserLevel->getWhereLevel(['is_del' => 0, 'exp_num' => $data['exp_num']]);
        $levelThree = $systemUserLevel->getWhereLevel(['is_del' => 0, 'name' => $data['name']]);
        $levelPre = $systemUserLevel->getPreLevel($data['grade']);
        $levelNext = $systemUserLevel->getNextLevel($data['grade']);
        if ($levelPre && $data['exp_num'] <= $levelPre['exp_num']) {
            throw new AdminException('会员经验必须大于上一等级设置的经验');
        }
        if ($levelNext && $data['exp_num'] >= $levelNext['exp_num']) {
            throw new AdminException('会员经验必须小于下一等级设置的经验');
        }
        //修改
        if ($id) {
            if (($levelOne && $levelOne['id'] != $id) || ($levelThree && $levelThree['id'] != $id)) {
                throw new AdminException('已检测到您设置过的会员等级，此等级不可重复');
            }
            if ($levelTwo && $levelTwo['id'] != $id) {
                throw new AdminException('已检测到您设置过该会员经验值，经验值不可重复');
            }
            if (!$systemUserLevel->update($id, $data)) {
                throw new AdminException('修改失败');
            }

            $data['id'] = $id;
            $systemUserLevel->dao->cacheUpdate($data);

            return '修改成功';
        } else {
            if ($levelOne || $levelThree) {
                throw new AdminException('已检测到您设置过的会员等级，此等级不可重复');
            }
            if ($levelTwo) {
                throw new AdminException('已检测到您设置过该会员经验值，经验值不可重复');
            }
            //新增
            $data['add_time'] = time();
            $res = $systemUserLevel->save($data);
            if (!$res) {
                throw new AdminException('添加失败');
            }

            $data['id'] = $res->id;
            $systemUserLevel->cacheUpdate($data);

            return '添加成功';
        }
    }

    /**
     * 假删除
     * @param int $id
     * @return mixed
     */
    public function delLevel(int $id)
    {
        /** @var SystemUserLevelServices $systemUserLevel */
        $systemUserLevel = app()->make(SystemUserLevelServices::class);
        $level = $systemUserLevel->getWhereLevel(['id' => $id]);
        if ($level && $level['is_del'] != 1) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            if ($userServices->count(['level' => $level['id']])) {
                throw new AdminException('存在用户已是该等级，无法删除');
            }
            if (!$systemUserLevel->update($id, ['is_del' => 1]))
                throw new AdminException('删除失败');
            if (!$this->dao->update(['level_id' => $id], ['is_del' => 1])) {
                throw new AdminException('删除失败');
            }
        }
        $systemUserLevel->cacheDelById($id);
        return '删除成功';
    }

    /**
     * 设置是否显示
     * @param int $id
     * @param $is_show
     * @return mixed
     */
    public function setShow(int $id, int $is_show)
    {
        /** @var SystemUserLevelServices $systemUserLevel */
        $systemUserLevel = app()->make(SystemUserLevelServices::class);
        if (!$systemUserLevel->getWhereLevel(['id' => $id]))
            throw new AdminException('数据不存在');
        if ($systemUserLevel->update($id, ['is_show' => $is_show])) {
            $systemUserLevel->cacheSaveValue($id, 'is_show', $is_show);
            return $is_show == 1 ? '显示成功' : '隐藏成功';
        } else {
            throw new AdminException($is_show == 1 ? '显示失败' : '隐藏失败');
        }
    }

    /**
     * 快速修改
     * @param int $id
     * @param $is_show
     * @return mixed
     */
    public function setValue(int $id, array $data)
    {
        /** @var SystemUserLevelServices $systemUserLevel */
        $systemUserLevel = app()->make(SystemUserLevelServices::class);
        if (!$systemUserLevel->getWhereLevel(['id' => $id]))
            throw new AdminException('数据不存在');
        if ($systemUserLevel->update($id, [$data['field'] => $data['value']])) {
            $systemUserLevel->cacheSaveValue($id, $data['field'], $data['value']);
            return true;
        } else {
            throw new AdminException('保存失败');
        }
    }

    /**
     * 检测用户会员升级
     * @param $uid
     * @return bool
     */
    public function detection(int $uid)
    {
		if (!$uid) {
			return true;
		}
        //商城会员是否开启
        if (!sys_config('member_func_status')) {
            return true;
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserCacheInfo($uid);
        if (!$user) {
            throw new ValidateException('没有此用户，无法检测升级会员');
        }
        //没有激活暂不升级
        if (!$user['level_status']) {
            return true;
        }
        /** @var SystemUserLevelServices $systemUserLevel */
        $systemUserLevel = app()->make(SystemUserLevelServices::class);
        $userAllLevel = $systemUserLevel->getList([['is_del', '=', 0], ['is_show', '=', 1], ['exp_num', '<=', (float)$user['exp']]]);
        if (!$userAllLevel) {
            return true;
        }
        $data = [];
        $data['add_time'] = time();
        $userLevel = $this->dao->getColumn(['uid' => $uid, 'status' => 1, 'is_del' => 0], 'level_id');
        foreach ($userAllLevel as $vipinfo) {
            if (in_array($vipinfo['id'], $userLevel)) {
                continue;
            }
            $data['mark'] = '尊敬的用户' . $user['nickname'] . '在' . date('Y-m-d H:i:s', time()) . '成为了' . $vipinfo['name'];
            $uservip = $this->dao->getOne(['uid' => $uid, 'level_id' => $vipinfo['id']]);
            if ($uservip) {
                //降级在升级情况
                $data['status'] = 1;
                $data['is_del'] = 0;
                if (!$this->dao->update($uservip['id'], $data, 'id')) {
                    throw new ValidateException('检测升级失败');
                }
            } else {
                $data = array_merge($data, [
                    'is_forever' => $vipinfo['is_forever'],
                    'status' => 1,
                    'is_del' => 0,
                    'grade' => $vipinfo['grade'],
                    'uid' => $uid,
                    'level_id' => $vipinfo['id'],
                    'discount' => $vipinfo['discount'],
                ]);
                if (!$this->dao->save($data)) {
                    throw new ValidateException('检测升级失败');
                }
            }
            $data['add_time'] += 1;
        }
        if (!$userServices->update($uid, ['level' => end($userAllLevel)['id']], 'uid')) {
            throw new ValidateException('检测升级失败');
        }
        return true;
    }

    /**
     * 会员等级列表
     * @param int $uid
     */
    public function grade(int $uid)
    {
        //商城会员是否开启
        if (!sys_config('member_func_status')) {
            return [];
        }
		$userLevelInfo = [];
		if ($uid) {
			/** @var UserServices $userServices */
			$userServices = app()->make(UserServices::class);
			$user = $userServices->getUserInfo($uid);
			if (!$user) {
				throw new ValidateException('没有此用户，无法检测升级会员');
			}
			$userLevelInfo = $this->getUerLevelInfoByUid($uid);
		}

        if (empty($userLevelInfo)) {
            $level_id = 0;
        } else {
            $level_id = $userLevelInfo['level_id'];
        }
        /** @var SystemUserLevelServices $systemUserLevel */
        $systemUserLevel = app()->make(SystemUserLevelServices::class);
        return $systemUserLevel->getLevelListAndGrade($level_id);
    }

    /**
     * 获取会员信息
     * @param int $uid
     * @return array[]
     */
    public function getUserLevelInfo(int $uid)
    {
        $data = ['user' => [], 'level_info' => [], 'level_list' => [], 'task' => []];
        //商城会员是否开启
        if (!sys_config('member_func_status')) {
            return $data;
        }
		$sign_count = $today_exp = 0;
		$user = $levelInfo = [];
		if ($uid) {//登录
			/** @var UserServices $userServices */
			$userServices = app()->make(UserServices::class);
			$user = $userServices->getUserInfo($uid);
			if (!$user) {
				throw new ValidateException('没有此会员');
			}
			/** @var StoreCouponUserServices $storeCoupon */
			$storeCoupon = app()->make(StoreCouponUserServices::class);
			$user['couponCount'] = $storeCoupon->getUserValidCouponCount((int)$uid);
			$data['user'] = $user;
			//用户等级
			$levelInfo = $this->getUerLevelInfoByUid($uid);
			//经验
			/** @var UserBillServices $userBillservices */
			$userBillservices = app()->make(UserBillServices::class);
			$today_exp = $userBillservices->getExpSum($uid, 'today');
			$user_integral = $userBillservices->countIntegralBalance((int)$user['integral'], (int)$uid);
			$user['integral'] = (int)max($user_integral, 0);
			//签到
			/** @var UserSignServices $userSignServices */
			$userSignServices = app()->make(UserSignServices::class);
			$sign_count = $userSignServices->getSignSumDay($uid);
			//看付费会员是否开启
			$user['is_open_member'] = $user['svip_open'] = !!(sys_config('member_card_status', 0));
			/** @var UserMoneyServices $userMoneyServices */
			$userMoneyServices = app()->make(UserMoneyServices::class);
			$user['orderStatusSum'] = (float)$userMoneyServices->sum(['uid' => $uid, 'pm' => 0, 'status' => 1], 'number', true);
		}
        /** @var SystemUserLevelServices $systemUserLevel */
        $systemUserLevel = app()->make(SystemUserLevelServices::class);
        $levelList = $systemUserLevel->getList(['is_del' => 0, 'is_show' => 1]);
        $i = 0;
        foreach ($levelList as &$level) {
            $level['next_exp_num'] = $levelList[$i + 1]['exp_num'] ?? $level['exp_num'];
            $level['image'] = set_file_url($level['image']);
            $level['icon'] = set_file_url($level['icon']);
            $i++;
        }
        $data['level_list'] = $levelList;
        if (!$levelInfo) {//不存在等级 展示最低等级
            /** @var SystemUserLevelServices $systemUserLevel */
            $systemUserLevel = app()->make(SystemUserLevelServices::class);
            $allLevelInfo = $systemUserLevel->getList([['is_del', '=', 0], ['is_show', '=', 1]], 'id,name,icon,grade', 1, 1);
            $levelInfo = $allLevelInfo[0] ?? [];

			$levelInfo['id'] = 0;
			$levelInfo['grade'] = 0;
        }
        if ($levelInfo) {
            $levelInfo['vip'] = true;
            $levelInfo['vip_id'] = $levelInfo['id'] ?? 0;
            $levelInfo['vip_icon'] = $levelInfo['icon'] ?? '';
            $levelInfo['vip_name'] = $levelInfo['name'] ?? '';
        }
        $data['level_info'] = $levelInfo;
        $i = 0;
        foreach ($levelList as &$level) {
            if ($level['grade'] < $levelInfo['grade']) {
                $level['next_exp_num'] = $levelList[$i + 1]['exp_num'] ?? $level['exp_num'];
                $level['lack_exp_num'] = 0;
            } else {
                $level['next_exp_num'] = $level['exp_num'];
                if (($user['exp'] ?? 0) > $level['exp_num']) {
                    $level['lack_exp_num'] = 0;
                } else {
                    $level['lack_exp_num'] = bcsub((string)$level['exp_num'], (string)($user['exp'] ?? 0), 0);
                }
            }

            $level['image'] = set_file_url($level['image']);
            $level['icon'] = set_file_url($level['icon']);
            $i++;
        }
        $data['level_list'] = $levelList;
        $data['level_info']['exp'] = $user['exp'] ?? 0;
        $data['level_info']['today_exp'] = $today_exp;
        $task = [];
        $task['sign_count'] = $sign_count;
        $config = SystemConfigService::more(['sign_give_exp', 'order_give_exp', 'invite_user_exp']);
        $task['sign'] = $config['sign_give_exp'] ?? 0;
        $task['order'] = $config['order_give_exp'] ?? 0;
        $task['invite'] = $config['invite_user_exp'] ?? 0;
        $data['task'] = $task;

        //检测升级
        UserLevelJob::dispatch([$uid]);
        return $data;
    }

    /**
     * 经验列表
     * @param int $uid
     * @return array
     */
    public function expList(int $uid)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserCacheInfo($uid);
        if (!$user) {
            throw new ValidateException('没有此用户');
        }
        /** @var UserBillServices $userBill */
        $userBill = app()->make(UserBillServices::class);
        $data = $userBill->getExpList($uid, [], 'id,title,number,pm,add_time');
        $list = $data['list'] ?? [];
        return $list;
    }

	/**
	 * 获取激活会员卡礼包
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getActivateGift()
	{
		//商城会员是否开启
		if (!sys_config('member_func_status')) {
			return [];
		}
		//是否需要激活
		if (!sys_config('level_activate_status')) {
			return [];
		}
		//获取激活送好礼
		$data = SystemConfigService::more([
			'level_integral_status',
			'level_give_integral',
			'level_money_status',
			'level_give_money',
			'level_coupon_status',
			'level_give_coupon',
		]);
		$ids = $data['level_give_coupon'] ?? [];
		$data['level_give_coupon'] = [];
		if ($data['level_coupon_status'] && $ids) {
			/** @var StoreCouponIssueServices $couponServices */
			$couponServices = app()->make(StoreCouponIssueServices::class);
			$coupon = $couponServices->getList(['id' => $ids]);
			$data['level_give_coupon'] = $coupon;
		}
		$data['level_give_coupon_price'] = 0;
		if ($data['level_give_coupon']) {
			$data['level_give_coupon_price'] = array_sum(array_column($data['level_give_coupon'], 'coupon_price'));
		}
		if (!$data['level_integral_status']) {
			$data['level_give_integral'] = 0;
		}
		if (!$data['level_money_status']) {
			$data['level_give_money'] = 0;
		}
		return $data;
	}

    /**
     * 获取激活会员卡需要的信息
     * @return mixed
     */
    public function getActivateInfo()
    {
		$data = ['activate_info' => [], 'activate_gift' => []];
        //商城会员是否开启
        if (!sys_config('member_func_status')) {
//            throw new ValidateException('会员卡功能暂未开启');
			return $data;
        }
        //是否需要激活
        if (!sys_config('level_activate_status')) {
//            throw new ValidateException('会员卡功能暂不需要激活');
			return $data;
        }
		$data['activate_info'] = SystemConfigService::get('level_extend_info');
		$data['activate_gift'] = $this->getActivateGift();
        return $data;
    }

    /**
     * 激活会员卡
     * @param int $uid
     * @param array $data
     * @return array
     */
    public function userActivatelevel(int $uid, array $data)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserInfo($uid);
        if (!$user) {
            throw new ValidateException('用户已注销，或不存在');
        }
        if ($user['level_status']) {
            throw new ValidateException('不需要重复激活');
        }
		$update = ['level_status' => 1];
		if ($data) {
			$extend_info = $userServices->handelExtendInfo($data, true) ?: [];
			if ($extend_info) {
				$default = $userServices->defaultExtendInfo;
				$params = array_column($default, 'param');
				$sex = $userServices->sex;
				$update['level_extend_info'] = $extend_info;
				foreach ($extend_info as $info) {
					if (isset($info['param']) && in_array($info['param'], $params) && isset($info['value'])) {
						if ($info['param'] == 'sex') {
							$update['sex'] = $sex[$info['value']] ?? 0;
						} elseif ($info['param'] == 'birthday') {
							$update['birthday'] = strtotime($info['value']);
						} elseif($info['param'] == 'address') {
							$update['addres'] = $info['value'];
						} else {
							$update[$info['param']] = $info['value'];
						}
					}
				}
			}
		}
        $userServices->update($uid, $update);
        //获取激活送好礼
		$data = $this->getActivateGift();
        //激活会员卡事件
        event('user.activate.level', [$uid]);
        return $data;
    }
}
