<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * The checkout request row is the authoritative version source. The checkout
 * aggregate writer owns its CAS. Gateway's bump phase observes that domain
 * CAS and verifies it advanced exactly one version; it never increments the
 * same request a second time.
 */
final class CashierV3CheckoutRequestVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const KIND = 'checkout_request';
    public const TABLE = 'cashier_v3_checkout_request';

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $this->assertKind($kind);
        if (!$this->validRequestId($resourceId)
            || !$this->validBaseScope($operatorScope, $dataScope)) {
            return null;
        }
        $row = Db::name(self::TABLE)
            ->where('request_id', $resourceId)
            ->field('tenant_id,store_id,request_version')
            ->find();
        if (!$row || !$this->rowVisible(
            $row,
            $operatorScope->tenantId(),
            $operatorScope->storeId(),
            $dataScope
        )) {
            return null;
        }
        if ((int)($row['request_version'] ?? 0) <= 0) {
            throw $this->invalidStoredVersion($resourceId);
        }
        return CashierV3ResourceScope::of(
            CashierV3ResourceScope::TYPE_STORE,
            (string)(int)$row['store_id']
        );
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('checkoutRequestVersionLock');
        $this->assertKind($kind);
        if (!$this->validRequestId($resourceId)
            || !$this->validLockedScope($scope, $dataScope)) {
            return null;
        }
        $row = Db::name(self::TABLE)
            ->where('request_id', $resourceId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->field('tenant_id,store_id,request_version')
            ->lock(true)
            ->find();
        if (!$row || !$this->rowVisible(
            $row,
            $dataScope->tenantId(),
            $dataScope->forcedStoreId(),
            $dataScope
        )) {
            return null;
        }
        $version = (int)($row['request_version'] ?? 0);
        if ($version <= 0) {
            throw $this->invalidStoredVersion($resourceId);
        }
        return $version;
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('checkoutRequestVersionBump');
        $current = $this->lockAndReadVersionWithDataScope(
            $scope,
            $kind,
            $resourceId,
            $dataScope
        );
        if ($current === null) {
            throw CashierV3ScopeResolver::notFound($kind, $resourceId);
        }
        $action = trim($action);
        if ($action === ''
            || strlen($action) > 64
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $action) !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '结账请求版本推进参数异常，本次操作已回滚。',
                CashierV3ResultCode::STATUS_FAILED,
                ['kind' => $kind, 'id' => $resourceId, 'reason' => 'checkout_version_bump_invalid']
            );
        }
        return $current;
    }

    private function validBaseScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): bool {
        return $operatorScope->storeId() === $dataScope->forcedStoreId()
            && $operatorScope->operatorId() === $dataScope->operatorId()
            && $operatorScope->tenantId() !== ''
            && hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            && hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            && $dataScope->allowsStore($operatorScope->storeId());
    }

    private function validLockedScope(
        CashierV3ResourceScope $scope,
        CashierV3DataScopeContext $dataScope
    ): bool {
        return $scope->type() === CashierV3ResourceScope::TYPE_STORE
            && ctype_digit($scope->id())
            && (int)$scope->id() === $dataScope->forcedStoreId()
            && $dataScope->tenantId() !== ''
            && $dataScope->allowsStore($dataScope->forcedStoreId());
    }

    private function rowVisible(
        array $row,
        string $tenantId,
        int $storeId,
        CashierV3DataScopeContext $dataScope
    ): bool {
        return $tenantId !== ''
            && hash_equals((string)($row['tenant_id'] ?? ''), $tenantId)
            && (int)($row['store_id'] ?? 0) === $storeId
            && $dataScope->forcedStoreId() === $storeId
            && $dataScope->allowsStore($storeId);
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== self::KIND) {
            throw CashierV3CommandException::invalidContext(
                '本次操作携带了错误的结账请求对象类型，请刷新后重试。',
                ['kind' => $kind, 'reason' => 'checkout_request_kind_invalid']
            );
        }
    }

    private function validRequestId(string $requestId): bool
    {
        return preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) === 1;
    }

    private function invalidStoredVersion(string $resourceId): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '结账请求版本异常，请联系管理员核对。',
            CashierV3ResultCode::STATUS_FAILED,
            ['kind' => self::KIND, 'id' => $resourceId, 'reason' => 'non_positive_version']
        );
    }
}
