<?php
namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\user\MemberLoginManagementServices;

/** 密码不经过通用网关的命令快照/日志；仍执行同一编辑功能权限和会员范围校验。 */
class MemberLogin extends AuthController
{
    public function read($id, MemberLoginManagementServices $service)
    {
        return $this->safe(function () use ($id, $service) { [$operator, $scope] = $this->context(false); return $service->read((int)$id, $operator, $scope); });
    }

    public function password($id, MemberLoginManagementServices $service)
    {
        return $this->write((int)$id, 'change-password', $service);
    }

    public function clear($id, MemberLoginManagementServices $service)
    {
        return $this->write((int)$id, 'clear-wechat', $service);
    }

    private function write(int $id, string $action, MemberLoginManagementServices $service)
    {
        return $this->safe(function () use ($id, $action, $service) { [$operator, $scope] = $this->context(true); return $service->mutate($id, $action, (array)$this->request->post(), $operator, $scope, 'STORE'); });
    }

    private function context(bool $write): array
    {
        $dispatcher = CashierV3Bootstrap::dispatcher();
        $operator = $dispatcher->scopeResolver()->operatorScope((int)$this->storeId, (int)$this->cashierId);
        $scope = $dispatcher->dataScopeFactory()->build((int)$this->storeId, (int)$this->cashierId, (array)$this->cashierInfo, $operator->tenantId(), $operator->organizationId());
        $dispatcher->permissionGuard()->assertAllowed(CashierV3ActionManifest::requireAction($write ? 'update-member' : 'open-member-editor'), $scope);
        return [$operator, $scope];
    }

    /** 密码请求失败也不走 debug 参数堆栈响应，避免原始输入出现在日志/回包中。 */
    private function safe(callable $operation)
    {
        try { return $this->success($operation()); }
        catch (\Throwable $e) { return $this->fail(($e instanceof \think\exception\ValidateException || $e instanceof \app\services\cashier\v3\CashierV3CommandException) ? $e->getMessage() : '登录信息操作失败，请刷新重试'); }
    }
}
