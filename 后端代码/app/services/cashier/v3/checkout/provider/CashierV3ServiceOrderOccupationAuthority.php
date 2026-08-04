<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;

interface CashierV3ServiceOrderOccupationAuthority
{
    public function contractVersion(): string;

    /** @return array{ready:bool,reasons:array} */
    public function readinessStatus(): array;

    /**
     * Must lock the complete service-order occupation set for the entitlement detail.
     * Each contributor uses the C2 kernel contributor shape.
     *
     * @return array<int,array{kind:string,id:int,version:int,occupiedTimes:int,convertibleTimes:int}>
     */
    public function lockContributorsInTx(array $request, CashierV3DataScopeContext $dataScope): array;
}
