<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3TransactionGuard;

/**
 * C2 员工类型只读适配器。仅消费 employee 权威字段，不读取旧任职分成标记。
 */
final class CashierV3EmployeeTypeAuthority implements CashierV3StaffTypeAuthority
{
    public function contractVersion(): string
    {
        return CashierV3EntitlementProviderContracts::STAFF_TYPE_AUTHORITY;
    }

    public function readinessStatus(): array
    {
        $schema = CashierV3EntitlementProviderSchemaProbe::tablesStatus([
            'eb_employee' => [
                'id', 'status', 'is_del', 'employment_type_code', 'employment_type_version',
            ],
            'eb_system_store_staff' => [
                'id', 'employee_id', 'store_id', 'status', 'is_del',
            ],
        ]);
        return [
            'ready' => (bool)$schema['ready'],
            'reasons' => $schema['ready'] ? [] : ['employee_type_authority_schema_not_ready'],
            'schema' => $schema,
        ];
    }

    public function lockTypeSnapshotInTx(
        array $staff,
        array $employee,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('employeeTypeAuthoritySnapshot');
        if (empty($this->readinessStatus()['ready'])) {
            throw $this->failure('staff_type_authority_not_ready');
        }

        $staffId = (int)($staff['id'] ?? 0);
        $employeeId = (int)($employee['id'] ?? 0);
        $storeId = (int)($staff['store_id'] ?? 0);
        if ($staffId <= 0
            || $employeeId <= 0
            || (int)($staff['employee_id'] ?? 0) !== $employeeId
        ) {
            throw $this->failure('staff_type_employee_binding_invalid');
        }
        if ((int)($staff['status'] ?? 0) !== 1
            || (int)($staff['is_del'] ?? 1) !== 0
            || (int)($employee['status'] ?? 0) !== 1
            || (int)($employee['is_del'] ?? 1) !== 0
        ) {
            throw $this->failure('staff_type_employee_inactive');
        }

        CashierV3EntitlementProviderDataScope::assertTenant($dataScope->tenantId(), $dataScope);
        CashierV3EntitlementProviderDataScope::assertStore($storeId, $dataScope, $employeeId);

        $typeCode = $employee['employment_type_code'] ?? null;
        $typeVersion = (int)($employee['employment_type_version'] ?? 0);
        if (!is_string($typeCode)
            || !in_array($typeCode, CashierV3EntitlementProviderContracts::staffTypes(), true)
            || $typeVersion <= 0
        ) {
            throw $this->failure('staff_type_unclassified');
        }

        return [
            'employeeTypeCodeSnapshot' => $typeCode,
            'employeeTypeAuthorityVersion' => $typeVersion,
        ];
    }

    private function failure(string $reason): CashierV3EntitlementProviderContractException
    {
        return new CashierV3EntitlementProviderContractException($reason);
    }
}
