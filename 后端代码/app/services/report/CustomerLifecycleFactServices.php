<?php

namespace app\services\report;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use think\facade\Db;

/**
 * Transactional lifecycle facts.  This service is intentionally fed only by
 * settled checkout/service authorities; it never infers lifecycle from report
 * queries or mutable member/source records.
 */
final class CustomerLifecycleFactServices
{
    public const FACT_TABLE = 'cashier_v3_customer_lifecycle_fact';
    public const PROJECTION_TABLE = 'cashier_v3_customer_lifecycle_projection';
    public const VERSION = 'customer-lifecycle-v1';

    public function recordCheckoutInTx(CashierV3CheckoutFactPlanV1 $plan): void
    {
        CashierV3TransactionGuard::assertInTransaction('customerLifecycle.recordCheckoutInTx');
        $context = $plan->context();
        // Direct cashier snapshot checkouts are settled sales as well. They
        // carry the same immutable source snapshots as workspace/sales-order
        // checkouts and must enter the first-course lifecycle projection;
        // excluding them leaves the source-analysis report empty even though
        // the sale/payment facts were written successfully.
        if ((int)$context['member_id'] <= 0 || !in_array((string)$context['source_document_type'], ['cashier_snapshot', 'cashier_workspace', 'sales_order', 'checkout'], true)) return;

        $cards = [];
        $nonCards = [];
        foreach ($plan->rows()['sale'] as $sale) {
            if ((string)$sale['fact_direction'] !== CashierV3CheckoutFactPlanV1::DIRECTION_FORWARD) continue;
            if ((string)$sale['source_type'] === 'card') $cards[] = $sale;
            else $nonCards[] = $sale;
        }
        if ($cards) {
            // The upgrade settlement is persisted earlier in the same
            // checkout transaction.  Do not inspect mutable workspace drafts:
            // their UI line id is not the final sales fact line id and they
            // cannot be the authority for a customer-lifecycle decision.
            if ($this->isUpgradeSettlement($context)) return;
            $first = $cards[0];
            if ((int)$first['debt_amount_cents'] > 0) {
                $this->append($context, 'course_pending_settlement', 'course-pending:' . $context['order_id'], '', []);
                return;
            }
            $this->completeFirstCourse($context, 'first-course:' . $context['order_id'], []);
            return;
        }
        if ($nonCards) {
            $this->recordPendingConversion($context);
        }
    }

    /** A sales-debt settlement promotes the source order's frozen pending-card fact. */
    public function recordDebtCompletionInTx(string $tenantId, string $salesOrderId, int $completedAt): void
    {
        CashierV3TransactionGuard::assertInTransaction('customerLifecycle.recordDebtCompletionInTx');
        $pending = Db::name(self::FACT_TABLE)->where('tenant_id', $tenantId)
            ->where('related_order_id', $salesOrderId)->where('event_type', 'course_pending_settlement')
            ->lock(true)->find();
        if (!$pending) return;
        $context = [
            'tenant_id' => (string)$pending['tenant_id'], 'organization_id' => (string)$pending['organization_id'],
            'store_id' => (int)$pending['store_id'], 'member_id' => (int)$pending['member_id'],
            'order_id' => (string)$pending['related_order_id'], 'order_no_snapshot' => (string)$pending['related_order_no_snapshot'],
            'business_date' => date('Y-m-d', $completedAt), 'occurred_at' => $completedAt, 'recorded_at' => $completedAt,
            'business_source_primary_id' => (int)$pending['source_primary_id'],
            'business_source_primary_name_snapshot' => (string)$pending['source_primary_name_snapshot'],
            'business_source_secondary_id' => (int)$pending['source_secondary_id'],
            'business_source_secondary_name_snapshot' => (string)$pending['source_secondary_name_snapshot'],
            'business_source_label_snapshot' => (string)$pending['source_label_snapshot'],
            'source_attribution_type_snapshot' => (string)$pending['source_attribution_type_snapshot'],
        ];
        $this->completeFirstCourse($context, 'first-course:' . $salesOrderId, ['settled_from_debt' => 1]);
    }

    public function recordServiceCompletionInTx(array $context, array $serviceRows): void
    {
        CashierV3TransactionGuard::assertInTransaction('customerLifecycle.recordServiceCompletionInTx');
        if (!$serviceRows || (int)($context['member_id'] ?? 0) <= 0) return;
        $natural = 'service-visit:' . (string)$context['checkout_request_id'];
        if (!$this->append($context, 'service_completed', $natural, '', [])) return;
        $projection = $this->projectionForUpdate($context);
        $visits = (int)$projection['service_visit_count'] + 1;
        $stage = (string)$projection['lifecycle_stage'];
        // A pending non-course order converts on the first completed care;
        // a customer with a settled course converts on their second visit.
        if ((int)$projection['has_pending_conversion'] === 1 || ((int)$projection['first_course_completed_at'] > 0 && $visits >= 2)) {
            $stage = 'post_sale';
        }
        $now = (int)$context['recorded_at'];
        Db::name(self::PROJECTION_TABLE)->where('id', $projection['id'])->update([
            'organization_id' => (string)$context['organization_id'], 'store_id' => (int)$context['store_id'],
            'lifecycle_stage' => $stage, 'service_visit_count' => $visits,
            'first_service_at' => (int)$projection['first_service_at'] ?: (int)$context['occurred_at'],
            'last_service_at' => (int)$context['occurred_at'], 'version' => (int)$projection['version'] + 1,
            'updated_at' => $now,
        ]);
    }

    private function recordPendingConversion(array $context): void
    {
        if (!$this->append($context, 'pending_conversion', 'pending-conversion:' . $context['order_id'], 'pending_conversion', [])) return;
        $projection = $this->projectionForUpdate($context);
        if ((int)$projection['first_course_completed_at'] > 0) return;
        Db::name(self::PROJECTION_TABLE)->where('id', $projection['id'])->update([
            'organization_id' => (string)$context['organization_id'], 'store_id' => (int)$context['store_id'],
            'lifecycle_stage' => (int)$projection['service_visit_count'] > 0 ? 'post_sale' : 'pending_conversion',
            'has_pending_conversion' => 1, 'pending_conversion_at' => (int)$context['occurred_at'],
            'version' => (int)$projection['version'] + 1, 'updated_at' => (int)$context['recorded_at'],
        ]);
    }

    private function completeFirstCourse(array $context, string $natural, array $extra): void
    {
        $projection = $this->projectionForUpdate($context);
        if ((int)$projection['first_course_completed_at'] > 0) return;
        $isGuest = $this->isGuestAtFirstCourse((string)$context['tenant_id'], (int)$context['member_id']);
        if (!$this->append($context, 'first_course_completed', $natural, 'pre_sale', $extra, $isGuest)) return;
        Db::name(self::PROJECTION_TABLE)->where('id', $projection['id'])->update([
            'organization_id' => (string)$context['organization_id'], 'store_id' => (int)$context['store_id'],
            'lifecycle_stage' => (int)$projection['service_visit_count'] >= 2 ? 'post_sale' : 'pre_sale',
            'first_course_order_id' => (string)$context['order_id'], 'first_course_order_no_snapshot' => (string)$context['order_no_snapshot'],
            'first_course_completed_at' => (int)$context['occurred_at'], 'first_course_business_date' => (string)$context['business_date'],
            'first_course_source_primary_id' => (int)$context['business_source_primary_id'],
            'first_course_source_primary_name_snapshot' => (string)$context['business_source_primary_name_snapshot'],
            'first_course_source_secondary_id' => (int)$context['business_source_secondary_id'],
            'first_course_source_secondary_name_snapshot' => (string)$context['business_source_secondary_name_snapshot'],
            'first_course_source_label_snapshot' => (string)$context['business_source_label_snapshot'],
            'first_course_source_attribution_type_snapshot' => $this->sourceType($context),
            'referrer_member_id' => $isGuest['referrer'], 'is_guest' => $isGuest['guest'],
            'version' => (int)$projection['version'] + 1, 'updated_at' => (int)$context['recorded_at'],
        ]);
    }

    private function append(array $context, string $event, string $natural, string $stage, array $extra, array $guest = []): bool
    {
        $tenant = (string)$context['tenant_id'];
        $existing = Db::name(self::FACT_TABLE)->where('tenant_id', $tenant)->where('natural_key', $natural)->lock(true)->find();
        if ($existing) return false;
        $now = (int)$context['recorded_at'];
        $guest = $guest ?: ['referrer' => 0, 'guest' => 0];
        $row = [
            'fact_id' => 'CLF-' . substr(hash('sha256', $tenant . "\0" . $natural), 0, 40), 'natural_key' => $natural,
            'tenant_id' => $tenant, 'organization_id' => (string)$context['organization_id'], 'store_id' => (int)$context['store_id'],
            'member_id' => (int)$context['member_id'], 'event_type' => $event, 'lifecycle_stage_after' => $stage,
            'related_order_id' => (string)($context['order_id'] ?? ''), 'related_order_no_snapshot' => (string)($context['order_no_snapshot'] ?? ''),
            'source_primary_id' => (int)($context['business_source_primary_id'] ?? 0), 'source_primary_name_snapshot' => (string)($context['business_source_primary_name_snapshot'] ?? ''),
            'source_secondary_id' => (int)($context['business_source_secondary_id'] ?? 0), 'source_secondary_name_snapshot' => (string)($context['business_source_secondary_name_snapshot'] ?? ''),
            'source_label_snapshot' => (string)($context['business_source_label_snapshot'] ?? ''), 'source_attribution_type_snapshot' => $this->sourceType($context),
            'referrer_member_id' => (int)$guest['referrer'], 'is_guest' => (int)$guest['guest'], 'business_date' => (string)$context['business_date'],
            'occurred_at' => (int)$context['occurred_at'], 'recorded_at' => $now, 'status' => 'effective',
        ];
        try { Db::name(self::FACT_TABLE)->insert($row); } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') === false && (int)$e->getCode() !== 1062) throw $e;
            return false;
        }
        return true;
    }

    private function projectionForUpdate(array $context): array
    {
        $tenant = (string)$context['tenant_id']; $memberId = (int)$context['member_id'];
        $row = Db::name(self::PROJECTION_TABLE)->where('tenant_id', $tenant)->where('member_id', $memberId)->lock(true)->find();
        if ($row) return $row;
        $now = (int)$context['recorded_at'];
        try { Db::name(self::PROJECTION_TABLE)->insert(['tenant_id'=>$tenant,'member_id'=>$memberId,'organization_id'=>(string)$context['organization_id'],'store_id'=>(int)$context['store_id'],'created_at'=>$now,'updated_at'=>$now]); }
        catch (\Throwable $e) { if (stripos($e->getMessage(), 'duplicate') === false && (int)$e->getCode() !== 1062) throw $e; }
        $row = Db::name(self::PROJECTION_TABLE)->where('tenant_id', $tenant)->where('member_id', $memberId)->lock(true)->find();
        if (!$row) throw new \RuntimeException('customer_lifecycle_projection_create_failed');
        return $row;
    }

    private function sourceType(array $context): string
    {
        if (!empty($context['source_attribution_type_snapshot'])) return (string)$context['source_attribution_type_snapshot'];
        $ids = array_filter([(int)($context['business_source_primary_id'] ?? 0), (int)($context['business_source_secondary_id'] ?? 0)]);
        if (!$ids) return 'other';
        $rows = Db::name('cashier_v3_business_source')->whereIn('id', $ids)->lock(true)->field('id,attribution_type')->select()->toArray();
        foreach ($rows as $row) if ((string)$row['attribution_type'] === 'guide') return 'guide';
        $secondary = (int)($context['business_source_secondary_id'] ?? 0);
        foreach ($rows as $row) if ((int)$row['id'] === $secondary) return (string)$row['attribution_type'];
        return (string)($rows[0]['attribution_type'] ?? 'other');
    }

    private function isGuestAtFirstCourse(string $tenantId, int $memberId): array
    {
        $user = Db::name('user')->where('uid', $memberId)->field('spread_uid')->find();
        $referrer = (int)($user['spread_uid'] ?? 0);
        if ($referrer <= 0) return ['referrer' => 0, 'guest' => 0];
        $old = Db::name(self::PROJECTION_TABLE)->where('tenant_id', $tenantId)->where('member_id', $referrer)->where('lifecycle_stage', 'post_sale')->lock(true)->find();
        return ['referrer' => $referrer, 'guest' => $old ? 1 : 0];
    }

    private function isUpgradeSettlement(array $context): bool
    {
        $rows = Db::name('cashier_v3_card_operation_settlement')
            ->where('tenant_id', (string)$context['tenant_id'])
            ->where('sales_order_id', (string)$context['order_id'])
            ->lock(true)->select()->toArray();
        if ($rows === []) {
            return false;
        }
        if (count($rows) !== 1
            || (string)($rows[0]['settlement_status'] ?? '') !== 'settled'
            || !in_array((string)($rows[0]['operation_type'] ?? ''), ['card_upgrade', 'project_upgrade'], true)) {
            throw new \RuntimeException('customer_lifecycle_upgrade_settlement_invalid');
        }
        return true;
    }
}
