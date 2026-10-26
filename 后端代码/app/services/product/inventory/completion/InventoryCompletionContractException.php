<?php
declare(strict_types=1);

namespace app\services\product\inventory\completion;

final class InventoryCompletionContractException extends \RuntimeException
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
