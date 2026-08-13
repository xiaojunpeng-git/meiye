<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

/** Read-only authority boundary used by checkout result recovery. */
interface CashierV3CheckoutResultReadRepository
{
    /**
     * Read the latest verified committed checkout for one exact workspace.
     * Sale-only/mixed results are backed by a settled CSO authority;
     * entitlement-only results are backed by a completed ECR authority.
     *
     * @return array|null
     */
    public function findLatestCommittedCheckoutForWorkspace(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );

    /**
     * Read the latest committed sale-only checkout for one exact cashier
     * workspace. This supports the root projection after the cart has been
     * cleared; it never resumes or mutates the completed checkout.
     *
     * @return array|null
     */
    public function findLatestCommittedSaleOnlyForWorkspace(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );

    /** @return array|null cashier_v3_command_receipt persistence row */
    public function findReceiptForActor(
        string $idempotencyKey,
        int $storeId
    );

    /**
     * Resolve and re-check a committed result for all supported compositions.
     * Command-receipt JSON may narrow the locator but never proves success.
     *
     * @return array|null
     */
    public function findCommittedCheckoutResult(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );

    /**
     * Resolve and re-check the smallest committed sale-only result.
     * Receipt JSON is only a locator; returned data must come from the
     * authoritative sales-order and checkout-request rows.
     *
     * @return array|null
     */
    public function findCommittedSaleOnlyResult(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );
}
