<?php

namespace app\services\cashier\v3\checkout;

use app\services\cashier\v3\CashierV3ResourceKindCatalog;

/**
 * Pre-lock candidate resource builder for entitlement completion.
 *
 * Every accepted value is resolved through CashierV3ResourceKindCatalog, so
 * the candidate plan cannot drift from the gateway's canonical lock order.
 * This class does not prove that resources were locked and is never called by
 * the post-lock domain kernel.
 */
final class CashierV3EntitlementCompletionLockPlanner
{
    private const ALLOWED_KINDS = [
        'member',
        'member_benefit_pool',
        'entitlement_occupation_guard',
        'card_holder',
        'performance_rule',
        'inventory_policy',
        'inventory_recipe',
        'inventory_stock',
        'inventory_batch',
        'inventory_shortage_cursor',
        'entitlement_debt_guard',
        'service_order',
        'reservation',
        'staff_profile',
        'cashier_workspace',
    ];

    /**
     * @param array<int,array{kind:string,id:string}> $resources
     * @return array<int,array{kind:string,id:string,lockOrder:int}>
     */
    public static function build(array $resources): array
    {
        $deduplicated = [];
        foreach ($resources as $index => $resource) {
            $keys = is_array($resource) ? array_keys($resource) : [];
            sort($keys, SORT_STRING);
            if (!is_array($resource)
                || $keys !== ['id', 'kind']
                || !is_string($resource['kind'])
                || !is_string($resource['id'])) {
                throw self::failure('lock_resource_shape_invalid', ['index' => $index]);
            }
            $kind = $resource['kind'];
            $id = trim($resource['id']);
            if (!in_array($kind, self::ALLOWED_KINDS, true)
                || !CashierV3ResourceKindCatalog::isKnown($kind)) {
                throw self::failure('lock_resource_kind_not_allowed', ['index' => $index, 'kind' => $kind]);
            }
            if ($id === '' || strlen($id) > 128 || preg_match('/^[A-Za-z0-9:._-]+$/D', $id) !== 1) {
                throw self::failure('lock_resource_id_invalid', ['index' => $index, 'kind' => $kind]);
            }
            $key = $kind . "\0" . $id;
            $deduplicated[$key] = [
                'kind' => $kind,
                'id' => $id,
                'lockOrder' => CashierV3ResourceKindCatalog::lockOrderOf($kind),
            ];
        }

        $plan = array_values($deduplicated);
        usort($plan, static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResources(
                $left['kind'],
                $left['id'],
                $right['kind'],
                $right['id']
            );
        });
        return $plan;
    }

    public static function lockOrder(string $kind): int
    {
        if (!in_array($kind, self::ALLOWED_KINDS, true)
            || !CashierV3ResourceKindCatalog::isKnown($kind)) {
            throw self::failure('lock_resource_kind_not_allowed', ['kind' => $kind]);
        }
        return CashierV3ResourceKindCatalog::lockOrderOf($kind);
    }

    public static function shortageCursorResourceId(
        string $stockId,
        int $recipeId,
        int $estimatedUnitCostCents
    ): string {
        $stockId = trim($stockId);
        $id = $stockId . ':' . $recipeId . ':' . $estimatedUnitCostCents;
        if ($stockId === '' || strlen($stockId) > 64
            || preg_match('/^[A-Za-z0-9._-]+$/D', $stockId) !== 1
            || $recipeId <= 0 || $estimatedUnitCostCents < 0
            || strlen($id) > 128) {
            throw self::failure('inventory_shortage_cursor_identity_invalid');
        }
        return $id;
    }

    private static function failure(string $reason, array $detail = []): CashierV3EntitlementCompletionContractException
    {
        return new CashierV3EntitlementCompletionContractException($reason, $detail);
    }
}
