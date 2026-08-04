<?php

namespace app\services\cashier\v3\settlement;

use think\facade\Db;

/**
 * Read-only one-argument loader used by the Gateway's two-phase checkout
 * source discovery. It never locks and never accepts tenant/store/source
 * values from the client.
 *
 * Legal source changes are possible only through the repository together
 * with checkout request CAS. Therefore the checkout_request FOR UPDATE version
 * check is the lock-phase latest-read gate even under MySQL REPEATABLE READ.
 */
final class CashierV3CheckoutSourceAuthorityLoader
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-source-authority-v2';

    public function __invoke(string $checkoutRequestId): array
    {
        return $this->load($checkoutRequestId);
    }

    public function load(string $checkoutRequestId): array
    {
        $checkoutRequestId = trim($checkoutRequestId);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $checkoutRequestId) !== 1) {
            return [];
        }
        $request = Db::name(ThinkPhpCashierV3CheckoutRequestRepository::REQUEST_TABLE)
            ->where('request_id', $checkoutRequestId)
            ->field('request_id,tenant_id,store_id,request_version,request_status')
            ->find();
        if (!$request) {
            return [];
        }
        $tenantId = (string)($request['tenant_id'] ?? '');
        $storeId = (int)($request['store_id'] ?? 0);
        $requestVersion = (int)($request['request_version'] ?? 0);
        if ($tenantId === '' || $storeId <= 0 || $requestVersion <= 0) {
            throw self::failure('checkout_source_request_authority_invalid');
        }

        $rows = $this->rows(Db::name(ThinkPhpCashierV3CheckoutRequestRepository::SOURCE_TABLE)
            ->where('request_id', $checkoutRequestId)
            ->field(
                'tenant_id,store_id,bound_request_version,source_kind,source_id,'
                . 'source_version,source_role,source_fingerprint'
            )
            ->order('source_kind asc,source_id asc,source_role asc')
            ->select());
        if (!$rows) {
            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'requestId' => $checkoutRequestId,
                'requestVersion' => $requestVersion,
                'requestStatus' => (string)$request['request_status'],
                'sources' => [],
                'references' => [],
            ];
        }

        $authorityRows = [];
        $bindingVersion = null;
        foreach ($rows as $row) {
            $rowBindingVersion = (int)($row['bound_request_version'] ?? 0);
            if ($rowBindingVersion <= 0
                || $rowBindingVersion > $requestVersion
                || ($bindingVersion !== null && $bindingVersion !== $rowBindingVersion)) {
                throw self::failure('checkout_source_binding_version_drift', [
                    'requestId' => $checkoutRequestId,
                    'requestVersion' => $requestVersion,
                    'boundRequestVersion' => $rowBindingVersion,
                ]);
            }
            $bindingVersion = $rowBindingVersion;
            $kind = (string)($row['source_kind'] ?? '');
            $id = (string)($row['source_id'] ?? '');
            $sourceVersion = (int)($row['source_version'] ?? 0);
            $role = (string)($row['source_role'] ?? '');
            $expectedFingerprint = CashierV3CheckoutVerifiedSourceSet::referenceFingerprint(
                $kind,
                $id,
                $sourceVersion,
                $role
            );
            if (!hash_equals((string)($row['source_fingerprint'] ?? ''), $expectedFingerprint)) {
                throw self::failure('checkout_source_reference_fingerprint_drift', [
                    'requestId' => $checkoutRequestId,
                    'kind' => $kind,
                    'id' => $id,
                ]);
            }
            $authorityRows[] = [
                'tenantId' => (string)($row['tenant_id'] ?? ''),
                'storeId' => (int)($row['store_id'] ?? 0),
                'kind' => $kind,
                'id' => $id,
                'sourceVersion' => $sourceVersion,
                'role' => $role,
            ];
        }

        $verified = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
            $tenantId,
            $storeId,
            $authorityRows
        );
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'requestId' => $checkoutRequestId,
            'requestVersion' => $requestVersion,
            'requestStatus' => (string)$request['request_status'],
            'boundRequestVersion' => (int)$bindingVersion,
            'sourceSetFingerprint' => $verified->fingerprint(),
            'sources' => $verified->gatewaySources(),
            'references' => $verified->references(),
        ];
    }

    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
