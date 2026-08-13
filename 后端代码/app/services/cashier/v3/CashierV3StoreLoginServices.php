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
 * 门店端 Vue 3 登录：单店直接进入，多店登录时选店，正式会话锁定当前门店。
 *
 * 没有直接任职的员工，只有在有效数据权限 + 组织直属收银 V3 岗位同时满足时，
 * 才能创建 delegated store session；该会话不写入或恢复 system_store_staff 任职。
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
            // 一次性消费；随后仍重新解析数据权限，不能信任票据中的门店列表。
            Cache::delete(self::SELECT_TICKET_PREFIX . $ticket);
            $employeeId = (int)$pending['employee_id'];
            $eligible = $this->loginCandidates($employeeId);
            return $this->issueSelectedStore(
                $employeeId,
                $storeId,
                (int)($pending['account_id'] ?? 0),
                $eligible
            );
        }

        /** @var EmployeeInternalAccountServices $accounts */
        $accounts = app()->make(EmployeeInternalAccountServices::class);
        $auth = $accounts->authenticateByPassword($account, $password);
        $employeeId = (int)$auth['employee_id'];
        /** @var EmployeeInternalLoginServices $employees */
        $employees = app()->make(EmployeeInternalLoginServices::class);
        $direct = $employees->listEligibleStoreV3Staff($employeeId);
        $eligible = $direct ?: $this->eligibleStores($employeeId);
        if (!$eligible) {
            /** @var EmployeeDataScopeServices $scope */
            $scope = app()->make(EmployeeDataScopeServices::class);
            $scopeIds = $scope->resolveEffectiveStoreIds($employeeId, 0, ['admin_type' => 3]);
            if ($scopeIds !== [] && !$employees->employeeHasStoreV3Entry($employeeId)) {
                throw new AdminException('当前账号已有数据权限，但未配置门店端岗位或功能入口');
            }
            throw new AdminException('当前账号没有可进入的有效门店权限');
        }
        // 有直接任职时优先使用直接任职；数据权限门店不覆盖已有任职入口。
        if (count($direct) === 1 && $storeId <= 0) {
            return $this->issueSelectedStore(
                $employeeId,
                (int)$direct[0]['store_id'],
                (int)($auth['account_row']['id'] ?? 0),
                $direct
            );
        }
        if ($storeId > 0) {
            return $this->issueSelectedStore(
                $employeeId,
                $storeId,
                (int)($auth['account_row']['id'] ?? 0),
                $eligible
            );
        }
        if (count($eligible) > 1) {
            $ticket = bin2hex(random_bytes(24));
            Cache::set(self::SELECT_TICKET_PREFIX . $ticket, [
                'employee_id' => $employeeId,
                'account_id' => (int)($auth['account_row']['id'] ?? 0),
            ], self::SELECT_TICKET_TTL);
            return [
                'need_select_store' => true,
                'login_ticket' => $ticket,
                'stores' => $this->presentStores($eligible),
                'message' => '该账号可进入多个门店，请选择门店后登录',
            ];
        }
        return $this->issueSelectedStore(
            $employeeId,
            (int)$eligible[0]['store_id'],
            (int)($auth['account_row']['id'] ?? 0),
            $eligible
        );
    }

    /** 登录候选：有直接任职时只展示直接任职；无直接任职时才使用数据权限门店。 */
    private function loginCandidates(int $employeeId): array
    {
        $direct = app()->make(EmployeeInternalLoginServices::class)->listEligibleStoreV3Staff($employeeId);
        return $direct ?: $this->eligibleStores($employeeId);
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
        $direct = $employees->listEligibleStoreV3Staff($employeeId);
        $byStore = [];
        foreach ($direct as $row) {
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
        $result['need_select_store'] = false;
        $result['stores'] = $this->presentStores($eligible);
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
        $token = $login->createToken($sessionId, 'cashier_v3_delegated', $secret);
        $store = Db::name('system_store')->where('id', $storeId)->field('id,name,image,product_category_status')->find();
        if ($accountId > 0) {
            app()->make(EmployeeInternalAccountServices::class)->touchLogin($accountId, (string)app('request')->ip());
        }
        return [
            'token' => $token['token'],
            'expires_time' => $token['params']['exp'],
            'features' => $features,
            'need_select_store' => false,
            'stores' => $this->presentStores($eligible),
            'store_id' => $storeId,
            'store_name' => (string)($store['name'] ?? $selected['store_name'] ?? ''),
            'user_info' => [
                'id' => $sessionId,
                'employee_id' => $employeeId,
                'account' => $accountSnapshot,
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
            ];
        }, $stores);
    }
}
