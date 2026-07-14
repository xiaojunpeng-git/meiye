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
namespace app\controller\admin\v1\system\admin;

use app\controller\admin\AuthController;
use app\services\system\admin\LoginAuthServices;
use app\services\system\admin\SystemAdminServices;
use mohe\services\CacheService;
use think\facade\{App, Config};

/**
 * Class SystemAdmin
 * @package app\controller\admin\v1\setting
 */
class SystemAdmin extends AuthController
{
    /**
     * SystemAdmin constructor.
     * @param App $app
     * @param SystemAdminServices $services
     */
    public function __construct(App $app, SystemAdminServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示管理员资源列表
     *
     * @return \think\Response
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['name', '', '', 'account_like'],
            ['roles', ''],
            ['is_del', 1],
            ['status', '']
        ]);
        $where['level'] = $this->adminInfo['level'] + 1;
        $where['admin_type'] = $this->adminType;
		$where['relation_id'] = $this->agentId;
        return $this->success($this->services->getAdminList($where));
    }

    /**
     * 创建表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function create()
    {
        return $this->success($this->services->createForm(0,$this->adminInfo['level'] + 1, '/setting/admin', (int)$this->adminType, (int)$this->agentId));
    }

	/**
	 * 保存管理员
	 * @param LoginAuthServices $loginAuthServices
	 * @return mixed
	 */
    public function save(LoginAuthServices $loginAuthServices)
    {
        $data = $this->request->postMore([
			['image', ''], //商城用户
			['head_pic'],//图像
            ['account', ''],//账号
			['pwd', ''],//密码
            ['conf_pwd', ''],//确认密码
            ['real_name', ''],//昵称
            ['phone', ''],//手机号
            ['roles', []],//角色
            ['status', 0],
        ]);
        $this->validate($data, \app\validate\admin\setting\SystemAdminValidate::class);
		if ($data['image']) {
			$data['uid'] = $data['image']['uid'] ?? 0;
			$data['head_pic'] = $data['image']['image'] ?? '';
		}
		unset($data['image']);
		//验证密码
		$loginAuthServices->validatePassword((string)$data['pwd']);

        $data['level'] = $this->adminInfo['level'] + 1;
		$data['admin_type'] = $this->adminType;
		$data['relation_id'] = $this->agentId;
        $this->services->saveData(0, $data, (int)$this->adminType);
        return $this->success('添加成功');
    }

    /**
     * 显示编辑资源表单页.
     *
     * @param int $id
     * @return \think\Response
     */
    public function edit($id)
    {
        if (!$id) {
            return $this->fail('管理员信息读取失败');
        }
        return $this->success($this->services->createForm((int)$id,$this->adminInfo['level'] + 1, '/setting/admin/' . $id, (int)$this->adminType, (int)$this->agentId));
    }

	/**
	 * 修改管理员信息
	 * @param LoginAuthServices $loginAuthServices
	 * @param $id
	 * @return mixed
	 */
    public function update(LoginAuthServices $loginAuthServices, $id)
    {
        $data = $this->request->postMore([
			['image', ''], //商城用户
			['head_pic', ''],
            ['account', ''],
            ['conf_pwd', ''],
            ['pwd', ''],
            ['real_name', ''],
            ['phone', ''],
            ['roles', []],
            ['status', 0],
        ]);
		if ($data['pwd']) {
			$loginAuthServices->validatePassword((string)$data['pwd']);
		}
        $this->validate($data, \app\validate\admin\setting\SystemAdminValidate::class, 'update');
		if ($data['image']) {
			$data['uid'] = $data['image']['uid'] ?? 0;
			$data['head_pic'] = $data['image']['image'] ?? '';
		}
		unset($data['image']);

        if ($this->services->saveData((int)$id, $data, (int)$this->adminType)) {
            return $this->success('修改成功');
        } else {
            return $this->fail('修改失败');
        }
    }

    /**
     * 删除管理员
     * @param $id
     * @return mixed
     */
    public function delete($id)
    {
        if (!$id) return $this->fail('删除失败，缺少参数');
        if ($this->services->update((int)$id, ['is_del' => 1, 'status' => 0]))
            return $this->success('删除成功！');
        else
            return $this->fail('删除失败');
    }

    /**
     * 修改状态
     * @param $id
     * @param $status
     * @return mixed
     */
    public function set_status($id, $status)
    {
        $this->services->update((int)$id, ['status' => $status]);
        return $this->success($status == 0 ? '关闭成功' : '开启成功');
    }

    /**
     * 获取当前登录管理员的信息
     * @return mixed
     */
    public function info()
    {
        return $this->success($this->adminInfo);
    }

    /**
     * 修改当前登录admin信息
     * @return mixed
     */
    public function update_admin()
    {
        $data = $this->request->postMore([
            ['real_name', ''],
            ['head_pic', ''],
            ['pwd', ''],
            ['new_pwd', ''],
            ['conf_pwd', ''],
            ['phone', ''],
            ['code', '']
        ]);
		if ($data['phone'] && $data['code']) {
			//验证验证码
			try {
				check_sms_code($data['phone'], $data['code']);
			} catch (\Throwable $e) {
				return app('json')->fail($e->getMessage());
			}
		}
        if ($this->services->updateAdmin($this->adminId, $data))
            return $this->success('修改成功');
        else
            return $this->fail('修改失败');
    }

    /**
     * 退出登录
     * @return mixed
     */
    public function logout()
    {
        $key = trim(ltrim($this->request->header(Config::get('cookie.token_name')), 'Bearer'));
        CacheService::redisHandler()->delete(md5($key));
        return $this->success();
    }
}
