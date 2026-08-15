<?php

namespace app\services\report;

use app\services\BaseServices;
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

    public function catalog()
    {
        return [
            ['folder' => '门店运营', 'code' => 'partner_item_summary', 'name' => '合作方品项汇总'],
            ['folder' => '门店运营', 'code' => 'partner_item_detail', 'name' => '合作方品项明细'],
            ['folder' => '门店运营', 'code' => 'member_consumption_detail', 'name' => '会员消费明细'],
            ['folder' => '门店运营', 'code' => 'store_item_analysis', 'name' => '门店品项分析'],
            ['folder' => '门店运营', 'code' => 'store_craftsman_consumption', 'name' => '门店手艺人消耗'],
            ['folder' => '门店运营', 'code' => 'store_salesperson_performance', 'name' => '门店销售人业绩'],
        ];
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
        $report = (string)($input['report'] ?? 'overview');
        $range = $this->range($input);
        $meta = $this->meta($range);
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

    private function operationSaleQuery($storeId, array $range, array $input)
    {
        $query = Db::name('cashier_v3_sale_fact')->alias('s')
            ->leftJoin('cashier_v3_report_sale_dimension_fact d', 'd.sale_fact_id=s.fact_id')
            ->whereBetween('s.business_date', [$range['start'], $range['end']])
            ->where('s.status', 'effective');
        if (is_array($storeId)) $query->whereIn('s.store_id', array_values(array_unique(array_map('intval', $storeId))));
        else $query->where('s.store_id', (int)$storeId);
        $this->operationFilters($query, $input);
        return $query;
    }

    private function operationFilters($query, array $input): void
    {
        if ((int)($input['category_id'] ?? 0) > 0) $query->where('d.category_id_snapshot', (int)$input['category_id']);
        if (($path = trim((string)($input['category_path'] ?? ''))) !== '') $query->whereLike('d.category_path_snapshot', $path . '%');
        if (($type = trim((string)($input['product_type'] ?? ''))) !== '') $query->where('d.product_type_snapshot', $type);
        if (($partner = trim((string)($input['partner_name'] ?? ''))) !== '') $query->where('d.partner_name_snapshot', $partner);
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
        $query = $this->operationSaleQuery($storeId, $range, $input)->where('d.partner_name_snapshot', '<>', '');
        $rows = $query->fieldRaw("DATE_FORMAT(s.business_date,'%Y-%m') AS month,MAX(s.organization_name_snapshot) AS division_name,s.store_id,MAX(s.store_name_snapshot) AS store_name,d.category_path_snapshot,SUBSTRING_INDEX(d.category_path_snapshot,'/',1) AS performance_type,SUM(s.sale_amount_cents) AS sale_amount_cents,SUM(CASE WHEN d.is_experience=1 THEN s.quantity ELSE 0 END) AS experience_count,COUNT(DISTINCT NULLIF(s.member_id,0)) AS member_count,SUM(s.quantity) AS quantity,SUM((SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective')) AS consumption_amount_cents,SUM((SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective')) AS labor_amount_cents,SUM((SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='actual_performance_recorded' AND pf.status='effective')) AS actual_performance_cents")
            ->group('month,s.store_id,d.partner_name_snapshot,d.category_path_snapshot')->orderRaw('month DESC,sale_amount_cents DESC')->select()->toArray();
        foreach ($rows as &$row) { $row['store_summary'] = (string)$row['store_name']; $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']); $row['actual_performance'] = $this->money((int)$row['actual_performance_cents']); $row['consumption_amount'] = $this->money((int)$row['consumption_amount_cents']); $row['labor_amount'] = $this->money((int)$row['labor_amount_cents']); $row['experience_count'] = (int)$row['experience_count']; $row['member_count'] = (int)$row['member_count']; }
        unset($row);
        return ['title'=>'合作方品项汇总','columns'=>[['key'=>'month','label'=>'月份'],['key'=>'division_name','label'=>'分公司'],['key'=>'store_summary','label'=>'门店汇总'],['key'=>'performance_type','label'=>'分类'],['key'=>'experience_count','label'=>'体验人次'],['key'=>'member_count','label'=>'成交人头'],['key'=>'consumption_amount','label'=>'消耗业绩'],['key'=>'labor_amount','label'=>'手工汇总'],['key'=>'sale_amount','label'=>'成交业绩']], 'records'=>$rows,'total'=>count($rows),'page'=>1,'page_size'=>count($rows)];
    }

    private function partnerItemDetail($storeId, array $range, array $input): array
    {
        $query = $this->operationSaleQuery($storeId, $range, $input)->where('d.partner_name_snapshot', '<>', '');
        $total = (int)(clone $query)->count('s.id');
        $rows = (clone $query)->leftJoin('user u', 'u.uid = s.member_id')
            ->fieldRaw("s.store_id,s.business_date,s.organization_name_snapshot,s.store_name_snapshot,s.order_no_snapshot,s.member_id,s.member_name_snapshot,u.phone AS member_phone,s.item_name_snapshot,s.source_type,s.quantity,s.sale_amount_cents,d.product_type_snapshot,d.category_path_snapshot,d.partner_name_snapshot,d.is_experience,s.source_line_id,s.fact_id,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective') AS consumption_amount_cents,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective') AS labor_amount_cents,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.source_line_id=s.source_line_id AND pf.performance_type='actual_performance_recorded' AND pf.status='effective') AS actual_performance_cents")
            ->order('s.business_date','desc')->order('s.id','desc')->page($this->page($input),$this->limit($input))->select()->toArray();
        $rows = $this->attachAnnotations($rows, 'partner_item_detail', $storeId);
        foreach ($rows as &$row) { $row['sale_amount'] = $this->money((int)$row['sale_amount_cents']); $row['actual_performance'] = $this->money((int)$row['actual_performance_cents']); $row['consumption_amount'] = $this->money((int)$row['consumption_amount_cents']); $row['labor_amount'] = $this->money((int)$row['labor_amount_cents']); $row['experience'] = (int)$row['is_experience'] === 1 ? '是' : '否'; }
        unset($row);
        foreach ($rows as &$row) { $row['division_name'] = (string)($row['organization_name_snapshot'] ?? ''); $row['member_phone'] = (string)($row['member_phone'] ?? ''); $row['performance_type'] = strtok((string)$row['category_path_snapshot'], '/'); $row['experience_project'] = $row['experience']; $row['deal_headcount'] = (int)($row['member_id'] ?? 0) > 0 ? 1 : 0; $row['deal_project'] = $row['item_name_snapshot']; $row['consumption'] = $row['consumption_amount'] ?? '0'; $row['consumption_amount'] = $row['consumption_amount'] ?? '0'; $row['labor_fee'] = $row['labor_amount'] ?? '0'; $row['deal_amount'] = $row['sale_amount']; $row['partner_label'] = $row['partner_name_snapshot']; }
        unset($row);
        return ['title'=>'合作方品项明细','columns'=>[['key'=>'division_name','label'=>'分公司'],['key'=>'store_name_snapshot','label'=>'门店'],['key'=>'member_name_snapshot','label'=>'会员'],['key'=>'member_phone','label'=>'手机'],['key'=>'performance_type','label'=>'业绩类型'],['key'=>'business_date','label'=>'日期'],['key'=>'experience_project','label'=>'体验项目'],['key'=>'medical_elevation','label'=>'私美复诊'],['key'=>'medical_followup','label'=>'私美类型'],['key'=>'deal_headcount','label'=>'成交人头'],['key'=>'deal_project','label'=>'成交项目'],['key'=>'consumption','label'=>'消耗'],['key'=>'consumption_amount','label'=>'消耗金额'],['key'=>'labor_fee','label'=>'手工费'],['key'=>'quantity','label'=>'数量'],['key'=>'deal_amount','label'=>'成交金额'],['key'=>'expert_name','label'=>'专家姓名'],['key'=>'partner_label','label'=>'合作方'],['key'=>'remark','label'=>'备注']], 'records'=>$rows,'total'=>$total,'page'=>$this->page($input),'page_size'=>$this->limit($input)];
    }

    private function memberConsumptionDetail($storeId, array $range, array $input): array
    {
        $query = $this->operationSaleQuery($storeId, $range, $input);
        $total = (int)(clone $query)->count('s.id');
        $rows = (clone $query)->fieldRaw("s.store_id,s.business_date,s.store_name_snapshot,s.order_no_snapshot,s.order_id,s.source_line_id,s.member_id,s.member_name_snapshot,s.item_name_snapshot,d.category_path_snapshot,d.product_type_snapshot,s.source_type,s.quantity,s.sale_amount_cents,s.business_source_label_snapshot,s.source_attribution_type_snapshot,d.is_experience")->order('s.business_date','desc')->order('s.id','desc')->page($this->page($input),$this->limit($input))->select()->toArray();
        $this->decorateMemberConsumptionRows($rows, $storeId);
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
        foreach ($this->fixedPartnerPerformanceDefinitions() as $definition) $columns[] = ['key'=>$definition['key'],'label'=>$definition['label'],'group_label'=>'销售人分配是合作方的现金业绩'];
        foreach ([['partner_performance','合作方业绩'],['actual_cash_performance','实际现金业绩'],['experience_cash','体验现金业绩'],['experience_payment_method','体验现金业绩支付方式']] as $column) $columns[] = ['key'=>$column[0],'label'=>$column[1]];
        return ['title'=>'会员消费明细','columns'=>$columns,'column_groups'=>$this->columnGroups($columns),'records'=>$rows,'total'=>$total,'page'=>$this->page($input),'page_size'=>$this->limit($input)];
    }

    private function storeItemAnalysis($storeId, array $range, array $input): array
    {
        $query = $this->operationSaleQuery($storeId, $range, $input);
        $rows = $query->fieldRaw('s.store_id,MAX(s.store_name_snapshot) AS store_name,SUM(s.sale_amount_cents) AS total_sale_amount_cents,SUM(CASE WHEN d.is_experience=0 OR d.is_experience IS NULL THEN s.sale_amount_cents ELSE 0 END) AS cash_amount_cents')->group('s.store_id')->orderRaw('total_sale_amount_cents DESC')->select()->toArray();
        $definitions = [
            ['key'=>'today_cash_performance','label'=>'当天现金业绩'],['key'=>'cumulative_cash_performance','label'=>'截止本日累计业绩'],['key'=>'home_cash_performance','label'=>'家居产品现金业绩'],['key'=>'beauty_card_cash_performance','label'=>'生美卡项现金业绩'],['key'=>'haomei_cash_performance','label'=>'昊美现金业绩'],['key'=>'haomei_partner_performance','label'=>'昊美现金分成业绩'],['key'=>'garden_cash_performance','label'=>'花园现金业绩'],['key'=>'garden_partner_performance','label'=>'花园现金分成业绩'],['key'=>'sixway_self_cash_performance','label'=>'六维自营现金业绩'],['key'=>'sixway_self_partner_performance','label'=>'六维自营现金分成业绩'],['key'=>'sixway_coop_cash_performance','label'=>'六维合作现金业绩'],['key'=>'sixway_coop_partner_performance','label'=>'六维合作现金分成业绩'],['key'=>'garden_cash_performance_2','label'=>'花园现金业绩'],['key'=>'garden_partner_performance_2','label'=>'花园现金分成业绩'],['key'=>'garden_ticket_cash_performance','label'=>'花园门票现金业绩'],['key'=>'garden_ticket_partner_performance','label'=>'花园门票现金业绩分成后'],['key'=>'kangmei_cash_performance','label'=>'康美现金业绩'],['key'=>'kangmei_partner_performance','label'=>'康美现金分成业绩'],['key'=>'huaxiangrong_cash_performance','label'=>'花享容现金业绩'],['key'=>'huaxiangrong_partner_performance','label'=>'花享容现金分成业绩'],['key'=>'beauty_card_consume_performance','label'=>'生美卡项消耗业绩'],['key'=>'sixway_consume_performance','label'=>'六维消耗业绩'],['key'=>'garden_consume_performance','label'=>'花园消耗业绩'],['key'=>'garden_ticket_consume_performance','label'=>'花园门票消耗业绩'],['key'=>'haomei_consume_performance','label'=>'昊美消耗业绩'],['key'=>'huaxiangrong_consume_performance','label'=>'花享容消耗业绩'],['key'=>'kangmei_consume_performance','label'=>'康美消耗业绩'],
        ];
        foreach ($rows as &$row) { $row['today_cash_performance'] = $this->money((int)$row['cash_amount_cents']); $row['cumulative_cash_performance'] = $row['today_cash_performance']; foreach ($definitions as $definition) if (!array_key_exists($definition['key'],$row)) $row[$definition['key']] = '0'; }
        unset($row);
        $columns=[['key'=>'store_name','label'=>'门店']]; foreach($definitions as $definition) $columns[]=['key'=>$definition['key'],'label'=>$definition['label']];
        return ['title'=>'门店品项分析','columns'=>$columns,'records'=>$rows,'total'=>count($rows),'page'=>1,'page_size'=>count($rows)];
    }

    private function craftsmanConsumption($storeId, array $range, array $input): array
    {
        $query = $this->withStoreScope(Db::name('cashier_v3_performance_fact'), $storeId)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective')->where('performance_type','labor_performance_allocated')->where('employee_id','>',0);
        if ((int)($input['craftsman_id'] ?? 0) > 0) $query->where('employee_id',(int)$input['craftsman_id']);
        // This report is a craftsman projection. "消耗" is the craftsman's
        // allocated labor performance; "手工" is the independent fee saved
        // with that allocation, never a second use of amount_cents.
        $raw = $query->fieldRaw("store_id,MAX(store_name_snapshot) AS store_name,MAX(employee_name_snapshot) AS employee_name,employee_id,DAY(business_date) AS day_no,SUM(CASE WHEN performance_type='labor_performance_allocated' THEN amount_cents ELSE 0 END) AS consumption_amount_cents,SUM(CASE WHEN performance_type='labor_performance_allocated' THEN labor_fee_amount_cents ELSE 0 END) AS labor_amount_cents,MAX(rule_code_snapshot) AS labor_rule_snapshot")->group('store_id,employee_id,day_no')->order('employee_name','asc')->select()->toArray();
        $by = [];
        foreach ($raw as $row) {
            $key = (int)$row['store_id'].'|'.(int)$row['employee_id'];
            if (!isset($by[$key])) $by[$key] = ['store_name'=>(string)$row['store_name'],'employee_name'=>(string)$row['employee_name'],'employee_id'=>(int)$row['employee_id']];
            $day = (int)$row['day_no'];
            $by[$key]['day_'.$day.'_consume'] = $this->money((int)$row['consumption_amount_cents']);
            $by[$key]['day_'.$day.'_labor'] = $this->money((int)$row['labor_amount_cents']);
            $by[$key]['total_consume'] = (int)($by[$key]['total_consume_cents'] ?? 0) + (int)$row['consumption_amount_cents'];
            $by[$key]['total_labor_cents'] = (int)($by[$key]['total_labor_cents'] ?? 0) + (int)$row['labor_amount_cents'];
        }
        $columns = [['key'=>'employee_name','label'=>'手艺人']];
        foreach (range(1,31) as $day) { $columns[]=['key'=>'day_'.$day.'_consume','label'=>$day.'日消耗','group_label'=>$day.'日']; $columns[]=['key'=>'day_'.$day.'_labor','label'=>$day.'日手工','group_label'=>$day.'日']; }
        $columns[]=['key'=>'total_consume','label'=>'合计消耗']; $columns[]=['key'=>'total_labor','label'=>'合计手工'];
        foreach ($by as &$row) { $row['total_consume']=$this->money((int)($row['total_consume_cents']??0)); $row['total_labor']=$this->money((int)($row['total_labor_cents']??0)); }
        unset($row);
        return ['title'=>'门店手艺人消耗','columns'=>$columns,'column_groups'=>$this->columnGroups($columns),'records'=>array_values($by),'total'=>count($by),'page'=>1,'page_size'=>count($by)];
    }

    private function salespersonPerformance($storeId, array $range, array $input): array
    {
        $query = $this->withStoreScope(Db::name('cashier_v3_performance_fact'), $storeId)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective')->where('performance_type','sales_performance_allocated')->where('employee_id','>',0);
        if ((int)($input['salesperson_id'] ?? 0) > 0) $query->where('employee_id',(int)$input['salesperson_id']);
        $raw = $query->fieldRaw("store_id,MAX(store_name_snapshot) AS store_name,MAX(employee_name_snapshot) AS employee_name,employee_id,DAY(business_date) AS day_no,SUM(amount_cents) AS amount_cents")->group('store_id,employee_id,day_no')->order('employee_name','asc')->select()->toArray();
        $by=[];
        foreach($raw as $row){$key=(int)$row['store_id'].'|'.(int)$row['employee_id'];if(!isset($by[$key]))$by[$key]=['store_name'=>(string)$row['store_name'],'employee_name'=>(string)$row['employee_name'],'employee_id'=>(int)$row['employee_id']];$day=(int)$row['day_no'];$by[$key]['day_'.$day.'_performance']=$this->money((int)$row['amount_cents']);$by[$key]['total_performance_cents']=(int)($by[$key]['total_performance_cents']??0)+(int)$row['amount_cents'];}
        // 销售人只产生销售业绩事实，不产生手艺人手工费；日期直接作为列名展示，
        // 不再返回二级日期分组表头或无意义的手工列。
        $columns=[['key'=>'employee_name','label'=>'销售人']]; foreach(range(1,31) as $day){$columns[]=['key'=>'day_'.$day.'_performance','label'=>$day.'日业绩'];} $columns[]=['key'=>'total_performance','label'=>'合计业绩'];
        foreach($by as &$row){$row['total_performance']=$this->money((int)($row['total_performance_cents']??0));} unset($row);
        return ['title'=>'门店销售人业绩','columns'=>$columns,'records'=>array_values($by),'total'=>count($by),'page'=>1,'page_size'=>count($by)];
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

    private function fixedPartnerPerformanceDefinitions(): array
    {
        return [
            ['key'=>'partner_sixway_self','label'=>'六维/自营分成业绩','path'=>'六维/自营'],
            ['key'=>'partner_sixway_coop','label'=>'六维/合作分成业绩','path'=>'六维/合作'],
            ['key'=>'partner_garden','label'=>'花园分成业绩','path'=>'花园'],
            ['key'=>'partner_garden_card','label'=>'花园卡项分成业绩','path'=>'花园/卡项'],
            ['key'=>'partner_haomei','label'=>'昊美分成业绩','path'=>'昊美'],
            ['key'=>'partner_huaxiangrong','label'=>'花享容分成业绩','path'=>'花享容'],
            ['key'=>'partner_garden_ticket','label'=>'花园/门票分成业绩','path'=>'花园/门票'],
            ['key'=>'partner_sleeping','label'=>'来源H/睡眠的分成业绩','path'=>'__source_h_sleeping'],
        ];
    }

    private function decorateMemberConsumptionRows(array &$rows, $storeId): void
    {
        $orderIds = array_values(array_unique(array_filter(array_map(static function ($row) { return trim((string)($row['order_id'] ?? '')); }, $rows))));
        $lineIds = array_values(array_unique(array_filter(array_map(static function ($row) { return trim((string)($row['source_line_id'] ?? '')); }, $rows))));
        $paymentByOrder = [];
        if ($orderIds) {
            $payments = $this->withStoreScope(Db::name('cashier_v3_payment_fact'), $storeId)->whereIn('order_id', $orderIds)->where('status','effective')
                ->field('order_id,payment_method,SUM(amount_cents) amount_cents')->group('order_id,payment_method')->select()->toArray();
            foreach ($payments as $payment) $paymentByOrder[(string)$payment['order_id']][(string)$payment['payment_method']] = (int)$payment['amount_cents'];
        }
        $partnerByLine = [];
        if ($lineIds) {
            $performance = $this->withStoreScope(Db::name('cashier_v3_performance_fact'), $storeId)->whereIn('source_line_id', $lineIds)->where('status','effective')->where('performance_type','sales_performance_allocated')->where('employee_type_snapshot','partner')
                ->field('source_line_id,employee_id,employee_name_snapshot,amount_cents')->select()->toArray();
            foreach ($performance as $fact) $partnerByLine[(string)$fact['source_line_id']][] = $fact;
        }
        $guideByOrder = $managerByOrder = [];
        if ($orderIds) {
            $guides = $this->withStoreScope(Db::name('cashier_v3_customer_guide_round_fact'), $storeId)->whereIn('order_id',$orderIds)->where('status','effective')->field('order_id,guide_round_no,guide_employee_name_snapshot')->order('guide_round_no','asc')->select()->toArray();
            foreach ($guides as $guide) $guideByOrder[(string)$guide['order_id']][] = $guide;
            $managers = $this->withStoreScope(Db::name('cashier_v3_sales_manager_fact'), $storeId)->whereIn('order_id',$orderIds)->where('status','effective')->field('order_id,sales_manager_name_snapshot')->select()->toArray();
            foreach ($managers as $manager) $managerByOrder[(string)$manager['order_id']][] = $manager;
        }
        $methods = $this->paymentMethodDefinitions();
        $partnerDefs = $this->fixedPartnerPerformanceDefinitions();
        foreach ($rows as &$row) {
            $order = (string)($row['order_id'] ?? '');
            $line = (string)($row['source_line_id'] ?? '');
            $payments = $paymentByOrder[$order] ?? [];
            $receiptTotal = 0; $paymentNames = [];
            foreach ($methods as $method) {
                $amount = (int)($payments[$method['code']] ?? 0);
                if ($method['code'] === 'other_collection') $receiptTotal = $amount;
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
            foreach ($partnerDefs as $definition) $row[$definition['key']] = '0';
            $row['partner_performance'] = '0'; $row['actual_cash_performance'] = $row['receipt_total'];
            foreach ($partnerByLine[$line] ?? [] as $fact) {
                $amount = (int)$fact['amount_cents']; $row['partner_performance'] = $this->money((int)round(((float)$row['partner_performance'] * 100) + $amount));
                $path = (string)($row['category_path_snapshot'] ?? '');
                foreach ($partnerDefs as $definition) {
                    if ($definition['path'] === '__source_h_sleeping') continue;
                    if ($path === $definition['path'] || str_starts_with($path, $definition['path'].'/')) $row[$definition['key']] = $this->money((int)round(((float)$row[$definition['key']] * 100) + $amount));
                }
            }
            if (stripos((string)($row['business_source_label_snapshot'] ?? ''), 'H沉睡唤醒') !== false) $row['partner_sleeping'] = $row['partner_performance'];
            $row['actual_cash_performance'] = $this->money(max(0, $receiptTotal - (int)round((float)$row['partner_performance'] * 100)));
        }
        unset($row);
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
