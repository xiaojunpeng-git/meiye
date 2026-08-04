<?php

namespace app\services\cashier\v3\fact;

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;

/** Server-only stable identities for immutable checkout facts. */
final class CashierV3CheckoutFactIdFactory
{
    private const MIN_SECRET_BYTES = 32;

    /** @var string */
    private $secret;

    public function __construct(string $serverNamespaceSecret)
    {
        if (strlen($serverNamespaceSecret) < self::MIN_SECRET_BYTES) {
            throw new CashierV3CheckoutFactContractException(
                'checkout_fact_server_id_secret_invalid'
            );
        }
        $this->secret = $serverNamespaceSecret;
    }

    public function saleFactId(
        string $tenantId,
        string $orderId,
        string $orderLineId
    ): string {
        return $this->factId('CFS', 'sale_completed', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'orderLineId' => $orderLineId,
        ]);
    }

    public function saleNaturalKey(
        string $tenantId,
        string $orderId,
        string $orderLineId
    ): string {
        return $this->naturalKey('sale_completed', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'orderLineId' => $orderLineId,
        ]);
    }

    public function paymentFactId(
        string $tenantId,
        string $orderId,
        string $collectionId
    ): string {
        return $this->factId('CFP', 'payment_collected', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'collectionId' => $collectionId,
        ]);
    }

    public function paymentNaturalKey(
        string $tenantId,
        string $orderId,
        string $collectionId
    ): string {
        return $this->naturalKey('payment_collected', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'collectionId' => $collectionId,
        ]);
    }

    public function balanceFactId(
        string $tenantId,
        string $orderId,
        string $ledgerId
    ): string {
        return $this->factId('CFB', 'balance_changed', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'ledgerId' => $ledgerId,
        ]);
    }

    public function balanceNaturalKey(
        string $tenantId,
        string $orderId,
        string $ledgerId
    ): string {
        return $this->naturalKey('balance_changed', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'ledgerId' => $ledgerId,
        ]);
    }

    public function actualPerformanceFactId(
        string $tenantId,
        string $orderId,
        string $collectionBatchId
    ): string {
        return $this->factId('CFA', 'actual_performance_recorded', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'collectionBatchId' => $collectionBatchId,
        ]);
    }

    public function actualPerformanceNaturalKey(
        string $tenantId,
        string $orderId,
        string $collectionBatchId
    ): string {
        return $this->naturalKey('actual_performance_recorded', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'collectionBatchId' => $collectionBatchId,
        ]);
    }

    public function salesPerformanceFactId(
        string $tenantId,
        string $orderId,
        string $orderLineId,
        string $employeeId,
        string $sequence
    ): string {
        return $this->factId('CFY', 'sales_performance_allocated', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'orderLineId' => $orderLineId,
            'employeeId' => $employeeId,
            'sequence' => $sequence,
        ]);
    }

    public function salesPerformanceNaturalKey(
        string $tenantId,
        string $orderId,
        string $orderLineId,
        string $employeeId,
        string $sequence
    ): string {
        return $this->naturalKey('sales_performance_allocated', [
            'tenantId' => $tenantId,
            'orderId' => $orderId,
            'orderLineId' => $orderLineId,
            'employeeId' => $employeeId,
            'sequence' => $sequence,
        ]);
    }

    private function factId(string $prefix, string $factType, array $identity): string
    {
        return $prefix . '-' . substr($this->digest($factType, $identity), 0, 40);
    }

    private function naturalKey(string $factType, array $identity): string
    {
        return 'checkout_fact:' . $factType . ':v1:' . $this->digest($factType, $identity);
    }

    private function digest(string $factType, array $identity): string
    {
        foreach ($identity as $field => $value) {
            if (!is_string($value)
                || $value === ''
                || strlen($value) > 128
                || strpos($value, "\0") !== false) {
                throw new CashierV3CheckoutFactContractException(
                    'checkout_fact_identity_invalid',
                    ['factType' => $factType, 'field' => (string)$field]
                );
            }
        }
        try {
            $encoded = CashierV3CheckoutSettlementCanonicalizer::encode([
                'factType' => $factType,
                'factVersion' => 1,
                'identity' => $identity,
            ]);
        } catch (\Throwable $exception) {
            throw new CashierV3CheckoutFactContractException(
                'checkout_fact_identity_encoding_failed',
                ['factType' => $factType, 'cause' => $exception->getMessage()]
            );
        }
        return hash_hmac('sha256', "checkout-fact-v1\n" . $encoded, $this->secret);
    }
}
