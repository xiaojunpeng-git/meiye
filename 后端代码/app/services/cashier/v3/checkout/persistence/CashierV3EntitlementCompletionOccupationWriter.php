<?php

namespace app\services\cashier\v3\checkout\persistence;

/**
 * Transaction-only bridge implemented by the reservation/C3 authority owner.
 * It converts only the current source occupation described by the locked plan.
 */
interface CashierV3EntitlementCompletionOccupationWriter
{
    public const CONTRACT_VERSION = 'cashier-v3-entitlement-completion-occupation-writer-v1';

    /**
     * Return exact keys: contractVersion, sourceType, sourceId, convertedTimes.
     * Implementations must not start, commit or roll back a transaction.
     */
    public function convertInTx(array $context, array $deductions): array;
}
