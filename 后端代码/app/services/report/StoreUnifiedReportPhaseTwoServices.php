<?php

namespace app\services\report;

use app\services\BaseServices;
use think\facade\Db;

/**
 * 第二阶段门店运营报表。
 *
 * 查询只读取 V3 不可变事实和受控报表补充记录。调用者必须传入已由
 * 控制器和组织权限服务裁剪后的门店 ID；本服务从不接受组织范围扩权。
 */
final class StoreUnifiedReportPhaseTwoServices extends BaseServices
{
    /** @var int 仅由认证后的门店报表控制器注入 */
    private $participantEmployeeId = 0;

    /** @var StoreUnifiedReportOrganizationDimensionServices|null */
    private $organizationDimensionServices;

    /** @var array{start:string,end:string} */
    private $activeRange = ['start' => '', 'end' => ''];

    /** @var array<string,mixed> */
    private $activeInput = [];

    private const REPORTS = [
        'market_performance', 'market_detail', 'member_visit_analysis',
        'member_visit_annual_summary', 'field_acquisition_detail',
        'field_acquisition_summary', 'cross_industry_customer_detail',
        'cross_industry_customer_summary', 'new_customer_analysis',
        'new_customer_analysis_summary', 'salesperson_large_order_statistics',
        'store_refund_ledger',
    ];

    public static function catalogEntries(): array
    {
        $names = [
            'market_performance' => '市场业绩表',
            'market_detail' => '市场明细表',
            'member_visit_analysis' => '会员进店分析表',
            'member_visit_annual_summary' => '会员进店年度汇总表',
            'field_acquisition_detail' => '地推拓客明细表',
            'field_acquisition_summary' => '地推拓客汇总表',
            'cross_industry_customer_detail' => '异业收客明细表',
            'cross_industry_customer_summary' => '异业收客汇总表',
            'new_customer_analysis' => '新客明细表',
            'new_customer_analysis_summary' => '新客汇总表',
            'salesperson_large_order_statistics' => '销售人生美大单统计表',
            'store_refund_ledger' => '院店退款台账',
        ];
        $rows = [];
        foreach ($names as $code => $name) $rows[] = ['folder' => '门店运营', 'code' => $code, 'name' => $name];
        return $rows;
    }

    public function supports(string $report): bool
    {
        return in_array($report, self::REPORTS, true);
    }

    public function query(string $report, $storeIds, array $range, array $input): array
    {
        $this->activeRange = ['start' => (string)$range['start'], 'end' => (string)$range['end']];
        $this->activeInput = $input;
        $reportScope = is_array($input['_report_scope'] ?? null) ? $input['_report_scope'] : [];
        if ((string)($reportScope['mode'] ?? '') === 'none') {
            throw new \InvalidArgumentException('当前账号没有可查看的数据范围');
        }
        $this->participantEmployeeId = (string)($reportScope['mode'] ?? '') === 'self_participant'
            ? max(0, (int)($reportScope['employee_id'] ?? 0)) : 0;
        if ((string)($reportScope['mode'] ?? '') === 'self_participant' && $this->participantEmployeeId <= 0) {
            throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
        }
        $storeIds = $this->storeIds($storeIds);
        if (!$storeIds) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        switch ($report) {
            case 'market_performance': return $this->marketPerformance($storeIds, $range, $input);
            case 'market_detail': return $this->marketDetail($storeIds, $range, $input);
            case 'member_visit_analysis': return $this->memberVisitAnalysis($storeIds, $range, $input);
            case 'member_visit_annual_summary': return $this->memberVisitAnnualSummary($storeIds, $range, $input);
            case 'field_acquisition_detail': return $this->fieldMarketingDetail($storeIds, $range, $input);
            case 'field_acquisition_summary': return $this->fieldMarketingSummary($storeIds, $range, $input);
            case 'cross_industry_customer_detail': return $this->crossIndustryDetail($storeIds, $range, $input);
            case 'cross_industry_customer_summary': return $this->crossIndustrySummary($storeIds, $range, $input);
            case 'new_customer_analysis': return $this->newCustomerAnalysis($storeIds, $range, $input);
            case 'new_customer_analysis_summary': return $this->newCustomerSummary($storeIds, $range, $input);
            case 'salesperson_large_order_statistics': return $this->salespersonBeautyLargeOrder($storeIds, $range, $input);
            case 'store_refund_ledger': return $this->storeRefundLedger($storeIds, $range, $input);
        }
        throw new \InvalidArgumentException('不支持的第二阶段报表类型');
    }

    private function marketPerformance(array $stores, array $range, array $input): array
    {
        $sources = $this->primarySources();
        $sourceById = [];
        $refundSourceId = 0;
        foreach ($sources as $source) {
            $sourceById[(int)$source['id']] = $source;
            if ($this->sourcePrefix($source) === 'K') $refundSourceId = (int)$source['id'];
        }
        $methods = $this->paymentMethods();
        $rows = [];
        $storeFacts = $this->cashFacts($stores)
            ->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective')
            ->fieldRaw('store_id,MAX(store_name_snapshot) store_name,MAX(organization_name_snapshot) organization_name')
            ->group('store_id')->select()->toArray();
        foreach ($storeFacts as $fact) {
            $id = (int)$fact['store_id'];
            $rows[$id] = [
                // organization_name_snapshot is the business node name (which
                // can be a manager), not necessarily the configured company.
                // Resolve the report dimension from the reporting store's
                // organization path after the per-store aggregation instead.
                'store_id' => $id, 'division_name' => '',
                'store_name' => (string)$fact['store_name'],
            ];
            $this->projectOrganization($rows[$id], '', '', $range['end']);
        }
        $cash = $this->cashFacts($stores, 'market_payment')
            ->whereBetween('market_payment.business_date', [$range['start'], $range['end']])->where('market_payment.status', 'effective')
            ->fieldRaw("market_payment.store_id,market_payment.business_source_primary_id,market_payment.fact_direction,market_payment.payment_method,CASE WHEN market_payment.fact_direction='reversal' AND EXISTS (SELECT 1 FROM eb_cashier_v3_order_lifecycle_operation refund_operation WHERE refund_operation.tenant_id=market_payment.tenant_id AND refund_operation.command_idempotency_key=market_payment.command_idempotency_key AND refund_operation.operation_type='refund' AND refund_operation.status='succeeded') THEN 1 ELSE 0 END is_refund_reversal,SUM(market_payment.amount_cents) amount_cents")
            ->group('market_payment.store_id,market_payment.business_source_primary_id,market_payment.fact_direction,market_payment.payment_method,is_refund_reversal')->select()->toArray();
        foreach ($cash as $fact) {
            $storeId = (int)$fact['store_id'];
            if (!isset($rows[$storeId])) continue;
            // A refund is reported in the refund channel. A void is an
            // accounting cancellation of its original sale, so its negative
            // fact must remain in the original channel and net that sale out.
            $sourceId = (int)($fact['is_refund_reversal'] ?? 0) === 1 && $refundSourceId > 0
                ? $refundSourceId : (int)$fact['business_source_primary_id'];
            if (!isset($sourceById[$sourceId])) continue;
            $key = 'channel_' . $sourceId . '_amount_cents';
            $rows[$storeId][$key] = (int)($rows[$storeId][$key] ?? 0) + (int)$fact['amount_cents'];
            $method = (string)$fact['payment_method'];
            $rows[$storeId]['payment_' . $method . '_cents'] = (int)($rows[$storeId]['payment_' . $method . '_cents'] ?? 0) + (int)$fact['amount_cents'];
            $rows[$storeId]['total_performance_cents'] = (int)($rows[$storeId]['total_performance_cents'] ?? 0) + (int)$fact['amount_cents'];
        }
        $visits = [];
        foreach ($this->marketServiceVisitFacts($stores, $range, $input) as $fact) {
            $sourceId = (int)($fact['business_source_primary_id'] ?? 0);
            if ($sourceId <= 0) continue;
            $key = (int)$fact['store_id'] . '|' . $sourceId;
            if (!isset($visits[$key])) {
                $visits[$key] = [
                    'store_id' => (int)$fact['store_id'],
                    'business_source_primary_id' => $sourceId,
                    'visit_count' => 0,
                ];
            }
            $visits[$key]['visit_count']++;
        }
        foreach ($visits as $fact) if (isset($rows[(int)$fact['store_id']])) {
            $rows[(int)$fact['store_id']]['channel_' . (int)$fact['business_source_primary_id'] . '_visits'] = (int)$fact['visit_count'];
        }
        foreach ($sources as $source) {
            $sourceId = (int)$source['id'];
            $threshold = $this->sourcePrefix($source) === 'A' ? 100000 : 50000;
            $effective = $this->cashFacts($stores)
                ->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective')
                ->where('business_source_primary_id', $sourceId)->where('member_id', '>', 0)
                ->fieldRaw('store_id,member_id,SUM(amount_cents) amount_cents')->group('store_id,member_id')
                ->having('SUM(amount_cents)>=' . $threshold)->select()->toArray();
            foreach ($effective as $fact) {
                $storeId = (int)$fact['store_id'];
                if (isset($rows[$storeId])) $rows[$storeId]['channel_' . $sourceId . '_effective'] = (int)($rows[$storeId]['channel_' . $sourceId . '_effective'] ?? 0) + 1;
            }
        }
        // B 渠道“进店”是订单明细级补充记录的合计。subject_key 固定使用
        // order_id，不允许把可变化的筛选日期拼成补充记录主键。
        $bSourceIds = [];
        foreach ($sources as $source) if ($this->sourcePrefix($source) === 'B') $bSourceIds[] = (int)$source['id'];
        if ($bSourceIds) {
            $bOrders = $this->cashFacts($stores)
                ->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective')
                ->whereIn('business_source_primary_id', $bSourceIds)
                ->field('store_id,business_source_primary_id,order_id')->group('store_id,business_source_primary_id,order_id')->select()->toArray();
            $manual = $this->annotations('market_detail', $stores, array_values(array_unique(array_column($bOrders, 'order_id'))));
            foreach ($bOrders as $order) {
                $storeId = (int)$order['store_id']; $sourceId = (int)$order['business_source_primary_id'];
                if (!isset($rows[$storeId])) continue;
                $rows[$storeId]['channel_'.$sourceId.'_walk_in'] = (int)($rows[$storeId]['channel_'.$sourceId.'_walk_in'] ?? 0)
                    + (int)($manual[(string)$order['order_id']]['walk_in']['value'] ?? 0);
            }
        }
        $columns = [['key'=>'division_name','label'=>'分公司'], ['key'=>'store_name','label'=>'门店']];
        $groups = [];
        foreach ($sources as $source) {
            $sourceId = (int)$source['id'];
            $prefix = $this->sourcePrefix($source);
            $keys = [];
            if ($prefix === 'B') {
                $key = 'channel_' . $sourceId . '_walk_in';
                $columns[] = ['key'=>$key,'label'=>'进店','group_label'=>(string)$source['name']]; $keys[] = $key;
                $columns[count($columns)-1]['drilldown']=['report'=>'market_detail','params'=>['dimension_code'=>(string)$sourceId]];
            }
            foreach ([['visits','人次'],['effective','有效人员'],['amount','金额']] as $definition) {
                $key = 'channel_' . $sourceId . '_' . $definition[0];
                $columns[] = ['key'=>$key,'label'=>$definition[1],'group_label'=>(string)$source['name']]; $keys[] = $key;
                $params = ['dimension_code'=>(string)$sourceId];
                if ($definition[0] === 'effective') $params['metric_code'] = 'effective_people';
                $columns[count($columns)-1]['drilldown']=['report'=>'market_detail','params'=>$params];
            }
            $groups[] = ['label'=>(string)$source['name'],'dimension_code'=>(string)$sourceId,'column_keys'=>$keys];
        }
        $systemKeys = [];
        foreach ($methods as $method) {
            $key = 'payment_' . $method['code'];
            $columns[] = ['key'=>$key,'label'=>$method['label'],'group_label'=>'系统操作']; $systemKeys[] = $key;
            $columns[count($columns)-1]['drilldown']=['report'=>'market_detail','params'=>['payment_method_code'=>(string)$method['code']]];
        }
        $columns[] = ['key'=>'total_performance','label'=>'总业绩','group_label'=>'系统操作']; $systemKeys[] = 'total_performance';
        $columns[count($columns)-1]['drilldown']=['report'=>'market_detail'];
        $groups[] = ['label'=>'系统操作','column_keys'=>$systemKeys];
        foreach ($rows as &$row) {
            foreach ($sources as $source) {
                $id = (int)$source['id'];
                foreach (['visits','effective'] as $suffix) $row['channel_'.$id.'_'.$suffix] = (int)($row['channel_'.$id.'_'.$suffix] ?? 0);
                $row['channel_'.$id.'_amount'] = $this->money((int)($row['channel_'.$id.'_amount_cents'] ?? 0));
                if ($this->sourcePrefix($source) === 'B') $row['channel_'.$id.'_walk_in'] = (int)($row['channel_'.$id.'_walk_in'] ?? 0);
            }
            foreach ($methods as $method) $row['payment_'.$method['code']] = $this->money((int)($row['payment_'.$method['code'].'_cents'] ?? 0));
            $row['total_performance'] = $this->money((int)($row['total_performance_cents'] ?? 0));
        }
        unset($row);
        return $this->result('市场业绩表', $columns, array_values($rows), $input, $groups, ['cash_performance_cents'=>$this->sumField($rows, 'total_performance_cents')]);
    }

    private function marketDetail(array $stores, array $range, array $input): array
    {
        $query = $this->cashFacts($stores, 'p')
            ->whereBetween('p.business_date', [$range['start'], $range['end']])->where('p.status', 'effective');
        $dimension = trim((string)($input['dimension_code'] ?? ''));
        $metricCode = trim((string)($input['metric_code'] ?? ''));
        if ($dimension !== '') $query->where('p.business_source_primary_id', (int)$dimension);
        if (($method = trim((string)($input['payment_method_code'] ?? ''))) !== '') $query->where('p.payment_method', $method);
        $rows = $query->leftJoin('user market_detail_member', 'market_detail_member.uid = p.member_id')
            ->fieldRaw('p.store_id,p.order_id,p.checkout_request_id,p.order_no_snapshot,p.store_name_snapshot,MAX(p.organization_id) organization_id,MAX(p.organization_path_snapshot) organization_path_snapshot,MAX(p.member_id) member_id,MAX(p.member_name_snapshot) member_name_snapshot,MAX(market_detail_member.phone) member_phone,p.business_source_primary_id,p.business_source_primary_name_snapshot,p.business_source_label_snapshot,p.business_date,MAX(p.operator_name_snapshot) creator_name,SUM(p.amount_cents) amount_cents,MAX(p.recorded_at) recorded_at')
            ->group('p.store_id,p.order_id,p.business_source_primary_id,p.business_date')
            // An order void writes a paired negative payment fact. Keep both
            // facts for audit and aggregates, but do not render a zero-net
            // document as an active market-detail row.
            ->having('SUM(p.amount_cents) <> 0')
            ->order('p.business_date','desc')->order('p.order_id','desc')->select()->toArray();
        // The detail query already contains every matching payment record before
        // pagination, so derive effective members here instead of running another
        // aggregate query solely for the summary row or drilldown filter.
        $effectiveMemberKeys = $this->marketEffectiveMemberKeys($rows);
        if ($metricCode === 'effective_people') {
            $rows = array_values(array_filter($rows, function (array $row) use ($effectiveMemberKeys): bool {
                return isset($effectiveMemberKeys[(int)$row['store_id'] . '|' . (int)$row['business_source_primary_id'] . '|' . (int)$row['member_id']]);
            }));
        }
        $serviceVisits = $this->marketServiceVisitFacts($stores, $range, $input);
        $visitsByCheckout = [];
        $visitsByOrder = [];
        foreach ($serviceVisits as $serviceVisit) {
            $sourceId = (int)($serviceVisit['business_source_primary_id'] ?? 0);
            if ($sourceId <= 0) continue;
            $checkoutKey = (string)($serviceVisit['checkout_request_id'] ?? '');
            $matchedOrderKey = (string)($serviceVisit['matched_order_id'] ?? '');
            $orderKey = (string)($serviceVisit['origin_order_id'] ?? '');
            $visit = ['source_id' => $sourceId, 'count' => 1];
            if ($checkoutKey !== '') $visitsByCheckout[$checkoutKey . '|' . $sourceId][] = $visit;
            if ($matchedOrderKey !== '') $visitsByOrder[$matchedOrderKey . '|' . $sourceId][] = $visit;
            if ($orderKey !== '') $visitsByOrder[$orderKey . '|' . $sourceId][] = $visit;
        }
        foreach ($rows as &$row) {
            $row['dimension'] = (string)$row['business_source_label_snapshot'];
            $row['walk_in'] = 0; $row['visits'] = 0;
            $row['effective_people'] = isset($effectiveMemberKeys[(int)$row['store_id'] . '|' . (int)$row['business_source_primary_id'] . '|' . (int)$row['member_id']]) ? 1 : 0;
            $sourceKey = (string)(int)($row['business_source_primary_id'] ?? 0);
            $checkoutVisits = $visitsByCheckout[(string)($row['checkout_request_id'] ?? '') . '|' . $sourceKey] ?? [];
            $orderVisits = $visitsByOrder[(string)($row['order_id'] ?? '') . '|' . $sourceKey] ?? [];
            $row['visits'] = count($checkoutVisits) > 0 ? count($checkoutVisits) : count($orderVisits);
            $row['amount'] = $this->money((int)$row['amount_cents']);
            $row['registered_date'] = (string)$row['business_date'];
            $row['reviewer'] = ''; $row['reviewed_at'] = ''; $row['created_at'] = $this->dateTime((int)$row['recorded_at']);
            $row['annotation_subject_key'] = (string)$row['order_id'];
            $row['annotation_subject_type'] = 'sales_order'; $row['source_order_id'] = (string)$row['order_id'];
        }
        unset($row);
        $keys=array_values(array_unique(array_column($rows,'order_id')));$manual=$this->annotations('market_detail',$stores,$keys);
        foreach($rows as &$row){$key=(string)$row['order_id'];$row['walk_in']=(int)($manual[$key]['walk_in']['value']??0);$row['walk_in_version']=(int)($manual[$key]['walk_in']['version']??0);}unset($row);
        $columns = $this->columns(['order_no_snapshot'=>'单据号','store_name_snapshot'=>'门店名称','member_name_snapshot'=>'会员','member_phone'=>'手机','dimension'=>'来源','walk_in'=>'进店','visits'=>'人次','effective_people'=>'有效人员','amount'=>'金额','registered_date'=>'登记日期','reviewer'=>'审核人','reviewed_at'=>'审核时间','creator_name'=>'制单人','created_at'=>'制单日期']);
        foreach (['order_no_snapshot'=>126, 'store_name_snapshot'=>112, 'member_name_snapshot'=>82, 'member_phone'=>116, 'dimension'=>108] as $key => $width) {
            foreach ($columns as &$column) if ($column['key'] === $key) { $column['fixed'] = 'left'; $column['fixed_width'] = $width; break; }
            unset($column);
        }
        $result = $this->result('市场明细表', $columns, $rows, $input, [], ['amount_cents'=>$this->sumField($rows,'amount_cents')]);
        $result['summary_row'] = $this->marketDetailSummaryRow($rows);
        return $result;
    }

    private function memberVisitAnalysis(array $stores, array $range, array $input): array
    {
        $year = (int)substr($range['end'], 0, 4);
        $services = $this->completedUnvoidedServiceFacts(
            $this->participantCheckout(
                $this->applyOrganizationFilters(
                    $this->scope(Db::name('cashier_v3_entitlement_service_fact')->alias('member_visit_service'), $stores, 'member_visit_service'),
                    'member_visit_service',
                    $input,
                    $range
                ),
                'member_visit_service.checkout_request_id'
            ),
            'member_visit_service',
            'member_visit_void'
        )
            ->whereBetween('member_visit_service.business_date', [$range['start'], $range['end']])
            ->where('member_visit_service.member_id', '>', 0)
            // 到店的事实粒度是“会员 + 业务日”。同日同会员可完成多个项目、
            // 也可发生多张单，但只能算一次到店；不能按服务项目事实行累加。
            ->fieldRaw("member_visit_service.member_id,MAX(member_visit_service.member_name_snapshot) member_name,COUNT(DISTINCT member_visit_service.business_date) total_visits,MONTH(member_visit_service.business_date) month_no,COUNT(DISTINCT member_visit_service.business_date) month_visits")
            ->group('member_visit_service.member_id,MONTH(member_visit_service.business_date)')->select()->toArray();
        $payments = $this->cashFacts($stores)
            ->whereBetween('business_date', [$range['start'], $range['end']])->where('status','effective')->where('member_id','>',0)
            ->fieldRaw('member_id,MONTH(business_date) month_no,SUM(amount_cents) amount_cents')->group('member_id,MONTH(business_date)')->select()->toArray();
        $records = [];
        foreach ($services as $fact) {
            $id = (int)$fact['member_id'];
            if (!isset($records[$id])) $records[$id] = ['member_id'=>$id,'member_name'=>(string)$fact['member_name'],'total_visits'=>0,'annual_cash_cents'=>0];
            $month = (int)$fact['month_no']; $records[$id]['month_'.$month.'_visits'] = (int)$fact['month_visits'];
            $records[$id]['total_visits'] += (int)$fact['month_visits'];
        }
        foreach ($payments as $fact) if (isset($records[(int)$fact['member_id']])) {
            $id=(int)$fact['member_id'];$month=(int)$fact['month_no'];$records[$id]['month_'.$month.'_cash_cents']=(int)$fact['amount_cents'];$records[$id]['annual_cash_cents']+=(int)$fact['amount_cents'];
        }
        $memberIds = array_keys($records);
        $phones = [];
        $annualCardSources = [];
        if ($memberIds) {
            $annualCardSources = $this->annualFirstCardSources($stores, $memberIds, $year);
            foreach (Db::name('user')->whereIn('uid',$memberIds)->field('uid,phone')->select()->toArray() as $row) $phones[(int)$row['uid']] = (string)$row['phone'];
        }
        $columns = $this->columns(['member_name'=>'会员姓名','phone'=>'手机号码','total_visits'=>'总进店数','annual_cash'=>'全年现金业绩','source'=>'来源']);
        $groups=[];
        foreach (range(1,12) as $month) {
            $keys=['month_'.$month.'_visits','month_'.$month.'_cash'];
            $columns[]=['key'=>$keys[0],'label'=>'进店次数','group_label'=>$month.'月'];$columns[]=['key'=>$keys[1],'label'=>'现金业绩','group_label'=>$month.'月'];
            $groups[]=['label'=>$month.'月','column_keys'=>$keys];
        }
        foreach ($records as &$row) {
            $id=(int)$row['member_id'];$row['phone']=$phones[$id]??'';$row['source']=(string)($annualCardSources[$id]??'');$row['annual_cash']=$this->money((int)$row['annual_cash_cents']);
            foreach(range(1,12) as $month){$row['month_'.$month.'_visits']=(int)($row['month_'.$month.'_visits']??0);$row['month_'.$month.'_cash']=$this->money((int)($row['month_'.$month.'_cash_cents']??0));}
        } unset($row);
        return $this->result('会员进店分析表',$columns,array_values($records),$input,$groups,['natural_year'=>$year]);
    }

    private function memberVisitAnnualSummary(array $stores, array $range, array $input): array
    {
        $year=(int)substr($range['end'],0,4);$mode=(string)($input['mode']??'count');
        if(!in_array($mode,['count','people','project'],true)) throw new \InvalidArgumentException('年度进店统计方式无效');
        $query=$this->completedUnvoidedServiceFacts($this->participantCheckout($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_entitlement_service_fact')->alias('annual_visit_service'),$stores,'annual_visit_service'), 'annual_visit_service', $input, $range),'annual_visit_service.checkout_request_id'),'annual_visit_service','annual_visit_void')
            ->whereBetween('annual_visit_service.business_date',[$range['start'],$range['end']])
            // 本表统计会员进店；游客服务不应混入会员年度口径。
            ->where('annual_visit_service.member_id', '>', 0);
        // 年度汇总的“进店次数”与会员进店分析使用同一口径：同一门店内，
        // 同一会员同一业务日仅计一次。项目模式仍统计项目数量，人数模式
        // 仍统计月度去重会员，三者不混用。
        $expression=$mode==='people'
            ? 'COUNT(DISTINCT annual_visit_service.member_id)'
            : ($mode==='project'
                ? 'SUM(annual_visit_service.quantity)'
                : "COUNT(DISTINCT CONCAT(annual_visit_service.member_id, '|', annual_visit_service.business_date))");
        $facts=$query->fieldRaw('annual_visit_service.store_id,MAX(annual_visit_service.store_name_snapshot) store_name,MONTH(annual_visit_service.business_date) month_no,'.$expression.' amount')->group('annual_visit_service.store_id,MONTH(annual_visit_service.business_date)')->select()->toArray();
        $storeNames=[];$records=[];foreach(range(1,12) as $month)$records[$month]=['row_label'=>$month.'月','year'=>$year.'年','total'=>0];
        foreach($facts as $fact){$sid=(int)$fact['store_id'];$storeNames[$sid]=(string)$fact['store_name'];$records[(int)$fact['month_no']]['store_'.$sid]=(int)$fact['amount'];$records[(int)$fact['month_no']]['total']+=(int)$fact['amount'];}
        $columns=$this->columns(['row_label'=>'列明','year'=>'年份','total'=>'合计']);foreach($storeNames as $id=>$name)$columns[]=['key'=>'store_'.$id,'label'=>$name,'store_id'=>$id];
        foreach($records as &$row)foreach($storeNames as $id=>$name)$row['store_'.$id]=(int)($row['store_'.$id]??0);unset($row);
        return $this->result('会员进店年度汇总表',$columns,array_values($records),$input,[],['mode'=>$mode,'natural_year'=>$year]);
    }

    private function fieldMarketingDetail(array $stores,array $range,array $input):array
    {
        $sourceIds=$this->sourceIds('E');$memberId=(int)($input['member_id']??0);
        $base=$this->participantOrder($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_sale_fact')->alias('s'),$stores,'s'), 's', $input, $range),'s.order_id')->leftJoin('cashier_v3_sales_order o','o.order_id=s.order_id')
            ->whereBetween('s.business_date',[$range['start'],$range['end']])->where('s.status','effective')->whereIn('s.business_source_primary_id',$sourceIds?:[-1]);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($base,'s.tenant_id','s.order_id');
        if($memberId>0)$base->where('s.member_id',$memberId);
        $base=$base->fieldRaw('s.fact_id,s.store_id,s.organization_id,s.organization_path_snapshot,s.organization_name_snapshot,s.store_name_snapshot,s.member_id,s.member_name_snapshot,s.business_source_secondary_name_snapshot,s.order_id,s.source_line_id,s.business_date,s.occurred_at,MAX(o.order_note) remark')
            ->group('s.store_id,s.member_id,s.order_id,s.source_line_id')->order('s.business_date','desc')->select()->toArray();
        $memberIds=array_values(array_unique(array_filter(array_column($base,'member_id'))));$phones=$this->phones($memberIds);
        $services=$this->servicesByMember($stores,$memberIds);
        // 服务槽位和销售槽位是两套独立序列：服务列按护理记录排序，
        // 现金列按地推销售订单排序；补交通过 sale_fact_id 回挂原销售槽位。
        $saleFacts=array_values(array_unique(array_filter(array_column($base,'fact_id'))));$saleCash=[];
        if($saleFacts){
            foreach($this->cashFacts($stores,'field_sale_payment')->join('cashier_v3_payment_sale_allocation_fact allocation',"allocation.tenant_id=field_sale_payment.tenant_id AND allocation.payment_fact_id=field_sale_payment.fact_id AND allocation.status='effective'")
                ->whereIn('allocation.sale_fact_id',$saleFacts)->where('field_sale_payment.business_date','<=',$range['end'])->where('field_sale_payment.status','effective')
                ->fieldRaw('allocation.sale_fact_id,field_sale_payment.source_document_type,SUM(allocation.amount_cents) amount_cents')->group('allocation.sale_fact_id,field_sale_payment.source_document_type')->select()->toArray() as $payment){
                if(in_array((string)$payment['source_document_type'],['recharge_debt_repayment'],true))continue;
                $saleCash[(string)$payment['sale_fact_id']]=(int)($saleCash[(string)$payment['sale_fact_id']]??0)+(int)$payment['amount_cents'];
            }
        }
        $saleOrders=[];foreach($base as $sale){$key=(int)$sale['member_id'].'|'.(string)$sale['order_id'];if(!isset($saleOrders[$key]))$saleOrders[$key]=['member_id'=>(int)$sale['member_id'],'order_id'=>(string)$sale['order_id'],'business_date'=>(string)$sale['business_date'],'occurred_at'=>(int)($sale['occurred_at']??0)];}
        $saleOrders=array_values($saleOrders);usort($saleOrders,static function(array $a,array $b):int{return [$a['business_date'],$a['occurred_at'],$a['order_id']]<=>[$b['business_date'],$b['occurred_at'],$b['order_id']];});$saleSlots=[];foreach($saleOrders as $index=>$sale)$saleSlots[(int)$sale['member_id'].'|'.(string)$sale['order_id']]=$index+1;
        $keys=array_values(array_unique(array_filter(array_column($base,'source_line_id'))));$manual=$this->annotations('field_acquisition_detail',$stores,$keys);
        foreach($base as &$row){$member=(int)$row['member_id'];$row['division_name']=(string)$row['organization_name_snapshot'];$row['source']=(string)$row['business_source_secondary_name_snapshot'];$row['phone']=$phones[$member]??'';$row['card_sale_date']=$manual[(string)$row['source_line_id']]['card_sale_date']['value']??'';$row['card_sale_date_version']=(int)($manual[(string)$row['source_line_id']]['card_sale_date']['version']??0);$row['visit_over_one_hour']=$manual[(string)$row['source_line_id']]['visit_over_one_hour']['value']??'';$row['visit_over_one_hour_version']=(int)($manual[(string)$row['source_line_id']]['visit_over_one_hour']['version']??0);$visits=$services[$member]??[];$fee=0;foreach(range(1,3)as$i){$visit=$visits[$i-1]??[];$row['visit_'.$i.'_craftsman']=(string)($visit['craftsman']??'');$row['visit_'.$i.'_date']=(string)($visit['business_date']??'');$row['visit_'.$i.'_project']=(string)($visit['project_name_snapshot']??'');$fee+=(int)($visit['labor_fee_amount_cents']??0);$row['cash_'.$i]=$this->money(((int)($saleSlots[$member.'|'.(string)$row['order_id']]??0)===$i)?(int)($saleCash[(string)$row['fact_id']]??0):0);}$row['labor_fee']=$this->money(count($visits)>=3?$fee:0);$row['fourth_and_above']=max(0,count($visits)-3);$row['annotation_subject_key']=(string)$row['source_line_id'];$row['annotation_subject_type']='sale_line';}
        unset($row);
        $columns=$this->columns(['division_name'=>'分公司','store_name_snapshot'=>'门店','source'=>'来源','member_name_snapshot'=>'会员','card_sale_date'=>'卖卡日期','phone'=>'手机号码','visit_over_one_hour'=>'进店满1小时']);$groups=[];
        foreach(range(1,3)as$i){$label=['','第一次','第二次','第三次'][$i];$keys=['visit_'.$i.'_craftsman','visit_'.$i.'_date','visit_'.$i.'_project'];foreach(array_combine($keys,['手艺人','护理日期','项目名称'])as$key=>$name)$columns[]=['key'=>$key,'label'=>$name,'group_label'=>$label];$groups[]=['label'=>$label,'column_keys'=>$keys];}
        foreach(['labor_fee'=>'手工费','cash_1'=>'第一次现金业绩','cash_2'=>'第二次现金业绩','cash_3'=>'第三次现金业绩','fourth_and_above'=>'四次及以上','remark'=>'备注']as$key=>$label)$columns[]=['key'=>$key,'label'=>$label];
        return $this->result('地推拓客明细表',$columns,$base,$input,$groups);
    }

    private function fieldMarketingSummary(array $stores,array $range,array $input):array
    {
        $detail=$this->fieldMarketingDetail($stores,$range,array_merge($input,['page'=>1,'limit'=>100,'_internal_all'=>true]));$records=[];$year=(int)substr($range['end'],0,4);
        foreach($detail['records'] as $row){$member=(int)$row['member_id'];if(isset($records[$member]))continue;$records[$member]=['member_id'=>$member,'card_sale_date'=>$row['card_sale_date'],'member_name_snapshot'=>$row['member_name_snapshot'],'phone'=>$row['phone'],'source'=>$row['source'],'first_visit_date'=>$row['visit_1_date'],'remark'=>$row['remark'],'annotation_subject_key'=>$row['annotation_subject_key']];}
        $memberIds=array_keys($records);$cash=$this->cashMonthlyTotals($stores,$memberIds,$year.'-01-01',$year.'-12-31');
        foreach($records as &$row){$row['annual_total_cents']=0;$row['_drilldown']=[];foreach(range(1,12)as$m){$c=(int)($cash[(int)$row['member_id']][$m]??0);$monthKey='month_'.$m;$row[$monthKey]=$this->money($c);$row['annual_total_cents']+=$c;if($c!==0)$row['_drilldown'][$monthKey]=['report'=>'field_acquisition_detail','params'=>['start_date'=>sprintf('%04d-%02d-01',$year,$m),'end_date'=>date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$year,$m)))],'param_map'=>['member_id'=>'member_id']];}$row['annual_total']=$this->money($row['annual_total_cents']);if((int)$row['annual_total_cents']!==0)$row['_drilldown']['annual_total']=['report'=>'field_acquisition_detail','params'=>['start_date'=>$year.'-01-01','end_date'=>$year.'-12-31'],'param_map'=>['member_id'=>'member_id']];}unset($row);
        $columns=$this->columns(['card_sale_date'=>'卖卡日期','member_name_snapshot'=>'会员','phone'=>'手机号码','source'=>'来源','first_visit_date'=>'首次护理日期','annual_total'=>'首年业绩合计']);foreach(range(1,12)as$m)$columns[]=['key'=>'month_'.$m,'label'=>$m.'月'];$columns[]=['key'=>'remark','label'=>'备注'];
        return $this->result('地推拓客汇总表',$columns,array_values($records),$input,[],['natural_year'=>$year,'annual_total_cents'=>$this->sumField($records,'annual_total_cents')]);
    }

    private function crossIndustryDetail(array $stores,array $range,array $input):array
    {
        $sourceIds=$this->sourceIds('G');$memberId=(int)($input['member_id']??0);$includeFollowupCash=(int)($input['include_followup_cash']??0)===1&&$memberId>0;$payments=$this->cashFacts($stores)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective');if(!$includeFollowupCash)$payments->whereIn('business_source_primary_id',$sourceIds?:[-1]);if($memberId>0)$payments->where('member_id',$memberId);$payments=$payments->fieldRaw('store_id,order_id,checkout_request_id,member_id,MAX(member_name_snapshot) member_name,MAX(store_name_snapshot) store_name,MAX(business_source_label_snapshot) source,SUM(amount_cents) amount_cents')->group('store_id,order_id,checkout_request_id,member_id')->select()->toArray();
        $keys=array_values(array_unique(array_column($payments,'order_id')));$orders=[];if($keys){$fields=$this->hasColumn('cashier_v3_sales_order','reward_amount_cents')?'order_id,order_note,reward_amount_cents':'order_id,order_note';foreach(Db::name('cashier_v3_sales_order')->whereIn('order_id',$keys)->field($fields)->select()->toArray()as$r)$orders[(string)$r['order_id']]=$r;}
        $memberIds=array_values(array_unique(array_filter(array_column($payments,'member_id'))));$careDates=[];
        if($memberIds){foreach($this->completedUnvoidedServiceFacts($this->participantCheckout($this->scope(Db::name('cashier_v3_entitlement_service_fact')->alias('cross_care_service'),$stores,'cross_care_service'),'cross_care_service.checkout_request_id'),'cross_care_service','cross_care_void')->whereIn('cross_care_service.member_id',$memberIds)->fieldRaw('cross_care_service.store_id,cross_care_service.member_id,MIN(cross_care_service.business_date) care_date')->group('cross_care_service.store_id,cross_care_service.member_id')->select()->toArray()as$r)$careDates[(int)$r['store_id'].'|'.(int)$r['member_id']]=(string)$r['care_date'];}
        foreach($payments as &$row){$key=(string)$row['order_id'];$row['care_date']=$careDates[(int)$row['store_id'].'|'.(int)$row['member_id']]??'';$row['full_payment']=$this->money((int)$row['amount_cents']);$row['reward']=$this->money((int)($orders[$key]['reward_amount_cents']??0));$row['remark']=(string)($orders[$key]['order_note']??'');$row['annotation_subject_key']=$key;$row['annotation_subject_type']='sales_order';$row['source_order_id']=$key;}unset($row);
        return $this->result('异业收客明细表',$this->columns(['source'=>'来源','care_date'=>'护理日期','store_name'=>'门店','member_name'=>'会员','full_payment'=>'收客全款业绩','reward'=>'奖励','remark'=>'备注']),$payments,$input);
    }

    private function crossIndustrySummary(array $stores,array $range,array $input):array
    {
        $sourceIds=$this->sourceIds('G');$year=(int)substr($range['end'],0,4);$sales=$this->participantOrder($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_sale_fact')->alias('cross_sale'),$stores,'cross_sale'),'cross_sale',$input,['start'=>$year.'-01-01','end'=>$year.'-12-31']),'cross_sale.order_id')->whereBetween('cross_sale.business_date',[$year.'-01-01',$year.'-12-31'])->where('cross_sale.status','effective')->whereIn('cross_sale.business_source_primary_id',$sourceIds?:[-1]);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($sales,'cross_sale.tenant_id','cross_sale.order_id');
        $sales=$sales->fieldRaw('cross_sale.store_id,cross_sale.member_id,MAX(cross_sale.member_name_snapshot) member_name,MIN(cross_sale.source_line_id) source_line_id,MAX(cross_sale.item_name_snapshot) card_name,MIN(cross_sale.business_date) first_sale_date')->group('cross_sale.store_id,cross_sale.member_id')->select()->toArray();
        $memberIds=array_values(array_unique(array_filter(array_column($sales,'member_id'))));$phones=$this->phones($memberIds);$cash=$this->cashMonthlyTotals($stores,$memberIds,$year.'-01-01',$year.'-12-31');$dailyCash=$this->cashByMemberAndMonth($stores,$memberIds,$year.'-01-01',$year.'-12-31');$services=$this->servicesByMember($stores,$memberIds);$entitlements=$this->activeCardEntitlements($stores,$memberIds);$keys=array_values(array_unique(array_column($sales,'source_line_id')));$manual=$this->annotations('cross_industry_customer_summary',$stores,$keys);$total500=0;$total2400=0;
        foreach($sales as &$row){
            $id=(int)$row['member_id'];$key=(string)$row['source_line_id'];$row['customer_acquired_at']=$manual[$key]['customer_acquired_at']['value']??'';$row['customer_acquired_at_version']=(int)($manual[$key]['customer_acquired_at']['version']??0);$row['partner_store_name']=$manual[$key]['partner_store_name']['value']??'';$row['partner_store_name_version']=(int)($manual[$key]['partner_store_name']['version']??0);$row['phone']=$phones[$id]??'';$row['remaining_service_count']=(int)($entitlements[$id]['remaining_count']??0);$row['remaining_service_amount']=$this->money((int)($entitlements[$id]['remaining_amount_cents']??0));$row['first_visit_at']=(string)($services[$id][0]['business_date']??'');
            $annual=0;foreach(range(1,12)as$m){$c=(int)($cash[$id][$m]??0);$row['month_'.$m]=$this->money($c);$annual+=$c;}$row['annual_cash']=$this->money($annual);
            $first500=0;$reached2400=0;$running=0;foreach($dailyCash[$id]??[]as$day){$amount=(int)$day['amount_cents'];if($first500===0&&$amount>=50000)$first500=$amount;$before=$running;$running+=$amount;if($reached2400===0&&$before<240000&&$running>=240000)$reached2400=$running;}
            $row['first_500']=$this->money($first500);$row['reached_2400']=$this->money($reached2400);$total500+=$first500;$total2400+=$reached2400;$row['annotation_subject_key']=$key;$row['annotation_subject_type']='sale_line';$row['source_line_id']=$key;$row['_drilldown']=[];$annualConfig=['report'=>'cross_industry_customer_detail','params'=>['start_date'=>$year.'-01-01','end_date'=>$year.'-12-31','include_followup_cash'=>1],'param_map'=>['member_id'=>'member_id']];foreach(range(1,12)as$m){$monthKey='month_'.$m;if((int)($cash[$id][$m]??0)===0)continue;$row['_drilldown'][$monthKey]=['report'=>'cross_industry_customer_detail','params'=>['start_date'=>sprintf('%04d-%02d-01',$year,$m),'end_date'=>date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$year,$m))),'include_followup_cash'=>1],'param_map'=>['member_id'=>'member_id']];}if($annual!==0)$row['_drilldown']['annual_cash']=$annualConfig;if($first500!==0)$row['_drilldown']['first_500']=$annualConfig;if($reached2400!==0)$row['_drilldown']['reached_2400']=$annualConfig;
        }unset($row);
        $columns=$this->columns(['customer_acquired_at'=>'收客时间','partner_store_name'=>'异业店名','member_name'=>'会员','phone'=>'手机号码','card_name'=>'卡项名称','remaining_service_count'=>'剩余服务次数','remaining_service_amount'=>'剩余服务金额','first_500'=>'首次成交满500','reached_2400'=>'成交满2400','first_visit_at'=>'首次到店时间','annual_cash'=>'全年现金业绩']);foreach(range(1,12)as$m)$columns[]=['key'=>'month_'.$m,'label'=>$m.'月'];
        return $this->result('异业收客汇总表',$columns,$sales,$input,[],['first_500_total'=>$this->money($total500),'reached_2400_total'=>$this->money($total2400),'natural_year'=>$year]);
    }

    private function newCustomerAnalysis(array $stores,array $range,array $input):array
    {
        $excluded=$this->sourceIdsMany(['A','H']);
        $memberId=(int)($input['member_id']??0);
        $sourceId=(int)($input['source_id']??0);
        $sourceLabel=trim((string)($input['source_label']??''));
        $sales=$this->participantOrder($this->scope(Db::name('cashier_v3_sale_fact')->alias('s'),$stores,'s'),'s.order_id')
            ->leftJoin('cashier_v3_sales_order o','o.order_id=s.order_id')->leftJoin('user u','u.uid=s.member_id')
            ->whereBetween('s.business_date',[$range['start'],$range['end']])->where('s.status','effective');
        if($excluded)$sales->whereNotIn('s.business_source_primary_id',$excluded);
        if($memberId>0)$sales->where('s.member_id',$memberId);
        if($sourceId>0)$sales->where('s.business_source_primary_id',$sourceId);
        elseif($sourceLabel!=='')$sales->where('s.business_source_label_snapshot',$sourceLabel);
        $this->applyOrganizationFilters($sales,'s',$input,$range);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($sales,'s.tenant_id','s.order_id');
        $rows=$sales->fieldRaw('s.fact_id,s.store_id,s.organization_id,s.organization_path_snapshot,s.organization_name_snapshot,s.store_name_snapshot,s.business_date,s.order_id,s.source_line_id,s.member_id,s.member_name_snapshot,s.business_source_label_snapshot,s.item_name_snapshot,s.sale_amount_cents,s.debt_amount_cents,u.phone,MAX(o.order_note) remark')
            ->group('s.source_line_id')->order('s.business_date','desc')->select()->toArray();
        $orderIds=array_values(array_unique(array_filter(array_column($rows,'order_id'))));
        $saleFactIds=array_values(array_unique(array_filter(array_column($rows,'fact_id'))));
        $lineIds=array_values(array_unique(array_filter(array_column($rows,'source_line_id'))));
        $personnel=$this->newCustomerPersonnel($stores,$orderIds,$lineIds);
        $guides=$personnel['guides'];$salespeople=$personnel['salespeople'];$managers=$personnel['managers'];
        $payments=[];$repaymentRows=[];
        if($saleFactIds){
            foreach($this->cashFacts($stores,'payment')->join('cashier_v3_payment_sale_allocation_fact allocation',"allocation.tenant_id=payment.tenant_id AND allocation.payment_fact_id=payment.fact_id AND allocation.status='effective'")
                ->whereIn('allocation.sale_fact_id',$saleFactIds)->whereBetween('payment.business_date',[$range['start'],$range['end']])->where('payment.status','effective')
                ->fieldRaw('allocation.sale_fact_id,payment.source_document_type,SUM(allocation.amount_cents) amount_cents')->group('allocation.sale_fact_id,payment.source_document_type')->select()->toArray() as $r) {
                $payments[(string)$r['sale_fact_id']][]=$r;
            }
            foreach($this->cashFacts($stores,'repayment_payment')->join('cashier_v3_payment_sale_allocation_fact repayment_allocation',"repayment_allocation.tenant_id=repayment_payment.tenant_id AND repayment_allocation.payment_fact_id=repayment_payment.fact_id AND repayment_allocation.status='effective'")
                ->whereIn('repayment_allocation.sale_fact_id',$saleFactIds)->where('repayment_payment.source_document_type','debt_repayment')->whereBetween('repayment_payment.business_date',[$range['start'],$range['end']])->where('repayment_payment.status','effective')
                ->fieldRaw('repayment_allocation.sale_fact_id,repayment_payment.fact_id payment_fact_id,repayment_payment.order_id repayment_order_id,repayment_payment.business_date,repayment_payment.organization_id,repayment_payment.organization_path_snapshot,repayment_payment.organization_name_snapshot,repayment_payment.store_name_snapshot,repayment_payment.member_id,repayment_payment.member_name_snapshot,repayment_payment.source_line_id,repayment_payment.source_document_type,SUM(repayment_allocation.amount_cents) amount_cents')
                ->group('repayment_allocation.sale_fact_id,repayment_payment.fact_id,repayment_payment.order_id,repayment_payment.business_date,repayment_payment.organization_id,repayment_payment.organization_path_snapshot,repayment_payment.organization_name_snapshot,repayment_payment.store_name_snapshot,repayment_payment.member_id,repayment_payment.member_name_snapshot,repayment_payment.source_line_id,repayment_payment.source_document_type')->select()->toArray() as $r) {
                $repaymentRows[(string)$r['sale_fact_id']][]=$r;
            }
        }
        // 补交按补交日期入报表，原单可能早于当前查询区间；为这类补交补载
        // 原销售明细作为展示锚点，但不把原单日期重新纳入当前结果。
        $allRepaymentRows=$this->cashFacts($stores,'repayment_payment_all')->join('cashier_v3_payment_sale_allocation_fact repayment_allocation',"repayment_allocation.tenant_id=repayment_payment_all.tenant_id AND repayment_allocation.payment_fact_id=repayment_payment_all.fact_id AND repayment_allocation.status='effective'")
            ->where('repayment_payment_all.source_document_type','debt_repayment')->whereBetween('repayment_payment_all.business_date',[$range['start'],$range['end']])->where('repayment_payment_all.status','effective')
            ->fieldRaw('repayment_allocation.sale_fact_id,repayment_payment_all.fact_id payment_fact_id,repayment_payment_all.order_id repayment_order_id,repayment_payment_all.business_date,SUM(repayment_allocation.amount_cents) amount_cents')
            ->group('repayment_allocation.sale_fact_id,repayment_payment_all.fact_id,repayment_payment_all.order_id,repayment_payment_all.business_date')->select()->toArray();
        foreach($allRepaymentRows as $item){$saleKey=(string)$item['sale_fact_id'];$duplicate=false;foreach($repaymentRows[$saleKey]??[] as $existing)if((string)($existing['payment_fact_id']??'')===(string)$item['payment_fact_id']){$duplicate=true;break;}if(!$duplicate)$repaymentRows[$saleKey][]=$item;}
        $missingAnchorIds=array_values(array_diff(array_keys($repaymentRows),$saleFactIds));
        if($missingAnchorIds){$anchorQuery=$this->scope(Db::name('cashier_v3_sale_fact')->alias('s'),$stores,'s');$this->normalDataScope()->excludeVoidedSalesOrderFacts($anchorQuery,'s.tenant_id','s.order_id');$anchorRows=$anchorQuery->leftJoin('cashier_v3_sales_order o','o.order_id=s.order_id')->leftJoin('user u','u.uid=s.member_id')->whereIn('s.fact_id',$missingAnchorIds)->where('s.status','effective')->where('s.fact_direction','forward')->fieldRaw('s.fact_id,s.store_id,s.organization_id,s.organization_path_snapshot,s.organization_name_snapshot,s.store_name_snapshot,s.business_date,s.order_id,s.source_line_id,s.member_id,s.member_name_snapshot,s.business_source_label_snapshot,s.item_name_snapshot,s.sale_amount_cents,s.debt_amount_cents,u.phone,MAX(o.order_note) remark')->group('s.fact_id')->select()->toArray();foreach($anchorRows as&$anchor)$anchor['_anchor_only']=true;unset($anchor);$rows=array_merge($rows,$anchorRows);$orderIds=array_values(array_unique(array_filter(array_column($rows,'order_id'))));$saleFactIds=array_values(array_unique(array_filter(array_column($rows,'fact_id'))));$lineIds=array_values(array_unique(array_filter(array_column($rows,'source_line_id'))));$personnel=$this->newCustomerPersonnel($stores,$orderIds,$lineIds);$guides=$personnel['guides'];$salespeople=$personnel['salespeople'];$managers=$personnel['managers'];$manual=$this->annotations('new_customer_analysis',$stores,$lineIds);}
        $repaymentOrderIds=[];foreach($repaymentRows as $items)foreach($items as $item)$repaymentOrderIds[]=(string)$item['repayment_order_id'];
        $repaymentSalespeople=$this->newCustomerRepaymentSalespeople($stores,array_values(array_unique($repaymentOrderIds)));
        $manual=$this->annotations('new_customer_analysis',$stores,$lineIds);$baseRowsByFact=[];
        foreach($rows as &$row){
            $line=(string)$row['source_line_id'];$order=(string)$row['order_id'];$salesFact=(string)$row['fact_id'];
            $guideRows=$guides[$order]??[];$salespersonRows=$salespeople[$line]??[];$managerRows=$managers[$line]??[];
            $anchorOnly=!empty($row['_anchor_only']);
            $hasRepayment=!empty($repaymentRows[$salesFact]);
            if(!$anchorOnly&&!$hasRepayment&&!$this->newCustomerPersonnelMatches($guideRows,$salespersonRows,$managerRows,$input)){ $row['_exclude_personnel']=true; continue; }
            if(!$anchorOnly&&!$hasRepayment&&!$guideRows&&!$salespersonRows&&!$managerRows){$row['_exclude_personnel']=true;continue;}
            $craftNames=[];$fee=0;foreach($personnel['performance'][$line]??[] as $f){if((string)$f['performance_type']==='labor_performance_allocated'){$craftNames[]=(string)$f['employee_name_snapshot'];$fee+=(int)$f['labor_fee_amount_cents'];}}
            $row['division_name']=(string)$row['organization_name_snapshot'];$row['customer']=(string)$row['member_name_snapshot'];
            $row['guide']=$this->newCustomerNames($guideRows,'guide_employee_id','guide_employee_name_snapshot');
            $row['salesperson']=$this->newCustomerNames($salespersonRows,'employee_id','employee_name_snapshot');
            $row['sales_manager']=$this->newCustomerNames($managerRows,'sales_manager_employee_id','sales_manager_name_snapshot');
            $row['source']=(string)$row['business_source_label_snapshot'];$row['age']='';$row['care_project']=(string)$row['item_name_snapshot'];$row['craftsman']=implode('、',array_unique(array_filter($craftNames)));
            $experienceCardAmount=$manual[$line]['experience_card_amount']??[];$row['experience_card_amount']=array_key_exists('value',$experienceCardAmount)?((string)$experienceCardAmount['value']===''?'':$this->money((int)$experienceCardAmount['value'])):$this->money((int)$row['sale_amount_cents']);$row['experience_card_amount_version']=(int)($experienceCardAmount['version']??0);$row['care_duration']=$manual[$line]['care_duration']['value']??'';$row['care_duration_version']=(int)($manual[$line]['care_duration']['version']??0);$row['labor_fee']=$this->money($fee);$row['guide_effective_count']=count($guideRows);$row['guide_performance_round']=implode('、',array_unique(array_map(static fn($g)=>(string)$g['guide_round_no'],$guideRows)));
            $collected=0;$hasDebt=(int)$row['debt_amount_cents']>0;foreach($payments[$salesFact]??[] as $p)if(!in_array((string)$p['source_document_type'],['debt_repayment','recharge_debt_repayment'],true))$collected+=(int)$p['amount_cents'];$row['full_payment']=$this->money($hasDebt?0:$collected);$row['deposit_payment']=$this->money($hasDebt?$collected:0);$row['cleared_payment']=$this->money(0);$row['annotation_subject_key']=$line;$row['annotation_subject_type']='sale_line';$baseRowsByFact[$salesFact]=$row;if(!empty($row['_anchor_only']))$row['_exclude_anchor']=true;
        }unset($row);
        $rows=array_values(array_filter($rows,static fn(array $row):bool=>empty($row['_exclude_personnel'])&&empty($row['_exclude_anchor'])));foreach($rows as &$row){unset($row['_exclude_personnel'],$row['_exclude_anchor'],$row['_anchor_only']);}unset($row);
        foreach($repaymentRows as $saleFact=>$items){$base=$baseRowsByFact[$saleFact]??null;if(!$base)continue;foreach($items as $item){$repay=$base;$repay['business_date']=(string)$item['business_date'];$repay['source']='补交';$repay['guide']='';$repay['sales_manager']='';$repay['salesperson']=$this->newCustomerNames($repaymentSalespeople[(string)$item['repayment_order_id']]??[],'employee_id','employee_name_snapshot');$repay['craftsman']='';$repay['experience_card_amount']='';$repay['labor_fee']=$this->money(0);$repay['guide_effective_count']=0;$repay['guide_performance_round']='';$repay['full_payment']=$this->money(0);$repay['deposit_payment']=$this->money(0);$repay['cleared_payment']=$this->money((int)$item['amount_cents']);$repay['annotation_subject_key']=(string)$item['payment_fact_id'];$repay['annotation_subject_type']='debt_repayment';$rows[]=$repay;}}
        return $this->result('新客明细表',$this->columns(['division_name'=>'分公司','store_name_snapshot'=>'门店','business_date'=>'日期','customer'=>'顾客','guide'=>'导购','salesperson'=>'销售人','sales_manager'=>'销售经理','source'=>'来源','age'=>'年龄','phone'=>'手机号码','care_project'=>'护理项目','craftsman'=>'护理手艺人','experience_card_amount'=>'体验卡金额','care_duration'=>'手艺人护理时长','labor_fee'=>'手艺人手工费','guide_effective_count'=>'导购有效人次','guide_performance_round'=>'导购业绩次数','full_payment'=>'全款业绩','deposit_payment'=>'定金业绩','cleared_payment'=>'清款业绩','remark'=>'备注']),$rows,array_merge($input,['_guide_filter_options'=>$this->guideFilterOptions($stores,$range)]));
    }

    private function newCustomerSummary(array $stores,array $range,array $input):array
    {
        $records=[];$year=(int)substr($range['end'],0,4);$currentYear=(int)date('Y');$lastMonth=$year===$currentYear?min((int)date('n'),(int)substr($range['end'],5,2)):12;$excluded=$this->sourceIdsMany(['A','H']);$guideId=(int)($input['guide_id']??0);
        // 现金列以 payment_fact.business_date 为唯一统计时间，因此退款反向事实
        // 只落在退款实际发生月份，不回写原订单月份。
        $query=$this->cashFacts($stores)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective')->where('member_id','>',0);
        $payments=$query->fieldRaw('fact_id,store_id,organization_name_snapshot division_name,store_name_snapshot store_name,member_id,member_name_snapshot member_name,order_id,business_source_primary_id,business_source_label_snapshot source,source_document_type,business_date,amount_cents')->select()->toArray();
        $regular=[];$repaymentFactIds=[];foreach($payments as $row){if((string)$row['source_document_type']==='debt_repayment')$repaymentFactIds[]=(string)$row['fact_id'];else$regular[]=$row;}
        $repayments=[];$orderIds=array_values(array_unique(array_column($regular,'order_id')));
        if($repaymentFactIds){foreach($this->cashFacts($stores,'summary_repayment')->join('cashier_v3_payment_sale_allocation_fact a',"a.tenant_id=summary_repayment.tenant_id AND a.payment_fact_id=summary_repayment.fact_id AND a.status='effective'")->join('cashier_v3_sale_fact origin',"origin.tenant_id=a.tenant_id AND origin.fact_id=a.sale_fact_id AND origin.status='effective' AND origin.fact_direction='forward'")->whereIn('summary_repayment.fact_id',$repaymentFactIds)->where('summary_repayment.source_document_type','debt_repayment')->where('summary_repayment.status','effective')->fieldRaw('summary_repayment.fact_id payment_fact_id,summary_repayment.order_id repayment_order_id,summary_repayment.store_id,summary_repayment.organization_name_snapshot division_name,summary_repayment.store_name_snapshot store_name,summary_repayment.member_id,summary_repayment.member_name_snapshot member_name,summary_repayment.business_date,a.sale_fact_id,origin.order_id origin_order_id,origin.source_line_id,SUM(a.amount_cents) amount_cents')->group('summary_repayment.fact_id,summary_repayment.order_id,summary_repayment.store_id,summary_repayment.organization_name_snapshot,summary_repayment.store_name_snapshot,summary_repayment.member_id,summary_repayment.member_name_snapshot,summary_repayment.business_date,a.sale_fact_id,origin.order_id,origin.source_line_id')->select()->toArray() as $row){$repayments[]=$row;$orderIds[]=(string)$row['origin_order_id'];}}
        $orderIds=array_values(array_unique(array_filter($orderIds)));$saleRowsByOrder=[];$debts=[];$lineIds=[];
        if($orderIds){foreach($this->scope(Db::name('cashier_v3_sale_fact'),$stores)->whereIn('order_id',$orderIds)->where('fact_direction','forward')->where('status','effective')->select()->toArray() as $sale){$saleRowsByOrder[(string)$sale['order_id']][]=$sale;$lineIds[]=(string)$sale['source_line_id'];$debts[(string)$sale['order_id']]=(int)($debts[(string)$sale['order_id']]??0)+(int)$sale['debt_amount_cents'];}}
        $personnel=$this->newCustomerPersonnel($stores,$orderIds,array_values(array_unique($lineIds)));$guides=$personnel['guides'];$salespeople=$personnel['salespeople'];$managers=$personnel['managers'];
        foreach($regular as $row){if(in_array((string)$row['source_document_type'],['debt_repayment','recharge_debt_repayment'],true))continue;$sourceId=(int)$row['business_source_primary_id'];if($excluded&&in_array($sourceId,$excluded,true))continue;$order=(string)$row['order_id'];$guideRows=$guides[$order]??[];$salespersonRows=[];$managerRows=[];foreach($saleRowsByOrder[$order]??[] as $sale){$line=(string)$sale['source_line_id'];$salespersonRows=array_merge($salespersonRows,$salespeople[$line]??[]);$managerRows=array_merge($managerRows,$managers[$line]??[]);} $suffix=((int)($debts[$order]??0)>0)?'deposit':'full';$this->newCustomerSummaryAddRow($records,$row,$guideRows,$salespersonRows,$managerRows,(int)$row['amount_cents'],$suffix,1,(string)$row['source'],$sourceId,$input);}
        $repaymentSalespeople=$this->newCustomerRepaymentSalespeople($stores,array_values(array_unique(array_column($repayments,'repayment_order_id'))));foreach($repayments as $row)$this->newCustomerSummaryAddRow($records,['member_id'=>$row['member_id'],'member_name'=>$row['member_name'],'division_name'=>$row['division_name'],'store_name'=>$row['store_name'],'business_date'=>$row['business_date']],[], $repaymentSalespeople[(string)$row['repayment_order_id']]??[],[],(int)$row['amount_cents'],'cleared',0,'补交',0,$input);
        $columns=$this->columns(['system_name'=>'系统名称','division_name'=>'分公司','store_name'=>'门店','member'=>'会员','guide'=>'导购','salesperson'=>'销售人','sales_manager'=>'销售经理','source'=>'来源']);$groups=[];foreach(range(1,$lastMonth)as$m){$keys=[];foreach([['count','人次'],['full','全款'],['deposit','定金'],['cleared','清款']]as$d){$key='month_'.$m.'_'.$d[0];$columns[]=['key'=>$key,'label'=>$d[1],'group_label'=>$m.'月'];$keys[]=$key;}$groups[]=['label'=>$m.'月','column_keys'=>$keys];}$columns[]=['key'=>'annual_cash','label'=>'全年现金业绩'];
        foreach($records as &$row){$row['_drilldown']=[];foreach(range(1,$lastMonth)as$m){$countKey='month_'.$m.'_count';$row[$countKey]=(int)($row[$countKey]??0);foreach(['full','deposit','cleared']as$s){$key='month_'.$m.'_'.$s;$row[$key]=$this->money((int)($row[$key.'_cents']??0));}$monthStart=max($range['start'],sprintf('%04d-%02d-01',$year,$m));$monthEnd=min($range['end'],date('Y-m-t',strtotime($monthStart)));$config=['report'=>'new_customer_analysis','params'=>['start_date'=>$monthStart,'end_date'=>$monthEnd],'param_map'=>['member_id'=>'member_id','source_id'=>'source_id','source_label'=>'source_label','guide_id'=>'guide_id','salesperson_id'=>'salesperson_id','sales_manager_id'=>'sales_manager_id']];if($row[$countKey]!==0)$row['_drilldown'][$countKey]=$config;foreach(['full','deposit','cleared']as$s){$key='month_'.$m.'_'.$s;if((int)($row[$key.'_cents']??0)!==0)$row['_drilldown'][$key]=$config;}}$row['annual_cash']=$this->money((int)$row['annual_cash_cents']);if((int)$row['annual_cash_cents']!==0)$row['_drilldown']['annual_cash']=['report'=>'new_customer_analysis','param_map'=>['member_id'=>'member_id','source_id'=>'source_id','source_label'=>'source_label','guide_id'=>'guide_id','salesperson_id'=>'salesperson_id','sales_manager_id'=>'sales_manager_id']];}unset($row);
        return $this->result('新客汇总表',$columns,array_values($records),array_merge($input,['_guide_filter_options'=>$this->guideFilterOptions($stores,$range)]),$groups,['natural_year'=>$year,'visible_months'=>$lastMonth]);
    }

    private function salespersonBeautyLargeOrder(array $stores,array $range,array $input):array
    {
        // 大单的唯一判断粒度是“销售人（或销售经理）+ 自然月 + 生美二级分类”。
        // 卡项不能以外层卡分类归集；只读取结账时冻结的卡内分类分摊事实，避免把
        // 一张混合卡的全部金额错误归入某一个生美分类。
        $cardCategorySql=Db::name('cashier_v3_card_sale_category_allocation_fact')->alias('ccf')
            ->where('ccf.status','effective')
            ->whereIn('ccf.store_id',$stores)
            ->whereBetween('ccf.business_date',[$range['start'],$range['end']])
            ->fieldRaw('ccf.tenant_id,ccf.sale_fact_id,SUM(ccf.sale_amount_cents) allocated_sale_total_cents,SUM(ccf.cash_performance_amount_cents) allocated_cash_total_cents')
            ->group('ccf.tenant_id,ccf.sale_fact_id')->buildSql();
        $categoryPathSql = "COALESCE(NULLIF(ccf.category_path_snapshot,''),ccf.category_name_snapshot)";
        $beautyCategorySql = Db::name('cashier_v3_card_sale_category_allocation_fact')->alias('ccf')
            ->where('ccf.status','effective')
            ->whereIn('ccf.store_id',$stores)
            ->whereBetween('ccf.business_date',[$range['start'],$range['end']])
            ->whereRaw("TRIM(SUBSTRING_INDEX({$categoryPathSql}, '/', 1))='生美'")
            ->whereRaw("LOCATE('/', {$categoryPathSql})>0")
            ->fieldRaw("ccf.tenant_id,ccf.sale_fact_id,TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX({$categoryPathSql}, '/', 2), '/', -1)) beauty_category,SUM(ccf.cash_performance_amount_cents) beauty_cash_amount_cents")
            ->group("ccf.tenant_id,ccf.sale_fact_id,TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX({$categoryPathSql}, '/', 2), '/', -1))")
            ->buildSql();
        $facts=$this->participantEmployeeFact($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_performance_fact')->alias('p'),$stores,'p'),'p',$input,$range),'p.employee_id')
            ->join('cashier_v3_sale_fact s','s.tenant_id=p.tenant_id AND s.store_id=p.store_id AND s.source_line_id=p.source_line_id AND s.status=\'effective\'')
            ->leftJoin([$cardCategorySql=>'cc'],'cc.tenant_id=s.tenant_id AND cc.sale_fact_id=s.fact_id')
            ->join([$beautyCategorySql=>'bc'],'bc.tenant_id=s.tenant_id AND bc.sale_fact_id=s.fact_id')
            ->whereBetween('p.business_date',[$range['start'],$range['end']])->where('p.status','effective')->where('p.performance_type','sales_performance_allocated');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($facts,'p.tenant_id','p.order_id');
        $facts=$facts
            ->whereRaw('cc.allocated_sale_total_cents=s.sale_amount_cents AND cc.allocated_cash_total_cents>0 AND bc.beauty_cash_amount_cents>0')
            ->fieldRaw('p.tenant_id,p.store_id,p.organization_id,p.organization_path_snapshot,MAX(p.organization_name_snapshot) division_name,MAX(p.store_name_snapshot) store_name,p.business_date,p.employee_id,p.order_id,p.source_line_id,MAX(p.employee_name_snapshot) salesperson,p.member_id,bc.beauty_category,SUM(ROUND(p.amount_cents*bc.beauty_cash_amount_cents/cc.allocated_cash_total_cents)) amount_cents')
            ->group('p.tenant_id,p.store_id,p.organization_id,p.organization_path_snapshot,p.business_date,p.employee_id,p.order_id,p.source_line_id,p.member_id,bc.beauty_category')->order('p.business_date','desc')->select()->toArray();
        foreach ($facts as &$fact) $fact['role_snapshot'] = 'salesperson';
        unset($fact);
        // Manager facts use checkout-line IDs, while sale facts use order-line
        // IDs. Join through the immutable order-line bridge and keep manager
        // rows separate from salesperson rows; their ratios and amounts are
        // independent snapshots.
        // A small number of pre-fix manager facts were persisted with a zero
        // amount after their order line had already moved to `settled`.  Keep
        // that immutable source record untouched and recover its authoritative
        // base from the settled collection batch in the report projection.
        // New checkouts persist sm.amount_cents directly; the fallback only
        // applies to a zero manager snapshot with a positive collected cash
        // performance amount.
        $managerPaymentSql = Db::name('cashier_v3_payment_collection_batch')->alias('smp')
            ->whereIn('smp.batch_status', ['effective', 'settled'])
            ->fieldRaw('smp.tenant_id,smp.sales_order_id,MAX(smp.cash_performance_amount_cents) cash_performance_amount_cents')
            ->group('smp.tenant_id,smp.sales_order_id')
            ->buildSql();
        $managerAmountSql = 'CASE WHEN sm.amount_cents<>0 THEN sm.amount_cents '
            . 'WHEN COALESCE(smp.cash_performance_amount_cents,0)>0 THEN ROUND('
            . 'smp.cash_performance_amount_cents*sm.allocation_weight_numerator/'
            . 'NULLIF(sm.allocation_weight_denominator,0)) ELSE 0 END';
        $managerFacts = $this->participantEmployeeFact($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_sales_manager_fact')->alias('sm'),$stores,'sm'),'sm',$input,$range),'sm.sales_manager_employee_id')
            ->join('cashier_v3_sales_order_line sol','sol.tenant_id=sm.tenant_id AND sol.order_id=sm.order_id AND sol.checkout_line_id=sm.source_line_id')
            ->join('cashier_v3_sale_fact s','s.tenant_id=sol.tenant_id AND s.store_id=sol.store_id AND s.source_line_id=sol.order_line_id AND s.status=\'effective\'')
            ->leftJoin([$managerPaymentSql=>'smp'],'smp.tenant_id=sm.tenant_id AND smp.sales_order_id=sm.order_id')
            ->leftJoin([$cardCategorySql=>'cc'],'cc.tenant_id=s.tenant_id AND cc.sale_fact_id=s.fact_id')
            ->join([$beautyCategorySql=>'bc'],'bc.tenant_id=s.tenant_id AND bc.sale_fact_id=s.fact_id')
            ->whereBetween('sm.business_date',[$range['start'],$range['end']])->where('sm.status','effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($managerFacts,'sm.tenant_id','sm.order_id');
        $managerFacts = $managerFacts
            ->whereRaw('cc.allocated_sale_total_cents=s.sale_amount_cents AND cc.allocated_cash_total_cents>0 AND bc.beauty_cash_amount_cents>0')
            ->fieldRaw("sm.tenant_id,sm.store_id,sm.organization_id,sm.sales_manager_employee_id employee_id,MAX(sm.sales_manager_name_snapshot) salesperson,sm.order_id,sol.order_line_id source_line_id,sm.member_id,sm.business_date,bc.beauty_category,MAX(s.organization_path_snapshot) organization_path_snapshot,MAX(s.organization_name_snapshot) division_name,MAX(s.store_name_snapshot) store_name,SUM(ROUND(({$managerAmountSql})*bc.beauty_cash_amount_cents/cc.allocated_cash_total_cents)) amount_cents")
            ->group('sm.tenant_id,sm.store_id,sm.organization_id,sm.sales_manager_employee_id,sm.order_id,sol.order_line_id,sm.member_id,sm.business_date,bc.beauty_category')->select()->toArray();
        foreach ($managerFacts as &$managerFact) $managerFact['role_snapshot'] = 'sales_manager';
        unset($managerFact);
        $facts = array_merge($facts, $managerFacts);
        usort($facts, static function (array $a, array $b): int {
            return strcmp((string)$a['business_date'], (string)$b['business_date'])
                ?: strcmp((string)$a['source_line_id'], (string)$b['source_line_id']);
        });
        $awards = self::beautyLargeOrderMonthlyAwards($facts);
        $cumulative = [];
        foreach ($facts as $index => &$row) {
            $month = substr((string)$row['business_date'], 0, 7);
            $key = implode('|', [(string)$row['tenant_id'], (string)($row['role_snapshot'] ?? ''), (string)$row['employee_id'], $month, (string)$row['beauty_category']]);
            $after = (int)($cumulative[$key] ?? 0) + (int)$row['amount_cents'];
            $cumulative[$key] = $after;
            $row['daily_cash'] = $this->money((int)$row['amount_cents']);
            $row['cumulative_cash'] = $this->money($after);
            $row['share_30000_before'] = '';
            $row['share_30000_after'] = '';
            $row['share_50000_before'] = '';
            foreach (range(1, 5) as $i) $row['share_50000_after_' . $i] = '';
            $row['remark'] = '';
            if (isset($awards[$index])) {
                $award = $awards[$index];
                if ((int)$award['threshold_cents'] === 5000000) $row['share_50000_before'] = $this->money((int)$award['amount_cents']);
                else $row['share_30000_before'] = $this->money((int)$award['amount_cents']);
                $row['remark'] = '本月达标分类：' . (string)$award['beauty_category'];
            }
        }
        unset($row);
        usort($facts, static function (array $a, array $b): int {
            return strcmp((string)$b['business_date'], (string)$a['business_date'])
                ?: strcmp((string)$b['source_line_id'], (string)$a['source_line_id']);
        });
        $columns=$this->columns(['division_name'=>'分公司','store_name'=>'门店','business_date'=>'成交日期','salesperson'=>'销售人/销售经理','beauty_category'=>'生美二级分类','daily_cash'=>'当日现金业绩','cumulative_cash'=>'当月分类累计现金业绩','share_30000_before'=>'3万生美卡项分成前','share_30000_after'=>'3万生美卡项分成后','share_50000_before'=>'5万生美卡项分成前']);foreach(range(1,5)as$i)$columns[]=['key'=>'share_50000_after_'.$i,'label'=>'5万生美卡项分成后'];$columns[]=['key'=>'remark','label'=>'备注'];
        return $this->result('销售人生美大单统计表',$columns,$facts,$input);
    }

    /**
     * 每名销售人员（或销售经理）每月只可落一笔大单：在所有达标的生美二级
     * 分类中选累计现金业绩最高的一类。相同金额按分类名稳定排序，避免数据顺序
     * 变化导致重复或漂移。奖项落在该分类当月最后一笔成交上，便于追溯。
     *
     * @return array<int,array{beauty_category:string,amount_cents:int,threshold_cents:int}>
     */
    private static function beautyLargeOrderMonthlyAwards(array $facts): array
    {
        $monthly = [];
        foreach ($facts as $index => $fact) {
            $category = trim((string)($fact['beauty_category'] ?? ''));
            $month = substr((string)($fact['business_date'] ?? ''), 0, 7);
            if ($category === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) continue;
            $scope = implode('|', [(string)($fact['tenant_id'] ?? ''), (string)($fact['role_snapshot'] ?? ''), (string)($fact['employee_id'] ?? ''), $month]);
            $categoryKey = $scope . '|' . $category;
            if (!isset($monthly[$scope][$categoryKey])) {
                $monthly[$scope][$categoryKey] = ['beauty_category' => $category, 'amount_cents' => 0, 'last_index' => $index];
            }
            $monthly[$scope][$categoryKey]['amount_cents'] += (int)($fact['amount_cents'] ?? 0);
            $monthly[$scope][$categoryKey]['last_index'] = $index;
        }
        $awards = [];
        foreach ($monthly as $categories) {
            $candidates = array_values($categories);
            usort($candidates, static function (array $left, array $right): int {
                return ((int)$right['amount_cents'] <=> (int)$left['amount_cents'])
                    ?: strcmp((string)$left['beauty_category'], (string)$right['beauty_category']);
            });
            $winner = $candidates[0] ?? null;
            if (!is_array($winner) || (int)$winner['amount_cents'] < 3000000) continue;
            $awards[(int)$winner['last_index']] = [
                'beauty_category' => (string)$winner['beauty_category'],
                'amount_cents' => (int)$winner['amount_cents'],
                'threshold_cents' => (int)$winner['amount_cents'] >= 5000000 ? 5000000 : 3000000,
            ];
        }
        return $awards;
    }

    /** Read-only refund facts for customer analysis; uses the same successful
     * refund operation source as the platform refund ledger. */
    public function customerRefundRows(array $stores, array $range, array $input = []): array
    {
        $scope = is_array($input['_report_scope'] ?? null) ? $input['_report_scope'] : [];
        if ((string)($scope['mode'] ?? '') === 'self_participant') {
            $this->participantEmployeeId = max(0, (int)($scope['employee_id'] ?? 0));
            if ($this->participantEmployeeId <= 0) {
                throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
            }
        }
        // Aggregates and exports must read every authorized refund fact; the
        // ledger UI normally paginates, so explicitly request the shared
        // all-rows mode without changing filters or permission scope.
        $result = $this->storeRefundLedger($stores, $range, array_merge($input, ['_internal_all' => true, 'page' => 1]));
        return (array)($result['records'] ?? []);
    }

    private function storeRefundLedger(array $stores,array $range,array $input):array
    {
        $rows=$this->participantOrder($this->scope(Db::name('cashier_v3_order_lifecycle_operation')->alias('r'),$stores,'r'),'r.source_order_id')
            ->join('cashier_v3_sales_order o','o.order_id=r.source_order_id AND o.tenant_id=r.tenant_id')
            ->whereBetween('r.business_date',[$range['start'],$range['end']])
            ->where('r.source_type','sales')->where('r.operation_type','refund')->where('r.status','succeeded')
            ->field('r.operation_id,r.store_id,r.business_date,r.business_date refund_date,r.source_order_id order_id,r.reason_snapshot refund_reason,r.request_json,r.cash_refund_cents refund_amount_cents,r.restored_principal_cents,r.restored_bonus_cents,o.organization_id,o.organization_path_snapshot,o.organization_name_snapshot market,o.store_name_snapshot store_name,o.member_id,o.member_name_snapshot customer,o.business_date original_sale_date,o.order_note original_remark')
            ->order('r.business_date','desc')->order('r.id','desc')->select()->toArray();
        foreach($rows as &$row){$request=json_decode((string)$row['request_json'],true);$names=[];foreach((array)($request['refundLines']??[])as$line){$name=trim((string)($line['itemName']??''));if($name!=='')$names[]=$name;}$row['refund_items']=implode('、',array_values(array_unique($names)));$row['refund_amount']=$this->money((int)$row['refund_amount_cents']);$row['refund_remark']=trim((string)$row['refund_reason'])?:trim((string)$row['original_remark']);}unset($row);
        return $this->result('院店退款台账',$this->columns(['market'=>'市场','store_name'=>'院店','customer'=>'顾客姓名','refund_date'=>'退款申请时间','refund_items'=>'退款项目','original_sale_date'=>'原销售日期','refund_amount'=>'退款金额','refund_remark'=>'退款原因']),$rows,$input,[],['refund_amount_cents'=>$this->sumField($rows,'refund_amount_cents')]);
    }

    private function primarySources():array{return Db::name('cashier_v3_business_source')->where('parent_id',0)->where('status',1)->order('sort','asc')->order('id','asc')->field('id,name,sort')->select()->toArray();}
    /**
     * Read completed service facts once and resolve their source without making
     * sales_order an existence gate. New rows normally resolve by checkout
     * request; historical/card-service rows can resolve through the immutable
     * card-purchase receipt, then the origin order and its payment fact. A
     * successful service void is excluded as a reversal, and service_fact_id
     * remains the deduplication grain.
     */
    private function marketServiceVisitFacts(array $stores, array $range, array $input): array
    {
        $query = $this->participantCheckout(
            $this->applyOrganizationFilters(
                $this->scope(Db::name('cashier_v3_entitlement_service_fact')->alias('sv'), $stores, 'sv'),
                'sv',
                $input,
                $range
            ),
            'sv.checkout_request_id'
        )
            ->leftJoin(
                'cashier_v3_entitlement_writeoff_fact wf',
                "wf.tenant_id=sv.tenant_id AND wf.checkout_request_id=sv.checkout_request_id AND wf.source_line_id=sv.source_line_id AND wf.status='effective'"
            )
            ->leftJoin(
                'cashier_v3_service_record_void_operation vo',
                "vo.tenant_id=sv.tenant_id AND vo.service_fact_id=sv.id AND vo.status='succeeded'"
            )
            ->leftJoin(
                'cashier_v3_sales_order o1',
                'o1.tenant_id=sv.tenant_id AND o1.store_id=sv.store_id AND o1.checkout_request_id=sv.checkout_request_id'
            )
            ->leftJoin(
                'cashier_v3_sales_order o2',
                'o2.tenant_id=sv.tenant_id AND o2.store_id=sv.store_id AND o2.order_id=wf.origin_order_id'
            )
            ->leftJoin(
                'cashier_v3_card_purchase_receipt cr',
                "cr.tenant_id=sv.tenant_id AND cr.store_id=sv.store_id AND cr.legacy_order_id=wf.origin_order_id AND cr.card_holder_id=wf.holder_id AND cr.status='completed'"
            )
            ->leftJoin(
                'cashier_v3_sales_order o3',
                'o3.tenant_id=sv.tenant_id AND o3.store_id=sv.store_id AND o3.order_id=cr.sales_order_id'
            )
            ->leftJoin(
                'cashier_v3_payment_fact p1',
                "p1.tenant_id=sv.tenant_id AND p1.store_id=sv.store_id AND p1.checkout_request_id=sv.checkout_request_id AND p1.status='effective'"
            )
            ->leftJoin(
                'cashier_v3_payment_fact p2',
                "p2.tenant_id=sv.tenant_id AND p2.store_id=sv.store_id AND p2.order_id=wf.origin_order_id AND p2.status='effective'"
            )
            ->leftJoin(
                'cashier_v3_payment_fact p3',
                "p3.tenant_id=sv.tenant_id AND p3.store_id=sv.store_id AND p3.order_id=cr.sales_order_id AND p3.status='effective'"
            )
            ->whereBetween('sv.business_date', [$range['start'], $range['end']])
            ->where('sv.service_status', 'completed')
            ->whereNull('vo.id');
        $this->normalDataScope()->excludeVoidedSalesOrderServices($query, 'sv');
        $rows = $query
            ->fieldRaw(
                'sv.store_id,sv.service_fact_id,sv.checkout_request_id,'
                . 'COALESCE(NULLIF(wf.origin_order_id,0),0) origin_order_id,'
                . "COALESCE(NULLIF(MAX(o1.order_id),''),NULLIF(MAX(cr.sales_order_id),''),'') matched_order_id,"
                . 'COALESCE(NULLIF(MAX(o1.business_source_primary_id),0),'
                . 'NULLIF(MAX(o3.business_source_primary_id),0),'
                . 'NULLIF(MAX(o2.business_source_primary_id),0),'
                . 'NULLIF(MAX(p1.business_source_primary_id),0),'
                . 'NULLIF(MAX(p3.business_source_primary_id),0),'
                . 'NULLIF(MAX(p2.business_source_primary_id),0),0) business_source_primary_id'
            )
            ->group('sv.store_id,sv.service_fact_id,sv.checkout_request_id,wf.origin_order_id,cr.sales_order_id')
            ->select()
            ->toArray();
        return array_values(array_filter($rows, static function (array $row): bool {
            return (int)($row['business_source_primary_id'] ?? 0) > 0;
        }));
    }
    private function marketEffectiveMemberKeys(array $rows):array
    {
        $sources = [];
        foreach ($this->primarySources() as $source) $sources[(int)$source['id']] = $source;
        $amounts = [];
        foreach ($rows as $row) {
            $memberId = (int)($row['member_id'] ?? 0);
            $sourceId = (int)($row['business_source_primary_id'] ?? 0);
            if ($memberId <= 0 || !isset($sources[$sourceId])) continue;
            $key = (int)$row['store_id'] . '|' . $sourceId . '|' . $memberId;
            $amounts[$key] = (int)($amounts[$key] ?? 0) + (int)($row['amount_cents'] ?? 0);
        }
        $keys = [];
        foreach ($amounts as $key => $amount) {
            $parts = explode('|', $key);
            $source = $sources[(int)$parts[1]];
            $threshold = $this->sourcePrefix($source) === 'A' ? 100000 : 50000;
            if ($amount >= $threshold) $keys[$key] = true;
        }
        return $keys;
    }
    private function marketDetailSummaryRow(array $rows):array
    {
        $walkIn = $visits = $amountCents = 0; $effectiveMembers = [];
        foreach ($rows as $row) {
            $walkIn += (int)($row['walk_in'] ?? 0);
            $visits += (int)($row['visits'] ?? 0);
            $amountCents += (int)($row['amount_cents'] ?? 0);
            if ((int)($row['effective_people'] ?? 0) === 1 && (int)($row['member_id'] ?? 0) > 0) $effectiveMembers[(int)$row['member_id']] = true;
        }
        return [
            'order_no_snapshot' => '合计', 'store_name_snapshot' => '-', 'member_name_snapshot' => '-', 'member_phone' => '-',
            'dimension' => '-', 'walk_in' => $walkIn, 'visits' => $visits, 'effective_people' => count($effectiveMembers),
            'amount' => $this->money($amountCents), 'registered_date' => '-', 'reviewer' => '-', 'reviewed_at' => '-',
            'creator_name' => '-', 'created_at' => '-',
        ];
    }
    private function sourceIds(string $prefix):array{return $this->sourceIdsMany([$prefix]);}
    private function sourceIdsMany(array $prefixes):array{$ids=[];foreach($this->primarySources()as$s)if(in_array($this->sourcePrefix($s),$prefixes,true))$ids[]=(int)$s['id'];return $ids;}
    private function sourcePrefix(array $source):string{$name=trim((string)($source['name']??''));return preg_match('/^([A-Z])/u',$name,$m)?$m[1]:'';}
    private function paymentMethods():array{$rows=Db::name('cashier_v3_payment_method_config')->where('status',1)->where('code','<>','old_card_entry')->order('sort','asc')->order('id','asc')->select()->toArray();$out=[];foreach($rows as$r){$code=trim((string)$r['code']);if($code==='')continue;$out[]=['code'=>$code,'label'=>trim((string)$r['display_name'])?:trim((string)$r['default_name'])?:$code];}return$out;}
    private function storeIds($ids):array{return array_values(array_unique(array_filter(array_map('intval',is_array($ids)?$ids:[$ids]))));}
    private function scope($query,array $stores,string $alias=''){return $query->whereIn(($alias!==''?$alias.'.':'').'store_id',$stores);}
    /**
     * A successful service void is a reversal fact, not a destructive update of
     * the original completed service. Operational audit lists can retain the
     * original row, while every business-report aggregation must exclude it.
     */
    private function completedUnvoidedServiceFacts($query, string $serviceAlias, string $voidAlias)
    {
        $query = $query
            ->leftJoin(
                'cashier_v3_service_record_void_operation ' . $voidAlias,
                $voidAlias . '.tenant_id=' . $serviceAlias . '.tenant_id AND '
                . $voidAlias . '.service_fact_id=' . $serviceAlias . '.id AND '
                . $voidAlias . ".status='succeeded'"
            )
            ->where($serviceAlias . '.service_status', 'completed')
            ->whereNull($voidAlias . '.id');
        $this->normalDataScope()->excludeVoidedSalesOrderServices($query, $serviceAlias);
        return $query;
    }
    private function participantOrder($query,string $orderField){return $this->participantEmployeeId>0?(new StoreReportParticipantScopeServices())->applyOrder($query,$orderField,$this->participantEmployeeId):$query;}
    private function participantCheckout($query,string $checkoutField){return $this->participantEmployeeId>0?(new StoreReportParticipantScopeServices())->applyCheckout($query,$checkoutField,$this->participantEmployeeId):$query;}
    private function participantEmployeeFact($query,string $employeeField){return $this->participantEmployeeId>0?(new StoreReportParticipantScopeServices())->applyEmployeeFact($query,$employeeField,$this->participantEmployeeId):$query;}
    private function columns(array $map):array{$out=[];foreach($map as$key=>$label)$out[]=['key'=>$key,'label'=>$label,'source_explanation'=>$this->columnExplanation((string)$key,(string)$label)];return$out;}
    private function result(string $title,array $columns,array $records,array $input,array $groups=[],array $totals=[]):array
    {
        $page=max(1,(int)($input['page']??1));$limit=min(100,max(10,(int)($input['limit']??20)));
        $hasOrganizationProjection=$this->projectOrganizationRows($records);
        if($hasOrganizationProjection)$records=array_values(array_filter($records,fn(array $row):bool=>$this->selectedDimensionMatches($row,$input)));
        if($hasOrganizationProjection&&!array_filter($columns,static fn($column)=>(string)($column['key']??'')==='company'))$columns=$this->withDimensions($columns);
        $columns=$this->fixedColumns($title,$this->withColumnExplanations($columns));
        $this->appendDimensionFiltersToColumns($columns);
        $this->appendDimensionFiltersToRows($records);
        $visible=!empty($input['_internal_all'])?$records:array_slice($records,($page-1)*$limit,$limit);
        $result=['title'=>$title,'columns'=>$columns,'records'=>$visible,'total'=>count($records),'page'=>$page,'page_size'=>$limit,'totals'=>$totals,'drilldown_keys'=>['organization_id','store_id','dimension_code','payment_method_code','metric_code','start_date','end_date'],'table_layout'=>['fixed'=>true,'sticky_header'=>true,'sticky_summary'=>true,'result_scroll'=>true],'metric_version'=>'store-operations-phase-two-v1','data_as_of'=>date('Y-m-d H:i:s'),'aggregation_status'=>'reconciled'];
        $summary=$this->summaryRow($title,$columns,$records);if($summary)$result['summary_row']=$summary;
        if($groups)$result['column_groups']=$groups;
        $metadata=$this->metadataForTitle($title,$totals);
        if (in_array($title, ['新客明细表', '新客汇总表'], true)) {
            $metadata['filter_schema'][] = [
                'key' => 'guide_id', 'label' => '导购', 'type' => 'select', 'required' => false,
                'placeholder' => '全部导购（可选）', 'options' => (array)($input['_guide_filter_options'] ?? []),
                'source_explanation' => '默认统计所有已在结账时确认导购归属的业务；选择导购后可进一步缩小范围。',
            ];
        }
        $metadata['filter_schema']=array_merge(
            $this->organizationDimensions()->filterSchema($this->activeRange),
            (array)($metadata['filter_schema']??[])
        );
        if(!empty($metadata['drilldown']))$metadata['drilldown']=$this->withDimensionFilterParams((array)$metadata['drilldown']);
        foreach($metadata as $key=>$value)$result[$key]=$value;
        return$result;
    }
    private function fixedColumns(string $title,array $columns):array
    {
        $maps=[
            '市场业绩表'=>['division_name'=>130,'store_name'=>140],
            '市场明细表'=>['order_no_snapshot'=>126,'store_name_snapshot'=>112,'member_name_snapshot'=>82,'member_phone'=>116,'dimension'=>108],
            '会员进店分析表'=>['member_name'=>100,'phone'=>116],
            '会员进店年度汇总表'=>['row_label'=>82,'year'=>76],
            '地推拓客明细表'=>['division_name'=>120,'store_name_snapshot'=>120,'source'=>110,'member_name_snapshot'=>90,'phone'=>116],
            '地推拓客汇总表'=>['card_sale_date'=>106,'member_name_snapshot'=>90,'phone'=>116,'source'=>110],
            '异业收客明细表'=>['source'=>110,'care_date'=>106,'store_name'=>120,'member_name'=>90],
            '异业收客汇总表'=>['customer_acquired_at'=>106,'partner_store_name'=>120,'member_name'=>90,'phone'=>116],
            '新客明细表'=>['division_name'=>120,'store_name_snapshot'=>120,'business_date'=>106,'customer'=>90,'guide'=>110,'salesperson'=>110,'sales_manager'=>110,'phone'=>116],
            '新客汇总表'=>['system_name'=>90,'division_name'=>120,'store_name'=>120,'member'=>90,'guide'=>110,'salesperson'=>110,'sales_manager'=>110,'source'=>110],
            '销售人生美大单统计表'=>['division_name'=>120,'store_name'=>120,'business_date'=>106,'salesperson'=>110],
            '院店退款台账'=>['market'=>120,'store_name'=>120,'customer'=>90,'refund_date'=>130],
        ];
        foreach($columns as &$column){$key=(string)($column['key']??'');if(isset($maps[$title][$key])&&empty($column['fixed'])){$column['fixed']='left';$column['fixed_width']=$maps[$title][$key];}}unset($column);
        return$columns;
    }
    private function summaryRow(string $title,array $columns,array $records):array
    {
        // A report result always has a total row. Empty result sets therefore make
        // the "no data" state explicit instead of changing the table structure.
        if(!$columns)return[];$row=[];$first=(string)($columns[0]['key']??'');foreach($columns as$column)$row[(string)$column['key']]='-';$row[$first]='合计';
        foreach($columns as$column){$key=(string)$column['key'];$kind=$this->summaryMetricKind($title,$key);if($kind==='')continue;$total=0;foreach($records as$record)$total+=$kind==='money'?$this->decimalCents($record[$key]??''):(int)($record[$key]??0);$row[$key]=$kind==='money'?$this->money($total):$total;}
        return$row;
    }
    private function summaryMetricKind(string $title,string $key):string
    {
        if($title==='市场业绩表')return preg_match('/^(channel_\d+_(walk_in|visits|effective)|payment_.+|channel_\d+_amount|total_performance)$/',$key)?(preg_match('/_(walk_in|visits|effective)$/',$key)?'count':'money'):'';
        if($title==='市场明细表')return in_array($key,['walk_in','visits','amount'],true)?($key==='amount'?'money':'count'):'';
        if($title==='会员进店分析表')return $key==='total_visits'||preg_match('/^month_\d+_visits$/',$key)?'count':($key==='annual_cash'||preg_match('/^month_\d+_cash$/',$key)?'money':'');
        if($title==='会员进店年度汇总表')return $key==='total'||strpos($key,'store_')===0?'count':'';
        if($title==='地推拓客明细表')return in_array($key,['visit_over_one_hour','fourth_and_above'],true)?'count':(in_array($key,['labor_fee','cash_1','cash_2','cash_3'],true)?'money':'');
        if($title==='地推拓客汇总表')return $key==='annual_total'||preg_match('/^month_\d+$/',$key)?'money':'';
        if($title==='异业收客明细表')return in_array($key,['full_payment','reward'],true)?'money':'';
        if($title==='异业收客汇总表')return $key==='remaining_service_count'?'count':(in_array($key,['remaining_service_amount','first_500','reached_2400','annual_cash'],true)||preg_match('/^month_\d+$/',$key)?'money':'');
        if($title==='新客明细表')return $key==='guide_effective_count'?'count':(in_array($key,['experience_card_amount','labor_fee','full_payment','deposit_payment','cleared_payment'],true)?'money':'');
        if($title==='新客汇总表')return preg_match('/^month_\d+_count$/',$key)?'count':($key==='annual_cash'||preg_match('/^month_\d+_(full|deposit|cleared)$/',$key)?'money':'');
        if($title==='销售人生美大单统计表')return $key==='daily_cash'?'money':'';
        if($title==='院店退款台账')return $key==='refund_amount'?'money':'';
        return'';
    }
    private function metadataForTitle(string $title,array $totals):array
    {
        $map=[
            '市场业绩表'=>['drilldown'=>['report'=>'market_detail','param_map'=>['dimension_code'=>'dimension_code','payment_method_code'=>'payment_method_code']]],
            '市场明细表'=>['editable_fields'=>[['key'=>'walk_in','label'=>'进店','type'=>'number','min'=>0]],'drilldown'=>['report'=>'market_detail']],
            '会员进店年度汇总表'=>['filter_schema'=>[['key'=>'mode','label'=>'统计方式','type'=>'select','options'=>[['value'=>'count','label'=>'按次数'],['value'=>'people','label'=>'按人头'],['value'=>'project','label'=>'按项目']]]]],
            '地推拓客明细表'=>['editable_fields'=>[['key'=>'card_sale_date','label'=>'卖卡日期','type'=>'date'],['key'=>'visit_over_one_hour','label'=>'进店满1小时','type'=>'number','min'=>0]]],
            '异业收客汇总表'=>['editable_fields'=>[['key'=>'customer_acquired_at','label'=>'收客时间','type'=>'date'],['key'=>'partner_store_name','label'=>'异业店名','type'=>'text']],'top_summaries'=>[['key'=>'first_500_total','label'=>'成交满500元合计：','value'=>$totals['first_500_total']??'0'],['key'=>'reached_2400_total','label'=>'成交满2400元合计：','value'=>$totals['reached_2400_total']??'0']]],
            '新客明细表'=>['editable_fields'=>[['key'=>'experience_card_amount','label'=>'体验卡金额','type'=>'money'],['key'=>'care_duration','label'=>'手艺人护理时长','type'=>'text']]],
            '销售人生美大单统计表'=>['pending_metrics'=>['分成前、分成后和兑现月份尚无权威分成计划事实，当前不猜算这些数值。']],
        ];
        $defaults=['filter_schema'=>[],'editable_fields'=>[],'top_summaries'=>[],'drilldown'=>[]];
        return array_merge($defaults,$map[$title]??[]);
    }
    private function annotations(string $report,array $stores,array $keys):array{if(!$keys)return[];$rows=Db::name('cashier_v3_report_annotation')->where('report_code',$report)->whereIn('store_id',$stores)->whereIn('subject_key',$keys)->field('subject_key,field_key,field_value,version')->select()->toArray();$out=[];foreach($rows as$r)$out[(string)$r['subject_key']][(string)$r['field_key']]=['value'=>(string)$r['field_value'],'version'=>(int)$r['version']];return$out;}
    private function annualFirstCardSources(array $stores,array $members,int $year):array
    {
        if(!$members)return[];
        $rows=$this->scope(Db::name('cashier_v3_sale_fact')->alias('annual_card_sale'),$stores,'annual_card_sale')->whereIn('annual_card_sale.member_id',$members)->where('annual_card_sale.source_type','card')->where('annual_card_sale.fact_direction','forward')->where('annual_card_sale.status','effective')->whereBetween('annual_card_sale.business_date',[$year.'-01-01',$year.'-12-31']);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($rows,'annual_card_sale.tenant_id','annual_card_sale.order_id');
        $rows=$rows->field('annual_card_sale.member_id,annual_card_sale.business_date,annual_card_sale.occurred_at,annual_card_sale.business_source_label_snapshot')->order('annual_card_sale.business_date','asc')->order('annual_card_sale.occurred_at','asc')->order('annual_card_sale.id','asc')->select()->toArray();
        $out=[];foreach($rows as $row){$id=(int)$row['member_id'];if(!isset($out[$id]))$out[$id]=(string)$row['business_source_label_snapshot'];}return$out;
    }
    private function activeCardEntitlements(array $stores,array $members):array
    {
        if(!$members)return[];
        $holders=Db::name('user_card_holder')->alias('h')->join('store_order o','o.id=h.oid')->whereIn('h.uid',$members)->whereIn('h.store_id',$stores)->where('h.is_del',0)->where('h.write_surplus_times','>',0)->where('o.paid',1)->where('o.is_del',0)->where('o.is_system_del',0)->where('o.refund_status',0)->field('h.uid,h.oid,h.write_surplus_times')->select()->toArray();
        if(!$holders)return[];
        $oids=array_values(array_unique(array_column($holders,'oid')));$owner=[];foreach($holders as $row)$owner[(int)$row['oid']]=(int)$row['uid'];
        $out=[];foreach($holders as $row){$id=(int)$row['uid'];$out[$id]['remaining_count']=(int)($out[$id]['remaining_count']??0)+(int)$row['write_surplus_times'];$out[$id]['remaining_amount_cents']=(int)($out[$id]['remaining_amount_cents']??0);}
        $carts=Db::name('store_order_cart_info')->whereIn('oid',$oids)->where('write_surplus_times','>',0)->where('cart_type',2)->where('product_type',6)->field('oid,pay_price,write_times,write_surplus_times')->select()->toArray();
        foreach($carts as $cart){$oid=(int)$cart['oid'];$id=(int)($owner[$oid]??0);$times=(int)$cart['write_times'];$remaining=(int)$cart['write_surplus_times'];if($id<=0||$times<=0)continue;$out[$id]['remaining_amount_cents']=(int)($out[$id]['remaining_amount_cents']??0)+intdiv($this->decimalCents($cart['pay_price'])*$remaining,$times);}
        return$out;
    }
    private function phones(array $ids):array{if(!$ids)return[];$out=[];foreach(Db::name('user')->whereIn('uid',$ids)->field('uid,phone')->select()->toArray()as$r)$out[(int)$r['uid']]=(string)$r['phone'];return$out;}
    private function servicesByMember(array $stores,array $members):array{if(!$members)return[];$rows=$this->completedUnvoidedServiceFacts($this->participantCheckout($this->scope(Db::name('cashier_v3_entitlement_service_fact')->alias('member_service'),$stores,'member_service'),'member_service.checkout_request_id'),'member_service','member_service_void')->whereIn('member_service.member_id',$members)->field('member_service.member_id,member_service.business_date,member_service.project_name_snapshot,member_service.labor_fee_amount_cents,member_service.craftsmen_snapshot_json,member_service.occurred_at')->order('member_service.business_date','asc')->order('member_service.occurred_at','asc')->select()->toArray();$out=[];foreach($rows as$r){$craft=[];foreach((array)json_decode((string)$r['craftsmen_snapshot_json'],true)as$c)$craft[]=(string)($c['employeeName']??$c['employee_name']??$c['name']??'');$r['craftsman']=implode('、',array_unique(array_filter($craft)));$out[(int)$r['member_id']][]=$r;}return$out;}
    private function newCustomerPersonnel(array $stores,array $orderIds,array $lineIds):array
    {
        $guides=[];$salespeople=[];$managers=[];$performance=[];
        if($orderIds){foreach($this->scope(Db::name('cashier_v3_customer_guide_round_fact'),$stores)->whereIn('order_id',$orderIds)->where('status','effective')->order('id','asc')->select()->toArray() as $row)$guides[(string)$row['order_id']][]=$row;}
        if($lineIds){foreach($this->scope(Db::name('cashier_v3_performance_fact'),$stores)->whereIn('source_line_id',$lineIds)->where('status','effective')->order('id','asc')->select()->toArray() as $row){$performance[(string)$row['source_line_id']][]=$row;if((string)$row['performance_type']==='sales_performance_allocated')$salespeople[(string)$row['source_line_id']][]=$row;}}
        if($orderIds){
            $checkoutToOrderLine=[];foreach(Db::name('cashier_v3_sales_order_line')->whereIn('order_id',$orderIds)->field('order_id,checkout_line_id,order_line_id')->select()->toArray() as $line){$checkoutToOrderLine[(string)$line['order_id'].'|'.(string)$line['checkout_line_id']]=(string)$line['order_line_id'];}
            foreach($this->scope(Db::name('cashier_v3_sales_manager_fact'),$stores)->whereIn('order_id',$orderIds)->where('status','effective')->order('id','asc')->select()->toArray() as $row){$key=(string)$row['order_id'].'|'.(string)$row['source_line_id'];$line=(string)($checkoutToOrderLine[$key]??$row['source_line_id']);if(in_array($line,$lineIds,true))$managers[$line][]=$row;}
        }
        return ['guides'=>$guides,'salespeople'=>$salespeople,'managers'=>$managers,'performance'=>$performance];
    }
    private function newCustomerRepaymentSalespeople(array $stores,array $repaymentOrderIds):array
    {
        $out=[];if(!$repaymentOrderIds)return$out;
        foreach($this->scope(Db::name('cashier_v3_performance_fact'),$stores)->whereIn('order_id',$repaymentOrderIds)->where('performance_type','sales_performance_allocated')->where('status','effective')->order('id','asc')->select()->toArray() as $row)$out[(string)$row['order_id']][]=$row;
        return$out;
    }
    private function newCustomerNames(array $rows,string $idKey,string $nameKey):string
    {
        $seen=[];$names=[];foreach($rows as $row){$id=(string)($row[$idKey]??'');$name=trim((string)($row[$nameKey]??''));$key=$id!==''?$id:$name;if($name!==''&&!isset($seen[$key])){$seen[$key]=true;$names[]=$name;}}return implode('、',$names);
    }
    private function newCustomerPersonnelMatches(array $guides,array $salespeople,array $managers,array $input):bool
    {
        $guideId=(int)($input['guide_id']??0);$salespersonId=(int)($input['salesperson_id']??0);$managerId=(int)($input['sales_manager_id']??0);
        if($guideId>0&&!array_filter($guides,static fn(array $row):bool=>(int)($row['guide_employee_id']??0)===$guideId))return false;
        if($salespersonId>0&&!array_filter($salespeople,static fn(array $row):bool=>(int)($row['employee_id']??0)===$salespersonId))return false;
        if($managerId>0&&!array_filter($managers,static fn(array $row):bool=>(int)($row['sales_manager_employee_id']??0)===$managerId))return false;
        return true;
    }
    private function newCustomerSummaryAddRow(array &$records,array $row,array $guides,array $salespeople,array $managers,int $amount,string $suffix,int $count,string $source,int $sourceId,array $input):void
    {
        if(!$guides&&!$salespeople&&!$managers)return;
        if(!$this->newCustomerPersonnelMatches($guides,$salespeople,$managers,$input))return;
        $guideId=(int)($input['guide_id']??0);$guide=$this->newCustomerNames($guides,'guide_employee_id','guide_employee_name_snapshot');$salesperson=$this->newCustomerNames($salespeople,'employee_id','employee_name_snapshot');$manager=$this->newCustomerNames($managers,'sales_manager_employee_id','sales_manager_name_snapshot');
        $key=(int)$row['member_id'].'|'.$guide.'|'.$salesperson.'|'.$manager.'|'.$sourceId.'|'.$source;
        if(!isset($records[$key]))$records[$key]=['system_name'=>'瑞昊','division_name'=>(string)$row['division_name'],'store_name'=>(string)$row['store_name'],'member'=>(string)$row['member_name'],'member_id'=>(int)$row['member_id'],'guide_id'=>$guideId,'guide'=>$guide,'salesperson'=>$salesperson,'sales_manager'=>$manager,'source'=>$source,'source_id'=>$sourceId,'source_label'=>$source,'annual_cash_cents'=>0];
        $month=(int)substr((string)$row['business_date'],5,2);$records[$key]['month_'.$month.'_count']=(int)($records[$key]['month_'.$month.'_count']??0)+$count;$records[$key]['month_'.$month.'_'.$suffix.'_cents']=(int)($records[$key]['month_'.$month.'_'.$suffix.'_cents']??0)+$amount;$records[$key]['annual_cash_cents']+=(int)$amount;
    }
    private function cashByMemberAndMonth(array $stores,array $members,string $start,string $end):array{if(!$members)return[];$rows=$this->cashFacts($stores)->whereIn('member_id',$members)->whereBetween('business_date',[$start,$end])->where('status','effective')->fieldRaw('member_id,business_date,SUM(amount_cents) amount_cents')->group('member_id,business_date')->order('business_date','asc')->select()->toArray();$out=[];foreach($rows as$r)$out[(int)$r['member_id']][]=$r;return$out;}
    private function cashMonthlyTotals(array $stores,array $members,string $start,string $end):array{if(!$members)return[];$rows=$this->cashFacts($stores)->whereIn('member_id',$members)->whereBetween('business_date',[$start,$end])->where('status','effective')->fieldRaw('member_id,MONTH(business_date) month_no,SUM(amount_cents) amount_cents')->group('member_id,MONTH(business_date)')->select()->toArray();$out=[];foreach($rows as$r)$out[(int)$r['member_id']][(int)$r['month_no']]=(int)$r['amount_cents'];return$out;}
    private function sumField(array $rows,string $field):int{$sum=0;foreach($rows as$r)$sum+=(int)($r[$field]??0);return$sum;}
    private function money(int $cents):string{$negative=$cents<0;$cents=abs($cents);$value=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);$value=rtrim(rtrim($value,'0'),'.');return($negative?'-':'').$value;}
    private function decimalCents($money):int{$value=trim((string)$money);if($value==='')return 0;$negative=substr($value,0,1)==='-';$value=ltrim($value,'+-');$parts=explode('.',$value,2);$cents=((int)($parts[0]??0))*100+(int)str_pad(substr((string)($parts[1]??''),0,2),2,'0');return$negative?-abs($cents):$cents;}
    private function cents($money):int{return$this->decimalCents($money);}
    private function dateTime(int $timestamp):string{return$timestamp>0?date('Y-m-d H:i:s',$timestamp):'';}
    private function hasColumn(string $table,string $column):bool{static$cache=[];$key=$table.'.'.$column;if(!array_key_exists($key,$cache))$cache[$key]=Db::query("SHOW COLUMNS FROM `eb_".$table."` LIKE '".addslashes($column)."'")!==[];return$cache[$key];}
    private function organizationDimensions():StoreUnifiedReportOrganizationDimensionServices
    {
        if(!$this->organizationDimensionServices)$this->organizationDimensionServices=new StoreUnifiedReportOrganizationDimensionServices();
        return$this->organizationDimensionServices;
    }
    private function normalDataScope():StoreReportNormalDataScopeServices
    {
        static $service;
        if(!$service)$service=new StoreReportNormalDataScopeServices();
        return $service;
    }
    private function applyOrganizationFilters($query,string $alias,array $input,array $range)
    {
        $this->organizationDimensions()->applyFilters($query,$alias,$input,$range);
        return$query;
    }
    private function projectOrganization(array &$row,string $organizationId,string $organizationPath,string $businessDate):void
    {
        $this->organizationDimensions()->project($row,$organizationId,$organizationPath,$businessDate);
        if(array_key_exists('division_name',$row))$row['division_name']=(string)$row['company'];
    }
    private function projectOrganizationRows(array &$records):bool
    {
        $projected=false;
        foreach($records as&$row){
            $date=(string)($row['business_date']??'');
            if($date==='')continue;
            $this->projectOrganization($row,(string)($row['organization_id']??''),(string)($row['organization_path_snapshot']??''),$date);
            $projected=true;
        }
        unset($row);
        return$projected;
    }
    private function organizationKey(array $row):string
    {
        return(string)($row['company_dimension_id']??'').'|'.(string)($row['city_manager_dimension_id']??'');
    }
    private function selectedDimensionMatches(array $row,array $input):bool
    {
        foreach(['company_dimension_id','city_manager_dimension_id']as$key){$selected=trim((string)($input[$key]??''));if($selected!==''&&$selected!==(string)($row[$key]??''))return false;}
        return true;
    }
    private function dimensionColumns():array
    {
        return[
            ['key'=>'company','label'=>'分公司','source_explanation'=>$this->organizationDimensions()->sourceExplanation('company')],
        ];
    }
    private function withDimensions(array $columns):array
    {
        foreach($columns as$index=>$column){
            if((string)($column['key']??'')!=='division_name')continue;
            return$columns;
        }
        return array_merge($this->dimensionColumns(),$columns);
    }
    private function columnExplanation(string $key,string $label):string
    {
        if($key==='company')return$this->organizationDimensions()->sourceExplanation('company');
        if($key==='city_manager')return$this->organizationDimensions()->sourceExplanation('city_manager');
        return$label.'按本报表已确认的统一事实口径、业务日期和当前权限范围读取。';
    }
    private function withColumnExplanations(array $columns):array
    {
        foreach($columns as&$column){if(trim((string)($column['source_explanation']??''))==='')$column['source_explanation']=$this->columnExplanation((string)($column['key']??''),(string)($column['label']??''));}unset($column);return$columns;
    }
    private function dimensionFilterParams():array
    {
        $params=[];foreach(['company_dimension_id','city_manager_dimension_id']as$key){$value=trim((string)($this->activeInput[$key]??''));if($value!=='')$params[$key]=$value;}return$params;
    }
    private function withDimensionFilterParams(array $drilldown):array
    {
        $drilldown['params']=array_merge((array)($drilldown['params']??[]),$this->dimensionFilterParams());return$drilldown;
    }
    private function appendDimensionFiltersToColumns(array &$columns):void
    {
        foreach($columns as&$column)if(!empty($column['drilldown']))$column['drilldown']=$this->withDimensionFilterParams((array)$column['drilldown']);unset($column);
    }
    private function appendDimensionFiltersToRows(array &$records):void
    {
        foreach($records as&$row){if(empty($row['_drilldown'])||!is_array($row['_drilldown']))continue;foreach($row['_drilldown']as$key=>$drilldown)$row['_drilldown'][$key]=$this->withDimensionFilterParams((array)$drilldown);}unset($row);
    }
    private function cashFacts(array $stores,string $alias='')
    {
        $alias=$alias!==''?$alias:'report_payment';
        $query=Db::name('cashier_v3_payment_fact')->alias($alias);
        $this->scope($query,$stores,$alias);
        $this->applyOrganizationFilters($query, $alias, $this->activeInput, $this->activeRange);
        $column=$alias.'.';
        // Refund facts remain normal operating data. A succeeded order void is
        // hidden as a whole in normal statistics, including its signed
        // reversal facts; audit records remain append-only elsewhere.
        $query->whereRaw("({$column}fact_direction='forward' OR ({$column}fact_direction='reversal' AND EXISTS (SELECT 1 FROM eb_cashier_v3_order_lifecycle_operation reversal_operation WHERE reversal_operation.tenant_id={$column}tenant_id AND reversal_operation.command_idempotency_key={$column}command_idempotency_key AND reversal_operation.operation_type IN ('refund','void') AND reversal_operation.status='succeeded')))" );
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query, $column . 'tenant_id', $column . 'order_id');
        return $this->participantOrder($query,$column.'order_id');
    }
    private function guideFilterOptions(array $stores,array $range):array
    {
        $rows=$this->scope(Db::name('cashier_v3_customer_guide_round_fact'),$stores)
            ->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective')
            ->fieldRaw('guide_employee_id value,MAX(guide_employee_name_snapshot) label')
            ->group('guide_employee_id')->order('label','asc')->select()->toArray();
        return array_values(array_filter(array_map(static function(array $row):array {
            return ['value'=>(int)($row['value']??0),'label'=>(string)($row['label']??'')];
        },$rows),static fn(array $row):bool=>(int)$row['value']>0&&trim($row['label'])!==''));
    }
}
