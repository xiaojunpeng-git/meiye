<?php

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3PersonnelIdentity;
use app\services\cashier\v3\CashierV3ProjectLaborDefault;
use app\services\cashier\v3\CashierV3CraftsmanPerformanceDefault;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContracts;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

/**
 * Shared entity-selector read model. The current operator scope is the only
 * source of the store boundary; selector payloads may only narrow by keyword.
 */
final class CashierV3QueryEntitySelectorServices
{
    private const PERSON_SCOPES = [
        'query_filter' => false,
        'sales_performance_assignees' => true,
        'service_actual_craftsmen' => false,
        // 可被收银临时加入手艺人分配的当前门店在职人员；不要求
        // cashier_craftsman_enabled，结账仍由统一事实链路记录其分配。
        'cashier_other_craftsmen' => false,
        // 集团其他销售人沿用组织员工身份，不放宽当前门店订单的操作权限。
        'cashier_other_salespeople' => false,
        'reservation_craftsmen' => false,
        'member_exclusive_service_staff' => false,
        // Group attribution selectors deliberately use employee identity, not
        // a store-local staff row. They are keyword-gated to avoid loading
        // the whole group into a cashier page.
        'group_sales_managers' => true,
        'group_guides' => true,
        'group_attributions' => true,
    ];

    /** 仅按后端当前门店读取任职身份，分页去重；不提供分配资格、不允许扩大门店范围。 */
    private function queryFilterPeople(array $payload, CashierV3OperatorScope $operatorScope): array
    {
        $page = max(1, (int)($payload['page'] ?? 1));
        $size = min(20, max(1, (int)($payload['pageSize'] ?? 20)));
        $keyword = trim((string)($payload['keyword'] ?? ''));
        $query = Db::name('system_store_staff')->alias('ss')
            ->leftJoin('employee e', 'e.id=ss.employee_id')
            ->where('ss.store_id', $operatorScope->storeId());
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_') . '%';
            $query->where(function ($sub) use ($like) {
                $sub->whereLike('e.name', $like)->whereLike('ss.staff_name', $like, 'OR')->whereLike('ss.account', $like, 'OR');
            });
        }
        $total = (int)(clone $query)->count();
        $rows = $query->field('ss.id,ss.employee_id,ss.store_id,ss.staff_name,ss.account,e.name as employee_name')
            ->order('ss.id asc')->page($page, $size)->select()->toArray();
        $records = array_map(static function (array $row): array {
            $name = trim((string)($row['employee_name'] ?? '')) ?: (trim((string)$row['staff_name']) ?: (string)$row['account']);
            return ['id' => (int)$row['id'], 'staffId' => (int)$row['id'], 'employeeId' => (int)$row['employee_id'],
                'storeId' => (int)$row['store_id'], 'name' => $name, 'staffNo' => (string)$row['account'], 'selectable' => true];
        }, $rows);
        return ['records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => $size, 'isLoading' => false];
    }

    /**
     * @return array{records:array,total:int,page:int,pageSize:int,isLoading:bool}
     */
    public function query(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $entityType = trim((string)($payload['entityType'] ?? $payload['entity_type'] ?? ''));
        if ($entityType !== 'person') {
            throw $this->invalid('当前选择器暂不支持该对象类型。', 'query_entity_type_unsupported');
        }

        $selectorContext = is_array($payload['selectorContext'] ?? null)
            ? $payload['selectorContext']
            : [];
        $selectorScope = trim((string)($selectorContext['scope'] ?? ''));
        if (!array_key_exists($selectorScope, self::PERSON_SCOPES)) {
            throw $this->invalid('当前人员选择来源无效，请从正确的业务入口重新打开。', 'query_entity_scope_invalid');
        }
        if ($dataScope->forcedStoreId() !== $operatorScope->storeId()) {
            throw $this->invalid('当前门店上下文已变化，请刷新页面后重试。', 'query_entity_store_context_mismatch');
        }

        // 查询候选不是可分配人员：保留历史任职，不套用在职/销售资格限制。
        // 独立只读分支，不能改变下方结账和人员调整使用的候选口径。
        if ($selectorScope === 'query_filter') {
            return $this->queryFilterPeople($payload, $operatorScope);
        }

        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(20, max(1, (int)($payload['pageSize'] ?? $payload['page_size'] ?? 20)));
        $keyword = trim((string)($payload['keyword'] ?? $payload['search'] ?? ''));
        $isGroupAttribution = in_array($selectorScope, ['group_sales_managers', 'group_guides', 'group_attributions'], true);
        if ($isGroupAttribution && mb_strlen($keyword) < 2) {
            return [
                'records' => [],
                'total' => 0,
                'page' => $page,
                'pageSize' => $pageSize,
                'isLoading' => false,
                'requiresKeyword' => true,
            ];
        }
        if ($selectorScope === 'group_attributions') {
            return $this->queryGroupAttributions($keyword, $page, $pageSize, $operatorScope);
        }
        if (in_array($selectorScope, ['cashier_other_craftsmen', 'cashier_other_salespeople'], true)) {
            if (mb_strlen($keyword) < 2) {
                return ['records' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize,
                    'isLoading' => false, 'requiresKeyword' => true];
            }
            return $this->queryOrganizationCraftsmen($keyword, $page, $pageSize, $operatorScope,
                $selectorScope === 'cashier_other_salespeople');
        }
        $requiresEmploymentType = self::PERSON_SCOPES[$selectorScope];
        $projectId = (int)($selectorContext['projectId'] ?? $selectorContext['project_id'] ?? 0);
        $partnerDefaultRatio = $selectorScope === 'sales_performance_assignees'
            ? $this->partnerDefaultRatioForProject($dataScope->tenantId(), $projectId)
            : 0;
        $laborDefaultFeeCents = 0;
        $craftsmanDefaultUnitAmountCents = null;
        if ($selectorScope === 'service_actual_craftsmen' && $projectId > 0) {
            // 与购物车使用同一读模型；权益来源身份由后端重新核对，
            // 不能凭客户端传入的 holder ID 读取另一张卡的手工费。
            $laborDefaultFeeCents = CashierV3ProjectLaborDefault::entitlementCents(
                $projectId,
                (int)($selectorContext['entitlementInstanceId'] ?? 0),
                (int)($selectorContext['entitlementSourceDetailId'] ?? 0),
                $dataScope->tenantId()
            );
            // 候选只携带服务端核对后的单次默认值；null 让页面沿用本次实际金额。
            $craftsmanDefaultUnitAmountCents = CashierV3CraftsmanPerformanceDefault::entitlementUnitCents(
                $projectId,
                (int)($selectorContext['entitlementInstanceId'] ?? 0),
                (int)($selectorContext['entitlementSourceDetailId'] ?? 0),
                $dataScope->tenantId()
            );
        }
        $eligibilityColumn = $requiresEmploymentType
            ? 'ss.cashier_salesperson_enabled'
            : 'ss.cashier_craftsman_enabled';
        $requiresCraftsmanEligibility = $selectorScope === 'service_actual_craftsmen';

        $query = Db::name('system_store_staff')->alias('ss')
            ->join('employee e', 'e.id = ss.employee_id')
            // 岗位是收银分组核算的权威来源。只读取当前有效任职，不能
            // 让客户端传入的岗位名称或历史岗位影响分组。
            ->leftJoin(
                'staff_job_position sjp',
                'sjp.staff_id = ss.id AND sjp.status = 1 AND sjp.is_del = 0 AND sjp.end_time = 0'
            )
            ->leftJoin('position p', 'p.id = sjp.position_id AND p.status = 1')
            ->where('ss.status', 1)
            ->where('ss.is_del', 0)
            ->where('ss.employee_id', '>', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0);
        if ($isGroupAttribution) {
            // Group scope is bounded by the logged-in organization and its
            // descendants. The selector never accepts a client org/store.
            /** @var OrganizationScopeService $organizationScope */
            $organizationScope = app()->make(OrganizationScopeService::class);
            $groupStoreIds = $organizationScope->getOrgStoreIds((int)$operatorScope->organizationId(), true);
            if ($groupStoreIds === []) {
                return [
                    'records' => [],
                    'total' => 0,
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'isLoading' => false,
                    'requiresKeyword' => false,
                ];
            }
            $query->whereIn('ss.store_id', $groupStoreIds);
        } else {
            $query->where('ss.store_id', $operatorScope->storeId());
            if ($requiresEmploymentType || $requiresCraftsmanEligibility) {
                $query->where($eligibilityColumn, 1);
            }
        }
        if ($requiresEmploymentType) {
            $query->whereIn('e.employment_type_code', ['internal', 'partner', 'outsourced'])
                ->where('e.employment_type_version', '>', 0);
        }
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_') . '%';
            $query->where(function ($subQuery) use ($like) {
                $subQuery->whereLike('e.name', $like)
                    ->whereLike('ss.staff_name', $like, 'OR')
                    ->whereLike('ss.account', $like, 'OR');
            });
        }

        $total = (int)(clone $query)->count();
        if ($isGroupAttribution) {
            // Keep grouping explicit for ThinkPHP versions without Query::when.
            $query->group('e.id,e.name,e.employment_type_code,e.employment_type_version');
        }
        $rows = $query
            ->field($isGroupAttribution
                ? 'e.id as employee_id,MIN(ss.id) as staff_id,MIN(ss.store_id) as store_id,e.name as employee_name,MAX(ss.account) as account,MAX(ss.staff_name) as staff_name,e.employment_type_code,e.employment_type_version'
                : 'ss.id as staff_id,ss.employee_id,ss.store_id,ss.account,ss.staff_name,ss.cashier_salesperson_enabled,ss.cashier_craftsman_enabled,ss.craftsman_performance_type,sjp.position_id,p.name as position_name,p.performance_independent,e.name as employee_name,e.employment_type_code,e.employment_type_version')
            ->order($isGroupAttribution ? 'e.id asc' : 'ss.id asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        $storeName = (string)Db::name('system_store')
            ->where('id', $operatorScope->storeId())
            ->value('name');

        $records = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['employee_name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['staff_name'] ?? ''));
            }
            if ($name === '') {
                continue;
            }
            $records[] = [
                'id' => $isGroupAttribution ? (int)$row['employee_id'] : (int)$row['staff_id'],
                'staffId' => $isGroupAttribution ? (int)$row['employee_id'] : (int)$row['staff_id'],
                'employeeId' => (int)$row['employee_id'],
                'storeId' => (int)$row['store_id'],
                'name' => $name,
                'staffName' => (string)($row['staff_name'] ?? ''),
                'staffNo' => (string)($row['account'] ?? ''),
                'storeName' => $storeName,
                'positionId' => $isGroupAttribution ? 0 : (int)($row['position_id'] ?? 0),
                'positionName' => $isGroupAttribution
                    ? '在职员工'
                    : (trim((string)($row['position_name'] ?? '')) ?: '在职员工'),
                'performanceIndependent' => !$isGroupAttribution && (int)($row['performance_independent'] ?? 0) === 1,
                'allocationGroupKey' => !$isGroupAttribution && (int)($row['performance_independent'] ?? 0) === 1
                    ? 'independent:' . ((int)($row['position_id'] ?? 0) > 0 ? (int)$row['position_id'] : (int)$row['staff_id'])
                    : 'normal',
                'employeeTypeCode' => (string)($row['employment_type_code'] ?? ''),
                'employeeTypeAuthorityVersion' => (int)($row['employment_type_version'] ?? 0),
                'partnerDefaultRatio' => $partnerDefaultRatio,
                'salespersonEligible' => (int)($row['cashier_salesperson_enabled'] ?? 0) === 1,
                'craftsmanEligible' => (int)($row['cashier_craftsman_enabled'] ?? 0) === 1,
                'personnelSource' => $selectorScope === 'cashier_other_craftsmen' ? 'other' : 'store',
                // The service-performance type is configured on the store
                // tenure row.  Returning it with the candidate lets the UI
                // zero the inapplicable fields before submit; the write side
                // still re-reads this value under lock.
                'craftsmanPerformanceType' => (string)($row['craftsman_performance_type'] ?? 'commission_labor'),
                'laborDefaultFeeCents' => $selectorScope === 'service_actual_craftsmen' ? $laborDefaultFeeCents : 0,
                'craftsmanDefaultUnitAmountCents' => $selectorScope === 'service_actual_craftsmen'
                    ? $craftsmanDefaultUnitAmountCents : null,
                'selectable' => true,
                'groupScoped' => $isGroupAttribution,
                'attributionRole' => $selectorScope === 'group_sales_managers'
                    ? 'sales_manager'
                    : ($selectorScope === 'group_guides' ? 'guide' : 'guide_and_sales_manager'),
            ];
        }

        return [
            'records' => $records,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'isLoading' => false,
            'requiresKeyword' => false,
        ];
    }

    /**
     * 分类比例只是销售人选择器的默认值。它不参与结账校验、事实写入或
     * 最终业绩计算；没有分类配置时返回 0，由前端沿用原有均分默认。
     */
    private function partnerDefaultRatioForProject(string $tenantId, int $projectId): int
    {
        if ($tenantId === '' || $projectId <= 0) {
            return 0;
        }
        try {
            $product = Db::name('store_product')->where('id', $projectId)->field('id,pid,cate_id')->find();
            if (!is_array($product)) {
                return 0;
            }
            $categoryIds = $this->positiveIds((string)($product['cate_id'] ?? ''));
            $masterId = (int)($product['pid'] ?? 0);
            if (!$categoryIds && $masterId > 0 && $masterId !== $projectId) {
                $master = Db::name('store_product')->where('id', $masterId)->field('cate_id')->find();
                $categoryIds = $this->positiveIds((string)($master['cate_id'] ?? ''));
            }
            if (!$categoryIds) {
                return 0;
            }
            $configs = Db::name('cashier_v3_report_category_config')
                ->where('tenant_id', $tenantId)
                ->whereIn('category_id', $categoryIds)
                ->where('enabled', 1)
                ->order('version', 'desc')
                ->select()
                ->toArray();
            foreach ($configs as $config) {
                $ratio = (int)($config['partner_default_ratio'] ?? 0);
                if ($ratio >= 0 && $ratio <= 100) {
                    return $ratio;
                }
            }
        } catch (\Throwable $e) {
            // 迁移尚未执行时不阻断旧收银流程，回退到原有均分默认。
        }
        return 0;
    }

    /** @return array<int,string> */
    private function positiveIds(string $raw): array
    {
        $result = [];
        foreach (explode(',', $raw) as $part) {
            $id = trim($part);
            if ($id !== '' && ctype_digit($id) && (int)$id > 0) {
                $result[] = (string)(int)$id;
            }
        }
        return array_values(array_unique($result));
    }

    private function invalid(string $message, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private function queryGroupAttributions(string $keyword, int $page, int $pageSize, CashierV3OperatorScope $operatorScope): array
    {
        // 导购/销售经理是集团归属，不要求当前门店或当前组织存在任职关系。
        // 本地数据库是一库一租户，租户边界由 CashierV3OperatorScope 绑定；
        // 关键词门槛仍然保留，避免把全集团人员一次性加载到收银端。
        $like = '%' . addcslashes($keyword, '%_') . '%';
        $query = Db::name('employee')->alias('e')
            ->leftJoin('system_store_staff ss', 'ss.employee_id=e.id AND ss.status=1 AND ss.is_del=0')
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->where(function ($subQuery) use ($like) {
                $subQuery->whereLike('e.name', $like)
                    ->whereLike('ss.staff_name', $like, 'OR')
                    ->whereLike('ss.account', $like, 'OR');
            })
            ->group('e.id,e.name,e.employment_type_version')
            ->field('e.id as employee_id,e.name as employee_name,e.employment_type_version,MIN(ss.id) as staff_id,MAX(ss.store_id) as store_id,MAX(ss.staff_name) as staff_name,MAX(ss.account) as account');
        $total = (int)(clone $query)->count();
        $rows = $query->order('e.id asc')->page($page, $pageSize)->select()->toArray();
        $records = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['employee_name'] ?? $row['staff_name'] ?? ''));
            if ($name === '') continue;
            $records[] = [
                'id' => (int)$row['employee_id'],
                'staffId' => (int)($row['employee_id'] ?? 0),
                'employeeId' => (int)$row['employee_id'],
                'storeId' => (int)($row['store_id'] ?? 0),
                'name' => $name,
                'staffName' => (string)($row['staff_name'] ?? ''),
                'staffNo' => (string)($row['account'] ?? ''),
                'storeName' => '',
                'employeeTypeCode' => '',
                // 导购正式事实要求人员类型资料已生效；销售经理仍按在职
                // 身份选择，两个角色在同一搜索入口里不能混用资格门槛。
                'employeeTypeAuthorityVersion' => (int)($row['employment_type_version'] ?? 0),
                'salespersonEligible' => true,
                'craftsmanEligible' => false,
                'selectable' => true,
                'groupScoped' => true,
                'attributionRole' => (int)($row['employment_type_version'] ?? 0) > 0
                    ? 'guide_and_sales_manager' : 'sales_manager',
            ];
        }
        return ['records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'isLoading' => false, 'requiresKeyword' => false];
    }

    private function queryOrganizationCraftsmen(string $keyword, int $page, int $pageSize, CashierV3OperatorScope $operatorScope, bool $salesperson = false): array
    {
        $organizationId = (int)$operatorScope->organizationId();
        if ($organizationId <= 0) {
            return ['records' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'isLoading' => false, 'requiresKeyword' => false];
        }
        /** @var OrganizationScopeService $organizationScope */
        $organizationScope = app()->make(OrganizationScopeService::class);
        $organizationIds = $organizationScope->getOrgIds($organizationId, true);
        // 门店账号的组织锚点通常是“直属”等门店分支；其他手艺人属于同一集团的
        // 其他组织时，也必须在集团根组织的后端数据权限范围内可检索，不能只看当前
        // 门店分支。集团根由当前组织向上解析，绝不接受客户端传入组织参数。
        $rootOrganizationId = $organizationId;
        for ($i = 0; $i < 64 && $rootOrganizationId > 0; $i++) {
            $parentId = (int)Db::name('organization')->where('id', $rootOrganizationId)->value('pid');
            if ($parentId <= 0 || $parentId === $rootOrganizationId) break;
            $rootOrganizationId = $parentId;
        }
        if ($rootOrganizationId > 0 && $rootOrganizationId !== $organizationId) {
            $organizationIds = $organizationScope->getOrgIds($rootOrganizationId, true);
        }
        if ($organizationIds === []) {
            return ['records' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'isLoading' => false, 'requiresKeyword' => false];
        }
        $query = Db::name('organization_employee')->alias('oe')
            ->join('employee e', 'e.id = oe.employee_id')
            ->leftJoin('organization o', 'o.id = oe.org_id')
            ->whereIn('oe.org_id', $organizationIds)
            ->where('oe.status', 1)
            ->where('oe.is_del', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0)
            ->where('e.id', '>', 0)
            // 与结账锁内员工档案校验一致，未建立类型版本的组织员工不能先进入可选列表。
            ->where('e.employment_type_version', '>', 0)
            ->where(function ($subQuery) use ($salesperson) {
                $subQuery->whereIn('e.employment_type_code', CashierV3EntitlementProviderContracts::staffTypes());
                // 手艺人沿用历史空类型兼容；销售人写入业绩事实时必须有有效类型。
                if (!$salesperson) $subQuery->whereNull('e.employment_type_code', 'OR');
            });
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_') . '%';
            $query->where(function ($subQuery) use ($like) {
                $subQuery->whereLike('e.name', $like)
                    ->whereLike('oe.job_title', $like, 'OR')
                    ->whereLike('o.name', $like, 'OR');
            });
        }
        $total = (int)(clone $query)->count('DISTINCT e.id');
        $rows = $query
            ->field('e.id as employee_id,e.name as employee_name,e.employment_type_code,e.employment_type_version,MAX(o.name) as organization_name')
            ->group('e.id,e.name,e.employment_type_code,e.employment_type_version')
            ->order('e.id asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        $directPositions = $salesperson
            ? $this->organizationDirectPositionMap(array_column($rows, 'employee_id'))
            : [];
        $records = [];
        foreach ($rows as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            $name = trim((string)($row['employee_name'] ?? ''));
            if ($employeeId <= 0 || $name === '') continue;
            $position = $directPositions[$employeeId] ?? [];
            $positionId = (int)($position['position_id'] ?? 0);
            $positionName = trim((string)($position['position_name'] ?? ''));
            $performanceIndependent = (int)($position['performance_independent'] ?? 0) === 1;
            $records[] = [
                'id' => CashierV3PersonnelIdentity::organizationStaffId($employeeId),
                'staffId' => CashierV3PersonnelIdentity::organizationStaffId($employeeId),
                'employeeId' => $employeeId,
                'storeId' => $operatorScope->storeId(),
                'name' => $name,
                'staffName' => $name,
                'staffNo' => '',
                'storeName' => '组织：' . trim((string)($row['organization_name'] ?? '')),
                'positionId' => $positionId,
                'positionName' => $positionName !== '' ? $positionName : '在职员工',
                'performanceIndependent' => $performanceIndependent,
                'allocationGroupKey' => $performanceIndependent
                    ? 'independent:' . $positionId
                    : 'normal',
                'employeeTypeCode' => (string)($row['employment_type_code'] ?? 'internal'),
                'employeeTypeAuthorityVersion' => (int)$row['employment_type_version'],
                'partnerDefaultRatio' => 0,
                'salespersonEligible' => $salesperson,
                'craftsmanEligible' => !$salesperson,
                'personnelSource' => 'other',
                'craftsmanPerformanceType' => 'commission_labor',
                'laborDefaultFeeCents' => 0,
                'selectable' => true,
                'groupScoped' => true,
                'attributionRole' => '',
            ];
        }
        return ['records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'isLoading' => false, 'requiresKeyword' => false];
    }

    /**
     * 集团直属员工的岗位以 staff_job_position.staff_id=0 为权威来源。
     * 收银一个销售人只能落入一个核算组；存在多条有效直属岗位时优先独立核算岗位，
     * 同类岗位按最早关系 ID 稳定选择，避免查询、刷新和结账重校验产生不同结果。
     * 服务端退款草稿可复用此投影；调用方必须先限定可读员工，返回岗位不授予人员选择权限。
     *
     * @param array<int,mixed> $employeeIds
     * @return array<int,array{position_id:int,position_name:string,performance_independent:int}>
     */
    public function organizationDirectPositionMap(array $employeeIds, bool $lock = false): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($employeeIds === []) return [];

        $query = Db::name('staff_job_position')->alias('j')
            ->join('position p', 'p.id=j.position_id')
            ->whereIn('j.employee_id', $employeeIds)
            ->where('j.staff_id', 0)
            ->where('j.status', 1)
            ->where('j.is_del', 0)
            ->where('j.end_time', 0)
            ->where('p.status', 1)
            ->field('j.id,j.employee_id,j.position_id,p.name as position_name,p.performance_independent')
            ->order('j.employee_id', 'asc')
            ->order('p.performance_independent', 'desc')
            ->order('j.id', 'asc');
        if ($lock) $query->lock(true);

        $result = [];
        foreach ($query->select()->toArray() as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            if ($employeeId <= 0 || isset($result[$employeeId])) continue;
            $result[$employeeId] = [
                'position_id' => (int)($row['position_id'] ?? 0),
                'position_name' => trim((string)($row['position_name'] ?? '')),
                'performance_independent' => (int)($row['performance_independent'] ?? 0) === 1 ? 1 : 0,
            ];
        }
        return $result;
    }

    /**
     * Resolve an organisation salesperson again inside the write transaction.
     * The browser's virtual id and source marker are not proof of employment.
     * Return a fact-compatible row while keeping the sale's store as the business owner.
     */
    public function lockOrganizationSalesperson(int $staffId, CashierV3OperatorScope $operatorScope): array
    {
        CashierV3TransactionGuard::assertInTransaction('organizationSalesperson');
        $employeeId = CashierV3PersonnelIdentity::employeeIdFromStaffId($staffId);
        $organizationId = (int)$operatorScope->organizationId();
        if ($employeeId <= 0 || $organizationId <= 0) return [];
        $rootId = $organizationId;
        for ($i = 0; $i < 64; $i++) {
            $parentId = (int)Db::name('organization')->where('id', $rootId)->value('pid');
            if ($parentId <= 0 || $parentId === $rootId) break;
            $rootId = $parentId;
        }
        $organizationIds = app()->make(OrganizationScopeService::class)->getOrgIds($rootId, true);
        if ($organizationIds === []) return [];
        $row = Db::name('organization_employee')->alias('oe')
            ->join('employee e', 'e.id=oe.employee_id')
            ->where('oe.employee_id', $employeeId)->whereIn('oe.org_id', $organizationIds)
            ->where('oe.status', 1)->where('oe.is_del', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->where('e.employment_type_version', '>', 0)
            ->whereIn('e.employment_type_code', CashierV3EntitlementProviderContracts::staffTypes())
            ->field('e.id as employee_id,e.name as employee_name,e.employment_type_code,e.employment_type_version')
            ->lock(true)->find();
        $row = (array)$row;
        if ($row === [] || trim((string)($row['employee_name'] ?? '')) === '') return [];
        // 必须在同一结账事务内重读直属岗位，不能相信浏览器缓存或草稿中的
        // positionId/performanceIndependent，否则不同浏览器会写出不同核算事实。
        $position = $this->organizationDirectPositionMap([$employeeId], true)[$employeeId] ?? [];
        return ['id' => $staffId, 'employee_id' => $employeeId,
            'store_id' => $operatorScope->storeId(), 'staff_name' => (string)$row['employee_name'],
            'name' => (string)$row['employee_name'], 'employee_name' => (string)$row['employee_name'],
            'employment_type_code' => (string)$row['employment_type_code'],
            'employment_type_version' => (int)$row['employment_type_version'],
            'position_id' => (int)($position['position_id'] ?? 0),
            'position_name' => (string)($position['position_name'] ?? ''),
            'performance_independent' => (int)($position['performance_independent'] ?? 0),
            'personnel_source' => 'other'];
    }
}
