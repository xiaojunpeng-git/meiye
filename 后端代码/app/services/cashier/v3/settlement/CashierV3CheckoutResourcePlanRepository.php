<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

interface CashierV3CheckoutResourcePlanRepository
{
    /** Caller owns the transaction. */
    public function persistInTx(
        string $requestId,
        CashierV3CheckoutVerifiedResourcePlan $plan,
        int $preparedAt
    ): array;

    /** Caller owns the transaction; rows remain immutable for audit. */
    public function invalidateInTx(
        string $requestId,
        string $tenantId,
        int $storeId,
        int $boundRequestVersion,
        string $reason
    ): array;

    /** Caller owns the transaction. */
    public function markConsumedInTx(
        string $requestId,
        string $tenantId,
        int $storeId,
        int $boundRequestVersion,
        string $expectedFingerprint
    ): array;

    /**
     * Caller owns the transaction and has advanced checkout_request by CAS.
     * Immutable rows remain available for audit.
     */
    public function supersedeActiveBeforeVersionInTx(
        string $requestId,
        string $tenantId,
        int $storeId,
        int $nextVersion,
        string $reason
    ): array;
}
