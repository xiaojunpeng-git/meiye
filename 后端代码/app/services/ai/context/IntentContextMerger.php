<?php
namespace app\services\ai\context;

/**
 * Sole server entry for a model delta plus a verified prior query. It merges
 * data, never customer prose, and therefore cannot introduce a new meaning.
 */
final class IntentContextMerger
{
    /**
     * Closed rankings replace their metric, dimension and ranking explicitly.
     * Only a common verified period and store scope can survive that change.
     * Concrete selections or incompatible sources remain on the normal
     * contextual understanding path instead of silently widening the query.
     */
    public static function rankingConstraints(array $queries,bool $inheritPeriod,array $metricReadiness=[]): ?array
    {
        $constraints=null;
        foreach ($queries as $query) {
            if (!is_array($query) || !is_array($query['store_ids']??null)
                || !is_array($query['business_filters']??null)
                || !empty($query['condition_set']) || !empty($query['aggregate_condition'])) return null;
            $filters=$query['business_filters'];
            // A registry-owned default cohort describes who contributes to
            // this metric, not a customer-selected person/project. It changes
            // with the metric; concrete selections must still be understood.
            $metric=$metricReadiness[$query['metric_codes'][0]??'']??[];
            if (count($query['metric_codes']??[])===1 && isset($filters['selection_ref'])
                && $filters['selection_ref']===($metric['analysis_default_selection_ref']??null)
                && ($filters['object_kind']??null)===($metric['filter_grain']??null)) unset($filters['selection_ref']);
            if (array_diff(array_keys($filters),['object_kind'])!==[]) return null;
            $candidate=['store_ids'=>$query['store_ids']];
            sort($candidate['store_ids']);
            if ($inheritPeriod) {
                if (!empty($query['compare_range']) || !is_string($query['start_date']??null)
                    || !is_string($query['end_date']??null)) return null;
                $candidate['start_date']=$query['start_date'];$candidate['end_date']=$query['end_date'];
            }
            if ($constraints!==null && $constraints!==$candidate) return null;
            $constraints=$candidate;
        }
        return $constraints??[];
    }

    /**
     * Project only the verified query meaning that the model may reuse.  The
     * optional origin says whether the preceding metric perspective came from
     * the customer or from the platform's first-answer suggestion; it contains
     * no answer text, identity, result row or business value.
     */
    public static function modelView(array $query,array $contextMeaning=[]): array
    {
        return ['metric_codes'=>array_values($query['metric_codes']),'operation'=>$query['query_shape'],
            'periods'=>array_values(array_filter([
                ['kind'=>'date_range','start'=>$query['start_date'],'end'=>$query['end_date']],
                $query['compare_range']===null?null:['kind'=>'date_range','start'=>$query['compare_range']['start'],'end'=>$query['compare_range']['end']],
            ])),'ranking'=>is_array($query['ranking']??null)?$query['ranking']:['direction'=>'unspecified','limit'=>null],'scope'=>self::scope($query),
            'object_kind'=>$query['business_filters']['object_kind']??'store',
            // These flags disclose neither an ID nor a name. They let the
            // model distinguish a real prior restriction from an empty
            // placeholder, so a new analytical object is not needlessly
            // treated as replacing a person or store the customer never chose.
            'has_store_scope_restriction'=>($query['store_ids']??[])!==[],
            'has_business_filter'=>($query['business_filters']??[])!==[],
            // IDs, names, refs and results never leave the server.
            'has_object_selection'=>self::hasObjectSelection((array)($query['business_filters']??[])),
            'aggregate_condition'=>is_array($query['aggregate_condition']??null)?$query['aggregate_condition']:null,
            'presentation_origin'=>self::presentationOrigin($contextMeaning)];
    }

    private static function presentationOrigin(array $contextMeaning): string
    {
        $origin=$contextMeaning['presentation_origin']??'customer_or_verified_context';
        if (!in_array($origin,['customer_or_verified_context','platform_observation','platform_recommendation'],true)) self::conflict();
        return $origin;
    }

    /**
     * The normal intent is used only when every contextual field is known.
     * When the model marks a field pending, fallback_intent is a private,
     * verified preview built from the last signed query.  The gateway wraps
     * that preview in a required customer decision before it can execute.
     *
     * @return array{intent:array,prospective_intent:array,constraints:?array,pending:array,fallback_intent:array,fallback_constraints:?array,replacement_confirmation:bool}
     */
    public static function merge(?array $source,array $intent): array
    {
        if ($source===null) return ['intent'=>$intent,'prospective_intent'=>$intent,'constraints'=>null,'pending'=>[],
            'fallback_intent'=>$intent,'fallback_constraints'=>null,'replacement_confirmation'=>false];
        $delta=$intent['context_delta']??null;
        if (!is_array($delta)) self::conflict();
        $prior=self::modelView($source);$pending=[];
        $out=$intent;
        $out['metric_codes']=self::field($delta['metric_codes'],$intent['metric_codes'],$prior['metric_codes'],'metric_codes',$pending);
        $out['operation']=self::field($delta['operation'],$intent['operation'],$prior['operation'],'operation',$pending);
        $out['periods']=self::field($delta['periods'],$intent['periods'],$prior['periods'],'periods',$pending);
        $out['scope']=self::field($delta['scope'],$intent['scope'],$prior['scope'],'scope',$pending);
        $out['aggregate_condition']=self::field($delta['aggregate_condition'],$intent['aggregate_condition']??null,$prior['aggregate_condition'],'aggregate_condition',$pending);
        if (in_array($out['operation'],['condition_count','condition_list'],true)
            && self::legacyMemberThreshold($out['aggregate_condition']??null)
            && ($prior['operation']??null)==='threshold_count' && ($prior['object_kind']??null)==='member'
            && count($out['metric_codes']??[])===1) {
            // Backward-compatible promotion of a verified legacy member count
            // into the generic object-condition carrier. This changes only the
            // requested response form; the signed metric, operator, threshold,
            // period and scope remain identical.
            $legacy=$out['aggregate_condition'];
            $out['aggregate_condition']=[
                'subject'=>'member','relation'=>'all',
                'result_form'=>$out['operation']==='condition_count'?'count':'list',
                'conditions'=>[['metric_code'=>$out['metric_codes'][0],'operator'=>$legacy['operator'],
                    'quantity'=>self::centsToYuan($legacy['amount_cents']),'unit'=>'yuan']],
            ];
        }
        if (in_array($out['operation'],['condition_count','condition_list'],true)
            && is_array($out['aggregate_condition']??null) && isset($out['aggregate_condition']['conditions'])) {
            // result_form is the transport mirror of query_shape, not an
            // independent business condition.  A count/list continuation
            // keeps every signed predicate and changes only that mirror.
            $out['aggregate_condition']['result_form']=$out['operation']==='condition_count'?'count':'list';
        }
        $out['object_kind']=self::field($delta['object'],$intent['object_kind'],$prior['object_kind'],'object',$pending);
        // A store-scope replacement carries its target in object_term while
        // the analytical object itself may remain inherited. Keep that target
        // for the later server-side authorized-store binding only.
        if ($delta['object']==='inherit' && $delta['store_scope']!=='replace') $out['object_term']='';
        if ($delta['object']==='clear') {$out['object_kind']='store';$out['object_term']='';}
        $out['ranking']=self::ranking($delta,$intent['ranking'],$prior['ranking'],$pending);
        // A verified metric is enough to answer a time-only continuation.
        // Do not turn it back into a choice merely because the model kept its
        // generic choice flag from the previous answer.
        if ($delta['metric_codes']==='inherit') $out['needs_metric_choice']=false;
        $constraints=[];$fallbackConstraints=[];$replacementConfirmation=false;
        foreach (['store_scope'=>'store_ids','business_filters'=>'business_filters'] as $deltaKey=>$sourceKey) {
            $decision=$delta[$deltaKey]??null;
            if (!in_array($decision,['inherit','replace','clear','pending'],true)) self::conflict();
            if ($decision==='inherit') {
                // A named-object selection is a business filter. A new
                // subject cannot silently discard it. The gateway turns this
                // pending marker into an explicit confirmation before the
                // replacement branch can execute.
                $constraints[$sourceKey]=$source[$sourceKey];
                $fallbackConstraints[$sourceKey]=$source[$sourceKey];
                // Only a declared replacement conflicts with the bound object.
                // `pending` must retain the signed object until the customer
                // explicitly changes it; treating it as replacement loses the
                // very context that the pending flow is meant to protect.
                if ($sourceKey==='business_filters' && $delta['object']==='replace') {
                    $constraints[$sourceKey]=null;
                    $fallbackConstraints[$sourceKey]=null;
                    // `object_kind` is an analytical dimension, not a
                    // customer-selected object. Changing project to product
                    // therefore replaces the old dimension without a second
                    // question. Only a signed concrete selection can be lost
                    // by that replacement; the new dimension still goes
                    // through normal capability and authority checks.
                    if (self::hasObjectSelection($source[$sourceKey])) {
                        $pending[]=$deltaKey;
                        $replacementConfirmation=true;
                    }
                }
            }
            elseif ($decision==='clear') {
                // The intent contract has already proved that this turn
                // explicitly asks for the authorized scope.  Do not turn a
                // correctly understood scope change into a second form just
                // because it broadens the previous query: Reader authority
                // remains the hard boundary and is rechecked at execution.
                $constraints[$sourceKey]=null;
                $fallbackConstraints[$sourceKey]=null;
            }
            elseif ($decision==='replace') {$constraints[$sourceKey]=null;$fallbackConstraints[$sourceKey]=null;}
            else {
                // Pending never means "remove the last restriction".  Keep
                // the signed restriction in the private preview until an
                // explicit response selects retain or clear.
                $constraints[$sourceKey]=$source[$sourceKey];
                $fallbackConstraints[$sourceKey]=$source[$sourceKey];
                $pending[]=$deltaKey;
            }
        }
        $prospective=$out;
        $fallback=$out;
        // A prospective object must never be sent through the executable
        // branch before the customer has confirmed that its previous object
        // filter may be replaced.  Keep the signed object in the preview;
        // the replacement envelope retains the prospective intent separately.
        if ($replacementConfirmation) {
            $fallback['object_kind']=$prior['object_kind'];
            $fallback['object_term']='';
        }
        foreach (['metric_codes','operation','periods','scope','aggregate_condition','object'] as $field) {
            if (!in_array($field,$pending,true)) continue;
            if ($field==='metric_codes') $fallback[$field]=$prior['metric_codes'];
            elseif ($field==='operation') $fallback[$field]=$prior['operation'];
            elseif ($field==='periods') $fallback[$field]=$prior['periods'];
            elseif ($field==='scope') $fallback[$field]=$prior['scope'];
            elseif ($field==='aggregate_condition') $fallback[$field]=$prior['aggregate_condition'];
            else $fallback[$field]=$prior['object_kind'];
        }
        if (in_array('object',$pending,true)) $fallback['object_kind']=$prior['object_kind'];
        if (in_array('ranking_direction',$pending,true)) $fallback['ranking']['direction']=$prior['ranking']['direction'];
        if (in_array('ranking_limit',$pending,true)) $fallback['ranking']['limit']=$prior['ranking']['limit'];
        // The preview retains an already verified metric. It is not an
        // ambiguous fresh request merely because the model marked this turn's
        // metric delta pending.
        if (in_array('metric_codes',$pending,true)) $fallback['needs_metric_choice']=false;
        foreach (['metric_codes','operation','periods','scope','aggregate_condition','object'] as $field) if (in_array($field,$pending,true)) $out[$field]=self::empty($field);
        if (in_array('ranking_direction',$pending,true)) $out['ranking']['direction']='unspecified';
        if (in_array('ranking_limit',$pending,true)) $out['ranking']['limit']=null;
        return ['intent'=>$out,'prospective_intent'=>$prospective,'constraints'=>$constraints,'pending'=>array_values(array_unique($pending)),
            'fallback_intent'=>$fallback,'fallback_constraints'=>$fallbackConstraints,
            'replacement_confirmation'=>$replacementConfirmation];
    }

    public static function resolveSelection(array $resolved,array $catalog,?array $constraints,string $kind,?string $metric): array
    {
        $filters=$constraints['business_filters']??null;
        if ($filters===null || !isset($filters['selection_ref'])) return $resolved;
        if (($filters['object_kind']??null)!==$kind) self::conflict();
        $ref=$filters['selection_ref'];$matches=array_values(array_filter($catalog,static function(array $object)use($ref,$metric):bool{return $object['ref']===$ref&&($metric===null||in_array($metric,$object['relations'],true));}));
        if (count($matches)!==1) throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        if ($resolved['status']==='resolved'&&$resolved['objects'][0]['ref']!==$ref) self::conflict();
        $resolved['status']='resolved';$resolved['objects']=$matches;return $resolved;
    }

    public static function bind(array $compiled,?array $constraints): array
    {
        if ($constraints===null) return $compiled;
        if (($compiled['kind']??null)==='clarification') {$compiled['inherited_query_constraints']=$constraints;return $compiled;}
        if (($compiled['kind']??null)!=='plan'||!is_array($compiled['plan']['query']??null)) return $compiled;
        foreach(['store_ids','business_filters'] as $key){if(!array_key_exists($key,$constraints)||($constraints[$key]!==null&&!is_array($constraints[$key])))self::conflict();if($constraints[$key]===null)continue;$value=$compiled['plan']['query'][$key];if($value!==[]&&$value!==$constraints[$key])self::conflict();$compiled['plan']['query'][$key]=$constraints[$key];}
        return $compiled;
    }

    /**
     * A result reference selects one displayed object. It must not replace an
     * unrelated, already-confirmed store range. A referenced store is itself
     * a store-range selection; a referenced person is a business filter and
     * therefore keeps the current store constraint intact.
     */
    public static function applyResultReference(?array $constraints,array $reference): array
    {
        if ($constraints===null) self::conflict();
        foreach (['store_ids','business_filters'] as $key) {
            if (!array_key_exists($key,$constraints) || ($constraints[$key]!==null && !is_array($constraints[$key]))) self::conflict();
        }
        $kind=$reference['object_kind']??null;
        if ($kind==='store') {
            if (!is_array($reference['store_ids']??null) || !is_array($reference['business_filters']??null)) self::conflict();
            $constraints['store_ids']=$reference['store_ids'];
            // Store scope and the current analytical population are separate:
            // selecting a ranked store must preserve the new people/product
            // filter (or null so the compiler can bind that new population).
            return $constraints;
        }
        if ($kind==='person' && is_array($reference['business_filters']??null)) {
            $constraints['business_filters']=$reference['business_filters'];
            return $constraints;
        }
        self::conflict();
    }

    private static function field(string $decision,$current,$previous,string $name,array &$pending)
    {
        if ($decision==='inherit') return $previous;
        if ($decision==='replace') return $current;
        if ($decision==='clear') return self::empty($name);
        if ($decision==='pending') {$pending[]=$name;return self::empty($name);} self::conflict();
    }
    private static function ranking(array $delta,array $current,array $previous,array &$pending): array
    {
        $out=$current;
        foreach(['direction'=>'ranking_direction','limit'=>'ranking_limit'] as $key=>$deltaKey){$decision=$delta[$deltaKey]??null;if($decision==='inherit')$out[$key]=$previous[$key];elseif($decision==='replace'){}elseif($decision==='clear')$out[$key]=$key==='direction'?'unspecified':null;elseif($decision==='pending'){$out[$key]=$key==='direction'?'unspecified':null;$pending[]=$deltaKey;}else self::conflict();}
        return $out;
    }
    private static function empty(string $name){return $name==='metric_codes'||$name==='periods'?[]:($name==='aggregate_condition'?null:($name==='scope'?'unspecified':($name==='object'?'unknown':'unknown')));}
    /**
     * A dimension or a server-owned population is not a concrete customer
     * selection.  Condition queries use a signed cohort reference only to
     * define their eligible population; treating that carrier as if the
     * customer had named one employee traps the next independent question in
     * the old topic and incorrectly asks to replace a selected person.
     */
    private static function hasObjectSelection(array $filters): bool
    {
        $ref=$filters['selection_ref']??null;
        return is_string($ref) && $ref!=='' && strpos($ref,'cohort:')!==0;
    }
    private static function scope(array $query): string {return 'authorized';}
    private static function legacyMemberThreshold($condition): bool
    {
        if (!is_array($condition)) return false;
        $keys=array_keys($condition);sort($keys,SORT_STRING);
        return $keys===['aggregation','amount_cents','operator','subject']
            &&($condition['subject']??null)==='member'&&($condition['aggregation']??null)==='period_total'
            &&in_array($condition['operator']??null,['gte','gt','lte','lt','eq'],true)
            &&is_int($condition['amount_cents']??null)&&$condition['amount_cents']>0;
    }
    private static function centsToYuan(int $cents): string
    {
        $whole=intdiv($cents,100);$fraction=$cents%100;
        return $fraction===0?(string)$whole:$whole.'.'.rtrim(str_pad((string)$fraction,2,'0',STR_PAD_LEFT),'0');
    }
    private static function conflict(): void {throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');}
}
