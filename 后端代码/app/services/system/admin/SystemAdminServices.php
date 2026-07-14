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

namespace app\services\system\admin;

use app\jobs\system\SocketPushJob;
use app\services\BaseServices;
use app\services\order\StoreOrderServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\product\StoreProductServices;
use app\services\user\UserExtractServices;
use mohe\exceptions\AdminException;
use app\dao\system\admin\SystemAdminDao;
use app\services\system\SystemMenusServices;
use mohe\services\CacheService;
use mohe\services\FormBuilder as Form;
use app\services\system\SystemRoleServices;
use mohe\services\SystemConfigService;
use think\facade\Cache;

/**
 * 管理员service
 * Class SystemAdminServices
 * @package app\services\system\admin
 * @mixin SystemAdminDao
 */
class SystemAdminServices extends BaseServices
{

	/**
	 * @var array|string[]
	 */
	protected $adminTypeName = [
		0 => '平台',
		1 => '门店',
		2 => '供应商',
		3 => '代理商'
	];


    /**
     * SystemAdminServices constructor.
     * @param SystemAdminDao $dao
     */
    public function __construct(SystemAdminDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 管理员登录
     * @param string $account
     * @param string $password
     * @param bool $is_mobile
     * @param int $adminType
     * @return array|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function verifyLogin(string $account, string $password, bool $is_mobile = false, int $adminType = 0)
    {
		$key = 'login_captcha_' . $account;
		/** @var LoginAuthServices $loginAuthServices */
		$loginAuthServices = app()->make(LoginAuthServices::class);
		//验证是否锁定
		$loginAuthServices->checkErrorLock($account, 'admin');
        if ($is_mobile) {
            $adminInfo = $this->dao->phoneByAdmin($account, $adminType);
        } else {
            $adminInfo = $this->dao->accountByAdmin($account, $adminType);
        }
        if (!$adminInfo) {
			Cache::inc($key);
			$loginAuthServices->setErrorNum($account, 'admin');
            throw new AdminException('账号或密码错误，请重新输入!');
        }
        if (!$adminInfo->status) {
			Cache::inc($key);
			$loginAuthServices->setErrorNum($account, 'admin');
            throw new AdminException('您已被禁止登录!');
        }
        if (!$is_mobile && $password && !password_verify($password, $adminInfo->pwd)) {
            Cache::inc($key);
			$loginAuthServices->setErrorNum($account, 'admin');
            throw new AdminException('账号或密码错误，请重新输入');
        }
        $adminInfo->last_time = time();
        $adminInfo->last_ip = app('request')->ip();
        $adminInfo->login_count++;
        $adminInfo->save();

        return $adminInfo;
    }

    /**
     * 后台登录获取菜单获取token
     * @param string $account
     * @param string $password
     * @param string $type
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function login(string $account, string $password, string $type, bool $is_mobile = false, int $adminType = 0)
    {
        $adminInfo = $this->verifyLogin($account, $password, $is_mobile, $adminType);
        $tokenInfo = $this->createToken($adminInfo->id, $type, $adminInfo['pwd']);
        /** @var SystemMenusServices $services */
        $services = app()->make(SystemMenusServices::class);
        [$menus, $uniqueAuth] = $services->getMenusList($adminInfo->roles, (int)$adminInfo['level'], 1, $adminType);
        return [
            'token' => $tokenInfo['token'],
            'expires_time' => $tokenInfo['params']['exp'],
            'menus' => $menus,
            'unique_auth' => $uniqueAuth,
            'user_info' => [
                'id' => $adminInfo['id'],
                'account' => $adminInfo['account'],
				'admin_type' => $adminInfo['admin_type'] ?? 0,
                'head_pic' => $adminInfo['head_pic'],
            ],
            'logo' => sys_config('site_logo', ''),
            'logo_square' => sys_config('site_logo', ''),
            'version' => get_mohe_version(),
            'newOrderAudioLink' => set_file_url(sys_config('new_order_audio_link', '/statics/audio/newOrderAudioLink.mp3')),
			'prefix' => $type == 'agent' ? config('admin.agent_prefix') : config('admin.admin_prefix')
        ];
    }

    /**
     * 获取登录前的login等信息
     * @return array
     */
    public function getLoginInfo()
    {
        $data = SystemConfigService::more(['admin_login_slide', 'site_logo_square', 'site_logo', 'login_logo']);
        return [
            'slide' => sys_config('admin_login_slide') ?? [],
            'logo_square' => $data['site_logo'] ?? '',//透明
            'logo_rectangle' => $data['site_logo'] ?? '',//方形
            'login_logo' => $data['login_logo'] ?? '',//登录
            'version' => get_mohe_version(),
            'upload_file_size_max' => config('upload.filesize'),//文件上传大小kb
        ];
    }

    /**
     * 管理员列表
     * @param array $where
     * @return array
     */
    public function getAdminList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);

        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $allRole = $service->getRoleArray(['type' => $where['admin_type'] ?? 0]);
        foreach ($list as &$item) {
            if ($item['roles']) {
                $roles = [];
                foreach ($item['roles'] as $id) {
                    if (isset($allRole[$id])) $roles[] = $allRole[$id];
                }
                if ($roles) {
                    $item['roles'] = implode(',', $roles);
                } else {
                    $item['roles'] = '';
                }
            }
            $item['_add_time'] = date('Y-m-d H:i:s', $item['add_time']);
            $item['_last_time'] = $item['last_time'] ? date('Y-m-d H:i:s', $item['last_time']) : '';
        }
        return compact('list', 'count');
    }

	/**
	 * 创建管理员表单
	 * @param int $level
	 * @param array $formData
	 * @param int $admin_type
	 * @param int $relation_id
	 * @return array
	 */
    public function createAdminForm(int $level, array $formData = [], int $admin_type = 0, int $relation_id = 0)
    {
		$f = [];
		if ((!$formData || !$formData['uid']) && $admin_type == 3) {//代理商需要关联商城用户
			$f[] = Form::frameImage('image', '商城用户：', $this->url(config('admin.admin_prefix') . '/system.user/list', ['fodder' => 'image'], true))->icon('ios-add')->width('960px')->height('550px')->modal(['footer-hide' => true])->Props(['srcKey' => 'image']);
			$f[] = Form::hidden('uid', 0);
			$f[] = Form::hidden('head_pic', '');
		} else {
			$f[] = Form::frameImage('head_pic', '头像：', $this->url(config('admin.admin_prefix') . '/widget.images/index', ['fodder' => 'head_pic'], true), $formData['head_pic'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]);
		}

        $f[] = Form::input('account', '管理员账号：', $formData['account'] ?? '')->required('请填写管理员账号');
        if ($formData) {
            $f[] = Form::input('pwd', '管理员密码：')->type('password')->placeholder('不修改密码请留空');
            $f[] = Form::input('conf_pwd', '确认密码：')->type('password')->placeholder('不修改密码请留空');
        } else {
            $f[] = Form::input('pwd', '管理员密码：')->type('password')->required('请填写管理员密码');
            $f[] = Form::input('conf_pwd', '确认密码：')->type('password')->required('请输入确认密码');
        }

        $f[] = Form::input('real_name', '管理员姓名：', $formData['real_name'] ?? '')->required('请输入管理员姓名');
        $f[] = Form::input('phone', '管理员电话：', $formData['phone'] ?? '')->required('请输入管理员电话');

        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $options = $service->getRoleFormSelect($level, $admin_type, $relation_id);
        $roles = [];
        if ($formData && ($formData['roles'] ?? [])) {
            foreach ($formData['roles'] as $role) {
                $roles[] = (int)$role;
            }
        }
        if ($level) {
            $f[] = Form::select('roles', '管理员身份：', $roles)->setOptions(Form::setOptions($options))->multiple(true)->required('请选择管理员身份');
        }
        $f[] = Form::radio('status', '状态：', $formData['status'] ?? 1)->options([['label' => '开启', 'value' => 1], ['label' => '关闭', 'value' => 0]]);
        return $f;
    }

	/**
	 * 添加管理员form表单获取
	 * @param int $level
	 * @param string $url
	 * @param int $admin_type
	 * @param int $relation_id
	 * @return mixed
	 */
    public function createForm(int $id, int $level, string $url = '/setting/admin', int $admin_type = 0, int $relation_id = 0)
    {
		$adminInfo = [];
		if ($id) {//编辑
			$adminInfo = $this->dao->get($id);
			if (!$adminInfo) {
				throw new AdminException('管理员不存在!');
			}
			if ($adminInfo->is_del) {
				throw new AdminException('管理员已经删除');
			}
			$adminInfo = $adminInfo->toArray();
		}
        return create_form('管理员添加', $this->createAdminForm($level, $adminInfo, $admin_type, $relation_id), $this->url($url), $id ? 'PUT' : 'POST');
    }

	/**
	 * 保存管理员信息
	 * @param int $id
	 * @param array $data
	 * @param int $admin_type
	 * @return bool|mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function saveData(int $id, array $data, int $admin_type = 0)
    {
		if ($id) {//修改
			if (!$adminInfo = $this->dao->get($id)) {
				throw new AdminException('管理员不存在,无法修改');
			}
			if ($adminInfo->is_del) {
				throw new AdminException('管理员已经删除');
			}
			//修改密码
			if ($data['pwd']) {

				if (!$data['conf_pwd']) {
					throw new AdminException('请输入确认密码');
				}

				if ($data['conf_pwd'] != $data['pwd']) {
					throw new AdminException('上次输入的密码不相同');
				}
				$adminInfo->pwd = $this->passwordHash($data['pwd']);
			}
			//修改账号
			if (isset($data['account']) && $data['account'] != $adminInfo->account && $this->dao->isAccountUsable($data['account'], $id)) {
				throw new AdminException('管理员账号已存在');
			}
			if (isset($data['phone']) && $data['phone'] != $adminInfo->phone && $this->dao->count(['phone' => $data['phone'], 'admin_type' => $admin_type, 'is_del' => 0])) {
				throw new AdminException('管理员电话已存在');
			}
			if (isset($data['roles'])) {
				$adminInfo->roles = implode(',', $data['roles']);
			}
			$adminInfo->uid = $data['uid'] ?? $adminInfo->uid;
			$adminInfo->real_name = $data['real_name'] ?? $adminInfo->real_name;
			$adminInfo->phone = $data['phone'] ?? $adminInfo->phone;
			$adminInfo->account = $data['account'] ?? $adminInfo->account;
			$adminInfo->head_pic = $data['head_pic'] ?? $adminInfo->head_pic;
			$adminInfo->status = $data['status'];
			if ($adminInfo->save()) {
				return true;
			} else {
				return false;
			}
		} else {
			if ($data['conf_pwd'] != $data['pwd']) {
				throw new AdminException('两次输入的密码不相同');
			}
			unset($data['conf_pwd']);

			if ($this->dao->count(['account' => $data['account'], 'admin_type' => $data['admin_type'] ?? 0, 'is_del' => 0])) {
				throw new AdminException('管理员账号已存在');
			}
			if ($this->dao->count(['phone' => $data['phone'], 'admin_type' => $data['admin_type'] ?? 0, 'is_del' => 0])) {
				throw new AdminException('管理员电话已存在');
			}

			$data['pwd'] = $this->passwordHash($data['pwd']);
			$data['add_time'] = time();
			$data['roles'] = implode(',', $data['roles']);

			return $this->transaction(function () use ($data) {
				if ($this->dao->save($data)) {
					return true;
				} else {
					throw new AdminException('添加失败');
				}
			});
		}
    }

    /**
     * 修改当前管理员信息
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function updateAdmin(int $id, array $data)
    {
        $adminInfo = $this->dao->get($id);
        if (!$adminInfo)
            throw new AdminException('管理员信息未查到');
        if ($adminInfo->is_del) {
            throw new AdminException('管理员已经删除');
        }
        if ($data['head_pic'] != '') {
            $adminInfo->head_pic = $data['head_pic'];
        } elseif ($data['real_name'] != '') {
            $adminInfo->real_name = $data['real_name'];
        } elseif ($data['pwd'] != '') {
            if (!password_verify($data['pwd'], $adminInfo['pwd']))
                throw new AdminException('原始密码错误');
            if (!$data['new_pwd'])
                throw new AdminException('请输入新密码');
            if (!$data['conf_pwd'])
                throw new AdminException('请输入确认密码');
            if ($data['new_pwd'] != $data['conf_pwd'])
                throw new AdminException('两次输入的密码不一致');
            $adminInfo->pwd = $this->passwordHash($data['new_pwd']);
        } elseif ($data['phone'] != '') {
            $adminInfo->phone = $data['phone'];
        }
        if ($adminInfo->save()) {
			CacheService::delete('code_' . $data['phone']);
			CacheService::delete('code_error_' . $data['phone']);
            return true;
        } else {
            return false;
        }
    }


    /**
     * 后台订单下单，评论，支付成功，后台消息提醒
     */
    public function adminNewPush()
    {
        try {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $data['ordernum'] = $orderServices->count(['is_del' => 0, 'status' => 1, 'shipping_type' => 1]);
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $data['inventory'] = $productServices->count(['type' => 5]);
            /** @var StoreProductReplyServices $replyServices */
            $replyServices = app()->make(StoreProductReplyServices::class);
            $data['commentnum'] = $replyServices->count(['is_reply' => 0]);
            /** @var UserExtractServices $extractServices */
            $extractServices = app()->make(UserExtractServices::class);
            $data['reflectnum'] = $extractServices->getCount(['status' => 0]);//提现
            $data['msgcount'] = intval($data['ordernum']) + intval($data['inventory']) + intval($data['commentnum']) + intval($data['reflectnum']);

			SocketPushJob::dispatch(['', 'ADMIN_NEW_PUSH', $data, 'admin']);
        } catch (\Exception $e) {
        }
    }

    /**
     * 短信修改密码
     * @param $phone
     * @param $newPwd
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function resetPwd(string $phone, string $newPwd, int $adminType = 0)
    {
        $adminInfo = $this->dao->phoneByAdmin($phone, $adminType);
        if ($adminInfo) {
            $adminInfo->pwd = $this->passwordHash($newPwd);
            $adminInfo->save();
            return true;
        } else {
            throw new AdminException('管理员不存在，请检查手机号码');
        }

    }

    /**
     * 获取供应商接收通知管理员
     * @param int $supplier_id
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getNotifySupplierList(int $supplier_id, string $field = '*')
    {
        $where = [
            'relation_id' => $supplier_id,
            'status' => 1,
            'is_del' => 0
        ];
        $list = $this->dao->getList($where, 0, 0, $field);
        return $list;
    }

}
