<?php
declare(strict_types=1);

namespace app\services\product\inventory\completion;

/**
 * Server-derived inventory scope. Request payloads may narrow this scope but
 * can never construct or widen it.
 */
final class InventoryCompletionDataScope
{
    /** @var string */
    private $tenantId;

    /** @var string */
    private $organizationId;

    /** @var string */
    private $organizationPath;

    /** @var array<int,int> */
    private $storeIds;

    /** @var int */
    private $operatorId;

    public function __construct(
        string $tenantId,
        string $organizationId,
        string $organizationPath,
        array $storeIds,
        int $operatorId
    ) {
        $tenantId = trim($tenantId);
        $organizationId = trim($organizationId);
        $organizationPath = trim($organizationPath);
        if ($tenantId === '' || strlen($tenantId) > 32 || !self::isToken($tenantId)) {
            throw new \InvalidArgumentException('inventory_scope_tenant_invalid');
        }
        if ($organizationId === '' || strlen($organizationId) > 32 || !self::isToken($organizationId)) {
            throw new \InvalidArgumentException('inventory_scope_organization_invalid');
        }
        if ($organizationPath === '' || strlen($organizationPath) > 191 || !self::isPathToken($organizationPath)) {
            throw new \InvalidArgumentException('inventory_scope_organization_path_invalid');
        }
        if ($operatorId <= 0) {
            throw new \InvalidArgumentException('inventory_scope_operator_invalid');
        }
        $normalizedStores = [];
        foreach ($storeIds as $storeId) {
            if (!is_int($storeId) || $storeId <= 0) {
                throw new \InvalidArgumentException('inventory_scope_store_invalid');
            }
            $normalizedStores[$storeId] = $storeId;
        }
        if (!$normalizedStores) {
            throw new \InvalidArgumentException('inventory_scope_stores_empty');
        }
        ksort($normalizedStores, SORT_NUMERIC);
        $this->tenantId = $tenantId;
        $this->organizationId = $organizationId;
        $this->organizationPath = $organizationPath;
        $this->storeIds = array_values($normalizedStores);
        $this->operatorId = $operatorId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function organizationPath(): string
    {
        return $this->organizationPath;
    }

    public function operatorId(): int
    {
        return $this->operatorId;
    }

    public function assertTenantAndStore(string $tenantId, int $storeId): void
    {
        if ($tenantId !== $this->tenantId) {
            throw new InventoryCompletionContractException('inventory_data_scope_tenant_denied');
        }
        if (!in_array($storeId, $this->storeIds, true)) {
            throw new InventoryCompletionContractException('inventory_data_scope_store_denied', [
                'storeId' => $storeId,
            ]);
        }
    }

    private static function isToken(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9:._-]+$/D', $value) === 1;
    }

    private static function isPathToken(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9:._\/-]+$/D', $value) === 1;
    }
}
