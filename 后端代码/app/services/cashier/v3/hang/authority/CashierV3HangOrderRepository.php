<?php

namespace app\services\cashier\v3\hang\authority;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

interface CashierV3HangOrderRepository
{
    /**
     * Persist or replay one immutable hang header and its complete line set.
     * The caller owns the final transaction; implementations must not commit.
     */
    public function persistInTx(
        CashierV3HangOrderPlanV1 $plan,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array;
}
