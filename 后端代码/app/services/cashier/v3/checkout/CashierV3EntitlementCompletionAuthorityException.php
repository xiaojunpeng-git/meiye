<?php

namespace app\services\cashier\v3\checkout;

final class CashierV3EntitlementCompletionAuthorityException extends \RuntimeException
{
    /** @var string */
    private $reason;

    /** @var array */
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
