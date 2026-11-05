<?php

declare(strict_types=1);

namespace app\services\mobile\warehouse;

use app\services\metric\MetricDictionaryServices;
use app\services\mobile\merchant\MobileMerchantCapabilityCatalog;
use app\services\mobile\merchant\MobileMerchantAnalyticsEntryPolicy;
use app\services\mobile\merchant\MobileMerchantAnalyticsEntryScopeServices;
use app\services\mobile\protocol\MobileApiException;
use app\services\report\StoreUnifiedReportOrganizationDimensionServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\facade\Db;

/** Read-only mobile regional performance projection. */
final class MobileWarehouseServices
{
    public const METRIC_VERSION = 'mobile-warehouse-unified-v1';
    /** Employee cards now use six registered person metrics, including allocated count units. */
    public const EMPLOYEE_METRIC_VERSION = 'mobile-employee-registered-v2';

    private $hierarchy;
    private $entryScopes;
    private $dictionary;

    public function __construct(
        MobileWarehouseHierarchyProjector $hierarchy,
        MobileMerchantAnalyticsEntryScopeServices $entryScopes,
        MetricDictionaryServices $dictionary
    ) {
        $this->hierarchy = $hierarchy;
        $this->entryScopes = $entryScopes;
        $this->dictionary = $dictionary;
    }

    public function overview(array $merchant, array $input): array
    {
        $this->assertWarehouseFeature((int)$merchant['employeeId']);
        $entry = $this->entryScopes->resolve($merchant);
        if ((string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_PERSONAL) {
            return ['warehouse' => ['entry' => $this->entryProjection($entry)]];
        }
        $validStoreIds = array_values(array_map('intval', (array)$entry['authorizedStoreIds']));
        $stores = Db::name('system_store')->whereIn('id', $validStoreIds)
            ->where('is_del', 0)->field('id,name')->select()->toArray();
        $bindings = Db::name('organization_store')->whereIn('store_id', $validStoreIds)
            ->field('org_id,store_id')->select()->toArray();
        $organizations = Db::name('organization')->where('is_del', 0)
            ->field('id,pid,name')->select()->toArray();

        [$nodeType, $nodeId, $defaultStoreEntry] = self::requestedWarehouseNode($entry, $input);

        try {
            $hierarchy = $this->hierarchy->project(
                $organizations,
                $stores,
                $bindings,
                $validStoreIds,
                $nodeType,
                $nodeId,
                (string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_ORGANIZATION
                    ? (int)$entry['entryNodeId']
                    : null
            );
        } catch (InvalidArgumentException $exception) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', $exception->getMessage());
        }

        $period = $this->period($input);
        $scopeStoreIds = array_values(array_map('intval', (array)($hierarchy['currentNode']['_storeIds'] ?? [])));
        $showOrganizationRanking = (bool)$entry['showOrganizationRanking'];
        $showStoreRanking = (bool)($entry['showStoreRanking'] ?? false);
        $rankingCode = $this->rankingCode((string)($input['rankingCode'] ?? ''), $showOrganizationRanking, $showStoreRanking);
        $rankingOrder = $this->rankingOrder((string)($input['rankingOrder'] ?? 'desc'));
        // 服务项目的默认排序是现金业绩；数量排序只改变同一组结果的顺序。
        $projectSortMetric = $this->projectSortMetric((string)($input['projectSortMetric'] ?? 'cash'));
        // 旧小程序仍以 storeRankingPage 请求门店续页；新页签共用 rankingPage。
        $rankingPage = (int)($input['rankingPage'] ?? $input['storeRankingPage'] ?? 1);
        if ($rankingPage < 1 || $rankingPage > 10000) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '排行页码无效。', 'rankingPage');
        }
        // 指定门店的初始工作台仍是单店，但六类排行读取全部授权门店；
        // 点进组织或门店后，所有排行只在该节点与授权门店的交集内读取。
        $rankingScopeStoreIds = $defaultStoreEntry ? $validStoreIds : $scopeStoreIds;
        if ($rankingPage > 1) {
            // 续页只读当前已授权排行，避免重复计算概览与趋势；组织排行仍是当前层级概览。
            if ((string)($input['rankingCode'] ?? '') !== $rankingCode || $rankingCode === 'organization') {
                throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限不能读取该排行续页。');
            }
            return ['warehouse' => $rankingCode === 'store'
                ? $this->storeRankingPage($rankingScopeStoreIds, $stores, $period, $rankingOrder, $rankingPage)
                : $this->factRankingPage($rankingCode, $rankingScopeStoreIds, $period, $rankingOrder, $rankingPage, $projectSortMetric)];
        }
        $metrics = $this->summaryMetrics($scopeStoreIds, $period);
        // 指定门店的组织排行跨越全部授权门店，趋势必须使用相同范围；
        // 页面顶部的工作台汇总仍保持当前门店口径。
        $trendStoreIds = $defaultStoreEntry && $rankingCode === 'organization' ? $validStoreIds : $scopeStoreIds;
        $trend = $this->trendProjection(
            $trendStoreIds,
            $period,
            $this->trendMetricCode((string)($input['trendMetricCode'] ?? 'cash_performance'))
        );
        foreach ($hierarchy['rows'] as &$row) {
            $rowStoreIds = array_values(array_map('intval', (array)($row['_storeIds'] ?? [])));
            $row['metrics'] = $this->metricValues($this->summaryMetrics($rowStoreIds, $period));
            unset($row['_storeIds']);
        }
        unset($row);
        unset($hierarchy['currentNode']['_storeIds']);

        $rankingRange = '';
        $rankingTotal = 0;
        $rankingHasMore = false;
        if ($rankingCode === 'organization') {
            $rankingHierarchyRows = $hierarchy['rows'];
            if ($defaultStoreEntry) {
                // 指定门店入口的当前节点固定为一家门店；另建仅用于排行的
                // 权限裁剪组织投影，避免把单店误当成全部授权门店。
                $rankingHierarchy = $this->hierarchy->project(
                    $organizations, $stores, $bindings, $rankingScopeStoreIds
                );
                $rankingHierarchyRows = $rankingHierarchy['rows'];
                $childOrganizations = array_filter($rankingHierarchyRows, static function (array $row): bool {
                    return (string)$row['entityType'] === 'organization';
                });
                if ($childOrganizations === [] && (string)$rankingHierarchy['currentNode']['entityType'] === 'organization') {
                    // 所有指定门店同属一个末级组织时，组织排行仍显示该组织，
                    // 不把门店行重复当作组织排行。
                    $rankingHierarchyRows = [$rankingHierarchy['currentNode']];
                }
                foreach ($rankingHierarchyRows as &$rankingRow) {
                    $rowStoreIds = array_values(array_map('intval', (array)($rankingRow['_storeIds'] ?? [])));
                    $rankingRow['metrics'] = $this->metricValues($this->summaryMetrics($rowStoreIds, $period));
                    unset($rankingRow['_storeIds']);
                }
                unset($rankingRow);
            }
            $rankingRows = $this->organizationRanking($rankingHierarchyRows, $rankingOrder);
        } elseif ($rankingCode === 'store') {
            $storePage = $this->storeRankingPage($rankingScopeStoreIds, $stores, $period, $rankingOrder, 1);
            $rankingRows = $storePage['rankingRows'];
            $rankingRange = $storePage['rankingRange'];
            $rankingTotal = $storePage['rankingTotal'];
            $rankingHasMore = $storePage['rankingHasMore'];
        } else {
            $factPage = $this->factRankingPage($rankingCode, $rankingScopeStoreIds, $period, $rankingOrder, 1, $projectSortMetric);
            $rankingRows = $factPage['rankingRows'];
            $rankingRange = $factPage['rankingRange'];
            $rankingTotal = $factPage['rankingTotal'];
            $rankingHasMore = $factPage['rankingHasMore'];
        }
        $rankingCatalog = $this->rankingCatalog($showOrganizationRanking, $showStoreRanking);
        $selectedRankingAvailable = true;
        foreach ($rankingCatalog as $rankingDefinition) {
            if ((string)$rankingDefinition['code'] === $rankingCode) {
                $selectedRankingAvailable = (bool)$rankingDefinition['available'];
                break;
            }
        }

        return [
            'warehouse' => [
                'period' => $period,
                'scope' => [
                    'mode' => (string)$entry['entryType'],
                    'authorizedStoreCount' => count($validStoreIds),
                    'label' => (string)$hierarchy['currentNode']['name'],
                ],
                'entry' => $this->entryProjection($entry),
                'currentNode' => $hierarchy['currentNode'],
                'breadcrumbs' => $hierarchy['breadcrumbs'],
                'hierarchyRows' => $hierarchy['rows'],
                'organizationTree' => $hierarchy['organizationTree'],
                'summaryMetrics' => $metrics,
                'trend' => $trend,
                'rankingRows' => $rankingRows,
                'rankingCatalog' => $rankingCatalog,
                'selectedRankingCode' => $rankingCode,
                'selectedRankingOrder' => $rankingOrder,
                'selectedProjectSortMetric' => $projectSortMetric,
                'selectedRankingAvailable' => $selectedRankingAvailable,
                'rankingRange' => $rankingRange,
                'rankingPage' => 1,
                'rankingTotal' => $rankingTotal,
                'rankingHasMore' => $rankingHasMore,
                'selectedMetricCode' => 'cash_performance',
                'metric_version' => self::METRIC_VERSION,
                'data_as_of' => time(),
                'aggregation_caught_up' => true,
                'availabilityMessage' => $selectedRankingAvailable
                    ? '当前直接读取收银 V3 不可变事实，日聚合接入后将使用同一口径对账切换。'
                    : '当前排行暂不可用。',
            ],
        ];
    }

    /**
     * Resolve a warehouse navigation request without changing its authorization source.
     * The selected-store entry starts on the active store but may navigate through
     * organization nodes; the hierarchy projector intersects every node with the
     * server-resolved authorized stores. Self-store grants stay locked to one store.
     *
     * @return array{0:string,1:int,2:bool} node type, node ID, default selected-store entry
     */
    private static function requestedWarehouseNode(array $entry, array $input): array
    {
        $nodeType = trim((string)($input['nodeType'] ?? ''));
        $nodeId = (int)($input['nodeId'] ?? 0);
        $selectedStoreRankingGrant = (string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_STORE
            && (bool)($entry['showOrganizationRanking'] ?? false);
        $defaultStoreEntry = $selectedStoreRankingGrant && $nodeType === '' && $nodeId === 0;
        if ((string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_STORE && !$selectedStoreRankingGrant) {
            return ['store', (int)$entry['entryNodeId'], false];
        }
        if ($nodeType === '' && $nodeId === 0) {
            return [(string)$entry['entryNodeType'], (int)$entry['entryNodeId'], $defaultStoreEntry];
        }
        return [$nodeType, $nodeId, false];
    }

    /**
     * Read one employee's registered V3 metrics and fact details inside the
     * server-authorized stores. The client never aggregates or widens scope.
     */
    public function employeePerformance(array $merchant, array $input): array
    {
        // 汇总、明细、完整月落档基数在同一次一致读取中取得；不生成或保存工资事实。
        return Db::transaction(function () use ($merchant,$input) { return $this->employeePerformanceProjection($merchant,$input); });
    }

    private function employeePerformanceProjection(array $merchant, array $input): array
    {
        $this->assertWarehouseFeature((int)$merchant['employeeId']);
        $entry = $this->entryScopes->resolve($merchant);
        $storeIds = array_values(array_unique(array_map('intval', (array)$entry['authorizedStoreIds'])));
        if ($storeIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有可查看的门店。');
        }
        $employeeId = (int)($input['employeeId'] ?? 0);
        if ($employeeId <= 0) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效员工。', 'employeeId');
        }
        if ((string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_PERSONAL
            && $employeeId !== (int)$merchant['employeeId']) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '个人数据权限只能查看本人员工业绩。');
        }

        $period = $this->period($input);
        $metricReader = new \app\services\query\metric\RegisteredMetricReadServices();
        $metricRange = ['start' => $period['startDate'], 'end' => $period['endDate']];
        $pairs = [];
        foreach ($storeIds as $storeId) $pairs[] = ['store_id' => (int)$storeId, 'employee_id' => $employeeId];
        $metricAmounts = [];
        // These six codes own the page's business meaning. Service visits and
        // point visits are tenths; allocated project counts are millionths.
        // Raw personnel fact row counts cannot replace any of those measures.
        foreach (['staff_sales_yeji', 'staff_labor_yeji', 'staff_point_visit_num', 'staff_service_num', 'staff_project_num', 'staff_labor_fee'] as $metricCode) {
            $metricAmounts[$metricCode] = $metricReader->personnelTotal($metricCode, '0', $storeIds, $metricRange, $pairs);
        }

        $identityRows = array_merge(
            $metricReader->personnelDailyTotals('staff_sales_yeji', '0', $storeIds, $metricRange, [$employeeId]),
            $metricReader->personnelDailyTotals('staff_labor_yeji', '0', $storeIds, $metricRange, [$employeeId])
        );
        $employeeName = '';
        foreach ($identityRows as $identity) {
            $employeeName = trim((string)($identity['employee_name'] ?? ''));
            if ($employeeName !== '') break;
        }
        if ($employeeName === '') {
            // A zero-fact day is not a permission denial: a currently assigned
            // employee remains visible within the server-authorized stores.
            $staff = Db::name('system_store_staff')->alias('s')
                ->join('employee e', 'e.id = s.employee_id')
                ->where('s.employee_id', $employeeId)->whereIn('s.store_id', $storeIds)
                ->where('s.status', 1)->where('s.is_del', 0)
                ->where('e.status', 1)->where('e.is_del', 0)
                ->field('e.name')->find();
            $employeeName = trim((string)($staff['name'] ?? ''));
        }
        if ($employeeName === '') {
            // Only the rare former employee with completed service but no
            // current assignment needs the service identity read. The common
            // path avoids a third service-customer aggregation.
            foreach ($metricReader->personnelDailyTotals('staff_service_num', '0', $storeIds, $metricRange, [$employeeId]) as $identity) {
                $employeeName = trim((string)($identity['employee_name'] ?? ''));
                if ($employeeName !== '') break;
            }
        }
        if ($employeeName === '') {
            // Former staff may still appear in fact-backed rankings. Only a
            // registered normal fact from an authorized store can establish
            // their historical identity; no data from other stores is exposed.
            foreach (['staff_sales_yeji', 'staff_labor_yeji'] as $metricCode) {
                $employeeName = $metricReader->personnelVisibleIdentity($metricCode, '0', $storeIds, $employeeId);
                if ($employeeName !== '') break;
            }
        }
        if ($employeeName === '') {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '该员工不在当前数据权限范围内。');
        }

        $detailMode = trim((string)($input['detailMode'] ?? 'cash')) === 'labor' ? 'labor' : 'cash';
        $detailMetricCode = $detailMode === 'labor' ? 'staff_labor_yeji' : 'staff_sales_yeji';
        $page = max(1, (int)($input['page'] ?? 1));
        $pageSize = min(20, max(1, (int)($input['pageSize'] ?? 20)));
        $detailPage = $metricReader->personnelDetailPage(
            $detailMetricCode, '0', $storeIds, $metricRange, $employeeId, $page, $pageSize
        );
        $total = (int)$detailPage['total'];
        $detailRows = $detailPage['rows'];
        $detailContext = $this->detailBusinessContext($detailRows, $storeIds, $employeeId);
        $contactPhones = $this->detailContactPhones($detailRows);
        $rechargeAmounts = $this->detailRechargeAmounts($detailRows);
        $calculated=\app\services\payroll\PayrollAlgorithmPolicy::calculated();
        $commission=$calculated ? (new \app\services\payroll\ProjectLaborCommissionReadServices($metricReader))->read($storeIds,$metricRange,[$employeeId]) : null;
        $commissionSummary=self::employeeCommissionSummary($commission);
        $details = [];
        foreach ($detailRows as $row) {
            $amount = (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)($row['metric_value'] ?? 0));
            $factId = (string)($row['fact_id'] ?? '');
            $context = $detailContext[$factId] ?? [];
            $saleNumber = (string)($row['order_no_snapshot'] ?? '');
            if (self::isMissingDocumentNo($saleNumber)) $saleNumber = (string)($context['sale_order_no'] ?? '');
            $hasSale = (int)($context['sale_fact_count'] ?? 0) > 0;
            $hasService = (int)($context['service_fact_count'] ?? 0) > 0;
            $isRecharge = (string)($row['source_document_type'] ?? '') === 'recharge';
            $details[] = [
                'factId' => $factId,
                'businessDate' => (string)($row['business_date'] ?? ''),
                'orderNo' => self::detailDocumentNo(
                    $saleNumber,
                    (string)($context['service_record_no'] ?? ''),
                    (string)($context['document_no_snapshot'] ?? '')
                ),
                'itemName' => $isRecharge ? '充值' : trim((string)($detailMode === 'labor'
                    ? ($context['project_name_snapshot'] ?? '') : ($context['sale_item_name'] ?? ''))),
                'isRecharge' => $isRecharge,
                // Recharge principal belongs to the original order, while the
                // metric value remains this employee's allocated performance.
                'displayRechargeAmount' => $isRecharge && isset($rechargeAmounts[$factId])
                    ? (string)\app\services\query\metric\MetricMoneyFormatter::integerYuan($rechargeAmounts[$factId]) : '',
                'displaySaleAmount' => $hasSale
                    ? (string)\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$context['sale_amount_cents']) : '—',
                'displayConsumptionAmount' => $hasService
                    ? (string)\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$context['consumption_amount_cents']) : '—',
                'commissionDetail' => $calculated && $detailMode==='labor' ? ($commission['details'][$factId] ?? null) : null,
                'displayCommission' => $calculated && $detailMode==='labor'
                    ? (isset($commission['details'][$factId]['amount_cents']) ? (string)\app\services\query\metric\MetricMoneyFormatter::integerYuan($commission['details'][$factId]['amount_cents']) : '待核对') : null,
                // 每条劳动事实的实际分配项目数：支持小数及冲销，独立于手工费算法。
                'displayProjectCount' => $detailMode === 'labor' && isset($row['project_count_micro_value'])
                    ? self::displayScaledCount((int)$row['project_count_micro_value'], 6) : null,
                'displayLaborFee' => (string)\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)($row['labor_fee_amount_cents'] ?? 0)),
                'storeName' => (string)($row['store_name_snapshot'] ?? ''),
                'memberName' => (string)($row['member_name_snapshot'] ?? ''),
                // 仅无会员身份的历史销售事实显示游客；有会员 ID 但姓名缺失不能误判为游客。
                'isGuest' => (int)($row['member_id'] ?? 0) <= 0,
                'memberPhone' => (string)($contactPhones[$factId] ?? ''),
                'serviceObject' => $hasService ? self::detailServiceObject((string)($context['service_object'] ?? ''), $context['friend_counts_as_customer'] ?? null) : '',
                'serviceQuantity' => $hasService ? (string)($context['service_quantity'] ?? '') : '',
                'serviceUnit' => $hasService ? self::detailCraftsmanUnit((string)($context['craftsmen_snapshot_json'] ?? ''), $employeeId) : '',
                'participants' => $hasService ? self::detailCraftsmen((string)($context['craftsmen_snapshot_json'] ?? '')) : '',
                'sourceLineId' => (string)($row['source_line_id'] ?? ''),
                'ruleName' => (string)($row['rule_name_snapshot'] ?? ''),
                'value' => $amount,
                // Keep the mobile warehouse amount format consistent with the
                // overview and ranking cards: whole yuan with no thousands
                // separator. The source fact remains cents.
                'displayValue' => (string)$amount,
            ];
        }

        return [
            'employeePerformance' => [
                'employee' => ['id' => $employeeId, 'name' => $employeeName],
                'laborAlgorithm'=>\app\services\payroll\PayrollAlgorithmPolicy::projection(),
                'commission'=>['visible'=>$calculated,'complete'=>$commissionSummary['complete'],'displayValue'=>$commissionSummary['displayValue'],'pendingCount'=>$commissionSummary['pendingCount']],
                'period' => $period,
                'metrics' => [
                    // 人员现金业绩是销售人分配事实，不是整店收款；页面按产品用语展示。
                    ['code' => 'staff_sales_yeji', 'name' => '现金业绩', 'value' => \app\services\query\metric\MetricMoneyFormatter::integerYuan($metricAmounts['staff_sales_yeji']), 'unit' => 'amount'],
                    ['code' => 'staff_labor_yeji', 'name' => '劳动业绩', 'value' => \app\services\query\metric\MetricMoneyFormatter::integerYuan($metricAmounts['staff_labor_yeji']), 'unit' => 'amount'],
                    ['code' => 'staff_point_visit_num', 'name' => '点客数', 'value' => self::displayScaledCount($metricAmounts['staff_point_visit_num'], 1), 'unit' => 'count'],
                    ['code' => 'staff_service_num', 'name' => '服务人次', 'value' => self::displayScaledCount($metricAmounts['staff_service_num'], 1), 'unit' => 'count'],
                    ['code' => 'staff_project_num', 'name' => '项目数', 'value' => self::displayScaledCount($metricAmounts['staff_project_num'], 6), 'unit' => 'count'],
                    ['code' => 'staff_labor_fee', 'name' => '手工费', 'value' => \app\services\query\metric\MetricMoneyFormatter::integerYuan($metricAmounts['staff_labor_fee']), 'unit' => 'amount'],
                ] + ($calculated ? [
                    6=>['code'=>'project_labor_commission','name'=>'劳动提成（预计）','value'=>$commissionSummary['displayValue'],'unit'=>'amount'],
                ] : []),
                'detailMode' => $detailMode,
                'details' => $details,
                'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total, 'hasMore' => $page * $pageSize < $total],
                'metric_version' => self::EMPLOYEE_METRIC_VERSION,
                'data_as_of' => time(),
                'aggregation_caught_up' => true,
            ],
        ];
    }

    /** Sum every authorized fact across the selected dates, not the visible page; unresolved facts stay explicit. */
    private static function employeeCommissionSummary(?array $commission): array
    {
        $amount=0;$pending=0;
        foreach ($commission['details'] ?? [] as $detail) {
            if (($detail['amount_cents'] ?? null) === null) $pending++;
            else $amount+=(int)$detail['amount_cents'];
        }
        $display=\app\services\query\metric\MetricMoneyFormatter::integerYuan($amount);
        if ($pending>0) $display='已计算 '.$display.'（'.$pending.'笔待核对）';
        return ['complete'=>$pending===0,'pendingCount'=>$pending,'displayValue'=>$display];
    }

    /**
     * Resolve card labels and source amounts from immutable sale and completed
     * service facts on the same authorized checkout line. The personnel reader
     * already decides which rows exist; this lookup cannot change those totals.
     *
     * @return array<string,array<string,mixed>>
     */
    private function detailBusinessContext(array $detailRows, array $storeIds, int $employeeId): array
    {
        $factIds = [];
        foreach ($detailRows as $row) {
            $factId = trim((string)($row['fact_id'] ?? ''));
            if ($factId !== '') $factIds[] = $factId;
        }
        if ($factIds === []) return [];

        $rows = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_sale_fact sf',
                "sf.tenant_id=p.tenant_id AND sf.store_id=p.store_id AND sf.checkout_request_id=p.checkout_request_id AND sf.source_line_id=p.source_line_id AND sf.status='effective' AND sf.fact_direction='forward'")
            ->leftJoin('cashier_v3_entitlement_service_fact sv',
                "sv.tenant_id=p.tenant_id AND sv.store_id=p.store_id AND sv.checkout_request_id=p.checkout_request_id AND sv.source_line_id=p.source_line_id AND sv.service_status='completed'")
            ->where('p.tenant_id', '0')->whereIn('p.store_id', $storeIds)
            ->where('p.employee_id', $employeeId)->whereIn('p.fact_id', array_values(array_unique($factIds)))
            ->fieldRaw("p.fact_id,COUNT(sf.fact_id) sale_fact_count,MAX(sf.order_no_snapshot) sale_order_no,GROUP_CONCAT(DISTINCT NULLIF(sf.item_name_snapshot,'') SEPARATOR '、') sale_item_name,COALESCE(SUM(sf.sale_amount_cents),0) sale_amount_cents,COUNT(DISTINCT sv.service_fact_id) service_fact_count,MAX(sv.service_record_no) service_record_no,MAX(sv.document_no_snapshot) document_no_snapshot,MAX(sv.project_name_snapshot) project_name_snapshot,MAX(sv.quantity) service_quantity,MAX(sv.service_object) service_object,MAX(sv.friend_counts_as_customer) friend_counts_as_customer,MAX(sv.craftsmen_snapshot_json) craftsmen_snapshot_json,MAX((SELECT COALESCE(SUM(pc.amount_cents),0) FROM eb_cashier_v3_performance_fact pc WHERE pc.tenant_id=p.tenant_id AND pc.store_id=p.store_id AND pc.checkout_request_id=p.checkout_request_id AND pc.source_line_id=p.source_line_id AND pc.performance_type='consumption_performance_recorded' AND pc.status='effective')) consumption_amount_cents")
            ->group('p.fact_id')->select()->toArray();
        $context = [];
        foreach ($rows as $row) $context[(string)$row['fact_id']] = $row;
        return $context;
    }

    /** Contact numbers are read only for members on the already authorized detail page; missing profiles stay blank. */
    private function detailContactPhones(array $detailRows): array
    {
        $memberIds = [];
        foreach ($detailRows as $row) {
            $memberId = (int)($row['member_id'] ?? 0);
            if ($memberId > 0) $memberIds[$memberId] = $memberId;
        }
        $memberPhones = $memberIds === [] ? [] : Db::name('user')->whereIn('uid', array_values($memberIds))->column('phone', 'uid');
        $result = [];
        foreach ($detailRows as $row) {
            $factId = (string)($row['fact_id'] ?? '');
            $memberId = (int)($row['member_id'] ?? 0);
            $result[$factId] = trim((string)($memberPhones[$memberId] ?? ''));
        }
        return $result;
    }

    /** Batch-read original recharge principal only for this authorized page's exact order, store and member. */
    private function detailRechargeAmounts(array $detailRows): array
    {
        $ids = [];
        foreach ($detailRows as $row) {
            if ((string)($row['source_document_type'] ?? '') !== 'recharge') continue;
            $orderId = (string)($row['order_id'] ?? '');
            if (preg_match('/^RCH:([1-9][0-9]*)$/D', $orderId, $match) === 1) $ids[(int)$match[1]] = (int)$match[1];
        }
        if ($ids === []) return [];
        $orders = [];
        foreach (Db::name('user_recharge')->whereIn('id', array_values($ids))
            ->fieldRaw('id,store_id,uid,CAST(ROUND(price * 100, 0) AS SIGNED) principal_cents')->select()->toArray() as $order) {
            $orders[(int)$order['id'] . ':' . (int)$order['store_id'] . ':' . (int)$order['uid']] = (int)$order['principal_cents'];
        }
        $amounts = [];
        foreach ($detailRows as $row) {
            $orderId = (string)($row['order_id'] ?? '');
            if (preg_match('/^RCH:([1-9][0-9]*)$/D', $orderId, $match) !== 1) continue;
            $key = (int)$match[1] . ':' . (int)($row['store_id'] ?? 0) . ':' . (int)($row['member_id'] ?? 0);
            if (isset($orders[$key])) $amounts[(string)$row['fact_id']] = $orders[$key];
        }
        return $amounts;
    }

    /** Historical service-object and friend-count flags describe the saved visit, not a recalculated customer count. */
    private static function detailServiceObject(string $object, $friendCounts): string
    {
        if (in_array($object, ['friend', 'FRIEND', '朋友'], true)) return (int)$friendCounts === 1 ? '朋友算' : '朋友不算';
        return in_array($object, ['self', 'SELF', '本人'], true) ? '本人' : '';
    }

    /** Decode only persisted craftsmen names and point/round flags; current staff assignments cannot rewrite old visits. */
    private static function detailCraftsmen(string $json): string
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) return '';
        $labels = [];
        foreach ($rows as $person) {
            if (!is_array($person)) continue;
            $name = trim((string)($person['name'] ?? $person['staff_name_snapshot'] ?? $person['staffName'] ?? ''));
            if ($name === '') continue;
            $point = $person['isPointCustomer'] ?? $person['is_point_customer'] ?? null;
            $labels[] = $name . ($point === null ? '' : ((bool)$point ? '（点）' : '（轮）'));
        }
        return implode('、', array_values(array_unique($labels)));
    }

    /** The selected employee's saved point/round role supplies the writeoff unit; missing old flags remain unlabeled. */
    private static function detailCraftsmanUnit(string $json, int $employeeId): string
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) return '';
        foreach ($rows as $person) {
            if (!is_array($person) || (int)($person['employeeId'] ?? $person['employee_id'] ?? 0) !== $employeeId) continue;
            $point = $person['isPointCustomer'] ?? $person['is_point_customer'] ?? null;
            return $point === null ? '' : ((bool)$point ? '点' : '轮');
        }
        return '';
    }

    /** A completion receipt ID is an internal reference, never a sales or service document number. */
    private static function isMissingDocumentNo(string $number): bool
    {
        $number = trim($number);
        return $number === '' || preg_match('/^ECR-[0-9a-f]{40}$/iD', $number) === 1;
    }

    /** Prefer the sale number; a service-only fact uses its customer-visible service number. */
    private static function detailDocumentNo(string $saleNumber, string $serviceNumber, string $serviceDocumentNumber): string
    {
        if (!self::isMissingDocumentNo($saleNumber)) return trim($saleNumber);
        if (!self::isMissingDocumentNo($serviceNumber)) return trim($serviceNumber);
        return self::isMissingDocumentNo($serviceDocumentNumber) ? '' : trim($serviceDocumentNumber);
    }

    /** Render registered count units exactly, without float rounding away split allocations. */
    private static function displayScaledCount(int $raw, int $places): string
    {
        $digits = str_pad(ltrim((string)$raw, '-'), $places + 1, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -$places), '0');
        $fraction = rtrim(substr($digits, -$places), '0');
        $display = ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
        return $raw < 0 && $display !== '0' ? '-' . $display : $display;
    }

    /**
     * Shared read-only projection for mobile merchant summary pages.
     *
     * The homepage must use the exact same immutable V3 facts, period parser,
     * trend builder and metric dictionary as the warehouse page.  Keeping
     * these small public adapters here avoids introducing a second set of
     * dashboard SQL while still allowing the homepage to be available to an
     * employee who has customer access but not the full warehouse feature.
     *
     * @param int[] $storeIds
     * @return array<string,mixed>
     */
    public function dashboardProjection(array $storeIds, array $input = []): array
    {
        $storeIds = $this->positiveIds($storeIds);
        $period = $this->periodForDashboard($input);
        $projection = $this->registeredStoreProjection($storeIds, $period);
        $summary = $this->summaryMetrics($storeIds, $period);
        $trend = [];
        // The merchant homepage requests the comparison chart through
        // dashboardTrendComparison. Avoid a third (cash) trend scan while
        // retaining the original trend for warehouse callers.
        if (($input['includeTrend'] ?? true) !== false) {
            $trend = $this->trendProjection(
                $storeIds,
                $period,
                $this->trendMetricCode((string)($input['trendMetricCode'] ?? 'cash_performance'))
            );
        }

        $ranking = [];
        $storeNames = $storeIds === [] ? [] : Db::name('system_store')
            ->whereIn('id', $storeIds)->where('is_del', 0)->column('name', 'id');
        foreach ($storeIds as $storeId) {
            $amountCents = (int)($projection['cash_performance'][$storeId] ?? 0);
            $ranking[] = [
                'entityId' => $storeId,
                'entityType' => 'store',
                'entityName' => (string)($storeNames[$storeId] ?? ('门店#' . $storeId)),
                'rankingValue' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($amountCents),
                'displayValue' => number_format((int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($amountCents), 0, '.', ','),
                'metricCode' => 'cash_performance',
            ];
        }
        usort($ranking, static function (array $left, array $right): int {
            if ((int)$left['rankingValue'] === (int)$right['rankingValue']) {
                return (int)$left['entityId'] <=> (int)$right['entityId'];
            }
            return (int)$right['rankingValue'] <=> (int)$left['rankingValue'];
        });

        return [
            'period' => $period,
            'summaryMetrics' => $summary,
            'trend' => $trend,
            'rankingRows' => array_slice($ranking, 0, 5),
            'metric_version' => self::METRIC_VERSION,
            'data_as_of' => time(),
            // The current projection reads the immutable facts directly. A
            // future daily aggregate may replace it only after reconciliation.
            'aggregation_caught_up' => true,
        ];
    }

    /**
     * Build the two homepage trend series without recomputing the full
     * summary projection for each metric.  The homepage summary remains
     * sourced from dashboardProjection; this adapter only removes duplicate
     * fact scans from the comparison chart.
     *
     * @return array{actual:array<string,mixed>,consume:array<string,mixed>}
     */
    public function dashboardTrendComparison(array $storeIds, array $input = []): array
    {
        $period = $this->periodForDashboard($input);
        return [
            'actual' => $this->trendProjection($this->positiveIds($storeIds), $period, 'actual_performance'),
            'consume' => $this->trendProjection($this->positiveIds($storeIds), $period, 'consume_amount'),
        ];
    }

    /** Build every authorized homepage ranking row from the scoped V3 fact projection. */
    public function dashboardRanking(array $storeIds, array $input, string $dimension): array
    {
        $storeIds = $this->positiveIds($storeIds);
        if ($storeIds === []) return [];
        $period = $this->periodForDashboard($input);
        if ($dimension === 'store') {
            $projection = $this->registeredStoreProjection($storeIds, $period);
            $names = Db::name('system_store')->whereIn('id', $storeIds)->where('is_del', 0)->column('name', 'id');
            $rows = [];
            foreach ($storeIds as $storeId) {
                $cents = (int)($projection['cash_performance'][$storeId] ?? 0);
                $rows[] = ['entityType' => 'store', 'entityId' => $storeId, 'entityName' => (string)($names[$storeId] ?? ('门店#' . $storeId)), 'rankingCents' => $cents, 'rankingValue' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($cents), 'storeCount' => 1];
            }
            usort($rows, [$this, 'compareDashboardRanking']);
            // The homepage list renders every matched store; truncating here
            // makes its “查看全部” entry disagree with the visible query scope.
            return $this->formatDashboardRanking($rows, 'store');
        }
        // Aggregate each authorized store into the configured reporting
        // dimension. The previous fallback always returned store rows even
        // when the homepage title said “分公司/经理排行”, which made the
        // visible scope and the value semantics disagree.
        $dimensionService = new StoreUnifiedReportOrganizationDimensionServices();
        $dimensionCode = $dimension === 'company' ? 'company' : 'city_manager';
        $entityType = $dimension === 'company' ? 'branch' : 'manager';
        $storeGroupKeys = [];
        $groupLabels = [];
        foreach ($storeIds as $storeId) {
            $resolved = $dimensionService->resolve($dimensionCode, '', '', (string)$period['endDate'], (int)$storeId);
            $id = (int)($resolved['id'] ?? 0);
            $name = trim((string)($resolved['name'] ?? ''));
            if ($id <= 0 || $name === '') continue;
            $storeGroupKeys[$storeId] = (string)$id;
            $groupLabels[(string)$id] = $name;
        }
        // A partially configured hierarchy has no sound aggregate. Keep the
        // documented store-level fallback rather than silently omitting stores.
        if (count($storeGroupKeys) === count($storeIds)) {
            $reader = new \app\services\query\metric\RegisteredMetricReadServices();
            $range = ['start' => $period['startDate'], 'end' => $period['endDate']];
            $rows = [];
            foreach ($reader->groupedStoreTotals('cash_performance', '0', $storeIds, $range, $storeGroupKeys) as $group) {
                $groupId = (string)$group['group_key'];
                $cents = (int)$group['metric_value'];
                $rows[] = [
                    'entityType' => $entityType, 'entityId' => (int)$groupId,
                    'entityName' => (string)($groupLabels[$groupId] ?? ''),
                    'rankingCents' => $cents,
                    'rankingValue' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($cents),
                    'storeCount' => (int)$group['store_count'],
                ];
            }
            usort($rows, [$this, 'compareDashboardRanking']);
            // Preserve the complete authorized group result after sorting.
            return $this->formatDashboardRanking($rows, $entityType);
        }

        // Keep the page usable while a reporting-dimension migration is not
        // configured yet; this is an explicit store-level fallback.
        // A partially configured hierarchy falls back to the same complete
        // store ranking. dashboardProjection() carries a separate five-row
        // preview and would silently reintroduce the homepage truncation.
        return $this->dashboardRanking($storeIds, $input, 'store');
    }

    private function compareDashboardRanking(array $left, array $right): int
    {
        // Compare cents before whole-yuan display rounding so close amounts
        // do not change rank when the complete result is shown.
        if ((int)$left['rankingCents'] === (int)$right['rankingCents']) return (int)$left['entityId'] <=> (int)$right['entityId'];
        return (int)$right['rankingCents'] <=> (int)$left['rankingCents'];
    }

    private function formatDashboardRanking(array $rows, string $entityType): array
    {
        foreach ($rows as &$row) {
            $row['entityType'] = $entityType; $row['name'] = (string)($row['entityName'] ?? '');
            $row['displayValue'] = number_format((int)$row['rankingValue'], 0, '.', ','); $row['metricCode'] = 'cash_performance'; $row['unit'] = 'amount'; $row['hasChildren'] = false;
        }
        unset($row); return $rows;
    }

    /** Normalize homepage period modes while retaining the warehouse contract. */
    public function periodForDashboard(array $input): array
    {
        $mode = trim((string)($input['periodMode'] ?? 'month'));
        if ($mode === 'year') {
            $year = trim((string)($input['year'] ?? date('Y')));
            if (!preg_match('/^\d{4}$/D', $year)) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效年份。', 'year');
            }
            $input['periodMode'] = 'custom';
            $input['startDate'] = $year . '-01-01';
            $input['endDate'] = $year . '-12-31';
        }
        return $this->period($input);
    }

    /** @param mixed[] $values @return int[] */
    private function positiveIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private function assertWarehouseFeature(int $employeeId): void
    {
        $row = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->field('rules')->find();
        $rules = is_array($row)
            ? array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($row['rules'] ?? ''))))))
            : [];
        if (!in_array(MobileMerchantCapabilityCatalog::RULE_WAREHOUSE, $rules, true)) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前账号没有数仓查看权限。');
        }
    }

    private function period(array $input): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $mode = trim((string)($input['periodMode'] ?? 'month'));
        if ($mode === '') {
            $mode = 'month';
        }
        $today = new DateTimeImmutable('today', $timezone);
        if ($mode === 'today') {
            $start = $today;
            $end = $today;
            $label = '今天';
            $month = $start->format('Y-m');
        } elseif ($mode === 'yesterday') {
            $start = $today->modify('-1 day');
            $end = $start;
            $label = '昨天';
            $month = $start->format('Y-m');
        } elseif ($mode === 'last_month') {
            $start = $today->modify('first day of last month');
            $end = $start->modify('last day of this month');
            $label = '上月';
            $month = $start->format('Y-m');
        } elseif ($mode === 'custom') {
            $start = $this->dateFromInput((string)($input['startDate'] ?? ''), 'startDate', $timezone);
            $end = $this->dateFromInput((string)($input['endDate'] ?? ''), 'endDate', $timezone);
            if ($start > $end) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '开始日期不能晚于结束日期。', 'startDate');
            }
            $label = $start->format('n月j日') . '-' . $end->format('n月j日');
            $month = $start->format('Y-m');
        } else {
            $month = trim((string)($input['month'] ?? ''));
            if ($month === '') {
                $month = $today->format('Y-m');
            }
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效月份。', 'month');
            }
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $timezone);
            if (!$start) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效月份。', 'month');
            }
            $end = $start->modify('last day of this month');
            $label = $start->format('Y年n月');
            $mode = 'month';
        }
        return [
            'mode' => $mode,
            'month' => $month,
            'label' => $label,
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
            'timezone' => 'Asia/Shanghai',
        ];
    }

    private function dateFromInput(string $date, string $field, DateTimeZone $timezone): DateTimeImmutable
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效日期。', $field);
        }
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if (!$value || $value->format('Y-m-d') !== $date) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效日期。', $field);
        }
        return $value;
    }

    private function registeredStoreProjection(array $storeIds, array $period): array
    {
        // Both callers consume cash rankings only. Overview values are read
        // separately by summaryMetrics; do not run unused metric queries.
        $projection = ['cash_performance' => []];
        if ($storeIds === []) {
            return $projection;
        }
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $range = ['start' => $period['startDate'], 'end' => $period['endDate']];
        foreach ($reader->storeTotals('cash_performance', '0', $storeIds, $range) as $row) {
            $projection['cash_performance'][(int)$row['store_id']] = (int)$row['metric_value'];
        }
        return $projection;
    }

    /**
     * Builds a read-only, permission-scoped comparison series.  The client is
     * deliberately given finished points only: it must never aggregate facts
     * or derive same-period comparisons itself.
     */
    private function trendProjection(array $storeIds, array $period, string $metricCode): array
    {
        $calendar = $this->trendCalendar($period);
        $current = $this->trendAmounts($storeIds, $calendar['currentStart'], $calendar['currentEnd'], $metricCode, $calendar['granularity']);
        $yearOnYear = $this->trendAmounts($storeIds, $calendar['yoyStart'], $calendar['yoyEnd'], $metricCode, $calendar['granularity']);
        $monthOnMonth = $this->trendAmounts($storeIds, $calendar['momStart'], $calendar['momEnd'], $metricCode, $calendar['granularity']);
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $cursor = new DateTimeImmutable($calendar['currentStart'], $timezone);
        $end = new DateTimeImmutable($calendar['currentEnd'], $timezone);
        $points = [];
        while ($cursor <= $end) {
            $currentKey = $this->trendKey($cursor, $calendar['granularity']);
            $yoyCursor = $cursor->modify('-1 year');
            $momCursor = $calendar['granularity'] === 'day'
                ? $cursor->modify('-1 month')
                : $cursor->modify('-1 year');
            $yoyKey = $this->trendKey($yoyCursor, $calendar['granularity']);
            $momKey = $this->trendKey($momCursor, $calendar['granularity']);
            $currentCents = $cursor > $today ? null : (int)($current[$currentKey] ?? 0);
            $yoyCents = (int)($yearOnYear[$yoyKey] ?? 0);
            $momCents = (int)($monthOnMonth[$momKey] ?? 0);
            $points[] = [
                'key' => $currentKey,
                'label' => $calendar['granularity'] === 'day'
                    ? $cursor->format('j')
                    : ($calendar['granularity'] === 'month' ? $cursor->format('n月') : $cursor->format('Y年')),
                // Future periods are intentionally gaps, not zeroes.
                'current' => $currentCents === null ? null : (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($currentCents),
                'yoy' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($yoyCents),
                'mom' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($momCents),
                'currentCents' => $currentCents,
                'yoyCents' => $yoyCents,
                'momCents' => $momCents,
            ];
            $cursor = $calendar['granularity'] === 'day'
                ? $cursor->modify('+1 day')
                : ($calendar['granularity'] === 'month' ? $cursor->modify('+1 month') : $cursor->modify('+1 year'));
        }

        return [
            'metricCode' => $metricCode,
            'metricName' => $this->trendMetricName($metricCode),
            'granularity' => $calendar['granularity'],
            'points' => $points,
            'periods' => [
                'current' => ['label' => '本期', 'range' => $calendar['currentLabel']],
                'yoy' => ['label' => '同比', 'range' => $calendar['yoyLabel']],
                'mom' => ['label' => '环比', 'range' => $calendar['momLabel']],
            ],
        ];
    }

    private function trendMetricCode(string $code): string
    {
        return in_array($code, ['cash_performance', 'actual_performance', 'consume_amount', 'refund_performance'], true)
            ? $code
            : 'cash_performance';
    }

    private function trendMetricName(string $code): string
    {
        $definition = $this->dictionary->getTooltip($this->trendMetricCode($code));
        if (($definition['user_ready'] ?? false) !== true) throw new \RuntimeException('MOBILE_METRIC_DICTIONARY_NOT_READY');
        return (string)$definition['name'];
    }

    /** @return array<string,string> */
    private function trendCalendar(array $period): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $start = new DateTimeImmutable((string)$period['startDate'], $timezone);
        $end = new DateTimeImmutable((string)$period['endDate'], $timezone);
        if ($start->format('Y-m') === $end->format('Y-m')) {
            $currentStart = $end->modify('first day of this month');
            $currentEnd = $end->modify('last day of this month');
            $yoyStart = $currentStart->modify('-1 year');
            $yoyEnd = $currentEnd->modify('-1 year');
            $momStart = $currentStart->modify('-1 month');
            $momEnd = $currentEnd->modify('-1 month');
            return [
                'granularity' => 'day',
                'currentStart' => $currentStart->format('Y-m-d'), 'currentEnd' => $currentEnd->format('Y-m-d'),
                'yoyStart' => $yoyStart->format('Y-m-d'), 'yoyEnd' => $yoyEnd->format('Y-m-d'),
                'momStart' => $momStart->format('Y-m-d'), 'momEnd' => $momEnd->format('Y-m-d'),
                'currentLabel' => $currentStart->format('Y年n月'),
                'yoyLabel' => $yoyStart->format('Y年n月'),
                'momLabel' => $momStart->format('Y年n月'),
            ];
        }

        if ($start->format('Y') === $end->format('Y')) {
            $year = $end->format('Y');
            $currentStart = new DateTimeImmutable($year . '-01-01', $timezone);
            $currentEnd = new DateTimeImmutable($year . '-12-31', $timezone);
            $yoyStart = $currentStart->modify('-1 year');
            $yoyEnd = $currentEnd->modify('-1 year');
            $momStart = $currentStart->modify('-1 year');
            $momEnd = $currentEnd->modify('-1 year');
            return [
                'granularity' => 'month',
                'currentStart' => $currentStart->format('Y-m-d'), 'currentEnd' => $currentEnd->format('Y-m-d'),
                'yoyStart' => $yoyStart->format('Y-m-d'), 'yoyEnd' => $yoyEnd->format('Y-m-d'),
                'momStart' => $momStart->format('Y-m-d'), 'momEnd' => $momEnd->format('Y-m-d'),
                'currentLabel' => $year . '年',
                'yoyLabel' => $yoyStart->format('Y年'),
                'momLabel' => $momStart->format('Y年'),
            ];
        }

        // A range spanning calendar years is intentionally a year chart.
        // Each point is a full calendar year, never a client-side roll-up.
        $currentStart = new DateTimeImmutable($start->format('Y') . '-01-01', $timezone);
        $currentEnd = new DateTimeImmutable($end->format('Y') . '-12-31', $timezone);
        $yoyStart = $currentStart->modify('-1 year');
        $yoyEnd = $currentEnd->modify('-1 year');
        $momStart = $currentStart->modify('-1 year');
        $momEnd = $currentEnd->modify('-1 year');
        return [
            'granularity' => 'year',
            'currentStart' => $currentStart->format('Y-m-d'), 'currentEnd' => $currentEnd->format('Y-m-d'),
            'yoyStart' => $yoyStart->format('Y-m-d'), 'yoyEnd' => $yoyEnd->format('Y-m-d'),
            'momStart' => $momStart->format('Y-m-d'), 'momEnd' => $momEnd->format('Y-m-d'),
            'currentLabel' => $currentStart->format('Y年') . '-' . $currentEnd->format('Y年'),
            'yoyLabel' => $yoyStart->format('Y年') . '-' . $yoyEnd->format('Y年'),
            'momLabel' => $momStart->format('Y年') . '-' . $momEnd->format('Y年'),
        ];
    }

    private function trendKey(DateTimeImmutable $date, string $granularity): string
    {
        if ($granularity === 'day') {
            return $date->format('Y-m-d');
        }
        return $granularity === 'month' ? $date->format('Y-m') : $date->format('Y');
    }

    /** @return array<string,int> values stay in cents until the final response projection. */
    private function trendAmounts(array $storeIds, string $startDate, string $endDate, string $metricCode, string $granularity): array
    {
        if ($storeIds === []) {
            return [];
        }
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        return $reader->periodTotals($metricCode, '0', $storeIds, ['start' => $startDate, 'end' => $endDate], $granularity);
    }

    private function summaryMetrics(array $storeIds, array $period): array
    {
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $range = ['start' => $period['startDate'], 'end' => $period['endDate']];
        $items = [];
        $visibleMetrics = [
            'cash_performance' => 'cash_performance',
            'actual_performance' => 'actual_performance',
            'consume_amount' => 'consume_amount',
            'refund_performance' => 'refund_performance',
            'visit_customer' => 'customer_active',
        ];
        foreach ($visibleMetrics as $code => $metricCode) {
            $definition = $this->dictionary->getTooltip($metricCode);
            if (($definition['user_ready'] ?? false) !== true) throw new \RuntimeException('MOBILE_METRIC_DICTIONARY_NOT_READY');
            $value = $storeIds === [] ? 0 : $reader->summary($metricCode, '0', $storeIds, $range);
            $isCount = $code === 'visit_customer';
            $items[] = [
                'code' => $code,
				'name' => (string)$definition['name'],
                'value' => $isCount ? $value : (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($value),
                'valueCents' => $isCount ? null : $value,
                'displayValue' => $isCount
                    ? number_format($value, 0, '.', ',')
                    : number_format((int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($value), 0, '.', ','),
                'unit' => $isCount ? 'count' : 'amount',
                'available' => true,
                'unavailableReason' => '',
            ];
        }
        return $items;
    }

    private function metricValues(array $metrics): array
    {
        $values = [];
        foreach ($metrics as $metric) {
            $values[] = [
                'code' => (string)$metric['code'],
                'value' => $metric['value'],
                'displayValue' => (string)$metric['displayValue'],
                'available' => (bool)$metric['available'],
            ];
        }
        return $values;
    }

    private function rankingCatalog(bool $showOrganizationRanking, bool $showStoreRanking): array
    {
        // 页签使用简短业务名称；metricCode 仍指向统一指标字典，改名不改变统计口径。
        $items = [
            [
                'code' => 'organization',
                'name' => '组织排行',
                'available' => true,
                'scopeRule' => 'AUTHORIZED_ORGANIZATION_OR_SELECTED_STORES',
            ],
            [
                'code' => 'store',
                'name' => '门店排行',
                'available' => true,
                'metricCode' => 'cash_performance',
                'scopeRule' => 'AUTHORIZED_ORGANIZATION_OR_SELECTED_STORES',
            ],
            [
                'code' => 'staff_cash_performance',
                'name' => '员工销售',
                'available' => true,
                'metricCode' => 'staff_sales_yeji',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'staff_labor_performance',
                'name' => '员工劳动',
                'available' => true,
                'metricCode' => 'staff_labor_yeji',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'staff_designated_customer',
                'name' => '员工点客',
                'available' => true,
                'metricCode' => 'staff_designated_num',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'project_count',
                'name' => '服务项目',
                'available' => true,
                'metricCode' => 'service_project_count',
                'metricDictionaryReady' => true,
                'cashMetricCode' => 'cash_performance',
                'groupDimension' => 'category_and_product_name',
            ],
        ];
        return array_values(array_filter($items, static function (array $item) use ($showOrganizationRanking, $showStoreRanking): bool {
            return ((string)$item['code'] !== 'organization' || $showOrganizationRanking)
                && ((string)$item['code'] !== 'store' || $showStoreRanking);
        }));
    }

    private function rankingCode(string $code, bool $showOrganizationRanking, bool $showStoreRanking): string
    {
        $allowed = array_column($this->rankingCatalog($showOrganizationRanking, $showStoreRanking), 'code');
        $default = $showOrganizationRanking ? 'organization' : ($showStoreRanking ? 'store' : 'staff_cash_performance');
        return in_array($code, $allowed, true) ? $code : $default;
    }

    /** @return array<string,mixed> */
    private function entryProjection(array $entry): array
    {
        $personal = (string)($entry['entryType'] ?? '') === MobileMerchantAnalyticsEntryPolicy::TYPE_PERSONAL;
        return [
            'entryType' => (string)($entry['entryType'] ?? ''),
            'entryNodeType' => (string)($entry['entryNodeType'] ?? ''),
            'entryNodeId' => (int)($entry['entryNodeId'] ?? 0),
            'employeeId' => $personal ? (int)($entry['employeeId'] ?? 0) : 0,
            'destination' => $personal ? 'employee-performance' : 'warehouse',
            'showOrganizationRanking' => (bool)($entry['showOrganizationRanking'] ?? false),
            'showStoreRanking' => (bool)($entry['showStoreRanking'] ?? false),
            'organizationPickerEnabled' => (bool)($entry['organizationPickerEnabled'] ?? false),
        ];
    }

    private function rankingOrder(string $order): string
    {
        return strtolower(trim($order)) === 'asc' ? 'asc' : 'desc';
    }

    /** Only the two server-supported sort measures are accepted for service projects. */
    private function projectSortMetric(string $metric): string
    {
        if (!in_array($metric, ['cash', 'quantity'], true)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '服务项目排序指标无效。', 'projectSortMetric');
        }
        return $metric;
    }

    private function organizationRanking(array $rows, string $rankingOrder): array
    {
        usort($rows, static function (array $left, array $right) use ($rankingOrder): int {
            $leftValue = self::metricAmount($left, 'cash_performance');
            $rightValue = self::metricAmount($right, 'cash_performance');
            if ($leftValue === $rightValue) {
                return (int)($left['entityId'] ?? 0) <=> (int)($right['entityId'] ?? 0);
            }
            return $rankingOrder === 'asc'
                ? $leftValue <=> $rightValue
                : $rightValue <=> $leftValue;
        });
        return array_slice($rows, 0, 10);
    }

    /** 门店排行沿用请求的统计区间，按服务端授权范围排序后每页返回 10 家。 */
    private function storeRankingPage(array $storeIds, array $stores, array $period, string $rankingOrder, int $page): array
    {
        $rows = $this->storeRanking($storeIds, $stores, $period, $rankingOrder);
        $startDate = (string)$period['startDate'];
        $endDate = (string)$period['endDate'];
        return self::paginateRankingRows($rows, $page) + [
            'rankingRange' => $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate,
            'selectedRankingCode' => 'store',
            'data_as_of' => time(),
        ];
    }

    /** 对已有完整授权排序结果分页；门店和点客都复用稳定的每批 10 条边界。 */
    private static function paginateRankingRows(array $rows, int $page): array
    {
        $total = count($rows);
        $lastPage = max(1, intdiv($total + 9, 10));
        return [
            'rankingRows' => $page <= $lastPage ? array_slice($rows, ($page - 1) * 10, 10) : [],
            'rankingPage' => $page,
            'rankingTotal' => $total,
            'rankingHasMore' => $page < $lastPage,
        ];
    }

    /**
     * 每家授权门店单独占一行，包括当日零业绩门店。统一指标 Reader 返回分值，
     * 排序仍按分精度；只在响应展示时转整数元，避免并列元数打乱真实顺序。
     */
    private function storeRanking(array $storeIds, array $stores, array $period, string $rankingOrder): array
    {
        if ($storeIds === []) return [];
        $names = array_column($stores, 'name', 'id');
        $projection = $this->registeredStoreProjection($storeIds, $period);
        $rows = [];
        foreach ($storeIds as $storeId) {
            if (!isset($names[$storeId])) continue;
            $cents = (int)($projection['cash_performance'][$storeId] ?? 0);
            $yuan = (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($cents);
            $rows[] = [
                'entityType' => 'store', 'entityId' => (int)$storeId,
                'name' => (string)$names[$storeId], 'metricCode' => 'cash_performance',
                'rankingCents' => $cents, 'rankingValue' => $yuan, 'displayValue' => (string)$yuan,
            ];
        }
        usort($rows, static function (array $left, array $right) use ($rankingOrder): int {
            $comparison = (int)$left['rankingCents'] <=> (int)$right['rankingCents'];
            return $comparison === 0
                ? (int)$left['entityId'] <=> (int)$right['entityId']
                : ($rankingOrder === 'asc' ? $comparison : -$comparison);
        });
        foreach ($rows as &$row) unset($row['rankingCents']);
        unset($row);
        return $rows;
    }

    private static function metricAmount(array $row, string $code): int
    {
        foreach ((array)($row['metrics'] ?? []) as $metric) {
            if ((string)($metric['code'] ?? '') === $code) {
                return (int)str_replace(',', '', (string)($metric['displayValue'] ?? $metric['value'] ?? 0));
            }
        }
        return 0;
    }

    /** Employee rankings use the registered reader; projects reuse the report fact projection below. */
    private function factRankingPage(string $rankingCode, array $storeIds, array $period, string $rankingOrder, int $page, string $projectSortMetric = 'cash'): array
    {
        $range = $period['startDate'] === $period['endDate']
            ? (string)$period['startDate']
            : $period['startDate'] . ' 至 ' . $period['endDate'];
        $base = ['selectedRankingCode' => $rankingCode, 'rankingRange' => $range, 'data_as_of' => time()];
        if ($storeIds === []) return self::paginateRankingRows([], $page) + $base;
        if ($rankingCode === 'staff_designated_customer') {
            return self::paginateRankingRows($this->designatedCustomerRanking($storeIds, $period, $rankingOrder), $page) + $base;
        }
        if ($rankingCode === 'project_count') {
            return $this->serviceProjectRankingPage($storeIds, $period, $rankingOrder, $projectSortMetric, $page) + $base;
        }
        $staffMetrics = [
            'staff_cash_performance' => 'staff_sales_yeji',
            'staff_labor_performance' => 'staff_labor_yeji',
        ];
        $metricCode = $staffMetrics[$rankingCode] ?? null;
        if ($metricCode === null) return self::paginateRankingRows([], $page) + $base;
        // 多取一条只用于判断还有没有下一页，不向客户端暴露；不按展示整数元重新排序。
        $registered = (new \app\services\query\metric\RegisteredMetricReadServices())->dimensionRanking(
            $metricCode, 'employee', '0', $storeIds,
            ['start' => $period['startDate'], 'end' => $period['endDate']], 11, $rankingOrder, ($page - 1) * 10
        );
        $rows = [];
        foreach ($registered as $row) {
            $rows[] = ['entity_id' => $row['entity_id'], 'entity_name' => $row['entity_name'], 'ranking_value' => $row['metric_value']];
        }
        return [
            'rankingRows' => $this->registeredStaffRankingRows(array_slice($rows, 0, 10)),
            'rankingPage' => $page,
            // 排行只展示续页，不展示总数；不能把当前页数量冒充完整命中量。
            'rankingTotal' => null,
            'rankingHasMore' => count($registered) > 10,
        ] + $base;
    }

    /**
     * A project row combines completed-service quantity with signed cash
     * collection allocated to sold projects. The two measures have different
     * event dates; both obey the requested range and the same server-enforced
     * store scope. Card components and refunds stay in the existing report
     * cash allocator, rather than being reconstructed by the mobile page.
     */
    private function serviceProjectRankingPage(array $storeIds, array $period, string $order, string $sortMetric, int $page): array
    {
        $range = ['start' => (string)$period['startDate'], 'end' => (string)$period['endDate']];
        $facts = new StoreUnifiedReportPhaseFourServices();
        $rows = self::serviceProjectRankingRows(
            $facts->customerCashRows($storeIds, $range),
            $facts->customerServiceRows($storeIds, $range),
            $sortMetric,
            $order
        );
        return self::paginateRankingRows($rows, $page);
    }

    /**
     * Merge store copies only when both current platform category path and
     * event-time product name match. Amounts stay in cents for sorting; the
     * API projects whole yuan only after the final order is known.
     *
     * @param array<int,array<string,mixed>> $cashFacts signed allocated cash facts
     * @param array<int,array<string,mixed>> $serviceFacts completed service facts
     */
    private static function serviceProjectRankingRows(array $cashFacts, array $serviceFacts, string $sortMetric, string $order): array
    {
        $groups = [];
        foreach ($cashFacts as $fact) {
            // Product type 6 is a service project. A card is expanded into
            // its components by the shared report reader before this point.
            if ((int)($fact['product_type'] ?? -1) !== 6) continue;
            $name = trim((string)($fact['item_name'] ?? ''));
            if ($name === '') continue;
            $category = trim((string)($fact['category_path'] ?? ''));
            $key = hash('sha256', json_encode([$category, $name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!isset($groups[$key])) $groups[$key] = ['entityType' => 'project_group', 'entityId' => $key, 'name' => $name,
                'categoryPath' => $category, 'quantity' => 0, 'cashCents' => 0, 'hasChildren' => false];
            $groups[$key]['cashCents'] += (int)($fact['amount_cents'] ?? 0);
        }
        foreach ($serviceFacts as $fact) {
            $name = trim((string)($fact['project_name_snapshot'] ?? ''));
            if ($name === '') continue;
            $category = trim((string)($fact['category_path'] ?? ''));
            $key = hash('sha256', json_encode([$category, $name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!isset($groups[$key])) $groups[$key] = ['entityType' => 'project_group', 'entityId' => $key, 'name' => $name,
                'categoryPath' => $category, 'quantity' => 0, 'cashCents' => 0, 'hasChildren' => false];
            $groups[$key]['quantity'] += max(0, (int)($fact['quantity'] ?? 0));
        }
        $rows = array_values($groups);
        usort($rows, static function (array $left, array $right) use ($sortMetric, $order): int {
            $field = $sortMetric === 'quantity' ? 'quantity' : 'cashCents';
            $comparison = (int)$left[$field] <=> (int)$right[$field];
            if ($comparison !== 0) return $order === 'asc' ? $comparison : -$comparison;
            return strcmp((string)$left['entityId'], (string)$right['entityId']);
        });
        foreach ($rows as &$row) {
            $yuan = (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$row['cashCents']);
            $row['cashPerformance'] = $yuan;
            $row['cashDisplay'] = number_format($yuan, 0, '.', ',');
            $row['rankingValue'] = $sortMetric === 'quantity' ? (int)$row['quantity'] : $yuan;
            $row['displayValue'] = $sortMetric === 'quantity' ? (string)$row['quantity'] : $row['cashDisplay'];
            unset($row['cashCents']);
        }
        unset($row);
        return $rows;
    }

    /** 与员工详情共用点客指标和十分位展示，原始服务记录由指标服务统一识别和去重。 */
    private function designatedCustomerRanking(array $storeIds, array $period, string $rankingOrder): array
    {
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $rows = $reader->pointVisitRanking('0', $storeIds, [
            'start' => $period['startDate'], 'end' => $period['endDate'],
        ], $rankingOrder);
        $result = [];
        foreach ($rows as $row) {
            $count = (int)$row['metric_value'];
            $result[] = [
                'entityType' => 'employee',
                'entityId' => (int)$row['entity_id'],
                'name' => trim((string)$row['entity_name']) ?: '未命名员工',
                'rankingValue' => $count / 10,
                'displayValue' => self::displayScaledCount($count, 1),
                'unit' => 'count',
                'hasChildren' => false,
            ];
        }
        return $result;
    }

    /** Registered staff performance is stored in cents; project groups use the separate fact projection. */
    private function registeredStaffRankingRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $raw = (int)($row['ranking_value'] ?? 0);
            $value = (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($raw);
            $result[] = [
                'entityType' => 'employee',
                'entityId' => (int)($row['entity_id'] ?? 0),
                'name' => trim((string)($row['entity_name'] ?? '')) ?: '未命名员工',
                'rankingValue' => $value,
                'displayValue' => number_format($value, 0, '.', ','),
                'unit' => 'amount',
                'hasChildren' => false,
            ];
        }
        return $result;
    }
}
