<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

use think\facade\Db;

/**
 * 既有资源版本：canonical scope 中央登记表 + 可插拔业务主表提供器。
 *
 * 中央表唯一身份是 scope_type + scope_id + resource_kind + resource_id。
 * 所有读、锁、推进都必须带上同一份 canonical scope，因此：
 * - 会员、余额、次数池、卡权益在 tenant 范围只有一份版本，两家门店同时扣
 *   同一个余额时，第二个必然版本冲突；
 * - 房间、库存、服务单按门店隔离，A 店查 B 店对象只会得到「对象不存在」，
 *   不会通过版本冲突反推对方存在。
 *
 * 版本从 1 起算，与前端 expectedVersion 必须 > 0 的冻结口径一致。
 * 所有读版本都走行级排他锁；MySQL 5.6／5.7 没有 SKIP LOCKED / NOWAIT，
 * 只能依赖固定加锁全序 + innodb_lock_wait_timeout。
 *
 * 本类不使用任何跨请求 static 缓存。
 */
class CashierV3ResourceVersionServices
{
    public const TABLE = 'cashier_v3_resource_version';

    /** @var CashierV3ScopeResolver */
    protected $scopeResolver;

    /** @var array<string,CashierV3DataScopedVersionProvider|CashierV3ResourceVersionProvider> */
    protected $providers = [];

    /** @var bool */
    protected $frozen = false;

    public function __construct(CashierV3ScopeResolver $scopeResolver)
    {
        $this->scopeResolver = $scopeResolver;
    }

    public function freeze(): void
    {
        $this->frozen = true;
        $this->scopeResolver->freeze();
    }

    /**
     * 领域 kind 只接受 DataScoped；中央技术资源可接受无 DataScope 接口。
     * 二者拆分，不用继承关系保留业务旁路。
     *
     * @param CashierV3DataScopedVersionProvider|CashierV3ResourceVersionProvider $provider
     */
    public function registerProvider(string $kind, $provider): void
    {
        if ($this->frozen) {
            throw new \LogicException('resource version providers 已 freeze');
        }
        CashierV3ResourceKindCatalog::assertKnown($kind);
        if (CashierV3ResourceKindCatalog::isDomainOwned($kind)) {
            if (!($provider instanceof CashierV3DataScopedVersionProvider)) {
                throw new \LogicException(sprintf(
                    'domain kind %s 必须注册 CashierV3DataScopedVersionProvider',
                    $kind
                ));
            }
        } elseif (!($provider instanceof CashierV3ResourceVersionProvider)
            && !($provider instanceof CashierV3DataScopedVersionProvider)) {
            throw new \LogicException(sprintf('resource provider %s 类型非法', $kind));
        }
        if (isset($this->providers[$kind])) {
            throw new \LogicException(sprintf('resource provider %s 重复注册', $kind));
        }
        $this->scopeResolver->registerProvider($kind, $provider);
        $this->providers[$kind] = $provider;
    }

    /** @return array<string,CashierV3DataScopedVersionProvider|CashierV3ResourceVersionProvider> */
    public function registeredProviders(): array
    {
        return $this->providers;
    }

    public function scopeResolver(): CashierV3ScopeResolver
    {
        return $this->scopeResolver;
    }

    /**
     * 首次登记资源版本。创建新对象后由领域事务显式调用，必须在同一业务事务内，
     * 且必须携带已锁定 DataScope（领域 kind）与权威 scope；禁止事后无权限补登记。
     */
    public function ensureRegistered(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope = null
    ): int {
        CashierV3TransactionGuard::assertInTransaction('ensureRegistered:' . $kind);
        CashierV3ResourceKindCatalog::assertKnown($kind);

        if (isset($this->providers[$kind])) {
            $provider = $this->providers[$kind];
            if (CashierV3ResourceKindCatalog::isDomainOwned($kind)
                || $provider instanceof CashierV3DataScopedVersionProvider) {
                if (!($provider instanceof CashierV3DataScopedVersionProvider) || $dataScope === null) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                        '该操作缺少数据权限上下文，已拒绝。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['kind' => $kind, 'reason' => 'ensure_registered_requires_data_scope']
                    );
                }
                $existing = $provider->lockAndReadVersionWithDataScope($scope, $kind, $resourceId, $dataScope);
                if ($existing === null) {
                    throw CashierV3ScopeResolver::notFound($kind, $resourceId);
                }
                return (int)$existing;
            }
            $existing = $provider->lockAndReadVersion($scope, $kind, $resourceId);
            if ($existing === null) {
                throw CashierV3ScopeResolver::notFound($kind, $resourceId);
            }
            return (int)$existing;
        }

        $current = $this->lockCentralRow($scope, $kind, $resourceId);
        if ($current !== null) {
            return $current;
        }
        $now = time();
        try {
            Db::name(self::TABLE)->insert([
                'scope_type' => $scope->type(),
                'scope_id' => $scope->id(),
                'resource_kind' => $kind,
                'resource_id' => $resourceId,
                'current_version' => 1,
                'last_action' => '',
                'add_time' => $now,
                'update_time' => $now,
            ]);
            return 1;
        } catch (\Throwable $exception) {
            $current = $this->lockCentralRow($scope, $kind, $resourceId);
            if ($current === null) {
                throw $exception;
            }
            return $current;
        }
    }

    /**
     * 事务内按固定全序逐个锁定并校验 expectedVersion。
     * 若 contexts 携带 data_scope，领域 provider 走 WithDataScope 入口。
     *
     * @param array<int,array{kind:string,id:string,expected_version:int,scope:CashierV3ResourceScope,data_scope?:CashierV3DataScopeContext}> $sortedContexts
     * @return array<string,int>
     */
    public function lockAndAssert(array $sortedContexts): array
    {
        CashierV3TransactionGuard::assertInTransaction('lockAndAssert');

        $locked = [];
        foreach ($sortedContexts as $context) {
            $kind = $context['kind'];
            $resourceId = $context['id'];
            $scope = $this->requireScope($context);
            $dataScope = $context['data_scope'] ?? null;

            if (isset($this->providers[$kind])) {
                $provider = $this->providers[$kind];
                if (CashierV3ResourceKindCatalog::isDomainOwned($kind)
                    || $provider instanceof CashierV3DataScopedVersionProvider) {
                    if (!($provider instanceof CashierV3DataScopedVersionProvider)
                        || !($dataScope instanceof CashierV3DataScopeContext)) {
                        throw new CashierV3CommandException(
                            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                            '该操作缺少数据权限上下文，已拒绝。',
                            CashierV3ResultCode::STATUS_FAILED,
                            ['kind' => $kind, 'reason' => 'domain_provider_requires_data_scope']
                        );
                    }
                    $current = $provider->lockAndReadVersionWithDataScope(
                        $scope,
                        $kind,
                        $resourceId,
                        $dataScope
                    );
                } else {
                    $current = $provider->lockAndReadVersion($scope, $kind, $resourceId);
                }
            } else {
                $current = $this->lockCentralRow($scope, $kind, $resourceId);
            }

            if ($current === null) {
                throw CashierV3ScopeResolver::notFound($kind, $resourceId);
            }
            if ((int)$current !== (int)$context['expected_version']) {
                throw CashierV3CommandException::versionConflict(
                    '该内容已被其他人更新，请刷新后再操作。',
                    [
                        'kind' => $kind,
                        'id' => $resourceId,
                        'expected_version' => (int)$context['expected_version'],
                        'current_version' => (int)$current,
                    ]
                );
            }
            $locked[self::versionKey($scope, $kind, $resourceId)] = (int)$current;
        }
        return $locked;
    }

    /**
     * @param array<int,array{kind:string,id:string,expected_version:int,scope:CashierV3ResourceScope,data_scope?:CashierV3DataScopeContext}> $sortedContexts
     * @param string[] $touchedKeys
     * @param array<string,int> $lockedVersions
     * @return array<string,int>
     */
    public function bumpTouched(array $sortedContexts, string $action, array $touchedKeys, array $lockedVersions): array
    {
        CashierV3TransactionGuard::assertInTransaction('bumpTouched');

        $result = [];
        foreach ($sortedContexts as $context) {
            $scope = $this->requireScope($context);
            $key = self::versionKey($scope, $context['kind'], $context['id']);
            if (!in_array($key, $touchedKeys, true)) {
                continue;
            }
            $dataScope = $context['data_scope'] ?? null;
            if (isset($this->providers[$context['kind']])) {
                $provider = $this->providers[$context['kind']];
                if (CashierV3ResourceKindCatalog::isDomainOwned($context['kind'])
                    || $provider instanceof CashierV3DataScopedVersionProvider) {
                    if (!($provider instanceof CashierV3DataScopedVersionProvider)
                        || !($dataScope instanceof CashierV3DataScopeContext)) {
                        throw new CashierV3CommandException(
                            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                            '该操作缺少数据权限上下文，已拒绝。',
                            CashierV3ResultCode::STATUS_FAILED,
                            ['kind' => $context['kind'], 'reason' => 'domain_bump_requires_data_scope']
                        );
                    }
                    $next = (int)$provider->bumpVersionWithDataScope(
                        $scope,
                        $context['kind'],
                        $context['id'],
                        $action,
                        $dataScope
                    );
                } else {
                    $next = (int)$provider->bumpVersion(
                        $scope,
                        $context['kind'],
                        $context['id'],
                        $action
                    );
                }
            } else {
                $next = $this->bumpCentralRow($scope, $context['kind'], $context['id'], $action);
            }

            $expected = (int)($lockedVersions[$key] ?? 0) + 1;
            if ($next !== $expected) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_TOUCHED_INVALID,
                    '本次操作的版本推进异常，已回滚，请刷新当前工作台后重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['kind' => $context['kind'], 'id' => $context['id'], 'expected' => $expected, 'actual' => $next]
                );
            }
            $result[$key] = $next;
        }
        return $result;
    }

    /**
     * 版本键：带 scope 前缀，避免不同门店同 id 对象在同一批 touched 里互相顶替。
     */
    public static function versionKey(CashierV3ResourceScope $scope, string $kind, string $resourceId): string
    {
        return $scope->signature() . ':' . $kind . ':' . $resourceId;
    }

    /**
     * @param array $context
     */
    protected function requireScope(array $context): CashierV3ResourceScope
    {
        $scope = $context['scope'] ?? null;
        if (!$scope instanceof CashierV3ResourceScope) {
            // 没有服务端解析出的 scope 就动版本，等于回到「一律按当前门店」的错误口径
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_SCOPE_UNRESOLVED,
                '系统未能确定该对象的归属范围，请刷新当前工作台后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['kind' => (string)($context['kind'] ?? '')]
            );
        }
        return $scope;
    }

    /**
     * @return int|null
     */
    protected function lockCentralRow(CashierV3ResourceScope $scope, string $kind, string $resourceId)
    {
        CashierV3TransactionGuard::assertInTransaction('lockCentralRow:' . $kind);

        $row = Db::name(self::TABLE)
            ->where('scope_type', $scope->type())
            ->where('scope_id', $scope->id())
            ->where('resource_kind', $kind)
            ->where('resource_id', $resourceId)
            ->lock(true)
            ->find();
        return $row ? (int)$row['current_version'] : null;
    }

    protected function bumpCentralRow(CashierV3ResourceScope $scope, string $kind, string $resourceId, string $action): int
    {
        CashierV3TransactionGuard::assertInTransaction('bumpCentralRow:' . $kind);

        $affected = Db::name(self::TABLE)
            ->where('scope_type', $scope->type())
            ->where('scope_id', $scope->id())
            ->where('resource_kind', $kind)
            ->where('resource_id', $resourceId)
            ->update([
                'current_version' => Db::raw('current_version + 1'),
                'last_action' => mb_substr($action, 0, 64),
                'update_time' => time(),
            ]);
        if ((int)$affected !== 1) {
            throw CashierV3ScopeResolver::notFound($kind, $resourceId);
        }
        $row = Db::name(self::TABLE)
            ->where('scope_type', $scope->type())
            ->where('scope_id', $scope->id())
            ->where('resource_kind', $kind)
            ->where('resource_id', $resourceId)
            ->find();
        return $row ? (int)$row['current_version'] : 0;
    }
}
