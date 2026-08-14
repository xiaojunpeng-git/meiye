<?php
namespace app\services\employee;

use mohe\exceptions\AdminException;

/**
 * 手艺人服务业绩类型的唯一规则入口。
 * 类型存储在门店任职上，避免同一员工跨门店配置相互覆盖。
 */
class EmployeeCraftsmanPerformanceTypeServices
{
    public const COMMISSION = 'commission';
    public const LABOR = 'labor';
    public const COMMISSION_LABOR = 'commission_labor';

    public const TYPES = [self::COMMISSION, self::LABOR, self::COMMISSION_LABOR];

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
