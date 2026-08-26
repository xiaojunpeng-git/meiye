<?php

namespace app\services\cashier\v3;

/**
 * Stable identity for an organisation-only employee used as a cashier
 * craftsman. It is deliberately outside the store-staff id range so an
 * employee id can never be mistaken for another store's staff row.
 */
final class CashierV3PersonnelIdentity
{
    private const ORGANIZATION_OFFSET = 1000000000;

    public static function organizationStaffId(int $employeeId): int
    {
        if ($employeeId <= 0 || $employeeId > 1000000000) {
            throw new \InvalidArgumentException('organization_employee_identity_invalid');
        }
        return self::ORGANIZATION_OFFSET + $employeeId;
    }

    public static function isOrganizationStaffId(int $staffId): bool
    {
        return $staffId > self::ORGANIZATION_OFFSET;
    }

    public static function employeeIdFromStaffId(int $staffId): int
    {
        return self::isOrganizationStaffId($staffId) ? $staffId - self::ORGANIZATION_OFFSET : 0;
    }
}
