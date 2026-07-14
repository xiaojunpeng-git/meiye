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
namespace app\controller\cashier;

use app\jobs\system\SocketPushJob;
use app\jobs\user\UserBelongStoreJob;
use app\Request;
use app\services\cashier\UserServices;
use app\services\order\OtherOrderServices;
use app\services\store\StoreStaffScheduleServices;
use app\services\store\StoreStaffShiftHandoverServices;
use app\services\store\StoreUserServices;
use app\services\order\StoreCartServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\member\MemberCardServices;
use app\services\user\UserCardHolderServices;
use mohe\services\AliPayService;
use mohe\services\CacheService;
use mohe\services\SystemConfigService;
use mohe\services\wechat\Payment;

/**
 * 收银台用户
 * Class User
 * @package app\controller\cashier
 */
class User extends AuthController
{
    /**
     * 修改收银员信息
     * @param Request $request
     * @param SystemStoreStaffServices $services
     * @return mixed
     */
    public function updatePwd(Request $request, SystemStoreStaffServices $services)
    {
        $data = $request->postMore([
            ['real_name', ''],
            ['pwd', ''],
            ['new_pwd', ''],
            ['conf_pwd', ''],
            ['avatar', ''],
        ]);
        if ($data['pwd'] && !preg_match('/^(?![^a-zA-Z]+$)(?!\D+$).{6,}$/', $data['new_pwd'])) {
            return $this->fail('设置的密码过于简单(不小于六位包含数字字母)');
        }
        if ($services->updateStaffPwd($this->cashierId, $data))
            return $this->success('修改成功');
        else
            return $this->fail('修改失败');
    }

    /**
     * 获取登录店员详情
     * @return mixed
     */
    public function getCashierInfo()
    {
        return $this->success($this->cashierInfo);
    }

	/**
	 * 收银台筛选客户
	 * @param Request $request
	 * @param \app\services\user\UserServices $userServices
	 * @param StoreCartServices $cartServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function searchUserInfo(Request $request, \app\services\user\UserServices $userServices, StoreCartServices $cartServices)
	{
		$search = $request->post('search', '');
		$uid = $request->post('uid', '');
		if (!$search && !$uid) {
			return $this->fail('缺少参数');
		}
		$field = ['uid', 'avatar', 'phone', 'bar_code', 'nickname', 'now_money', 'integral', 'level', 'is_money_level', 'is_ever_level', 'overdue_time'];
		$userInfo = [];
		if ($uid) {
			$userInfo = $userServices->getUserInfo($uid, $field);
		} elseif ($search) {
			$userInfo = $userServices->getOne(['uid|phone|bar_code|uniqid' => $search], implode(',', $field));
			if (!$userInfo) {//没搜到用户 用付款码搜索
				$userInfo = $userServices->authCodeToUserInfo($search);
			}
		}
		if ($userInfo) {
			$userInfo = $userInfo->toArray();
			$userInfo['vip_name'] = '';
			if ($userInfo['level']) {
				/** @var SystemUserLevelServices $levelServices */
				$levelServices = app()->make(SystemUserLevelServices::class);
				$levelInfo = $levelServices->getOne(['id' => $userInfo['level']], 'id,name');
				$userInfo['vip_name'] = $levelInfo['name'] ?? '';
			}
			$userInfo['overdue_time'] = date('Y-m-d H:i:s', $userInfo['overdue_time']);
		} else {
			$userInfo = [];
		}

		$cart = $request->post('cart', []);
		if ($cart) {
			$cartServices->batchAddCart($cart, $this->storeId, $userInfo['uid'] ?? 0);
		}
		$is_user = !!$userInfo;
		$user_code = $is_user && $search == $userInfo['bar_code'];
		if ($user_code) {
			$code_type = 'user';
		} else {
			$code_type = Payment::isWechatAuthCode($search) ? 'weixin' : (AliPayService::isAliPayAuthCode($search) ? 'alipay' : '');
		}
		return $this->success(['search_user' => $is_user, 'user_code' => $user_code, 'code_type' => $code_type, 'user_info' => $userInfo]);
	}

	/**
	 * 收银台注册添加会员
	 * @param Request $request
	 * @param \app\services\user\UserServices $userServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function saveUser(Request $request, \app\services\user\UserServices $userServices)
	{
		$data = $request->postMore([
			['phone', 0],//手机号
			['nickname', ''],//昵称
			[['uid', 'd'], 0],//用户ID
		]);
		$uid = (int)$data['uid'];
		unset($data['uid']);
		if (!$data['phone']) {
			return $this->fail('请输入会员手机号');
		}
		if (!check_phone($data['phone'])) {
			return $this->fail('手机号码格式不正确');
		}
		$user = $userServices->get(['phone' => $data['phone']]);
		if ($user && (($uid && $user['uid'] != $uid) || !$uid)) {
			return $this->fail('手机号已经存在不能添加相同的手机号用户');
		}
		if (!$data['nickname']) {
			$data['nickname'] = substr_replace($data['phone'], '****', 3, 4);
		}
		$data['adminId'] = $this->cashierId;
		if ($uid) {
			$isNew = false;
			$userServices->update($uid, $data);
		} else {
			$isNew = true;
			$data['avatar'] = sys_config('h5_avatar');
			$data['user_type'] = 'cashier';
			$userInfo = $userServices->save($data);
			if (!$userInfo) {
				return $this->fail('保存用户失败');
			}
			event('user.create', $data);
			$uid = (int)$userInfo['uid'];
		}
		/** @var StoreUserServices $storeUserServices */
		$storeUserServices = app()->make(StoreUserServices::class);
		$storeUserServices->setStoreUser($uid, (int)$this->storeId);
		//记录用户归属门店
		UserBelongStoreJob::dispatch([$uid, (int)$this->storeId, 'admin', (int)$this->cashierId]);
		event('user.register', [$userServices->get($uid), $isNew, 0]);
		$userInfo = $userServices->getUserInfo($uid, ['uid', 'avatar', 'phone', 'nickname', 'now_money', 'integral', 'level', 'is_money_level', 'is_ever_level', 'overdue_time']);
		if (!$userInfo) {
			return $this->fail('保存用户失败');
		}
		return $this->success('保存成功', $userInfo->toArray());
	}

	/**
	 * 修改用户信息
	 * @param Request $request
	 * @param \app\services\user\UserServices $userServices
	 * @param $uid
	 * @return mixed
	 */
	public function updateUser(Request $request, \app\services\user\UserServices $userServices, $uid)
	{
		$uid = (int)$uid;
		if (!$uid) {
			return $this->fail('缺少参数');
		}
		$data = $request->postMore([
			['real_name', ''],//真实姓名
			['nickname', ''],//真实姓名
			['phone', ''],//手机号码
			['birthday', ''],//生日
			['sex', 0],//性别
			['card_id', ''],//身份证号
			['addres', ''],//地址
			['mark', ''],//备注
		]);
		$userInfo = $userServices->getUserInfo($uid);
		if (!$userInfo) {
			return $this->fail('用户不存在或已注销');
		}
		if ($data['phone']) {
			if (!check_phone($data['phone'])) return $this->fail('手机号码格式不正确');
		}
		if ($data['card_id']) {
			try {
				if (!check_card($data['card_id'])) return $this->fail('请输入正确的身份证');
			} catch (\Throwable $e) {
//				return $this->fail('请输入正确的身份证');
			}
		}
		if ($data['birthday']) {
			if (strtotime($data['birthday']) > time()) return $this->fail('生日请选择今天之前日期');
			$data['birthday'] = strtotime($data['birthday']);
		}
		if (!in_array($data['sex'], array_keys($userServices->sexName))) {
			return $this->fail('请选择正确的性别');
		}
		$userServices->update($uid, $data);
		return $this->success('保存成功');
	}

    /**
     * 收银台选择用户列表
     * @param Request $request
     * @param UserServices $services
     * @return mixed
     */
    public function getUserList(Request $request, StoreUserServices $storeUserservices, \app\services\user\UserServices $services)
    {
        $data = $request->getMore([
            ['keyword', ''],
            ['field_key', '']
        ]);
        if ($data['keyword']) {
            if ($data['field_key'] == 'all') {
                $data['field_key'] = '';
            }
            if ($data['field_key'] && in_array($data['field_key'], ['uid', 'phone', 'bar_code'])) {
                $where[$data['field_key']] = trim($data['keyword']);
            } else {
                $where['store_like'] = trim($data['keyword']);
            }
            $where['is_filter_del'] = 1;
            $list = $services->getUserList($where);
            if (isset($list['list']) && $list['list']) {
                foreach ($list['list'] as &$item) {
                    //用户类型
					$item['user_type'] = $services->userTypeName[$item['user_type']] ?? '其他';
                }
            }
            return $this->success($list);
        } else {
            return app('json')->success($storeUserservices->index($data, $this->storeId));
        }
    }

	/**
	 * 获取当前门店所有店员信息
	 * @param Request $request
	 * @param SystemStoreStaffServices $services
	 * @return mixed
	 */
	public function getALlStaffList(Request $request, SystemStoreStaffServices $services)
	{
		$where['store_id'] = $this->storeId;
		$where['is_del'] = 0;
		$where['status'] = 1;
		/** @var StoreStaffScheduleServices $scheduleServices */
		$scheduleServices = app()->make(StoreStaffScheduleServices::class);
		if (!$scheduleServices->isScheduleManageEnabled()) {
			$where['is_reservable'] = 1;
		}
		return $this->success($services->getSelectList($where));
	}

    public function getStaffAll(Request $request, SystemStoreStaffServices $services)
    {
        $where = $request->postMore([
            ['keyword', ''],
            ['name', ''],
            ['for_reservation', 0],
            ['service_date', ''],
            ['service_time', ''],
            ['reservation_start', ''],
            [['service_duration', 'd'], 0],
            [['exclude_reservation_id', 'd'], 0],
        ]);
        $forReservation = (int)($where['for_reservation'] ?? 0);
        $serviceDate = trim((string)($where['service_date'] ?? ''));
        $serviceTime = trim((string)($where['service_time'] ?? ''));
        if (!$serviceTime) {
            $serviceTime = trim((string)($where['reservation_start'] ?? ''));
        }
        $serviceDuration = (int)($where['service_duration'] ?? 0);
        $excludeReservationId = (int)($where['exclude_reservation_id'] ?? 0);
        unset($where['for_reservation'], $where['service_date'], $where['service_time'], $where['reservation_start'], $where['service_duration'], $where['exclude_reservation_id']);
        $where['store_id'] = $this->storeId;
        $where['is_del'] = 0;
        $where['status'] = 1;
        if ($forReservation) {
            $list = $services->getReservationStaffList((int)$this->storeId, [
                'keyword' => trim((string)($where['keyword'] ?? $where['name'] ?? '')),
                'service_date' => $serviceDate,
                'service_time' => $serviceTime,
                'service_duration' => $serviceDuration,
                'exclude_reservation_id' => $excludeReservationId,
            ]);
            return app('json')->success($list);
        }
        $list = $services->geAllList($where);
        return app('json')->success($list);
    }

    /**
     * 获取当前门店店员列表和店员信息
     * @param Request $request
     * @param SystemStoreStaffServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCashierList(Request $request, SystemStoreStaffServices $services)
    {
        $where = $request->getMore([
            ['keyword', '']
        ]);
        $where['store_id'] = $this->storeId;
        $where['is_del'] = 0;
        $where['status'] = 1;
        return $this->success([
            'staffInfo' => $request->cashierInfo(),
            'staffList' => $services->getStoreStaff($where),
            'count' => $services->count($where)
        ]);
    }

    /**
     * 游客切换到用户
     * @param Request $request
     * @param StoreCartServices $services
     * @param $cashierId
     * @return mixed
     */
    public function switchCartUser(Request $request, StoreCartServices $services, $cashierId)
    {
        [$uid, $toUid, $isTourist] = $request->postMore([
            ['uid', 0],
            ['to_uid', 0],
            ['is_tourist', 0]
        ], true);
        if ($isTourist && $uid) {
            $where = ['tourist_uid' => $uid, 'store_id' => $this->storeId, 'staff_id' => $cashierId];
            $touristCart = $services->getCartList($where);
            if ($touristCart) {
                $userWhere = ['uid' => $toUid, 'store_id' => $this->storeId, 'staff_id' => $cashierId];
                $userCarts = $services->getCartList($userWhere);
                if ($userCarts) {
                    foreach ($touristCart as $cart) {
                        foreach ($userCarts as $userCart) {
                            //游客商品 存在用户购物车商品中
                            if ($cart['product_id'] == $userCart['product_id'] && $cart['product_attr_unique'] == $userCart['product_attr_unique']) {
                                //修改用户商品数量 删除游客购物车这条数据
                                $services->update(['id' => $userCart['id']], ['cart_num' => bcadd((string)$cart['cart_num'], (string)$userCart['cart_num'])]);
                                $services->delete(['id' => $cart['id']]);
                            }
                        }
                    }
                }
                //发送消息
				SocketPushJob::dispatch([$this->cashierId, 'changCart', ['uid' => $uid], 'cashier']);

            }
            $services->update($where, ['uid' => $toUid, 'tourist_uid' => '']);
        }
        return $this->success('修改成功');
    }

    /**
     * 用户信息
     * @param Request $request
     * @param \app\services\user\UserServices $services
     * @param StoreCartServices $cartServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserInfo(Request $request, \app\services\user\UserServices $services, StoreCartServices $cartServices)
    {
        $code = $request->post('code', '');
        $uid = $request->post('uid', '');
        if (!$code && !$uid) {
            return $this->fail('缺少参数');
        }
        $field = ['uid', 'avatar', 'phone', 'nickname','ben_money','give_money','now_money', 'integral', 'level', 'is_money_level', 'is_ever_level', 'overdue_time'];
		$userInfo = [];
        if ($uid) {
            $userInfo = $services->getUserWithTrashedInfo($uid,$field);
        } elseif ($code) {
            $userInfo = $services->get(['uniqid' => $code], $field);
        }
		if ($userInfo) {
			$userInfo = $userInfo->toArray();
			$userInfo['vip_name'] = '';
            $maxPer=SystemConfigService::get('recharge_per');
            $userInfo['recharge_per']=bcdiv($maxPer,100,2);
			if ($userInfo['level']) {
				/** @var SystemUserLevelServices $levelServices */
				$levelServices = app()->make(SystemUserLevelServices::class);
				$levelInfo = $levelServices->getOne(['id' => $userInfo['level']], 'id,name');
				$userInfo['vip_name'] = $levelInfo['name'] ?? '';
			}
			$userInfo['overdue_time'] = date('Y-m-d H:i:s', $userInfo['overdue_time']);
		} else {
			$userInfo = [];
		}

        $cart = $request->post('cart', []);
        if ($cart) {
            $cartServices->batchAddCart($cart, $this->storeId, $userInfo['uid'] ?? 0);
        }

        return $this->success($userInfo);
    }

    /**
     * 收银台获取当前用户信息
     * @param \app\services\user\UserServices $userServices
     * @param $uid
     * @return mixed
     */
    public function getUidInfo(\app\services\user\UserServices $userServices, $uid)
    {
        return $this->success($userServices->read((int)$uid));
    }

    /**
     * 收银台用户记录
     * @param Request $request
     * @param \app\services\user\UserServices $userServices
     * @param $uid
     * @return mixed
     */
    public function userRecord(Request $request, \app\services\user\UserServices $userServices, $uid)
    {
        $type = $request->get('type', '');
        return $this->success($userServices->oneUserInfo((int)$uid, $type, $this->storeId));
    }

    //修改余额和本金
    public function changeMoney(Request $request, \app\services\user\UserServices $userServices){
           $data = $request->postMore([
               ['ben_money', 0],
               ['give_money', 0],
               ['type',0],
               ['uid', 0],
               ['mark', '']
           ]);
           $data['adminId']=$this->cashierId;
           return $this->success($userServices->changeMoney($data) ? '修改成功' : '修改失败');
    }
    /**
     * 切换用户、用户切换到其他用户、用户切换到游客
     * @param Request $request
     * @return mixed
     */
    public function switchUser(Request $request)
    {
        $uid = $request->post('uid', 0);
        $touristUid = $request->post('tourist_uid', 0);
        $cashierId = $request->post('cashier_id', 0);
        $changePrice = $request->post('change_price', -1);
        $changCartRemove = $request->post('chang_cart_remove', -1);

        $res = CacheService::redisHandler()->get('aux_screen_' . $this->cashierId);

        if ($uid) {

            if ($res && is_array($res)) {
                $res['uid'] = $uid;
                $res['tourist'] = false;
                $res['tourist_uid'] = 0;
                CacheService::redisHandler(CacheService::CASHIER_AUX_SCREEN_TAG . '_' . $this->storeId)
                    ->set('aux_screen_' . $this->cashierId, $res);
            } else {
                //游客切换到用户。或者用户之间切换
                CacheService::redisHandler(CacheService::CASHIER_AUX_SCREEN_TAG . '_' . $this->storeId)
                    ->set('aux_screen_' . $this->cashierId, [
                        'uid' => $uid,
                        'cashier_id' => 0,
                        'tourist_uid' => 0,
                        'tourist' => false
                    ]);
            }

            //发送消息
			SocketPushJob::dispatch([$this->cashierId, 'changUser', ['uid' => $uid], 'cashier']);

        } else if ($touristUid) {
            if ($res && is_array($res)) {
                $res['tourist_uid'] = $touristUid;
                $res['tourist'] = true;
                $res['uid'] = 0;
                CacheService::redisHandler(CacheService::CASHIER_AUX_SCREEN_TAG . '_' . $this->storeId)
                    ->set('aux_screen_' . $this->cashierId, $res);
            } else {
                //用户切换到游客
                CacheService::redisHandler(CacheService::CASHIER_AUX_SCREEN_TAG . '_' . $this->storeId)
                    ->set('aux_screen_' . $this->cashierId, [
                        'uid' => 0,
                        'cashier_id' => 0,
                        'tourist_uid' => $touristUid,
                        'tourist' => true
                    ]);
            }

            //发送消息
			SocketPushJob::dispatch([$this->cashierId, 'changUser', ['tourist_uid' => $touristUid], 'cashier']);

        } else if ($cashierId) {
            //切换店员
            if ($res && is_array($res)) {
                $res['cashier_id'] = $cashierId;
                CacheService::redisHandler(CacheService::CASHIER_AUX_SCREEN_TAG . '_' . $this->storeId)
                    ->set('aux_screen_' . $this->cashierId, $res);
            } else {
                CacheService::redisHandler(CacheService::CASHIER_AUX_SCREEN_TAG . '_' . $this->storeId)
                    ->set('aux_screen_' . $this->cashierId, [
                        'uid' => 0,
                        'cashier_id' => $cashierId,
                        'tourist_uid' => 0,
                        'tourist' => true
                    ]);
            }

            //发送消息
			SocketPushJob::dispatch([$this->cashierId, 'changUser', ['cashier_id' => $cashierId], 'cashier']);

        } else if ($changePrice > 0) {
            //发送消息
			SocketPushJob::dispatch([$this->cashierId, 'changUser', ['change_price' => $changePrice], 'cashier']);

        } else if ($changCartRemove > -1) {
            //发送消息
			SocketPushJob::dispatch([$this->cashierId, 'changCartRemove', [], 'cashier']);

        }

        return $this->success();
    }

    /**
     * 获取副屏用户信息
     * @return mixed
     */
    public function getAuxScreenInfo()
    {
        $res = CacheService::redisHandler()->get('aux_screen_' . $this->cashierId);

        $data = [];
        $key = ['cashier_id' => 0, 'tourist_uid' => 0, 'uid' => 0, 'tourist' => false];
        foreach ($key as $k => $v) {
            $data[$k] = $res[$k] ?? $v;
        }
        return $this->success($data);
    }

    /**
     * 获取会员类型
     * @param Request $request
     * @return mixed
     */
    public function getMemberCard(Request $request)
    {
        [$is_money_level, $overdue_time] = $request->getMore([
            ['is_money_level', 0],
            ['overdue_time', 0],
        ], true);
        /** @var MemberCardServices $memberCardServices */
        $memberCardServices = app()->make(MemberCardServices::class);
        $member_type = $memberCardServices->DoMemberType(0, false);
        if (!$is_money_level) $overdue_time = time();
        foreach ($member_type as $key => &$item) {
            if (!$overdue_time || $item['type'] == 'ever' && $item['vip_day'] == -1) {
                $item['overdue_time'] = '';
            } else {
                $item['overdue_time'] = date('Y-m-d H:i:s', $overdue_time + $item['vip_day'] * 86400);
            }
        }
        return $this->success($member_type);
    }

    /**
     * 购买会员
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function payMember(Request $request)
    {
        [$uid, $price, $memberId, $payType, $authCode] = $request->postMore([
            ['uid', 0],
            ['price', 0],
            ['merber_id', 0],
            [['pay_type', 'd'], 2], //2=用户扫码支付，3=付款码扫码支付, 4=现金支付
            ['auth_code', '']
        ], true);
        if (!(int)$memberId) {
            return $this->fail('缺少购买会员类型ID');
        }
        if (!$authCode && $payType == 3) {
            return $this->fail('缺少付款码二维码CODE');
        }
        if (!$price || $price <= 0) {
            return $this->fail('储值金额不能为0元!');
        }

        $storeMinRecharge = sys_config('store_user_min_recharge');
        if ($price < $storeMinRecharge) return $this->fail('储值金额不能低于' . $storeMinRecharge);
        /** @var OtherOrderServices $OtherOrderServices */
        $OtherOrderServices = app()->make(OtherOrderServices::class);
        $re = $OtherOrderServices->payMember($uid, (int)$memberId, (float)$price, (int)$payType, 'store', $this->cashierInfo, $authCode);
        if ($re) {
            $msg = $re['msg'];
            unset($re['msg']);
            return $this->success($msg, $re);
        }
        return $this->fail('储值失败');
    }

    /**显示指定的资源
     * @param $id
     * @param \app\services\user\UserServices $services
     * @return mixed
     */
    public function read($id, \app\services\user\UserServices $services)
    {
        return $this->success($services->read((int)$id));
    }

	/**
	 * 获取用户信息
	 * @param Request $request
	 * @param $id
	 * @param \app\services\user\UserServices $services
	 * @return mixed
	 */
    public function oneUserInfo(Request $request, $id, \app\services\user\UserServices $services)
    {
        $data = $request->getMore([
            ['type', ''],
        ]);
        $id = (int)$id;
        if ($data['type'] == '') return $this->fail('缺少参数');
        return $this->success($services->oneUserInfo($id, $data['type'], $this->storeId));
    }

    /**
     * 卡项信息
     * @param $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cardHolder($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        /** @var UserCardHolderServices $cardHolderServices */
        $cardHolderServices = app()->make(UserCardHolderServices::class);
        $cardHolder = $cardHolderServices->oneCardHolder((int)$id);
        if(!$cardHolder) return $this->fail('数据有误');
        return $this->success($cardHolder);
    }

    /**
     * 店员交接班时业绩
     * @param StoreStaffShiftHandoverServices $services
     * @param int $staff_id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function shiftHandover(SystemStoreStaffServices $staffServices, StoreStaffShiftHandoverServices $services,int $staff_id = 0)
    {
        if(!$staff_id) return app('json')->fail('参数有误！');
        $staffInfo = $staffServices->getStaffInfo($staff_id);
        if (!$staffInfo) {
            return app('json')->fail('店员不存在！');
        }
        return app('json')->success($services->getStaffShiftHandoverData($this->storeId,$staff_id,$staffInfo['last_time'],time(),true));
    }
}
