<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

interface CashierV3CheckoutRequestRepository
{
    /**
     * Lock one exact editable aggregate after Gateway has locked every public
     * business context. The returned children all belong to expectedVersion.
     *
     * @return array{request:array,lines:array,payments:array,sources:array,currentRequest:array,verifiedSources:CashierV3CheckoutVerifiedSourceSet}
     */
    public function lockAggregateForEditInTx(
        string $requestId,
        int $expectedVersion,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array;

    /**
     * Lock the exact immutable aggregate accepted for final submission. The
     * caller already owns Gateway's complete resource lock set.
     *
     * @return array{request:array,lines:array,payments:array,sources:array,currentRequest:array,verifiedSources:CashierV3CheckoutVerifiedSourceSet}
     */
    public function lockAggregateForSubmitInTx(
        string $requestId,
        int $expectedVersion,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array;

    /**
     * Atomically close one locked ready_for_submit request after every domain
     * authority, event and fact write has succeeded in the caller transaction.
     * Sale-only and mixed requests pass a real CSO sales-order ID. An
     * entitlement-only request passes a real completed ECR receipt ID. Mixed
     * completion additionally derives and locks the matching ECR authority by
     * request + command key; a CSO alone is never sufficient proof of success.
     */
    public function markSucceededInTx(
        string $requestId,
        int $expectedVersion,
        string $commandIdempotencyKey,
        string $completionReferenceId,
        int $settledAt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array;

    /**
     * Read the latest eventless editing request for one exact cashier
     * workspace. This is projection data only and acquires no business lock.
     *
     * @return array|null request, line and payment rows in persistence shape
     */
    public function readLatestEditingProjection(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );

    /**
     * Read one exact editable request after a committed narrow mutation. The
     * request identity comes from the committed command receipt; this method
     * never guesses among older editing requests in the same workspace.
     *
     * @return array|null request, line and payment rows in persistence shape
     */
    public function readEditingProjectionByRequestId(
        string $requestId,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );

    /**
     * Lock the existing request used as CashierV3CheckoutSettlementKernel's
     * currentRequest. A blank request ID resolves only by the immutable
     * creation idempotency key.
     *
     * @return array|null
     */
    public function lockCurrentForKernelInTx(
        string $requestId,
        string $creationIdempotencyKey,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );

    /**
     * Persist one kernel persistencePlan inside the caller-owned final
     * transaction. This method never opens or commits a transaction itself.
     */
    public function persistKernelPlanInTx(
        array $kernelResult,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array;
}
