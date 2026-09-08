<?php

namespace app\services\ai\contract;

/** Stable, payload-free contract errors. No model content is copied to messages. */
class AiContractException extends \RuntimeException
{
    public function reason(): string
    {
        return $this->getMessage();
    }
}
