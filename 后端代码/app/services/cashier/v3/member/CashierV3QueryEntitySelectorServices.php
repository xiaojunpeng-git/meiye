<?php

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

/**
 * Shared entity-selector read model. The current operator scope is the only
 * source of the store boundary; selector payloads may only narrow by keyword.
 */
final class CashierV3QueryEntitySelectorServices
{
    private const PERSON_SCOPES = [
        'sales_performance_assignees' => true,
        'service_actual_craftsmen' => false,
        'reservation_craftsmen' => false,
        'member_exclusive_service_staff' => false,
        // Group attribution selectors deliberately use employee identity, not
        // a store-local staff row. They are keyword-gated to avoid loading
        // the whole group into a cashier page.
        'group_sales_managers' => true,
        'group_guides' => true,
        'group_attributions' => true,
    ];

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
        $requiresEmploymentType = self::PERSON_SCOPES[$selectorScope];
        $projectId = (int)($selectorContext['projectId'] ?? $selectorContext['project_id'] ?? 0);
        $partnerDefaultRatio = $selectorScope === 'sales_performance_assignees'
            ? $this->partnerDefaultRatioForProject($dataScope->tenantId(), $projectId)
            : 0;
        $laborDefaultFeeCents = 0;
        if ($selectorScope === 'service_actual_craftsmen' && $projectId > 0) {
            $ruleQuery = Db::name('cashier_v3_project_performance_rule')
                ->where('tenant_id', $dataScope->tenantId())
                ->where('project_id', $projectId)
                ->order('id', 'desc');
            $rule = $ruleQuery->find();
            if (is_array($rule) && array_key_exists('labor_configured_unit_amount_cents', $rule)) {
                $configured = $rule['labor_configured_unit_amount_cents'];
                $laborDefaultFeeCents = max(0, (int)$configured);
            } else {
                // 门店商品是平台项目的复制行（type=1，pid=平台项目ID），
                // 固定手工费规则归属于平台主项目，需回溯 pid 读取。
                $masterId = (int)(Db::name('store_product')
                    ->where('id', $projectId)
                    ->where('type', 1)
                    ->where('product_type', 6)
                    ->value('pid') ?: 0);
                if ($masterId > 0 && $masterId !== $projectId) {
                    $masterRule = Db::name('cashier_v3_project_performance_rule')
                        ->where('tenant_id', $dataScope->tenantId())
                        ->where('project_id', $masterId)
                        ->order('id', 'desc')
                        ->find();
                    if (is_array($masterRule) && array_key_exists('labor_configured_unit_amount_cents', $masterRule)) {
                        $laborDefaultFeeCents = max(0, (int)$masterRule['labor_configured_unit_amount_cents']);
                    }
                }
            }
        }
        $eligibilityColumn = $requiresEmploymentType
            ? 'ss.cashier_salesperson_enabled'
            : 'ss.cashier_craftsman_enabled';

        $query = Db::name('system_store_staff')->alias('ss')
            ->join('employee e', 'e.id = ss.employee_id')
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
            $query->where('ss.store_id', $operatorScope->storeId())
                ->where($eligibilityColumn, 1);
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
                : 'ss.id as staff_id,ss.employee_id,ss.store_id,ss.account,ss.staff_name,ss.cashier_salesperson_enabled,ss.cashier_craftsman_enabled,ss.craftsman_performance_type,e.name as employee_name,e.employment_type_code,e.employment_type_version')
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
                'employeeTypeCode' => (string)($row['employment_type_code'] ?? ''),
                'employeeTypeAuthorityVersion' => (int)($row['employment_type_version'] ?? 0),
                'partnerDefaultRatio' => $partnerDefaultRatio,
                'salespersonEligible' => (int)($row['cashier_salesperson_enabled'] ?? 0) === 1,
                'craftsmanEligible' => (int)($row['cashier_craftsman_enabled'] ?? 0) === 1,
                // The service-performance type is configured on the store
                // tenure row.  Returning it with the candidate lets the UI
                // zero the inapplicable fields before submit; the write side
                // still re-reads this value under lock.
                'craftsmanPerformanceType' => (string)($row['craftsman_performance_type'] ?? 'commission_labor'),
                'laborDefaultFeeCents' => $selectorScope === 'service_actual_craftsmen' ? $laborDefaultFeeCents : 0,
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
            ->group('e.id,e.name')
            ->field('e.id as employee_id,e.name as employee_name,MIN(ss.id) as staff_id,MAX(ss.store_id) as store_id,MAX(ss.staff_name) as staff_name,MAX(ss.account) as account');
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
                'employeeTypeAuthorityVersion' => 0,
                'salespersonEligible' => true,
                'craftsmanEligible' => false,
                'selectable' => true,
                'groupScoped' => true,
                'attributionRole' => 'guide_and_sales_manager',
            ];
        }
        return ['records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'isLoading' => false, 'requiresKeyword' => false];
    }
}
