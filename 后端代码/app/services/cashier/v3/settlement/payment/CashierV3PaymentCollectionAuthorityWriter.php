<?php

namespace app\services\cashier\v3\settlement\payment;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

interface CashierV3PaymentCollectionAuthorityWriter
{
    /**
     * Persist the collection set and every bookkeeping-payment detail in the
     * caller-owned final checkout transaction. Implementations never commit it.
     */
    public function persistInTx(
        CashierV3PaymentCollectionPlanV1 $plan,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array;
}
