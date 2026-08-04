<?php

namespace app\services\cashier\v3\settlement;

/**
 * Stable opaque IDs derived only inside the server boundary.
 *
 * The future adapter must obtain the namespace secret from server
 * configuration; a client-supplied secret is never valid input.
 */
final class CashierV3CheckoutSettlementIdFactory
{
    private const MIN_SECRET_BYTES = 32;

    /** @var string */
    private $secret;

    public function __construct(string $serverNamespaceSecret)
    {
        if (strlen($serverNamespaceSecret) < self::MIN_SECRET_BYTES) {
            throw new CashierV3CheckoutSettlementContractException('server_id_secret_invalid');
        }
        $this->secret = $serverNamespaceSecret;
    }

    public function requestId(string $tenantId, string $workspaceId, string $creationIdempotencyKey): string
    {
        return $this->derive('CKR', [
            'tenantId' => $tenantId,
            'workspaceId' => $workspaceId,
            'creationIdempotencyKey' => $creationIdempotencyKey,
        ]);
    }

    public function lineId(string $requestId, string $lineRole, string $authorityKey): string
    {
        return $this->derive('CKL', [
            'requestId' => $requestId,
            'lineRole' => $lineRole,
            'authorityKey' => $authorityKey,
        ]);
    }

    public function paymentDraftId(string $requestId, string $paymentAuthorityKey): string
    {
        return $this->derive('CKP', [
            'requestId' => $requestId,
            'paymentAuthorityKey' => $paymentAuthorityKey,
        ]);
    }

    private function derive(string $prefix, array $identity): string
    {
        $digest = hash_hmac(
            'sha256',
            $prefix . "\n" . CashierV3CheckoutSettlementCanonicalizer::encode($identity),
            $this->secret
        );
        return $prefix . '-' . substr($digest, 0, 40);
    }
}
