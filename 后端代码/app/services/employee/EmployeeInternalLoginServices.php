<?php
namespace app\services\employee;

use app\services\BaseServices;
use app\services\organization\JobPositionPolicyServices;
use app\services\organization\StaffJobPositionServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * 统一登录：凭证 → employee_id → 端口资格 → 当前唯一任职（不做多店选择）
 */
class EmployeeInternalLoginServices extends BaseServices
{
    /**
     * 当前唯一有效门店任职（本阶段不做多店选店）
     */
    public function resolveCurrentStaff(int $employeeId): ?array
    {
        if ($employeeId <= 0) {
            return null;
        }
        $rows = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('store_id', '>', 0)
            ->order('id', 'desc')
            ->select()
            ->toArray();
        if (!$rows) {
            return null;
        }
        // 禁止 store_id=0 占位；多条有效真实任职取最新一条（本阶段不做多店选择）
        return $rows[0];
    }

    /**
     * 员工在指定门店任职上是否具备某端入口（岗位入口开 + 有规则）
     */
    public function staffHasChannelEntry(int $staffId, string $channel): bool
    {
        if ($staffId <= 0) {
            return false;
        }
        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        $jobs = $jobSvc->listActiveJobs($staffId);
        if (!$jobs) {
            return false;
        }
        $posIds = array_values(array_unique(array_filter(array_map('intval', array_column($jobs, 'position_id')))));
        if (!$posIds) {
            return false;
        }
        $col = $this->entryColumn($channel);
        if ($col === '') {
            return false;
        }
        $opened = (int)Db::name('position')
            ->whereIn('id', $posIds)
            ->where('status', 1)
            ->where($col, 1)
            ->count();
        if ($opened <= 0) {
            return false;
        }
        $rules = $jobSvc->computeChannelRulesUnion($staffId, $channel);
        return count($rules) > 0;
    }

    /**
     * 平台后台：是否有平台入口岗位（不依赖门店任职）
     */
    public function employeeHasPlatformEntry(int $employeeId): bool
    {
        if ($employeeId <= 0) {
            return false;
        }
        $staffIds = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->column('id');
        $staffIds = array_map('intval', $staffIds ?: []);
        // 组织直属人员：通过岗位绑定到任一 staff 或直接查员工全部岗位绑
        $posIds = Db::name('staff_job_position')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('end_time', 0)
            ->column('position_id');
        $posIds = array_values(array_unique(array_filter(array_map('intval', $posIds ?: []))));
        if (!$posIds) {
            return false;
        }
        $opened = (int)Db::name('position')
            ->whereIn('id', $posIds)
            ->where('status', 1)
            ->where('use_platform', 1)
            ->count();
        if ($opened <= 0) {
            return false;
        }
        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        if ($staffIds) {
            foreach ($staffIds as $sid) {
                $rules = $jobSvc->computeChannelRulesUnion((int)$sid, JobPositionPolicyServices::CHANNEL_PLATFORM);
                if ($rules) {
                    return true;
                }
            }
        }
        // 无门店任职时：按岗位规则并集
        $ruleRows = Db::name('job_position_channel_rule')
            ->whereIn('position_id', $posIds)
            ->where('channel', JobPositionPolicyServices::CHANNEL_PLATFORM)
            ->where('status', 1)
            ->column('rules');
        foreach ($ruleRows as $r) {
            if (trim((string)$r) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * 解析或创建平台 system_admin 投影（admin_type=0）
     */
    public function resolvePlatformAdmin(int $employeeId, array $accountRow, array $employee): array
    {
        $admin = Db::name('system_admin')
            ->where('employee_id', $employeeId)
            ->where('admin_type', 0)
            ->where('is_del', 0)
            ->order('id', 'asc')
            ->find();
        if ($admin) {
            return is_array($admin) ? $admin : $admin->toArray();
        }
        $now = time();
        $id = (int)Db::name('system_admin')->insertGetId([
            'account' => (string)$accountRow['account'],
            'pwd' => (string)$accountRow['pwd'],
            'real_name' => (string)($employee['name'] ?? ''),
            'phone' => (string)($employee['phone'] ?? ''),
            'roles' => '',
            'level' => 1,
            'admin_type' => 0,
            'status' => 1,
            'is_del' => 0,
            'employee_id' => $employeeId,
            'add_time' => $now,
        ]);
        $admin = Db::name('system_admin')->where('id', $id)->find();
        return is_array($admin) ? $admin : $admin->toArray();
    }

    protected function entryColumn(string $channel): string
    {
        $map = [
            JobPositionPolicyServices::CHANNEL_PLATFORM => 'use_platform',
            JobPositionPolicyServices::CHANNEL_STORE_BACKEND => 'use_store',
            JobPositionPolicyServices::CHANNEL_CASHIER => 'use_cashier',
            JobPositionPolicyServices::CHANNEL_MOBILE => 'use_mobile',
        ];
        return $map[$channel] ?? '';
    }
}
