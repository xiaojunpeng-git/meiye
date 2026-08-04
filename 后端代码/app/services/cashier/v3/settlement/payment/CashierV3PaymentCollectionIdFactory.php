<?php

namespace app\services\cashier\v3\settlement\payment;

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;

/** Server-only stable identifiers; the namespace secret never comes from HTTP. */
final class CashierV3PaymentCollectionIdFactory
{
    private const MIN_SECRET_BYTES = 32;

    /** @var string */
    private $secret;

    public function __construct(string $serverNamespaceSecret)
    {
        if (strlen($serverNamespaceSecret) < self::MIN_SECRET_BYTES) {
            throw new CashierV3PaymentCollectionAuthorityException(
                'payment_collection_server_id_secret_invalid'
            );
        }
        $this->secret = $serverNamespaceSecret;
    }

    public function batchId(string $tenantId, string $checkoutRequestId): string
    {
        return $this->derive('CPB', [
            'tenantId' => $tenantId,
            'checkoutRequestId' => $checkoutRequestId,
        ]);
    }

    public function collectionId(
        string $tenantId,
        string $checkoutRequestId,
        string $paymentDraftId
    ): string {
        return $this->derive('CPC', [
            'tenantId' => $tenantId,
            'checkoutRequestId' => $checkoutRequestId,
            'paymentDraftId' => $paymentDraftId,
        ]);
    }

    public function collectionNo(
        string $tenantId,
        string $checkoutRequestId,
        string $paymentDraftId,
        string $businessDate
    ): string {
        $digest = $this->digest('PC', [
            'tenantId' => $tenantId,
            'checkoutRequestId' => $checkoutRequestId,
            'paymentDraftId' => $paymentDraftId,
        ]);
        return 'PC-' . str_replace('-', '', $businessDate) . '-'
            . strtoupper(substr($digest, 0, 20));
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
            throw new CashierV3PaymentCollectionAuthorityException(
                'payment_collection_server_identity_invalid',
                ['cause' => $exception->getMessage()]
            );
        }
        return hash_hmac('sha256', $prefix . "\n" . $encoded, $this->secret);
    }
}
