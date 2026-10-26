<?php

declare(strict_types=1);

namespace app\services\merchant;

use app\services\BaseServices;
use app\services\cashier\LoginServices as CashierLoginServices;
use app\services\employee\EmployeeInternalAccountServices;
use app\services\organization\MerchantEntryServices;
use mohe\exceptions\AdminException;
use think\facade\Cache;
use think\facade\Db;

/**
 * 旧 uniapp 商家页的员工入口：统一内部账号认证后，仅签发商家专用会话。
 * 不调用会员登录，也不要求收银 V3 岗位权限。
 */
final class MerchantEmployeeLoginServices extends BaseServices
{
    private const SELECT_TICKET_PREFIX = 'merchant_employee_select:';
    private const SELECT_TICKET_TTL = 300;

    public function login(string $account, string $password, int $storeId = 0, string $ticket = ''): array
    {
        $ticket = trim($ticket);
        if ($ticket !== '') {
            $pending = Cache::get(self::SELECT_TICKET_PREFIX . $ticket);
            if (!is_array($pending) || (int)($pending['employee_id'] ?? 0) <= 0) {
                throw new AdminException('选店凭证已失效，请重新登录');
            }
            return $this->issue((int)$pending['employee_id'], $storeId, (int)($pending['account_id'] ?? 0));
        }

        /** @var EmployeeInternalAccountServices $accounts */
        $accounts = app()->make(EmployeeInternalAccountServices::class);
        $auth = $accounts->authenticateByPassword($account, $password);
        $employeeId = (int)$auth['employee_id'];
        $stores = $this->eligibleStores($employeeId);
        if (!$stores) {
            throw new AdminException('当前账号没有可进入的手机商家端门店');
        }
        if ($storeId <= 0 && count($stores) > 1) {
            $ticket = bin2hex(random_bytes(24));
            Cache::set(self::SELECT_TICKET_PREFIX . $ticket, [
                'employee_id' => $employeeId,
                'account_id' => (int)($auth['account_row']['id'] ?? 0),
            ], self::SELECT_TICKET_TTL);
            return [
                'need_select_store' => true,
                'login_ticket' => $ticket,
                'stores' => $stores,
            ];
        }
        if ($storeId <= 0) {
            $storeId = (int)$stores[0]['store_id'];
        }
        return $this->issue($employeeId, $storeId, (int)($auth['account_row']['id'] ?? 0), $stores);
    }

    private function issue(int $employeeId, int $storeId, int $accountId = 0, ?array $stores = null): array
    {
        $stores = $stores ?? $this->eligibleStores($employeeId);
        $selected = null;
        foreach ($stores as $store) {
            if ((int)$store['store_id'] === $storeId) {
                $selected = $store;
                break;
            }
        }
        if (!$selected) {
            throw new AdminException('该门店不可进入或手机商家端权限已失效');
        }
        $staff = Db::name('system_store_staff')->where('id', (int)$selected['staff_id'])
            ->where('employee_id', $employeeId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->find();
        if (!is_array($staff) || (int)($staff['uid'] ?? 0) <= 0) {
            throw new AdminException('员工门店任职或会员映射已失效，请联系管理员处理');
        }

        /** @var CashierLoginServices $tokens */
        $tokens = app()->make(CashierLoginServices::class);
        $token = $tokens->createToken((int)$staff['id'], 'merchant_legacy', (string)$staff['pwd']);
        if ($accountId > 0) {
            /** @var EmployeeInternalAccountServices $accounts */
            $accounts = app()->make(EmployeeInternalAccountServices::class);
            $accounts->touchLogin($accountId, (string)app('request')->ip());
        }
        return [
            'token' => (string)$token['token'],
            'expires_time' => (int)$token['params']['exp'],
            'need_select_store' => false,
            'stores' => $stores,
            'store_id' => $storeId,
            'store_name' => (string)$selected['store_name'],
            'user_info' => [
                'id' => (int)$staff['id'],
                'uid' => (int)$staff['uid'],
                'employee_id' => $employeeId,
                'account' => (string)$staff['account'],
                'avatar' => (string)($staff['avatar'] ?? ''),
            ],
        ];
    }

    /** @return array<int, array{staff_id:int,store_id:int,store_name:string}> */
    private function eligibleStores(int $employeeId): array
    {
        /** @var MerchantEntryServices $entry */
        $entry = app()->make(MerchantEntryServices::class);
        $judge = $entry->canShowMerchantEntry($employeeId);
        if (empty($judge['show_merchant_entry'])) {
            throw new AdminException($entry->reasonMessage((string)($judge['reason'] ?? '')));
        }
        $stores = $entry->listSelectableStoresForMerchant($employeeId);
        $staffByStore = Db::name('system_store_staff')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->whereIn('store_id', array_column($stores, 'store_id'))
            ->order('id', 'asc')->column('id', 'store_id');
        $out = [];
        foreach ($stores as $store) {
            $storeId = (int)($store['store_id'] ?? 0);
            $staffId = (int)($staffByStore[$storeId] ?? 0);
            if ($storeId > 0 && $staffId > 0) {
                $out[] = ['staff_id' => $staffId, 'store_id' => $storeId, 'store_name' => (string)($store['name'] ?? '')];
            }
        }
        return $out;
    }
}
