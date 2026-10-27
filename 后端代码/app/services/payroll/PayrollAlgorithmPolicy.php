<?php
declare(strict_types=1);
namespace app\services\payroll;

/** 唯一算法选择入口。缺省继续现有算法；职位模型不随手工费选项切换。 */
final class PayrollAlgorithmPolicy
{
    public static function calculated(): bool { return function_exists('sys_config') && (int)sys_config('cashier_labor_fee_mode', 0) === 1; }
    public static function independentPositions(): bool { return function_exists('config') && config('payroll.position_model', 'legacy') === 'independent'; }
    public static function projection(): array
    {
        return ['code'=>self::calculated() ? 'project_tiers' : 'existing',
            'label'=>self::calculated() ? '项目档位计算（预计）' : '现有手工费',
            'independent_positions'=>self::independentPositions(), 'settled'=>false];
    }
}
