<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

/** Read-only authority boundary for submit-hang-order recovery. */
interface CashierV3HangOrderResultReadRepository
{
    /** @return array|null cashier_v3_command_receipt row for the current actor */
    public function findReceiptForActor(string $idempotencyKey, int $storeId, int $operatorId);

    /**
     * Re-checks the authoritative hang header, frozen lines and room guard.
     * Command-receipt JSON is never a proof that the hang succeeded.
     *
     * @return array|null
     */
    public function findCommittedHangOrder(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );
}
