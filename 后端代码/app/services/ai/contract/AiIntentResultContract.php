<?php
namespace app\services\ai\contract;

/**
 * The model may understand prose, but it can only cross this boundary as a
 * bounded semantic candidate. Follow-ups use an explicit delta: absent is
 * never silently interpreted as “keep the old value”.
 */
final class AiIntentResultContract
{
    const VERSION='intent-binding-v3';
    const REQUIRED_FIELDS=['action_codes','metric_codes','needs_metric_choice','object_kind','object_term','operation','requirement_bindings','unresolved_fragments'];
    const DELTA_FIELDS=['metric_codes','object','business_filters','store_scope','periods','operation','ranking_direction','ranking_limit','scope'];
    const DELTA_ACTIONS=['inherit','replace','clear','pending'];
    const PROVENANCE_FIELDS=['metric_codes','object_kind','operation','periods','ranking','scope'];

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
        // A binding that marks a customer-supplied value as inherited would
        // otherwise let the merger execute the previous query.  It is a
        // bounded, model-authored delta correction, not a request for the
        // server to fill in business meaning.
        if (is_string($predicate) && strpos($predicate,'binding_requirement_delta_mismatch:')===0) return true;
        return is_string($predicate) && strpos($predicate,'missing_key:')===0
            && in_array(substr($predicate,12),array_merge(self::REQUIRED_FIELDS,['context_delta']),true);
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
            ? 'A verified prior query exists. Include context_delta with exactly these keys: '.implode(', ',self::DELTA_FIELDS).'. Each value is one of inherit, replace, clear or pending. Use inherit only when the current wording leaves that exact meaning unchanged. Use replace only when current wording supplies a new meaning in the matching ordinary field. Use clear only when the customer explicitly removes a condition. Use pending when clarification is needed. Never omit a delta key and never infer inherit from an omitted field. A short continuation can replace just the analytical object while retaining the verified period, response form, ranking direction, ranking quantity, scope and other unchanged meaning. Set store_scope to clear only when the current request explicitly asks for the authorized/all-store range and supplies scope=authorized; set store_scope to replace only when the current request identifies a store object; set business_filters to clear or replace only when the current request identifies the changed object scope. When that new object cannot legally use the previous metric under capabilities.object_contracts, mark metric_codes pending rather than returning operation unknown or an unresolved fragment; the server will present only registered choices.'
            : 'No verified prior query exists. Do not include context_delta.';
        return 'Return one JSON object following '.self::VERSION.'. Required keys: '.implode(', ',self::REQUIRED_FIELDS).'. Optional keys: ranking, periods, scope, result_reference, initial_observation, recommended_initial_answer'.($hasPriorQuery?', context_delta':'').'. '.$context.' object_term is an exact customer term or an empty string where no named object is needed. needs_metric_choice is true only when the intended business measurement is genuinely ambiguous and no professionally useful first reading can be selected; then metric_codes must be empty. A customer may instead ask for a broad overall operating view without naming one measurement. When the accepted understanding is such an open operating goal, no explicit customer condition conflicts with it, and operation is summary, set initial_observation=true and select two to four independent supplied store metrics as observation angles for an initial answer. Those codes are professional observations, not a claim that the customer selected one metric; do not use this exception for a named measurement, exclusion, comparison, ranking, or object-specific request. When the customer has made the analytical object and response form clear but the business measurement has several registered readings, select the most useful compatible professional first reading from the supplied capabilities. Only a summary can intentionally return multiple independent reading angles; a ranking must set metric_codes to exactly one registered code because one ranked result needs one comparable measurement. Set recommended_initial_answer=true, needs_metric_choice=false and label the resulting metric perspective in the answer, so the customer can naturally pursue another perspective afterwards. Never set recommended_initial_answer for an unresolved meaning, an unavailable capability, a changed exclusion, or a result that needs customer confirmation. Set both initial_observation and recommended_initial_answer false or omit them for every other request. Lack of an available metric is not ambiguity. requirement_bindings is a required array with exactly one row for every accepted understanding requirement whose fields include metric_codes, and no other rows. Each row is exactly {"requirement_id":"rN","status":"satisfied|unavailable|pending","metric_codes":[registered codes]}. A satisfied row names the registered metric codes that fulfill that one requirement; together all satisfied rows must account for the selected metric_codes. An unavailable or pending row has an empty metric_codes array and therefore cannot accompany an executable metric selection. This is an accountability record for the accepted meaning, not a phrase matcher: do not decide it from word overlap, and do not omit an exclusion or another customer requirement. periods, when present, is an ordered array of up to two period objects. A period is exactly one of {"kind":"date_range","start":"YYYY-MM-DD","end":"YYYY-MM-DD"}, {"kind":"relative_days","days":positive-integer,"end_offset_days":signed-integer}, or {"kind":"month_offset","offset_months":signed-integer}. Preserve early, future and long periods exactly; execution coverage and length limits are checked by the server later, never reinterpret them as an invalid intent. A calendar-month meaning uses month_offset; relative_days is only for a stated rolling number of days. ranking is {"direction":"top|bottom|top_and_bottom|unspecified","limit":integer-or-null}. Scope and accessible stores are server-owned: omit scope unless current wording explicitly changes current-store versus authorized scope. metric_codes and action_codes are binding candidates inside their required arrays and only contain supplied codes. Do not output provenance: the server derives it from the accepted understanding fields and rejects a bound field that has no legitimate source. result_reference, if used, is only {"group":"top|bottom","ordinal":positive-integer}; emit it only when the accepted understanding has an equal result_reference requirement with current-question evidence. group means the rank section explicitly named by the customer and never contains an ID, name or result value. unresolved_fragments only contains exact current-question text whose meaning cannot be understood, not understood requests that lack a registered binding. Never calculate, query, invent a condition, discard a condition, or copy a previous result value.';
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
        foreach (self::REQUIRED_FIELDS as $key) if (!array_key_exists($key,$value)) self::fail('missing_key:'.$key);
        if ($hasPrior && !array_key_exists('context_delta',$value)) self::fail('missing_key:context_delta');
        // Accept provenance from older model prompts but never trust or emit it.
        // The server derives the authoritative audit record below.
        $allowed=array_merge(self::REQUIRED_FIELDS,['provenance','ranking','periods','scope','result_reference','initial_observation','recommended_initial_answer'],$hasPrior?['context_delta']:[]);sort($allowed,SORT_STRING);
        $keys=array_keys($value);sort($keys,SORT_STRING);
        if (array_diff($keys,$allowed)) self::fail('unknown_key');
        if (!$hasPrior && array_key_exists('context_delta',$value)) self::fail('unexpected_context_delta');
        if (!is_bool($value['needs_metric_choice'])) self::fail('bad_type:needs_metric_choice');
        if (!is_string($value['object_term']) || mb_strlen($value['object_term'],'UTF-8')>160) self::fail('bad_value:object_term');
        if (!in_array($value['object_kind'],['store','person','position','member','product','project','category','partner','inventory','course','organization','unknown'],true)) self::fail('bad_value:object_kind');
        if (!in_array($value['operation'],['summary','trend','ranking','comparison','definition','unknown'],true)) self::fail('bad_value:operation');
        if (!self::codes($value['metric_codes'])) self::fail('bad_value:metric_codes');
        foreach ($value['metric_codes'] as $code) if (!in_array($code,$metricCodes,true)) throw new AiContractException('AI_MODEL_METRIC_UNKNOWN',['stage'=>'intent_contract','predicate'=>'unknown_metric_code']);
        $initialObservation=$value['initial_observation']??false;
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
        // A model may emit its one selected candidate together with its old
        // "needs choice" marker.  This is a contradictory transport state,
        // not customer ambiguity.  For exactly one understood metric
        // requirement and exactly one model-selected registered candidate,
        // retain that model decision as a labelled recommended first answer.
        // The independent semantic reviewer still has to admit it before any
        // Reader execution; PHP neither maps words to a metric nor chooses a
        // candidate from a list.
        if ($value['needs_metric_choice'] && $value['metric_codes']!==[]) {
            $metricRequirementIds=[];
            foreach (AiIntentUnderstandingContract::requirements($understanding) as $id=>$requirement) {
                if (in_array('metric_codes',(array)($requirement['fields']??[]),true)) $metricRequirementIds[]=$id;
            }
            if ($initialObservation || count($value['metric_codes'])>4 || count($metricRequirementIds)!==1) self::fail('ambiguous_metric_codes_present');
            $value['needs_metric_choice']=false;
            $value['recommended_initial_answer']=true;
            if (is_array($value['requirement_bindings']??null) && count($value['requirement_bindings'])===1) {
                $row=&$value['requirement_bindings'][0];
                if (is_array($row) && ($row['status']??null)==='pending' && ($row['metric_codes']??null)===[]) {
                    $row['status']='satisfied';$row['metric_codes']=$value['metric_codes'];
                }
                unset($row);
            }
        }
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
        if ($initialObservation) {
            if (count($value['metric_codes'])<2 || count($value['metric_codes'])>4) self::fail('initial_observation_metric_count');
            if ($value['needs_metric_choice'] || $value['object_kind']!=='store' || $value['object_term']!=='' || $value['operation']!=='summary'
                || $ranking['direction']!=='unspecified' || $ranking['limit']!==null) self::fail('initial_observation_query_shape');
        }
        $periodsSupplied=array_key_exists('periods',$value);$periods=$periodsSupplied?$value['periods']:[];
        if (!self::periods($periods)) self::fail('bad_value:periods');
        // Like an all-null ranking, null scope is an optional transport form,
        // not a customer request.  Do not turn it into a failed query or an
        // implied data-range change.
        $scopeSupplied=array_key_exists('scope',$value) && $value['scope']!==null;$scope=$scopeSupplied?$value['scope']:'unspecified';
        if (!in_array($scope,['current_store','authorized','unspecified'],true)) self::fail('bad_value:scope');
        $delta=$hasPrior?self::delta($value['context_delta']):null;
        self::assertContextConstraintSources($understanding,$delta,$value,$scope,$scopeSupplied);
        self::requireReplacementValues($delta,$value,$rankingSupplied,$periodsSupplied,$scopeSupplied);
        // Provenance is a server-owned audit record.  The binding model may
        // carry an old or malformed copy, but it never gets to authorise its
        // own fields by writing that copy.  Derivation below uses only the
        // independently accepted understanding and the candidate values.
        self::assertRequirementValues($understanding,$value,$ranking,$periods,$scope,$delta,$safeQuestion['prior_query']??null);
        $requirementBindings=self::requirementBindings(
            $value['requirement_bindings'],
            AiIntentUnderstandingContract::requirements($understanding),
            $metricCodes,
            self::effectiveMetricCodes($value['metric_codes'],$delta,$safeQuestion['prior_query']??null)
        );
        $provenance=self::provenance(
            $understanding,$hasPrior,$value,$ranking,$periods,$scope,$delta,
            self::effectiveMetricCodes($value['metric_codes'],$delta,$safeQuestion['prior_query']??null)
        );
        $resultReference=self::resultReference($value['result_reference']??null,$hasPrior);
        self::assertResultReferenceRequirement($resultReference,$understanding);
        $texts=array_merge([(string)($safeQuestion['question']??'')],(array)($safeQuestion['recent_questions']??[]));
        $normalized=$value['object_term']!=='' && !self::contained($value['object_term'],$texts);
        if ($normalized) $value['object_term']='';
        foreach ($value['unresolved_fragments'] as $fragment) if (!is_string($fragment)||$fragment===''||mb_strlen($fragment,'UTF-8')>160||!self::contained($fragment,$texts)) self::fail('bad_value:unresolved_fragments');
        return ['object_kind'=>$value['object_kind'],'object_term'=>$value['object_term'],'operation'=>$value['operation'],'metric_codes'=>$value['metric_codes'],'action_codes'=>$value['action_codes'],'needs_metric_choice'=>$value['needs_metric_choice'],'initial_observation'=>$initialObservation,'recommended_initial_answer'=>$recommendedInitialAnswer,'requirement_bindings'=>$requirementBindings,'provenance'=>$provenance,'ranking'=>$ranking,'periods'=>$periods,'scope'=>$scope,'context_delta'=>$delta,'result_reference'=>$resultReference,'unresolved_fragments'=>$value['unresolved_fragments'],'_periods_supplied'=>$periodsSupplied,'_scope_supplied'=>$scopeSupplied,'_ranking_supplied'=>$rankingSupplied,'_object_term_normalized'=>$normalized,'_contract_version'=>self::VERSION];
    }

    private static function delta($value): array
    {
        if (!is_array($value)) self::fail('bad_value:context_delta');
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
    }

    /**
     * Store and object restrictions are as material as a metric or date.  A
     * delta may retain a signed restriction freely, but it may remove or
     * replace one only when this customer turn contains the corresponding
     * accepted meaning.  This is structural provenance, not phrase matching:
     * the understanding stage remains responsible for interpreting the words.
     */
    private static function assertContextConstraintSources(array $understanding,?array $delta,array $intent,string $scope,bool $scopeSupplied): void
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
        // object kind. Its removal or replacement must still be grounded in a
        // current object request, even where that kind happens to equal the
        // previous one (for example “all beauticians”).
        if (in_array($delta['business_filters'],['clear','replace'],true)
            && !$hasField('object_kind',true)) {
            self::fail('context_constraint_without_source:business_filters');
        }
    }

    private static function provenance(array $understanding,bool $hasPrior,array $intent,array $ranking,array $periods,string $scope,?array $delta,array $effectiveMetricCodes): array
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
                $out[$field]=['source'=>'customer','requirements'=>$ids];
                continue;
            }
            if ($hasPrior && self::inherited($field,$delta)) {
                $out[$field]=['source'=>'context','requirements'=>[]];
                continue;
            }
            if (!self::neutralDefault($field,$intent,$ranking,$periods,$scope)) {
                throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
                    'stage'=>'intent_contract','predicate'=>'provenance_field_not_understood','field'=>$field,
                ]);
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
    public static function assertEffectiveRequirementValues(array $understanding,array $intent): void
    {
        self::assertRequirementValues(
            $understanding,
            $intent,
            (array)($intent['ranking']??['direction'=>'unspecified','limit'=>null]),
            (array)($intent['periods']??[]),
            (string)($intent['scope']??'unspecified'),
            null,
            null
        );
        self::requirementBindings(
            $intent['requirement_bindings']??null,
            AiIntentUnderstandingContract::requirements($understanding),
            (array)($intent['metric_codes']??[]),
            (array)($intent['metric_codes']??[])
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
        if ($hasPriorQuery || ($intent['needs_metric_choice']??false) || ($intent['initial_observation']??false)
            || ($intent['recommended_initial_answer']??false) || count((array)($intent['metric_codes']??[]))!==1) return false;
        $requirements=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
            if (!empty($requirement['values']['metric_exclusions'])) return false;
            $requirements[]=$requirement;
        }
        return count($requirements)===1;
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

    public static function semanticReviewInstruction(): string
    {
        return 'Return exactly {"decision":"accept|reject","rejected_requirement_ids":["rN"]}. '
            .'Independently compare the accepted understanding with the proposed binding and supplied registered capability descriptions. '
            .'Evaluate only requirements that this turn newly adds, replaces or clears. A signed condition inherited unchanged from the prior query is not a new customer requirement and must neither require a new wording nor be changed. '
            .'Accept only if every evaluated customer requirement represented by the proposed binding is faithfully expressed by the current customer question. '
            .'When candidate_binding.initial_observation is true, accept only if the accepted understanding is an open overall operating goal with no customer-stated metric, exclusion, comparison, ranking or object-specific condition that the observation set would replace; otherwise reject it. '
            .'When candidate_binding.recommended_initial_answer is true, accept only if it contains one to four compatible registered metrics that are reasonable professional first readings of the accepted goal, object and response form; it must not change a stated exclusion, range, ranking or object condition. '
            .'Reject when a selected metric or result reference substitutes, reverses, ignores or conflicts with any such requirement, including an exclusion. '
            .'When a binding replaces or clears a signed store range, or changes an object filter, accept only if the current customer question itself expresses that exact change. A confirmed request for the authorized range may execute directly, but never expands authority beyond the current Reader permission. '
            .'An excerpt that expresses only a date, ranking, metric or another condition does not support a scope expansion merely because it appears in the same requirement. '
            .'A result reference is valid only when the current question itself asks for the displayed ranked result; it must never be inferred from prior conversation. '
            .'Do not invent requirements, metric codes, conditions, identities, dates, formulas, results or explanations. '
            .'An accept has an empty rejected_requirement_ids array; a reject names every rejected requirement id.';
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
        if (($value['decision']==='accept') !== ($value['rejected_requirement_ids']===[])) self::fail('bad_value:semantic_review');
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

    private static function assertRequirementValues(array $understanding,array $intent,array $ranking,array $periods,string $scope,?array $delta,?array $priorQuery): void
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
            $deltaField=['object_kind'=>'object','operation'=>'operation','periods'=>'periods','scope'=>'scope'][$field]??null;
            if ($deltaField!==null && ($delta[$deltaField]??null)==='inherit'
                && !self::equivalent($value,self::priorValue($field,$priorQuery))) {
                self::fail('binding_requirement_delta_mismatch:'.$field);
            }
        }
        foreach($requirements as $requirement) {
            $values=(array)($requirement['values']??[]);
            foreach(['object_kind','operation','periods','ranking','scope'] as $field) {
                if (!array_key_exists($field,$values)) continue;
                if ($delta!==null && !self::requiresCandidateValueCheck($field,$delta)) continue;
                $actual=$field==='ranking'?$ranking:($field==='periods'?$periods:($field==='scope'?$scope:$intent[$field]));
                if ($field==='ranking' && $delta!==null) {
                    foreach(['direction'=>'ranking_direction','limit'=>'ranking_limit'] as $key=>$deltaField) {
                        if (($delta[$deltaField]??null)==='replace'
                            && !self::equivalent($actual[$key]??null,$values[$field][$key]??null)) {
                            self::fail('binding_requirement_value_mismatch:ranking');
                        }
                    }
                    continue;
                }
                if (!self::equivalent($actual,$values[$field])) self::fail('binding_requirement_value_mismatch:'.$field);
            }
        }
    }

    /**
     * Before context merge, only a changed structural component can be
     * compared with the raw candidate. Inherited components are intentionally
     * compared after IntentContextMerger has restored their signed values.
     */
    private static function requiresCandidateValueCheck(string $field,array $delta): bool
    {
        if ($field==='ranking') return $delta['ranking_direction']==='replace'||$delta['ranking_limit']==='replace';
        $deltaField=['object_kind'=>'object','operation'=>'operation','periods'=>'periods','scope'=>'scope'][$field]??null;
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
    private static function requirementBindings($value,array $requirements,array $allowedCodes,array $effectiveCodes): array
    {
        if (!is_array($value) || count($value)>12 || ($value!==[]&&array_keys($value)!==range(0,count($value)-1))) self::fail('bad_value:requirement_bindings');
        $metricRequirementIds=[];
        foreach($requirements as $id=>$requirement) if (in_array('metric_codes',(array)($requirement['fields']??[]),true)) $metricRequirementIds[]=$id;
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
        // A model's natural-language metric phrase is deliberately not mapped
        // back to a code by the server. A newly stated metric phrase therefore
        // always needs an explicit binding decision.
        return null;
    }

    private static function inherited(string $field,?array $delta): bool
    {
        if ($delta===null) return false;
        $key=['metric_codes'=>'metric_codes','object_kind'=>'object','operation'=>'operation','periods'=>'periods','ranking'=>'ranking_direction','scope'=>'scope'][$field]??null;
        if ($key===null || $delta[$key]!=='inherit') return false;
        return $field!=='ranking' || $delta['ranking_limit']==='inherit';
    }

    private static function neutralDefault(string $field,array $intent,array $ranking,array $periods,string $scope): bool
    {
        if ($field==='object_kind') return in_array($intent['object_kind'],['store','unknown'],true)&&$intent['object_term']==='';
        if ($field==='metric_codes') return $intent['metric_codes']===[];
        if ($field==='operation') return in_array($intent['operation'],['summary','unknown'],true);
        if ($field==='ranking') return $ranking['direction']==='unspecified'&&$ranking['limit']===null;
        if ($field==='periods') return $periods===[];
        return $scope==='unspecified'||$scope==='authorized';
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
    private static function validDate(string $value): bool { if(!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D',$value))return false;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Asia/Shanghai'));return $date!==false&&$date->format('Y-m-d')===$value; }
    private static function contained(string $value,array $texts): bool {foreach($texts as $text)if(is_string($text)&&mb_strpos($text,$value,0,'UTF-8')!==false)return true;return false;}
    private static function fail(string $predicate): void {throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_contract','predicate'=>$predicate]);}
}
