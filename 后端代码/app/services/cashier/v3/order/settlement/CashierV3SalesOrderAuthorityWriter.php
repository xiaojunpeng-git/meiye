<?php

namespace app\services\cashier\v3\order\settlement;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

interface CashierV3SalesOrderAuthorityWriter
{
    /**
     * Persist the header and every formal-sale line in the caller-owned final
     * checkout transaction. Implementations must never open or commit it.
     */
    public function persistInTx(
        CashierV3SalesOrderPlanV1 $plan,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array;
}
