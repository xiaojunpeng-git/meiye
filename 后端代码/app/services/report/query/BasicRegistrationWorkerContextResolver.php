<?php
namespace app\services\report\query;

use app\services\query\provider\MemberUnifiedQueryWorkerContextResolver;
use app\services\query\UnifiedQueryException;
use app\services\report\BasicReportHostServices;
use app\services\organization\EmployeeDataScopeServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\system\SystemRoleServices;
use think\facade\Db;

/** 导出任务重新解析当前账号权限，不把旧任务中的门店集合当成永久授权。 */
class BasicRegistrationWorkerContextResolver extends MemberUnifiedQueryWorkerContextResolver
{
    public function pageCode(): string { return BasicRegistrationQueryProvider::PAGE_CODE; }

    public function resolve(array $task): array
    {
        if (($task['page_code'] ?? '') !== $this->pageCode() || ($task['tenant_id'] ?? '') !== '0') throw $this->permissionRevoked();
        $operator = (int)($task['operator_id'] ?? 0); $store = (int)($task['origin_store_id'] ?? 0);
        if ($operator < 1 || $store < 0) throw $this->permissionRevoked();
        if ($store > 0) {
            $profile = $this->currentOperatorProfile($task, $operator, $store);
            $resolver = app()->make(CashierV3FeatureResolver::class);
            $features = !empty($profile['_cashier_v3_delegated']) ? $resolver->resolveVisibleFeatures($profile) : $resolver->resolveGrantedFeatures($profile);
            if (!in_array('cashier.v3.data', $features, true)) throw $this->permissionRevoked();
            $dispatcher = CashierV3Bootstrap::dispatcher();
            $operatorScope = $dispatcher->scopeResolver()->operatorScope($store, $operator);
            $scope = $dispatcher->dataScopeFactory()->build($store, $operator, $profile, '0', $operatorScope->organizationId());
            $visible = $scope->visibleStoreIds();
            if ($visible !== null && !in_array($store, array_map('intval', $visible), true)) throw $this->permissionRevoked();
            return BasicReportHostServices::context([$store], $scope->authorizationMode(), $operator, 'store', false, 0, $this->pageCode());
        }
        $admin = Db::name('system_admin')->where('id', $operator)->where('status', 1)->find();
        if (!$admin || !empty($admin['is_del']) || (int)($admin['type'] ?? 0) !== 0) throw $this->permissionRevoked();
        if ((int)$admin['level'] !== 0) {
            $roles = is_array($admin['roles']) ? $admin['roles'] : array_filter(explode(',', (string)$admin['roles']));
            $allowed = false;
            foreach (app()->make(SystemRoleServices::class)->getRolesByAuth($roles, 1) as $menu) {
                if (($menu['unique_auth'] ?? '') === 'admin-report-basic') $allowed = true;
            }
            if (!$allowed) throw $this->permissionRevoked();
        }
        $employee = (int)($admin['employee_id'] ?? 0);
        $stores = null;
        if ($employee > 0) {
            $scope = app()->make(EmployeeDataScopeServices::class);
            if ($scope->resolvePrimaryHqScopeMode($employee) === EmployeeDataScopeServices::MODE_PERSONAL) throw $this->permissionRevoked();
            $stores = $scope->resolveEffectiveStoreIds($employee, 0, $admin);
        }
        if ($stores === null) $stores = Db::name('system_store')->where('is_del', 0)->where('name', '<>', '总部')->column('id');
        return BasicReportHostServices::context($stores, 'stores', $operator, 'platform', false, 0, $this->pageCode());
    }
}
