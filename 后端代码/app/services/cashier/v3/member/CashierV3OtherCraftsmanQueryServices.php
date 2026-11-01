<?php
namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

/** Shared read-only organization picker for other craftsmen and salespeople. Client nodes can only narrow the server-derived group. */
final class CashierV3OtherCraftsmanQueryServices
{
    /** The same root calculation is reusable by the final locked staff-profile check. */
    public function groupScope(int $organizationId): array
    {
        if ($organizationId <= 0) return ['organizations' => [], 'stores' => []];
        $seen = []; $foundRoot = false;
        for ($i = 0; $i < 64; $i++) {
            if (isset($seen[$organizationId])) throw CashierV3CommandException::invalidContext('组织关系异常，请联系管理员。', ['reason' => 'personnel_organization_cycle']);
            $seen[$organizationId] = true;
            $row = Db::name('organization')->where('id', $organizationId)->where('is_del', 0)->find();
            if (!$row) return ['organizations' => [], 'stores' => []];
            $parent = (int)$row['pid'];
            if ($parent <= 0) { $foundRoot = true; break; }
            $organizationId = $parent;
        }
        if (!$foundRoot) throw CashierV3CommandException::invalidContext('组织层级过深，请联系管理员。', ['reason' => 'personnel_organization_depth']);
        $service = app()->make(OrganizationScopeService::class);
        $organizations = array_map('intval', $service->getOrgIds($organizationId, true));
        return ['organizations' => $organizations, 'stores' => $this->storeIds($organizations)];
    }

    /** Queries real employment relationships; never creates a fake direct-organization membership for a store employee. */
    public function query(array $payload, CashierV3OperatorScope $operator): array
    {
        $salesperson = ($payload['selectorContext']['scope'] ?? '') === 'cashier_other_salespeople';
        $scope = $this->groupScope((int)$operator->organizationId());
        $context = (array)($payload['selectorContext'] ?? []);
        $node = $context['organizationNode'] ?? null;
        $organizations = $scope['organizations']; $stores = $scope['stores'];
        if ($node !== null) {
            if (!is_array($node) || !in_array($node['type'] ?? '', ['org', 'store'], true)
                || !(is_int($node['id'] ?? null) || (is_string($node['id'] ?? null) && ctype_digit($node['id']))) || (int)$node['id'] <= 0) {
                throw CashierV3CommandException::invalidContext('请选择有效组织或门店。', ['reason' => 'personnel_node_invalid']);
            }
            $id = (int)$node['id'];
            if ($node['type'] === 'store') {
                if (!in_array($id, $stores, true)) throw CashierV3CommandException::invalidContext('所选门店不在可选范围内。', ['reason' => 'personnel_node_outside_group']);
                $organizations = []; $stores = [$id];
            } else {
                if (!in_array($id, $organizations, true)) throw CashierV3CommandException::invalidContext('所选组织不在可选范围内。', ['reason' => 'personnel_node_outside_group']);
                $service = app()->make(OrganizationScopeService::class);
                $organizations = array_values(array_intersect($organizations, array_map('intval', $service->getOrgIds($id, true))));
                $stores = array_values(array_intersect($stores, $this->storeIds($organizations)));
            }
        }
        $page = max(1, (int)($payload['page'] ?? 1)); $size = min(20, max(1, (int)($payload['pageSize'] ?? 20)));
        $keyword = trim((string)($payload['keyword'] ?? ''));
        $metadata = ['organizationTree' => $this->tree($scope), 'allowedStoreIds' => $scope['stores']];
        if (!empty($context['organizationOptionsOnly']) || (!$node && mb_strlen($keyword) < 2)) {
            return $metadata + ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => $size, 'requiresKeyword' => true, 'isLoading' => false];
        }
        // These integer lists were produced only by the authenticated group and checked node above.
        $orgList = implode(',', $organizations ?: [0]); $storeList = implode(',', $stores ?: [0]);
        $direct = "EXISTS (SELECT 1 FROM eb_organization_employee oe WHERE oe.employee_id=e.id AND oe.org_id IN ($orgList) AND oe.status=1 AND oe.is_del=0)";
        $staff = "EXISTS (SELECT 1 FROM eb_system_store_staff ss WHERE ss.employee_id=e.id AND ss.store_id IN ($storeList) AND ss.status=1 AND ss.is_del=0)";
        $where = "e.status=1 AND e.is_del=0 AND e.employment_type_version>0 AND (e.employment_type_code IN ('internal','partner','outsourced') OR e.employment_type_code IS NULL) AND ($direct OR $staff)";
        // Sales attribution requires an authoritative type; legacy null types remain craftsmen-only.
        if ($salesperson) $where .= ' AND e.employment_type_code IS NOT NULL';
        $params = [];
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_') . '%';
            $where .= " AND (e.name LIKE ? OR EXISTS (SELECT 1 FROM eb_system_store_staff ss WHERE ss.employee_id=e.id AND ss.store_id IN ($storeList) AND ss.status=1 AND ss.is_del=0 AND (ss.staff_name LIKE ? OR ss.account LIKE ?)) OR EXISTS (SELECT 1 FROM eb_organization_employee oe WHERE oe.employee_id=e.id AND oe.org_id IN ($orgList) AND oe.status=1 AND oe.is_del=0 AND oe.job_title LIKE ?))";
            $params = [$like, $like, $like, $like];
        }
        $total = (int)Db::query("SELECT COUNT(*) n FROM eb_employee e WHERE $where", $params)[0]['n'];
        $page = min($page, max(1, (int)ceil($total / $size))); $offset = ($page - 1) * $size;
        $rows = Db::query("SELECT e.id,e.name,e.employment_type_code,e.employment_type_version,(SELECT MIN(st.name) FROM eb_system_store_staff ss JOIN eb_system_store st ON st.id=ss.store_id WHERE ss.employee_id=e.id AND ss.store_id IN ($storeList) AND ss.status=1 AND ss.is_del=0) store_name FROM eb_employee e WHERE $where ORDER BY e.id ASC LIMIT $offset,$size", $params);
        $positions = $salesperson ? app()->make(CashierV3QueryEntitySelectorServices::class)->organizationDirectPositionMap(array_column($rows, 'id')) : [];
        $records = array_map(static function ($row) use ($operator, $salesperson, $positions) {
            // Keep the same authoritative direct-position grouping as the locked sales writer.
            $position = $positions[(int)$row['id']] ?? [];
            $positionId = (int)($position['position_id'] ?? 0);
            $independent = !empty($position['performance_independent']);
            $id = \app\services\cashier\v3\CashierV3PersonnelIdentity::organizationStaffId((int)$row['id']);
            return ['id'=>$id, 'staffId'=>$id, 'employeeId'=>(int)$row['id'], 'name'=>$row['name'], 'staffName'=>$row['name'],
                'storeId'=>$operator->storeId(), 'storeName'=>$row['store_name'] ?: '组织直属', 'staffNo'=>'', 'positionName'=>$position['position_name'] ?? '在职员工',
                'positionId'=>$positionId, 'performanceIndependent'=>$independent, 'allocationGroupKey'=>$independent ? 'independent:' . $positionId : 'normal', 'employeeTypeCode'=>$row['employment_type_code'] ?? 'internal',
                'employeeTypeAuthorityVersion'=>(int)$row['employment_type_version'], 'craftsmanEligible'=>!$salesperson, 'salespersonEligible'=>$salesperson, 'partnerDefaultRatio'=>0,
                'personnelSource'=>'other', 'craftsmanPerformanceType'=>'commission_labor', 'laborDefaultFeeCents'=>0, 'selectable'=>true, 'groupScoped'=>true];
        }, $rows);
        return $metadata + ['records'=>$records, 'total'=>$total, 'page'=>$page, 'pageSize'=>$size, 'requiresKeyword'=>false, 'isLoading'=>false];
    }

    /** Final save rechecks current real employment; selector results never grant permanent cross-group access. */
    public function lockedMembership(int $employeeId, int $currentStoreId): array
    {
        \app\services\cashier\v3\CashierV3TransactionGuard::assertInTransaction('otherCraftsmanMembership');
        // Read the actual organization binding even while the old migration-mode flag is stale.
        $organizationId = (int)Db::name('organization_store')->where('store_id', $currentStoreId)->lock(true)->value('org_id');
        $scope = $this->groupScope($organizationId);
        $direct = Db::name('organization_employee')->where('employee_id', $employeeId)
            ->whereIn('org_id', $scope['organizations'] ?: [0])->where('status', 1)->where('is_del', 0)
            ->order('id')->lock(true)->select()->toArray();
        $staff = Db::name('system_store_staff')->where('employee_id', $employeeId)
            ->whereIn('store_id', $scope['stores'] ?: [0])->where('status', 1)->where('is_del', 0)
            ->order('id')->lock(true)->select()->toArray();
        if (!$direct && !$staff) throw CashierV3CommandException::invalidContext('该人员已不在当前可选组织范围，请重新选择。', ['reason' => 'other_craftsman_membership_inactive']);
        return ['directIds' => array_map('intval', array_column($direct, 'id')),
            'staffIds' => array_map('intval', array_column($staff, 'id'))];
    }

    /** Read current bindings rather than a process-lifetime organization/store cache after transfers. */
    private function storeIds(array $organizations): array
    {
        if (!$organizations) return [];
        return array_values(array_unique(array_map('intval', Db::name('organization_store')->alias('os')
            ->join('system_store s', 's.id=os.store_id')->whereIn('os.org_id', $organizations)
            ->where('s.is_del', 0)->column('os.store_id'))));
    }

    /** Keep empty direct-personnel organizations, but never disclose nodes outside this group. */
    private function tree(array $scope): array
    {
        $allowed = array_fill_keys($scope['organizations'], true);
        $walk = function ($nodes) use (&$walk, $allowed) {
            $out=[]; foreach ($nodes as $node) {
                if (($node['node_type'] ?? '') !== 'store' && !isset($allowed[(int)$node['id']])) continue;
                // Picker metadata carries labels/identities only, not store contact or address details.
                $children=$walk($node['children'] ?? []);
                $node=array_intersect_key($node, array_flip(['id','org_id','store_id','node_type','name','title','label']));
                $node['children']=$children; $out[]=$node;
            } return $out;
        };
        return $walk(app()->make(OrganizationScopeService::class)->buildPickerTree($scope['stores'], true, true));
    }
}
