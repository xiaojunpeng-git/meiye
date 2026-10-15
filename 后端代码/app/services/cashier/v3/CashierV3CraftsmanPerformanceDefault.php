<?php

namespace app\services\cashier\v3;

/**
 * Current per-service craftsman performance default for the selector and
 * settlement paths. Null keeps the sale/writeoff amount; zero is an explicit
 * configured zero. Neither case changes project consumption performance.
 */
final class CashierV3CraftsmanPerformanceDefault
{
    public static function projectUnitCents(int $projectId, string $tenantId): ?int
    {
        $rule = CashierV3ProjectPersonnelDefaults::projectRule($projectId, $tenantId);
        if ($rule === null || !array_key_exists('craftsman_default_unit_amount_cents', $rule)
            || $rule['craftsman_default_unit_amount_cents'] === null) return null;
        return max(0, (int)$rule['craftsman_default_unit_amount_cents']);
    }

    /** Already issued cards use the current card-project setting for future services. */
    public static function entitlementUnitCents(
        int $projectId,
        int $holderId,
        int $detailId,
        string $tenantId
    ): ?int {
        $relation = CashierV3ProjectPersonnelDefaults::currentCardRelation($projectId, $holderId, $detailId);
        if ($relation === null) return self::projectUnitCents($projectId, $tenantId);
        $mode = (string)($relation['craftsman_default_mode'] ?? 'default_unit_price');
        if ($mode === 'default_unit_price') return null;
        if ($mode !== 'manual_amount') throw new \UnexpectedValueException('card_craftsman_default_mode_invalid');
        return max(0, (int)($relation['craftsman_default_unit_amount_cents'] ?? 0));
    }
}
