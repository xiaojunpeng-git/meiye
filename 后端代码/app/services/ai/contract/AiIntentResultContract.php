<?php
namespace app\services\ai\contract;

/**
 * The model may understand prose, but it can only cross this boundary as a
 * bounded semantic candidate. Follow-ups use an explicit delta: absent is
 * never silently interpreted as “keep the old value”.
 */
final class AiIntentResultContract
{
    const VERSION='intent-binding-v5';
    const REQUIRED_FIELDS=['action_codes','metric_codes','needs_metric_choice','object_kind','object_term','operation','requirement_bindings','unresolved_fragments'];
    const DELTA_FIELDS=['metric_codes','object','business_filters','store_scope','periods','operation','ranking_direction','ranking_limit','scope','aggregate_condition'];
    const DELTA_ACTIONS=['inherit','replace','clear','pending'];
    const PROVENANCE_FIELDS=['metric_codes','object_kind','object_relation','operation','periods','ranking','scope','aggregate_condition'];

    public static function repairableFormat(?string $predicate): bool
    {
        // A retry asks the model to emit its own complete answer. The server
        // never supplies omitted business semantics or repairs an invalid
        // selected metric on the model's behalf.
        if ($predicate==='missing_metric_codes') return true;
        if (in_array($predicate,['bad_value:requirement_bindings','missing_requirement_binding','unexpected_requirement_binding',
            'binding_row_shape','binding_row_id','binding_row_status','binding_row_codes'],true)) return true;
        if (in_array($predicate,['ambiguous_metric_codes_present','bad_value:recommended_initial_answer',
            'bad_value:initial_observation','initial_observation_metric_count',
            'initial_observation_query_shape','provenance_field_not_understood',
            'bad_value:result_reference'],true)) return true;
        if ($predicate==='recommended_initial_answer_with_current_metric_requirement') return true;
        // These are bounded enum carriers, not business decisions made by the
        // server.  A provider can occasionally use a natural-language label
        // (for example, "overall") where this protocol requires `store`.
        // Let one separately-recorded model correction choose a valid carrier
        // from the published vocabulary; PHP must never translate it or pick
        // a metric, period, object identity or result on the model's behalf.
        if (in_array($predicate,['bad_value:object_kind','bad_value:object_relation',
            'bad_value:operation','bad_value:scope','bad_value:ranking','bad_value:periods','bad_value:aggregate_condition'],true)) return true;
        // A binding that marks a customer-supplied value as inherited would
        // otherwise let the merger execute the previous query.  It is a
        // bounded, model-authored delta correction, not a request for the
        // server to fill in business meaning.
        if (is_string($predicate) && strpos($predicate,'binding_requirement_delta_mismatch:')===0) return true;
        if (is_string($predicate) && preg_match('/^context_constraint_without_source:(store_scope_clear|store_scope_replace|business_filters)$/D',$predicate)) return true;
        // This is not a business fallback. It detects only a malformed
        // follow-up *relationship*: the binding kept an old analytical
        // dimension while declaring a new non-ranking answer form, despite
        // the accepted current meaning naming no analytical object. The one
        // repair asks the model to decide the delta again; PHP never clears a
        // dimension, picks a metric or interprets customer wording itself.
        if (in_array($predicate,['context_analytical_dimension_carryover','context_metric_carryover_rejected',
            'current_metric_binding_rejected','empty_registered_binding'],true)) return true;
        // A context-only follow-up may change one verified condition, but it
        // cannot silently replace every other part of the prior query.  The
        // recovery asks the model to publish a coherent delta again; PHP does
        // not supply an object, response form, rank or metric in its place.
        if (is_string($predicate) && preg_match('/^contextual_followup_changed:(metric_codes|object|business_filters|store_scope|operation|ranking_direction|ranking_limit|scope|aggregate_condition)$/D',$predicate)) return true;
        return is_string($predicate) && strpos($predicate,'missing_key:')===0
            && in_array(substr($predicate,12),array_merge(self::REQUIRED_FIELDS,['context_delta']),true);
    }

    /**
     * A completed language-understanding pass can safely continue a verified
     * query when it carries exactly one new period and nothing else.  This is
     * deliberately a typed-contract shortcut, not a phrase, metric or result
     * shortcut: the model still decides whether the current customer message
     * is period-only, and the normal merger/compiler re-checks the signed
     * capability, current authority and Reader boundary afterwards.
     *
     * Platform-proposed multi-metric observations stay on the ordinary
     * binding path.  Their presentation origin is a product decision that
     * must not be reconstructed from a query snapshot by PHP.
     */
    public static function inheritedPeriodOnlyContextIntent(array $understanding,array $sourceQuery,array $contextMeaning=[]): ?array
    {
        if (($understanding['status']??null)!=='understood'
            || ($contextMeaning['presentation_origin']??'customer_or_verified_context')!=='customer_or_verified_context') return null;
        $requirements=AiIntentUnderstandingContract::requirements($understanding);
        if (count($requirements)!==1) return null;
        $requirement=array_values($requirements)[0];
        if (($requirement['fields']??null)!==['periods']) return null;
        $periods=$requirement['values']['periods']??null;
        if (!self::periods($periods) || !self::currentEvidenceOnly($requirement['evidence']??[])) return null;
        $metrics=$sourceQuery['metric_codes']??null;
        $shape=$sourceQuery['query_shape']??null;
        if (!self::codes($metrics) || !in_array($shape,['summary','trend','ranking','comparison','threshold_count'],true)) return null;
        $ranking=is_array($sourceQuery['ranking']??null)?$sourceQuery['ranking']:[];
        $direction=$ranking['direction']??'unspecified';
        if ($direction===null) $direction='unspecified';
        $limit=$ranking['limit']??null;
        if (!in_array($direction,['top','bottom','top_and_bottom','unspecified'],true)
            || (!is_null($limit) && !is_int($limit))) return null;
        $filters=is_array($sourceQuery['business_filters']??null)?$sourceQuery['business_filters']:[];
        $objectKind=$filters['object_kind']??'store';
        if (!in_array($objectKind,['store','person','position','guide','sales_manager','member','product','project','category','partner','inventory','course','organization'],true)) return null;
        $delta=array_fill_keys(self::DELTA_FIELDS,'inherit');
        $delta['periods']='replace';
        return [
            'object_kind'=>$objectKind,
            'object_term'=>'',
            'object_relation'=>isset($filters['selection_ref'])?'selection':'analysis',
            'operation'=>$shape,
            'metric_codes'=>array_values($metrics),
            'action_codes'=>[],
            'needs_metric_choice'=>false,
            'ranking'=>['direction'=>$direction,'limit'=>$limit],
            'periods'=>array_values($periods),
            'scope'=>'unspecified',
            'aggregate_condition'=>null,
            'requirement_bindings'=>[],
            'unresolved_fragments'=>[],
            'context_delta'=>$delta,
            // These are server-private normalization markers normally added
            // by normalize().  The shortcut has no model binding payload, so
            // it declares their conservative values explicitly instead of
            // relying on an undefined-key default later in the gateway.
            'result_reference'=>null,
            '_object_term_normalized'=>false,
            '_scope_supplied'=>false,
            '_periods_supplied'=>true,
            '_ranking_supplied'=>false,
            '_aggregate_condition_supplied'=>false,
        ];
    }

    private static function currentEvidenceOnly($evidence): bool
    {
        if (!is_array($evidence) || $evidence===[]) return false;
        foreach ($evidence as $item) if (!is_array($item) || ($item['message_id']??null)!=='current') return false;
        return true;
    }

    /**
     * A prior analytical dimension (such as product or project) is not a
     * customer-selected filter. When a binding changes to a new non-ranking
     * answer form but mechanically inherits that old dimension, its own
     * context delta is structurally incoherent. Detect the shape only and
     * send it back to the binding model once; semantic meaning remains owned
     * by the two model stages.
     */
    public static function requiresContextRebinding(?array $sourceQuery,array $understanding,array $rawIntent,array $mergedIntent): bool
    {
        if ($sourceQuery===null || !is_array($rawIntent['context_delta']??null)) return false;
        $delta=$rawIntent['context_delta'];
        if (($delta['object']??null)!=='inherit' || ($delta['business_filters']??null)!=='inherit'
            || ($delta['operation']??null)!=='replace') return false;
        if (!in_array($mergedIntent['operation']??null,['summary','trend','comparison','definition'],true)) return false;
        $priorKind=$sourceQuery['business_filters']['object_kind']??'store';
        if (!in_array($priorKind,['position','guide','sales_manager','member','product','project','category','partner','inventory','course','organization'],true)) return false;
        if (is_string($sourceQuery['business_filters']['selection_ref']??null)
            && $sourceQuery['business_filters']['selection_ref']!=='') return false;
        // A current analytical object is a legitimate new subject and must
        // never be rewritten by this guard. The accepted understanding is
        // normalized, so its evidence tells us this is current-turn meaning
        // without parsing any customer phrase in PHP.
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('object_kind',(array)($requirement['fields']??[]),true)) continue;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current') return false;
            }
        }
        return ($mergedIntent['object_kind']??null)===$priorKind;
    }

    /**
     * A reviewer may prove that a model mechanically retained a previous
     * metric although the current, independently accepted meaning contains a
     * new metric requirement.  This detects only that contradictory context
     * shape; the recovery asks the model to bind the current meaning again.
     * It never interprets customer wording or selects a replacement metric.
     */
    public static function requiresMetricContextRebinding(?array $sourceQuery,array $understanding,array $rawIntent): bool
    {
        if ($sourceQuery===null || !is_array($rawIntent['context_delta']??null)
            || ($rawIntent['context_delta']['metric_codes']??null)!=='inherit') return false;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current') return true;
            }
        }
        return false;
    }

    /**
     * A semantic reviewer can reject a fresh metric candidate even when the
     * binding did not literally inherit a prior code.  The only fact this
     * exposes is that this turn contains an evidence-backed metric
     * requirement; the model must choose any replacement itself.
     */
    public static function requiresCurrentMetricRebinding(array $understanding): bool
    {
        return self::hasCurrentMetricRequirement($understanding);
    }

    public static function manifest(): array
    {
        return ['code'=>'intent_result','version'=>self::VERSION,'hash'=>hash('sha256',self::modelInstruction(true))];
    }

    public static function native($value)
    {
        if ($value instanceof \stdClass) $value=get_object_vars($value);
        if (is_array($value)) foreach ($value as $key=>$item) $value[$key]=self::native($item);
        return $value;
    }

    public static function modelInstruction(bool $hasPriorQuery): string
    {
        $context=$hasPriorQuery
            ? 'A verified prior query exists. Include context_delta with exactly these keys: '.implode(', ',self::DELTA_FIELDS).'. Each value is one of inherit, replace, clear or pending. Use inherit only when the current wording leaves that exact meaning unchanged. Use replace only when current wording supplies a new meaning in the matching ordinary field. Use clear only when the customer explicitly removes a condition or when the accepted current meaning is a complete standalone overall query rather than a continuation. Use pending when clarification is needed. Never omit a delta key and never infer inherit from an omitted field. A short continuation can replace just the analytical object while retaining the verified period, response form, ranking direction, ranking quantity, scope and other unchanged meaning. Conversely, a self-contained current question seeking an overall operating view is a new topic when it does not refer to the earlier result or object: clear the old analytical object/dimension, replace the response form with summary, clear old ranking, and do not let an old product, project, person or ranking become an unspoken condition. This can clear a prior selected analytical object as well; it never clears data authority or widens the authorized range. prior_query.presentation_origin says whether the preceding metric perspective was explicitly selected by the customer or was a platform-suggested first answer. A platform suggestion is context that may be continued when the current wording refers to it, but it is never an unspoken customer condition. When presentation_origin is platform_observation, its metric_codes are one deliberately presented overview group, not competing choices. If the current wording semantically continues that overview and changes only a contextual condition such as the period, inherit the complete group and keep initial_observation=true; do not turn it into needs_metric_choice merely because it contains several metrics. Only request a metric choice when the current wording itself introduces a different measurement or makes the intended continuation genuinely unclear. Set store_scope to clear only when the current request explicitly asks for the authorized/all-store range and supplies scope=authorized; set store_scope to replace only when the current request identifies a store object; clear business_filters when abandoning an earlier analytical dimension. A selected object may be cleared only for a complete standalone overall query, and that transition is independently reviewed before execution; otherwise clear or replace it only when the current request identifies the changed object scope. When that new object cannot legally use the previous metric under capabilities.object_contracts, do not return operation unknown or an unresolved fragment and do not reuse the old metric. Select a compatible registered metric when it faithfully provides a useful first answer to the accepted goal; mark metric_codes pending only when several readings remain and none can be selected without changing that goal.'
            : 'No verified prior query exists. Do not include context_delta.';
        return 'Return one JSON object following '.self::VERSION.'. Required keys: '.implode(', ',self::REQUIRED_FIELDS).'. Optional keys: object_relation, ranking, periods, scope, aggregate_condition, result_reference, initial_observation, recommended_initial_answer'.($hasPriorQuery?', context_delta':'').'. '.$context.' object_term is an exact customer term or an empty string where no named object is needed. object_relation is analysis when object_kind is the thing being compared, grouped or listed, and selection only when object_term identifies a particular target that must narrow the data. Do not turn an analytical object into a selection; when object_relation is analysis, object_term must be empty. A threshold member count uses object_kind=member, object_relation=analysis, operation=threshold_count and aggregate_condition exactly {"subject":"member","aggregation":"period_total","operator":"gte|gt|lte|lt|eq","amount_cents":positive-integer}; preserve it only when the accepted understanding carries the same condition. For an aggregate operating goal with no named analytical object, use object_kind store and an empty object_term; words such as “overall” describe the goal, not a separate object_kind. The accepted understanding is the primary record of customer meaning. Do not reinterpret, replace or contradict any typed value it already carries; bind its business goal to registered capability only. If that understanding has no typed carrier for a response form, period, ranking, scope, object or object relation that you can still clearly understand from the same question, return the faithful candidate rather than treating the customer as unclear. The server sends such a new carrier to a separate semantic reviewer before it can execute. needs_metric_choice is true only when the intended business measurement is genuinely ambiguous and no professionally useful first reading can be selected; then metric_codes must be empty. A customer may instead ask for a broad overall operating view without naming one measurement. When the accepted understanding is such an open operating goal, no explicit customer condition conflicts with it, and operation is summary, set initial_observation=true and select two to four independent supplied store metrics as observation angles for an initial answer. Those codes are professional observations, not a claim that the customer selected one metric; do not use this exception for a named measurement, exclusion, comparison, ranking, or object-specific request. When the customer has made the analytical object and response form clear but the business measurement has several registered readings, select the most useful compatible professional first reading from the supplied capabilities. Only a summary can intentionally return multiple independent reading angles; a ranking must set metric_codes to exactly one registered code because one ranked result needs one comparable measurement. Set recommended_initial_answer=true, needs_metric_choice=false and label the resulting metric perspective in the answer, so the customer can naturally pursue another perspective afterwards. Never set recommended_initial_answer for an unresolved meaning, an unavailable capability, a changed exclusion, or a result that needs customer confirmation. Set both initial_observation and recommended_initial_answer false or omit them for every other request. Lack of an available metric is not ambiguity. requirement_bindings is a required accountability array. It has exactly one row for every accepted understanding requirement whose fields include metric_codes, and no other rows. Each row is exactly {"requirement_id":"rN","status":"satisfied|unavailable|pending","metric_codes":[registered codes]}. A satisfied row names the registered metric codes that fulfill that one requirement; together all satisfied rows must account for the selected metric_codes. An unavailable or pending row has an empty metric_codes array and therefore cannot accompany an executable metric selection. When there is no accepted metric_codes requirement and you select a professional recommended_initial_answer, requirement_bindings must be an empty array: the recommendation is not customer-selected meaning and must not be attached to object, ranking, period, scope or other requirements. This is an accountability record for the accepted meaning, not a phrase matcher: do not decide it from word overlap, and do not omit an exclusion or another customer requirement. periods, when present, is an ordered array of up to two period objects. A period is exactly one of {"kind":"date_range","start":"YYYY-MM-DD","end":"YYYY-MM-DD"}, {"kind":"relative_days","days":positive-integer,"end_offset_days":signed-integer}, or {"kind":"month_offset","offset_months":signed-integer}. Preserve early, future and long periods exactly; execution coverage and length limits are checked by the server later, never reinterpret them as an invalid intent. A calendar-month meaning uses month_offset; relative_days is only for a stated rolling number of days. ranking is {"direction":"top|bottom|top_and_bottom|unspecified","limit":integer-or-null}. Scope and accessible stores are server-owned: omit scope unless current wording explicitly changes current-store versus authorized scope. metric_codes and action_codes are binding candidates inside their required arrays and only contain supplied codes. Do not output provenance: the server records whether a carrier came from accepted meaning, verified context, a system default, or a candidate that still needs semantic admission. result_reference, if used, is only {"group":"top|bottom","ordinal":positive-integer}; emit it only when the accepted understanding has an equal result_reference requirement with current-question evidence. group means the rank section explicitly named by the customer and never contains an ID, name or result value. unresolved_fragments only contains exact current-question text whose meaning cannot be understood, not understood requests that lack a registered binding. Never calculate, query, invent a condition, discard a condition, or copy a previous result value.';
    }

    public static function normalize($value,array $metricCodes,array $actionCodes,array $safeQuestion,array $understanding=[]): array
    {
        $value=self::native($value);
        if (!is_array($value)) self::fail('root_not_object');
        $hasPrior=($safeQuestion['prior_query']??null)!==null;
        // This is response bookkeeping only: an omitted list means the model
        // did not identify an unbound fragment.  Defaulting it cannot select
        // a metric, date, object, scope, action or permission, and avoids
        // rejecting a complete business binding for an empty carrier field.
        if (!array_key_exists('unresolved_fragments',$value)) $value['unresolved_fragments']=[];
        // `object_term` is only an identity carrier. When accepted meaning
        // contains no selected object, its sole faithful value is the empty
        // string; normalizing that omission cannot select a person, store,
        // metric, period, scope or result. A customer-selected object remains
        // strict: the omitted identity must never be guessed or recovered
        // from history.
        if (!array_key_exists('object_term',$value) && self::canDefaultEmptyObjectTerm($understanding)) {
            $value['object_term']='';
        }
        foreach (self::REQUIRED_FIELDS as $key) if (!array_key_exists($key,$value)) self::fail('missing_key:'.$key);
        if ($hasPrior && !array_key_exists('context_delta',$value)) self::fail('missing_key:context_delta');
        // Accept provenance from older model prompts but never trust or emit it.
        // The server derives the authoritative audit record below.
        $allowed=array_merge(self::REQUIRED_FIELDS,['provenance','object_relation','ranking','periods','scope','aggregate_condition','result_reference','initial_observation','recommended_initial_answer'],$hasPrior?['context_delta']:[]);sort($allowed,SORT_STRING);
        $keys=array_keys($value);sort($keys,SORT_STRING);
        if (array_diff($keys,$allowed)) self::fail('unknown_key');
        if (!$hasPrior && array_key_exists('context_delta',$value)) self::fail('unexpected_context_delta');
        if (!is_bool($value['needs_metric_choice'])) self::fail('bad_type:needs_metric_choice');
        if (!is_string($value['object_term']) || mb_strlen($value['object_term'],'UTF-8')>160) self::fail('bad_value:object_term');
        if (!in_array($value['object_kind'],['store','person','position','guide','sales_manager','member','product','project','category','partner','inventory','course','organization','unknown'],true)) self::fail('bad_value:object_kind');
        $objectRelation=$value['object_relation']??($value['object_term']===''?'analysis':'selection');
        if (!in_array($objectRelation,['analysis','selection'],true)) {
            // Binding is not a second authority on the customer's object
            // relation.  When the independently accepted understanding has
            // one complete relation, retain that model-authored semantic
            // value instead of spending a second provider call merely to
            // correct an enum spelling.  No source (or conflicting sources)
            // remains a strict rejection: PHP never decides whether an
            // object is analytical or a selection from customer wording.
            $understoodRelation=self::singleUnderstoodObjectRelation($understanding);
            if ($understoodRelation===null) self::fail('bad_value:object_relation');
            $objectRelation=$understoodRelation;
            $value['object_relation']=$objectRelation;
        }
        if ($objectRelation==='analysis') $value['object_term']='';
        if ($objectRelation==='selection' && $value['object_term']==='') self::fail('bad_value:object_relation');
        if (!in_array($value['operation'],['summary','trend','ranking','comparison','threshold_count','definition','unknown'],true)) self::fail('bad_value:operation');
        if (!self::codes($value['metric_codes'])) self::fail('bad_value:metric_codes');
        foreach ($value['metric_codes'] as $code) if (!in_array($code,$metricCodes,true)) throw new AiContractException('AI_MODEL_METRIC_UNKNOWN',['stage'=>'intent_contract','predicate'=>'unknown_metric_code']);
        $initialObservation=$value['initial_observation']??false;
        $explicitRecommendedInitialAnswer=($value['recommended_initial_answer']??false)===true;
        if (!is_bool($initialObservation)) self::fail('bad_value:initial_observation');
        // `initial_observation` already expresses the complete presentation
        // decision for a broad operating first answer. Some JSON models add
        // the otherwise useful single-reading label as well. It carries no
        // customer condition, metric choice or execution authority, and it
        // is incompatible only because it is redundant in this one shape.
        // Normalize that transport duplication instead of spending every
        // model retry asking it to remove a presentation-only boolean.
        if ($initialObservation && ($value['recommended_initial_answer']??false)===true) {
            $value['recommended_initial_answer']=false;
        }
        // A model can choose one registered professional focus for a broad
        // question while accidentally retaining its multi-angle overview
        // label. This is presentation metadata only: retain the model's one
        // selected candidate and let the ordinary first-answer path (and its
        // semantic review) handle it. PHP does not add a metric, infer a
        // requirement, or decide what the focus should be.
        $singleObservationRecommendation=false;
        if ($initialObservation && count($value['metric_codes'])===1) {
            $initialObservation=false;
            $singleObservationRecommendation=true;
            $value['initial_observation']=false;
            if (($value['needs_metric_choice']??false)===false) $value['recommended_initial_answer']=true;
        }
        // A model may emit its one selected candidate together with its old
        // "needs choice" marker.  This is a contradictory transport state,
        // not customer ambiguity.  For at most one understood metric
        // requirement and a model-selected registered candidate,
        // retain that model decision as a labelled recommended first answer.
        // The independent semantic reviewer still has to admit it before any
        // Reader execution; PHP neither maps words to a metric nor chooses a
        // candidate from a list.
        if ($value['needs_metric_choice'] && $value['metric_codes']!==[]) {
            $metricRequirementIds=[];
            foreach (AiIntentUnderstandingContract::requirements($understanding) as $id=>$requirement) {
                if (in_array('metric_codes',(array)($requirement['fields']??[]),true)) $metricRequirementIds[]=$id;
            }
            // A broad ranking can clearly ask for one leading person while
            // naming no particular measurement. It has no metric-bearing
            // customer requirement, so a model-selected professional first
            // measure is allowed. Two separately stated measurements are not:
            // selecting one would drop customer meaning.
            // A stale "needs choice" marker can be repaired only when it
            // carries one already-selected metric.  Several candidates for
            // one customer measurement are genuine ambiguity; clearing the
            // marker would silently turn alternatives into a combined query.
            if ($initialObservation || count($value['metric_codes'])!==1 || count($metricRequirementIds)>1) self::fail('ambiguous_metric_codes_present');
            $value['needs_metric_choice']=false;
            // When the understanding already carries a customer-owned
            // measurement, this only repairs the stale choice marker.  It is
            // not a platform recommendation and must not later be rejected
            // merely because the binding transport described it that way.
            $value['recommended_initial_answer']=!self::hasCurrentMetricRequirement($understanding);
            if (is_array($value['requirement_bindings']??null) && count($value['requirement_bindings'])===1) {
                $row=&$value['requirement_bindings'][0];
                if (is_array($row) && ($row['status']??null)==='pending' && ($row['metric_codes']??null)===[]) {
                    $row['status']='satisfied';$row['metric_codes']=$value['metric_codes'];
                }
                unset($row);
            }
        }
        if (!self::codes($value['action_codes'])) self::fail('bad_value:action_codes');
        // The current understanding contract has no evidence-bearing action
        // requirement. Letting a model-authored action label filter a metric
        // candidate would therefore add a condition the customer never made.
        // Keep the transport key for compatibility, but do not make it an
        // executable constraint until action meaning has a real, auditable
        // source in the understanding protocol.
        $value['action_codes']=[];
        if (!is_array($value['unresolved_fragments']) || count($value['unresolved_fragments'])>8 || count(array_unique($value['unresolved_fragments']))!==count($value['unresolved_fragments'])) self::fail('bad_value:unresolved_fragments');
        // Optional structural fields are often emitted by a JSON model as
        // null.  For an otherwise absent ranking that is formatting, not a
        // business decision: normalize only the all-null form to omission.
        // A partly filled ranking remains invalid so the server never invents
        // its direction or count.
        $rankingSupplied=array_key_exists('ranking',$value) && $value['ranking']!==null;
        // Validate the shape before accepting the all-null transport form as
        // an omitted optional value. Otherwise a model could hide an unknown
        // nested field behind two nulls and bypass the contract boundary.
        if ($rankingSupplied && is_array($value['ranking'])) {
            $rawRankingKeys=array_keys($value['ranking']);sort($rawRankingKeys,SORT_STRING);
            if ($rawRankingKeys!==['direction','limit']) self::fail('bad_value:ranking');
        }
        if ($rankingSupplied && is_array($value['ranking'])
            && $value['ranking']['direction']===null && $value['ranking']['limit']===null) $rankingSupplied=false;
        $ranking=$rankingSupplied?$value['ranking']:['direction'=>'unspecified','limit'=>null];
        $rankingKeys=is_array($ranking)?array_keys($ranking):[];sort($rankingKeys,SORT_STRING);
        if (!is_array($ranking) || $rankingKeys!==['direction','limit'] || !in_array($ranking['direction']??null,['top','bottom','top_and_bottom','unspecified'],true) || (!is_null($ranking['limit']??null) && (!is_int($ranking['limit']) || $ranking['limit']<1 || $ranking['limit']>999))) self::fail('bad_value:ranking');
        $recommendedInitialAnswer=$value['recommended_initial_answer']??false;
        if (!is_bool($recommendedInitialAnswer)
            || ($recommendedInitialAnswer && ($initialObservation || $value['needs_metric_choice'] || count($value['metric_codes'])<1 || count($value['metric_codes'])>4
                || ($value['operation']==='ranking' && count($value['metric_codes'])!==1)))) {
            // Keep production diagnostics useful without retaining the model
            // response, customer wording, identity or business values.  A
            // recommendation is only a presentation label; these structural
            // facts explain why the contract could not safely execute it.
            throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
                'stage'=>'intent_contract',
                'predicate'=>'bad_value:recommended_initial_answer',
                'recommended_value_type'=>gettype($recommendedInitialAnswer),
                'initial_observation'=>$initialObservation,
                'needs_metric_choice'=>$value['needs_metric_choice'],
                'selected_metric_count'=>count($value['metric_codes']),
                'operation'=>$value['operation'],
            ]);
        }
        // This flag changes only response wording; it never authorizes the
        // selected metric. A binding can accidentally retain the optional
        // "recommended" presentation label while its selected metric is
        // already accountable to a current customer requirement. Preserve
        // the model's candidate and its ordinary semantic review, but remove
        // that contradictory label instead of spending the sole recovery on
        // a non-business transport detail. The understanding model—not a
        // phrase rule—continues to decide whether the current turn contains
        // a customer-owned measurement.
        if ($recommendedInitialAnswer && !$singleObservationRecommendation && self::hasCurrentMetricRequirement($understanding)) {
            $recommendedInitialAnswer=false;
            $value['recommended_initial_answer']=false;
        }
        if ($initialObservation) {
            if (count($value['metric_codes'])<2 || count($value['metric_codes'])>4) self::fail('initial_observation_metric_count');
            if ($value['needs_metric_choice'] || $value['object_kind']!=='store' || $value['object_term']!=='' || $value['operation']!=='summary'
                || $ranking['direction']!=='unspecified' || $ranking['limit']!==null) self::fail('initial_observation_query_shape');
        }
        $periodsSupplied=array_key_exists('periods',$value);$periods=$periodsSupplied?$value['periods']:[];
        if (!self::periods($periods)) self::fail('bad_value:periods');
        $conditionSupplied=array_key_exists('aggregate_condition',$value) && $value['aggregate_condition']!==null;
        $aggregateCondition=$conditionSupplied?$value['aggregate_condition']:null;
        if ($conditionSupplied && !self::aggregateCondition($aggregateCondition)) self::fail('bad_value:aggregate_condition');
        if (($value['operation']==='threshold_count') !== ($aggregateCondition!==null)) self::fail('bad_value:aggregate_condition');
        // Like an all-null ranking, null scope is an optional transport form,
        // not a customer request.  Do not turn it into a failed query or an
        // implied data-range change.
        $scopeSupplied=array_key_exists('scope',$value) && $value['scope']!==null;$scope=$scopeSupplied?$value['scope']:'unspecified';
        if (!in_array($scope,['current_store','authorized','unspecified'],true)) self::fail('bad_value:scope');
        // Understanding owns the semantic projection of the customer's
        // wording. Binding owns only the later capability candidate.  When
        // the accepted projection already has a typed execution value, keep
        // that value instead of asking a second model pass to independently
        // restate (and occasionally contradict) the same natural-language
        // decision. This is a copy of model-authored meaning, never a PHP
        // phrase rule or a server-selected metric/range/rank.
        $anchored=self::anchorUnderstandingValues($understanding,$value,$ranking,$periods,$scope,$objectRelation,$aggregateCondition,
            $rankingSupplied,$periodsSupplied,$scopeSupplied,$conditionSupplied);
        $value=$anchored['intent'];$ranking=$anchored['ranking'];$periods=$anchored['periods'];$scope=$anchored['scope'];$objectRelation=$anchored['object_relation'];$aggregateCondition=$anchored['aggregate_condition'];
        $rankingSupplied=$anchored['ranking_supplied'];$periodsSupplied=$anchored['periods_supplied'];$scopeSupplied=$anchored['scope_supplied'];$conditionSupplied=$anchored['aggregate_condition_supplied'];
        if ($objectRelation==='analysis') $value['object_term']='';
        if ($objectRelation==='selection' && $value['object_term']==='') self::fail('bad_value:object_relation');
        $delta=$hasPrior?self::delta($value['context_delta']):null;
        self::normalizeContextOnlyPendingFollowup($understanding,$delta,$value);
        self::normalizeNoopContextChanges($delta,$value,$safeQuestion['prior_query']??null);
        self::assertContextOnlyFollowup($understanding,$delta);
        self::assertContextConstraintSources($understanding,$delta,$value,$scope,$scopeSupplied,$safeQuestion['prior_query']??null);
        self::requireReplacementValues($delta,$value,$rankingSupplied,$periodsSupplied,$scopeSupplied);
        // Provenance is a server-owned audit record.  The binding model may
        // carry an old or malformed copy, but it never gets to authorise its
        // own fields by writing that copy.  Derivation below uses only the
        // independently accepted understanding and the candidate values.
        self::assertRequirementValues($understanding,$value,$ranking,$periods,$scope,$objectRelation,$aggregateCondition,$delta,
            $safeQuestion['prior_query']??null,$safeQuestion['reference_date']??null);
        $requirementBindings=self::requirementBindings(
            $value['requirement_bindings'],
            AiIntentUnderstandingContract::requirements($understanding),
            $metricCodes,
            self::effectiveMetricCodes($value['metric_codes'],$delta,$safeQuestion['prior_query']??null),
            $initialObservation
        );
        $provenance=self::provenance(
            $understanding,$hasPrior,$value,$ranking,$periods,$scope,$objectRelation,$aggregateCondition,$delta,
            self::effectiveMetricCodes($value['metric_codes'],$delta,$safeQuestion['prior_query']??null)
        );
        // A fresh conversation has no signed result snapshot at all. A model
        // can occasionally emit the optional transport key anyway; it cannot
        // narrow a query, reveal a result, or represent a customer condition
        // in that state, so discard it rather than spending the only recovery
        // attempt asking the model to remove an inert field. With verified
        // context we retain the strict current-turn requirement below.
        if (!$hasPrior) $value['result_reference']=null;
        $resultReference=self::resultReference($value['result_reference']??null,$hasPrior);
        self::assertResultReferenceRequirement($resultReference,$understanding);
        $texts=array_merge([(string)($safeQuestion['question']??'')],(array)($safeQuestion['recent_questions']??[]));
        $normalized=$value['object_term']!=='' && !self::contained($value['object_term'],$texts);
        if ($normalized) $value['object_term']='';
        foreach ($value['unresolved_fragments'] as $fragment) if (!is_string($fragment)||$fragment===''||mb_strlen($fragment,'UTF-8')>160||!self::contained($fragment,$texts)) self::fail('bad_value:unresolved_fragments');
        return ['object_kind'=>$value['object_kind'],'object_relation'=>$objectRelation,'object_term'=>$value['object_term'],'operation'=>$value['operation'],'metric_codes'=>$value['metric_codes'],'action_codes'=>$value['action_codes'],'needs_metric_choice'=>$value['needs_metric_choice'],'initial_observation'=>$initialObservation,'recommended_initial_answer'=>$recommendedInitialAnswer,'requirement_bindings'=>$requirementBindings,'provenance'=>$provenance,'ranking'=>$ranking,'periods'=>$periods,'scope'=>$scope,'aggregate_condition'=>$aggregateCondition,'context_delta'=>$delta,'result_reference'=>$resultReference,'unresolved_fragments'=>$value['unresolved_fragments'],'_periods_supplied'=>$periodsSupplied,'_scope_supplied'=>$scopeSupplied,'_ranking_supplied'=>$rankingSupplied,'_object_term_normalized'=>$normalized,'_contract_version'=>self::VERSION];
    }

    private static function delta($value): array
    {
        if (!is_array($value)) self::fail('bad_value:context_delta');
        // Compatibility for a signed pre-v5 continuation: its prior query
        // cannot carry a threshold condition, so the only non-mutating
        // interpretation of the newly introduced delta field is inherit.
        // New model responses are instructed to emit it explicitly.
        if (!array_key_exists('aggregate_condition',$value)) $value['aggregate_condition']='inherit';
        $keys=array_keys($value);sort($keys,SORT_STRING);$expected=self::DELTA_FIELDS;sort($expected,SORT_STRING);
        if ($keys!==$expected) self::fail('bad_value:context_delta');
        foreach (self::DELTA_FIELDS as $field) if (!in_array($value[$field],self::DELTA_ACTIONS,true)) self::fail('bad_value:context_delta');
        return $value;
    }

    private static function requireReplacementValues(?array $delta,array $value,bool $rankingSupplied,bool $periodsSupplied,bool $scopeSupplied): void
    {
        if ($delta===null) return;
        if ($delta['metric_codes']==='replace' && !array_key_exists('metric_codes',$value)) self::fail('missing_replacement:metric_codes');
        if ($delta['object']==='replace' && !array_key_exists('object_kind',$value)) self::fail('missing_replacement:object');
        if ($delta['periods']==='replace' && !$periodsSupplied) self::fail('missing_replacement:periods');
        if ($delta['operation']==='replace' && !array_key_exists('operation',$value)) self::fail('missing_replacement:operation');
        if (($delta['ranking_direction']==='replace'||$delta['ranking_limit']==='replace') && !$rankingSupplied) self::fail('missing_replacement:ranking');
        if ($delta['scope']==='replace' && !$scopeSupplied) self::fail('missing_replacement:scope');
        if ($delta['aggregate_condition']==='replace' && !self::aggregateCondition($value['aggregate_condition']??null)) self::fail('missing_replacement:aggregate_condition');
    }

    /**
     * Store and object restrictions are as material as a metric or date.  A
     * delta may retain a signed restriction freely, but it may remove or
     * replace one only when this customer turn contains the corresponding
     * accepted meaning.  This is structural provenance, not phrase matching:
     * the understanding stage remains responsible for interpreting the words.
     */
    private static function normalizeNoopContextChanges(?array &$delta,array &$intent,$priorQuery): void
    {
        if ($delta===null || !is_array($priorQuery)) return;
        // A clear action on an already empty, signed constraint has no query
        // effect. Canonicalise only that no-op; an actual scope or object
        // change remains model-understood and must still have current-turn
        // evidence before it can execute.
        if (($priorQuery['has_store_scope_restriction']??true)===false && $delta['store_scope']==='clear') {
            $delta['store_scope']='inherit'; $intent['context_delta']['store_scope']='inherit';
        }
        if (($priorQuery['has_business_filter']??true)===false && $delta['business_filters']==='clear') {
            $delta['business_filters']='inherit'; $intent['context_delta']['business_filters']='inherit';
        }
    }

    /**
     * The understanding boundary has already accepted a turn whose only new
     * customer meaning is a period. A binding-stage `pending` marker for an
     * unrelated prior field cannot make that customer repeat the response
     * form, metric, object or scope: it carries no new business choice.
     *
     * This deliberately uses the model-authored typed field inventory rather
     * than a phrase, metric name or report-specific condition. Replacement
     * and clearing remain strict failures because they would alter the query.
     */
    private static function normalizeContextOnlyPendingFollowup(array $understanding,?array &$delta,array &$intent): void
    {
        if ($delta===null) return;
        $current=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            $hasCurrent=false;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current') {$hasCurrent=true;break;}
            }
            if (!$hasCurrent) continue;
            foreach ((array)($requirement['fields']??[]) as $field) $current[$field]=true;
        }
        if ($current===[] || array_diff(array_keys($current),['periods'])!==[]) return;
        foreach (['metric_codes','object','business_filters','store_scope','operation','ranking_direction','ranking_limit','scope','aggregate_condition'] as $field) {
            if (($delta[$field]??null)!=='pending') continue;
            $delta[$field]='inherit';
            $intent['context_delta'][$field]='inherit';
        }
    }

    /** An absent selected-object identity is never normalized. */
    private static function canDefaultEmptyObjectTerm(array $understanding): bool
    {
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('object_relation',(array)($requirement['fields']??[]),true)) continue;
            if (($requirement['values']['object_relation']??null)==='selection') return false;
        }
        return true;
    }

    /**
     * A turn that contributes only a new time condition is a continuation by
     * contract, not a licence to replace the prior subject, answer form or
     * measurement. This is based on the model-authored, evidence-backed
     * field inventory rather than customer wording or a metric catalogue.
     * Richer turns still let the model decide their delta and pass the normal
     * semantic review.
     */
    private static function assertContextOnlyFollowup(array $understanding,?array $delta): void
    {
        if ($delta===null) return;
        $currentFields=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            $isCurrent=false;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current') {$isCurrent=true;break;}
            }
            if (!$isCurrent) continue;
            foreach ((array)($requirement['fields']??[]) as $field) $currentFields[$field]=true;
        }
        // An empty understanding is handled by the ordinary contract. A
        // result reference, metric, object, rank, scope or unbound condition
        // makes this a richer turn and remains entirely model-owned.
        if ($currentFields===[] || array_diff(array_keys($currentFields),['periods'])!==[]) return;
        foreach (['metric_codes','object','business_filters','store_scope','operation','ranking_direction','ranking_limit','scope','aggregate_condition'] as $field) {
            if (($delta[$field]??null)!=='inherit') self::fail('contextual_followup_changed:'.$field);
        }
    }

    private static function assertContextConstraintSources(array $understanding,?array $delta,array $intent,string $scope,bool $scopeSupplied,?array $priorQuery): void
    {
        if ($delta===null) return;
        $requirements=AiIntentUnderstandingContract::requirements($understanding);
        $hasField=static function(string $field,bool $currentTurnOnly=false) use($requirements): bool {
            foreach ($requirements as $requirement) {
                if (!in_array($field,(array)($requirement['fields']??[]),true)) continue;
                if (!$currentTurnOnly) return true;
                foreach ((array)($requirement['evidence']??[]) as $evidence) {
                    if (($evidence['message_id']??null)==='current') return true;
                }
            }
            return false;
        };
        // “All authorized stores” is an explicit customer scope request.  It
        // is the only current meaning that can remove a previously selected
        // store range.  An empty model field must never mean the same thing.
        if ($delta['store_scope']==='clear'
            && (!($scopeSupplied && $scope==='authorized' && $delta['scope']==='replace'
                && $hasField('scope',true)))) {
            self::fail('context_constraint_without_source:store_scope_clear');
        }
        // Replacing a store range requires the current request to identify a
        // store object. The later gateway binding resolves its actual ID from
        // the authorized catalog; no customer name or ID is trusted here.
        if ($delta['store_scope']==='replace'
            && !($intent['object_kind']==='store' && $hasField('object_kind',true))) {
            self::fail('context_constraint_without_source:store_scope_replace');
        }
        // A selected person/member/etc. is a separate restriction from its
        // analytical object kind. Its removal or replacement must still be
        // grounded in a current object request. A previous analytical
        // dimension alone has no selected identity and must not trap a later
        // independent business question in its old topic.
        // A complete current aggregate query may deliberately end a previous
        // selected analytical object.  This is not a PHP interpretation of
        // a phrase: the understanding independently carries both a current
        // measurement and summary response form, and the binding model has
        // explicitly declared the clear.  The response form may be a
        // capability-binding decision rather than a separately typed
        // customer field (for example, a direct amount question). It always
        // proceeds to the separate semantic reviewer below before a reader
        // can execute it. A short continuation such as only a new period has
        // neither a current measurement nor a summary candidate and remains
        // unable to remove a selected object.
        $standaloneAggregate=$intent['object_kind']==='store'
            && $intent['object_term']===''
            && $intent['operation']==='summary'
            && $hasField('metric_codes',true);
        if (in_array($delta['business_filters'],['clear','replace'],true)
            && ($priorQuery['has_object_selection']??false)===true
            && !$hasField('object_kind',true)
            && !$standaloneAggregate) {
            self::fail('context_constraint_without_source:business_filters');
        }
    }

    private static function provenance(array $understanding,bool $hasPrior,array $intent,array $ranking,array $periods,string $scope,string $objectRelation,?array $aggregateCondition,?array $delta,array $effectiveMetricCodes): array
    {
        $requirements=AiIntentUnderstandingContract::requirements($understanding);
        $known=array_fill_keys(array_keys($requirements),true);
        if (!$known && ($understanding['status']??null)!=='needs_clarification') self::fail('bad_value:provenance');
        $out=[];$used=[];
        // `unbound` is a deliberate understanding outcome: the model has
        // preserved a customer condition for which no registered execution
        // field exists. It must reach the gateway's capability decision, not
        // be misreported as a malformed model response merely because it has
        // no provenance field in an executable plan.
        foreach ($requirements as $id=>$requirement) {
            $fields=(array)($requirement['fields']??[]);
            if (in_array('unbound',$fields,true) || in_array('result_reference',$fields,true)) $used[$id]=true;
        }
        foreach (self::PROVENANCE_FIELDS as $field) {
            $ids=[];
            foreach ($requirements as $id=>$requirement) if (in_array($field,$requirement['fields'],true)) $ids[]=$id;
            if ($ids!==[]) {
                foreach ($ids as $id) $used[$id]=true;
                // A comparison needs an ordered pair.  A first model pass may
                // preserve one side faithfully while leaving the other side
                // unmaterialised; a later binding may complete that pair from
                // the same customer wording.  Do not let the server select
                // the missing period or call the partial carrier a complete
                // instruction.  It remains a model candidate and therefore
                // reaches independent semantic admission.
                if ($field==='periods' && self::incompleteComparisonPeriods($requirements,$intent,$periods)) {
                    $out[$field]=['source'=>'binding_candidate','requirements'=>$ids];
                    continue;
                }
                $out[$field]=['source'=>'customer','requirements'=>$ids];
                continue;
            }
            if ($hasPrior && self::inherited($field,$delta)) {
                $out[$field]=['source'=>'context','requirements'=>[]];
                continue;
            }
            if (!self::neutralDefault($field,$intent,$ranking,$periods,$scope,$objectRelation,$aggregateCondition)) {
                // The first model may preserve the business goal without
                // materialising every executable carrier. The binding model
                // may propose a response form, object or period from the
                // same customer sentence, but it is never trusted merely for
                // being well formed: requiresSemanticBindingReview() routes
                // this candidate through an independent natural-language
                // admission before execution.
                $out[$field]=['source'=>'binding_candidate','requirements'=>[]];
                continue;
            }
            $out[$field]=['source'=>'system','requirements'=>[]];
        }
        // For a continuation the raw candidate can intentionally be empty;
        // execution, however, uses the signed inherited metric.  Coverage must
        // therefore be checked against the effective query, never the raw
        // model fragment, otherwise a new unbound requirement could disappear.
        // A pending context decision is not executable. It may carry an
        // unbound explanation solely so the server can present clarification;
        // the same requirement is blocked again when the decision is resolved.
        $hasPending=$delta!==null && in_array('pending',$delta,true);
        $executable=$effectiveMetricCodes!==[]&&!$intent['needs_metric_choice']&&!$hasPending;
        if ($executable && array_diff(array_keys($known),array_keys($used))) self::fail('missing_requirement_coverage');
        return $out;
    }

    /**
     * Checks the executable candidate against the accepted understanding.
     * This compares data structure, never a customer phrase against a local
     * synonym list: natural-language meaning belongs to the two model stages,
     * while the server keeps the registered capability/authority boundary.
     */
    public static function assertEffectiveRequirementValues(array $understanding,array $intent,?string $referenceDate=null): void
    {
        self::assertRequirementValues(
            $understanding,
            $intent,
            (array)($intent['ranking']??['direction'=>'unspecified','limit'=>null]),
            (array)($intent['periods']??[]),
            (string)($intent['scope']??'unspecified'),
            (string)($intent['object_relation']??'analysis'),
            is_array($intent['aggregate_condition']??null)?$intent['aggregate_condition']:null,
            null,
            null,
            $referenceDate
        );
        self::requirementBindings(
            $intent['requirement_bindings']??null,
            AiIntentUnderstandingContract::requirements($understanding),
            (array)($intent['metric_codes']??[]),
            (array)($intent['metric_codes']??[]),
            (bool)($intent['initial_observation']??false)
        );
    }

    /** A second, independent model pass reviews a proposed metric binding.
     * It is only an admission check: it cannot add codes, conditions or data. */
    public static function requiresSemanticBindingReview(array $understanding,array $intent): bool
    {
        // Replacing a signed query restriction is a semantic decision even
        // when no metric is being selected. A cleared store range is also a
        // material semantic decision: the server has structural evidence for
        // the current turn, while this independent pass confirms that the
        // evidence actually expresses the requested wider scope.
        // The contract can verify that a current evidence excerpt exists, but
        // only the independent reviewer can decide whether that excerpt
        // actually expresses a replacement object or store target.
        $delta=$intent['context_delta']??null;
        if (is_array($delta) && (
            in_array(($delta['store_scope']??null),['replace','clear'],true)
            || in_array($delta['business_filters']??null,['clear','replace'],true)
        )) return true;
        // A result reference narrows the authorized query just as a selected
        // metric does. Its natural-language support is reviewed independently
        // before the server resolves the row in its private snapshot.
        if (($intent['result_reference'] ?? null) !== null) return true;
        // A binding-only field is a semantic proposal rather than a system
        // default. Let the independent reviewer decide whether the customer
        // actually expressed it; PHP must not turn a missing first-pass
        // carrier into either a rejected request or an inferred condition.
        foreach ((array)($intent['provenance']??[]) as $record) {
            if (($record['source']??null)==='binding_candidate') return true;
        }
        if (($intent['recommended_initial_answer']??false)) return true;
        if (($intent['needs_metric_choice']??false) || empty($intent['metric_codes'])) return false;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (in_array('metric_codes',(array)($requirement['fields']??[]),true)) return true;
        }
        return false;
    }

    /**
     * A deferred metric choice is not a generic review outcome. It is allowed
     * only for one new, positive measurement whose meaning has not yet been
     * narrowed by another metric requirement (notably an exclusion).
     */
    public static function canDeferMetricChoice(array $understanding,array $intent,bool $hasPriorQuery): bool
    {
        // A new, evidence-backed measurement remains eligible for an
        // independent candidate-blind admission even in a conversation. The
        // prior-query flag alone is not customer meaning: prohibiting this
        // review let a stale metric pass a weaker candidate-aware review.
        if (($intent['needs_metric_choice']??false) || count((array)($intent['metric_codes']??[]))!==1) return false;
        return self::canUseCandidateBlindMetricReview($understanding,$intent);
    }

    /**
     * A candidate-blind model pass is permitted for one explicit, current
     * measurement requirement even when the first binding declined to select
     * a metric.  It is a semantic classification over registry descriptions,
     * not a server-side vocabulary or metric-selection rule.  The pass can
     * still return ambiguous or unavailable; only a model-unique result may
     * become a bounded executable metric code.
     */
    public static function canUseCandidateBlindMetricReview(array $understanding,array $intent): bool
    {
        if (($intent['initial_observation']??false) || ($intent['recommended_initial_answer']??false)
            || count((array)($intent['metric_codes']??[]))>1) return false;
        $requirements=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
            if (!empty($requirement['values']['metric_exclusions'])) return false;
            $current=false;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current') {$current=true;break;}
            }
            // A mixed historical/current metric bundle is not a single new
            // measurement. Keep it on the normal context-review path rather
            // than letting a blind pass silently reinterpret old meaning.
            if (!$current) return false;
            $requirements[]=$requirement;
        }
        return $requirements!==[];
    }

    /** The accepted understanding is the sole source of this distinction. */
    private static function hasCurrentMetricRequirement(array $understanding): bool
    {
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current') return true;
            }
        }
        return false;
    }

    /** A clear but unbound requirement is a capability gap, never a retryable
     * model-format error and never permission to execute a reduced query. */
    public static function hasUnboundRequirement(array $understanding): bool
    {
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (in_array('unbound',(array)($requirement['fields']??[]),true)) return true;
        }
        return false;
    }

    /**
     * A clear store-level summary can arrive at the registry boundary with no
     * candidate at all. This is neither a permission to select a convenient
     * metric nor proof that the customer is unclear: the model owns both of
     * those decisions. It only permits one fenced re-bind request, where the
     * model receives the same understanding and registry descriptions again.
     */
    public static function requiresEmptyRegisteredBindingRecovery(array $understanding,array $intent): bool
    {
        if (($understanding['status']??null)!=='understood' || self::hasUnboundRequirement($understanding)) return false;
        if (($intent['object_kind']??null)!=='store' || ($intent['operation']??null)!=='summary'
            || !empty($intent['metric_codes']) || !empty($intent['needs_metric_choice'])
            || !empty($intent['unresolved_fragments'])) return false;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (in_array('metric_codes',(array)($requirement['fields']??[]),true)) return true;
        }
        return false;
    }

    public static function semanticReviewInstruction(): string
    {
        // The candidate-blind uniqueness pass has its own, deliberately
        // narrower `unique|ambiguous|unavailable` contract.  This coverage
        // reviewer must only admit or reject the binding it was given: asking
        // it to create a third decision here used to produce a value the
        // gateway correctly could not execute, wasting a model round.
        return 'Return exactly {"decision":"accept|reject","rejected_requirement_ids":["rN"]}. '
            .'Independently compare the accepted understanding with the proposed binding and supplied registered capability descriptions. '
            .'Evaluate only requirements that this turn newly adds, replaces or clears. A signed condition inherited unchanged from the prior query is not a new customer requirement and must neither require a new wording nor be changed. '
            .'Accept only if every evaluated customer requirement represented by the proposed binding is faithfully expressed by the current customer question. '
            .'When candidate_binding.initial_observation is true, accept it for a first answer only if the accepted understanding is an open overall operating goal with no specific customer-stated measurement, exclusion, comparison, ranking or object-specific condition that the observation set would replace. A broad everyday evaluation can remain an open operating goal even when the understanding protocol carries a generic metric requirement; decide from the ordinary customer wording whether it names a particular business fact, accounting basis or measurement, rather than treating that carrier by itself as a specific metric. A different case is a verified prior query whose presentation_origin is platform_observation: its metric_codes are one previously presented overview group, not alternatives. If the current accepted understanding only continues that group while changing a contextual condition, accept the complete inherited group; reject only when the current wording adds a conflicting measurement, exclusion, comparison, ranking, object condition or scope change. '
            .'When candidate_binding.recommended_initial_answer is true, review the proposed recommendation as a candidate rather than as a customer-selected metric. Accept a compatible registered metric when it is a reasonable professional first reading of the broad customer goal, object and response form. A broad everyday evaluation without a named accounting basis is not by itself a conflict. Reject only when the candidate changes a stated exclusion, range, ranking, object condition or other explicit requirement, and name the conflicting requirement ids. '
            .'When the customer asks to identify which comparable person, store, member, project or other object is doing best, leading or weakest, the binding must preserve that comparative result: it needs a ranking response with the requested direction, and must not substitute an aggregate summary. '
            .'Reject when a selected metric or result reference substitutes, reverses, ignores or conflicts with any such requirement, including an exclusion. '
            .'When a binding replaces or clears a signed store range, or changes an object filter, accept only if the current customer question itself expresses that exact change. A confirmed request for the authorized range may execute directly, but never expands authority beyond the current Reader permission. '
            .'An analytical object and a selected object have different meanings. Accept object_relation=analysis only when the customer is asking to compare, group or list that kind; accept object_relation=selection only when the customer identifies a particular target whose records must narrow the query. Reject a candidate that turns one into the other. '
            .'The accepted understanding may preserve a clear business goal without materialising every response-form carrier. When candidate binding adds an object kind, response form, period, ranking or scope that has no typed first-pass carrier, decide from the current customer question itself whether that exact candidate is faithful. Do not reject only because the first pass omitted that carrier; reject any candidate that adds, replaces or narrows a condition the customer did not express. '
            .'An excerpt that expresses only a date, ranking, metric or another condition does not support a scope expansion merely because it appears in the same requirement. '
            .'A result reference is valid only when the current question itself asks for the displayed ranked result; it must never be inferred from prior conversation. '
            .'Do not invent requirements, metric codes, conditions, identities, dates, formulas, results or explanations. '
            .'Accept uses an empty rejected_requirement_ids array. A reject names every rejected requirement id.';
    }

    public static function semanticUniquenessInstruction(): string
    {
        return 'Evaluate the exact business event or measurement requested in ordinary language, using only the supplied registered descriptions. '
            .'A concrete transaction or activity is not interchangeable with other measurements merely because they involve money or performance. '
            .'A broad outcome without an identified event or accounting basis can represent multiple distinct metrics and must not be assigned one by default. '
            .'Do not see or assume the proposed candidate. Return exactly {"decision":"unique|ambiguous|unavailable","metric_code":"registered code or empty string"}. '
            .'Use unique only when one registered metric faithfully answers the expressed fact; for ambiguous or unavailable, metric_code is empty. Do not answer with figures.';
    }

    public static function normalizeSemanticReview($value,array $understanding): array
    {
        $value=self::native($value);
        $keys=is_array($value)?array_keys($value):[];sort($keys,SORT_STRING);
        if ($keys!==['decision','rejected_requirement_ids'] || !in_array($value['decision']??null,['accept','reject','metric_choice'],true)
            || !is_array($value['rejected_requirement_ids']??null) || count($value['rejected_requirement_ids'])>12
            || ($value['rejected_requirement_ids']!==[] && array_keys($value['rejected_requirement_ids'])!==range(0,count($value['rejected_requirement_ids'])-1))
            || count(array_unique($value['rejected_requirement_ids']))!==count($value['rejected_requirement_ids'])) {
            self::fail('bad_value:semantic_review');
        }
        $known=AiIntentUnderstandingContract::requirements($understanding);
        foreach($value['rejected_requirement_ids'] as $id) {
            if (!is_string($id) || !isset($known[$id])) self::fail('bad_value:semantic_review');
        }
        if (in_array($value['decision'],['accept','metric_choice'],true) && $value['rejected_requirement_ids']!==[]) self::fail('bad_value:semantic_review');
        if ($value['decision']==='reject' && $value['rejected_requirement_ids']===[]) self::fail('bad_value:semantic_review');
        return ['decision'=>$value['decision'],'rejected_requirement_ids'=>array_values($value['rejected_requirement_ids'])];
    }

    public static function normalizeSemanticUniqueness($value,array $allowedCodes): array
    {
        $value=self::native($value);
        $keys=is_array($value)?array_keys($value):[];sort($keys,SORT_STRING);
        if ($keys!==['decision','metric_code'] || !in_array($value['decision']??null,['unique','ambiguous','unavailable'],true)
            || !is_string($value['metric_code']??null)
            || (($value['decision']==='unique') !== ($value['metric_code']!==''))
            || ($value['metric_code']!=='' && !in_array($value['metric_code'],$allowedCodes,true))) self::fail('bad_value:semantic_uniqueness');
        return $value;
    }

    private static function assertRequirementValues(array $understanding,array $intent,array $ranking,array $periods,string $scope,string $objectRelation,?array $aggregateCondition,?array $delta,?array $priorQuery,?string $referenceDate=null): void
    {
        $requirements=AiIntentUnderstandingContract::requirements($understanding);
        // All production understanding objects are normalized and therefore
        // carry `values`. The guard keeps isolated legacy fixtures usable while
        // never weakening a value-bearing production request.
        $hasValues=false;foreach($requirements as $requirement) if (($requirement['values']??[])!==[]) {$hasValues=true;break;}
        if (!$hasValues) return;
        // `inherit` is valid only when the value that the customer stated is
        // already exactly present in the verified prior query. This check runs
        // before the unbound early return: an empty candidate can otherwise
        // inherit a prior metric later in the gateway too.
        foreach($requirements as $requirement) foreach((array)($requirement['values']??[]) as $field=>$value) {
            if ($delta===null) continue;
            if ($field==='ranking') {
                $priorRanking=(array)($priorQuery['ranking']??[]);
                foreach(['direction'=>'ranking_direction','limit'=>'ranking_limit'] as $key=>$deltaField) {
                    if (($delta[$deltaField]??null)==='inherit'
                        && !self::equivalent($value[$key]??null,$priorRanking[$key]??null)) {
                        self::fail('binding_requirement_delta_mismatch:ranking');
                    }
                }
                continue;
            }
            // Metric words are deliberately not compared to a previous query
            // here. They are natural-language meaning, and the binding model
            // accounts for every such requirement through requirement_bindings.
            $deltaField=['object_kind'=>'object','operation'=>'operation','periods'=>'periods','scope'=>'scope','aggregate_condition'=>'aggregate_condition'][$field]??null;
            $same=$field==='periods'
                ? self::equivalentPeriods($value,self::priorValue($field,$priorQuery),$referenceDate)
                : self::equivalent($value,self::priorValue($field,$priorQuery));
            if ($deltaField!==null && ($delta[$deltaField]??null)==='inherit' && !$same) {
                self::fail('binding_requirement_delta_mismatch:'.$field);
            }
        }
        foreach($requirements as $requirement) {
            $values=(array)($requirement['values']??[]);
            foreach(['object_kind','object_relation','operation','periods','ranking','scope','aggregate_condition'] as $field) {
                if (!array_key_exists($field,$values)) continue;
                if ($delta!==null && !self::requiresCandidateValueCheck($field,$delta)) continue;
                $actual=$field==='ranking'?$ranking:($field==='periods'?$periods:($field==='scope'?$scope:($field==='aggregate_condition'?$aggregateCondition:($field==='object_relation'?$objectRelation:$intent[$field]))));
                if ($field==='periods' && self::incompleteComparisonPeriods($requirements,$intent,$actual)) continue;
                if ($field==='ranking' && $delta!==null) {
                    foreach(['direction'=>'ranking_direction','limit'=>'ranking_limit'] as $key=>$deltaField) {
                        if (($delta[$deltaField]??null)==='replace'
                            && !self::equivalent($actual[$key]??null,$values[$field][$key]??null)) {
                            self::fail('binding_requirement_value_mismatch:ranking');
                        }
                    }
                    continue;
                }
                $same=$field==='periods'
                    ? self::equivalentPeriods($actual,$values[$field],$referenceDate)
                    : self::equivalent($actual,$values[$field]);
                if (!$same) self::fail('binding_requirement_value_mismatch:'.$field);
            }
        }
    }

    /**
     * Carry one accepted, model-authored structured semantic value across the
     * binding boundary. Multiple different values for one field deliberately
     * remain unanchored: they need model clarification rather than a PHP tie
     * break. The method never derives a value from customer text.
     */
    private static function anchorUnderstandingValues(array $understanding,array $intent,array $ranking,array $periods,string $scope,string $objectRelation,?array $aggregateCondition,bool $rankingSupplied,bool $periodsSupplied,bool $scopeSupplied,bool $aggregateConditionSupplied): array
    {
        $values=[];$scopeExpressed=false;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (in_array('scope',(array)($requirement['fields']??[]),true)) $scopeExpressed=true;
            foreach (['object_kind','object_relation','operation','periods','ranking','scope','aggregate_condition'] as $field) {
                if (!array_key_exists($field,(array)($requirement['values']??[]))) continue;
                $candidate=$requirement['values'][$field];
                if (!isset($values[$field])) {$values[$field]=['value'=>$candidate,'conflict'=>false];continue;}
                if (!self::equivalent($values[$field]['value'],$candidate)) $values[$field]['conflict']=true;
            }
        }
        foreach ($values as $field=>$record) {
            if ($record['conflict']) continue;
            $candidate=$record['value'];
            if ($field==='object_kind') $intent['object_kind']=$candidate;
            elseif ($field==='object_relation') $objectRelation=$candidate;
            elseif ($field==='operation') $intent['operation']=$candidate;
            elseif ($field==='periods') {
                // A partial understanding carrier must not overwrite a
                // model-completed comparison pair.  This is structural
                // completeness only: PHP never derives either date and the
                // independent semantic reviewer still admits the added side.
                if (!(($intent['operation']??null)==='comparison' && count((array)$candidate)<2 && count($periods)===2)) {
                    $periods=$candidate;$periodsSupplied=true;
                }
            }
            elseif ($field==='ranking') {$ranking=$candidate;$rankingSupplied=true;}
            elseif ($field==='scope') {$scope=$candidate;$scopeSupplied=true;}
            elseif ($field==='aggregate_condition') {$aggregateCondition=$candidate;$aggregateConditionSupplied=true;}
        }
        // The data range belongs to the authenticated person, not to the
        // analytical object or application entry. A binding candidate cannot
        // turn “which store” into “only the current store”; without an
        // understood scope condition, use the server-owned authorized range.
        // If the first pass did preserve a scope requirement but its typed
        // carrier is missing or conflicting, keep the candidate for semantic
        // review rather than silently broadening the customer request.
        if (!$scopeExpressed) {$scope='unspecified';$scopeSupplied=false;}
        return ['intent'=>$intent,'ranking'=>$ranking,'periods'=>$periods,'scope'=>$scope,'object_relation'=>$objectRelation,'aggregate_condition'=>$aggregateCondition,
            'ranking_supplied'=>$rankingSupplied,'periods_supplied'=>$periodsSupplied,'scope_supplied'=>$scopeSupplied,'aggregate_condition_supplied'=>$aggregateConditionSupplied];
    }

    /**
     * The semantic stage may be used as an anchor only when it states one
     * unambiguous, protocol-valid relation.  This deliberately does not
     * infer a relation from object text or from the binding response.
     */
    private static function singleUnderstoodObjectRelation(array $understanding): ?string
    {
        $relation=null;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            $candidate=$requirement['values']['object_relation']??null;
            if (!in_array($candidate,['analysis','selection'],true)) continue;
            if ($relation===null) {$relation=$candidate;continue;}
            if ($relation!==$candidate) return null;
        }
        return $relation;
    }

    /**
     * Before context merge, only a changed structural component can be
     * compared with the raw candidate. Inherited components are intentionally
     * compared after IntentContextMerger has restored their signed values.
     */
    private static function requiresCandidateValueCheck(string $field,array $delta): bool
    {
        if ($field==='ranking') return $delta['ranking_direction']==='replace'||$delta['ranking_limit']==='replace';
        $deltaField=['object_kind'=>'object','operation'=>'operation','periods'=>'periods','scope'=>'scope','aggregate_condition'=>'aggregate_condition'][$field]??null;
        if ($deltaField===null) return true;
        $action=$delta[$deltaField]??null;
        if ($action==='replace') return true;
        // clear and pending do not provide a raw candidate to compare. They
        // are held by the merger and the controlled clarification flow; any
        // eventual executable value is checked after that flow completes.
        return false;
    }

    /**
     * The model is accountable for binding every metric-related requirement it
     * understood. This is intentionally a structural coverage check, never a
     * server-side comparison between customer words and metric aliases.
     */
    private static function requirementBindings($value,array $requirements,array $allowedCodes,array $effectiveCodes,bool $initialObservation=false): array
    {
        if (!is_array($value) || count($value)>12 || ($value!==[]&&array_keys($value)!==range(0,count($value)-1))) self::fail('bad_value:requirement_bindings');
        $metricRequirementIds=[];
        foreach($requirements as $id=>$requirement) if (in_array('metric_codes',(array)($requirement['fields']??[]),true)) $metricRequirementIds[]=$id;
        // A multi-angle initial observation is explicitly labelled as a
        // platform-proposed first answer. Its generic operating-goal carrier
        // establishes the topic, but does not make any selected observation
        // metric a customer requirement. Do not make the model invent audit
        // rows just to satisfy a structural rule; semantic admission still
        // verifies that this presentation shape is appropriate.
        if ($initialObservation && $value===[]) return [];
        if ($metricRequirementIds===[]) {
            $value=self::removeRedundantNonRequirementBindings($value,$requirements,$allowedCodes);
        }
        // The model sometimes emits an audit row for a period or object even
        // though that requirement has no metric binding. A row with no metric
        // codes contributes nothing to this table. Its actual period/object
        // value is still checked independently by assertRequirementValues.
        // Never discard a row carrying a code or an unknown requirement ID.
        $value=array_values(array_filter($value,static function($row)use($requirements,$metricRequirementIds):bool {
            return !(is_array($row) && is_string($row['requirement_id']??null)
                && isset($requirements[$row['requirement_id']])
                && !in_array($row['requirement_id'],$metricRequirementIds,true)
                && ($row['metric_codes']??null)===[]);
        }));
        // A pure structural follow-up (for example “this month instead”) may
        // legitimately inherit its already verified metric without restating
        // any metric requirement. It therefore has no binding rows to audit.
        if ($metricRequirementIds===[]) {
            if ($value!==[]) self::fail('unexpected_requirement_binding');
            return [];
        }
        $seen=[];$out=[];$allCodes=[];
        foreach($value as $row) {
            $keys=is_array($row)?array_keys($row):[];sort($keys,SORT_STRING);
            if ($keys!==['metric_codes','requirement_id','status']) self::fail('binding_row_shape');
            // With exactly one metric requirement, the row identifier is
            // bookkeeping: the accepted understanding already owns its ID.
            // Correcting that ID cannot choose a metric or change meaning.
            // The selected code still has to pass the independent semantic
            // binding review before execution. Multiple requirements must
            // retain the model's explicit one-to-one assignment.
            if (count($metricRequirementIds)===1 && count($value)===1
                && ($row['status']??null)==='satisfied' && self::codes($row['metric_codes']??null)
                && $row['metric_codes']!==[] && self::sameCodeSet($row['metric_codes'],$effectiveCodes)) {
                $row['requirement_id']=$metricRequirementIds[0];
            }
            if (!is_string($row['requirement_id']) || !in_array($row['requirement_id'],$metricRequirementIds,true)
                || isset($seen[$row['requirement_id']])) {
                throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
                    'stage'=>'intent_contract','predicate'=>'binding_row_id',
                    'metric_requirement_count'=>count($metricRequirementIds),
                    'binding_row_count'=>count($value),
                    'selected_code_count'=>count($effectiveCodes),
                    'row_code_count'=>is_array($row['metric_codes']??null)?count($row['metric_codes']):0,
                    'row_status'=>in_array($row['status']??null,['satisfied','unavailable','pending'],true)?$row['status']:'invalid',
                ]);
            }
            if (!in_array($row['status'],['satisfied','unavailable','pending'],true)) self::fail('binding_row_status');
            if (!self::codes($row['metric_codes'])) self::fail('binding_row_codes');
            foreach($row['metric_codes'] as $code) if (!in_array($code,$allowedCodes,true)) self::fail('binding_requirement_metric_unknown');
            if ($row['status']==='satisfied' && $row['metric_codes']===[]) self::fail('binding_requirement_unsatisfied');
            if ($row['status']!=='satisfied' && $row['metric_codes']!==[]) self::fail('binding_requirement_status_conflict');
            $seen[$row['requirement_id']]=true;$out[]=['requirement_id'=>$row['requirement_id'],'status'=>$row['status'],'metric_codes'=>$row['metric_codes']];
            $allCodes=array_merge($allCodes,$row['metric_codes']);
        }
        sort($metricRequirementIds,SORT_STRING);$actualIds=array_keys($seen);sort($actualIds,SORT_STRING);
        if ($actualIds!==$metricRequirementIds) self::fail('missing_requirement_binding');
        $allCodes=array_values(array_unique($allCodes));sort($allCodes,SORT_STRING);
        $effective=array_values(array_unique($effectiveCodes));sort($effective,SORT_STRING);
        if ($effective===[]) {
            foreach($out as $row) if ($row['status']==='satisfied') self::fail('binding_requirement_without_metric');
        } else {
            foreach($out as $row) if ($row['status']!=='satisfied') self::fail('binding_requirement_unsatisfied');
            if ($allCodes!==$effective) self::fail('binding_requirement_metric_mismatch');
        }
        return $out;
    }

    /**
     * A binding can carry a metric already accounted for elsewhere: a metric
     * candidate from the binding response. Some providers attach it to an
     * object or ranking requirement even though the accepted current
     * understanding has no metric requirement. That is bookkeeping, not new
     * customer meaning. The candidate itself still goes through the
     * independent semantic review. Remove only complete, known, unique,
     * registered-code rows; malformed or invented accountability data remains
     * a strict rejection.
     */
    private static function removeRedundantNonRequirementBindings(array $rows,array $requirements,array $allowedCodes): array
    {
        if ($rows===[]) return [];
        $seen=[];
        foreach ($rows as $row) {
            $keys=is_array($row)?array_keys($row):[];sort($keys,SORT_STRING);
            if ($keys!==['metric_codes','requirement_id','status']) self::fail('binding_row_shape');
            $id=$row['requirement_id']??null;
            if (!is_string($id) || !isset($requirements[$id]) || isset($seen[$id])) self::fail('binding_row_id');
            $seen[$id]=true;
            if (($row['status']??null)!=='satisfied') self::fail('binding_row_status');
            if (!self::codes($row['metric_codes']??null) || $row['metric_codes']===[]) self::fail('binding_row_codes');
            foreach ($row['metric_codes'] as $code) if (!in_array($code,$allowedCodes,true)) self::fail('binding_requirement_metric_unknown');
        }
        return [];
    }

    private static function effectiveMetricCodes(array $candidate,?array $delta,?array $priorQuery): array
    {
        if ($delta===null || $delta['metric_codes']==='replace') return $candidate;
        if ($delta['metric_codes']==='inherit') return (array)($priorQuery['metric_codes']??[]);
        return [];
    }

    private static function sameCodeSet(array $left,array $right): bool
    {
        $left=array_values(array_unique($left));$right=array_values(array_unique($right));
        sort($left,SORT_STRING);sort($right,SORT_STRING);
        return $left===$right;
    }

    /** JSON object member order has no semantic meaning; ordered arrays do. */
    private static function equivalent($left,$right): bool
    {
        if (!is_array($left) || !is_array($right)) return $left===$right;
        if (self::list($left)!==self::list($right) || count($left)!==count($right)) return false;
        if (self::list($left)) {
            foreach($left as $index=>$value) if(!self::equivalent($value,$right[$index])) return false;
        } else {
            $leftKeys=array_keys($left);$rightKeys=array_keys($right);sort($leftKeys,SORT_STRING);sort($rightKeys,SORT_STRING);
            if ($leftKeys!==$rightKeys) return false;
            foreach($leftKeys as $key) if(!self::equivalent($left[$key],$right[$key])) return false;
        }
        return true;
    }

    /** Compatible with supported PHP runtimes that predate array_is_list(). */
    private static function list(array $value): bool
    {
        return $value===[] || array_keys($value)===range(0,count($value)-1);
    }

    private static function priorValue(string $field,?array $priorQuery)
    {
        if ($priorQuery===null) return null;
        if ($field==='object_kind') return $priorQuery['object_kind']??'store';
        if ($field==='operation') return $priorQuery['operation']??null;
        if ($field==='periods') return $priorQuery['periods']??null;
        if ($field==='scope') return $priorQuery['scope']??'authorized';
        if ($field==='aggregate_condition') return $priorQuery['aggregate_condition']??null;
        // A model's natural-language metric phrase is deliberately not mapped
        // back to a code by the server. A newly stated metric phrase therefore
        // always needs an explicit binding decision.
        return null;
    }

    /**
     * Context stores the verified Reader range, while understanding preserves
     * the customer's time meaning.  Compare their calendar effect under the
     * server-owned reference date so "current calendar month" can faithfully
     * inherit an already verified month-to-date range.  This never interprets
     * customer words in PHP: it only evaluates the model's typed period form.
     */
    private static function equivalentPeriods($left,$right,?string $referenceDate): bool
    {
        if (self::equivalent($left,$right)) return true;
        if (!self::periods($left) || !self::periods($right) || !self::validDate((string)$referenceDate)) return false;
        $leftRanges=self::materializePeriods($left,$referenceDate);
        $rightRanges=self::materializePeriods($right,$referenceDate);
        return $leftRanges!==null && $rightRanges!==null && self::equivalent($leftRanges,$rightRanges);
    }

    /** @return array<int,array{start:string,end:string}>|null */
    private static function materializePeriods(array $periods,string $referenceDate): ?array
    {
        try {
            $reference=new \DateTimeImmutable($referenceDate,new \DateTimeZone('Asia/Shanghai'));
            $ranges=[];
            foreach ($periods as $period) {
                if ($period['kind']==='date_range') {
                    $ranges[]=['start'=>$period['start'],'end'=>$period['end']]; continue;
                }
                if ($period['kind']==='relative_days') {
                    $end=$reference->modify(((int)$period['end_offset_days']).' days');
                    $ranges[]=['start'=>$end->modify('-'.(((int)$period['days'])-1).' days')->format('Y-m-d'),'end'=>$end->format('Y-m-d')];
                    continue;
                }
                if ($period['kind']!=='month_offset') return null;
                $start=$reference->modify('first day of this month')->modify(((int)$period['offset_months']).' months');
                $ranges[]=['start'=>$start->format('Y-m-d'),'end'=>((int)$period['offset_months']===0?$reference:$start->modify('last day of this month'))->format('Y-m-d')];
            }
            return $ranges;
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    private static function inherited(string $field,?array $delta): bool
    {
        if ($delta===null) return false;
        $key=['metric_codes'=>'metric_codes','object_kind'=>'object','operation'=>'operation','periods'=>'periods','ranking'=>'ranking_direction','scope'=>'scope','aggregate_condition'=>'aggregate_condition'][$field]??null;
        if ($key===null || $delta[$key]!=='inherit') return false;
        return $field!=='ranking' || $delta['ranking_limit']==='inherit';
    }

    private static function neutralDefault(string $field,array $intent,array $ranking,array $periods,string $scope,string $objectRelation,?array $aggregateCondition): bool
    {
        if ($field==='object_kind') return in_array($intent['object_kind'],['store','unknown'],true)&&$intent['object_term']==='';
        if ($field==='object_relation') return $objectRelation==='analysis';
        if ($field==='metric_codes') return $intent['metric_codes']===[];
        if ($field==='operation') return in_array($intent['operation'],['summary','unknown'],true);
        if ($field==='ranking') return $ranking['direction']==='unspecified'&&$ranking['limit']===null;
        if ($field==='periods') return $periods===[];
        if ($field==='aggregate_condition') return $aggregateCondition===null;
        return $scope==='unspecified'||$scope==='authorized';
    }

    /** A comparison carrier is incomplete until both customer-stated periods exist. */
    private static function incompleteComparisonPeriods(array $requirements,array $intent,array $periods): bool
    {
        if (($intent['operation']??null)!=='comparison' || count($periods)!==2) return false;
        foreach ($requirements as $requirement) {
            $value=$requirement['values']['periods']??null;
            if (is_array($value) && count($value)<2) return true;
        }
        return false;
    }

    private static function resultReference($value,bool $hasPrior): ?array
    {
        if ($value===null) return null;
        $keys=is_array($value)?array_keys($value):[];sort($keys,SORT_STRING);
        if (!$hasPrior || $keys!==['group','ordinal'] || !is_int($value['ordinal']??null)||$value['ordinal']<1||$value['ordinal']>1000
            || !in_array($value['group']??null,['top','bottom'],true)) self::fail('bad_value:result_reference');
        return ['group'=>$value['group'],'ordinal'=>$value['ordinal']];
    }

    /** A result row may narrow a query only when the accepted request explicitly asked for that row. */
    private static function assertResultReferenceRequirement(?array $reference,array $understanding): void
    {
        $requested=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('result_reference',(array)($requirement['fields']??[]),true)) continue;
            $value=$requirement['values']['result_reference']??null;
            if (!is_array($value)) self::fail('binding_requirement_value_mismatch:result_reference');
            $requested[]=$value;
        }
        if ($reference===null) {
            if ($requested!==[]) self::fail('binding_requirement_value_mismatch:result_reference');
            return;
        }
        if (count($requested)!==1 || !self::equivalent($reference,$requested[0])) {
            self::fail('binding_requirement_value_mismatch:result_reference');
        }
    }

    private static function codes($values): bool { if (!is_array($values)||count($values)>8||count(array_unique($values))!==count($values)) return false;foreach($values as $v)if(!is_string($v))return false;return true; }
    public static function periods($periods): bool
    {
        if (!is_array($periods)||count($periods)>2||($periods!==[]&&array_keys($periods)!==range(0,count($periods)-1))) return false;
        foreach ($periods as $p) { if(!is_array($p)||!is_string($p['kind']??null))return false;$keys=array_keys($p);sort($keys,SORT_STRING);
            if($p['kind']==='date_range'){if($keys!==['end','kind','start']||!is_string($p['start'])||!is_string($p['end'])||!self::validDate($p['start'])||!self::validDate($p['end'])||$p['start']>$p['end'])return false;}
            elseif($p['kind']==='relative_days'){if($keys!==['days','end_offset_days','kind']||!is_int($p['end_offset_days'])||!is_int($p['days'])||$p['days']<1)return false;}
            elseif($p['kind']==='month_offset'){if($keys!==['kind','offset_months']||!is_int($p['offset_months']))return false;} else return false;
        } return true;
    }
    private static function aggregateCondition($value): bool
    {
        $keys=is_array($value)?array_keys($value):[];sort($keys,SORT_STRING);
        return $keys===['aggregation','amount_cents','operator','subject']
            && ($value['subject']??null)==='member' && ($value['aggregation']??null)==='period_total'
            && in_array($value['operator']??null,['gte','gt','lte','lt','eq'],true)
            && is_int($value['amount_cents']??null) && $value['amount_cents']>0
            && $value['amount_cents']<=100000000000;
    }
    private static function validDate(string $value): bool { if(!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D',$value))return false;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Asia/Shanghai'));return $date!==false&&$date->format('Y-m-d')===$value; }
    private static function contained(string $value,array $texts): bool {foreach($texts as $text)if(is_string($text)&&mb_strpos($text,$value,0,'UTF-8')!==false)return true;return false;}
    private static function fail(string $predicate): void {throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_contract','predicate'=>$predicate]);}
}
