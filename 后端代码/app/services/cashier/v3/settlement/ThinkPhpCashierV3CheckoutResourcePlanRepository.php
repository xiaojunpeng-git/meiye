<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Immutable checkout resource-plan persistence inside the caller transaction. */
final class ThinkPhpCashierV3CheckoutResourcePlanRepository implements CashierV3CheckoutResourcePlanRepository
{
    public const HEADER_TABLE = 'cashier_v3_checkout_resource_plan';
    public const ROW_TABLE = 'cashier_v3_checkout_resource_plan_row';
    public const REQUEST_TABLE = 'cashier_v3_checkout_request';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INVALIDATED = 'invalidated';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_CONSUMED = 'consumed';

    public function persistInTx(
        string $requestId,
        CashierV3CheckoutVerifiedResourcePlan $plan,
        int $preparedAt
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutResourcePlanPersistence');
        $requestId = $this->requestId($requestId);
        if ($preparedAt <= 0) {
            throw self::failure('checkout_resource_plan_prepared_at_invalid');
        }
        $request = $this->lockRequest(
            $requestId,
            $plan->tenantId(),
            $plan->storeId()
        );
        if (!$request
            || (int)($request['request_version'] ?? 0) !== $plan->boundRequestVersion()
            || (string)($request['request_status'] ?? '') !== 'ready_for_submit') {
            throw self::failure('checkout_resource_plan_request_not_ready', [
                'requestId' => $requestId,
                'boundRequestVersion' => $plan->boundRequestVersion(),
            ]);
        }

        $existing = $this->lockHeader($requestId, $plan->boundRequestVersion());
        if ($existing) {
            return $this->assertReplay($existing, $plan);
        }

        Db::name(self::HEADER_TABLE)
            ->where('request_id', $requestId)
            ->where('plan_status', self::STATUS_ACTIVE)
            ->where('bound_request_version', '<', $plan->boundRequestVersion())
            ->update([
                'plan_status' => self::STATUS_SUPERSEDED,
                'invalidation_reason' => 'request_version_advanced',
                'updated_at' => $preparedAt,
            ]);

        try {
            $planId = (int)Db::name(self::HEADER_TABLE)->insertGetId([
                'request_id' => $requestId,
                'tenant_id' => $plan->tenantId(),
                'store_id' => $plan->storeId(),
                'bound_request_version' => $plan->boundRequestVersion(),
                'contract_version' => CashierV3CheckoutVerifiedResourcePlan::CONTRACT_VERSION,
                'plan_status' => self::STATUS_ACTIVE,
                'resource_count' => $plan->resourceCount(),
                'role_count' => $plan->roleCount(),
                'resource_plan_fingerprint' => $plan->fingerprint(),
                'invalidation_reason' => '',
                'prepared_at' => $preparedAt,
                'created_at' => $preparedAt,
                'updated_at' => $preparedAt,
            ]);
        } catch (\Throwable $exception) {
            if (!$this->duplicateKey($exception)) {
                throw $exception;
            }
            $existing = $this->lockHeader($requestId, $plan->boundRequestVersion());
            if (!$existing) {
                throw $exception;
            }
            return $this->assertReplay($existing, $plan);
        }
        if ($planId <= 0) {
            throw self::failure('checkout_resource_plan_header_insert_failed');
        }

        $rows = [];
        foreach ($plan->resources() as $resource) {
            $rows[] = [
                'plan_id' => $planId,
                'request_id' => $requestId,
                'tenant_id' => $plan->tenantId(),
                'store_id' => $plan->storeId(),
                'bound_request_version' => $plan->boundRequestVersion(),
                'resource_kind' => $resource['kind'],
                'resource_id' => $resource['id'],
                'scope_type' => $resource['scopeType'],
                'scope_id' => $resource['scopeId'],
                'lock_order' => $resource['lockOrder'],
                'expected_version' => $resource['expectedVersion'],
                'roles_json' => $this->encode($resource['roles']),
                'role_count' => count($resource['roles']),
                'access_mode' => $resource['accessMode'],
                'provider_contract_version' => $resource['providerContractVersion'],
                'authority_fingerprint' => $resource['authorityFingerprint'],
                'row_fingerprint' => $resource['rowFingerprint'],
                'created_at' => $preparedAt,
            ];
        }
        $inserted = (int)Db::name(self::ROW_TABLE)->insertAll($rows);
        if ($inserted !== $plan->resourceCount()) {
            throw self::failure('checkout_resource_plan_rows_insert_incomplete', [
                'expected' => $plan->resourceCount(),
                'actual' => $inserted,
            ]);
        }
        return $this->assertReplay(
            $this->lockHeader($requestId, $plan->boundRequestVersion()),
            $plan,
            false
        );
    }

    public function invalidateInTx(
        string $requestId,
        string $tenantId,
        int $storeId,
        int $boundRequestVersion,
        string $reason
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutResourcePlanInvalidation');
        $requestId = $this->requestId($requestId);
        $tenantId = $this->tenantId($tenantId);
        $reason = $this->reason($reason);
        if ($storeId <= 0 || $boundRequestVersion <= 0) {
            throw self::failure('checkout_resource_plan_invalidation_scope_invalid');
        }
        $request = $this->lockRequest($requestId, $tenantId, $storeId);
        $this->assertRequestVersion(
            $request,
            $tenantId,
            $storeId,
            $boundRequestVersion
        );
        $header = $this->lockHeader($requestId, $boundRequestVersion);
        if (!$header) {
            return ['affected' => 0, 'status' => 'missing'];
        }
        $this->assertHeaderScope($header, $tenantId, $storeId, $boundRequestVersion);
        if ((string)$header['plan_status'] === self::STATUS_INVALIDATED) {
            return ['affected' => 0, 'status' => self::STATUS_INVALIDATED];
        }
        if ((string)$header['plan_status'] !== self::STATUS_ACTIVE) {
            throw self::failure('checkout_resource_plan_not_active');
        }
        $affected = (int)Db::name(self::HEADER_TABLE)
            ->where('id', (int)$header['id'])
            ->where('plan_status', self::STATUS_ACTIVE)
            ->update([
                'plan_status' => self::STATUS_INVALIDATED,
                'invalidation_reason' => $reason,
                'updated_at' => time(),
            ]);
        if ($affected !== 1) {
            throw self::failure('checkout_resource_plan_invalidation_conflict');
        }
        return ['affected' => 1, 'status' => self::STATUS_INVALIDATED];
    }

    public function markConsumedInTx(
        string $requestId,
        string $tenantId,
        int $storeId,
        int $boundRequestVersion,
        string $expectedFingerprint
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutResourcePlanConsumption');
        $requestId = $this->requestId($requestId);
        $tenantId = $this->tenantId($tenantId);
        $expectedFingerprint = $this->fingerprint($expectedFingerprint);
        if ($storeId <= 0 || $boundRequestVersion <= 0) {
            throw self::failure('checkout_resource_plan_consumption_scope_invalid');
        }
        $request = $this->lockRequest($requestId, $tenantId, $storeId);
        $this->assertRequestVersion(
            $request,
            $tenantId,
            $storeId,
            $boundRequestVersion
        );
        $header = $this->lockHeader($requestId, $boundRequestVersion);
        if (!$header) {
            throw self::failure('checkout_resource_plan_not_found');
        }
        $this->assertHeaderScope($header, $tenantId, $storeId, $boundRequestVersion);
        if (!hash_equals((string)$header['resource_plan_fingerprint'], $expectedFingerprint)) {
            throw self::failure('checkout_resource_plan_fingerprint_conflict');
        }
        if ((string)$header['plan_status'] === self::STATUS_CONSUMED) {
            return ['affected' => 0, 'status' => self::STATUS_CONSUMED];
        }
        if ((string)$header['plan_status'] !== self::STATUS_ACTIVE) {
            throw self::failure('checkout_resource_plan_not_active');
        }
        $affected = (int)Db::name(self::HEADER_TABLE)
            ->where('id', (int)$header['id'])
            ->where('plan_status', self::STATUS_ACTIVE)
            ->update([
                'plan_status' => self::STATUS_CONSUMED,
                'updated_at' => time(),
            ]);
        if ($affected !== 1) {
            throw self::failure('checkout_resource_plan_consumption_conflict');
        }
        return ['affected' => 1, 'status' => self::STATUS_CONSUMED];
    }

    public function supersedeActiveBeforeVersionInTx(
        string $requestId,
        string $tenantId,
        int $storeId,
        int $nextVersion,
        string $reason
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutResourcePlanSupersession');
        $requestId = $this->requestId($requestId);
        $tenantId = $this->tenantId($tenantId);
        $reason = $this->reason($reason);
        if ($storeId <= 0 || $nextVersion <= 0) {
            throw self::failure('checkout_resource_plan_supersession_scope_invalid');
        }

        $request = $this->lockRequest($requestId, $tenantId, $storeId);
        $this->assertRequestVersion($request, $tenantId, $storeId, $nextVersion);
        $headers = $this->lockActiveHeadersBeforeVersion($requestId, $nextVersion);
        foreach ($headers as $header) {
            $headerVersion = (int)($header['bound_request_version'] ?? 0);
            if ($headerVersion <= 0 || $headerVersion >= $nextVersion) {
                throw self::failure('checkout_resource_plan_supersession_header_invalid');
            }
            $this->assertHeaderScope($header, $tenantId, $storeId, $headerVersion);
        }
        if (!$headers) {
            return [
                'affected' => 0,
                'status' => self::STATUS_SUPERSEDED,
                'nextVersion' => $nextVersion,
            ];
        }

        $affected = (int)Db::name(self::HEADER_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('bound_request_version', '<', $nextVersion)
            ->where('plan_status', self::STATUS_ACTIVE)
            ->update([
                'plan_status' => self::STATUS_SUPERSEDED,
                'invalidation_reason' => $reason,
                'updated_at' => time(),
            ]);
        if ($affected !== count($headers)) {
            throw self::failure('checkout_resource_plan_supersession_conflict', [
                'expected' => count($headers),
                'actual' => $affected,
            ]);
        }
        return [
            'affected' => $affected,
            'status' => self::STATUS_SUPERSEDED,
            'nextVersion' => $nextVersion,
        ];
    }

    private function assertReplay(
        array $header,
        CashierV3CheckoutVerifiedResourcePlan $plan,
        bool $replayed = true
    ): array {
        if (!$header) {
            throw self::failure('checkout_resource_plan_header_missing');
        }
        $this->assertHeaderScope(
            $header,
            $plan->tenantId(),
            $plan->storeId(),
            $plan->boundRequestVersion()
        );
        if ((string)$header['contract_version'] !== CashierV3CheckoutVerifiedResourcePlan::CONTRACT_VERSION
            || (string)$header['plan_status'] !== self::STATUS_ACTIVE
            || (int)$header['resource_count'] !== $plan->resourceCount()
            || (int)$header['role_count'] !== $plan->roleCount()
            || !hash_equals((string)$header['resource_plan_fingerprint'], $plan->fingerprint())) {
            throw self::failure('checkout_resource_plan_idempotency_conflict');
        }
        $rows = $this->rowsForHeader((int)$header['id']);
        $rehydrated = CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            $plan->tenantId(),
            $plan->storeId(),
            $plan->boundRequestVersion(),
            $this->authorityRows($rows),
            $plan->resourceCount() === 0
        );
        if (!hash_equals($rehydrated->fingerprint(), $plan->fingerprint())) {
            throw self::failure('checkout_resource_plan_rows_drift');
        }
        foreach ($rows as $index => $row) {
            if (!hash_equals(
                (string)($row['row_fingerprint'] ?? ''),
                (string)$rehydrated->resources()[$index]['rowFingerprint']
            )) {
                throw self::failure('checkout_resource_plan_row_fingerprint_drift');
            }
        }
        return [
            'planId' => (int)$header['id'],
            'requestId' => (string)$header['request_id'],
            'boundRequestVersion' => (int)$header['bound_request_version'],
            'resourcePlanFingerprint' => (string)$header['resource_plan_fingerprint'],
            'resourceCount' => (int)$header['resource_count'],
            'roleCount' => (int)$header['role_count'],
            'replayed' => $replayed,
        ];
    }

    private function authorityRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $roles = json_decode((string)($row['roles_json'] ?? ''), true);
            if (!is_array($roles) || count($roles) !== (int)($row['role_count'] ?? -1)) {
                throw self::failure('checkout_resource_plan_roles_storage_invalid');
            }
            $result[] = [
                'tenantId' => (string)$row['tenant_id'],
                'storeId' => (int)$row['store_id'],
                'kind' => (string)$row['resource_kind'],
                'id' => (string)$row['resource_id'],
                'scopeType' => (string)$row['scope_type'],
                'scopeId' => (string)$row['scope_id'],
                'lockOrder' => (int)$row['lock_order'],
                'expectedVersion' => (int)$row['expected_version'],
                'roles' => $roles,
                'accessMode' => (string)$row['access_mode'],
                'providerContractVersion' => (string)$row['provider_contract_version'],
                'authorityFingerprint' => (string)$row['authority_fingerprint'],
            ];
        }
        return $result;
    }

    private function rowsForHeader(int $planId): array
    {
        $rows = Db::name(self::ROW_TABLE)
            ->where('plan_id', $planId)
            ->order('lock_order asc,resource_kind asc,resource_id asc')
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $rows = is_array($rows) ? array_values($rows) : [];
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

    private function lockRequest(string $requestId, string $tenantId, int $storeId): array
    {
        $row = Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->lock(true)
            ->find();
        return is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : []);
    }

    private function lockHeader(string $requestId, int $boundRequestVersion): array
    {
        $row = Db::name(self::HEADER_TABLE)
            ->where('request_id', $requestId)
            ->where('bound_request_version', $boundRequestVersion)
            ->lock(true)
            ->find();
        return is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : []);
    }

    /** @return array<int,array> */
    private function lockActiveHeadersBeforeVersion(string $requestId, int $nextVersion): array
    {
        $rows = Db::name(self::HEADER_TABLE)
            ->where('request_id', $requestId)
            ->where('bound_request_version', '<', $nextVersion)
            ->where('plan_status', self::STATUS_ACTIVE)
            ->order('bound_request_version asc,id asc')
            ->lock(true)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    private function assertRequestVersion(
        array $request,
        string $tenantId,
        int $storeId,
        int $expectedVersion
    ): void {
        if (!$request) {
            throw self::failure('checkout_resource_plan_request_not_found');
        }
        if ((string)($request['tenant_id'] ?? '') !== $tenantId
            || (int)($request['store_id'] ?? 0) !== $storeId) {
            throw self::failure('checkout_resource_plan_request_scope_mismatch');
        }
        if ((int)($request['request_version'] ?? 0) !== $expectedVersion) {
            throw self::failure('checkout_resource_plan_request_version_conflict', [
                'expectedVersion' => $expectedVersion,
                'actualVersion' => (int)($request['request_version'] ?? 0),
            ]);
        }
    }

    private function assertHeaderScope(array $header, string $tenantId, int $storeId, int $version): void
    {
        if ((string)($header['tenant_id'] ?? '') !== $tenantId
            || (int)($header['store_id'] ?? 0) !== $storeId
            || (int)($header['bound_request_version'] ?? 0) !== $version) {
            throw self::failure('checkout_resource_plan_header_scope_mismatch');
        }
    }

    private function requestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1) {
            throw self::failure('checkout_resource_plan_request_id_invalid');
        }
        return $requestId;
    }

    private function tenantId(string $tenantId): string
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '' || strlen($tenantId) > 32
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $tenantId) !== 1) {
            throw self::failure('checkout_resource_plan_tenant_invalid');
        }
        return $tenantId;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 64
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $reason) !== 1) {
            throw self::failure('checkout_resource_plan_invalidation_reason_invalid');
        }
        return $reason;
    }

    private function fingerprint(string $value): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw self::failure('checkout_resource_plan_fingerprint_invalid');
        }
        return $value;
    }

    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('checkout_resource_plan_json_encode_failed');
        }
        return $json;
    }

    private function duplicateKey(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return strpos($message, 'duplicate') !== false
            || strpos($message, '1062') !== false
            || (string)$exception->getCode() === '23000';
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
