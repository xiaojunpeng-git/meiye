<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

final class CashierV3EntitlementDebtGuardProvider implements CashierV3DataScopedVersionProvider
{
    public const TABLE = 'cashier_v3_entitlement_debt_guard';
    public const MUTATION_TABLE = 'cashier_v3_entitlement_debt_guard_mutation';
    public const KIND = 'entitlement_debt_guard';

    public function contractVersion(): string
    {
        return CashierV3EntitlementProviderContracts::DEBT_GUARD;
    }

    public static function identityForOrder(int $originOrderId): string
    {
        if ($originOrderId <= 0) {
            throw self::failure('debt_guard_origin_order_invalid');
        }
        return 'order:' . $originOrderId;
    }

    public function readinessStatus(): array
    {
        $schema = CashierV3EntitlementProviderSchemaProbe::tablesStatus([
            'eb_store_order' => ['id'],
            'eb_' . self::TABLE => [
                'id', 'tenant_id', 'origin_order_id', 'current_version', 'last_action',
                'created_at', 'updated_at',
            ],
            'eb_' . self::MUTATION_TABLE => [
                'id', 'tenant_id', 'mutation_key', 'origin_order_id', 'action',
                'request_fingerprint', 'guard_version_before', 'guard_version_after', 'created_at',
            ],
        ]);
        return [
            'dependency' => 'entitlement_debt_guard',
            'contractVersion' => $this->contractVersion(),
            'ready' => $schema['ready'],
            'reasons' => $schema['ready'] ? [] : ['debt_guard_schema_not_ready'],
            'schema' => $schema,
        ];
    }

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        try {
            $this->assertKind($kind);
            CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
            CashierV3EntitlementProviderDataScope::assertStore($operatorScope->storeId(), $dataScope);
            CashierV3EntitlementProviderDataScope::assertTenant($operatorScope->tenantId(), $dataScope);
            $orderId = $this->originOrderId($resourceId);
            $order = $this->originOrder($orderId, false);
            if ($order === null) {
                return null;
            }
            $this->assertOriginOrderScope($order, $dataScope);
            return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_TENANT, $operatorScope->tenantId());
        } catch (CashierV3EntitlementProviderContractException $exception) {
            return null;
        }
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('entitlementDebtGuardLock');
        $this->assertKind($kind);
        $this->assertTenantScope($scope, $dataScope);
        $orderId = $this->originOrderId($resourceId);
        $hint = $this->originOrder($orderId, false);
        if ($hint === null) {
            return null;
        }
        $this->assertOriginOrderScope($hint, $dataScope);
        $guard = $this->lockOrCreateRow($dataScope->tenantId(), $orderId);
        $locked = $this->originOrder($orderId, true);
        if ($locked === null) {
            throw self::failure('debt_guard_origin_order_disappeared');
        }
        $this->assertOriginOrderScope($locked, $dataScope);
        return (int)$guard['current_version'];
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('entitlementDebtGuardBump');
        $current = $this->lockAndReadVersionWithDataScope($scope, $kind, $resourceId, $dataScope);
        if ($current === null) {
            throw self::failure('debt_guard_not_found');
        }
        return $this->advanceRow($dataScope->tenantId(), $this->originOrderId($resourceId), $current, $action);
    }

    public function lockOrCreateSnapshotInTx(
        int $originOrderId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('entitlementDebtGuardSnapshot');
        CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
        CashierV3EntitlementProviderDataScope::assertTenant($operatorScope->tenantId(), $dataScope);
        $hint = $this->originOrder($originOrderId, false);
        if ($hint === null) {
            throw self::failure('debt_guard_origin_order_not_found', ['originOrderId' => $originOrderId]);
        }
        $this->assertOriginOrderScope($hint, $dataScope);
        $row = $this->lockOrCreateRow($dataScope->tenantId(), $originOrderId);
        $locked = $this->originOrder($originOrderId, true);
        if ($locked === null) {
            throw self::failure('debt_guard_origin_order_disappeared');
        }
        $this->assertOriginOrderScope($locked, $dataScope);
        return [
            'contractVersion' => $this->contractVersion(),
            'resourceKind' => self::KIND,
            'identity' => self::identityForOrder($originOrderId),
            'tenantId' => $dataScope->tenantId(),
            'originOrderId' => $originOrderId,
            'originStoreId' => (int)$locked['store_id'],
            'version' => (int)$row['current_version'],
        ];
    }

    /**
     * Locks the guard, authoritative origin order and mutation receipt before
     * a legacy writer changes any debt row. A replay is returned without a
     * version bump; a new mutation must call advanceAfterDebtMutationInTx in
     * the same transaction after its business writes succeed.
     */
    public function lockMutationReplayInTx(
        int $originOrderId,
        string $mutationKey,
        string $requestFingerprint,
        string $action,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('entitlementDebtGuardMutationPrepare');
        $snapshot = $this->lockOrCreateSnapshotInTx($originOrderId, $operatorScope, $dataScope);
        $contract = $this->normalizeMutationContract($mutationKey, $requestFingerprint, $action);
        $existing = $this->lockMutationReceipt($dataScope->tenantId(), $contract['mutationKey']);
        if ($existing !== null) {
            $this->assertMutationReceiptMatches(
                $existing,
                $originOrderId,
                $contract['requestFingerprint'],
                $contract['action']
            );
            return [
                'idempotentReplay' => true,
                'versionBefore' => (int)$existing['guard_version_before'],
                'versionAfter' => (int)$existing['guard_version_after'],
                'currentVersion' => (int)$snapshot['version'],
                'originStoreId' => (int)$snapshot['originStoreId'],
            ];
        }
        return [
            'idempotentReplay' => false,
            'versionBefore' => (int)$snapshot['version'],
            'versionAfter' => 0,
            'currentVersion' => (int)$snapshot['version'],
            'originStoreId' => (int)$snapshot['originStoreId'],
        ];
    }

    /**
     * Advance only after the debt mutation has succeeded in the same transaction.
     * Replaying the same mutation key and fingerprint returns the first result.
     */
    public function advanceAfterDebtMutationInTx(
        int $originOrderId,
        int $expectedVersion,
        string $mutationKey,
        string $requestFingerprint,
        string $action,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('entitlementDebtGuardMutationAdvance');
        $prepared = $this->lockMutationReplayInTx(
            $originOrderId,
            $mutationKey,
            $requestFingerprint,
            $action,
            $operatorScope,
            $dataScope
        );
        if ($prepared['idempotentReplay']) {
            return [
                'idempotentReplay' => true,
                'versionBefore' => (int)$prepared['versionBefore'],
                'versionAfter' => (int)$prepared['versionAfter'],
            ];
        }
        if ($expectedVersion <= 0 || (int)$prepared['currentVersion'] !== $expectedVersion) {
            throw self::failure('debt_guard_version_conflict', [
                'expectedVersion' => $expectedVersion,
                'currentVersion' => (int)$prepared['currentVersion'],
            ]);
        }
        $contract = $this->normalizeMutationContract($mutationKey, $requestFingerprint, $action);
        $next = $this->advanceRow($dataScope->tenantId(), $originOrderId, $expectedVersion, $action);
        Db::name(self::MUTATION_TABLE)->insert([
            'tenant_id' => $dataScope->tenantId(),
            'mutation_key' => $contract['mutationKey'],
            'origin_order_id' => $originOrderId,
            'action' => $contract['action'],
            'request_fingerprint' => $contract['requestFingerprint'],
            'guard_version_before' => $expectedVersion,
            'guard_version_after' => $next,
            'created_at' => time(),
        ]);
        return [
            'idempotentReplay' => false,
            'versionBefore' => $expectedVersion,
            'versionAfter' => $next,
        ];
    }

    private function lockOrCreateRow(string $tenantId, int $originOrderId): array
    {
        // Insert first, then lock. Selecting a missing unique key FOR UPDATE
        // before inserting lets two creators hold compatible gap locks and
        // deadlock while upgrading them. The unique insert serializes creation.
        $now = time();
        try {
            Db::name(self::TABLE)->insert([
                'tenant_id' => $tenantId,
                'origin_order_id' => $originOrderId,
                'current_version' => 1,
                'last_action' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            $existing = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('origin_order_id', $originOrderId)
                ->lock(true)
                ->find();
            if (!$existing) {
                throw $exception;
            }
        }
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('origin_order_id', $originOrderId)
            ->lock(true)
            ->find();
        if (!$row || (int)($row['current_version'] ?? 0) <= 0) {
            throw self::failure('debt_guard_row_invalid');
        }
        return $row;
    }

    private function advanceRow(string $tenantId, int $originOrderId, int $expectedVersion, string $action): int
    {
        if ($expectedVersion <= 0 || $expectedVersion >= PHP_INT_MAX) {
            throw self::failure('debt_guard_version_invalid');
        }
        $affected = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('origin_order_id', $originOrderId)
            ->where('current_version', $expectedVersion)
            ->update([
                'current_version' => Db::raw('current_version + 1'),
                'last_action' => substr($action, 0, 64),
                'updated_at' => time(),
            ]);
        if ((int)$affected !== 1) {
            throw self::failure('debt_guard_version_conflict');
        }
        return $expectedVersion + 1;
    }

    private function originOrder(int $originOrderId, bool $lock): ?array
    {
        if ($originOrderId <= 0) {
            return null;
        }
        $query = Db::name('store_order')
            ->where('id', $originOrderId)
            ->field('id,store_id');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        return is_array($row) && $row ? $row : null;
    }

    private function assertOriginOrderScope(array $order, CashierV3DataScopeContext $dataScope): void
    {
        $orderId = (int)($order['id'] ?? 0);
        $storeId = (int)($order['store_id'] ?? 0);
        if ($orderId <= 0 || $storeId <= 0) {
            throw self::failure('debt_guard_origin_order_scope_invalid');
        }
        // A valid entitlement can be consumed in another store. The guard
        // serializes debt changes for its original order, but does not grant
        // access to that order or require its purchase store to be the service store.
        CashierV3EntitlementProviderDataScope::assertTenant($dataScope->tenantId(), $dataScope);
    }

    private function normalizeMutationContract(
        string $mutationKey,
        string $requestFingerprint,
        string $action
    ): array {
        $mutationKey = trim($mutationKey);
        $requestFingerprint = strtolower(trim($requestFingerprint));
        $action = trim($action);
        if ($mutationKey === ''
            || strlen($mutationKey) > 128
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $mutationKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $requestFingerprint) !== 1
            || $action === ''
            || strlen($action) > 64
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $action) !== 1) {
            throw self::failure('debt_guard_mutation_contract_invalid');
        }
        return compact('mutationKey', 'requestFingerprint', 'action');
    }

    private function lockMutationReceipt(string $tenantId, string $mutationKey): ?array
    {
        $row = Db::name(self::MUTATION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('mutation_key', $mutationKey)
            ->lock(true)
            ->find();
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        return is_array($row) && $row ? $row : null;
    }

    private function assertMutationReceiptMatches(
        array $existing,
        int $originOrderId,
        string $requestFingerprint,
        string $action
    ): void {
        if ((int)$existing['origin_order_id'] !== $originOrderId
            || !hash_equals((string)$existing['request_fingerprint'], $requestFingerprint)
            || (string)$existing['action'] !== $action) {
            throw self::failure('debt_guard_mutation_key_conflict');
        }
    }

    private function originOrderId(string $resourceId): int
    {
        if (preg_match('/^order:([1-9][0-9]*)$/D', $resourceId, $matches) !== 1) {
            throw self::failure('debt_guard_identity_invalid');
        }
        $orderId = (int)$matches[1];
        if ($orderId <= 0 || (string)$orderId !== $matches[1]) {
            throw self::failure('debt_guard_identity_invalid');
        }
        return $orderId;
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== self::KIND) {
            throw self::failure('debt_guard_kind_invalid');
        }
    }

    private function assertTenantScope(
        CashierV3ResourceScope $scope,
        CashierV3DataScopeContext $dataScope
    ): void {
        CashierV3EntitlementProviderDataScope::assertTenant($scope->id(), $dataScope);
        if ($scope->type() !== CashierV3ResourceScope::TYPE_TENANT) {
            throw self::failure('debt_guard_scope_invalid');
        }
    }

    private static function failure(string $reason, array $detail = []): CashierV3EntitlementProviderContractException
    {
        return new CashierV3EntitlementProviderContractException($reason, $detail);
    }
}
