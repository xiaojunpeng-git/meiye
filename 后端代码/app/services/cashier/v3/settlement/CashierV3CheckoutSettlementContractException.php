<?php

namespace app\services\cashier\v3\settlement;

/**
 * Stable domain failure for the isolated checkout-settlement contract.
 *
 * Gateway result-code mapping is deliberately outside this package until the
 * full payment, balance, debt, entitlement and event transaction is wired.
 */
final class CashierV3CheckoutSettlementContractException extends \InvalidArgumentException
{
    /** @var string */
    private $reason;

    /** @var array */
    private $detail;

    public function __construct(string $reason, array $detail = [])
    {
        $this->reason = $reason;
        $this->detail = $detail;
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function detail(): array
    {
        return $this->detail;
    }
}
