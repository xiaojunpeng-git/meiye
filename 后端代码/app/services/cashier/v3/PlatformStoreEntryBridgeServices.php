<?php
namespace app\services\cashier\v3;

use app\services\employee\EmployeeInternalAccountServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use think\facade\Db;

/**
 * 平台到门店的一次性身份桥。票据仅证明已登录平台账号对应的员工身份；
 * 门店及功能权限始终由门店登录服务按实时数据重新确定。
 */
class PlatformStoreEntryBridgeServices
{
    private const TICKET_PREFIX = 'cashier_v3_platform_entry:';
    private const TICKET_TTL = 60;

    /** @return array{ticket:string,expires_in:int} */
    public function issue(int $adminId, string $adminToken): array
    {
        $admin = $this->activeAdmin($adminId);
        $employeeId = (int)($admin['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new AdminException('当前平台账号未关联员工身份，请先关联员工身份');
        }
        if ($adminToken === '' || !CacheService::hasToken(md5($adminToken))) {
            throw new AdminException('平台登录已失效，请重新登录');
        }
        $this->activeEmployeeAccount($employeeId);

        // 浏览器只拿随机票据；不传输平台密码、平台令牌或目标门店管理员身份。
        $ticket = bin2hex(random_bytes(32));
        $saved = CacheService::redisHandler()->set(self::TICKET_PREFIX . hash('sha256', $ticket), [
            'admin_id' => $adminId,
            'employee_id' => $employeeId,
            'admin_token_hash' => md5($adminToken),
            'admin_password_hash' => hash('sha256', (string)$admin['pwd']),
        ], self::TICKET_TTL);
        if (!$saved) {
            throw new AdminException('进入门店凭证签发失败，请稍后重试');
        }
        return ['ticket' => $ticket, 'expires_in' => self::TICKET_TTL];
    }

    /** @return array */
    public function consume(string $ticket, CashierV3StoreLoginServices $login): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) {
            throw new AdminException('进入门店凭证无效，请从平台重新进入');
        }
        $digest = hash('sha256', $ticket);
        $key = self::TICKET_PREFIX . $digest;
        $redis = CacheService::redisHandler()->handler();
        $lockKey = 'cashier_v3_platform_entry_lock:' . $digest;
        // 跨 PHP 进程共用 Redis 锁，使并发请求只有一个能读取并删除票据。
        if (!$redis->setnx($lockKey, '1')) {
            throw new AdminException('进入门店凭证已使用，请从平台重新进入');
        }
        $redis->expire($lockKey, 10);
        try {
            $pending = CacheService::redisHandler()->get($key);
            CacheService::redisHandler()->delete($key);
        } finally {
            $redis->del($lockKey);
        }
        if (!is_array($pending)) {
            throw new AdminException('进入门店凭证已失效，请从平台重新进入');
        }

        $admin = $this->activeAdmin((int)($pending['admin_id'] ?? 0));
        $employeeId = (int)($pending['employee_id'] ?? 0);
        if ((int)($admin['employee_id'] ?? 0) !== $employeeId
            || !hash_equals((string)($pending['admin_password_hash'] ?? ''), hash('sha256', (string)$admin['pwd']))
            || !CacheService::hasToken((string)($pending['admin_token_hash'] ?? ''))) {
            throw new AdminException('平台身份已变化，请重新登录后进入门店');
        }
        $account = $this->activeEmployeeAccount($employeeId);
        return $login->loginAuthenticatedEmployee($employeeId, (int)$account['id']);
    }

    /** 平台管理员必须仍有效，旧的代入门店管理员入口不参与本凭证。 */
    private function activeAdmin(int $adminId): array
    {
        $admin = $adminId > 0 ? Db::name('system_admin')->where('id', $adminId)
            ->where('admin_type', 0)->where('status', 1)->where('is_del', 0)->find() : null;
        if (!$admin) {
            throw new AdminException('当前平台账号已失效，请重新登录');
        }
        return is_array($admin) ? $admin : $admin->toArray();
    }

    /** 关联员工、统一内部账号都必须仍可登录门店端。 */
    private function activeEmployeeAccount(int $employeeId): array
    {
        /** @var EmployeeInternalAccountServices $accounts */
        $accounts = app()->make(EmployeeInternalAccountServices::class);
        $account = $accounts->getByEmployeeId($employeeId);
        $employee = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->find();
        if (!$account || (int)($account['status'] ?? 0) !== 1 || !$employee || (int)($employee['status'] ?? 0) !== 1) {
            throw new AdminException('关联员工账号已停用，请先检查员工身份');
        }
        return $account;
    }
}
