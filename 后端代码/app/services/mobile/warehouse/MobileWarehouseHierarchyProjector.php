<?php

declare(strict_types=1);

namespace app\services\mobile\warehouse;

use InvalidArgumentException;

/** Builds the permission-trimmed organization/store drill-down projection. */
final class MobileWarehouseHierarchyProjector
{
    /**
     * @param array<int,array<string,mixed>> $organizations
     * @param array<int,array<string,mixed>> $stores
     * @param array<int,array<string,mixed>> $bindings
     * @param int[] $allowedStoreIds
     */
    public function project(
        array $organizations,
        array $stores,
        array $bindings,
        array $allowedStoreIds,
        string $nodeType = '',
        int $nodeId = 0,
        ?int $preferredRootOrganizationId = null
    ): array {
        $orgMap = $this->organizationMap($organizations);
        $storeMap = $this->storeMap($stores, $allowedStoreIds);
        $storeOrgMap = $this->storeOrganizationMap($bindings, $storeMap, $orgMap);
        if ($storeMap === [] || $storeOrgMap === []) {
            throw new InvalidArgumentException('当前数据权限范围内没有有效门店。');
        }
        if (count($storeOrgMap) !== count($storeMap)) {
            throw new InvalidArgumentException('部分授权门店尚未归属有效组织，暂时无法读取数仓。');
        }

        $paths = [];
        foreach ($storeOrgMap as $storeId => $orgId) {
            $paths[$storeId] = $this->pathToRoot($orgId, $orgMap);
        }
        $rootId = $this->commonRootId(array_values($paths));
        if ($preferredRootOrganizationId !== null) {
            if ($preferredRootOrganizationId === 0) {
                // An analytics entry policy can intentionally choose a
                // virtual root for disjoint grants. Do not relabel that
                // restricted union as an ungranted common parent.
                $rootId = 0;
            } elseif (isset($orgMap[$preferredRootOrganizationId])
                && $this->organizationContainsEveryAllowedStore($preferredRootOrganizationId, $paths)) {
                $rootId = $preferredRootOrganizationId;
            }
        }

        if ($nodeType === 'store') {
            if ($nodeId <= 0 || !isset($storeMap[$nodeId], $storeOrgMap[$nodeId])) {
                throw new InvalidArgumentException('该门店不在当前账号的数据权限范围内。');
            }
            return $this->storeProjection($rootId, $nodeId, $orgMap, $storeMap, $storeOrgMap, $paths);
        }

        $currentOrgId = $nodeType === 'organization' ? $nodeId : $rootId;
        if ($currentOrgId < 0 || ($currentOrgId > 0 && !isset($orgMap[$currentOrgId]))) {
            throw new InvalidArgumentException('组织节点不存在或已失效。');
        }
        if (!$this->organizationContainsAllowedStore($currentOrgId, $paths)) {
            throw new InvalidArgumentException('该组织不在当前账号的数据权限范围内。');
        }
        if ($rootId > 0 && !$this->isDescendantOrSelf($currentOrgId, $rootId, $orgMap)) {
            throw new InvalidArgumentException('该组织不在当前账号的数据权限范围内。');
        }

        $rows = [];
        foreach ($orgMap as $orgId => $org) {
            $isImmediateChild = $currentOrgId === 0
                ? (int)$org['pid'] === 0
                : (int)$org['pid'] === $currentOrgId;
            if (!$isImmediateChild || !$this->organizationContainsAllowedStore($orgId, $paths)) {
                continue;
            }
            $subtreeStoreIds = $this->storeIdsWithinOrganization($orgId, $paths);
            $rows[] = [
                'entityType' => 'organization',
                'entityId' => $orgId,
                'name' => (string)$org['name'],
                'storeCount' => count($subtreeStoreIds),
                'hasChildren' => true,
                '_storeIds' => $subtreeStoreIds,
            ];
        }
        foreach ($storeOrgMap as $storeId => $orgId) {
            if ($orgId !== $currentOrgId) {
                continue;
            }
            $rows[] = [
                'entityType' => 'store',
                'entityId' => $storeId,
                'name' => (string)$storeMap[$storeId]['name'],
                'storeCount' => 1,
                'hasChildren' => false,
                '_storeIds' => [$storeId],
            ];
        }
        usort($rows, static function (array $left, array $right): int {
            $type = strcmp((string)$left['entityType'], (string)$right['entityType']);
            if ($type !== 0) {
                return $type;
            }
            $name = strcmp((string)$left['name'], (string)$right['name']);
            return $name !== 0 ? $name : ((int)$left['entityId'] <=> (int)$right['entityId']);
        });

        $currentName = $currentOrgId === 0 ? '全部授权门店' : (string)$orgMap[$currentOrgId]['name'];
        return [
            'rootOrganizationId' => $rootId,
            'currentNode' => [
                'entityType' => $currentOrgId === 0 ? 'virtual' : 'organization',
                'entityId' => $currentOrgId,
                'name' => $currentName,
                'storeCount' => count($this->storeIdsWithinOrganization($currentOrgId, $paths)),
                '_storeIds' => $this->storeIdsWithinOrganization($currentOrgId, $paths),
            ],
            'breadcrumbs' => $this->organizationBreadcrumbs($rootId, $currentOrgId, $orgMap),
            'rows' => $rows,
            // This is a permission-trimmed picker projection, not a second
            // authorization source.  Clients may only select these nodes.
            'organizationTree' => $this->organizationTree($rootId, $orgMap, $storeMap, $storeOrgMap, $paths),
        ];
    }

    private function organizationMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = [
                'id' => $id,
                'pid' => max(0, (int)($row['pid'] ?? 0)),
                'name' => trim((string)($row['name'] ?? '')) ?: ('组织 ' . $id),
            ];
        }
        return $map;
    }

    private function storeMap(array $rows, array $allowedStoreIds): array
    {
        $allowed = array_fill_keys(array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds)))), true);
        $map = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || !isset($allowed[$id])) {
                continue;
            }
            $map[$id] = ['id' => $id, 'name' => trim((string)($row['name'] ?? '')) ?: ('门店 ' . $id)];
        }
        return $map;
    }

    private function storeOrganizationMap(array $rows, array $storeMap, array $orgMap): array
    {
        $map = [];
        foreach ($rows as $row) {
            $storeId = (int)($row['store_id'] ?? 0);
            $orgId = (int)($row['org_id'] ?? 0);
            if (isset($storeMap[$storeId], $orgMap[$orgId]) && !isset($map[$storeId])) {
                $map[$storeId] = $orgId;
            }
        }
        ksort($map, SORT_NUMERIC);
        return $map;
    }

    private function pathToRoot(int $orgId, array $orgMap): array
    {
        $path = [];
        $seen = [];
        $current = $orgId;
        for ($depth = 0; $depth < 128; $depth++) {
            if ($current <= 0) {
                return array_reverse($path);
            }
            if (isset($seen[$current]) || !isset($orgMap[$current])) {
                throw new InvalidArgumentException('组织层级数据不完整，暂时无法读取数仓。');
            }
            $seen[$current] = true;
            $path[] = $current;
            $current = (int)$orgMap[$current]['pid'];
        }
        throw new InvalidArgumentException('组织层级过深，暂时无法读取数仓。');
    }

    private function commonRootId(array $paths): int
    {
        if ($paths === []) {
            return 0;
        }
        $prefix = $paths[0];
        foreach (array_slice($paths, 1) as $path) {
            $limit = min(count($prefix), count($path));
            $matched = [];
            for ($index = 0; $index < $limit && $prefix[$index] === $path[$index]; $index++) {
                $matched[] = $prefix[$index];
            }
            $prefix = $matched;
        }
        return $prefix === [] ? 0 : (int)end($prefix);
    }

    private function organizationContainsAllowedStore(int $orgId, array $paths): bool
    {
        if ($orgId === 0) {
            return $paths !== [];
        }
        foreach ($paths as $path) {
            if (in_array($orgId, $path, true)) {
                return true;
            }
        }
        return false;
    }

    private function organizationContainsEveryAllowedStore(int $orgId, array $paths): bool
    {
        if ($paths === []) {
            return false;
        }
        foreach ($paths as $path) {
            if (!in_array($orgId, $path, true)) {
                return false;
            }
        }
        return true;
    }

    private function storeIdsWithinOrganization(int $orgId, array $paths): array
    {
        if ($orgId === 0) {
            return array_map('intval', array_keys($paths));
        }
        $ids = [];
        foreach ($paths as $storeId => $path) {
            if (in_array($orgId, $path, true)) {
                $ids[] = (int)$storeId;
            }
        }
        return $ids;
    }

    private function isDescendantOrSelf(int $orgId, int $rootId, array $orgMap): bool
    {
        if ($orgId === 0) {
            return false;
        }
        return in_array($rootId, $this->pathToRoot($orgId, $orgMap), true);
    }

    private function organizationBreadcrumbs(int $rootId, int $currentOrgId, array $orgMap): array
    {
        if ($currentOrgId === 0) {
            return [['entityType' => 'virtual', 'entityId' => 0, 'name' => '全部授权门店']];
        }
        $path = $this->pathToRoot($currentOrgId, $orgMap);
        $start = $rootId > 0 ? array_search($rootId, $path, true) : false;
        $visible = $start === false ? $path : array_slice($path, (int)$start);
        $breadcrumbs = $rootId === 0
            ? [['entityType' => 'virtual', 'entityId' => 0, 'name' => '全部授权门店']]
            : [];
        foreach ($visible as $id) {
            $breadcrumbs[] = ['entityType' => 'organization', 'entityId' => $id, 'name' => (string)$orgMap[$id]['name']];
        }
        return $breadcrumbs;
    }

    private function storeProjection(int $rootId, int $storeId, array $orgMap, array $storeMap, array $storeOrgMap, array $paths): array
    {
        $orgId = $storeOrgMap[$storeId];
        $breadcrumbs = $this->organizationBreadcrumbs($rootId, $orgId, $orgMap);
        $breadcrumbs[] = ['entityType' => 'store', 'entityId' => $storeId, 'name' => (string)$storeMap[$storeId]['name']];
        return [
            'rootOrganizationId' => $rootId,
            'currentNode' => [
                'entityType' => 'store',
                'entityId' => $storeId,
                'name' => (string)$storeMap[$storeId]['name'],
                'storeCount' => 1,
                '_storeIds' => [$storeId],
            ],
            'breadcrumbs' => $breadcrumbs,
            'rows' => [],
            'organizationTree' => $this->organizationTree($rootId, $orgMap, $storeMap, $storeOrgMap, $paths),
        ];
    }

    /**
     * @return array<int,array<string,mixed>> flat tree for a full-screen mobile picker
     */
    private function organizationTree(int $rootId, array $orgMap, array $storeMap, array $storeOrgMap, array $paths): array
    {
        $result = [];
        $rootName = $rootId > 0 && isset($orgMap[$rootId]) ? (string)$orgMap[$rootId]['name'] : '全部授权门店';
        $result[] = [
            'entityType' => $rootId > 0 ? 'organization' : 'virtual',
            'entityId' => $rootId,
            'name' => $rootName,
            'storeCount' => count($this->storeIdsWithinOrganization($rootId, $paths)),
            'hasChildren' => true,
            'depth' => 0,
            'parentEntityType' => '',
            'parentEntityId' => 0,
        ];
        $append = function (int $parentId, int $depth, string $parentEntityType) use (&$append, &$result, $orgMap, $storeMap, $storeOrgMap, $paths): void {
            $children = [];
            foreach ($orgMap as $orgId => $org) {
                if ((int)$org['pid'] !== $parentId || !$this->organizationContainsAllowedStore($orgId, $paths)) {
                    continue;
                }
                $children[] = ['type' => 'organization', 'id' => $orgId, 'name' => (string)$org['name']];
            }
            usort($children, static function (array $left, array $right): int {
                return strcmp($left['name'], $right['name']) ?: ($left['id'] <=> $right['id']);
            });
            foreach ($children as $child) {
                $id = (int)$child['id'];
                $result[] = [
                    'entityType' => 'organization',
                    'entityId' => $id,
                    'name' => (string)$child['name'],
                    'storeCount' => count($this->storeIdsWithinOrganization($id, $paths)),
                    'hasChildren' => true,
                    'depth' => $depth,
                    'parentEntityType' => $parentEntityType,
                    'parentEntityId' => $parentId,
                ];
                $append($id, $depth + 1, 'organization');
            }
            $stores = [];
            foreach ($storeOrgMap as $storeId => $orgId) {
                if ($orgId === $parentId && isset($storeMap[$storeId])) {
                    $stores[] = ['id' => $storeId, 'name' => (string)$storeMap[$storeId]['name']];
                }
            }
            usort($stores, static function (array $left, array $right): int {
                return strcmp($left['name'], $right['name']) ?: ($left['id'] <=> $right['id']);
            });
            foreach ($stores as $store) {
                $result[] = [
                    'entityType' => 'store',
                    'entityId' => (int)$store['id'],
                    'name' => (string)$store['name'],
                    'storeCount' => 1,
                    'hasChildren' => false,
                    'depth' => $depth,
                    'parentEntityType' => $parentEntityType,
                    'parentEntityId' => $parentId,
                ];
            }
        };
        $append($rootId, 1, $rootId > 0 ? 'organization' : 'virtual');
        return $result;
    }
}
