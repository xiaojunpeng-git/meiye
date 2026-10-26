<?php

namespace app\services\cashier\v3\service;

interface CashierV3ServiceOrderRepository
{
    /** @return mixed */
    public function transaction(callable $callback);

    /** @return array */
    public function lockOrCreateEntitlementGuard(string $tenantId, int $entitlementSourceDetailId): array;

    /**
     * The entitlement guard must already be locked. Implementations lock orders
     * by ascending id and then every line of those orders by ascending id.
     *
     * @return array{orders:array<int,array>,lines:array<int,array>,targetLines:array<int,array>}
     */
    public function lockOccupationSet(
        string $tenantId,
        int $entitlementSourceDetailId,
        int $includeServiceOrderId = 0
    ): array;

    public function findOperation(string $tenantId, string $idempotencyKey): ?array;

    public function insertServiceOrder(array $row): array;

    public function insertLine(array $row): array;

    public function updateServiceOrderCas(
        string $tenantId,
        int $serviceOrderId,
        int $expectedVersion,
        array $fields
    ): bool;

    public function updateLineCas(
        string $tenantId,
        int $lineId,
        int $expectedVersion,
        array $fields
    ): bool;

    public function bumpEntitlementGuardCas(
        string $tenantId,
        int $entitlementSourceDetailId,
        int $expectedVersion,
        string $action,
        int $now
    ): int;

    public function insertOperation(array $row): array;
}
