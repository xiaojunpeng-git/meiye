<?php

namespace app\services\cashier\v3\settlement\payment;

/** Stable failure contract for the isolated authoritative collection writer. */
final class CashierV3PaymentCollectionAuthorityException extends \InvalidArgumentException
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
