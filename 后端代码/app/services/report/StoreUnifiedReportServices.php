<?php

namespace app\services\report;

use app\services\BaseServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\order\CashierV3SupplementAttributionServices;
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
            ['folder' => '门店运营', 'code' => 'store_salesperson_performance_detail', 'name' => '销售人业绩明细', 'hidden' => true],
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
            'store_salesperson_performance', 'store_salesperson_performance_detail',
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
     * 门店运营报表的金额、合作方和体验维度来自原业务事实；商品分类仅是
     * 实际商品 ID 关联平台商品后的当前展示维度，不能回写历史金额事实。
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
            case 'store_salesperson_performance_detail': return $this->salespersonPerformanceDetail($storeId, $range, $input);
            default: throw new \InvalidArgumentException('不支持的门店运营报表类型');
        }
    }

    private function operationSaleQuery($storeId, array $range, array $input, bool $expandCardCategories = true)
    {
        $query = Db::name('cashier_v3_sale_fact')->alias('s')
            ->leftJoin('cashier_v3_report_sale_dimension_fact d',
                'd.tenant_id=s.tenant_id AND d.store_id=s.store_id AND d.sale_fact_id=s.fact_id')
            ->whereBetween('s.business_date', [$range['start'], $range['end']])
            ->where('s.status', 'effective')
            ->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query, 's.tenant_id', 's.order_id');
        // Summary/detail projections expand a card by its contained project
        // category. The member-consumption table intentionally keeps one row
        // per sold card, then aggregates its component shares in PHP.
        if ($expandCardCategories) {
            $query->leftJoin('cashier_v3_card_sale_category_allocation_fact c',
                "c.tenant_id=s.tenant_id AND c.store_id=s.store_id AND c.sale_fact_id=s.fact_id AND c.status='effective'");
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
        if ($categoryId > 0 || $path !== '' || $exactPath !== '') {
            // Resolve candidates from this scoped fact population, then filter SQL by immutable
            // product IDs. A historical category predicate would hide newly reclassified facts.
            $candidateItem = $expandCardCategories ? 'COALESCE(c.component_product_id,d.item_id)' : 'd.item_id';
            $candidates = (clone $query)->fieldRaw("s.fact_id,s.store_id,{$candidateItem} item_id")->select()->toArray();
            $links = [];
            $owners = [];
            foreach ($candidates as $candidate) {
                $links[] = ['store_id' => (int)$candidate['store_id'], 'product_id' => (int)$candidate['item_id']];
                $owners[] = (string)$candidate['fact_id'];
            }
            if (!$expandCardCategories) {
                $saleIds = array_values(array_unique(array_filter(array_column($candidates, 'fact_id'))));
                $candidateStores = array_values(array_unique(array_map('intval', array_column($candidates, 'store_id'))));
                foreach (array_chunk($saleIds, 300) as $chunk) {
                    foreach (Db::name('cashier_v3_card_sale_category_allocation_fact')->whereIn('sale_fact_id', $chunk)
                        ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                        ->whereIn('store_id', $candidateStores)->where('status', 'effective')
                        ->field('sale_fact_id,store_id,component_product_id')->select()->toArray() as $component) {
                        $links[] = ['store_id' => (int)$component['store_id'], 'product_id' => (int)$component['component_product_id']];
                        $owners[] = (string)$component['sale_fact_id'];
                    }
                }
            }
            $resolved = (new StoreReportCurrentProductCategoryServices())->resolve($links);
            $productIds = [];
            $matchingFacts = [];
            foreach ($links as $index => $link) {
                $category = $resolved[$index] ?? [];
                $currentPath = (string)($category['path'] ?? '');
                if ($categoryId > 0 && (int)($category['id'] ?? 0) !== $categoryId) continue;
                if ($path !== '' && strpos($currentPath, $path) !== 0) continue;
                if ($exactPath !== '' && $currentPath !== $exactPath) continue;
                if ((int)$link['product_id'] > 0) {
                    $productIds[(int)$link['product_id']] = true;
                    $matchingFacts[$owners[$index]] = true;
                }
            }
            if ($productIds === []) $query->whereRaw('1=0');
            elseif ($expandCardCategories) {
                $query->whereRaw('COALESCE(c.component_product_id,d.item_id) IN ('
                    . implode(',', array_fill(0, count($productIds), '?')) . ')', array_keys($productIds));
            } else {
                // The source fact IDs were already narrowed by authorized store/date and
                // current product classification; this indexed predicate avoids a per-row EXISTS scan.
                $query->whereIn('s.fact_id', array_keys($matchingFacts));
            }
        }
        if ($expandCardCategories) {
            if ($type !== '') $query->whereRaw('COALESCE(c.product_type_snapshot,d.product_type_snapshot)=?', [$type]);
            if ($partner !== '') $query->whereRaw('COALESCE(c.partner_name_snapshot,d.partner_name_snapshot)=?', [$partner]);
        } else {
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

    /** Project only a verified product's linked platform category; unknown IDs stay unclassified. */
    private function projectSaleCategories(array &$rows, string $itemKey, string $idKey, string $pathKey): void
    {
        $links = [];
        foreach ($rows as $index => $row) {
            $links[$index] = ['store_id' => (int)($row['store_id'] ?? 0),
                'product_id' => (int)($row[$itemKey] ?? 0)];
        }
        $resolved = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        foreach ($rows as $index => &$row) {
            $row[$idKey] = (int)($resolved[$index]['id'] ?? 0);
            $row[$pathKey] = (string)($resolved[$index]['path'] ?? '');
        }
        unset($row);
    }

    private function partnerItemSummary($storeId, array $range, array $input): array
    {
        $facts = $this->partnerItemFacts($storeId, $range, $input);
        $grouped = [];
        foreach ($facts as $fact) {
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
                    // The path is projected from today's platform product before grouping.
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
            // 补交只增加收款金额，不再制造一笔原销售的成交人头或项目数量。
            if (!empty($fact['_sale_activity']) && (int)$fact['member_id'] > 0) $row['_member_ids'][(string)$fact['member_id']] = true;
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
            ['key'=>'store_summary','label'=>'门店汇总'],['key'=>'performance_type','label'=>'分类'],['key'=>'experience_count','label'=>'体验人次'],['key'=>'member_count','label'=>'成交人头'],['key'=>'consumption_amount','label'=>'消耗业绩'],['key'=>'labor_amount','label'=>'手工汇总'],['key'=>'sale_amount','label'=>'成交业绩'],
        ]), ['month'=>88, 'division_name'=>130, 'city_manager'=>130, 'store_summary'=>132, 'performance_type'=>220]);
        // 汇总逐列解释实际分组和计数边界，不能把体验数量说成去重人数；只更新说明，不改变指标。
        $explanations = [
            'month'=>'销售按成交日、补交按收款日、合作方退款按退款业务日归入月份。只统计所选日期范围内的记录。',
            'division_name'=>'这家门店当前所属的分公司；没有配置时显示“未配置分公司”。',
            'store_summary'=>'本行汇总的门店名称，使用业务发生时记录的名称。不同门店分别列示。',
            'performance_type'=>'按商品或卡内项目关联的平台商品当前分类显示；同一门店、月份和分类汇总为一行。平台改分类后，历史行的分类归属会更新，金额事实不变。',
            'experience_count'=>'查询日期内销售时记为体验的明细，按购买数量累计。例如一条体验明细购买2份，记2；不是按会员去重的人数。补交欠款不增加体验人次。',
            'member_count'=>'查询日期内本门店、本月份、本分类成交过的会员人数；同一会员重复成交只算1人，未关联会员的销售不计入。补交欠款不增加人数。一个会员可能出现在多行，因此合计栏显示“-”，不直接相加。',
            'consumption_amount'=>'查询日期内销售明细对应的已完成服务，按本分类累计消耗业绩金额；只购买、未完成服务不产生消耗。补交欠款不增加消耗业绩。',
            'labor_amount'=>'查询日期内销售明细对应的有效手艺人分配记录，累计其中的手工费金额；不是劳动业绩金额。补交欠款不增加手工费。',
            'sale_amount'=>'查询日期内已记账并分配到本分类的现金业绩净额；原成交可核实合作方归属的退款按实际退款金额在退款日负向冲减。不含未收欠款、余额支付和无法关联原成交分类的旧卡退款。',
        ];
        foreach ($columns as &$column) {
            $column['source_explanation'] = $explanations[$column['key']];
            if (!in_array((string)$column['key'], ['experience_count','member_count','consumption_amount','labor_amount','sale_amount'], true)) continue;
            $column['drilldown'] = ['report'=>'partner_item_detail', 'param_map'=>[
                'store_ids'=>'store_id', 'category_path_exact'=>'category_path_snapshot',
                'company_dimension_id'=>'company_dimension_id', 'city_manager_dimension_id'=>'city_manager_dimension_id',
            ]];
        }
        unset($column);
        return [
            'title'=>'合作方品项汇总','metric_version'=>'partner-item-current-category-v3','columns'=>$columns, 'records'=>$rows,'total'=>count($rows),'page'=>1,'page_size'=>count($rows),
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
     * 合作方汇总、明细及导出共用的只读行集。原销售活动保留数量、消耗和手工费，
     * 金额仅来自统一现金指标；跨日补交追加到收款日，不能被原销售日期筛掉。
     * 同一销售行/日期/完整分类合并多笔收款，不重复统计原成交活动。
     */
    private function partnerItemFacts($storeId, array $range, array $input): array
    {
        $stores = $this->organizationDimensions()->narrowStoreIds(is_array($storeId) ? $storeId : [(int)$storeId], $input);
        if ($stores === []) return [];
        $sales = $this->operationSaleQuery($stores, $range, $input)
            // 原销售活动也要固定租户，不能只给现金行做隔离而让其他实例活动污染数量。
            ->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereRaw("COALESCE(c.partner_name_snapshot,d.partner_name_snapshot)<>''")
            ->leftJoin('user u', 'u.uid=s.member_id')
            ->fieldRaw("s.fact_id,s.store_id,s.business_date,s.organization_id,s.organization_path_snapshot,s.store_name_snapshot,s.store_name_snapshot AS store_name,s.order_id,s.order_no_snapshot,s.member_id,s.member_name_snapshot,u.phone AS member_phone,s.item_name_snapshot,s.source_type,s.quantity,COALESCE(c.component_product_id,d.item_id,0) current_product_id,COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) AS category_id_snapshot,COALESCE(c.category_path_snapshot,d.category_path_snapshot) AS category_path_snapshot,COALESCE(c.product_type_snapshot,d.product_type_snapshot) AS product_type_snapshot,COALESCE(c.partner_name_snapshot,d.partner_name_snapshot) AS partner_name_snapshot,COALESCE(d.is_experience,0) AS is_experience,s.source_line_id,(SELECT COALESCE(SUM(pf.labor_fee_amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective') AS labor_amount_cents")
            ->select()->toArray();
        $this->projectSaleCategories($sales, 'current_product_id', 'category_id_snapshot', 'category_path_snapshot');
        $consume = $this->metricSourceLineCategoryTotals('consume_amount', $stores, $range, $input, array_column($sales, 'source_line_id'));
        $rows = [];
        foreach ($sales as $sale) {
            $key = $this->partnerItemFactKey($sale);
            if (isset($rows[$key])) continue;
            $consumeKey = (string)$sale['source_line_id'] . '|' . (int)$sale['category_id_snapshot'];
            $rows[$key] = $sale + ['_sale_activity'=>true, 'sale_amount_cents'=>0, 'consumption_amount_cents'=>(int)($consume[$consumeKey] ?? 0)];
        }
        $reader = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory();
        $categories = (int)($input['category_id'] ?? 0) > 0 ? [(int)$input['category_id']] : [];
        $filters = $this->itemAnalysisMetricFilters($input);
        $cash = $this->participantEmployeeId > 0
            ? $reader->categoryRowsForParticipant('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range, $categories, $filters, $this->participantEmployeeId)
            : $reader->categoryRows('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range, $categories, $filters);
        $exact = trim((string)($input['category_path_exact'] ?? ''));
        // 不给充值或未关联原销售分类的历史补交臆造合作方；精确下钻在后端再收窄。
        $cash = array_values(array_filter($cash, static function (array $row) use ($exact): bool {
            return (string)($row['partner_name_snapshot'] ?? '') !== ''
                && ($exact === '' || (string)($row['category_path'] ?? '') === $exact);
        }));
        $saleIds = array_values(array_unique(array_column($cash, 'sale_fact_id')));
        $metadata = [];
        if ($saleIds !== []) {
            // 此查询只补原订单识别信息，不读取销售金额；收款行已由 Reader 做租户、门店、参与人及作废过滤。
            $sourceRows = Db::name('cashier_v3_sale_fact')->alias('s')->leftJoin('user u', 'u.uid=s.member_id')
                ->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('s.store_id', $stores)->whereIn('s.fact_id', $saleIds)
                ->field('s.fact_id,s.order_id,s.order_no_snapshot,s.member_name_snapshot,s.item_name_snapshot,s.source_type,u.phone AS member_phone')->select()->toArray();
            foreach ($sourceRows as $source) $metadata[(string)$source['fact_id']] = $source;
        }
        foreach ($cash as $payment) {
            $source = $metadata[(string)($payment['sale_fact_id'] ?? '')] ?? null;
            if ($source === null) throw new \RuntimeException('合作方收款缺少原销售明细，请联系管理员核对。');
            $entry = array_merge($payment, $source, [
                'store_name_snapshot'=>(string)$payment['store_name'],
                'category_id_snapshot'=>(int)$payment['category_id'], 'category_path_snapshot'=>(string)$payment['category_path'],
            ]);
            $key = $this->partnerItemFactKey($entry);
            if (!isset($rows[$key])) {
                $rows[$key] = $entry + ['_sale_activity'=>false, 'quantity'=>0, 'is_experience'=>0, 'labor_amount_cents'=>0, 'consumption_amount_cents'=>0, 'sale_amount_cents'=>0];
            }
            $rows[$key]['sale_amount_cents'] += (int)$payment['amount_cents'];
        }
        // A refund is its own dated negative business fact. Only a verified
        // original partner allocation can attribute it to a partner category.
        $refundReader = new StoreMemberConsumptionRefundReadServices();
        $refundQuery = $refundReader->query($stores, $range, $input, $this->participantEmployeeId);
        $refundIds = (clone $refundQuery)->column('refund_operation.id');
        foreach ($refundReader->rows($refundQuery, array_map('intval', $refundIds)) as $refund) {
            if ((int)$refund['partner_category_id_snapshot'] <= 0
                || trim((string)$refund['partner_name_snapshot']) === '') continue;
            $row = $refund + [
                '_sale_activity' => false, 'quantity' => 0, 'is_experience' => 0,
                'labor_amount_cents' => 0, 'consumption_amount_cents' => 0,
            ];
            $row['store_name'] = (string)$refund['store_name_snapshot'];
            $row['source_type'] = 'refund';
            $row['order_no_snapshot'] = (string)$refund['order_no_snapshot'];
            $row['category_id_snapshot'] = (int)$refund['current_category_id'];
            $row['sale_amount_cents'] = (int)$refund['amount_cents'];
            $row['_market_group_key'] = 'refund:' . (string)$refund['order_id'];
            $rows[$row['_market_group_key']] = $row;
        }
        $rows = array_values($rows);
        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)$right['business_date'], (string)$left['business_date'])
                ?: strcmp((string)$right['order_no_snapshot'], (string)$left['order_no_snapshot'])
                ?: strcmp((string)$left['category_path_snapshot'], (string)$right['category_path_snapshot']);
        });
        return $rows;
    }

    /** 收款按稳定的原销售行、业务日、门店和完整分类合并，不能靠显示名称定位单据。 */
    private function partnerItemFactKey(array $row): string
    {
        // SQL 驱动会把分类 ID 返回为字符串，Reader 返回整数；归一化后才能合并同一活动。
        return (string)json_encode([(int)$row['store_id'], (string)$row['business_date'], (string)$row['source_line_id'], (int)$row['category_id_snapshot'], (string)$row['category_path_snapshot']], JSON_UNESCAPED_UNICODE);
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
        // 合计先覆盖完整权限行集，分页仅影响展示；导出不得沿用页面的前 100 行。
        $all = $this->partnerItemFacts($storeId, $range, $input);
        $total = count($all);
        // 明细不再展示成交人头；会员去重统计仍由汇总报表负责，不在这里重复计算。
        $summary = [];
        foreach (['quantity','labor_amount_cents','sale_amount_cents','consumption_amount_cents'] as $field) $summary[$field] = array_sum(array_column($all, $field));
        $rows = !empty($input['_internal_all']) ? $all : array_slice($all, ($this->page($input)-1)*$this->limit($input), $this->limit($input));
        $rows = $this->attachAnnotations($rows, 'partner_item_detail', $storeId);
        foreach ($rows as &$row) { $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']); $row['consumption_amount'] = $this->money((int)$row['consumption_amount_cents']); $row['labor_amount'] = $this->money((int)$row['labor_amount_cents']); $row['experience'] = (int)$row['is_experience'] === 1 ? '是' : '否'; }
        unset($row);
        foreach ($rows as &$row) { $this->applyOrganizationDimensions($row); $row['member_phone'] = (string)($row['member_phone'] ?? ''); $row['performance_type'] = (string)$row['category_path_snapshot']; $row['experience_project'] = $row['experience']; $row['deal_project'] = $row['item_name_snapshot']; $row['consumption'] = $row['consumption_amount'] ?? '0'; $row['consumption_amount'] = $row['consumption_amount'] ?? '0'; $row['labor_fee'] = $row['labor_amount'] ?? '0'; $row['deal_amount'] = $row['sale_amount']; $row['partner_label'] = $row['partner_name_snapshot']; }
        unset($row);
        // 原销售与补交保留原单号；退款行显示退款单号，另列原销售单号便于追查。
        $columns = $this->fixedColumns(array_merge($this->organizationDimensionColumns(), [
            ['key'=>'store_name_snapshot','label'=>'门店'],['key'=>'member_name_snapshot','label'=>'会员'],['key'=>'member_phone','label'=>'手机'],
            ['key'=>'order_no_snapshot','label'=>'业务单号','source_explanation'=>'成交及补交显示原销售单号；退款显示独立退款单号。'],
            ['key'=>'original_sale_no','label'=>'原销售单号','source_explanation'=>'退款对应的原销售单号；无法核实原单时留空。'],
            ['key'=>'performance_type','label'=>'业绩类型','source_explanation'=>'按商品或卡内项目关联的平台商品当前分类显示，与合作方品项汇总使用同一分类口径。'],['key'=>'business_date','label'=>'日期'],['key'=>'experience_project','label'=>'体验项目'],['key'=>'medical_elevation','label'=>'复诊'],['key'=>'medical_followup','label'=>'类型'],['key'=>'deal_project','label'=>'成交项目'],['key'=>'consumption','label'=>'消耗'],['key'=>'consumption_amount','label'=>'消耗金额'],['key'=>'labor_fee','label'=>'手工费'],['key'=>'quantity','label'=>'数量'],['key'=>'deal_amount','label'=>'成交金额'],['key'=>'expert_name','label'=>'专家姓名'],['key'=>'partner_label','label'=>'合作方'],['key'=>'remark','label'=>'备注'],
        ]), ['division_name'=>130, 'city_manager'=>130, 'store_name_snapshot'=>126, 'member_name_snapshot'=>88, 'member_phone'=>116, 'order_no_snapshot'=>165, 'performance_type'=>220, 'business_date'=>104]);
        // 本表逐列解释实际取值，不能用通用套话代替业务口径；这里只改说明，不改统计或人工字段规则。
        $explanations = [
            'division_name'=>'这家门店当前所属的分公司；没有配置时显示“未配置分公司”。',
            'store_name_snapshot'=>'办理原销售单据的门店名称，使用销售时记录的名称。',
            'member_name_snapshot'=>'原销售单据上的会员姓名，使用销售时记录的姓名。',
            'member_phone'=>'会员档案中当前保存的手机号；没有手机号时显示“-”。',
            'order_no_snapshot'=>'成交及补交显示原销售单号；退款显示独立退款单号。',
            'original_sale_no'=>'退款对应的原销售单号；无法核实原单时留空。',
            'performance_type'=>'按商品或卡内项目关联的平台商品当前分类显示；平台改分类后，历史行的分类归属随之更新。',
            'business_date'=>'销售显示成交日期，补交显示补交收款日期，退款显示退款业务日期。',
            'experience_project'=>'销售时记为体验的明细显示“是”，其余显示“否”；单独补交欠款的记录不重复计为体验。',
            'medical_elevation'=>'人工填写的复诊信息，系统不自动判断；未填写时显示“-”。',
            'medical_followup'=>'人工填写的业务类型，系统不自动分类；未填写时显示“-”。',
            'deal_project'=>'原销售单据中的商品、项目或卡项名称，使用销售时记录的名称。',
            'consumption'=>'这里显示消耗金额，不是次数，与“消耗金额”列相同；单独补交欠款不重复计入消耗。',
            'consumption_amount'=>'本条销售明细在查询日期内完成服务后，按本分类计算的消耗金额；只购买、未完成服务不产生消耗，单独补交欠款也不增加消耗。',
            'labor_fee'=>'本条销售明细对应的有效手艺人手工费合计；单独补交欠款不增加手工费。',
            'quantity'=>'原销售明细的购买数量；补交和退款调整行显示0，不重复改变成交数量。',
            'deal_amount'=>'本行在查询日期内已记账的现金业绩；退款按实际退款日以负数冲减。未核实原销售合作方归属的退款不分配到合作方分类。',
            'expert_name'=>'人工填写的专家姓名；未填写时显示“-”，不会自动取销售人或手艺人的姓名。',
            'partner_label'=>'销售时商品或卡内项目分类对应的合作方名称；之后修改分类或合作方配置，不改变这里的历史名称。',
            'remark'=>'人工填写的本条明细备注；未填写时显示“-”，备注不改变金额和统计结果。',
        ];
        foreach ($columns as &$column) {
            $column['source_explanation'] = $explanations[$column['key']];
        }
        unset($column);
        return [
            'title'=>'合作方品项明细','metric_version'=>'partner-item-current-category-v3','columns'=>$columns, 'records'=>$rows,'total'=>$total,'page'=>$this->page($input),'page_size'=>$this->limit($input),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true],
            'summary_row'=>$this->summaryRow($columns, [
                'store_name_snapshot'=>'合计',
                'consumption'=>$this->money((int)($summary['consumption_amount_cents'] ?? 0)),
                'consumption_amount'=>$this->money((int)($summary['consumption_amount_cents'] ?? 0)),
                'labor_fee'=>$this->money((int)($summary['labor_amount_cents'] ?? 0)),
                'quantity'=>(int)($summary['quantity'] ?? 0), 'deal_amount'=>$this->money((int)($summary['sale_amount_cents'] ?? 0)),
            ]),
        ];
    }

    /** 补交只从成功收款及原销售分摊事实入表，按补交业务日而非原销售日筛选。 */
    private function memberConsumptionSupplementQuery($storeId, array $range, array $input)
    {
        $query = Db::name('cashier_v3_payment_fact')->alias('repayment_payment')
            ->join('cashier_v3_debt_repayment repayment',
                "repayment.tenant_id=repayment_payment.tenant_id AND repayment.repayment_id=repayment_payment.order_id AND repayment.status='succeeded'")
            ->join('cashier_v3_payment_sale_allocation_fact allocation',
                "allocation.tenant_id=repayment_payment.tenant_id AND allocation.payment_fact_id=repayment_payment.fact_id AND allocation.status='effective' AND allocation.fact_direction='forward'")
            ->join('cashier_v3_sale_fact s',
                "s.tenant_id=allocation.tenant_id AND s.fact_id=allocation.sale_fact_id AND s.status='effective' AND s.fact_direction='forward'")
            ->leftJoin('cashier_v3_report_sale_dimension_fact d',
                'd.tenant_id=s.tenant_id AND d.store_id=s.store_id AND d.sale_fact_id=s.fact_id')
            ->where('repayment_payment.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('repayment_payment.source_document_type', 'debt_repayment')
            ->where('repayment_payment.fact_direction', 'forward')
            ->where('repayment_payment.status', 'effective')
            ->whereBetween('repayment_payment.business_date', [$range['start'], $range['end']]);
        if (is_array($storeId)) $query->whereIn('repayment_payment.store_id', array_values(array_unique(array_map('intval', $storeId))));
        else $query->where('repayment_payment.store_id', (int)$storeId);
        $this->normalDataScope()->excludeVoidedSupplementAllocations($query, 'allocation');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query, 's.tenant_id', 's.order_id');
        $this->organizationDimensions()->applyFilters($query, 'repayment_payment', $input, $range);
        if ($this->participantEmployeeId > 0) {
            // 补交是独立收款单；个人报表按本笔人员核权，不能借原销售参与关系看他人的补交。
            (new StoreReportParticipantScopeServices())->applySupplement($query,
                'repayment_payment.order_id', $this->participantEmployeeId);
        }
        $this->operationFilters($query, $input, false);
        return $query;
    }

    /** 本笔补交按原销售明细分摊展示，人员读取补交自身最新版本。 */
    private function memberConsumptionSupplementRows($query, array $entryIds, $storeId, array $partnerDefinitions): array
    {
        if ($entryIds === []) return [];
        $repaymentIds = $saleFactIds = [];
        foreach ($entryIds as $entryId) {
            [$repaymentId, $saleFactId] = array_pad(explode('|', (string)$entryId, 2), 2, '');
            if ($repaymentId !== '' && $saleFactId !== '') {
                $repaymentIds[] = $repaymentId;
                $saleFactIds[] = $saleFactId;
            }
        }
        if ($repaymentIds === []) return [];
        $raw = (clone $query)->whereIn('repayment_payment.order_id', array_values(array_unique($repaymentIds)))
            ->whereIn('allocation.sale_fact_id', array_values(array_unique($saleFactIds)))
            ->fieldRaw('repayment_payment.order_id repayment_id,repayment_payment.tenant_id,repayment_payment.store_id,repayment_payment.organization_id,repayment_payment.organization_path_snapshot,repayment_payment.store_name_snapshot,repayment_payment.member_id,repayment_payment.member_name_snapshot,repayment_payment.business_date,repayment_payment.business_source_label_snapshot,repayment_payment.payment_method,allocation.sale_fact_id,allocation.amount_cents,repayment.repayment_no,s.item_name_snapshot,d.item_id current_product_id,d.category_path_snapshot')
            ->select()->toArray();
        $this->projectSaleCategories($raw, 'current_product_id', 'current_category_id', 'category_path_snapshot');
        $selectedEntries = array_fill_keys($entryIds, true);
        $byEntry = [];
        foreach ($raw as $item) {
            $key = (string)$item['repayment_id'] . '|' . (string)$item['sale_fact_id'];
            if (!isset($selectedEntries[$key])) continue;
            if (!isset($byEntry[$key])) {
                $this->applyOrganizationDimensions($item);
                $item['order_no_snapshot'] = (string)$item['repayment_no'];
                $item['consume_type'] = '补交';
                $item['member_name'] = (string)$item['member_name_snapshot'];
                $item['consumption_detail'] = (string)$item['item_name_snapshot'];
                $item['category'] = (string)$item['category_path_snapshot'];
                $item['member_source'] = (string)$item['business_source_label_snapshot'];
                $item['quantity'] = 0;
                $item['experience_cash'] = '0';
                $item['experience_payment_method'] = '';
                $item['_payment_cents'] = [];
                $byEntry[$key] = $item;
            }
            $method = (string)$item['payment_method'];
            $byEntry[$key]['_payment_cents'][$method] = (int)($byEntry[$key]['_payment_cents'][$method] ?? 0)
                + (int)$item['amount_cents'];
        }
        $attributions = (new CashierV3SupplementAttributionServices())->currentRows(
            CashierV3ScopeResolver::TENANT_SCOPE_ID, array_values(array_unique($repaymentIds)));
        $salespersonRows = Db::name('cashier_v3_performance_fact')->alias('person')
            ->where('person.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('person.order_id', array_values(array_unique($repaymentIds)))
            ->where('person.source_document_type', 'debt_repayment')
            ->where('person.performance_type', 'sales_performance_allocated')
            ->where('person.fact_direction', 'forward')->where('person.status', 'effective')
            ->whereNotExists(function ($reverse) {
                $reverse->name('cashier_v3_performance_fact')->alias('person_reverse')
                    ->whereRaw('person_reverse.tenant_id=person.tenant_id')
                    ->whereRaw('person_reverse.reversal_of=person.fact_id')
                    ->where('person_reverse.fact_direction', 'reversal');
            })->field('person.order_id,person.employee_name_snapshot')->select()->toArray();
        $salesNames = [];
        foreach ($salespersonRows as $person) $salesNames[(string)$person['order_id']][] = (string)$person['employee_name_snapshot'];
        foreach ($byEntry as &$row) {
            $repaymentId = (string)$row['repayment_id'];
            $receiptCents = 0;
            foreach ($this->memberConsumptionPaymentMethods() as $method) {
                $amount = (int)($row['_payment_cents'][$method['code']] ?? 0);
                $row['payment_' . $method['code']] = $this->money($amount);
                $receiptCents += $amount;
            }
            $row['receipt_total'] = $this->money($receiptCents);
            $row['actual_cash_performance'] = $this->money($receiptCents);
            $row['partner_performance'] = $this->money(0);
            foreach ($partnerDefinitions as $definition) $row[$definition['key']] = $this->money(0);
            $row['salesperson_names'] = implode('、', array_values(array_unique($salesNames[$repaymentId] ?? [])));
            $guides = $managers = []; $rounds = [];
            foreach ($attributions[$repaymentId] ?? [] as $person) {
                if ((string)$person['role'] === 'guide') {
                    $guides[] = (string)$person['employee_name_snapshot'];
                    $rounds[] = (int)($person['guide_round_no'] ?? 0);
                } elseif ((string)$person['role'] === 'sales_manager') $managers[] = (string)$person['employee_name_snapshot'];
            }
            $row['sales_manager_name'] = implode('、', array_values(array_unique($managers)));
            $row['guide_names'] = implode('、', array_values(array_unique($guides)));
            $row['guide_round_no'] = $rounds ? (max($rounds) > 0 ? max($rounds) : '无') : '';
            unset($row['_payment_cents']);
        }
        unset($row);
        return $byEntry;
    }

    private function memberConsumptionDetail($storeId, array $range, array $input): array
    {
        $query = $this->operationSaleQuery($storeId, $range, $input, false);
        $supplementQuery = $this->memberConsumptionSupplementQuery($storeId, $range, $input);
        $stores = is_array($storeId) ? $storeId : [(int)$storeId];
        $stores = $this->organizationDimensions()->narrowStoreIds($stores, $input);
        $refundReader = new StoreMemberConsumptionRefundReadServices();
        $refundQuery = $refundReader->query($stores, $range, $input, $this->participantEmployeeId);
        // Original sales retain their sale date and gross amount. A completed
        // refund is a separate negative business-day row; union identities
        // before paging so filtered totals, the screen and export agree.
        $refundKeys = (clone $refundQuery)->fieldRaw("refund_operation.id entry_id,refund_operation.business_date,'refund' entry_kind")
            ->buildSql(false);
        $saleKeys = (clone $query)->fieldRaw("s.id entry_id,s.business_date,'sale' entry_kind")
            ->unionAll($refundKeys);
        $supplementKeys = (clone $supplementQuery)
            ->fieldRaw("CONCAT(repayment_payment.order_id,'|',allocation.sale_fact_id) entry_id,repayment_payment.business_date,'supplement' entry_kind")
            ->group('repayment_payment.order_id,allocation.sale_fact_id,repayment_payment.business_date')->buildSql(false);
        $saleKeys = $saleKeys->unionAll($supplementKeys)->buildSql();
        $entries = Db::table([$saleKeys => 'member_activity']);
        $total = (int)(clone $entries)->count();
        $ordered = (clone $entries)->field('entry_id,entry_kind,business_date')
            ->order('business_date', 'desc')->order('entry_id', 'desc')->order('entry_kind', 'asc');
        if (empty($input['_internal_all'])) $ordered->page($this->page($input), $this->limit($input));
        $pageEntries = $ordered->select()->toArray();
        $saleIds = $refundIds = $supplementEntryIds = [];
        foreach ($pageEntries as $entry) {
            if ((string)$entry['entry_kind'] === 'refund') $refundIds[] = (int)$entry['entry_id'];
            elseif ((string)$entry['entry_kind'] === 'supplement') $supplementEntryIds[] = (string)$entry['entry_id'];
            else $saleIds[] = (int)$entry['entry_id'];
        }
        $partnerDefinitions = $this->partnerPerformanceDefinitions($storeId);
        $summaryValues = $this->memberConsumptionSummaryValues($query, $supplementQuery, $storeId, $partnerDefinitions);
        $refundCents = $refundReader->amountCents($refundQuery);
        $allRefundIds = array_map('intval', (clone $refundQuery)->column('refund_operation.id'));
        $refundRows = $refundReader->rows($refundQuery, $allRefundIds);
        $refundById = [];
        $refundShares = [];
        $verifiedRefundCents = 0;
        foreach ($refundRows as $refund) {
            $refundById[(int)$refund['id']] = $refund;
            if ((string)($refund['original_sale_fact_id'] ?? '') !== '') {
                $verifiedRefundCents += (int)$refund['amount_cents'];
            }
            $categoryId = (int)$refund['partner_category_id_snapshot'];
            if ($categoryId > 0) $refundShares[$categoryId] = (int)($refundShares[$categoryId] ?? 0)
                + (int)$refund['partner_share_cents'];
        }
        $refundShareCents = array_sum($refundShares);
        $summaryValues['payment_refund_adjustment'] = $this->money($refundCents);
        $summaryValues['receipt_total'] = $this->money((int)$summaryValues['receipt_total_cents'] + $refundCents);
        $summaryValues['partner_performance'] = $this->money((int)$summaryValues['partner_performance_cents'] + $refundShareCents);
        $summaryValues['actual_cash_performance'] = $this->money((int)$summaryValues['actual_cash_performance_cents']
            + $verifiedRefundCents - $refundShareCents);
        foreach ($partnerDefinitions as $definition) {
            $categoryId = (int)$definition['category_id'];
            $summaryValues[(string)$definition['key']] = $this->money(
                (int)($summaryValues['partner_share_cents_by_category'][$categoryId] ?? 0)
                + (int)($refundShares[$categoryId] ?? 0));
        }
        $rows = $saleIds === [] ? [] : (clone $query)->whereIn('s.id', $saleIds)
            ->fieldRaw("s.id,s.fact_id,s.tenant_id,s.store_id,s.business_date,s.organization_id,s.organization_path_snapshot,s.store_name_snapshot,s.order_no_snapshot,s.order_id,s.source_line_id,s.member_id,s.member_name_snapshot,s.item_name_snapshot,d.item_id current_product_id,d.category_path_snapshot,d.product_type_snapshot,s.source_type,s.quantity,s.sale_amount_cents,d.partner_category_id_snapshot,d.partner_category_path_snapshot,d.partner_share_amount_cents,s.business_source_label_snapshot,s.source_attribution_type_snapshot,d.is_experience")
            ->select()->toArray();
        $this->projectSaleCategories($rows, 'current_product_id', 'current_category_id', 'category_path_snapshot');
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
        $byEntry = [];
        foreach ($rows as $row) $byEntry['sale:' . (int)$row['id']] = $row;
        // Refund personnel belongs to the refund operation and its latest revision;
        // only visible rows need this projection, while full totals still use all facts.
        $refundPageRows = [];
        foreach ($refundIds as $refundId) {
            if (isset($refundById[$refundId])) $refundPageRows[] = $refundById[$refundId];
        }
        $refundAttributionNames = $refundReader->attributionNamesForRefunds($refundPageRows);
        foreach ($refundIds as $refundId) {
            if (!isset($refundById[$refundId])) continue;
            $refund = $refundById[$refundId];
            $this->applyOrganizationDimensions($refund);
            $refund['_market_group_key'] = 'refund:' . (string)$refund['order_id'];
            $amount = (int)$refund['amount_cents'];
            $refund['consume_type'] = '退款';
            $refund['member_name'] = (string)$refund['member_name_snapshot'];
            $refundRoles = $refundAttributionNames[(int)$refund['store_id'] . '|' . (string)$refund['order_id']] ?? [];
            $refund['sales_manager_name'] = $refundRoles['sales_manager'] ?? '';
            $refund['guide_names'] = $refundRoles['guide'] ?? '';
            $refund['consumption_detail'] = (string)$refund['item_name_snapshot'];
            $refund['category'] = (string)$refund['category_path_snapshot'];
            $refund['sale_amount'] = $this->money($amount);
            $refund['receipt_total'] = $this->money($amount);
            $partnerShare = (int)$refund['partner_share_cents'];
            $hasOriginalSale = (string)($refund['original_sale_fact_id'] ?? '') !== '';
            // Old refunds without a verifiable original sale cannot borrow a
            // current partner ratio or be treated as zero-share cash.
            $refund['actual_cash_performance'] = $hasOriginalSale ? $this->money($amount - $partnerShare) : '';
            $refund['payment_refund_adjustment'] = $this->money($amount);
            $refund['partner_performance'] = $hasOriginalSale ? $this->money($partnerShare) : '';
            $refund['experience_cash'] = '0';
            $refund['quantity'] = 0;
            foreach ($this->memberConsumptionPaymentMethods() as $method) {
                if ($method['code'] !== 'refund_adjustment') $refund['payment_' . $method['code']] = '0';
            }
            foreach ($partnerDefinitions as $definition) {
                $refund[$definition['key']] = (int)$definition['category_id'] === (int)$refund['partner_category_id_snapshot']
                    ? $this->money($partnerShare) : ($hasOriginalSale ? '0' : '');
            }
            $byEntry['refund:' . (int)$refund['id']] = $refund;
        }
        foreach ($this->memberConsumptionSupplementRows($supplementQuery, $supplementEntryIds,
            $storeId, $partnerDefinitions) as $entryId => $supplement) {
            $byEntry['supplement:' . $entryId] = $supplement;
        }
        $rows = [];
        foreach ($pageEntries as $entry) {
            $key = (string)$entry['entry_kind'] . ':' . (string)$entry['entry_id'];
            if (isset($byEntry[$key])) $rows[] = $byEntry[$key];
        }
        $columns = array_merge($this->organizationDimensionColumns(), [
            ['key'=>'business_date','label'=>'日期'], ['key'=>'order_no_snapshot','label'=>'业务单号','source_explanation'=>'成交显示原销售单号；补交显示补交单号；退款显示退款单号，分别按业务日期入表。'], ['key'=>'consume_type','label'=>'消费类型'], ['key'=>'member_name','label'=>'姓名'],
            ['key'=>'consumption_detail','label'=>'消费明细'], ['key'=>'category','label'=>'分类','source_explanation'=>'按本行商品关联的平台商品当前分类显示；平台改分类后，历史明细分类同步更新，金额不变。'], ['key'=>'sales_manager_name','label'=>'销售经理','source_explanation'=>'成交显示原单经理；补交和退款显示本笔记录当前版本的销售经理，修改后不覆盖原单。'],
            ['key'=>'guide_round_no','label'=>'导购业绩次数','source_explanation'=>'成交显示原单导购轮次；补交显示本笔记录保存的轮次，仅作补交归属，不新增一次到店；退款不计轮次。'], ['key'=>'guide_names','label'=>'导购人员','source_explanation'=>'成交显示原单导购；补交和退款显示本笔记录当前版本的导购，修改后不覆盖原单。'], ['key'=>'salesperson_names','label'=>'销售'],
            ['key'=>'member_source','label'=>'会员来源'],
        ]);
        foreach ($this->memberConsumptionPaymentMethods() as $method) $columns[] = ['key'=>'payment_'.$method['code'],'label'=>$method['label'],'group_label'=>'支付现金业绩方式'];
        $columns[] = ['key'=>'receipt_total','label'=>'收款总金额','group_label'=>'支付现金业绩方式','source_explanation'=>'成交行显示当次已分摊记账收款，补交行显示补交业务日分摊收款；退款在退款日显示负向调整。每笔收款只进一个业务日。'];
        foreach ($partnerDefinitions as $definition) $columns[] = [
            'key'=>$definition['key'],'label'=>$definition['label'],'group_label'=>'合作方分成业绩',
            'source_explanation'=>'成交使用保存的合作方分成快照；退款按原成交比例在退款日冲减，不能从当前分类配置倒推历史比例。',
        ];
        foreach ([['partner_performance','合作方业绩'],['actual_cash_performance','分成后现金业绩'],['experience_cash','体验现金业绩'],['experience_payment_method','体验现金业绩支付方式']] as $column) $columns[] = ['key'=>$column[0],'label'=>$column[1]];
        foreach ($columns as &$column) {
            if ($column['key'] === 'partner_performance') $column['source_explanation'] =
                '合作方成交使用原销售分成快照；退款按原成交比例冲减。没有可核实原销售事实的旧退款不猜测分成，明细显示未核算。';
            if ($column['key'] === 'actual_cash_performance') $column['source_explanation'] =
                '已核实原成交的退款按原分成比例冲减；没有原销售事实的旧退款仍记入收款总额，但不计入本列，明细显示未核算。';
        }
        unset($column);
        $columns = $this->fixedColumns($columns, [
            'business_date'=>104, 'order_no_snapshot'=>150, 'consume_type'=>88, 'member_name'=>88,
            'consumption_detail'=>138, 'category'=>116,
        ]);
        return [
            'title'=>'会员消费明细','metric_version'=>'member-consumption-supplement-attribution-v3','columns'=>$columns,'column_groups'=>$this->columnGroups($columns),'records'=>$rows,'total'=>$total,'page'=>$this->page($input),'page_size'=>$this->limit($input),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true],
            'summary_row'=>$this->summaryRow($columns, array_merge(['business_date'=>'合计'], $summaryValues)),
        ];
    }

    private function storeItemAnalysis($storeId, array $range, array $input): array
    {
        $today = ['start' => $range['end'], 'end' => $range['end']];
        $cumulative = ['start' => self::COVERAGE_START, 'end' => $range['end']];
        // Header categories come from every existing platform category,
        // never from whichever facts happened to occur in the selected period.
        $definitions = $this->itemAnalysisCategoryDefinitions($storeId);
        $dimensions = $this->organizationDimensions();
        // Period, day and cumulative values must use one current organization
        // assignment snapshot; a later request starts fresh after this read.
        $dimensions->beginCurrentDimensionSnapshot();
        try {
            $period = $this->itemAnalysisMetricRows($storeId, $range, $input, $definitions);
            $daily = $this->itemAnalysisMetricRows($storeId, $today, $input, $definitions);
            $total = $this->itemAnalysisMetricRows($storeId, $cumulative, $input, $definitions);
        } finally {
            $dimensions->endCurrentDimensionSnapshot();
        }
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
                foreach (['cash', 'after_split', 'consume'] as $metric) {
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
                $rows[$storeIdKey][$base . '_after_split'] = $this->money($this->itemAnalysisAfterSplitCents($amounts));
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
            ['cash', '现金业绩', '成功收款金额扣除退款成功日的现金退款；不含余额支付和欠款。', 'payment'],
            ['share', '分成业绩', '各分类成交时记录的合作方分成，退款按原成交比例冲减。', 'partner'],
            ['actual', '分成后业绩', '本表现金业绩减去分成业绩；该列不是实际收款金额。', 'result'],
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
            // 当前配置决定分类列与历史金额的现时归属；原金额和合作方比例保持事实快照。
            // “未分类”承接没有可信平台商品关联或平台分类失效的历史金额。
            $isUnclassified = (int)$definition['category_id'] === 0;
            $metricExplanations = $isUnclassified ? [
                ['cash', '现金业绩', '所选日期内未关联有效平台分类的成功收款扣除同期间现金退款；不含余额支付和欠款。'],
                ['after_split', '分成后业绩', '上述未分类现金业绩减去成交时已记录、并随退款冲减的合作方分成金额。'],
                ['consume', '消耗业绩', '所选日期内完成的服务或核销，项目未关联有效平台分类时归入此列；冲销按发生日扣回，原金额不变。'],
            ] : [
                ['cash', '现金业绩', '所选日期内成功收款扣除现金退款，按商品或卡内项目的平台当前分类归入本列；不含余额支付和欠款。'],
                ['after_split', '分成后业绩', '本分类净现金业绩减去成交时记录、并随退款按原比例冲减的合作方分成金额。'],
                ['consume', '消耗业绩', '所选日期内已完成服务或核销的消耗金额，按项目的平台当前分类归入本列；冲销按发生日扣回。'],
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
            $categoryTotals = ['cash_cents' => 0, 'share_cents' => 0, 'consume_cents' => 0];
            foreach ($period['categories'] as $categories) {
                foreach (array_keys($categoryTotals) as $amountKey) {
                    $categoryTotals[$amountKey] += (int)($categories[$categoryId][$amountKey] ?? 0);
                }
            }
            $summaryValues[$definition['key'] . '_cash'] = $this->money($categoryTotals['cash_cents']);
            $summaryValues[$definition['key'] . '_after_split'] = $this->money($this->itemAnalysisAfterSplitCents($categoryTotals));
            $summaryValues[$definition['key'] . '_consume'] = $this->money($categoryTotals['consume_cents']);
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
            'title' => '门店品项分析', 'metric_version' => 'store-item-analysis-current-category-refund-v3', 'columns' => $columns,
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
            ->fieldRaw("s.store_id,s.store_name_snapshot,s.organization_id,s.organization_path_snapshot,s.business_date,s.source_line_id,COALESCE(c.component_product_id,d.item_id,0) current_product_id,COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) AS category_id_snapshot,COALESCE(c.category_path_snapshot,d.category_path_snapshot) AS category_path_snapshot,COALESCE(c.partner_share_amount_cents,d.partner_share_amount_cents,0) AS share_cents")
            ->select()->toArray();
        $this->projectSaleCategories($shareRows, 'current_product_id', 'category_id_snapshot', 'category_path_snapshot');
        $categoryIds = (int)($input['category_id'] ?? 0) > 0 ? [(int)$input['category_id']] : [];
        $metricFilters = $this->itemAnalysisMetricFilters($input);
        $metricReader = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory();
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
        // The registered refund reader owns sale-linked adjustments. Standalone
        // card/document refunds are excluded here and reintroduced below with
        // their verified original product, so one cash refund is deducted once.
        $refundBuckets = $metricReader->categoryReportBucketsWithoutStandaloneRefunds(
            CashierV3ScopeResolver::TENANT_SCOPE_ID, $authorizedStores, $range, $categoryIds,
            $metricFilters, $this->participantEmployeeId
        );
        $refundTotals = [];
        foreach ($refundBuckets as $entry) {
            $entry['category_id_snapshot'] = (int)($entry['category_id'] ?? 0);
            $entry['category_path_snapshot'] = (string)($entry['category_path'] ?? '');
            $this->applyOrganizationDimensions($entry);
            $storeKey = $this->itemAnalysisStore($stores, $entry);
            $refundTotals[$storeKey] = (int)$entry['store_metric_value'];
            $category = $this->itemAnalysisConfiguredCategory(
                $definitions, (int)$entry['category_id_snapshot'], (string)$entry['category_path_snapshot']
            );
            if ($category === null) continue;
            $this->itemAnalysisCategoryAmount($categories, $storeKey, $category);
            $categories[$storeKey][$category['id']]['cash_cents'] -= (int)$entry['metric_value'];
        }
        foreach ($refundTotals as $storeKey => $refundCents) {
            $stores[$storeKey]['cash_cents'] -= $refundCents;
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
        $refundReader = new StoreMemberConsumptionRefundReadServices();
        $refundQuery = $refundReader->query($authorizedStores, $range, $input, $this->participantEmployeeId);
        $refundIds = array_column((clone $refundQuery)->field('refund_operation.id')->select()->toArray(), 'id');
        // Bound each product/category lookup; this report never needs the
        // refund salespeople names used by the member detail page.
        foreach (array_chunk($refundIds, 500) as $batch) {
            foreach ($refundReader->rows($refundQuery, $batch, false) as $entry) {
                $this->applyOrganizationDimensions($entry);
                $storeKey = $this->itemAnalysisStore($stores, $entry);
                $shareCents = (int)$entry['partner_share_cents'];
                $stores[$storeKey]['share_cents'] += $shareCents;
                // Sale-linked refunds were already counted by the metric Reader;
                // only independent payment facts need their own cash adjustment.
                $standalone = in_array((string)$entry['source_document_type'],
                    ['manual_refund', 'imported_card_refund'], true);
                if ($standalone) $stores[$storeKey]['cash_cents'] += (int)$entry['amount_cents'];
                $category = $this->itemAnalysisConfiguredCategory(
                    $definitions, (int)$entry['current_category_id'], (string)$entry['category_path_snapshot']
                );
                if ($category === null) continue;
                $this->itemAnalysisCategoryAmount($categories, $storeKey, $category);
                $categories[$storeKey][$category['id']]['share_cents'] += $shareCents;
                if ($standalone) $categories[$storeKey][$category['id']]['cash_cents'] += (int)$entry['amount_cents'];
            }
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

    /**
     * 分类“分成后业绩”必须复用成交时冻结的现金与合作方分成事实。
     * 页面、合计和导出都调用这里，避免把分成金额本身误当成经营结果。
     */
    private function itemAnalysisAfterSplitCents(array $amounts): int
    {
        return (int)($amounts['cash_cents'] ?? 0) - (int)($amounts['share_cents'] ?? 0);
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
        return \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory()->sourceLineCategoryTotals(
            $metricCode, CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range, $lineIds,
            $this->itemAnalysisMetricFilters($input)
        );
    }

    /**
     * Inputs have already been projected from the linked platform product.
     * Match the current ID first; a deeper category may roll up to a displayed
     * second-level column by its current path. Neither branch reads a sale snapshot.
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
            // 未关联可显示的平台分类时，保留在“未分类”列，避免隐去金额。
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
            ->field('id,pid,cate_name')->select()->toArray() as $category) {
            $id = (int)($category['id'] ?? 0);
            if ($id > 0) $categories[$id] = $category;
        }
        if ($categories === []) return $unclassified;

        // is_show only hides catalog choices; a category row still classifies its linked products.
        $available = array_fill_keys(array_keys($categories), true);

        // Keep the same two-level projection used by partner columns, but
        // apply it to every existing category. A root is replaced by
        // its direct children; deeper categories roll up to that
        // second level and never create a third-level header.
        $directChildren = [];
        foreach (array_keys($available) as $categoryId) {
            $chain = $this->partnerCategoryChain((int)$categoryId, $categories);
            if ($chain === []) continue;
            $rootId = (int)$chain[0]['id'];
            if (count($chain) >= 2 && (int)$chain[1]['id'] === (int)$categoryId) {
                $directChildren[$rootId][(int)$categoryId] = true;
            }
        }

        $definitions = [];
        foreach (array_keys($available) as $categoryId) {
            $chain = $this->partnerCategoryChain((int)$categoryId, $categories);
            if ($chain === []) continue;
            $rootId = (int)$chain[0]['id'];
            $effective = $chain[0];
            if (count($chain) >= 2) {
                $effective = $chain[1];
            } elseif (isset($directChildren[$rootId])) {
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
            $row['project_count'] = ($row['project_count_decimal'] ?? null) !== null
                ? (float)$row['project_count_decimal']
                : ((int)($row['project_count_half_units'] ?? 0)) / 2;
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
            : (new StoreSalespersonNetReadServices())->matrix($stores, $range, $employeeIds);
        $by = $matrix['records'];
        $summaryPerformance = $matrix['summary']['day_metric_values'];
        // Sales and refund allocations share the same person/day facts. Drill
        // into those facts, not the sale-only order-center list.
        $columns = array_merge($this->organizationDimensionColumns(), [[
            'key'=>'employee_name','label'=>'销售人',
            'source_explanation'=>'显示结账完成后实际分到销售业绩的员工；同一笔成交分给多人时，每个人分别统计。',
        ]]);
        foreach (range(1,31) as $day) {
            $columns[] = [
                'key'=>'day_'.$day.'_performance','label'=>$day.'日业绩',
                'source_explanation'=>$day.'日分配给该销售人的销售业绩合计；退款或人员调整产生的反向金额按发生日冲减，整单作废后原金额和冲销金额都不显示。',
            ];
        }
        $columns[] = [
            'key'=>'total_performance','label'=>'合计业绩',
            'source_explanation'=>'查询日期内分配给该销售人的销售业绩合计；退款或人员调整按发生日冲减，整单作废的数据不计入。',
        ];
        foreach($by as &$row){
            $this->applyOrganizationDimensions($row);
            $row['_drilldown'] = [];
            foreach(range(1,31) as $day) {
                $key = 'day_'.$day.'_performance';
                $value = (int)($row['day_metric_values'][$day] ?? 0);
                $row[$key] = $this->money($value);
                if ($value !== 0) {
                    $row['_drilldown'][$key] = [
                        'report'=>'store_salesperson_performance_detail',
                        'params'=>['day_of_month'=>$day],
                        'param_map'=>['salesperson_id'=>'employee_id','store_ids'=>'store_id'],
                    ];
                }
            }
            $row['total_performance_cents']=(int)$row['total_metric_value'];
            $row['total_performance']=$this->money($row['total_performance_cents']);
            if ($row['total_performance_cents'] !== 0) {
                $row['_drilldown']['total_performance'] = [
                    'report'=>'store_salesperson_performance_detail','params'=>['day_of_month'=>0],
                    'param_map'=>['salesperson_id'=>'employee_id','store_ids'=>'store_id'],
                ];
            }
        } unset($row);
        $summaryValues = ['employee_name'=>'合计']; foreach(range(1,31) as $day)$summaryValues['day_'.$day.'_performance']=$this->money((int)($summaryPerformance[$day]??0)); $summaryValues['total_performance']=$this->money((int)$matrix['summary']['total_metric_value']);
        $columns = $this->fixedColumns($columns, ['division_name'=>130, 'city_manager'=>130, 'employee_name'=>120]);
        return [
            'title'=>'门店销售人业绩','columns'=>$columns,'records'=>$by,'total'=>count($by),'page'=>1,'page_size'=>count($by),
            'filter_schema' => $this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true], 'summary_row'=>$this->summaryRow($columns, $summaryValues),
        ];
    }

    /** The hidden detail retains exactly the summary's employee, store and day-of-month scope. */
    private function salespersonPerformanceDetail($storeId, array $range, array $input): array
    {
        $stores = is_array($storeId) ? array_values(array_unique(array_map('intval', $storeId))) : [(int)$storeId];
        $stores = $this->organizationDimensions()->narrowStoreIds($stores, $input);
        $employeeIds = $this->personnelSelection($input, 'salesperson_id');
        $day = (int)($input['day_of_month'] ?? 0);
        if ($day < 0 || $day > 31) throw new \InvalidArgumentException('日序号无效');
        $exportAll = !empty($input['_internal_all']);
        $details = $employeeIds === null
            ? ['records' => [], 'total' => 0, 'amount_cents' => 0]
            : (new StoreSalespersonNetReadServices())->detail($stores, $range, $employeeIds, $day,
                $this->page($input), $this->limit($input), $exportAll);
        $rows = $details['records'];
        foreach ($rows as &$row) {
            $this->applyOrganizationDimensions($row);
            $row['_market_group_key'] = (string)$row['fact_id'];
            $isRefund = (string)$row['performance_type'] === 'refund_sales_deduction';
            $row['business_type'] = $isRefund ? '退款冲减' : '销售分配';
            $row['document_no'] = $isRefund ? (string)$row['refund_no'] : (string)$row['order_no_snapshot'];
            $row['original_no'] = $isRefund ? (string)$row['original_no'] : '';
            $row['performance_amount'] = $this->money((int)$row['amount_cents']);
        }
        unset($row);
        $columns = $this->fixedColumns(array_merge($this->organizationDimensionColumns(), [
            ['key'=>'business_date','label'=>'业务日期'],
            ['key'=>'business_type','label'=>'业绩类型','source_explanation'=>'销售人正向分配或有效退款负向冲减；退款作废和人员改派的旧归属不显示。'],
            ['key'=>'document_no','label'=>'业务单号','source_explanation'=>'销售分配显示原销售单号，退款冲减显示退款单号。'],
            ['key'=>'original_no','label'=>'原销售单号','source_explanation'=>'退款对应的原销售单号；无可信关联时留空。'],
            ['key'=>'member_name','label'=>'会员'], ['key'=>'employee_name','label'=>'销售人'],
            ['key'=>'performance_amount','label'=>'销售业绩','source_explanation'=>'逐人有效销售分配金额及退款日负向冲减，金额在分精度求和后按元展示。'],
        ]), ['business_date'=>104,'business_type'=>96,'document_no'=>160,'employee_name'=>104]);
        return [
            'title'=>'销售人业绩明细','columns'=>$columns,'records'=>$rows,
            'total'=>(int)$details['total'],'page'=>$this->page($input),'page_size'=>$this->limit($input),
            'filter_schema'=>$this->organizationDimensionFilterSchema($range),
            'table_layout'=>['fixed'=>true],
            'summary_row'=>$this->summaryRow($columns, ['business_date'=>'合计',
                'performance_amount'=>$this->money((int)$details['amount_cents'])]),
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
        if ($dataset === 'payment') {
            // Payment facts also contain recharge and standalone repayment receipts.
            $this->normalDataScope()->excludeVoidedRechargeOrderFacts($query, 'tenant_id', 'order_id');
            $this->normalDataScope()->excludeVoidedSupplementPaymentFacts($query, 'tenant_id', 'store_id', 'order_id');
        }
        $this->commonFilters($query, $input, $dataset);
        $total = (int)(clone $query)->count();
        $records = (clone $query)->order('business_date', 'desc')->order('id', 'desc')->page($this->page($input), $this->limit($input))->select()->toArray();
        foreach ($records as &$row) $row['amount'] = $this->money((int)($row[$dataset === 'payment' ? 'amount_cents' : 'sale_amount_cents'] ?? 0));
        unset($row);
        return ['title' => $dataset === 'payment' ? '收款明细' : '销售明细', 'dataset' => $dataset, 'columns' => $dataset === 'payment' ? $this->paymentColumns() : $this->saleColumns(), 'records' => $records, 'total' => $total, 'page' => $this->page($input), 'page_size' => $this->limit($input)];
    }

    private function service($storeId, array $range, array $input)
    {
        $serviceQuery = $this->withStoreScope(Db::name('cashier_v3_entitlement_service_fact')->alias('sv'), $storeId)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']])->where('sv.service_status', 'completed');
        // The normal-scope subqueries refer to the SQL alias, not the logical
        // table name (which ThinkPHP prefixes before execution).
        $this->normalDataScope()->excludeVoidedSalesOrderServices($serviceQuery, 'sv');
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
        // Projection rows may retain stages from voided orders/services. Only
        // effective lifecycle and service facts may classify operating rows.
        $normalLife = (new CustomerLifecycleNormalReadServices())->forMembers('0', $memberIds);
        $sales = $this->withStoreScope(Db::name('cashier_v3_sale_fact'), $storeId)->whereIn('member_id', $memberIds)
            ->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($sales, 'tenant_id', 'order_id');
        $sales = $sales
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
        foreach ($memberIds as $memberId) {
            $life = $normalLife[$memberId] ?? [];
            if ($life === [] && !isset($salesByMember[$memberId])
                && !isset($cashByMember[$memberId]) && !isset($consumeByMember[$memberId])) continue;
            $stage = (string)($life['lifecycle_stage'] ?? '');
            $isActive = isset($active[$memberId]);
            $lastVisit = (int)($life['last_service_at'] ?? 0);
            $base = $lastVisit ?: (int)($life['first_course_completed_at'] ?? 0);
            $sleeping = $base > 0 && $base < $cutoff;
            $cash = (int)($cashByMember[$memberId] ?? 0);
            $consume = (int)($consumeByMember[$memberId] ?? 0);
            $isEffective = ($stage === 'pre_sale' && $cash >= 50000) || ($stage === 'post_sale' && $cash >= 100000);
            if (!$this->matchesSegment($segment, $stage, (int)($life['is_guest'] ?? 0), $isActive, $isEffective, $sleeping)) continue;
            $sale = $salesByMember[$memberId] ?? [];
            $amount = $metric === 'consume' ? $consume : $cash;
            $records[] = [
                'member_id'=>$memberId, 'member_name'=>(string)($sale['member_name'] ?? ''), 'lifecycle_label'=>$this->lifecycleLabel($stage),
                'is_guest'=>(int)($life['is_guest'] ?? 0) === 1 ? '是' : '否', 'first_course_source'=>(string)($life['first_course_source_label_snapshot'] ?? ''),
                'conversion_history'=>!empty($life['has_pending_conversion']) ? '曾待转换' : '',
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
        $records = (clone $query)->fieldRaw('item_id,MIN(store_id) store_id,MAX(item_name_snapshot) AS item_name,SUM(quantity) AS quantity,COUNT(DISTINCT NULLIF(member_id,0)) AS member_count,SUM(sale_amount_cents) AS sale_amount_cents')
            ->group('item_id')->orderRaw('sale_amount_cents DESC')->page($this->page($input), $this->limit($input))->select()->toArray();
        $this->projectSaleCategories($records, 'item_id', 'category_id', 'category_name');
        foreach ($records as &$row) $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']);
        unset($row);
        return ['title' => '品项经营', 'metric_version'=>'item-business-current-category-v2',
            'columns' => [['key'=>'category_name','label'=>'品类','source_explanation'=>'按品项关联的平台商品当前分类显示，找不到有效关联时留空；成交数量和金额仍取原销售事实。'],['key'=>'item_name','label'=>'品项'],['key'=>'quantity','label'=>'成交数量'],['key'=>'member_count','label'=>'成交顾客'],['key'=>'sale_amount','label'=>'成交金额']], 'records' => $records, 'total' => $total, 'page' => $this->page($input), 'page_size' => $this->limit($input)];
    }

    private function channels($storeId, array $range, array $input)
    {
        $customers = $this->withStoreScope(Db::name('cashier_v3_customer_lifecycle_projection'), $storeId)
            ->whereBetween('first_course_business_date', [$range['start'], $range['end']])->where('first_course_order_id', '<>', '')->select()->toArray();
        $groups = [];
        foreach ($customers as $customer) {
            $id = (int)$customer['first_course_source_primary_id']; if ($id <= 0) continue;
            if (!empty($input['channel_id']) && $id !== (int)$input['channel_id']) continue;
            if (!isset($groups[$id])) $groups[$id] = ['business_source_primary_id'=>$id,'channel_name'=>(string)$customer['first_course_source_label_snapshot'],'source_type'=>(string)$customer['first_course_source_attribution_type_snapshot'],'member_count'=>0,'order_ids'=>[],'members_by_order'=>[]];
            $orderId = (string)$customer['first_course_order_id'];
            $groups[$id]['order_ids'][$orderId] = true;
            $groups[$id]['members_by_order'][$orderId][(int)$customer['member_id']] = true;
        }
        foreach ($groups as &$group) {
            $orders = array_keys($group['order_ids']);
            // The lifecycle projection is historical; a voided first-course
            // sale remains there for audit but cannot contribute a customer or
            // order to the operating channel report.
            $salesQuery = $orders ? $this->withStoreScope(Db::name('cashier_v3_sale_fact'), $storeId)->whereIn('order_id', $orders)->where('status','effective')->where('source_type','card') : null;
            if ($salesQuery) $this->normalDataScope()->excludeVoidedSalesOrderFacts($salesQuery, 'tenant_id', 'order_id');
            $validOrders = $salesQuery ? array_values(array_unique(array_map('strval', (clone $salesQuery)->column('order_id')))) : [];
            $sales = $salesQuery ? $salesQuery->sum('sale_amount_cents') : 0;
            $receiptsQuery = $validOrders ? $this->withStoreScope(Db::name('cashier_v3_payment_fact'), $storeId)->whereIn('order_id', $validOrders)->where('status','effective') : null;
            if ($receiptsQuery) $this->normalDataScope()->excludeVoidedSalesOrderFacts($receiptsQuery, 'tenant_id', 'order_id');
            $receipts = $receiptsQuery ? $receiptsQuery->sum('amount_cents') : 0;
            $members = [];
            foreach ($validOrders as $orderId) foreach (array_keys($group['members_by_order'][$orderId] ?? []) as $memberId) $members[(int)$memberId] = true;
            $group['member_count'] = count($members);
            $group['order_count'] = count($validOrders); $group['sale_amount'] = $this->money((int)$sales); $group['receipt_amount'] = $this->money((int)$receipts);
            $group['average_order_amount'] = $group['order_count'] ? $this->money((int)$sales / $group['order_count']) : '0'; unset($group['order_ids'], $group['members_by_order']);
        }
        unset($group); $records = array_values(array_filter($groups, static function (array $group): bool { return (int)$group['order_count'] > 0; })); usort($records, function ($a, $b) { return (float)$b['sale_amount'] <=> (float)$a['sale_amount']; });
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
        $query = $this->withStoreScope(Db::name($table)->alias('fact'), $storeId)
            ->whereIn('fact.member_id', $memberIds)->whereBetween('fact.business_date', [$start, $end])->where('fact.status', 'effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query, 'fact.tenant_id', 'fact.order_id');
        if ($table === 'cashier_v3_payment_fact' || $table === 'cashier_v3_performance_fact') {
            $this->normalDataScope()->excludeVoidedRechargeOrderFacts($query, 'fact.tenant_id', 'fact.order_id');
            $this->normalDataScope()->excludeVoidedSupplementPaymentFacts($query, 'fact.tenant_id', 'fact.store_id', 'fact.order_id');
        }
        if ($table === 'cashier_v3_performance_fact' && ($conditions['performance_type'] ?? '') === 'consumption_performance_recorded') {
            $this->normalDataScope()->excludeVoidedSalesOrderServices($query, 'fact');
        }
        foreach ($conditions as $key => $value) $query->where('fact.' . $key, $value);
        $rows = $query->fieldRaw('fact.member_id, SUM(fact.' . $amount . ') amount')->group('fact.member_id')->select()->toArray();
        $result = []; foreach ($rows as $row) $result[(int)$row['member_id']] = (int)$row['amount'];
        return $result;
    }

    private function activeMembers($storeId, array $range, array $memberIds): array
    {
        $query = $this->withStoreScope(Db::name('cashier_v3_entitlement_service_fact')->alias('s'), $storeId)->whereIn('s.member_id', $memberIds)
            ->whereBetween('s.business_date', [$range['start'], $range['end']])->where('s.service_status', 'completed');
        $this->normalDataScope()->excludeVoidedSalesOrderServices($query, 's');
        // 客户活跃按门店真实到店判定；体验不占到店，人员不计数开关不影响顾客状态。
        \app\services\query\metric\StoreCustomerVisitEligibility::apply($query, 's');
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

    /** Refund cash has no verified original tender; keep its signed amount in an explicit adjustment column. */
    private function memberConsumptionPaymentMethods(): array
    {
        $methods = $this->paymentMethodDefinitions();
        $methods[] = ['code' => 'refund_adjustment', 'label' => '退款调整'];
        return $methods;
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

        // Disabled partner configurations are excluded above; catalog visibility
        // must not hide a configured category that still has persisted facts.
        $categories = [];
        foreach (Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
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
            // Correlated void predicates require an explicit SQL alias; the
            // logical ThinkPHP table name omits the configured database prefix.
            $payments = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('allocation')
                ->join('cashier_v3_payment_fact original_payment',
                    'original_payment.tenant_id=allocation.tenant_id AND original_payment.fact_id=allocation.payment_fact_id')
                ->where('allocation.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('allocation.sale_fact_id', $saleFactIds)->where('allocation.status', 'effective')
                // 补交收款单列在补交业务日，原销售行只保留成交当次收款，避免跨日重复计入。
                ->where('original_payment.source_document_type', '<>', 'debt_repayment')
                ->fieldRaw('allocation.sale_fact_id,allocation.payment_method,SUM(allocation.amount_cents) amount_cents')
                ->group('allocation.sale_fact_id,allocation.payment_method');
            if (is_array($storeId)) $payments->whereIn('allocation.store_id', array_values(array_unique(array_map('intval', $storeId))));
            else $payments->where('allocation.store_id', (int)$storeId);
            $this->normalDataScope()->excludeVoidedSupplementAllocations($payments, 'allocation');
            $payments = $payments->select()->toArray();
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
        $methods = $this->memberConsumptionPaymentMethods();
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
    private function memberConsumptionSummaryValues($query, $supplementQuery, $storeId, array $partnerDefinitions): array
    {
        $base = (clone $query)->fieldRaw('SUM(s.quantity) quantity')->find() ?: [];
        $paymentRows = (clone $query)
            ->join('cashier_v3_payment_sale_allocation_fact pa', "pa.sale_fact_id=s.fact_id AND pa.status='effective'")
            ->join('cashier_v3_payment_fact original_payment',
                'original_payment.tenant_id=pa.tenant_id AND original_payment.fact_id=pa.payment_fact_id')
            ->where('original_payment.source_document_type', '<>', 'debt_repayment')
            ->fieldRaw('pa.payment_method,SUM(pa.amount_cents) amount_cents')->group('pa.payment_method')->select()->toArray();
        $payments = [];
        foreach ($paymentRows as $payment) $payments[(string)$payment['payment_method']] = (int)$payment['amount_cents'];
        // 全量补交汇总与补交行共用同一事实查询和权限范围，分页不会改变合计。
        foreach ((clone $supplementQuery)->fieldRaw('repayment_payment.payment_method,SUM(allocation.amount_cents) amount_cents')
            ->group('repayment_payment.payment_method')->select()->toArray() as $payment) {
            $method = (string)$payment['payment_method'];
            $payments[$method] = (int)($payments[$method] ?? 0) + (int)$payment['amount_cents'];
        }

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
        foreach ($this->memberConsumptionPaymentMethods() as $method) {
            $amount = (int)($payments[(string)$method['code']] ?? 0);
            $values['payment_' . $method['code']] = $this->money($amount);
            $receiptTotal += $amount;
        }
        $values['receipt_total'] = $this->money($receiptTotal);
        $values['receipt_total_cents'] = $receiptTotal;
        $partnerTotal = 0;
        foreach ($partnerDefinitions as $definition) {
            $amount = (int)($shareByCategory[(int)$definition['category_id']] ?? 0);
            $values[(string)$definition['key']] = $this->money($amount);
            $partnerTotal += $amount;
        }
        $values['partner_performance'] = $this->money($partnerTotal);
        $values['partner_performance_cents'] = $partnerTotal;
        $values['partner_share_cents_by_category'] = $shareByCategory;
        $values['actual_cash_performance'] = $this->money($receiptTotal - $partnerTotal);
        $values['actual_cash_performance_cents'] = $receiptTotal - $partnerTotal;
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
