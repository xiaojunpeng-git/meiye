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
        $hasExplicitProjectCount = in_array(true, array_map(static function (array $craftsman): bool {
            return array_key_exists('projectCount', $craftsman)
                || array_key_exists('project_count', $craftsman)
                || array_key_exists('projectCountHalfUnits', $craftsman);
        }, $craftsmen), true);
        $projectCounts = [];
        $groupMembers = [];
        foreach ($craftsmen as $index => $craftsman) {
            $groupKey = (string)($craftsman['allocationGroupKey'] ?? 'normal');
            $groupMembers[$groupKey][] = $index;
        }
        if ($hasExplicitProjectCount) {
            foreach ($craftsmen as $craftsman) {
                if (array_key_exists('projectCount', $craftsman) || array_key_exists('project_count', $craftsman)) {
                    $projectCounts[(int)$craftsman['staffId']] = self::projectCount(
                        $craftsman['projectCount'] ?? $craftsman['project_count']
                    );
                } else {
                    $projectCounts[(int)$craftsman['staffId']] = max(0, (int)($craftsman['projectCountHalfUnits'] ?? 0));
                }
            }
        } else {
            $totalHalfUnits = $quantity * 2;
            foreach ($groupMembers as $members) {
                $base = intdiv($totalHalfUnits, count($members));
                $remainder = $totalHalfUnits - ($base * count($members));
                foreach ($members as $offset => $index) {
                    $staffId = (int)$craftsmen[$index]['staffId'];
                    $projectCounts[$staffId] = $base + ($offset >= count($members) - $remainder ? 1 : 0);
                }
            }
        }
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
        $manualPerformanceByStaff = [];
        foreach ($craftsmen as $craftsman) {
            $staffId = (int)$craftsman['staffId'];
            if (!isset($weights[$staffId])) continue;
            $manual = !empty($craftsman['performanceAmountManual']);
            $performanceByStaff[$staffId] = $manual
                ? max(0, (int)($craftsman['performanceAmountCents'] ?? 0))
                : intdiv($laborAmountCents * max(0, (int)$weights[$staffId]), 100);
            $manualPerformanceByStaff[$staffId] = $manual;
        }

        // The older line-wide override has no per-person amounts. Preserve its
        // total-fee meaning for drafts created before the per-person UI while
        // keeping it outside the labor-performance amount.
        if (array_sum($fees) === 0 && (int)($line['manual_labor_fee_cents'] ?? 0) > 0) {
            $lineFeeCents = max(0, (int)$line['manual_labor_fee_cents']) * $quantity;
            if ($performanceCraftsmen !== []) {
                foreach ($groupMembers as $members) {
                    $groupIds = [];
                    $groupWeights = [];
                    foreach ($members as $index) {
                        $staffId = (int)$craftsmen[$index]['staffId'];
                        if (isset($weights[$staffId])) {
                            $groupIds[] = $staffId;
                            $groupWeights[$staffId] = $weights[$staffId];
                        }
                    }
                    if ($groupIds === []) continue;
                    foreach (CashierV3EntitlementCompletionKernel::allocateLaborAmount(
                        $lineFeeCents,
                        $groupIds,
                        $groupWeights
                    ) as $allocation) {
                        $fees[(int)$allocation['staffId']] = (int)$allocation['amountCents'];
                    }
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
                'laborPerformanceAmountManual' => !empty($manualPerformanceByStaff[$staffId]),
                'laborFeeCents' => (int)($fees[$staffId] ?? 0),
                // New manual entries keep their exact decimal value. Legacy
                // snapshots stay in half-units so prior facts remain stable.
                array_key_exists('projectCount', $craftsman) || array_key_exists('project_count', $craftsman)
                    ? 'projectCount'
                    : 'projectCountHalfUnits' => $projectCounts[$staffId] ?? 0,
            ];
            if (isset($craftsman['positionId'])) {
                $allocations[count($allocations) - 1]['positionId'] = (int)$craftsman['positionId'];
                $allocations[count($allocations) - 1]['positionName'] = (string)($craftsman['positionName'] ?? '');
            }
            if (!empty($craftsman['performanceIndependent'])) {
                $allocations[count($allocations) - 1]['performanceIndependent'] = true;
                $allocations[count($allocations) - 1]['allocationGroupKey'] = (string)($craftsman['allocationGroupKey'] ?? 'normal');
            }
        }

        return [
            'laborAmountCents' => $laborAmountCents,
            'laborFeeAmountCents' => array_sum($fees),
            'laborMode' => $laborMode,
            'ruleVersion' => $ruleVersion,
            'allocations' => $allocations,
        ];
    }

    private static function projectCount($value): string
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException('paid_project_count_invalid');
        }
        $text = trim((string)$value);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $text)) {
            throw new \InvalidArgumentException('paid_project_count_invalid');
        }
        $text = rtrim(rtrim($text, '0'), '.');
        return $text === '' ? '0' : $text;
    }
}
