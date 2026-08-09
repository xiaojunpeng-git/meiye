<?php

namespace app\services\merchant;

use app\services\BaseServices;
use app\services\customer\care\integration\CustomerCareActionInputMapper;
use app\services\customer\care\integration\CustomerCareWorkbenchActionAdapter;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Read-only bridge from the legacy merchant session to the shared customer-care domain.
 * The page cannot provide an employee, store or data scope; all are resolved server-side.
 */
class MerchantCustomerCareServices extends BaseServices
{
    public function workbench(int $uid, array $access, array $input): array
    {
        $context = $this->trustedContext($uid, $access);
        $bucket = (string)($input['bucket'] ?? 'today');
        if (!in_array($bucket, ['today', 'overdue', 'future', 'completed', 'all'], true)) {
            $bucket = 'today';
        }
        $scope = (string)($input['scope'] ?? 'my');
        if ($scope !== 'all' || empty($context['canViewAllTasks'])) {
            $scope = 'my';
        }

        /** @var CustomerCareWorkbenchActionAdapter $adapter */
        $adapter = app()->make(CustomerCareWorkbenchActionAdapter::class);
        return $adapter->handle(
            CustomerCareActionInputMapper::QUERY,
            $context,
            [
                'view' => 'tasks',
                'query' => [
                    'scope' => $scope,
                    'bucket' => $bucket,
                    'pageSize' => 20,
                ],
            ]
        );
    }

    private function trustedContext(int $uid, array $access): array
    {
        $storeId = (int)($access['active_store_id'] ?? 0);
        $scopeStoreIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($access['scope_store_ids'] ?? [])
        ))));
        if ($storeId <= 0 || !in_array($storeId, $scopeStoreIds, true)) {
            throw new ValidateException('请先选择有权限的门店');
        }

        $staff = Db::name('system_store_staff')
            ->where('uid', $uid)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)
            ->field('id,employee_id,staff_name')
            ->find();
        if (!is_array($staff) || (int)($staff['employee_id'] ?? 0) <= 0) {
            throw new ValidateException('当前门店任职未绑定统一员工档案，暂不能进入客情管理');
        }

        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)
            ->field('id,name')->find();
        $orgId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        $organization = $orgId > 0
            ? Db::name('organization')->where('id', $orgId)->where('is_del', 0)->field('id,name')->find()
            : null;
        if (!is_array($store) || !is_array($organization)) {
            throw new ValidateException('当前门店组织关系未就绪，暂不能进入客情管理');
        }

        $isPersonal = (string)($access['data_scope_type'] ?? '') === 'PERSONAL';
        return [
            'tenantId' => '0',
            'staffId' => (int)$staff['id'],
            'employeeId' => (int)$staff['employee_id'],
            'staffName' => trim((string)$staff['staff_name']) ?: '未命名员工',
            'operationStoreId' => $storeId,
            'operationStoreName' => trim((string)$store['name']) ?: '未命名门店',
            'operationOrganizationId' => (string)$organization['id'],
            'operationOrganizationPath' => '/' . (string)$organization['id'],
            'operationOrganizationName' => trim((string)$organization['name']) ?: '未命名组织',
            // 组织身份在手机端也必须先选定一间门店，不能默认汇总或跨店读取。
            'allowedBusinessStoreIds' => [$storeId],
            'businessTimezone' => 'Asia/Shanghai',
            'canViewAllTasks' => !$isPersonal,
            // 首期仅接入任务执行；手工建任务、转派和规则配置仍在电脑端。
            'canCreateTask' => false,
            'canCreateRecord' => false,
            'canReassign' => false,
            'canViewStatistics' => !$isPersonal,
        ];
    }
}
