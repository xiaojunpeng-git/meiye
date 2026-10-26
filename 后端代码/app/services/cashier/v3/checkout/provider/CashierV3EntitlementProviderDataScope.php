<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

final class CashierV3EntitlementProviderDataScope
{
    public static function assertBase(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->tenantId() === ''
            || $operatorScope->tenantId() !== $dataScope->tenantId()
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            ) {
            throw self::failure('provider_data_scope_session_mismatch');
        }
    }

    public static function assertStore(
        int $storeId,
        CashierV3DataScopeContext $dataScope,
        int $participantEmployeeId = 0
    ): void {
        if ($storeId <= 0 || $storeId !== $dataScope->forcedStoreId()) {
            throw self::failure('provider_data_scope_store_mismatch', ['storeId' => $storeId]);
        }
        if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT) {
            if ($participantEmployeeId <= 0 || $participantEmployeeId !== $dataScope->employeeId()) {
                throw self::failure('provider_data_scope_self_participant_denied');
            }
            return;
        }
        if (!$dataScope->allowsStore($storeId)) {
            throw self::failure('provider_data_scope_store_denied', ['storeId' => $storeId]);
        }
    }

    public static function assertTenant(string $tenantId, CashierV3DataScopeContext $dataScope): void
    {
        if ($tenantId === '' || $tenantId !== $dataScope->tenantId()) {
            throw self::failure('provider_data_scope_tenant_denied');
        }
    }

    private static function failure(string $reason, array $detail = []): CashierV3EntitlementProviderContractException
    {
        return new CashierV3EntitlementProviderContractException($reason, $detail);
    }
}
