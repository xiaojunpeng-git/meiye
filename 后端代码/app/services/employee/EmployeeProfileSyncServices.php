<?php
namespace app\services\employee;

use think\facade\Db;

/**
 * @deprecated I1 起员工/任职权威写请使用 EmployeeStaffWriteServices。
 * 兼容绑定：仅在 staff 尚未关联 employee 时按手机号绑定 employee_id；
 * 命中已有主档时不覆盖姓名/头像；禁止新写/清零/回写商城 UID。
 */
class EmployeeProfileSyncServices
{
    public const PHONE_REGEX = EmployeeMigrateServices::PHONE_REGEX;
    public const DEFAULT_AVATAR = EmployeeMigrateServices::DEFAULT_AVATAR;
    public const DEFAULT_AVATAR_TYPE = EmployeeMigrateServices::DEFAULT_AVATAR_TYPE;

    /**
     * @param array{operator_id?:int,operator_name?:string,operator_ip?:string,request_id?:string,source?:string,reason?:string} $operatorContext
     * @return int employee_id
     */
    public function syncFromStaff(int $staffId, array $operatorContext = []): int
    {
        if ($staffId <= 0) {
            throw new \Exception('店员ID无效');
        }
        $staff = Db::name('system_store_staff')->where('id', $staffId)->lock(true)->find();
        if (!$staff || (int)($staff['is_del'] ?? 0) === 1) {
            throw new \Exception('店员不存在或已删除');
        }

        /** @var EmployeeStaffWriteServices $write */
        $write = app()->make(EmployeeStaffWriteServices::class);
        $phone = $write->assertStrictPhone((string)($staff['phone'] ?? ''));
        $name = trim((string)($staff['staff_name'] ?? ''));
        if ($name === '') {
            $name = '未命名员工';
        }
        $avatar = trim((string)($staff['avatar'] ?? ''));
        if ($avatar === '') {
            $avatar = self::DEFAULT_AVATAR;
        }
        $avatarType = $this->resolveAvatarType($avatar);

        $linkedId = (int)($staff['employee_id'] ?? 0);
        $time = time();

        if ($linkedId > 0) {
            $employee = Db::name('employee')->where('id', $linkedId)->lock(true)->find();
            if (!$employee) {
                throw new \Exception('店员关联的员工主档不存在，请勿覆盖其他员工');
            }
            if ((int)($employee['is_del'] ?? 0) === 1) {
                throw new \Exception('店员关联的员工主档已删除，无法同步');
            }
            // 已关联：兼容入口不再改写主档（避免旁路覆盖）
            return $linkedId;
        }

        $employee = Db::name('employee')->where('phone', $phone)->lock(true)->find();
        if ($employee) {
            if ((int)($employee['is_del'] ?? 0) === 1) {
                throw new \Exception('该手机号对应员工主档已删除，请联系总部处理');
            }
            $employeeId = (int)$employee['id'];
            // 命中已有主档：只绑定任职，不覆盖姓名/头像
            Db::name('system_store_staff')->where('id', $staffId)->update([
                'employee_id' => $employeeId,
            ]);
            $this->writeEmployeeChangeLog(
                $employeeId,
                'staff_profile_bind',
                'staff',
                $staffId,
                [],
                ['employee_id' => $employeeId, 'staff_id' => $staffId, 'profile_updated' => false],
                (string)($operatorContext['reason'] ?? '兼容绑定员工主档（不覆盖资料）'),
                $operatorContext
            );
            return $employeeId;
        }

        $employeeId = (int)Db::name('employee')->insertGetId([
            'name' => $name,
            'phone' => $phone,
            'avatar' => $avatar,
            'avatar_type' => $avatarType,
            'uid' => null,
            'status' => 1,
            'is_del' => 0,
            'add_time' => $time,
            'update_time' => $time,
        ]);
        if ($employeeId <= 0) {
            throw new \Exception('创建员工主档失败');
        }
        Db::name('system_store_staff')->where('id', $staffId)->update([
            'employee_id' => $employeeId,
        ]);
        $this->writeEmployeeChangeLog(
            $employeeId,
            'staff_profile_create',
            'staff',
            $staffId,
            [],
            ['employee_id' => $employeeId, 'staff_id' => $staffId, 'uid' => null],
            (string)($operatorContext['reason'] ?? '兼容创建员工主档'),
            $operatorContext
        );
        return $employeeId;
    }

    /**
     * @deprecated 不再进入员工主档写流程
     * @return array{touch:bool,uid:?int}
     */
    protected function resolveUidWriteValue(int $uidCandidate, int $employeeId): array
    {
        return ['touch' => false, 'uid' => null];
    }

    protected function resolveAvatarType(string $avatar): int
    {
        if ($avatar === '' || $avatar === self::DEFAULT_AVATAR || preg_match('/avatar_male\.(png|svg)/i', $avatar)) {
            return 1;
        }
        if (preg_match('/avatar_female\.(png|svg)/i', $avatar)) {
            return 2;
        }
        return 3;
    }

    protected function writeEmployeeChangeLog(
        int $employeeId,
        string $action,
        string $targetType,
        int $targetId,
        array $before,
        array $after,
        string $reason,
        array $operatorContext
    ): void {
        $beforeJson = $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : '';
        $afterJson = $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : '';
        if ($before && $beforeJson === false) {
            throw new \Exception('员工审计序列化失败');
        }
        if ($after && $afterJson === false) {
            throw new \Exception('员工审计序列化失败');
        }
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'source' => (string)($operatorContext['source'] ?? 'admin'),
            'before_data' => $beforeJson ?: '',
            'after_data' => $afterJson ?: '',
            'reason' => $reason,
            'operator_type' => 'admin',
            'operator_id' => (int)($operatorContext['operator_id'] ?? 0),
            'operator_name' => (string)($operatorContext['operator_name'] ?? ''),
            'operator_ip' => (string)($operatorContext['operator_ip'] ?? ''),
            'request_id' => (string)($operatorContext['request_id'] ?? ''),
            'add_time' => time(),
        ]);
    }
}
