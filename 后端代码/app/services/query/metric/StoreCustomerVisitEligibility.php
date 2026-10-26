<?php

declare(strict_types=1);

namespace app\services\query\metric;

/**
 * 门店顾客到店资格只看完成服务冻结的体验标记；售后人次再按会员日去重。
 * 调用方仍负责完成与作废状态、门店权限及业务日期范围；
 * 人员“不算人数人次”开关不属于门店资格，金额和真实护理记录不得套用本过滤器。
 */
final class StoreCustomerVisitEligibility
{
    public const VERSION = 'store-customer-visit-member-day-v2';
    public const EXPLANATION = '已完成且未作废的非体验服务按同店同会员同业务日计售后人次；未建档按服务单计，“不算人数人次”只影响人员统计。';

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

    /**
     * A completed non-experience visit is one member at one store on one business day.
     * Anonymous services have no stable person identity, so only lines of the same
     * service document are merged; unrelated anonymous documents remain separate.
     */
    public static function dailyVisitKey(array $fact): ?string
    {
        if (!self::allows($fact) || (int)($fact['quantity'] ?? 0) <= 0) return null;
        $store = (int)($fact['store_id'] ?? 0);
        $date = (string)($fact['business_date'] ?? '');
        if ($store <= 0 || $date === '') return null;
        $member = (int)($fact['member_id'] ?? 0);
        if ($member > 0) return implode('|', [$store, $date, 'member', $member]);
        foreach (['service_record_no', 'checkout_request_id', 'service_fact_id', 'fact_id'] as $field) {
            $value = trim((string)($fact[$field] ?? ''));
            if ($value !== '') return implode('|', [$store, $date, $field, $value]);
        }
        return null;
    }

    /** Count the same eligible member-day cohort used by report drilldowns. */
    public static function countDailyVisits(array $facts): int
    {
        $keys = [];
        foreach ($facts as $fact) {
            $key = self::dailyVisitKey($fact);
            if ($key !== null) $keys[$key] = true;
        }
        return count($keys);
    }

    /** MySQL 5.7 expression matching dailyVisitKey(), for grouped store reports. */
    public static function sqlDailyVisitKey(string $alias): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid service fact alias');
        }
        return "CASE WHEN COALESCE({$alias}.quantity,0)>0 THEN CONCAT({$alias}.store_id,'|',{$alias}.business_date,'|',"
            . "CASE WHEN {$alias}.member_id>0 THEN CONCAT('member|',{$alias}.member_id) "
            . "ELSE COALESCE(CONCAT('service_record_no|',NULLIF({$alias}.service_record_no,'')),"
            . "CONCAT('checkout_request_id|',NULLIF({$alias}.checkout_request_id,'')),"
            . "CONCAT('service_fact_id|',NULLIF({$alias}.service_fact_id,''))) END) END";
    }
}
