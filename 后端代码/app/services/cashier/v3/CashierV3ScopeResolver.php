<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

use think\facade\Db;

/**
 * 把「后端强制会话」翻译成资源的 canonical scope。
 *
 * 铁律：客户端永远不提交 scope_type／scope_id。本类是服务端唯一的推导入口。
 *
 * 推导规则：
 * - tenant 类资源：一库一租户，scope_id 固定为 TENANT_SCOPE_ID。会员、余额、
 *   次数池、卡权益因此在集团内共享同一份版本，跨店并发不会各自通过。
 * - organization 类资源：由强制门店经 eb_organization_store 反查所属组织；
 *   查不到即 fail-closed，不退化成门店范围。
 * - store 类资源：默认取后端强制门店。若该 kind 已注册业务主表提供器，则以
 *   提供器返回的「真实归属门店」为准；提供器判定越权时返回 null，
 *   统一按「对象不存在或不可操作」处理，不返回当前版本，防止跨店探测。
 */
class CashierV3ScopeResolver
{
    /**
     * 一库一租户：本项目每个站点是独立业务库，租户即整库。
     * 将来真正多租户时，这里换成会话里的租户标识，唯一键结构无需变更。
     */
    public const TENANT_SCOPE_ID = '0';

    /** @var array<string,CashierV3DataScopedVersionProvider|CashierV3ResourceVersionProvider> */
    protected $providers = [];

    /** @var bool */
    protected $frozen = false;

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * 领域 kind 只接受 DataScoped；中央技术 kind 可接受无 DataScope 的旧接口。
     *
     * @param CashierV3DataScopedVersionProvider|CashierV3ResourceVersionProvider $provider
     */
    public function registerProvider(string $kind, $provider): void
    {
        if ($this->frozen) {
            throw new \LogicException('scope resolver providers 已 freeze');
        }
        CashierV3ResourceKindCatalog::assertKnown($kind);
        if (CashierV3ResourceKindCatalog::isDomainOwned($kind)) {
            if (!($provider instanceof CashierV3DataScopedVersionProvider)) {
                throw new \LogicException(sprintf(
                    'domain kind %s 必须注册 CashierV3DataScopedVersionProvider（禁止无 DataScope 旁路）',
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
        $this->providers[$kind] = $provider;
    }

    public function providerFor(string $kind)
    {
        return $this->providers[$kind] ?? null;
    }

    /**
     * 从会话构造后端强制操作范围。
     */
    public function operatorScope(int $storeId, int $operatorId): CashierV3OperatorScope
    {
        return new CashierV3OperatorScope(
            $storeId,
            $operatorId,
            $this->organizationIdOfStore($storeId),
            self::TENANT_SCOPE_ID
        );
    }

    /**
     * 解析单个资源的 canonical scope。
     *
     * @param CashierV3DataScopeContext|null $dataScope 同一请求的数据权限；越权与不存在统一 NOT_FOUND
     * @throws CashierV3CommandException RESOURCE_NOT_FOUND（含越权）／RESOURCE_SCOPE_UNRESOLVED
     */
    public function resolveFor(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope = null
    ): CashierV3ResourceScope {
        $scopeType = CashierV3ResourceKindCatalog::scopeTypeOf($kind);

        $provider = $this->providers[$kind] ?? null;
        if ($provider !== null) {
            if ($provider instanceof CashierV3DataScopedVersionProvider) {
                if ($dataScope === null) {
                    // 缺 DataScope 立即 fail-closed，不得回退无授权接口
                    throw self::notFound($kind, $resourceId);
                }
                $scope = $provider->resolveScopeWithDataScope($kind, $resourceId, $operatorScope, $dataScope);
            } else {
                // 仅中央／非领域技术资源可走无 DataScope 接口
                if (CashierV3ResourceKindCatalog::isDomainOwned($kind)) {
                    throw self::notFound($kind, $resourceId);
                }
                $scope = $provider->resolveScope($kind, $resourceId, $operatorScope);
            }
            if ($scope === null) {
                throw self::notFound($kind, $resourceId);
            }
            if ($scope->type() !== $scopeType) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::RESOURCE_SCOPE_UNRESOLVED,
                    '系统未能确定该对象的归属范围，请刷新当前工作台后重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['kind' => $kind, 'declared' => $scopeType, 'provider' => $scope->type()]
                );
            }
            // SELF_PARTICIPANT：provider 已按参与关系判定可见性，不得再用空门店集合否决
            if ($dataScope !== null
                && $scope->type() === CashierV3ResourceScope::TYPE_STORE
                && $dataScope->requiresStoreSetGate()
                && $dataScope->authorizationMode() !== CashierV3DataScopeContext::MODE_SELF_PARTICIPANT
            ) {
                if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_ALL) {
                    // 全范围放行
                } elseif (!$dataScope->allowsStore((int)$scope->id())) {
                    throw self::notFound($kind, $resourceId);
                }
            }
            return $scope;
        }

        // 业务领域 kind 缺 provider 时禁止退回默认门店／租户归属（fail-open）
        if (CashierV3ResourceKindCatalog::isDomainOwned($kind)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '该操作尚未开放，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['kind' => $kind, 'missing' => 'resource_provider']
            );
        }

        switch ($scopeType) {
            case CashierV3ResourceScope::TYPE_TENANT:
                return CashierV3ResourceScope::of(
                    CashierV3ResourceScope::TYPE_TENANT,
                    $operatorScope->tenantId()
                );

            case CashierV3ResourceScope::TYPE_ORGANIZATION:
                $organizationId = $operatorScope->organizationId();
                if ($organizationId === '') {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::RESOURCE_SCOPE_UNRESOLVED,
                        '当前门店尚未归属组织，无法执行该操作，请联系管理员。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['kind' => $kind, 'store_id' => $operatorScope->storeId()]
                    );
                }
                return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_ORGANIZATION, $organizationId);

            case CashierV3ResourceScope::TYPE_ACCOUNT:
                // 查询方案：账号级 scope，不随办理门店变化
                return CashierV3ResourceScope::of(
                    CashierV3ResourceScope::TYPE_ACCOUNT,
                    (string)$operatorScope->operatorId()
                );

            case CashierV3ResourceScope::TYPE_STORE:
            default:
                // 仅 C1 内置 kind（如 cashier_workspace）可落到强制门店
                return $operatorScope->storeScope();
        }
    }

    /**
     * 批量解析并附加到已校验的 contexts 上。
     *
     * @param array<int,array{kind:string,id:string,expected_version:int}> $contexts
     * @return array<int,array{kind:string,id:string,expected_version:int,scope:CashierV3ResourceScope}>
     */
    public function attachScopes(
        array $contexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope = null
    ): array {
        $out = [];
        foreach ($contexts as $context) {
            $context['scope'] = $this->resolveFor(
                $context['kind'],
                $context['id'],
                $operatorScope,
                $dataScope
            );
            $out[] = $context;
        }
        return $out;
    }

    /**
     * 越权与不存在共用同一结果：不回传当前版本，避免 A 店用版本冲突探测 B 店对象。
     */
    public static function notFound(string $kind, string $resourceId): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::RESOURCE_NOT_FOUND,
            '该对象不存在或已被移除，请刷新当前工作台后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['kind' => $kind, 'id' => $resourceId]
        );
    }

    /**
     * 强制门店所属组织。查不到返回空串，由调用方按 kind 决定是否 fail-closed。
     *
     * 这里刻意不做任何缓存：容器把本类按单例复用，Swoole 常驻进程下缓存组织归属
     * 会在门店调整组织后继续用旧值判定作用域。每次一条走唯一索引的查询即可。
     */
    protected function organizationIdOfStore(int $storeId): string
    {
        if ($storeId <= 0) {
            return '';
        }
        try {
            $row = Db::name('organization_store')->where('store_id', $storeId)->find();
            if ($row && (int)($row['org_id'] ?? 0) > 0) {
                return (string)(int)$row['org_id'];
            }
        } catch (\Throwable $exception) {
            // 组织表缺失或不可读时不放宽：返回空串，organization 类资源随后 fail-closed
            return '';
        }
        return '';
    }
}
