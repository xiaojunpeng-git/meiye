<?php
namespace app\services\cashier\v3;

/**
 * 领域资源版本提供器（强制 DataScope，不继承无授权旧接口）。
 *
 * domain-owned kind 只能通过本接口注册／解析／锁定／推进。
 * 禁止再暴露 resolveScope／lockAndReadVersion／bumpVersion 无 DataScope 旁路。
 */
interface CashierV3DataScopedVersionProvider
{
    /**
     * @return CashierV3ResourceScope|null
     */
    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    );

    /**
     * @return int|null
     */
    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    );

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int;
}
