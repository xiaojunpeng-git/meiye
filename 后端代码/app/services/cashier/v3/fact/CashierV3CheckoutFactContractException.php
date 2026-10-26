<?php

namespace app\services\cashier\v3\fact;

final class CashierV3CheckoutFactContractException extends \RuntimeException
{
    private $reason;
    private $detail;

    public function __construct(string $reason, array $detail = [])
    {
        parent::__construct($reason);
        $this->reason = $reason;
        $this->detail = $detail;
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
