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

namespace app\services\system;


use app\Request;
use app\services\BaseServices;
use app\dao\system\SystemRoleDao;
use app\services\store\SystemStoreStaffServices;
use mohe\exceptions\AuthException;
use mohe\utils\ApiErrorCode;
use mohe\services\CacheService;


/**
 * Class SystemRoleServices
 * @package app\services\system
 * @mixin SystemRoleDao
 */
class SystemRoleServices extends BaseServices
{

    /**
     * 当前管理员权限缓存前缀
     */
    const ADMIN_RULES_LEVEL = 'Admin_rules_level_';

    /**
     * SystemRoleServices constructor.
     * @param SystemRoleDao $dao
     */
    public function __construct(SystemRoleDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取权限
     * @return mixed
     */
    public function getRoleArray(array $where = [], string $field = '', string $key = '')
    {
        return $this->dao->getRoule($where, $field, $key);
    }

	/**
	 * 获取表单所需的权限名称列表
	 * @param int $level
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 */
    public function getRoleFormSelect(int $level, int $type = 0, int $relation_id = 0)
    {
        $list = $this->getRoleArray(['level' => $level, 'type' => $type, 'relation_id' => $relation_id, 'status' => 1]);
        $options = [];
        foreach ($list as $id => $roleName) {
            $options[] = ['label' => $roleName, 'value' => $id];
        }
        return $options;
    }

    /**
     * 身份管理列表
     * @param array $where
     * @return array
     */
    public function getRoleList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getRouleList($where, $page, $limit);
        $count = $this->dao->count($where);
        /** @var SystemMenusServices $service */
        $service = app()->make(SystemMenusServices::class);
        foreach ($list as &$item) {
//			$rules = implode(',', array_merge($service->column(['id' => $item['rules']], 'menu_name', 'id')));
//			$item['rules'] = substrUTf8($rules, 520);
			$item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', (int)$item['add_time']) : '';
        }
        return compact('count', 'list');
    }

    /**
     * 后台验证权限
     * @param Request $request
     */
    public function verifiAuth(Request $request)
    {
        $rule = str_replace('adminapi/', '', trim(strtolower($request->rule()->getRule())));
        if (in_array($rule, ['setting/admin/logout', 'menuslist'])) {
            return true;
        }
		$method = trim(strtolower($request->method()));
		$auth = $this->getAllRoles(2);
        $isPlatformInventoryV3 = strpos($rule, 'product/inventory/v3/') === 0;
        $registered = array_values(array_filter($auth, function ($item) use ($rule, $method) {
            return trim(strtolower($item['methods'])) === $method
                && trim(strtolower(str_replace(' ', '', $item['api_url']))) === $rule;
        }));
        if (!$registered) {
            // V3 平台库存不得沿用历史接口“未登记即放行”的兜底；其他
            // 路由维持旧行为，避免本次权限补洞扩大成全平台兼容性改造。
            if ($isPlatformInventoryV3) {
                throw new AuthException(ApiErrorCode::ERR_AUTH);
            }
            return true;
        }
		$auth = $this->getRolesByAuth($request->adminInfo()['roles'], 2);
        $directGranted = !empty(array_filter($auth, function ($item) use ($rule, $method) {
            return trim(strtolower($item['api_url'])) === $rule
                && $method === trim(strtolower($item['methods']));
        }));
        if ($directGranted) {
            return true;
        }
        if ($isPlatformInventoryV3) {
            // A stable capability may intentionally cover sibling endpoint
            // registrations. The requested route was already verified above.
            $requiredAuth = trim((string)($registered[0]['unique_auth'] ?? ''));
            $sharedGranted = $requiredAuth !== '' && !empty(array_filter($auth, function ($item) use ($requiredAuth) {
                return trim((string)($item['unique_auth'] ?? '')) === $requiredAuth;
            }));
            if (!$sharedGranted) {
                throw new AuthException(ApiErrorCode::ERR_AUTH);
            }
            return true;
        }
        //验证访问接口是否有权限
        if ($auth) {
            throw new AuthException(ApiErrorCode::ERR_AUTH);
        }
    }

	/**
     * 获取所有权限
     * @param int $auth_type
     * @param int $type
     * @param string $cachePrefix
     * @return array|bool|mixed|null
     */
    public function getAllRoles(int $auth_type = 1, int $type = 1, string $cachePrefix = self::ADMIN_RULES_LEVEL)
    {
        $cacheName = md5($cachePrefix . '_' . $auth_type . '_' . $type . '_ALl' );
        return CacheService::redisHandler('system_menus')->remember($cacheName, function () use ($auth_type, $type) {
            /** @var SystemMenusServices $menusService */
            $menusService = app()->make(SystemMenusServices::class);
            return $menusService->getColumn([['auth_type', '=', $auth_type], ['type', '=', $type]], 'api_url,methods,unique_auth');
        });
    }

    /**
     * 获取指定权限
     * @param array $roles
     * @param int $auth_type
     * @param int $type
     * @param string $cachePrefix
     * @return array|bool|mixed|null
     */
    public function getRolesByAuth(array $roles, int $auth_type = 1, int $type = 1, string $cachePrefix = self::ADMIN_RULES_LEVEL)
    {
        if (empty($roles)) return [];
        $cacheName = md5($cachePrefix . '_' . $auth_type . '_' . $type . '_' . implode('_', $roles));
        return CacheService::redisHandler('system_menus')->remember($cacheName, function () use ($roles, $auth_type, $type) {
            /** @var SystemMenusServices $menusService */
            $menusService = app()->make(SystemMenusServices::class);
            return $menusService->getColumn([['id', 'IN', $this->getRoleIds($roles, $type == 3 ? 'cashier_rules' : 'rules')], ['auth_type', '=', $auth_type], ['type', '=', $type]], 'api_url,methods,unique_auth');
        });
    }

    /**
     * 获取权限id
     * @param array $roles
     * @return array
     */
    public function getRoleIds(array $roles, string $field = 'rules', string $key = 'id')
    {
        $rules = $this->dao->getColumn([['id', 'IN', $roles], ['status', '=', '1']], $field, $key);
        return $rules ? array_unique(explode(',', implode(',', $rules))) : [];
    }

    /**
     * 门店角色状态更改改变角色下店员、管理员状态
     * @param int $store_id
     * @param int $role_id
     * @param $status
     * @return mixed
     */
    public function setStaffStatus(int $store_id, int $role_id, $status)
    {
        /** @var SystemStoreStaffServices $storeStaffServices */
        $storeStaffServices = app()->make(SystemStoreStaffServices::class);
        if ($status) {
            return $storeStaffServices->update(['store_id' => $store_id, 'roles' => $role_id, 'is_del' => 0, 'status' => 0], ['status' => 1]);
        } else {
            return $storeStaffServices->update(['store_id' => $store_id, 'roles' => $role_id, 'status' => 1], ['status' => 0]);
        }
    }

	/**
	 * 获取区域代理商超级管理员角色
	 * @return array|\think\Model|null
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getRegionAgentRole()
	{
		$role = $this->dao->get(['type' => 3, 'relation_id' => 0, 'level' => 0, 'status' => 1]);
		if (!$role) {
			$roleData = [
				'type' => 3,
				'relation_id' => 0,
				'level' => 0,
				'status' => 1,
				'role_name' => '区域代理商',
				'add_time' => time(),
				'rules' => '7,1091,366,367,368,369,1587,1837,1565,1901,1037,1041,1297,1299,1300,1302,1307,1622,1570,1,2,577,108,192,596,193,888,911,1601,1603,1920,6,190,579,188,189,1555,1042,1305,1306,1308,1309,1311,1317,1318,1922,1924,1927,1556,1683,1687,1571,718,818,819,820,821,824,9,10,585,232,235,960,1027,1029,1909,1910,828,1572,1597,720,722,1491,1905,1828,1829,1573,1583,717,815,816,817,825,766,822,823,1522,1040,1332,1333,1334,1335,1574,1835,1838,1902,1903,1839,1904,35,1043,1044,1325,1045,1327,1328,1046,1320,12,14,19,325,326,327,328,329,330,611,20,331,332,333,334,335,336,337,610,635,1932,425,426,431,435,912,1340,1620,1671,1923'
			];
			$res = $this->dao->save($roleData);
			$role = $this->dao->get($res->id);
		}
		return $role;
	}
}
