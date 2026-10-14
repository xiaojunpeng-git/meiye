<?php

namespace app\services\report;
use app\services\query\metric\StoreCustomerVisitEligibility;

use app\services\BaseServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\order\CashierV3RefundAttributionServices;
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
        // 登记退款不是支付通道的反向流水；单独归入 K，且退款日即使没有
        // 正向收款也必须保留门店行，否则退款单日会从汇总和导出中消失。
        $refunds = $this->marketRefundAdjustmentFacts($stores, 'market_refund')
            ->whereBetween('market_refund.business_date', [$range['start'], $range['end']])
            ->fieldRaw('market_refund.store_id,MAX(market_refund.store_name_snapshot) store_name,SUM(market_refund.amount_cents) amount_cents')
            ->group('market_refund.store_id')->select()->toArray();
        foreach ($refunds as $refund) {
            $storeId = (int)$refund['store_id'];
            if (!isset($rows[$storeId])) {
                $rows[$storeId] = ['store_id' => $storeId, 'division_name' => '', 'store_name' => (string)$refund['store_name']];
                $this->projectOrganization($rows[$storeId], '', '', $range['end']);
            }
        }
        $refundReversal = $this->marketCashRefundCondition('market_payment');
        $cash = $this->cashFacts($stores, 'market_payment')
            ->whereBetween('market_payment.business_date', [$range['start'], $range['end']])->where('market_payment.status', 'effective')
            ->fieldRaw("market_payment.store_id,market_payment.business_source_primary_id,market_payment.fact_direction,market_payment.payment_method,CASE WHEN " . $refundReversal . " THEN 1 ELSE 0 END is_refund_reversal,SUM(market_payment.amount_cents) amount_cents")
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
        foreach ($refunds as $refund) {
            $storeId = (int)$refund['store_id'];
            $amount = (int)$refund['amount_cents'];
            if ($refundSourceId > 0) {
                $key = 'channel_' . $refundSourceId . '_amount_cents';
                $rows[$storeId][$key] = (int)($rows[$storeId][$key] ?? 0) + $amount;
            }
            // 退款业绩扣实际业绩，但没有外部支付通道，不归入任一收款方式。
            $rows[$storeId]['total_performance_cents'] = (int)($rows[$storeId]['total_performance_cents'] ?? 0) + $amount;
        }
        $visits = [];
        $visitedSubjects = [];
        $serviceVisitFacts = $this->marketServiceVisitFacts($stores, $range, $input);
        // 体验服务仍可形成明细和其他经营结果，但不取得市场人次资格。
        foreach ($this->marketCountableServiceVisits($serviceVisitFacts) as $fact) {
            $sourceId = (int)($fact['business_source_primary_id'] ?? 0);
            if ($sourceId <= 0 || $sourceId === $refundSourceId) continue;
            $storeId = (int)$fact['store_id'];
            // 普通来源人次取完成且未作废的服务，不要求本期先有收款；K 单独按退款日计数。
            if (!isset($rows[$storeId]) && isset($sourceById[$sourceId])) {
                $rows[$storeId] = [
                    'store_id' => $storeId, 'division_name' => '',
                    'store_name' => (string)$fact['store_name_snapshot'],
                ];
                $this->projectOrganization($rows[$storeId], '', '', $range['end']);
            }
            $visitIdentity = $this->marketVisitIdentity($fact);
            if ($visitIdentity === '') continue;
            // 会员按“会员日”去重；游客没有会员身份，只能按关联的有效服务单去重。
            // 来源保留在键中，确保汇总与明细下钻始终使用同一业务范围。
            $visitKey = $storeId . '|' . $sourceId . '|' . (string)$fact['service_business_date'] . '|' . $visitIdentity;
            if (isset($visitedSubjects[$visitKey])) continue;
            $visitedSubjects[$visitKey] = true;
            $key = $storeId . '|' . $sourceId;
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
            $storeId = (int)$fact['store_id'];
            $sourceId = (int)$fact['business_source_primary_id'];
            $rows[$storeId]['channel_' . $sourceId . '_visits'] = (int)$fact['visit_count'];
        }
        // K 的人次与服务无关：跨退款方式和退款单合并同一会员日，不套达标金额门槛。
        if ($refundSourceId > 0) foreach ($this->marketRefundVisitDays($stores, $range) as $refundDay) {
            $storeId = (int)$refundDay['store_id'];
            if (isset($rows[$storeId])) {
                $key = 'channel_' . $refundSourceId . '_visits';
                $rows[$storeId][$key] = (int)($rows[$storeId][$key] ?? 0) + 1;
            }
        }
        // 每个达标会员日计 1；跨日既不能凑门槛，也不能再按整段日期去重。
        foreach ($this->marketEffectiveMemberKeys($stores, $range) as $fact) {
            $storeId = (int)$fact['store_id'];
            $sourceId = (int)$fact['business_source_primary_id'];
            if (isset($rows[$storeId])) $rows[$storeId]['channel_' . $sourceId . '_effective'] = (int)($rows[$storeId]['channel_' . $sourceId . '_effective'] ?? 0) + 1;
        }
        // B 来源进店优先读取会员每日来源行的补充值。旧逐单值只在该行尚无
        // 新补充值时回退相加，不能把两种记录同时叠加到市场业绩。
        $bSourceIds = [];
        foreach ($sources as $source) if ($this->sourcePrefix($source) === 'B') $bSourceIds[] = (int)$source['id'];
        if ($bSourceIds) {
            $bCashOrders = $this->cashFacts($stores)
                ->whereBetween('business_date', [$range['start'], $range['end']])->where('status', 'effective')
                ->whereIn('business_source_primary_id', $bSourceIds)
                ->field('store_id,member_id,business_date,business_source_primary_id,order_id')
                ->group('store_id,member_id,business_date,business_source_primary_id,order_id')->select()->toArray();
            $bGroups = [];
            foreach ($bCashOrders as $order) {
                $key = (int)$order['store_id'] . '|' . (string)$order['business_date'] . '|' . (int)$order['member_id'] . '|' . (int)$order['business_source_primary_id'];
                if ((int)$order['member_id'] <= 0) $key .= '|' . (string)$order['order_id'];
                $bGroups[$key]['store_id'] = (int)$order['store_id'];
                $bGroups[$key]['member_id'] = (int)$order['member_id'];
                $bGroups[$key]['business_date'] = (string)$order['business_date'];
                $bGroups[$key]['source_id'] = (int)$order['business_source_primary_id'];
                $bGroups[$key]['orders'][(string)$order['order_id']] = true;
            }
            // 无收款的正常服务也要形成可编辑的会员每日行。
            foreach ($serviceVisitFacts as $visit) {
                $sourceId = (int)$visit['business_source_primary_id'];
                $orderId = (string)($visit['matched_order_id'] ?? '');
                if (!in_array($sourceId, $bSourceIds, true) || $orderId === '') continue;
                $storeId = (int)$visit['store_id'];
                $memberId = (int)$visit['member_id'];
                $date = (string)$visit['service_business_date'];
                $key = $storeId . '|' . $date . '|' . $memberId . '|' . $sourceId;
                if ($memberId <= 0) $key .= '|' . $orderId;
                $bGroups[$key]['store_id'] = $storeId;
                $bGroups[$key]['member_id'] = $memberId;
                $bGroups[$key]['business_date'] = $date;
                $bGroups[$key]['source_id'] = $sourceId;
                $bGroups[$key]['orders'][$orderId] = true;
            }
            $orderIds = []; $dayKeys = [];
            foreach ($bGroups as $group) {
                foreach (array_keys($group['orders']) as $orderId) $orderIds[$orderId] = true;
                $annotationKey = $group['member_id'] > 0
                    ? $this->marketMemberDayKey($group['store_id'], $group['business_date'], $group['member_id'], $group['source_id'])
                    : $this->marketGuestOrderKey($group['store_id'], $group['business_date'], $group['source_id'], (string)array_key_first($group['orders']));
                $dayKeys[$annotationKey] = true;
            }
            $manual = $this->annotations('market_detail', $stores, array_keys($orderIds));
            // 个人参与范围可能只包含该会员当天的部分订单，不能读取或改写
            // 门店完整合并行的手动值；个人视图仍只显示可见原单的旧值。
            $dailyManual = $this->participantEmployeeId > 0 ? []
                : $this->annotations('market_detail', $stores, array_keys($dayKeys));
            foreach ($bGroups as $group) {
                $storeId = $group['store_id']; $sourceId = $group['source_id'];
                if (!isset($rows[$storeId])) continue;
                $legacy = 0;
                foreach (array_keys($group['orders']) as $orderId) $legacy += (int)($manual[$orderId]['walk_in']['value'] ?? 0);
                // 游客不能按空会员 ID 合并，因此以原始订单作为独立边界；
                // 新补充值与会员日行一样优先，旧逐单值仅作兼容回退。
                $dayKey = $group['member_id'] > 0
                    ? $this->marketMemberDayKey($storeId, $group['business_date'], $group['member_id'], $sourceId)
                    : $this->marketGuestOrderKey($storeId, $group['business_date'], $sourceId, (string)array_key_first($group['orders']));
                $daily = $dailyManual[$dayKey]['walk_in'] ?? null;
                $rows[$storeId]['channel_'.$sourceId.'_walk_in'] = (int)($rows[$storeId]['channel_'.$sourceId.'_walk_in'] ?? 0)
                    + ($daily !== null ? (int)$daily['value'] : $legacy);
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
                $columns[count($columns)-1]['drilldown']=['report'=>'market_detail','params'=>['dimension_code'=>(string)$sourceId,'metric_code'=>'walk_in']];
            }
            foreach ([['visits','人次'],['effective','有效人员'],['amount','金额']] as $definition) {
                $key = 'channel_' . $sourceId . '_' . $definition[0];
                $columns[] = ['key'=>$key,'label'=>$definition[1],'group_label'=>(string)$source['name']]; $keys[] = $key;
                $params = ['dimension_code'=>(string)$sourceId];
                if ($definition[0] === 'effective') $params['metric_code'] = 'effective_people';
                if ($prefix === 'B' && $definition[0] === 'visits') $params['metric_code'] = 'visits';
                if ($prefix === 'B' && $definition[0] === 'amount') $params['metric_code'] = 'amount';
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
        $refundSourceId = 0;
        $refundSourceName = '';
        foreach ($this->primarySources() as $source) {
            if ($this->sourcePrefix($source) !== 'K') continue;
            $refundSourceId = (int)$source['id'];
            $refundSourceName = (string)$source['name'];
            break;
        }
        // 与市场汇总使用同一“成功退款反向流水”判定，作废仍留在原来源。
        $refundReversal = $this->marketCashRefundCondition('p');
        $query = $this->cashFacts($stores, 'p')
            ->whereBetween('p.business_date', [$range['start'], $range['end']])->where('p.status', 'effective');
        $dimension = trim((string)($input['dimension_code'] ?? ''));
        $metricCode = trim((string)($input['metric_code'] ?? ''));
        if ($dimension !== '') {
            $sourceId = (int)$dimension;
            if ($sourceId === $refundSourceId && $refundSourceId > 0) {
                $query->whereRaw('(p.business_source_primary_id=' . $sourceId . ' OR (' . $refundReversal . '))');
            } else {
                $query->where('p.business_source_primary_id', $sourceId)->whereRaw('NOT (' . $refundReversal . ')');
            }
        }
        if (($method = trim((string)($input['payment_method_code'] ?? ''))) !== '') $query->where('p.payment_method', $method);
        $rows = $query->leftJoin('user market_detail_member', 'market_detail_member.uid = p.member_id')
            ->fieldRaw('p.store_id,p.order_id,p.checkout_request_id,p.order_no_snapshot,p.store_name_snapshot,MAX(p.organization_id) organization_id,MAX(p.organization_path_snapshot) organization_path_snapshot,MAX(p.member_id) member_id,MAX(p.member_name_snapshot) member_name_snapshot,MAX(market_detail_member.phone) member_phone,p.business_source_primary_id,p.business_source_primary_name_snapshot,p.business_source_label_snapshot,p.business_date,CASE WHEN ' . $refundReversal . ' THEN 1 ELSE 0 END is_refund_reversal,MAX(p.operator_name_snapshot) creator_name,SUM(p.amount_cents) amount_cents,MAX(p.recorded_at) recorded_at')
            ->group('p.store_id,p.order_id,p.business_source_primary_id,p.business_date,is_refund_reversal')
            // An order void writes a paired negative payment fact. Keep both
            // facts for audit and aggregates, but do not render a zero-net
            // document as an active market-detail row.
            ->having('SUM(p.amount_cents) <> 0')
            ->order('p.business_date','desc')->order('p.order_id','desc')->select()->toArray();
        foreach ($rows as &$cashRow) {
            if ((int)($cashRow['is_refund_reversal'] ?? 0) !== 1 || $refundSourceId <= 0) continue;
            $cashRow['business_source_primary_id'] = $refundSourceId;
            $cashRow['business_source_primary_name_snapshot'] = $refundSourceName;
            $cashRow['business_source_label_snapshot'] = $refundSourceName;
        }
        unset($cashRow);
        // 下钻与汇总读取同一批有效调整事实；退款操作单号是明细边界，
        // 不按原销售单合并，以免多次部分退款被折成一笔。
        if ($refundSourceId > 0 && $method === '' && ($dimension === '' || (int)$dimension === $refundSourceId)) {
            $refundRows = $this->marketRefundAdjustmentFacts($stores, 'p')
                ->whereBetween('p.business_date', [$range['start'], $range['end']])
                ->leftJoin('user market_detail_member', 'market_detail_member.uid = p.member_id')
                ->fieldRaw('p.store_id,p.order_id,p.checkout_request_id,p.order_no_snapshot,p.store_name_snapshot,MAX(p.organization_id) organization_id,MAX(p.organization_path_snapshot) organization_path_snapshot,MAX(p.member_id) member_id,MAX(p.member_name_snapshot) member_name_snapshot,MAX(market_detail_member.phone) member_phone,p.business_date,MAX(p.operator_name_snapshot) creator_name,SUM(p.amount_cents) amount_cents,MAX(p.recorded_at) recorded_at')
                ->group('p.store_id,p.order_id,p.business_date')
                ->order('p.business_date','desc')->order('p.order_id','desc')->select()->toArray();
            foreach ($refundRows as &$refundRow) {
                $refundRow['business_source_primary_id'] = $refundSourceId;
                $refundRow['business_source_primary_name_snapshot'] = $refundSourceName;
                $refundRow['business_source_label_snapshot'] = $refundSourceName;
            }
            unset($refundRow);
            $rows = array_merge($rows, $refundRows);
        }
        // 与汇总共用原始收款的每日净额资格；不能用已将退款展示为 K 的明细
        // 重新判定，否则同日退款抵减后的资格会与汇总不同。支付方式筛选仍缩小范围。
        $effectiveMemberKeys = $this->marketEffectiveMemberKeys($stores, $range, $method);
        if ($metricCode === 'effective_people') {
            $rows = array_values(array_filter($rows, function (array $row) use ($effectiveMemberKeys): bool {
                return isset($effectiveMemberKeys[$this->marketEffectiveMemberDayKey($row)]);
            }));
        }
        $serviceVisits = $this->marketServiceVisitFacts($stores, $range, $input);
        // 正常服务不依赖现金收款事实；仅在人次/进店等非金额下钻中
        // 补充无收款服务行，避免把 ¥0 行误当成现金明细。
        if ($method === '' && !in_array($metricCode, ['amount', 'effective_people'], true)) {
            $rows = array_merge($rows, $this->marketServiceOnlyRows($stores, $serviceVisits, $rows, $dimension));
        }
        $refundVisitDays = $refundSourceId > 0 && ($dimension === '' || (int)$dimension === $refundSourceId)
            ? $this->marketRefundVisitDays($stores, $range, $method) : [];
        $visitedSubjects = [];
        // 与市场业绩汇总使用同一资格集合；体验行可展示，不能因展示而计入人次。
        foreach ($this->marketCountableServiceVisits($serviceVisits) as $serviceVisit) {
            $sourceId = (int)($serviceVisit['business_source_primary_id'] ?? 0);
            $visitIdentity = $this->marketVisitIdentity($serviceVisit);
            if ($sourceId <= 0 || $sourceId === $refundSourceId || $visitIdentity === '') continue;
            // 人次不能按项目数累加：会员按当天去重，游客按有效服务单去重。
            $dayKey = (int)$serviceVisit['store_id'] . '|' . (string)$serviceVisit['service_business_date'] . '|' . $sourceId . '|' . $visitIdentity;
            $visitedSubjects[$dayKey] = true;
        }
        foreach ($rows as &$row) {
            $row['dimension'] = (string)$row['business_source_label_snapshot'];
            // 会员 ID 才是会员身份的权威依据；无会员身份的服务单统一显示“游客”，
            // 仅改变报表展示，不把游客补写成会员，也不改变金额或人次归属。
            if ((int)($row['member_id'] ?? 0) <= 0) $row['member_name_snapshot'] = '游客';
            $row['walk_in'] = 0; $row['visits'] = 0;
            $row['effective_people'] = isset($effectiveMemberKeys[$this->marketEffectiveMemberDayKey($row)]) ? 1 : 0;
            $visitIdentity = $this->marketVisitIdentity($row);
            $dayKey = (int)$row['store_id'] . '|' . (string)$row['business_date'] . '|' . (int)$row['business_source_primary_id'] . '|' . $visitIdentity;
            $row['visits'] = $visitIdentity !== '' && isset($visitedSubjects[$dayKey]) ? 1 : 0;
            // 同一天的多笔退款/多种退款方式在后续会员日聚合中取 max(1)，
            // 普通 K 收款或服务本身不提供资格；人次下钻仅留下真实退款日。
            if ($refundSourceId > 0 && (int)$row['business_source_primary_id'] === $refundSourceId) {
                $row['visits'] = isset($refundVisitDays[$this->marketRefundVisitDayKey($row)]) ? 1 : 0;
            }
            $row['amount'] = $this->money((int)$row['amount_cents']);
            $row['registered_date'] = (string)$row['business_date'];
            $row['reviewer'] = ''; $row['reviewed_at'] = ''; $row['created_at'] = $this->dateTime((int)$row['recorded_at']);
            $row['annotation_subject_key'] = (string)$row['order_id'];
            $row['annotation_subject_type'] = 'sales_order'; $row['source_order_id'] = (string)$row['order_id'];
        }
        unset($row);
        $keys=array_values(array_unique(array_column($rows,'order_id')));$manual=$this->annotations('market_detail',$stores,$keys);
        foreach($rows as &$row){$key=(string)$row['order_id'];$row['walk_in']=(int)($manual[$key]['walk_in']['value']??0);$row['walk_in_version']=(int)($manual[$key]['walk_in']['version']??0);}unset($row);
        $rows = $this->marketMemberDailyRows($rows);
        // 新的会员每日补充值优先于旧逐单补充值；旧值仅在本行尚未保存时作为读回默认值。
        $dailyKeys = array_values(array_filter(array_column($rows, 'annotation_subject_key')));
        $dailyManual = $this->participantEmployeeId > 0 ? []
            : $this->annotations('market_detail', $stores, $dailyKeys);
        foreach ($rows as &$row) {
            $daily = $dailyManual[(string)$row['annotation_subject_key']]['walk_in'] ?? null;
            if ($daily !== null) $row['walk_in'] = (int)$daily['value'];
            $row['walk_in_version'] = (int)($daily['version'] ?? 0);
        }
        unset($row);
        if ($metricCode === 'visits') {
            $rows = array_values(array_filter($rows, static function (array $row): bool {
                return (int)($row['visits'] ?? 0) > 0;
            }));
        }
        if ($metricCode === 'walk_in') {
            $rows = array_values(array_filter($rows, static function (array $row): bool {
                return (int)($row['walk_in'] ?? 0) > 0;
            }));
        }
        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)$right['business_date'], (string)$left['business_date'])
                ?: strcmp((string)$right['order_id'], (string)$left['order_id']);
        });
        $columns = $this->columns(['business_date'=>'日期','store_name_snapshot'=>'门店名称','member_name_snapshot'=>'会员','member_phone'=>'手机','dimension'=>'来源','walk_in'=>'进店','visits'=>'人次','effective_people'=>'有效人员','amount'=>'金额','registered_date'=>'登记日期','reviewer'=>'审核人','reviewed_at'=>'审核时间','creator_name'=>'制单人','created_at'=>'制单日期']);
        foreach (['business_date'=>112, 'store_name_snapshot'=>112, 'member_name_snapshot'=>82, 'member_phone'=>116, 'dimension'=>108] as $key => $width) {
            foreach ($columns as &$column) if ($column['key'] === $key) { $column['fixed'] = 'left'; $column['fixed_width'] = $width; break; }
            unset($column);
        }
        $result = $this->result('市场明细表', $columns, $rows, $input, [], ['amount_cents'=>$this->sumField($rows,'amount_cents')]);
        $result['summary_row'] = $this->marketDetailSummaryRow($rows);
        if ($this->participantEmployeeId > 0) $result['editable_fields'] = [];
        return $result;
    }

    private function memberVisitAnalysis(array $stores, array $range, array $input): array
    {
        $year = (int)substr($range['end'], 0, 4);
        // 有效收款或现金退款均能让会员进入所选期间的报表；退款不产生到店，
        // 仅完成服务但没有本期现金业务的会员仍不入表。
        $payingMembers = $this->cashFacts($stores)
            ->whereBetween('business_date', [$range['start'], $range['end']])
            ->where('status', 'effective')
            ->where('fact_direction', 'forward')
            ->where('amount_cents', '>', 0)
            ->where('member_id', '>', 0)
            ->fieldRaw('member_id,MAX(member_name_snapshot) member_name')
            ->group('member_id')->select()->toArray();
        $records = [];
        foreach ($payingMembers as $fact) {
            $id = (int)$fact['member_id'];
            $records[$id] = ['member_id'=>$id,'member_name'=>(string)$fact['member_name'],'total_visits'=>0,'annual_cash_cents'=>0];
        }
        // 常规退款的负向收款事实已在 cashFacts 中；单独退款调整是 adjustment，
        // 必须从已成功退款单读取，不能把任意负数或未完成退款当作现金退款。
        $reversalMembers = $this->cashFacts($stores)
            ->whereBetween('business_date', [$range['start'], $range['end']])
            ->where('status', 'effective')->where('fact_direction', 'reversal')
            ->where('amount_cents', '<', 0)->where('member_id', '>', 0)
            ->fieldRaw('member_id,MAX(member_name_snapshot) member_name')
            ->group('member_id')->select()->toArray();
        $refundReader = new StoreMemberConsumptionRefundReadServices();
        $refundStores = $this->organizationDimensions()->narrowStoreIds($stores, $input);
        $periodRefunds = $refundReader->query($refundStores, $range, $input, $this->participantEmployeeId);
        $adjustmentMembers = (clone $periodRefunds)->where('payment.member_id', '>', 0)
            ->fieldRaw('payment.member_id member_id,MAX(payment.member_name_snapshot) member_name')
            ->group('payment.member_id')->select()->toArray();
        foreach (array_merge($reversalMembers, $adjustmentMembers) as $fact) {
            $id = (int)$fact['member_id'];
            if (!isset($records[$id])) {
                $records[$id] = ['member_id'=>$id,'member_name'=>(string)$fact['member_name'],
                    'total_visits'=>0,'annual_cash_cents'=>0];
            }
        }
        $memberIds = array_keys($records);
        if ($memberIds) {
            // 到店只补充入表会员在所选期间已完成、未作废且非体验的服务；
            // 人员不计数开关不参与门店到店资格。
            // 粒度为“会员 + 业务日”，同日多个服务项目或订单只算一次。
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
                ->whereIn('member_visit_service.member_id', $memberIds)
                ->whereBetween('member_visit_service.business_date', [$range['start'], $range['end']])
                ->whereRaw(StoreCustomerVisitEligibility::sqlPredicate('member_visit_service'))
                ->fieldRaw('member_visit_service.member_id,MONTH(member_visit_service.business_date) month_no,COUNT(DISTINCT member_visit_service.business_date) month_visits')
                ->group('member_visit_service.member_id,MONTH(member_visit_service.business_date)')->select()->toArray();
            foreach ($services as $fact) {
                $id = (int)$fact['member_id']; $month = (int)$fact['month_no'];
                $records[$id]['month_'.$month.'_visits'] = (int)$fact['month_visits'];
                $records[$id]['total_visits'] += (int)$fact['month_visits'];
            }
            // 会员名单与到店次数只受所选日期影响；金额取所选自然年
            // 的有效收款净额及单独退款调整，绝不把历史权益迁入当作现金。
            $payments = $this->cashFacts($stores)
                ->whereIn('member_id', $memberIds)
                ->whereBetween('business_date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
                ->where('status', 'effective')
                ->fieldRaw('member_id,MONTH(business_date) month_no,SUM(amount_cents) amount_cents')
                ->group('member_id,MONTH(business_date)')->select()->toArray();
            foreach ($payments as $fact) {
                $id=(int)$fact['member_id'];$month=(int)$fact['month_no'];
                $records[$id]['month_'.$month.'_cash_cents']=(int)$fact['amount_cents'];
                $records[$id]['annual_cash_cents']+=(int)$fact['amount_cents'];
            }
            $annualRange = ['start'=>sprintf('%04d-01-01', $year), 'end'=>sprintf('%04d-12-31', $year)];
            $annualAdjustments = $refundReader->query($refundStores, $annualRange, $input, $this->participantEmployeeId)
                ->whereIn('payment.member_id', $memberIds)
                ->fieldRaw('payment.member_id member_id,MONTH(refund_operation.business_date) month_no,SUM(payment.amount_cents) amount_cents')
                ->group('payment.member_id,MONTH(refund_operation.business_date)')->select()->toArray();
            foreach ($annualAdjustments as $fact) {
                $id=(int)$fact['member_id'];$month=(int)$fact['month_no'];
                $records[$id]['month_'.$month.'_cash_cents']=(int)($records[$id]['month_'.$month.'_cash_cents']??0)+(int)$fact['amount_cents'];
                $records[$id]['annual_cash_cents']+=(int)$fact['amount_cents'];
            }
        }
        $phones = [];
        $annualCardSources = [];
        if ($memberIds) {
            $annualCardSources = $this->annualFirstCardSources($stores, $memberIds, $year);
            foreach (Db::name('user')->whereIn('uid',$memberIds)->field('uid,phone')->select()->toArray() as $row) $phones[(int)$row['uid']] = (string)$row['phone'];
        }
        $columns = $this->columns(['member_name'=>'会员姓名','phone'=>'手机号码','total_visits'=>'总进店数','annual_cash'=>'全年现金业绩','source'=>'来源']);
        foreach ($columns as &$column) {
            if ($column['key'] === 'member_name') {
                $column['source_explanation'] = '所选日期内有成功记账收款或已完成现金退款的会员；只有服务、没有本期现金业务的会员不列入。';
            }
            if ($column['key'] === 'total_visits') {
                $column['source_explanation'] = '本行会员在所选日期内完成且未作废的非体验服务到店天数；没有符合条件的服务为 0，同日多个项目只算一次。“体验”不计门店到店次数，“不算人数人次”只影响人员统计。';
            }
            if ($column['key'] === 'annual_cash') {
                $column['source_explanation'] = '所选日期内有记账收款或现金退款的会员，在所选年份内的记账收款扣除退款实际发生月份的现金退款；退款不增加进店次数，历史权益迁入不计入。';
            }
        }
        unset($column);
        $groups=[];
        foreach (range(1,12) as $month) {
            $keys=['month_'.$month.'_visits','month_'.$month.'_cash'];
            $columns[]=['key'=>$keys[0],'label'=>'进店次数','group_label'=>$month.'月','source_explanation'=>'本行会员在所选日期范围内、该月份完成且未作废的非体验服务到店天数；没有符合条件的服务为 0，同日多项目只算一次。“体验”不计门店到店次数，“不算人数人次”只影响人员统计；退款本身不增加进店次数。'];
            $columns[]=['key'=>$keys[1],'label'=>'现金业绩','group_label'=>$month.'月','source_explanation'=>'本行会员在所选年份该月的成功记账收款，扣除当月已完成的现金退款；退款只影响金额，不增加进店次数，历史权益迁入不计入。'];
            $groups[]=['label'=>$month.'月','column_keys'=>$keys];
        }
        foreach ($records as &$row) {
            $id=(int)$row['member_id'];$row['phone']=$phones[$id]??'';$row['source']=(string)($annualCardSources[$id]??'');$row['annual_cash']=$this->money((int)$row['annual_cash_cents']);
            foreach(range(1,12) as $month){$row['month_'.$month.'_visits']=(int)($row['month_'.$month.'_visits']??0);$row['month_'.$month.'_cash']=$this->money((int)($row['month_'.$month.'_cash_cents']??0));}
        } unset($row);
        $result = $this->result('会员进店分析表',$columns,array_values($records),$input,$groups,['natural_year'=>$year]);
        // 到店仍只认已完成服务；现金退款现在也能让会员在退款日入表。
        $result['metric_version'] = 'store-member-visit-analysis-v5';
        return $result;
    }

    private function memberVisitAnnualSummary(array $stores, array $range, array $input): array
    {
        $year=(int)substr($range['end'],0,4);$mode=(string)($input['mode']??'count');
        if(!in_array($mode,['count','people','project'],true)) throw new \InvalidArgumentException('年度进店统计方式无效');
        // 年度进店人数/次数只取非体验服务；项目数量仍按实际完成量统计，不扣体验项目。
        $query=$this->completedUnvoidedServiceFacts($this->participantCheckout($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_entitlement_service_fact')->alias('annual_visit_service'),$stores,'annual_visit_service'), 'annual_visit_service', $input, $range),'annual_visit_service.checkout_request_id'),'annual_visit_service','annual_visit_void')
            ->whereBetween('annual_visit_service.business_date',[$range['start'],$range['end']])
            // 本表统计会员进店；游客服务不应混入会员年度口径。
            ->where('annual_visit_service.member_id', '>', 0);
        if ($mode !== 'project') StoreCustomerVisitEligibility::apply($query, 'annual_visit_service');
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
        // 项目模式统计实际项目数量；次数/人数模式按非体验服务去重，均不受人员开关影响。
        if ($mode !== 'project') foreach ($columns as &$column) {
            if ($column['key'] === 'total' || strpos($column['key'], 'store_') === 0) {
                $column['source_explanation'] = '统计已完成且未作废的非体验会员服务，按所选次数或人数模式去重；“体验”不计门店到店次数和人数，“不算人数人次”只影响人员统计。';
            }
        }
        unset($column);
        foreach($records as &$row)foreach($storeNames as $id=>$name)$row['store_'.$id]=(int)($row['store_'.$id]??0);unset($row);
        $result = $this->result('会员进店年度汇总表',$columns,array_values($records),$input,[],['mode'=>$mode,'natural_year'=>$year]);
        $result['metric_version'] = 'store-member-visit-annual-v2';
        return $result;
    }

    /** 地推现金只取 E 来源销售明细实际分摊到的有效收款，月份由收款业务日决定。 */
    private function fieldMarketingPaymentAllocations(array $stores)
    {
        $query=$this->cashFacts($stores,'field_sale_payment')
            ->join('cashier_v3_payment_sale_allocation_fact allocation',"allocation.tenant_id=field_sale_payment.tenant_id AND allocation.payment_fact_id=field_sale_payment.fact_id AND allocation.status='effective'")
            ->join('cashier_v3_sale_fact field_sale',"field_sale.tenant_id=allocation.tenant_id AND field_sale.store_id=allocation.store_id AND field_sale.fact_id=allocation.sale_fact_id")
            ->where('field_sale_payment.status','effective')->where('field_sale.status','effective')
            ->where('field_sale_payment.source_document_type','<>','recharge_debt_repayment')
            ->whereIn('field_sale.business_source_primary_id',$this->sourceIds('E')?:[-1]);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query,'field_sale.tenant_id','field_sale.order_id');
        return $query;
    }

    private function fieldMarketingDetail(array $stores,array $range,array $input):array
    {
        $sourceIds=$this->sourceIds('E');$memberId=(int)($input['member_id']??0);
        // 汇总下钻按收款月选原 E 销售明细；普通明细仍按销售/护理发生日筛选。
        $receiptPeriod=(int)($input['receipt_period']??0)===1&&$memberId>0;
        $receiptSaleIds=[];
        if($receiptPeriod){
            $receiptSaleIds=array_column($this->fieldMarketingPaymentAllocations($stores)
                ->where('field_sale.member_id',$memberId)
                ->whereBetween('field_sale_payment.business_date',[$range['start'],$range['end']])
                ->fieldRaw('field_sale.fact_id')->group('field_sale.fact_id')->select()->toArray(),'fact_id');
        }
        // 两张地推表都按会员归行；未绑定会员的销售单保留在订单事实中，但不能合并成一条空会员报表行。
        // 导购事实使用结账明细号，销售事实使用订单明细号；经订单明细映射后才能按真实轮次归档收款。
        $base=$this->participantOrder($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_sale_fact')->alias('s'),$stores,'s'), 's', $input, $range),'s.order_id')->leftJoin('cashier_v3_sales_order o','o.order_id=s.order_id')
            ->leftJoin('cashier_v3_sales_order_line sale_line','sale_line.tenant_id=s.tenant_id AND sale_line.order_id=s.order_id AND sale_line.order_line_id=s.source_line_id')
            ->leftJoin('cashier_v3_customer_guide_round_fact guide_round',"guide_round.tenant_id=s.tenant_id AND guide_round.order_id=s.order_id AND guide_round.source_line_id=sale_line.checkout_line_id AND guide_round.status='effective'")
            ->where('s.status','effective')->where('s.member_id','>',0)->whereIn('s.business_source_primary_id',$sourceIds?:[-1]);
        if($receiptPeriod)$base->whereIn('s.fact_id',$receiptSaleIds?:['__no_field_receipt__']);
        else $base->whereBetween('s.business_date',[$range['start'],$range['end']]);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($base,'s.tenant_id','s.order_id');
        if($memberId>0)$base->where('s.member_id',$memberId);
        $base=$base->fieldRaw('s.fact_id,s.store_id,s.organization_id,s.organization_path_snapshot,s.organization_name_snapshot,s.store_name_snapshot,s.member_id,s.member_name_snapshot,s.business_source_secondary_name_snapshot,s.order_id,s.source_line_id,s.business_date,s.occurred_at,MAX(o.order_note) remark,MAX(guide_round.guide_round_no) guide_round_no')
            ->group('s.store_id,s.member_id,s.order_id,s.source_line_id')->order('s.business_date','desc')->select()->toArray();
        // 纯权益结账没有销售事实；以已完成且未作废的服务行补足报表行，
        // 仅取 E 来源及 entitlement_only，混合结账仍由销售行承载，避免重复展示。
        $standalone=$this->completedUnvoidedServiceFacts(
            $this->participantCheckout($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_entitlement_service_fact')->alias('standalone_service'),$stores,'standalone_service'),'standalone_service',$input,$range),'standalone_service.checkout_request_id'),
            'standalone_service','standalone_void'
        )->join('cashier_v3_checkout_request standalone_request','standalone_request.tenant_id=standalone_service.tenant_id AND standalone_request.request_id=standalone_service.checkout_request_id')
            ->leftJoin('cashier_v3_checkout_business_source_selection standalone_source',"standalone_source.tenant_id=standalone_service.tenant_id AND standalone_source.store_id=standalone_service.store_id AND standalone_source.checkout_request_id=standalone_service.checkout_request_id AND standalone_source.checkout_kind='sale'")
            ->where('standalone_request.composition','entitlement_only')
            ->whereBetween('standalone_service.business_date',[$range['start'],$range['end']])
            ->where('standalone_service.is_experience',0)
            ->where('standalone_service.member_id','>',0)
            ->where(function($source)use($sourceIds):void{
                $source->whereIn('standalone_service.business_source_primary_id',$sourceIds?:[-1])
                    ->whereOr('standalone_service.business_source_primary_name_snapshot','like','E%')
                    ->whereOr('standalone_source.primary_source_id','in',$sourceIds?:[-1])
                    ->whereOr('standalone_source.primary_source_name_snapshot','like','E%');
            });
        // 纯权益护理没有现金分摊，不应出现在现金月份下钻中。
        if($receiptPeriod)$standalone->whereRaw('1=0');
        if($memberId>0)$standalone->where('standalone_service.member_id',$memberId);
        $standaloneServiceIds=[];
        foreach($standalone->field('standalone_service.service_fact_id,standalone_service.tenant_id,standalone_service.store_id,standalone_service.organization_id,standalone_service.organization_path_snapshot,standalone_service.organization_name_snapshot,standalone_service.store_name_snapshot,standalone_service.member_id,standalone_service.member_name_snapshot,standalone_service.business_source_primary_id,standalone_service.business_source_primary_name_snapshot,standalone_service.business_source_secondary_name_snapshot,standalone_service.checkout_request_id,standalone_service.source_line_id,standalone_service.business_date,standalone_service.occurred_at,standalone_source.primary_source_id selected_primary_id,standalone_source.primary_source_name_snapshot selected_primary_name')->select()->toArray() as $service){
            $sourceName=trim((string)$service['business_source_primary_name_snapshot']);
            $isFieldSource=$sourceName!==''?$this->sourcePrefix(['name'=>$sourceName])==='E':
                ((int)$service['business_source_primary_id']>0?in_array((int)$service['business_source_primary_id'],$sourceIds,true):
                    (in_array((int)$service['selected_primary_id'],$sourceIds,true)||$this->sourcePrefix(['name'=>(string)$service['selected_primary_name']])==='E'));
            if(!$isFieldSource)continue;
            $standaloneServiceIds[(string)$service['service_fact_id']]=true;
            $service['_service_only']=true;
            $service['fact_id']=(string)$service['service_fact_id'];
            $service['order_id']='';
            $service['remark']='';
            $service['guide_round_no']=0;
            $base[]=$service;
        }
        usort($base,static function(array $left,array $right):int{
            return strcmp((string)$right['business_date'],(string)$left['business_date'])
                ?: ((int)$right['occurred_at']<=>(int)$left['occurred_at'])
                ?: strcmp((string)$right['fact_id'],(string)$left['fact_id']);
        });
        $memberIds=array_values(array_unique(array_filter(array_column($base,'member_id'))));$phones=$this->phones($memberIds);
        // 地推护理只归入其发生门店与会员；结账来源和导购轮次必须取护理当次快照，
        // 不能把同会员的 A 来源护理或另一门店的护理带入 E 来源销售行。
        $services=$this->servicesByMember($stores,$memberIds,true);
        $checkoutIds=[];
        foreach($services as $memberServices)foreach($memberServices as $service){
            $checkoutId=(string)($service['checkout_request_id']??'');
            if($checkoutId!=='')$checkoutIds[$checkoutId]=true;
        }
        $selectedSources=[];$serviceRounds=[];$conflictingRounds=[];$checkoutRounds=[];$conflictingCheckoutRounds=[];
        if($checkoutIds){
            foreach(Db::name('cashier_v3_checkout_business_source_selection')->whereIn('store_id',$stores)->whereIn('checkout_request_id',array_keys($checkoutIds))->where('checkout_kind','sale')->field('tenant_id,store_id,checkout_request_id,primary_source_id,primary_source_name_snapshot')->select()->toArray() as $selection){
                $key=(string)$selection['tenant_id'].'|'.(int)$selection['store_id'].'|'.(string)$selection['checkout_request_id'];
                $selectedSources[$key]=$selection;
            }
            foreach($this->scope(Db::name('cashier_v3_customer_guide_round_fact'),$stores)->whereIn('checkout_request_id',array_keys($checkoutIds))->where('status','effective')->field('tenant_id,store_id,checkout_request_id,source_line_id,guide_round_no')->select()->toArray() as $guide){
                $key=(string)$guide['tenant_id'].'|'.(int)$guide['store_id'].'|'.(string)$guide['checkout_request_id'].'|'.(string)$guide['source_line_id'];
                $checkoutKey=(string)$guide['tenant_id'].'|'.(int)$guide['store_id'].'|'.(string)$guide['checkout_request_id'];
                $round=(int)($guide['guide_round_no']??0);
                // 同次结账只允许一个轮次；异常混合轮次不猜测，归入无轮次供核查。
                if(isset($serviceRounds[$key])&&$serviceRounds[$key]!==$round)$conflictingRounds[$key]=true;
                $serviceRounds[$key]=$round;
                if(isset($checkoutRounds[$checkoutKey])&&$checkoutRounds[$checkoutKey]!==$round)$conflictingCheckoutRounds[$checkoutKey]=true;
                $checkoutRounds[$checkoutKey]=$round;
            }
        }
        // 服务与现金分别按各自结账保存的导购轮次落列；没有轮次的业务不能占用前三轮。
        $saleFacts=array_values(array_unique(array_filter(array_column($base,'fact_id'))));$saleCash=[];
        if($saleFacts){
            $paymentQuery=$this->fieldMarketingPaymentAllocations($stores)->whereIn('allocation.sale_fact_id',$saleFacts);
            if($receiptPeriod)$paymentQuery->whereBetween('field_sale_payment.business_date',[$range['start'],$range['end']]);
            else $paymentQuery->where('field_sale_payment.business_date','<=',$range['end']);
            foreach($paymentQuery->fieldRaw('allocation.sale_fact_id,SUM(allocation.amount_cents) amount_cents')->group('allocation.sale_fact_id')->select()->toArray() as $payment){
                $saleCash[(string)$payment['sale_fact_id']]=(int)($saleCash[(string)$payment['sale_fact_id']]??0)+(int)$payment['amount_cents'];
            }
        }
        $keys=array_values(array_unique(array_map(static function(array $row):string{
            return !empty($row['_service_only'])?'service:'.(string)$row['service_fact_id']:(string)$row['source_line_id'];
        },$base)));$manual=$this->annotations('field_acquisition_detail',$stores,$keys);
        foreach ($base as &$row) {
            $member = (int)$row['member_id']; $serviceOnly=!empty($row['_service_only']);
            $subject=$serviceOnly?'service:'.(string)$row['service_fact_id']:(string)$row['source_line_id'];
            $row['division_name'] = (string)$row['organization_name_snapshot'];
            $row['source'] = (string)$row['business_source_secondary_name_snapshot'];
            if($serviceOnly&&$row['source']==='')$row['source']=(string)($row['business_source_primary_name_snapshot']?:$row['selected_primary_name']?:'E地推拓客');
            $row['phone'] = $phones[$member] ?? '';
            $row['card_sale_date'] = $manual[$subject]['card_sale_date']['value'] ?? '';
            $row['card_sale_date_version'] = (int)($manual[$subject]['card_sale_date']['version'] ?? 0);
            $row['visit_over_one_hour'] = $manual[$subject]['visit_over_one_hour']['value'] ?? '';
            $row['visit_over_one_hour_version'] = (int)($manual[$subject]['visit_over_one_hour']['version'] ?? 0);
            $allServices=[];
            foreach($services[(int)$row['store_id'].'|'.$member]??[] as $service){
                if(!$serviceOnly&&isset($standaloneServiceIds[(string)$service['service_fact_id']]))continue;
                if($serviceOnly&&(string)$service['service_fact_id']!==(string)$row['service_fact_id'])continue;
                $checkoutKey=(string)$service['tenant_id'].'|'.(int)$service['store_id'].'|'.(string)$service['checkout_request_id'];
                $sourceName=trim((string)($service['business_source_primary_name_snapshot']??''));
                $selected=$selectedSources[$checkoutKey]??[];
                $isFieldSource=$sourceName!==''
                    ? $this->sourcePrefix(['name'=>$sourceName])==='E'
                    : ((int)($service['business_source_primary_id']??0)>0
                        ? in_array((int)$service['business_source_primary_id'],$sourceIds,true)
                        : (in_array((int)($selected['primary_source_id']??0),$sourceIds,true)
                            || $this->sourcePrefix(['name'=>(string)($selected['primary_source_name_snapshot']??'')])==='E'));
                if(!$isFieldSource)continue;
                // 新单只认护理行自身轮次；旧混合结账只有销售行导购时，
                // 仅在整笔结账轮次唯一的情况下兼容继承，不能跨行猜测混合轮次。
                $serviceKey=$checkoutKey.'|'.(string)($service['source_line_id']??'');
                $service['guide_round_no']=isset($conflictingRounds[$serviceKey])?0:
                    ($serviceRounds[$serviceKey]??(isset($conflictingCheckoutRounds[$checkoutKey])?0:($checkoutRounds[$checkoutKey]??0)));
                $allServices[]=$service;
            }
            $cashSlot = (int)($row['guide_round_no'] ?? 0);
            $row['cash_none'] = $this->money($cashSlot === 0 ? (int)($saleCash[(string)$row['fact_id']] ?? 0) : 0);
            // 护理列只接纳 E 来源的非体验服务；轮次从该护理结账的导购事实读取，
            // NULL/缺失均明确留在无轮次，不能按业务日期把它顺推到第一至三次。
            $visits = StoreCustomerVisitEligibility::filter($allServices);
            $row['first_e_visit_date']=(string)($visits[0]['business_date']??'');
            foreach(range(0,3) as $round){
                $slot=$round===0?'none':(string)$round;
                $roundVisits=array_values(array_filter($visits,static function(array $visit)use($round):bool{
                    return (int)($visit['guide_round_no']??0)===$round;
                }));
                foreach(['craftsman'=>'craftsman','date'=>'business_date','project'=>'project_name_snapshot'] as $suffix=>$field){
                    $values=array_values(array_filter(array_map(static function(array $visit)use($field):string{
                        return trim((string)($visit[$field]??''));
                    },$roundVisits),static function(string $value):bool{return $value!=='';}));
                    $row['visit_'.$slot.'_'.$suffix]=implode('、',$values);
                }
                if($round>0)$row['cash_'.$round]=$this->money($cashSlot===$round?(int)($saleCash[(string)$row['fact_id']]??0):0);
            }
            // 手工费沿用前三条实际服务、至少三条才显示的原口径，但只在 E 来源护理中计算。
            $fee = array_sum(array_map(static function (array $service): int {
                return (int)($service['labor_fee_amount_cents'] ?? 0);
            }, array_slice($allServices, 0, 3)));
            $row['labor_fee'] = $this->money(count($allServices) >= 3 ? $fee : 0);
            $row['fourth_and_above'] = max(0, count($visits) - 3);
            $row['annotation_subject_key'] = $subject; $row['annotation_subject_type'] = $serviceOnly?'business_event_line':'sale_line';
        }
        unset($row);
        $columns=$this->columns(['division_name'=>'分公司','store_name_snapshot'=>'门店','source'=>'来源','member_name_snapshot'=>'会员','card_sale_date'=>'卖卡日期','phone'=>'手机号码','visit_over_one_hour'=>'进店满1小时']);$groups=[];
        foreach(['none'=>'无轮次',1=>'第一次',2=>'第二次',3=>'第三次'] as $slot=>$label){
            $keys=['visit_'.$slot.'_craftsman','visit_'.$slot.'_date','visit_'.$slot.'_project'];
            // 每列分别说明用户看到的人、日期和项目；轮次取当次护理，不按日期推算。
            $roundText=$slot==='none'?'未选择导购轮次':'选择导购第'.$slot.'轮';
            foreach(array_combine($keys,['手艺人','护理日期','项目名称'])as$key=>$name)$columns[]=[
                'key'=>$key,'label'=>$name,'group_label'=>$label,
                'source_explanation'=>'显示本门店会员已完成、未作废、非体验的 E 地推护理中，'.$roundText.'的'.$name.'；同轮多次护理按日期逐笔列出。',
            ];
            $groups[]=['label'=>$label,'column_keys'=>$keys];
        }
        foreach(['labor_fee'=>'手工费','cash_none'=>'无轮次现金业绩','cash_1'=>'第一次现金业绩','cash_2'=>'第二次现金业绩','cash_3'=>'第三次现金业绩','fourth_and_above'=>'四次及以上','remark'=>'备注']as$key=>$label){
            $column=['key'=>$key,'label'=>$label];
            if($key==='cash_none'){
                $column['source_explanation']=$receiptPeriod
                    ? '本次选中的收款月份内，未选择导购轮次的 E 地推销售明细实际收到的现金；补交只计入补交发生的月份。'
                    : '本笔地推销售未选择导购轮次时分摊到该销售明细的成功记账收款；后续补交仍归原销售明细，不占第1～3轮。';
            }elseif(in_array($key,['cash_1','cash_2','cash_3'],true)){
                $round=(int)substr($key,-1);
                $column['source_explanation']=$receiptPeriod
                    ? '本次选中的收款月份内，选择导购第'.$round.'轮的 E 地推销售明细实际收到的现金；补交只计入补交发生的月份。'
                    : '本笔地推销售选择导购第'.$round.'轮时分摊到该销售明细的成功记账收款；后续补交仍归原销售明细。';
            }
            $columns[]=$column;
        }
        $detailExplanations=[
            'division_name'=>'这笔地推销售或护理发生时所属的分公司，后续改名不追改历史记录。',
            'store_name_snapshot'=>'这笔地推销售或护理发生的门店，只显示当前账号可查看的门店。',
            'source'=>'本表只收录当次来源为 E 地推拓客的销售或纯权益护理。销售时填写了二级来源就显示该名称，未填写显示“-”；纯权益护理未填二级来源时显示当次 E 来源。',
            'member_name_snapshot'=>'这笔销售或护理当时记录的会员姓名，后续改名不追改历史记录。',
            'card_sale_date'=>'由员工为这一笔销售或纯权益护理手动填写的卖卡日期；未填写显示“-”，不拿护理日期代替。',
            'phone'=>'会员资料中当前登记的手机号码；没有登记时显示“-”。',
            'visit_over_one_hour'=>'由员工为本行手动填写的进店满 1 小时次数；未填写显示 0。',
            'labor_fee'=>'该会员本门店前 3 条已完成、未作废的 E 来源护理所记手工费合计；不足 3 条时显示 0。',
            'fourth_and_above'=>'该会员本门店已完成、未作废、非体验的 E 来源护理超过 3 次的次数；不足 4 次显示 0。',
            'remark'=>'地推销售单上填写的备注；纯权益护理没有销售单备注时显示“-”。',
        ];
        foreach($columns as &$column)if(isset($detailExplanations[$column['key']]))$column['source_explanation']=$detailExplanations[$column['key']];unset($column);
        return $this->result('地推拓客明细表',$columns,$base,$input,$groups);
    }

    private function fieldMarketingSummary(array $stores,array $range,array $input):array
    {
        $detail=$this->fieldMarketingDetail($stores,$range,array_merge($input,['page'=>1,'limit'=>100,'_internal_all'=>true]));$records=[];$year=(int)substr($range['end'],0,4);
        // 首护日期按符合 E 来源条件的最早护理事实取值，不等同于导购“第一轮”。
        foreach($detail['records'] as $row){$member=(int)$row['member_id'];if(isset($records[$member])){
            $date=(string)$row['first_e_visit_date'];if($date!==''&&($records[$member]['first_visit_date']===''||$date<$records[$member]['first_visit_date']))$records[$member]['first_visit_date']=$date;
            continue;
        }$records[$member]=['member_id'=>$member,'card_sale_date'=>$row['card_sale_date'],'member_name_snapshot'=>$row['member_name_snapshot'],'phone'=>$row['phone'],'source'=>$row['source'],'first_visit_date'=>$row['first_e_visit_date'],'remark'=>$row['remark'],'annotation_subject_key'=>$row['annotation_subject_key']];}
        $memberIds=array_keys($records);$cash=[];
        if($memberIds){
            // 只汇总报表中 E 来源销售明细的有效分摊；按实际收款业务日落月，不能按会员全部来源收款凑数。
            $payments=$this->fieldMarketingPaymentAllocations($stores)
                ->whereIn('field_sale.member_id',$memberIds)
                ->whereBetween('field_sale_payment.business_date',[$year.'-01-01',$year.'-12-31'])
                ->fieldRaw('field_sale.member_id,MONTH(field_sale_payment.business_date) month_no,SUM(allocation.amount_cents) amount_cents')
                ->group('field_sale.member_id,MONTH(field_sale_payment.business_date)')->select()->toArray();
            foreach($payments as $payment)$cash[(int)$payment['member_id']][(int)$payment['month_no']]=(int)$payment['amount_cents'];
        }
        foreach($records as &$row){$row['annual_total_cents']=0;$row['_drilldown']=[];foreach(range(1,12)as$m){$c=(int)($cash[(int)$row['member_id']][$m]??0);$monthKey='month_'.$m;$row[$monthKey]=$this->money($c);$row['annual_total_cents']+=$c;if($c!==0)$row['_drilldown'][$monthKey]=['report'=>'field_acquisition_detail','params'=>['start_date'=>sprintf('%04d-%02d-01',$year,$m),'end_date'=>date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$year,$m))),'receipt_period'=>1],'param_map'=>['member_id'=>'member_id']];}$row['annual_total']=$this->money($row['annual_total_cents']);if((int)$row['annual_total_cents']!==0)$row['_drilldown']['annual_total']=['report'=>'field_acquisition_detail','params'=>['start_date'=>$year.'-01-01','end_date'=>$year.'-12-31','receipt_period'=>1],'param_map'=>['member_id'=>'member_id']];}unset($row);
        $columns=$this->columns(['card_sale_date'=>'卖卡日期','member_name_snapshot'=>'会员','phone'=>'手机号码','source'=>'来源','first_visit_date'=>'首次护理日期','annual_total'=>'首年业绩合计']);foreach(range(1,12)as$m)$columns[]=['key'=>'month_'.$m,'label'=>$m.'月'];$columns[]=['key'=>'remark','label'=>'备注'];
        $explanations=[
            'card_sale_date'=>'本会员最近一笔入表地推销售或纯权益护理上手动填写的卖卡日期；未填显示“-”，不拿护理日期代替。',
            'member_name_snapshot'=>'本会员最近一笔入表地推业务当时记录的姓名；同会员合并为一行。',
            'phone'=>'会员资料中当前登记的手机号码；未登记显示“-”。',
            'source'=>'最近一笔入表地推业务填写的二级来源；销售未填二级来源显示“-”，纯权益护理未填时显示当次 E 地推来源。',
            'first_visit_date'=>'本会员在本表 E 来源业务中，最早一笔已完成、未作废、非体验护理的日期；不按导购轮次推算。',
            'annual_total'=>'本自然年 1～12 月 E 地推销售明细实际分摊到的有效现金收款合计；按收款业务日期计入，其他来源收款不计。',
            'remark'=>'最近一笔入表地推销售单填写的备注；未填写显示“-”。',
        ];
        foreach($columns as &$column){
            $key=(string)$column['key'];
            $column['source_explanation']=isset($explanations[$key])?$explanations[$key]:
                (preg_match('/^month_(\d{1,2})$/',$key,$match)?$match[1].'月实际收到、分摊给 E 地推销售明细的有效现金；后续补交按补交的收款月份计入，其他来源收款不计。':'');
        }unset($column);
        return $this->result('地推拓客汇总表',$columns,array_values($records),$input,[],['natural_year'=>$year,'annual_total_cents'=>$this->sumField($records,'annual_total_cents')]);
    }

    /**
     * 按收款业务日读取异业收客现金，销售、消费欠款补交和充值补交共用收款事实，
     * 不能再拼接补交单金额，否则一笔补交会重复统计。来源沿用本次收款的冻结选择；
     * 指定会员的后续收款下钻才放宽来源，门店、人员和作废过滤仍由 cashFacts 统一执行。
     */
    private function crossIndustryDetail(array $stores,array $range,array $input):array
    {
        $sourceIds=$this->sourceIds('G');$memberId=(int)($input['member_id']??0);$includeFollowupCash=(int)($input['include_followup_cash']??0)===1&&$memberId>0;
        $payments=$this->cashFacts($stores)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective');
        if(!$includeFollowupCash)$payments->whereIn('business_source_primary_id',$sourceIds?:[-1]);
        if($memberId>0)$payments->where('member_id',$memberId);
        // 日期与金额都取本次收款事实；跨日收退款不能合并后任取一天，更不能用首次护理日替代。
        // 使用收款日期专用投影，不触发 result 的通用组织维度增列；单号取收款快照，补交不能显示成原销售单号。
        $payments=$payments->fieldRaw('store_id,order_id,checkout_request_id,member_id,business_date receipt_business_date,MAX(order_no_snapshot) document_no,MAX(member_name_snapshot) member_name,MAX(store_name_snapshot) store_name,MAX(business_source_label_snapshot) source,SUM(amount_cents) amount_cents')
            ->group('store_id,order_id,checkout_request_id,member_id,business_date')->select()->toArray();
        // 手机号沿用会员资料权威源，仅查询当前授权收款结果里的会员，不新增全库联系人入口。
        $phones=$this->phones(array_values(array_unique(array_filter(array_column($payments,'member_id')))));
        $keys=array_values(array_unique(array_column($payments,'order_id')));$orders=[];if($keys){$fields=$this->hasColumn('cashier_v3_sales_order','reward_amount_cents')?'order_id,order_note,reward_amount_cents':'order_id,order_note';foreach(Db::name('cashier_v3_sales_order')->whereIn('order_id',$keys)->field($fields)->select()->toArray()as$r)$orders[(string)$r['order_id']]=$r;}
        // 只补充已授权收款行对应的本次补交奖励，不关联原销售单奖励；组合收款仍按补交单计一次。
        // 未升级的历史库读作零，正式写入必须先执行奖励列迁移；不反向回填旧单。
        if($keys)foreach(['cashier_v3_debt_repayment','cashier_v3_recharge_debt_repayment'] as $table){
            if(!$this->hasColumn($table,'reward_amount_cents'))continue;
            $repayments=$this->scope(Db::name($table),$stores)->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('repayment_id',$keys)->where('status','succeeded')->field('repayment_id,reward_amount_cents')->select()->toArray();
            foreach($repayments as $repayment)$orders[(string)$repayment['repayment_id']]=['reward_amount_cents'=>(int)$repayment['reward_amount_cents']];
        }
        // 新增业务日分行只拆收款；奖励仍按原来的门店、单据、结账、会员粒度计一次，不能因跨日退款重复累计。
        $rewardSubjects=[];
        foreach($payments as &$row){
            $key=(string)$row['order_id'];
            $rewardSubject=json_encode([$row['store_id'],$key,$row['checkout_request_id'],$row['member_id']]);
            $row['full_payment']=$this->money((int)$row['amount_cents']);
            $row['phone']=$phones[(int)$row['member_id']]??'';
            $row['reward']=$this->money(isset($rewardSubjects[$rewardSubject])?0:(int)($orders[$key]['reward_amount_cents']??0));
            $rewardSubjects[$rewardSubject]=true;
            $row['remark']=(string)($orders[$key]['order_note']??'');$row['annotation_subject_key']=$key;$row['annotation_subject_type']='sales_order';$row['source_order_id']=$key;
        }unset($row);
        return $this->result('异业收客明细表',$this->columns(['source'=>'来源','receipt_business_date'=>'业务日期','store_name'=>'门店','document_no'=>'单据号','member_name'=>'会员','phone'=>'手机号','full_payment'=>'收客全款业绩','reward'=>'奖励','remark'=>'备注']),$payments,$input);
    }

    /** 本年 G 来源销售或补交均可成为异业顾客；先确定会员名单，再计算后续收款，不能把补交当新销售重复加款。 */
    private function crossIndustrySummaryMembers(array $stores,array $range,array $input):array
    {
        $sourceIds=$this->sourceIds('G');$year=(int)substr($range['end'],0,4);$sales=$this->participantOrder($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_sale_fact')->alias('cross_sale'),$stores,'cross_sale'),'cross_sale',$input,['start'=>$year.'-01-01','end'=>$year.'-12-31']),'cross_sale.order_id')->whereBetween('cross_sale.business_date',[$year.'-01-01',$year.'-12-31'])->where('cross_sale.status','effective')->whereIn('cross_sale.business_source_primary_id',$sourceIds?:[-1]);
        $sales->where('cross_sale.fact_direction','forward')->where('cross_sale.member_id','>',0);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($sales,'cross_sale.tenant_id','cross_sale.order_id');
        $sales=$sales->fieldRaw('cross_sale.store_id,cross_sale.member_id,MAX(cross_sale.member_name_snapshot) member_name,MIN(cross_sale.source_line_id) source_line_id,MAX(cross_sale.item_name_snapshot) card_name,MIN(cross_sale.business_date) first_sale_date')->group('cross_sale.store_id,cross_sale.member_id')->select()->toArray();
        $members=[];
        foreach($sales as $row){
            $row['annotation_subject_key']=(string)$row['source_line_id'];$row['annotation_subject_type']='sale_line';
            $members[(int)$row['store_id'].'|'.(int)$row['member_id']]=$row;
        }
        $repayments=$this->cashFacts($stores)->whereBetween('business_date',[$year.'-01-01',$year.'-12-31'])
            ->where('status','effective')->where('fact_direction','forward')->where('amount_cents','>',0)->where('member_id','>',0)
            ->whereIn('business_source_primary_id',$sourceIds?:[-1])->whereIn('source_document_type',['debt_repayment','recharge_debt_repayment'])
            ->field('id,fact_id,store_id,member_id,member_name_snapshot,order_id')->order('id','asc')->select()->toArray();
        $savedRepayments=$this->annotations('cross_industry_customer_summary',$stores,array_column($repayments,'fact_id'));
        $anchored=[];
        foreach($repayments as $payment){
            $memberKey=(int)$payment['store_id'].'|'.(int)$payment['member_id'];$factKey=(string)$payment['fact_id'];
            if(!isset($members[$memberKey])){
                // 补交没有本年销售明细时仍入表，但不能凭补交金额虚构卡项名称或首次卖卡日。
                $members[$memberKey]=['store_id'=>(int)$payment['store_id'],'member_id'=>(int)$payment['member_id'],
                    'member_name'=>(string)$payment['member_name_snapshot'],'source_line_id'=>'','card_name'=>'','first_sale_date'=>'',
                    'annotation_subject_key'=>$factKey,'annotation_subject_type'=>'cross_industry_repayment','source_order_id'=>(string)$payment['order_id']];
            }
            // 补交行已保存过手动收客信息时保留原主题；以后发生销售或再次补交也不能让已保存值消失。
            if(isset($savedRepayments[$factKey])&&!isset($anchored[$memberKey])){
                $members[$memberKey]['annotation_subject_key']=$factKey;$members[$memberKey]['annotation_subject_type']='cross_industry_repayment';
                $members[$memberKey]['source_order_id']=(string)$payment['order_id'];$anchored[$memberKey]=true;
            }
        }
        return array_values($members);
    }

    /** 汇总延续自然年及后续全部来源收款口径；按门店+会员归集，月度下钻带同一门店范围。 */
    private function crossIndustrySummary(array $stores,array $range,array $input):array
    {
        $year=(int)substr($range['end'],0,4);$sales=$this->crossIndustrySummaryMembers($stores,$range,$input);
        $memberIds=array_values(array_unique(array_filter(array_column($sales,'member_id'))));$phones=$this->phones($memberIds);$dailyCash=$this->cashByStoreMemberDay($stores,$memberIds,$year.'-01-01',$year.'-12-31');$services=$this->servicesByMember($stores,$memberIds,true);$entitlements=$this->activeCardEntitlements($stores,$memberIds);$keys=array_values(array_unique(array_column($sales,'annotation_subject_key')));$manual=$this->annotations('cross_industry_customer_summary',$stores,$keys);$total500=0;$total2400=0;
        // 首次到店只读非体验的门店服务；金额和剩余权益仍保留原始事实口径。
        foreach($sales as &$row){
            $id=(int)$row['member_id'];$memberKey=(int)$row['store_id'].'|'.$id;$key=(string)$row['annotation_subject_key'];$row['customer_acquired_at']=$manual[$key]['customer_acquired_at']['value']??'';$row['customer_acquired_at_version']=(int)($manual[$key]['customer_acquired_at']['version']??0);$row['partner_store_name']=$manual[$key]['partner_store_name']['value']??'';$row['partner_store_name_version']=(int)($manual[$key]['partner_store_name']['version']??0);$row['phone']=$phones[$id]??'';$row['remaining_service_count']=(int)($entitlements[$memberKey]['remaining_count']??0);$row['remaining_service_amount']=$this->money((int)($entitlements[$memberKey]['remaining_amount_cents']??0));$visits=StoreCustomerVisitEligibility::filter($services[$memberKey]??[]);$row['first_visit_at']=(string)($visits[0]['business_date']??'');
            $monthly=[];foreach($dailyCash[$memberKey]??[]as$day){$m=(int)substr((string)$day['business_date'],5,2);$monthly[$m]=(int)($monthly[$m]??0)+(int)$day['amount_cents'];}
            $annual=0;foreach(range(1,12)as$m){$c=(int)($monthly[$m]??0);$row['month_'.$m]=$this->money($c);$annual+=$c;}$row['annual_cash']=$this->money($annual);
            $first500=0;$reached2400=0;$running=0;$first500Date='';$reached2400Date='';
            foreach($dailyCash[$memberKey]??[]as$day){
                $amount=(int)$day['amount_cents'];
                if($first500===0&&$amount>=50000){$first500=$amount;$first500Date=(string)$day['business_date'];}
                $before=$running;$running+=$amount;
                if($reached2400===0&&$before<240000&&$running>=240000){$reached2400=$running;$reached2400Date=(string)$day['business_date'];}
            }
            $row['first_500']=$this->money($first500);$row['reached_2400']=$this->money($reached2400);$total500+=$first500;$total2400+=$reached2400;$row['_drilldown']=[];$annualConfig=['report'=>'cross_industry_customer_detail','params'=>['start_date'=>$year.'-01-01','end_date'=>$year.'-12-31','include_followup_cash'=>1],'param_map'=>['member_id'=>'member_id','store_ids'=>'store_id']];foreach(range(1,12)as$m){$monthKey='month_'.$m;if((int)($monthly[$m]??0)===0)continue;$row['_drilldown'][$monthKey]=['report'=>'cross_industry_customer_detail','params'=>['start_date'=>sprintf('%04d-%02d-01',$year,$m),'end_date'=>date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$year,$m))),'include_followup_cash'=>1],'param_map'=>['member_id'=>'member_id','store_ids'=>'store_id']];}if($annual!==0)$row['_drilldown']['annual_cash']=$annualConfig;
            // 门槛单元格只下钻到组成它的单日/累计日期，不能用全年流水解释较小的门槛金额。
            foreach(['first_500'=>$first500Date,'reached_2400'=>$reached2400Date] as $field=>$thresholdDate){
                if($thresholdDate==='')continue;
                $config=$annualConfig;$config['params']['end_date']=$thresholdDate;
                if($field==='first_500')$config['params']['start_date']=$thresholdDate;
                $row['_drilldown'][$field]=$config;
            }
        }unset($row);
        $columns=$this->columns(['customer_acquired_at'=>'收客时间','partner_store_name'=>'异业店名','member_name'=>'会员','phone'=>'手机号码','card_name'=>'卡项名称','remaining_service_count'=>'剩余服务次数','remaining_service_amount'=>'剩余服务金额','first_500'=>'首次成交满500','reached_2400'=>'成交满2400','first_visit_at'=>'首次到店时间','annual_cash'=>'全年现金业绩']);foreach(range(1,12)as$m)$columns[]=['key'=>'month_'.$m,'label'=>$m.'月'];
        return $this->result('异业收客汇总表',$columns,$sales,$input,[],['first_500_total'=>$this->money($total500),'reached_2400_total'=>$this->money($total2400),'natural_year'=>$year]);
    }

    /**
     * 查询顾客首次有效疗程卡销售的对应订单，含全款和欠款销售。
     * 用首次销售业务日决定新客月份，并隐藏已作废原单；后续补清只计收款。
     * 本组新客汇总/明细按已确认口径排除一级来源 A；按来源 ID 判定，
     * 包含已停用但仍有历史事实的来源，不能只凭当前展示名称过滤。
     * 门店、组织和个人参与范围与其他门店报表使用同一后端权限限制。
     */
    private function firstCourseOrders(array $stores,array $range,array $input):array
    {
        // 两张新客报表以首次有效疗程卡销售为资格：欠款订单用待付清事件，
        // 全款订单用完成事件。按真实发生顺序选全局首笔，不能在补清时再记一人。
        // 只返回当前账号有权查看的门店，跨店更早订单仅用于资格去重，不泄露其明细。
        $query=$this->participantOrder($this->scope(Db::name('cashier_v3_customer_lifecycle_fact')->alias('first_course'),$stores,'first_course'),'first_course.related_order_id');
        $this->applyOrganizationFilters($query,'first_course',$input,$range);
        $query->whereIn('first_course.event_type',['course_pending_settlement','first_course_completed'])
            ->where('first_course.status','effective')->where('first_course.member_id','>',0)
            ->whereBetween('first_course.business_date',[StoreUnifiedReportServices::COVERAGE_START,$range['end']]);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($query,'first_course.tenant_id','first_course.related_order_id');
        $candidates=$query->field('first_course.id,first_course.tenant_id,first_course.related_order_id,first_course.member_id,first_course.business_date,first_course.source_primary_id,first_course.source_label_snapshot')->select()->toArray();
        if(!$candidates)return[];
        $memberIdsByTenant=[];
        foreach($candidates as $candidate)$memberIdsByTenant[(string)$candidate['tenant_id']][(int)$candidate['member_id']]=true;
        $firstByMember=[];
        foreach($memberIdsByTenant as $tenantId=>$memberIds){
            // tenant + event + business_date 命中现有索引；跨店查首笔仅作资格判断，
            // 返回结果仍限于上方已授权的候选门店，不引入第二套历史回填或写库。
            $history=Db::name('cashier_v3_customer_lifecycle_fact')->alias('history_course')
                ->where('history_course.tenant_id',(string)$tenantId)
                ->whereIn('history_course.member_id',array_keys($memberIds))
                ->whereIn('history_course.event_type',['course_pending_settlement','first_course_completed'])
                ->where('history_course.status','effective')
                ->where('history_course.business_date','<=',$range['end']);
            $this->normalDataScope()->excludeVoidedSalesOrderFacts($history,'history_course.tenant_id','history_course.related_order_id');
            foreach($history->field('history_course.id,history_course.tenant_id,history_course.member_id')->order('history_course.occurred_at','asc')->order('history_course.id','asc')->select()->toArray() as $event){
                $memberKey=(string)$event['tenant_id'].'|'.(int)$event['member_id'];
                if(!isset($firstByMember[$memberKey]))$firstByMember[$memberKey]=(int)$event['id'];
            }
        }
        $excludedSourceIds=$this->newCustomerExcludedSourceIds();
        $out=[];
        foreach($candidates as $event){
            $memberKey=(string)$event['tenant_id'].'|'.(int)$event['member_id'];
            if((int)$event['id']!==($firstByMember[$memberKey]??0)
                ||$this->isExcludedNewCustomerSource((int)$event['source_primary_id'],(string)$event['source_label_snapshot'],$excludedSourceIds))continue;
            $order=(string)$event['related_order_id'];
            if($order!=='')$out[$order]=$event;
        }
        return $out;
    }

    private function newCustomerAnalysis(array $stores,array $range,array $input):array
    {
        // 两张新客报表以首次有效疗程卡销售为资格；定金订单也计人次，补清和后续购买不能再计一次。
        $firstOrders=$this->firstCourseOrders($stores,$range,$input);
        $excludedSourceIds=$this->newCustomerExcludedSourceIds();
        $memberId=(int)($input['member_id']??0);
        $summaryGroupKey=trim((string)($input['summary_group_key']??''));
        $sourceId=(int)($input['source_id']??0);
        $sourceLabel=trim((string)($input['source_label']??''));
        $sales=$this->participantOrder($this->scope(Db::name('cashier_v3_sale_fact')->alias('s'),$stores,'s'),'s.order_id')
            ->leftJoin('cashier_v3_sales_order o','o.order_id=s.order_id')->leftJoin('user u','u.uid=s.member_id')
            ->whereIn('s.order_id',array_keys($firstOrders)?:['__no_first_course_order__'])->where('s.fact_direction','forward')->where('s.status','effective');
        if($memberId>0)$sales->where('s.member_id',$memberId);
        // 明细来源筛选要分别作用于原销售和补交收款；不能先按原单来源过滤销售，
        // 否则补交来源与原单不同的明细会在关联收款前被提前删掉。
        $this->applyOrganizationFilters($sales,'s',$input,$range);
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($sales,'s.tenant_id','s.order_id');
        $rows=$sales->fieldRaw('s.fact_id,s.store_id,s.organization_id,s.organization_path_snapshot,s.organization_name_snapshot,s.store_name_snapshot,s.business_date,s.order_id,s.source_line_id,s.member_id,s.member_name_snapshot,s.business_source_label_snapshot,s.item_name_snapshot,s.sale_amount_cents,s.debt_amount_cents,u.phone,MAX(o.order_note) remark')
            ->group('s.source_line_id')->order('s.business_date','desc')->select()->toArray();
        // 欠款和全款都以首次疗程卡销售业务日记新客，补清不改写首次日期。
        foreach($rows as &$row){
            $event=$firstOrders[(string)$row['order_id']]??null;
            if(!$event||(int)$row['member_id']!==(int)$event['member_id']){$row['_exclude_first_course']=true;continue;}
            $row['business_date']=(string)$event['business_date'];
        }unset($row);
        $rows=array_values(array_filter($rows,static fn(array $row):bool=>empty($row['_exclude_first_course'])));
        $orderIds=array_values(array_unique(array_filter(array_column($rows,'order_id'))));
        $saleFactIds=array_values(array_unique(array_filter(array_column($rows,'fact_id'))));
        $lineIds=array_values(array_unique(array_filter(array_column($rows,'source_line_id'))));
        $personnel=$this->newCustomerPersonnel($stores,$orderIds,$lineIds);
        $guides=$personnel['guides'];$salespeople=$personnel['salespeople'];$managers=$personnel['managers'];
        // 汇总按订单整体人员归属分组，明细按商品行展示；下钻须复用订单级归属而非单行人员，否则会丢失同订单的其他商品。
        $orderPeople=[];foreach($rows as $saleRow){$order=(string)$saleRow['order_id'];$line=(string)$saleRow['source_line_id'];$orderPeople[$order]['salespeople']=array_merge($orderPeople[$order]['salespeople']??[],$salespeople[$line]??[]);$orderPeople[$order]['managers']=array_merge($orderPeople[$order]['managers']??[],$managers[$line]??[]);}
        $payments=[];$repaymentRows=[];
        if($saleFactIds){
            foreach($this->cashFacts($stores,'payment')->join('cashier_v3_payment_sale_allocation_fact allocation',"allocation.tenant_id=payment.tenant_id AND allocation.payment_fact_id=payment.fact_id AND allocation.status='effective'")
                ->whereIn('allocation.sale_fact_id',$saleFactIds)->whereBetween('payment.business_date',[$range['start'],$range['end']])->where('payment.status','effective')
                ->fieldRaw('allocation.sale_fact_id,payment.source_document_type,SUM(allocation.amount_cents) amount_cents')->group('allocation.sale_fact_id,payment.source_document_type')->select()->toArray() as $r) {
                $payments[(string)$r['sale_fact_id']][]=$r;
            }
            foreach($this->cashFacts($stores,'repayment_payment')->join('cashier_v3_payment_sale_allocation_fact repayment_allocation',"repayment_allocation.tenant_id=repayment_payment.tenant_id AND repayment_allocation.payment_fact_id=repayment_payment.fact_id AND repayment_allocation.status='effective'")
                ->whereIn('repayment_allocation.sale_fact_id',$saleFactIds)->where('repayment_payment.source_document_type','debt_repayment')->whereBetween('repayment_payment.business_date',[$range['start'],$range['end']])->where('repayment_payment.status','effective')
                ->fieldRaw('repayment_allocation.sale_fact_id,repayment_payment.fact_id payment_fact_id,repayment_payment.order_id repayment_order_id,repayment_payment.store_id,repayment_payment.business_date,repayment_payment.organization_id,repayment_payment.organization_path_snapshot,repayment_payment.organization_name_snapshot,repayment_payment.store_name_snapshot,repayment_payment.member_id,repayment_payment.member_name_snapshot,repayment_payment.business_source_primary_id,repayment_payment.business_source_label_snapshot,repayment_payment.source_line_id,repayment_payment.source_document_type,SUM(repayment_allocation.amount_cents) amount_cents')
                ->group('repayment_allocation.sale_fact_id,repayment_payment.fact_id,repayment_payment.order_id,repayment_payment.store_id,repayment_payment.business_date,repayment_payment.organization_id,repayment_payment.organization_path_snapshot,repayment_payment.organization_name_snapshot,repayment_payment.store_name_snapshot,repayment_payment.member_id,repayment_payment.member_name_snapshot,repayment_payment.business_source_primary_id,repayment_payment.business_source_label_snapshot,repayment_payment.source_line_id,repayment_payment.source_document_type')->select()->toArray() as $r) {
                $repaymentRows[(string)$r['sale_fact_id']][]=$r;
            }
        }
        $repaymentOrderIds=[];foreach($repaymentRows as $items)foreach($items as $item)$repaymentOrderIds[]=(string)$item['repayment_order_id'];
        $repaymentSalespeople=$this->newCustomerRepaymentSalespeople($stores,array_values(array_unique($repaymentOrderIds)));
        $manual=$this->annotations('new_customer_analysis',$stores,$lineIds);$baseRowsByFact=[];
        foreach($rows as &$row){
            $line=(string)$row['source_line_id'];$order=(string)$row['order_id'];$salesFact=(string)$row['fact_id'];
            $guideRows=$guides[$order]??[];$salespersonRows=$salespeople[$line]??[];$managerRows=$managers[$line]??[];
            // 未分配人员仍是真实新客；当前行来源只按首次办卡事件判断，补交行另按自身收款来源判断。
            $event=$firstOrders[$order];
            if(!$this->newCustomerPersonnelMatches($guideRows,$salespersonRows,$managerRows,$input)
                ||($sourceId>0&&(int)$event['source_primary_id']!==$sourceId)
                ||($sourceLabel!==''&&(string)$event['source_label_snapshot']!==$sourceLabel))$row['_exclude_personnel']=true;
            $craftNames=[];$fee=0;foreach($personnel['performance'][$line]??[] as $f){if((string)$f['performance_type']==='labor_performance_allocated'){$craftNames[]=(string)$f['employee_name_snapshot'];$fee+=(int)$f['labor_fee_amount_cents'];}}
            // 新客明细分组键与显示列都必须先解析当前组织统计维度，
            // 不能让交易时的岗位/组织名称快照冒充分公司。
            $row['division_name']=(string)$row['organization_name_snapshot'];$this->projectOrganization($row,(string)$row['organization_id'],(string)$row['organization_path_snapshot'],(string)$row['business_date']);$row['customer']=(string)$row['member_name_snapshot'];
            $row['guide']=$this->newCustomerNames($guideRows,'guide_employee_id','guide_employee_name_snapshot');
            $row['salesperson']=$this->newCustomerNames($salespersonRows,'employee_id','employee_name_snapshot');
            $row['sales_manager']=$this->newCustomerNames($managerRows,'sales_manager_employee_id','sales_manager_name_snapshot');
            $row['source']=(string)$event['source_label_snapshot'];$row['age']='';$row['care_project']=(string)$row['item_name_snapshot'];$row['craftsman']=implode('、',array_unique(array_filter($craftNames)));
            $row['_summary_group_key']=$this->newCustomerSummaryGroupKey($row,$this->newCustomerNames($guideRows,'guide_employee_id','guide_employee_name_snapshot'),$this->newCustomerNames($orderPeople[$order]['salespeople']??[],'employee_id','employee_name_snapshot'),$this->newCustomerNames($orderPeople[$order]['managers']??[],'sales_manager_employee_id','sales_manager_name_snapshot'),(int)$event['source_primary_id'],(string)$event['source_label_snapshot']);
            $experienceCardAmount=$manual[$line]['experience_card_amount']??[];$row['experience_card_amount']=array_key_exists('value',$experienceCardAmount)?((string)$experienceCardAmount['value']===''?'':$this->money((int)$experienceCardAmount['value'])):$this->money((int)$row['sale_amount_cents']);$row['experience_card_amount_version']=(int)($experienceCardAmount['version']??0);$row['care_duration']=$manual[$line]['care_duration']['value']??'';$row['care_duration_version']=(int)($manual[$line]['care_duration']['version']??0);$row['labor_fee']=$this->money($fee);$row['guide_effective_count']=count($guideRows);$row['guide_performance_round']=implode('、',array_unique(array_map(static fn($g)=>(string)$g['guide_round_no'],$guideRows)));
            // 按商品明细自己的欠款区分全款和定金，避免其他商品的欠款改变本明细归类。
            $collected=0;$hasDebt=(int)$row['debt_amount_cents']>0;foreach($payments[$salesFact]??[] as $p)if(!in_array((string)$p['source_document_type'],['debt_repayment','recharge_debt_repayment'],true))$collected+=(int)$p['amount_cents'];$row['full_payment']=$this->money($hasDebt?0:$collected);$row['deposit_payment']=$this->money($hasDebt?$collected:0);$row['cleared_payment']=$this->money(0);$row['annotation_subject_key']=$line;$row['annotation_subject_type']='sale_line';$baseRowsByFact[$salesFact]=$row;
        }unset($row);
        // 历史首笔订单留在内存中供本期补清追溯，但只把本期首次销售明细显示在当前日期范围。
        $rows=array_values(array_filter($rows,static fn(array $row):bool=>empty($row['_exclude_personnel'])&&(string)$row['business_date']>=$range['start']&&(string)$row['business_date']<=$range['end']));foreach($rows as &$row){unset($row['_exclude_personnel']);}unset($row);
        // 明细的业务识别粒度仍是原销售商品行。同一业务日、门店及人员/来源分组的
        // 补清可并入原行的“清款业绩”；不同日期或归因必须留独立行，避免改写收款时点。
        $saleRowIndex=[];foreach($rows as $index=>$saleRow){$saleRowIndex[implode('|',[(string)$saleRow['order_id'],(string)$saleRow['source_line_id'],(string)$saleRow['business_date'],(string)$saleRow['_summary_group_key']])]=$index;}
        foreach($repaymentRows as $saleFact=>$items){$base=$baseRowsByFact[$saleFact]??null;if(!$base)continue;foreach($items as $item){
            $repayPeople=$repaymentSalespeople[(string)$item['repayment_order_id']]??[];
            $repaySourceId=(int)$item['business_source_primary_id'];$repaySource=(string)$item['business_source_label_snapshot'];
            // 新客明细与汇总统一排除 A 来源补交；不能因为原首单是 B 就让 A 收款混入。
            if($this->isExcludedNewCustomerSource($repaySourceId,$repaySource,$excludedSourceIds))continue;
            if(($sourceId>0&&$repaySourceId!==$sourceId)||($sourceLabel!==''&&$repaySource!==$sourceLabel)||!$this->newCustomerPersonnelMatches([],$repayPeople,[],$input))continue;
            $repay=$base;unset($repay['_exclude_personnel']);$repay['business_date']=(string)$item['business_date'];$repay['store_id']=(int)$item['store_id'];$repay['organization_id']=(string)$item['organization_id'];$repay['organization_path_snapshot']=(string)$item['organization_path_snapshot'];$repay['store_name_snapshot']=(string)$item['store_name_snapshot'];$repay['division_name']=(string)$item['organization_name_snapshot'];$this->projectOrganization($repay,(string)$repay['organization_id'],(string)$repay['organization_path_snapshot'],(string)$repay['business_date']);$repay['source']=$repaySource;$repay['guide']='';$repay['sales_manager']='';$repay['salesperson']=$this->newCustomerNames($repayPeople,'employee_id','employee_name_snapshot');$repay['_summary_group_key']=$this->newCustomerSummaryGroupKey($repay,'',$repay['salesperson'],'',$repaySourceId,$repaySource);$repay['craftsman']='';$repay['experience_card_amount']='';$repay['labor_fee']=$this->money(0);$repay['guide_effective_count']=0;$repay['guide_performance_round']='';$repay['full_payment']=$this->money(0);$repay['deposit_payment']=$this->money(0);$repay['cleared_payment']=$this->money((int)$item['amount_cents']);$repay['annotation_subject_key']=(string)$item['payment_fact_id'];$repay['annotation_subject_type']='debt_repayment';
            $saleKey=implode('|',[(string)$repay['order_id'],(string)$repay['source_line_id'],(string)$repay['business_date'],(string)$repay['_summary_group_key']]);
            if(isset($saleRowIndex[$saleKey])){$target=&$rows[$saleRowIndex[$saleKey]];$target['_merged_cleared_cents']=(int)($target['_merged_cleared_cents']??0)+(int)$item['amount_cents'];$target['cleared_payment']=$this->money($target['_merged_cleared_cents']);unset($target);continue;}
            $rows[]=$repay;
        }}
        foreach($rows as &$row){unset($row['_merged_cleared_cents']);}unset($row);
        if($summaryGroupKey!=='')$rows=array_values(array_filter($rows,static fn(array $row):bool=>($row['_summary_group_key']??'')===$summaryGroupKey));
        return $this->result('新客明细表',$this->columns(['division_name'=>'分公司','store_name_snapshot'=>'门店','business_date'=>'日期','customer'=>'顾客','guide'=>'导购','salesperson'=>'销售人','sales_manager'=>'销售经理','source'=>'来源','age'=>'年龄','phone'=>'手机号码','care_project'=>'护理项目','craftsman'=>'护理手艺人','experience_card_amount'=>'体验卡金额','care_duration'=>'手艺人护理时长','labor_fee'=>'手艺人手工费','guide_effective_count'=>'导购有效人次','guide_performance_round'=>'导购业绩次数','full_payment'=>'全款业绩','deposit_payment'=>'定金业绩','cleared_payment'=>'清款业绩','remark'=>'备注']),$rows,array_merge($input,['_guide_filter_options'=>$this->newCustomerGuideFilterOptions($stores,array_keys($firstOrders))]));
    }

    private function newCustomerSummary(array $stores,array $range,array $input):array
    {
        $records=[];$year=(int)substr($range['end'],0,4);$currentYear=(int)date('Y');$lastMonth=$year===$currentYear?min((int)date('n'),(int)substr($range['end'],5,2)):12;
        $firstOrders=$this->firstCourseOrders($stores,$range,$input);
        $excludedSourceIds=$this->newCustomerExcludedSourceIds();
        $firstOrderIds=array_keys($firstOrders);
        // 现金列以 payment_fact.business_date 为唯一统计时间，因此退款反向事实
        // 只落在退款实际发生月份，不回写原订单月份。
        $query=$this->cashFacts($stores)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective')->where('member_id','>',0);
        $payments=$query->fieldRaw('fact_id,store_id,organization_id,organization_path_snapshot,organization_name_snapshot division_name,store_name_snapshot store_name,member_id,member_name_snapshot member_name,order_id,business_source_primary_id,business_source_label_snapshot source,source_document_type,business_date,amount_cents')->select()->toArray();
        $regularPayments=[];$repaymentFactIds=[];foreach($payments as $row){
            if((string)$row['source_document_type']==='debt_repayment')$repaymentFactIds[]=(string)$row['fact_id'];
            elseif(isset($firstOrders[(string)$row['order_id']])&&!in_array((string)$row['source_document_type'],['recharge_debt_repayment'],true))$regularPayments[(string)$row['fact_id']]=$row;
        }
        $regular=[];
        if($regularPayments&&$firstOrderIds){
            // 一张订单可以同时有全款商品和欠款商品；先按收款分摊明细归属，再汇总金额。
            $allocated=Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')
                ->join('cashier_v3_sale_fact origin',"origin.tenant_id=a.tenant_id AND origin.fact_id=a.sale_fact_id AND origin.status='effective' AND origin.fact_direction='forward'")
                ->whereIn('a.payment_fact_id',array_keys($regularPayments))->whereIn('origin.order_id',$firstOrderIds)
                ->where('a.status','effective')
                ->field('a.payment_fact_id,a.amount_cents,origin.order_id,origin.source_line_id,origin.debt_amount_cents')
                ->select()->toArray();
            foreach($allocated as $allocation){$payment=$regularPayments[(string)$allocation['payment_fact_id']];$payment['amount_cents']=(int)$allocation['amount_cents'];$payment['source_line_id']=(string)$allocation['source_line_id'];$payment['sale_debt_amount_cents']=(int)$allocation['debt_amount_cents'];$regular[]=$payment;}
        }
        $repayments=[];$orderIds=$firstOrderIds;
        if($repaymentFactIds&&$firstOrderIds){foreach($this->cashFacts($stores,'summary_repayment')->join('cashier_v3_payment_sale_allocation_fact a',"a.tenant_id=summary_repayment.tenant_id AND a.payment_fact_id=summary_repayment.fact_id AND a.status='effective'")->join('cashier_v3_sale_fact origin',"origin.tenant_id=a.tenant_id AND origin.fact_id=a.sale_fact_id AND origin.status='effective' AND origin.fact_direction='forward'")->whereIn('summary_repayment.fact_id',$repaymentFactIds)->whereIn('origin.order_id',$firstOrderIds)->where('summary_repayment.source_document_type','debt_repayment')->where('summary_repayment.status','effective')->fieldRaw('summary_repayment.fact_id payment_fact_id,summary_repayment.order_id repayment_order_id,summary_repayment.store_id,summary_repayment.organization_name_snapshot division_name,summary_repayment.store_name_snapshot store_name,summary_repayment.member_id,summary_repayment.member_name_snapshot member_name,summary_repayment.business_date,summary_repayment.business_source_primary_id,summary_repayment.business_source_label_snapshot,a.sale_fact_id,origin.order_id origin_order_id,origin.source_line_id,SUM(a.amount_cents) amount_cents')->group('summary_repayment.fact_id,summary_repayment.order_id,summary_repayment.store_id,summary_repayment.organization_name_snapshot,summary_repayment.store_name_snapshot,summary_repayment.member_id,summary_repayment.member_name_snapshot,summary_repayment.business_date,summary_repayment.business_source_primary_id,summary_repayment.business_source_label_snapshot,a.sale_fact_id,origin.order_id,origin.source_line_id')->select()->toArray() as $row){$repayments[]=$row;}}
        $saleRowsByOrder=[];$lineIds=[];
        if($orderIds){foreach($this->scope(Db::name('cashier_v3_sale_fact'),$stores)->whereIn('order_id',$orderIds)->where('fact_direction','forward')->where('status','effective')->select()->toArray() as $sale){$saleRowsByOrder[(string)$sale['order_id']][]=$sale;$lineIds[]=(string)$sale['source_line_id'];}}
        $personnel=$this->newCustomerPersonnel($stores,$orderIds,array_values(array_unique($lineIds)));$guides=$personnel['guides'];$salespeople=$personnel['salespeople'];$managers=$personnel['managers'];
        // 首次销售事件的冻结来源是新客归属权威值，不能让收款单上的来源把同一新客拆成两组。
        foreach($regular as $row){$order=(string)$row['order_id'];$event=$firstOrders[$order];$sourceId=(int)$event['source_primary_id'];$source=(string)$event['source_label_snapshot'];$guideRows=$guides[$order]??[];$salespersonRows=[];$managerRows=[];foreach($saleRowsByOrder[$order]??[] as $sale){$line=(string)$sale['source_line_id'];$salespersonRows=array_merge($salespersonRows,$salespeople[$line]??[]);$managerRows=array_merge($managerRows,$managers[$line]??[]);} $suffix=(int)$row['sale_debt_amount_cents']>0?'deposit':'full';$this->newCustomerSummaryAddRow($records,$row,$guideRows,$salespersonRows,$managerRows,(int)$row['amount_cents'],$suffix,0,$source,$sourceId,$input);}
        // 一笔首次销售事件只记一次新客，即使该订单拆成多条商品和多次收款。
        foreach($firstOrders as $order=>$event){if((string)$event['business_date']<$range['start'])continue;$saleRows=$saleRowsByOrder[$order]??[];if(!$saleRows)continue;$guideRows=$guides[$order]??[];$salespersonRows=[];$managerRows=[];foreach($saleRows as $sale){$line=(string)$sale['source_line_id'];$salespersonRows=array_merge($salespersonRows,$salespeople[$line]??[]);$managerRows=array_merge($managerRows,$managers[$line]??[]);} $sale=$saleRows[0];$this->newCustomerSummaryAddRow($records,['store_id'=>$sale['store_id'],'member_id'=>$event['member_id'],'member_name'=>$sale['member_name_snapshot'],'organization_id'=>$sale['organization_id'],'organization_path_snapshot'=>$sale['organization_path_snapshot'],'division_name'=>$sale['organization_name_snapshot'],'store_name'=>$sale['store_name_snapshot'],'business_date'=>$event['business_date']],$guideRows,$salespersonRows,$managerRows,0,'full',1,(string)$event['source_label_snapshot'],(int)$event['source_primary_id'],$input);}
        // 补交来源是收款事件自己的历史快照；原办卡来源只决定新客资格，不覆盖补交归因。
        $repaymentSalespeople=$this->newCustomerRepaymentSalespeople($stores,array_values(array_unique(array_column($repayments,'repayment_order_id'))));foreach($repayments as $row){
            // 与明细共用 A 来源判定；合计、下钻及导出都从相同的后端行集得出。
            if($this->isExcludedNewCustomerSource((int)$row['business_source_primary_id'],(string)$row['business_source_label_snapshot'],$excludedSourceIds))continue;
            $this->newCustomerSummaryAddRow($records,$row,[], $repaymentSalespeople[(string)$row['repayment_order_id']]??[],[],(int)$row['amount_cents'],'cleared',0,(string)$row['business_source_label_snapshot'],(int)$row['business_source_primary_id'],$input);
        }
        // 会员不是汇总维度；前端、合计和导出使用同一组不含会员的后端列契约。
        $columns=$this->columns(['system_name'=>'系统名称','division_name'=>'分公司','store_name'=>'门店','guide'=>'导购','salesperson'=>'销售人','sales_manager'=>'销售经理','source'=>'来源']);$groups=[];foreach(range(1,$lastMonth)as$m){$keys=[];foreach([['count','人次'],['full','全款'],['deposit','定金'],['cleared','清款']]as$d){$key='month_'.$m.'_'.$d[0];$columns[]=['key'=>$key,'label'=>$d[1],'group_label'=>$m.'月'];$keys[]=$key;}$groups[]=['label'=>$m.'月','column_keys'=>$keys];}$columns[]=['key'=>'annual_cash','label'=>'全年现金业绩'];
        foreach($records as &$row){$row['_drilldown']=[];foreach(range(1,$lastMonth)as$m){$countKey='month_'.$m.'_count';$row[$countKey]=(int)($row[$countKey]??0);foreach(['full','deposit','cleared']as$s){$key='month_'.$m.'_'.$s;$row[$key]=$this->money((int)($row[$key.'_cents']??0));}$monthStart=max($range['start'],sprintf('%04d-%02d-01',$year,$m));$monthEnd=min($range['end'],date('Y-m-t',strtotime($monthStart)));$config=['report'=>'new_customer_analysis','params'=>['start_date'=>$monthStart,'end_date'=>$monthEnd],'param_map'=>['summary_group_key'=>'summary_group_key']];if($row[$countKey]!==0)$row['_drilldown'][$countKey]=$config;foreach(['full','deposit','cleared']as$s){$key='month_'.$m.'_'.$s;if((int)($row[$key.'_cents']??0)!==0)$row['_drilldown'][$key]=$config;}}$row['annual_cash']=$this->money((int)$row['annual_cash_cents']);if((int)$row['annual_cash_cents']!==0)$row['_drilldown']['annual_cash']=['report'=>'new_customer_analysis','param_map'=>['summary_group_key'=>'summary_group_key']];}unset($row);
        return $this->result('新客汇总表',$columns,array_values($records),array_merge($input,['_guide_filter_options'=>$this->newCustomerGuideFilterOptions($stores,$firstOrderIds)]),$groups,['natural_year'=>$year,'visible_months'=>$lastMonth]);
    }

    private function salespersonBeautyLargeOrder(array $stores,array $range,array $input):array
    {
        // 大单的唯一判断粒度是“销售人（或销售经理）+ 自然月 + 生美二级分类”。
        // 卡项不能以外层卡分类归集。金额与权重保留结账事实，分类则按组件商品
        // 关联的平台当前分类重排，因此历史月份的达标奖也随当前分类重算。
        // 查询从首月月初起算累计，但仅展示用户选中日期内的业务行。
        $calculationRange = ['start' => substr($range['start'], 0, 7) . '-01', 'end' => $range['end']];
        $cardCategorySql=Db::name('cashier_v3_card_sale_category_allocation_fact')->alias('ccf')
            ->where('ccf.status','effective')
            ->whereIn('ccf.store_id',$stores)
            ->whereBetween('ccf.business_date',[$calculationRange['start'],$calculationRange['end']])
            ->fieldRaw('ccf.tenant_id,ccf.sale_fact_id,SUM(ccf.sale_amount_cents) allocated_sale_total_cents,SUM(ccf.cash_performance_amount_cents) allocated_cash_total_cents')
            ->group('ccf.tenant_id,ccf.sale_fact_id')->buildSql();
        $beautyCategorySql = Db::name('cashier_v3_card_sale_category_allocation_fact')->alias('ccf')
            ->where('ccf.status','effective')
            ->whereIn('ccf.store_id',$stores)
            ->whereBetween('ccf.business_date',[$calculationRange['start'],$calculationRange['end']])
            ->fieldRaw('ccf.tenant_id,ccf.sale_fact_id,ccf.component_product_id,SUM(ccf.cash_performance_amount_cents) component_cash_amount_cents')
            ->group('ccf.tenant_id,ccf.sale_fact_id,ccf.component_product_id')
            ->buildSql();
        $facts=$this->participantEmployeeFact($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_performance_fact')->alias('p'),$stores,'p'),'p',$input,$calculationRange),'p.employee_id')
            ->join('cashier_v3_sale_fact s','s.tenant_id=p.tenant_id AND s.store_id=p.store_id AND s.source_line_id=p.source_line_id AND s.status=\'effective\'')
            ->leftJoin([$cardCategorySql=>'cc'],'cc.tenant_id=s.tenant_id AND cc.sale_fact_id=s.fact_id')
            ->join([$beautyCategorySql=>'bc'],'bc.tenant_id=s.tenant_id AND bc.sale_fact_id=s.fact_id')
            ->whereBetween('p.business_date',[$calculationRange['start'],$calculationRange['end']])->where('p.status','effective')->where('p.performance_type','sales_performance_allocated');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($facts,'p.tenant_id','p.order_id');
        $facts=$facts
            ->whereRaw('cc.allocated_sale_total_cents=s.sale_amount_cents AND cc.allocated_cash_total_cents>0 AND bc.component_cash_amount_cents>0')
            ->fieldRaw('p.tenant_id,p.store_id,p.organization_id,p.organization_path_snapshot,MAX(p.organization_name_snapshot) division_name,MAX(p.store_name_snapshot) store_name,p.business_date,p.employee_id,p.order_id,p.source_line_id,MAX(p.employee_name_snapshot) salesperson,p.member_id,s.fact_id sale_fact_id,bc.component_product_id,MAX(bc.component_cash_amount_cents) component_cash_amount_cents,MAX(cc.allocated_cash_total_cents) allocated_cash_total_cents,SUM(p.amount_cents) base_amount_cents')
            ->group('p.tenant_id,p.store_id,p.organization_id,p.organization_path_snapshot,p.business_date,p.employee_id,p.order_id,p.source_line_id,p.member_id,s.fact_id,bc.component_product_id')->order('p.business_date','desc')->select()->toArray();
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
        $managerFacts = $this->participantEmployeeFact($this->applyOrganizationFilters($this->scope(Db::name('cashier_v3_sales_manager_fact')->alias('sm'),$stores,'sm'),'sm',$input,$calculationRange),'sm.sales_manager_employee_id')
            ->join('cashier_v3_sales_order_line sol','sol.tenant_id=sm.tenant_id AND sol.order_id=sm.order_id AND sol.checkout_line_id=sm.source_line_id')
            ->join('cashier_v3_sale_fact s','s.tenant_id=sol.tenant_id AND s.store_id=sol.store_id AND s.source_line_id=sol.order_line_id AND s.status=\'effective\'')
            ->leftJoin([$managerPaymentSql=>'smp'],'smp.tenant_id=sm.tenant_id AND smp.sales_order_id=sm.order_id')
            ->leftJoin([$cardCategorySql=>'cc'],'cc.tenant_id=s.tenant_id AND cc.sale_fact_id=s.fact_id')
            ->join([$beautyCategorySql=>'bc'],'bc.tenant_id=s.tenant_id AND bc.sale_fact_id=s.fact_id')
            ->whereBetween('sm.business_date',[$calculationRange['start'],$calculationRange['end']])->where('sm.status','effective');
        $this->normalDataScope()->excludeVoidedSalesOrderFacts($managerFacts,'sm.tenant_id','sm.order_id');
        $managerFacts = $managerFacts
            ->whereRaw('cc.allocated_sale_total_cents=s.sale_amount_cents AND cc.allocated_cash_total_cents>0 AND bc.component_cash_amount_cents>0')
            ->fieldRaw("sm.tenant_id,sm.store_id,sm.organization_id,sm.sales_manager_employee_id employee_id,MAX(sm.sales_manager_name_snapshot) salesperson,sm.order_id,sol.order_line_id source_line_id,sm.member_id,sm.business_date,s.fact_id sale_fact_id,bc.component_product_id,MAX(bc.component_cash_amount_cents) component_cash_amount_cents,MAX(cc.allocated_cash_total_cents) allocated_cash_total_cents,MAX(s.organization_path_snapshot) organization_path_snapshot,MAX(s.organization_name_snapshot) division_name,MAX(s.store_name_snapshot) store_name,SUM({$managerAmountSql}) base_amount_cents")
            ->group('sm.tenant_id,sm.store_id,sm.organization_id,sm.sales_manager_employee_id,sm.order_id,sol.order_line_id,sm.member_id,sm.business_date,s.fact_id,bc.component_product_id')->select()->toArray();
        foreach ($managerFacts as &$managerFact) $managerFact['role_snapshot'] = 'sales_manager';
        unset($managerFact);
        $refundFacts = $this->salespersonBeautyRefundFacts($stores, $calculationRange, $input);
        $managerRefundFacts = $this->managerBeautyRefundFacts($stores, $calculationRange, $input);
        $facts = $this->currentBeautyLargeOrderFacts(array_merge($facts, $managerFacts, $refundFacts, $managerRefundFacts));
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
            $row['remark'] = !empty($row['refund_no']) ? '退款单号：' . (string)$row['refund_no'] : '';
            if (isset($awards[$index])) {
                $award = $awards[$index];
                if ((int)$award['threshold_cents'] === 5000000) $row['share_50000_before'] = $this->money((int)$award['amount_cents']);
                else $row['share_30000_before'] = $this->money((int)$award['amount_cents']);
                $row['remark'] .= ($row['remark'] === '' ? '' : '；') . '本月达标分类：' . (string)$award['beauty_category'];
            }
        }
        unset($row);
        $facts = array_values(array_filter($facts, static function (array $row) use ($range): bool {
            return (string)$row['business_date'] >= $range['start']
                && (string)$row['business_date'] <= $range['end'];
        }));
        usort($facts, static function (array $a, array $b): int {
            return strcmp((string)$b['business_date'], (string)$a['business_date'])
                ?: strcmp((string)$b['source_line_id'], (string)$a['source_line_id']);
        });
        $columns=$this->columns(['division_name'=>'分公司','store_name'=>'门店','business_date'=>'业务日期','salesperson'=>'销售人/销售经理','beauty_category'=>'生美二级分类','daily_cash'=>'当日现金业绩','cumulative_cash'=>'当月分类累计现金业绩','share_30000_before'=>'3万生美卡项分成前','share_30000_after'=>'3万生美卡项分成后','share_50000_before'=>'5万生美卡项分成前']);foreach(range(1,5)as$i)$columns[]=['key'=>'share_50000_after_'.$i,'label'=>'5万生美卡项分成后（第'.$i.'次）'];$columns[]=['key'=>'remark','label'=>'备注'];
        // 页面弹窗和导出共用这些业务说明，未确认的兑现列不能被解释成已结算收入。
        $explanations = [
            'division_name' => '显示该笔业务所属门店的分公司，只包含当前账号有权查看的门店。',
            'store_name' => '显示该笔业务实际发生的门店。',
            'business_date' => '销售按成交日期显示；退款按退款成功日期显示，不改写原成交日期。',
            'salesperson' => '显示这笔业绩归属的销售人或销售经理；退款按当前选定人员归属，经理与销售人各按自己的业绩分别统计，不合并为门店现金总额。',
            'beauty_category' => '卡项按卡内项目关联的平台商品当前“生美”二级分类归组，不按卡项外层分类。退款只能归入可核对的原卡内项目分类，无法核对的退款不猜算。分类调整后历史月份会重新归组，原成交金额不变。',
            'daily_cash' => '显示本行人员在该日期、该生美二级分类分配到的现金业绩；退款在成功日记负数。销售人与销售经理分别记账，不可把两类人员金额相加当作门店收款。',
            'cumulative_cash' => '从本月1日到本行日期，累计该人员在同一生美二级分类的销售业绩并扣除已完成的现金退款；不同二级分类不合并凑门槛。',
            'share_30000_before' => '该人员本月有生美二级分类累计达到3万元、且没有分类达到5万元时，只在达标分类本月最后一笔业务上显示该分类金额；同月只选金额最高的一类。此列不是已发放的分成。',
            'share_30000_after' => '分成后金额尚无已确认的计算和兑现记录，暂不显示；空白不代表已发放或金额为零。',
            'share_50000_before' => '该人员本月有生美二级分类累计达到5万元时，只在达标分类本月最后一笔业务上显示该分类金额；同月只选金额最高的一类。此列不是已发放的分成。',
            'remark' => '退款行显示退款单号；达到3万元或5万元时，还会标明本月被选中的生美二级分类。',
        ];
        foreach (range(1, 5) as $i) {
            $explanations['share_50000_after_' . $i] = '第' . $i . '次兑现的分成后金额尚无已确认的计算和兑现记录，暂不显示；空白不代表已发放或金额为零。';
        }
        foreach ($columns as &$column) {
            $column['source_explanation'] = $explanations[(string)$column['key']];
        }
        unset($column);
        $result=$this->result('销售人生美大单统计表',$columns,$facts,$input);
        // v4 admits an exact completed card-purchase receipt when an older sale
        // has no category-allocation fact; cash, person, and refund day are unchanged.
        $result['metric_version']='beauty-large-order-refund-net-v4';
        return $result;
    }

    /**
     * A refund has one selected card component and its own salesperson deduction.
     * Only a verified original sale/component can place that deduction in a beauty category;
     * the refund-day person and amount never inherit the original sale's attribution.
     */
    private function salespersonBeautyRefundFacts(array $stores, array $range, array $input): array
    {
        $query = $this->participantEmployeeFact($this->applyOrganizationFilters(
            $this->scope(Db::name('cashier_v3_performance_fact')->alias('rp'), $stores, 'rp'),
            'rp', $input, $range), 'rp.employee_id')
            ->join('cashier_v3_order_lifecycle_operation ro',
                "ro.tenant_id=rp.tenant_id AND ro.store_id=rp.store_id AND ro.operation_id=rp.order_id"
                . " AND ro.operation_type='refund' AND ro.status='succeeded'")
            ->join('cashier_v3_refund_adjustment ra',
                "ra.tenant_id=rp.tenant_id AND ra.store_id=rp.store_id AND ra.operation_id=rp.order_id"
                . " AND ra.status='effective' AND ra.target_type='card'")
            ->join('cashier_v3_sale_fact original',
                "original.tenant_id=ra.tenant_id AND original.store_id=ra.store_id"
                . " AND original.order_id=ra.source_sales_order_id"
                . " AND original.source_line_id=ra.source_sales_line_id"
                . " AND original.source_type='card' AND original.fact_direction='forward'"
                . " AND original.status='effective'")
            ->join('user_card_holder holder',
                'holder.id=ra.card_holder_id AND holder.uid=ra.member_id')
            ->join('store_order_cart_info component',
                'component.id=ra.card_component_id AND component.oid=holder.oid')
            ->where('rp.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereBetween('rp.business_date', [$range['start'], $range['end']])
            ->where('rp.status', 'effective')->where('rp.fact_direction', 'forward')
            ->where('rp.performance_type', 'refund_sales_deduction')->where('rp.amount_cents', '<', 0)
            ->where('component.product_id', '>', 0)
            ->whereNotExists(function ($reversal): void {
                $reversal->name('cashier_v3_performance_fact')->alias('rp_reversal')
                    ->whereRaw('rp_reversal.tenant_id=rp.tenant_id')
                    ->whereRaw('rp_reversal.reversal_of=rp.fact_id')
                    ->where('rp_reversal.fact_direction', 'reversal')
                    ->where('rp_reversal.status', 'effective');
            });
        $this->requireVerifiedBeautyRefundComponent($query);
        $rows = $query->fieldRaw('rp.tenant_id,rp.store_id,rp.organization_id,rp.organization_path_snapshot,'
            . 'rp.organization_name_snapshot division_name,rp.store_name_snapshot store_name,'
            . 'rp.business_date,rp.employee_id,rp.employee_name_snapshot salesperson,'
            . 'rp.order_id,rp.order_id source_line_id,rp.member_id,original.fact_id sale_fact_id,'
            . 'component.product_id component_product_id,rp.amount_cents direct_amount_cents,'
            . 'ro.operation_no refund_no')->select()->toArray();
        foreach ($rows as &$row) $row['role_snapshot'] = 'salesperson';
        unset($row);
        return $rows;
    }

    /**
     * Manager attribution is versioned independently of salesperson deductions.
     * A selected manager receives the successful cash-refund amount as a negative
     * projection, using the latest complete attribution revision (including clears).
     */
    private function managerBeautyRefundFacts(array $stores, array $range, array $input): array
    {
        $payments = Db::name('cashier_v3_payment_fact')->alias('refund_payment')
            ->where('refund_payment.status', 'effective')
            ->where('refund_payment.fact_direction', 'adjustment')
            ->whereIn('refund_payment.fact_type', ['manual_refund_adjustment', 'payment_refunded'])
            ->where('refund_payment.amount_cents', '<', 0)
            ->whereIn('refund_payment.store_id', $stores)
            ->whereBetween('refund_payment.business_date', [$range['start'], $range['end']])
            ->fieldRaw('refund_payment.tenant_id,refund_payment.store_id,refund_payment.order_id,'
                . 'MAX(refund_payment.organization_id) organization_id,'
                . 'MAX(refund_payment.organization_path_snapshot) organization_path_snapshot,'
                . 'MAX(refund_payment.organization_name_snapshot) division_name,'
                . 'MAX(refund_payment.store_name_snapshot) store_name,'
                . 'SUM(refund_payment.amount_cents) amount_cents')
            ->group('refund_payment.tenant_id,refund_payment.store_id,refund_payment.order_id')
            ->buildSql();
        $latest = Db::name('cashier_v3_refund_attribution_revision')
            ->fieldRaw('tenant_id,store_id,operation_id,MAX(revision_no) revision_no')
            ->group('tenant_id,store_id,operation_id')->buildSql();
        $managerId = 'COALESCE(revised_manager.employee_id,original_manager.employee_id,0)';
        $query = $this->applyOrganizationFilters($this->scope(
            Db::name('cashier_v3_order_lifecycle_operation')->alias('ro'), $stores, 'ro')
            ->join([$payments => 'refund_cash'],
                'refund_cash.tenant_id=ro.tenant_id AND refund_cash.store_id=ro.store_id'
                . ' AND refund_cash.order_id=ro.operation_id')
            ->join('cashier_v3_refund_adjustment ra',
                "ra.tenant_id=ro.tenant_id AND ra.store_id=ro.store_id"
                . " AND ra.operation_id=ro.operation_id AND ra.status='effective' AND ra.target_type='card'")
            ->join('cashier_v3_sale_fact original',
                "original.tenant_id=ra.tenant_id AND original.store_id=ra.store_id"
                . " AND original.order_id=ra.source_sales_order_id"
                . " AND original.source_line_id=ra.source_sales_line_id"
                . " AND original.source_type='card' AND original.fact_direction='forward'"
                . " AND original.status='effective'")
            ->join('user_card_holder holder',
                'holder.id=ra.card_holder_id AND holder.uid=ra.member_id')
            ->join('store_order_cart_info component',
                'component.id=ra.card_component_id AND component.oid=holder.oid')
            ->leftJoin([$latest => 'latest_manager'],
                'latest_manager.tenant_id=ro.tenant_id AND latest_manager.store_id=ro.store_id'
                . ' AND latest_manager.operation_id=ro.operation_id')
            ->leftJoin('cashier_v3_refund_attribution original_manager',
                "original_manager.tenant_id=ro.tenant_id AND original_manager.store_id=ro.store_id"
                . " AND original_manager.operation_id=ro.operation_id"
                . " AND original_manager.role='sales_manager' AND latest_manager.revision_no IS NULL")
            ->leftJoin('cashier_v3_refund_attribution_revision_person revised_manager',
                "revised_manager.tenant_id=ro.tenant_id AND revised_manager.store_id=ro.store_id"
                . " AND revised_manager.operation_id=ro.operation_id"
                . " AND revised_manager.revision_no=latest_manager.revision_no"
                . " AND revised_manager.role='sales_manager'"),
            'refund_cash', $input, $range)
            ->where('ro.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereBetween('ro.business_date', [$range['start'], $range['end']])
            ->where('ro.operation_type', 'refund')->where('ro.status', 'succeeded')
            ->where('refund_cash.amount_cents', '<', 0)
            ->where('component.product_id', '>', 0)->whereRaw($managerId . '>0');
        $this->requireVerifiedBeautyRefundComponent($query);
        if ($this->participantEmployeeId > 0) {
            $query->whereRaw($managerId . '=?', [$this->participantEmployeeId]);
        }
        $rows = $query->fieldRaw('ro.tenant_id,ro.store_id,refund_cash.organization_id,'
            . 'refund_cash.organization_path_snapshot,refund_cash.division_name,refund_cash.store_name,'
            . 'ro.business_date,' . $managerId . ' employee_id,'
            . 'COALESCE(revised_manager.employee_name_snapshot,original_manager.employee_name_snapshot) salesperson,'
            . 'ro.operation_id order_id,ro.operation_id source_line_id,ro.member_id,'
            . 'original.fact_id sale_fact_id,component.product_id component_product_id,'
            . 'refund_cash.amount_cents direct_amount_cents,ro.operation_no refund_no')
            ->select()->toArray();
        foreach ($rows as &$row) $row['role_snapshot'] = 'sales_manager';
        unset($row);
        return $rows;
    }

    /**
     * A refund is classifiable only when the original card component is proven
     * by its sale allocation or by a completed purchase receipt for that exact
     * holder and sale line. The receipt path covers older sales without an
     * allocation fact; an unrelated or complimentary cart must not qualify.
     */
    private function requireVerifiedBeautyRefundComponent($query): void
    {
        $query->where(function ($verified): void {
            $verified->whereExists(function ($allocation): void {
                $allocation->name('cashier_v3_card_sale_category_allocation_fact')->alias('verified_component')
                    ->whereRaw('verified_component.tenant_id=original.tenant_id')
                    ->whereRaw('verified_component.store_id=original.store_id')
                    ->whereRaw('verified_component.sale_fact_id=original.fact_id')
                    ->whereRaw('verified_component.component_product_id=component.product_id')
                    ->where('verified_component.status', 'effective');
            })->whereExists(function ($receipt): void {
                $receipt->name('cashier_v3_card_purchase_receipt')->alias('verified_receipt')
                    ->whereRaw('verified_receipt.tenant_id=original.tenant_id')
                    ->whereRaw('verified_receipt.store_id=original.store_id')
                    ->whereRaw('verified_receipt.member_id=ra.member_id')
                    ->whereRaw('verified_receipt.card_holder_id=holder.id')
                    ->whereRaw('verified_receipt.legacy_order_id=holder.oid')
                    ->whereRaw('verified_receipt.sales_order_id=original.order_id')
                    ->whereRaw('verified_receipt.sales_order_line_id=original.source_line_id')
                    ->where('verified_receipt.status', 'completed')
                    ->where('component.cart_type', 2)->where('component.product_type', 6)
                    ->where('component.is_gift', 0)->where('component.pay_price', '>', 0);
            }, 'OR');
        });
    }

    /** Rebucket frozen card-component cash weights by today's linked platform category before rounding. */
    private function currentBeautyLargeOrderFacts(array $components): array
    {
        $links = [];
        foreach ($components as $index => $component) {
            $links[$index] = ['store_id' => (int)$component['store_id'],
                'product_id' => (int)$component['component_product_id']];
        }
        $resolved = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $bySaleCategory = [];
        foreach ($components as $index => $component) {
            $parts = explode('/', (string)($resolved[$index]['path'] ?? ''));
            if (count($parts) !== 2 || trim($parts[0]) !== '生美' || trim($parts[1]) === '') continue;
            $category = trim($parts[1]);
            $key = json_encode([(string)$component['tenant_id'], (int)$component['store_id'],
                (string)$component['role_snapshot'], (int)$component['employee_id'],
                (string)$component['order_id'], (string)$component['source_line_id'],
                (string)$component['sale_fact_id'], (string)$component['business_date'], $category], JSON_UNESCAPED_UNICODE);
            if (!isset($bySaleCategory[$key])) {
                $bySaleCategory[$key] = $component;
                $bySaleCategory[$key]['beauty_category'] = $category;
                $bySaleCategory[$key]['component_cash_amount_cents'] = 0;
            } elseif (isset($component['direct_amount_cents'])
                || (int)$bySaleCategory[$key]['base_amount_cents'] !== (int)$component['base_amount_cents']
                || (int)$bySaleCategory[$key]['allocated_cash_total_cents'] !== (int)$component['allocated_cash_total_cents']) {
                throw new \RuntimeException('生美大单的卡内分摊基数不一致，请核对原销售事实。');
            }
            if (!isset($component['direct_amount_cents'])) {
                $bySaleCategory[$key]['component_cash_amount_cents'] += (int)$component['component_cash_amount_cents'];
            }
        }
        $byLineCategory = [];
        foreach ($bySaleCategory as $fact) {
            if (isset($fact['direct_amount_cents'])) {
                $amount = (int)$fact['direct_amount_cents'];
            } else {
                $denominator = (int)$fact['allocated_cash_total_cents'];
                if ($denominator <= 0) continue;
                $amount = (int)round((int)$fact['base_amount_cents']
                    * (int)$fact['component_cash_amount_cents'] / $denominator);
            }
            $key = json_encode([(string)$fact['tenant_id'], (int)$fact['store_id'],
                (string)$fact['role_snapshot'], (int)$fact['employee_id'],
                (string)$fact['order_id'], (string)$fact['source_line_id'],
                (string)$fact['business_date'], (string)$fact['beauty_category']], JSON_UNESCAPED_UNICODE);
            if (!isset($byLineCategory[$key])) {
                $byLineCategory[$key] = $fact;
                $byLineCategory[$key]['amount_cents'] = 0;
            }
            $byLineCategory[$key]['amount_cents'] += $amount;
        }
        foreach ($byLineCategory as &$fact) {
            unset($fact['component_product_id'], $fact['component_cash_amount_cents'],
                $fact['allocated_cash_total_cents'], $fact['base_amount_cents'], $fact['sale_fact_id']);
        }
        unset($fact);
        return array_values($byLineCategory);
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
        // 旧销售退款仍以成功的生命周期操作为准，且只读销售单现金退款。
        // 卡项和明细的登记退款在下方单独读取，不能把转入余额叠加为退款。
        $rows=$this->participantOrder($this->scope(Db::name('cashier_v3_order_lifecycle_operation')->alias('r'),$stores,'r'),'r.source_order_id')
            ->join('cashier_v3_sales_order o','o.order_id=r.source_order_id AND o.tenant_id=r.tenant_id')
            ->whereBetween('r.business_date',[$range['start'],$range['end']])
            ->where('r.source_type','sales')->where('r.operation_type','refund')->where('r.status','succeeded')
            ->field('r.id refund_sequence,r.tenant_id,r.business_event_no,r.settled_at refund_settled_at,r.operation_id,r.store_id,r.business_date,r.business_date refund_date,r.source_order_id order_id,r.reason_snapshot refund_reason,r.request_json,r.cash_refund_cents refund_amount_cents,r.restored_principal_cents,r.restored_bonus_cents,o.organization_id,o.organization_path_snapshot,o.organization_name_snapshot market,o.store_name_snapshot store_name,o.member_id,o.member_name_snapshot customer,o.business_date original_sale_date,o.order_note original_remark')
            ->order('r.business_date','desc')->order('r.id','desc')->select()->toArray();
        foreach($rows as &$row){$row['source_type']='sales';$request=json_decode((string)$row['request_json'],true);$names=[];foreach((array)($request['refundLines']??[])as$line){$name=trim((string)($line['itemName']??''));if($name!=='')$names[]=$name;}$row['refund_items']=implode('、',array_values(array_unique($names)));$row['refund_amount']=$this->money((int)$row['refund_amount_cents']);$row['refund_remark']=trim((string)$row['refund_reason'])?:trim((string)$row['original_remark']);}unset($row);
        // 退款调整事实提供组织/会员历史快照并执行与市场 K 相同的权限过滤；
        // 生命周期成功操作决定台账资格、业务日和实际登记退款金额。
        $adjustments=$this->marketRefundAdjustmentFacts($stores,'ledger_refund')
            ->join('cashier_v3_order_lifecycle_operation refund_operation',
                "refund_operation.tenant_id=ledger_refund.tenant_id AND refund_operation.operation_id=ledger_refund.order_id AND refund_operation.operation_type='refund' AND refund_operation.status='succeeded'")
            ->leftJoin('cashier_v3_refund_adjustment adjustment',
                "adjustment.tenant_id=refund_operation.tenant_id AND adjustment.operation_id=refund_operation.operation_id AND adjustment.status='effective'")
            ->leftJoin('cashier_v3_sales_order document_sale',
                "refund_operation.source_type='document' AND document_sale.tenant_id=refund_operation.tenant_id AND document_sale.order_id=refund_operation.source_order_id")
            ->leftJoin('cashier_v3_card_purchase_receipt card_receipt',
                "refund_operation.source_type='card' AND card_receipt.tenant_id=refund_operation.tenant_id AND card_receipt.card_holder_id=adjustment.card_holder_id AND card_receipt.legacy_order_id=refund_operation.source_order_id AND card_receipt.status='completed'")
            ->leftJoin('cashier_v3_sales_order card_sale',
                'card_sale.tenant_id=card_receipt.tenant_id AND card_sale.order_id=card_receipt.sales_order_id')
            ->leftJoin('cashier_v3_sales_order_line card_line',
                'card_line.tenant_id=card_receipt.tenant_id AND card_line.order_line_id=card_receipt.sales_order_line_id')
            ->leftJoin('cashier_v3_sales_order_line document_line',
                "refund_operation.source_type='document' AND document_line.tenant_id=adjustment.tenant_id AND document_line.order_line_id=adjustment.source_line_id")
            ->leftJoin('store_order legacy_card_sale',
                "refund_operation.source_type='card' AND legacy_card_sale.id=refund_operation.source_order_id AND legacy_card_sale.uid=refund_operation.member_id")
            ->whereBetween('ledger_refund.business_date',[$range['start'],$range['end']])
            ->whereIn('refund_operation.source_type',['card','document','imported_card'])
            ->field('refund_operation.id refund_sequence,refund_operation.tenant_id,refund_operation.settled_at refund_settled_at,refund_operation.operation_id,refund_operation.source_type,refund_operation.request_json,refund_operation.reason_snapshot refund_reason,refund_operation.cash_refund_cents refund_amount_cents,refund_operation.business_date,refund_operation.business_date refund_date,refund_operation.source_order_id order_id,ledger_refund.store_id,ledger_refund.organization_id,ledger_refund.organization_path_snapshot,ledger_refund.organization_name_snapshot refund_market,ledger_refund.store_name_snapshot refund_store_name,ledger_refund.member_id,ledger_refund.member_name_snapshot refund_customer,adjustment.card_holder_id,adjustment.target_name_snapshot,adjustment.salespeople_snapshot_json,card_line.order_line_id card_order_line_id,document_line.order_line_id document_order_line_id,document_line.item_id document_product_id,document_line.item_type document_item_type,document_sale.organization_name_snapshot document_market,document_sale.store_name_snapshot document_store_name,document_sale.member_name_snapshot document_customer,document_sale.business_date document_sale_date,card_sale.organization_name_snapshot card_market,card_sale.store_name_snapshot card_store_name,card_sale.member_name_snapshot card_customer,card_sale.business_date card_sale_date,legacy_card_sale.pay_time legacy_pay_time,legacy_card_sale.add_time legacy_add_time')
            ->order('refund_operation.business_date','desc')->order('refund_operation.id','desc')->select()->toArray();
        // 导入旧卡使用成功操作保存的原订单 ID，不回读会员当前持卡关系。
        $importedOrderIds=[];
        foreach($adjustments as $adjustment){
            if((string)$adjustment['source_type']!=='imported_card')continue;
            $request=json_decode((string)$adjustment['request_json'],true);
            $orderId=(int)($request['originOrderId']??0);
            if($orderId>0)$importedOrderIds[$orderId]=$orderId;
        }
        $importedSales=[];
        if($importedOrderIds){
            foreach(Db::name('store_order')->whereIn('id',array_values($importedOrderIds))
                ->field('id,uid,pay_time,add_time')->select()->toArray() as $sale){
                $importedSales[(int)$sale['id']]=$sale;
            }
        }
        foreach($adjustments as $adjustment){
            $isCard=(string)$adjustment['source_type']==='card';
            $request=json_decode((string)$adjustment['request_json'],true);
            $importedSale=$importedSales[(int)($request['originOrderId']??0)]??[];
            if((int)($importedSale['uid']??0)!==(int)$adjustment['member_id'])$importedSale=[];
            $legacyTime=(int)($isCard?$adjustment['legacy_pay_time']:($importedSale['pay_time']??0));
            if($legacyTime<=0)$legacyTime=(int)($isCard?$adjustment['legacy_add_time']:($importedSale['add_time']??0));
            $adjustment['original_sale_date']=(string)($isCard?$adjustment['card_sale_date']:$adjustment['document_sale_date']);
            if($adjustment['original_sale_date']===''&&$legacyTime>0)$adjustment['original_sale_date']=$this->dateTime($legacyTime);
            $adjustment['original_sale_date']=substr($adjustment['original_sale_date'],0,10);
            $adjustment['market']=(string)(($isCard?$adjustment['card_market']:$adjustment['document_market'])?:$adjustment['refund_market']);
            $adjustment['store_name']=(string)(($isCard?$adjustment['card_store_name']:$adjustment['document_store_name'])?:$adjustment['refund_store_name']);
            $adjustment['customer']=(string)(($isCard?$adjustment['card_customer']:$adjustment['document_customer'])?:$adjustment['refund_customer']);
            $adjustment['refund_items']=trim((string)($adjustment['target_name_snapshot']??''))?:trim((string)($request['cardName']??''));
            $adjustment['category']='';
            $adjustment['refund_amount']=$this->money((int)$adjustment['refund_amount_cents']);
            $adjustment['refund_remark']=(string)$adjustment['refund_reason'];
            $rows[]=$adjustment;
        }
        usort($rows,static function(array $left,array $right):int{
            return strcmp((string)$right['business_date'],(string)$left['business_date'])
                ?: ((int)$right['refund_settled_at']<=>(int)$left['refund_settled_at'])
                ?: ((int)$right['refund_sequence']<=>(int)$left['refund_sequence']);
        });
        $this->refundLedgerPersonnel($rows);
        $this->refundLedgerCurrentProductCategories($rows);
        // 退款事实只保存会员身份，没有历史手机快照。权限过滤完成后批量读取
        // 当前会员手机号；号码变更会反映在台账中，不伪装为退款时的历史号码。
        $memberIds=array_values(array_unique(array_filter(array_map('intval',array_column($rows,'member_id')))));
        $phones=$memberIds?Db::name('user')->whereIn('uid',$memberIds)->column('phone','uid'):[];
        foreach($rows as &$row){
            $row['phone']=(string)($phones[(int)($row['member_id']??0)]??'');
            unset($row['refund_sequence'],$row['refund_settled_at'],$row['card_holder_id'],$row['card_order_line_id'],$row['document_order_line_id'],
                $row['document_product_id'],$row['document_item_type']);
        }unset($row);
        // 市场归属仍供客户退款分析使用，本台账只移除其展示及导出列。
        $columns=$this->columns(['store_name'=>'门店','customer'=>'会员','phone'=>'手机','refund_date'=>'退款申请时间','salesperson'=>'销售人','guide'=>'导购','sales_manager'=>'销售经理','category'=>'分类','refund_items'=>'退款项目','original_sale_date'=>'原销售日期','refund_amount'=>'退款金额','refund_remark'=>'退款原因']);
        $explanations=[
            'store_name'=>'优先显示原销售单保存的门店；旧卡没有原销售快照时显示办理退款的门店。',
            'customer'=>'优先显示原销售单保存的会员姓名；旧卡没有原销售快照时显示退款记录保存的姓名。',
            'phone'=>'显示会员当前登记的手机号；退款时没有保存历史号码，会员改号后此列会更新。',
            'refund_date'=>'成功办理退款的业务日期，不按原销售日期回填。',
            'salesperson'=>'显示本笔退款分配的销售人；改派后显示当前有效人员。原销售退款取当次负向销售业绩人员，旧记录没有可靠快照时留空。',
            'guide'=>'显示本笔退款选择的导购；改派后显示当前有效人员。未保存归属的旧退款留空，不回填原销售导购。',
            'sales_manager'=>'显示本笔退款选择的销售经理；改派后显示当前有效人员。未保存归属的旧退款留空，不回填原销售经理。',
            'category'=>'按退款所对应商品或卡内项目的平台商品当前分类显示；多项退款合并显示。没有可核对商品关联时留空，不按名称猜填。',
            'refund_items'=>'显示本次成功退款时保存的项目或卡项名称。',
            'original_sale_date'=>'显示原销售单的业务日期；旧卡按原订单支付时间、无支付时间时按创建时间显示。',
            'refund_amount'=>'统计成功退款时登记的金额；转入余额独立记账，不重复计入，也不表示已通过支付渠道退钱。',
            'refund_remark'=>'显示退款时填写的原因；旧销售退款未填原因时显示原销售备注。',
        ];
        foreach($columns as &$column)$column['source_explanation']=$explanations[$column['key']];unset($column);
        return $this->result('院店退款台账',$columns,$rows,$input,[],['refund_amount_cents'=>$this->sumField($rows,'refund_amount_cents')]);
    }

    /**
     * Refund categories follow the refunded project's linked platform product, not a name or sale-time label.
     * Card refunds use the original sale components or a validated legacy cart line; missing links remain unclassified.
     */
    private function refundLedgerCurrentProductCategories(array &$rows): void
    {
        if ($rows === []) return;
        $tenants = array_values(array_unique(array_map('strval', array_column($rows, 'tenant_id'))));
        $stores = array_values(array_unique(array_map('intval', array_column($rows, 'store_id'))));
        $salesOwners = [];
        $links = [];
        $owners = [];
        $cardLines = [];
        $legacyCartOwners = [];
        foreach ($rows as $index => $row) {
            $type = (string)$row['source_type'];
            if ($type === 'sales') {
                $salesOwners[(string)$row['tenant_id'] . '|' . (int)$row['store_id'] . '|'
                    . (string)$row['operation_id']] = $index;
            } elseif ($type === 'document') {
                $itemType = (string)($row['document_item_type'] ?? '');
                $itemId = (string)($row['document_product_id'] ?? '');
                if (in_array($itemType, ['project', 'product'], true) && preg_match('/^[1-9][0-9]*$/D', $itemId)) {
                    $owners[] = $index;
                    $links[] = ['store_id' => (int)$row['store_id'], 'product_id' => (int)$itemId];
                } elseif ($itemType === 'card') {
                    $cardLines[(string)$row['document_order_line_id']][] = $index;
                }
            } elseif ($type === 'card') {
                if ((string)($row['card_order_line_id'] ?? '') !== '') {
                    $cardLines[(string)$row['card_order_line_id']][] = $index;
                    continue;
                }
                // Historical imported cards have no V3 purchase receipt. Their successful refund request names
                // the exact cart component; require the same holder, order, member and store before resolving it.
                $request = json_decode((string)($row['request_json'] ?? ''), true);
                $cartId = (string)($request['componentId'] ?? '');
                $holderId = (string)($request['holderId'] ?? '');
                if ((string)($request['mode'] ?? '') === 'card'
                    && preg_match('/^[1-9][0-9]*$/D', $cartId)
                    && preg_match('/^[1-9][0-9]*$/D', $holderId)
                    && (int)$holderId === (int)($row['card_holder_id'] ?? 0)) {
                    $legacyCartOwners[(int)$cartId][] = $index;
                }
            }
        }
        foreach (array_chunk(array_keys($legacyCartOwners), 300) as $chunk) {
            foreach (Db::name('store_order_cart_info')->whereIn('id', $chunk)
                ->field('id,uid,oid,relation_id,product_id')->select()->toArray() as $cart) {
                foreach ($legacyCartOwners[(int)$cart['id']] ?? [] as $owner) {
                    $refund = $rows[$owner];
                    if ((int)$cart['uid'] !== (int)$refund['member_id']
                        || (string)$cart['oid'] !== (string)$refund['order_id']
                        || (int)$cart['relation_id'] !== (int)$refund['store_id']
                        || (int)$cart['product_id'] <= 0) continue;
                    $owners[] = $owner;
                    $links[] = ['store_id' => (int)$refund['store_id'], 'product_id' => (int)$cart['product_id']];
                }
            }
        }
        $salesOperations = array_values(array_unique(array_map(static function (array $row): string {
            return (string)$row['operation_id'];
        }, array_filter($rows, static fn(array $row): bool => (string)$row['source_type'] === 'sales'))));
        foreach (array_chunk($salesOperations, 300) as $chunk) {
            foreach (Db::name('cashier_v3_order_lifecycle_refund_line')->alias('refund_line')
                ->join('cashier_v3_sales_order_line sale_line',
                    'sale_line.tenant_id=refund_line.tenant_id AND sale_line.store_id=refund_line.store_id AND sale_line.order_id=refund_line.source_order_id AND sale_line.order_line_id=refund_line.sales_order_line_id')
                ->whereIn('refund_line.tenant_id', $tenants)->whereIn('refund_line.store_id', $stores)
                ->whereIn('refund_line.operation_id', $chunk)->where('refund_line.status', 'succeeded')
                ->field('refund_line.tenant_id,refund_line.operation_id,refund_line.store_id,sale_line.order_line_id,sale_line.item_type,sale_line.item_id')
                ->order('refund_line.id asc')->select()->toArray() as $line) {
                $key = (string)$line['tenant_id'] . '|' . (int)$line['store_id'] . '|'
                    . (string)$line['operation_id'];
                $index = $salesOwners[$key] ?? null;
                if ($index === null) continue;
                $itemType = (string)$line['item_type'];
                $itemId = (string)$line['item_id'];
                if (in_array($itemType, ['project', 'product'], true)
                    && preg_match('/^[1-9][0-9]*$/D', $itemId)) {
                    $owners[] = $index;
                    $links[] = ['store_id' => (int)$line['store_id'], 'product_id' => (int)$itemId];
                }
            }
        }
        if ($cardLines !== []) {
            $factOwners = [];
            foreach (array_chunk(array_keys($cardLines), 300) as $chunk) {
                foreach (Db::name('cashier_v3_sale_fact')->whereIn('tenant_id', $tenants)
                    ->whereIn('store_id', $stores)->whereIn('source_line_id', $chunk)
                    ->where('source_type', 'card')->where('fact_direction', 'forward')->where('status', 'effective')
                    ->field('tenant_id,store_id,source_line_id,fact_id')->select()->toArray() as $sale) {
                    foreach ($cardLines[(string)$sale['source_line_id']] ?? [] as $owner) {
                        if ((string)$rows[$owner]['tenant_id'] === (string)$sale['tenant_id']
                            && (int)$rows[$owner]['store_id'] === (int)$sale['store_id']) {
                            $factOwners[(string)$sale['fact_id']][] = $owner;
                        }
                    }
                }
            }
            foreach (array_chunk(array_keys($factOwners), 300) as $chunk) {
                foreach (Db::name('cashier_v3_card_sale_category_allocation_fact')
                    ->whereIn('tenant_id', $tenants)->whereIn('store_id', $stores)
                    ->whereIn('sale_fact_id', $chunk)->where('status', 'effective')
                    ->field('tenant_id,store_id,sale_fact_id,component_product_id')->select()->toArray() as $component) {
                    if ((int)$component['component_product_id'] <= 0) continue;
                    foreach ($factOwners[(string)$component['sale_fact_id']] ?? [] as $owner) {
                        if ((string)$rows[$owner]['tenant_id'] !== (string)$component['tenant_id']
                            || (int)$rows[$owner]['store_id'] !== (int)$component['store_id']) continue;
                        $owners[] = $owner;
                        $links[] = ['store_id' => (int)$component['store_id'],
                            'product_id' => (int)$component['component_product_id']];
                    }
                }
            }
        }
        $resolved = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $categories = [];
        foreach ($owners as $key => $owner) {
            $path = trim((string)($resolved[$key]['path'] ?? ''));
            if ($path !== '') $categories[$owner][$path] = true;
        }
        foreach ($rows as $index => &$row) $row['category'] = implode('、', array_keys($categories[$index] ?? []));
        unset($row);
    }

    /**
     * 台账仍是一笔退款一行；本方法只补充有效人员，分类由商品 ID 的独立读取器处理。
     * 改派版本是完整人员集，空版本必须覆盖初始选择。
     */
    private function refundLedgerPersonnel(array &$rows): void
    {
        foreach (array_chunk(array_keys($rows), 400) as $indexes) {
            $ids = []; $tenants = []; $events = []; $salesIds = []; $importedIds = [];
            foreach ($indexes as $index) {
                $row = $rows[$index];
                $ids[] = (string)$row['operation_id'];
                $tenants[] = (string)$row['tenant_id'];
                if ((string)$row['source_type'] === 'sales') {
                    $salesIds[] = (string)$row['operation_id'];
                    $events[] = (string)$row['business_event_no'];
                } elseif ((string)$row['source_type'] === 'imported_card') {
                    $importedIds[] = (string)$row['operation_id'];
                }
            }
            $ids = array_values(array_unique($ids));
            $tenants = array_values(array_unique($tenants));
            $salespeople = []; $attributions = []; $importedSnapshots = [];
            if ($salesIds !== []) {
                foreach (Db::name('cashier_v3_performance_fact')
                    ->whereIn('tenant_id', $tenants)->whereIn('business_event_no', array_values(array_unique($events)))
                    ->where('performance_type', 'sales_performance_allocated')
                    ->where('fact_direction', 'reversal')->where('status', 'effective')
                    ->field('tenant_id,business_event_no,employee_id,employee_name_snapshot')
                    ->order('id asc')->select()->toArray() as $person) {
                    $name = trim((string)$person['employee_name_snapshot']);
                    if ($name !== '') $salespeople[(string)$person['tenant_id'] . '|' . (string)$person['business_event_no']][(int)$person['employee_id']] = $name;
                }
            }
            foreach (Db::name(CashierV3RefundAttributionServices::TABLE)
                ->whereIn('tenant_id', $tenants)->whereIn('operation_id', $ids)
                ->field('tenant_id,operation_id,role,employee_id,employee_name_snapshot')
                ->order('id asc')->select()->toArray() as $person) {
                $key = (string)$person['tenant_id'] . '|' . (string)$person['operation_id'];
                $attributions[$key][(string)$person['role']][(int)$person['employee_id']] = (string)$person['employee_name_snapshot'];
            }
            $versions = Db::name(CashierV3RefundAttributionServices::REVISION_TABLE)
                ->whereIn('tenant_id', $tenants)->whereIn('operation_id', $ids)
                ->field('tenant_id,operation_id,MAX(id) AS id')->group('tenant_id,operation_id')->select()->toArray();
            foreach ($versions as $version) {
                $attributions[(string)$version['tenant_id'] . '|' . (string)$version['operation_id']] = [];
            }
            $versionIds = array_map('intval', array_column($versions, 'id'));
            if ($versionIds !== []) {
                foreach (Db::name(CashierV3RefundAttributionServices::REVISION_PERSON_TABLE)->alias('person')
                    ->join(CashierV3RefundAttributionServices::REVISION_TABLE . ' revision',
                        'revision.tenant_id=person.tenant_id AND revision.operation_id=person.operation_id AND revision.revision_no=person.revision_no')
                    ->whereIn('revision.id', $versionIds)
                    ->field('person.tenant_id,person.operation_id,person.role,person.employee_id,person.employee_name_snapshot')
                    ->order('person.id asc')->select()->toArray() as $person) {
                    $key = (string)$person['tenant_id'] . '|' . (string)$person['operation_id'];
                    $attributions[$key][(string)$person['role']][(int)$person['employee_id']] = (string)$person['employee_name_snapshot'];
                }
            }
            if ($importedIds !== []) {
                $latest = Db::name('cashier_v3_refund_salesperson_revision')
                    ->whereIn('tenant_id', $tenants)->whereIn('operation_id', $importedIds)
                    ->field('tenant_id,operation_id,MAX(id) AS id')->group('tenant_id,operation_id')->select()->toArray();
                $latestIds = array_map('intval', array_column($latest, 'id'));
                if ($latestIds !== []) {
                    foreach (Db::name('cashier_v3_refund_salesperson_revision')->whereIn('id', $latestIds)
                        ->field('tenant_id,operation_id,new_snapshot_json')->select()->toArray() as $revision) {
                        $importedSnapshots[(string)$revision['tenant_id'] . '|' . (string)$revision['operation_id']] = (string)$revision['new_snapshot_json'];
                    }
                }
            }
            foreach ($indexes as $index) {
                $row = &$rows[$index];
                $key = (string)$row['tenant_id'] . '|' . (string)$row['operation_id'];
                $sourceType = (string)$row['source_type'];
                if ($sourceType === 'sales') {
                    $row['salesperson'] = implode('、', array_values($salespeople[(string)$row['tenant_id'] . '|' . (string)$row['business_event_no']] ?? []));
                } else {
                    $snapshot = $sourceType === 'imported_card' ? ($importedSnapshots[$key] ?? '[]')
                        : (string)($row['salespeople_snapshot_json'] ?? '[]');
                    $people = json_decode($snapshot, true);
                    $names = [];
                    foreach (is_array($people) ? $people : [] as $person) {
                        if (!is_array($person)) continue;
                        $name = trim((string)($person['name'] ?? ''));
                        if ($name !== '') $names[(string)($person['employeeId'] ?? $name)] = $name;
                    }
                    $row['salesperson'] = implode('、', array_values($names));
                }
                $roles = $attributions[$key] ?? [];
                // 分列仅改变展示，不合并角色，也不让旧的合并文本进入导出契约。
                $row['guide'] = implode('、', array_values($roles['guide'] ?? []));
                $row['sales_manager'] = implode('、', array_values($roles['sales_manager'] ?? []));
                unset($row);
            }
        }
    }

    private function primarySources():array{return Db::name('cashier_v3_business_source')->where('parent_id',0)->where('status',1)->order('sort','asc')->order('id','asc')->field('id,name,sort')->select()->toArray();}
    /**
     * 为各来源补充无现金服务行：先按业务日、来源和销售/纯权益服务单投影，
     * 再交给会员日聚合统一去重。销售单或本次服务快照只提供展示和稳定键，
     * 不推导现金金额，不写入业务事实；游客按服务结账请求独立识别。
     */
    private function marketServiceOnlyRows(array $stores, array $serviceVisits, array $cashRows, string $dimension): array
    {
        $sourcesById = [];
        foreach ($this->primarySources() as $source) {
            $sourcesById[(int)$source['id']] = $source;
        }
        $represented = [];
        foreach ($cashRows as $row) {
            $represented[(int)$row['store_id'] . '|' . (string)$row['order_id'] . '|' . (int)$row['business_source_primary_id'] . '|' . (string)$row['business_date']] = true;
        }
        $candidates = [];
        foreach ($serviceVisits as $visit) {
            $sourceId = (int)($visit['business_source_primary_id'] ?? 0);
            if (!isset($sourcesById[$sourceId]) || ($dimension !== '' && $sourceId !== (int)$dimension)) continue;
            $orderId = (string)($visit['matched_order_id'] ?? '');
            if ($orderId === '') continue;
            // 同一销售单可跨多天产生服务；日期是明细行的必要组成部分。
            $key = (int)$visit['store_id'] . '|' . $orderId . '|' . $sourceId . '|' . (string)$visit['service_business_date'];
            if (!isset($represented[$key]) && !isset($candidates[$key])) {
                $candidates[$key] = $visit;
            }
        }
        if (!$candidates) return [];

        $orderIds = array_values(array_unique(array_map(static function (array $visit): string {
            return (string)$visit['matched_order_id'];
        }, array_values($candidates))));
        $orders = Db::name('cashier_v3_sales_order')->whereIn('store_id', $stores)
            ->whereIn('order_id', $orderIds)->where('order_status', 'settled')
            ->where('order_direction', 'forward')
            ->field('tenant_id,store_id,order_id,order_no,checkout_request_id,organization_id,organization_path_snapshot,member_id,member_name_snapshot,store_name_snapshot,operator_name_snapshot,business_source_primary_id,business_source_primary_name_snapshot,business_source_label_snapshot,recorded_at')
            ->select()->toArray();
        $ordersByKey = [];
        foreach ($orders as $order) {
            $ordersByKey[(string)$order['tenant_id'] . '|' . (int)$order['store_id'] . '|' . (string)$order['order_id']] = $order;
        }
        $memberIds = array_values(array_unique(array_filter(array_map(static function (array $visit): int {
            return (int)($visit['member_id'] ?? 0);
        }, array_values($candidates)))));
        $phones = $memberIds ? Db::name('user')->whereIn('uid', $memberIds)->column('phone', 'uid') : [];
        $rows = [];
        foreach ($candidates as $visit) {
            $orderId = (string)$visit['matched_order_id'];
            $order = $ordersByKey[(string)$visit['tenant_id'] . '|' . (int)$visit['store_id'] . '|' . $orderId] ?? null;
            // 纯权益没有销售资金单：只读投影使用结账快照和服务单稳定键，绝不补造销售/收款事实。
            if (!$order && strpos($orderId, 'service:') === 0) {
                $order = [
                    'checkout_request_id' => $visit['checkout_request_id'],
                    'order_no' => $visit['service_record_no'],
                    'store_name_snapshot' => $visit['store_name_snapshot'],
                    'organization_id' => $visit['organization_id'],
                    'organization_path_snapshot' => $visit['organization_path_snapshot'],
                    'business_source_primary_id' => $visit['business_source_primary_id'],
                    'business_source_primary_name_snapshot' => $visit['service_source_name'],
                    'business_source_label_snapshot' => $visit['service_source_label'],
                ];
            }
            $sourceId = (int)$visit['business_source_primary_id'];
            if (!$order || ((int)$order['business_source_primary_id'] > 0
                && (int)$order['business_source_primary_id'] !== $sourceId)) continue;
            $memberId = (int)$visit['member_id'];
            $rows[] = [
                'store_id' => (int)$visit['store_id'], 'order_id' => $orderId,
                'checkout_request_id' => (string)$order['checkout_request_id'],
                'order_no_snapshot' => (string)$order['order_no'],
                'store_name_snapshot' => (string)$order['store_name_snapshot'],
                'organization_id' => (string)$order['organization_id'],
                'organization_path_snapshot' => (string)$order['organization_path_snapshot'],
                'member_id' => $memberId,
                'member_name_snapshot' => (string)$visit['member_name_snapshot'],
                'member_phone' => (string)($phones[$memberId] ?? ''),
                'business_source_primary_id' => $sourceId,
                'business_source_primary_name_snapshot' => (string)($order['business_source_primary_name_snapshot'] ?: $sourcesById[$sourceId]['name']),
                'business_source_label_snapshot' => (string)($order['business_source_label_snapshot'] ?: $sourcesById[$sourceId]['name']),
                'business_date' => (string)$visit['service_business_date'],
                'creator_name' => (string)$visit['operator_name_snapshot'],
                'amount_cents' => 0, 'recorded_at' => (int)$visit['recorded_at'],
            ];
        }
        return $rows;
    }
    /**
     * Read completed service facts once and resolve their source without making
     * sales_order an existence gate. New rows normally resolve by checkout
     * request and frozen checkout source (including entitlement-only receipts);
     * historical/card-service rows can resolve through the immutable
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
            // 当前结账冻结的来源优先于历史购卡来源，与订单中心纯权益单保持一致。
            ->leftJoin('cashier_v3_checkout_business_source_selection bs',
                "bs.tenant_id=sv.tenant_id AND bs.store_id=sv.store_id AND bs.checkout_request_id=sv.checkout_request_id AND bs.checkout_kind='sale'")
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
        // 门店市场人次只由完成、未作废且非体验的服务决定；“不算人数人次”
        // 是人员统计开关，不得在此过滤服务事实，否则门店人次和人员口径会串用。
        $this->normalDataScope()->excludeVoidedSalesOrderServices($query, 'sv');
        // A reservation ended directly has no checkout request row; its service
        // fact carries the booking attribution. Actual checkout attribution wins
        // when a service was completed through checkout instead.
        $rows = $query
            ->fieldRaw(
                'sv.tenant_id,sv.store_id,sv.service_fact_id,sv.checkout_request_id,'
                . 'MAX(sv.service_record_no) service_record_no,MAX(sv.organization_id) organization_id,'
                . 'MAX(sv.organization_path_snapshot) organization_path_snapshot,'
                . "COALESCE(NULLIF(MAX(bs.primary_source_name_snapshot),''),NULLIF(MAX(sv.business_source_primary_name_snapshot),''),'') service_source_name,"
                . "COALESCE(NULLIF(MAX(bs.source_label_snapshot),''),NULLIF(MAX(sv.business_source_label_snapshot),''),'') service_source_label,"
                . 'MAX(sv.store_name_snapshot) store_name_snapshot,'
                . 'MAX(sv.business_date) service_business_date,'
                . 'MAX(sv.member_id) member_id,MAX(sv.member_name_snapshot) member_name_snapshot,'
                . 'MAX(sv.operator_name_snapshot) operator_name_snapshot,'
                . 'MAX(sv.recorded_at) recorded_at,'
                // 体验标记在完成服务时冻结；按服务事实而非当前项目配置判定人次资格。
                . 'MAX(sv.is_experience) is_experience,'
                . 'COALESCE(NULLIF(wf.origin_order_id,0),0) origin_order_id,'
                // 无当前销售单但有本次冻结来源时，按结账请求表示服务单；禁止借历史购卡单合并不同游客。
                . "COALESCE(NULLIF(MAX(o1.order_id),''),CASE WHEN COALESCE(NULLIF(MAX(bs.primary_source_id),0),NULLIF(MAX(sv.business_source_primary_id),0),0)>0 AND sv.checkout_request_id<>'' THEN CONCAT('service:',sv.checkout_request_id) END,NULLIF(MAX(o3.order_id),''),NULLIF(MAX(o2.order_id),''),NULLIF(MAX(p1.order_id),''),NULLIF(MAX(p3.order_id),''),NULLIF(MAX(p2.order_id),''),'') matched_order_id,"
                . 'COALESCE(NULLIF(MAX(o1.business_source_primary_id),0),'
                . 'NULLIF(MAX(bs.primary_source_id),0),'
                . 'NULLIF(MAX(sv.business_source_primary_id),0),'
                . 'NULLIF(MAX(o3.business_source_primary_id),0),'
                . 'NULLIF(MAX(o2.business_source_primary_id),0),'
                . 'NULLIF(MAX(p1.business_source_primary_id),0),'
                . 'NULLIF(MAX(p3.business_source_primary_id),0),'
                . 'NULLIF(MAX(p2.business_source_primary_id),0),0) business_source_primary_id'
            )
            ->group('sv.tenant_id,sv.store_id,sv.service_fact_id,sv.checkout_request_id,wf.origin_order_id,cr.sales_order_id')
            ->select()
            ->toArray();
        return array_values(array_filter($rows, static function (array $row): bool {
            return (int)($row['business_source_primary_id'] ?? 0) > 0;
        }));
    }
    /** 市场两表只在人次入口排除体验服务；不改变明细存在性、金额、进店或有效人员。 */
    private function marketCountableServiceVisits(array $serviceVisits): array
    {
        return StoreCustomerVisitEligibility::filter($serviceVisits);
    }
    /**
     * 人次身份必须同时适配实名会员和游客：会员使用稳定会员 ID，保持同日去重；
     * 游客没有会员 ID，只能使用关联销售单作为一次服务的边界，避免同单多个项目重复计数。
     * 无法关联销售单的游客历史事实不计入，防止汇总出现无法下钻核对的孤立人次。
     */
    private function marketVisitIdentity(array $row): string
    {
        $memberId = (int)($row['member_id'] ?? 0);
        if ($memberId > 0) return 'member:' . $memberId;
        $orderId = trim((string)($row['matched_order_id'] ?? $row['order_id'] ?? ''));
        return $orderId === '' ? '' : 'guest-order:' . $orderId;
    }
    /** K 金额、明细和退款人次共用同一成功退款判定；别名只由服务内部传入。 */
    private function marketCashRefundCondition(string $alias): string
    {
        return "{$alias}.fact_direction='reversal' AND EXISTS (SELECT 1 FROM eb_cashier_v3_order_lifecycle_operation refund_operation WHERE refund_operation.tenant_id={$alias}.tenant_id AND refund_operation.command_idempotency_key={$alias}.command_idempotency_key AND refund_operation.operation_type='refund' AND refund_operation.status='succeeded')";
    }
    /**
     * K 人次只读既有金额路径认可的有效负向退款事实，继承门店/组织/个人权限。
     * 现金退款、登记退款及旧卡退款先按记录取身份，再跨方式按门店+业务日+会员去重。
     * 无会员身份沿用市场明细的原单边界，不把所有游客当作同一人；不按姓名猜身份。
     * 选择支付方式时只计该通道退款，登记/旧卡退款没有支付通道，不混入其下钻。
     */
    private function marketRefundVisitDays(array $stores, array $range, string $method = ''): array
    {
        $query = $this->cashFacts($stores, 'refund_visit')
            ->whereBetween('refund_visit.business_date', [$range['start'], $range['end']])
            ->where('refund_visit.status', 'effective')->where('refund_visit.amount_cents', '<', 0)
            ->whereRaw($this->marketCashRefundCondition('refund_visit'));
        if ($method !== '') $query->where('refund_visit.payment_method', $method);
        $fields = 'refund_visit.store_id,refund_visit.business_date,refund_visit.member_id,refund_visit.order_id';
        $records = $query->field($fields)->group($fields)->select()->toArray();
        if ($method === '') {
            $adjustments = $this->marketRefundAdjustmentFacts($stores, 'refund_visit')
                ->whereBetween('refund_visit.business_date', [$range['start'], $range['end']])
                ->field($fields)->group($fields)->select()->toArray();
            $records = array_merge($records, $adjustments);
        }
        $days = [];
        foreach ($records as $record) {
            if ($this->marketVisitIdentity($record) === '') continue;
            $days[$this->marketRefundVisitDayKey($record)] = $record;
        }
        return $days;
    }
    /** K 是固定来源，身份键只需门店、退款业务日与稳定会员/原单标识。 */
    private function marketRefundVisitDayKey(array $row): string
    {
        return (int)$row['store_id'] . '|' . (string)$row['business_date'] . '|' . $this->marketVisitIdentity($row);
    }
    /** K 汇总列与明细共用业务说明，明确其人次不是服务次数或达标人数。 */
    private function marketRefundVisitExplanation(): string
    {
        return 'K退款顾客的人次按有效退款记录统计：同一门店、同一业务日期、同一会员有退款即计 1，多笔或多种方式退款只计一次，不同日期分别累计。包括成功现金退款、登记退款和旧卡退款；已作废或未生效退款不计。不要求有服务记录，不设退款金额达标门槛，“体验”和“不算人数人次”不影响退款人次。无会员身份时按原单独立计数，不按空会员或姓名合并。';
    }
    /**
     * 有效人员只按业务日净记账收款达标：门店、日期、来源、会员为唯一粒度。
     * 查询/下钻/导出共用权限裁剪后的事实；退款沿原来源在其业务日抵减，
     * 作废沿 cashFacts 排除；历史导入欠款的补交只从达标金额剔除，
     * 不改金额列、K 来源展示或任何原始事实。
     * SQL 先聚合并排除低于最低门槛的会员日，避免加载逐笔事实或逐来源重复扫描。
     */
    private function marketEffectiveMemberKeys(array $stores, array $range, string $method = ''):array
    {
        $sources = [];
        foreach ($this->primarySources() as $source) $sources[(int)$source['id']] = $source;
        $query = $this->cashFacts($stores)
            ->whereBetween('report_payment.business_date', [$range['start'], $range['end']])
            ->where('report_payment.status', 'effective')->where('report_payment.member_id', '>', 0)
            // 导入欠款的补交仍是真实收款；只是不用于认定当日新增的有效人员。
            // 以原欠款/充值单的迁移标记识别，不能凭补交单号或会员姓名猜测。
            ->whereRaw("NOT (report_payment.source_document_type='debt_repayment' AND (LEFT(report_payment.natural_key,CHAR_LENGTH('historical_debt_repayment:payment:'))='historical_debt_repayment:payment:' OR EXISTS (SELECT 1 FROM eb_cashier_v3_debt_repayment market_repay JOIN eb_store_debt market_debt ON market_debt.id=market_repay.debt_id JOIN eb_store_order market_origin ON market_origin.id=market_debt.order_id WHERE market_repay.tenant_id=report_payment.tenant_id AND market_repay.repayment_id=report_payment.order_id AND market_origin.channel_type='migration'))) ")
            ->whereRaw("NOT (report_payment.source_document_type='recharge_debt_repayment' AND EXISTS (SELECT 1 FROM eb_cashier_v3_recharge_debt_repayment market_recharge_repay JOIN eb_user_recharge market_recharge_origin ON market_recharge_origin.id=market_recharge_repay.recharge_id WHERE market_recharge_repay.tenant_id=report_payment.tenant_id AND market_recharge_repay.repayment_id=report_payment.order_id AND (market_recharge_origin.channel_type='migration' OR market_recharge_origin.recharge_type='migration'))) ");
        if ($method !== '') $query->where('report_payment.payment_method', $method);
        $rows = $query->fieldRaw('report_payment.store_id,report_payment.business_date,report_payment.business_source_primary_id,report_payment.member_id,SUM(report_payment.amount_cents) amount_cents')
            ->group('report_payment.store_id,report_payment.business_date,report_payment.business_source_primary_id,report_payment.member_id')
            ->having('SUM(amount_cents)>=50000')->select()->toArray();
        $keys = [];
        foreach ($rows as $row) {
            $sourceId = (int)($row['business_source_primary_id'] ?? 0);
            if (!isset($sources[$sourceId])) continue;
            $source = $sources[$sourceId];
            $threshold = $this->sourcePrefix($source) === 'A' ? 100000 : 50000;
            if ((int)$row['amount_cents'] >= $threshold) $keys[$this->marketEffectiveMemberDayKey($row)] = $row;
        }
        return $keys;
    }
    /** 达标、逐行标记与合计使用同一会员日键，不合并跨门店、跨来源或跨日记录。 */
    private function marketEffectiveMemberDayKey(array $row):string
    {
        return (int)$row['store_id'] . '|' . (string)$row['business_date'] . '|'
            . (int)$row['business_source_primary_id'] . '|' . (int)$row['member_id'];
    }
    /**
     * 市场明细按门店、日期、会员 ID 和来源投影；不以姓名合并不同会员。
     * 收款仍从原订单事实累加，人工进店保留各原单据的独立记录及版本。
     */
    private function marketMemberDailyRows(array $orders):array
    {
        $days = [];
        foreach ($orders as $order) {
            $memberId = (int)($order['member_id'] ?? 0);
            $key = (int)$order['store_id'] . '|' . (string)$order['business_date'] . '|' . $memberId . '|' . (int)$order['business_source_primary_id'];
            // 没有会员 ID 的历史单不能按空值并成同一位顾客。
            if ($memberId <= 0) $key .= '|' . (string)$order['order_id'];
            if (!isset($days[$key])) {
                $days[$key] = $order;
                $days[$key]['_market_group_key'] = $key;
                $days[$key]['_market_orders'] = [];
                $days[$key]['amount_cents'] = 0;
                $days[$key]['walk_in'] = 0;
                $days[$key]['visits'] = 0;
                $days[$key]['effective_people'] = 0;
                $days[$key]['_creator_names'] = [];
            }
            $days[$key]['amount_cents'] += (int)$order['amount_cents'];
            // 聚合行不能继续把其中一张单的制单人/制单时间冒充整行来源。
            $days[$key]['recorded_at'] = max((int)($days[$key]['recorded_at'] ?? 0), (int)($order['recorded_at'] ?? 0));
            $creator = trim((string)($order['creator_name'] ?? ''));
            if ($creator !== '') $days[$key]['_creator_names'][$creator] = true;
            $days[$key]['visits'] = max((int)$days[$key]['visits'], (int)($order['visits'] ?? 0));
            $days[$key]['effective_people'] = max((int)$days[$key]['effective_people'], (int)($order['effective_people'] ?? 0));
            $orderId = (string)$order['order_id'];
            // 同一原单在同一天可能有多种支付方式，人工值只加一次。
            if (!isset($days[$key]['_market_orders'][$orderId])) {
                $days[$key]['walk_in'] += (int)($order['walk_in'] ?? 0);
            }
            $days[$key]['_market_orders'][$orderId] = [
                'order_id' => $orderId, 'order_no' => (string)($order['order_no_snapshot'] ?? ''),
                'walk_in' => (int)($order['walk_in'] ?? 0),
                'walk_in_version' => (int)($order['walk_in_version'] ?? 0),
            ];
        }
        foreach ($days as &$day) {
            $day['amount'] = $this->money((int)$day['amount_cents']);
            $day['_market_orders'] = array_values($day['_market_orders']);
            $day['creator_name'] = implode('、', array_keys($day['_creator_names']));
            $day['created_at'] = $this->dateTime((int)$day['recorded_at']);
            unset($day['_creator_names']);
            $memberId = (int)$day['member_id'];
            // 会员按“日+来源”合并；游客则必须保留原始订单边界，
            // 避免空会员 ID 把不同顾客的手工值合并或互相覆盖。
            $guestOrderId = $memberId <= 0 ? (string)($day['_market_orders'][0]['order_id'] ?? '') : '';
            $day['annotation_subject_key'] = $memberId > 0
                ? $this->marketMemberDayKey((int)$day['store_id'], (string)$day['business_date'], $memberId, (int)$day['business_source_primary_id'])
                : $this->marketGuestOrderKey((int)$day['store_id'], (string)$day['business_date'], (int)$day['business_source_primary_id'], $guestOrderId);
            $day['annotation_subject_type'] = $memberId > 0 ? 'market_member_day' : 'market_guest_order';
            $day['source_order_id'] = $guestOrderId;
        }
        unset($day);
        return array_values($days);
    }
    /** 同一门店、业务日期、会员和来源共同标识一条可编辑市场明细行。 */
    private function marketMemberDayKey(int $storeId, string $date, int $memberId, int $sourceId):string
    {
        return 'market-day-v1:' . $storeId . ':' . $date . ':' . $memberId . ':' . $sourceId;
    }
    /** 游客行以门店、业务日、来源和原始订单共同定位；哈希避免将不透明单号直接嵌入主键。 */
    private function marketGuestOrderKey(int $storeId, string $date, int $sourceId, string $orderId):string
    {
        return 'market-guest-v1:' . $storeId . ':' . $date . ':' . $sourceId . ':' . hash('sha256', $orderId);
    }
    /** 合计覆盖分页前的全部明细，按达标会员日计数，不能跨天或跨来源按会员再去重。 */
    private function marketDetailSummaryRow(array $rows):array
    {
        $walkIn = $visits = $amountCents = 0; $effectiveMembers = [];
        foreach ($rows as $row) {
            $walkIn += (int)($row['walk_in'] ?? 0);
            $visits += (int)($row['visits'] ?? 0);
            $amountCents += (int)($row['amount_cents'] ?? 0);
            if ((int)($row['effective_people'] ?? 0) === 1 && (int)($row['member_id'] ?? 0) > 0) $effectiveMembers[$this->marketEffectiveMemberDayKey($row)] = true;
        }
        return [
            'business_date' => '合计', 'store_name_snapshot' => '-', 'member_name_snapshot' => '-', 'member_phone' => '-',
            'dimension' => '-', 'walk_in' => $walkIn, 'visits' => $visits, 'effective_people' => count($effectiveMembers),
            'amount' => $this->money($amountCents), 'registered_date' => '-', 'reviewer' => '-', 'reviewed_at' => '-',
            'creator_name' => '-', 'created_at' => '-',
        ];
    }
    private function sourceIds(string $prefix):array{return $this->sourceIdsMany([$prefix]);}
    /** 新客两表排除全部一级 A 来源，包括已停用但仍存在历史快照的来源。 */
    private function newCustomerExcludedSourceIds():array
    {
        $ids=[];
        foreach(Db::name('cashier_v3_business_source')->where('parent_id',0)->field('id,name')->select()->toArray() as $source){
            if($this->sourcePrefix($source)==='A')$ids[]=(int)$source['id'];
        }
        return $ids;
    }
    /** 历史来源可能改名或删除：ID 与发生时名称任一指向 A，均不进入新客收款行。 */
    private function isExcludedNewCustomerSource(int $sourceId,string $label,array $excludedIds):bool
    {
        return in_array($sourceId,$excludedIds,true)||$this->sourcePrefix(['name'=>$label])==='A';
    }
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
        // 顾客到店、项目数量和护理审计均保留全部完成服务；人员客数资格只能由人员指标层应用。
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
        if($title==='市场业绩表')$columns=$this->marketPerformanceColumnExplanations($columns);
        if($title==='市场明细表')$columns=$this->marketDetailColumnExplanations($columns);
        if($title==='异业收客明细表')$columns=$this->crossIndustryDetailColumnExplanations($columns,$input);
        if($title==='异业收客汇总表')$columns=$this->crossIndustrySummaryColumnExplanations($columns);
        if(in_array($title,['新客明细表','新客汇总表'],true))$columns=$this->newCustomerColumnExplanations($columns);
        $this->appendDimensionFiltersToColumns($columns);
        $this->appendDimensionFiltersToRows($records);
        $visible=!empty($input['_internal_all'])?$records:array_slice($records,($page-1)*$limit,$limit);
        // 未付清订单入表改变两张新客表的资格和统计时间，不能复用旧口径版本。
        $metricVersion=$title==='新客汇总表'?'new-customer-first-sale-grouped-v6':($title==='新客明细表'?'new-customer-first-sale-v5':'store-operations-phase-two-v1');
        // 有效人员按日达标，K 人次按有效退款会员日；普通来源体验人次规则保持不变。
        $isMarketReport = in_array($title, ['市场业绩表', '市场明细表'], true);
        if ($isMarketReport) $metricVersion = 'market-imported-debt-effective-v6';
        // v7 只改变商品分类读取来源；退款金额、资格、人员及业务日不回写。
        if ($title === '院店退款台账') $metricVersion = 'store-refund-ledger-v7';
        // 护理槽位与首次到店日期原先误用了人员标记；相关报表单独升级口径，其他报表不变。
        if (in_array($title, ['地推拓客明细表', '地推拓客汇总表', '异业收客明细表', '异业收客汇总表'], true)) {
            $metricVersion = 'store-customer-service-events-v2';
        }
        // 纯权益 E 来源护理也独立成行；汇总纳入该会员，年度收款仍取原权威收款事实。
        if ($title === '地推拓客明细表') $metricVersion = 'field-acquisition-service-only-v5';
        if ($title === '地推拓客汇总表') $metricVersion = 'field-acquisition-summary-service-only-v3';
        // 本表展示本次收款业务日，不再展示首次护理日；其他到店报表仍沿用服务口径。
        if ($title === '异业收客明细表') $metricVersion = 'cross-industry-cash-business-date-v3';
        // 汇总名单新增本年 G 来源补交会员；月度/年度金额仍为这些会员在相应门店的全部有效收款。
        if ($title === '异业收客汇总表') $metricVersion = 'cross-industry-sale-repayment-members-v3';
        $result=['title'=>$title,'columns'=>$columns,'records'=>$visible,'total'=>count($records),'page'=>$page,'page_size'=>$limit,'totals'=>$totals,'drilldown_keys'=>['organization_id','store_id','dimension_code','payment_method_code','metric_code','start_date','end_date'],'table_layout'=>['fixed'=>true,'sticky_header'=>true,'sticky_summary'=>true,'result_scroll'=>true],'metric_version'=>$metricVersion,'service_customer_rule_version'=>null,'data_as_of'=>date('Y-m-d H:i:s'),'aggregation_status'=>'reconciled'];
        $summary=$this->summaryRow($title,$columns,$records);if($summary)$result['summary_row']=$summary;
        if($groups)$result['column_groups']=$groups;
        $metadata=$this->metadataForTitle($title,$totals);
        if (in_array($title, ['新客明细表', '新客汇总表'], true)) {
            $metadata['filter_schema'][] = [
                'key' => 'guide_id', 'label' => '导购', 'type' => 'select', 'required' => false,
                'placeholder' => '全部导购（可选）', 'options' => (array)($input['_guide_filter_options'] ?? []),
                'source_explanation' => '默认显示来源不是 A 且首次办理疗程卡的顾客，未付清和未填写导购的订单也计入；选择导购后，只显示该导购参与的订单。',
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
    /**
     * 只替换本表的列名说明，不改变其他报表或金额口径。普通入口与会员后续收款
     * 下钻的来源范围不同，说明必须跟随实际查询条件，避免把后续款误说成全是 G 来源。
     */
    private function crossIndustryDetailColumnExplanations(array $columns,array $input):array
    {
        $includeFollowupCash=(int)($input['include_followup_cash']??0)===1&&(int)($input['member_id']??0)>0;
        $explanations=[
            'source'=>'显示收款时选择的客户来源，补交按本次补交选择的来源显示。'
                .($includeFollowupCash?'当前正在查看指定异业顾客的后续收款，包含该顾客在查询期间的各类来源收款。':'本表只统计来源为“G异业收客”的收款。'),
            'receipt_business_date'=>'显示本笔收款的业务日期：正常消费按订单收款业务日期，补交按本次补交业务日期，退款按退款业务日期。查询周期也按此日期筛选，与是否做过护理无关。',
            'store_name'=>'办理本次收款或补交的门店，显示收款时记录的门店名称；只显示你有权查看的门店。',
            'document_no'=>'显示本笔收款对应的单据号：正常消费显示销售单号，欠款补交显示本次补交单号，不显示原欠款单号。',
            'member_name'=>'本次收款或补交对应的会员，显示收款时记录的会员姓名。',
            'phone'=>'显示该会员在会员资料中登记的当前手机号；未登记时显示“-”。',
            'full_payment'=>'查询期间实际收到的金额，包含购买付款、充值和欠款补交。补交按本次补交日期计入，只算本次收到的钱，不重复计算原订单总额；订单退款按退款日期扣减，已作废的订单和补交不计入。合计包含当前筛选范围内的全部记录。',
            'reward'=>'本次销售或补交单确认的异业收客奖励；补交不继承原销售单奖励。组合收款及同单跨日分行只计一次，未登记时为0；奖励不抵扣收款或欠款。',
            'remark'=>'销售单中填写的备注；补交记录及未填写备注的销售单显示“-”。',
        ];
        foreach($columns as &$column){
            $key=(string)($column['key']??'');
            if(isset($explanations[$key]))$column['source_explanation']=$explanations[$key];
        }
        unset($column);
        return$columns;
    }
    /** 明确汇总的入表条件与后续款范围；补交入表不意味着把原欠款总额再算一遍。 */
    private function crossIndustrySummaryColumnExplanations(array $columns):array
    {
        $explanations=[
            'member_name'=>'显示所选年份内有“G异业收客”正常销售或有效欠款补交的会员；同一门店同一会员只显示一行，作废记录不计。',
            'customer_acquired_at'=>'人工登记的收客时间；保存后刷新仍保留，未登记时显示“-”。',
            'partner_store_name'=>'人工登记的异业合作店名；保存后刷新仍保留，未登记时显示“-”。',
            'phone'=>'显示会员资料中登记的当前手机号，未登记时显示“-”。',
            'card_name'=>'显示本年 G 来源销售记录中的商品或卡项名称；仅通过补交入表、没有本年销售记录时显示“-”。',
            'remaining_service_count'=>'显示该会员在本门店当前有效卡项的剩余服务次数，已作废及已退款卡项不计。',
            'remaining_service_amount'=>'显示该会员在本门店当前有效卡项的剩余服务金额，已作废及已退款卡项不计。',
            'first_500'=>'显示该会员本年在本门店第一次单日收款净额达到500元的金额；包含正常收款和补交，不限当次来源。',
            'reached_2400'=>'显示该会员本年在本门店累计收款净额首次达到2400元时的累计金额，不限当次来源。',
            'first_visit_at'=>'显示该会员在本门店第一次完成非体验护理的日期；已作废服务不计，无记录显示“-”。',
            'annual_cash'=>'按所选年份汇总该会员在本门店的有效收款净额，包含正常收款和补交及后续其他来源收款；退款按退款业务日扣减，作废记录不计，不重复计原欠款总额。',
        ];
        foreach($columns as &$column){
            $key=(string)$column['key'];
            if(isset($explanations[$key]))$column['source_explanation']=$explanations[$key];
            elseif(preg_match('/^month_(\d+)$/D',$key,$match))$column['source_explanation']='汇总所选年份'.$match[1].'月该会员在本门店的有效收款净额，包含正常收款、补交和后续其他来源收款；按各笔业务日期归属月份，退款扣减，作废不计。';
        }
        unset($column);
        return$columns;
    }
    /**
     * 市场业绩的动态来源列必须说明真实计入口径。分组名称来自当前来源配置，
     * 但人次、有效人员和金额的解释固定由后端给出，页面不能按列名猜算法。
     * 普通来源沿用体验服务排除规则；K 人次取退款而不是服务，不受服务标记影响。
     * 最终说明集中在此及明细说明方法，避免前置列模板重复维护后又被覆盖。
     */
    private function marketPerformanceColumnExplanations(array $columns):array
    {
        foreach($columns as &$column){
            $key=(string)($column['key']??'');
            $label=trim((string)($column['label']??''));
            $source=trim((string)($column['group_label']??''));
            if($key==='division_name'){
                $column['source_explanation']='显示业务门店当前所属的分公司；门店未配置分公司时显示“未配置分公司”。';
                continue;
            }
            if($key==='store_name'){
                $column['source_explanation']='显示收款或服务实际归属的门店；只汇总当前账号有权查看的门店。';
                continue;
            }
            if(preg_match('/^channel_\d+_(walk_in|visits|effective|amount)$/',$key,$parts)){
                $source=$source!==''?$source:'该来源';
                $descriptions=[
                    'walk_in'=>'汇总有权限人员在市场明细表保存的进店数：会员按“门店＋业务日期＋会员＋来源”汇总，游客按原始订单独立记录；新行尚未保存时读取原单据已保存值，新旧值不会重复相加。仅 B 来源显示该列。',
                    'visits'=>'统计所选日期内归属“'.$source.'”、已完成且未作废的非体验服务人次。打了“体验”标签的服务不计门店人次；“不算人数人次”只影响人员统计，不影响本列。同一门店、同一天、同一来源还有其他符合条件的服务时，会员仍记 1 人次。游客每张可关联销售单记 1；同单多项目不重复，不要求当次有收款。体验标签只排除门店人次，不排除金额和按收款门槛计算的有效人员，也不表示整行明细隐藏。',
                    'effective'=>'统计所选日期内归属“'.$source.'”的每日达标会员数之和：同一门店、同一业务日期、同一来源、同一会员的净记账收款独立达到标准才计 1，A 来源每天至少 1,000 元，其他来源每天至少 500 元。同日多笔合并，不跨天凑门槛；同一会员多天达标可累计，游客不计。导入历史欠款的补交不参与达标金额，但仍显示在收款金额中。退款会抵减，已作废销售不计；成功现金退款在退款业务日抵减原来源的达标收款，不回改其他日期。“体验”和“不算人数人次”不影响本列，达到收款门槛仍计入。',
                    'amount'=>'合计所选日期内归属“'.$source.'”的有效记账收款净额。成功现金退款及登记退款在退款发生日计入 K退款顾客并冲减金额；作废销售冲减原来源；余额支付和旧卡录入不计。“体验”和“不算人数人次”不排除已经产生的有效记账收款。',
                ];
                $column['source_explanation']=$descriptions[$parts[1]];
                if ($parts[1] === 'visits' && $this->sourcePrefix(['name' => $source]) === 'K') {
                    $column['source_explanation'] = $this->marketRefundVisitExplanation();
                }
                continue;
            }
            if(strpos($key,'payment_')===0){
                $column['source_explanation']='合计所选日期内使用“'.$label.'”完成的有效记账收款净额；该通道发生的退款和作废反向收款按实际发生日冲减。登记退款没有支付通道，不计入本列。';
                continue;
            }
            if($key==='total_performance'){
                $column['source_explanation']='合计所选日期内全部有效记账收款方式的净额，再扣除已登记的退款业绩；作废按实际发生日冲减，退款转入余额、余额支付和旧卡录入不计。登记退款没有支付通道，因此本列可能小于各收款方式金额之和。';
            }
        }
        unset($column);
        return$columns;
    }
    /** 市场明细按日合并；K 按退款、其他来源按服务计人次，零人次不表示整行隐藏。 */
    private function marketDetailColumnExplanations(array $columns):array
    {
        $descriptions=[
            'company'=>'显示业务门店当前所属的分公司；门店未配置分公司时显示“未配置分公司”。',
            'business_date'=>'显示收款或服务归属的业务日期；同一门店、同一会员、同一天、同一来源的记录合并为一行。',
            'store_name_snapshot'=>'显示收款或服务实际归属的门店；只展示当前账号有权查看的门店。',
            'member_name_snapshot'=>'有会员身份时显示业务发生时记录的会员姓名；没有会员身份时显示“游客”，不同游客不会按姓名合并。',
            'member_phone'=>'显示会员资料中当前保存的手机号码；未填写则留空。',
            'dimension'=>'显示业务发生时保存的客户来源；纯权益服务优先读取本次结账保存的来源，历史服务没有该快照时读取关联购卡或销售记录，不按会员当前资料改写历史来源。',
            'walk_in'=>'显示有权限人员为本行保存的进店数：会员按“门店＋业务日期＋会员＋来源”合并，游客按原始订单独立显示和编辑；新行尚未保存时显示原单据已保存值。仅 B 来源汇总到市场业绩表的进店列。',
            'visits'=>$this->marketRefundVisitExplanation().' 其他来源有已完成且未作废的非体验服务才计门店人次。打了“体验”标签的服务不计普通来源门店人次；“不算人数人次”只影响人员统计，不影响本列。同一门店、同一天、同一来源还有其他符合条件的服务时，会员仍记 1 人次。游客每张可关联销售单记 1；同单多项目不重复，不要求当次有收款。体验标签只排除普通来源门店人次，不排除金额和按收款门槛计算的有效人员；明细行仍可能显示，人次为 0 不代表整行隐藏。',
            'effective_people'=>'同一门店、同一业务日期、同一来源、同一会员的净记账收款独立达标时显示 1，否则为 0：A 来源至少 1,000 元，其他来源至少 500 元；游客不计。同日多笔合并，不跨天凑门槛；同一会员多天达标可累计，合计为每日达标会员数之和。导入历史欠款的补交不参与达标金额，但仍显示在收款金额中。成功现金退款在退款业务日抵减原来源的达标收款，不回改其他日期；退款金额仍展示在 K 来源，已作废销售不计。“体验”和“不算人数人次”不影响本列，达到收款门槛仍计入。',
            'amount'=>'合计本行会员当天在该门店和来源下的有效记账收款净额；退款按退款发生日抵减，已作废销售不计。只有正常服务、没有当次收款时显示 0。“体验”和“不算人数人次”不排除已经产生的有效记账收款。',
            'registered_date'=>'显示本行收款或服务归属的业务日期；补录业务的系统录入时间可能晚于该日期。',
            'reviewer'=>'本表目前没有读取审核人，显示“—”；不能据此判断单据是否审核。',
            'reviewed_at'=>'本表目前没有读取审核时间，显示“—”；不能据此判断单据是否审核。',
            'creator_name'=>'显示本行所合并业务记录的制单人；涉及多人时用顿号分隔，未记录则显示“—”。',
            'created_at'=>'显示本行合并记录最后一次写入系统的时间；这是系统录入时间，不是业务归属日期。',
        ];
        foreach($columns as &$column){
            $key=(string)($column['key']??'');
            if(isset($descriptions[$key]))$column['source_explanation']=$descriptions[$key];
        }
        unset($column);
        return$columns;
    }
    /** 两张新客表的列名说明直接描述顾客能核对的单据和时间，不使用内部统计术语。 */
    private function newCustomerColumnExplanations(array $columns):array
    {
        $descriptions=[
            'company'=>'显示该订单门店现在所属的分公司；门店未配置分公司时显示“未配置分公司”。',
            'division_name'=>'显示首次办理疗程卡订单所属的分公司。',
            'store_name_snapshot'=>'显示首次办理疗程卡订单的下单门店。',
            'store_name'=>'显示首次办理疗程卡订单的下单门店。',
            'business_date'=>'显示顾客首次办理疗程卡的销售日期；未付清也计入新客，后来补清不改变这一天。',
            'customer'=>'显示首次办理疗程卡订单上的顾客姓名。',
            'system_name'=>'显示该顾客所属的业务系统。',
            'guide'=>'显示首次办理疗程卡订单中记录的导购；未填写则显示空白。',
            'salesperson'=>'显示首次办理疗程卡订单中记录的销售人；未填写则显示空白。',
            'sales_manager'=>'显示首次办理疗程卡订单中记录的销售经理；未填写则显示空白。',
            'source'=>'首次办理疗程卡的明细显示办卡订单来源；补交按收款时记录的来源统计。首次办卡或补交来源为 A 的数据均不进入本表。',
            'age'=>'当前订单没有可核实的年龄资料，因此暂不显示年龄。',
            'phone'=>'显示会员资料中当前保存的手机号码。',
            'care_project'=>'显示首次办理疗程卡订单中的商品或项目名称。',
            'craftsman'=>'显示该订单项目分配的护理手艺人；未分配则显示空白。',
            'experience_card_amount'=>'默认显示该订单明细的销售金额；有权限人员手动修改后显示保存的金额。',
            'care_duration'=>'显示有权限人员为该订单明细手动填写并保存的护理时长。',
            'labor_fee'=>'显示该订单项目分配给护理手艺人的手工费。',
            'guide_effective_count'=>'显示该订单记录的导购人数；没有导购时为 0。',
            'guide_performance_round'=>'显示该订单记录的导购轮次；没有导购时留空。',
            'full_payment'=>'显示查询日期内，这笔首次疗程卡订单未形成欠款的收款金额。',
            'deposit_payment'=>'显示查询日期内，这笔首次疗程卡订单形成欠款时先收的金额。',
            'cleared_payment'=>'显示查询日期内，为这笔首次疗程卡订单补交并结清的金额。',
            'remark'=>'显示首次办理疗程卡订单上填写的备注。',
            'annual_cash'=>'合计所选日期内，这笔首次疗程卡订单的全款、定金和补交收款；未付清不影响已收款计入，退款按实际发生日期扣减。',
        ];
        foreach($columns as &$column){
            $key=(string)($column['key']??'');
            if(isset($descriptions[$key])){$column['source_explanation']=$descriptions[$key];continue;}
            if(preg_match('/^month_(\d+)_(count|full|deposit|cleared)$/',$key,$parts)){
                $month=(int)$parts[1];$kind=$parts[2];
                $column['source_explanation']=$kind==='count'
                    ?$month.'月来源不是 A 且首次办理疗程卡的顾客人数；未付清也计入，每位顾客只算一次，后续补清不会重复增加人数。'
                    :$month.'月所选日期内，这些首次疗程卡订单的'.['full'=>'全款','deposit'=>'定金','cleared'=>'补交结清'][$kind].'收款金额。';
            }
        }unset($column);
        return $columns;
    }
    private function fixedColumns(string $title,array $columns):array
    {
        $maps=[
            '市场业绩表'=>['division_name'=>130,'store_name'=>140],
            '市场明细表'=>['business_date'=>112,'store_name_snapshot'=>112,'member_name_snapshot'=>82,'member_phone'=>116,'dimension'=>108],
            '会员进店分析表'=>['member_name'=>100,'phone'=>116],
            '会员进店年度汇总表'=>['row_label'=>82,'year'=>76],
            '地推拓客明细表'=>['division_name'=>120,'store_name_snapshot'=>120,'source'=>110,'member_name_snapshot'=>90,'phone'=>116],
            '地推拓客汇总表'=>['card_sale_date'=>106,'member_name_snapshot'=>90,'phone'=>116,'source'=>110],
            '异业收客明细表'=>['source'=>110,'receipt_business_date'=>106,'store_name'=>120,'document_no'=>160,'member_name'=>90,'phone'=>116],
            '异业收客汇总表'=>['customer_acquired_at'=>106,'partner_store_name'=>120,'member_name'=>90,'phone'=>116],
            '新客明细表'=>['division_name'=>120,'store_name_snapshot'=>120,'business_date'=>106,'customer'=>90,'guide'=>110,'salesperson'=>110,'sales_manager'=>110,'phone'=>116],
            '新客汇总表'=>['system_name'=>90,'division_name'=>120,'store_name'=>120,'member'=>90,'guide'=>110,'salesperson'=>110,'sales_manager'=>110,'source'=>110],
            '销售人生美大单统计表'=>['division_name'=>120,'store_name'=>120,'business_date'=>106,'salesperson'=>110],
            '院店退款台账'=>['store_name'=>120,'customer'=>90,'refund_date'=>130],
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
        if($title==='地推拓客明细表')return in_array($key,['visit_over_one_hour','fourth_and_above'],true)?'count':(in_array($key,['labor_fee','cash_none','cash_1','cash_2','cash_3'],true)?'money':'');
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
    /** 异业汇总按门店+会员读取当前权益，避免同一会员跨店时重复展示全部门店余额。 */
    private function activeCardEntitlements(array $stores,array $members):array
    {
        if(!$members)return[];
        $holderQuery=Db::name('user_card_holder')->alias('h')->join('store_order o','o.id=h.oid')->whereIn('h.uid',$members)->whereIn('h.store_id',$stores)->where('h.is_del',0)->where('h.write_surplus_times','>',0)->where('o.paid',1)->where('o.is_del',0)->where('o.is_system_del',0)->where('o.refund_status',0);
        if($this->hasColumn('store_order','terminal_action'))$holderQuery->where('o.terminal_action',0);
        if($this->hasColumn('store_order','order_id')){
            // The current entitlement projection is still the balance authority;
            // a succeeded V3 sale void must never contribute its old remainder.
            $holderQuery->whereNotExists(function($void):void{
                $void->name('cashier_v3_order_lifecycle_operation')->alias('remaining_card_sale_void')
                    ->where('remaining_card_sale_void.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                    ->whereRaw('remaining_card_sale_void.source_order_id=o.order_id')
                    ->where('remaining_card_sale_void.source_type','sales')
                    ->where('remaining_card_sale_void.operation_type','void')
                    ->where('remaining_card_sale_void.status','succeeded');
            });
        }
        $holders=$holderQuery->field('h.store_id,h.uid,h.oid,h.write_surplus_times')->select()->toArray();
        if(!$holders)return[];
        $oids=array_values(array_unique(array_column($holders,'oid')));$owner=[];foreach($holders as $row)$owner[(int)$row['oid']]=(int)$row['store_id'].'|'.(int)$row['uid'];
        $out=[];foreach($holders as $row){$id=(int)$row['store_id'].'|'.(int)$row['uid'];$out[$id]['remaining_count']=(int)($out[$id]['remaining_count']??0)+(int)$row['write_surplus_times'];$out[$id]['remaining_amount_cents']=(int)($out[$id]['remaining_amount_cents']??0);}
        $carts=Db::name('store_order_cart_info')->whereIn('oid',$oids)->where('write_surplus_times','>',0)->where('cart_type',2)->where('product_type',6)->field('oid,pay_price,write_times,write_surplus_times')->select()->toArray();
        foreach($carts as $cart){$oid=(int)$cart['oid'];$id=$owner[$oid]??'';$times=(int)$cart['write_times'];$remaining=(int)$cart['write_surplus_times'];if($id===''||$times<=0)continue;$out[$id]['remaining_amount_cents']=(int)($out[$id]['remaining_amount_cents']??0)+intdiv($this->decimalCents($cart['pay_price'])*$remaining,$times);}
        return$out;
    }
    private function phones(array $ids):array{if(!$ids)return[];$out=[];foreach(Db::name('user')->whereIn('uid',$ids)->field('uid,phone')->select()->toArray()as$r)$out[(int)$r['uid']]=(string)$r['phone'];return$out;}
    /** 保留完成服务与冻结体验标记；异业汇总可按门店+会员隔离，其他调用方维持原会员投影。 */
    private function servicesByMember(array $stores, array $members, bool $byStore = false): array
    {
        if (!$members) return [];
        $rows = $this->completedUnvoidedServiceFacts(
            $this->participantCheckout($this->scope(Db::name('cashier_v3_entitlement_service_fact')->alias('member_service'), $stores, 'member_service'), 'member_service.checkout_request_id'),
            'member_service', 'member_service_void'
        )->whereIn('member_service.member_id', $members)
            ->field('member_service.service_fact_id,member_service.tenant_id,member_service.store_id,member_service.member_id,member_service.checkout_request_id,member_service.source_line_id,member_service.business_source_primary_id,member_service.business_source_primary_name_snapshot,member_service.business_date,member_service.project_name_snapshot,member_service.labor_fee_amount_cents,member_service.craftsmen_snapshot_json,member_service.occurred_at,member_service.is_experience')
            ->order('member_service.business_date', 'asc')->order('member_service.occurred_at', 'asc')->select()->toArray();
        $out = [];
        foreach ($rows as $row) {
            $craftsmen = [];
            foreach ((array)json_decode((string)$row['craftsmen_snapshot_json'], true) as $craftsman) {
                $craftsmen[] = (string)($craftsman['employeeName'] ?? $craftsman['employee_name'] ?? $craftsman['name'] ?? '');
            }
            $row['craftsman'] = implode('、', array_unique(array_filter($craftsmen)));
            $key = $byStore ? (int)$row['store_id'].'|'.(int)$row['member_id'] : (int)$row['member_id'];
            $out[$key][] = $row;
        }
        return $out;
    }
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
    /**
     * 汇总及下钻共用非会员维度键。门店 ID 阻止同名门店跨店混算，历史名称快照
     * 保留报表当期展示维度；会员、订单与收款单均不能再拆分汇总行。
     */
    private function newCustomerSummaryGroupKey(array $row,string $guide,string $salesperson,string $manager,int $sourceId,string $source):string
    {
        return hash('sha256',json_encode([(int)($row['store_id']??0),(string)($row['company_dimension_id']??''),(string)($row['division_name']??$row['organization_name_snapshot']??''),(string)($row['store_name']??$row['store_name_snapshot']??''),$guide,$salesperson,$manager,$sourceId,$source],JSON_UNESCAPED_UNICODE));
    }
    /**
     * 将同一门店和人员维度的首次销售事实合入新客汇总；系统名称只取当前实例配置，
     * 不参与事实分组，也不能把某个客户的品牌写进其他客户的报表。
     */
    private function newCustomerSummaryAddRow(array &$records,array $row,array $guides,array $salespeople,array $managers,int $amount,string $suffix,int $count,string $source,int $sourceId,array $input):void
    {
        // 缺少人员归属不影响首次疗程卡顾客身份；人员仅用于主动筛选。
        if(!$this->newCustomerPersonnelMatches($guides,$salespeople,$managers,$input))return;
        if((int)($input['member_id']??0)>0&&(int)$row['member_id']!==(int)$input['member_id'])return;
        if((int)($input['source_id']??0)>0&&$sourceId!==(int)$input['source_id'])return;
        if((int)($input['source_id']??0)===0&&trim((string)($input['source_label']??''))!==''&&$source!==trim((string)$input['source_label']))return;
        // 与明细共用组织维度解析，再按分公司 ID 分组并应用已选择的维度筛选。
        $this->projectOrganization($row,(string)($row['organization_id']??''),(string)($row['organization_path_snapshot']??''),(string)($row['business_date']??''));
        if(!$this->selectedDimensionMatches($row,$input))return;
        $guide=$this->newCustomerNames($guides,'guide_employee_id','guide_employee_name_snapshot');$salesperson=$this->newCustomerNames($salespeople,'employee_id','employee_name_snapshot');$manager=$this->newCustomerNames($managers,'sales_manager_employee_id','sales_manager_name_snapshot');
        $key=$this->newCustomerSummaryGroupKey($row,$guide,$salesperson,$manager,$sourceId,$source);
        if(!isset($records[$key])){
            $systemName=trim((string)sys_config('site_name',''));
            $records[$key]=['system_name'=>$systemName!==''?$systemName:'当前系统','division_name'=>(string)$row['division_name'],'company_dimension_id'=>(string)$row['company_dimension_id'],'city_manager_dimension_id'=>(string)$row['city_manager_dimension_id'],'store_name'=>(string)$row['store_name'],'store_id'=>(int)$row['store_id'],'summary_group_key'=>$key,'guide'=>$guide,'salesperson'=>$salesperson,'sales_manager'=>$manager,'source'=>$source,'source_id'=>$sourceId,'source_label'=>$source,'annual_cash_cents'=>0];
        }
        $month=(int)substr((string)$row['business_date'],5,2);$records[$key]['month_'.$month.'_count']=(int)($records[$key]['month_'.$month.'_count']??0)+$count;$records[$key]['month_'.$month.'_'.$suffix.'_cents']=(int)($records[$key]['month_'.$month.'_'.$suffix.'_cents']??0)+$amount;$records[$key]['annual_cash_cents']+=(int)$amount;
    }
    /** 汇总的月度、全年与成交门槛均复用同一份门店会员日净收款，不跨店凑金额。 */
    private function cashByStoreMemberDay(array $stores,array $members,string $start,string $end):array{if(!$members)return[];$rows=$this->cashFacts($stores)->whereIn('member_id',$members)->whereBetween('business_date',[$start,$end])->where('status','effective')->fieldRaw('store_id,member_id,business_date,SUM(amount_cents) amount_cents')->group('store_id,member_id,business_date')->order('business_date','asc')->select()->toArray();$out=[];foreach($rows as$r)$out[(int)$r['store_id'].'|'.(int)$r['member_id']][]=$r;return$out;}
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
        $this->normalDataScope()->excludeVoidedRechargeOrderFacts($query, $column . 'tenant_id', $column . 'order_id');
        $this->normalDataScope()->excludeVoidedSupplementPaymentFacts($query, $column . 'tenant_id', $column . 'store_id', $column . 'order_id');
        return $this->participantOrder($query,$column.'order_id');
    }
    /**
     * 市场报表只补入已生效的登记退款事实，不读取独立的赠金入账事实。
     * 个人视图须经退款调整单反查原单参与关系；旧卡没有可验证的 V3
     * 参与关系时保持不可见，不用退款操作员身份扩大个人数据权限。
     */
    private function marketRefundAdjustmentFacts(array $stores, string $alias)
    {
        $query = Db::name('cashier_v3_payment_fact')->alias($alias);
        $this->scope($query, $stores, $alias);
        $this->applyOrganizationFilters($query, $alias, $this->activeInput, $this->activeRange);
        $query->where($alias . '.status', 'effective')
            ->where($alias . '.amount_cents', '<', 0)
            // 作废退款仍可从审计追溯，市场 K、下钻及院店退款台账均不再统计。
            ->whereRaw("({$alias}.source_document_type <> 'manual_refund' OR NOT EXISTS (SELECT 1 FROM eb_cashier_v3_refund_adjustment voided_refund WHERE voided_refund.tenant_id={$alias}.tenant_id AND voided_refund.operation_id={$alias}.order_id AND voided_refund.status='voided'))")
            ->where(function ($kind) use ($alias): void {
                $kind->where(function ($manual) use ($alias): void {
                    $manual->where($alias . '.fact_type', 'manual_refund_adjustment')
                        ->where($alias . '.source_document_type', 'manual_refund');
                })->whereOr(function ($oldCard) use ($alias): void {
                    $oldCard->where($alias . '.fact_type', 'payment_refunded')
                        ->where($alias . '.source_document_type', 'imported_card_refund');
                });
            });
        if ($this->participantEmployeeId > 0) {
            $query->join('cashier_v3_refund_adjustment market_refund_subject',
                'market_refund_subject.tenant_id=' . $alias . '.tenant_id AND market_refund_subject.operation_id=' . $alias . '.order_id')
                ->where('market_refund_subject.status', 'effective');
            $this->participantOrder($query, 'market_refund_subject.source_order_id');
        }
        return $query;
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
    /** 导购选项来自首笔疗程卡订单，避免跨月补清时选不到原单导购。 */
    private function newCustomerGuideFilterOptions(array $stores,array $orderIds):array
    {
        if(!$orderIds)return[];
        $rows=$this->scope(Db::name('cashier_v3_customer_guide_round_fact'),$stores)
            ->whereIn('order_id',$orderIds)->where('status','effective')
            ->fieldRaw('guide_employee_id value,MAX(guide_employee_name_snapshot) label')
            ->group('guide_employee_id')->order('label','asc')->select()->toArray();
        return array_values(array_filter(array_map(static function(array $row):array {
            return ['value'=>(int)($row['value']??0),'label'=>(string)($row['label']??'')];
        },$rows),static fn(array $row):bool=>(int)$row['value']>0&&trim($row['label'])!==''));
    }
}
