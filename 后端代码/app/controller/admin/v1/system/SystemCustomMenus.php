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
use app\services\other\CacheServices;
use app\services\system\SystemCustomMenusServices;
use think\facade\App;


/**
 * 菜单权限
 * Class SystemCustomMenus
 * @package app\controller\admin\v1\setting
 */
class SystemCustomMenus extends AuthController
{
    /**
     * SystemMenus constructor.
     * @param App $app
     * @param SystemCustomMenusServices $services
     */
    public function __construct(App $app, SystemCustomMenusServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

	/**
	 * 获取后台菜单快捷入口
	 * @return mixed
	 */
	public function getFastMenus()
	{
		return $this->success($this->services->getAdminFastMenus((int)$this->request->adminId()));
	}

	/**
	 * 设置后台菜单快捷入口
	 * @param CacheServices $cacheServices
	 * @return mixed
	 * @throws \Exception
	 */
	public function setFastMenus(CacheServices $cacheServices)
	{
		[$menus] = $this->request->postMore([
			['menus', []],
		], true);
		$adminId = (int)$this->request->adminId();
		$cacheServices->setDbCache('admin_fast_menus_' . $adminId, $menus);
		return $this->success('设置成功');
	}

	/**
	 * 获取一条菜单数据
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function info($id)
	{
		if (!$id) return $this->fail('缺少参数');
		$menus = $this->services->get((int)$id);
		if (!$menus) {
			return $this->fail('数据不存在');
		}
		$menus = $menus->toArray();
		$baseUrl = sys_config('site_url');
		$menus['image'] = filter_var($menus['image'], FILTER_VALIDATE_URL) ? $menus['image'] : $baseUrl . $menus['image'];
		return $this->success($menus);
	}

    /**
     * 保存菜单权限
     * @return mixed
     */
    public function save($id)
    {
        $data = $this->request->getMore([
            ['name', ''],
			['image', ''],
            ['url', ''],
            ['is_show', 0],
        ]);
        if (!$data['name']) return $this->fail('请填写菜单名称');
		if (!$data['image']) return $this->fail('请上传菜单图标');
		if (!$data['url']) return $this->fail('请填写菜单链接');
		$data['admin_id'] = $this->request->adminId();
		$menus = $this->services->getOne(['name' => $data['name']]);
		if ($id) {
			if ($menus && $menus['id'] != $id) {
				return $this->fail('菜单名称已经存在');
			}
			$res = $this->services->update($id, $data);
		} else {
			if ($menus) {
				return $this->fail('菜单名称已经存在');
			}
			$res = $this->services->save($data);
		}
        if ($res) {
            return $this->success($id ? '编辑成功' : '添加成功');
        } else {
            return $this->fail($id ? '编辑失败' : '添加失败');
        }
    }

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (!$id) {
            return $this->fail('参数错误');
        }
		$menus = $this->services->get((int)$id);
		if (!$menus) {
			return $this->fail('删除成功');
		}
		if (!$menus['admin_id']) {
			return $this->fail('系统默认菜单不允许删除');
		}
		$adminId = $this->request->adminId();
		if ($menus['admin_id'] != $adminId) {
			return $this->fail('不允许删除');
		}
        if ($this->services->delete((int)$id)) {
			/** @var CacheServices $cache */
			$cache = app()->make(CacheServices::class);
			$data = $cache->getDbCache('admin_fast_menus_' . $adminId, []);
			if ($data) {//
				$ids = array_column($data, 'id');
				if (in_array($id, $ids)) unset($data[array_search($id, $ids)]);
				$cache->setDbCache('admin_fast_menus_' . $adminId, array_merge($data));
			}
            return $this->success('删除成功');
        } else {
            return $this->fail('删除失败,请稍候再试!!');
        }
    }
}
