<?php

namespace app\services\cashier\v3\hang;

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
 * 挂单主表是挂单资源版本的唯一权威来源。
 *
 * 所有读取和推进都强制按当前租户、强制门店和 DataScope 查询；跨店或跨租户
 * 的对象一律按不存在处理，不能以版本差异探测其存在。
 */
final class CashierV3HangOrderVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const CONTRACT_VERSION = 'cashier-v3-hang-order-version-v1';
    public const KIND = 'hang_order';
    public const TABLE = 'cashier_v3_hang_order';

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $this->assertKind($kind);
        if (!$this->validHangOrderId($resourceId)
            || !$this->validBaseScope($operatorScope, $dataScope)) {
            return null;
        }

        $row = Db::name(self::TABLE)
            ->where('hang_order_id', $resourceId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->field('tenant_id,store_id,hang_version')
            ->find();
        if (!$row || !$this->rowVisible($row, $dataScope)) {
            return null;
        }
        $this->assertPositiveVersion($row['hang_version'] ?? null, $resourceId);

        return CashierV3ResourceScope::of(
            CashierV3ResourceScope::TYPE_STORE,
            (string)$dataScope->forcedStoreId()
        );
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('hangOrderVersionLock');
        $this->assertKind($kind);
        if (!$this->validHangOrderId($resourceId)
            || !$this->validLockedScope($scope, $dataScope)) {
            return null;
        }

        $row = Db::name(self::TABLE)
            ->where('hang_order_id', $resourceId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->field('tenant_id,store_id,hang_version')
            ->lock(true)
            ->find();
        if (!$row || !$this->rowVisible($row, $dataScope)) {
            return null;
        }

        return $this->assertPositiveVersion($row['hang_version'] ?? null, $resourceId);
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('hangOrderVersionBump');
        $current = $this->lockAndReadVersionWithDataScope(
            $scope,
            $kind,
            $resourceId,
            $dataScope
        );
        if ($current === null) {
            throw CashierV3ScopeResolver::notFound($kind, $resourceId);
        }
        if ($current >= PHP_INT_MAX) {
            throw $this->invalidStoredVersion($resourceId, 'version_overflow');
        }

        $affected = Db::name(self::TABLE)
            ->where('hang_order_id', $resourceId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('hang_version', $current)
            ->update([
                'hang_version' => Db::raw('hang_version + 1'),
                'update_time' => time(),
            ]);
        if ((int)$affected !== 1) {
            throw CashierV3CommandException::versionConflict(
                '该挂单已被其他操作更新，请刷新后重试。',
                [
                    'kind' => $kind,
                    'id' => $resourceId,
                    'action' => $action,
                    'reason' => 'hang_order_version_cas_conflict',
                ]
            );
        }

        return $current + 1;
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

    private function rowVisible(array $row, CashierV3DataScopeContext $dataScope): bool
    {
        return $dataScope->tenantId() !== ''
            && hash_equals((string)($row['tenant_id'] ?? ''), $dataScope->tenantId())
            && (int)($row['store_id'] ?? 0) === $dataScope->forcedStoreId()
            && $dataScope->allowsStore($dataScope->forcedStoreId());
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== self::KIND) {
            throw CashierV3CommandException::invalidContext(
                '本次操作携带了错误的挂单对象类型，请刷新后重试。',
                ['kind' => $kind, 'reason' => 'hang_order_kind_invalid']
            );
        }
    }

    private function validHangOrderId(string $resourceId): bool
    {
        return preg_match('/^HGO[0-9a-f]{40}$/D', $resourceId) === 1;
    }

    private function assertPositiveVersion($value, string $resourceId): int
    {
        $version = is_int($value) ? $value : (int)$value;
        if ($version <= 0) {
            throw $this->invalidStoredVersion($resourceId, 'non_positive_version');
        }
        return $version;
    }

    private function invalidStoredVersion(
        string $resourceId,
        string $reason
    ): CashierV3CommandException {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '挂单版本异常，请联系管理员核对。',
            CashierV3ResultCode::STATUS_FAILED,
            ['kind' => self::KIND, 'id' => $resourceId, 'reason' => $reason]
        );
    }
}
