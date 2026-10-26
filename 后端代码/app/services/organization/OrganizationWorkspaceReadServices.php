<?php
namespace app\services\organization;

use app\services\BaseServices;
use app\services\store\SystemStoreStaffServices;
use think\facade\Db;

/**
 * 组织工作台只读（O3）
 * - 始终读取新组织表，不因 source mode=legacy 回退 region_manage/agent
 * - 禁止写入业务表 / 审计表 / Redis / 配置
 */
class OrganizationWorkspaceReadServices extends BaseServices
{
    protected const MAX_LIMIT = 50;
    protected const DEFAULT_LIMIT = 10;

    /** @var OrganizationManageServices */
    protected $manageServices;
    /** @var OrganizationScopeService */
    protected $scopeService;

    public function __construct(
        OrganizationManageServices $manageServices,
        OrganizationScopeService $scopeService
    ) {
        $this->manageServices = $manageServices;
        $this->scopeService = $scopeService;
    }

    /**
     * 左侧导航只返回组织结构。组织统计由详情概况接口按需读取，避免每次展开
     * 树都扫描全部门店、员工和负责人。
     */
    public function getTree(): array
    {
        return $this->manageServices->getTree();
    }

    /**
     * 组织编辑弹窗的统计维度下拉。
     * 选项完全来自已落库的报表统计维度记录；这里不推断组织层级，也不定义维度白名单。
     *
     * @return array{options:array<int,array{value:string,label:string}>,current_value:string}
     */
    public function getStatisticDimensions(int $orgId = 0): array
    {
        $options = Db::name('cashier_v3_report_organization_dimension')
            ->where('tenant_id', '0')
            ->field('dimension_code,MIN(display_order) AS display_order,MIN(id) AS first_id')
            ->group('dimension_code')
            ->orderRaw('MIN(display_order) ASC, MIN(id) ASC')
            ->select()
            ->toArray();

        $currentValue = '';
        if ($orgId > 0) {
            $this->assertOrgId($orgId);
            $today = date('Y-m-d');
            $currentValue = (string)(Db::name('cashier_v3_report_organization_dimension')
                ->where('tenant_id', '0')
                ->where('organization_id', (string)$orgId)
                ->where('enabled', 1)
                ->where('valid_from', '<=', $today)
                ->where(function ($query) use ($today): void {
                    $query->whereNull('valid_to')->whereOr('valid_to', '>=', $today);
                })
                ->order('valid_from', 'desc')
                ->order('id', 'desc')
                ->value('dimension_code') ?: '');
        }

        return [
            'options' => array_map(static function (array $row): array {
                $value = (string)($row['dimension_code'] ?? '');
                $labels = [
                    'company' => '分公司',
                    'city_manager' => '城市经理',
                ];
                return ['value' => $value, 'label' => $labels[$value] ?? $value];
            }, $options),
            'current_value' => $currentValue,
        ];
    }

    public function getOverview(int $orgId): array
    {
        $orgId = $this->assertOrgId($orgId);
        $orgRows = $this->loadOrgRows();
        $orgMap = [];
        foreach ($orgRows as $row) {
            $orgMap[(int)$row['id']] = $row;
        }
        $org = $orgMap[$orgId];
        $stats = $this->buildOrgStatBundle($orgRows);
        $breadcrumb = $this->buildBreadcrumb($orgId, $orgMap);

        $childOrgs = [];
        foreach ($orgRows as $row) {
            if ((int)($row['pid'] ?? 0) !== $orgId) {
                continue;
            }
            $cid = (int)$row['id'];
            $childOrgs[] = [
                'id' => $cid,
                'name' => (string)($row['name'] ?? ''),
                'all_store_count' => (int)($stats['allStoreCount'][$cid] ?? 0),
                'employee_count' => (int)($stats['employeeCount'][$cid] ?? 0),
                'attention_count' => (int)($stats['attentionCount'][$cid] ?? 0),
                'leader_count' => (int)($stats['leaderCount'][$cid] ?? 0),
            ];
        }

        $leaders = $this->loadOrgLeaders([$orgId]);
        $leaderList = $leaders[$orgId] ?? [];
        $leaderAnomalies = $stats['leaderAnomaliesByOrg'][$orgId] ?? [];

        $positionDist = $this->buildPositionDistribution(
            $stats['storeIdsByOrgAll'][$orgId] ?? []
        );
        $focusStores = $this->buildFocusStores(
            $stats['storeIdsByOrgAll'][$orgId] ?? [],
            $orgMap,
            4
        );

        $attentionDetail = [
            'no_leader_org_count' => (int)($stats['noLeaderOrgCount'][$orgId] ?? 0),
            'no_manager_store_count' => (int)($stats['noManagerStoreCount'][$orgId] ?? 0),
            'total' => (int)($stats['attentionCount'][$orgId] ?? 0),
            'invalid_leader_count' => count($leaderAnomalies),
        ];

        return [
            'scene' => 'workspace',
            'org_id' => $orgId,
            'org_name' => (string)($org['name'] ?? ''),
            'pid' => (int)($org['pid'] ?? 0),
            'sort' => (int)($org['sort'] ?? 0),
            'update_time' => (int)($org['update_time'] ?? 0),
            'update_time_text' => $this->formatTime((int)($org['update_time'] ?? 0)),
            'breadcrumb' => $breadcrumb,
            'child_orgs' => $childOrgs,
            'direct_store_count' => (int)($stats['directStoreCount'][$orgId] ?? 0),
            'all_store_count' => (int)($stats['allStoreCount'][$orgId] ?? 0),
            'store_count' => (int)($stats['allStoreCount'][$orgId] ?? 0),
            'employee_count' => (int)($stats['employeeCount'][$orgId] ?? 0),
            'leader_count' => count($leaderList),
            'leaders' => $leaderList,
            'leader_anomalies' => $leaderAnomalies,
            'attention' => $attentionDetail,
            'position_distribution' => $positionDist,
            'focus_stores' => $focusStores,
            'admin_count' => (int)($stats['adminCount'][$orgId] ?? 0),
            'admins' => [],
            'need_migrate' => false,
            'legacy_region_id' => 0,
        ];
    }

    public function getStores(
        int $orgId,
        string $scope = 'all',
        string $keyword = '',
        string $status = '',
        string $attention = '',
        int $page = 1,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $orgId = $this->assertOrgId($orgId);
        [$page, $limit] = $this->normalizePage($page, $limit);
        $scope = $scope === 'direct' ? 'direct' : 'all';
        $orgIds = $scope === 'direct'
            ? [$orgId]
            : $this->collectOrgIdsIncl($orgId);

        $query = Db::name('organization_store')->alias('os')
            ->join('system_store st', 'st.id = os.store_id')
            ->whereIn('os.org_id', $orgIds)
            ->where('st.is_del', 0);

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('st.name', 'like', $like)
                    ->whereOr('st.phone', 'like', $like)
                    ->whereOr('st.address', 'like', $like)
                    ->whereOr('st.detailed_address', 'like', $like);
            });
        }
        $status = trim($status);
        if ($status === 'open') {
            $query->where('st.is_show', 1);
        } elseif ($status === 'closed') {
            $query->where('st.is_show', 0);
        }

        $attention = trim($attention);
        if ($attention === 'NO_MANAGER' || $attention === '1' || $attention === 'attention') {
            // 营业中且无有效店长/副店长
            $query->where('st.is_show', 1)
                ->whereNotExists(function ($sub) {
                    $sub->table('eb_system_store_staff')->alias('s')
                        ->join('eb_employee e', 'e.id = s.employee_id')
                        ->leftJoin('eb_position p', 'p.id = s.position')
                        ->whereRaw('s.store_id = st.id')
                        ->where('s.status', 1)
                        ->where('s.is_del', 0)
                        ->where('s.employee_id', '>', 0)
                        ->where('e.status', 1)
                        ->where('e.is_del', 0)
                        ->where(function ($w) {
                            $w->where('s.is_manager', 1)
                                ->whereOr('s.is_butler', 1)
                                ->whereOr('p.name', 'in', ['店长', '副店', '副店长']);
                        })
                        ->field('s.id');
                });
        }

        $count = (int)(clone $query)->count('st.id');
        $rows = (clone $query)
            ->field('st.id,st.name,st.image,st.phone,st.address,st.detailed_address,st.is_show,os.org_id')
            ->order('st.id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $pageStoreIds = array_map(static function ($r) {
            return (int)$r['id'];
        }, $rows);
        $orgNameMap = $this->orgNameMap();
        $staffBundle = $this->loadStaffBundleForStores($pageStoreIds);

        $list = [];
        foreach ($rows as $store) {
            $sid = (int)$store['id'];
            $boundOrgId = (int)$store['org_id'];
            $isShow = (int)($store['is_show'] ?? 0) === 1;
            $managers = $staffBundle['managersByStore'][$sid] ?? [];
            $attentionCodes = [];
            if ($isShow && !$managers) {
                $attentionCodes[] = 'NO_MANAGER';
            }
            $list[] = [
                'id' => $sid,
                'name' => (string)($store['name'] ?? ''),
                'image' => (string)($store['image'] ?? ''),
                'phone' => (string)($store['phone'] ?? ''),
                'address' => trim(((string)($store['address'] ?? '')) . ' ' . ((string)($store['detailed_address'] ?? ''))),
                'org_id' => $boundOrgId,
                'org_name' => (string)($orgNameMap[$boundOrgId] ?? ''),
                'is_direct' => $boundOrgId === $orgId,
                'business_status' => $isShow ? 'open' : 'closed',
                'employee_count' => count($staffBundle['employeeIdsByStore'][$sid] ?? []),
                'managers' => $managers,
                'attention_codes' => $attentionCodes,
            ];
        }

        return compact('list', 'count', 'page', 'limit');
    }

    public function getEmployees(
        int $orgId,
        string $scope = 'all',
        string $keyword = '',
        int $storeId = 0,
        string $role = '',
        int $page = 1,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $orgId = $this->assertOrgId($orgId);
        [$page, $limit] = $this->normalizePage($page, $limit);
        $scope = $scope === 'direct' ? 'direct' : 'all';
        $orgIds = $scope === 'direct'
            ? [$orgId]
            : $this->collectOrgIdsIncl($orgId);

        $base = Db::name('employee')->alias('e')
            ->join('system_store_staff s', 's.employee_id = e.id')
            ->join('system_store st', 'st.id = s.store_id')
            ->join('organization_store os', 'os.store_id = st.id')
            ->whereIn('os.org_id', $orgIds)
            ->where('st.is_del', 0)
            ->where('s.status', 1)
            ->where('s.is_del', 0)
            ->where('s.employee_id', '>', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0);

        if ($storeId > 0) {
            $base->where('s.store_id', $storeId);
        }

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $base->where(function ($q) use ($like, $keyword) {
                $q->where('e.name', 'like', $like)
                    ->whereOr('e.phone', 'like', '%' . $keyword . '%')
                    ->whereOr('st.name', 'like', $like);
            });
        }

        $role = trim($role);
        if ($role !== '') {
            $base->leftJoin('position p', 'p.id = s.position');
            $this->applyRoleFilter($base, $role);
        }

        // 门店任职、组织直属、组织管理员授权统一按 employee_id 去重并分页。
        // 组织管理员可以是总部岗位，未必存在门店任职或直属名册关系；漏掉这条
        // 有效授权会使组织树的“找人”无法定位其明确可管理的组织。
        $includeOrganizationRelationships = $storeId <= 0 && $role === '';
        $orgIdSql = implode(',', array_map('intval', $orgIds));
        if ($orgIdSql === '') {
            return ['list' => [], 'count' => 0, 'page' => $page, 'limit' => $limit];
        }

        $staffWhere = "st.is_del=0 AND s.status=1 AND s.is_del=0 AND s.employee_id>0 AND e.status=1 AND e.is_del=0 AND os.org_id IN ({$orgIdSql})";
        if ($storeId > 0) {
            $staffWhere .= ' AND s.store_id=' . (int)$storeId;
        }
        $binds = [];
        $kwSql = '';
        if ($keyword !== '') {
            $kwSql = ' AND (e.name LIKE :kw1 OR e.phone LIKE :kw2 OR st.name LIKE :kw3)';
            $like = '%' . $keyword . '%';
            $binds['kw1'] = $like;
            $binds['kw2'] = '%' . $keyword . '%';
            $binds['kw3'] = $like;
        }
        $roleJoin = '';
        $roleSql = '';
        if ($role !== '') {
            $roleJoin = ' LEFT JOIN eb_position p ON p.id = s.position ';
            if ($role === '收银员') {
                $roleSql = ' AND s.is_cashier=1 ';
            } elseif ($role === '客服') {
                $roleSql = ' AND s.is_customer=1 ';
            } elseif (in_array($role, ['店长', '副店长', '副店'], true)) {
                $roleSql = " AND (s.is_manager=1 OR s.is_butler=1 OR p.name IN ('店长','副店','副店长')) ";
            } elseif ($role === '手艺人') {
                $roleSql = " AND (p.name LIKE '%手艺人%' OR p.name LIKE '%美容师%') ";
            } else {
                $roleSql = ' AND p.name LIKE :role_name ';
                $binds['role_name'] = '%' . $role . '%';
            }
        }

        $staffPart = "SELECT e.id AS employee_id
            FROM eb_employee e
            INNER JOIN eb_system_store_staff s ON s.employee_id = e.id
            INNER JOIN eb_system_store st ON st.id = s.store_id
            INNER JOIN eb_organization_store os ON os.store_id = st.id
            {$roleJoin}
            WHERE {$staffWhere}{$kwSql}{$roleSql}";

        if ($includeOrganizationRelationships) {
            $directKw = '';
            if ($keyword !== '') {
                $directKw = ' AND (e2.name LIKE :dkw1 OR e2.phone LIKE :dkw2)';
                $binds['dkw1'] = '%' . $keyword . '%';
                $binds['dkw2'] = '%' . $keyword . '%';
            }
            $statusFilter = $this->organizationEmployeeHasStatusColumn()
                ? ' AND oe.status=1'
                : '';
            $directPart = "SELECT oe.employee_id AS employee_id
                FROM eb_organization_employee oe
                INNER JOIN eb_employee e2 ON e2.id = oe.employee_id
                WHERE oe.org_id IN ({$orgIdSql}) AND oe.is_del=0{$statusFilter}
                  AND e2.status=1 AND e2.is_del=0{$directKw}";

            $adminKw = '';
            if ($keyword !== '') {
                $adminKw = ' AND (e3.name LIKE :akw1 OR e3.phone LIKE :akw2)';
                $binds['akw1'] = '%' . $keyword . '%';
                $binds['akw2'] = '%' . $keyword . '%';
            }
            $adminPart = "SELECT oa.employee_id AS employee_id
                FROM eb_organization_admin oa
                INNER JOIN eb_employee e3 ON e3.id = oa.employee_id
                INNER JOIN eb_system_admin sa ON sa.id = oa.admin_id AND sa.employee_id = oa.employee_id
                WHERE oa.org_id IN ({$orgIdSql}) AND oa.is_del=0
                  AND oa.employee_id>0 AND oa.admin_id>0
                  AND e3.status=1 AND e3.is_del=0
                  AND sa.status=1 AND sa.is_del=0{$adminKw}";
            $unionSql = "({$staffPart}) UNION ({$directPart}) UNION ({$adminPart})";
        } else {
            $unionSql = "({$staffPart})";
        }

        $countRow = Db::query("SELECT COUNT(*) AS cnt FROM (SELECT DISTINCT employee_id FROM ({$unionSql}) u) c", $binds);
        $count = (int)($countRow[0]['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;
        $pageRows = Db::query(
            "SELECT DISTINCT employee_id FROM ({$unionSql}) u ORDER BY employee_id ASC LIMIT {$offset}, {$limit}",
            $binds
        );
        $pageEmployeeIds = array_map(static function ($r) {
            return (int)$r['employee_id'];
        }, $pageRows ?: []);
        if (!$pageEmployeeIds) {
            return ['list' => [], 'count' => $count, 'page' => $page, 'limit' => $limit];
        }

        $empRows = Db::name('employee')
            ->whereIn('id', $pageEmployeeIds)
            ->field('id,name,phone,avatar,avatar_type,status')
            ->select()
            ->toArray();
        $empMap = [];
        foreach ($empRows as $er) {
            $empMap[(int)$er['id']] = $er;
        }

        // 当前组织范围内任职（仅有效门店）
        $scopeAssignRows = Db::name('system_store_staff')->alias('s')
            ->join('system_store st', 'st.id = s.store_id')
            ->join('organization_store os', 'os.store_id = st.id')
            ->leftJoin('organization o', 'o.id = os.org_id')
            ->whereIn('os.org_id', $orgIds)
            ->whereIn('s.employee_id', $pageEmployeeIds)
            ->where('st.is_del', 0)
            ->where('s.status', 1)
            ->where('s.is_del', 0)
            ->where('s.employee_id', '>', 0)
            ->field('s.id as staff_id,s.store_id,s.employee_id,s.position,s.is_manager,s.is_butler,s.is_cashier,s.is_customer,st.name as store_name,os.org_id,o.name as organization_name')
            ->order('s.employee_id', 'asc')
            ->order('s.id', 'asc')
            ->select()
            ->toArray();

        // 全局任职数量（排除已删除门店），仅当页 employee
        $globalRows = Db::name('system_store_staff')->alias('s')
            ->join('system_store st', 'st.id = s.store_id')
            ->whereIn('s.employee_id', $pageEmployeeIds)
            ->where('st.is_del', 0)
            ->where('s.status', 1)
            ->where('s.is_del', 0)
            ->where('s.employee_id', '>', 0)
            ->field('s.employee_id, COUNT(*) as cnt')
            ->group('s.employee_id')
            ->select()
            ->toArray();
        $totalAssignCount = [];
        foreach ($globalRows as $gr) {
            $totalAssignCount[(int)$gr['employee_id']] = (int)$gr['cnt'];
        }

        // 岗位权威来源是 staff_job_position；system_store_staff.position 仅作旧数据兼容。
        // 这里按当前页一次性读取，避免列表逐员工 N+1 查询。
        $jobRowsByStaff = [];
        $scopeStaffIds = array_values(array_filter(array_map(static function ($row) {
            return (int)($row['staff_id'] ?? 0);
        }, $scopeAssignRows)));
        if ($scopeStaffIds) {
            $jobRows = Db::name('staff_job_position')->alias('j')
                ->leftJoin('position p', 'p.id = j.position_id')
                ->whereIn('j.staff_id', $scopeStaffIds)
                ->where('j.is_del', 0)
                ->where('j.status', 1)
                ->where('j.end_time', 0)
                ->field('j.id,j.staff_id,j.employee_id,j.store_id,j.position_id,j.start_time,j.end_time,j.status,p.name AS position_name,p.status AS position_status')
                ->order('j.staff_id', 'asc')
                ->order('j.id', 'asc')
                ->select()
                ->toArray();
            foreach ($jobRows as $job) {
                $sid = (int)($job['staff_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $job['id'] = (int)($job['id'] ?? 0);
                $job['employee_id'] = (int)($job['employee_id'] ?? 0);
                $job['store_id'] = (int)($job['store_id'] ?? 0);
                $job['position_id'] = (int)($job['position_id'] ?? 0);
                $job['position_name'] = $this->normalizePositionLabel((string)($job['position_name'] ?? ''));
                $job['position_status'] = (int)($job['position_status'] ?? 0);
                $jobRowsByStaff[$sid][] = $job;
            }
        }
        $jobRowsByDirectEmployee = [];
        if ($pageEmployeeIds && $includeOrganizationRelationships) {
            $directJobRows = Db::name('staff_job_position')->alias('j')
                ->leftJoin('position p', 'p.id = j.position_id')
                ->whereIn('j.employee_id', $pageEmployeeIds)
                ->where('j.staff_id', 0)
                ->where('j.is_del', 0)
                ->where('j.status', 1)
                ->where('j.end_time', 0)
                ->field('j.id,j.staff_id,j.employee_id,j.store_id,j.position_id,j.start_time,j.end_time,j.status,p.name AS position_name,p.status AS position_status')
                ->order('j.employee_id', 'asc')
                ->order('j.id', 'asc')
                ->select()
                ->toArray();
            foreach ($directJobRows as $job) {
                $eid = (int)($job['employee_id'] ?? 0);
                if ($eid <= 0) {
                    continue;
                }
                $job['id'] = (int)($job['id'] ?? 0);
                $job['employee_id'] = $eid;
                $job['store_id'] = (int)($job['store_id'] ?? 0);
                $job['position_id'] = (int)($job['position_id'] ?? 0);
                $job['position_name'] = $this->normalizePositionLabel((string)($job['position_name'] ?? ''));
                $job['position_status'] = (int)($job['position_status'] ?? 0);
                $jobRowsByDirectEmployee[$eid][] = $job;
            }
        }

        $positionNameMap = $this->loadPositionNameMap();
        $byEmployee = [];
        foreach ($scopeAssignRows as $row) {
            $eid = (int)$row['employee_id'];
            if (!isset($byEmployee[$eid])) {
                $emp = $empMap[$eid] ?? [];
                $byEmployee[$eid] = [
                    'employee_id' => $eid,
                    'name' => (string)($emp['name'] ?? ''),
                    'avatar' => (string)($emp['avatar'] ?? ''),
                    'avatar_type' => (int)($emp['avatar_type'] ?? 0),
                    'phone_masked' => $this->maskPhone((string)($emp['phone'] ?? '')),
                    'status' => (int)($emp['status'] ?? 0) === 1 ? 1 : 0,
                    'roles' => [],
                    'assignments' => [],
                    'direct_memberships' => [],
                    'admin_grants' => [],
                ];
            }
            $posName = $this->normalizePositionLabel(
                (string)($positionNameMap[(int)($row['position'] ?? 0)] ?? '')
            );
            $jobPositions = $jobRowsByStaff[(int)$row['staff_id']] ?? [];
            $jobNames = array_values(array_unique(array_filter(array_map(static function ($job) {
                return trim((string)($job['position_name'] ?? ''));
            }, $jobPositions))));
            $roles = $this->buildStaffRoles($row, $posName);
            foreach ($jobNames as $jobName) {
                if (!in_array($jobName, $roles, true)) {
                    $roles[] = $jobName;
                }
            }
            foreach ($roles as $roleName) {
                if (!in_array($roleName, $byEmployee[$eid]['roles'], true)) {
                    $byEmployee[$eid]['roles'][] = $roleName;
                }
            }
            $byEmployee[$eid]['assignments'][] = [
                'staff_id' => (int)$row['staff_id'],
                'store_id' => (int)$row['store_id'],
                'store_name' => (string)($row['store_name'] ?? ''),
                'org_id' => (int)($row['org_id'] ?? 0),
                'organization_name' => (string)($row['organization_name'] ?? ''),
                'position' => $posName,
                'roles' => $roles,
                'job_positions' => $jobPositions,
                'job_names' => $jobNames,
                'is_manager' => SystemStoreStaffServices::staffIsManager($row),
            ];
        }

        if ($includeOrganizationRelationships) {
            $directMembershipQuery = Db::name('organization_employee')->alias('oe')
                ->join('organization o', 'o.id = oe.org_id')
                ->whereIn('oe.org_id', $orgIds)
                ->whereIn('oe.employee_id', $pageEmployeeIds)
                ->where('oe.is_del', 0)
                ->where('o.is_del', 0);
            if ($this->organizationEmployeeHasStatusColumn()) {
                $directMembershipQuery->where('oe.status', 1);
            }
            $directMembershipRows = $directMembershipQuery
                ->field('oe.org_id,oe.employee_id,oe.job_title,oe.source,o.name as org_name')
                ->order('oe.employee_id', 'asc')
                ->order('oe.id', 'asc')
                ->select()
                ->toArray();
            foreach ($directMembershipRows as $row) {
                $eid = (int)$row['employee_id'];
                if (!isset($byEmployee[$eid])) {
                    $emp = $empMap[$eid] ?? [];
                    $byEmployee[$eid] = [
                        'employee_id' => $eid,
                        'name' => (string)($emp['name'] ?? ''),
                        'avatar' => (string)($emp['avatar'] ?? ''),
                        'avatar_type' => (int)($emp['avatar_type'] ?? 0),
                        'phone_masked' => $this->maskPhone((string)($emp['phone'] ?? '')),
                        'status' => (int)($emp['status'] ?? 0) === 1 ? 1 : 0,
                        'roles' => [],
                        'assignments' => [],
                        'direct_memberships' => [],
                        'admin_grants' => [],
                    ];
                }
                $directJobPositions = $jobRowsByDirectEmployee[$eid] ?? [];
                $directJobNames = array_values(array_unique(array_filter(array_map(static function ($job) {
                    return trim((string)($job['position_name'] ?? ''));
                }, $directJobPositions))));
                foreach ($directJobNames as $jobName) {
                    if (!in_array($jobName, $byEmployee[$eid]['roles'], true)) {
                        $byEmployee[$eid]['roles'][] = $jobName;
                    }
                }
                $byEmployee[$eid]['direct_memberships'][] = [
                    'org_id' => (int)$row['org_id'],
                    'org_name' => (string)($row['org_name'] ?? ''),
                    'job_title' => (string)($row['job_title'] ?? ''),
                    'source' => (string)($row['source'] ?? ''),
                    'job_positions' => $directJobPositions,
                    'job_names' => $directJobNames,
                ];
            }

            // 组织管理员授权是独立于门店任职和直属名册的组织关联。仅返回当前
            // 查询范围内、账号和员工均有效的授权，供列表说明与“找人”精确定位。
            $adminGrantRows = Db::name('organization_admin')->alias('oa')
                ->join('organization o', 'o.id = oa.org_id')
                ->join('employee e', 'e.id = oa.employee_id')
                ->join('system_admin sa', 'sa.id = oa.admin_id AND sa.employee_id = oa.employee_id')
                ->whereIn('oa.org_id', $orgIds)
                ->whereIn('oa.employee_id', $pageEmployeeIds)
                ->where('oa.is_del', 0)
                ->where('oa.employee_id', '>', 0)
                ->where('oa.admin_id', '>', 0)
                ->where('o.is_del', 0)
                ->where('e.status', 1)
                ->where('e.is_del', 0)
                ->where('sa.status', 1)
                ->where('sa.is_del', 0)
                ->field('oa.id as org_admin_id,oa.org_id,oa.employee_id,oa.admin_id,o.name as org_name,sa.account')
                ->order('oa.employee_id', 'asc')
                ->order('oa.id', 'asc')
                ->select()
                ->toArray();
            foreach ($adminGrantRows as $row) {
                $eid = (int)$row['employee_id'];
                if (!isset($byEmployee[$eid])) {
                    $emp = $empMap[$eid] ?? [];
                    $byEmployee[$eid] = [
                        'employee_id' => $eid,
                        'name' => (string)($emp['name'] ?? ''),
                        'avatar' => (string)($emp['avatar'] ?? ''),
                        'avatar_type' => (int)($emp['avatar_type'] ?? 0),
                        'phone_masked' => $this->maskPhone((string)($emp['phone'] ?? '')),
                        'status' => (int)($emp['status'] ?? 0) === 1 ? 1 : 0,
                        'roles' => [],
                        'assignments' => [],
                        'direct_memberships' => [],
                        'admin_grants' => [],
                    ];
                }
                $byEmployee[$eid]['admin_grants'][] = [
                    'org_admin_id' => (int)$row['org_admin_id'],
                    'org_id' => (int)$row['org_id'],
                    'org_name' => (string)($row['org_name'] ?? ''),
                    'admin_id' => (int)$row['admin_id'],
                    'account' => (string)($row['account'] ?? ''),
                ];
            }
        }

        // 精确按 employee_id 判断后台账号（禁止手机号猜测）
        $accountByEmployee = [];
        if ($pageEmployeeIds) {
            $accRows = Db::name('system_admin')
                ->whereIn('employee_id', $pageEmployeeIds)
                ->where('is_del', 0)
                ->field('id,employee_id,account,status')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($accRows as $ar) {
                $eid = (int)$ar['employee_id'];
                if (!isset($accountByEmployee[$eid])) {
                    $accountByEmployee[$eid] = [
                        'has_account' => true,
                        'account' => (string)($ar['account'] ?? ''),
                        'admin_id' => (int)$ar['id'],
                        'account_status' => (int)($ar['status'] ?? 0),
                    ];
                }
            }
        }

        $list = [];
        foreach ($pageEmployeeIds as $eid) {
            if (!isset($byEmployee[$eid])) {
                continue;
            }
            $item = $byEmployee[$eid];
            $scopeCount = count($item['assignments']);
            $total = (int)($totalAssignCount[$eid] ?? $scopeCount);
            $acc = $accountByEmployee[$eid] ?? [
                'has_account' => false,
                'account' => '',
                'admin_id' => 0,
                'account_status' => 0,
            ];
            $list[] = [
                'employee_id' => $item['employee_id'],
                'name' => $item['name'],
                'avatar' => $item['avatar'],
                'avatar_type' => $item['avatar_type'],
                'phone_masked' => $item['phone_masked'],
                'status' => $item['status'],
                'roles' => $item['roles'],
                'assignments' => $item['assignments'],
                'direct_memberships' => $item['direct_memberships'],
                'admin_grants' => $item['admin_grants'],
                'scope_assignment_count' => $scopeCount,
                'total_assignment_count' => $total,
                'has_out_of_scope_assignments' => $total > $scopeCount,
                'has_account' => (bool)$acc['has_account'],
                'account' => (string)$acc['account'],
                'admin_id' => (int)$acc['admin_id'],
            ];
        }

        return compact('list', 'count', 'page', 'limit');
    }

    public function getLeaderCandidates(string $keyword = '', int $page = 1, int $limit = self::DEFAULT_LIMIT): array
    {
        [$page, $limit] = $this->normalizePage($page, $limit);
        $keyword = trim($keyword);
        $query = Db::name('employee')
            ->where('status', 1)
            ->where('is_del', 0);
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%')
                    ->whereOr('phone', 'like', '%' . $keyword . '%');
            });
        }
        $count = (int)(clone $query)->count();
        $rows = $query->field('id,name,phone,avatar,avatar_type,status')
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $ids = array_map(static function ($r) {
            return (int)$r['id'];
        }, $rows);
        $assignSummary = [];
        if ($ids) {
            $assignRows = Db::name('system_store_staff')->alias('s')
                ->join('system_store st', 'st.id = s.store_id')
                ->whereIn('s.employee_id', $ids)
                ->where('st.is_del', 0)
                ->where('s.status', 1)
                ->where('s.is_del', 0)
                ->where('s.employee_id', '>', 0)
                ->field('s.employee_id,s.store_id,st.name as store_name')
                ->select()
                ->toArray();
            foreach ($assignRows as $ar) {
                $eid = (int)$ar['employee_id'];
                $assignSummary[$eid][] = [
                    'store_id' => (int)$ar['store_id'],
                    'store_name' => (string)($ar['store_name'] ?? ''),
                ];
            }
        }
        $list = [];
        foreach ($rows as $row) {
            $eid = (int)$row['id'];
            $list[] = [
                'employee_id' => $eid,
                'name' => (string)$row['name'],
                'avatar' => (string)($row['avatar'] ?? ''),
                'avatar_type' => (int)($row['avatar_type'] ?? 0),
                'phone_masked' => $this->maskPhone((string)$row['phone']),
                'status' => (int)$row['status'],
                'assignments' => $assignSummary[$eid] ?? [],
                'assignment_count' => count($assignSummary[$eid] ?? []),
            ];
        }
        return compact('list', 'count', 'page', 'limit');
    }

    /**
     * 纯状态解析（无 DB）：权限人员 / 通用五态。
     * 顺序：UNLINKED_LEGACY → NO_ACCOUNT → NO_ORG_PERMISSION → INHERIT → CUSTOM
     *
     * @param array|null $sysAdmin id/employee_id/account/status/is_del
     * @return array{
     *   permission_status:string,permission_status_text:string,has_account:bool,account:string,
     *   anomaly:string,anomaly_text:string,scope_mode:string,allow_scope:bool
     * }
     */
    public static function resolvePermissionStatus(
        int $employeeId,
        int $adminId,
        ?array $sysAdmin,
        bool $hasOrgPermission,
        array $excludeStoreIds = [],
        string $explicitScopeMode = ''
    ): array {
        $explicit = strtolower(trim($explicitScopeMode));
        if ($employeeId <= 0) {
            if ($explicit === 'custom' || $explicit === 'inherit') {
                $scopeMode = strtoupper($explicit);
            } else {
                $scopeMode = $excludeStoreIds ? 'CUSTOM' : 'INHERIT';
            }
            return [
                'permission_status' => 'UNLINKED_LEGACY',
                'permission_status_text' => '历史权限关系尚未关联员工',
                'has_account' => false,
                'account' => '',
                'anomaly' => '',
                'anomaly_text' => '',
                'scope_mode' => $scopeMode,
                'allow_scope' => true,
            ];
        }

        $anomaly = '';
        $anomalyText = '';
        $accountUsable = false;
        $account = '';

        if ($adminId <= 0) {
            $anomaly = '';
            $anomalyText = '';
        } elseif ($sysAdmin === null) {
            $anomaly = 'ADMIN_MISSING';
            $anomalyText = '绑定的后台账号不存在';
        } else {
            $sysEmployeeId = (int)($sysAdmin['employee_id'] ?? 0);
            $sysDel = (int)($sysAdmin['is_del'] ?? 1);
            $sysStatus = (int)($sysAdmin['status'] ?? 0);
            if ($sysEmployeeId !== $employeeId) {
                $anomaly = 'EMPLOYEE_ADMIN_MISMATCH';
                $anomalyText = '后台账号关联了其他员工，当前组织权限员工与账号员工不一致';
            } elseif ($sysDel !== 0 || $sysStatus !== 1) {
                $anomaly = 'ADMIN_UNAVAILABLE';
                $anomalyText = '绑定的后台账号已删除或不可用';
            } else {
                $accountUsable = true;
                $account = (string)($sysAdmin['account'] ?? '');
            }
        }

        if (!$accountUsable) {
            $statusText = $anomaly === 'EMPLOYEE_ADMIN_MISMATCH' ? '账号关联异常' : '没有后台账号';
            return [
                'permission_status' => 'NO_ACCOUNT',
                'permission_status_text' => $statusText,
                'has_account' => false,
                'account' => '',
                'anomaly' => $anomaly,
                'anomaly_text' => $anomalyText,
                'scope_mode' => '',
                'allow_scope' => false,
            ];
        }

        if (!$hasOrgPermission) {
            return [
                'permission_status' => 'NO_ORG_PERMISSION',
                'permission_status_text' => '有账号但没有当前组织权限',
                'has_account' => true,
                'account' => $account,
                'anomaly' => '',
                'anomaly_text' => '',
                'scope_mode' => '',
                'allow_scope' => false,
            ];
        }

        if ($explicit === 'custom' || $explicit === 'inherit') {
            $scopeMode = strtoupper($explicit);
        } else {
            $scopeMode = $excludeStoreIds ? 'CUSTOM' : 'INHERIT';
        }
        return [
            'permission_status' => $scopeMode,
            'permission_status_text' => $scopeMode === 'CUSTOM' ? '自定义门店范围' : '继承组织及下级范围',
            'has_account' => true,
            'account' => $account,
            'anomaly' => '',
            'anomaly_text' => '',
            'scope_mode' => $scopeMode,
            'allow_scope' => true,
        ];
    }

    /**
     * 纯函数：逐条校验负责人账号 usable（必须 status/is_del 可用且 employee_id 精确匹配）。
     * 错绑账号：usable=false，account 置空，不得用于满足「有账号」。
     *
     * @param array $sysAdmin
     * @return array{admin_id:int,employee_id:int,account:string,real_name:string,status:int,is_del:int,usable:bool,anomaly:string}
     */
    public static function normalizeLeaderAccount(array $sysAdmin, int $expectedEmployeeId): array
    {
        $adminEmployeeId = (int)($sysAdmin['employee_id'] ?? 0);
        $status = (int)($sysAdmin['status'] ?? 0);
        $isDel = (int)($sysAdmin['is_del'] ?? 1);
        $matched = $adminEmployeeId === $expectedEmployeeId && $expectedEmployeeId > 0;
        $active = $isDel === 0 && $status === 1;
        $usable = $matched && $active;
        $anomaly = '';
        if (!$matched && $expectedEmployeeId > 0) {
            $anomaly = 'EMPLOYEE_ADMIN_MISMATCH';
        } elseif ($matched && !$active) {
            $anomaly = 'ADMIN_UNAVAILABLE';
        }
        return [
            'admin_id' => (int)($sysAdmin['id'] ?? 0),
            'employee_id' => $adminEmployeeId,
            'account' => $usable ? (string)($sysAdmin['account'] ?? '') : '',
            'real_name' => (string)($sysAdmin['real_name'] ?? ''),
            'status' => $status,
            'is_del' => $isDel,
            'usable' => $usable,
            'anomaly' => $anomaly,
        ];
    }

    /**
     * 纯状态解析：负责人（多账号）。has_account 仅统计 usable=true。
     *
     * @param array<int,array> $accounts normalizeLeaderAccount 结果
     */
    public static function resolveLeaderPermissionStatus(
        array $accounts,
        bool $hasOrgPermission,
        array $excludeStoreIds = []
    ): array {
        $usableAccounts = array_values(array_filter($accounts, static function ($a) {
            return !empty($a['usable']);
        }));
        if (!$usableAccounts) {
            $hasMismatch = false;
            foreach ($accounts as $a) {
                if (($a['anomaly'] ?? '') === 'EMPLOYEE_ADMIN_MISMATCH') {
                    $hasMismatch = true;
                    break;
                }
            }
            return [
                'permission_status' => 'NO_ACCOUNT',
                'permission_status_text' => $hasMismatch ? '账号关联异常' : '没有后台账号',
                'has_account' => false,
                'account' => '',
                'anomaly' => $hasMismatch ? 'EMPLOYEE_ADMIN_MISMATCH' : '',
                'anomaly_text' => $hasMismatch ? '后台账号关联了其他员工，不能作为当前负责人可用账号' : '',
                'scope_mode' => '',
                'allow_scope' => false,
                'usable_accounts' => [],
            ];
        }
        if (!$hasOrgPermission) {
            return [
                'permission_status' => 'NO_ORG_PERMISSION',
                'permission_status_text' => '有账号但没有当前组织权限',
                'has_account' => true,
                'account' => (string)($usableAccounts[0]['account'] ?? ''),
                'anomaly' => '',
                'anomaly_text' => '',
                'scope_mode' => '',
                'allow_scope' => false,
                'usable_accounts' => $usableAccounts,
            ];
        }
        $scopeMode = $excludeStoreIds ? 'CUSTOM' : 'INHERIT';
        return [
            'permission_status' => $scopeMode,
            'permission_status_text' => $scopeMode === 'CUSTOM' ? '自定义门店范围' : '继承组织及下级范围',
            'has_account' => true,
            'account' => (string)($usableAccounts[0]['account'] ?? ''),
            'anomaly' => '',
            'anomaly_text' => '',
            'scope_mode' => $scopeMode,
            'allow_scope' => true,
            'usable_accounts' => $usableAccounts,
        ];
    }

    /**
     * 权限只读：leaders / permission_holders 分开；账号按 admin_id 精确关联。
     */
    public function getPermissions(int $orgId): array
    {
        $orgId = $this->assertOrgId($orgId);
        $orgStoreIds = $this->getValidOrgStoreIds($orgId, true);

        $leaderRows = Db::name('organization_leader')->alias('l')
            ->join('employee e', 'e.id = l.employee_id')
            ->where('l.org_id', $orgId)
            ->where('l.is_del', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('l.id as leader_id,l.employee_id,l.sort,e.name,e.phone,e.avatar,e.avatar_type')
            ->order('l.sort', 'asc')
            ->order('l.id', 'asc')
            ->select()
            ->toArray();

        $hasScopeCol = $this->manageServices->hasOrganizationAdminScopeModeColumn();
        $adminFields = $hasScopeCol
            ? 'id,employee_id,admin_id,name,phone,legacy_agent_id,scope_mode'
            : 'id,employee_id,admin_id,name,phone,legacy_agent_id';
        $adminRows = Db::name('organization_admin')
            ->where('org_id', $orgId)
            ->where('is_del', 0)
            ->field($adminFields)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $leaderEmployeeIds = [];
        foreach ($leaderRows as $row) {
            $leaderEmployeeIds[] = (int)$row['employee_id'];
        }
        $adminIds = [];
        $holderEmployeeIds = [];
        foreach ($adminRows as $row) {
            if ((int)($row['admin_id'] ?? 0) > 0) {
                $adminIds[] = (int)$row['admin_id'];
            }
            if ((int)($row['employee_id'] ?? 0) > 0) {
                $holderEmployeeIds[] = (int)$row['employee_id'];
            }
        }
        $adminIds = array_values(array_unique($adminIds));
        $allEmployeeIds = array_values(array_unique(array_merge($leaderEmployeeIds, $holderEmployeeIds)));

        // 精确：按 admin_id
        $sysAdminById = [];
        if ($adminIds) {
            $sysRows = Db::name('system_admin')
                ->whereIn('id', $adminIds)
                ->field('id,employee_id,account,real_name,status,is_del')
                ->select()
                ->toArray();
            foreach ($sysRows as $sa) {
                $sysAdminById[(int)$sa['id']] = $sa;
            }
        }

        // 负责人可能多账号：按 employee_id 查询后仍逐条校验 usable + employee_id（禁止错绑冒充）
        $accountsByEmployee = [];
        if ($leaderEmployeeIds) {
            $allAcc = Db::name('system_admin')
                ->whereIn('employee_id', array_values(array_unique($leaderEmployeeIds)))
                ->field('id,employee_id,account,real_name,status,is_del')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($allAcc as $sa) {
                $eid = (int)$sa['employee_id'];
                $accountsByEmployee[$eid][] = self::normalizeLeaderAccount($sa, $eid);
            }
        }

        $orgAdminByEmployee = [];
        $orgAdminIds = [];
        foreach ($adminRows as $row) {
            $orgAdminIds[] = (int)$row['id'];
            $eid = (int)($row['employee_id'] ?? 0);
            if ($eid > 0 && !isset($orgAdminByEmployee[$eid])) {
                $orgAdminByEmployee[$eid] = $row;
            }
        }

        $excludeByAdmin = [];
        if ($orgAdminIds) {
            $exRows = Db::name('organization_admin_store_exclude')
                ->whereIn('org_admin_id', $orgAdminIds)
                ->field('org_admin_id,store_id')
                ->select()
                ->toArray();
            foreach ($exRows as $ex) {
                $excludeByAdmin[(int)$ex['org_admin_id']][] = (int)$ex['store_id'];
            }
        }

        $storeNameMap = [];
        if ($orgStoreIds) {
            $storeNameMap = Db::name('system_store')
                ->whereIn('id', $orgStoreIds)
                ->where('is_del', 0)
                ->column('name', 'id');
        }

        $empMap = [];
        if ($allEmployeeIds) {
            foreach (Db::name('employee')->whereIn('id', $allEmployeeIds)->field('id,name,phone,avatar,avatar_type')->select()->toArray() as $r) {
                $empMap[(int)$r['id']] = $r;
            }
        }

        $leaders = [];
        foreach ($leaderRows as $row) {
            $eid = (int)$row['employee_id'];
            $accounts = $accountsByEmployee[$eid] ?? [];
            $orgAdmin = $orgAdminByEmployee[$eid] ?? null;
            $excludes = $orgAdmin ? ($excludeByAdmin[(int)$orgAdmin['id']] ?? []) : [];
            $resolved = self::resolveLeaderPermissionStatus($accounts, (bool)$orgAdmin, $excludes);
            $resolvedIds = [];
            if (!empty($resolved['allow_scope']) && $orgAdmin) {
                $resolvedIds = $this->resolveStoresWithExclude($orgStoreIds, $excludes);
            }

            $leaders[] = [
                'leader_id' => (int)$row['leader_id'],
                'employee_id' => $eid,
                'name' => (string)($row['name'] ?? ''),
                'phone_masked' => $this->maskPhone((string)($row['phone'] ?? '')),
                'avatar' => (string)($row['avatar'] ?? ''),
                'avatar_type' => (int)($row['avatar_type'] ?? 0),
                'permission_status' => $resolved['permission_status'],
                'permission_status_text' => $resolved['permission_status_text'],
                'has_account' => (bool)$resolved['has_account'],
                'account' => (string)$resolved['account'],
                'accounts' => $accounts,
                'account_count' => count($accounts),
                'usable_account_count' => count($resolved['usable_accounts'] ?? []),
                'anomaly' => (string)($resolved['anomaly'] ?? ''),
                'anomaly_text' => (string)($resolved['anomaly_text'] ?? ''),
                'scope_mode' => (string)$resolved['scope_mode'],
                'store_count' => count($resolvedIds),
                'store_names_preview' => $this->previewStoreNames($resolvedIds, $storeNameMap),
                'excluded_store_count' => !empty($resolved['allow_scope']) ? count($excludes) : 0,
            ];
        }

        $permissionHolders = [];
        foreach ($adminRows as $row) {
            $eid = (int)($row['employee_id'] ?? 0);
            $orgAdminId = (int)$row['id'];
            $boundAdminId = (int)($row['admin_id'] ?? 0);
            $excludes = array_values(array_unique(array_map('intval', $excludeByAdmin[$orgAdminId] ?? [])));
            sort($excludes);
            $sys = $boundAdminId > 0 ? ($sysAdminById[$boundAdminId] ?? null) : null;
            $explicitMode = '';
            if ($hasScopeCol) {
                $m = strtolower(trim((string)($row['scope_mode'] ?? '')));
                if ($m === 'custom' || $m === 'inherit') {
                    $explicitMode = $m;
                }
            }
            // 权限人员行本身即组织权限关系；011 后优先 scope_mode，未执行时按排除推断
            $resolved = self::resolvePermissionStatus($eid, $boundAdminId, $sys, true, $excludes, $explicitMode);
            $allowedIds = [];
            $resolvedIds = [];
            $modeLower = '';
            $excludedOut = [];
            if (!empty($resolved['allow_scope'])) {
                if ($explicitMode === 'custom' || $explicitMode === 'inherit') {
                    $modeLower = $explicitMode;
                } else {
                    $rawMode = strtolower((string)$resolved['scope_mode']);
                    $modeLower = ($rawMode === 'custom' || $rawMode === 'inherit')
                        ? $rawMode
                        : ($excludes === [] ? 'inherit' : 'custom');
                }
                if ($modeLower === 'inherit') {
                    $allowedIds = [];
                    $resolvedIds = $orgStoreIds;
                    $excludedOut = [];
                } else {
                    $exFlip = array_flip($excludes);
                    foreach ($orgStoreIds as $sid) {
                        if (!isset($exFlip[$sid])) {
                            $allowedIds[] = (int)$sid;
                        }
                    }
                    $resolvedIds = $allowedIds;
                    $excludedOut = $excludes;
                }
            }

            $emp = $eid > 0 ? ($empMap[$eid] ?? null) : null;
            $permissionHolders[] = [
                'org_admin_id' => $orgAdminId,
                'employee_id' => $eid,
                'admin_id' => $boundAdminId,
                'name' => (string)($emp['name'] ?? $row['name'] ?? ''),
                'phone_masked' => $this->maskPhone((string)($emp['phone'] ?? $row['phone'] ?? '')),
                'avatar' => (string)($emp['avatar'] ?? ''),
                'avatar_type' => (int)($emp['avatar_type'] ?? 0),
                'permission_status' => $resolved['permission_status'],
                'permission_status_text' => $resolved['permission_status_text'],
                'has_account' => (bool)$resolved['has_account'],
                'account' => (string)$resolved['account'],
                'anomaly' => (string)$resolved['anomaly'],
                'anomaly_text' => (string)$resolved['anomaly_text'],
                'scope_mode' => $modeLower,
                'allowed_store_ids' => $allowedIds,
                'excluded_store_ids' => $excludedOut,
                'org_store_ids' => $orgStoreIds,
                'store_count' => count($resolvedIds),
                'store_names_preview' => $this->previewStoreNames($resolvedIds, $storeNameMap),
                'excluded_store_count' => count($excludedOut),
                'legacy_agent_id' => (int)($row['legacy_agent_id'] ?? 0),
            ];
        }

        $orgStoreOptions = [];
        foreach ($orgStoreIds as $sid) {
            $orgStoreOptions[] = [
                'id' => (int)$sid,
                'name' => (string)($storeNameMap[$sid] ?? ('门店' . $sid)),
            ];
        }

        return [
            'org_id' => $orgId,
            'org_store_count' => count($orgStoreIds),
            'org_store_options' => $orgStoreOptions,
            'leaders' => $leaders,
            'permission_holders' => $permissionHolders,
        ];
    }

    /**
     * 组织权限授权候选：仅在职员工 + system_admin.employee_id 精确关联的有效账号。
     * 禁止手机号匹配。
     */
    public function getAdminCandidates(int $orgId, string $keyword = '', int $page = 1, int $limit = self::DEFAULT_LIMIT): array
    {
        $orgId = $this->assertOrgId($orgId);
        [$page, $limit] = $this->normalizePage($page, $limit);
        $keyword = trim($keyword);

        $grantedAdminIds = array_map('intval', Db::name('organization_admin')
            ->where('org_id', $orgId)
            ->where('is_del', 0)
            ->where('admin_id', '>', 0)
            ->column('admin_id') ?: []);
        $grantedFlip = array_flip($grantedAdminIds);

        $query = Db::name('system_admin')->alias('a')
            ->join('employee e', 'e.id = a.employee_id')
            ->where('a.is_del', 0)
            ->where('a.status', 1)
            ->where('a.employee_id', '>', 0)
            ->where('e.is_del', 0)
            ->where('e.status', 1);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('e.name', 'like', $like)
                    ->whereOr('a.account', 'like', $like)
                    ->whereOr('a.real_name', 'like', $like)
                    ->whereOr('e.phone', 'like', $like);
            });
        }
        $count = (int)(clone $query)->count();
        $rows = $query
            ->field('a.id as admin_id,a.account,a.real_name,a.employee_id,e.name as employee_name,e.phone')
            ->order('a.id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $row) {
            $adminId = (int)$row['admin_id'];
            $list[] = [
                'admin_id' => $adminId,
                'account' => (string)($row['account'] ?? ''),
                'real_name' => (string)($row['real_name'] ?? ''),
                'employee_id' => (int)$row['employee_id'],
                'employee_name' => (string)($row['employee_name'] ?? ''),
                'phone_masked' => $this->maskPhone((string)($row['phone'] ?? '')),
                'already_granted' => isset($grantedFlip[$adminId]),
            ];
        }

        return [
            'org_id' => $orgId,
            'list' => $list,
            'count' => $count,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function getChangeLog(
        int $orgId = 0,
        string $keyword = '',
        string $action = '',
        int $page = 1,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        [$page, $limit] = $this->normalizePage($page, $limit);
        $query = Db::name('organization_change_log');
        if ($orgId > 0) {
            $resolved = $this->manageServices->resolveOrgId($orgId);
            if ($resolved <= 0) {
                return ['list' => [], 'count' => 0, 'page' => $page, 'limit' => $limit];
            }
            $query->where('org_id', $resolved);
        }
        if ($action !== '') {
            $query->where('action', $action);
        }
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('remark', 'like', '%' . $keyword . '%')
                    ->whereOr('operator_name', 'like', '%' . $keyword . '%')
                    ->whereOr('action', 'like', '%' . $keyword . '%');
            });
        }
        $count = (int)(clone $query)->count();
        $list = $query->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $act = (string)($item['action'] ?? '');
            $item['title'] = $this->changeLogTitle($act);
            $item['summary'] = (string)($item['remark'] ?? '');
            $item['operator_display'] = (string)($item['operator_name'] ?? '') ?: '系统';
            $item['add_time_text'] = $this->formatTime((int)($item['add_time'] ?? 0));
        }
        unset($item);

        return compact('list', 'count', 'page', 'limit');
    }

    /**
     * 内存样本：验证一人多店去重（冒烟用，不写库）
     *
     * @param array<int, array> $staffRows
     */
    public function dedupeEmployeesFromStaffRows(array $staffRows): array
    {
        $byEmployee = [];
        foreach ($staffRows as $row) {
            $eid = (int)($row['employee_id'] ?? 0);
            if ($eid <= 0) {
                continue;
            }
            if (!isset($byEmployee[$eid])) {
                $byEmployee[$eid] = [
                    'employee_id' => $eid,
                    'name' => (string)($row['name'] ?? ''),
                    'assignments' => [],
                ];
            }
            $byEmployee[$eid]['assignments'][] = [
                'staff_id' => (int)($row['staff_id'] ?? $row['id'] ?? 0),
                'store_id' => (int)($row['store_id'] ?? 0),
                'store_name' => (string)($row['store_name'] ?? ''),
            ];
        }
        return array_values($byEmployee);
    }

    // ───────────────────── helpers ─────────────────────

    protected function assertOrgId(int $orgId): int
    {
        if ($orgId <= 0) {
            throw new \Exception('组织无效');
        }
        $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->field('id')->find();
        if (!$org) {
            throw new \Exception('组织不存在');
        }
        return $orgId;
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function normalizePage(int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = $limit <= 0 ? self::DEFAULT_LIMIT : min(self::MAX_LIMIT, $limit);
        return [$page, $limit];
    }

    /**
     * @return array<int, array>
     */
    protected function loadOrgRows(): array
    {
        return Db::name('organization')
            ->where('is_del', 0)
            ->field('id,pid,name,sort,update_time')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * @return int[]
     */
    protected function collectOrgIdsIncl(int $orgId): array
    {
        $orgRows = $this->loadOrgRows();
        $children = [];
        foreach ($orgRows as $row) {
            $children[(int)($row['pid'] ?? 0)][] = (int)$row['id'];
        }
        return $this->collectDescendantsIncl($orgId, $children);
    }

    /**
     * 有效门店 ID：organization_store ∩ system_store.is_del=0
     *
     * @return int[]
     */
    protected function getValidOrgStoreIds(int $orgId, bool $includeDescendants): array
    {
        $orgIds = $includeDescendants ? $this->collectOrgIdsIncl($orgId) : [$orgId];
        if (!$orgIds) {
            return [];
        }
        $ids = Db::name('organization_store')->alias('os')
            ->join('system_store st', 'st.id = os.store_id')
            ->whereIn('os.org_id', $orgIds)
            ->where('st.is_del', 0)
            ->column('os.store_id');
        return array_values(array_unique(array_map('intval', $ids ?: [])));
    }

    /**
     * @return array<int, string>
     */
    protected function orgNameMap(): array
    {
        return Db::name('organization')->where('is_del', 0)->column('name', 'id') ?: [];
    }

    /**
     * @param array<int, array> $orgRows
     */
    protected function buildOrgStatBundle(array $orgRows): array
    {
        $orgIds = [];
        $children = [];
        foreach ($orgRows as $row) {
            $id = (int)$row['id'];
            $orgIds[] = $id;
            $children[(int)($row['pid'] ?? 0)][] = $id;
        }

        $descendantsIncl = [];
        foreach ($orgIds as $oid) {
            $descendantsIncl[$oid] = $this->collectDescendantsIncl($oid, $children);
        }

        // 仅有效门店
        $orgStoreRows = Db::name('organization_store')->alias('os')
            ->join('system_store st', 'st.id = os.store_id')
            ->where('st.is_del', 0)
            ->field('os.org_id,os.store_id,st.is_show')
            ->select()
            ->toArray();

        $directStoreCount = [];
        $storesByOrgDirect = [];
        $storeMeta = [];
        foreach ($orgStoreRows as $row) {
            $oid = (int)$row['org_id'];
            $sid = (int)$row['store_id'];
            $directStoreCount[$oid] = ($directStoreCount[$oid] ?? 0) + 1;
            $storesByOrgDirect[$oid][] = $sid;
            $storeMeta[$sid] = ['id' => $sid, 'is_show' => (int)$row['is_show']];
        }

        $storeIdsByOrgAll = [];
        $allStoreCount = [];
        foreach ($orgIds as $oid) {
            $set = [];
            foreach ($descendantsIncl[$oid] as $did) {
                foreach ($storesByOrgDirect[$did] ?? [] as $sid) {
                    $set[$sid] = true;
                }
            }
            $ids = array_keys($set);
            $storeIdsByOrgAll[$oid] = $ids;
            $allStoreCount[$oid] = count($ids);
        }

        $allStoreIds = array_keys($storeMeta);
        $staffBundle = $this->loadStaffBundleForStores($allStoreIds);

        // 组织直属人员（按 org 及下级汇总，employee_id 去重）
        $directEmpByOrg = [];
        $directQuery = Db::name('organization_employee')->alias('oe')
            ->join('employee e', 'e.id = oe.employee_id')
            ->where('oe.is_del', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0);
        if ($this->organizationEmployeeHasStatusColumn()) {
            $directQuery->where('oe.status', 1);
        }
        foreach ($directQuery->field('oe.org_id,oe.employee_id')->select()->toArray() as $row) {
            $directEmpByOrg[(int)$row['org_id']][(int)$row['employee_id']] = true;
        }

        $employeeCount = [];
        $noManagerStoreCount = [];
        foreach ($orgIds as $oid) {
            $empSet = [];
            $noMgr = 0;
            foreach ($storeIdsByOrgAll[$oid] as $sid) {
                foreach ($staffBundle['employeeIdsByStore'][$sid] ?? [] as $eid) {
                    $empSet[$eid] = true;
                }
                $meta = $storeMeta[$sid] ?? null;
                if ($meta && (int)$meta['is_show'] === 1) {
                    if (empty($staffBundle['managersByStore'][$sid])) {
                        $noMgr++;
                    }
                }
            }
            foreach ($descendantsIncl[$oid] as $did) {
                foreach (array_keys($directEmpByOrg[$did] ?? []) as $eid) {
                    $empSet[$eid] = true;
                }
            }
            $employeeCount[$oid] = count($empSet);
            $noManagerStoreCount[$oid] = $noMgr;
        }

        // 有效负责人
        $validLeaderRows = Db::name('organization_leader')->alias('l')
            ->join('employee e', 'e.id = l.employee_id')
            ->where('l.is_del', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('l.org_id,l.employee_id')
            ->select()
            ->toArray();
        $leaderCount = [];
        $leaderOrgSet = [];
        foreach ($validLeaderRows as $row) {
            $oid = (int)$row['org_id'];
            $leaderCount[$oid] = ($leaderCount[$oid] ?? 0) + 1;
            $leaderOrgSet[$oid] = true;
        }

        // 无效负责人 anomaly（离职/删除员工），不抵消「未设置负责人」
        $invalidLeaderRows = Db::name('organization_leader')->alias('l')
            ->leftJoin('employee e', 'e.id = l.employee_id')
            ->where('l.is_del', 0)
            ->where(function ($q) {
                $q->whereNull('e.id')
                    ->whereOr('e.status', '<>', 1)
                    ->whereOr('e.is_del', 1);
            })
            ->field('l.org_id,l.employee_id,l.id as leader_id,e.status,e.is_del,e.name')
            ->select()
            ->toArray();
        $leaderAnomaliesByOrg = [];
        foreach ($invalidLeaderRows as $row) {
            $oid = (int)$row['org_id'];
            $leaderAnomaliesByOrg[$oid][] = [
                'leader_id' => (int)$row['leader_id'],
                'employee_id' => (int)$row['employee_id'],
                'name' => (string)($row['name'] ?? ''),
                'anomaly' => 'INVALID_LEADER_EMPLOYEE',
                'anomaly_text' => '负责人关联员工已离职或已删除，不计入有效负责人',
            ];
        }

        $adminCount = [];
        foreach (Db::name('organization_admin')->where('is_del', 0)->field('org_id')->select()->toArray() as $row) {
            $oid = (int)$row['org_id'];
            $adminCount[$oid] = ($adminCount[$oid] ?? 0) + 1;
        }

        $noLeaderOrgCount = [];
        $attentionCount = [];
        foreach ($orgIds as $oid) {
            $noLeader = 0;
            foreach ($descendantsIncl[$oid] as $did) {
                if (empty($leaderOrgSet[$did])) {
                    $noLeader++;
                }
            }
            $noLeaderOrgCount[$oid] = $noLeader;
            $attentionCount[$oid] = $noLeader + (int)($noManagerStoreCount[$oid] ?? 0);
        }

        $updateTime = [];
        foreach ($orgRows as $row) {
            $updateTime[(int)$row['id']] = (int)($row['update_time'] ?? 0);
        }

        return compact(
            'directStoreCount',
            'allStoreCount',
            'storeIdsByOrgAll',
            'employeeCount',
            'leaderCount',
            'adminCount',
            'noLeaderOrgCount',
            'noManagerStoreCount',
            'attentionCount',
            'updateTime',
            'children',
            'leaderAnomaliesByOrg'
        );
    }

    /**
     * @param array<int, int[]> $children
     * @return int[]
     */
    protected function collectDescendantsIncl(int $orgId, array $children): array
    {
        $result = [$orgId];
        $queue = [$orgId];
        $visited = [$orgId => true];
        $guard = 0;
        $max = 5000;
        while ($queue) {
            if (++$guard > $max) {
                throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
            }
            $cur = (int)array_shift($queue);
            foreach ($children[$cur] ?? [] as $childId) {
                $childId = (int)$childId;
                if (isset($visited[$childId])) {
                    throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
                }
                $visited[$childId] = true;
                $result[] = $childId;
                $queue[] = $childId;
            }
        }
        return $result;
    }

    /**
     * @param array<int, array> $nodes
     * @param array $stats
     */
    protected function enrichTreeNodes(array $nodes, array $stats, int $depth): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $id = (int)($node['id'] ?? 0);
            $children = $this->enrichTreeNodes($node['children'] ?? [], $stats, $depth + 1);
            $direct = (int)($stats['directStoreCount'][$id] ?? 0);
            $node['store_count'] = $direct;
            $node['direct_store_count'] = $direct;
            $node['all_store_count'] = (int)($stats['allStoreCount'][$id] ?? 0);
            $node['employee_count'] = (int)($stats['employeeCount'][$id] ?? 0);
            $node['leader_count'] = (int)($stats['leaderCount'][$id] ?? 0);
            $node['attention_count'] = (int)($stats['attentionCount'][$id] ?? 0);
            $node['update_time'] = (int)($stats['updateTime'][$id] ?? 0);
            $node['update_time_text'] = $this->formatTime((int)($stats['updateTime'][$id] ?? 0));
            $node['depth'] = $depth;
            $node['children'] = $children;
            $out[] = $node;
        }
        return $out;
    }

    /**
     * @param int[] $storeIds
     * @return array{employeeIdsByStore: array<int, int[]>, managersByStore: array<int, array>}
     */
    protected function loadStaffBundleForStores(array $storeIds): array
    {
        $employeeIdsByStore = [];
        $managersByStore = [];
        if (!$storeIds) {
            return compact('employeeIdsByStore', 'managersByStore');
        }
        $positionNameMap = $this->loadPositionNameMap();
        $rows = Db::name('system_store_staff')->alias('s')
            ->join('employee e', 'e.id = s.employee_id')
            ->join('system_store st', 'st.id = s.store_id')
            ->whereIn('s.store_id', $storeIds)
            ->where('st.is_del', 0)
            ->where('s.status', 1)
            ->where('s.is_del', 0)
            ->where('s.employee_id', '>', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('s.id,s.store_id,s.employee_id,s.position,s.is_manager,s.is_butler,s.is_cashier,s.is_customer,e.name,e.phone,e.avatar,e.avatar_type')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $sid = (int)$row['store_id'];
            $eid = (int)$row['employee_id'];
            $employeeIdsByStore[$sid][$eid] = $eid;
            $posName = $this->normalizePositionLabel(
                (string)($positionNameMap[(int)($row['position'] ?? 0)] ?? '')
            );
            if ($this->isStoreManagerStaff($row, $posName)) {
                $managersByStore[$sid][$eid] = [
                    'employee_id' => $eid,
                    'name' => (string)$row['name'],
                    'phone_masked' => $this->maskPhone((string)$row['phone']),
                    'position' => $posName !== '' ? $posName : '店长',
                ];
            }
        }
        foreach ($employeeIdsByStore as $sid => $set) {
            $employeeIdsByStore[$sid] = array_values($set);
        }
        foreach ($managersByStore as $sid => $set) {
            $managersByStore[$sid] = array_values($set);
        }
        return compact('employeeIdsByStore', 'managersByStore');
    }

    /**
     * @return array<int, string>
     */
    protected function loadPositionNameMap(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = Db::name('position')->column('name', 'id') ?: [];
        return $cache;
    }

    protected function isStoreManagerStaff(array $staff, string $positionName): bool
    {
        if (SystemStoreStaffServices::staffIsManager($staff)) {
            return true;
        }
        $normalized = $this->normalizePositionLabel($positionName);
        return in_array($normalized, ['店长', '副店长'], true)
            || in_array($positionName, ['店长', '副店', '副店长'], true);
    }

    /**
     * @return string[]
     */
    protected function buildStaffRoles(array $staff, string $positionName): array
    {
        $roles = [];
        if ($positionName !== '') {
            $roles[] = $positionName;
        } elseif (SystemStoreStaffServices::staffIsManager($staff)) {
            $roles[] = '店长';
        }
        if ((int)($staff['is_cashier'] ?? 0) === 1 && !in_array('收银员', $roles, true)) {
            $roles[] = '收银员';
        }
        if ((int)($staff['is_customer'] ?? 0) === 1 && !in_array('客服', $roles, true)) {
            $roles[] = '客服';
        }
        return array_values(array_filter($roles, static function ($r) {
            return !in_array($r, ['店员', '管理员', '店员/管理员'], true);
        }));
    }

    protected function normalizePositionLabel(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if ($name === '副店') {
            return '副店长';
        }
        if (mb_strpos($name, '美容师') !== false) {
            return str_replace('美容师', '手艺人', $name);
        }
        return $name;
    }

    /**
     * @param \think\db\Query $query
     */
    protected function applyRoleFilter($query, string $role): void
    {
        $role = trim($role);
        if ($role === '收银员') {
            $query->where('s.is_cashier', 1);
            return;
        }
        if ($role === '客服') {
            $query->where('s.is_customer', 1);
            return;
        }
        if (in_array($role, ['店长', '副店长', '副店'], true)) {
            $query->where(function ($w) use ($role) {
                $w->where('s.is_manager', 1)
                    ->whereOr('s.is_butler', 1)
                    ->whereOr('p.name', 'in', ['店长', '副店', '副店长']);
                if ($role === '店长') {
                    // 保留上面并集即可
                }
            });
            return;
        }
        if ($role === '手艺人') {
            $query->where(function ($w) {
                $w->where('p.name', 'like', '%手艺人%')
                    ->whereOr('p.name', 'like', '%美容师%');
            });
            return;
        }
        $query->where('p.name', 'like', '%' . $role . '%');
    }

    /**
     * @param int[] $storeIds
     */
    protected function buildPositionDistribution(array $storeIds): array
    {
        if (!$storeIds) {
            return [];
        }
        $staffRows = Db::name('system_store_staff')->alias('s')
            ->join('employee e', 'e.id = s.employee_id')
            ->join('system_store st', 'st.id = s.store_id')
            ->whereIn('s.store_id', $storeIds)
            ->where('st.is_del', 0)
            ->where('s.status', 1)
            ->where('s.is_del', 0)
            ->where('s.employee_id', '>', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('s.employee_id,s.position,s.is_manager,s.is_butler,s.id')
            ->order('s.id', 'asc')
            ->select()
            ->toArray();
        $positionNameMap = $this->loadPositionNameMap();
        $primary = [];
        foreach ($staffRows as $row) {
            $eid = (int)$row['employee_id'];
            if (isset($primary[$eid])) {
                continue;
            }
            $posName = $this->normalizePositionLabel(
                (string)($positionNameMap[(int)($row['position'] ?? 0)] ?? '')
            );
            if ($posName === '' && SystemStoreStaffServices::staffIsManager($row)) {
                $posName = '店长';
            }
            if ($posName === '') {
                $posName = '未设职位';
            }
            $primary[$eid] = $posName;
        }
        $counts = [];
        foreach ($primary as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        arsort($counts);
        $colors = ['#5b5bd6', '#3fb594', '#e39b4d', '#6d9ee5', '#8854c7', '#d9822b', '#929bad', '#4384cf'];
        $i = 0;
        $out = [];
        foreach ($counts as $name => $count) {
            $out[] = [
                'name' => $name,
                'count' => $count,
                'color' => $colors[$i % count($colors)],
            ];
            $i++;
        }
        return $out;
    }

    /**
     * @param int[] $storeIds
     * @param array<int, array> $orgMap
     */
    protected function buildFocusStores(array $storeIds, array $orgMap, int $limit = 4): array
    {
        if (!$storeIds) {
            return [];
        }
        $stores = Db::name('system_store')
            ->whereIn('id', $storeIds)
            ->where('is_del', 0)
            ->field('id,name,image,phone,address,detailed_address,is_show')
            ->order('is_show', 'desc')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        $pageIds = array_map(static function ($s) {
            return (int)$s['id'];
        }, $stores);
        $bindMap = [];
        if ($pageIds) {
            $bindMap = Db::name('organization_store')
                ->whereIn('store_id', $pageIds)
                ->column('org_id', 'store_id');
        }
        $staffBundle = $this->loadStaffBundleForStores($pageIds);
        $out = [];
        foreach ($stores as $store) {
            $sid = (int)$store['id'];
            $oid = (int)($bindMap[$sid] ?? 0);
            $managers = $staffBundle['managersByStore'][$sid] ?? [];
            $isShow = (int)$store['is_show'] === 1;
            $out[] = [
                'id' => $sid,
                'name' => (string)$store['name'],
                'image' => (string)($store['image'] ?? ''),
                'org_id' => $oid,
                'org_name' => (string)(($orgMap[$oid]['name'] ?? '')),
                'address' => trim(((string)$store['address']) . ' ' . ((string)($store['detailed_address'] ?? ''))),
                'business_status' => $isShow ? 'open' : 'closed',
                'employee_count' => count($staffBundle['employeeIdsByStore'][$sid] ?? []),
                'managers' => $managers,
                'attention_codes' => ($isShow && !$managers) ? ['NO_MANAGER'] : [],
            ];
        }
        return $out;
    }

    /**
     * @param int[] $orgIds
     * @return array<int, array>
     */
    protected function loadOrgLeaders(array $orgIds): array
    {
        if (!$orgIds) {
            return [];
        }
        $rows = Db::name('organization_leader')->alias('l')
            ->join('employee e', 'e.id = l.employee_id')
            ->whereIn('l.org_id', $orgIds)
            ->where('l.is_del', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->field('l.org_id,l.id as leader_id,l.employee_id,e.name,e.phone,e.avatar,e.avatar_type')
            ->order('l.sort', 'asc')
            ->order('l.id', 'asc')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $oid = (int)$row['org_id'];
            $map[$oid][] = [
                'leader_id' => (int)$row['leader_id'],
                'employee_id' => (int)$row['employee_id'],
                'name' => (string)($row['name'] ?? ''),
                'phone_masked' => $this->maskPhone((string)($row['phone'] ?? '')),
                'avatar' => (string)($row['avatar'] ?? ''),
                'avatar_type' => (int)($row['avatar_type'] ?? 0),
            ];
        }
        return $map;
    }

    /**
     * @param array<int, array> $orgMap
     * @return array<int, array{id:int,name:string}>
     */
    protected function buildBreadcrumb(int $orgId, array $orgMap): array
    {
        $path = [];
        $visited = [];
        $cur = $orgId;
        $guard = 0;
        while ($cur > 0 && isset($orgMap[$cur])) {
            if (isset($visited[$cur]) || ++$guard > 100) {
                break;
            }
            $visited[$cur] = true;
            array_unshift($path, [
                'id' => $cur,
                'name' => (string)($orgMap[$cur]['name'] ?? ''),
            ]);
            $cur = (int)($orgMap[$cur]['pid'] ?? 0);
        }
        return $path;
    }

    /**
     * @param int[] $orgStoreIds
     * @param int[] $excludes
     * @return int[]
     */
    protected function resolveStoresWithExclude(array $orgStoreIds, array $excludes): array
    {
        if (!$excludes) {
            return array_values($orgStoreIds);
        }
        $flip = array_flip(array_map('intval', $excludes));
        $out = [];
        foreach ($orgStoreIds as $sid) {
            if (!isset($flip[(int)$sid])) {
                $out[] = (int)$sid;
            }
        }
        return $out;
    }

    /**
     * @param int[] $storeIds
     * @param array<int, string> $storeNameMap
     */
    protected function previewStoreNames(array $storeIds, array $storeNameMap): string
    {
        $names = [];
        foreach ($storeIds as $sid) {
            if (isset($storeNameMap[$sid])) {
                $names[] = $storeNameMap[$sid];
            }
            if (count($names) >= 3) {
                break;
            }
        }
        $total = count($storeIds);
        if ($total <= 3) {
            return implode('、', $names);
        }
        return implode('、', $names) . ' 等' . $total . '家';
    }

    protected function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        if (preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return substr($phone, 0, 3) . '****' . substr($phone, -4);
        }
        $len = mb_strlen($phone);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return mb_substr($phone, 0, 2) . '****' . mb_substr($phone, -2);
    }

    protected function formatTime(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        return date('Y-m-d H:i', $ts);
    }

    protected function changeLogTitle(string $action): string
    {
        $map = [
            'create' => '新增组织',
            'update' => '编辑组织',
            'delete' => '删除组织',
            'bind_store' => '更新门店归属',
            'set_leader' => '调整组织负责人',
            'save_exclude' => '调整权限范围',
            'migrate' => '组织数据迁移',
        ];
        return $map[$action] ?? ($action !== '' ? $action : '组织变更');
    }

    protected function organizationEmployeeHasStatusColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $cols = Db::query("SHOW COLUMNS FROM `eb_organization_employee` LIKE 'status'");
            $cached = !empty($cols);
        } catch (\Throwable $e) {
            $cached = false;
        }
        return $cached;
    }
}
