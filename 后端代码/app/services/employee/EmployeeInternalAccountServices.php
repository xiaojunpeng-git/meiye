<?php
namespace app\services\employee;

use app\services\BaseServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * 员工统一内部账号：一人一账号，平台/门店/收银共用；投影到 admin/staff
 */
class EmployeeInternalAccountServices extends BaseServices
{
    public const ERR_PHONE_AS_ACCOUNT = '内部账号不能使用纯11位手机号格式';

    public function getByEmployeeId(int $employeeId): ?array
    {
        if ($employeeId <= 0) {
            return null;
        }
        $row = Db::name('employee_internal_account')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    public function getByAccount(string $account): ?array
    {
        $account = trim($account);
        if ($account === '') {
            return null;
        }
        $row = Db::name('employee_internal_account')
            ->where('account', $account)
            ->where('is_del', 0)
            ->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    /**
     * 冻结内部账号格式：emp{employee_id}（禁止纯手机号）
     */
    public function canonicalAccountForEmployee(int $employeeId): string
    {
        if ($employeeId <= 0) {
            throw new AdminException('员工无效');
        }
        return 'emp' . $employeeId;
    }

    /**
     * 登录输入解析：内部账号 或 员工手机号 → 内部账号行。
     * 保留手机号作为用户输入，不把手机号写入内部账号字段。
     */
    public function resolveByLoginInput(string $loginInput): ?array
    {
        $loginInput = trim($loginInput);
        if ($loginInput === '') {
            return null;
        }
        $direct = $this->getByAccount($loginInput);
        if ($direct) {
            return $direct;
        }
        // 手机号 → employee → 内部账号
        if (preg_match('/^1\d{10}$/', $loginInput)) {
            $emp = Db::name('employee')
                ->where('phone', $loginInput)
                ->where('is_del', 0)
                ->find();
            if ($emp) {
                $row = $this->getByEmployeeId((int)$emp['id']);
                if ($row) {
                    return $row;
                }
            }
            // 兼容：admin.account 仍为手机号且已绑定 employee
            $admin = Db::name('system_admin')
                ->where('account', $loginInput)
                ->where('admin_type', 0)
                ->where('is_del', 0)
                ->where('employee_id', '>', 0)
                ->order('id', 'asc')
                ->find();
            if ($admin) {
                $row = $this->getByEmployeeId((int)$admin['employee_id']);
                if ($row) {
                    return $row;
                }
            }
            // 兼容：staff.account/phone
            $staff = Db::name('system_store_staff')
                ->where('is_del', 0)
                ->where(function ($q) use ($loginInput) {
                    $q->where('account', $loginInput)->whereOr('phone', $loginInput);
                })
                ->where('employee_id', '>', 0)
                ->order('id', 'desc')
                ->find();
            if ($staff) {
                $row = $this->getByEmployeeId((int)$staff['employee_id']);
                if ($row) {
                    return $row;
                }
            }
        }
        return null;
    }

    public function assertValidAccountFormat(string $account): void
    {
        $account = trim($account);
        if ($account === '') {
            throw new AdminException('请填写登录账号');
        }
        if (strlen($account) < 4 || strlen($account) > 64) {
            throw new AdminException('登录账号长度4-64位');
        }
        if (preg_match('/^1\d{10}$/', $account)) {
            throw new AdminException(self::ERR_PHONE_AS_ACCOUNT);
        }
    }

    /**
     * 创建或更新统一账号，并投影到同 employee 的 system_admin / 全部有效 staff
     * @param array{use_outer_transaction?:bool} $options
     * @return array{employee_id:int,account:string,credential_version:int}
     */
    public function saveAccount(
        int $employeeId,
        string $account,
        ?string $plainPwd,
        int $status,
        array $operatorContext = [],
        array $options = []
    ): array {
        if ($employeeId <= 0) {
            throw new AdminException('员工无效');
        }
        $this->assertValidAccountFormat($account);
        $account = trim($account);
        $status = $status === 1 ? 1 : 0;
        $emp = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->lock(true)->find();
        if (!$emp) {
            throw new AdminException('员工不存在');
        }

        $runner = function () use ($employeeId, $account, $plainPwd, $status, $operatorContext) {
            $now = time();
            $exist = Db::name('employee_internal_account')
                ->where('employee_id', $employeeId)
                ->where('is_del', 0)
                ->lock(true)
                ->find();
            $dup = Db::name('employee_internal_account')
                ->where('account', $account)
                ->where('is_del', 0)
                ->when($exist, function ($q) use ($exist) {
                    $q->where('id', '<>', (int)$exist['id']);
                })
                ->find();
            if ($dup) {
                throw new AdminException('该登录账号已被其他员工使用');
            }

            $credVer = 1;
            if ($exist) {
                $credVer = (int)$exist['credential_version'];
                $update = [
                    'account' => $account,
                    'status' => $status,
                    'update_time' => $now,
                ];
                if ($plainPwd !== null && $plainPwd !== '') {
                    $update['pwd'] = $this->passwordHash($plainPwd);
                    $credVer++;
                    $update['credential_version'] = $credVer;
                }
                if ((int)$exist['status'] !== $status) {
                    $credVer = max($credVer, (int)$exist['credential_version'] + 1);
                    $update['credential_version'] = $credVer;
                }
                Db::name('employee_internal_account')->where('id', (int)$exist['id'])->update($update);
                $pwdHash = (string)($update['pwd'] ?? $exist['pwd']);
            } else {
                if ($plainPwd === null || $plainPwd === '') {
                    throw new AdminException('请设置登录密码');
                }
                $pwdHash = $this->passwordHash($plainPwd);
                Db::name('employee_internal_account')->insert([
                    'employee_id' => $employeeId,
                    'account' => $account,
                    'pwd' => $pwdHash,
                    'status' => $status,
                    'credential_version' => 1,
                    'is_del' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                $credVer = 1;
            }

            $this->projectCredentials($employeeId, $account, $pwdHash, $status);
            $this->writeAudit($employeeId, $exist ? 'internal_account_update' : 'internal_account_create', [
                'account' => $account,
                'status' => $status,
                'credential_version' => $credVer,
                'pwd_changed' => ($plainPwd !== null && $plainPwd !== '') ? 1 : 0,
            ], $operatorContext);

            return [
                'employee_id' => $employeeId,
                'account' => $account,
                'credential_version' => $credVer,
            ];
        };
        if (!empty($options['use_outer_transaction'])) {
            return $runner();
        }
        return Db::transaction($runner);
    }

    /**
     * 投影账号密码到兼容表（禁止独立编辑）
     */
    public function projectCredentials(int $employeeId, string $account, string $pwdHash, int $status): void
    {
        $this->projectCredentialsPreservePhoneLogin($employeeId, $account, $pwdHash, $status);
    }

    /**
     * 投影密码/状态；若兼容表账号本就是手机号登录输入则保留，不覆盖为内部账号。
     */
    public function projectCredentialsPreservePhoneLogin(int $employeeId, string $account, string $pwdHash, int $status): void
    {
        $empPhone = (string)Db::name('employee')->where('id', $employeeId)->value('phone');
        $staffRows = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->select()
            ->toArray();
        foreach ($staffRows as $s) {
            $cur = trim((string)($s['account'] ?? ''));
            $keep = $cur !== '' && (
                preg_match('/^1\d{10}$/', $cur)
                || ($empPhone !== '' && $cur === $empPhone)
            );
            Db::name('system_store_staff')->where('id', (int)$s['id'])->update([
                'account' => $keep ? $cur : $account,
                'pwd' => $pwdHash,
            ]);
        }
        $admins = Db::name('system_admin')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('admin_type', 0)
            ->select()
            ->toArray();
        foreach ($admins as $a) {
            $cur = trim((string)($a['account'] ?? ''));
            $keep = $cur !== '' && (
                preg_match('/^1\d{10}$/', $cur)
                || ($empPhone !== '' && $cur === $empPhone)
            );
            Db::name('system_admin')->where('id', (int)$a['id'])->update([
                'account' => $keep ? $cur : $account,
                'pwd' => $pwdHash,
                'status' => $status === 1 ? 1 : 0,
            ]);
        }
    }

    /**
     * 登录：账号或手机号 + 密码 → employee_id（含状态校验）
     * @return array{employee_id:int,account_row:array,employee:array}
     */
    public function authenticateByPassword(string $account, string $pwd): array
    {
        $account = trim($account);
        $row = $this->resolveByLoginInput($account);
        if (!$row || (int)$row['status'] !== 1) {
            // 兼容：无统一账号时，允许无 employee_id 的旧平台账号走调用方旧逻辑
            throw new AdminException('账号或密码错误');
        }
        if (!password_verify($pwd, (string)$row['pwd'])) {
            throw new AdminException('账号或密码错误');
        }
        $emp = Db::name('employee')->where('id', (int)$row['employee_id'])->where('is_del', 0)->find();
        if (!$emp || (int)$emp['status'] !== 1) {
            throw new AdminException('员工已停用');
        }
        return [
            'employee_id' => (int)$row['employee_id'],
            'account_row' => $row,
            'employee' => is_array($emp) ? $emp : $emp->toArray(),
        ];
    }

    public function touchLogin(int $accountId, string $ip): void
    {
        if ($accountId <= 0) {
            return;
        }
        Db::name('employee_internal_account')->where('id', $accountId)->update([
            'last_ip' => mb_substr($ip, 0, 64),
            'last_time' => time(),
            'login_count' => Db::raw('login_count+1'),
            'update_time' => time(),
        ]);
    }

    /**
     * 当前登录员工修改自己的统一内部账号密码。
     * 账号、任职与权限不由客户端传入，调用方只能传入已认证会话中的 employee_id。
     */
    public function changeOwnPassword(int $employeeId, string $currentPassword, string $newPassword, array $operatorContext = []): array
    {
        $currentPassword = (string)$currentPassword;
        $newPassword = (string)$newPassword;
        if ($employeeId <= 0) {
            throw new AdminException('当前登录身份无效，请重新登录');
        }
        if ($currentPassword === '' || !password_verify($currentPassword, (string)($this->getByEmployeeId($employeeId)['pwd'] ?? ''))) {
            throw new AdminException('原密码错误');
        }
        if (strlen($newPassword) < 4 || strlen($newPassword) > 64) {
            throw new AdminException('新密码必须为4到64位');
        }
        if ($currentPassword === $newPassword) {
            throw new AdminException('新密码不能与原密码相同');
        }
        $account = $this->getByEmployeeId($employeeId);
        if (!$account || (int)($account['status'] ?? 0) !== 1) {
            throw new AdminException('当前账号已失效，请重新登录');
        }
        return $this->saveAccount(
            $employeeId,
            (string)$account['account'],
            $newPassword,
            1,
            $operatorContext
        );
    }

    /**
     * 当前登录员工同时修改统一登录账号和密码。
     * saveAccount 在事务内锁定账号并执行全局唯一性校验，兼容表由同一事务投影。
     */
    public function changeOwnCredentials(
        int $employeeId,
        string $currentPassword,
        string $newAccount,
        string $newPassword,
        array $operatorContext = []
    ): array {
        $currentPassword = (string)$currentPassword;
        $newPassword = (string)$newPassword;
        $account = $this->getByEmployeeId($employeeId);
        if ($employeeId <= 0 || !$account) {
            throw new AdminException('当前登录身份无效，请重新登录');
        }
        if ($currentPassword === '' || !password_verify($currentPassword, (string)($account['pwd'] ?? ''))) {
            throw new AdminException('原密码错误');
        }
        $this->assertValidAccountFormat($newAccount);
        $newAccount = trim($newAccount);
        if (strlen($newPassword) < 4 || strlen($newPassword) > 64) {
            throw new AdminException('新密码必须为4到64位');
        }
        if ($currentPassword === $newPassword) {
            throw new AdminException('新密码不能与原密码相同');
        }
        if ((int)($account['status'] ?? 0) !== 1) {
            throw new AdminException('当前账号已失效，请重新登录');
        }
        return $this->saveAccount(
            $employeeId,
            $newAccount,
            $newPassword,
            1,
            $operatorContext
        );
    }

    public function passwordHash(string $plainPwd): string
    {
        $plainPwd = (string)$plainPwd;
        if ($plainPwd === '') {
            throw new AdminException('请设置登录密码');
        }
        return password_hash($plainPwd, PASSWORD_BCRYPT);
    }

    /**
     * 凭证变更后递增版本（供权限变更即时失效）
     */
    public function bumpCredentialVersion(int $employeeId): void
    {
        if ($employeeId <= 0) {
            return;
        }
        Db::name('employee_internal_account')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->update([
                'credential_version' => Db::raw('credential_version+1'),
                'update_time' => time(),
            ]);
    }

    protected function writeAudit(int $employeeId, string $action, array $payload, array $op): void
    {
        try {
            Db::name('employee_change_log')->insert([
                'employee_id' => $employeeId,
                'action' => $action,
                'target_type' => 'internal_account',
                'target_id' => $employeeId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'operator_id' => (int)($op['operator_id'] ?? 0),
                'operator_name' => (string)($op['operator_name'] ?? ''),
                'operator_ip' => (string)($op['operator_ip'] ?? ''),
                'add_time' => time(),
            ]);
        } catch (\Throwable $e) {
            // 审计表字段差异时不阻断主流程
        }
    }
}
