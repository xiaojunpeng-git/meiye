<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

/**
 * 命令网关拒绝原因。携带前端可识别的 code 与 status，避免退化成无码的通用异常。
 */
class CashierV3CommandException extends \Exception
{
    /** @var string */
    protected $resultCode;

    /** @var string */
    protected $resultStatus;

    /** @var array */
    protected $detail;

    public function __construct(string $resultCode, string $message, string $resultStatus = CashierV3ResultCode::STATUS_FAILED, array $detail = [])
    {
        parent::__construct($message);
        $this->resultCode = $resultCode;
        $this->resultStatus = $resultStatus;
        $this->detail = $detail;
    }

    public function getResultCode(): string
    {
        return $this->resultCode;
    }

    public function getResultStatus(): string
    {
        return $this->resultStatus;
    }

    public function getDetail(): array
    {
        return $this->detail;
    }

    public static function invalidContext(string $message, array $detail = []): self
    {
        return new self(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, $message, CashierV3ResultCode::STATUS_FAILED, $detail);
    }

    public static function versionConflict(string $message, array $detail = []): self
    {
        return new self(CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, $message, CashierV3ResultCode::STATUS_CONFLICT, $detail);
    }
}
