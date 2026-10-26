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
namespace app\controller\admin\v1\system;

use app\controller\admin\AuthController;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRoleServices;
use mohe\services\CacheService;
use think\facade\App;

/**
 * Class SystemRole
 * @package app\controller\admin\v1\setting
 */
class SystemRole extends AuthController
{
    /**
     * SystemRole constructor.
     * @param App $app
     * @param SystemRoleServices $services
     */
    public function __construct(App $app, SystemRoleServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['role_name', ''],
        ]);
		$type = $this->adminType;
        $where['type'] = $type == 0 ? [0, 4] : $type;
		$where['relation_id'] = $this->agentId;
        $where['level'] = $this->adminInfo['level'] + 1;
        return $this->success($this->services->getRoleList($where));
    }

    /**
     * 显示创建资源表单页.
     *
     * @return \think\Response
     */
    public function create(SystemMenusServices $services)
    {
		[$type] = $this->request->postMore([
			['type', 0],//角色类型0平台4移动端商家管理
		], true);
		$roles = [];
		if ($this->adminInfo['level'] || $this->adminType == 3) {//子管理员 || 区域代理商
			$roles = $this->adminInfo['roles'];
		}
        $menus = $services->getmenus($roles, $type == 4 ? 5 : 0, 0);
        return $this->success(compact('menus'));
    }

    /**
     * 保存新建的资源
     *
     * @return \think\Response
     */
    public function save($id)
    {
        $data = $this->request->postMore([
			['type', 0],//角色类型0平台4移动端商家管理
            'role_name',
            ['status', 0],
            ['checked_menus', [], '', 'rules']
        ]);
        if (!$data['role_name']) return $this->fail('请输入身份名称');
        if (!is_array($data['rules']) || !count($data['rules']))
            return $this->fail('请选择最少一个权限');
        $rules = implode(',', $data['rules']);
		$data['type'] = $data['type'] ?: $this->adminType;
		$data['relation_id'] = $this->agentId;
		if ($data['type'] == 4) {//移动端
			$data['mall_rules'] = $rules;
		} else {
			$data['rules'] = $rules;
		}
        if ($id) {
			$role = $this->services->get((int)$id);
			if (!$role) {
				return $this->fail('角色不存在或已删除');
			}
			if (!$role['add_time']) {//历史数据无添加时间
				$data['add_time'] = time();
			}
            if (!$this->services->update($id, $data)) return $this->fail('修改失败!');
			CacheService::redisHandler('system_menus')->clear();
            return $this->success('修改成功!');
        } else {
			$data['add_time'] = time();
            $data['level'] = $this->adminInfo['level'] + 1;
            if (!$this->services->save($data)) return $this->fail('添加身份失败!');
			CacheService::redisHandler('system_menus')->clear();
            return $this->success('添加身份成功!');
        }
    }

    /**
     * 显示编辑资源表单页.
     *
     * @param int $id
     * @return \think\Response
     */
    public function edit(SystemMenusServices $services, $id)
    {
        $role = $this->services->get($id);
        if (!$role) {
            return $this->fail('修改的角色不存在');
        }
		$roles = [];
		if ($this->adminInfo['level'] || $this->adminType == 3) {//子管理员 || 区域代理商
			$roles = $this->adminInfo['roles'];
		}
		$type = 1;
		$role = $role->toArray();
		if ($role['type'] == 4) {//移动端
			$type = 5;
			$role['rules'] = $role['mall_rules'] ?? '';
		}
        $menus = $services->getMenus($roles, $type, 0);
        return $this->success(['role' => $role, 'menus' => $menus]);
    }

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (!$this->services->delete($id))
            return $this->fail('删除失败,请稍候再试!');
        else {
			CacheService::redisHandler('system_menus')->clear();
            return $this->success('删除成功!');
        }
    }

    /**
     * 修改状态
     * @param $id
     * @param $status
     * @return mixed
     */
    public function set_status($id, $status)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $role = $this->services->get($id);
        if (!$role) {
            return $this->fail('没有查到此身份');
        }
        $role->status = $status;
        if ($role->save()) {
			CacheService::redisHandler('system_menus')->clear();
            return $this->success('修改成功');
        } else {
            return $this->fail('修改失败');
        }
    }
}
