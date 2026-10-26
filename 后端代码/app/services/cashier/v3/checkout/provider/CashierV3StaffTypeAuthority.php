<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;

interface CashierV3StaffTypeAuthority
{
    public function contractVersion(): string;

    /** @return array{ready:bool,reasons:array} */
    public function readinessStatus(): array;

    /**
     * Called after system_store_staff and employee are both locked.
     *
     * @return array{employeeTypeCodeSnapshot:string,employeeTypeAuthorityVersion:int}
     */
    public function lockTypeSnapshotInTx(
        array $staff,
        array $employee,
        CashierV3DataScopeContext $dataScope
    ): array;
}
