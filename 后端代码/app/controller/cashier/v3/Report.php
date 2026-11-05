<?php

namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\Request;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreReportGuestDisplayServices;
use app\services\report\StoreReportXlsxWriter;
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
    /** 复用门店数据功能鉴权；新报表固定本次登录门店，不接受浏览器跨店参数。 */
    public function basicCatalog()
    {
        // 目录只返回岗位逐表授权的入口；仅拥有旧容器权限不能枚举其他报表。
        return $this->success('ok', \app\services\report\BasicReportHostServices::storeCatalogForFeatures($this->basicReportFeatures()));
    }

    public function basicQuery(Request $request)
    {
        try {
            $raw = (string)$request->get('query', '{}');
            if (strlen($raw) > 50000) throw new \InvalidArgumentException('查询条件过长');
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new \InvalidArgumentException('查询格式不正确');
            $code = (string)($payload['pageCode'] ?? '');
            \app\services\report\BasicReportCatalogServices::definition($code);
            $this->assertBasicReportFeature($code);
            $context=$this->basicContext(false);
            if ($code==='basic_salary_realtime') $context['salary_edit']=!empty($context['basic_registration_write']);
            return $this->success('ok', \app\services\report\BasicReportHostServices::query($context, $payload));
        } catch (\InvalidArgumentException | \JsonException $e) { return app('json')->fail($e->getMessage()); }
        catch (\app\services\query\UnifiedQueryException $e) { return app('json')->fail($e->getMessage()); }
        catch (\RuntimeException $e) { return app('json')->fail($e->getMessage()); }
    }

    /** 门店端同一报表导出入口；当前会话只能使用本店权限范围。 */
    public function basicExport(Request $request)
    {
        try {
            $raw = (string)$request->get('query', '{}');
            if (strlen($raw) > 50000) throw new \InvalidArgumentException('查询条件过长');
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new \InvalidArgumentException('查询格式不正确');
            $code = (string)($payload['pageCode'] ?? '');
            \app\services\report\BasicReportCatalogServices::definition($code);
            $this->assertBasicReportFeature($code);
            $file = (new \app\services\report\BasicReportXlsxExportServices())->export($this->basicContext(false), $payload);
            return (new StoreReportXlsxWriter())->fileResponse($file);
        } catch (\InvalidArgumentException | \JsonException | \RuntimeException $e) { return app('json')->fail($e->getMessage()); }
    }

    /** 门店只可修改当前登录店的工资人工项；冻结由共享服务事务锁强制校验。 */
    public function basicSalarySave(Request $request)
    {
        try {
            $this->assertBasicReportFeature('basic_salary_realtime');
            $context=$this->basicContext(true);
            $context['salary_edit']=!empty($context['basic_registration_write']);
            return $this->success('ok',(new \app\services\report\BasicSalaryManualServices())->save($context,$request->post()));
        } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function basicSave(Request $request)
    {
        try {
            $payload = $request->post();
            // 写入只属于刷单登记；逐表查询权限不能顺带开放其他登记写入口。
            $this->assertBasicReportFeature(\app\services\report\query\BasicRegistrationQueryProvider::PAGE_CODE);
            // ForceStoreSessionMiddleware 注入的store_id也须与认证门店一致。
            if (isset($payload['store_id']) && (int)$payload['store_id'] !== (int)$this->storeId) throw new \InvalidArgumentException('不能修改其他门店登记');
            $payload['store_id'] = (int)$this->storeId;
            return $this->success('ok', (new \app\services\report\BasicRegistrationWriteServices())->save($this->basicContext(true), $payload));
        } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    /** 删除沿用保存入口的逐表授权与认证门店边界，具体源行校验由共享事务服务执行。 */
    public function basicDelete(Request $request)
    {
        try {
            $payload = $request->post();
            $this->assertBasicReportFeature(\app\services\report\query\BasicRegistrationQueryProvider::PAGE_CODE);
            if (isset($payload['store_id']) && (int)$payload['store_id'] !== (int)$this->storeId) throw new \InvalidArgumentException('不能删除其他门店登记');
            $payload['store_id'] = (int)$this->storeId;
            return $this->success('ok', (new \app\services\report\BasicRegistrationWriteServices())->delete($this->basicContext(true), $payload));
        } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    /** 当前岗位/受委托会话每次从服务端重新解析，浏览器不能指定可查询报表。 */
    private function basicReportFeatures(): array
    {
        $profile = is_array($this->cashierInfo) ? $this->cashierInfo : [];
        $resolver = app()->make(\app\services\cashier\v3\permission\CashierV3FeatureResolver::class);
        return !empty($profile['_cashier_v3_delegated'])
            ? $resolver->resolveVisibleFeatures($profile) : $resolver->resolveGrantedFeatures($profile);
    }

    private function assertBasicReportFeature(string $code): void
    {
        if (!\app\services\report\BasicReportHostServices::canReadStoreReport($code, $this->basicReportFeatures())) {
            throw new \mohe\exceptions\AuthException('当前岗位未授权该基础报表，请联系管理员。', 403);
        }
    }

    /** 读取只由认证会话的当前门店限定；人工登记写入继续受既有数据权限约束。 */
    private function basicContext(bool $writeRequest): array
    {
        $scope = $this->dataScope();
        $stores = $scope->visibleStoreIds();
        $canWriteStore = $stores === null || in_array((int)$this->storeId, array_map('intval', $stores), true);
        if ($writeRequest && !$canWriteStore) throw new \InvalidArgumentException('当前账号无权修改本店登记');
        $info = is_array($this->cashierInfo) ? $this->cashierInfo : [];
        $mode = $scope->authorizationMode();
        $readMode = !$writeRequest && in_array($mode, ['none', 'self_participant'], true) ? 'stores' : $mode;
        $canWrite = $canWriteStore && !in_array($mode, ['none', 'self_participant'], true)
            && empty($info['_cashier_v3_delegated']);
        $context = \app\services\report\BasicReportHostServices::context([(int)$this->storeId], $readMode,
            (int)$this->cashierId, 'store', $canWrite, (int)($info['id'] ?? 0));
        // 导出任务必须区分组织会话与传统店员ID，不能用碰巧相等的数字跨身份回源。
        if (!empty($info['_cashier_v3_organization'])) $context['permission_version'] = 'organization:' . $context['permission_version'];
        elseif (!empty($info['_cashier_v3_delegated'])) $context['permission_version'] = 'delegated:' . $context['permission_version'];
        return $context;
    }

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
            $input = $this->withDefaultDates($input);
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
            // 页面与所有 XLSX 工作簿共用同一游客姓名投影；汇总行保持原样。
            $result = StoreReportGuestDisplayServices::project($result);
            if ($export && (string)($input['format'] ?? '') === 'xlsx') {
                // 与页面相同的授权、筛选、完整行集和游客投影；仅统一文件编码，不另算业务数据。
                return (new StoreReportXlsxWriter())->response($report, $result);
            }
            return $this->success('ok', $result);
        } catch (\InvalidArgumentException $exception) {
            return app('json')->fail($exception->getMessage());
        }
    }

    /** 门店查询与导出共用缺省业务日；不改平台周期，也不覆盖用户显式选择/下钻的日期。 */
    private function withDefaultDates(array $input): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $start = trim((string)($input['start_date'] ?? ''));
        $end = trim((string)($input['end_date'] ?? ''));
        $input['start_date'] = $start !== '' ? $start : ($end !== '' ? $end : $today);
        $input['end_date'] = $end !== '' ? $end : ($start !== '' ? $start : $today);
        return $input;
    }

    private function inputRules(): array
    {
        // 结果行筛选仅缩小已授权报表结果，不能替代门店权限或改变事实聚合。
        return [
            ['report', 'partner_item_summary'], ['start_date', ''], ['end_date', ''], ['format', ''], ['row_filters', ''],
            ['dataset', 'sale'], ['metric', 'cash_performance'],
            // Operations popups share server-validated metric and row keys
            // with the platform report; these values cannot expand store scope.
            ['item_id', ''], ['detail_store_id', 0], ['detail_store_key', ''], ['detail_category_path', ''], ['item_group_key', ''],
            ['parent_report', ''], ['team_key', ''], ['drill_month', ''], ['metric_code', ''],
            ['company_dimension_id', ''], ['payment_method', ''], ['operator_id', 0],
            ['channel_id', 0], ['customer_segment', 'all'],
            // 月度大单查询/导出共用明确月份；门店权限仍从登录上下文注入。
            ['consumption_metric', 'cash'], ['sleep_months', 3], ['year', 0], ['month', ''],
            // Exact path is reserved for a grouped row's drilldown; ordinary category_path remains prefix-searchable.
            ['category_id', 0], ['category_path', ''], ['category_path_exact', ''], ['product_type', ''], ['partner_name', ''],
            ['salesperson_id', 0], ['sales_manager_id', 0], ['guide_id', 0], ['craftsman_id', 0], ['day_of_month', 0],
            ['store_ids', ''],
            // 异业汇总下钻必须保留会员和后续收款条件；只缩小已授权门店范围，不能在入口丢失后变为全店明细。
            // 地推汇总下钻只对指定会员按收款业务日展示原 E 销售明细；不能把筛选参数丢成全店查询。
            // 会员进店次数与现金金额下钻用稳定会员 ID、月份和指标缩小范围，权限仍在服务端裁剪。
            ['member_id', 0], ['visit_month', 0], ['visit_metric', 'visits'], ['include_followup_cash', 0], ['receipt_period', 0],
            // Shared detail search only narrows already-authorized rows; it never changes the parent metric or scope.
            ['detail_member', ''], ['detail_order_no', ''],
            // 大单原单弹窗只透传后端行键和金额列；权限与事实仍由统一报表服务重验。
            ['detail_row_key', ''], ['detail_metric', ''],
            ['dimension_code', ''], ['payment_method_code', ''], ['metric_code', ''],
            // 薪资筛选和指标下钻保留原单号与稳定人员/门店 ID；权限范围仍由服务端裁剪。
            ['employee_name', ''], ['document_no', ''], ['salary_employee_id', 0], ['salary_store_id', 0], ['salary_category_key', ''], ['salary_metric_key', ''],
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

    /** Reuses this report's existing scope resolver; personal report grain is not widened. */
    public function aiContext(): array
    {
        return (new \app\services\ai\execution\AiTrustedPrincipalResolver())->storeAuthenticated(
            (int)$this->storeId,(int)$this->cashierId,is_array($this->cashierInfo)?$this->cashierInfo:[]
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
