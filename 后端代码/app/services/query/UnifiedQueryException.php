<?php

namespace app\services\query;

/**
 * 统一查询可预期契约错误。errorCode 供接口稳定映射，message 可直接展示。
 */
class UnifiedQueryException extends \RuntimeException
{
    /** @var string */
    protected $errorCode;

    /** @var array */
    protected $detail;

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
