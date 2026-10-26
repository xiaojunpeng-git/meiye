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
namespace app\services\message\service;


use app\dao\message\service\StoreServiceDao;
use app\services\BaseServices;
use app\services\user\UserServices;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;

/**
 * 客服
 * Class StoreServiceServices
 * @package app\services\message\service
 * @mixin StoreServiceDao
 */
class StoreServiceServices extends BaseServices
{
    use ServicesTrait;

	/**
	 * @param StoreServiceDao $dao
	 */
    public function __construct(StoreServiceDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 根据uid获取客服信息
	 * @param int $uid
	 * @param bool $isThrow
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getServiceInfoByUid(int $uid, bool $isThrow = false)
	{
		$serviceInfo = [];
		if ($uid) {
			$where = ['uid' => $uid, 'account_status' => 1];
			$serviceInfo = $this->dao->get($where);
			if (!$serviceInfo) {//抛出错误
				$serviceInfo = [];
				if ($isThrow) throw new ValidateException('客服不存在');
			} else {
				$serviceInfo = $serviceInfo->toArray();
			}
		}
		return $serviceInfo;
	}

    /**
     * 获取客服列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getServiceList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getServiceList($where, $page, $limit);
		$prefix = config('admin.kefu_prefix');
        foreach ($list as &$item) {
            if (!isset($item['workMember'])) {
                $item['workMember'] = [];
            }
			if (isset($item['wx_name']) && !isset($item['nickname'])) {
				$item['nickname'] = $item['wx_name'];
			}
			$item['prefix'] = $prefix;
        }
        $this->updateNonExistentService(array_column($list, 'uid'));
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * @param array $uids
     * @return bool
     */
    public function updateNonExistentService(array $uids = [])
    {
        if (!$uids) {
            return true;
        }
        /** @var UserServices $services */
        $services = app()->make(UserServices::class);
        $userUids = $services->getColumn([['uid', 'in', $uids]], 'uid');
        $unUids = array_diff($uids, $userUids, [0]);
        return $this->dao->deleteNonExistentService($unUids);
    }

	/**
	 * 创建客服表单
	 * @param array $formData
	 * @return array
	 */
    public function createServiceForm(array $formData = [])
    {
        if ($formData) {
            $field[] = Form::frameImage('avatar', '客服头像', $this->url(config('admin.admin_prefix') . '/widget.images/index', ['fodder' => 'avatar'], true), $formData['avatar'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]);
        } else {
            $field[] = Form::frameImage('image', '商城用户', $this->url(config('admin.admin_prefix') . '/system.user/list', ['fodder' => 'image'], true))->icon('ios-add')->width('960px')->height('550px')->modal(['footer-hide' => true])->Props(['srcKey' => 'image']);
            $field[] = Form::hidden('uid', 0);
            $field[] = Form::hidden('avatar', '');
        }
        $field[] = Form::input('nickname', '客服名称', $formData['nickname'] ?? '')->col(24)->required();
        $field[] = Form::input('phone', '手机号码', $formData['phone'] ?? '')->col(24)->required();
        if ($formData) {
            $field[] = Form::input('account', '客服账号', $formData['account'] ?? '')->col(24)->required();
            $field[] = Form::input('password', '客服密码')->type('password')->col(24);
            $field[] = Form::input('true_password', '确认密码')->type('password')->col(24);
        } else {
            $field[] = Form::input('account', '客服账号')->col(24)->required();
            $field[] = Form::input('password', '客服密码')->type('password')->col(24)->required();
            $field[] = Form::input('true_password', '确认密码')->type('password')->col(24)->required();
        }
        $field[] = Form::switches('account_status', '账号状态', (int)($formData['account_status'] ?? 0))->appendControl(1, [
            Form::switches('status', '客服状态', (int)($formData['status'] ?? 0))->falseValue(0)->trueValue(1)->openStr('打开')->closeStr('关闭')->size('large'),
            Form::switches('customer', '手机订单管理', $formData['customer'] ?? 0)->falseValue(0)->trueValue(1)->openStr('打开')->closeStr('关闭')->size('large'),
            Form::switches('notify', '订单通知', $formData['notify'] ?? 0)->falseValue(0)->trueValue(1)->openStr('打开')->closeStr('关闭')->size('large'),
        ])->falseValue(0)->trueValue(1)->openStr('开启')->closeStr('关闭')->size('large');
        return $field;
    }

	/**
	 * 创建客服获取表单
	 * @return mixed
	 */
    public function create()
    {
        return create_form('添加客服', $this->createServiceForm(), $this->url('/app/wechat/kefu'), 'POST');
    }

	/**
	 * 编辑获取表单
	 * @param int $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function edit(int $id)
    {
        $serviceInfo = $this->dao->get($id);
        if (!$serviceInfo) {
            throw new AdminException('数据不存在!');
        }
        return create_form('编辑客服', $this->createServiceForm($serviceInfo->toArray()), $this->url('/app/wechat/kefu/' . $id), 'PUT');
    }

    /**
     * 获取某人的聊天记录用户列表
     * @param int $uid
     * @return array|array[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getChatUser(int $uid)
    {
        /** @var StoreServiceLogServices $serviceLog */
        $serviceLog = app()->make(StoreServiceLogServices::class);
        /** @var UserServices $serviceUser */
        $serviceUser = app()->make(UserServices::class);
        $uids = $serviceLog->getChatUserIds($uid);
        if (!$uids) {
            return [];
        }
        return $serviceUser->getUserList(['uid' => $uids], 'nickname,uid,avatar as headimgurl');
    }

    /**
     * 检查用户是否是客服
     * @param array $where
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkoutIsService(array $where)
    {
        return (bool)$this->dao->count($where);
    }

    /**
     * 查询聊天记录和获取客服uid
     * @param int $uid 当前用户uid
     * @param int $uidTo 上翻页id
     * @param int $limit 展示条数
     * @param int $toUid 客服uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRecord(int $uid, int $uidTo, int $limit = 10, int $toUid = 0)
    {
        if (!$toUid) {
            $serviceInfoList = $this->getServiceList(['noId' => [$uid], 'status' => 1, 'account_status' => 1, 'online' => 1]);
            if (!count($serviceInfoList)) {
                throw new ValidateException('暂无客服人员在线，请稍后联系');
            }
            $uids = array_column($serviceInfoList['list'], 'uid');
            if (!$uids) {
                throw new ValidateException('暂无客服人员在线，请稍后联系');
            }
            /** @var StoreServiceRecordServices $recordServices */
            $recordServices = app()->make(StoreServiceRecordServices::class);
            //上次聊天客服优先对话
            $toUid = $recordServices->getLatelyMsgUid(['to_uid' => $uid], 'user_id');
            //如果上次聊天的客不在当前客服中从新
            if (!in_array($toUid, $uids)) {
                $toUid = 0;
            }
            if (!$toUid) {
                mt_srand();
                $toUid = $uids[array_rand($uids)] ?? 0;
            }
            if (!$toUid) {
                throw new ValidateException('暂无客服人员在线，请稍后联系');
            }
        }
        $userInfo = $this->dao->get(['uid' => $toUid], ['nickname', 'avatar']);
        if (!$userInfo) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userInfo = $userServices->get(['uid' => $toUid], ['nickname', 'avatar']);
            if (!$userInfo) {
                $userInfo['nickname'] = '';
                $userInfo['avatar'] = '';
            }
        }
        /** @var StoreServiceLogServices $logServices */
        $logServices = app()->make(StoreServiceLogServices::class);
        $result = ['serviceList' => [], 'uid' => $toUid, 'nickname' => $userInfo['nickname'], 'avatar' => $userInfo['avatar']];
        $serviceLogList = $logServices->getServiceChatList(['chat' => [$uid, $toUid], 'is_tourist' => 0], $limit, $uidTo);
        $result['serviceList'] = array_reverse($logServices->tidyChat($serviceLogList));
        return $result;
    }
}
