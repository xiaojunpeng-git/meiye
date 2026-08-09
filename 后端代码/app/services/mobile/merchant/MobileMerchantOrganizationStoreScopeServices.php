<?php

declare(strict_types=1);

namespace app\services\mobile\merchant;

use app\services\organization\OrganizationScopeService;

/** Resolves organization grants through the authoritative descendant-aware scope service. */
final class MobileMerchantOrganizationStoreScopeServices
{
    /** @return int[] */
    public function resolve(array $organizationIds): array
    {
        $ids = [];
        foreach ($organizationIds as $value) {
            $id = (int)$value;
            if ($id > 0) $ids[$id] = $id;
        }
        if ($ids === []) return [];

        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        $storeIds = [];
        foreach (array_values($ids) as $organizationId) {
            foreach ($scope->getOrgStoreIds($organizationId, true) as $storeId) {
                $storeId = (int)$storeId;
                if ($storeId > 0) $storeIds[$storeId] = $storeId;
            }
        }
        ksort($storeIds, SORT_NUMERIC);
        return array_values($storeIds);
    }
}
