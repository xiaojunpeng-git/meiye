<?php

namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\Request;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreOperationsReportAnnotationServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;

/** Cashier V3 门店经营报表的会话受控只读入口。 */
class Report extends AuthController
{
    public function catalog(StoreUnifiedReportServices $services)
    {
        return $this->success('ok', $services->catalog());
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
            $result = $export
                ? $services->export((int)$this->storeId, $input)
                : $services->query((int)$this->storeId, $input);
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
            ['page', 1], ['limit', 20],
        ];
    }
}
