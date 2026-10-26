<?php
declare(strict_types=1);

namespace app\services\order\cashier;

use think\exception\ValidateException;

/**
 * 现金/余额/组合结账失败：外层事务应回滚，由控制器组装 ERROR 响应。
 */
class CashierCheckoutFailException extends ValidateException
{
    /** @var string */
    protected $orderId = '';

    /** @var string */
    protected $failReason = '';

    public function __construct(string $reason, string $orderId = '')
    {
        $reason = trim($reason) !== '' ? trim($reason) : '未知错误';
        $this->failReason = $reason;
        $this->orderId = $orderId;
        parent::__construct('结账失败，请重新结账。原因：' . $reason);
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getFailReason(): string
    {
        return $this->failReason;
    }
}
