<?php
namespace app\services\organization;

use app\services\BaseServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I2 组织资源选择查询：organization / store / employee
 * 非超管按 organization_admin 解析门店范围，禁止泄漏范围外行
 */
class OrganizationResourceSelectorServices extends BaseServices
{
    public const MAX_LIMIT = 50;

    public function search(array $params, array $adminInfo = []): array
    {
        $resource = (string)($params['resource'] ?? 'employee');
        $keyword = trim((string)($params['keyword'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(self::MAX_LIMIT, max(1, (int)($params['limit'] ?? 20)));
        $ids = $this->normalizeIds($params['ids'] ?? []);
        $disabledIds = $this->normalizeIds($params['disabled_ids'] ?? []);
        $scopeOrgId = (int)($params['scope_org_id'] ?? 0);

        $allowedStoreIds = $this->resolveAllowedStoreIds($adminInfo);
        $allowedOrgIds = $this->resolveAllowedOrgIds($allowedStoreIds);

        switch ($resource) {
            case 'organization':
                return $this->searchOrganizations($keyword, $page, $limit, $ids, $disabledIds, $allowedOrgIds);
            case 'store':
                return $this->searchStores($keyword, $page, $limit, $ids, $disabledIds, $allowedStoreIds, $scopeOrgId);
            case 'employee':
                return $this->searchEmployees($keyword, $page, $limit, $ids, $disabledIds, $allowedStoreIds, $allowedOrgIds);
            case 'org_store_tree':
                return $this->searchOrgStoreTree($keyword, $ids, $disabledIds, $allowedStoreIds);
            default:
                throw new AdminException('不支持的资源类型');
        }
    }

    /**
     * 手机商家端等显式门店范围查询（null=禁止全量，按空权限 fail-closed）
     * @param int[]|null $allowedStoreIds
     */
    public function searchScoped(array $params, ?array $allowedStoreIds): array
    {
        $resource = (string)($params['resource'] ?? 'employee');
        $keyword = trim((string)($params['keyword'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(self::MAX_LIMIT, max(1, (int)($params['limit'] ?? 20)));
        $ids = $this->normalizeIds($params['ids'] ?? []);
        $disabledIds = $this->normalizeIds($params['disabled_ids'] ?? []);
        $scopeOrgId = (int)($params['scope_org_id'] ?? 0);

        if ($allowedStoreIds === null) {
            $allowedStoreIds = [];
        }
        $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        $allowedOrgIds = $this->resolveAllowedOrgIds($allowedStoreIds);

        switch ($resource) {
            case 'organization':
                return $this->searchOrganizations($keyword, $page, $limit, $ids, $disabledIds, $allowedOrgIds);
            case 'store':
                return $this->searchStores($keyword, $page, $limit, $ids, $disabledIds, $allowedStoreIds, $scopeOrgId);
            case 'employee':
                return $this->searchEmployees($keyword, $page, $limit, $ids, $disabledIds, $allowedStoreIds, $allowedOrgIds);
            case 'org_store_tree':
                return $this->searchOrgStoreTree($keyword, $ids, $disabledIds, $allowedStoreIds);
            default:
                throw new AdminException('不支持的资源类型');
        }
    }

    /**
     * 组织—门店完整树（供人员所属组织/任职门店/组织数据权限选择器）
     * @param int[]|null $allowedStoreIds
     */
    protected function searchOrgStoreTree(
        string $keyword,
        array $ids,
        array $disabledIds,
        ?array $allowedStoreIds
    ): array {
        if ($allowedStoreIds === null) {
            $allowedStoreIds = Db::name('system_store')
                ->where('is_del', 0)
                ->where('is_show', 1)
                ->column('id');
            $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        }
        if ($ids) {
            // ids 回显：可按 org:12 / store:5 或纯数字组织 ID
            $wantOrg = [];
            $wantStore = [];
            foreach ($ids as $raw) {
                // normalizeIds 已转 int；回显走 label 接口时用 resource=organization|store
                $wantOrg[] = (int)$raw;
            }
            unset($wantOrg, $wantStore);
        }
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        // 人员所属组织选择需要保留无门店的组织节点（如董事会、总部中心）
        $tree = $scope->buildPickerTree($allowedStoreIds ?: [], true);
        $tree = $this->decoratePickerTree($tree, $disabledIds);
        if ($keyword !== '') {
            $tree = $this->filterPickerTreeByKeyword($tree, $keyword);
        }
        return [
            'resource' => 'org_store_tree',
            'count' => $this->countPickerTreeNodes($tree),
            'list' => [],
            'tree' => $tree,
            'page' => 1,
            'limit' => 0,
        ];
    }

    /**
     * @param array $nodes
     * @param int[] $disabledIds
     */
    protected function decoratePickerTree(array $nodes, array $disabledIds): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $type = (string)($node['node_type'] ?? '');
            $id = (int)($node['id'] ?? 0);
            $children = $this->decoratePickerTree($node['children'] ?? [], $disabledIds);
            $isStore = $type === 'store';
            $row = [
                'id' => $id,
                'name' => (string)($node['name'] ?? ''),
                'label' => (string)($node['name'] ?? ''),
                'node_type' => $isStore ? 'store' : 'org',
                'value' => $id,
                'org_id' => $isStore ? (int)($node['org_id'] ?? 0) : $id,
                'store_id' => $isStore ? $id : 0,
                'disabled' => !empty($node['disabled']) || ($isStore && in_array($id, $disabledIds, true)),
                'children' => $children,
                'phone' => (string)($node['phone'] ?? ''),
                'desc' => (string)($node['desc'] ?? ''),
            ];
            // 门店节点补所属组织
            if ($isStore && $row['org_id'] <= 0) {
                $bind = Db::name('organization_store')->where('store_id', $id)->value('org_id');
                $row['org_id'] = (int)$bind;
            }
            $out[] = $row;
        }
        return $out;
    }

    protected function filterPickerTreeByKeyword(array $nodes, string $keyword): array
    {
        $kw = mb_strtolower(trim($keyword));
        if ($kw === '') {
            return $nodes;
        }
        $out = [];
        foreach ($nodes as $node) {
            $children = $this->filterPickerTreeByKeyword($node['children'] ?? [], $keyword);
            $name = mb_strtolower((string)($node['name'] ?? ''));
            $phone = (string)($node['phone'] ?? '');
            $hit = (mb_strpos($name, $kw) !== false) || ($phone !== '' && strpos($phone, $keyword) !== false);
            if ($hit || $children) {
                $node['children'] = $children;
                if ($hit) {
                    $node['_force_open'] = true;
                }
                $out[] = $node;
            }
        }
        return $out;
    }

    protected function countPickerTreeNodes(array $nodes): int
    {
        $n = 0;
        foreach ($nodes as $node) {
            $n += 1;
            $n += $this->countPickerTreeNodes($node['children'] ?? []);
        }
        return $n;
    }

    /**
     * null = 超管全量；[] = 无权限；int[] = 限定门店
     * @return int[]|null
     */
    protected function resolveAllowedStoreIds(array $adminInfo): ?array
    {
        /** @var OrganizationWorkspaceWriteGate $gate */
        $gate = app()->make(OrganizationWorkspaceWriteGate::class);
        if ($gate->isSuperAdmin($adminInfo)) {
            return null;
        }
        // 具备平台「人员维护」权限时，选择器与组织树只读一致，可读全量，避免新建人员选不到组织
        if (!empty($gate->assertPlatformStaffMaintainPermission($adminInfo)['ok'])) {
            return null;
        }
        $adminId = (int)($adminInfo['id'] ?? 0);
        if ($adminId <= 0) {
            return [];
        }
        $orgAdminIds = Db::name('organization_admin')
            ->where('admin_id', $adminId)
            ->where('is_del', 0)
            ->column('id');
        if (!$orgAdminIds) {
            return [];
        }
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        $storeIds = [];
        foreach ($orgAdminIds as $oaId) {
            $storeIds = array_merge($storeIds, $scope->getAdminResolvedStoreIds((int)$oaId));
        }
        return array_values(array_unique(array_filter(array_map('intval', $storeIds))));
    }

    /**
     * @param int[]|null $allowedStoreIds
     * @return int[]|null
     */
    protected function resolveAllowedOrgIds(?array $allowedStoreIds): ?array
    {
        if ($allowedStoreIds === null) {
            return null;
        }
        if (!$allowedStoreIds) {
            return [];
        }
        $directOrgIds = Db::name('organization_store')
            ->whereIn('store_id', $allowedStoreIds)
            ->column('org_id');
        $directOrgIds = array_values(array_unique(array_filter(array_map('intval', $directOrgIds))));
        if (!$directOrgIds) {
            return [];
        }
        $pidMap = Db::name('organization')->where('is_del', 0)->column('pid', 'id');
        $allowed = [];
        foreach ($directOrgIds as $oid) {
            $cur = $oid;
            $guard = 0;
            while ($cur > 0 && $guard++ < 128) {
                $allowed[$cur] = true;
                $cur = (int)($pidMap[$cur] ?? 0);
            }
        }
        return array_map('intval', array_keys($allowed));
    }

    /**
     * @param int[]|null $allowedOrgIds
     */
    protected function searchOrganizations(
        string $keyword,
        int $page,
        int $limit,
        array $ids,
        array $disabledIds,
        ?array $allowedOrgIds
    ): array {
        if ($allowedOrgIds !== null && !$allowedOrgIds) {
            return ['resource' => 'organization', 'count' => 0, 'list' => [], 'page' => $page, 'limit' => $limit];
        }
        if ($ids && $allowedOrgIds !== null) {
            $ids = array_values(array_intersect($ids, $allowedOrgIds));
            if (!$ids) {
                return ['resource' => 'organization', 'count' => 0, 'list' => [], 'page' => $page, 'limit' => $limit];
            }
        }
        $q = Db::name('organization')->where('is_del', 0);
        if ($allowedOrgIds !== null) {
            $q->whereIn('id', $allowedOrgIds);
        }
        if ($keyword !== '') {
            $q->whereLike('name', '%' . $keyword . '%');
        }
        if ($ids) {
            $q->whereIn('id', $ids);
        }
        $count = (int)(clone $q)->count();
        $list = $q->field('id,name,pid,sort')->order('sort', 'asc')->order('id', 'asc')
            ->page($page, $limit)->select()->toArray();
        foreach ($list as &$row) {
            $row['value'] = (int)$row['id'];
            $row['label'] = (string)$row['name'];
            $row['disabled'] = in_array((int)$row['id'], $disabledIds, true);
        }
        unset($row);
        return ['resource' => 'organization', 'count' => $count, 'list' => $list, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @param int[]|null $allowedStoreIds
     */
    protected function searchStores(
        string $keyword,
        int $page,
        int $limit,
        array $ids,
        array $disabledIds,
        ?array $allowedStoreIds,
        int $scopeOrgId = 0
    ): array {
        if ($scopeOrgId > 0) {
            /** @var OrganizationScopeService $scope */
            $scope = app()->make(OrganizationScopeService::class);
            $orgStoreIds = $scope->getOrgStoreIds($scopeOrgId, true);
            if ($allowedStoreIds === null) {
                $allowedStoreIds = $orgStoreIds;
            } else {
                $allowedStoreIds = array_values(array_intersect($allowedStoreIds, $orgStoreIds));
            }
        }
        if ($allowedStoreIds !== null && !$allowedStoreIds) {
            return ['resource' => 'store', 'count' => 0, 'list' => [], 'page' => $page, 'limit' => $limit];
        }
        if ($ids && $allowedStoreIds !== null) {
            $ids = array_values(array_intersect($ids, $allowedStoreIds));
            if (!$ids) {
                return ['resource' => 'store', 'count' => 0, 'list' => [], 'page' => $page, 'limit' => $limit];
            }
        }
        $q = Db::name('system_store')->where('is_del', 0)->where('is_show', 1);
        if ($allowedStoreIds !== null) {
            $q->whereIn('id', $allowedStoreIds);
        }
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->whereLike('name', '%' . $keyword . '%')->whereOr('phone', 'like', '%' . $keyword . '%');
            });
        }
        if ($ids) {
            $q->whereIn('id', $ids);
        }
        $count = (int)(clone $q)->count();
        $list = $q->field('id,name,phone,address')->order('id', 'asc')->page($page, $limit)->select()->toArray();
        $orgNameByStore = $this->mapStoreOrgNames(array_column($list, 'id'));
        foreach ($list as &$row) {
            $sid = (int)$row['id'];
            $orgMeta = $orgNameByStore[$sid] ?? ['org_id' => 0, 'org_name' => ''];
            $row['value'] = $sid;
            $row['label'] = (string)$row['name'];
            $row['org_id'] = (int)$orgMeta['org_id'];
            $row['org_name'] = (string)$orgMeta['org_name'];
            $row['disabled'] = in_array($sid, $disabledIds, true);
        }
        unset($row);
        return ['resource' => 'store', 'count' => $count, 'list' => $list, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @param int[] $storeIds
     * @return array<int, array{org_id:int,org_name:string}>
     */
    protected function mapStoreOrgNames(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds) {
            return [];
        }
        $binds = Db::name('organization_store')
            ->whereIn('store_id', $storeIds)
            ->field('store_id,org_id')
            ->select()
            ->toArray();
        if (!$binds) {
            return [];
        }
        $orgIds = array_values(array_unique(array_filter(array_map('intval', array_column($binds, 'org_id')))));
        $orgNames = $orgIds
            ? Db::name('organization')->whereIn('id', $orgIds)->where('is_del', 0)->column('name', 'id')
            : [];
        $map = [];
        foreach ($binds as $bind) {
            $sid = (int)$bind['store_id'];
            if (isset($map[$sid])) {
                continue;
            }
            $oid = (int)$bind['org_id'];
            $map[$sid] = [
                'org_id' => $oid,
                'org_name' => (string)($orgNames[$oid] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * @param int[]|null $allowedStoreIds
     * @param int[]|null $allowedOrgIds
     */
    protected function searchEmployees(
        string $keyword,
        int $page,
        int $limit,
        array $ids,
        array $disabledIds,
        ?array $allowedStoreIds,
        ?array $allowedOrgIds
    ): array {
        if ($allowedStoreIds !== null && !$allowedStoreIds && $allowedOrgIds !== null && !$allowedOrgIds) {
            return ['resource' => 'employee', 'count' => 0, 'list' => [], 'page' => $page, 'limit' => $limit];
        }

        $scopedEmpIds = null;
        if ($allowedStoreIds !== null) {
            $fromStaff = [];
            if ($allowedStoreIds) {
                $fromStaff = Db::name('system_store_staff')
                    ->whereIn('store_id', $allowedStoreIds)
                    ->where('is_del', 0)->where('status', 1)
                    ->where('employee_id', '>', 0)
                    ->column('employee_id');
            }
            $fromOrg = [];
            if ($allowedOrgIds) {
                $fromOrg = Db::name('organization_employee')
                    ->whereIn('org_id', $allowedOrgIds)
                    ->where('is_del', 0)->where('status', 1)
                    ->where('employee_id', '>', 0)
                    ->column('employee_id');
            }
            $scopedEmpIds = array_values(array_unique(array_filter(array_map('intval', array_merge($fromStaff, $fromOrg)))));
            if (!$scopedEmpIds) {
                return ['resource' => 'employee', 'count' => 0, 'list' => [], 'page' => $page, 'limit' => $limit];
            }
            if ($ids) {
                $ids = array_values(array_intersect($ids, $scopedEmpIds));
                if (!$ids) {
                    return ['resource' => 'employee', 'count' => 0, 'list' => [], 'page' => $page, 'limit' => $limit];
                }
            }
        }

        $q = Db::name('employee')->where('is_del', 0);
        if ($scopedEmpIds !== null) {
            $q->whereIn('id', $scopedEmpIds);
        }
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->whereLike('name', '%' . $keyword . '%')->whereOr('phone', 'like', '%' . $keyword . '%');
            });
        }
        if ($ids) {
            $q->whereIn('id', $ids);
        }
        $count = (int)(clone $q)->count();
        $list = $q->field('id,name,phone,avatar,status')->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $empIds = array_column($list, 'id');
        $assignMap = [];
        if ($empIds) {
            $assignQ = Db::name('system_store_staff')->alias('s')
                ->leftJoin('system_store st', 'st.id = s.store_id')
                ->whereIn('s.employee_id', $empIds)
                ->where('s.is_del', 0)->where('s.status', 1);
            if ($allowedStoreIds !== null) {
                if (!$allowedStoreIds) {
                    $assignQ->where('s.id', 0);
                } else {
                    $assignQ->whereIn('s.store_id', $allowedStoreIds);
                }
            }
            $assigns = $assignQ->field('s.employee_id,s.store_id,st.name AS store_name')->select()->toArray();
            foreach ($assigns as $a) {
                $eid = (int)$a['employee_id'];
                $assignMap[$eid][] = [
                    'store_id' => (int)$a['store_id'],
                    'store_name' => (string)($a['store_name'] ?? ''),
                ];
            }
        }
        foreach ($list as &$row) {
            $eid = (int)$row['id'];
            $row['value'] = $eid;
            $row['label'] = (string)$row['name'];
            $row['phone_masked'] = $this->maskPhone((string)$row['phone']);
            $row['assignments'] = $assignMap[$eid] ?? [];
            $row['assignment_summary'] = $row['assignments']
                ? implode('、', array_map(static function ($x) {
                    return $x['store_name'] !== '' ? $x['store_name'] : ('门店#' . $x['store_id']);
                }, $row['assignments']))
                : '无门店任职';
            $row['disabled'] = in_array($eid, $disabledIds, true) || (int)($row['status'] ?? 1) !== 1;
        }
        unset($row);
        return ['resource' => 'employee', 'count' => $count, 'list' => $list, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    protected function normalizeIds($raw): array
    {
        if (!is_array($raw)) {
            $raw = $raw !== '' && $raw !== null ? explode(',', (string)$raw) : [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    protected function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        if (strlen($phone) < 7) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
}
