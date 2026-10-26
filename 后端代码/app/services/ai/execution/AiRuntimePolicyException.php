<?php
declare(strict_types=1);

namespace app\services\ai\execution;

/** Internal policy reason only; adapters must map it to registered user copy. */
class AiRuntimePolicyException extends \RuntimeException
{
    public function reason(): string
    {
        return $this->getMessage();
    }
}
