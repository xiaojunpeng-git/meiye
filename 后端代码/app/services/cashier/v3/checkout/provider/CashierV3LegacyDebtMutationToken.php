<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

final class CashierV3LegacyDebtMutationToken
{
    private $originOrderId;
    private $originStoreId;
    private $guardVersion;
    private $originOrder;
    private $operatorScope;
    private $dataScope;

    public function __construct(
        int $originOrderId,
        int $originStoreId,
        int $guardVersion,
        array $originOrder,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $this->originOrderId = $originOrderId;
        $this->originStoreId = $originStoreId;
        $this->guardVersion = $guardVersion;
        $this->originOrder = $originOrder;
        $this->operatorScope = $operatorScope;
        $this->dataScope = $dataScope;
    }

    public function originOrderId(): int { return $this->originOrderId; }
    public function originStoreId(): int { return $this->originStoreId; }
    public function guardVersion(): int { return $this->guardVersion; }
    public function originOrder(): array { return $this->originOrder; }
    public function operatorScope(): CashierV3OperatorScope { return $this->operatorScope; }
    public function dataScope(): CashierV3DataScopeContext { return $this->dataScope; }
}
