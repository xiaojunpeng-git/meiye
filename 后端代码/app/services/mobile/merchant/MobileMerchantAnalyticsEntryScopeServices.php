<?php

declare(strict_types=1);

namespace app\services\mobile\merchant;

use app\services\mobile\protocol\MobileApiException;
use app\services\organization\EmployeeDataScopeServices;
use InvalidArgumentException;
use think\facade\Db;

/** Resolves the server-authoritative entry scope shared by mobile analytics pages. */
final class MobileMerchantAnalyticsEntryScopeServices
{
    private $dataScopes;
    private $policy;

    public function __construct(
        EmployeeDataScopeServices $dataScopes,
        MobileMerchantAnalyticsEntryPolicy $policy
    ) {
        $this->dataScopes = $dataScopes;
        $this->policy = $policy;
    }

    /** @return array<string,mixed> */
    public function resolve(array $merchant): array
    {
        $employeeId = (int)($merchant['employeeId'] ?? 0);
        $activeStoreId = (int)($merchant['storeId'] ?? 0);
        $auth = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->field('employee_id')->find();
        if (!is_array($auth)) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前员工没有有效手机端授权。');
        }

        $dataScopeStoreIds = $this->dataScopes->resolveEffectiveStoreIds($employeeId, 0);
        $dataScopeStoreIds = is_array($dataScopeStoreIds) && $dataScopeStoreIds !== []
            ? $this->positiveIds($dataScopeStoreIds)
            : $this->positiveIds([$activeStoreId]);
        // The mobile grant gates entry; employee data scope is the sole
        // authority for the stores visible to merchant analytics.
        $storeIds = $this->positiveIds($dataScopeStoreIds);
        $stores = $storeIds === [] ? [] : Db::name('system_store')->whereIn('id', $storeIds)
            ->where('is_del', 0)->field('id')->select()->toArray();
        $storeIds = $this->positiveIds(array_column($stores, 'id'));
        if ($storeIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有有效门店。');
        }

        $scopeRows = Db::name('employee_data_scope')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->field('scope_mode,org_ids')->select()->toArray();
        $organizations = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
        $bindings = Db::name('organization_store')->whereIn('store_id', $storeIds)->field('org_id,store_id')->select()->toArray();
        try {
            return $this->policy->resolve($scopeRows, $storeIds, $activeStoreId, $employeeId, $organizations, $bindings);
        } catch (InvalidArgumentException $exception) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', $exception->getMessage());
        }
    }

    /** @return int[] */
    private function positiveIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }
}
