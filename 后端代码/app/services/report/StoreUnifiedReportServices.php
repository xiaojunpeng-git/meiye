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
            'store_item_analysis', 'store_craftsman_consumption',
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
        $result = $this->query($storeId, $input);
        return [
            'filename' => '门店经营报表-' . ($result['title'] ?? '数据') . '-' . date('YmdHis') . '.csv',
            'columns' => $result['columns'] ?? [], 'records' => $result['records'] ?? [],
            'metric_version' => self::METRIC_VERSION,
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
        $this->operationFilters($query, $input, $expandCardCategories);
        return $query;
    }

    private function operationFilters($query, array $input, bool $expandCardCategories = true): void
    {
        $categoryId = (int)($input['category_id'] ?? 0);
        $path = trim((string)($input['category_path'] ?? ''));
        $type = trim((string)($input['product_type'] ?? ''));
        $partner = trim((string)($input['partner_name'] ?? ''));
        if ($expandCardCategories) {
            if ($categoryId > 0) $query->whereRaw('COALESCE(c.category_id_snapshot,d.category_id_snapshot)=?', [$categoryId]);
            if ($path !== '') $query->whereRaw('COALESCE(c.category_path_snapshot,d.category_path_snapshot) LIKE ?', [$path . '%']);
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
        $rows = $query->fieldRaw("DATE_FORMAT(s.business_date,'%Y-%m') AS month,MAX(s.organization_name_snapshot) AS division_name,s.store_id,MAX(s.store_name_snapshot) AS store_name,COALESCE(c.category_path_snapshot,d.category_path_snapshot) AS category_path_snapshot,COALESCE(c.partner_name_snapshot,d.partner_name_snapshot) AS partner_name_snapshot,SUBSTRING_INDEX(COALESCE(c.category_path_snapshot,d.category_path_snapshot),'/',1) AS performance_type,SUM(COALESCE(c.sale_amount_cents,s.sale_amount_cents)) AS sale_amount_cents,SUM(CASE WHEN COALESCE(d.is_experience,0)=1 THEN s.quantity ELSE 0 END) AS experience_count,COUNT(DISTINCT NULLIF(s.member_id,0)) AS member_count,SUM(s.quantity) AS quantity,SUM((SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective')) AS consumption_amount_cents,SUM((SELECT COALESCE(SUM(pf.labor_fee_amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective')) AS labor_amount_cents,SUM(COALESCE(c.cash_performance_amount_cents,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='actual_performance_recorded' AND pf.status='effective'))) AS actual_performance_cents")
            ->group("month,s.store_id,COALESCE(c.partner_name_snapshot,d.partner_name_snapshot),COALESCE(c.category_path_snapshot,d.category_path_snapshot)")->orderRaw('month DESC,sale_amount_cents DESC')->select()->toArray();
        foreach ($rows as &$row) { $row['store_summary'] = (string)$row['store_name']; $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']); $row['actual_performance'] = $this->money((int)$row['actual_performance_cents']); $row['consumption_amount'] = $this->money((int)$row['consumption_amount_cents']); $row['labor_amount'] = $this->money((int)$row['labor_amount_cents']); $row['experience_count'] = (int)$row['experience_count']; $row['member_count'] = (int)$row['member_count']; }
        unset($row);
        $columns = $this->fixedColumns([
            ['key'=>'month','label'=>'月份'],['key'=>'division_name','label'=>'分公司'],['key'=>'store_summary','label'=>'门店汇总'],['key'=>'performance_type','label'=>'分类'],['key'=>'experience_count','label'=>'体验人次'],['key'=>'member_count','label'=>'成交人头'],['key'=>'consumption_amount','label'=>'消耗业绩'],['key'=>'labor_amount','label'=>'手工汇总'],['key'=>'sale_amount','label'=>'成交业绩'],
        ], ['month'=>88, 'store_summary'=>132, 'performance_type'=>106]);
        foreach ($columns as &$column) {
            if (!in_array((string)$column['key'], ['experience_count','member_count','consumption_amount','labor_amount','sale_amount'], true)) continue;
            $column['drilldown'] = ['report'=>'partner_item_detail', 'param_map'=>[
                'store_ids'=>'store_id', 'category_path'=>'category_path_snapshot', 'partner_name'=>'partner_name_snapshot',
            ]];
        }
        unset($column);
        return [
            'title'=>'合作方品项汇总','columns'=>$columns, 'records'=>$rows,'total'=>count($rows),'page'=>1,'page_size'=>count($rows),
            'table_layout'=>['fixed'=>true],
            'summary_row'=>$this->summaryRow($columns, [
                'month'=>'合计', 'experience_count'=>array_sum(array_column($rows, 'experience_count')),
                'member_count'=>'-', 'consumption_amount'=>$this->money(array_sum(array_column($rows, 'consumption_amount_cents'))),
                'labor_amount'=>$this->money(array_sum(array_column($rows, 'labor_amount_cents'))),
                'sale_amount'=>$this->money(array_sum(array_column($rows, 'sale_amount_cents'))),
            ]),
        ];
    }

    private function partnerItemDetail($storeId, array $range, array $input): array
    {
        $query = $this->operationSaleQuery($storeId, $range, $input)->whereRaw("COALESCE(c.partner_name_snapshot,d.partner_name_snapshot)<>''");
        $total = (int)(clone $query)->count('s.id');
        $summary = (clone $query)->fieldRaw("COUNT(DISTINCT NULLIF(s.member_id,0)) AS member_count,SUM(s.quantity) AS quantity,SUM(COALESCE(c.sale_amount_cents,s.sale_amount_cents)) AS sale_amount_cents,SUM((SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective')) AS consumption_amount_cents,SUM((SELECT COALESCE(SUM(pf.labor_fee_amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective')) AS labor_amount_cents")->find() ?: [];
        $rows = (clone $query)->leftJoin('user u', 'u.uid = s.member_id')
            ->fieldRaw("s.store_id,s.business_date,s.organization_name_snapshot,s.store_name_snapshot,s.order_no_snapshot,s.member_id,s.member_name_snapshot,u.phone AS member_phone,s.item_name_snapshot,s.source_type,s.quantity,COALESCE(c.sale_amount_cents,s.sale_amount_cents) AS sale_amount_cents,COALESCE(c.product_type_snapshot,d.product_type_snapshot) AS product_type_snapshot,COALESCE(c.category_path_snapshot,d.category_path_snapshot) AS category_path_snapshot,COALESCE(c.partner_name_snapshot,d.partner_name_snapshot) AS partner_name_snapshot,d.is_experience,s.source_line_id,s.fact_id,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective') AS consumption_amount_cents,(SELECT COALESCE(SUM(pf.labor_fee_amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective') AS labor_amount_cents,COALESCE(c.cash_performance_amount_cents,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='actual_performance_recorded' AND pf.status='effective')) AS actual_performance_cents")
            ->order('s.business_date','desc')->order('s.id','desc')->page($this->page($input),$this->limit($input))->select()->toArray();
        $rows = $this->attachAnnotations($rows, 'partner_item_detail', $storeId);
        foreach ($rows as &$row) { $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']); $row['actual_performance'] = $this->money((int)$row['actual_performance_cents']); $row['consumption_amount'] = $this->money((int)$row['consumption_amount_cents']); $row['labor_amount'] = $this->money((int)$row['labor_amount_cents']); $row['experience'] = (int)$row['is_experience'] === 1 ? '是' : '否'; }
        unset($row);
        foreach ($rows as &$row) { $row['division_name'] = (string)($row['organization_name_snapshot'] ?? ''); $row['member_phone'] = (string)($row['member_phone'] ?? ''); $row['performance_type'] = strtok((string)$row['category_path_snapshot'], '/'); $row['experience_project'] = $row['experience']; $row['deal_headcount'] = (int)($row['member_id'] ?? 0) > 0 ? 1 : 0; $row['deal_project'] = $row['item_name_snapshot']; $row['consumption'] = $row['consumption_amount'] ?? '0'; $row['consumption_amount'] = $row['consumption_amount'] ?? '0'; $row['labor_fee'] = $row['labor_amount'] ?? '0'; $row['deal_amount'] = $row['sale_amount']; $row['partner_label'] = $row['partner_name_snapshot']; }
        unset($row);
        $columns = $this->fixedColumns([
            ['key'=>'division_name','label'=>'分公司'],['key'=>'store_name_snapshot','label'=>'门店'],['key'=>'member_name_snapshot','label'=>'会员'],['key'=>'member_phone','label'=>'手机'],['key'=>'performance_type','label'=>'业绩类型'],['key'=>'business_date','label'=>'日期'],['key'=>'experience_project','label'=>'体验项目'],['key'=>'medical_elevation','label'=>'私美复诊'],['key'=>'medical_followup','label'=>'私美类型'],['key'=>'deal_headcount','label'=>'成交人头'],['key'=>'deal_project','label'=>'成交项目'],['key'=>'consumption','label'=>'消耗'],['key'=>'consumption_amount','label'=>'消耗金额'],['key'=>'labor_fee','label'=>'手工费'],['key'=>'quantity','label'=>'数量'],['key'=>'deal_amount','label'=>'成交金额'],['key'=>'expert_name','label'=>'专家姓名'],['key'=>'partner_label','label'=>'合作方'],['key'=>'remark','label'=>'备注'],
        ], ['store_name_snapshot'=>126, 'member_name_snapshot'=>88, 'member_phone'=>116, 'performance_type'=>96, 'business_date'=>104]);
        return [
            'title'=>'合作方品项明细','columns'=>$columns, 'records'=>$rows,'total'=>$total,'page'=>$this->page($input),'page_size'=>$this->limit($input),
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
        $total = (int)(clone $query)->count('s.id');
        $partnerDefinitions = $this->partnerPerformanceDefinitions($storeId);
        $summaryValues = $this->memberConsumptionSummaryValues($query, $storeId, $partnerDefinitions);
        $rows = (clone $query)->fieldRaw("s.fact_id,s.tenant_id,s.store_id,s.business_date,s.store_name_snapshot,s.order_no_snapshot,s.order_id,s.source_line_id,s.member_id,s.member_name_snapshot,s.item_name_snapshot,d.category_path_snapshot,d.product_type_snapshot,s.source_type,s.quantity,s.sale_amount_cents,d.partner_category_id_snapshot,d.partner_category_path_snapshot,d.partner_share_amount_cents,s.business_source_label_snapshot,s.source_attribution_type_snapshot,d.is_experience")->order('s.business_date','desc')->order('s.id','desc')->page($this->page($input),$this->limit($input))->select()->toArray();
        $this->decorateMemberConsumptionRows($rows, $storeId, $partnerDefinitions, $this->cardPartnerSharesBySaleFact($rows, $storeId));
        foreach ($rows as &$row) {
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
        $rows = $this->attachAnnotations($rows, 'member_consumption_detail', $storeId);
        $columns = [
            ['key'=>'business_date','label'=>'日期'], ['key'=>'consume_type','label'=>'消费类型'], ['key'=>'member_name','label'=>'姓名'],
            ['key'=>'consumption_detail','label'=>'消费明细'], ['key'=>'category','label'=>'分类'], ['key'=>'sales_manager_name','label'=>'销售经理'],
            ['key'=>'guide_round_no','label'=>'导购业绩次数'], ['key'=>'guide_names','label'=>'导购人员'], ['key'=>'salesperson_names','label'=>'销售'],
            ['key'=>'member_source','label'=>'会员来源'],
        ];
        foreach ($this->paymentMethodDefinitions() as $method) $columns[] = ['key'=>'payment_'.$method['code'],'label'=>$method['label'],'group_label'=>'支付现金业绩方式'];
        $columns[] = ['key'=>'receipt_total','label'=>'收款总金额','group_label'=>'支付现金业绩方式'];
        foreach ($partnerDefinitions as $definition) $columns[] = ['key'=>$definition['key'],'label'=>$definition['label'],'group_label'=>'合作方分成业绩'];
        foreach ([['partner_performance','合作方业绩'],['actual_cash_performance','实际现金业绩'],['experience_cash','体验现金业绩'],['experience_payment_method','体验现金业绩支付方式']] as $column) $columns[] = ['key'=>$column[0],'label'=>$column[1]];
        $columns = $this->fixedColumns($columns, [
            'business_date'=>104, 'consume_type'=>88, 'member_name'=>88,
            'consumption_detail'=>138, 'category'=>116,
        ]);
        return [
            'title'=>'会员消费明细','columns'=>$columns,'column_groups'=>$this->columnGroups($columns),'records'=>$rows,'total'=>$total,'page'=>$this->page($input),'page_size'=>$this->limit($input),
            'table_layout'=>['fixed'=>true],
            'summary_row'=>$this->summaryRow($columns, array_merge(['business_date'=>'合计'], $summaryValues)),
        ];
    }

    private function storeItemAnalysis($storeId, array $range, array $input): array
    {
        $today = ['start' => $range['end'], 'end' => $range['end']];
        $cumulative = ['start' => self::COVERAGE_START, 'end' => $range['end']];
        // Header categories come from the current product-category configuration,
        // never from whichever facts happened to occur in the selected period.
        $definitions = $this->itemAnalysisCategoryDefinitions($storeId);
        $period = $this->itemAnalysisMetricRows($storeId, $range, $input, $definitions);
        $daily = $this->itemAnalysisMetricRows($storeId, $today, $input, $definitions);
        $total = $this->itemAnalysisMetricRows($storeId, $cumulative, $input, $definitions);
        $rows = [];
        foreach ([$period, $daily, $total] as $metrics) {
            foreach ($metrics['stores'] as $storeIdKey => $store) {
                if (!isset($rows[$storeIdKey])) {
                    $rows[$storeIdKey] = ['store_name' => $store['store_name']];
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

        $columns = [[
            'key' => 'store_name', 'label' => '门店',
            'logic' => '当前数据权限和筛选范围内的门店名称。',
        ]];
        $groups = [['label' => '门店', 'column_keys' => ['store_name'], 'rowspan' => 2, 'tone' => 'basic']];
        foreach ([
            ['cash', '现金业绩', '成功记账收款按销售明细分摊后的金额；不含余额支付和欠款。', 'payment'],
            ['share', '分成业绩', '各分类现金业绩按结账时冻结的合作方默认比例计算后的合计。', 'partner'],
            ['actual', '实际业绩', '现金业绩扣除分成业绩后的金额。', 'result'],
            ['consume', '消耗业绩', '实际完成服务或核销后形成的消耗业绩。', 'consumption'],
        ] as $summary) {
            [$metric, $label, $logic, $tone] = $summary;
            $keys = [];
            foreach ([['today', '当日'], ['cumulative', '累计']] as $periodLabel) {
                $key = 'item_analysis_' . $metric . '_' . $periodLabel[0];
                $keys[] = $key;
                $timeLogic = $periodLabel[0] === 'today'
                    ? '统计查询截止日当天。'
                    : '从 V3 报表覆盖起始日累计至查询截止日。';
                $columns[] = ['key' => $key, 'label' => $periodLabel[1], 'logic' => $logic . $timeLogic];
            }
            $groups[] = ['label' => $label, 'column_keys' => $keys, 'tone' => $tone];
        }
        foreach ($definitions as $definition) {
            $keys = [];
            foreach ([
                ['cash', '现金业绩', '本分类在所选日期范围内成功记账收款按销售明细分摊后的金额。'],
                ['share', '现金分成业绩', '本分类现金业绩按结账时冻结的合作方默认比例计算后的金额。'],
                ['consume', '消耗业绩', '本分类在所选日期范围内实际完成服务或核销后形成的消耗业绩。'],
            ] as $metric) {
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
        $columns = $this->fixedColumns($columns, ['store_name'=>140]);
        return [
            'title' => '门店品项分析', 'columns' => $columns,
            'column_groups' => $groups, 'records' => array_values($rows),
            'total' => count($rows), 'page' => 1, 'page_size' => count($rows),
            'table_layout' => ['fixed'=>true],
            'summary_row' => $this->summaryRow($columns, $summaryValues),
        ];
    }

    /** @return array{stores:array<int,array>,categories:array<int,array>} */
    private function itemAnalysisMetricRows($storeId, array $range, array $input, array $definitions): array
    {
        $stores = [];
        $categories = [];
        $cashRows = $this->operationSaleQuery($storeId, $range, $input)
            ->fieldRaw("s.store_id,s.store_name_snapshot,COALESCE(c.partner_category_id_snapshot,d.partner_category_id_snapshot,0) AS partner_category_id_snapshot,COALESCE(c.partner_category_path_snapshot,d.partner_category_path_snapshot,c.category_path_snapshot,d.category_path_snapshot) AS category_path_snapshot,COALESCE(c.cash_performance_amount_cents,d.cash_performance_amount_cents,0) AS cash_cents,COALESCE(c.partner_share_amount_cents,d.partner_share_amount_cents,0) AS share_cents")
            ->select()->toArray();
        foreach ($cashRows as $entry) {
            $storeKey = (int)$entry['store_id'];
            $this->itemAnalysisStore($stores, $storeKey, (string)$entry['store_name_snapshot']);
            $stores[$storeKey]['cash_cents'] += (int)$entry['cash_cents'];
            $stores[$storeKey]['share_cents'] += (int)$entry['share_cents'];
            $category = $this->itemAnalysisConfiguredCategory(
                $definitions,
                (int)($entry['partner_category_id_snapshot'] ?? 0),
                (string)$entry['category_path_snapshot']
            );
            if ($category === null) continue;
            $this->itemAnalysisCategoryAmount($categories, $storeKey, $category);
            $categories[$storeKey][$category['id']]['cash_cents'] += (int)$entry['cash_cents'];
            $categories[$storeKey][$category['id']]['share_cents'] += (int)$entry['share_cents'];
        }
        $consume = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_entitlement_service_fact es', 'es.tenant_id=p.tenant_id AND es.checkout_request_id=p.checkout_request_id AND es.source_line_id=p.source_line_id')
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('p.performance_type', 'consumption_performance_recorded');
        if (is_array($storeId)) {
            $consume->whereIn('p.store_id', array_values(array_unique(array_map('intval', $storeId))));
        } else {
            $consume->where('p.store_id', (int)$storeId);
        }
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($consume, 'p.checkout_request_id', $this->participantEmployeeId);
        }
        $this->itemAnalysisConsumptionFilters($consume, $input);
        foreach ($consume->fieldRaw("p.store_id,p.store_name_snapshot,es.project_category_id_snapshot,COALESCE(NULLIF(es.project_category_path_snapshot,''),es.project_category_name_snapshot) AS category_path_snapshot,SUM(p.amount_cents) AS consume_cents")
            ->group('p.store_id,p.store_name_snapshot,es.project_category_id_snapshot,es.project_category_path_snapshot,es.project_category_name_snapshot')->select()->toArray() as $entry) {
            $storeKey = (int)$entry['store_id'];
            $this->itemAnalysisStore($stores, $storeKey, (string)$entry['store_name_snapshot']);
            $stores[$storeKey]['consume_cents'] += (int)$entry['consume_cents'];
            $category = $this->itemAnalysisConfiguredCategory(
                $definitions,
                (int)($entry['project_category_id_snapshot'] ?? 0),
                (string)$entry['category_path_snapshot']
            );
            if ($category === null) continue;
            $this->itemAnalysisCategoryAmount($categories, $storeKey, $category);
            $categories[$storeKey][$category['id']]['consume_cents'] += (int)$entry['consume_cents'];
        }
        return compact('stores', 'categories');
    }

    private function itemAnalysisConsumptionFilters($query, array $input): void
    {
        $categoryId = (int)($input['category_id'] ?? 0);
        $path = trim((string)($input['category_path'] ?? ''));
        $type = trim((string)($input['product_type'] ?? ''));
        if ($categoryId > 0) $query->where('es.project_category_id_snapshot', $categoryId);
        if ($path !== '') $query->whereLike('es.project_category_path_snapshot', $path . '%');
        if ($type !== '' && $type !== 'project') $query->whereRaw('1=0');
    }

    private function itemAnalysisStore(array &$stores, int $storeId, string $name): void
    {
        if (!isset($stores[$storeId])) {
            $stores[$storeId] = ['store_name' => $name, 'cash_cents' => 0, 'share_cents' => 0, 'consume_cents' => 0];
        }
    }

    private function itemAnalysisCategoryAmount(array &$categories, int $storeId, array $category): void
    {
        if (!isset($categories[$storeId][$category['id']])) {
            $categories[$storeId][$category['id']] = ['label' => $category['label'], 'cash_cents' => 0, 'share_cents' => 0, 'consume_cents' => 0];
        }
    }

    /**
     * Facts keep their own category snapshots.  Match the frozen effective
     * category ID first; a frozen project below a configured second level then
     * falls back to its frozen path, never to a current product category tree.
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
        if ($path === '') return null;

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
        if ($matched === null) return null;
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
        $definitions = [];
        foreach ($this->partnerPerformanceDefinitions($storeId) as $partnerDefinition) {
            $categoryId = (int)($partnerDefinition['category_id'] ?? 0);
            $categoryPath = $this->itemAnalysisCategoryPath((string)($partnerDefinition['category_path'] ?? ''));
            if ($categoryId <= 0 || $categoryPath === '') continue;
            $definitions[(string)$categoryId] = [
                'key' => 'item_analysis_category_' . $categoryId,
                'label' => $categoryPath,
                'category_id' => $categoryId,
                'category_path' => $categoryPath,
            ];
        }
        return $definitions;
    }

    private function craftsmanConsumption($storeId, array $range, array $input): array
    {
        $query = $this->withStoreScope(Db::name('cashier_v3_performance_fact'), $storeId)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective')->where('performance_type','labor_performance_allocated')->where('employee_id','>',0);
        if ($this->participantEmployeeId > 0) $query->where('employee_id', $this->participantEmployeeId);
        if ((int)($input['craftsman_id'] ?? 0) > 0) $query->where('employee_id',(int)$input['craftsman_id']);
        // This report is a craftsman projection. "消耗" is the craftsman's
        // allocated labor performance; "手工" is the independent fee saved
        // with that allocation, never a second use of amount_cents.
        $raw = $query->fieldRaw("store_id,MAX(store_name_snapshot) AS store_name,MAX(employee_name_snapshot) AS employee_name,employee_id,DAY(business_date) AS day_no,SUM(CASE WHEN performance_type='labor_performance_allocated' THEN amount_cents ELSE 0 END) AS consumption_amount_cents,SUM(CASE WHEN performance_type='labor_performance_allocated' THEN labor_fee_amount_cents ELSE 0 END) AS labor_amount_cents,MAX(rule_code_snapshot) AS labor_rule_snapshot")->group('store_id,employee_id,day_no')->order('employee_name','asc')->select()->toArray();
        $by = []; $summaryConsume = []; $summaryLabor = [];
        foreach ($raw as $row) {
            $key = (int)$row['store_id'].'|'.(int)$row['employee_id'];
            if (!isset($by[$key])) $by[$key] = ['store_name'=>(string)$row['store_name'],'employee_name'=>(string)$row['employee_name'],'employee_id'=>(int)$row['employee_id']];
            $day = (int)$row['day_no'];
            $by[$key]['day_'.$day.'_consume'] = $this->money((int)$row['consumption_amount_cents']);
            $by[$key]['day_'.$day.'_labor'] = $this->money((int)$row['labor_amount_cents']);
            $by[$key]['total_consume_cents'] = (int)($by[$key]['total_consume_cents'] ?? 0) + (int)$row['consumption_amount_cents'];
            $by[$key]['total_labor_cents'] = (int)($by[$key]['total_labor_cents'] ?? 0) + (int)$row['labor_amount_cents'];
            $summaryConsume[$day] = (int)($summaryConsume[$day] ?? 0) + (int)$row['consumption_amount_cents'];
            $summaryLabor[$day] = (int)($summaryLabor[$day] ?? 0) + (int)$row['labor_amount_cents'];
        }
        $columns = [['key'=>'employee_name','label'=>'手艺人']];
        foreach (range(1,31) as $day) { $columns[]=['key'=>'day_'.$day.'_consume','label'=>$day.'日消耗','group_label'=>$day.'日']; $columns[]=['key'=>'day_'.$day.'_labor','label'=>$day.'日手工','group_label'=>$day.'日']; }
        $columns[]=['key'=>'total_consume','label'=>'合计消耗']; $columns[]=['key'=>'total_labor','label'=>'合计手工'];
        foreach ($by as &$row) { $row['total_consume']=$this->money((int)($row['total_consume_cents']??0)); $row['total_labor']=$this->money((int)($row['total_labor_cents']??0)); }
        unset($row);
        $summaryValues = ['employee_name'=>'合计'];
        foreach (range(1, 31) as $day) {
            $summaryValues['day_'.$day.'_consume'] = $this->money((int)($summaryConsume[$day] ?? 0));
            $summaryValues['day_'.$day.'_labor'] = $this->money((int)($summaryLabor[$day] ?? 0));
        }
        $summaryValues['total_consume'] = $this->money(array_sum($summaryConsume));
        $summaryValues['total_labor'] = $this->money(array_sum($summaryLabor));
        $columns = $this->fixedColumns($columns, ['employee_name'=>120]);
        return [
            'title'=>'门店手艺人消耗','columns'=>$columns,'column_groups'=>$this->columnGroups($columns),'records'=>array_values($by),'total'=>count($by),'page'=>1,'page_size'=>count($by),
            'table_layout'=>['fixed'=>true], 'summary_row'=>$this->summaryRow($columns, $summaryValues),
        ];
    }

    private function salespersonPerformance($storeId, array $range, array $input): array
    {
        $query = $this->withStoreScope(Db::name('cashier_v3_performance_fact'), $storeId)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective')->where('performance_type','sales_performance_allocated')->where('employee_id','>',0);
        if ($this->participantEmployeeId > 0) $query->where('employee_id', $this->participantEmployeeId);
        if ((int)($input['salesperson_id'] ?? 0) > 0) $query->where('employee_id',(int)$input['salesperson_id']);
        $raw = $query->fieldRaw("store_id,MAX(store_name_snapshot) AS store_name,MAX(employee_name_snapshot) AS employee_name,employee_id,DAY(business_date) AS day_no,SUM(amount_cents) AS amount_cents")->group('store_id,employee_id,day_no')->order('employee_name','asc')->select()->toArray();
        $by=[]; $summaryPerformance=[];
        foreach($raw as $row){$key=(int)$row['store_id'].'|'.(int)$row['employee_id'];if(!isset($by[$key]))$by[$key]=['store_name'=>(string)$row['store_name'],'employee_name'=>(string)$row['employee_name'],'employee_id'=>(int)$row['employee_id']];$day=(int)$row['day_no'];$by[$key]['day_'.$day.'_performance']=$this->money((int)$row['amount_cents']);$by[$key]['total_performance_cents']=(int)($by[$key]['total_performance_cents']??0)+(int)$row['amount_cents'];$summaryPerformance[$day]=(int)($summaryPerformance[$day]??0)+(int)$row['amount_cents'];}
        // 销售人只产生销售业绩事实，不产生手艺人手工费；日期直接作为列名展示，
        // 不再返回二级日期分组表头或无意义的手工列。
        $columns=[['key'=>'employee_name','label'=>'销售人']]; foreach(range(1,31) as $day){$columns[]=['key'=>'day_'.$day.'_performance','label'=>$day.'日业绩'];} $columns[]=['key'=>'total_performance','label'=>'合计业绩'];
        foreach($by as &$row){$row['total_performance']=$this->money((int)($row['total_performance_cents']??0));} unset($row);
        $summaryValues = ['employee_name'=>'合计']; foreach(range(1,31) as $day)$summaryValues['day_'.$day.'_performance']=$this->money((int)($summaryPerformance[$day]??0)); $summaryValues['total_performance']=$this->money(array_sum($summaryPerformance));
        $columns = $this->fixedColumns($columns, ['employee_name'=>120]);
        return [
            'title'=>'门店销售人业绩','columns'=>$columns,'records'=>array_values($by),'total'=>count($by),'page'=>1,'page_size'=>count($by),
            'table_layout'=>['fixed'=>true], 'summary_row'=>$this->summaryRow($columns, $summaryValues),
        ];
    }

    private function overview($storeId, array $range, array $input)
    {
        $metrics = [
            'sales_amount' => ['销售额', 'cashier_v3_sale_fact', 'sale_amount_cents', []],
            'cash_performance' => ['现金业绩', 'cashier_v3_payment_fact', 'amount_cents', []],
            'actual_performance' => ['实际业绩', 'cashier_v3_performance_fact', 'amount_cents', ['performance_type' => 'actual_performance_recorded']],
            'balance_deduction_amount' => ['余额扣款', 'cashier_v3_balance_fact', '-(principal_delta_cents + bonus_delta_cents)', ['balance_change_type' => 'order_payment']],
            'recharge_amount' => ['充值', 'cashier_v3_balance_fact', 'principal_delta_cents', ['balance_change_type' => 'recharge_credit']],
            'service_count' => ['服务次数', 'cashier_v3_entitlement_service_fact', 'quantity', ['service_status' => 'completed']],
            'consumption_performance' => ['消耗业绩', 'cashier_v3_performance_fact', 'amount_cents', ['performance_type' => 'consumption_performance_recorded']],
            'labor_performance' => ['劳动业绩', 'cashier_v3_performance_fact', 'amount_cents', ['performance_type' => 'labor_performance_allocated']],
        ];
        $cards = [];
        foreach ($metrics as $code => $item) {
            $value = $this->sum($item[1], $item[2], $storeId, $range, $item[3]);
            $cards[] = ['code' => $code, 'name' => $item[0], 'value' => $code === 'service_count' ? $value : $this->money($value), 'unit' => $code === 'service_count' ? '次' : '元'];
        }
        $trendCode = in_array(($input['metric'] ?? ''), array_keys($metrics), true) ? $input['metric'] : 'cash_performance';
        $trend = $this->trend($metrics[$trendCode], $storeId, $range, $trendCode === 'service_count');
        $ranking = $this->withStoreScope(Db::name('cashier_v3_performance_fact'), $storeId)->whereBetween('business_date', [$range['start'], $range['end']])
            ->where('status', 'effective')->where('performance_type', 'labor_performance_allocated')->where('employee_id', '>', 0)
            ->fieldRaw('employee_id, MAX(employee_name_snapshot) AS employee_name, SUM(amount_cents) AS amount_cents')
            ->group('employee_id')->orderRaw('amount_cents DESC')->limit(20)->select()->toArray();
        foreach ($ranking as &$row) $row['amount'] = $this->money((int)$row['amount_cents']);
        unset($row);
        return ['title' => '经营总览', 'cards' => $cards, 'trend' => ['metric' => $trendCode, 'points' => $trend], 'ranking' => $ranking, 'columns' => [], 'records' => []];
    }

    private function sales($storeId, array $range, array $input)
    {
        $dataset = ($input['dataset'] ?? 'sale') === 'payment' ? 'payment' : 'sale';
        $table = $dataset === 'payment' ? 'cashier_v3_payment_fact' : 'cashier_v3_sale_fact';
        $query = $this->withStoreScope(Db::name($table), $storeId)->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective');
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
        $total = (int)(clone $serviceQuery)->count();
        $records = (clone $serviceQuery)->order('business_date', 'desc')->order('id', 'desc')->page($this->page($input), $this->limit($input))->select()->toArray();
        $performance = $this->withStoreScope(Db::name('cashier_v3_performance_fact'), $storeId)->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective')->whereIn('performance_type', ['consumption_performance_recorded', 'labor_performance_allocated'])
            ->fieldRaw("performance_type, SUM(amount_cents) AS amount_cents")->group('performance_type')->select()->toArray();
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
            ->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective')
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
            $sales = $orders ? $this->withStoreScope(Db::name('cashier_v3_sale_fact'), $storeId)->whereIn('order_id', $orders)->where('status','effective')->where('source_type','card')->sum('sale_amount_cents') : 0;
            $receipts = $orders ? $this->withStoreScope(Db::name('cashier_v3_payment_fact'), $storeId)->whereIn('order_id', $orders)->where('status','effective')->sum('amount_cents') : 0;
            $group['order_count'] = count($orders); $group['sale_amount'] = $this->money((int)$sales); $group['receipt_amount'] = $this->money((int)$receipts);
            $group['average_order_amount'] = $group['order_count'] ? $this->money((int)$sales / $group['order_count']) : '0'; unset($group['order_ids']);
        }
        unset($group); $records = array_values($groups); usort($records, function ($a, $b) { return (float)$b['sale_amount'] <=> (float)$a['sale_amount']; });
        return ['title' => '拓客渠道（首次疗程卡来源）', 'columns' => [['key'=>'channel_name','label'=>'首次疗程卡来源'],['key'=>'source_type','label'=>'来源类型'],['key'=>'member_count','label'=>'成交顾客'],['key'=>'order_count','label'=>'首次疗程卡单数'],['key'=>'sale_amount','label'=>'成交金额'],['key'=>'receipt_amount','label'=>'收款金额'],['key'=>'average_order_amount','label'=>'成交单产']], 'records' => $records, 'total' => count($records), 'page' => 1, 'page_size' => count($records)];
    }

    private function sum($table, $amount, $storeId, array $range, array $conditions)
    {
        $query = $this->withStoreScope(Db::name($table), $storeId)->whereBetween('business_date', [$range['start'], $range['end']]);
        foreach ($conditions as $key => $value) $query->where($key, $value);
        if ($table === 'cashier_v3_entitlement_service_fact') $query->where('service_status', 'completed'); else $query->where('status', 'effective');
        $row = $query->fieldRaw('COALESCE(SUM(' . $amount . '),0) AS amount')->find();
        return (int)($row['amount'] ?? 0);
    }

    private function trend(array $metric, $storeId, array $range, $count)
    {
        $table = $metric[1]; $amount = $metric[2]; $conditions = $metric[3];
        $query = $this->withStoreScope(Db::name($table), $storeId)->whereBetween('business_date', [$range['start'], $range['end']]);
        foreach ($conditions as $key => $value) $query->where($key, $value);
        if ($table === 'cashier_v3_entitlement_service_fact') $query->where('service_status', 'completed'); else $query->where('status', 'effective');
        $rows = $query->fieldRaw('business_date, SUM(' . $amount . ') AS amount')->group('business_date')->order('business_date', 'asc')->select()->toArray();
        foreach ($rows as &$row) $row['value'] = $count ? (int)$row['amount'] : $this->money((int)$row['amount']);
        unset($row);
        return $rows;
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
        foreach ($conditions as $key => $value) $query->where($key, $value);
        $rows = $query->fieldRaw('member_id, SUM(' . $amount . ') amount')->group('member_id')->select()->toArray();
        $result = []; foreach ($rows as $row) $result[(int)$row['member_id']] = (int)$row['amount'];
        return $result;
    }

    private function activeMembers($storeId, array $range, array $memberIds): array
    {
        $rows = $this->withStoreScope(Db::name('cashier_v3_entitlement_service_fact'), $storeId)->whereIn('member_id', $memberIds)
            ->whereBetween('business_date', [$range['start'], $range['end']])->where('service_status', 'completed')->field('member_id')->group('member_id')->select()->toArray();
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
    private function attachAnnotations(array $rows, string $reportCode, $storeId): array
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
            foreach ($candidates as $candidate) foreach ((array)($byKey[$candidate] ?? []) as $field => $value) $row[$field] = $value;
            foreach (['medical_elevation','medical_followup','expert_name','remark','walk_in_manual_count','refund_headcount_manual','manual_cash_amount','experience_cash','experience_payment_method'] as $field) {
                if (!array_key_exists($field, $row)) $row[$field] = '';
                if (!array_key_exists($field . '_version', $row)) $row[$field . '_version'] = 0;
            }
        }
        unset($row);
        return $rows;
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
        $paymentBySaleFact = [];
        if ($saleFactIds) {
            $payments = $this->withStoreScope(Db::name('cashier_v3_payment_sale_allocation_fact'), $storeId)
                ->whereIn('sale_fact_id', $saleFactIds)->where('status', 'effective')
                ->field('sale_fact_id,payment_method,SUM(amount_cents) amount_cents')->group('sale_fact_id,payment_method')->select()->toArray();
            foreach ($payments as $payment) $paymentBySaleFact[(string)$payment['sale_fact_id']][(string)$payment['payment_method']] = (int)$payment['amount_cents'];
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
            $row['guide_round_no'] = $guidesForOrder ? (int)min(array_map(static function ($item) { return (int)$item['guide_round_no']; }, $guidesForOrder)) : '';
            $row['guide_names'] = implode('、', array_values(array_unique(array_map(static function ($item) { return (string)$item['guide_employee_name_snapshot']; }, $guidesForOrder))));
            $row['sales_manager_name'] = implode('、', array_values(array_unique(array_map(static function ($item) { return (string)$item['sales_manager_name_snapshot']; }, $managerByOrder[$order] ?? []))));
            $row['salesperson_names'] = '';
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
            if (!isset($widths[$key])) continue;
            $column['fixed'] = 'left';
            $column['fixed_width'] = (int)$widths[$key];
        }
        unset($column);
        return $columns;
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
    private function money($cents) { return (string)intdiv((int)$cents, 100); }
    private function saleColumns() { return [['key'=>'business_date','label'=>'业务日期'],['key'=>'order_no_snapshot','label'=>'订单号'],['key'=>'member_name_snapshot','label'=>'顾客'],['key'=>'item_name_snapshot','label'=>'品项'],['key'=>'quantity','label'=>'数量'],['key'=>'business_source_label_snapshot','label'=>'结账来源'],['key'=>'amount','label'=>'销售额']]; }
    private function paymentColumns() { return [['key'=>'business_date','label'=>'业务日期'],['key'=>'order_no_snapshot','label'=>'订单号'],['key'=>'member_name_snapshot','label'=>'顾客'],['key'=>'payment_method','label'=>'收款方式'],['key'=>'business_source_label_snapshot','label'=>'结账来源'],['key'=>'amount','label'=>'收款金额']]; }
    private function serviceColumns() { return [['key'=>'business_date','label'=>'服务日期'],['key'=>'document_no_snapshot','label'=>'服务单号'],['key'=>'member_name_snapshot','label'=>'顾客'],['key'=>'project_name_snapshot','label'=>'项目'],['key'=>'quantity','label'=>'服务次数'],['key'=>'service_object','label'=>'服务对象']]; }
}
