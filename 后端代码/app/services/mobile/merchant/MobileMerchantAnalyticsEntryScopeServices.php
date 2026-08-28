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
    private $organizationStores;
    private $policy;

    public function __construct(
        EmployeeDataScopeServices $dataScopes,
        MobileMerchantOrganizationStoreScopeServices $organizationStores,
        MobileMerchantAnalyticsEntryPolicy $policy
    ) {
        $this->dataScopes = $dataScopes;
        $this->organizationStores = $organizationStores;
        $this->policy = $policy;
    }

    /** @return array<string,mixed> */
    public function resolve(array $merchant): array
    {
        $employeeId = (int)($merchant['employeeId'] ?? 0);
        $activeStoreId = (int)($merchant['storeId'] ?? 0);
        $auth = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->field('scope_mode,store_ids,org_ids')->find();
        if (!is_array($auth)) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前员工没有有效手机端授权。');
        }

        $dataScopeStoreIds = $this->dataScopes->resolveEffectiveStoreIds($employeeId, 0);
        $dataScopeStoreIds = is_array($dataScopeStoreIds) && $dataScopeStoreIds !== []
            ? $this->positiveIds($dataScopeStoreIds)
            : $this->positiveIds([$activeStoreId]);
        $mobileScopeStoreIds = $this->mobileScopeStoreIds($auth, $dataScopeStoreIds);
        $storeIds = array_values(array_intersect($dataScopeStoreIds, $mobileScopeStoreIds));
        $storeIds = $this->positiveIds($storeIds);
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
    private function mobileScopeStoreIds(array $auth, array $dataScopeStoreIds): array
    {
        $mode = trim((string)($auth['scope_mode'] ?? ''));
        if ($mode === 'all') {
            return $dataScopeStoreIds;
        }
        if ($mode === 'store') {
            return $this->jsonIds($auth['store_ids'] ?? '[]');
        }
        if ($mode === 'org') {
            return $this->organizationStores->resolve($this->jsonIds($auth['org_ids'] ?? '[]'));
        }
        return [];
    }

    /** @return int[] */
    private function jsonIds($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return $this->positiveIds(is_array($value) ? $value : []);
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
