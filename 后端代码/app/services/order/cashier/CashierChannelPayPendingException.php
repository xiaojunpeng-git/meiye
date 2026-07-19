<?php
declare(strict_types=1);

namespace app\services\order\cashier;

use think\exception\ValidateException;

/**
 * 微信/支付宝已扣款，但自动核销/院装失败：订单保持已支付，禁止重新结账。
 */
class CashierChannelPayPendingException extends ValidateException
{
    /** @var array */
    protected $orderInfo = [];

    /** @var string */
    protected $failReason = '';

    public function __construct(string $message, array $orderInfo = [], string $failReason = '')
    {
        parent::__construct($message);
        $this->orderInfo = $orderInfo;
        $this->failReason = $failReason;
    }

    public function getOrderInfo(): array
    {
        return $this->orderInfo;
    }

    public function getFailReason(): string
    {
        return $this->failReason;
    }
}
