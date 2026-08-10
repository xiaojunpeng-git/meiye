<?php

namespace app\services\cashier\v3\order;

use app\model\store\SystemStoreStaff;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\UnifiedQueryWorkerContextResolver;
use app\services\query\provider\MemberUnifiedQueryContextFactory;
use think\facade\Db;

/** Rebuilds the current server-side scope for every order-center export task. */
final class CashierV3OrderCenterUnifiedQueryWorkerContextResolver implements UnifiedQueryWorkerContextResolver
{
    public function pageCode(): string { return CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE['sales']; }
    public function pageCodes(): array { return array_values(CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE); }

    public function resolve(array $task): array
    {
        $operatorId = (int)($task['operator_id'] ?? 0);
        $storeId = (int)($task['origin_store_id'] ?? 0);
        $tenantId = trim((string)($task['tenant_id'] ?? ''));
        $staff = SystemStoreStaff::where('id', $operatorId)->where('status', 1)->where('is_del', 0)->find();
        if (!$staff || $storeId <= 0 || $tenantId === '') {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '创建任务的账号已停用，导出任务已停止。', []);
        }
        $profile = $staff->toArray();
        $employeeId = (int)($profile['employee_id'] ?? 0);
        if ($employeeId > 0 && !Db::name('employee')->where('id', $employeeId)->where('status', 1)->where('is_del', 0)->find()) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '创建任务的员工档案已停用，导出任务已停止。', []);
        }
        $dispatcher = CashierV3Bootstrap::dispatcher();
        $operatorScope = $dispatcher->scopeResolver()->operatorScope($storeId, $operatorId);
        if (!hash_equals($operatorScope->tenantId(), $tenantId)) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '导出任务所属商户与当前账号不一致，任务已停止。', []);
        }
        $dataScope = $dispatcher->dataScopeFactory()->build(
            $storeId, $operatorId, $profile, $tenantId,
            (string)($task['origin_organization_id'] ?? $operatorScope->organizationId())
        );
        $factory = new MemberUnifiedQueryContextFactory(UnifiedQueryRuntime::service('contextFactory'));
        return $factory->make($operatorScope, $dataScope, ['pageCode' => (string)($task['page_code'] ?? '')]);
    }
}
