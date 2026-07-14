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
namespace app\controller\admin\v1\agent;

use app\services\agent\SystemRegionAgentServices;
use app\services\store\SystemRegionManageServices;
use app\services\system\admin\SystemAdminServices;
use app\services\system\SystemRoleServices;
use think\facade\App;
use app\controller\admin\AuthController;
use app\services\store\SystemStoreServices;
use mohe\exceptions\AdminException;

/**
 * 区域代理控制器
 * Class SystemRegionAgent
 * @package app\controller\admin\v1\store
 */
class SystemRegionAgent extends AuthController
{
    /**
     * 构造方法
     * SystemRegionAgent constructor.
     * @param App $app
     * @param SystemRegionAgentServices $services
     */
    public function __construct(App $app, SystemRegionAgentServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

	/**
	 * 获取所有区域已经选择的城市数据
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getRegionCity()
	{
		[$region_id] = $this->request->getMore([
			['region_id', 0],//区域ID
		], true);
		$region = $this->services->getList(['is_del' => 0, 'not_id' => $region_id], 'id,recommend_region');
		$regionCity = [];
		$recommendRegion = [];
		if ($region) {
			foreach ($region as $item) {
				$recommendRegion = array_merge($recommendRegion, $item['recommend_region']);
				foreach ($item['recommend_region'] as $city) {
					$regionCity[] = end($city);
				}
			}
		}
		$recommendRegion = array_merge(array_unique(array_reduce($recommendRegion, function($carry, $item) {
			return array_merge($carry, $item);
		}, [])));
		return $this->success(['region' => $recommendRegion, 'city' => array_merge(array_unique($regionCity))]);
	}

	/**
	 * 获取区域代理商cascader格式数据
	 * @param $type
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function cascader_list($type = 1)
	{
		$where = [];
		if ($this->adminType == 3 && $this->agentId) {//当前登录区域
			$where['id'] = $this->agentId;
		}
		return $this->success($this->services->cascaderList($where, $type));
	}

	/**
	 * 获取区域
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function allAgent()
	{
		$where = $this->request->getMore([
			['is_show', ''],//是否显示
		]);
		$where['is_del'] = 0;
		return $this->success($this->services->getAllRegion($where));
	}

    /**
     * 获取门店区域列表
     * @return mixed
     */
    public function index()
    {
        $where = $this->request->getMore([
			['pid', 0],//父级ID
			[['manage_region_id', 'd'], 0],//区域架构ID
            ['keyword', ''],//关键字
			['agent_admin', ''],//管理员搜索
            [['is_show', 'd'], ''],//状态
			[['is_alone', 'd'], ''],//是否隔离
        ]);
        $where['is_del'] = 0;
		$filteredByManageRegion = false;
		if (!empty($where['manage_region_id'])) {
			/** @var SystemRegionManageServices $manageServices */
			$manageServices = app()->make(SystemRegionManageServices::class);
			$manageRegionId = (int)$where['manage_region_id'];
			unset($where['manage_region_id'], $where['pid']);
			$agentIds = $manageServices->getAgentIdsByManageRegion($manageRegionId, true);
			if (!$agentIds) {
				return $this->success(['list' => [], 'count' => 0]);
			}
			$where['id'] = $agentIds;
			$filteredByManageRegion = true;
		}
		if (!$filteredByManageRegion && empty($where['pid']) && empty($where['name']) && $this->adminType == 3 && $this->agentId) {//当前登录区域
			$where['id'] = $this->agentId;
			if (empty($where['pid'])) {//未选择上级，查询当前
				unset($where['pid']);
			}
		}
        return $this->success($this->services->getRegionAgentList($where));
    }


	/**
	 * 区域详情
	 * @param SystemStoreServices $storeServices
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function info(SystemStoreServices $storeServices, SystemAdminServices $adminServices, $id)
	{
		$id = (int)$id;
		if (!$id) return $this->fail('数据不存在');
		$info = $this->services->get((int)$id);
		if (!$info) {
			return $this->fail('数据不存在');
		}
		$info = $info->toArray();
		$agentAdmin = $this->getAgentAdminRecord($adminServices, $id);
		$agentAdmin = $agentAdmin ?: [];
		$info['account'] = $agentAdmin['account'] ?? '';
		$info['admin_name'] = $agentAdmin['real_name'] ?? '';
		$info['uid'] = (int)($agentAdmin['uid'] ?? 0);
		$info['userInfo'] = [];
		if ($info['uid'] > 0) {
			$user = \app\model\user\User::where('uid', $info['uid'])
				->field('uid,avatar,nickname')
				->find();
			if ($user) {
				$info['userInfo'] = $user->toArray();
			}
		}
		$agentPid = (int)($info['pid'] ?? 0);
		/** @var SystemRegionManageServices $manageServices */
		$manageServices = app()->make(SystemRegionManageServices::class);
		$info['manage_region_id'] = (int)($info['manage_region_id'] ?? 0) ?: $manageServices->findManageRegionIdByAgentId($id);
		if ($info['manage_region_id']) {
			$info['manage_region_path'] = $manageServices->getManageRegionPath((int)$info['manage_region_id']);
		} else {
			$info['manage_region_path'] = [];
		}
		$pid = 0;
		if ($agentPid) {
			$pInfo = $this->services->get($agentPid);
			$pid = $pInfo['pid'] ?? 0;
		}
		$info['pid'] = $pid ? [$pid, $agentPid] : [$agentPid];
		$info['managed_store_ids'] = $this->services->getManagedStoreIds($id);
		return $this->success($info);
	}

	/**
	 * 设置区域是否隔离
	 * @param $id
	 * @param $is_alone
	 * @return mixed
	 */
	public function setAlone(SystemStoreServices $storeServices, $id = '', $is_alone = '')
	{
		if($is_alone === '' || $id === '') return $this->fail('缺少参数');
		$res = $this->services->update((int)$id, ['is_alone' => (int)$is_alone]);
		$storeServices->update(['region_id' => $id], ['is_region_alone' => (int)$is_alone]);
		return $this->success('设置成功');
	}


    /**
     * 设置单个门店是否显示
     * @param string $is_show
     * @param string $id
     * @return json
     */
    public function setShow($id = '', $is_show = '')
    {
        if($is_show === '' || $id === '') return $this->fail('缺少参数');
        $res = $this->services->update((int)$id, ['is_show' => (int)$is_show]);
		return $this->success('设置成功');
    }


	/**
	 * @param $id
	 * @return mixed
	 */
    public function save(SystemStoreServices $storeServices, SystemAdminServices $adminServices, SystemRoleServices $roleServices, $id = 0)
    {
        $data = $this->request->postMore([
			[['manage_region_id', 'd'], 0],//区域架构ID
			['pid', []],//上级区域
			[['name', 's'], ''],//联系人名称
			[['uid', 'd'], 0],//关联用户uid
			['image', ''],//绑定商城用户uid
			[['nickname', 's'], ''],//昵称
			['phone', ''],//联系人电话
			[['account', 's'], ''],//管理员账号
			['pwd', ''],//管理员密码
			['conf_pwd', ''],//管理员确认密码
            ['sort', 0],
            ['is_alone', ''],
            ['is_show', 1],
			['recommend_region', ''],//推荐区域
            ['store_id', ''],
        ]);
		$manageRegionId = (int)($data['manage_region_id'] ?? 0);
		unset($data['manage_region_id']);
		$account = trim((string)($data['account'] ?? ''));
		/** @var SystemRegionManageServices $manageServices */
		$manageServices = app()->make(SystemRegionManageServices::class);
		$contactName = trim((string)($data['name'] ?? ''));
		if ($manageRegionId) {
			try {
				$resolved = $manageServices->resolveAgentRegion($manageRegionId, (int)$id);
				$data['name'] = $contactName;
				$data['pid'] = (int)$resolved['pid'];
				$data['level'] = (int)($resolved['level'] ?? 1);
				$data['manage_region_id'] = $manageRegionId;
			} catch (\Throwable $e) {
				return $this->fail($e->getMessage());
			}
		} else {
			$data['pid'] = is_array($data['pid']) ? (int)end($data['pid']) : (int)$data['pid'];
		}
		$data['nickname'] = trim((string)($data['nickname'] ?? ''));
		$id = (int)$id;
		if ($id) {
			unset($data['nickname']);
		} elseif ($data['nickname'] === '') {
			$data['nickname'] = $account;
		}
		$this->validate($data, \app\validate\agent\SystemRegionAgentValidate::class, $id ? 'update' : 'save');
		if (!$id && $manageRegionId) {
			$archInfo = $manageServices->getRegionInfo($manageRegionId);
			if ($archInfo && (int)($archInfo['pid'] ?? 0) > 0) {
				$data['is_alone'] = 0;
			}
		}
		$bindUid = (int)($data['uid'] ?? 0);
		$pwd = (string)($data['pwd'] ?? '');
		$confPwd = (string)($data['conf_pwd'] ?? '');
		unset($data['store_id'], $data['uid'], $data['account'], $data['pwd'], $data['conf_pwd'], $data['image']);
		$province_id = $city_id = $area_id = [];
		$recommendRegion = is_array($data['recommend_region'] ?? null) ? $data['recommend_region'] : [];
		foreach ($recommendRegion as $region) {
			$province_id[] = array_shift($region);
			$count = count($region);
			switch ($count) {
				case 0:
					break;
				case 1:
					$city_id[] = array_shift($region);
					break;
				case 2:
					$city_id[] = $region[0];
					$area_id[] = end($region);
			}
		}
		$data['province_id'] = implode(',', array_unique($province_id));
		$data['city_id'] = implode(',', array_unique($city_id));
		$data['area_id'] = implode(',', array_unique($area_id));
		if (empty($data['level'])) {
			$data['level'] = 1;
		}
		if ($data['pid'] && $id && $id == $data['pid']) {
			// 管理人员从父区域改到子区域时，上级解析可能暂时仍是自己，置 0 后仅依赖 manage_region_id 归属
			if (!$manageRegionId) {
				return $this->fail('上级区域不能是自己');
			}
			$data['pid'] = 0;
		}
		if ($data['pid']) {
			$parentRegion = $this->services->get((int)$data['pid']);
			if (!$parentRegion || (int)($parentRegion['is_del'] ?? 0) === 1) {
				return $this->fail('上级区域不存在');
			}
			if (!$manageRegionId) {
				$data['level'] = (int)($parentRegion['level'] ?? 1) + 1;
			}
		}
		$this->services->transaction(function () use ($id, $data, $bindUid, $account, $pwd, $confPwd, $roleServices, $adminServices) {
			$agentId = $id;
			if ($agentId) {
				$this->services->update($agentId, $data);
			} else {
				$data['store_id'] = [];
				$data['add_time'] = time();
				$res = $this->services->save($data);
				if (!$res) {
					throw new AdminException('保存失败');
				}
				$agentId = (int)$res->id;
			}
			$regionRole = $roleServices->getRegionAgentRole();
			//代理商超级管理员信息
			$agentAdminData = [
				'admin_type' => 3,
				'relation_id' => $agentId,
				'level' => 0,
				'status' => 1,
				'is_del' => 0,
				'phone' => $data['phone'],
				'roles' => $regionRole ? (int)($regionRole['id'] ?? 0) : 0,
			];
			if (!$id || isset($data['nickname'])) {
				$agentAdminData['real_name'] = isset($data['nickname']) ? ($data['nickname'] ?: $account) : $account;
			}
			if ($account !== '') {
				$agentAdminData['account'] = $account;
			}
			if ($bindUid > 0) {
				$agentAdminData['uid'] = $bindUid;
			}
			if ($pwd !== '') {
				if ($confPwd === '') {
					throw new AdminException('请输入确认密码');
				}
				if ($pwd != $confPwd) {
					throw new AdminException('两次输入的密码不一致');
				}
				$agentAdminData['pwd'] = $this->services->passwordHash($pwd);
			}
			$agentAdmin = $this->getAgentAdminRecord($adminServices, $agentId);
			if ($agentAdmin) {
				if (isset($agentAdminData['account']) && $agentAdminData['account'] != ($agentAdmin['account'] ?? '') && $adminServices->isAccountUsable($agentAdminData['account'], (int)$agentAdmin['id'], 3)) {
					throw new AdminException('管理员账号已存在');
				}
				if (isset($agentAdminData['phone']) && $agentAdminData['phone'] != ($agentAdmin['phone'] ?? '') && $adminServices->count(['phone' => $data['phone'], 'admin_type' => 3, 'is_del' => 0])) {
					throw new AdminException('管理员电话已存在');
				}
				$adminServices->update((int)$agentAdmin['id'], $agentAdminData);
			} else {
				if (empty($agentAdminData['account'])) {
					throw new AdminException('请输入管理员账号');
				}
				if ($adminServices->count(['account' => $agentAdminData['account'], 'admin_type' => 3, 'is_del' => 0])) {
					throw new AdminException('管理员账号已存在');
				}
				if ($adminServices->count(['phone' => $data['phone'], 'admin_type' => 3, 'is_del' => 0])) {
					throw new AdminException('管理员电话已存在');
				}
				$agentAdminData['add_time'] = time();
				$adminServices->save($agentAdminData);
			}
			$this->services->syncManagedStoreIds($agentId);
		});
        return $this->success('操作成功!');
    }

	/**
	 * 删除区域代理商
	 * @param SystemStoreServices $storeServices
	 * @param SystemAdminServices $adminServices
	 * @param SystemRoleServices $roleServices
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function delete(SystemStoreServices $storeServices, SystemAdminServices $adminServices, SystemRoleServices $roleServices, $id)
    {
		$id = (int)$id;
        if (!$id) return $this->fail('数据不存在');
        $storeInfo = $this->services->get($id);
        if (!$storeInfo) {
            return $this->fail('数据不存在');
        }
		if ($this->services->count(['pid' => $id, 'is_del' => 0])) {
			return $this->fail('请先删除下级区域');
		}
		if ($storeInfo['is_del'] == 0) {
			$this->services->update($id, ['is_del' => 1]);
		}
		//清空区域下管理员
		$adminServices->delete(['admin_type' => 3, 'relation_id' => $id]);
		//清空区域下角色
		$roleServices->delete(['type' => 3, 'relation_id' => $id]);
		// 清除管辖门店关联（不修改门店 region_id 归属）
		app()->make(\app\dao\agent\SystemRegionAgentStoreDao::class)->delete(['agent_id' => $id]);
		$this->services->syncManagedStoreIds((int)$id);
		return $this->success('删除区域代理商!');
    }


	/**
	 * 区域删除关联门店
	 * @param SystemStoreServices $storeServices
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function delStore($id)
	{
		$id = (int)$id;
		if (!$id) return $this->fail('数据不存在');
		[$store_id] = $this->request->postMore([
			['store_id', ''],
		], true);
		$store_id = stringToIntArray($store_id);
		if (!$store_id) {
			return $this->fail('请选择门店');
		}
		$managed = $this->services->getManagedStoreIds($id);
		$remain = array_values(array_diff($managed, $store_id));
		try {
			$this->services->saveManagedStores($id, $remain);
		} catch (\Throwable $e) {
			return $this->fail($e->getMessage());
		}
		return $this->success('操作成功!');
	}

	/**
	 * 管理门店-弹窗数据
	 */
	public function manageStoresInfo($id = 0)
	{
		$id = (int)($id ?: $this->request->param('id', 0));
		if (!$id) {
			return $this->fail('数据不存在');
		}
		try {
			$scopeManageRegionId = (int)$this->request->param('manage_region_id', 0);
			return $this->success($this->services->getManageStoresData($id, $scopeManageRegionId));
		} catch (\Throwable $e) {
			return $this->fail($e->getMessage());
		}
	}

	/**
	 * 保存管理人员管辖门店
	 */
	public function saveManageStores($id = 0)
	{
		$id = (int)($id ?: $this->request->param('id', 0));
		if (!$id) {
			return $this->fail('数据不存在');
		}
		[$storeIds, $scopeManageRegionId] = $this->request->postMore([
			['store_ids', []],
			['manage_region_id', 0],
		], true);
		if (is_string($storeIds)) {
			$storeIds = stringToIntArray($storeIds);
		}
		$storeIds = array_values(array_filter(array_map('intval', (array)$storeIds)));
		try {
			$this->services->saveManagedStores($id, $storeIds, (int)$scopeManageRegionId);
		} catch (\Throwable $e) {
			return $this->fail($e->getMessage());
		}
		return $this->success('保存成功');
	}


	/**
	 * 区域代理登录
	 * @param SystemAdminServices $services
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function agentLogin(SystemAdminServices $services, $id)
	{
		$agentInfo = $this->services->get($id);
		if (!$agentInfo) {
			return $this->fail('登录的区域代理商不存在');
		}
		$agentAdmin = $services->getOne(['admin_type' => 3, 'relation_id' => $id, 'level' => 0, 'status' => 1, 'is_del' => 0]);
		if (!$agentAdmin) {
			return $this->fail('区域代理商超级管理员异常');
		}
		return $this->success($services->login($agentAdmin['account'], '', 'agent', false, '3'));
	}

	/**
	 * 获取区域管理员账号（不受 status 限制，避免编辑时查不到）
	 * @param SystemAdminServices $adminServices
	 * @param int $agentId
	 * @return array|null
	 */
	protected function getAgentAdminRecord(SystemAdminServices $adminServices, int $agentId): ?array
	{
		if ($agentId <= 0) {
			return null;
		}
		$agentAdmin = $adminServices->getOne(['admin_type' => 3, 'relation_id' => $agentId, 'level' => 0, 'is_del' => 0]);
		if (!$agentAdmin) {
			$agentAdmin = $adminServices->getOne(['admin_type' => 3, 'relation_id' => $agentId, 'is_del' => 0]);
		}
		if (!$agentAdmin) {
			return null;
		}
		return is_array($agentAdmin) ? $agentAdmin : $agentAdmin->toArray();
	}
}
