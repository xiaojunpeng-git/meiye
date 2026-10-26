<?php

namespace app\services\cashier\v3\settlement;

/**
 * Checkout sources that have already been resolved by server-side domain
 * authorities. Never construct this value from an HTTP payload.
 *
 * Every authority row carries its authoritative tenant/store so this boundary
 * can reject a mixed or cross-store set before the repository writes it.
 */
final class CashierV3CheckoutVerifiedSourceSet
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-verified-sources-v2';

    private const KIND_ORDER = [
        'debt_record' => 65,
        'service_order' => 70,
        'hang_order' => 90,
        'reservation' => 100,
        'room' => 110,
    ];

    private const MAX_SOURCES = 32;

    /** @var string */
    private $tenantId;

    /** @var int */
    private $storeId;

    /** @var array<int,array{kind:string,id:string,sourceVersion:int,role:string}> */
    private $references;

    /** @var string */
    private $fingerprint;

    private function __construct(string $tenantId, int $storeId, array $references)
    {
        $this->tenantId = $tenantId;
        $this->storeId = $storeId;
        $this->references = $references;
        $this->fingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint([
            'contractVersion' => self::CONTRACT_VERSION,
            'tenantId' => $tenantId,
            'storeId' => $storeId,
            'references' => $references,
        ]);
    }

    /**
     * @param array<int,array{tenantId:string,storeId:int,kind:string,id:string,sourceVersion:int,role:string}> $authorityRows
     */
    public static function fromServerVerifiedAuthorityRows(
        string $tenantId,
        int $storeId,
        array $authorityRows
    ): self {
        $tenantId = self::token($tenantId, 32, 'tenant_id');
        if ($storeId <= 0) {
            throw self::failure('checkout_source_store_invalid');
        }
        // A plain cashier checkout is rooted only in its workspace and has no
        // service/reservation/room navigation source. An empty verified set is
        // therefore valid; the checkout request itself still freezes tenant,
        // store, workspace and cart authority.
        if (count($authorityRows) > self::MAX_SOURCES) {
            throw self::failure('checkout_source_count_invalid', [
                'count' => count($authorityRows),
            ]);
        }

        $references = [];
        $identities = [];
        $tuples = [];
        foreach (array_values($authorityRows) as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_source_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys(
                $row,
                ['tenantId', 'storeId', 'kind', 'id', 'sourceVersion', 'role'],
                'checkout_source',
                $index
            );
            $rowTenantId = self::token($row['tenantId'], 32, 'source_tenant_id');
            $rowStoreId = self::positiveInt($row['storeId'], 'source_store_id');
            if (!hash_equals($tenantId, $rowTenantId) || $storeId !== $rowStoreId) {
                throw self::failure('checkout_source_scope_mismatch', [
                    'index' => $index,
                    'expectedTenantId' => $tenantId,
                    'expectedStoreId' => $storeId,
                ]);
            }

            $kind = self::token($row['kind'], 32, 'source_kind');
            if (!isset(self::KIND_ORDER[$kind])) {
                throw self::failure('checkout_source_kind_invalid', [
                    'index' => $index,
                    'kind' => $kind,
                ]);
            }
            $id = self::sourceId($row['id']);
            $sourceVersion = self::positiveInt($row['sourceVersion'], 'source_version');
            $role = self::token($row['role'], 32, 'source_role');
            $identity = $kind . "\0" . $id;
            $tuple = $identity . "\0" . $role;
            if (isset($identities[$identity]) || isset($tuples[$tuple])) {
                throw self::failure('checkout_source_duplicate', [
                    'index' => $index,
                    'kind' => $kind,
                    'id' => $id,
                    'role' => $role,
                ]);
            }
            $identities[$identity] = true;
            $tuples[$tuple] = true;
            $references[] = [
                'kind' => $kind,
                'id' => $id,
                'sourceVersion' => $sourceVersion,
                'role' => $role,
            ];
        }

        usort($references, static function (array $left, array $right): int {
            $kindOrder = self::KIND_ORDER[$left['kind']] <=> self::KIND_ORDER[$right['kind']];
            if ($kindOrder !== 0) {
                return $kindOrder;
            }
            $idOrder = strcmp($left['id'], $right['id']);
            return $idOrder !== 0 ? $idOrder : strcmp($left['role'], $right['role']);
        });

        return new self($tenantId, $storeId, $references);
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    /** @return array<int,array{kind:string,id:string,sourceVersion:int,role:string}> */
    public function references(): array
    {
        return $this->references;
    }

    /** @return array<int,array{kind:string,id:string}> */
    public function gatewaySources(): array
    {
        return array_map(static function (array $reference): array {
            return ['kind' => $reference['kind'], 'id' => $reference['id']];
        }, $this->references);
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public static function referenceFingerprint(
        string $kind,
        string $id,
        int $sourceVersion,
        string $role
    ): string
    {
        return CashierV3CheckoutSettlementCanonicalizer::fingerprint([
            'kind' => $kind,
            'id' => $id,
            'sourceVersion' => $sourceVersion,
            'role' => $role,
        ]);
    }

    /** @return string[] */
    public static function supportedKinds(): array
    {
        return array_keys(self::KIND_ORDER);
    }

    private static function sourceId($value): string
    {
        $id = self::token($value, 64, 'source_id');
        if (strpos($id, '://') !== false
            || strpos($id, '/') !== false
            || strpos($id, '\\') !== false
            || strpos($id, '?') !== false
            || strpos($id, '#') !== false
            || strpos($id, '%') !== false) {
            throw self::failure('checkout_source_id_url_forbidden');
        }
        return $id;
    }

    private static function token($value, int $maxLength, string $field): string
    {
        if (!is_string($value)) {
            throw self::failure('checkout_source_token_invalid', ['field' => $field]);
        }
        $value = trim($value);
        if ($value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw self::failure('checkout_source_token_invalid', ['field' => $field]);
        }
        return $value;
    }

    private static function positiveInt($value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw self::failure('checkout_source_positive_int_invalid', ['field' => $field]);
        }
        return $value;
    }

    private static function assertExactKeys(
        array $row,
        array $required,
        string $field,
        int $index
    ): void {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw self::failure('checkout_source_shape_invalid', [
                'field' => $field,
                'index' => $index,
                'actualKeys' => $actual,
            ]);
        }
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
