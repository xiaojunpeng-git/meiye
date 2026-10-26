<?php

namespace app\services\cashier\v3;

/**
 * Checkout workspace identity is store-scoped. The operator is an audit actor
 * and must never decide whether the current store cashier can resume a draft.
 */
final class CashierV3CheckoutWorkspaceIdentity
{
    public static function id(int $storeId, string $stateContextId): string
    {
        $stateContextId = trim($stateContextId);
        if ($storeId <= 0 || $stateContextId === '') {
            throw new \InvalidArgumentException('checkout_workspace_identity_invalid');
        }
        return sprintf('ws:%d:%s', $storeId, $stateContextId);
    }
}
