<?php
declare(strict_types=1);

namespace app\services\product\inventory\completion;

/**
 * Database-free contract shared by the inventory provider and its consumers.
 */
final class InventoryEntitlementCompletionContract
{
    public const CONTRACT_VERSION = 'inventory-entitlement-completion-provider-v1';

    public const POLICY_INHERIT = 'inherit';
    public const POLICY_DENY = 'deny_shortage';
    public const POLICY_ALLOW = 'allow_shortage';

    public const LOCK_ORDER_POLICY = 46;
    public const LOCK_ORDER_RECIPE = 47;
    public const LOCK_ORDER_STOCK = 50;
    public const LOCK_ORDER_BATCH = 55;
    public const LOCK_ORDER_SHORTAGE_CURSOR = 56;

    public const SHORTAGE_CURSOR_GATE = 'explicit_resource_plan_locked_v1';

    public const STOCK_STATUS_GOOD = 'GOOD';
    public const STOCK_STATUS_DEFECTIVE = 'DEFECTIVE';

    public static function resolvePolicy(string $merchantDefault, string $projectOverride): string
    {
        self::assertMerchantPolicy($merchantDefault);
        self::assertProjectPolicy($projectOverride);
        return $projectOverride === self::POLICY_INHERIT ? $merchantDefault : $projectOverride;
    }

    /**
     * Packs the two independently locked policy versions into one positive
     * version accepted by the C2 inventory_policy resource contract.
     */
    public static function policyVersion(int $merchantVersion, int $projectVersion): int
    {
        if ($merchantVersion <= 0 || $merchantVersion > 2147483647
            || $projectVersion <= 0 || $projectVersion > 2147483647) {
            throw new InventoryCompletionContractException('inventory_policy_version_invalid');
        }
        return ($merchantVersion << 31) | $projectVersion;
    }

    public static function shortageCursorResourceId(
        int $stockId,
        int $recipeId,
        int $estimatedUnitCostCents
    ): string {
        if ($stockId <= 0 || $recipeId <= 0 || $estimatedUnitCostCents < 0) {
            throw new InventoryCompletionContractException('inventory_shortage_cursor_identity_invalid');
        }
        return $stockId . ':' . $recipeId . ':' . $estimatedUnitCostCents;
    }

    /**
     * Keep inventory row locks aligned with the cashier resource catalog.
     * Canonical positive decimal ids use numeric order without integer casts;
     * opaque ids and decimal strings with leading zeroes use byte order.
     */
    public static function compareResourceIds(string $left, string $right): int
    {
        $leftDecimal = preg_match('/^[1-9][0-9]*$/D', $left) === 1;
        $rightDecimal = preg_match('/^[1-9][0-9]*$/D', $right) === 1;
        if ($leftDecimal && $rightDecimal) {
            $lengthOrder = strlen($left) <=> strlen($right);
            if ($lengthOrder !== 0) {
                return $lengthOrder;
            }
        }
        return strcmp($left, $right);
    }

    /** Convert an exact decimal quantity into its integer storage unit. */
    public static function decimalToUnits(string $quantity, int $scale): int
    {
        $quantity = trim($quantity);
        if ($scale < 0 || $scale > 4 || preg_match('/^\d+(?:\.\d+)?$/D', $quantity) !== 1) {
            throw new InventoryCompletionContractException('inventory_quantity_invalid');
        }
        $parts = explode('.', $quantity, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $parts[1] ?? '';
        if (strlen($fraction) > $scale) {
            $discarded = substr($fraction, $scale);
            if (trim($discarded, '0') !== '') {
                throw new InventoryCompletionContractException('inventory_quantity_precision_exceeded', [
                    'quantity' => $quantity,
                    'scale' => $scale,
                ]);
            }
            $fraction = substr($fraction, 0, $scale);
        }
        $digits = ltrim($whole . str_pad($fraction, $scale, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        if (strlen($digits) > 18 || (strlen($digits) === 18 && strcmp($digits, '100000000000000000') > 0)) {
            throw new InventoryCompletionContractException('inventory_quantity_overflow');
        }
        $units = (int)$digits;
        if ($units <= 0) {
            throw new InventoryCompletionContractException('inventory_quantity_not_positive');
        }
        return $units;
    }

    public static function recipeFormulaHash(int $recipeId, int $recipeVersion, array $lines): string
    {
        if ($recipeId <= 0 || $recipeVersion <= 0 || !$lines) {
            throw new InventoryCompletionContractException('inventory_recipe_snapshot_invalid');
        }
        $canonical = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new InventoryCompletionContractException('inventory_recipe_line_invalid');
            }
            $canonical[] = [
                'stockId' => (string)($line['stockId'] ?? ''),
                'consumableId' => (int)($line['consumableId'] ?? 0),
                'skuId' => (int)($line['skuId'] ?? 0),
                'quantityUnitsPerService' => (int)($line['quantityUnitsPerService'] ?? 0),
                'stockUnitScale' => (int)($line['stockUnitScale'] ?? -1),
            ];
        }
        usort($canonical, static function (array $left, array $right): int {
            $stock = self::compareResourceIds($left['stockId'], $right['stockId']);
            if ($stock !== 0) {
                return $stock;
            }
            return [$left['consumableId'], $left['skuId'], $left['quantityUnitsPerService']]
                <=> [$right['consumableId'], $right['skuId'], $right['quantityUnitsPerService']];
        });
        foreach ($canonical as $line) {
            if ($line['stockId'] === '' || $line['consumableId'] <= 0 || $line['skuId'] <= 0
                || $line['quantityUnitsPerService'] <= 0 || $line['stockUnitScale'] < 0
                || $line['stockUnitScale'] > 4) {
                throw new InventoryCompletionContractException('inventory_recipe_line_invalid');
            }
        }
        $encoded = json_encode([
            'recipeId' => $recipeId,
            'recipeVersion' => $recipeVersion,
            'lines' => $canonical,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new InventoryCompletionContractException('inventory_recipe_hash_failed');
        }
        return hash('sha256', $encoded);
    }

    public static function requestFingerprint(array $request): string
    {
        $normalized = self::canonicalize($request);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new InventoryCompletionContractException('inventory_request_fingerprint_failed');
        }
        return hash('sha256', $encoded);
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isList($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    private static function assertMerchantPolicy(string $policy): void
    {
        if (!in_array($policy, [self::POLICY_DENY, self::POLICY_ALLOW], true)) {
            throw new InventoryCompletionContractException('inventory_merchant_policy_invalid');
        }
    }

    private static function assertProjectPolicy(string $policy): void
    {
        if (!in_array($policy, [self::POLICY_INHERIT, self::POLICY_DENY, self::POLICY_ALLOW], true)) {
            throw new InventoryCompletionContractException('inventory_project_policy_invalid');
        }
    }
}
