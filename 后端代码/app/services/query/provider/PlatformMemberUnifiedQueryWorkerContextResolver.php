<?php
namespace app\services\query\provider;
use app\services\query\UnifiedQueryWorkerContextResolver;
use app\services\query\UnifiedQueryRuntime;
use app\services\user\PlatformMemberScopeServices;

/** 异步导出重新校验平台账号/角色/实时范围，绝不按同数字门店员工 ID 取身份。 */
class PlatformMemberUnifiedQueryWorkerContextResolver implements UnifiedQueryWorkerContextResolver
{
    public function pageCode(): string { return PlatformMemberUnifiedQueryProvider::PAGE_CODE; }
    public function resolve(array $task): array
    {
        $scope = (new PlatformMemberScopeServices())->build((int)$task['operator_id']);
        return (new MemberUnifiedQueryContextFactory(UnifiedQueryRuntime::service('contextFactory')))->make($scope['operator'], $scope['data'], ['pageCode' => $this->pageCode()]);
    }
}
