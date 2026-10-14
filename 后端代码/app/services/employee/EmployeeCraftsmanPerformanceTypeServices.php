<?php
namespace app\services\employee;

use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * 手艺人服务业绩类型的唯一规则入口。
 * 任职类型仍以门店任职为准；无店员工保存独立默认值，仅供新任职初始化。
 */
class EmployeeCraftsmanPerformanceTypeServices
{
    public const COMMISSION = 'commission';
    public const LABOR = 'labor';
    public const COMMISSION_LABOR = 'commission_labor';

    public const TYPES = [self::COMMISSION, self::LABOR, self::COMMISSION_LABOR];

    /** 调用方已完成员工维护权限校验，且必须置于完整保存事务内。 */
    public function saveDefaultInTx(int $employeeId, string $type, int $version, array $context): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new AdminException('手艺人服务业绩类型无效');
        }
        $employee = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)
            ->lock(true)->find();
        if (!$employee || $version < 1 || (int)$employee['craftsman_setting_version'] !== $version) {
            throw new AdminException('手艺人服务业绩类型已变更，请刷新员工档案后重试');
        }
        $before = $employee['default_craftsman_performance_type'];
        if ($before === $type) return;
        Db::name('employee')->where('id', $employeeId)->update([
            'default_craftsman_performance_type' => $type,
            'craftsman_setting_version' => $version + 1, 'update_time' => time(),
        ]);
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId, 'action' => 'craftsman_default_update',
            'target_type' => 'employee', 'target_id' => $employeeId, 'source' => 'admin',
            'before_data' => json_encode(['type' => $before, 'version' => $version]),
            'after_data' => json_encode(['type' => $type, 'version' => $version + 1]),
            'reason' => '保存无店员工手艺人服务业绩类型', 'operator_type' => 'admin',
            'operator_id' => (int)($context['operator_id'] ?? 0),
            'operator_name' => (string)($context['operator_name'] ?? ''),
            'operator_ip' => (string)($context['operator_ip'] ?? ''),
            'request_id' => (string)($context['request_id'] ?? ''), 'add_time' => time(),
        ]);
    }

    public function normalize($value, string $default = self::COMMISSION): string
    {
        $value = trim((string)$value);
        if (!in_array($value, self::TYPES, true)) {
            $value = $default;
        }
        if (!in_array($value, self::TYPES, true)) {
            throw new AdminException('手艺人服务业绩类型无效');
        }
        return $value;
    }

    /** 返回界面和下游读取使用的确定性能力投影。 */
    public function project(string $type): array
    {
        $type = $this->normalize($type);
        return [
            'craftsman_performance_type' => $type,
            'craftsman_performance_enabled' => $type === self::LABOR ? 0 : 1,
            'craftsman_labor_enabled' => $type === self::COMMISSION ? 0 : 1,
        ];
    }
}
