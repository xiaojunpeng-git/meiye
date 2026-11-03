<?php
declare(strict_types=1);

namespace think\facade {
    /** In-memory write boundary: assert the locked source type reaches the final update. */
    final class Db
    {
        public static $expectedCartType = -1;
        public static $updated = false;

        public static function name(string $table): Query
        {
            if ($table !== 'store_order_cart_info') {
                throw new \RuntimeException('Unexpected table: ' . $table);
            }
            return new Query();
        }
    }

    final class Query
    {
        private $conditions = [];

        public function where(string $column, $value): self
        {
            $this->conditions[$column] = $value;
            return $this;
        }

        public function update(array $values): int
        {
            if (($this->conditions['cart_type'] ?? null) !== Db::$expectedCartType
                || ($this->conditions['product_type'] ?? null) !== 6
                || ($this->conditions['write_surplus_times'] ?? null) !== 3
                || ($values['write_surplus_times'] ?? null) !== 2) {
                throw new \RuntimeException('Final deduction did not match its locked source');
            }
            Db::$updated = true;
            return 1;
        }
    }
}

namespace {
    use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPlanV1;
    use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;
    use think\facade\Db;

    $backend = dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/checkout/persistence';
    require_once $backend . '/CashierV3EntitlementCompletionPlanV1.php';
    require_once $backend . '/ThinkPhpCashierV3EntitlementCompletionWriter.php';

    /** Exercise the real private write branch without touching a database or checkout facts. */
    function assertSourceCartType(int $cartType, string $instanceType): void
    {
        $planClass = new \ReflectionClass(CashierV3EntitlementCompletionPlanV1::class);
        $plan = $planClass->newInstanceWithoutConstructor();
        foreach (['context' => ['source_type' => 'direct'], 'deductions' => [[
            'source_detail_id' => 15,
            'origin_order_id' => 25,
            'project_id' => 35,
            'holder_id' => 0,
            'entitlement_instance_type' => $instanceType,
            'deduct_physical_times' => 1,
        ]]] as $property => $value) {
            $field = $planClass->getProperty($property);
            if (PHP_VERSION_ID < 80100) $field->setAccessible(true);
            $field->setValue($plan, $value);
        }

        Db::$expectedCartType = $cartType;
        Db::$updated = false;
        $writer = new ThinkPhpCashierV3EntitlementCompletionWriter();
        $method = new \ReflectionMethod($writer, 'applyDeductions');
        if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
        $result = $method->invoke($writer, $plan, [
            'directSnapshot' => true,
            'details' => [15 => ['cart_type' => $cartType, 'write_surplus_times' => 3]],
            'holderExpectation' => [],
        ], []);
        if (!Db::$updated || $result !== ['cardHolder' => [], 'memberBenefitPool' => []]) {
            throw new \RuntimeException('Deduction did not complete');
        }
    }

    assertSourceCartType(2, 'card_holder');
    assertSourceCartType(0, 'independent_gift');
    echo "ENTITLEMENT_SOURCE_CART_TYPE_REGRESSION=PASS\n";
}
