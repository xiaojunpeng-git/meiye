<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;

/**
 * Combines checkout sale discovery with the entitlement completion authority.
 *
 * The sale discovery currently includes compatibility entitlement identities.
 * When the authority adapter returns the same physical resource, its provider
 * contract and authority fingerprint replace the compatibility metadata while
 * every role remains attached to the one physical lock.
 */
final class CashierV3CheckoutSubmissionResourceDiscoveryComposite
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-submission-discovery-composite-v1';

    /** @var array<int,array{origin:string,discoverer:\Closure}> */
    private $discoverers;

    /**
     * The third discoverer is intentionally optional: it is used for a
     * member-balance authority only after a checkout draft has selected a
     * balance deduction. Existing entitlement-only callers retain the exact
     * two-source contract.
     */
    public function __construct(
        callable $saleDiscoverer,
        callable $entitlementAuthorityDiscoverer,
        ?callable $balanceAuthorityDiscoverer = null
    ) {
        $this->discoverers = [
            [
                'origin' => 'sale',
                'discoverer' => \Closure::fromCallable($saleDiscoverer),
            ],
            [
                'origin' => 'entitlement_authority',
                'discoverer' => \Closure::fromCallable($entitlementAuthorityDiscoverer),
            ],
        ];
        if ($balanceAuthorityDiscoverer !== null) {
            $this->discoverers[] = [
                'origin' => 'balance_authority',
                'discoverer' => \Closure::fromCallable($balanceAuthorityDiscoverer),
            ];
        }
    }

    public function discover(array $scope): array
    {
        $byPhysical = [];
        foreach ($this->discoverers as $definition) {
            $origin = $definition['origin'];
            $pack = ($definition['discoverer'])($scope);
            foreach ($this->normalizePack($pack, $origin) as $resource) {
                $this->merge($byPhysical, $resource, $origin);
            }
        }

        $resources = [];
        foreach ($byPhysical as $resource) {
            unset($resource['_origin']);
            $resources[] = $resource;
        }
        usort($resources, static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResources(
                $left['kind'],
                $left['id'],
                $right['kind'],
                $right['id']
            );
        });

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'resources' => $resources,
        ];
    }

    private function normalizePack($pack, string $origin): array
    {
        if (!is_array($pack)
            || !is_string($pack['contractVersion'] ?? null)
            || trim($pack['contractVersion']) === ''
            || !isset($pack['resources'])
            || !is_array($pack['resources'])
            || !self::isList($pack['resources'])
            || count($pack['resources']) > 10000) {
            throw self::invalid('checkout_submission_discovery_pack_invalid', ['origin' => $origin]);
        }
        $result = [];
        foreach ($pack['resources'] as $index => $resource) {
            $result[] = $this->normalizeResource($resource, $origin, $index);
        }
        return $result;
    }

    private function normalizeResource($resource, string $origin, int $index): array
    {
        if (!is_array($resource)) {
            throw self::invalid('checkout_submission_discovery_resource_invalid', [
                'origin' => $origin,
                'index' => $index,
            ]);
        }
        $kind = trim((string)($resource['kind'] ?? ''));
        $id = trim((string)($resource['id'] ?? ''));
        $version = $resource['expectedVersion'] ?? null;
        $rolesInput = $resource['roles'] ?? null;
        $accessMode = trim((string)($resource['accessMode'] ?? ''));
        $providerContractVersion = trim((string)($resource['providerContractVersion'] ?? ''));
        $authorityFingerprint = trim((string)($resource['authorityFingerprint'] ?? ''));
        CashierV3ResourceKindCatalog::assertKnown($kind);
        if ($id === ''
            || strlen($id) > 64
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $id) !== 1
            || !is_int($version)
            || $version <= 0
            || !is_array($rolesInput)
            || !self::isList($rolesInput)
            || !$rolesInput
            || !in_array($accessMode, ['read', 'mutate'], true)
            || $providerContractVersion === ''
            || strlen($providerContractVersion) > 128
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $providerContractVersion) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $authorityFingerprint) !== 1) {
            throw self::invalid('checkout_submission_discovery_resource_invalid', [
                'origin' => $origin,
                'index' => $index,
            ]);
        }
        $roles = [];
        foreach ($rolesInput as $roleInput) {
            $role = is_string($roleInput) ? trim($roleInput) : '';
            if ($role === ''
                || strlen($role) > 128
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $role) !== 1) {
                throw self::invalid('checkout_submission_discovery_role_invalid', [
                    'origin' => $origin,
                    'index' => $index,
                ]);
            }
            $roles[$role] = $role;
        }
        $roles = array_values($roles);
        sort($roles, SORT_STRING);

        return [
            'kind' => $kind,
            'id' => $id,
            'expectedVersion' => $version,
            'roles' => $roles,
            'accessMode' => $accessMode,
            'providerContractVersion' => $providerContractVersion,
            'authorityFingerprint' => $authorityFingerprint,
        ];
    }

    private function merge(array &$byPhysical, array $resource, string $origin): void
    {
        $physical = $resource['kind'] . "\0" . $resource['id'];
        if (!isset($byPhysical[$physical])) {
            $resource['_origin'] = $origin;
            $byPhysical[$physical] = $resource;
            return;
        }

        $existing = $byPhysical[$physical];
        if ($existing['expectedVersion'] !== $resource['expectedVersion']) {
            throw CashierV3CommandException::versionConflict(
                '结账资源版本不一致，请刷新购物车后重试。',
                [
                    'reason' => 'checkout_submission_discovery_version_conflict',
                    'kind' => $resource['kind'],
                    'id' => $resource['id'],
                    'leftVersion' => $existing['expectedVersion'],
                    'rightVersion' => $resource['expectedVersion'],
                ]
            );
        }

        $roles = array_values(array_unique(array_merge($existing['roles'], $resource['roles'])));
        sort($roles, SORT_STRING);
        $existing['roles'] = $roles;
        if ($resource['accessMode'] === 'mutate') {
            $existing['accessMode'] = 'mutate';
        }

        if ($existing['_origin'] === $origin) {
            if ($existing['providerContractVersion'] !== $resource['providerContractVersion']
                || $existing['authorityFingerprint'] !== $resource['authorityFingerprint']) {
                throw self::invalid('checkout_submission_discovery_provider_conflict', [
                    'origin' => $origin,
                    'kind' => $resource['kind'],
                    'id' => $resource['id'],
                ]);
            }
        } elseif ($origin === 'entitlement_authority') {
            $existing['providerContractVersion'] = $resource['providerContractVersion'];
            $existing['authorityFingerprint'] = $resource['authorityFingerprint'];
            $existing['_origin'] = $origin;
        }
        $byPhysical[$physical] = $existing;
    }

    private static function isList(array $value): bool
    {
        return !$value || array_keys($value) === range(0, count($value) - 1);
    }

    private static function invalid(string $reason, array $detail = []): CashierV3CommandException
    {
        return CashierV3CommandException::invalidContext(
            '结账所需的服务端资源集合不完整，请刷新购物车后重试。',
            ['reason' => $reason] + $detail
        );
    }
}
