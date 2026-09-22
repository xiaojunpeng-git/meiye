<?php

namespace app\services\report;

use app\services\BaseServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\metric\MetricDictionaryServices;
use think\facade\Db;

/**
 * 门店统一报表 V1：只读 V3 不可变事实，门店范围由控制器强制注入。
 * 不读取旧订单、staff_yeji 或页面传入的组织/门店范围。
 */
class StoreUnifiedReportServices extends BaseServices
{
    const METRIC_VERSION = 'store-unified-report-v1';
    const COVERAGE_START = '2026-08-10';

    /** @var int 仅由认证后的门店报表控制器注入 */
    private $participantEmployeeId = 0;

    public function catalog()
    {
        return array_merge([
            ['folder' => '门店运营', 'code' => 'partner_item_summary', 'name' => '合作方品项汇总'],
            ['folder' => '门店运营', 'code' => 'partner_item_detail', 'name' => '合作方品项明细'],
            ['folder' => '门店运营', 'code' => 'member_consumption_detail', 'name' => '会员消费明细'],
            ['folder' => '门店运营', 'code' => 'store_item_analysis', 'name' => '门店品项分析'],
            ['folder' => '门店运营', 'code' => 'store_craftsman_consumption', 'name' => '门店手艺人消耗'],
            // 明细只通过“门店手艺人消耗”中的受控下钻进入，不能单独作为导航入口。
            ['folder' => '门店运营', 'code' => 'store_craftsman_consumption_detail', 'name' => '手艺人消耗明细', 'hidden' => true],
            ['folder' => '门店运营', 'code' => 'store_salesperson_performance', 'name' => '门店销售人业绩'],
        ], StoreUnifiedReportPhaseTwoServices::catalogEntries());
    }

    public function definitions()
    {
        $wanted = ['cash_performance', 'actual_performance', 'balance_deduction_amount', 'recharge_amount', 'consume_amount', 'customer_pre_sale', 'customer_post_sale', 'customer_pending_conversion', 'customer_guest', 'customer_active', 'customer_effective', 'customer_sleeping', 'customer_consumption_tier', 'customer_annual_30000', 'unconsumed_performance', 'performance_100_percent', 'performance_after_split', 'performance_transfer_card', 'performance_external', 'sales_gross_amount', 'refund_completed_amount', 'sales_net_amount', 'channel_conflict'];
        $definitions = [];
        foreach ((new MetricDictionaryServices())->getDefinitions() as $row) {
            if (in_array($row['code'], $wanted, true)) $definitions[] = $row;
        }
        return $definitions;
    }

    public function query($storeId, array $input)
    {
        $reportScope = is_array($input['_report_scope'] ?? null) ? $input['_report_scope'] : [];
        if ((string)($reportScope['mode'] ?? '') === 'none') {
            throw new \InvalidArgumentException('当前账号没有可查看的数据范围');
        }
        $this->participantEmployeeId = (string)($reportScope['mode'] ?? '') === 'self_participant'
            ? max(0, (int)($reportScope['employee_id'] ?? 0)) : 0;
        if ((string)($reportScope['mode'] ?? '') === 'self_participant' && $this->participantEmployeeId <= 0) {
            throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
        }
        $report = (string)($input['report'] ?? 'overview');
        $range = $this->range($input);
        $meta = $this->meta($range);
        $phaseTwo = new StoreUnifiedReportPhaseTwoServices();
        if ($phaseTwo->supports($report)) {
            return array_merge($meta, $phaseTwo->query($report, $storeId, $range, $input));
        }
        if (in_array($report, [
            'partner_item_summary', 'partner_item_detail', 'member_consumption_detail',
            'store_item_analysis', 'store_craftsman_consumption', 'store_craftsman_consumption_detail',
            'store_salesperson_performance',
        ], true)) {
            return array_merge($meta, $this->operationsReport($report, $storeId, $range, $input));
        }
        if ($report === 'overview') return array_merge($meta, $this->overview($storeId, $range, $input));
        if ($report === 'sales') return array_merge($meta, $this->sales($storeId, $range, $input));
        if ($report === 'service') return array_merge($meta, $this->service($storeId, $range, $input));
        if ($report === 'customers') return array_merge($meta, $this->customers($storeId, $range, $input));
        if ($report === 'items') return array_merge($meta, $this->items($storeId, $range, $input));
        if ($report === 'channels') return array_merge($meta, $this->channels($storeId, $range, $input));
        throw new \InvalidArgumentException('不支持的报表类型');
    }

    public function export($storeId, array $input)
    {
        // Export is the same complete authorized result set as the screen,
        // rather than the first browser page capped at 100 records.
        $result = $this->query($storeId, array_merge($input, ['_internal_all' => true, 'page' => 1]));
        return [
            'filename' => '门店经营报表-' . ($result['title'] ?? '数据') . '-' . date('YmdHis') . '.csv',
            'columns' => $result['columns'] ?? [], 'records' => $result['records'] ?? [],
            // Keep the export metadata aligned with the queried report. Phase
            // two reports have an independent metric version and summary row.
            'summary_row' => $result['summary_row'] ?? [],
            'column_groups' => $result['column_groups'] ?? [],
            'metric_version' => $result['metric_version'] ?? self::METRIC_VERSION,
            'data_as_of' => $result['data_as_of'] ?? '',
            'aggregation_status' => $result['aggregation_status'] ?? '',
        ];
    }

    /**
     * 第一阶段门店运营六表。所有金额均来自正式 V3 事实；分类、合作方和体验
     * 维度来自成交时写入的 report_sale_dimension_fact 快照，绝不按当前配置回算历史。
     */
    private function operationsReport(string $report, $storeId, array $range, array $input): array
    {
        switch ($report) {
            case 'partner_item_summary': return $this->partnerItemSummary($storeId, $range, $input);
            case 'partner_item_detail': return $this->partnerItemDetail($storeId, $range, $input);
            case 'member_consumption_detail': return $this->memberConsumptionDetail($storeId, $range, $input);
            case 'store_item_analysis': return $this->storeItemAnalysis($storeId, $range, $input);
            case 'store_craftsman_consumption': return $this->craftsmanConsumption($storeId, $range, $input);
            case 'store_craftsman_consumption_detail': return $this->craftsmanConsumptionDetail($storeId, $range, $input);
            case 'store_salesperson_performance': return $this->salespersonPerformance($storeId, $range, $input);
            default: throw new \InvalidArgumentException('不支持的门店运营报表类型');
        }
    }

    private function operationSaleQuery($storeId, array $range, array $input, bool $expandCardCategories = true)
    {
        $query = Db::name('cashier_v3_sale_fact')->alias('s')
            ->leftJoin('cashier_v3_report_sale_dimension_fact d', 'd.sale_fact_id=s.fact_id')
            ->whereBetween('s.business_date', [$range['start'], $range['end']])
            ->where('s.status', 'effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query, 's.tenant_id', 's.order_id');
        // Summary/detail projections expand a card by its contained project
        // category. The member-consumption table intentionally keeps one row
        // per sold card, then aggregates its component shares in PHP.
        if ($expandCardCategories) {
            $query->leftJoin('cashier_v3_card_sale_category_allocation_fact c', "c.sale_fact_id=s.fact_id AND c.status='effective'");
        }
        if (is_array($storeId)) $query->whereIn('s.store_id', array_values(array_unique(array_map('intval', $storeId))));
        else $query->where('s.store_id', (int)$storeId);
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyOrder($query, 's.order_id', $this->participantEmployeeId);
        }
        // The organization dimension filter must operate on the immutable
        // sale-fact path and business date before an operation report groups
        // or paginates its result set.
        $this->organizationDimensions()->applyFilters($query, 's', $input, $range);
        $this->operationFilters($query, $input, $expandCardCategories);
        return $query;
    }

    private function organizationDimensions(): StoreUnifiedReportOrganizationDimensionServices
    {
        static $service;
        if (!$service) $service = new StoreUnifiedReportOrganizationDimensionServices();
        return $service;
    }

    private function normalDataScope(): StoreReportNormalDataScopeServices
    {
        static $service;
        if (!$service) $service = new StoreReportNormalDataScopeServices();
        return $service;
    }

    /**
     * Aggregate a registered metric at the already-filtered sale-line grain.
     * The report supplies only a narrowing set of immutable line identifiers;
     * source, amount expression and normal-data guards remain owned by Reader.
     *
     * @return array<string,int>
     */
    private function applyOrganizationDimensions(array &$row): void
    {
        $this->organizationDimensions()->project(
            $row,
            (string)($row['organization_id'] ?? ''),
            (string)($row['organization_path_snapshot'] ?? ''),
            (string)($row['business_date'] ?? '')
        );
        // Keep the established first-phase key so existing exports and
        // manual-report consumers receive the configured company name.
        $row['division_name'] = (string)$row['company'];
    }

    private function organizationDimensionColumns(): array
    {
        return [
            [
                'key' => 'division_name', 'label' => '分公司',
                'source_explanation' => $this->organizationDimensions()->sourceExplanation('company'),
            ],
        ];
    }

    private function organizationDimensionFilterSchema(array $range): array
    {
        return $this->organizationDimensions()->filterSchema($range);
    }

    private function operationFilters($query, array $input, bool $expandCardCategories = true): void
    {
        $categoryId = (int)($input['category_id'] ?? 0);
        $path = trim((string)($input['category_path'] ?? ''));
        $exactPath = trim((string)($input['category_path_exact'] ?? ''));
        $type = trim((string)($input['product_type'] ?? ''));
        $partner = trim((string)($input['partner_name'] ?? ''));
        if ($expandCardCategories) {
            if ($categoryId > 0) $query->whereRaw('COALESCE(c.category_id_snapshot,d.category_id_snapshot)=?', [$categoryId]);
            if ($path !== '') $query->whereRaw('COALESCE(c.category_path_snapshot,d.category_path_snapshot) LIKE ?', [$path . '%']);
            // A summary-row drilldown must match its frozen full category path;
            // the ordinary category search above intentionally remains a prefix filter.
            if ($exactPath !== '') $query->whereRaw('COALESCE(c.category_path_snapshot,d.category_path_snapshot)=?', [$exactPath]);
            if ($type !== '') $query->whereRaw('COALESCE(c.product_type_snapshot,d.product_type_snapshot)=?', [$type]);
            if ($partner !== '') $query->whereRaw('COALESCE(c.partner_name_snapshot,d.partner_name_snapshot)=?', [$partner]);
        } else {
            if ($categoryId > 0) $query->where(function ($sub) use ($categoryId) {
                $sub->where('d.category_id_snapshot', $categoryId)->whereExists(function ($card) use ($categoryId) {
                    $card->name('cashier_v3_card_sale_category_allocation_fact')
                        ->whereRaw('sale_fact_id=s.fact_id')->where('status', 'effective')
                        ->where('category_id_snapshot', $categoryId);
                }, 'OR');
            });
            if ($path !== '') $query->where(function ($sub) use ($path) {
                $sub->whereLike('d.category_path_snapshot', $path . '%')->whereExists(function ($card) use ($path) {
                    $card->name('cashier_v3_card_sale_category_allocation_fact')
                        ->whereRaw('sale_fact_id=s.fact_id')->where('status', 'effective')
                        ->whereLike('category_path_snapshot', $path . '%');
                }, 'OR');
            });
            if ($type !== '') $query->where(function ($sub) use ($type) {
                $sub->where('d.product_type_snapshot', $type)->whereExists(function ($card) use ($type) {
                    $card->name('cashier_v3_card_sale_category_allocation_fact')
                        ->whereRaw('sale_fact_id=s.fact_id')->where('status', 'effective')
                        ->where('product_type_snapshot', $type);
                }, 'OR');
            });
            if ($partner !== '') $query->where(function ($sub) use ($partner) {
                $sub->where('d.partner_name_snapshot', $partner)->whereExists(function ($card) use ($partner) {
                    $card->name('cashier_v3_card_sale_category_allocation_fact')
                        ->whereRaw('sale_fact_id=s.fact_id')->where('status', 'effective')
                        ->where('partner_name_snapshot', $partner);
                }, 'OR');
            });
        }
        if ((int)($input['salesperson_id'] ?? 0) > 0) $query->whereExists(function ($sub) use ($input) {
            $sub->name('cashier_v3_performance_fact')->whereRaw('source_line_id=s.source_line_id')->where('employee_id', (int)$input['salesperson_id'])->where('performance_type', 'sales_performance_allocated')->where('status', 'effective');
        });
        if ((int)($input['guide_id'] ?? 0) > 0) $query->whereExists(function ($sub) use ($input) {
            $sub->name('cashier_v3_customer_guide_round_fact')->whereRaw('order_id=s.order_id')->where('guide_employee_id', (int)$input['guide_id'])->where('status', 'effective');
        });
        if ((int)($input['sales_manager_id'] ?? 0) > 0) $query->whereExists(function ($sub) use ($input) {
            $sub->name('cashier_v3_sales_manager_fact')->whereRaw('order_id=s.order_id')->where('sales_manager_employee_id', (int)$input['sales_manager_id'])->where('status', 'effective');
        });
    }

    private function partnerItemSummary($storeId, array $range, array $input): array
    {
        $query = $this->operationSaleQuery($storeId, $range, $input)->whereRaw("COALESCE(c.partner_name_snapshot,d.partner_name_snapshot)<>''");
        $facts = $query->fieldRaw("s.business_date,s.organization_id,s.organization_path_snapshot,s.store_id,s.store_name_snapshot AS store_name,COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) AS category_id_snapshot,COALESCE(c.category_path_snapshot,d.category_path_snapshot) AS category_path_snapshot,COALESCE(c.partner_name_snapshot,d.partner_name_snapshot) AS partner_name_snapshot,0 AS sale_amount_cents,COALESCE(d.is_experience,0) AS is_experience,s.member_id,s.quantity,s.source_line_id,(SELECT COALESCE(SUM(pf.labor_fee_amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective') AS labor_amount_cents")
            ->order('s.business_date', 'desc')->order('s.id', 'desc')->select()->toArray();
        $saleByLine = $this->metricSourceLineCategoryTotals('sales_amount', $storeId, $range, $input, array_column($facts, 'source_line_id'));
        $consumeByLine = $this->metricSourceLineCategoryTotals('consume_amount', $storeId, $range, $input, array_column($facts, 'source_line_id'));
        $grouped = [];
        foreach ($facts as $fact) {
            $consumeKey = (string)($fact['source_line_id'] ?? '') . '|' . (int)($fact['category_id_snapshot'] ?? 0);
            $fact['sale_amount_cents'] = (int)($saleByLine[$consumeKey] ?? 0);
            $fact['consumption_amount_cents'] = (int)($consumeByLine[$consumeKey] ?? 0);
            $this->applyOrganizationDimensions($fact);
            $month = substr((string)$fact['business_date'], 0, 7);
            $categoryPath = (string)$fact['category_path_snapshot'];
            // A hidden partner label must not split two rows that display the
            // same full category. The visible month/store/dimensions/path are
            // the summary grain; partner filtering is applied before grouping.
            $key = $this->partnerSummaryGroupKey($month, $fact, $categoryPath);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'month' => $month, 'division_name' => (string)$fact['division_name'],
                    'company_dimension_id' => (string)$fact['company_dimension_id'],
                    'city_manager' => (string)$fact['city_manager'],
                    'city_manager_dimension_id' => (string)$fact['city_manager_dimension_id'],
                    'store_id' => (int)$fact['store_id'], 'store_name' => (string)$fact['store_name'],
                    'category_path_snapshot' => $categoryPath,
                    // Keep the entire sale-time category snapshot visible: rows
                    // are already grouped by this path, not just its first level.
                    'performance_type' => $categoryPath,
                    'sale_amount_cents' => 0, 'experience_count' => 0, 'quantity' => 0,
                    'consumption_amount_cents' => 0, 'labor_amount_cents' => 0,
                    '_member_ids' => [],
                ];
            }
            $row =& $grouped[$key];
            $row['sale_amount_cents'] += (int)$fact['sale_amount_cents'];
            $row['experience_count'] += (int)$fact['is_experience'] === 1 ? (int)$fact['quantity'] : 0;
            $row['quantity'] += (int)$fact['quantity'];
            $row['consumption_amount_cents'] += (int)$fact['consumption_amount_cents'];
            $row['labor_amount_cents'] += (int)$fact['labor_amount_cents'];
            if ((int)$fact['member_id'] > 0) $row['_member_ids'][(string)$fact['member_id']] = true;
            unset($row);
        }
        $rows = array_values($grouped);
        foreach ($rows as &$row) {
            $row['store_summary'] = (string)$row['store_name'];
            $row['member_count'] = count($row['_member_ids']);
            unset($row['_member_ids']);
            $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']);
            $row['consumption_amount'] = $this->money((int)$row['consumption_amount_cents']);
            $row['labor_amount'] = $this->money((int)$row['labor_amount_cents']);
        }
        unset($row);
        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)$right['month'], (string)$left['month'])
                ?: ((int)$right['sale_amount_cents'] <=> (int)$left['sale_amount_cents']);
        });
        $columns = $this->fixedColumns(array_merge([
            ['key'=>'month','label'=>'月份'],
        ], $this->organizationDimensionColumns(), [
            ['key'=>'store_summary','label'=>'门店汇总'],['key'=>'performance_type','label'=>'分类','source_explanation'=>'显示成交时卡内项目或商品的完整分类路径；同一完整路径的成交额合并，不同路径分别列示。'],['key'=>'experience_count','label'=>'体验人次'],['key'=>'member_count','label'=>'成交人头'],['key'=>'consumption_amount','label'=>'消耗业绩'],['key'=>'labor_amount','label'=>'手工汇总'],['key'=>'sale_amount','label'=>'成交业绩'],
        ]), ['month'=>88, 'division_name'=>130, 'city_manager'=>130, 'store_summary'=>132, 'performance_type'=>220]);
        foreach ($columns as &$column) {
            if (!in_array((string)$column['key'], ['experience_count','member_count','consumption_amount','labor_amount','sale_amount'], true)) continue;
            $column['drilldown'] = ['report'=>'partner_item_detail', 'param_map'=>[
                'store_ids'=>'store_id', 'category_path_exact'=>'category_path_snapshot',
                'company_dimension_id'=>'company_dimension_id', 'city_manager_dimension_id'=>'city_manager_dimension_id',
            ]];
        }
        unset($column);
        return [
            'title'=>'合作方品项汇总','columns'=>$columns, 'records'=>$rows,'total'=>count($rows),'page'=>1,'page_size'=>count($rows),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true],
            'summary_row'=>$this->summaryRow($columns, [
                'month'=>'合计', 'experience_count'=>array_sum(array_column($rows, 'experience_count')),
                'member_count'=>'-', 'consumption_amount'=>$this->money(array_sum(array_column($rows, 'consumption_amount_cents'))),
                'labor_amount'=>$this->money(array_sum(array_column($rows, 'labor_amount_cents'))),
                'sale_amount'=>$this->money(array_sum(array_column($rows, 'sale_amount_cents'))),
            ]),
        ];
    }

    /**
     * Partner item totals share one row per visible month, store, organization,
     * and full frozen category path. Partner labels are filters, not a hidden
     * grouping dimension; JSON encoding also avoids path delimiter collisions.
     */
    private function partnerSummaryGroupKey(string $month, array $fact, string $categoryPath): string
    {
        return (string)json_encode([
            $month,
            (string)($fact['store_id'] ?? ''),
            (string)($fact['company_dimension_id'] ?? ''),
            (string)($fact['city_manager_dimension_id'] ?? ''),
            $categoryPath,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function partnerItemDetail($storeId, array $range, array $input): array
    {
        $query = $this->operationSaleQuery($storeId, $range, $input)->whereRaw("COALESCE(c.partner_name_snapshot,d.partner_name_snapshot)<>''");
        $total = (int)(clone $query)->count('s.id');
        $summary = (clone $query)->fieldRaw("COUNT(DISTINCT NULLIF(s.member_id,0)) AS member_count,SUM(s.quantity) AS quantity,SUM((SELECT COALESCE(SUM(pf.labor_fee_amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective')) AS labor_amount_cents")->find() ?: [];
        $selectedCategories = (clone $query)->fieldRaw('s.source_line_id,COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) AS category_id_snapshot')->select()->toArray();
        $summarySales = $this->metricSourceLineCategoryTotals(
            'sales_amount', $storeId, $range, $input, array_column($selectedCategories, 'source_line_id')
        );
        $summary['sale_amount_cents'] = $this->selectedCategoryMetricTotal($selectedCategories, $summarySales);
        $summaryConsume = $this->metricSourceLineCategoryTotals(
            'consume_amount', $storeId, $range, $input, array_column($selectedCategories, 'source_line_id')
        );
        $summary['consumption_amount_cents'] = $this->selectedCategoryMetricTotal($selectedCategories, $summaryConsume);
        $rows = (clone $query)->leftJoin('user u', 'u.uid = s.member_id')
            ->fieldRaw("s.store_id,s.business_date,s.organization_id,s.organization_path_snapshot,s.store_name_snapshot,s.order_no_snapshot,s.member_id,s.member_name_snapshot,u.phone AS member_phone,s.item_name_snapshot,s.source_type,s.quantity,0 AS sale_amount_cents,COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) AS category_id_snapshot,COALESCE(c.product_type_snapshot,d.product_type_snapshot) AS product_type_snapshot,COALESCE(c.category_path_snapshot,d.category_path_snapshot) AS category_path_snapshot,COALESCE(c.partner_name_snapshot,d.partner_name_snapshot) AS partner_name_snapshot,d.is_experience,s.source_line_id,s.fact_id,(SELECT COALESCE(SUM(pf.labor_fee_amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective') AS labor_amount_cents")
            ->order('s.business_date','desc')->order('s.id','desc')->page($this->page($input),$this->limit($input))->select()->toArray();
        $pageSales = $this->metricSourceLineCategoryTotals('sales_amount', $storeId, $range, $input, array_column($rows, 'source_line_id'));
        $pageConsume = $this->metricSourceLineCategoryTotals('consume_amount', $storeId, $range, $input, array_column($rows, 'source_line_id'));
        foreach ($rows as &$row) {
            $consumeKey = (string)($row['source_line_id'] ?? '') . '|' . (int)($row['category_id_snapshot'] ?? 0);
            $row['sale_amount_cents'] = (int)($pageSales[$consumeKey] ?? 0);
            $row['consumption_amount_cents'] = (int)($pageConsume[$consumeKey] ?? 0);
        }
        unset($row);
        $rows = $this->attachAnnotations($rows, 'partner_item_detail', $storeId);
        foreach ($rows as &$row) { $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']); $row['consumption_amount'] = $this->money((int)$row['consumption_amount_cents']); $row['labor_amount'] = $this->money((int)$row['labor_amount_cents']); $row['experience'] = (int)$row['is_experience'] === 1 ? '是' : '否'; }
        unset($row);
        foreach ($rows as &$row) { $this->applyOrganizationDimensions($row); $row['member_phone'] = (string)($row['member_phone'] ?? ''); $row['performance_type'] = (string)$row['category_path_snapshot']; $row['experience_project'] = $row['experience']; $row['deal_headcount'] = (int)($row['member_id'] ?? 0) > 0 ? 1 : 0; $row['deal_project'] = $row['item_name_snapshot']; $row['consumption'] = $row['consumption_amount'] ?? '0'; $row['consumption_amount'] = $row['consumption_amount'] ?? '0'; $row['labor_fee'] = $row['labor_amount'] ?? '0'; $row['deal_amount'] = $row['sale_amount']; $row['partner_label'] = $row['partner_name_snapshot']; }
        unset($row);
        $columns = $this->fixedColumns(array_merge($this->organizationDimensionColumns(), [
            ['key'=>'store_name_snapshot','label'=>'门店'],['key'=>'member_name_snapshot','label'=>'会员'],['key'=>'member_phone','label'=>'手机'],['key'=>'performance_type','label'=>'业绩类型','source_explanation'=>'显示成交时商品或卡内项目的完整分类路径，与合作方品项汇总的分类一致。'],['key'=>'business_date','label'=>'日期'],['key'=>'experience_project','label'=>'体验项目'],['key'=>'medical_elevation','label'=>'复诊'],['key'=>'medical_followup','label'=>'类型'],['key'=>'deal_headcount','label'=>'成交人头'],['key'=>'deal_project','label'=>'成交项目'],['key'=>'consumption','label'=>'消耗'],['key'=>'consumption_amount','label'=>'消耗金额'],['key'=>'labor_fee','label'=>'手工费'],['key'=>'quantity','label'=>'数量'],['key'=>'deal_amount','label'=>'成交金额'],['key'=>'expert_name','label'=>'专家姓名'],['key'=>'partner_label','label'=>'合作方'],['key'=>'remark','label'=>'备注'],
        ]), ['division_name'=>130, 'city_manager'=>130, 'store_name_snapshot'=>126, 'member_name_snapshot'=>88, 'member_phone'=>116, 'performance_type'=>220, 'business_date'=>104]);
        return [
            'title'=>'合作方品项明细','columns'=>$columns, 'records'=>$rows,'total'=>$total,'page'=>$this->page($input),'page_size'=>$this->limit($input),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true],
            'summary_row'=>$this->summaryRow($columns, [
                'store_name_snapshot'=>'合计', 'deal_headcount'=>(int)($summary['member_count'] ?? 0),
                'consumption'=>$this->money((int)($summary['consumption_amount_cents'] ?? 0)),
                'consumption_amount'=>$this->money((int)($summary['consumption_amount_cents'] ?? 0)),
                'labor_fee'=>$this->money((int)($summary['labor_amount_cents'] ?? 0)),
                'quantity'=>(int)($summary['quantity'] ?? 0), 'deal_amount'=>$this->money((int)($summary['sale_amount_cents'] ?? 0)),
            ]),
        ];
    }

    private function memberConsumptionDetail($storeId, array $range, array $input): array
    {
        $query = $this->operationSaleQuery($storeId, $range, $input, false);
        // 会员消费明细是“已收款的销售明细”，不是所有已成交明细。
        // 按同一销售事实的有效分摊净额判断，欠款后续收款可使原行进入报表，
        // 退款/作废反向分摊抵尽时则退出；列表、合计和导出共用此条件。
        $query->whereExists(function ($receipt) {
            $receipt->name('cashier_v3_payment_sale_allocation_fact')->alias('member_receipt')
                ->whereRaw('member_receipt.tenant_id=s.tenant_id AND member_receipt.store_id=s.store_id AND member_receipt.sale_fact_id=s.fact_id')
                ->where('member_receipt.status', 'effective')
                ->group('member_receipt.tenant_id,member_receipt.store_id,member_receipt.sale_fact_id')
                ->having('SUM(member_receipt.amount_cents)>0');
        });
        $total = (int)(clone $query)->count('s.id');
        $partnerDefinitions = $this->partnerPerformanceDefinitions($storeId);
        $summaryValues = $this->memberConsumptionSummaryValues($query, $storeId, $partnerDefinitions);
        $rowQuery = (clone $query)->fieldRaw("s.fact_id,s.tenant_id,s.store_id,s.business_date,s.organization_id,s.organization_path_snapshot,s.store_name_snapshot,s.order_no_snapshot,s.order_id,s.source_line_id,s.member_id,s.member_name_snapshot,s.item_name_snapshot,d.category_path_snapshot,d.product_type_snapshot,s.source_type,s.quantity,s.sale_amount_cents,d.partner_category_id_snapshot,d.partner_category_path_snapshot,d.partner_share_amount_cents,s.business_source_label_snapshot,s.source_attribution_type_snapshot,d.is_experience")->order('s.business_date','desc')->order('s.id','desc');
        // 导出沿用相同的后端过滤，但不能被页面分页截断。
        if (empty($input['_internal_all'])) $rowQuery->page($this->page($input), $this->limit($input));
        $rows = $rowQuery->select()->toArray();
        $this->decorateMemberConsumptionRows($rows, $storeId, $partnerDefinitions, $this->cardPartnerSharesBySaleFact($rows, $storeId));
        foreach ($rows as &$row) {
            $this->applyOrganizationDimensions($row);
            $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']);
            $row['consume_type'] = in_array((string)$row['source_type'], ['refund','void','cancel'], true) ? (string)$row['source_type'] : '正常';
            $row['member_name'] = (string)$row['member_name_snapshot'];
            $row['consumption_detail'] = (string)$row['item_name_snapshot'];
            $row['category'] = (string)$row['category_path_snapshot'];
            $row['experience_cash'] = !empty($row['is_experience']) ? $row['cash_receipt_total'] : '0';
            $row['experience_payment_method'] = !empty($row['is_experience']) ? $row['payment_method_names'] : '';
        }
        unset($row);
        // Apply persisted manual overrides after deriving fact defaults.  The
        // annotation is the controlled projection for these two editable
        // columns and must win on every subsequent query/refresh.
        $rows = $this->attachAnnotations($rows, 'member_consumption_detail', $storeId, !empty($input['_internal_all']));
        $columns = array_merge($this->organizationDimensionColumns(), [
            ['key'=>'business_date','label'=>'日期'], ['key'=>'consume_type','label'=>'消费类型'], ['key'=>'member_name','label'=>'姓名'],
            ['key'=>'consumption_detail','label'=>'消费明细'], ['key'=>'category','label'=>'分类'], ['key'=>'sales_manager_name','label'=>'销售经理'],
            ['key'=>'guide_round_no','label'=>'导购业绩次数'], ['key'=>'guide_names','label'=>'导购人员'], ['key'=>'salesperson_names','label'=>'销售'],
            ['key'=>'member_source','label'=>'会员来源'],
        ]);
        foreach ($this->paymentMethodDefinitions() as $method) $columns[] = ['key'=>'payment_'.$method['code'],'label'=>$method['label'],'group_label'=>'支付现金业绩方式'];
        $columns[] = ['key'=>'receipt_total','label'=>'收款总金额','group_label'=>'支付现金业绩方式','source_explanation'=>'本表仅列出销售明细分摊后的有效记账收款净额大于 0 的记录；各收款方式合计为本行收款总金额，退款或作废的反向收款会抵减。'];
        foreach ($partnerDefinitions as $definition) $columns[] = ['key'=>$definition['key'],'label'=>$definition['label'],'group_label'=>'合作方分成业绩'];
        foreach ([['partner_performance','合作方业绩'],['actual_cash_performance','分成后现金业绩'],['experience_cash','体验现金业绩'],['experience_payment_method','体验现金业绩支付方式']] as $column) $columns[] = ['key'=>$column[0],'label'=>$column[1]];
        $columns = $this->fixedColumns($columns, [
            'business_date'=>104, 'consume_type'=>88, 'member_name'=>88,
            'consumption_detail'=>138, 'category'=>116,
        ]);
        return [
            'title'=>'会员消费明细','columns'=>$columns,'column_groups'=>$this->columnGroups($columns),'records'=>$rows,'total'=>$total,'page'=>$this->page($input),'page_size'=>$this->limit($input),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true],
            'summary_row'=>$this->summaryRow($columns, array_merge(['business_date'=>'合计'], $summaryValues)),
        ];
    }

    private function storeItemAnalysis($storeId, array $range, array $input): array
    {
        $today = ['start' => $range['end'], 'end' => $range['end']];
        $cumulative = ['start' => self::COVERAGE_START, 'end' => $range['end']];
        // Header categories come from all currently visible product categories,
        // never from whichever facts happened to occur in the selected period.
        $definitions = $this->itemAnalysisCategoryDefinitions($storeId);
        $period = $this->itemAnalysisMetricRows($storeId, $range, $input, $definitions);
        $daily = $this->itemAnalysisMetricRows($storeId, $today, $input, $definitions);
        $total = $this->itemAnalysisMetricRows($storeId, $cumulative, $input, $definitions);
        $rows = [];
        foreach ([$period, $daily, $total] as $metrics) {
            foreach ($metrics['stores'] as $storeIdKey => $store) {
                if (!isset($rows[$storeIdKey])) {
                    $rows[$storeIdKey] = [
                        'division_name' => (string)$store['division_name'],
                        'company_dimension_id' => (string)$store['company_dimension_id'],
                        'city_manager' => (string)$store['city_manager'],
                        'city_manager_dimension_id' => (string)$store['city_manager_dimension_id'],
                        'store_name' => $store['store_name'],
                    ];
                }
            }
        }
        foreach ($rows as &$row) {
            foreach (['cash', 'share', 'actual', 'consume'] as $metric) {
                $row['item_analysis_' . $metric . '_today'] = '0';
                $row['item_analysis_' . $metric . '_cumulative'] = '0';
            }
            foreach ($definitions as $definition) {
                foreach (['cash', 'share', 'consume'] as $metric) {
                    $row[$definition['key'] . '_' . $metric] = '0';
                }
            }
        }
        unset($row);
        foreach (['today' => $daily, 'cumulative' => $total] as $suffix => $metrics) {
            foreach ($metrics['stores'] as $storeIdKey => $store) {
                if (!isset($rows[$storeIdKey])) continue;
                $cash = (int)$store['cash_cents'];
                $share = (int)$store['share_cents'];
                $rows[$storeIdKey]['item_analysis_cash_' . $suffix] = $this->money($cash);
                $rows[$storeIdKey]['item_analysis_share_' . $suffix] = $this->money($share);
                $rows[$storeIdKey]['item_analysis_actual_' . $suffix] = $this->money($cash - $share);
                $rows[$storeIdKey]['item_analysis_consume_' . $suffix] = $this->money((int)$store['consume_cents']);
            }
        }
        foreach ($period['categories'] as $storeIdKey => $categories) {
            if (!isset($rows[$storeIdKey])) continue;
            foreach ($categories as $categoryKey => $amounts) {
                if (!isset($definitions[$categoryKey])) continue;
                $base = $definitions[$categoryKey]['key'];
                $rows[$storeIdKey][$base . '_cash'] = $this->money((int)$amounts['cash_cents']);
                $rows[$storeIdKey][$base . '_share'] = $this->money((int)$amounts['share_cents']);
                $rows[$storeIdKey][$base . '_consume'] = $this->money((int)$amounts['consume_cents']);
            }
        }
        uasort($rows, static function (array $left, array $right): int {
            return strcmp((string)$left['store_name'], (string)$right['store_name']);
        });

        $organizationColumns = $this->organizationDimensionColumns();
        // 取值来源面向门店使用者解释，不暴露组织维度等实现术语。
        $organizationColumns[0]['source_explanation'] = '显示业务门店所属的分公司；按当前分公司设置取名称，未设置时显示“未配置分公司”。';
        $columns = array_merge($organizationColumns, [[
            'key' => 'store_name', 'label' => '门店',
            'logic' => '产生销售或服务记录的门店；只显示当前账号可以查看且符合筛选条件的门店。',
        ]]);
        $groups = [
            ['label' => '分公司', 'column_keys' => ['division_name'], 'rowspan' => 2, 'tone' => 'basic'],
            ['label' => '城市经理', 'column_keys' => ['city_manager'], 'rowspan' => 2, 'tone' => 'basic'],
            ['label' => '门店', 'column_keys' => ['store_name'], 'rowspan' => 2, 'tone' => 'basic'],
        ];
        foreach ([
            ['cash', '现金业绩', '成功收款后记下的现金业绩，不含余额支付和欠款。', 'payment'],
            ['share', '分成业绩', '各分类现金业绩按成交时记录的合作方比例计算后相加。', 'partner'],
            ['actual', '分成后业绩', '现金业绩减去分成业绩；该列不是实际业绩，也不是实际收款金额。', 'result'],
            ['consume', '消耗业绩', '已完成服务或核销记下的消耗金额；发生冲销时按冲销日期扣回。', 'consumption'],
        ] as $summary) {
            [$metric, $label, $logic, $tone] = $summary;
            $keys = [];
            foreach ([['today', '当日'], ['cumulative', '累计']] as $periodLabel) {
                $key = 'item_analysis_' . $metric . '_' . $periodLabel[0];
                $keys[] = $key;
                $timeLogic = $periodLabel[0] === 'today'
                    ? '只看查询结束日当天。'
                    : '从 2026-08-10 累计到查询结束日。';
                $columns[] = ['key' => $key, 'label' => $periodLabel[1], 'logic' => $logic . $timeLogic];
            }
            $groups[] = ['label' => $label, 'column_keys' => $keys, 'tone' => $tone];
        }
        foreach ($definitions as $definition) {
            $keys = [];
            // 当前配置决定显示哪些分类列；发生业务时记录的分类决定金额归属。
            // “未分类”只承接无法可靠归入现行分类的历史金额，不重写旧服务。
            $isUnclassified = (int)$definition['category_id'] === 0;
            $metricExplanations = $isUnclassified ? [
                ['cash', '现金业绩', '所选日期内已有收款记录，但成交时的分类缺失、已失效或无法对应当前分类的现金业绩；不含余额支付和欠款。'],
                ['share', '现金分成业绩', '上述未分类现金业绩中，成交时已记录合作方分成的金额；未设置分成时为 0。'],
                ['consume', '消耗业绩', '所选日期内完成的服务或核销，其当时记录的项目分类缺失、已失效或无法对应当前分类时，消耗金额留在这里。冲销按发生日扣回；以后改项目分类不会改动历史记录。'],
            ] : [
                ['cash', '现金业绩', '所选日期内成功收款并记下的现金业绩，按成交时记录的商品或卡内项目分类归入本列；不含余额支付和欠款。'],
                ['share', '现金分成业绩', '本分类的现金业绩按成交时记录的合作方比例计算；以后修改比例不会重算历史金额。'],
                ['consume', '消耗业绩', '所选日期内已完成服务或核销的消耗金额，按完成时记录的项目分类归入本列；冲销按发生日扣回，以后改项目分类不会重算历史金额。'],
            ];
            foreach ($metricExplanations as $metric) {
                $key = $definition['key'] . '_' . $metric[0];
                $keys[] = $key;
                $columns[] = ['key' => $key, 'label' => $metric[1], 'logic' => $metric[2]];
            }
            $groups[] = ['label' => $definition['label'], 'column_keys' => $keys, 'tone' => 'category'];
        }
        $summaryValues = ['store_name'=>'合计'];
        foreach (['today'=>$daily, 'cumulative'=>$total] as $suffix => $metrics) {
            $summaryCents = [];
            foreach (['cash', 'share', 'consume'] as $metric) {
                $cents = 0;
                foreach ($metrics['stores'] as $store) $cents += (int)($store[$metric . '_cents'] ?? 0);
                $summaryCents[$metric] = $cents;
                $summaryValues['item_analysis_' . $metric . '_' . $suffix] = $this->money($cents);
            }
            $summaryValues['item_analysis_actual_' . $suffix] = $this->money((int)$summaryCents['cash'] - (int)$summaryCents['share']);
        }
        foreach ($definitions as $definition) {
            $categoryId = (string)$definition['category_id'];
            foreach (['cash', 'share', 'consume'] as $metric) {
                $cents = 0;
                foreach ($period['categories'] as $categories) $cents += (int)($categories[$categoryId][$metric . '_cents'] ?? 0);
                $summaryValues[$definition['key'] . '_' . $metric] = $this->money($cents);
            }
        }
        // 固定列的通用默认文案会覆盖本报表写明的业务说明；先填入用户实际
        // 看到的 source_explanation，保证弹窗与导出读到同一段明确文字。
        foreach ($columns as &$column) {
            if (trim((string)($column['logic'] ?? '')) !== '') {
                $column['source_explanation'] = (string)$column['logic'];
            }
        }
        unset($column);
        $columns = $this->fixedColumns($columns, ['division_name'=>130, 'city_manager'=>130, 'store_name'=>140]);
        return [
            'title' => '门店品项分析', 'columns' => $columns,
            'column_groups' => $groups, 'records' => array_values($rows),
            'total' => count($rows), 'page' => 1, 'page_size' => count($rows),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout' => ['fixed'=>true],
            'summary_row' => $this->summaryRow($columns, $summaryValues),
        ];
    }

    /** @return array{stores:array<int,array>,categories:array<int,array>} */
    private function itemAnalysisMetricRows($storeId, array $range, array $input, array $definitions): array
    {
        $stores = [];
        $categories = [];
        $authorizedStores = is_array($storeId)
            ? array_values(array_unique(array_filter(array_map('intval', $storeId))))
            : [(int)$storeId];
        // Organization filters narrow the authorized store set before every
        // source is read. They never act as a page-side post-filter.
        $authorizedStores = $this->organizationDimensions()->narrowStoreIds($authorizedStores, $input);
        if ($authorizedStores === []) return compact('stores', 'categories');
        $shareRows = $this->operationSaleQuery($authorizedStores, $range, $input)
            ->fieldRaw("s.store_id,s.store_name_snapshot,s.organization_id,s.organization_path_snapshot,s.business_date,s.source_line_id,COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) AS category_id_snapshot,COALESCE(c.category_path_snapshot,d.category_path_snapshot) AS category_path_snapshot,COALESCE(c.partner_share_amount_cents,d.partner_share_amount_cents,0) AS share_cents")
            ->select()->toArray();
        $categoryIds = (int)($input['category_id'] ?? 0) > 0 ? [(int)$input['category_id']] : [];
        $metricFilters = $this->itemAnalysisMetricFilters($input);
        $metricReader = new \app\services\query\metric\RegisteredMetricReadServices();
        // "本人参与" is a data-scope guard, not a page-side post-filter and
        // not a request filter. The Reader's dedicated server-side entry
        // applies it to every selected fact population.
        $cashRows = $this->participantEmployeeId > 0
            ? $metricReader->categoryReportBucketsForParticipant('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, $authorizedStores, $range, $categoryIds, $metricFilters, $this->participantEmployeeId)
            : $metricReader->categoryReportBuckets('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, $authorizedStores, $range, $categoryIds, $metricFilters);
        foreach ($cashRows as $entry) {
            $entry['category_id_snapshot'] = (int)($entry['category_id'] ?? 0);
            $entry['category_path_snapshot'] = (string)($entry['category_path'] ?? '');
            $this->applyOrganizationDimensions($entry);
            $storeKey = $this->itemAnalysisStore($stores, $entry);
            $stores[$storeKey]['cash_cents'] = (int)$entry['store_metric_value'];
            $category = $this->itemAnalysisConfiguredCategory(
                $definitions,
                (int)($entry['category_id_snapshot'] ?? 0),
                (string)$entry['category_path_snapshot']
            );
            if ($category === null) continue;
            $this->itemAnalysisCategoryAmount($categories, $storeKey, $category);
            $categories[$storeKey][$category['id']]['cash_cents'] = (int)$entry['metric_value'];
        }
        foreach ($shareRows as $entry) {
            $this->applyOrganizationDimensions($entry);
            $storeKey = $this->itemAnalysisStore($stores, $entry);
            $stores[$storeKey]['share_cents'] += (int)$entry['share_cents'];
            $category = $this->itemAnalysisConfiguredCategory(
                $definitions,
                (int)($entry['category_id_snapshot'] ?? 0),
                (string)$entry['category_path_snapshot']
            );
            if ($category === null) continue;
            $this->itemAnalysisCategoryAmount($categories, $storeKey, $category);
            $categories[$storeKey][$category['id']]['share_cents'] += (int)$entry['share_cents'];
        }
        $consumeRows = $this->participantEmployeeId > 0
            ? $metricReader->categoryReportBucketsForParticipant('consume_amount', CashierV3ScopeResolver::TENANT_SCOPE_ID, $authorizedStores, $range, $categoryIds, $metricFilters, $this->participantEmployeeId)
            : $metricReader->categoryReportBuckets('consume_amount', CashierV3ScopeResolver::TENANT_SCOPE_ID, $authorizedStores, $range, $categoryIds, $metricFilters);
        foreach ($consumeRows as $entry) {
            $entry['category_id_snapshot'] = (int)($entry['category_id'] ?? 0);
            $entry['category_path_snapshot'] = (string)($entry['category_path'] ?? '');
            $this->applyOrganizationDimensions($entry);
            $storeKey = $this->itemAnalysisStore($stores, $entry);
            $stores[$storeKey]['consume_cents'] = (int)$entry['store_metric_value'];
            $category = $this->itemAnalysisConfiguredCategory(
                $definitions,
                (int)($entry['category_id_snapshot'] ?? 0),
                (string)$entry['category_path_snapshot']
            );
            if ($category === null) continue;
            $this->itemAnalysisCategoryAmount($categories, $storeKey, $category);
            $categories[$storeKey][$category['id']]['consume_cents'] = (int)$entry['metric_value'];
        }
        return compact('stores', 'categories');
    }

    private function itemAnalysisMetricFilters(array $input): array
    {
        $filters = [];
        foreach (['category_path', 'product_type', 'partner_name'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if ($value !== '') $filters[$key] = $value;
        }
        foreach (['salesperson_id', 'guide_id', 'sales_manager_id'] as $key) {
            $value = (int)($input[$key] ?? 0);
            if ($value > 0) $filters[$key] = $value;
        }
        if (array_key_exists('is_experience', $input) && $input['is_experience'] !== '' && $input['is_experience'] !== null) {
            $filters['is_experience'] = (int)$input['is_experience'];
        }
        return $filters;
    }

    private function itemAnalysisStore(array &$stores, array $entry): string
    {
        $key = implode('|', [
            (string)($entry['store_id'] ?? 0), (string)($entry['company_dimension_id'] ?? ''),
            (string)($entry['city_manager_dimension_id'] ?? ''),
        ]);
        if (!isset($stores[$key])) {
            $stores[$key] = [
                'store_name' => (string)($entry['store_name_snapshot'] ?? $entry['store_name'] ?? ''),
                'division_name' => (string)($entry['division_name'] ?? ''),
                'company_dimension_id' => (string)($entry['company_dimension_id'] ?? ''),
                'city_manager' => (string)($entry['city_manager'] ?? ''),
                'city_manager_dimension_id' => (string)($entry['city_manager_dimension_id'] ?? ''),
                'cash_cents' => 0, 'share_cents' => 0, 'consume_cents' => 0,
            ];
        }
        return $key;
    }

    private function itemAnalysisCategoryAmount(array &$categories, string $storeId, array $category): void
    {
        if (!isset($categories[$storeId][$category['id']])) {
            $categories[$storeId][$category['id']] = ['label' => $category['label'], 'cash_cents' => 0, 'share_cents' => 0, 'consume_cents' => 0];
        }
    }

    private function metricSourceLineCategoryTotals(string $metricCode, $storeId, array $range, array $input, array $sourceLineIds): array
    {
        $lineIds = array_values(array_unique(array_filter(array_map('strval', $sourceLineIds), static function (string $line): bool {
            return $line !== '';
        })));
        if ($lineIds === []) return [];
        $stores = is_array($storeId)
            ? array_values(array_unique(array_filter(array_map('intval', $storeId))))
            : [(int)$storeId];
        return (new \app\services\query\metric\RegisteredMetricReadServices())->sourceLineCategoryTotals(
            $metricCode, CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range, $lineIds,
            $this->itemAnalysisMetricFilters($input)
        );
    }

    /**
     * A line can contain several card categories. Its detail total must only
     * include the category pairs left by the report's exact path filter, while
     * the amount for each pair still comes from the registered metric reader.
     */
    private function selectedCategoryMetricTotal(array $selectedCategories, array $amountsByLineCategory): int
    {
        $total = 0;
        $seen = [];
        foreach ($selectedCategories as $row) {
            $key = (string)($row['source_line_id'] ?? '') . '|' . (int)($row['category_id_snapshot'] ?? 0);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $total += (int)($amountsByLineCategory[$key] ?? 0);
        }
        return $total;
    }

    /**
     * Facts keep their own category snapshots. Match the frozen product
     * category ID first; a product below a projected second level then falls
     * back to its frozen path, never to a current product category tree.
     *
     * @param array<string,array{key:string,label:string,category_id:int,category_path:string}> $definitions
     * @return array{id:string,label:string}|null
     */
    private function itemAnalysisConfiguredCategory(array $definitions, int $categoryId, string $path): ?array
    {
        if ($categoryId > 0 && isset($definitions[(string)$categoryId])) {
            $definition = $definitions[(string)$categoryId];
            return ['id' => (string)$definition['category_id'], 'label' => $definition['label']];
        }
        $path = $this->itemAnalysisCategoryPath($path);
        if ($path === '') return isset($definitions['0']) ? ['id' => '0', 'label' => '未分类'] : null;

        $matched = null;
        foreach ($definitions as $definition) {
            $configuredPath = $this->itemAnalysisCategoryPath((string)$definition['category_path']);
            if ($configuredPath === '' || ($path !== $configuredPath && strpos($path, $configuredPath . '/') !== 0)) {
                continue;
            }
            if ($matched === null || strlen($configuredPath) > strlen($matched['path'])) {
                $matched = ['path' => $configuredPath, 'definition' => $definition];
            }
        }
        if ($matched === null) {
            // 服务事实已有金额但历史分类被删除或未冻结时，保留在“未分类”列，
            // 不让分类列合计静默少于门店消耗总额，也不猜入任一现行分类。
            return isset($definitions['0']) ? ['id' => '0', 'label' => '未分类'] : null;
        }
        $definition = $matched['definition'];
        return ['id' => (string)$definition['category_id'], 'label' => $definition['label']];
    }

    private function itemAnalysisCategoryPath(string $path): string
    {
        $parts = preg_split('/\\s*\\/\\s*/u', trim($path)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static function (string $part): bool {
            return $part !== '' && $part !== '全部';
        }));
        return implode('/', array_slice($parts, 0, 2));
    }

    /**
     * @return array<string,array{key:string,label:string,category_id:int,category_path:string}>
     */
    private function itemAnalysisCategoryDefinitions($storeId): array
    {
        $unclassified = ['0' => [
            'key' => 'item_analysis_category_0', 'label' => '未分类',
            'category_id' => 0, 'category_path' => '未分类',
        ]];
        $categories = [];
        foreach (Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name,is_show')->select()->toArray() as $category) {
            $id = (int)($category['id'] ?? 0);
            if ($id > 0) $categories[$id] = $category;
        }
        if ($categories === []) return $unclassified;

        $visible = [];
        foreach ($categories as $id => $category) {
            if ((int)($category['is_show'] ?? 0) === 1) $visible[$id] = true;
        }
        if ($visible === []) return $unclassified;

        // Keep the same two-level projection used by partner columns, but
        // apply it to every visible category. A visible root is replaced by
        // its visible direct children; deeper categories roll up to that
        // second level and never create a third-level header.
        $visibleDirectChildren = [];
        foreach (array_keys($visible) as $categoryId) {
            $chain = $this->partnerCategoryChain((int)$categoryId, $categories);
            if ($chain === []) continue;
            $rootId = (int)$chain[0]['id'];
            if (count($chain) >= 2 && (int)$chain[1]['id'] === (int)$categoryId) {
                $visibleDirectChildren[$rootId][(int)$categoryId] = true;
            }
        }

        $definitions = [];
        foreach (array_keys($visible) as $categoryId) {
            $chain = $this->partnerCategoryChain((int)$categoryId, $categories);
            if ($chain === []) continue;
            $rootId = (int)$chain[0]['id'];
            $effective = $chain[0];
            if (count($chain) >= 2) {
                $secondId = (int)$chain[1]['id'];
                if (isset($visible[$secondId])) {
                    $effective = $chain[1];
                } elseif (isset($visibleDirectChildren[$rootId])) {
                    // The current row is visible but its parent is hidden;
                    // retain the visible root rather than exposing a hidden
                    // category as a report column.
                    $effective = $chain[0];
                }
            } elseif (isset($visibleDirectChildren[$rootId])) {
                continue;
            }
            $effectiveId = (int)$effective['id'];
            $categoryPath = $this->itemAnalysisCategoryPath($this->partnerCategoryPathLabel($chain, $effectiveId));
            if ($effectiveId <= 0 || $categoryPath === '') continue;
            $definitions[(string)$effectiveId] = [
                'key' => 'item_analysis_category_' . $effectiveId,
                'label' => $categoryPath,
                'category_id' => $effectiveId,
                'category_path' => $categoryPath,
            ];
        }
        uasort($definitions, static function (array $left, array $right): int {
            return strcmp((string)$left['category_path'], (string)$right['category_path'])
                ?: ((int)$left['category_id'] <=> (int)$right['category_id']);
        });
        // 已发生的服务消耗不能因分类缺失从动态列里消失。
        return $definitions + $unclassified;
    }

    private function craftsmanConsumption($storeId, array $range, array $input): array
    {
        $stores = is_array($storeId) ? array_values(array_unique(array_map('intval', $storeId))) : [(int)$storeId];
        $stores = $this->organizationDimensions()->narrowStoreIds($stores, $input);
        $employeeIds = $this->personnelSelection($input, 'craftsman_id');
        // This report is a craftsman projection. "消耗" is the craftsman's
        // allocated labor performance; "手工" is the independent fee saved
        // with that allocation, never a second use of amount_cents.
        $matrix = ($stores === [] || $employeeIds === null)
            ? ['records' => [], 'summary' => ['day_metric_values' => [], 'total_metric_value' => 0, 'day_labor_values' => [], 'total_labor_value' => 0]]
            : (new \app\services\query\metric\RegisteredMetricReadServices())->personnelDayMatrix('staff_labor_yeji', '0', $stores, $range, $employeeIds, true);
        $by = $matrix['records'];
        $summaryConsume = $matrix['summary']['day_metric_values'];
        $summaryLabor = $matrix['summary']['day_labor_values'];
        foreach ($by as &$row) {
            $this->applyOrganizationDimensions($row);
            foreach (range(1, 31) as $day) {
                $metricValue = (int)($row['day_metric_values'][$day] ?? 0);
                $laborValue = (int)($row['day_labor_values'][$day] ?? 0);
                $row['day_'.$day.'_consume'] = $this->money($metricValue);
                $row['day_'.$day.'_labor'] = $this->money($laborValue);
                if ($metricValue !== 0 || $laborValue !== 0) {
                    $row['_drilldown']['day_'.$day.'_consume'] = $this->craftsmanConsumptionDrilldown($day);
                    $row['_drilldown']['day_'.$day.'_labor'] = $this->craftsmanConsumptionDrilldown($day);
                }
            }
            if ((int)$row['total_metric_value'] !== 0 || (int)$row['total_labor_value'] !== 0) {
                $row['_drilldown']['total_consume'] = $this->craftsmanConsumptionDrilldown();
                $row['_drilldown']['total_labor'] = $this->craftsmanConsumptionDrilldown();
            }
        }
        unset($row);
        $columns = array_merge($this->organizationDimensionColumns(), [[
            'key'=>'employee_name','label'=>'手艺人',
            'source_explanation'=>'显示当前仍有效的服务中，结账或后续调整时记录的手艺人姓名；服务作废后不再显示。',
        ]]);
        foreach (range(1,31) as $day) {
            $columns[] = [
                'key'=>'day_'.$day.'_consume','label'=>$day.'日消耗','group_label'=>$day.'日',
                'source_explanation'=>$day.'日当前仍有效、未作废的服务分配给该手艺人的消耗业绩合计；服务作废后，原金额和冲销金额都不显示。',
            ];
            $columns[] = [
                'key'=>'day_'.$day.'_labor','label'=>$day.'日手工','group_label'=>$day.'日',
                'source_explanation'=>$day.'日当前仍有效、未作废的服务分配给该手艺人的手工费合计；没有手工费时显示 0，服务作废后不再显示。',
            ];
        }
        $columns[] = ['key'=>'total_consume','label'=>'合计消耗','source_explanation'=>'查询日期内当前仍有效、未作废服务的消耗业绩合计；已作废服务在任何日期都不计入。'];
        $columns[] = ['key'=>'total_labor','label'=>'合计手工','source_explanation'=>'查询日期内当前仍有效、未作废服务的手工费合计；已作废服务在任何日期都不计入。'];
        foreach ($by as &$row) {
            // Compatibility keys mirror a Reader-computed value; they are not
            // recomputed from page rows and remain available to exports/tests.
            $row['total_consume_cents'] = (int)$row['total_metric_value'];
            $row['total_labor_cents'] = (int)$row['total_labor_value'];
            $row['total_consume']=$this->money($row['total_consume_cents']);
            $row['total_labor']=$this->money($row['total_labor_cents']);
        }
        unset($row);
        $summaryValues = ['employee_name'=>'合计'];
        foreach (range(1, 31) as $day) {
            $summaryValues['day_'.$day.'_consume'] = $this->money((int)($summaryConsume[$day] ?? 0));
            $summaryValues['day_'.$day.'_labor'] = $this->money((int)($summaryLabor[$day] ?? 0));
        }
        $summaryValues['total_consume'] = $this->money((int)$matrix['summary']['total_metric_value']);
        $summaryValues['total_labor'] = $this->money((int)$matrix['summary']['total_labor_value']);
        $columns = $this->fixedColumns($columns, ['division_name'=>130, 'city_manager'=>130, 'employee_name'=>120]);
        return [
            'title'=>'门店手艺人消耗','columns'=>$columns,'column_groups'=>$this->columnGroups($columns),'records'=>$by,'total'=>count($by),'page'=>1,'page_size'=>count($by),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true], 'summary_row'=>$this->summaryRow($columns, $summaryValues),
        ];
    }

    /**
     * 手艺人消耗汇总直接下钻订单中心的服务记录，而非停留在内部事实弹窗。
     * 日列仍按所选范围内的日号匹配事实发生日，跨月时不得擅自缩成单一天。
     */
    private function craftsmanConsumptionDrilldown(int $dayOfMonth = 0): array
    {
        $params = $dayOfMonth >= 1 && $dayOfMonth <= 31 ? ['day_of_month' => $dayOfMonth] : [];
        return [
            'report' => 'order_center_service',
            'params' => $params,
            'param_map' => ['craftsman_id' => 'employee_id', 'store_ids' => 'store_id'],
        ];
    }

    /**
     * 手艺人消耗明细和上层汇总读取同一 performance_fact，并共同排除已经
     * 作废的服务。原始事实与冲销事实仍留作审计，但不进入当前经营结果。
     */
    private function craftsmanConsumptionDetail($storeId, array $range, array $input): array
    {
        $stores = is_array($storeId) ? array_values(array_unique(array_map('intval', $storeId))) : [(int)$storeId];
        $stores = $this->organizationDimensions()->narrowStoreIds($stores, $input);
        $employeeIds = $this->personnelSelection($input, 'craftsman_id');
        $dayOfMonth = (int)($input['day_of_month'] ?? 0);
        $detail = ($stores === [] || $employeeIds === null)
            ? ['rows' => [], 'total_metric_value' => 0, 'total_labor_value' => 0]
            : (new \app\services\query\metric\RegisteredMetricReadServices())->personnelDetailResult('staff_labor_yeji', '0', $stores, $range, $employeeIds, $dayOfMonth, true);
        $allRows = $detail['rows'];
        $itemNames = $this->sourceLineItemNames(CashierV3ScopeResolver::TENANT_SCOPE_ID, $allRows);
        foreach ($allRows as &$row) {
            $row['store_name'] = (string)($row['store_name_snapshot'] ?? '');
            $row['employee_name'] = (string)($row['employee_name_snapshot'] ?? '');
            $this->applyOrganizationDimensions($row);
            $itemName = trim((string)($itemNames[(string)($row['order_id'] ?? '') . '|' . (string)($row['source_line_id'] ?? '')] ?? ''));
            $row['item_name_snapshot'] = $itemName !== '' ? $itemName : '来源行 ' . (string)($row['source_line_id'] ?? '-');
            $row['consumption_amount'] = $this->money((int)($row['metric_value'] ?? 0));
            $row['labor_amount'] = $this->money((int)($row['labor_fee_amount_cents'] ?? 0));
            $row['project_count'] = ((int)($row['project_count_half_units'] ?? 0)) / 2;
            $row['business_status'] = (string)($row['fact_direction'] ?? '') === 'reversal' ? '冲销' : '正常';
        }
        unset($row);
        $total = count($allRows);
        $records = !empty($input['_internal_all'])
            ? $allRows
            : array_slice($allRows, ($this->page($input) - 1) * $this->limit($input), $this->limit($input));
        $columns = $this->fixedColumns(array_merge($this->organizationDimensionColumns(), [
            ['key'=>'store_name','label'=>'门店'], ['key'=>'business_date','label'=>'业务日期'],
            ['key'=>'order_no_snapshot','label'=>'订单号'], ['key'=>'member_name_snapshot','label'=>'会员'],
            ['key'=>'item_name_snapshot','label'=>'项目'], ['key'=>'employee_name','label'=>'手艺人'],
            ['key'=>'consumption_amount','label'=>'消耗'], ['key'=>'labor_amount','label'=>'手工费'],
            ['key'=>'project_count','label'=>'项目数'], ['key'=>'business_status','label'=>'状态'],
        ]), ['division_name'=>130, 'city_manager'=>130, 'store_name'=>140, 'business_date'=>112, 'order_no_snapshot'=>165, 'member_name_snapshot'=>110, 'item_name_snapshot'=>160, 'employee_name'=>110]);
        return [
            'title' => '手艺人消耗明细', 'columns' => $columns, 'records' => $records,
            'total' => $total, 'page' => $this->page($input), 'page_size' => $this->limit($input),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout' => ['fixed'=>true],
            'summary_row' => $this->summaryRow($columns, [
                'employee_name' => '合计', 'consumption_amount' => $this->money((int)$detail['total_metric_value']),
                'labor_amount' => $this->money((int)$detail['total_labor_value']),
            ]),
        ];
    }

    private function salespersonPerformance($storeId, array $range, array $input): array
    {
        $stores = is_array($storeId) ? array_values(array_unique(array_map('intval', $storeId))) : [(int)$storeId];
        $stores = $this->organizationDimensions()->narrowStoreIds($stores, $input);
        $employeeIds = $this->personnelSelection($input, 'salesperson_id');
        $matrix = ($stores === [] || $employeeIds === null)
            ? ['records' => [], 'summary' => ['day_metric_values' => [], 'total_metric_value' => 0]]
            : (new \app\services\query\metric\RegisteredMetricReadServices())->personnelDayMatrix('staff_sales_yeji', '0', $stores, $range, $employeeIds);
        $by = $matrix['records'];
        $summaryPerformance = $matrix['summary']['day_metric_values'];
        // 销售人只产生销售业绩事实，不产生手艺人手工费；日期直接作为列名展示，
        // 不再返回二级日期分组表头或无意义的手工列。
        $columns=array_merge($this->organizationDimensionColumns(), [['key'=>'employee_name','label'=>'销售人']]); foreach(range(1,31) as $day){$columns[]=['key'=>'day_'.$day.'_performance','label'=>$day.'日业绩'];} $columns[]=['key'=>'total_performance','label'=>'合计业绩'];
        foreach($by as &$row){
            $this->applyOrganizationDimensions($row);
            foreach(range(1,31) as $day)$row['day_'.$day.'_performance']=$this->money((int)($row['day_metric_values'][$day]??0));
            $row['total_performance_cents']=(int)$row['total_metric_value'];
            $row['total_performance']=$this->money($row['total_performance_cents']);
        } unset($row);
        $summaryValues = ['employee_name'=>'合计']; foreach(range(1,31) as $day)$summaryValues['day_'.$day.'_performance']=$this->money((int)($summaryPerformance[$day]??0)); $summaryValues['total_performance']=$this->money((int)$matrix['summary']['total_metric_value']);
        $columns = $this->fixedColumns($columns, ['division_name'=>130, 'city_manager'=>130, 'employee_name'=>120]);
        return [
            'title'=>'门店销售人业绩','columns'=>$columns,'records'=>$by,'total'=>count($by),'page'=>1,'page_size'=>count($by),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true], 'summary_row'=>$this->summaryRow($columns, $summaryValues),
        ];
    }

    /** @return array<int,int>|null null means two narrowing personnel filters conflict. */
    private function personnelSelection(array $input, string $inputKey): ?array
    {
        $requested = max(0, (int)($input[$inputKey] ?? 0));
        if ($this->participantEmployeeId > 0 && $requested > 0 && $requested !== $this->participantEmployeeId) return null;
        $employeeId = $this->participantEmployeeId > 0 ? $this->participantEmployeeId : $requested;
        return $employeeId > 0 ? [$employeeId] : [];
    }

    /**
     * Item text is presentation metadata, not an amount source. Performance
     * values have already been fixed by the registered Reader before this
     * optional label lookup runs.
     *
     * @return array<string,string>
     */
    private function sourceLineItemNames(string $tenantId, array $rows): array
    {
        $orders = [];
        foreach ($rows as $row) {
            $orderId = trim((string)($row['order_id'] ?? ''));
            $lineId = trim((string)($row['source_line_id'] ?? ''));
            if ($orderId !== '' && $lineId !== '') $orders[$orderId . '|' . $lineId] = [$orderId, $lineId];
        }
        if ($orders === []) return [];
        $lineIds = array_values(array_unique(array_column($orders, 1)));
        $result = [];
        foreach (Db::name('cashier_v3_sales_order_line')->where('tenant_id', $tenantId)->whereIn('order_line_id', $lineIds)
            ->field('order_id,order_line_id,item_name_snapshot')->select()->toArray() as $item) {
            $key = (string)($item['order_id'] ?? '') . '|' . (string)($item['order_line_id'] ?? '');
            if (isset($orders[$key])) $result[$key] = (string)($item['item_name_snapshot'] ?? '');
        }
        return $result;
    }

    private function overview($storeId, array $range, array $input)
    {
        $metrics = [
            'sales_amount' => 'sales_amount', 'cash_performance' => 'cash_performance',
            'actual_performance' => 'actual_performance', 'balance_deduction_amount' => 'balance_deduction_amount',
            'recharge_amount' => 'recharge_amount', 'service_count' => 'completed_service_item_count',
            'consumption_performance' => 'consume_amount', 'labor_performance' => 'staff_labor_yeji',
        ];
        $stores=is_array($storeId)?array_values(array_unique(array_map('intval',$storeId))):[(int)$storeId];
        $reader=new \app\services\query\metric\RegisteredMetricReadServices();
        $dictionary=new MetricDictionaryServices();
        $cards = [];
        foreach ($metrics as $code => $canonical) {
            $contract=\app\services\query\metric\MetricDefinitionRegistry::get($canonical);
            $definition=$dictionary->getTooltip($canonical);
            if (($definition['user_ready']??false)!==true) throw new \RuntimeException('REPORT_METRIC_DICTIONARY_NOT_READY');
            $value=$reader->summary($canonical,'0',$stores,$range);$count=$contract['storage_unit']==='count';
            $cards[] = ['code' => $code, 'name' => (string)$definition['name'], 'value' => $count ? $value : $this->money($value), 'value_cents' => $count ? null : $value, 'unit' => $count ? '次' : '元'];
        }
        $trendCode = in_array(($input['metric'] ?? ''), array_keys($metrics), true) ? $input['metric'] : 'cash_performance';
        $trendContract=\app\services\query\metric\MetricDefinitionRegistry::get($metrics[$trendCode]);
        $trend=$reader->dailyStoreTotals($metrics[$trendCode],'0',$stores,$range);
        foreach($trend as &$point)$point['value']=$trendContract['storage_unit']==='count'?(int)$point['amount_cents']:$this->money((int)$point['amount_cents']);unset($point);
        $ranking = $reader->personnelRanking('staff_labor_yeji', '0', $stores, $range, 20);
        foreach ($ranking as &$row) $row['amount'] = $this->money((int)$row['amount_cents']);
        unset($row);
        return ['title' => '经营总览', 'cards' => $cards, 'trend' => ['metric' => $trendCode, 'points' => $trend], 'ranking' => $ranking, 'columns' => [], 'records' => []];
    }

    private function sales($storeId, array $range, array $input)
    {
        $dataset = ($input['dataset'] ?? 'sale') === 'payment' ? 'payment' : 'sale';
        $table = $dataset === 'payment' ? 'cashier_v3_payment_fact' : 'cashier_v3_sale_fact';
        $query = $this->withStoreScope(Db::name($table), $storeId)->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query, 'tenant_id', 'order_id');
        $this->commonFilters($query, $input, $dataset);
        $total = (int)(clone $query)->count();
        $records = (clone $query)->order('business_date', 'desc')->order('id', 'desc')->page($this->page($input), $this->limit($input))->select()->toArray();
        foreach ($records as &$row) $row['amount'] = $this->money((int)($row[$dataset === 'payment' ? 'amount_cents' : 'sale_amount_cents'] ?? 0));
        unset($row);
        return ['title' => $dataset === 'payment' ? '收款明细' : '销售明细', 'dataset' => $dataset, 'columns' => $dataset === 'payment' ? $this->paymentColumns() : $this->saleColumns(), 'records' => $records, 'total' => $total, 'page' => $this->page($input), 'page_size' => $this->limit($input)];
    }

    private function service($storeId, array $range, array $input)
    {
        $serviceQuery = $this->withStoreScope(Db::name('cashier_v3_entitlement_service_fact'), $storeId)->whereBetween('business_date', [$range['start'], $range['end']])->where('service_status', 'completed');
        $this->normalDataScope()->excludeVoidedSalesOrderServices($serviceQuery, 'cashier_v3_entitlement_service_fact');
        $total = (int)(clone $serviceQuery)->count();
        $records = (clone $serviceQuery)->order('business_date', 'desc')->order('id', 'desc')->page($this->page($input), $this->limit($input))->select()->toArray();
        $stores = is_array($storeId) ? array_values(array_unique(array_map('intval', $storeId))) : [(int)$storeId];
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $performance = [
            ['metric_code' => 'consume_amount', 'amount_cents' => $reader->summary('consume_amount', '0', $stores, $range)],
            ['metric_code' => 'staff_labor_yeji', 'amount_cents' => $reader->summary('staff_labor_yeji', '0', $stores, $range)],
        ];
        return ['title' => '服务消耗明细', 'columns' => $this->serviceColumns(), 'records' => $records, 'total' => $total, 'page' => $this->page($input), 'page_size' => $this->limit($input), 'performance_summary' => $performance];
    }

    private function customers($storeId, array $range, array $input)
    {
        $segment = $this->customerSegment($input);
        $metric = $this->consumptionMetric($input);
        $sleepMonths = $this->sleepMonths($input);
        $year = $this->reportYear($input, $range);
        $projection = $this->withStoreScope(Db::name('cashier_v3_customer_lifecycle_projection'), $storeId)->select()->toArray();
        $members = [];
        foreach ($projection as $row) $members[(int)$row['member_id']] = $row;
        if (!$members) return $this->customerResult([], $input, $metric, $year, $sleepMonths);

        $memberIds = array_keys($members);
        $sales = $this->withStoreScope(Db::name('cashier_v3_sale_fact'), $storeId)->whereIn('member_id', $memberIds)
            ->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($sales, 'tenant_id', 'order_id');
        $sales
            ->fieldRaw('member_id, MAX(member_name_snapshot) member_name, COUNT(DISTINCT order_id) order_count, SUM(quantity) item_quantity, SUM(sale_amount_cents) sale_amount_cents')->group('member_id')->select()->toArray();
        $salesByMember = []; foreach ($sales as $row) $salesByMember[(int)$row['member_id']] = $row;
        $cashByMember = $this->memberAmounts('cashier_v3_payment_fact', 'amount_cents', $storeId, $memberIds, self::COVERAGE_START, $range['end'], []);
        $consumeByMember = $this->memberAmounts('cashier_v3_performance_fact', 'amount_cents', $storeId, $memberIds, self::COVERAGE_START, $range['end'], ['performance_type'=>'consumption_performance_recorded']);
        // Historical V3 facts before the lifecycle/report migration must never
        // leak into the natural-year tier calculation.
        $annualStart = max($year . '-01-01', self::COVERAGE_START);
        $annualCash = $this->memberAmounts('cashier_v3_payment_fact', 'amount_cents', $storeId, $memberIds, $annualStart, $year . '-12-31', []);
        $annualConsume = $this->memberAmounts('cashier_v3_performance_fact', 'amount_cents', $storeId, $memberIds, $annualStart, $year . '-12-31', ['performance_type'=>'consumption_performance_recorded']);
        $active = $this->activeMembers($storeId, $range, $memberIds);
        $cutoff = strtotime('-' . $sleepMonths . ' months', strtotime($range['end'] . ' 23:59:59'));
        $records = [];
        foreach ($members as $memberId => $life) {
            $stage = (string)$life['lifecycle_stage'];
            $isActive = isset($active[$memberId]);
            $lastVisit = (int)$life['last_service_at'];
            $base = $lastVisit ?: (int)$life['first_course_completed_at'];
            $sleeping = $base > 0 && $base < $cutoff;
            $cash = (int)($cashByMember[$memberId] ?? 0);
            $consume = (int)($consumeByMember[$memberId] ?? 0);
            $isEffective = ($stage === 'pre_sale' && $cash >= 50000) || ($stage === 'post_sale' && $cash >= 100000);
            if (!$this->matchesSegment($segment, $stage, (int)$life['is_guest'], $isActive, $isEffective, $sleeping)) continue;
            $sale = $salesByMember[$memberId] ?? [];
            $amount = $metric === 'consume' ? $consume : $cash;
            $records[] = [
                'member_id'=>$memberId, 'member_name'=>(string)($sale['member_name'] ?? ''), 'lifecycle_label'=>$this->lifecycleLabel($stage),
                'is_guest'=>(int)$life['is_guest'] === 1 ? '是' : '否', 'first_course_source'=>(string)$life['first_course_source_label_snapshot'],
                'conversion_history'=>(int)$life['has_pending_conversion'] === 1 ? '曾待转换' : '',
                'order_count'=>(int)($sale['order_count'] ?? 0), 'sale_amount'=>$this->money((int)($sale['sale_amount_cents'] ?? 0)),
                'cash_performance'=>$this->money($cash), 'consume_performance'=>$this->money($consume),
                'consumption_amount'=>$this->money($amount), 'annual_30000'=>(($metric === 'consume' ? (int)($annualConsume[$memberId] ?? 0) : (int)($annualCash[$memberId] ?? 0)) >= 3000000) ? '是' : '否',
                'active'=>$isActive ? '是' : '否', 'effective'=>$isEffective ? '是' : '否', 'sleeping'=>$sleeping ? '是' : '否',
                'last_service_at'=>$lastVisit ? date('Y-m-d H:i:s', $lastVisit) : '',
            ];
        }
        usort($records, function ($a, $b) { return (float)$b['consumption_amount'] <=> (float)$a['consumption_amount']; });
        return $this->customerResult($records, $input, $metric, $year, $sleepMonths);
    }

    private function items($storeId, array $range, array $input)
    {
        $query = $this->withStoreScope(Db::name('cashier_v3_sale_fact'), $storeId)->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query, 'tenant_id', 'order_id');
        $this->commonFilters($query, $input, 'sale');
        $total = (int)(clone $query)->count('DISTINCT item_id');
        $records = (clone $query)->fieldRaw('item_id, MAX(item_name_snapshot) AS item_name, MAX(category_name_snapshot) AS category_name, SUM(quantity) AS quantity, COUNT(DISTINCT NULLIF(member_id,0)) AS member_count, SUM(sale_amount_cents) AS sale_amount_cents')
            ->group('item_id')->orderRaw('sale_amount_cents DESC')->page($this->page($input), $this->limit($input))->select()->toArray();
        foreach ($records as &$row) $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']);
        unset($row);
        return ['title' => '品项经营', 'columns' => [['key'=>'category_name','label'=>'品类'],['key'=>'item_name','label'=>'品项'],['key'=>'quantity','label'=>'成交数量'],['key'=>'member_count','label'=>'成交顾客'],['key'=>'sale_amount','label'=>'成交金额']], 'records' => $records, 'total' => $total, 'page' => $this->page($input), 'page_size' => $this->limit($input)];
    }

    private function channels($storeId, array $range, array $input)
    {
        $customers = $this->withStoreScope(Db::name('cashier_v3_customer_lifecycle_projection'), $storeId)
            ->whereBetween('first_course_business_date', [$range['start'], $range['end']])->where('first_course_order_id', '<>', '')->select()->toArray();
        $groups = [];
        foreach ($customers as $customer) {
            $id = (int)$customer['first_course_source_primary_id']; if ($id <= 0) continue;
            if (!empty($input['channel_id']) && $id !== (int)$input['channel_id']) continue;
            if (!isset($groups[$id])) $groups[$id] = ['business_source_primary_id'=>$id,'channel_name'=>(string)$customer['first_course_source_label_snapshot'],'source_type'=>(string)$customer['first_course_source_attribution_type_snapshot'],'member_count'=>0,'order_ids'=>[]];
            $groups[$id]['member_count']++; $groups[$id]['order_ids'][(string)$customer['first_course_order_id']] = true;
        }
        foreach ($groups as &$group) {
            $orders = array_keys($group['order_ids']);
            $salesQuery = $orders ? $this->withStoreScope(Db::name('cashier_v3_sale_fact'), $storeId)->whereIn('order_id', $orders)->where('status','effective')->where('source_type','card') : null;
            if ($salesQuery) $this->normalDataScope()->excludeVoidedSalesOrderFacts($salesQuery, 'tenant_id', 'order_id');
            $sales = $salesQuery ? $salesQuery->sum('sale_amount_cents') : 0;
            $receiptsQuery = $orders ? $this->withStoreScope(Db::name('cashier_v3_payment_fact'), $storeId)->whereIn('order_id', $orders)->where('status','effective') : null;
            if ($receiptsQuery) $this->normalDataScope()->excludeVoidedSalesOrderFacts($receiptsQuery, 'tenant_id', 'order_id');
            $receipts = $receiptsQuery ? $receiptsQuery->sum('amount_cents') : 0;
            $group['order_count'] = count($orders); $group['sale_amount'] = $this->money((int)$sales); $group['receipt_amount'] = $this->money((int)$receipts);
            $group['average_order_amount'] = $group['order_count'] ? $this->money((int)$sales / $group['order_count']) : '0'; unset($group['order_ids']);
        }
        unset($group); $records = array_values($groups); usort($records, function ($a, $b) { return (float)$b['sale_amount'] <=> (float)$a['sale_amount']; });
        return ['title' => '拓客渠道（首次疗程卡来源）', 'columns' => [['key'=>'channel_name','label'=>'首次疗程卡来源'],['key'=>'source_type','label'=>'来源类型'],['key'=>'member_count','label'=>'成交顾客'],['key'=>'order_count','label'=>'首次疗程卡单数'],['key'=>'sale_amount','label'=>'成交金额'],['key'=>'receipt_amount','label'=>'收款金额'],['key'=>'average_order_amount','label'=>'成交单产']], 'records' => $records, 'total' => count($records), 'page' => 1, 'page_size' => count($records)];
    }

    private function commonFilters($query, array $input, $dataset)
    {
        if (!empty($input['item_id']) && $dataset === 'sale') $query->where('item_id', (string)$input['item_id']);
        if (!empty($input['payment_method']) && $dataset === 'payment') $query->where('payment_method', (string)$input['payment_method']);
        if (!empty($input['operator_id'])) $query->where('operator_id', (int)$input['operator_id']);
        if (!empty($input['channel_id'])) $query->where('business_source_primary_id', (int)$input['channel_id']);
    }

    private function customerResult(array $records, array $input, string $metric, int $year, int $sleepMonths): array
    {
        $total = count($records); $page = $this->page($input); $limit = $this->limit($input);
        return [
            'title' => '顾客经营', 'records' => array_slice($records, ($page - 1) * $limit, $limit), 'total' => $total, 'page' => $page, 'page_size' => $limit,
            'columns' => [['key'=>'member_name','label'=>'顾客'],['key'=>'lifecycle_label','label'=>'顾客阶段'],['key'=>'conversion_history','label'=>'待转换历史'],['key'=>'is_guest','label'=>'嘉宾'],['key'=>'first_course_source','label'=>'首次疗程卡来源'],['key'=>'cash_performance','label'=>'累计现金业绩'],['key'=>'consume_performance','label'=>'累计消耗业绩'],['key'=>'annual_30000','label'=> $year . '年达3万'],['key'=>'active','label'=>'活客'],['key'=>'effective','label'=>'有效顾客'],['key'=>'sleeping','label'=> $sleepMonths . '个月睡眠'],['key'=>'last_service_at','label'=>'最近护理时间']],
            'lifecycle_metric_version' => CustomerLifecycleFactServices::VERSION, 'consumption_metric' => $metric, 'sleep_months' => $sleepMonths, 'natural_year' => $year,
            'pending_metrics' => ['未耗业绩（卡项分摊余额事实接入前不计算、不导出）'],
        ];
    }

    private function memberAmounts($table, $amount, $storeId, array $memberIds, string $start, string $end, array $conditions): array
    {
        if (!$memberIds) return [];
        $query = $this->withStoreScope(Db::name($table), $storeId)->whereIn('member_id', $memberIds)->whereBetween('business_date', [$start, $end])->where('status', 'effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query, 'tenant_id', 'order_id');
        foreach ($conditions as $key => $value) $query->where($key, $value);
        $rows = $query->fieldRaw('member_id, SUM(' . $amount . ') amount')->group('member_id')->select()->toArray();
        $result = []; foreach ($rows as $row) $result[(int)$row['member_id']] = (int)$row['amount'];
        return $result;
    }

    private function activeMembers($storeId, array $range, array $memberIds): array
    {
        $query = $this->withStoreScope(Db::name('cashier_v3_entitlement_service_fact')->alias('s'), $storeId)->whereIn('s.member_id', $memberIds)
            ->whereBetween('s.business_date', [$range['start'], $range['end']])->where('s.service_status', 'completed');
        $this->normalDataScope()->excludeVoidedSalesOrderServices($query, 's');
        $rows = $query->field('s.member_id')->group('s.member_id')->select()->toArray();
        $result = []; foreach ($rows as $row) $result[(int)$row['member_id']] = true;
        return $result;
    }

    private function customerSegment(array $input): string
    {
        $segment = (string)($input['customer_segment'] ?? 'all');
        if (!in_array($segment, ['all','pre_sale','post_sale','pending_conversion','guest','active','effective','sleeping'], true)) throw new \InvalidArgumentException('顾客标签筛选无效');
        return $segment;
    }

    private function consumptionMetric(array $input): string
    {
        $metric = (string)($input['consumption_metric'] ?? 'cash');
        if (!in_array($metric, ['cash','consume'], true)) throw new \InvalidArgumentException('消费口径筛选无效');
        return $metric;
    }

    private function sleepMonths(array $input): int
    {
        $months = (int)($input['sleep_months'] ?? 3);
        if (!in_array($months, [3, 6], true)) throw new \InvalidArgumentException('睡眠阈值只支持3个月或6个月');
        return $months;
    }

    private function reportYear(array $input, array $range): int
    {
        $requested = (int)($input['year'] ?? 0);
        $year = $requested > 0 ? $requested : (int)substr($range['end'], 0, 4);
        if ($year < 2020 || $year > (int)date('Y') + 1) throw new \InvalidArgumentException('自然年筛选无效');
        return $year;
    }

    private function matchesSegment(string $segment, string $stage, int $guest, bool $active, bool $effective, bool $sleeping): bool
    {
        if ($segment === 'all') return true;
        if ($segment === 'guest') return $guest === 1;
        if ($segment === 'active') return $active;
        if ($segment === 'effective') return $effective;
        if ($segment === 'sleeping') return $sleeping;
        return $stage === $segment;
    }

    private function lifecycleLabel(string $stage): string
    {
        return ['pre_sale'=>'售前（新客）','post_sale'=>'售后（老客）','pending_conversion'=>'售前订单待转换'][$stage] ?? '待形成';
    }

    private function range(array $input)
    {
        $start = trim((string)($input['start_date'] ?? '')) ?: self::COVERAGE_START;
        $end = trim((string)($input['end_date'] ?? '')) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end || $end < self::COVERAGE_START) throw new \InvalidArgumentException('统计日期范围无效或早于 V3 报表覆盖期');
        if ($start < self::COVERAGE_START) $start = self::COVERAGE_START;
        return compact('start', 'end');
    }

    /** Merge only controlled report annotations; never alters V3 fact values. */
    private function attachAnnotations(array $rows, string $reportCode, $storeId, bool $export = false): array
    {
        if (!$rows) return $rows;
        $keys = [];
        foreach ($rows as $row) {
            foreach (['source_line_id', 'order_id', 'business_source_primary_id'] as $field) {
                $value = trim((string)($row[$field] ?? ''));
                if ($value !== '') $keys[$value] = true;
            }
        }
        if (!$keys) return $rows;
        // Fetch the small, controlled annotation set for this report/store
        // and match keys in PHP.  This deliberately keeps the valid string
        // key "0" (the default source), which some SQL drivers coerce away
        // when it is passed through whereIn().
        $query = Db::name('cashier_v3_report_annotation')->where('report_code', $reportCode);
        if (is_array($storeId)) $query->whereIn('store_id', array_values(array_unique(array_map('intval', $storeId))));
        else $query->where('store_id', (int)$storeId);
        $annotations = $query->field('subject_key,field_key,field_value,version')->select()->toArray();
        $byKey = [];
        foreach ($annotations as $annotation) {
            $field = (string)$annotation['field_key'];
            $byKey[(string)$annotation['subject_key']][$field] = (string)$annotation['field_value'];
            $byKey[(string)$annotation['subject_key']][$field . '_version'] = (int)$annotation['version'];
        }
        foreach ($rows as &$row) {
            // Preserve the valid source id "0" (the default/other source)
            // when resolving editable market rows; array_filter's default
            // truthiness would otherwise drop it and hide saved annotations.
            $candidates = array_filter(
                [(string)($row['source_line_id'] ?? ''), (string)($row['order_id'] ?? ''), (string)($row['business_source_primary_id'] ?? '')],
                static fn(string $value): bool => $value !== ''
            );
            foreach ($candidates as $candidate) foreach ((array)($byKey[$candidate] ?? []) as $field => $value) {
                $row[$field] = $this->annotationDisplayValue($reportCode, $field, $value, $export);
            }
            foreach (['medical_elevation','medical_followup','expert_name','remark','walk_in_manual_count','refund_headcount_manual','manual_cash_amount','experience_cash','experience_payment_method'] as $field) {
                if (!array_key_exists($field, $row)) $row[$field] = '';
                if (!array_key_exists($field . '_version', $row)) $row[$field . '_version'] = 0;
            }
        }
        unset($row);
        return $rows;
    }

    /** 报表补充金额持久化为分；读回仅投影为整数元，不改写审计值，清空仍为空。 */
    private function annotationDisplayValue(string $reportCode, string $field, $value, bool $export = false): string
    {
        $text = (string)$value;
        return $reportCode === 'member_consumption_detail' && $field === 'experience_cash' && $text !== ''
            ? ($export
                ? \app\services\query\metric\MetricMoneyFormatter::exactYuan((int)$text)
                : $this->money((int)$text))
            : $text;
    }

    /** Payment columns are configuration-driven; facts only provide row amounts. */
    private function paymentMethodDefinitions(): array
    {
        $rows = Db::name('cashier_v3_payment_method_config')->where('status', 1)
            ->where('code', '<>', 'old_card_entry')->order('sort', 'asc')->order('id', 'asc')
            ->field('code,display_name,default_name')->select()->toArray();
        $out = [];
        foreach ($rows as $row) {
            $code = trim((string)$row['code']);
            if ($code === '' || $code === 'old_card_entry') continue;
            $out[] = ['code' => $code, 'label' => trim((string)$row['display_name']) ?: trim((string)$row['default_name']) ?: $code];
        }
        return $out;
    }

    /**
     * Column metadata follows the current category switches, while row values
     * come only from the successful-checkout snapshots.  This deliberately
     * keeps a later ratio/configuration edit from recalculating past orders.
     *
     * A configured root is shown only when none of its direct second-level
     * children is configured.  Once a second-level category is configured,
     * the root column is replaced by that second-level column.  Deeper levels
     * never create a third-level column.
     */
    private function partnerPerformanceDefinitions($storeId): array
    {
        $tenantIds = $this->partnerCategoryTenantIds($storeId);
        if ($tenantIds === []) return [];

        $configs = Db::name('cashier_v3_report_category_config')
            ->whereIn('tenant_id', $tenantIds)->where('enabled', 1)
            ->field('tenant_id,category_id')->select()->toArray();
        if ($configs === []) return [];

        $categories = [];
        foreach (Db::name('store_product_category')->where('is_show', 1)
            ->field('id,pid,cate_name')->select()->toArray() as $category) {
            $id = (int)($category['id'] ?? 0);
            if ($id > 0) $categories[$id] = $category;
        }
        $enabled = [];
        foreach ($configs as $config) {
            $id = (int)($config['category_id'] ?? 0);
            if ($id > 0 && isset($categories[$id])) $enabled[$id] = true;
        }

        $definitions = [];
        foreach (array_keys($enabled) as $configuredId) {
            $chain = $this->partnerCategoryChain((int)$configuredId, $categories);
            if ($chain === []) continue;
            $effective = null;
            if (count($chain) === 1) {
                $rootId = (int)$chain[0]['id'];
                if (!$this->hasEnabledPartnerSecondLevel($rootId, $enabled, $categories)) {
                    $effective = $chain[0];
                }
            } elseif ((int)$chain[1]['id'] === (int)$configuredId) {
                // The checkout snapshot uses the configured second-level
                // category for all deeper product classifications.
                $effective = $chain[1];
            }
            if ($effective === null) continue;

            $effectiveId = (int)$effective['id'];
            $definitions[$effectiveId] = [
                'key' => 'partner_category_' . $effectiveId,
                'category_id' => $effectiveId,
                'category_path' => $this->partnerCategoryPathLabel($chain, $effectiveId),
                'label' => $this->partnerCategoryPathLabel($chain, $effectiveId) . '分成业绩',
                'sort_path' => $this->partnerCategoryPathLabel($chain, $effectiveId),
            ];
        }
        uasort($definitions, static function (array $left, array $right): int {
            return strcmp((string)$left['sort_path'], (string)$right['sort_path'])
                ?: ((int)$left['category_id'] <=> (int)$right['category_id']);
        });
        foreach ($definitions as &$definition) unset($definition['sort_path']);
        unset($definition);
        return array_values($definitions);
    }

    /** @return array<int,string> */
    private function partnerCategoryTenantIds($storeId): array
    {
        // Each customer instance has one database tenant.  Header configuration
        // must be available even before the selected store has any V3 facts.
        return [CashierV3ScopeResolver::TENANT_SCOPE_ID];
    }

    /** @return array<int,array> root first */
    private function partnerCategoryChain(int $categoryId, array $categories): array
    {
        $chain = [];
        $seen = [];
        for ($guard = 0; $categoryId > 0 && $guard < 16; $guard++) {
            if (isset($seen[$categoryId]) || !isset($categories[$categoryId])) return [];
            $seen[$categoryId] = true;
            array_unshift($chain, $categories[$categoryId]);
            $categoryId = (int)($categories[$categoryId]['pid'] ?? 0);
        }
        return $categoryId > 0 ? [] : $chain;
    }

    private function hasEnabledPartnerSecondLevel(int $rootId, array $enabled, array $categories): bool
    {
        foreach (array_keys($enabled) as $categoryId) {
            if ((int)($categories[(int)$categoryId]['pid'] ?? 0) === $rootId) return true;
        }
        return false;
    }

    private function partnerCategoryPathLabel(array $chain, int $effectiveId): string
    {
        $parts = [];
        foreach ($chain as $category) {
            $parts[] = trim((string)($category['cate_name'] ?? ''));
            if ((int)($category['id'] ?? 0) === $effectiveId) break;
        }
        return implode('/', array_values(array_filter($parts, static function (string $value): bool {
            return $value !== '';
        })));
    }

    /** @return array<string,array<int,int>> sale fact id => partner category id => share cents */
    private function cardPartnerSharesBySaleFact(array $rows, $storeId): array
    {
        $saleFactIds = array_values(array_unique(array_filter(array_map(static function (array $row): string {
            return (string)($row['source_type'] ?? '') === 'card' ? trim((string)($row['fact_id'] ?? '')) : '';
        }, $rows))));
        if ($saleFactIds === []) return [];

        $facts = $this->withStoreScope(Db::name('cashier_v3_card_sale_category_allocation_fact'), $storeId)
            ->whereIn('sale_fact_id', $saleFactIds)->where('status', 'effective')
            ->field('sale_fact_id,partner_category_id_snapshot,partner_share_amount_cents')->select()->toArray();
        $shares = [];
        foreach ($facts as $fact) {
            $saleFactId = trim((string)($fact['sale_fact_id'] ?? ''));
            $categoryId = (int)($fact['partner_category_id_snapshot'] ?? 0);
            if ($saleFactId === '' || $categoryId <= 0) continue;
            $shares[$saleFactId][$categoryId] = (int)($shares[$saleFactId][$categoryId] ?? 0)
                + (int)($fact['partner_share_amount_cents'] ?? 0);
        }
        return $shares;
    }

    private function decorateMemberConsumptionRows(array &$rows, $storeId, array $partnerDefinitions, array $cardPartnerSharesBySaleFact): void
    {
        $orderIds = array_values(array_unique(array_filter(array_map(static function ($row) { return trim((string)($row['order_id'] ?? '')); }, $rows))));
        $saleFactIds = array_values(array_unique(array_filter(array_map(static function ($row) { return trim((string)($row['fact_id'] ?? '')); }, $rows))));
        $sourceLineIds = array_values(array_unique(array_filter(array_map(static function ($row) { return trim((string)($row['source_line_id'] ?? '')); }, $rows))));
        $paymentBySaleFact = [];
        if ($saleFactIds) {
            $payments = $this->withStoreScope(Db::name('cashier_v3_payment_sale_allocation_fact'), $storeId)
                ->whereIn('sale_fact_id', $saleFactIds)->where('status', 'effective')
                ->field('sale_fact_id,payment_method,SUM(amount_cents) amount_cents')->group('sale_fact_id,payment_method')->select()->toArray();
            foreach ($payments as $payment) $paymentBySaleFact[(string)$payment['sale_fact_id']][(string)$payment['payment_method']] = (int)$payment['amount_cents'];
        }
        $salespersonByLine = [];
        if ($sourceLineIds) {
            $salespeople = $this->withStoreScope(Db::name('cashier_v3_performance_fact'), $storeId)
                ->whereIn('source_line_id', $sourceLineIds)
                ->where('performance_type', 'sales_performance_allocated')
                ->where('fact_direction', 'forward')
                ->where('status', 'effective')
                ->field('source_line_id,employee_name_snapshot')
                ->order('id', 'asc')->select()->toArray();
            foreach ($salespeople as $salesperson) {
                $line = trim((string)($salesperson['source_line_id'] ?? ''));
                $name = trim((string)($salesperson['employee_name_snapshot'] ?? ''));
                if ($line !== '' && $name !== '') $salespersonByLine[$line][] = $name;
            }
            foreach ($salespersonByLine as $line => $names) {
                $salespersonByLine[$line] = array_values(array_unique($names));
            }
        }
        $guideByOrder = $managerByOrder = [];
        if ($orderIds) {
            $guides = $this->withStoreScope(Db::name('cashier_v3_customer_guide_round_fact'), $storeId)->whereIn('order_id',$orderIds)->where('status','effective')->field('order_id,guide_round_no,guide_employee_name_snapshot')->order('guide_round_no','asc')->select()->toArray();
            foreach ($guides as $guide) $guideByOrder[(string)$guide['order_id']][] = $guide;
            $managers = $this->withStoreScope(Db::name('cashier_v3_sales_manager_fact'), $storeId)->whereIn('order_id',$orderIds)->where('status','effective')->field('order_id,sales_manager_name_snapshot')->select()->toArray();
            foreach ($managers as $manager) $managerByOrder[(string)$manager['order_id']][] = $manager;
        }
        $methods = $this->paymentMethodDefinitions();
        foreach ($rows as &$row) {
            $order = (string)($row['order_id'] ?? '');
            $payments = $paymentBySaleFact[(string)($row['fact_id'] ?? '')] ?? [];
            $receiptTotal = 0; $paymentNames = [];
            foreach ($methods as $method) {
                $amount = (int)($payments[$method['code']] ?? 0);
                // 收款总金额只汇总当前销售明细分摊到的成功记账收款。
                $receiptTotal += $amount;
                $row['payment_'.$method['code']] = $this->money($amount);
                if ($amount > 0) $paymentNames[] = $method['label'];
            }
            $row['receipt_total'] = $this->money($receiptTotal);
            $row['cash_receipt_total'] = $this->money($receiptTotal);
            $row['payment_method_names'] = implode('、', $paymentNames);
            $guidesForOrder = $guideByOrder[$order] ?? [];
            // 游客导购事实有人员、无会员轮次：显示“无”，不能把 NULL
            // 强转为 0 并误导为一个可占用的第 0 轮。
            $row['guide_round_no'] = $guidesForOrder
                ? ($guidesForOrder[0]['guide_round_no'] === null
                    ? '无'
                    : (int)min(array_map(static function ($item) { return (int)$item['guide_round_no']; }, $guidesForOrder)))
                : '';
            $row['guide_names'] = implode('、', array_values(array_unique(array_map(static function ($item) { return (string)$item['guide_employee_name_snapshot']; }, $guidesForOrder))));
            $row['sales_manager_name'] = implode('、', array_values(array_unique(array_map(static function ($item) { return (string)$item['sales_manager_name_snapshot']; }, $managerByOrder[$order] ?? []))));
            $line = trim((string)($row['source_line_id'] ?? ''));
            $row['salesperson_names'] = implode('、', $salespersonByLine[$line] ?? []);
            $row['member_source'] = (string)($row['business_source_label_snapshot'] ?? '');
            $partnerCategoryCents = array_fill_keys(array_column($partnerDefinitions, 'key'), 0);
            if ((string)($row['source_type'] ?? '') === 'card') {
                foreach ((array)($cardPartnerSharesBySaleFact[(string)($row['fact_id'] ?? '')] ?? []) as $categoryId => $share) {
                    foreach ($partnerDefinitions as $definition) {
                        if ((int)$categoryId === (int)$definition['category_id']) {
                            $partnerCategoryCents[(string)$definition['key']] += (int)$share;
                            break;
                        }
                    }
                }
            } else {
                $partnerCategoryId = (int)($row['partner_category_id_snapshot'] ?? 0);
                $partnerShare = (int)($row['partner_share_amount_cents'] ?? 0);
                foreach ($partnerDefinitions as $definition) {
                    if ($partnerCategoryId === (int)$definition['category_id']) {
                        $partnerCategoryCents[(string)$definition['key']] += $partnerShare;
                        break;
                    }
                }
            }
            $partnerPerformanceCents = 0;
            foreach ($partnerDefinitions as $definition) {
                $key = (string)$definition['key'];
                $row[$key] = $this->money((int)$partnerCategoryCents[$key]);
                $partnerPerformanceCents += (int)$partnerCategoryCents[$key];
            }
            $row['partner_performance'] = $this->money($partnerPerformanceCents);
            $row['actual_cash_performance'] = $this->money($receiptTotal - $partnerPerformanceCents);
        }
        unset($row);
    }

    /**
     * The member-consumption list is paginated, so its first-row total must be
     * aggregated from the same filtered facts rather than from visible rows.
     * Payment allocation and partner-share facts are the authority for their
     * respective columns; manual experience overrides intentionally remain a
     * row-level operating supplement and are not merged into a business total.
     */
    private function memberConsumptionSummaryValues($query, $storeId, array $partnerDefinitions): array
    {
        $base = (clone $query)->fieldRaw('SUM(s.quantity) quantity')->find() ?: [];
        $paymentRows = (clone $query)
            ->join('cashier_v3_payment_sale_allocation_fact pa', "pa.sale_fact_id=s.fact_id AND pa.status='effective'")
            ->fieldRaw('pa.payment_method,SUM(pa.amount_cents) amount_cents')->group('pa.payment_method')->select()->toArray();
        $payments = [];
        foreach ($paymentRows as $payment) $payments[(string)$payment['payment_method']] = (int)$payment['amount_cents'];

        $shareByCategory = [];
        foreach ((clone $query)->where('s.source_type', '<>', 'card')
            ->fieldRaw('d.partner_category_id_snapshot,SUM(d.partner_share_amount_cents) amount_cents')
            ->group('d.partner_category_id_snapshot')->select()->toArray() as $share) {
            $shareByCategory[(int)$share['partner_category_id_snapshot']] = (int)$share['amount_cents'];
        }
        foreach ((clone $query)
            ->join('cashier_v3_card_sale_category_allocation_fact cc', "cc.sale_fact_id=s.fact_id AND cc.status='effective'")
            ->where('s.source_type', 'card')
            ->fieldRaw('cc.partner_category_id_snapshot,SUM(cc.partner_share_amount_cents) amount_cents')
            ->group('cc.partner_category_id_snapshot')->select()->toArray() as $share) {
            $categoryId = (int)$share['partner_category_id_snapshot'];
            $shareByCategory[$categoryId] = (int)($shareByCategory[$categoryId] ?? 0) + (int)$share['amount_cents'];
        }

        $values = ['quantity'=>(int)($base['quantity'] ?? 0)];
        $receiptTotal = 0;
        foreach ($this->paymentMethodDefinitions() as $method) {
            $amount = (int)($payments[(string)$method['code']] ?? 0);
            $values['payment_' . $method['code']] = $this->money($amount);
            $receiptTotal += $amount;
        }
        $values['receipt_total'] = $this->money($receiptTotal);
        $partnerTotal = 0;
        foreach ($partnerDefinitions as $definition) {
            $amount = (int)($shareByCategory[(int)$definition['category_id']] ?? 0);
            $values[(string)$definition['key']] = $this->money($amount);
            $partnerTotal += $amount;
        }
        $values['partner_performance'] = $this->money($partnerTotal);
        $values['actual_cash_performance'] = $this->money($receiptTotal - $partnerTotal);
        return $values;
    }

    /**
     * Fixed-column order is a server projection decision.  The browser only
     * renders this declaration and never decides which business identifiers
     * remain visible while an operator compares wide result columns.
     */
    private function fixedColumns(array $columns, array $widths): array
    {
        foreach ($columns as &$column) {
            $key = (string)($column['key'] ?? '');
            if (trim((string)($column['source_explanation'] ?? '')) === '') {
                $column['source_explanation'] = $this->reportColumnExplanation(
                    $key, (string)($column['label'] ?? $key)
                );
            }
            if (!isset($widths[$key])) continue;
            $column['fixed'] = 'left';
            $column['fixed_width'] = (int)$widths[$key];
        }
        unset($column);
        return $columns;
    }

    private function reportColumnExplanation(string $key, string $label): string
    {
        $explanations = [
            'month' => '按销售事实的业务日期归入自然月。',
            'store_summary' => '同一统计行内业务事实发生门店的名称快照。',
            'store_name' => '业务事实发生时保存的门店名称快照。',
            'store_name_snapshot' => '业务事实发生时保存的门店名称快照。',
            'business_date' => '业务事实的经营归属日期。',
            'member_name' => '业务事实发生时保存的会员名称。',
            'member_name_snapshot' => '业务事实发生时保存的会员名称。',
            'member_phone' => '当前会员档案中的手机号；无手机号时留空。',
            'employee_name' => '业绩事实中保存的实际分配员工名称。',
            'quantity' => '业务事实中保存的成交数量合计。',
            'sale_amount' => '销售事实中保存的成交金额，卡项按卡内项目分类展开后归集。',
            'consumption_amount' => '已完成服务形成的消耗业绩金额。',
            'labor_amount' => '已完成服务分配给手艺人的手工费金额。',
            'total_consume' => '所选期间内该手艺人的消耗业绩合计。',
            'total_labor' => '所选期间内该手艺人的手工费合计。',
            'total_performance' => '所选期间内该销售人分配到的销售业绩合计。',
        ];
        return $explanations[$key] ?? ('“' . $label . '”按本表统一事实、筛选条件和数据权限读取；合计与导出使用同一口径。');
    }

    /** @return array<string,string|int> */
    private function summaryRow(array $columns, array $values): array
    {
        $row = [];
        foreach ($columns as $column) {
            $key = (string)($column['key'] ?? '');
            if ($key !== '') $row[$key] = array_key_exists($key, $values) ? $values[$key] : '-';
        }
        return $row;
    }

    private function columnGroups(array $columns): array
    {
        $groups = [];
        foreach ($columns as $column) {
            $label = trim((string)($column['group_label'] ?? ''));
            if ($label === '') continue;
            $groups[$label][] = (string)$column['key'];
        }
        $out = [];
        foreach ($groups as $label => $keys) $out[] = ['label'=>$label,'column_keys'=>$keys];
        return $out;
    }
    private function meta(array $range) { return ['metric_version'=>self::METRIC_VERSION,'lifecycle_metric_version'=>CustomerLifecycleFactServices::VERSION,'data_as_of'=>date('Y-m-d H:i:s'),'coverage_start'=>self::COVERAGE_START,'fact_completeness'=>'V3 正式事实覆盖期内完整；旧订单不回填、不混入','aggregation_caught_up'=>true,'scope'=>['date_range'=>$range]]; }
    private function page(array $input) { return max(1, (int)($input['page'] ?? 1)); }
    private function limit(array $input) { return min(100, max(10, (int)($input['limit'] ?? 20))); }
    private function withStoreScope($query, $storeId) { return is_array($storeId) ? $query->whereIn('store_id', array_values(array_unique(array_map('intval', $storeId)))) : $query->where('store_id', (int)$storeId); }
    private function money($cents) { return \app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$cents); }
    private function saleColumns() { return [['key'=>'business_date','label'=>'业务日期'],['key'=>'order_no_snapshot','label'=>'订单号'],['key'=>'member_name_snapshot','label'=>'顾客'],['key'=>'item_name_snapshot','label'=>'品项'],['key'=>'quantity','label'=>'数量'],['key'=>'business_source_label_snapshot','label'=>'结账来源'],['key'=>'amount','label'=>'销售额']]; }
    private function paymentColumns() { return [['key'=>'business_date','label'=>'业务日期'],['key'=>'order_no_snapshot','label'=>'订单号'],['key'=>'member_name_snapshot','label'=>'顾客'],['key'=>'payment_method','label'=>'收款方式'],['key'=>'business_source_label_snapshot','label'=>'结账来源'],['key'=>'amount','label'=>'收款金额']]; }
    private function serviceColumns() { return [['key'=>'business_date','label'=>'服务日期'],['key'=>'document_no_snapshot','label'=>'服务单号'],['key'=>'member_name_snapshot','label'=>'顾客'],['key'=>'project_name_snapshot','label'=>'项目'],['key'=>'quantity','label'=>'服务次数'],['key'=>'service_object','label'=>'服务对象']]; }
}
