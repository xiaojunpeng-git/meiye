<?php
namespace app\controller\store\report;

use app\controller\store\AuthController;
use app\Request;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreReportGuestDisplayServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\StoreOperationsReportAnnotationServices;

class UnifiedReport extends AuthController
{
    private const PLATFORM_ONLY_REPORTS = [
        'six_dimension_item_deal_analysis', 'six_dimension_cash_consumption_analysis',
        'six_dimension_consumption_refund_detail', 'six_dimension_performance_deal',
        'six_dimension_performance_distribution', 'six_dimension_performance_market_distribution',
    ];

    public function catalog(StoreUnifiedReportServices $services, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix)
    {
        $catalog = array_values(array_filter(array_merge($services->catalog(), $phaseFour::catalogEntries(false), $phaseSix::catalogEntries(false)), function (array $report): bool {
            return !$this->isPlatformOnlyReport((string)($report['code'] ?? ''));
        }));
        return $this->success($catalog);
    }
    public function definitions(StoreUnifiedReportServices $services) { return $this->success($services->definitions()); }
    public function query(Request $request, StoreUnifiedReportServices $services, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix)
    {
        if ((int)$this->storeId <= 0) return app('json')->fail('门店未登录');
        $input = $request->getMore($this->inputRules());
        if ($this->isPlatformOnlyReport((string)($input['report'] ?? ''))) return app('json')->fail('该报表仅支持平台端访问');
        try {
            $report = (string)($input['report'] ?? '');
            $result = $phaseSix->supports($report) ? $phaseSix->query($report, [(int)$this->storeId], ['start' => (string)($input['start_date'] ?? ''), 'end' => (string)($input['end_date'] ?? '')], $input)
                : ($phaseFour->supports($report) ? $phaseFour->query($report, [(int)$this->storeId], ['start' => (string)($input['start_date'] ?? ''), 'end' => (string)($input['end_date'] ?? '')], $input)
                : $services->query((int)$this->storeId, $input));
            // 门店查询在权限裁剪后才换展示姓名；不能改变汇总金额或历史事实。
            return $this->success(StoreReportGuestDisplayServices::project($result));
        }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }
    public function export(Request $request, StoreUnifiedReportServices $services, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix)
    {
        if ((int)$this->storeId <= 0) return app('json')->fail('门店未登录');
        $input = $request->getMore($this->inputRules());
        if ($this->isPlatformOnlyReport((string)($input['report'] ?? ''))) return app('json')->fail('该报表仅支持平台端访问');
        try {
            $report = (string)($input['report'] ?? '');
            if ($phaseSix->supports($report) || $phaseFour->supports($report)) {
                $result = ($phaseSix->supports($report) ? $phaseSix : $phaseFour)->query($report, [(int)$this->storeId], ['start' => (string)($input['start_date'] ?? ''), 'end' => (string)($input['end_date'] ?? '')], array_merge($input, ['_internal_all' => true]));
                // 完整导出和分页查询共用游客投影，不能让 CSV 重新出现空姓名。
                $result = StoreReportGuestDisplayServices::project($result);
                return $this->success(['filename' => '统一数据报表-' . date('YmdHis') . '.csv', 'columns' => $result['columns'], 'records' => $result['records'], 'summary_row' => $result['summary_row'], 'column_groups' => $result['column_groups'], 'metric_version' => $result['metric_version']]);
            }
            return $this->success(StoreReportGuestDisplayServices::project($services->export((int)$this->storeId, $input)));
        }
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
        // 仅透传统一固定列筛选，字段白名单和统计后裁剪由报表服务负责。
        // Exact path only narrows a grouped report drilldown; free category search keeps its prefix semantics.
        // 薪资单号筛选及分类/指标下钻使用服务端列键和稳定 ID，门店权限仍二次裁剪。
        return [['report','partner_item_summary'],['start_date',''],['end_date',''],['row_filters',''],['dataset','sale'],['metric','cash_performance'],['item_id',''],['payment_method',''],['operator_id',0],['channel_id',0],['customer_segment','all'],['consumption_metric','cash'],['sleep_months',3],['year',0],['category_id',0],['category_path',''],['category_path_exact',''],['product_type',''],['partner_name',''],['salesperson_id',0],['sales_manager_id',0],['guide_id',0],['craftsman_id',0],['day_of_month',0],['employee_name',''],['document_no',''],['salary_employee_id',0],['salary_store_id',0],['salary_category_key',''],['salary_metric_key',''],['page',1],['limit',20]];
    }

    private function isPlatformOnlyReport(string $report): bool
    {
        $report = trim($report);
        return in_array($report, self::PLATFORM_ONLY_REPORTS, true)
            || in_array($report, StoreUnifiedReportPhaseFourServices::platformOnlyReportCodes(), true)
            || in_array($report, StoreUnifiedReportPhaseSixServices::platformOnlyReportCodes(), true);
    }
}
