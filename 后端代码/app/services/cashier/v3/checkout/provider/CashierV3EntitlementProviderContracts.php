<?php

namespace app\services\cashier\v3\checkout\provider;

final class CashierV3EntitlementProviderContracts
{
    public const DEBT_GUARD = 'c2-entitlement-debt-guard-provider-v1';
    public const DEBT_GUARD_WRITER = 'c2-entitlement-debt-guard-writer-v1';
    public const STAFF_PROFILE = 'c2-entitlement-staff-profile-provider-v1';
    public const STAFF_TYPE_AUTHORITY = 'cashier-staff-type-authority-v1';
    public const OCCUPATION = 'c2-entitlement-occupation-provider-v1';
    public const RESERVATION_OCCUPATION = 'legacy-store-reservation-occupation-v1';
    public const SERVICE_ORDER_OCCUPATION = 'c3-service-order-occupation-v1';
    public const INVENTORY_COMPLETION = 'inventory-entitlement-completion-provider-v1';
    public const INVENTORY_SHORTAGE_CURSOR_GATE = 'explicit_resource_plan_locked_v1';
    public const ACTIVATION_READINESS = 'c2-entitlement-activation-readiness-v1';

    public const STAFF_TYPE_INTERNAL = 'internal';
    public const STAFF_TYPE_PARTNER = 'partner';
    public const STAFF_TYPE_OUTSOURCED = 'outsourced';

    public static function staffTypes(): array
    {
        return [
            self::STAFF_TYPE_INTERNAL,
            self::STAFF_TYPE_PARTNER,
            self::STAFF_TYPE_OUTSOURCED,
        ];
    }
}
