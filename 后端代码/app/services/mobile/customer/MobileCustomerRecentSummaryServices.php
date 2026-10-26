<?php

namespace app\services\mobile\customer;

use think\exception\ValidateException;
use think\facade\Db;

/**
 * The customer-detail "nearby" projection deliberately reads the customer's
 * latest formal facts across this customer instance. Product has confirmed
 * that this one projection is not narrowed by the operator's store scope.
 */
final class MobileCustomerRecentSummaryServices
{
    private const TENANT_ID = '0';

    public function summary(array $payload): array
    {
        $memberId = (int)($payload['memberId'] ?? 0);
        if ($memberId <= 0 || !Db::name('user')->where('uid', $memberId)->where('is_del', 0)->count()) {
            throw new ValidateException('客户参数无效，或客户已不存在。');
        }

        return [
            'memberId' => $memberId,
            'latestService' => $this->latestService($memberId),
            'latestPurchase' => $this->latestPurchase($memberId),
            'dataAuthority' => 'CURRENT_INSTANCE_CUSTOMER_FORMAL_FACTS',
        ];
    }

    private function latestService(int $memberId): ?array
    {
        $row = Db::name('cashier_v3_entitlement_service_fact')
            ->where('tenant_id', self::TENANT_ID)
            ->where('member_id', $memberId)
            ->where('service_status', 'completed')
            ->field('id,service_fact_id,project_name_snapshot,store_name_snapshot,settled_at,occurred_at')
            ->order('settled_at', 'desc')->order('id', 'desc')->find();
        if (!$row) return null;
        return [
            'serviceRecordNo' => (string)($row['service_fact_id'] ?? ''),
            'projectName' => (string)($row['project_name_snapshot'] ?? ''),
            'storeName' => (string)($row['store_name_snapshot'] ?? ''),
            'completedAt' => $this->dateTime((int)($row['settled_at'] ?: $row['occurred_at'])),
        ];
    }

    private function latestPurchase(int $memberId): ?array
    {
        $base = Db::name('cashier_v3_sale_fact')
            ->where('tenant_id', self::TENANT_ID)
            ->where('member_id', $memberId)
            ->where('fact_type', 'sale_completed')
            ->where('fact_direction', 'forward')
            ->where('status', 'effective');
        $order = (clone $base)->field('order_id,order_no_snapshot,store_name_snapshot,settled_at,occurred_at')
            ->order('settled_at', 'desc')->order('id', 'desc')->find();
        if (!$order) return null;

        $amountCents = (int)(clone $base)->where('order_id', (string)$order['order_id'])->sum('sale_amount_cents');
        return [
            'orderId' => (string)($order['order_id'] ?? ''),
            'orderNo' => (string)($order['order_no_snapshot'] ?? ''),
            'storeName' => (string)($order['store_name_snapshot'] ?? ''),
            'amount' => $this->cents($amountCents),
            'purchasedAt' => $this->dateTime((int)($order['settled_at'] ?: $order['occurred_at'])),
        ];
    }

    private function cents(int $value): string
    {
        return number_format($value / 100, 2, '.', '');
    }

    private function dateTime(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
