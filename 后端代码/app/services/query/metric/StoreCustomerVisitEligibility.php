<?php

declare(strict_types=1);

namespace app\services\query\metric;

/**
 * 门店顾客到店／人次资格只看完成服务冻结的体验标记。
 * 调用方仍负责完成与作废状态、门店权限、业务日期及去重粒度；
 * 人员“不算人数人次”开关不属于门店资格，金额和真实护理记录不得套用本过滤器。
 */
final class StoreCustomerVisitEligibility
{
    public const VERSION = 'store-customer-visit-experience-only-v1';
    public const EXPLANATION = '已完成且未作废的非体验服务计门店到店；“体验”不计门店人次，“不算人数人次”只影响人员统计。';

    /** 按事实中的冻结标记判断；历史空值与既有市场报表一样视为非体验。 */
    public static function allows(array $fact): bool
    {
        return (int)($fact['is_experience'] ?? 0) === 0;
    }

    /** SQL 与 PHP 使用相同的空值兼容规则，仅接受服务端固定的事实别名。 */
    public static function sqlPredicate(string $alias): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid service fact alias');
        }
        return "COALESCE({$alias}.is_experience,0)=0";
    }

    /** 缩小已经完成权限和作废过滤的服务查询，不改写底层服务事实。 */
    public static function apply($query, string $alias)
    {
        return $query->whereRaw(self::sqlPredicate($alias));
    }

    /** 保留输入顺序与稳定事实内容，只投影门店可计的服务。 */
    public static function filter(array $facts): array
    {
        return array_values(array_filter($facts, [self::class, 'allows']));
    }
}
