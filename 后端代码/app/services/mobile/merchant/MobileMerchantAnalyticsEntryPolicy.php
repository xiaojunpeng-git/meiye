<?php

declare(strict_types=1);

namespace app\services\mobile\merchant;

use InvalidArgumentException;

/** Pure entry policy shared by mobile analytics pages. */
final class MobileMerchantAnalyticsEntryPolicy
{
    public const TYPE_PERSONAL = 'personal';
    public const TYPE_STORE = 'store';
    public const TYPE_ORGANIZATION = 'organization';

    /**
     * @param array<int,array<string,mixed>> $scopeRows
     * @param int[] $authorizedStoreIds
     * @param array<int,array<string,mixed>> $organizations
     * @param array<int,array<string,mixed>> $storeBindings
     * @return array<string,mixed>
     */
    public function resolve(
        array $scopeRows,
        array $authorizedStoreIds,
        int $activeStoreId,
        int $employeeId,
        array $organizations,
        array $storeBindings
    ): array {
        $storeIds = $this->positiveIds($authorizedStoreIds);
        if ($storeIds === []) {
            throw new InvalidArgumentException('当前数据权限范围内没有有效门店。');
        }

        $entryType = self::TYPE_PERSONAL;
        $grantedOrgIds = [];
        foreach ($scopeRows as $row) {
            $mode = trim((string)($row['scope_mode'] ?? self::TYPE_PERSONAL));
            if ($mode === 'org') {
                $entryType = self::TYPE_ORGANIZATION;
                $grantedOrgIds = array_merge($grantedOrgIds, $this->jsonIds($row['org_ids'] ?? []));
            } elseif ($entryType !== self::TYPE_ORGANIZATION && in_array($mode, ['store', 'store_self'], true)) {
                $entryType = self::TYPE_STORE;
            }
        }

        if ($entryType === self::TYPE_PERSONAL) {
            return [
                'entryType' => self::TYPE_PERSONAL,
                'entryNodeType' => '',
                'entryNodeId' => 0,
                'employeeId' => $employeeId,
                'showOrganizationRanking' => false,
                'organizationPickerEnabled' => false,
                'authorizedStoreIds' => $storeIds,
            ];
        }

        if ($entryType === self::TYPE_STORE) {
            $storeId = in_array($activeStoreId, $storeIds, true) ? $activeStoreId : $storeIds[0];
            return [
                'entryType' => self::TYPE_STORE,
                'entryNodeType' => 'store',
                'entryNodeId' => $storeId,
                'employeeId' => 0,
                'showOrganizationRanking' => false,
                'organizationPickerEnabled' => false,
                'authorizedStoreIds' => $storeIds,
            ];
        }

        $rootOrganizationId = $this->highestGrantedOrganizationId(
            $grantedOrgIds,
            $storeIds,
            $organizations,
            $storeBindings
        );
        return [
            'entryType' => self::TYPE_ORGANIZATION,
            'entryNodeType' => $rootOrganizationId > 0 ? 'organization' : '',
            'entryNodeId' => $rootOrganizationId,
            'employeeId' => 0,
            'showOrganizationRanking' => true,
            'organizationPickerEnabled' => true,
            'authorizedStoreIds' => $storeIds,
        ];
    }

    /** @param int[] $grantedOrgIds @param int[] $authorizedStoreIds */
    private function highestGrantedOrganizationId(array $grantedOrgIds, array $authorizedStoreIds, array $organizations, array $storeBindings): int
    {
        $orgMap = [];
        foreach ($organizations as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $orgMap[$id] = max(0, (int)($row['pid'] ?? 0));
            }
        }
        $storeOrgIds = [];
        foreach ($storeBindings as $row) {
            $storeId = (int)($row['store_id'] ?? 0);
            $orgId = (int)($row['org_id'] ?? 0);
            if ($storeId > 0 && $orgId > 0 && isset($orgMap[$orgId]) && !isset($storeOrgIds[$storeId])) {
                $storeOrgIds[$storeId] = $orgId;
            }
        }

        $candidates = [];
        foreach ($this->positiveIds($grantedOrgIds) as $orgId) {
            if (!isset($orgMap[$orgId])) {
                continue;
            }
            foreach ($authorizedStoreIds as $storeId) {
                if (isset($storeOrgIds[$storeId]) && $this->contains($storeOrgIds[$storeId], $orgId, $orgMap)) {
                    $candidates[$orgId] = $orgId;
                    break;
                }
            }
        }
        if ($candidates === []) {
            return 0;
        }

        $topLevel = [];
        foreach ($candidates as $candidate) {
            $isNestedGrant = false;
            foreach ($candidates as $other) {
                if ($candidate !== $other && $this->contains($candidate, $other, $orgMap)) {
                    $isNestedGrant = true;
                    break;
                }
            }
            if (!$isNestedGrant) {
                $topLevel[$candidate] = $candidate;
            }
        }
        if (count($topLevel) !== 1) {
            return 0;
        }
        $rootId = (int)reset($topLevel);
        foreach ($authorizedStoreIds as $storeId) {
            if (!isset($storeOrgIds[$storeId]) || !$this->contains($storeOrgIds[$storeId], $rootId, $orgMap)) {
                return 0;
            }
        }
        return $rootId;
    }

    private function contains(int $organizationId, int $ancestorId, array $orgMap): bool
    {
        $current = $organizationId;
        for ($depth = 0; $depth < 128 && $current > 0; $depth++) {
            if ($current === $ancestorId) {
                return true;
            }
            if (!isset($orgMap[$current])) {
                return false;
            }
            $current = $orgMap[$current];
        }
        return false;
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
