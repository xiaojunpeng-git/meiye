<?php
namespace app\services\query\metric;

use app\services\query\UnifiedQueryProvider;
use app\services\query\UnifiedQueryExportTaskServices;

/** Uses the shared snapshot, never re-queries current facts or accepts AI arithmetic. */
final class MetricReadViewExportProvider implements UnifiedQueryProvider
{
    const PAGE_CODE='metric_read_view_export';
    private $views; private $resolve;
    /** Resolver MUST derive principal/query from trusted server ownership, not browser fields. */
    public function __construct(MetricReadViewServices $views, callable $resolveReadContext)
    { $this->views=$views; $this->resolve=$resolveReadContext; }
    public function pageCode(): string {return self::PAGE_CODE;}
    public function query(array $context,array $payload): array {throw new \RuntimeException('METRIC_EXPORT_QUERY_ONLY');}
    public function executeFrozenPlan(array $context,array $plan,string $exportScope,array $fieldKeys): array
    {
        if ($exportScope!=='query' || ($plan['page_code']??null)!==self::PAGE_CODE) throw new \RuntimeException('EXPORT_AI_QUERY_SCOPE_REQUIRED');
        foreach (['filters','top_filters','keyword_filters','quick_filters','groups','summaries','custom_definitions'] as $field) {
            if (($plan[$field]??null)!==[]) throw new \RuntimeException('METRIC_EXPORT_ORIGINAL_RESULT_REQUIRED');
        }
        if (\app\services\query\UnifiedQueryJson::encode($plan['sorts']??null)!==\app\services\query\UnifiedQueryJson::encode([['field_key'=>'row_id','direction'=>'asc']])) throw new \RuntimeException('METRIC_EXPORT_ORIGINAL_RESULT_REQUIRED');
        if (!is_array($plan['domain_scope']??null) || count($plan['domain_scope'])!==2 || ($plan['domain_scope']['data_scope']??null)!=='normal' || ($plan['domain_scope']['business_status']??null)!=='' || ($plan['filter_relation']??null)!=='all'
            || ($plan['stable_row_key']??null)!=='row_id') throw new \RuntimeException('METRIC_EXPORT_ORIGINAL_RESULT_REQUIRED');
        $expected=array_keys(MetricReadViewExportRegistrar::fields());
        if ($fieldKeys!==$expected || ($plan['visible_fields']??null)!==$expected) throw new \RuntimeException('METRIC_EXPORT_FIELD_BINDING_INVALID');
        $refs=$context['scope_dimensions']['metric_read_ref']??null;
        if (!is_array($refs) || count($refs)!==1 || !is_string($refs[0]??null) || !preg_match('/^mrv_[a-f0-9]{48}$/D',$refs[0])) throw new \RuntimeException('METRIC_EXPORT_READ_REF_INVALID');
        $resolved=call_user_func($this->resolve,$context,$refs[0]);
        if (!is_array($resolved) || !is_array($resolved['principal']??null) || !is_array($resolved['query']??null)) throw new \RuntimeException('METRIC_EXPORT_CONTEXT_INVALID');
        $view=$this->views->replay($resolved['principal'],$resolved['query'],$refs[0]);
        if (($view['ai_query_ready']??false)!==true || ($view['result_status']??null)!=='complete') throw new \RuntimeException('METRIC_EXPORT_SOURCE_NOT_READY');
        $rows=self::project($view);
        UnifiedQueryExportTaskServices::assertCellBudget(count($fieldKeys),count($rows),false);
        return ['exportRows'=>$rows,'summaries'=>[], 'total'=>count($rows),'read_consistency_ref'=>$refs[0], 'result_hash'=>$view['result_hash']];
    }
    public static function project(array $view): array
    {
        $rows=[]; $capabilities=MetricReadViewServices::metricCapabilities();
        foreach ($view['results'] as $result) {
            $code=$result['metric_code']??null;
            // Keep export eligibility explicit; names come from the same registered
            // read contract as the cards, never from model/result display text.
            $storageUnit = $result['storage_unit'] ?? null;
            if (!isset($capabilities[$code]) || !in_array($storageUnit, ['fen', 'count'], true)
                || ($capabilities[$code]['storage_unit'] ?? null) !== $storageUnit
                || ($capabilities[$code]['ai_query_ready']??false)!==true) throw new \RuntimeException('METRIC_EXPORT_METRIC_NOT_READY');
            if (!in_array($result['period']??null,['current','comparison'],true)) throw new \RuntimeException('METRIC_EXPORT_PERIOD_INVALID');
            $period=$result['period']; $range=$period==='current'?['start'=>$view['query']['start_date'],'end'=>$view['query']['end_date']]:$view['query']['compare_range'];
            if (!is_array($range)) throw new \RuntimeException('METRIC_EXPORT_PERIOD_INVALID');
            $businessFilters=$view['query']['business_filters']??[];
            if (!is_array($businessFilters)) throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
            $objectKind=$businessFilters['object_kind']??'store';
            $person=$objectKind==='person';
            $dimensionContract=null;
            foreach ((array)($capabilities[$code]['analysis_dimension_contracts']??[]) as $contract) {
                if (is_array($contract)&&($contract['object_kind']??null)===$objectKind&&($contract['filter_keys']??null)===[]) $dimensionContract=$contract;
            }
            $valid=$person ? (($capabilities[$code]['filter_grain']??null)==='person')
                : ($dimensionContract!==null || (($capabilities[$code]['filter_grain']??null)==='store' && $businessFilters===[]));
            if (!$valid) throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
            $label=$person?($view['personnel_selection_label']??''): '当前授权范围';
            if (!is_string($label)||$label==='') throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
            $base=['metric_name'=>$capabilities[$code]['name'],'period_name'=>$period==='current'?'本期':'对比期','start_date'=>$range['start'],'end_date'=>$range['end'],
                'store_name'=>$label.($person?'（按当前任职筛选）':''),'ranking_direction'=>'','business_date'=>'',
                'unit'=>$storageUnit === 'fen' ? '元' : '个'];
            if (isset($result['amount_cents']) || isset($result['count'])) self::append($rows,$base,$storageUnit==='fen' ? ($result['amount_cents']??null) : ($result['count']??null),$storageUnit);
            elseif ($view['query']['query_shape']==='trend') foreach ($result['rows'] as $point) self::append($rows,array_replace($base,['business_date'=>$point['business_date']]),$point['amount_cents'],$storageUnit);
            elseif ($view['query']['query_shape']==='ranking') foreach ($result['rows'] as $direction=>$points) foreach ($points as $point) {
                if (!in_array($direction,['top','bottom'],true)) throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
                // Views are retained locally for 24 hours.  A member ranking
                // created before the generic dimension-row shape used
                // `member_name`; continue to export that already verified
                // result instead of treating a harmless schema evolution as a
                // reason to lose its Excel file.
                $name=$person?($point['employee_name']??($point['member_name']??null))
                    :($dimensionContract!==null?($point['entity_name']??($point['member_name']??null)):($point['store_name']??null));
                if (!is_string($name) || $name==='') throw new \RuntimeException('METRIC_EXPORT_STORE_NAME_INVALID');
                self::append($rows,array_replace($base,['store_name'=>$name.(($person||$dimensionContract!==null)?'；范围：'.$base['store_name']:''),'ranking_direction'=>$direction==='top'?'前列':'后列']),$point['amount_cents'],$storageUnit);
            } else throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
        }
        return $rows;
    }
    private static function append(array &$rows,array $base,$value,string $storageUnit): void
    {
        if (!is_int($value)) {
            throw new \RuntimeException($storageUnit === 'fen' ? 'METRIC_EXPORT_AMOUNT_INVALID' : 'METRIC_EXPORT_COUNT_INVALID');
        }
        if ($storageUnit === 'fen') {
            $digits=str_pad(ltrim((string)$value,'-'),3,'0',STR_PAD_LEFT);
            $display=($value<0?'-':'').substr($digits,0,-2).'.'.substr($digits,-2);
        } elseif ($storageUnit === 'count') {
            $display=(string)$value;
        } else throw new \RuntimeException('METRIC_EXPORT_METRIC_NOT_READY');
        $rows[]=array_merge(['row_id'=>(string)(count($rows)+1)],$base,['metric_value'=>$display]);
    }
}
