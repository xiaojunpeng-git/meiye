<?php

namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\Request;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\StoreOperationsReportAnnotationServices;
use app\services\report\StoreReportParticipantScopeServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

/** Cashier V3 门店经营报表的会话受控只读入口。 */
class Report extends AuthController
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
        return $this->success('ok', $catalog);
    }

    public function scope(OrganizationScopeService $organizations)
    {
        if ((int)$this->storeId <= 0) return app('json')->fail('门店未登录');
        $dataScope = $this->dataScope();
        $selfParticipant = $dataScope->isSelfParticipantMode();
        if ($selfParticipant) {
            $tenantId = CashierV3Bootstrap::dispatcher()->scopeResolver()
                ->operatorScope((int)$this->storeId, (int)$this->cashierId)->tenantId();
            $allowed = (new StoreReportParticipantScopeServices())
                ->participatingStoreIds($tenantId, $dataScope->employeeId());
        } else {
            $allowed = $dataScope->visibleStoreIds();
            if ($allowed === null) $allowed = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->column('id');
        }
        $allowed = array_values(array_unique(array_filter(array_map('intval', (array)$allowed))));
        return $this->success('ok', [
            'tree' => $organizations->buildPickerTree($allowed),
            'allowed_store_ids' => $allowed,
            'authorization_mode' => $selfParticipant
                ? CashierV3DataScopeContext::MODE_SELF_PARTICIPANT
                : ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_NONE
                    ? CashierV3DataScopeContext::MODE_NONE : CashierV3DataScopeContext::MODE_STORES),
            'permission_version' => $dataScope->permissionVersion(),
        ]);
    }

    public function query(Request $request, StoreUnifiedReportServices $services, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix)
    {
        return $this->respond($request, $services, $phaseFour, $phaseSix, false);
    }

    public function export(Request $request, StoreUnifiedReportServices $services, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix)
    {
        return $this->respond($request, $services, $phaseFour, $phaseSix, true);
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
        if ($this->isPlatformOnlyReport((string)$request->get('report_code', ''))) {
            return app('json')->fail('该报表仅支持平台端访问');
        }
        try { return $this->success('ok', $services->listAnnotations($this->annotationContext(true), $request->get())); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function saveAnnotation(Request $request, StoreOperationsReportAnnotationServices $services)
    {
        $payload = $request->post();
        if ($this->isPlatformOnlyReport((string)($payload['report_code'] ?? ''))) {
            return app('json')->fail('该报表仅支持平台端访问');
        }
        try { return $this->success('ok', $services->saveAnnotation($this->annotationContext(true), $payload)); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    private function annotationContext(bool $participantAware = false): array
    {
        $scope = CashierV3Bootstrap::dispatcher()->scopeResolver()->operatorScope((int)$this->storeId, (int)$this->cashierId);
        $dataScope = $this->dataScope();
        $info = is_array($this->cashierInfo) ? $this->cashierInfo : [];
        $selfParticipant = $participantAware && $dataScope->isSelfParticipantMode();
        return [
            'tenant_id' => $scope->tenantId(), 'organization_id' => $scope->organizationId(),
            'store_id' => $scope->storeId(), 'store_ids' => $selfParticipant ? null : [$scope->storeId()],
            'operator_id' => $scope->operatorId(), 'operator_name' => (string)($info['real_name'] ?? $info['nickname'] ?? ''),
            'authorization_mode' => $dataScope->authorizationMode(),
            'participant_employee_id' => $selfParticipant ? $dataScope->employeeId() : 0,
        ];
    }

    private function respond(Request $request, StoreUnifiedReportServices $services, StoreUnifiedReportPhaseFourServices $phaseFour, StoreUnifiedReportPhaseSixServices $phaseSix, bool $export)
    {
        if ((int)$this->storeId <= 0) return app('json')->fail('门店未登录');
        try {
            $input = $request->getMore($this->inputRules());
            if ($this->isPlatformOnlyReport((string)($input['report'] ?? ''))) {
                return app('json')->fail('该报表仅支持平台端访问');
            }
            $dataScope = $this->dataScope();
            if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_NONE) {
                return app('json')->fail('当前账号没有可查看的数据范围');
            }
            if ($dataScope->isSelfParticipantMode()) {
                if ($dataScope->employeeId() <= 0) return app('json')->fail('当前账号未绑定有效员工');
                $input['_report_scope'] = [
                    'mode' => CashierV3DataScopeContext::MODE_SELF_PARTICIPANT,
                    'employee_id' => $dataScope->employeeId(),
                ];
                $storeIds = $this->selfParticipantStoreIds($input, $dataScope);
            } else {
                $storeIds = $this->scopeStoreIds($input, $dataScope);
            }
            if (!$storeIds) return app('json')->fail('当前账号没有可查看的门店范围');
            $report = (string)($input['report'] ?? '');
            $result = $phaseSix->supports($report)
                ? $phaseSix->query($report, $storeIds, ['start' => (string)($input['start_date'] ?? ''), 'end' => (string)($input['end_date'] ?? '')], $export ? array_merge($input, ['_internal_all' => true]) : $input)
                : ($phaseFour->supports($report)
                ? $phaseFour->query($report, $storeIds, ['start' => (string)($input['start_date'] ?? ''), 'end' => (string)($input['end_date'] ?? '')], $export ? array_merge($input, ['_internal_all' => true]) : $input)
                : ($export ? $services->export($storeIds, $input) : $services->query($storeIds, $input)));
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
            ['dimension_code', ''], ['payment_method_code', ''], ['metric_code', ''],
            ['unit_price_min', ''], ['unit_price_max', ''],
            ['mode', 'count'],
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

    private function scopeStoreIds(array $input, $dataScope = null): array
    {
        $allowed = ($dataScope ?: $this->dataScope())->visibleStoreIds();
        if ($allowed === null) $allowed = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->column('id');
        $allowed = array_values(array_unique(array_filter(array_map('intval', (array)$allowed))));
        $requested = $input['store_ids'] ?? '';
        if (is_string($requested)) $requested = preg_split('/[,\s]+/', trim($requested), -1, PREG_SPLIT_NO_EMPTY);
        $requested = array_values(array_unique(array_filter(array_map('intval', (array)$requested))));
        return $requested ? array_values(array_intersect($requested, $allowed)) : $allowed;
    }

    /** Restrict personal reports before querying facts; service-level participant filters remain in force. */
    private function selfParticipantStoreIds(array $input, $dataScope): array
    {
        $operatorScope = CashierV3Bootstrap::dispatcher()->scopeResolver()
            ->operatorScope((int)$this->storeId, (int)$this->cashierId);
        $allowed = (new StoreReportParticipantScopeServices())->participatingStoreIds(
            $operatorScope->tenantId(),
            $dataScope->employeeId()
        );
        $requested = $input['store_ids'] ?? '';
        if (is_string($requested)) $requested = preg_split('/[,\s]+/', trim($requested), -1, PREG_SPLIT_NO_EMPTY);
        $requested = array_values(array_unique(array_filter(array_map('intval', (array)$requested))));
        return $requested ? array_values(array_intersect($requested, $allowed)) : $allowed;
    }

    private function isPlatformOnlyReport(string $report): bool
    {
        $report = trim($report);
        return in_array($report, self::PLATFORM_ONLY_REPORTS, true)
            || in_array($report, StoreUnifiedReportPhaseFourServices::platformOnlyReportCodes(), true)
            || in_array($report, StoreUnifiedReportPhaseSixServices::platformOnlyReportCodes(), true);
    }
}
