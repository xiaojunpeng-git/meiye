<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3ResourceKindCatalog;

/**
 * Server-built, versioned resource plan for one checkout request version.
 *
 * This value must never be assembled from HTTP contexts. Multiple logical
 * roles may point at one physical resource; the physical row is stored once
 * with a canonical sorted role list.
 */
final class CashierV3CheckoutVerifiedResourcePlan
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-resource-plan-v1';
    public const ACCESS_READ = 'read';
    public const ACCESS_MUTATE = 'mutate';
    public const MAX_RESOURCES = 10000;
    public const MAX_ROLES = 10000;

    /** @var string */
    private $tenantId;

    /** @var int */
    private $storeId;

    /** @var int */
    private $boundRequestVersion;

    /** @var array<int,array> */
    private $resources;

    /** @var int */
    private $roleCount;

    /** @var string */
    private $fingerprint;

    private function __construct(
        string $tenantId,
        int $storeId,
        int $boundRequestVersion,
        array $resources,
        int $roleCount
    ) {
        $this->tenantId = $tenantId;
        $this->storeId = $storeId;
        $this->boundRequestVersion = $boundRequestVersion;
        $this->resources = $resources;
        $this->roleCount = $roleCount;
        $this->fingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint([
            'contractVersion' => self::CONTRACT_VERSION,
            'tenantId' => $tenantId,
            'storeId' => $storeId,
            'boundRequestVersion' => $boundRequestVersion,
            'resources' => $resources,
        ]);
    }

    /**
     * @param array<int,array{
     *   tenantId:string,storeId:int,kind:string,id:string,scopeType:string,
     *   scopeId:string,lockOrder:int,expectedVersion:int,roles:array,
     *   accessMode:string,providerContractVersion:string,authorityFingerprint:string
     * }> $authorityRows
     */
    public static function fromServerVerifiedAuthorityRows(
        string $tenantId,
        int $storeId,
        int $boundRequestVersion,
        array $authorityRows,
        bool $allowEmpty = false
    ): self {
        $tenantId = self::token($tenantId, 32, 'tenant_id');
        if ($storeId <= 0) {
            throw self::failure('checkout_resource_plan_store_invalid');
        }
        if ($boundRequestVersion <= 0) {
            throw self::failure('checkout_resource_plan_bound_version_invalid');
        }
        if ((!$allowEmpty && !$authorityRows) || count($authorityRows) > self::MAX_RESOURCES) {
            throw self::failure('checkout_resource_plan_count_invalid', [
                'count' => count($authorityRows),
                'limit' => self::MAX_RESOURCES,
            ]);
        }

        $byPhysical = [];
        $kindOrders = [];
        foreach (array_values($authorityRows) as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_resource_plan_row_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($row, [
                'tenantId', 'storeId', 'kind', 'id', 'scopeType', 'scopeId',
                'lockOrder', 'expectedVersion', 'roles', 'accessMode',
                'providerContractVersion', 'authorityFingerprint',
            ], $index);
            if (!hash_equals($tenantId, self::token($row['tenantId'], 32, 'row_tenant_id'))
                || self::positiveInt($row['storeId'], 'row_store_id') !== $storeId) {
                throw self::failure('checkout_resource_plan_scope_mismatch', ['index' => $index]);
            }

            $kind = self::token($row['kind'], 32, 'resource_kind');
            if (!CashierV3ResourceKindCatalog::isKnown($kind)) {
                throw self::failure('checkout_resource_plan_kind_unknown', [
                    'index' => $index,
                    'kind' => $kind,
                ]);
            }
            $id = self::resourceId($row['id']);
            $scopeType = self::scopeType($row['scopeType']);
            $scopeId = self::token($row['scopeId'], 32, 'scope_id');
            if (($scopeType === 'tenant' && !hash_equals($tenantId, $scopeId))
                || ($scopeType === 'store' && $scopeId !== (string)$storeId)) {
                throw self::failure('checkout_resource_plan_canonical_scope_mismatch', [
                    'index' => $index,
                    'kind' => $kind,
                ]);
            }
            $lockOrder = self::positiveInt($row['lockOrder'], 'lock_order');
            $canonicalLockOrder = CashierV3ResourceKindCatalog::lockOrderOf($kind);
            if ($lockOrder !== $canonicalLockOrder) {
                throw self::failure('checkout_resource_plan_lock_order_mismatch', [
                    'index' => $index,
                    'kind' => $kind,
                    'expectedLockOrder' => $canonicalLockOrder,
                    'actualLockOrder' => $lockOrder,
                ]);
            }
            $expectedVersion = self::positiveInt($row['expectedVersion'], 'expected_version');
            if (isset($kindOrders[$kind]) && $kindOrders[$kind] !== $lockOrder) {
                throw self::failure('checkout_resource_plan_kind_order_inconsistent', ['kind' => $kind]);
            }
            $kindOrders[$kind] = $lockOrder;
            $roles = self::roles($row['roles'], $index);
            $accessMode = self::accessMode($row['accessMode']);
            $providerContractVersion = self::token(
                $row['providerContractVersion'],
                128,
                'provider_contract_version'
            );
            $authorityFingerprint = self::fingerprintToken(
                $row['authorityFingerprint'],
                'authority_fingerprint'
            );
            $physical = $kind . "\0" . $id;
            $normalized = [
                'kind' => $kind,
                'id' => $id,
                'scopeType' => $scopeType,
                'scopeId' => $scopeId,
                'lockOrder' => $lockOrder,
                'expectedVersion' => $expectedVersion,
                'roles' => $roles,
                'accessMode' => $accessMode,
                'providerContractVersion' => $providerContractVersion,
                'authorityFingerprint' => $authorityFingerprint,
            ];

            if (!isset($byPhysical[$physical])) {
                $byPhysical[$physical] = $normalized;
                continue;
            }
            $existing = $byPhysical[$physical];
            foreach ([
                'kind', 'id', 'scopeType', 'scopeId', 'lockOrder',
                'expectedVersion', 'providerContractVersion', 'authorityFingerprint',
            ] as $field) {
                if ($existing[$field] !== $normalized[$field]) {
                    throw self::failure('checkout_resource_plan_duplicate_inconsistent', [
                        'index' => $index,
                        'kind' => $kind,
                        'id' => $id,
                        'field' => $field,
                    ]);
                }
            }
            $existing['roles'] = array_values(array_unique(array_merge(
                $existing['roles'],
                $normalized['roles']
            )));
            sort($existing['roles'], SORT_STRING);
            if ($normalized['accessMode'] === self::ACCESS_MUTATE) {
                $existing['accessMode'] = self::ACCESS_MUTATE;
            }
            $byPhysical[$physical] = $existing;
        }

        $resources = array_values($byPhysical);
        if ((!$resources && !$allowEmpty) || count($resources) > self::MAX_RESOURCES) {
            throw self::failure('checkout_resource_plan_count_invalid');
        }
        usort($resources, static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResources(
                $left['kind'],
                $left['id'],
                $right['kind'],
                $right['id']
            );
        });

        $seenRoles = [];
        $roleCount = 0;
        foreach ($resources as &$resource) {
            foreach ($resource['roles'] as $role) {
                if (isset($seenRoles[$role])) {
                    throw self::failure('checkout_resource_plan_role_duplicate', ['role' => $role]);
                }
                $seenRoles[$role] = true;
                $roleCount++;
                if ($roleCount > self::MAX_ROLES) {
                    throw self::failure('checkout_resource_plan_role_limit_exceeded');
                }
            }
            $resource['rowFingerprint'] = self::rowFingerprint($resource);
        }
        unset($resource);

        return new self($tenantId, $storeId, $boundRequestVersion, $resources, $roleCount);
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    public function boundRequestVersion(): int
    {
        return $this->boundRequestVersion;
    }

    /** @return array<int,array> */
    public function resources(): array
    {
        return $this->resources;
    }

    /** @return array<int,array{kind:string,id:string,expectedVersion:int}> */
    public function trustedContexts(): array
    {
        return array_map(static function (array $resource): array {
            return [
                'kind' => $resource['kind'],
                'id' => $resource['id'],
                'expectedVersion' => $resource['expectedVersion'],
            ];
        }, $this->resources);
    }

    public function resourceCount(): int
    {
        return count($this->resources);
    }

    public function roleCount(): int
    {
        return $this->roleCount;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public static function rowFingerprint(array $resource): string
    {
        $copy = $resource;
        unset($copy['rowFingerprint']);
        return CashierV3CheckoutSettlementCanonicalizer::fingerprint($copy);
    }

    private static function roles($value, int $index): array
    {
        if (!is_array($value) || !$value || !self::isList($value)) {
            throw self::failure('checkout_resource_plan_roles_invalid', ['index' => $index]);
        }
        $roles = [];
        foreach ($value as $role) {
            $role = self::token($role, 128, 'resource_role');
            if (isset($roles[$role])) {
                throw self::failure('checkout_resource_plan_role_duplicate_in_row', [
                    'index' => $index,
                    'role' => $role,
                ]);
            }
            $roles[$role] = $role;
        }
        $roles = array_values($roles);
        sort($roles, SORT_STRING);
        return $roles;
    }

    private static function scopeType($value): string
    {
        if (!is_string($value) || !in_array($value, ['tenant', 'store'], true)) {
            throw self::failure('checkout_resource_plan_scope_type_invalid');
        }
        return $value;
    }

    private static function accessMode($value): string
    {
        if (!is_string($value) || !in_array($value, [self::ACCESS_READ, self::ACCESS_MUTATE], true)) {
            throw self::failure('checkout_resource_plan_access_mode_invalid');
        }
        return $value;
    }

    private static function resourceId($value): string
    {
        return self::token($value, 64, 'resource_id');
    }

    private static function token($value, int $maxLength, string $field): string
    {
        if (!is_string($value)) {
            throw self::failure('checkout_resource_plan_token_invalid', ['field' => $field]);
        }
        $value = trim($value);
        if ($value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw self::failure('checkout_resource_plan_token_invalid', ['field' => $field]);
        }
        return $value;
    }

    private static function fingerprintToken($value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw self::failure('checkout_resource_plan_fingerprint_invalid', ['field' => $field]);
        }
        return $value;
    }

    private static function positiveInt($value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw self::failure('checkout_resource_plan_positive_int_invalid', ['field' => $field]);
        }
        return $value;
    }

    private static function assertExactKeys(array $row, array $required, int $index): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw self::failure('checkout_resource_plan_row_shape_invalid', [
                'index' => $index,
                'actualKeys' => $actual,
            ]);
        }
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }
        return true;
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
