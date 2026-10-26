<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use think\facade\Db;

/** Tenant-owned, versioned performance rule used by entitlement completion. */
final class CashierV3PerformanceRuleProvider implements CashierV3DataScopedVersionProvider
{
    public const CONTRACT_VERSION = 'cashier-v3-project-performance-rule-v1';
    public const TABLE = 'cashier_v3_project_performance_rule';
    public const KIND = 'performance_rule';
    private const MAX_MONEY_CENTS = 100000000000;

    public function contractVersion(): string
    {
        return self::CONTRACT_VERSION;
    }

    public function readinessStatus(): array
    {
        $schema = CashierV3EntitlementProviderSchemaProbe::tablesStatus([
            'eb_store_product' => [
                'id', 'type', 'relation_id', 'product_type', 'is_del',
            ],
            'eb_' . self::TABLE => [
                'id', 'tenant_id', 'project_id', 'consumption_mode',
                'consumption_configured_unit_amount_cents', 'labor_mode',
                'labor_configured_unit_amount_cents', 'current_version',
                'created_at', 'updated_at',
            ],
        ]);
        return [
            'dependency' => 'performance_rule',
            'contractVersion' => self::CONTRACT_VERSION,
            'ready' => $schema['ready'],
            'reasons' => $schema['ready'] ? [] : ['performance_rule_schema_not_ready'],
            'schema' => $schema,
        ];
    }

    public function discoverVersion(
        int $projectId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
        $this->assertReady();
        $this->assertAuthorizedProject($projectId, $dataScope);
        $row = $this->row(Db::name(self::TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('project_id', $projectId)
            ->find());
        if ($row === null) {
            return 1;
        }
        return $this->validatedVersion($row);
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
            $this->assertReady();
            $this->assertAuthorizedProject($this->positiveId($resourceId), $dataScope);
            return CashierV3ResourceScope::of(
                CashierV3ResourceScope::TYPE_TENANT,
                $dataScope->tenantId()
            );
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
        CashierV3TransactionGuard::assertInTransaction('performanceRuleLock');
        $this->assertKind($kind);
        $projectId = $this->positiveId($resourceId);
        if ($scope->type() !== CashierV3ResourceScope::TYPE_TENANT
            || !hash_equals($scope->id(), $dataScope->tenantId())) {
            return null;
        }
        $this->assertReady();
        $this->assertAuthorizedProject($projectId, $dataScope);
        $this->ensureDefaultRule($dataScope->tenantId(), $projectId);
        $row = $this->row(Db::name(self::TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('project_id', $projectId)
            ->lock(true)
            ->find());
        return $row === null ? null : $this->validatedVersion($row);
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('performanceRuleBump');
        throw self::failure('performance_rule_read_only', [
            'kind' => $kind,
            'resourceId' => $resourceId,
            'action' => $action,
        ]);
    }

    public function snapshotAfterGatewayLock(
        int $projectId,
        int $expectedVersion,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('performanceRuleSnapshot');
        $this->assertReady();
        $this->assertAuthorizedProject($projectId, $dataScope);
        if ($expectedVersion <= 0) {
            throw self::failure('performance_rule_snapshot_version_invalid');
        }
        $row = $this->row(Db::name(self::TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('project_id', $projectId)
            ->find());
        if ($row === null || $this->validatedVersion($row) !== $expectedVersion) {
            throw self::failure('performance_rule_snapshot_version_mismatch');
        }
        return [
            'ruleVersion' => $expectedVersion,
            'consumptionMode' => (string)$row['consumption_mode'],
            'consumptionConfiguredUnitAmount' => self::centsToMoney(
                (int)$row['consumption_configured_unit_amount_cents']
            ),
            'laborMode' => (string)$row['labor_mode'],
            'laborConfiguredUnitAmount' => self::centsToMoney(
                (int)$row['labor_configured_unit_amount_cents']
            ),
        ];
    }

    private function ensureDefaultRule(string $tenantId, int $projectId): void
    {
        $now = time();
        try {
            Db::name(self::TABLE)->insert([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'consumption_mode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
                'consumption_configured_unit_amount_cents' => 0,
                'labor_mode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
                'labor_configured_unit_amount_cents' => 0,
                'current_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateRuleKey($exception)) {
                throw $exception;
            }
        }
    }

    private function validatedVersion(array $row): int
    {
        $version = $this->databaseUnsignedInt(
            $row['current_version'] ?? null,
            PHP_INT_MAX,
            false
        );
        $consumption = (string)($row['consumption_mode'] ?? '');
        $labor = (string)($row['labor_mode'] ?? '');
        $this->databaseUnsignedInt(
            $row['consumption_configured_unit_amount_cents'] ?? null,
            self::MAX_MONEY_CENTS,
            true
        );
        $this->databaseUnsignedInt(
            $row['labor_configured_unit_amount_cents'] ?? null,
            self::MAX_MONEY_CENTS,
            true
        );
        if (!in_array($consumption, [
                CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
                CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED,
            ], true)
            || !in_array($labor, [
                CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
                CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED,
            ], true)) {
            throw self::failure('performance_rule_row_invalid');
        }
        return $version;
    }

    private function assertReady(): void
    {
        if (empty($this->readinessStatus()['ready'])) {
            throw self::failure('performance_rule_provider_not_ready');
        }
    }

    private function assertAuthorizedProject(
        int $projectId,
        CashierV3DataScopeContext $dataScope
    ): void {
        CashierV3EntitlementProviderDataScope::assertTenant($dataScope->tenantId(), $dataScope);
        CashierV3EntitlementProviderDataScope::assertStore(
            $dataScope->forcedStoreId(),
            $dataScope
        );
        // The service store remains scope-checked above. A valid member entitlement
        // may originate from another store and must keep its original project rule.
        $this->assertProject($projectId);
    }

    private function assertProject(int $projectId): void
    {
        if ($projectId <= 0) {
            throw self::failure('performance_rule_project_not_found');
        }
        $project = $this->row(Db::name('store_product')
            ->where('id', $projectId)
            ->where('product_type', 6)
            ->field('id,type,relation_id')
            ->find());
        // A project already captured in the checkout snapshot can be
        // completed even when its catalogue entry is later hidden, unverified
        // or soft-deleted. This provider only establishes the project identity
        // for its final performance-rule record.
        if ($project === null
            || (int)$project['id'] !== $projectId
            || !in_array((int)$project['type'], [0, 1], true)) {
            throw self::failure('performance_rule_project_not_found');
        }
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== self::KIND) {
            throw self::failure('performance_rule_kind_invalid');
        }
    }

    private function positiveId(string $value): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || (string)(int)$value !== $value) {
            throw self::failure('performance_rule_identity_invalid');
        }
        return (int)$value;
    }

    private static function centsToMoney(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function databaseUnsignedInt($value, int $maximum, bool $allowZero): int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > $maximum || (!$allowZero && $value === 0)) {
                throw self::failure('performance_rule_row_invalid');
            }
            $digits = (string)$value;
        } elseif (is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $digits = $value;
        } else {
            throw self::failure('performance_rule_row_invalid');
        }
        $limit = (string)$maximum;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
            || (!$allowZero && $digits === '0')) {
            throw self::failure('performance_rule_row_invalid');
        }
        return (int)$digits;
    }

    private function isDuplicateRuleKey(\Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            $message = strtolower($cursor->getMessage());
            $duplicate = (int)$cursor->getCode() === 1062
                || strpos($message, '1062') !== false
                || strpos($message, 'duplicate entry') !== false;
            if ($duplicate && strpos($message, 'uk_tenant_project') !== false) {
                return true;
            }
        }
        return false;
    }

    private function row($row): ?array
    {
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        return is_array($row) && $row ? $row : null;
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3EntitlementProviderContractException {
        return new CashierV3EntitlementProviderContractException($reason, $detail);
    }
}
