<?php

namespace app\services\cashier\v3\order;

use app\model\store\SystemStoreStaff;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3PermissionSnapshotServices;
use app\services\cashier\v3\CashierV3StoreLoginServices;
use app\services\organization\EmployeeDataScopeServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\UnifiedQueryWorkerContextResolver;
use app\services\query\provider\MemberUnifiedQueryContextFactory;
use think\facade\Db;

/** 从当前任职或有效选店会话重建订单导出权限，绝不沿用任务创建时的权限快照。 */
final class CashierV3OrderCenterUnifiedQueryWorkerContextResolver implements UnifiedQueryWorkerContextResolver
{
    public function pageCode(): string { return CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE['sales']; }
    public function pageCodes(): array { return array_values(CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE); }

    public function resolve(array $task): array
    {
        return $this->currentScopes(
            (int)($task['operator_id'] ?? 0), (int)($task['origin_store_id'] ?? 0),
            (string)($task['tenant_id'] ?? ''), (string)($task['origin_organization_id'] ?? ''),
            (string)($task['page_code'] ?? ''), (string)($task['permission_version'] ?? '')
        )[2];
    }

    /** 文件执行与订单 Reader 必须使用同一当前身份，不能只在任务领取时修正权限。 */
    public function scopesForContext(array $context): array
    {
        $scopes = $this->currentScopes(
            (int)($context['operator_id'] ?? 0), (int)($context['store_id'] ?? 0),
            (string)($context['tenant_id'] ?? ''), (string)($context['organization_id'] ?? ''),
            (string)($context['page_code'] ?? ''), (string)($context['permission_version'] ?? '')
        );
        return [$scopes[0], $scopes[1]];
    }

    private function currentScopes(
        int $operatorId, int $storeId, string $tenantId, string $organizationId,
        string $pageCode, string $permissionVersion
    ): array {
        $tenantId = trim($tenantId);
        if ($operatorId <= 0 || $storeId <= 0 || $tenantId === '') {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '创建任务的账号已停用，导出任务已停止。', []);
        }
        if (strpos($permissionVersion, 'platform-order-center:') === 0) {
            $scopes = app()->make(\app\services\order\PlatformOrderCenterReadServices::class)
                ->exportScopes($operatorId, $pageCode);
            if (!hash_equals($tenantId, $scopes[0]->tenantId())
                || !hash_equals($organizationId, $scopes[0]->organizationId())
                || $storeId !== $scopes[0]->storeId()
                || !hash_equals($permissionVersion, (string)$scopes[2]['permission_version'])) {
                throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '平台导出范围已变化，请重新查询后导出。', []);
            }
            return $scopes;
        }
        $dispatcher = CashierV3Bootstrap::dispatcher();
        $operatorScope = $dispatcher->scopeResolver()->operatorScope($storeId, $operatorId);
        if (!hash_equals($operatorScope->tenantId(), $tenantId)
            || !hash_equals($operatorScope->organizationId(), $organizationId)) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '导出任务所属商户与当前账号不一致，任务已停止。', []);
        }
        $profiles = [];
        $staff = SystemStoreStaff::where('id', $operatorId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->find();
        if ($staff) {
            $profile = $staff->toArray();
            // 导出任务创建路径已把岗位 ID 规范为整数。模型访问器返回字符串数组，
            // worker 必须复用同一规范化口径，避免同一岗位生成不同权限版本。
            $profile['roles'] = CashierV3PermissionSnapshotServices::normalizeRoleIds(
                $profile['roles'] ?? []
            );
            $employeeId = (int)($profile['employee_id'] ?? 0);
            if ($employeeId <= 0 || Db::name('employee')->where('id', $employeeId)
                ->where('status', 1)->where('is_del', 0)->find()) $profiles[] = $profile;
        }
        $sessionProfile = $this->currentStoreSessionProfile($operatorId, $storeId);
        if ($sessionProfile !== null) $profiles[] = $sessionProfile;
        $factory = new MemberUnifiedQueryContextFactory(UnifiedQueryRuntime::service('contextFactory'));
        $matched = [];
        foreach ($profiles as $profile) {
            $dataScope = $dispatcher->dataScopeFactory()->build(
                $storeId, $operatorId, $profile, $tenantId, $operatorScope->organizationId()
            );
            $context = $factory->make($operatorScope, $dataScope, ['pageCode' => $pageCode]);
            // staff ID 与选店 session ID 可能相同；创建时的服务端权限版本只用于
            // 辨认原身份。权限版本变化则要求重新查询，不能换一种身份执行旧任务。
            if (hash_equals($permissionVersion, (string)$context['permission_version'])) {
                $matched[] = [$operatorScope, $dataScope, $context];
            }
        }
        if (count($matched) !== 1) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '导出账号或当前岗位已变化，请重新查询后导出。', []);
        }
        return $matched[0];
    }

    /** 选店身份必须从仍有效的本机服务端会话和员工权限重建。 */
    private function currentStoreSessionProfile(int $operatorId, int $storeId): ?array
    {
        $session = Db::name('cashier_v3_store_session')->where('id', $operatorId)
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        if (!$session || (int)($session['expire_time'] ?? 0) < time()
            || !in_array((string)($session['source'] ?? ''), ['data_scope', CashierV3StoreLoginServices::STAFF_SCOPE_SESSION_SOURCE], true)) return null;
        $employeeId = (int)($session['employee_id'] ?? 0);
        $employee = Db::name('employee')->where('id', $employeeId)->where('status', 1)
            ->where('is_del', 0)->field('id,auth_version')->find();
        if (!$employee || (int)$employee['auth_version'] !== (int)$session['auth_version']) return null;
        $allowed = app()->make(EmployeeDataScopeServices::class)->resolveEffectiveStoreIds(
            $employeeId, 0, ['admin_type' => 3]
        );
        if ($allowed !== null && !in_array($storeId, array_map('intval', (array)$allowed), true)) return null;
        $profile = ['id' => $operatorId, 'employee_id' => $employeeId, 'store_id' => $storeId,
            'roles' => [], 'level' => 1, '_cashier_v3_organization' => 1];
        if ((string)$session['source'] === CashierV3StoreLoginServices::STAFF_SCOPE_SESSION_SOURCE) {
            $profile['_cashier_v3_staff_scope'] = 1;
        }
        return $profile;
    }
}
