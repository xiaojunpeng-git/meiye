<?php

namespace app\services\ai\contract;

/** Stable, payload-free contract errors. No model content is copied to messages. */
class AiContractException extends \RuntimeException
{
    private $diagnostic;

    /** Diagnostic metadata is bounded, payload-free and intended for the
     * 24-hour Run audit only. It must never contain model or customer text. */
    public function __construct(string $reason, array $diagnostic=[])
    {
        parent::__construct($reason);
        $this->diagnostic=$diagnostic;
    }

    public function reason(): string
    {
        return $this->getMessage();
    }

    public function diagnostic(): array
    {
        return $this->diagnostic;
    }
}
