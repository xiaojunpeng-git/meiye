<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\query\metric\StoreCustomerVisitEligibility;
use think\facade\Db;

/**
 * Reconstruct report-only customer stages from effective lifecycle events and
 * service facts. The projection remains an audit/history read model and must
 * never be deleted just because its originating order was voided.
 */
final class CustomerLifecycleNormalReadServices
{
    /** @return array<int,array<string,mixed>> keyed by member ID */
    public function forMembers(string $tenantId, array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if ($memberIds === []) return [];

        $events = Db::name(CustomerLifecycleFactServices::FACT_TABLE)->alias('life')
            ->where('life.tenant_id', $tenantId)->whereIn('life.member_id', $memberIds)
            ->whereIn('life.event_type', ['first_course_completed', 'pending_conversion'])
            ->where('life.status', 'effective');
        // The immutable event is still present after void; its related sales
        // order decides whether it may influence a current operating report.
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts(
            $events, 'life.tenant_id', 'life.related_order_id'
        );
        $rows = $events->field('life.id,life.member_id,life.event_type,life.business_date,life.occurred_at,life.related_order_id,life.source_label_snapshot,life.is_guest')
            ->order('life.occurred_at', 'asc')->order('life.id', 'asc')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $memberId = (int)$row['member_id'];
            $result[$memberId] ??= $this->emptyStage();
            if ((string)$row['event_type'] === 'pending_conversion') {
                // The writer retains the event for audit after a first course,
                // but does not mark that customer's projection as pending.
                if ($result[$memberId]['first_course_completed_at'] === 0) {
                    $result[$memberId]['has_pending_conversion'] = true;
                }
            } elseif ($result[$memberId]['first_course_completed_at'] === 0) {
                $result[$memberId]['first_course_completed_at'] = (int)$row['occurred_at'];
                $result[$memberId]['first_course_order_id'] = (string)$row['related_order_id'];
                $result[$memberId]['first_course_business_date'] = (string)$row['business_date'];
                $result[$memberId]['first_course_source_label_snapshot'] = (string)$row['source_label_snapshot'];
                $result[$memberId]['is_guest'] = (int)$row['is_guest'];
            }
        }

        $visits = Db::name(CustomerLifecycleFactServices::FACT_TABLE)->alias('life')
            ->join('cashier_v3_entitlement_service_fact service',
                "service.tenant_id=life.tenant_id AND service.member_id=life.member_id"
                . " AND life.natural_key=CONCAT('service-visit:',service.checkout_request_id)")
            ->where('life.tenant_id', $tenantId)->whereIn('life.member_id', $memberIds)
            ->where('life.event_type', 'service_completed')->where('life.status', 'effective')
            ->where('service.service_status', 'completed');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($visits, 'service');
        // 生命周期的到店次数沿用统一门店资格，不能受手艺人人员统计开关影响。
        StoreCustomerVisitEligibility::apply($visits, 'service');
        $visitRows = $visits->fieldRaw('life.member_id,COUNT(DISTINCT life.id) visit_count,MAX(life.occurred_at) last_service_at')
            ->group('life.member_id')->select()->toArray();
        foreach ($visitRows as $row) {
            $memberId = (int)$row['member_id'];
            $result[$memberId] ??= $this->emptyStage();
            $result[$memberId]['service_visit_count'] = (int)$row['visit_count'];
            $result[$memberId]['last_service_at'] = (int)$row['last_service_at'];
        }

        foreach ($result as &$stage) {
            $courseAt = (int)$stage['first_course_completed_at'];
            $visits = (int)$stage['service_visit_count'];
            $pending = (bool)$stage['has_pending_conversion'];
            $lastVisit = (int)$stage['last_service_at'];
            if ($courseAt > 0) {
                // Completing a course after one earlier visit resets the
                // customer to pre-sale; a later visit or a second visit converts.
                $stage['lifecycle_stage'] = $visits >= 2 || ($pending && $lastVisit > $courseAt)
                    ? 'post_sale' : 'pre_sale';
            } elseif ($pending) {
                $stage['lifecycle_stage'] = $visits > 0 ? 'post_sale' : 'pending_conversion';
            }
        }
        unset($stage);
        return $result;
    }

    /** @return array<string,mixed> */
    private function emptyStage(): array
    {
        return [
            'lifecycle_stage' => '', 'service_visit_count' => 0,
            'last_service_at' => 0, 'first_course_completed_at' => 0,
            'first_course_order_id' => '',
            'first_course_business_date' => '', 'first_course_source_label_snapshot' => '',
            'has_pending_conversion' => false, 'is_guest' => 0,
        ];
    }
}
