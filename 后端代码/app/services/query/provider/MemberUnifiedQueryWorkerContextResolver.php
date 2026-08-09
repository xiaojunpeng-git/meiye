<?php

namespace app\services\query\provider;

use app\model\store\SystemStoreStaff;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\UnifiedQueryWorkerContextResolver;
use think\facade\Db;

/**
 * 会员页沿用现有收银员工身份与 DataScope 重建逻辑。
 */
class MemberUnifiedQueryWorkerContextResolver implements UnifiedQueryWorkerContextResolver
{
    public function pageCode(): string
    {
        return MemberUnifiedQueryProvider::PAGE_CODE;
    }

    public function resolve(array $authoritativeTask): array
    {
        $operatorId = (int)($authoritativeTask['operator_id'] ?? 0);
        $originStoreId = (int)($authoritativeTask['origin_store_id'] ?? 0);
        $staff = SystemStoreStaff::where('id', $operatorId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->find();
        if (!$staff || $originStoreId <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '创建任务的账号已停用，导出任务已停止。',
                []
            );
        }
        $profile = $staff->toArray();
        $employeeId = (int)($profile['employee_id'] ?? 0);
        if ($employeeId > 0) {
            $employee = Db::name('employee')
                ->where('id', $employeeId)
                ->where('status', 1)
                ->where('is_del', 0)
                ->find();
            if (!$employee) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                    '创建任务的员工档案已停用，导出任务已停止。',
                    []
                );
            }
        }

        $dispatcher = CashierV3Bootstrap::dispatcher();
        $operatorScope = $dispatcher->scopeResolver()->operatorScope(
            $originStoreId,
            $operatorId
        );
        $taskTenantId = trim((string)($authoritativeTask['tenant_id'] ?? ''));
        if ($taskTenantId === ''
            || !hash_equals($operatorScope->tenantId(), $taskTenantId)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '导出任务所属商户与当前账号不一致，任务已停止。',
                []
            );
        }
        $dataScope = $dispatcher->dataScopeFactory()->build(
            $originStoreId,
            $operatorId,
            $profile,
            $taskTenantId,
            (string)($authoritativeTask['origin_organization_id']
                ?? $operatorScope->organizationId())
        );
        $factory = new MemberUnifiedQueryContextFactory(
            UnifiedQueryRuntime::service('contextFactory')
        );
        return $factory->make(
            $operatorScope,
            $dataScope,
            ['pageCode' => $this->pageCode()]
        );
    }
}
