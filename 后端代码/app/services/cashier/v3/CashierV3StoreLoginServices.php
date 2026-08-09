<?php
namespace app\services\cashier\v3;

use app\services\BaseServices;
use app\services\cashier\LoginServices;
use app\services\employee\EmployeeInternalAccountServices;
use app\services\employee\EmployeeInternalLoginServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use think\facade\Db;

/**
 * 门店端 Vue 3 登录：统一员工账号认证后，直接进入唯一有效任职门店。
 */
class CashierV3StoreLoginServices extends BaseServices
{
    /** @return array */
    public function login(string $account, string $password, int $storeId = 0, string $ticket = ''): array
    {
        /** @var EmployeeInternalAccountServices $accounts */
        $accounts = app()->make(EmployeeInternalAccountServices::class);
        $auth = $accounts->authenticateByPassword($account, $password);
        $employeeId = (int)$auth['employee_id'];
        /** @var EmployeeInternalLoginServices $employees */
        $employees = app()->make(EmployeeInternalLoginServices::class);
        $staff = $employees->resolveUniqueStoreV3Staff($employeeId);
        if (!$staff) {
            throw new AdminException('当前账号没有可进入的门店端门店');
        }
        return $this->issueSelectedStore(
            $employeeId,
            (int)$staff['store_id'],
            (int)($auth['account_row']['id'] ?? 0),
            [$staff]
        );
    }

    /** @return array */
    public function switchStore(int $employeeId, int $storeId): array
    {
        /** @var EmployeeInternalLoginServices $employees */
        $employees = app()->make(EmployeeInternalLoginServices::class);
        $staff = $employees->resolveUniqueStoreV3Staff($employeeId);
        if (!$staff) {
            throw new AdminException('当前账号没有可进入的门店端门店');
        }
        if ((int)$staff['store_id'] !== $storeId) {
            throw new AdminException('员工只能进入当前任职门店');
        }
        return $this->issueSelectedStore($employeeId, $storeId, 0, [$staff]);
    }

    /**
     * V3 只撤销当前浏览器会话。账号凭据及其他终端会话不受影响。
     */
    public function logout(string $token): void
    {
        $token = trim(preg_replace('/^Bearer\s+/i', '', $token));
        if ($token === '') {
            throw new AdminException('当前会话已失效，请重新登录');
        }
        app()->make(CacheService::class)->redisHandler()->delete(md5($token));
    }

    /** @return array */
    private function issueSelectedStore(int $employeeId, int $storeId, int $accountId = 0, ?array $eligible = null): array
    {
        if ($storeId <= 0) {
            throw new AdminException('请选择门店');
        }
        if ($eligible === null) {
            /** @var EmployeeInternalLoginServices $employees */
            $employees = app()->make(EmployeeInternalLoginServices::class);
            $uniqueStaff = $employees->resolveUniqueStoreV3Staff($employeeId);
            $eligible = $uniqueStaff ? [$uniqueStaff] : [];
        }
        $staff = null;
        foreach ($eligible as $item) {
            if ((int)($item['store_id'] ?? 0) === $storeId) {
                $staff = $item;
                break;
            }
        }
        if (!$staff) {
            throw new AdminException('该门店不可进入或门店端权限已失效');
        }
        $staffRow = Db::name('system_store_staff')->where('id', (int)$staff['id'])
            ->where('employee_id', $employeeId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->find();
        if (!$staffRow) {
            throw new AdminException('门店任职已失效，请重新登录');
        }
        /** @var LoginServices $login */
        $login = app()->make(LoginServices::class);
        $result = $login->getLoginResult((int)$staff['id'], 'cashier_v3');
        if ($accountId > 0) {
            /** @var EmployeeInternalAccountServices $accounts */
            $accounts = app()->make(EmployeeInternalAccountServices::class);
            $accounts->touchLogin($accountId, (string)app('request')->ip());
        }
        $result['need_select_store'] = false;
        $result['stores'] = $this->presentStores([$staff]);
        return $result;
    }

    /** @return array<int, array> */
    private function presentStores(array $stores): array
    {
        return array_map(static function (array $store): array {
            return [
                'staff_id' => (int)($store['id'] ?? 0),
                'store_id' => (int)($store['store_id'] ?? 0),
                'store_name' => (string)($store['store_name'] ?? ''),
            ];
        }, $stores);
    }
}
