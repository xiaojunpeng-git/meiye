<?php

namespace app\controller\admin\v1\report;

use app\controller\admin\AuthController;
use app\model\store\SystemStore;
use app\Request;
use app\services\organization\OrganizationScopeService;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreOperationsReportAnnotationServices;

/** 平台端复用门店业务部门的统一事实报表，范围由组织权限服务裁剪。 */
class UnifiedReport extends AuthController
{
    public function catalog(StoreUnifiedReportServices $services)
    {
        return app('json')->success($services->catalog());
    }

    public function query(Request $request, StoreUnifiedReportServices $services)
    {
        $input = $request->getMore($this->inputRules());
        $storeIds = $this->allowedStoreIds((int)($input['org_id'] ?? 0), (int)($input['store_id'] ?? 0));
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
        $storeIds = $this->allowedStoreIds((int)($input['org_id'] ?? 0), (int)($input['store_id'] ?? 0));
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
            ['org_id', 0], ['store_id', 0],
        ];
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
