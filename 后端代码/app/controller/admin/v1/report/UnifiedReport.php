<?php

namespace app\controller\admin\v1\report;

use app\controller\admin\AuthController;
use app\model\store\SystemStore;
use app\Request;
use app\services\organization\OrganizationScopeService;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreOperationsReportAnnotationServices;
use think\facade\Db;

/** 平台端复用门店业务部门的统一事实报表，范围由组织权限服务裁剪。 */
class UnifiedReport extends AuthController
{
    public function catalog(StoreUnifiedReportServices $services)
    {
        return app('json')->success($services->catalog());
    }

    /**
     * Vue 3 平台报表与门店端使用相同的组织选择契约；范围仍只由后台身份决定。
     */
    public function scope(OrganizationScopeService $organizations)
    {
        $allowed = $this->allowedStoreIds(0, 0);
        return app('json')->success([
            'tree' => $organizations->buildPickerTree($allowed),
            'allowed_store_ids' => $allowed,
            'authorization_mode' => (int)$this->adminType === 3 ? 'agent_limited' : 'platform_admin',
            'permission_version' => 'platform-admin-' . (int)$this->adminId . '-' . (int)$this->adminType,
        ]);
    }

    public function query(Request $request, StoreUnifiedReportServices $services)
    {
        $input = $request->getMore($this->inputRules());
        $storeIds = $this->scopedStoreIds($input);
        if (!$storeIds) return app('json')->fail('无权限或当前范围无门店');
        try {
            return app('json')->success($services->query($storeIds, $input));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function export(Request $request, StoreUnifiedReportServices $services)
    {
        $input = $request->getMore($this->inputRules());
        $storeIds = $this->scopedStoreIds($input);
        if (!$storeIds) return app('json')->fail('无权限或当前范围无门店');
        try {
            return app('json')->success($services->export($storeIds, $input));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function operationsCategories(StoreOperationsReportAnnotationServices $services)
    {
        return app('json')->success($services->listAvailableCategories($this->annotationContext()));
    }

    public function saveCategory(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        try {
            return app('json')->success($services->saveCategoryConfig($this->annotationContext(), $request->post()));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function annotations(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        return app('json')->success($services->listAnnotations($this->annotationContext(), $request->get()));
    }

    public function saveAnnotation(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        $payload = $request->post();
        $storeIds = $this->allowedStoreIds((int)($payload['org_id'] ?? 0), (int)($payload['store_id'] ?? 0));
        $storeId = (int)($payload['store_id'] ?? 0);
        if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) return app('json')->fail('无权限编辑该门店报表');
        try {
            return app('json')->success($services->saveAnnotation($this->annotationContext($storeId), $payload));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * 统一报表人员筛选只返回当前管理员可查看门店内的在职人员。
     * 人员 ID 是报表事实使用的 employee_id，不暴露收银工作台命令入口。
     */
    public function personnel(Request $request, OrganizationScopeService $organizations)
    {
        $input = $request->getMore([
            ['keyword', ''], ['role', ''], ['store_ids', ''], ['page', 1], ['limit', 20],
        ]);
        $keyword = trim((string)($input['keyword'] ?? ''));
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(20, max(1, (int)($input['limit'] ?? 20)));
        if (mb_strlen($keyword) < 2) {
            return app('json')->success(['records' => [], 'total' => 0, 'page' => $page, 'page_size' => $limit, 'requires_keyword' => true]);
        }
        $allowed = $organizations->resolveScopedStoreIdsFromRequest($this->allowedStoreIds(0, 0), $input);
        if (!$allowed) {
            return app('json')->success(['records' => [], 'total' => 0, 'page' => $page, 'page_size' => $limit, 'requires_keyword' => false]);
        }

        $role = trim((string)($input['role'] ?? ''));
        if (!in_array($role, ['salesperson_id', 'sales_manager_id', 'guide_id', 'craftsman_id'], true)) {
            return app('json')->fail('人员筛选类型无效');
        }
        $like = '%' . addcslashes($keyword, '%_') . '%';
        $query = Db::name('system_store_staff')->alias('ss')
            ->join('employee e', 'e.id=ss.employee_id')
            ->leftJoin('system_store st', 'st.id=ss.store_id')
            ->whereIn('ss.store_id', $allowed)
            ->where('ss.status', 1)->where('ss.is_del', 0)->where('ss.employee_id', '>', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->where(function ($subQuery) use ($like) {
                $subQuery->whereLike('e.name', $like)
                    ->whereLike('ss.staff_name', $like, 'OR')
                    ->whereLike('ss.account', $like, 'OR');
            });
        if ($role === 'salesperson_id') $query->where('ss.cashier_salesperson_enabled', 1);
        if ($role === 'craftsman_id') $query->where('ss.cashier_craftsman_enabled', 1);

        $total = (int)(clone $query)->count('DISTINCT e.id');
        $rows = $query->fieldRaw('e.id AS employee_id,MAX(e.name) AS employee_name,MIN(ss.id) AS staff_id,MIN(ss.store_id) AS store_id,MAX(st.name) AS store_name,MAX(ss.staff_name) AS staff_name,MAX(ss.account) AS staff_no')
            ->group('e.id')->order('e.id', 'asc')->page($page, $limit)->select()->toArray();
        $records = array_values(array_filter(array_map(static function (array $row): array {
            $name = trim((string)($row['employee_name'] ?? $row['staff_name'] ?? ''));
            return [
                'id' => (int)($row['employee_id'] ?? 0),
                'employeeId' => (int)($row['employee_id'] ?? 0),
                'staffId' => (int)($row['staff_id'] ?? 0),
                'storeId' => (int)($row['store_id'] ?? 0),
                'storeName' => (string)($row['store_name'] ?? ''),
                'name' => $name,
                'staffName' => (string)($row['staff_name'] ?? ''),
                'staffNo' => (string)($row['staff_no'] ?? ''),
            ];
        }, $rows), static fn(array $row): bool => $row['employeeId'] > 0 && $row['name'] !== ''));
        return app('json')->success(['records' => $records, 'total' => $total, 'page' => $page, 'page_size' => $limit, 'requires_keyword' => false]);
    }

    private function annotationContext(int $storeId = 0): array
    {
        return [
            'tenant_id' => '0', 'admin_id' => (int)$this->adminId,
            'admin_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            'store_id' => $storeId, 'store_ids' => $storeId > 0 ? [$storeId] : $this->allowedStoreIds(0, 0),
        ];
    }

    private function inputRules(): array
    {
        return [
            ['report', 'overview'], ['start_date', ''], ['end_date', ''], ['dataset', 'sale'], ['metric', 'cash_performance'],
            ['item_id', ''], ['payment_method', ''], ['operator_id', 0], ['channel_id', 0], ['customer_segment', 'all'], ['consumption_metric', 'cash'], ['sleep_months', 3], ['year', 0], ['category_id', 0], ['category_path', ''], ['product_type', ''], ['partner_name', ''], ['salesperson_id', 0], ['sales_manager_id', 0], ['guide_id', 0], ['craftsman_id', 0], ['page', 1], ['limit', 20],
            ['org_id', 0], ['store_id', 0], ['store_ids', ''],
        ];
    }

    private function scopedStoreIds(array $input): array
    {
        $scope = app()->make(OrganizationScopeService::class);
        return $scope->resolveScopedStoreIdsFromRequest(
            $this->allowedStoreIds((int)($input['org_id'] ?? 0), (int)($input['store_id'] ?? 0)),
            $input
        );
    }

    /** 不能相信前端传来的范围；仅可在当前后台身份拥有的门店中缩小查询。 */
    private function allowedStoreIds(int $orgId, int $storeId): array
    {
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        if ((int)$this->adminType === 3 && $this->agentId) {
            $allowed = $scope->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId);
        } else {
            $allowed = SystemStore::where('is_del', 0)->where('name', '<>', '总部')->column('id') ?: [];
            $allowed = array_values(array_filter(array_map('intval', $allowed)));
        }
        if ($orgId <= 0 && $storeId <= 0) $orgId = $scope->resolveGroupRootOrgId();
        return $scope->resolveDashboardStoreIds($orgId, $storeId, $allowed);
    }
}
