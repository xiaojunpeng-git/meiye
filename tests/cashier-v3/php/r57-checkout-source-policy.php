<?php
/** Adapter isolation: real selection methods, policy/catalog transport stubbed; no DB writes. */
namespace app\services\cashier\v3\config {
    class CashierV3BusinessConfigServices {
        public function assertCheckoutSourceAllowed($id): void { if ($id === 99) throw new \RuntimeException('reserved'); }
        public function resolveSourceSnapshot($primary, $secondary, $locked = false): array {
            if ($primary <= 0 || $secondary < 0) throw new \RuntimeException('invalid source');
            return ['primarySourceId' => $primary, 'secondarySourceId' => $secondary,
                'primarySourceNameSnapshot' => 'current-name', 'secondarySourceNameSnapshot' => '',
                'displayNameSnapshot' => 'current-name'];
        }
    }
    class CashierV3BusinessSourcePolicyServices {
        public static $calls = [];
        public static $fixed = true;
        public function resolveChoice($kind, $tenant, $store, $member, $candidate): array {
            self::$calls[] = [$kind, $tenant, $store, $member];
            return (new CashierV3BusinessConfigServices())->resolveSourceSnapshot(self::$fixed ? 7 : (int)($candidate['primarySourceId'] ?? 0), 0);
        }
    }
}
namespace app\services\cashier\v3 {
    class CashierV3CommandException extends \RuntimeException {
        public static function invalidContext($message, $metadata = []): self { return new self($message); }
    }
}
namespace {
    use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices as Policy;
    use app\services\cashier\v3\settlement\CashierV3CheckoutBusinessSourceSelectionServices as Selection;
    $root = dirname(__DIR__, 3);
    require $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBusinessSourceSelectionServices.php';
    $checks = 0;
    $check = static function ($condition, $label) use (&$checks): void {
        if (!$condition) throw new \RuntimeException($label);
        ++$checks;
    };
    $service = new Selection();
    foreach (['purchase', 'recharge', 'repayment'] as $kind) {
        $choice = $service->prepareChoice($kind, 'tenant-one', 5, 12, ['primarySourceId' => 2]);
        $check($choice['primarySourceId'] === 7, 'authoritative preparation enforces fixed choice');
        $check(end(Policy::$calls) === [$kind, 'tenant-one', 5, 12], 'scope retained');
        $count = count(Policy::$calls);
        // Simulate a later administrator default change. Final validation must
        // not consult the policy or overwrite this confirmed source/name.
        $saved = ['primarySourceId' => 2, 'secondarySourceId' => 0,
            'primarySourceNameSnapshot' => 'confirmed-name', 'displayNameSnapshot' => 'confirmed-name'];
        $validated = $service->validateConfirmedChoice($kind, 'tenant-one', 5, 12, $saved);
        $check($validated['primarySourceId'] === 2, 'final does not substitute fixed source');
        $check($validated['displayNameSnapshot'] === 'confirmed-name', 'confirmed names retained');
        $check(count(Policy::$calls) === $count, 'final never rereads policy');
        foreach ([0, 99] as $invalid) {
            try { $service->validateConfirmedChoice($kind, 'tenant-one', 5, 12, ['primarySourceId' => $invalid]); }
            catch (\RuntimeException $error) { ++$checks; continue; }
            throw new \RuntimeException('empty/reserved final choice must fail');
        }
    }
    $code = file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBusinessSourceSelectionServices.php');
    $lock = substr($code, strpos($code, 'public function lockResolvedForSettlementInTx'), strpos($code, 'public function read(') - strpos($code, 'public function lockResolvedForSettlementInTx'));
    $check(strpos($lock, 'latestNormalMemberSource(') === false, 'settlement no history fallback');
    $history = substr($code, strpos($code, 'public function latestNormalMemberSource'), strpos($code, 'private function saveSelectionInTx') - strpos($code, 'public function latestNormalMemberSource'));
    $check(strpos($history, 'PolicyServices') === false, 'history cannot recurse through policy');
    foreach (['CashierV3DebtRepaymentServices.php', 'CashierV3RechargeDebtRepaymentServices.php'] as $file) {
        $code = file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/' . $file);
        $check(strpos($code, 'validateConfirmedChoice(') !== false, 'repayment final validator installed');
        $check(strpos($code, 'sourceFromOriginalSalesOrder') === false && strpos($code, '$originalSource') === false, 'old original-source branch removed');
    }
    $preparation = file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
    $check(strpos($preparation, '->prepareChoice(') === false, 'final sale preparation never replaces confirmed source with fresh defaults');
    $check(strpos($preparation, '->validateConfirmedChoice(') !== false, 'final sale validates the same choice');
    require $root . '/后端代码/app/services/cashier/v3/member/CashierV3RechargeCheckoutModule.php';
    $moduleClass = new ReflectionClass(\app\services\cashier\v3\member\CashierV3RechargeCheckoutModule::class);
    $normalizeSource = $moduleClass->getMethod('businessSource');
    if (PHP_VERSION_ID < 80100) $normalizeSource->setAccessible(true);
    $confirmed = ['primarySourceId' => 2, 'secondarySourceId' => 0,
        'primarySourceNameSnapshot' => 'confirmed-before-rename', 'displayNameSnapshot' => 'confirmed-before-rename'];
    $check($normalizeSource->invoke($moduleClass->newInstance(), $confirmed) === $confirmed, 'recharge normalization keeps confirmed names');
    echo "R57_CHECKOUT_SOURCE_ADAPTER=PASS ($checks checks; dependencies stubbed; no DB)\n";
}
