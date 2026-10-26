<?php
namespace app\services\report;

/**
 * Shared first-completed-course cohort predicate for reports and AI.
 * Callers must bind tenant, authorized stores and any participant/organization
 * scope before reading; this layer owns the immutable event and void rules.
 */
final class FirstCourseMemberCohortServices
{
    public function apply($query, string $alias, array $range, int $sourcePrimaryId = 0, int $sourceSecondaryId = 0)
    {
        $query->where($alias.'.event_type', 'first_course_completed')
            ->where($alias.'.status', 'effective')
            ->where($alias.'.member_id', '>', 0)
            ->whereBetween($alias.'.business_date', [$range['start'], $range['end']]);
        if ($sourcePrimaryId > 0) $query->where($alias.'.source_primary_id', $sourcePrimaryId);
        if ($sourceSecondaryId > 0) $query->where($alias.'.source_secondary_id', $sourceSecondaryId);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts(
            $query, $alias.'.tenant_id', $alias.'.related_order_id'
        );
        return $query;
    }
}
