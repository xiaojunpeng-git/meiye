<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use think\facade\Db;

/**
 * One locked paid-project line produces two independent craftsman amounts:
 * labor performance from the project rule and manual labor fees from the
 * checkout snapshot. They must never be substituted for one another.
 */
final class CashierV3PaidProjectCraftsmanPerformanceServices
{
    private const RULE_TABLE = 'cashier_v3_project_performance_rule';

    /**
     * @return array{laborAmountCents:int,laborFeeAmountCents:int,laborMode:string,ruleVersion:int,allocations:array<int,array<string,mixed>>}
     */
    public static function planInTx(array $line, string $tenantId): array
    {
        CashierV3TransactionGuard::assertInTransaction('paidProjectCraftsmanPerformance.planInTx');
        if ($tenantId === '' || (int)($line['item_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('paid_project_performance_line_invalid');
        }

        $quantity = max(1, (int)($line['quantity'] ?? 0));
        $saleAmountCents = max(0, (int)($line['sale_amount_cents'] ?? 0));
        $craftsmen = CashierV3CheckoutCraftsmenSnapshot::decode(
            (string)($line['craftsmen_snapshot_json'] ?? '')
        );
        if ($craftsmen === []) {
            return [
                'laborAmountCents' => 0,
                'laborFeeAmountCents' => 0,
                'laborMode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
                'ruleVersion' => 1,
                'allocations' => [],
            ];
        }

        $rule = Db::name(self::RULE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('project_id', (int)$line['item_id'])
            ->lock(true)
            ->find();
        $laborMode = (string)($rule['labor_mode'] ?? CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL);
        if (!in_array($laborMode, [
            CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
            CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED,
        ], true)) {
            throw new \InvalidArgumentException('paid_project_performance_rule_invalid');
        }
        $ruleVersion = max(1, (int)($rule['current_version'] ?? 1));
        $laborAmountCents = $laborMode === CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED
            ? max(0, (int)($rule['labor_configured_unit_amount_cents'] ?? 0)) * $quantity
            : $saleAmountCents;

        $performanceCraftsmen = [];
        $weights = [];
        $fees = [];
        foreach ($craftsmen as $craftsman) {
            $staffId = (int)$craftsman['staffId'];
            $type = (string)($craftsman['craftsmanPerformanceType'] ?? 'commission_labor');
            $fees[$staffId] = $type === 'commission'
                ? 0
                : max(0, (int)($craftsman['laborFeeCents'] ?? 0)) * $quantity;
            if ($type !== 'labor') {
                $performanceCraftsmen[] = $staffId;
                $weights[$staffId] = (int)$craftsman['laborWeight'];
            }
        }

        // A labor-only selection intentionally receives no labor performance.
        // It can still receive its independently entered manual labor fee.
        if ($performanceCraftsmen === []) {
            $laborAmountCents = 0;
        }
        $performanceByStaff = [];
        if ($performanceCraftsmen !== []) {
            foreach (CashierV3EntitlementCompletionKernel::allocateLaborAmount(
                $laborAmountCents,
                $performanceCraftsmen,
                $weights
            ) as $allocation) {
                $performanceByStaff[(int)$allocation['staffId']] = (int)$allocation['amountCents'];
            }
        }

        // The older line-wide override has no per-person amounts. Preserve its
        // total-fee meaning for drafts created before the per-person UI while
        // keeping it outside the labor-performance amount.
        if (array_sum($fees) === 0 && (int)($line['manual_labor_fee_cents'] ?? 0) > 0) {
            $lineFeeCents = max(0, (int)$line['manual_labor_fee_cents']) * $quantity;
            if ($performanceCraftsmen !== []) {
                foreach (CashierV3EntitlementCompletionKernel::allocateLaborAmount(
                    $lineFeeCents,
                    $performanceCraftsmen,
                    $weights
                ) as $allocation) {
                    $fees[(int)$allocation['staffId']] = (int)$allocation['amountCents'];
                }
            } else {
                $fees[(int)$craftsmen[0]['staffId']] = $lineFeeCents;
            }
        }

        $allocations = [];
        foreach ($craftsmen as $craftsman) {
            $staffId = (int)$craftsman['staffId'];
            $allocations[] = [
                'staffId' => $staffId,
                'employeeId' => max(1, (int)($craftsman['employeeId'] ?? $staffId)),
                'name' => (string)($craftsman['name'] ?? ''),
                'isPrimary' => !empty($craftsman['isPrimary']),
                'laborWeight' => (int)($craftsman['laborWeight'] ?? 0),
                'laborPerformanceCents' => (int)($performanceByStaff[$staffId] ?? 0),
                'laborFeeCents' => (int)($fees[$staffId] ?? 0),
            ];
        }

        return [
            'laborAmountCents' => $laborAmountCents,
            'laborFeeAmountCents' => array_sum($fees),
            'laborMode' => $laborMode,
            'ruleVersion' => $ruleVersion,
            'allocations' => $allocations,
        ];
    }
}
