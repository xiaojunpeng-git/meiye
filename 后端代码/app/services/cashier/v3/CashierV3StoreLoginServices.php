<?php
namespace app\services\cashier\v3;

use app\services\BaseServices;
use app\services\cashier\LoginServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\employee\EmployeeInternalAccountServices;
use app\services\employee\EmployeeInternalLoginServices;
use app\services\organization\EmployeeDataScopeServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use think\facade\Cache;
use think\facade\Db;

/**
 * 门店端 Vue 3 登录分流契约：
 * 1. 有唯一有效任职门店的门店人员：直接进入该门店，不走选店票据；
 * 2. 没有任职门店、但具备组织数据权限和门店端入口的组织人员：从权限范围选店，
 *    进入后按该组织岗位的门店端规则使用当前门店，不写入或恢复 system_store_staff 任职；
 * 3. 无论哪种入口，选定门店都会绑定到当前会话，登录后不支持切店。
 *
 * 组织人员的多门店选店不能被误判为“门店人员多店任职”。两者是不同的登录模式。
 */
class CashierV3StoreLoginServices extends BaseServices
{
    private const SELECT_TICKET_PREFIX = 'cashier_v3_store_select:';
    private const SELECT_TICKET_TTL = 300;
    private const DELEGATED_SESSION_TTL = 28800;

    /** @return array */
    public function login(string $account, string $password, int $storeId = 0, string $ticket = ''): array
    {
        $ticket = trim($ticket);
        if ($ticket !== '') {
            $pending = Cache::get(self::SELECT_TICKET_PREFIX . $ticket);
            if (!is_array($pending) || (int)($pending['employee_id'] ?? 0) <= 0) {
                throw new AdminException('选店凭证已失效，请重新登录');
            }
            // 票据只保存已认证身份，不能保存或信任过期的门店范围；消费时再次按当前
            // 任职、岗位入口、数据权限和门店状态重新解析。
            Cache::delete(self::SELECT_TICKET_PREFIX . $ticket);
            $employeeId = (int)$pending['employee_id'];
            $organization = $this->organizationLoginCandidates($employeeId);
            if (!$organization) {
                throw new AdminException('当前账号的可进入门店已变化，请重新登录');
            }
            return $this->issueSelectedStore(
                $employeeId,
                $storeId,
                (int)($pending['account_id'] ?? 0),
                $organization
            );
        }

        /** @var EmployeeInternalAccountServices $accounts */
        $accounts = app()->make(EmployeeInternalAccountServices::class);
        $auth = $accounts->authenticateByPassword($account, $password);
        $employeeId = (int)$auth['employee_id'];
        /** @var EmployeeInternalLoginServices $employees */
        $employees = app()->make(EmployeeInternalLoginServices::class);
        // 门店人员分支：唯一有效任职门店固定直登；不能被数据权限门店覆盖。
        $direct = $employees->resolveUniqueStoreV3Staff($employeeId);
        if ($direct) {
            if ($storeId > 0 && $storeId !== (int)$direct['store_id']) {
                throw new AdminException('员工只能进入当前任职门店');
            }
            return $this->issueSelectedStore(
                $employeeId,
                (int)$direct['store_id'],
                (int)($auth['account_row']['id'] ?? 0),
                [$direct]
            );
        }

        // A direct tenure exists but has no V3 entry: do not silently turn it
        // into an organization read-only session.
        $activeTenureCount = (int)Db::name('system_store_staff')
            ->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)
            ->where('store_id', '>', 0)->count();
        if ($activeTenureCount > 0) {
            throw new AdminException('当前任职未开通门店端入口，请联系管理员');
        }

        // 组织人员没有任职时，只能从当前数据权限推导出的门店中选一店进入；
        // 会话随后强制绑定该店，绝不把集团权限直接作为跨店收银会话。
        $organization = $this->organizationLoginCandidates($employeeId);
        if (!$organization) {
            throw new AdminException('当前账号没有可进入的有效门店权限');
        }
        if (count($organization) > 1) {
            $selectionTicket = bin2hex(random_bytes(24));
            Cache::set(self::SELECT_TICKET_PREFIX . $selectionTicket, [
                'employee_id' => $employeeId,
                'account_id' => (int)($auth['account_row']['id'] ?? 0),
            ], self::SELECT_TICKET_TTL);
            return [
                'need_select_store' => true,
                'login_ticket' => $selectionTicket,
                'stores' => $this->presentStores($organization),
                // 组织树只包含当前登录候选门店所属组织及其必要祖先，作为选店导航，
                // 不下发该组织下其他未获授权门店。
                'organization_tree' => $this->presentOrganizationTree($organization),
                'message' => '请选择本次进入的门店',
            ];
        }
        return $this->issueSelectedStore(
            $employeeId,
            (int)$organization[0]['store_id'],
            (int)($auth['account_row']['id'] ?? 0),
            $organization
        );
    }

    /** @return array<int, array> */
    private function organizationLoginCandidates(int $employeeId): array
    {
        /** @var EmployeeInternalLoginServices $employees */
        $employees = app()->make(EmployeeInternalLoginServices::class);
        if ($employees->resolveUniqueStoreV3Staff($employeeId)) {
            return [];
        }
        $activeTenureCount = (int)Db::name('system_store_staff')
            ->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)
            ->where('store_id', '>', 0)->count();
        if ($activeTenureCount > 0) {
            return [];
        }
        return array_values(array_filter(
            $this->eligibleStores($employeeId),
            static fn(array $row): bool => !empty($row['delegated'])
        ));
    }

    /** 登录后不开放切店，门店在会话签发时固定。 */
    public function switchStore(int $employeeId, int $storeId): array
    {
        throw new AdminException('当前门店已在登录时固定，不支持登录后切换');
    }

    /** @return array<int, array> */
    public function eligibleStores(int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        /** @var EmployeeInternalLoginServices $employees */
        $employees = app()->make(EmployeeInternalLoginServices::class);
        $direct = $employees->resolveUniqueStoreV3Staff($employeeId);
        $byStore = [];
        foreach ($direct ? [$direct] : [] as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if ($sid > 0 && !isset($byStore[$sid])) {
                $row['source'] = 'direct_tenure';
                $row['delegated'] = false;
                $byStore[$sid] = $row;
            }
        }

        // 组织直属岗位才有资格把数据权限门店作为收银入口；个人数据权限不扩店。
        if ($employees->employeeHasStoreV3Entry($employeeId)) {
            /** @var EmployeeDataScopeServices $scope */
            $scope = app()->make(EmployeeDataScopeServices::class);
            $scopeIds = $scope->resolveEffectiveStoreIds($employeeId, 0, ['admin_type' => 3]);
            if ($scopeIds === null) {
                $scopeIds = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->column('id');
            }
            $scopeIds = array_values(array_unique(array_filter(array_map('intval', (array)$scopeIds))));
            if ($scopeIds) {
                $rows = Db::name('system_store')->alias('store')
                    ->leftJoin('organization_store org_store', 'org_store.store_id = store.id')
                    ->leftJoin('organization org', 'org.id = org_store.org_id')
                    ->whereIn('store.id', $scopeIds)
                    ->where('store.is_del', 0)->where('store.is_show', 1)
                    ->where(function ($query) {
                        $query->whereNull('org.id')->whereOr(function ($or) {
                            $or->where('org.is_del', 0);
                        });
                    })
                    ->field('store.id AS store_id,store.name AS store_name,org.id AS org_id,org.name AS org_name')
                    ->order('store.id', 'asc')->select()->toArray();
                foreach ($rows as $row) {
                    $sid = (int)($row['store_id'] ?? 0);
                    if ($sid <= 0 || isset($byStore[$sid])) continue;
                    $row['id'] = 0;
                    $row['employee_id'] = $employeeId;
                    $row['source'] = 'data_scope';
                    $row['delegated'] = true;
                    $byStore[$sid] = $row;
                }
            }
        }
        return array_values($byStore);
    }

    /** V3 只撤销当前浏览器会话；账号凭据及其他终端会话不受影响。 */
    public function logout(string $token): void
    {
        $token = trim(preg_replace('/^Bearer\s+/i', '', $token));
        if ($token === '') {
            throw new AdminException('当前会话已失效，请重新登录');
        }
        $cache = app()->make(CacheService::class);
        $cache->redisHandler()->delete(md5($token));
        // 无任职组织会话的 JWT 被清除后，再同步撤销其短期会话投影。
        try {
            [$id, $type] = app()->make(\mohe\utils\JwtAuth::class)->parseToken($token);
            if (in_array((string)$type, ['cashier_v3_delegated', 'cashier_v3_organization'], true)) {
                Db::name('cashier_v3_store_session')->where('id', (int)$id)->update([
                    'status' => 0, 'update_time' => time(),
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    /** @return array */
    private function issueSelectedStore(int $employeeId, int $storeId, int $accountId = 0, ?array $eligible = null): array
    {
        if ($storeId <= 0) {
            throw new AdminException('请选择门店');
        }
        $eligible = $eligible ?? $this->eligibleStores($employeeId);
        $selected = null;
        foreach ($eligible as $store) {
            if ((int)($store['store_id'] ?? 0) === $storeId) {
                $selected = $store;
                break;
            }
        }
        if (!$selected) {
            throw new AdminException('该门店不可进入或门店端权限已失效');
        }
        if (!empty($selected['delegated'])) {
            return $this->issueOrganizationStore($employeeId, $selected, $accountId, $eligible);
        }
        $staffRow = Db::name('system_store_staff')->where('id', (int)$selected['id'])
            ->where('employee_id', $employeeId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->find();
        if (!$staffRow) {
            throw new AdminException('门店任职已失效，请重新登录');
        }
        /** @var LoginServices $login */
        $login = app()->make(LoginServices::class);
        $result = $login->getLoginResult((int)$selected['id'], 'cashier_v3');
        if ($accountId > 0) {
            app()->make(EmployeeInternalAccountServices::class)->touchLogin($accountId, (string)app('request')->ip());
        }
        return $result;
    }

    /** @return array */
    private function issueOrganizationStore(int $employeeId, array $selected, int $accountId, array $eligible): array
    {
        $storeId = (int)($selected['store_id'] ?? 0);
        $now = time();
        $accountSnapshot = $accountId > 0
            ? trim((string)Db::name('employee_internal_account')->where('id', $accountId)->value('account'))
            : trim((string)Db::name('employee_internal_account')->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)->value('account'));
        $employeeName = trim((string)Db::name('employee')->where('id', $employeeId)->value('name'));
        $authVersion = max(1, (int)Db::name('employee')->where('id', $employeeId)->value('auth_version'));
        $secret = bin2hex(random_bytes(32));
        $sessionId = (int)Db::name('cashier_v3_store_session')->insertGetId([
            'employee_id' => $employeeId,
            'store_id' => $storeId,
            'token_secret_hash' => md5($secret),
            'auth_version' => $authVersion,
            'expire_time' => $now + self::DELEGATED_SESSION_TTL,
            'status' => 1,
            'is_del' => 0,
            'source' => 'data_scope',
            'account_snapshot' => $accountSnapshot,
            'staff_name_snapshot' => $employeeName,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($sessionId <= 0) {
            throw new AdminException('门店会话创建失败，请稍后重试');
        }
        $profile = [
            'id' => $sessionId,
            'account' => $accountSnapshot,
            'staff_name' => $employeeName,
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'roles' => [],
            'level' => 1,
            '_cashier_v3_organization' => 1,
        ];
        /** @var CashierV3FeatureResolver $resolver */
        $resolver = app()->make(CashierV3FeatureResolver::class);
        $features = $resolver->resolveGrantedFeatures($profile);
        if (!$features) {
            Db::name('cashier_v3_store_session')->where('id', $sessionId)->update(['status' => 0, 'update_time' => $now]);
            throw new AdminException('当前岗位未开通门店端功能');
        }
        /** @var LoginServices $login */
        $login = app()->make(LoginServices::class);
        $token = $login->createToken($sessionId, 'cashier_v3_organization', $secret);
        $store = Db::name('system_store')->where('id', $storeId)->field('id,name,image,product_category_status')->find();
        if ($accountId > 0) {
            app()->make(EmployeeInternalAccountServices::class)->touchLogin($accountId, (string)app('request')->ip());
        }
        return [
            'token' => $token['token'],
            'expires_time' => $token['params']['exp'],
            'features' => $features,
            'visible_features' => $features,
            'operation_features' => $features,
            'feature_permissions' => array_fill_keys($features, true),
            'permission_version' => 'organization:' . md5($employeeId . ':' . $storeId . ':' . $authVersion . ':' . implode(',', $features)),
            'store_id' => $storeId,
            'read_only' => false,
            'session_mode' => 'store_organization',
            'store_name' => (string)($store['name'] ?? $selected['store_name'] ?? ''),
            'user_info' => [
                'id' => $sessionId,
                'employee_id' => $employeeId,
                'account' => $accountSnapshot,
                'read_only' => false,
                'session_mode' => 'store_organization',
                'avatar' => '',
                'shift_start_time' => $now,
            ],
            'version' => get_mohe_version(),
            'prefix' => config('admin.cashier_prefix'),
        ];
    }

    /** @return array<int, array> */
    private function presentStores(array $stores): array
    {
        return array_map(static function (array $store): array {
            return [
                'staff_id' => (int)($store['id'] ?? 0),
                'store_id' => (int)($store['store_id'] ?? 0),
                'store_name' => (string)($store['store_name'] ?? ''),
                'org_id' => (int)($store['org_id'] ?? 0),
                'org_name' => (string)($store['org_name'] ?? ''),
                'source' => (string)($store['source'] ?? 'direct_tenure'),
                'read_only' => false,
            ];
        }, $stores);
    }

    /**
     * 组织选店只展示登录候选门店，不把“组织节点”错误扩展为该组织全量门店权限。
     *
     * @return array<int, array>
     */
    private function presentOrganizationTree(array $stores): array
    {
        $storeNodesByOrg = [];
        foreach ($stores as $store) {
            $storeId = (int)($store['store_id'] ?? 0);
            if ($storeId <= 0) continue;
            $orgId = (int)($store['org_id'] ?? 0);
            $storeNodesByOrg[$orgId][] = [
                'id' => $storeId,
                'store_id' => $storeId,
                'node_type' => 'store',
                'name' => (string)($store['store_name'] ?? ''),
            ];
        }
        if (!$storeNodesByOrg) return [];

        $requiredIds = array_values(array_filter(array_map('intval', array_keys($storeNodesByOrg))));
        $orgRows = [];
        $pending = $requiredIds;
        // 仅逐层补齐候选门店组织的祖先链，不查询或拼接其他下级组织。
        while ($pending) {
            $pending = array_values(array_diff($pending, array_keys($orgRows)));
            if (!$pending) break;
            $rows = Db::name('organization')->whereIn('id', $pending)->where('is_del', 0)
                ->field('id,pid,name,sort')->select()->toArray();
            $next = [];
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0) continue;
                $orgRows[$id] = $row;
                $parentId = (int)($row['pid'] ?? 0);
                if ($parentId > 0 && !isset($orgRows[$parentId])) $next[] = $parentId;
            }
            $pending = array_values(array_unique($next));
        }

        $nodes = [];
        foreach ($orgRows as $id => $row) {
            $nodes[$id] = [
                'id' => $id,
                'node_type' => 'org',
                'name' => (string)($row['name'] ?? ''),
                'sort' => (int)($row['sort'] ?? 0),
                'children' => [],
            ];
        }
        $roots = [];
        foreach ($nodes as $id => &$node) {
            $parentId = (int)($orgRows[$id]['pid'] ?? 0);
            if ($parentId > 0 && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        // 无组织归属的门店保持可选，但以单独导航节点展示，避免丢失权限内候选项。
        if (!empty($storeNodesByOrg[0])) {
            $roots[] = [
                'id' => 'unassigned',
                'node_type' => 'org',
                'name' => '未归属组织',
                'sort' => PHP_INT_MAX,
                'children' => [],
            ];
        }

        foreach ($storeNodesByOrg as $orgId => $storeNodes) {
            if ($orgId > 0 && isset($nodes[$orgId])) {
                $nodes[$orgId]['children'] = array_merge($nodes[$orgId]['children'], $storeNodes);
            } elseif ($orgId === 0) {
                $last = array_key_last($roots);
                if ($last !== null && ($roots[$last]['id'] ?? '') === 'unassigned') {
                    $roots[$last]['children'] = $storeNodes;
                }
            }
        }

        $sortTree = static function (array &$items) use (&$sortTree): void {
            usort($items, static function (array $left, array $right): int {
                $leftOrg = ($left['node_type'] ?? '') === 'org';
                $rightOrg = ($right['node_type'] ?? '') === 'org';
                if ($leftOrg !== $rightOrg) return $leftOrg ? -1 : 1;
                $bySort = ((int)($left['sort'] ?? 0)) <=> ((int)($right['sort'] ?? 0));
                if ($bySort !== 0) return $bySort;
                return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
            });
            foreach ($items as &$item) {
                if (!empty($item['children']) && is_array($item['children'])) $sortTree($item['children']);
                unset($item['sort']);
            }
            unset($item);
        };
        $sortTree($roots);
        return $roots;
    }
}
