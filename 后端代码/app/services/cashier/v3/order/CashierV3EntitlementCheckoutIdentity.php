<?php

namespace app\services\cashier\v3\order;

/**
 * 纯权益消费单的结账组标识边界。新结账请求使用普通不透明 ID，历史预约
 * 直通服务使用 reservation:数字；仅接受这两种已落库格式，不能按服务事实 ID
 * 猜测组归属，也不能放宽为任意冒号串。
 */
final class CashierV3EntitlementCheckoutIdentity
{
    public static function isValidRequestId(string $requestId): bool
    {
        return strlen($requestId) <= 64
            && preg_match('/^(?:[A-Za-z0-9_-]+|reservation:[1-9][0-9]*)$/D', $requestId) === 1;
    }

    /** 展示订单 ID 必须带 service: 前缀，并还原为同一权威结账组标识。 */
    public static function requestIdFromServiceOrderId(string $orderId): string
    {
        if (strpos($orderId, 'service:') !== 0) return '';
        $requestId = substr($orderId, strlen('service:'));
        return self::isValidRequestId($requestId) ? $requestId : '';
    }
}
