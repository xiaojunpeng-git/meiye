<?php
declare(strict_types=1);

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Tenant-wide member balance authority backed by the real eb_user row.
 *
 * balance_version is mutation-owned: the database trigger advances it when
 * any of now_money/ben_money/give_money changes. bumpVersionWithDataScope()
 * therefore observes the already-advanced row; it must never add a second
 * artificial version after the business mutation.
 */
final class CashierV3MemberBalanceProvider implements CashierV3DataScopedVersionProvider
{
    public const CONTRACT_VERSION = 'cashier-v3-member-balance-authority-v1';
    public const KIND = 'member_balance';
    public const TABLE = 'user';
    public const VERSION_TRIGGER = 'eb_user_balance_version_bu';

    public function contractVersion(): string
    {
        return self::CONTRACT_VERSION;
    }

    public static function identityForMember(int $memberId): string
    {
        if ($memberId <= 0) {
            throw self::failure('member_balance_identity_invalid');
        }
        return (string)$memberId;
    }

    public function readinessStatus(): array
    {
        $required = [
            'uid', 'now_money', 'ben_money', 'give_money', 'balance_version',
            'status', 'is_del', 'delete_time', 'belong_store_id',
        ];
        $columns = $this->columns('eb_user');
        $missing = [];
        foreach ($required as $column) {
            if (!isset($columns[$column])) {
                $missing[] = $column;
            }
        }
        $versionExact = isset($columns['balance_version'])
            && (string)$columns['balance_version']['DATA_TYPE'] === 'bigint'
            && strpos((string)$columns['balance_version']['COLUMN_TYPE'], 'unsigned') !== false
            && (string)$columns['balance_version']['IS_NULLABLE'] === 'NO'
            && (string)$columns['balance_version']['COLUMN_DEFAULT'] === '1';
        $moneyExact = true;
        foreach (['now_money', 'ben_money', 'give_money'] as $moneyColumn) {
            $moneyExact = $moneyExact
                && isset($columns[$moneyColumn])
                && (string)$columns[$moneyColumn]['DATA_TYPE'] === 'decimal'
                && (int)$columns[$moneyColumn]['NUMERIC_SCALE'] === 2
                && (string)$columns[$moneyColumn]['IS_NULLABLE'] === 'NO';
        }
        $trigger = $this->triggerStatus();
        $ready = $missing === [] && $versionExact && $moneyExact && $trigger['ready'];
        $reasons = [];
        if ($missing !== [] || !$versionExact || !$moneyExact) {
            $reasons[] = 'member_balance_schema_not_ready';
        }
        if (!$trigger['ready']) {
            $reasons[] = 'member_balance_version_trigger_not_ready';
        }
        return [
            'dependency' => 'member_balance_authority',
            'contractVersion' => self::CONTRACT_VERSION,
            'ready' => $ready,
            'reasons' => $reasons,
            'schema' => [
                'missingColumns' => $missing,
                'versionColumnExact' => $versionExact,
                'moneyColumnsExact' => $moneyExact,
            ],
            'trigger' => $trigger,
            'versionSource' => 'eb_user.balance_version',
            'versionAdvance' => 'database_trigger_on_real_balance_change',
            'shadowVersionForbidden' => true,
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
            $this->assertBaseDataScope($operatorScope, $dataScope);
            $this->assertStoreAccess($dataScope);
            if (empty($this->readinessStatus()['ready'])) {
                return null;
            }
            $member = $this->loadMember($this->memberId($resourceId), false);
            if (!$this->activeMember($member)) {
                return null;
            }
            return CashierV3ResourceScope::of(
                CashierV3ResourceScope::TYPE_TENANT,
                $dataScope->tenantId()
            );
        } catch (CashierV3MemberBalanceContractException $exception) {
            return null;
        }
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('memberBalanceLock');
        $this->assertKind($kind);
        $this->assertTenantScope($scope, $dataScope);
        $this->assertStoreAccess($dataScope);
        if (empty($this->readinessStatus()['ready'])) {
            throw self::failure('member_balance_provider_not_ready');
        }
        $member = $this->loadMember($this->memberId($resourceId), true);
        if (!$this->activeMember($member)) {
            return null;
        }
        return $this->snapshot($member, $dataScope)['accountVersion'];
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('memberBalanceObserveMutationVersion');
        $version = $this->lockAndReadVersionWithDataScope($scope, $kind, $resourceId, $dataScope);
        if ($version === null || $version <= 0) {
            throw self::failure('member_balance_not_found');
        }
        // ResourceVersionServices verifies this value is lockedVersion + 1.
        // Returning the current real-row version prevents a second fake bump.
        return $version;
    }

    public function lockSnapshotInTx(
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('memberBalanceSnapshot');
        $this->assertBaseDataScope($operatorScope, $dataScope);
        $this->assertStoreAccess($dataScope);
        if (empty($this->readinessStatus()['ready'])) {
            throw self::failure('member_balance_provider_not_ready');
        }
        $member = $this->loadMember($memberId, true);
        if (!$this->activeMember($member)) {
            throw self::failure('member_balance_member_not_found');
        }
        $snapshot = $this->snapshot($member, $dataScope);
        $snapshot['operatorId'] = $operatorScope->operatorId();
        return $snapshot;
    }

    /**
     * Read-only balance snapshot for a checkout projection. The final debit
     * still locks and revalidates this same authority in its own transaction.
     */
    public function readSnapshot(
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertBaseDataScope($operatorScope, $dataScope);
        $this->assertStoreAccess($dataScope);
        if (empty($this->readinessStatus()['ready'])) {
            throw self::failure('member_balance_provider_not_ready');
        }
        $member = $this->loadMember($memberId, false);
        if (!$this->activeMember($member)) {
            throw self::failure('member_balance_member_not_found');
        }
        $snapshot = $this->snapshot($member, $dataScope);
        $snapshot['operatorId'] = $operatorScope->operatorId();
        return $snapshot;
    }

    private function snapshot(array $member, CashierV3DataScopeContext $dataScope): array
    {
        $principal = $this->moneyToCents($member['ben_money'] ?? null);
        $gift = $this->moneyToCents($member['give_money'] ?? null);
        $total = $this->moneyToCents($member['now_money'] ?? null);
        if ($principal + $gift !== $total) {
            throw self::failure('member_balance_invariant_invalid', [
                'memberId' => (int)($member['uid'] ?? 0),
            ]);
        }
        $version = (int)($member['balance_version'] ?? 0);
        if ($version <= 0) {
            throw self::failure('member_balance_version_invalid');
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'resourceKind' => self::KIND,
            'accountId' => (string)(int)$member['uid'],
            'authorityKey' => 'member_balance:' . (int)$member['uid'],
            'memberId' => (int)$member['uid'],
            'tenantId' => $dataScope->tenantId(),
            'storeId' => $dataScope->forcedStoreId(),
            'memberBelongStoreId' => (int)($member['belong_store_id'] ?? 0),
            'accountVersion' => $version,
            'principalCents' => $principal,
            'giftCents' => $gift,
            'totalCents' => $total,
        ];
    }

    private function loadMember(int $memberId, bool $lock): array
    {
        if ($memberId <= 0) {
            throw self::failure('member_balance_identity_invalid');
        }
        try {
            $query = Db::name(self::TABLE)
                ->where('uid', $memberId)
                ->field(
                    'uid,now_money,ben_money,give_money,balance_version,'
                    . 'status,is_del,delete_time,belong_store_id'
                );
            if ($lock) {
                $query->lock(true);
            }
            $row = $query->find();
        } catch (\Throwable $exception) {
            throw self::failure('member_balance_provider_not_ready');
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        return is_array($row) ? $row : [];
    }

    private function activeMember(array $member): bool
    {
        if (!$member
            || (int)($member['uid'] ?? 0) <= 0
            || (int)($member['status'] ?? 0) !== 1
            || (int)($member['is_del'] ?? 1) !== 0) {
            return false;
        }
        $deletedAt = $member['delete_time'] ?? null;
        return $deletedAt === null
            || $deletedAt === ''
            || (string)$deletedAt === '0'
            || (string)$deletedAt === '0000-00-00 00:00:00';
    }

    private function assertBaseDataScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->tenantId() === ''
            || $operatorScope->tenantId() !== $dataScope->tenantId()
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->operatorId() !== $dataScope->operatorId()) {
            throw self::failure('member_balance_data_scope_session_mismatch');
        }
    }

    private function assertStoreAccess(CashierV3DataScopeContext $dataScope): void
    {
        $storeId = $dataScope->forcedStoreId();
        if ($storeId <= 0
            || $dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_NONE
            || $dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT
            || !$dataScope->allowsStore($storeId)) {
            throw self::failure('member_balance_data_scope_store_denied');
        }
        if ($dataScope->operatorId() <= 0 || $dataScope->tenantId() === '') {
            throw self::failure('member_balance_data_scope_invalid');
        }
    }

    private function assertTenantScope(
        CashierV3ResourceScope $scope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($scope->type() !== CashierV3ResourceScope::TYPE_TENANT
            || $scope->id() !== $dataScope->tenantId()
            || $scope->id() === '') {
            throw self::failure('member_balance_scope_invalid');
        }
    }

    private function memberId(string $resourceId): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $resourceId) !== 1) {
            throw self::failure('member_balance_identity_invalid');
        }
        $memberId = (int)$resourceId;
        if ($memberId <= 0 || (string)$memberId !== $resourceId) {
            throw self::failure('member_balance_identity_invalid');
        }
        return $memberId;
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== self::KIND) {
            throw self::failure('member_balance_kind_invalid');
        }
    }

    private function moneyToCents($value): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw self::failure('member_balance_money_invalid');
        }
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $raw, $matches) !== 1) {
            throw self::failure('member_balance_money_invalid');
        }
        $fraction = isset($matches[2]) ? str_pad($matches[2], 2, '0') : '00';
        $whole = (int)$matches[1];
        if ($whole > 99999999) {
            throw self::failure('member_balance_money_invalid');
        }
        return ($whole * 100) + (int)$fraction;
    }

    /** @return array<string,array> */
    private function columns(string $table): array
    {
        try {
            $rows = Db::query(
                'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,NUMERIC_SCALE'
                . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$table]
            );
        } catch (\Throwable $exception) {
            return [];
        }
        $result = [];
        foreach ((array)$rows as $row) {
            $name = (string)($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
            if ($name !== '') {
                $result[$name] = [
                    'DATA_TYPE' => (string)($row['DATA_TYPE'] ?? $row['data_type'] ?? ''),
                    'COLUMN_TYPE' => (string)($row['COLUMN_TYPE'] ?? $row['column_type'] ?? ''),
                    'IS_NULLABLE' => (string)($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? ''),
                    'COLUMN_DEFAULT' => $row['COLUMN_DEFAULT'] ?? $row['column_default'] ?? null,
                    'NUMERIC_SCALE' => $row['NUMERIC_SCALE'] ?? $row['numeric_scale'] ?? null,
                ];
            }
        }
        return $result;
    }

    private function triggerStatus(): array
    {
        try {
            $rows = Db::query(
                'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT'
                . ' FROM information_schema.TRIGGERS'
                . ' WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?',
                [self::VERSION_TRIGGER]
            );
        } catch (\Throwable $exception) {
            $rows = [];
        }
        if (count($rows) !== 1) {
            return ['ready' => false, 'name' => self::VERSION_TRIGGER, 'reason' => 'missing_or_duplicate'];
        }
        $row = $rows[0];
        $statement = strtolower((string)($row['ACTION_STATEMENT'] ?? $row['action_statement'] ?? ''));
        $statement = str_replace(["`", " ", "\t", "\r", "\n"], '', $statement);
        $tokens = [
            'new.now_money<=>old.now_money',
            'new.ben_money<=>old.ben_money',
            'new.give_money<=>old.give_money',
            'new.balance_version=old.balance_version+1',
            'new.balance_version=old.balance_version',
        ];
        $exact = strtoupper((string)($row['ACTION_TIMING'] ?? $row['action_timing'] ?? '')) === 'BEFORE'
            && strtoupper((string)($row['EVENT_MANIPULATION'] ?? $row['event_manipulation'] ?? '')) === 'UPDATE'
            && (string)($row['EVENT_OBJECT_TABLE'] ?? $row['event_object_table'] ?? '') === 'eb_user';
        foreach ($tokens as $token) {
            $exact = $exact && strpos($statement, $token) !== false;
        }
        return [
            'ready' => $exact,
            'name' => self::VERSION_TRIGGER,
            'reason' => $exact ? '' : 'definition_mismatch',
        ];
    }

    private static function failure(string $reason, array $detail = []): CashierV3MemberBalanceContractException
    {
        return new CashierV3MemberBalanceContractException($reason, $detail);
    }
}
