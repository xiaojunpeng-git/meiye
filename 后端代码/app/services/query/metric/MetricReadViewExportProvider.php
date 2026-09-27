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
        $view=self::withMemberRights($view,$resolved['member_rights_export']??null);
        if (($view['ai_query_ready']??false)!==true || ($view['result_status']??null)!=='complete') throw new \RuntimeException('METRIC_EXPORT_SOURCE_NOT_READY');
        $rows=self::project($view);
        UnifiedQueryExportTaskServices::assertCellBudget(count($fieldKeys),count($rows),false);
        return ['exportRows'=>$rows,'summaries'=>[], 'total'=>count($rows),'read_consistency_ref'=>$refs[0], 'result_hash'=>$view['result_hash']];
    }
    public static function project(array $view): array
    {
        if (isset($view['member_rights_export'])) {
            return \app\services\ai\presentation\AiMemberRightsExportProjection::validate($view['member_rights_export'])['rows'];
        }
        $rows=[]; $capabilities=MetricReadViewServices::metricCapabilities();
        foreach ($view['results'] as $result) {
            $code=$result['metric_code']??null;
            if (in_array($view['query']['query_shape']??null,['condition_count','condition_list'],true)) {
                self::appendConditionResult($rows,$view,$result,$capabilities);
                continue;
            }
            // Keep export eligibility explicit; names come from the same registered
            // read contract as the cards, never from model/result display text.
            $storageUnit = $result['storage_unit'] ?? null;
            if (!isset($capabilities[$code]) || !in_array($storageUnit, ['fen', 'count', 'project_count_micro', 'customer_tenth'], true)
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
                $keys=$contract['filter_keys']??null;
                if (is_array($contract)&&($contract['object_kind']??null)===$objectKind
                    && ($keys===[] || ($view['query']['query_shape']??null)==='breakdown'&&$objectKind==='person'&&$keys===['selection_ref'])) $dimensionContract=$contract;
            }
            $valid=$person ? (($capabilities[$code]['filter_grain']??null)==='person')
                : ($dimensionContract!==null || (($capabilities[$code]['filter_grain']??null)==='store'
                    && ($businessFilters===[] || (($view['query']['query_shape']??null)==='breakdown'&&$businessFilters===['object_kind'=>'store']))));
            if (!$valid) throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
            $label=$person&&($view['query']['query_shape']??null)!=='breakdown'?($view['personnel_selection_label']??''): '当前授权范围';
            if (!is_string($label)||$label==='') throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
            $tooltip=(new \app\services\metric\MetricDictionaryServices())->getTooltip($code);
            $displayUnit=$tooltip['display_unit']??null;
            if (!is_string($displayUnit)||$displayUnit==='') $displayUnit=$storageUnit === 'fen' ? '元' : ($storageUnit === 'project_count_micro' ? '项' : ($storageUnit === 'customer_tenth' ? '人次' : '个'));
            // The executed selection label already states whether this is a
            // current role, an explicit position or a period fact cohort.
            // Export must not invent a current-employment restriction that
            // was absent from the signed screen query.
            $base=['metric_name'=>$capabilities[$code]['name'],'period_name'=>$period==='current'?'本期':'对比期','start_date'=>$range['start'],'end_date'=>$range['end'],
                'store_name'=>$label,'ranking_direction'=>'','business_date'=>'',
                'unit'=>$displayUnit];
            if (isset($result['amount_cents']) || isset($result['count'])) self::append($rows,$base,in_array($storageUnit,['fen','customer_tenth'],true) ? ($result['amount_cents']??null) : ($result['count']??null),$storageUnit);
            elseif ($view['query']['query_shape']==='breakdown') foreach ($result['rows'] as $point) {
                $name=$point['entity_name']??null;$value=$point['amount_cents']??null;
                if (!is_string($name)||$name===''||!is_int($value)) throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
                self::append($rows,array_replace($base,['store_name'=>$name.'；范围：'.$base['store_name']]),$value,$storageUnit);
            }
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
                $scopedName=$name.(($person||$dimensionContract!==null)?'；范围：'.$base['store_name']:'');
                self::append($rows,array_replace($base,['store_name'=>$scopedName,'ranking_direction'=>$direction==='top'?'前列':'后列']),$point['amount_cents'],$storageUnit);
                // Export the same immutable supplementary values as the
                // screen, retaining source precision rather than rounded text.
                $entityId=$point['entity_id']??$point['employee_id']??$point['store_id']??null;
                foreach ((array)($result['ranking_presentation_metrics']??[]) as $supplement) {
                    $extraCode=$supplement['metric_code']??null;$extra=$capabilities[$extraCode]??null;
                    if (!is_array($extra) || empty($extra['ai_query_ready']) || ($supplement['storage_unit']??null)!==$extra['storage_unit']) throw new \RuntimeException('METRIC_EXPORT_METRIC_NOT_READY');
                    foreach ($supplement['values'] as $value) if ($value['entity_id']===$entityId) {
                        self::append($rows,array_replace($base,['metric_name'=>$extra['name'],'store_name'=>$scopedName,
                            'ranking_direction'=>$direction==='top'?'前列':'后列','unit'=>self::displayUnit($extraCode,$extra['storage_unit'])]),
                            $value['metric_value'],$extra['storage_unit']);
                    }
                }
            } else throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
        }
        return $rows;
    }
    /** Only the trusted server resolver may attach an immutable asset snapshot.
     * The composite hash prevents a metric-list file from passing verification
     * for an asset answer that happens to share the same conversation view.
     */
    public static function withMemberRights(array $view,$snapshot): array
    {
        if ($snapshot===null) return $view;
        $snapshot=\app\services\ai\presentation\AiMemberRightsExportProjection::validate($snapshot);
        $view['member_rights_export']=$snapshot;
        $view['result_hash']=hash('sha256',$view['result_hash'].':'.$snapshot['hash']);
        return $view;
    }
    private static function appendConditionResult(array &$rows,array $view,array $result,array $capabilities): void
    {
        $shape=$view['query']['query_shape']??null;$set=$view['query']['condition_set']??null;
        $subject=$set['subject']??null;$filters=$view['query']['business_filters']??null;
        $expected=$subject==='person'?['object_kind'=>'person','selection_ref'=>'cohort:active_personnel']:['object_kind'=>$subject];
        if (!is_string($subject)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$subject)||$filters!==$expected||($result['object_kind']??null)!==$subject
            ||($result['storage_unit']??null)!=='count'||($result['condition_set']??null)!==$set
            ||!is_int($result['count']??null)||$result['count']<0||($result['period']??null)!=='current') {
            throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
        }
        $range=['start'=>$view['query']['start_date'],'end'=>$view['query']['end_date']];
        $objectLabel=['person'=>'人员','member'=>'客户','store'=>'门店','order'=>'销售订单',
            'sale_line'=>'销售明细','card'=>'卡项','project'=>'项目','product'=>'产品'][$subject]
            ??MetricDefinitionRegistry::overviewObjectLabel($subject);
        if (!is_string($objectLabel)||$objectLabel==='') throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
        if ($shape==='condition_count') {
            self::append($rows,['metric_name'=>'符合条件的'.$objectLabel.'数量','period_name'=>'本期','start_date'=>$range['start'],'end_date'=>$range['end'],
                'store_name'=>'当前授权范围','ranking_direction'=>'','business_date'=>'','unit'=>$subject==='store'?'家':($subject==='order'?'笔':($subject==='sale_line'?'条':($subject==='person'||$subject==='member'?'人':'个')))],$result['count'],'count');
            return;
        }
        if (!is_array($result['rows']??null)) throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
        foreach ($result['rows'] as $object) {
            $keys=['person'=>['employee_id','employee_name'],'member'=>['member_id','member_name'],'store'=>['store_id','store_name']];
            [$idKey,$nameKey]=$keys[$subject]??['entity_id','entity_name'];
            if (!is_int($object[$idKey]??null)||!is_string($object[$nameKey]??null)||$object[$nameKey]===''||!is_array($object['metrics']??null)) {
                throw new \RuntimeException('METRIC_EXPORT_RESULT_INVALID');
            }
            foreach ($set['conditions']??[] as $condition) {
                $metric=$condition['metric_code']??null;$contract=$capabilities[$metric]??null;$value=$object['metrics'][$metric]??null;
                if (!is_array($contract)||empty($contract['ai_query_ready'])||!is_int($value)) throw new \RuntimeException('METRIC_EXPORT_METRIC_NOT_READY');
                $unit=self::displayUnit($metric,(string)($contract['storage_unit']??''));
                self::append($rows,['metric_name'=>$contract['name'],'period_name'=>'本期','start_date'=>$range['start'],'end_date'=>$range['end'],
                    'store_name'=>$object[$nameKey].'；范围：当前授权范围','ranking_direction'=>'','business_date'=>'','unit'=>$unit],
                    $value,(string)$contract['storage_unit']);
            }
        }
    }
    private static function displayUnit(string $metricCode,string $storageUnit): string
    {
        $tooltip=(new \app\services\metric\MetricDictionaryServices())->getTooltip($metricCode);
        $unit=$tooltip['display_unit']??null;
        if (is_string($unit)&&$unit!=='') return $unit;
        return $storageUnit==='fen'?'元':($storageUnit==='project_count_micro'?'项':($storageUnit==='customer_tenth'?'人次':'个'));
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
        } elseif ($storageUnit === 'project_count_micro') {
            $negative=$value<0;$digits=str_pad(ltrim((string)$value,'-'),7,'0',STR_PAD_LEFT);
            $whole=ltrim(substr($digits,0,-6),'0');$whole=$whole===''?'0':$whole;
            $fraction=rtrim(substr($digits,-6),'0');
            $display=($negative?'-':'').$whole.($fraction===''?'':'.'.$fraction);
        } elseif ($storageUnit === 'customer_tenth') {
            $negative=$value<0;$digits=str_pad(ltrim((string)$value,'-'),2,'0',STR_PAD_LEFT);
            $whole=ltrim(substr($digits,0,-1),'0');$whole=$whole===''?'0':$whole;
            $fraction=substr($digits,-1);
            $display=($negative?'-':'').$whole.($fraction==='0'?'':'.'.$fraction);
        } else throw new \RuntimeException('METRIC_EXPORT_METRIC_NOT_READY');
        $rows[]=array_merge(['row_id'=>(string)(count($rows)+1)],$base,['metric_value'=>$display]);
    }
}
