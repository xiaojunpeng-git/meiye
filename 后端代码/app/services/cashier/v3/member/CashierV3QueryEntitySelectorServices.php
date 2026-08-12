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
        $isGroupAttribution = in_array($selectorScope, ['group_sales_managers', 'group_guides'], true);
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
        $requiresEmploymentType = self::PERSON_SCOPES[$selectorScope];
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
                : 'ss.id as staff_id,ss.employee_id,ss.store_id,ss.account,ss.staff_name,ss.cashier_salesperson_enabled,ss.cashier_craftsman_enabled,e.name as employee_name,e.employment_type_code,e.employment_type_version')
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
                'salespersonEligible' => (int)($row['cashier_salesperson_enabled'] ?? 0) === 1,
                'craftsmanEligible' => (int)($row['cashier_craftsman_enabled'] ?? 0) === 1,
                'selectable' => true,
                'groupScoped' => $isGroupAttribution,
                'attributionRole' => $selectorScope === 'group_sales_managers' ? 'sales_manager' : ($selectorScope === 'group_guides' ? 'guide' : ''),
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

    private function invalid(string $message, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
