<?php
namespace app\controller\store\report;

use app\controller\store\AuthController;
use app\Request;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreOperationsReportAnnotationServices;

class UnifiedReport extends AuthController
{
    private const PLATFORM_ONLY_REPORTS = [
        'six_dimension_item_deal_analysis', 'six_dimension_cash_consumption_analysis',
        'six_dimension_consumption_refund_detail', 'six_dimension_performance_deal',
        'six_dimension_performance_distribution', 'six_dimension_performance_market_distribution',
    ];

    public function catalog(StoreUnifiedReportServices $services)
    {
        $catalog = array_values(array_filter($services->catalog(), function (array $report): bool {
            return !$this->isPlatformOnlyReport((string)($report['code'] ?? ''));
        }));
        return $this->success($catalog);
    }
    public function definitions(StoreUnifiedReportServices $services) { return $this->success($services->definitions()); }
    public function query(Request $request, StoreUnifiedReportServices $services)
    {
        if ((int)$this->storeId <= 0) return app('json')->fail('门店未登录');
        $input = $request->getMore($this->inputRules());
        if ($this->isPlatformOnlyReport((string)($input['report'] ?? ''))) return app('json')->fail('该报表仅支持平台端访问');
        try { return $this->success($services->query((int)$this->storeId, $input)); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }
    public function export(Request $request, StoreUnifiedReportServices $services)
    {
        if ((int)$this->storeId <= 0) return app('json')->fail('门店未登录');
        $input = $request->getMore($this->inputRules());
        if ($this->isPlatformOnlyReport((string)($input['report'] ?? ''))) return app('json')->fail('该报表仅支持平台端访问');
        try { return $this->success($services->export((int)$this->storeId, $input)); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function operationsCategories(StoreOperationsReportAnnotationServices $services)
    {
        return $this->success($services->listAvailableCategories($this->annotationContext()));
    }

    public function saveCategory(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        try { return $this->success($services->saveCategoryConfig($this->annotationContext(), $request->post())); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function annotations(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        if ($this->isPlatformOnlyReport((string)$request->get('report_code', ''))) return app('json')->fail('该报表仅支持平台端访问');
        try { return $this->success($services->listAnnotations($this->annotationContext(), $request->get())); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function saveAnnotation(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        $payload = $request->post();
        if ($this->isPlatformOnlyReport((string)($payload['report_code'] ?? ''))) return app('json')->fail('该报表仅支持平台端访问');
        try { return $this->success($services->saveAnnotation($this->annotationContext(), $payload)); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    private function annotationContext(): array
    {
        $staff = is_array($this->storeStaffInfo) ? $this->storeStaffInfo : [];
        return [
            'tenant_id' => '0', 'store_id' => (int)$this->storeId, 'store_ids' => [(int)$this->storeId],
            'operator_id' => (int)$this->storeStaffId,
            'operator_name' => (string)($staff['real_name'] ?? $staff['nickname'] ?? ''),
        ];
    }
    private function inputRules(): array
    {
        return [['report','partner_item_summary'],['start_date',''],['end_date',''],['dataset','sale'],['metric','cash_performance'],['item_id',''],['payment_method',''],['operator_id',0],['channel_id',0],['customer_segment','all'],['consumption_metric','cash'],['sleep_months',3],['year',0],['category_id',0],['category_path',''],['product_type',''],['partner_name',''],['salesperson_id',0],['sales_manager_id',0],['guide_id',0],['craftsman_id',0],['page',1],['limit',20]];
    }

    private function isPlatformOnlyReport(string $report): bool
    {
        return in_array(trim($report), self::PLATFORM_ONLY_REPORTS, true);
    }
}
