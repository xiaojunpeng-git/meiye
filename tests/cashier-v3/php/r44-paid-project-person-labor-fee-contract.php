<?php

declare(strict_types=1);

namespace app\services\cashier\v3 {
    /** The planner test has no database transaction; only its transaction boundary is stubbed. */
    final class CashierV3TransactionGuard
    {
        public static function assertInTransaction(string $operation): void {}
    }
}

namespace app\services\cashier\v3\checkout {
    /** Keep the production labor-mode value while isolating the paid-project calculation. */
    final class CashierV3EntitlementCompletionKernel
    {
        public const PERFORMANCE_ACTUAL = 'actual_entitlement_amount';
        public const PERFORMANCE_CONFIGURED = 'project_configured_amount';
    }
}

namespace app\services\cashier\v3\settlement {
    /** Read the same JSON personnel snapshot shape supplied by the checkout line. */
    final class CashierV3CheckoutCraftsmenSnapshot
    {
        public static function decode($json): array
        {
            return json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
        }
    }
}

namespace think\facade {
    /** Supply a missing configured rule; the test never writes persistent business data. */
    final class Db
    {
        public static function name(string $table): RuleQuery
        {
            return new RuleQuery();
        }
    }

    final class RuleQuery
    {
        public function where($field, $value): self { return $this; }
        public function lock($enabled): self { return $this; }
        public function find(): ?array { return null; }
    }
}

namespace {
    require_once dirname(__DIR__, 3)
        . '/后端代码/app/services/cashier/v3/settlement/CashierV3PaidProjectCraftsmanPerformanceServices.php';

    use app\services\cashier\v3\settlement\CashierV3PaidProjectCraftsmanPerformanceServices;

    $craftsmen = [[
        'staffId' => 11, 'laborWeight' => 50, 'craftsmanPerformanceType' => 'commission_labor',
        'laborFeeCents' => 500, 'projectCount' => '1',
    ], [
        'staffId' => 12, 'laborWeight' => 50, 'craftsmanPerformanceType' => 'labor',
        'laborFeeCents' => 300, 'projectCount' => '1',
    ], [
        'staffId' => 13, 'laborWeight' => 0, 'craftsmanPerformanceType' => 'commission',
        'laborFeeCents' => 0, 'projectCount' => '0',
    ]];
    foreach ([1, 3] as $quantity) {
        $plan = CashierV3PaidProjectCraftsmanPerformanceServices::planInTx([
            'item_id' => 901,
            'quantity' => $quantity,
            'sale_amount_cents' => 13400 * $quantity,
            'craftsmen_snapshot_json' => json_encode($craftsmen, JSON_THROW_ON_ERROR),
        ], 'test-tenant');
        $byStaff = array_column($plan['allocations'], null, 'staffId');
        $ok = $plan['laborFeeAmountCents'] === 800
            && $byStaff[11]['laborFeeCents'] === 500
            && $byStaff[12]['laborFeeCents'] === 300
            && $byStaff[13]['laborFeeCents'] === 0
            && $plan['laborAmountCents'] === 13400 * $quantity;
        if (!$ok) {
            fwrite(STDERR, "FAIL quantity={$quantity}: " . json_encode($plan) . "\n");
            exit(1);
        }
        echo "PASS paid project quantity={$quantity}: per-person fee stays 5/3 yuan\n";
    }
}
