<?php

namespace app\services\customer\care\query;

final class CustomerCareProjectionException extends \RuntimeException
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

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function detail(): array
    {
        return $this->detail;
    }
}
