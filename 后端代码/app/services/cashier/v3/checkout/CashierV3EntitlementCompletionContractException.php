<?php

namespace app\services\cashier\v3\checkout;

/**
 * Stable failure returned by the isolated entitlement-completion kernel.
 *
 * The shared Gateway adapter will map reason() to CashierV3ResultCode later.
 * Keeping that mapping out of this package lets the kernel remain side-effect free.
 */
final class CashierV3EntitlementCompletionContractException extends \InvalidArgumentException
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
