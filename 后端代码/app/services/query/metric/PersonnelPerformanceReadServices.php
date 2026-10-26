<?php
namespace app\services\query\metric;

/** Shared personnel-grain reader. Uses the same allocated facts and normal-data guard
 * as personnel reports. Caller supplies current report authority and a read transaction.
 * Selection pairs intentionally retain store + person, not a union of employee IDs.
 */
final class PersonnelPerformanceReadServices
{
    public static function capabilities(): array
    {
        $out=[];
        foreach (MetricDefinitionRegistry::capabilities() as $code=>$item) if ($item['filter_grain']==='person') $out[$code]=$item;
        return $out;
    }
    private $query; private $normalScope;
    public function __construct(callable $queryFactory,callable $normalScope)
    { $this->query=$queryFactory; $this->normalScope=$normalScope; }

    public function totals(string $tenant,array $stores,array $range,string $metric,array $pairs): array
    {
        $contract=MetricDefinitionRegistry::get($metric);
        if (($contract['reader_strategy']??null)!=='personnel_fact_sum' || $tenant==='' || !$stores || !$pairs || count($pairs)>10000) $this->fail('METRIC_PERSONNEL_SCOPE_INVALID');
        foreach ($stores as $id) if (!is_int($id)||$id<1) $this->fail('METRIC_PERSONNEL_SCOPE_INVALID');
        if (count($range)!==2) $this->fail('METRIC_SOURCE_RANGE_INVALID');
        foreach (['start','end'] as $key) {
            $value=$range[$key]??null;
            $date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if (!$date || $date->format('Y-m-d')!==$value) $this->fail('METRIC_SOURCE_RANGE_INVALID');
        }
        if ($range['start']>$range['end']) $this->fail('METRIC_SOURCE_RANGE_INVALID');
        $unique=[];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair)!==2 || !is_int($pair['store_id']??null) || !in_array($pair['store_id'],$stores,true)
                || !is_int($pair['employee_id']??null) || $pair['employee_id']<1) $this->fail('METRIC_PERSONNEL_SCOPE_INVALID');
            $unique[$pair['store_id'].':'.$pair['employee_id']]=$pair;
        }
        $query=call_user_func($this->query,'cashier_v3_performance_fact')->alias('p')
            ->where('p.tenant_id',$tenant)->whereIn('p.store_id',$stores)->where('p.status','effective')
            ->where('p.performance_type',$contract['source']['filters']['performance_type'])->whereBetween('p.business_date',[$range['start'],$range['end']]);
        call_user_func($this->normalScope,$query,'p.tenant_id','p.order_id');
        // Preserve exact store+employee membership without emitting one OR
        // branch per person.  The condition-set population can legitimately
        // span more than 1,000 active personnel; grouping by store keeps the
        // SQL bounded by the authorized store count and never widens a person
        // into another store where they are not currently in the population.
        $byStore=[];
        foreach ($unique as $pair) $byStore[$pair['store_id']][$pair['employee_id']]=true;
        $query->where(function($scope)use($byStore){
            foreach ($byStore as $storeId=>$employees) $scope->whereOr(function($both)use($storeId,$employees){
                $both->where('p.store_id',(int)$storeId)->whereIn('p.employee_id',array_map('intval',array_keys($employees)));
            });
        });
        // No source-row limit: reversals and allocations all contribute before grouping.
        // The amount expression is owned by the registered metric contract.
        $rows=$query->fieldRaw('p.employee_id,p.business_date,SUM(' . (string)$contract['source']['amount'] . ') AS metric_value,COUNT(*) AS fact_count')
            ->group('p.employee_id,p.business_date')->order('p.employee_id','asc')->order('p.business_date','asc')->limit(10001)->select()->toArray();
        if (count($rows)>10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
        $allowed=array_column($unique,'employee_id');$out=[];$seen=[];
        foreach ($rows as $row) {
            $id=$this->integer($row['employee_id']??null);$amount=$this->integer($row['metric_value']??null);$count=$this->integer($row['fact_count']??null);
            $day=$row['business_date']??null;$date=is_string($day)?\DateTimeImmutable::createFromFormat('!Y-m-d',$day):false;
            if (!in_array($id,$allowed,true) || !$date || $date->format('Y-m-d')!==$day || $day<$range['start'] || $day>$range['end'] || $count<1 || isset($seen[$id.':'.$day])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $seen[$id.':'.$day]=true;$out[]=['employee_id'=>$id,'business_date'=>$day,'metric_value'=>$amount,'amount_cents'=>$amount,'fact_count'=>$count];
        }
        return $out;
    }
    private function integer($value): int
    {
        if (is_int($value)) return $value;
        if (!is_string($value)||!preg_match('/^-?(0|[1-9][0-9]*)$/D',$value)||(string)(int)$value!==$value) $this->fail('METRIC_SOURCE_RESULT_INVALID');
        return (int)$value;
    }
    private function fail(string $code): void {throw new MetricQueryContractException($code,'当前人员查询条件或数据结果不合法。');}
}
