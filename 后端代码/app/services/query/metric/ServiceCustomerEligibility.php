<?php

namespace app\services\query\metric;

/**
 * 服务客数指标的唯一资格规则（不是服务/金额事实的全局过滤器）。
 * 只读取完成服务冻结的顾客对象与计数开关；调用方仍负责权限、日期、
 * 成功/冲销范围和指标声明的去重粒度，禁止用于排除消耗或手工费。
 */
final class ServiceCustomerEligibility
{
    public const VERSION = 'service-customer-eligibility-v1';
    public const EXPLANATION = '选择“朋友、不计人次”的服务不计入人数或到店次数；实际服务消耗与手工费仍保留。';

    /** PHP 投影与 SQL 查询必须等价：本人不受朋友计数开关影响。 */
    public static function allows(array $fact): bool
    {
        return (string)($fact['service_object'] ?? '') !== 'friend'
            || (int)($fact['friend_counts_as_customer'] ?? 0) === 1;
    }

    /** 只接受后端代码中的事实别名，不允许客户端提供 SQL 标识符。 */
    public static function apply($query, string $alias)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid service fact alias');
        }
        return $query->whereRaw("({$alias}.service_object <> 'friend' OR {$alias}.friend_counts_as_customer = 1)");
    }

    /** 混合指标报表先保留完整服务事实，只在人/到店计算入口缩小集合。 */
    public static function filter(array $facts): array
    {
        return array_values(array_filter($facts, [self::class, 'allows']));
    }
}
