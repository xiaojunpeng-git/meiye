<?php

namespace app\services\query\provider;

use app\model\store\SystemStoreStaff;
use app\services\cashier\v3\CashierV3StoreLoginServices;
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
        if ($originStoreId <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '创建任务的账号已停用，导出任务已停止。',
                []
            );
        }
        $profile = $this->currentOperatorProfile(
            $authoritativeTask,
            $operatorId,
            $originStoreId
        );
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

    /**
     * 异步任务必须按创建时的服务端身份类型回源。组织选店会话来自
     * cashier_v3_store_session，不能因 ID 恰好命中另一家门店的员工行而
     * 错用 system_store_staff，进而把完整查询导出成空文件。
     */
    protected function currentOperatorProfile(
        array $task,
        int $operatorId,
        int $originStoreId
    ): array {
        $permissionVersion = (string)($task['permission_version'] ?? '');
        $isOrganizationSession = strpos($permissionVersion, 'organization:') === 0;
        $isDelegatedSession = strpos($permissionVersion, 'delegated:') === 0;
        if ($isOrganizationSession || $isDelegatedSession) {
            $session = Db::name('cashier_v3_store_session')
                ->where('id', $operatorId)
                ->where('store_id', $originStoreId)
                ->where('status', 1)
                ->where('is_del', 0)
                ->where('expire_time', '>', time())
                ->find();
            if (!$session) {
                throw $this->permissionRevoked();
            }
            $source = (string)($session['source'] ?? '');
            $sessionIsOrganization = in_array($source, [
                'data_scope',
                CashierV3StoreLoginServices::STAFF_SCOPE_SESSION_SOURCE,
            ], true);
            if ($sessionIsOrganization !== $isOrganizationSession) {
                throw $this->permissionRevoked();
            }
            $profile = [
                'id' => $operatorId,
                'employee_id' => (int)($session['employee_id'] ?? 0),
                'store_id' => $originStoreId,
                'staff_name' => (string)($session['staff_name_snapshot'] ?? ''),
                'account' => (string)($session['account_snapshot'] ?? ''),
                'roles' => [],
                'level' => 1,
                'type' => $isOrganizationSession
                    ? 'cashier_v3_organization'
                    : 'cashier_v3_delegated',
                'session_mode' => $isOrganizationSession
                    ? 'store_organization'
                    : 'store_read_only',
            ];
            $profile[$isOrganizationSession
                ? '_cashier_v3_organization'
                : '_cashier_v3_delegated'] = 1;
            if ($source === CashierV3StoreLoginServices::STAFF_SCOPE_SESSION_SOURCE) {
                $profile['_cashier_v3_staff_scope'] = 1;
            }
            return $profile;
        }

        $staff = SystemStoreStaff::where('id', $operatorId)
            ->where('store_id', $originStoreId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->find();
        if (!$staff) {
            throw $this->permissionRevoked();
        }
        return $staff->toArray();
    }

    protected function permissionRevoked(): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
            '创建任务的账号已停用，导出任务已停止。',
            []
        );
    }
}
