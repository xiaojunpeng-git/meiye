<?php

namespace app\services\cashier\v3\settlement;

/**
 * Transaction-only domain port used by the final checkout orchestrator.
 *
 * Implementations assemble every authority from server-side rows locked in
 * the caller's transaction. They must never copy authority facts from HTTP.
 */
interface CashierV3CheckoutSubmissionExecutionPort
{
    public function lockSubmissionAuthorityInTx(array $scope): array;

    /** Delegates the already-proven sale-only submission slice. */
    public function submitSaleOnlyInTx(array $scope, array $authority): array;

    public function persistSalesOrderInTx(array $authority): array;

    public function persistPaymentCollectionInTx(
        array $authority,
        array $salesOrder
    ): array;

    /** Persists the checkout debt authority, if any, in the same transaction. */
    public function persistDebtInTx(array $authority, array $salesOrder): array;

    /**
     * Deducts the server-locked member balance for a sale-bearing checkout.
     * The result is subsequently used to create the authoritative balance fact.
     */
    public function persistBalancePaymentInTx(
        array $authority,
        array $salesOrder,
        array $paymentCollection
    ): ?array;

    /** Builds the C2 entitlement kernel plan without writing business state. */
    public function planEntitlementCompletionInTx(array $authority): array;

    public function persistInventoryCompletionInTx(
        array $authority,
        array $entitlementPlan
    ): array;

    /**
     * Records the complete required event set. The returned event authority is
     * then supplied to fact writers in the same transaction.
     */
    public function recordCompletionEventsInTx(
        array $authority,
        array $salesOrder,
        array $paymentCollection,
        array $debt,
        array $entitlementPlan,
        array $inventoryCompletion
    ): array;

    public function persistEntitlementCompletionInTx(
        array $authority,
        array $entitlementPlan,
        array $inventoryCompletion,
        array $businessEvents
    ): array;

    public function persistSaleFactsInTx(
        array $authority,
        array $salesOrder,
        array $paymentCollection,
        array $businessEvents,
        ?array $balancePayment
    ): array;

    public function markSucceededInTx(
        array $authority,
        string $completionReferenceId,
        array $resultSlices
    ): array;

    public function completeWorkspaceInTx(array $authority): array;
}
