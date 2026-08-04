<?php

namespace app\services\cashier\v3\order\settlement;

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;

/** Server-only stable identifiers; the namespace secret never comes from HTTP. */
final class CashierV3SalesOrderIdFactory
{
    private const MIN_SECRET_BYTES = 32;

    /** @var string */
    private $secret;

    public function __construct(string $serverNamespaceSecret)
    {
        if (strlen($serverNamespaceSecret) < self::MIN_SECRET_BYTES) {
            throw new CashierV3SalesOrderAuthorityException('sales_order_server_id_secret_invalid');
        }
        $this->secret = $serverNamespaceSecret;
    }

    public function orderId(string $tenantId, string $checkoutRequestId): string
    {
        return $this->derive('CSO', [
            'tenantId' => $tenantId,
            'checkoutRequestId' => $checkoutRequestId,
        ]);
    }

    public function lineId(string $orderId, string $checkoutLineId): string
    {
        return $this->derive('CSL', [
            'orderId' => $orderId,
            'checkoutLineId' => $checkoutLineId,
        ]);
    }

    private function derive(string $prefix, array $identity): string
    {
        return $prefix . '-' . substr($this->digest($prefix, $identity), 0, 40);
    }

    private function digest(string $prefix, array $identity): string
    {
        try {
            $encoded = CashierV3CheckoutSettlementCanonicalizer::encode($identity);
        } catch (\Throwable $exception) {
            throw new CashierV3SalesOrderAuthorityException(
                'sales_order_server_identity_invalid',
                ['cause' => $exception->getMessage()]
            );
        }
        return hash_hmac('sha256', $prefix . "\n" . $encoded, $this->secret);
    }
}
