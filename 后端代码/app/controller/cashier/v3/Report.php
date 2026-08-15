<?php

namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\Request;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreOperationsReportAnnotationServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

/** Cashier V3 门店经营报表的会话受控只读入口。 */
class Report extends AuthController
{
    public function catalog(StoreUnifiedReportServices $services)
    {
        return $this->success('ok', $services->catalog());
    }

    public function scope(OrganizationScopeService $organizations)
    {
        if ((int)$this->storeId <= 0) return app('json')->fail('门店未登录');
        $dataScope = $this->dataScope();
        $allowed = $dataScope->visibleStoreIds();
        if ($allowed === null) $allowed = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->column('id');
        $allowed = array_values(array_unique(array_filter(array_map('intval', (array)$allowed))));
        return $this->success('ok', [
            'tree' => $organizations->buildPickerTree($allowed),
            'allowed_store_ids' => $allowed,
            'authorization_mode' => $dataScope->authorizationMode(),
            'permission_version' => $dataScope->permissionVersion(),
        ]);
    }

    public function query(Request $request, StoreUnifiedReportServices $services)
    {
        return $this->respond($request, $services, false);
    }

    public function export(Request $request, StoreUnifiedReportServices $services)
    {
        return $this->respond($request, $services, true);
    }

    public function operationsCategories(StoreOperationsReportAnnotationServices $services)
    {
        return $this->success('ok', $services->listAvailableCategories($this->annotationContext()));
    }

    public function saveCategory(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        try { return $this->success('ok', $services->saveCategoryConfig($this->annotationContext(), $request->post())); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function annotations(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        return $this->success('ok', $services->listAnnotations($this->annotationContext(), $request->get()));
    }

    public function saveAnnotation(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        try { return $this->success('ok', $services->saveAnnotation($this->annotationContext(), $request->post())); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    private function annotationContext(): array
    {
        $scope = CashierV3Bootstrap::dispatcher()->scopeResolver()->operatorScope((int)$this->storeId, (int)$this->cashierId);
        $info = is_array($this->cashierInfo) ? $this->cashierInfo : [];
        return [
            'tenant_id' => $scope->tenantId(), 'organization_id' => $scope->organizationId(),
            'store_id' => $scope->storeId(), 'store_ids' => [$scope->storeId()],
            'operator_id' => $scope->operatorId(), 'operator_name' => (string)($info['real_name'] ?? $info['nickname'] ?? ''),
        ];
    }

    private function respond(Request $request, StoreUnifiedReportServices $services, bool $export)
    {
        if ((int)$this->storeId <= 0) return app('json')->fail('门店未登录');
        try {
            $input = $request->getMore($this->inputRules());
            $storeIds = $this->scopeStoreIds($input);
            if (!$storeIds) return app('json')->fail('当前账号没有可查看的门店范围');
            $result = $export ? $services->export($storeIds, $input) : $services->query($storeIds, $input);
            return $this->success('ok', $result);
        } catch (\InvalidArgumentException $exception) {
            return app('json')->fail($exception->getMessage());
        }
    }

    private function inputRules(): array
    {
        return [
            ['report', 'partner_item_summary'], ['start_date', ''], ['end_date', ''],
            ['dataset', 'sale'], ['metric', 'cash_performance'],
            ['item_id', ''], ['payment_method', ''], ['operator_id', 0],
            ['channel_id', 0], ['customer_segment', 'all'],
            ['consumption_metric', 'cash'], ['sleep_months', 3], ['year', 0],
            ['category_id', 0], ['category_path', ''], ['product_type', ''], ['partner_name', ''],
            ['salesperson_id', 0], ['sales_manager_id', 0], ['guide_id', 0], ['craftsman_id', 0],
            ['store_ids', ''],
            ['page', 1], ['limit', 20],
        ];
    }

    private function dataScope()
    {
        $dispatcher = CashierV3Bootstrap::dispatcher();
        $operatorScope = $dispatcher->scopeResolver()->operatorScope((int)$this->storeId, (int)$this->cashierId);
        return $dispatcher->dataScopeFactory()->build(
            (int)$this->storeId,
            (int)$this->cashierId,
            is_array($this->cashierInfo) ? $this->cashierInfo : [],
            $operatorScope->tenantId(),
            $operatorScope->organizationId()
        );
    }

    private function scopeStoreIds(array $input): array
    {
        $allowed = $this->dataScope()->visibleStoreIds();
        if ($allowed === null) $allowed = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->column('id');
        $allowed = array_values(array_unique(array_filter(array_map('intval', (array)$allowed))));
        $requested = $input['store_ids'] ?? '';
        if (is_string($requested)) $requested = preg_split('/[,\s]+/', trim($requested), -1, PREG_SPLIT_NO_EMPTY);
        $requested = array_values(array_unique(array_filter(array_map('intval', (array)$requested))));
        return $requested ? array_values(array_intersect($requested, $allowed)) : $allowed;
    }
}
