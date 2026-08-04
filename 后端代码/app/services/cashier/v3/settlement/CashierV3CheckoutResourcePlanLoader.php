<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use think\facade\Db;

/** Read-only loader for the active resource plan bound to the current request version. */
final class CashierV3CheckoutResourcePlanLoader
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-resource-plan-loader-v1';

    public function __invoke(string $requestId): array
    {
        return $this->load($requestId);
    }

    public function load(string $requestId): array
    {
        $requestId = trim($requestId);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1) {
            return [];
        }
        $request = $this->row(Db::name(ThinkPhpCashierV3CheckoutResourcePlanRepository::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->field('request_id,tenant_id,store_id,request_version,request_status')
            ->find());
        if (!$request) {
            return [];
        }
        $tenantId = (string)($request['tenant_id'] ?? '');
        $storeId = (int)($request['store_id'] ?? 0);
        $requestVersion = (int)($request['request_version'] ?? 0);
        $requestStatus = (string)($request['request_status'] ?? '');
        if ($tenantId === '' || $storeId <= 0 || $requestVersion <= 0) {
            throw self::failure('checkout_resource_plan_request_authority_invalid');
        }
        if ($requestStatus !== 'ready_for_submit') {
            return [];
        }

        $headers = $this->rows(Db::name(ThinkPhpCashierV3CheckoutResourcePlanRepository::HEADER_TABLE)
            ->where('request_id', $requestId)
            ->where('bound_request_version', $requestVersion)
            ->where('plan_status', ThinkPhpCashierV3CheckoutResourcePlanRepository::STATUS_ACTIVE)
            ->select());
        if (!$headers) {
            return [];
        }
        if (count($headers) !== 1) {
            throw self::failure('checkout_resource_plan_active_header_duplicate');
        }
        $header = $headers[0];
        if ((string)($header['tenant_id'] ?? '') !== $tenantId
            || (int)($header['store_id'] ?? 0) !== $storeId
            || (int)($header['bound_request_version'] ?? 0) !== $requestVersion
            || (string)($header['contract_version'] ?? '') !== CashierV3CheckoutVerifiedResourcePlan::CONTRACT_VERSION) {
            throw self::failure('checkout_resource_plan_header_drift');
        }

        $storedRows = $this->sortResourceRows($this->rows(
            Db::name(ThinkPhpCashierV3CheckoutResourcePlanRepository::ROW_TABLE)
            ->where('plan_id', (int)$header['id'])
            ->order('lock_order asc,resource_kind asc,resource_id asc')
            ->select()
        ));
        if (!$storedRows || count($storedRows) !== (int)($header['resource_count'] ?? -1)) {
            throw self::failure('checkout_resource_plan_row_count_drift');
        }
        $authorityRows = [];
        foreach ($storedRows as $index => $row) {
            if ((string)($row['request_id'] ?? '') !== $requestId
                || (string)($row['tenant_id'] ?? '') !== $tenantId
                || (int)($row['store_id'] ?? 0) !== $storeId
                || (int)($row['bound_request_version'] ?? 0) !== $requestVersion) {
                throw self::failure('checkout_resource_plan_row_scope_drift', ['index' => $index]);
            }
            $roles = json_decode((string)($row['roles_json'] ?? ''), true);
            if (!is_array($roles) || count($roles) !== (int)($row['role_count'] ?? -1)) {
                throw self::failure('checkout_resource_plan_roles_storage_invalid', ['index' => $index]);
            }
            $authorityRows[] = [
                'tenantId' => $tenantId,
                'storeId' => $storeId,
                'kind' => (string)($row['resource_kind'] ?? ''),
                'id' => (string)($row['resource_id'] ?? ''),
                'scopeType' => (string)($row['scope_type'] ?? ''),
                'scopeId' => (string)($row['scope_id'] ?? ''),
                'lockOrder' => (int)($row['lock_order'] ?? 0),
                'expectedVersion' => (int)($row['expected_version'] ?? 0),
                'roles' => $roles,
                'accessMode' => (string)($row['access_mode'] ?? ''),
                'providerContractVersion' => (string)($row['provider_contract_version'] ?? ''),
                'authorityFingerprint' => (string)($row['authority_fingerprint'] ?? ''),
            ];
        }
        $plan = CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            $tenantId,
            $storeId,
            $requestVersion,
            $authorityRows
        );
        if ((int)($header['role_count'] ?? -1) !== $plan->roleCount()
            || !hash_equals((string)($header['resource_plan_fingerprint'] ?? ''), $plan->fingerprint())) {
            throw self::failure('checkout_resource_plan_header_fingerprint_drift');
        }
        $resources = $plan->resources();
        foreach ($storedRows as $index => $row) {
            if (!hash_equals(
                (string)($row['row_fingerprint'] ?? ''),
                (string)$resources[$index]['rowFingerprint']
            )) {
                throw self::failure('checkout_resource_plan_row_fingerprint_drift', ['index' => $index]);
            }
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'planContractVersion' => CashierV3CheckoutVerifiedResourcePlan::CONTRACT_VERSION,
            'requestId' => $requestId,
            'tenantId' => $tenantId,
            'storeId' => $storeId,
            'requestVersion' => $requestVersion,
            'requestStatus' => $requestStatus,
            'boundRequestVersion' => $plan->boundRequestVersion(),
            'resourcePlanFingerprint' => $plan->fingerprint(),
            'resourceCount' => $plan->resourceCount(),
            'roleCount' => $plan->roleCount(),
            'resources' => $resources,
            'trustedContexts' => $plan->trustedContexts(),
        ];
    }

    private function row($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) && $value ? $value : [];
    }

    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }

    /** @param array<int,array> $rows @return array<int,array> */
    private function sortResourceRows(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResources(
                (string)($left['resource_kind'] ?? ''),
                (string)($left['resource_id'] ?? ''),
                (string)($right['resource_kind'] ?? ''),
                (string)($right['resource_id'] ?? '')
            );
        });
        return $rows;
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
