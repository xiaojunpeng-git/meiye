<?php

namespace app\services\customer\care;

final class CustomerCareDomainException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var array */
    private $detail;

    public function __construct(string $errorCode, string $message, array $detail = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->detail = $detail;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetail(): array
    {
        return $this->detail;
    }
}
