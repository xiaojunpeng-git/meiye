<?php

namespace app\services\query\metric;

use app\services\report\StoreReportNormalDataScopeServices;
use think\facade\Db;

/** Shared scalar read implementation extracted from the existing group report.
 * This is NOT an authorization issuer, an AI readiness switch or a replayable snapshot.
 * Its caller must supply a server-authorized store scope and the same DB read view.
 */
final class GroupPerformanceMetricReadServices
{
    private $queryFactory;
    private $normalScope;

    public function __construct(?callable $queryFactory = null, ?callable $normalScope = null)
    {
        $this->queryFactory = $queryFactory ?: static function (string $table) { return Db::name($table); };
        $this->normalScope = $normalScope ?: static function ($query, string $tenantField, string $orderField): void {
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, $tenantField, $orderField);
        };
    }

    public function cashTotals(string $tenantId, array $stores, array $range): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $query = $this->cashQuery($tenantId, $stores, $range);
        $row = $query->fieldRaw(
            'COALESCE(SUM(CASE WHEN p.amount_cents > 0 THEN p.amount_cents ELSE 0 END),0) gross_cents,'
            . 'COALESCE(SUM(CASE WHEN p.amount_cents < 0 THEN p.amount_cents ELSE 0 END),0) refund_cents'
        )->find() ?: [];
        $recharge=$this->rechargeCashQuery($tenantId,$stores,$range)->fieldRaw(
            'COALESCE(SUM(CASE WHEN p.amount_cents > 0 THEN p.amount_cents ELSE 0 END),0) gross_cents,'
            .'COALESCE(SUM(CASE WHEN p.amount_cents < 0 THEN p.amount_cents ELSE 0 END),0) refund_cents'
        )->find() ?: [];
        return ['gross_cents' => $this->add($this->cents($row['gross_cents'] ?? 0),$this->cents($recharge['gross_cents']??0)),
            'refund_cents' => $this->add($this->cents($row['refund_cents'] ?? 0),$this->cents($recharge['refund_cents']??0))];
    }

    public function personnelTotals(string $tenantId,array $stores,array $range,string $metric,array $pairs): array
    {
        $this->assertScope($tenantId,$stores,$range);
        return (new PersonnelPerformanceReadServices($this->queryFactory,$this->normalScope))->totals($tenantId,$stores,$range,$metric,$pairs);
    }

    /** Mutually exclusive from sale allocation facts: recharge has no sale lines. */
    private function rechargeCashQuery(string $tenantId,array $stores,array $range)
    {
        $this->assertScope($tenantId,$stores,$range);
        $query=call_user_func($this->queryFactory,'cashier_v3_payment_fact')->alias('p')
            ->where('p.tenant_id',$tenantId)->whereIn('p.store_id',$stores)->where('p.status','effective')
            ->where('p.fact_type','payment_collected')->whereIn('p.source_document_type',['recharge','recharge_debt_repayment'])
            ->whereIn('p.payment_method',\app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1::paymentMethods())
            ->whereBetween('p.business_date',[$range['start'],$range['end']]);
        $query->whereNotExists(function($op){
            $op->name('cashier_v3_order_lifecycle_operation')->alias('recharge_void')
                ->whereRaw('recharge_void.tenant_id=p.tenant_id')
                ->whereRaw("p.source_document_type='recharge' AND LEFT(p.order_id,4)='RCH:' AND recharge_void.source_order_id=SUBSTRING(p.order_id,5)")
                ->where('recharge_void.source_type','recharge')->where('recharge_void.operation_type','void')->where('recharge_void.status','succeeded');
        });
        $query->where(function($kind){
            $kind->where('p.source_document_type','recharge')->whereOr(function($supplement){
                $supplement->where('p.source_document_type','recharge_debt_repayment')->whereExists(function($repayment){
                    $repayment->name('cashier_v3_recharge_debt_repayment')->alias('cash_repayment')
                        ->whereRaw('cash_repayment.tenant_id=p.tenant_id AND cash_repayment.repayment_id=p.order_id AND cash_repayment.store_id=p.store_id AND cash_repayment.member_id=p.member_id')
                        ->where('cash_repayment.status','succeeded')->whereNotExists(function($op){
                            $op->name('cashier_v3_order_lifecycle_operation')->alias('parent_recharge_void')
                                ->whereRaw('parent_recharge_void.tenant_id=cash_repayment.tenant_id AND parent_recharge_void.source_order_id=CAST(cash_repayment.recharge_id AS CHAR)')
                                ->where('parent_recharge_void.source_type','recharge')->where('parent_recharge_void.operation_type','void')->where('parent_recharge_void.status','succeeded');
                        });
                })->whereNotExists(function($op){
                    $op->name('cashier_v3_order_center_void_operation')->alias('supplement_void')
                        ->whereRaw('supplement_void.tenant_id=p.tenant_id AND supplement_void.source_id=p.order_id')
                        ->where('supplement_void.source_kind','recharge_supplement')->where('supplement_void.status','succeeded');
                });
            });
        });
        return $query;
    }

    /** Report details share the exact same recharge eligibility as summary/trend/ranking. */
    public function rechargeCashRows(string $tenantId,array $stores,array $range): array
    {
        return $this->rechargeCashQuery($tenantId,$stores,$range)->fieldRaw(
            "CONCAT('recharge-payment:',p.fact_id) id,p.fact_id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,p.amount_cents,p.organization_id,p.organization_path_snapshot,p.store_name_snapshot store_name,p.business_source_primary_id,p.business_source_label_snapshot source_label,0 item_id,CASE WHEN p.source_document_type='recharge' THEN '充值' ELSE '充值欠款补交' END item_name,'recharge' product_type_snapshot,0 category_id,'' category_path"
        )->select()->toArray();
    }

    /** Current authorized store labels, read on the same connection as the metric snapshot. */
    public function storeNames(array $stores): array
    {
        foreach ($stores as $id) if (!is_int($id) || $id <= 0) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        if (!$stores || count($stores) > 10000) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        $names = call_user_func($this->queryFactory, 'system_store')->whereIn('id', $stores)->column('name', 'id');
        $result = [];
        foreach ($stores as $id) {
            $name = $names[$id] ?? null;
            if (!is_string($name) || $name === '') $this->fail('METRIC_STORE_LABEL_UNAVAILABLE');
            $result[$id] = $name;
        }
        return $result;
    }

    private function cashQuery(string $tenantId, array $stores, array $range)
    {
        $query = call_user_func($this->queryFactory, 'cashier_v3_payment_sale_allocation_fact')->alias('p')
            ->leftJoin('cashier_v3_payment_sale_allocation_fact original', 'original.tenant_id=p.tenant_id AND original.allocation_fact_id=p.reversal_of')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.fact_id=COALESCE(original.sale_fact_id,p.sale_fact_id)')
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])->where('p.status', 'effective');
        call_user_func($this->normalScope, $query, 'p.tenant_id', 'p.order_id');
        return $query;
    }

    /** Retains the existing report's two performance fact types; this does not expose either to AI. */
    public function performanceTotal(string $tenantId, array $stores, array $range, string $type): int
    {
        $this->assertScope($tenantId, $stores, $range);
        if (!in_array($type, ['consumption_performance_recorded', 'actual_performance_recorded'], true)) $this->fail('METRIC_SOURCE_TYPE_INVALID');
        $query = $this->performanceQuery($tenantId, $stores, $range, $type);
        $row = $query->fieldRaw('COALESCE(SUM(p.amount_cents),0) amount_cents')->find() ?: [];
        return $this->cents($row['amount_cents'] ?? 0);
    }

    private function performanceQuery(string $tenantId, array $stores, array $range, string $type)
    {
        $query = call_user_func($this->queryFactory, 'cashier_v3_performance_fact')->alias('p')
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('p.performance_type', $type);
        call_user_func($this->normalScope, $query, 'p.tenant_id', 'p.order_id');
        if ($type === 'consumption_performance_recorded') {
            $query->whereExists(function ($service) {
                $service->name('cashier_v3_entitlement_service_fact')->whereRaw(
                    "tenant_id=p.tenant_id AND checkout_request_id=p.checkout_request_id AND source_line_id=p.source_line_id AND service_status='completed'"
                );
            });
        }
        return $query;
    }

    /** Bounded GROUP BY output; never applies a fact-row limit before SUM. */
    public function dailyStoreTotals(string $tenantId, array $stores, array $range, string $metric): array
    {
        $this->assertScope($tenantId, $stores, $range);
        if ($metric === 'cash_performance') {
            $query = $this->cashQuery($tenantId, $stores, $range);
            $value = 'COALESCE(SUM(CASE WHEN p.amount_cents > 0 THEN p.amount_cents ELSE 0 END),0)';
        } elseif ($metric === 'consume_amount') {
            $query = $this->performanceQuery($tenantId, $stores, $range, 'consumption_performance_recorded');
            $value = 'COALESCE(SUM(p.amount_cents),0)';
        } else { $this->fail('METRIC_SOURCE_TYPE_INVALID'); }
        $rows = $query->fieldRaw('p.store_id,p.business_date,' . $value . ' amount_cents')
            ->group('p.store_id,p.business_date')->order('p.store_id', 'asc')->order('p.business_date', 'asc')
            ->limit(10001)->select()->toArray();
        if ($metric==='cash_performance') {
            $extra=$this->rechargeCashQuery($tenantId,$stores,$range)
                ->fieldRaw('p.store_id,p.business_date,COALESCE(SUM(CASE WHEN p.amount_cents > 0 THEN p.amount_cents ELSE 0 END),0) amount_cents')
                ->group('p.store_id,p.business_date')->order('p.store_id','asc')->order('p.business_date','asc')->limit(10001)->select()->toArray();
            foreach ([$rows,$extra] as $branch) {
                if (count($branch)>10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
                $branchKeys=[];
                foreach ($branch as $point) {
                    $id=$this->cents($point['store_id']??null);
                    $day=$point['business_date']??null;
                    if (!in_array($id,$stores,true) || !is_string($day) || $day<$range['start'] || $day>$range['end']) $this->fail('METRIC_SOURCE_RESULT_INVALID');
                    $this->assertScope($tenantId,[$id],['start'=>$day,'end'=>$day]);
                    $key=$id.':'.$day;
                    if (isset($branchKeys[$key])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
                    $branchKeys[$key]=true;
                }
            }
            $merged=[];
            foreach(array_merge($rows,$extra) as $point){
                $key=$point['store_id'].':'.$point['business_date'];
                if(isset($merged[$key])) $merged[$key]['amount_cents']=$this->add($this->cents($merged[$key]['amount_cents']),$this->cents($point['amount_cents']));
                else $merged[$key]=$point;
            }
            $rows=array_values($merged);
        }
        if (count($rows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
        $out = []; $seen = [];
        foreach ($rows as $row) {
            $id = $this->cents($row['store_id'] ?? null);
            $day = $row['business_date'] ?? null;
            if (!in_array($id, $stores, true) || !is_string($day) || $day < $range['start'] || $day > $range['end']) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $this->assertScope($tenantId, [$id], ['start' => $day, 'end' => $day]);
            $key = $id . ':' . $day;
            if (isset($seen[$key])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $seen[$key] = true;
            $out[] = ['store_id' => $id, 'business_date' => $day, 'amount_cents' => $this->cents($row['amount_cents'] ?? null)];
        }
        return $out;
    }

    private function assertScope(string $tenantId, array $stores, array $range): void
    {
        if ($tenantId === '' || strlen($tenantId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $tenantId)
            || $stores === [] || count($stores) > 10000 || array_keys($stores) !== range(0, count($stores) - 1)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        foreach ($stores as $store) if (!is_int($store) || $store <= 0) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        if (count(array_unique($stores)) !== count($stores)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        if (count($range) !== 2 || !isset($range['start'], $range['end'])) $this->fail('METRIC_SOURCE_RANGE_INVALID');
        foreach (['start', 'end'] as $key) {
            if (!is_string($range[$key]) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $range[$key])) $this->fail('METRIC_SOURCE_RANGE_INVALID');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $range[$key], new \DateTimeZone('Asia/Shanghai'));
            if (!$date || $date->format('Y-m-d') !== $range[$key]) $this->fail('METRIC_SOURCE_RANGE_INVALID');
        }
        if ($range['start'] > $range['end']) $this->fail('METRIC_SOURCE_RANGE_INVALID');
    }

    private function cents($value): int
    {
        if (is_int($value)) return $value;
        if (!is_string($value) || !preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) || (string)(int)$value !== $value) $this->fail('METRIC_SOURCE_AMOUNT_INVALID');
        return (int)$value;
    }
    private function add(int $left,int $right): int
    {
        $sum=$left+$right;
        if(!is_int($sum)) $this->fail('METRIC_SOURCE_AMOUNT_INVALID');
        return $sum;
    }

    private function fail(string $code): void { throw new MetricQueryContractException($code, '当前指标查询条件或来源结果不合法。'); }
}
