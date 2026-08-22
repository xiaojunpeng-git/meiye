<?php

namespace app\controller\admin\v1\report;

use app\controller\admin\AuthController;
use app\model\store\SystemStore;
use app\Request;
use app\services\organization\OrganizationScopeService;
use app\services\organization\EmployeeDataScopeServices;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreOperationsReportAnnotationServices;
use app\services\report\StoreReportParticipantScopeServices;
use app\services\report\StoreUnifiedReportPhaseThreeFoundationServices;
use app\services\report\StoreUnifiedReportPhaseThreeServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\GroupManagementDashboardServices;
use app\services\report\GroupManagementDashboardTargetServices;
use app\services\report\MemberManagementDashboardServices;
use app\services\report\ProductManagementDashboardServices;
use app\services\report\BusinessLedgerServices;
use app\services\report\CustomerAnalyticsServices;
use app\services\report\EmployeeDashboardServices;
use app\services\report\StaffingQuotaServices;
use app\services\system\SystemRoleServices;
use think\facade\Db;

/** 平台端复用门店业务部门的统一事实报表，范围由组织权限服务裁剪。 */
class UnifiedReport extends AuthController
{
    private const STORE_OPERATION_REPORTS = [
        'partner_item_summary', 'partner_item_detail', 'member_consumption_detail',
        'store_item_analysis', 'store_craftsman_consumption', 'store_salesperson_performance',
        'market_performance', 'market_detail', 'member_visit_analysis', 'member_visit_annual_summary',
        'field_acquisition_detail', 'field_acquisition_summary', 'cross_industry_customer_detail',
        'cross_industry_customer_summary', 'new_customer_analysis', 'new_customer_analysis_summary',
        'salesperson_large_order_statistics', 'store_refund_ledger',
    ];

    private const SIX_DIMENSION_REPORTS = [
        'six_dimension_item_deal_analysis', 'six_dimension_cash_consumption_analysis',
        'six_dimension_consumption_refund_detail', 'six_dimension_performance_deal',
        'six_dimension_performance_distribution', 'six_dimension_performance_market_distribution',
    ];

    /** 第四阶段除六维数据分析表外均只允许平台端访问。 */
    private const PHASE_FOUR_REPORTS = [
        'operations_pre_sale_bdegh', 'operations_customer_status_bdegh', 'operations_referral_beautician_pre_sale',
        'operations_performance_comparison', 'operations_health_data', 'operations_beauty_item',
        'operations_annual_member_consumption', 'six_dimension_analysis', 'marketing_acquisition_pre_sale',
        'marketing_referral_pre_sale', 'marketing_post_sale_performance', 'marketing_health_data',
        'marketing_beauty_new_item', 'marketing_customer_status', 'product_monetization_performance_total',
        'product_monetization_beauty_performance_total', 'product_monetization_beauty_item',
        'product_monetization_beauty_market_distribution', 'product_monetization_six_dimension_performance',
        'product_monetization_six_dimension_item_performance', 'product_monetization_six_dimension_efficiency',
        'product_monetization_six_dimension_market_distribution', 'product_monetization_haomei_performance',
        'product_monetization_private_performance', 'product_monetization_private_item_performance',
        'product_monetization_private_efficiency', 'product_monetization_private_market_distribution',
    ];

    private const PHASE_SIX_REPORTS = [
        'phase_six_garden_item_analysis', 'phase_six_monthly_featured_item',
        'phase_six_headquarters_acquisition', 'phase_six_other_multi_payment',
        'phase_six_salary_summary', 'phase_six_salary_detail', 'phase_six_training_employee',
        'phase_six_acquisition_source', 'phase_six_human_store_health',
    ];

    /** 第九阶段客户一级菜单专属报表，不继承会员看板权限。 */
    private const CUSTOMER_ANALYTICS_REPORTS = [
        'customer_overview', 'customer_source_analysis', 'customer_visit_analysis',
        'customer_store_health', 'customer_consumption_tier', 'customer_cash_performance',
        'customer_refund_performance', 'customer_item_analysis', 'customer_unconsumed_analysis',
    ];

    public function catalog(StoreUnifiedReportServices $services, StoreUnifiedReportPhaseThreeServices $phaseThree, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix)
    {
        $entries = array_merge($services->catalog(), $phaseThree::catalogEntries(), $phaseFour::catalogEntries(true), $phaseSix::catalogEntries(true), CustomerAnalyticsServices::catalogEntries());
        return app('json')->success(array_values(array_filter($entries, function (array $entry): bool {
            $code = (string)($entry['code'] ?? '');
            return $this->canAccessReport($code);
        })));
    }

    /** 第九阶段客户分析统一查询；列表、合计、下钻和导出共享同一读取服务。 */
    public function customerAnalytics(Request $request, CustomerAnalyticsServices $services)
    {
        if (!$this->canAccessCustomerAnalytics((string)$request->param('report', 'customer_overview'))) {
            return app('json')->fail('当前账号未配置客户分析权限');
        }
        try {
            $input = $request->getMore([
                ['report', 'customer_overview'], ['start_date', ''], ['end_date', ''], ['store_ids', ''],
                ['org_id', 0], ['store_id', 0], ['region_id', 0], ['source_id', 0], ['item_id', ''],
                ['page', 1], ['limit', 20], ['consumption_metric', 'cash'], ['category_path', ''],
            ]);
            $range = $this->customerAnalyticsRange($input);
            $stores = $this->scopedStoreIds($input);
            if (!$stores) throw new \InvalidArgumentException('无权限或当前范围无门店');
            $input['_report_scope'] = $this->reportAuthorization();
            return app('json')->success($services->read((string)$input['report'], $stores, $range, $input));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** 客户分析导出只改变分页，不改变查询条件、权限和指标口径。 */
    public function customerAnalyticsExport(Request $request, CustomerAnalyticsServices $services)
    {
        if (!$this->canAccessCustomerAnalytics((string)$request->param('report', 'customer_overview'))) {
            return app('json')->fail('当前账号未配置客户分析权限');
        }
        try {
            $input = $request->getMore([
                ['report', 'customer_overview'], ['start_date', ''], ['end_date', ''], ['store_ids', ''],
                ['org_id', 0], ['store_id', 0], ['region_id', 0], ['source_id', 0], ['item_id', ''],
                ['consumption_metric', 'cash'], ['category_path', ''],
            ]);
            $range = $this->customerAnalyticsRange($input);
            $stores = $this->scopedStoreIds($input);
            if (!$stores) throw new \InvalidArgumentException('无权限或当前范围无门店');
            $input['_report_scope'] = $this->reportAuthorization();
            $file = $services->export((string)$input['report'], $stores, $range, $input);
            return download($file['path'], $file['filename']);
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** 员工看板统一聚合读取；客户端筛选只能缩小当前账号权限范围。 */
    public function employeeDashboard(Request $request, EmployeeDashboardServices $services)
    {
        // The platform menu keeps the existing employee-area permission
        // (admin-staff); accept the report-specific permission when a tenant
        // has configured it, without changing any stored menu records.
        if (!$this->hasMenuPermission('admin-report-employee-dashboard') && !$this->hasMenuPermission('admin-staff')) {
            return app('json')->fail('当前账号未配置员工看板权限');
        }
        try {
            $input = $request->getMore([
                ['start_date', ''], ['end_date', ''], ['store_ids', ''],
                ['org_id', 0], ['store_id', 0], ['role', ''],
            ]);
            $range = $this->employeeDashboardRange($input);
            $stores = $this->scopedStoreIds($input);
            if (!$stores) throw new \InvalidArgumentException('无权限或当前范围无门店');
            return app('json')->success($services->dashboard($stores, $range, $input));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * Vue 3 平台报表与门店端使用相同的组织选择契约；范围仍只由后台身份决定。
     */
    public function scope(OrganizationScopeService $organizations)
    {
        $allowed = $this->allowedStoreIds(0, 0);
        $reportScope = $this->reportAuthorization();
        return app('json')->success([
            'tree' => $organizations->buildPickerTree($allowed),
            'allowed_store_ids' => $allowed,
            'authorization_mode' => (string)$reportScope['mode'],
            'permission_version' => 'platform-admin-' . (int)$this->adminId . '-'
                . (int)$this->adminType . '-' . md5(json_encode($reportScope, JSON_UNESCAPED_UNICODE)),
        ]);
    }

    /** 集团管理看板专属查询。目标、经营事实和旧目标功能彼此隔离。 */
    public function groupDashboard(Request $request, GroupManagementDashboardServices $services)
    {
        if (!$this->canAccessGroupDashboard()) return app('json')->fail('当前账号未配置集团管理看板权限');
        try {
            $input = $request->getMore([
                ['start_date', ''], ['end_date', ''], ['category_id', 0], ['store_ids', ''],
                ['org_id', 0], ['store_id', 0],
            ]);
            $context = $this->groupDashboardContext($input);
            return app('json')->success($services->dashboard($context, $input));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** 目标管理抽屉切换年度时读取 12 个月门店行。 */
    public function groupDashboardTargets(Request $request, GroupManagementDashboardServices $services)
    {
        if (!$this->canAccessGroupDashboard()) return app('json')->fail('当前账号未配置集团管理看板权限');
        try {
            $input = $request->getMore([['year', (int)date('Y')], ['store_ids', ''], ['org_id', 0], ['store_id', 0]]);
            $context = $this->groupDashboardContext($input);
            return app('json')->success((new GroupManagementDashboardTargetServices())->yearTargets($context, (int)$input['year']));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** 看板专属门店月度目标保存，不会进入任何既有目标或报表补充接口。 */
    public function saveGroupDashboardTarget(Request $request, GroupManagementDashboardServices $services)
    {
        if (!$this->canAccessGroupDashboard()) return app('json')->fail('当前账号未配置集团管理看板权限');
        try {
            $payload = $request->post();
            $context = $this->groupDashboardContext($payload);
            return app('json')->success($services->targetSave($context, $payload));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function groupDashboardDrilldown(Request $request, GroupManagementDashboardServices $services)
    {
        if (!$this->canAccessGroupDashboard()) return app('json')->fail('当前账号未配置集团管理看板权限');
        try {
            $input = $request->getMore([
                ['start_date', ''], ['end_date', ''], ['category_id', 0], ['store_ids', ''],
                ['org_id', 0], ['store_id', 0], ['metric_code', 'cash_performance'],
            ]);
            return app('json')->success($services->drilldown($this->groupDashboardContext($input), $input));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** Phase 7 member dashboard; store scope is intersected again in the service. */
    public function memberDashboard(Request $request, MemberManagementDashboardServices $services)
    {
        if (!$this->hasMenuPermission('admin-report-member-management-dashboard')) {
            return app('json')->fail('当前账号未配置会员看板权限');
        }
        try {
            $input = $request->getMore([
                ['period', 'today'], ['start_date', ''], ['end_date', ''], ['store_ids', ''],
                ['org_id', 0], ['store_id', 0], ['category_id', 0],
            ]);
            $scopeStores = $this->scopedStoreIds([
                'org_id' => (int)$input['org_id'],
                'store_id' => (int)$input['store_id'],
                'store_ids' => (string)$input['store_ids'],
            ]);
            return app('json')->success($services->dashboard([
                'tenant_id' => '0',
                'store_ids' => $scopeStores,
                'admin_id' => (int)$this->adminId,
                'admin_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            ], $input));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** 第八阶段商品看板；与集团/会员看板使用独立菜单权限。 */
    public function productDashboard(Request $request, ProductManagementDashboardServices $services)
    {
        if (!$this->hasMenuPermission('admin-report-product-dashboard')) {
            return app('json')->fail('当前账号未配置商品看板权限');
        }
        try {
            $input = $request->getMore([
                ['period', 'month'], ['start_date', ''], ['end_date', ''], ['store_ids', ''],
                ['org_id', 0], ['store_id', 0], ['category_id', 0], ['product_type', 'all'],
            ]);
            $stores = $this->scopedStoreIds([
                'org_id' => (int)$input['org_id'], 'store_id' => (int)$input['store_id'],
                'store_ids' => (string)$input['store_ids'],
            ]);
            if (!$stores) throw new \InvalidArgumentException('无权限或当前范围无门店');
            $costVisible = $this->hasMenuPermission('inventory-v3-platform-batch-cost');
            return app('json')->success($services->dashboard([
                'tenant_id' => '0', 'store_ids' => $stores, 'cost_visible' => $costVisible,
                'admin_id' => (int)$this->adminId,
                'admin_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            ], $input));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function ledgerCatalog(BusinessLedgerServices $services)
    {
        if (!$this->hasMenuPermission('admin-data-engineering-management')) return app('json')->fail('当前账号未配置工程管理权限');
        return app('json')->success($services->catalog());
    }

    public function ledgerList(Request $request, BusinessLedgerServices $services)
    {
        if (!$this->ledgerPermission((string)$request->param('type', ''))) return app('json')->fail('当前账号未配置该台账权限');
        $input = $request->getMore([['type', ''], ['page', 1], ['limit', 20], ['keyword', ''], ['start_date', ''], ['end_date', ''], ['org_id', 0], ['store_id', 0], ['store_ids', '']]);
        try { $stores = $this->scopedStoreIds($input); return app('json')->success($services->list((string)$input['type'], ['store_ids' => $stores], $input)); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function ledgerSave(Request $request, BusinessLedgerServices $services)
    {
        if (!$this->ledgerPermission((string)$request->param('type', $request->post('type', '')))) return app('json')->fail('当前账号未配置该台账权限');
        $payload = $request->post(); $input = ['org_id' => (int)($payload['org_id'] ?? 0), 'store_id' => (int)($payload['store_id'] ?? 0), 'store_ids' => (string)($payload['store_ids'] ?? '')];
        try { return app('json')->success($services->save((string)($payload['type'] ?? ''), ['store_ids' => $this->scopedStoreIds($input)], $payload, ['id' => $this->adminId, 'name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? '')])); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function ledgerRead(Request $request, BusinessLedgerServices $services, $id = 0)
    {
        $input = $request->getMore([['type', ''], ['org_id', 0], ['store_id', 0], ['store_ids', '']]);
        if (!$this->ledgerPermission((string)$input['type'])) return app('json')->fail('当前账号未配置该台账权限');
        try { return app('json')->success($services->read((string)$input['type'], (int)($id ?: $request->param('id', 0)), ['store_ids' => $this->scopedStoreIds($input)])); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function ledgerVoid(Request $request, BusinessLedgerServices $services, $id = 0)
    {
        $payload = $request->post(); $input = ['org_id' => (int)($payload['org_id'] ?? 0), 'store_id' => (int)($payload['store_id'] ?? 0), 'store_ids' => (string)($payload['store_ids'] ?? '')];
        if (!$this->ledgerPermission((string)($payload['type'] ?? ''))) return app('json')->fail('当前账号未配置该台账权限');
        try { return app('json')->success($services->void((string)($payload['type'] ?? ''), (int)($id ?: ($payload['id'] ?? 0)), (int)($payload['version'] ?? 0), ['store_ids' => $this->scopedStoreIds($input)], ['id' => $this->adminId, 'name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? '')])); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function ledgerExport(Request $request, BusinessLedgerServices $services)
    {
        if (!$this->ledgerPermission((string)$request->param('type', ''))) return app('json')->fail('当前账号未配置该台账权限');
        $input = $request->getMore([['type', ''], ['keyword', ''], ['start_date', ''], ['end_date', ''], ['org_id', 0], ['store_id', 0], ['store_ids', '']]);
        try { $file = $services->export((string)$input['type'], ['store_ids' => $this->scopedStoreIds($input)], $input); return download($file['path'], $file['filename']); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function ledgerReminders(Request $request, BusinessLedgerServices $services)
    {
        if (!$this->hasMenuPermission('admin-data-engineering-management')) return app('json')->fail('当前账号未配置工程管理权限');
        $input = $request->getMore([['org_id', 0], ['store_id', 0], ['store_ids', '']]);
        return app('json')->success($services->reminders(['store_ids' => $this->scopedStoreIds($input)]));
    }

    private function ledgerPermission(string $type): bool
    {
        $map = ['store_building' => 'store-building', 'engineering_quality' => 'engineering-quality', 'engineering_repair' => 'engineering-repair', 'rent_renewal' => 'rent-renewal'];
        return $this->hasMenuPermission('admin-data-engineering-management') && isset($map[$type]) && $this->hasMenuPermission('admin-data-engineering-management-' . $map[$type]);
    }

    public function query(Request $request, StoreUnifiedReportServices $services, StoreUnifiedReportPhaseThreeServices $phaseThree, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix)
    {
        return $this->respond($request, $services, $phaseThree, $phaseFour, $phaseSix, false);
    }

    public function export(Request $request, StoreUnifiedReportServices $services, StoreUnifiedReportPhaseThreeServices $phaseThree, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix)
    {
        return $this->respond($request, $services, $phaseThree, $phaseFour, $phaseSix, true);
    }

    public function phaseSixStaffing(StoreUnifiedReportPhaseSixServices $services)
    {
        return app('json')->success($services->staffing($this->allowedStoreIds(0, 0)));
    }

    public function savePhaseSixStaffing(Request $request, StoreUnifiedReportPhaseSixServices $services)
    {
        try {
            return app('json')->success($services->saveStaffing([
                'admin_id' => (int)$this->adminId,
                'admin_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'store_ids' => $this->allowedStoreIds(0, 0),
            ], $request->post()));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** 岗位编制功能权限用户可维护整张统一岗位编制表。 */
    public function staffingQuota(Request $request, StaffingQuotaServices $services)
    {
        if (!$this->hasMenuPermission(StaffingQuotaServices::PERMISSION)) {
            return app('json')->fail('当前账号未配置岗位编制功能');
        }
        try {
            return app('json')->success($services->list($request->get()));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function saveStaffingQuota(Request $request, StaffingQuotaServices $services)
    {
        if (!$this->hasMenuPermission(StaffingQuotaServices::PERMISSION)) {
            return app('json')->fail('当前账号未配置岗位编制功能');
        }
        try {
            return app('json')->success($services->save($request->post(), [
                'id' => (int)$this->adminId,
                'name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            ]));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function consumptionTiers(StoreUnifiedReportPhaseThreeFoundationServices $services)
    {
        if (!$this->canManageConsumptionTiers()) return app('json')->fail('当前账号未配置消费分级设置权限');
        return app('json')->success($services->consumptionTiers('0', false));
    }

    public function saveConsumptionTier(Request $request, StoreUnifiedReportPhaseThreeFoundationServices $services)
    {
        if (!$this->canManageConsumptionTiers()) return app('json')->fail('当前账号未配置消费分级设置权限');
        try {
            return app('json')->success($services->saveConsumptionTier([
                'tenant_id' => '0', 'admin_id' => (int)$this->adminId,
                'admin_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            ], $request->post()));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function sortConsumptionTiers(Request $request, StoreUnifiedReportPhaseThreeFoundationServices $services)
    {
        if (!$this->canManageConsumptionTiers()) return app('json')->fail('当前账号未配置消费分级设置权限');
        try {
            return app('json')->success($services->sortConsumptionTiers([
                'tenant_id' => '0', 'admin_id' => (int)$this->adminId,
                'admin_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            ], $request->post()));
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
        $report = trim((string)$request->get('report_code', ''));
        if (!$this->canAccessAnnotationReport($report)) return app('json')->fail('当前账号未配置该报表权限');
        try {
            return app('json')->success($services->listAnnotations($this->annotationContext(), $request->get()));
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function saveAnnotation(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        $payload = $request->post();
        if (!$this->canAccessAnnotationReport(trim((string)($payload['report_code'] ?? '')))) {
            return app('json')->fail('当前账号未配置该报表权限');
        }
        if ((string)($payload['report_code'] ?? '') === 'phase_six_human_store_health'
            && (string)($payload['field_key'] ?? '') === 'beautician_establishment_count') {
            return app('json')->fail('美容师编制已统一到岗位编制功能维护');
        }
        $context = $this->annotationContext();
        $phaseFourMonthly = $this->isPhaseFourMonthlyAnnotation($payload);
        if ((string)$context['authorization_mode'] !== 'self_participant' && !$phaseFourMonthly) {
            $storeIds = $this->allowedStoreIds((int)($payload['org_id'] ?? 0), (int)($payload['store_id'] ?? 0));
            $storeId = (int)($payload['store_id'] ?? 0);
            if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) return app('json')->fail('无权限编辑该门店报表');
            $context = $this->annotationContext($storeId);
        }
        try {
            return app('json')->success($services->saveAnnotation($context, $payload));
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
        $reportScope = $this->reportAuthorization();
        if ((string)$reportScope['mode'] === 'self_participant') {
            $query->where('e.id', (int)$reportScope['employee_id']);
        }
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
        $reportScope = $this->reportAuthorization();
        $selfParticipant = (string)$reportScope['mode'] === 'self_participant';
        return [
            'tenant_id' => '0', 'admin_id' => (int)$this->adminId,
            'admin_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            'store_id' => $storeId,
            'store_ids' => $selfParticipant ? null : ($storeId > 0 ? [$storeId] : $this->allowedStoreIds(0, 0)),
            'authorization_mode' => (string)$reportScope['mode'],
            'participant_employee_id' => $selfParticipant ? (int)$reportScope['employee_id'] : 0,
        ];
    }

    private function respond(
        Request $request,
        StoreUnifiedReportServices $services,
        StoreUnifiedReportPhaseThreeServices $phaseThree,
        StoreUnifiedReportPhaseFourServices $phaseFour,
        StoreUnifiedReportPhaseSixServices $phaseSix,
        bool $export
    )
    {
        try {
            $input = $request->getMore($this->inputRules());
            $report = trim((string)($input['report'] ?? ''));
            if (!$this->canAccessReport($report)) {
                return app('json')->fail('当前账号未配置该报表权限');
            }
            $reportScope = $this->reportAuthorization();
            if ((string)$reportScope['mode'] === 'none') {
                return app('json')->fail('当前账号没有可查看的数据范围');
            }
            if ((string)$reportScope['mode'] === 'self_participant') {
                $input['_report_scope'] = [
                    'mode' => 'self_participant',
                    'employee_id' => (int)$reportScope['employee_id'],
                ];
            }
            $storeIds = $this->scopedStoreIds($input);
            if (!$storeIds) return app('json')->fail('无权限或当前范围无门店');
            // 第四阶段平台报表必须先按稳定 code 分发。不能因为服务实例状态、
            // 目录加载顺序或旧服务回退，让已授权的第四阶段报表落入旧查询器。
            if (in_array($report, self::PHASE_SIX_REPORTS, true)) {
                $input['_authorized_store_ids'] = $this->allowedStoreIds(0, 0);
                $range = ['start' => (string)($input['start_date'] ?? ''), 'end' => (string)($input['end_date'] ?? '')];
                $queryInput = $export ? array_merge($input, ['_internal_all' => true]) : $input;
                $result = $phaseSix->query($report, $storeIds, $range, $queryInput);
                if ($export) {
                    $result = [
                        'filename' => '统一数据报表-' . (string)($result['title'] ?? '报表') . '-' . date('YmdHis') . '.csv',
                        'columns' => $result['columns'] ?? [], 'records' => $result['records'] ?? [],
                        'summary_row' => $result['summary_row'] ?? [], 'column_groups' => $result['column_groups'] ?? [],
                        'metric_version' => $result['metric_version'] ?? StoreUnifiedReportPhaseSixServices::METRIC_VERSION,
                        'data_as_of' => $result['data_as_of'] ?? '', 'aggregation_status' => $result['aggregation_status'] ?? '',
                    ];
                }
            } elseif (in_array($report, self::PHASE_FOUR_REPORTS, true)) {
                $input['_authorized_store_ids'] = $this->allowedStoreIds(0, 0);
                $range = ['start' => (string)($input['start_date'] ?? ''), 'end' => (string)($input['end_date'] ?? '')];
                $queryInput = $export ? array_merge($input, ['_internal_all' => true]) : $input;
                $result = $phaseFour->query($report, $storeIds, $range, $queryInput);
                if ($export) {
                    $result = [
                        'filename' => '统一数据报表-' . (string)($result['title'] ?? '报表') . '-' . date('YmdHis') . '.csv',
                        'columns' => $result['columns'] ?? [], 'records' => $result['records'] ?? [],
                        'summary_row' => $result['summary_row'] ?? [], 'column_groups' => $result['column_groups'] ?? [],
                        'metric_version' => $result['metric_version'] ?? StoreUnifiedReportPhaseThreeServices::METRIC_VERSION,
                        'data_as_of' => $result['data_as_of'] ?? '', 'aggregation_status' => $result['aggregation_status'] ?? '',
                    ];
                }
            } elseif ($phaseThree->supports($report)) {
                $input['_authorized_store_ids'] = $this->allowedStoreIds(0, 0);
                $range = ['start' => (string)($input['start_date'] ?? ''), 'end' => (string)($input['end_date'] ?? '')];
                $queryInput = $export ? array_merge($input, ['_internal_all' => true]) : $input;
                $result = $phaseThree->query($report, $storeIds, $range, $queryInput);
                if ($export) {
                    $result = [
                        'filename' => '统一数据报表-' . (string)($result['title'] ?? '报表') . '-' . date('YmdHis') . '.csv',
                        'columns' => $result['columns'] ?? [], 'records' => $result['records'] ?? [],
                        'summary_row' => $result['summary_row'] ?? [], 'column_groups' => $result['column_groups'] ?? [],
                        'metric_version' => $result['metric_version'] ?? StoreUnifiedReportPhaseThreeServices::METRIC_VERSION,
                        'data_as_of' => $result['data_as_of'] ?? '', 'aggregation_status' => $result['aggregation_status'] ?? '',
                    ];
                }
            } else {
                $result = $export ? $services->export($storeIds, $input) : $services->query($storeIds, $input);
            }
            return app('json')->success($result);
        } catch (\InvalidArgumentException $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * 平台报表的页面菜单是授权权威源。第一阶段与第二阶段的门店运营报表
     * 共用查询接口，因此必须按 report 参数再次校验，不能只依赖前端菜单。
     */
    private function canAccessStoreOperationReport(string $report): bool
    {
        if (!in_array($report, self::STORE_OPERATION_REPORTS, true)) return true;
        if (!(int)($this->adminInfo['level'] ?? 0) && (int)$this->adminType !== 3) return true;

        $roles = $this->adminInfo['roles'] ?? [];
        $roles = is_string($roles) ? array_filter(explode(',', $roles)) : (array)$roles;
        if (!$roles) return false;

        $expected = 'admin-report-store-operations-' . $report;
        $menus = app()->make(SystemRoleServices::class)->getRolesByAuth($roles, 1);
        foreach ($menus as $menu) {
            if ((string)($menu['unique_auth'] ?? '') === $expected) return true;
        }
        return false;
    }

    private function canAccessSixDimensionReport(string $report): bool
    {
        if (!in_array($report, self::SIX_DIMENSION_REPORTS, true)) return true;
        return $this->hasMenuPermission('admin-report-six-dimension-' . $report);
    }

    private function canAccessGroupDashboard(): bool
    {
        return $this->hasMenuPermission('admin-report-group-management-dashboard');
    }

    private function groupDashboardContext(array $input): array
    {
        $stores = $this->scopedStoreIds([
            'org_id' => (int)($input['org_id'] ?? 0),
            'store_id' => (int)($input['store_id'] ?? 0),
            'store_ids' => (string)($input['store_ids'] ?? ''),
        ]);
        if ($stores === []) throw new \InvalidArgumentException('无权限或当前范围无门店');
        return [
            'tenant_id' => '0', 'store_ids' => $stores, 'admin_id' => (int)$this->adminId,
            'admin_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
        ];
    }

    private function canAccessReport(string $report): bool
    {
        if (in_array($report, self::CUSTOMER_ANALYTICS_REPORTS, true)) return $this->canAccessCustomerAnalytics($report);
        if (in_array($report, self::SIX_DIMENSION_REPORTS, true)) return $this->canAccessSixDimensionReport($report);
        if (in_array($report, self::PHASE_SIX_REPORTS, true)) return $this->hasMenuPermission('admin-report-phase-six-' . $report);
        if (in_array($report, self::PHASE_FOUR_REPORTS, true)) return $this->hasMenuPermission('admin-report-phase-four-' . $report);
        if (in_array($report, self::STORE_OPERATION_REPORTS, true)) return $this->canAccessStoreOperationReport($report);
        return true;
    }

    private function canAccessCustomerAnalytics(string $report): bool
    {
        if (!in_array($report, self::CUSTOMER_ANALYTICS_REPORTS, true)) return false;
        if ($this->hasMenuPermission('admin-customer-analytics')) return true;
        return $this->hasMenuPermission('admin-customer-analytics-' . $this->customerAuthCode($report));
    }

    private function customerAuthCode(string $report): string
    {
        return [
            'customer_overview' => 'customer_overview',
            'customer_source_analysis' => 'customer_source_analysis',
            'customer_visit_analysis' => 'customer_visit_analysis',
            'customer_store_health' => 'customer_store_health',
            'customer_consumption_tier' => 'customer_consumption_tier',
            'customer_cash_performance' => 'customer_cash_performance',
            'customer_refund_performance' => 'customer_refund_performance',
            'customer_item_analysis' => 'customer_item_analysis',
            'customer_unconsumed_analysis' => 'customer_unconsumed_analysis',
        ][$report] ?? '';
    }

    private function customerAnalyticsRange(array $input): array
    {
        $start = trim((string)($input['start_date'] ?? ''));
        $end = trim((string)($input['end_date'] ?? ''));
        if ($start === '' || $end === '') {
            $year = (int)date('Y');
            $start = $year . '-01-01'; $end = date('Y-m-d');
        }
        $valid = static function (string $date): bool {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            return $parsed !== false && $parsed->format('Y-m-d') === $date;
        };
        if (!$valid($start) || !$valid($end) || $start > $end) throw new \InvalidArgumentException('统计日期范围不正确');
        return ['start' => $start, 'end' => $end];
    }

    private function employeeDashboardRange(array $input): array
    {
        $start = trim((string)($input['start_date'] ?? '')) ?: date('Y-m-01');
        $end = trim((string)($input['end_date'] ?? '')) ?: date('Y-m-d');
        $valid = static function (string $date): bool {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            return $parsed !== false && $parsed->format('Y-m-d') === $date;
        };
        if (!$valid($start) || !$valid($end) || $start > $end) throw new \InvalidArgumentException('统计日期范围不正确');
        return ['start' => $start, 'end' => $end];
    }

    private function canAccessAnnotationReport(string $report): bool
    {
        if (in_array($report, self::STORE_OPERATION_REPORTS, true)
            || in_array($report, self::SIX_DIMENSION_REPORTS, true)
            || in_array($report, self::PHASE_FOUR_REPORTS, true)
            || in_array($report, self::PHASE_SIX_REPORTS, true)) {
            return $this->canAccessReport($report);
        }
        return true;
    }

    /** Fourth-stage yearly/monthly targets belong to the authorized report scope, not a single store. */
    private function isPhaseFourMonthlyAnnotation(array $payload): bool
    {
        return (string)($payload['subject_type'] ?? '') === 'phase_four_month'
            && (int)($payload['store_id'] ?? 0) === 0
            && in_array(trim((string)($payload['report_code'] ?? '')), self::PHASE_FOUR_REPORTS, true);
    }

    private function canManageConsumptionTiers(): bool
    {
        return $this->hasMenuPermission('setting-shop-six-dimension-consumption-tier');
    }

    private function hasMenuPermission(string $uniqueAuth): bool
    {
        if (!(int)($this->adminInfo['level'] ?? 0) && (int)$this->adminType !== 3) return true;
        $roles = $this->adminInfo['roles'] ?? [];
        $roles = is_string($roles) ? array_filter(explode(',', $roles)) : (array)$roles;
        if (!$roles) return false;
        foreach (app()->make(SystemRoleServices::class)->getRolesByAuth($roles, 1) as $menu) {
            if ((string)($menu['unique_auth'] ?? '') === $uniqueAuth) return true;
        }
        return false;
    }

    private function inputRules(): array
    {
        return [
            ['report', 'overview'], ['start_date', ''], ['end_date', ''], ['dataset', 'sale'], ['metric', 'cash_performance'],
            ['item_id', ''], ['payment_method', ''], ['operator_id', 0], ['channel_id', 0], ['customer_segment', 'all'], ['consumption_metric', 'cash'], ['sleep_months', 3], ['year', 0], ['category_id', 0], ['category_path', ''], ['product_type', ''], ['partner_name', ''], ['salesperson_id', 0], ['sales_manager_id', 0], ['guide_id', 0], ['craftsman_id', 0], ['page', 1], ['limit', 20],
            ['org_id', 0], ['store_id', 0], ['store_ids', ''],
            ['dimension_code', ''], ['payment_method_code', ''], ['metric_code', ''],
            ['company_dimension_id', ''], ['city_manager_dimension_id', ''],
            ['dimension_as_of', ''], ['drill_month', ''], ['six_dimension_only', 0],
            ['tier_id', ''], ['month', ''],
            ['target_amount', ''], ['target_count', ''], ['visit_count', ''], ['unit_price_min', ''], ['unit_price_max', ''],
            ['mode', 'count'],
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
        $allowed = $this->reportAuthorization()['store_ids'];
        if ($orgId <= 0 && $storeId <= 0) $orgId = $scope->resolveGroupRootOrgId();
        return $scope->resolveDashboardStoreIds($orgId, $storeId, $allowed);
    }

    /**
     * 平台报表使用员工管理中的同一数据权限。员工身份只取认证后的管理员投影，
     * 个人权限的门店集合由本人参与事实反查，客户端参数不能覆盖 employee_id。
     *
     * @return array{mode:string,employee_id:int,store_ids:array<int,int>}
     */
    private function reportAuthorization(): array
    {
        if ((int)$this->adminType === 3 && $this->agentId) {
            $stores = app()->make(OrganizationScopeService::class)
                ->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId);
            return ['mode' => 'agent_limited', 'employee_id' => 0, 'store_ids' => $this->normalizeStoreIds($stores)];
        }

        $employeeId = max(0, (int)($this->adminInfo['employee_id'] ?? 0));
        if ($employeeId > 0) {
            /** @var EmployeeDataScopeServices $dataScope */
            $dataScope = app()->make(EmployeeDataScopeServices::class);
            $resolved = $dataScope->resolveEffectiveStoreIds($employeeId, 0, (array)$this->adminInfo);
            if ($resolved === null) {
                return ['mode' => 'all', 'employee_id' => $employeeId, 'store_ids' => $this->allStoreIds()];
            }
            if ($dataScope->resolvePrimaryHqScopeMode($employeeId) === EmployeeDataScopeServices::MODE_PERSONAL) {
                $stores = app()->make(StoreReportParticipantScopeServices::class)
                    ->participatingStoreIds('0', $employeeId);
                return ['mode' => 'self_participant', 'employee_id' => $employeeId, 'store_ids' => $this->normalizeStoreIds($stores)];
            }
            return ['mode' => 'stores', 'employee_id' => $employeeId, 'store_ids' => $this->normalizeStoreIds($resolved)];
        }

        // 尚未迁入统一员工身份的历史平台管理员维持原有范围，不静默降权。
        return ['mode' => 'platform_admin', 'employee_id' => 0, 'store_ids' => $this->allStoreIds()];
    }

    /** @return array<int,int> */
    private function allStoreIds(): array
    {
        return $this->normalizeStoreIds(
            SystemStore::where('is_del', 0)->where('name', '<>', '总部')->column('id') ?: []
        );
    }

    /** @return array<int,int> */
    private function normalizeStoreIds($storeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$storeIds))));
        sort($ids);
        return $ids;
    }
}
