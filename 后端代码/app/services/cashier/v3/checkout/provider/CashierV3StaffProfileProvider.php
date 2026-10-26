<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3PersonnelIdentity;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

final class CashierV3StaffProfileProvider implements CashierV3DataScopedVersionProvider
{
    public const TABLE = 'cashier_v3_staff_profile_version';
    public const KIND = 'staff_profile';

    /** @var CashierV3StaffTypeAuthority|null */
    private $typeAuthority;

    public function __construct(CashierV3StaffTypeAuthority $typeAuthority = null)
    {
        $this->typeAuthority = $typeAuthority;
    }

    public function contractVersion(): string
    {
        return CashierV3EntitlementProviderContracts::STAFF_PROFILE;
    }

    public function readinessStatus(): array
    {
        $schema = CashierV3EntitlementProviderSchemaProbe::tablesStatus([
            'eb_system_store_staff' => ['id', 'employee_id', 'store_id', 'staff_name', 'status', 'is_del', 'cashier_craftsman_enabled'],
            'eb_employee' => ['id', 'name', 'status', 'is_del'],
            'eb_' . self::TABLE => [
                'id', 'tenant_id', 'staff_id', 'employee_id_snapshot', 'store_id_snapshot',
                'profile_fingerprint', 'current_version', 'last_action', 'created_at', 'updated_at',
            ],
        ]);
        $typeStatus = $this->typeAuthority
            ? $this->typeAuthority->readinessStatus()
            : ['ready' => false, 'reasons' => ['staff_type_authority_missing']];
        $typeContract = $this->typeAuthority ? $this->typeAuthority->contractVersion() : '';
        $typeReady = !empty($typeStatus['ready'])
            && $typeContract === CashierV3EntitlementProviderContracts::STAFF_TYPE_AUTHORITY;
        $reasons = [];
        if (!$schema['ready']) {
            $reasons[] = 'staff_profile_schema_not_ready';
        }
        if (!$typeReady) {
            $reasons[] = 'staff_type_authority_not_ready';
        }
        return [
            'dependency' => 'staff_profile',
            'contractVersion' => $this->contractVersion(),
            'ready' => $schema['ready'] && $typeReady,
            'reasons' => $reasons,
            'schema' => $schema,
            'staffTypeAuthority' => [
                'contractVersion' => $typeContract,
                'status' => $typeStatus,
            ],
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
            if (empty($this->readinessStatus()['ready'])) {
                return null;
            }
            $staffId = $this->positiveId($resourceId, 'staff_profile_identity_invalid');
            if (CashierV3PersonnelIdentity::isOrganizationStaffId($staffId)) {
                $employee = Db::name('employee')->where('id', CashierV3PersonnelIdentity::employeeIdFromStaffId($staffId))->find();
                return $employee && $this->activeEmployee($employee)
                    ? CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$dataScope->forcedStoreId())
                    : null;
            }
            $staff = Db::name('system_store_staff')->where('id', $staffId)->find();
            if (!$staff || !$this->activeStaff($staff)) {
                return null;
            }
            $employee = Db::name('employee')->where('id', (int)$staff['employee_id'])->find();
            if (!$employee || !$this->activeEmployee($employee)) {
                return null;
            }
            CashierV3EntitlementProviderDataScope::assertStore(
                (int)$staff['store_id'],
                $dataScope,
                (int)$staff['employee_id']
            );
            return CashierV3ResourceScope::of(
                CashierV3ResourceScope::TYPE_STORE,
                (string)$staff['store_id']
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
        CashierV3TransactionGuard::assertInTransaction('staffProfileLock');
        $this->assertKind($kind);
        try {
            $staffId = $this->positiveId($resourceId, 'staff_profile_identity_invalid');
            $profile = CashierV3PersonnelIdentity::isOrganizationStaffId($staffId)
                ? $this->lockOrganizationProfile($staffId, $dataScope)
                : $this->lockProfile($staffId, $dataScope);
            if ($scope->type() !== CashierV3ResourceScope::TYPE_STORE
                || $scope->id() !== (string)$profile['storeId']) {
                return null;
            }
            return (int)$profile['staffVersion'];
        } catch (CashierV3EntitlementProviderContractException $exception) {
            return null;
        }
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('staffProfileBump');
        $current = $this->lockAndReadVersionWithDataScope($scope, $kind, $resourceId, $dataScope);
        if ($current === null || $current <= 0) {
            throw self::failure('staff_profile_not_found');
        }
        $staffId = $this->positiveId($resourceId, 'staff_profile_identity_invalid');
        $affected = Db::name(self::TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('staff_id', $staffId)
            ->where('current_version', $current)
            ->update([
                'current_version' => Db::raw('current_version + 1'),
                'last_action' => substr($action, 0, 64),
                'updated_at' => time(),
            ]);
        if ((int)$affected !== 1) {
            throw self::failure('staff_profile_version_conflict');
        }
        return $current + 1;
    }

    public function lockProfileSnapshotInTx(
        int $staffId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $personnelSource = 'store'
    ): array {
        CashierV3TransactionGuard::assertInTransaction('staffProfileSnapshot');
        CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
        $profile = $personnelSource === 'other' && CashierV3PersonnelIdentity::isOrganizationStaffId($staffId)
            ? $this->lockOrganizationProfile($staffId, $dataScope)
            : $this->lockProfile($staffId, $dataScope);
        if ((int)$profile['storeId'] !== $operatorScope->storeId()) {
            throw self::failure('staff_profile_store_mismatch');
        }
        return $profile;
    }

    private function lockProfile(int $staffId, CashierV3DataScopeContext $dataScope): array
    {
        if (empty($this->readinessStatus()['ready']) || !$this->typeAuthority) {
            throw self::failure('staff_profile_provider_not_ready');
        }
        $staff = Db::name('system_store_staff')->where('id', $staffId)->lock(true)->find();
        if (!$staff) {
            throw self::failure('staff_profile_not_found');
        }
        if (!$this->activeStaff($staff)) {
            throw self::failure('staff_profile_inactive');
        }
        $employeeId = (int)$staff['employee_id'];
        $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
        if (!$employee) {
            throw self::failure('staff_employee_binding_missing');
        }
        if (!$this->activeEmployee($employee)) {
            throw self::failure('staff_employee_inactive');
        }
        $storeId = (int)$staff['store_id'];
        CashierV3EntitlementProviderDataScope::assertTenant($dataScope->tenantId(), $dataScope);
        CashierV3EntitlementProviderDataScope::assertStore($storeId, $dataScope, $employeeId);

        $type = $this->typeAuthority->lockTypeSnapshotInTx($staff, $employee, $dataScope);
        $keys = is_array($type) ? array_keys($type) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['employeeTypeAuthorityVersion', 'employeeTypeCodeSnapshot']) {
            throw self::failure('staff_type_snapshot_shape_invalid');
        }
        $typeCode = (string)$type['employeeTypeCodeSnapshot'];
        $typeVersion = is_int($type['employeeTypeAuthorityVersion'])
            ? $type['employeeTypeAuthorityVersion']
            : 0;
        if (!in_array($typeCode, CashierV3EntitlementProviderContracts::staffTypes(), true)
            || $typeVersion <= 0) {
            throw self::failure('staff_type_snapshot_invalid');
        }

        $staffName = trim((string)($employee['name'] ?? ''));
        if ($staffName === '') {
            $staffName = trim((string)($staff['staff_name'] ?? ''));
        }
        if ($staffName === '' || mb_strlen($staffName) > 128) {
            throw self::failure('staff_profile_name_invalid');
        }
        $fingerprint = hash('sha256', json_encode([
            'staffId' => $staffId,
            'employeeId' => $employeeId,
            'storeId' => $storeId,
            'staffName' => $staffName,
            'staffStatus' => (int)$staff['status'],
            'staffIsDel' => (int)$staff['is_del'],
            'craftsmanEligible' => (int)($staff['cashier_craftsman_enabled'] ?? 0),
            'employeeStatus' => (int)$employee['status'],
            'employeeIsDel' => (int)$employee['is_del'],
            'employeeTypeCode' => $typeCode,
            'employeeTypeAuthorityVersion' => $typeVersion,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $version = $this->synchronizeVersion(
            $dataScope->tenantId(),
            $staffId,
            $employeeId,
            $storeId,
            $fingerprint
        );

        return [
            'contractVersion' => $this->contractVersion(),
            'staffId' => $staffId,
            'employeeId' => $employeeId,
            'staffVersion' => $version,
            'staffName' => $staffName,
            'storeId' => $storeId,
            'active' => true,
            'craftsmanEligible' => (int)($staff['cashier_craftsman_enabled'] ?? 0) === 1,
            'employeeTypeCodeSnapshot' => $typeCode,
            'employeeTypeAuthorityVersion' => $typeVersion,
            'profileFingerprint' => $fingerprint,
        ];
    }

    private function lockOrganizationProfile(int $staffId, CashierV3DataScopeContext $dataScope): array
    {
        $employeeId = CashierV3PersonnelIdentity::employeeIdFromStaffId($staffId);
        if ($employeeId <= 0 || empty($this->readinessStatus()['ready'])) {
            throw self::failure('organization_staff_profile_not_ready');
        }
        $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
        if (!$employee || !$this->activeEmployee($employee)) {
            throw self::failure('organization_staff_employee_inactive');
        }
        $typeCode = (string)($employee['employment_type_code'] ?? 'internal');
        $typeVersion = (int)($employee['employment_type_version'] ?? 1);
        if (!in_array($typeCode, CashierV3EntitlementProviderContracts::staffTypes(), true) || $typeVersion <= 0) {
            throw self::failure('organization_staff_type_unclassified');
        }
        $storeId = $dataScope->forcedStoreId();
        $staffName = trim((string)($employee['name'] ?? ''));
        if ($staffName === '' || mb_strlen($staffName) > 128) throw self::failure('organization_staff_profile_name_invalid');
        $fingerprint = hash('sha256', json_encode([
            'staffId' => $staffId, 'employeeId' => $employeeId, 'storeId' => $storeId,
            'staffName' => $staffName, 'employeeStatus' => (int)$employee['status'],
            'employeeIsDel' => (int)$employee['is_del'], 'personnelSource' => 'other',
            'employeeTypeCode' => $typeCode, 'employeeTypeAuthorityVersion' => $typeVersion,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $version = $this->synchronizeVersion($dataScope->tenantId(), $staffId, $employeeId, $storeId, $fingerprint);
        return [
            'contractVersion' => $this->contractVersion(), 'staffId' => $staffId,
            'employeeId' => $employeeId, 'staffVersion' => $version, 'staffName' => $staffName,
            'storeId' => $storeId, 'active' => true, 'craftsmanEligible' => true,
            'employeeTypeCodeSnapshot' => $typeCode, 'employeeTypeAuthorityVersion' => $typeVersion,
            'profileFingerprint' => $fingerprint, 'personnelSource' => 'other',
        ];
    }

    private function synchronizeVersion(
        string $tenantId,
        int $staffId,
        int $employeeId,
        int $storeId,
        string $fingerprint
    ): int {
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->lock(true)
            ->find();
        $now = time();
        if (!$row) {
            Db::name(self::TABLE)->insert([
                'tenant_id' => $tenantId,
                'staff_id' => $staffId,
                'employee_id_snapshot' => $employeeId,
                'store_id_snapshot' => $storeId,
                'profile_fingerprint' => $fingerprint,
                'current_version' => 1,
                'last_action' => 'profile_discovered',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return 1;
        }
        $version = (int)($row['current_version'] ?? 0);
        if ($version <= 0 || $version >= PHP_INT_MAX) {
            throw self::failure('staff_profile_version_invalid');
        }
        if (!hash_equals((string)$row['profile_fingerprint'], $fingerprint)) {
            $affected = Db::name(self::TABLE)
                ->where('id', (int)$row['id'])
                ->where('current_version', $version)
                ->update([
                    'employee_id_snapshot' => $employeeId,
                    'store_id_snapshot' => $storeId,
                    'profile_fingerprint' => $fingerprint,
                    'current_version' => Db::raw('current_version + 1'),
                    'last_action' => 'authority_snapshot_changed',
                    'updated_at' => $now,
                ]);
            if ((int)$affected !== 1) {
                throw self::failure('staff_profile_version_conflict');
            }
            return $version + 1;
        }
        return $version;
    }

    private function activeStaff(array $staff): bool
    {
        return (int)($staff['id'] ?? 0) > 0
            && (int)($staff['employee_id'] ?? 0) > 0
            && (int)($staff['store_id'] ?? 0) > 0
            && (int)($staff['status'] ?? 0) === 1
            && (int)($staff['is_del'] ?? 1) === 0;
    }

    private function activeEmployee(array $employee): bool
    {
        return (int)($employee['id'] ?? 0) > 0
            && (int)($employee['status'] ?? 0) === 1
            && (int)($employee['is_del'] ?? 1) === 0;
    }

    private function positiveId(string $resourceId, string $reason): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $resourceId) !== 1) {
            throw self::failure($reason);
        }
        $id = (int)$resourceId;
        if ($id <= 0 || (string)$id !== $resourceId) {
            throw self::failure($reason);
        }
        return $id;
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== self::KIND) {
            throw self::failure('staff_profile_kind_invalid');
        }
    }

    private static function failure(string $reason, array $detail = []): CashierV3EntitlementProviderContractException
    {
        return new CashierV3EntitlementProviderContractException($reason, $detail);
    }
}
