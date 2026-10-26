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
namespace app\services\agent;

use app\dao\agent\SystemRegionAgentDao;
use app\dao\agent\SystemRegionAgentStoreDao;
use app\dao\store\SystemStoreRegionDao;
use app\services\store\SystemRegionManageServices;
use app\dao\system\admin\SystemAdminDao;
use app\services\BaseServices;
use app\services\other\CityAreaServices;
use app\services\store\SystemStoreServices;
use app\services\system\admin\SystemAdminServices;
use app\services\user\UserServices;


/**
 * 区域代理商
 * Class SystemRegionAgentServices
 * @package app\services\agent
 * @mixin SystemStoreRegionDao
 */
class SystemRegionAgentServices extends BaseServices
{
    /**
     * 构造方法
     * SystemRegionAgentServices constructor.
     * @param SystemRegionAgentDao $dao
     */
    public function __construct(SystemRegionAgentDao $dao)
    {
        $this->dao = $dao;
    }


	/**
	 * 根据商城 uid 获取其在「区域管理」中对应的区域记录
	 * 判定规则：只要在区域管理添加了该管理员（区域表 / 关联后台账号），即视为有权限，与 admin_type、level 无关
	 *
	 * @param int $uid
	 * @param int $agentId
	 * @return array
	 */
	public function getRegionAgentByUid(int $uid, int $agentId = 0)
	{
		if ($uid <= 0) {
			return [];
		}
		$agentWhere = ['is_del' => 0];
		if ($agentId > 0) {
			$agentWhere['id'] = $agentId;
		}
		/** @var UserServices $userServices */
		$userServices = app()->make(UserServices::class);
		$phone = trim((string)$userServices->value(['uid' => $uid], 'phone'));

		// 1. 区域管理人员表上的联系电话
		if ($phone !== '') {
			$agentByPhone = $this->dao->getOne(array_merge($agentWhere, ['phone' => $phone]));
			if ($agentByPhone) {
				return $this->toAgentArray($agentByPhone);
			}
		}

		$agentIds = $this->dao->getColumn($agentWhere, 'id');
		if (!$agentIds) {
			return [];
		}

		// 2. 后台账号 relation_id 指向区域管理人员（不限 admin_type / level）
		$admin = $this->findRegionManageAdmin($uid, $phone, $agentIds);
		if (!$admin) {
			return [];
		}
		$relationId = (int)($admin['relation_id'] ?? 0);
		if (!$relationId || !in_array($relationId, $agentIds, true)) {
			return [];
		}
		$agentInfo = $this->dao->get($relationId);
		if (!$agentInfo || (int)($agentInfo['is_del'] ?? 0) === 1) {
			return [];
		}
		if ($phone !== '' && (int)($admin['uid'] ?? 0) !== $uid) {
			/** @var SystemAdminServices $adminServices */
			$adminServices = app()->make(SystemAdminServices::class);
			$adminServices->update((int)$admin['id'], ['uid' => $uid]);
		}
		return $this->toAgentArray($agentInfo);
	}

	/**
	 * 是否在区域管理中被添加为管理人员（个人中心「区域统计」等入口）
	 */
	public function userIsRegionAgent(int $uid): bool
	{
		return $this->getRegionAgentByUid($uid) !== [];
	}

	/**
	 * 查找绑定到区域管理人员的后台账号（不限制 admin_type、level）
	 */
	protected function findRegionManageAdmin(int $uid, string $phone, array $agentIds): array
	{
		$agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds))));
		if (!$agentIds) {
			return [];
		}
		/** @var SystemAdminDao $adminDao */
		$adminDao = app()->make(SystemAdminDao::class);
		$buildWhere = function (array $extra = []) use ($agentIds): array {
			$where = [
				['relation_id', 'in', $agentIds],
				['is_del', '=', 0],
			];
			foreach ($extra as $field => $value) {
				$where[] = [$field, '=', $value];
			}
			return $where;
		};
		if ($uid > 0) {
			$admin = $adminDao->getOne($buildWhere(['uid' => $uid]));
			if ($admin) {
				return is_object($admin) ? $admin->toArray() : (array)$admin;
			}
		}
		if ($phone !== '') {
			$admin = $adminDao->getOne($buildWhere(['phone' => $phone]));
			if ($admin) {
				return is_object($admin) ? $admin->toArray() : (array)$admin;
			}
		}
		return [];
	}

	/**
	 * @param mixed $row
	 * @return array
	 */
	protected function toAgentArray($row): array
	{
		if (!$row) {
			return [];
		}
		return is_object($row) ? $row->toArray() : (array)$row;
	}

	/**
	 * 获取当前区域代理商可操作门店ID（统计/订单等权限范围）
	 * 已配置管辖门店则仅返回管辖门店；未配置则返回所属区域下全部门店
	 * @param int $id
	 * @return array
	 */
	public function getRegionAgentStoreId(int $id)
	{
		return $this->getAgentStoreScopeIds($id);
	}

	/**
	 * 管理人员显式管辖的门店ID（关联表）
	 */
	public function getManagedStoreIds(int $agentId): array
	{
		if ($agentId <= 0) {
			return [];
		}
		/** @var SystemRegionAgentStoreDao $storeDao */
		$storeDao = app()->make(SystemRegionAgentStoreDao::class);
		$storeIds = $storeDao->getColumn(['agent_id' => $agentId], 'store_id') ?: [];
		return array_values(array_unique(array_filter(array_map('intval', $storeIds))));
	}

	/**
	 * 区域管理人员门店权限范围
	 */
	public function getAgentStoreScopeIds(int $agentId): array
	{
		/** @var \app\services\organization\OrganizationScopeService $scopeService */
		$scopeService = app()->make(\app\services\organization\OrganizationScopeService::class);
		if ($scopeService->isMigrated()) {
			return $scopeService->getResolvedStoreIdsByLegacyAgentId($agentId);
		}
		if ($agentId <= 0) {
			return [];
		}
		$managed = $this->getManagedStoreIds($agentId);
		if ($managed) {
			return $managed;
		}
		$agent = $this->dao->get($agentId, ['id', 'manage_region_id', 'is_del']);
		if (!$agent || (int)($agent['is_del'] ?? 0) === 1) {
			return [];
		}
		$agent = is_object($agent) ? $agent->toArray() : $agent;
		$manageRegionId = (int)($agent['manage_region_id'] ?? 0);
		if ($manageRegionId > 0) {
			/** @var SystemRegionManageServices $manageServices */
			$manageServices = app()->make(SystemRegionManageServices::class);
			return $manageServices->getRegionTerritoryStoreIds($manageRegionId, false);
		}
		/** @var SystemStoreServices $storeServices */
		$storeServices = app()->make(SystemStoreServices::class);
		$storeIds = $storeServices->getColumn(['region_id' => $agentId, 'is_del' => 0], 'id') ?: [];
		return array_values(array_unique(array_filter(array_map('intval', $storeIds))));
	}

	/**
	 * 是否已配置管辖门店（有则按管辖门店限制权限）
	 */
	public function hasManagedStores(int $agentId): bool
	{
		return $this->getManagedStoreIds($agentId) !== [];
	}

	/**
	 * 架构区域下首个管理人员（门店归属 region_id 用）
	 */
	public function getPrimaryAgentIdByManageRegion(int $manageRegionId): int
	{
		if ($manageRegionId <= 0) {
			return 0;
		}
		$ids = $this->dao->getColumn([
			'manage_region_id' => $manageRegionId,
			'is_del' => 0,
		], 'id') ?: [];
		if (!$ids) {
			return 0;
		}
		$ids = array_map('intval', $ids);
		sort($ids);
		return (int)reset($ids);
	}

	/**
	 * 门店切换/解绑区域时，解除与所有管理人员的管辖关联
	 */
	public function unbindStoreFromManagedAgents(int $storeId): void
	{
		if ($storeId <= 0) {
			return;
		}
		/** @var SystemRegionAgentStoreDao $storeDao */
		$storeDao = app()->make(SystemRegionAgentStoreDao::class);
		$agentIds = $storeDao->getColumn(['store_id' => $storeId], 'agent_id') ?: [];
		$agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds))));
		if (!$agentIds) {
			return;
		}
		$storeDao->delete(['store_id' => $storeId]);
		foreach ($agentIds as $agentId) {
			$this->syncManagedStoreIds($agentId);
		}
	}

	/**
	 * 同步 store_id 字段为管辖门店缓存
	 */
	public function syncManagedStoreIds(int $agentId): bool
	{
		if ($agentId <= 0) {
			return true;
		}
		$storeIds = $this->getManagedStoreIds($agentId);
		return (bool)$this->dao->update($agentId, ['store_id' => $storeIds]);
	}

	/**
	 * 保存管理人员管辖门店
	 */
	public function saveManagedStores(int $agentId, array $storeIds, int $scopeManageRegionId = 0): bool
	{
		$agent = $this->dao->get($agentId, ['id', 'manage_region_id', 'is_del']);
		if (!$agent || (int)($agent['is_del'] ?? 0) === 1) {
			throw new \Exception('管理人员不存在');
		}
		$agent = is_object($agent) ? $agent->toArray() : $agent;
		/** @var SystemRegionManageServices $manageServices */
		$manageServices = app()->make(SystemRegionManageServices::class);
		$agentManageRegionId = (int)($agent['manage_region_id'] ?? 0);
		$manageRegionId = $scopeManageRegionId > 0 ? $scopeManageRegionId : $agentManageRegionId;
		if (!$manageRegionId) {
			throw new \Exception('请先选择区域架构');
		}
		$allowedIds = $manageServices->getManageRegionSelectableStoreIds($manageRegionId);
		$storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
		foreach ($storeIds as $storeId) {
			if (!in_array($storeId, $allowedIds, true)) {
				throw new \Exception('门店不在当前区域范围内');
			}
		}
		/** @var SystemRegionAgentStoreDao $storeDao */
		$storeDao = app()->make(SystemRegionAgentStoreDao::class);
		$storeDao->delete(['agent_id' => $agentId]);
		$time = time();
		foreach ($storeIds as $storeId) {
			$storeDao->save([
				'agent_id' => $agentId,
				'store_id' => $storeId,
				'add_time' => $time,
			]);
		}
		return $this->syncManagedStoreIds($agentId);
	}

	/**
	 * 管理门店弹窗数据
	 */
	public function getManageStoresData(int $agentId, int $scopeManageRegionId = 0): array
	{
		$agent = $this->dao->get($agentId, ['id', 'name', 'manage_region_id', 'is_del']);
		if (!$agent || (int)($agent['is_del'] ?? 0) === 1) {
			throw new \Exception('管理人员不存在');
		}
		$agent = is_object($agent) ? $agent->toArray() : $agent;
		/** @var SystemRegionManageServices $manageServices */
		$manageServices = app()->make(SystemRegionManageServices::class);
		$agentManageRegionId = (int)($agent['manage_region_id'] ?? 0);
		$manageRegionId = $scopeManageRegionId > 0 ? $scopeManageRegionId : $agentManageRegionId;
		if (!$manageRegionId) {
			throw new \Exception('请先选择区域架构');
		}
		// 与区域「门店列表」一致：仅当前架构及下级已绑定门店
		$allowedIds = $manageServices->getManageRegionSelectableStoreIds($manageRegionId);
		/** @var SystemStoreServices $storeServices */
		$storeServices = app()->make(SystemStoreServices::class);
		$availableStores = [];
		if ($allowedIds) {
			$availableStores = $storeServices->getList(
				[
					'id' => $allowedIds,
					'is_del' => 0,
				],
				['id', 'name', 'phone', 'address', 'image', 'is_show', 'region_id']
			) ?: [];
			$availableStores = array_values(array_filter($availableStores, function ($store) {
				return (int)($store['region_id'] ?? 0) > 0;
			}));
		}
		$managedIds = $this->getManagedStoreIds($agentId);
		$managedIds = $allowedIds
			? array_values(array_intersect($managedIds, $allowedIds))
			: [];
		return [
			'agent_id' => $agentId,
			'agent_name' => $agent['name'] ?? '',
			'manage_region_id' => $manageRegionId,
			'has_managed_stores' => $managedIds !== [],
			'managed_store_ids' => $managedIds,
			'available_stores' => $availableStores,
		];
	}

	/**
	 * 更新区域关联门店IDS（管辖门店缓存）
	 */
	public function setRegionAgentStoreId(int $id)
	{
		return $this->syncManagedStoreIds($id);
	}

	/**
	 * 	获取所有区域
	 * @param array $where
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getAllRegion(array $where = [])
	{
		return $this->dao->getList($where, 'id,name');
	}

	/**
	 * 获取区域cascader
	 * @param array $where
	 * @param $type
	 * @return array|array[]
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function cascaderList(array $where = [], $type = 1)
	{
		if ($type == 1) {
			$top = true;
		} else {
			$top = false;
		}
		$menus = [];
		$where['is_del'] = 0;
		$where['is_show'] = 1;
		$list = get_tree_children($this->dao->getList($where,'id as value,name as label,pid'), 'children', 'value');
		if ($top) {
			$menus = [['value' => 0, 'label' => '顶级区域代理商']];
			foreach ($list as &$item) {
				if (isset($item['children']) && $item['children']) {
					foreach ($item['children'] as &$val) {
						if (isset($val['children']) && $val['children']) {
							unset($val['children']);
						}
					}
				}
			}
		}
		$menus = array_merge($menus, $list);
		return $menus;
	}


	/**
	 * 获取区域列表
	 * @param array $where
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getRegionAgentList(array $where)
	{
		[$page, $limit] = $this->getPageValue();
		$list = $this->dao->getList($where, '*', $page, $limit, ['admin' => function($query) {
			$query->field('id,admin_type,relation_id,real_name,account');
		}, 'children']);
		/** @var CityAreaServices $cityAreaServices */
		$cityAreaServices = app()->make(CityAreaServices::class);
		/** @var SystemRegionManageServices $manageServices */
		$manageServices = app()->make(SystemRegionManageServices::class);
		$city = $cityAreaServices->getColumn([], 'id,name', 'id');
		if ($list) {
			foreach ($list as &$item) {
				$manageRegionId = (int)($item['manage_region_id'] ?? 0);
				if (!$manageRegionId) {
					$manageRegionId = $manageServices->findManageRegionIdByAgentId((int)($item['id'] ?? 0));
				}
				$archInfo = $manageRegionId ? $manageServices->getRegionInfo($manageRegionId) : null;
				$item['manage_region_id'] = $manageRegionId;
				$item['manage_region_name'] = $archInfo['name'] ?? ($item['name'] ?? '');
				$item['manage_region_pid'] = (int)($archInfo['pid'] ?? 0);
				$recommend_region_name = [];
				if ($item['recommend_region']) {
					foreach ($item['recommend_region'] as $region) {
						$recommend_region_name_one = [];
						foreach ($region as $id) {
							$recommend_region_name_one[] = $city[$id]['name'] ?? '';
						}
						$recommend_region_name[] = implode('/', $recommend_region_name_one);
					}
				}
				$item['recommend_region'] = implode('；', $recommend_region_name);
				$item['admin_name'] = $item['admin']['account'] ?? '';
				if (isset($item['children']) && $item['children']) {
					$item['children'] = [];
					$item['loading'] = false;
					$item['_loading'] = false;
				} else {
					unset($item['children']);
				}
				$item['store_id'] = $this->getAgentStoreScopeIds((int)$item['id']);
				$item['store_count'] = count($item['store_id']);
				unset($item['admin']);
			}
		}
		$count = $this->dao->count($where);
		return compact('list', 'count');
	}

	/**
	 * 根据城市查询所在门店区域
	 * @param array $cityIds
	 * @return int
	 */
	function getRegionByCity(array $cityIds)
	{
		$region = 0;
		if (!$cityIds) {
			return $region;
		}
		$count = count($cityIds);
		$keys = [3 => ['area_id', 'city_id', 'province_id'], '2' => ['city_id', 'province_id'], 1 => ['province_id']];
		$fileds = $keys[$count] ?? '';
		if (!$fileds) {
			return $region;
		}
		$ids = array_reverse($cityIds);

		foreach ($ids as $key => $id) {
			$filed = $fileds[$key] ?? '';
			$where = ['is_del' => 0];
			if ($filed) {
				$where[$filed] = $id;
				$regionInfo = $this->dao->getRegion($where);
				if ($regionInfo) {
					$region = $regionInfo['id'];
					break;
				}
			}
		}
		return $region;
	}

	/**
	 * 解析请求中的门店筛选（支持 store_id / store_ids 逗号分隔，与管辖门店取交集）
	 * @param int $agentId
	 * @param mixed $storeId
	 * @param mixed $storeIds
	 * @return array
	 */
	public function resolveRequestStoreIds(int $agentId, $storeId = '', $storeIds = ''): array
	{
		$regionStoreIds = $this->getRegionAgentStoreId($agentId) ?: [];
		$regionStoreIds = array_values(array_filter(array_map('intval', $regionStoreIds)));

		$ids = [];
		if ($storeIds !== '' && $storeIds !== null) {
			if (is_array($storeIds)) {
				$ids = array_map('intval', $storeIds);
			} else {
				$ids = array_map('intval', array_filter(explode(',', (string)$storeIds)));
			}
		}
		if (!$ids && $storeId !== '' && (int)$storeId > 0) {
			$ids = [(int)$storeId];
		}
		$ids = array_values(array_unique(array_filter($ids)));

		if ($ids) {
			if ($regionStoreIds) {
				$ids = array_values(array_intersect($ids, $regionStoreIds));
			}
			return $ids ?: $regionStoreIds;
		}

		return $regionStoreIds;
	}

}
