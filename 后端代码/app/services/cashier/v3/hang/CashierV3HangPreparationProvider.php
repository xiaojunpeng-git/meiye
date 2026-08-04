<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

interface CashierV3HangPreparationProvider
{
    public function workspaceVersion(
        string $workspaceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): int;

    /**
     * @return array<int,array>
     */
    public function roomCandidates(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array;
}
