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
use think\facade\Db;

/**
 * 门店端 Vue 3 登录分流契约：
 * 1. 有唯一有效任职门店的门店人员：直接进入该门店，不走选店票据；
 * 2. 没有任职门店、但具备组织数据权限和门店端入口的组织人员：只能从权限范围选店，
 *    进入后创建只读 delegated store session，不写入或恢复 system_store_staff 任职；
 * 3. 无论哪种入口，选定门店都会绑定到当前会话，登录后不支持切店。
 *
 * 组织人员的多门店选店不能被误判为“门店人员多店任职”。两者是不同的登录模式。
 */
class CashierV3StoreLoginServices extends BaseServices
{
    private const DELEGATED_SESSION_TTL = 28800;

    /** @return array */
    public function login(string $account, string $password, int $storeId = 0, string $ticket = ''): array
    {
        if (trim($ticket) !== '') {
            throw new AdminException('门店端登录不支持选择门店，请重新输入账号密码');
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

        // 组织人员分支：没有任职门店时，数据权限内的门店才是可选入口；
        // 选择后仍只绑定一个具体门店，不能把门店端变成跨店查询。
        $delegated = array_values(array_filter(
            $this->eligibleStores($employeeId),
            static fn(array $row): bool => !empty($row['delegated'])
        ));
        // 产品规则要求多门店 delegated 账号在登录时选择门店。当前接口尚未恢复
        // 两步选店交互，因此暂时明确拦截并提示；这不是门店人员单店规则的冲突结论。
        if (count($delegated) > 1) {
            throw new AdminException('当前账号有多个数据权限门店，请从指定门店入口进入');
        }
        if (!$delegated) {
            throw new AdminException('当前账号没有可进入的有效门店权限');
        }
        if ($storeId > 0 && $storeId !== (int)$delegated[0]['store_id']) {
            throw new AdminException('员工只能进入当前指定门店');
        }
        return $this->issueSelectedStore(
            $employeeId,
            (int)$delegated[0]['store_id'],
            (int)($auth['account_row']['id'] ?? 0),
            $delegated
        );
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
        // delegated token 的 JWT 被清除后，再同步撤销其短期会话投影。
        try {
            [$id, $type] = app()->make(\mohe\utils\JwtAuth::class)->parseToken($token);
            if ((string)$type === 'cashier_v3_delegated') {
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
            return $this->issueDelegatedStore($employeeId, $selected, $accountId, $eligible);
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
    private function issueDelegatedStore(int $employeeId, array $selected, int $accountId, array $eligible): array
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
            '_cashier_v3_delegated' => 1,
            '_cashier_v3_read_only' => 1,
        ];
        /** @var CashierV3FeatureResolver $resolver */
        $resolver = app()->make(CashierV3FeatureResolver::class);
        $features = $resolver->resolveVisibleFeatures($profile);
        if (!$features) {
            Db::name('cashier_v3_store_session')->where('id', $sessionId)->update(['status' => 0, 'update_time' => $now]);
            throw new AdminException('当前岗位未开通门店端功能');
        }
        /** @var LoginServices $login */
        $login = app()->make(LoginServices::class);
        $token = $login->createToken($sessionId, 'cashier_v3_delegated', $secret);
        $store = Db::name('system_store')->where('id', $storeId)->field('id,name,image,product_category_status')->find();
        if ($accountId > 0) {
            app()->make(EmployeeInternalAccountServices::class)->touchLogin($accountId, (string)app('request')->ip());
        }
        return [
            'token' => $token['token'],
            'expires_time' => $token['params']['exp'],
            'features' => $features,
            'visible_features' => $features,
            'operation_features' => [],
            'feature_permissions' => [],
            'permission_version' => 'readonly:' . md5($employeeId . ':' . $storeId . ':' . $authVersion),
            'store_id' => $storeId,
            'read_only' => true,
            'session_mode' => 'store_read_only',
            'store_name' => (string)($store['name'] ?? $selected['store_name'] ?? ''),
            'user_info' => [
                'id' => $sessionId,
                'employee_id' => $employeeId,
                'account' => $accountSnapshot,
                'read_only' => true,
                'session_mode' => 'store_read_only',
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
                'read_only' => !empty($store['delegated']),
            ];
        }, $stores);
    }
}
