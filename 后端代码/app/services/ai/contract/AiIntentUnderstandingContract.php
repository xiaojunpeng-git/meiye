<?php
namespace app\services\ai\contract;

/**
 * The first model boundary records what the customer means before any metric
 * catalogue, query form or permission scope is considered. It intentionally
 * carries no executable code or business identity.
 */
final class AiIntentUnderstandingContract
{
    public const VERSION = 'intent-understanding-v23';
    // The model sees only current protocol fields. Older stored member-detail
    // carriers remain valid input for context replay, but must not reappear
    // in newly generated understanding responses.
    private const MODEL_FIELDS = ['metric_codes','object_kind','object_relation','operation','periods','ranking','scope','store_term','result_reference','aggregate_condition','condition_update','object_detail','unbound'];
    private const LEGACY_FIELDS = ['member_detail'];

    /**
     * Keep one current named store when the model also calls a typed role a
     * store, or repeats the same store in an adjacent deictic. Both repairs
     * require current evidence and a unique local store type. Distinct names,
     * conjunctions and historical references remain separate.
     */
    public static function reconcileCurrentStoreRequirements(array $understanding,array $safeQuestion,array $privateKinds): array
    {
        if (($understanding['status']??null)!=='understood' || isset($understanding['groups'])) return $understanding;
        $question=(string)($safeQuestion['question']??'');$requirements=(array)($understanding['requirements']??[]);
        // A model can mistakenly copy the adjacent position token into a
        // store requirement. Local catalogue kinds, unlike its wording, are
        // authoritative: only one typed store may own store_term. The role
        // token remains in the safe question and must still bind as a person
        // filter later, or the common unresolved-condition guard rejects it.
        $typedStores=[];$misplaced=[];
        foreach ($requirements as $index=>$requirement) {
            if (!in_array('store_term',(array)($requirement['fields']??[]),true)) continue;
            $term=$requirement['values']['store_term']??null;
            if (!is_string($term)) return $understanding;
            preg_match_all('/local_condition_[0-9]+/',$term,$found);
            foreach (array_unique($found[0]) as $reference) {
                $token='['.$reference.']';$currentEvidence=false;
                foreach ((array)($requirement['evidence']??[]) as $evidence) {
                    if (($evidence['message_id']??null)==='current'
                        && strpos((string)($evidence['quote']??''),$token)!==false) $currentEvidence=true;
                }
                if (strpos($question,$token)===false || !$currentEvidence) return $understanding;
                $kind=$privateKinds[$reference]??null;
                if ($kind==='store') $typedStores[$reference]=true;
                elseif ($kind==='position') {
                    // Only local relation words may surround this position
                    // token. Another store name, conjunction or unexplained
                    // condition cannot be shortened to the typed store.
                    $residual=preg_replace('/\s+/u','',str_replace($token,'',$term));
                    if (!is_string($residual) || !preg_match('/^(?:(?:(?:这|那|该)(?:个|家|间)?)?(?:门店|店))?(?:岗位)?$/uD',$residual)) return $understanding;
                    $misplaced[$index]=true;
                }
                else return $understanding;
            }
        }
        if (count($typedStores)===1 && $misplaced!==[]) {
            $storeToken='['.array_key_first($typedStores).']';
            foreach (array_keys($misplaced) as $index) {
                $term=$requirements[$index]['values']['store_term'];
                if (strpos($term,$storeToken)!==false) return $understanding;
                $fields=array_values(array_diff((array)$requirements[$index]['fields'],['store_term']));
                unset($requirements[$index]['values']['store_term']);
                if ($fields===[]) unset($requirements[$index]);
                else $requirements[$index]['fields']=$fields;
            }
            $requirements=array_values($requirements);
        }
        $storeIndexes=[];$reference=null;$deictic=null;$deicticIndex=null;
        foreach ($requirements as $index=>$requirement) {
            if (!in_array('store_term',(array)($requirement['fields']??[]),true)) continue;
            $term=$requirement['values']['store_term']??null;
            if (!is_string($term)) return $understanding;
            $storeIndexes[]=$index;
            $current=false;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current' && strpos((string)($evidence['quote']??''),$term)!==false) $current=true;
            }
            if (!$current) return $understanding;
            if (preg_match('/\[(local_condition_[0-9]+)\]/D',$term,$match)) {
                if (($privateKinds[$match[1]]??null)!=='store' || $reference!==null) return $understanding;
                $reference='['.$match[1].']';
            } else {
                if ($deictic!==null || !preg_match('/^(?:这|那|该)(?:个|家|间)?(?:门店|店)$/uD',$term)) return $understanding;
                $deictic=$term;$deicticIndex=$index;
            }
        }
        if (count($storeIndexes)===2 && $reference!==null && $deictic!==null && $deicticIndex!==null
            && preg_match('/'.preg_quote($reference,'/').'\s*'.preg_quote($deictic,'/').'/u',$question)) {
            $fields=array_values(array_diff((array)$requirements[$deicticIndex]['fields'],['store_term']));
            unset($requirements[$deicticIndex]['values']['store_term']);
            if ($fields===[]) unset($requirements[$deicticIndex]);
            else $requirements[$deicticIndex]['fields']=$fields;
        } elseif ($misplaced===[]) return $understanding;
        // This repair owns only requirement packaging. Rebuilding a partial
        // envelope used to discard the accepted topic relation and could
        // reopen an old topic at later context/recommendation gates.
        $understanding['requirements']=array_values($requirements);
        return self::normalize($understanding,$safeQuestion);
    }

    /**
     * A deictic store in the current turn can name the sole row of a verified
     * prior ranking, but never the user's current login store. Some valid
     * model responses omit the store field altogether while preserving the
     * requested metric and ranking; ground that one missing field in the
     * current wording and signed ordinal without changing any other meaning.
     * Named, plural and tied stores stay on the normal clarification path.
     */
    public static function reconcileSoleStoreReference(array $understanding,array $safeQuestion,array $privateKinds=[]): array
    {
        $sole=$safeQuestion['prior_query']['sole_result_reference']??null;
        if (($understanding['status']??null)!=='understood' || isset($understanding['groups'])
            // A local deictic repair cannot overrule an accepted new topic
            // by selecting an identity from the preceding answer.
            || ($understanding['topic_relation']??null)==='new_topic'
            || ($safeQuestion['prior_query']['object_kind']??null)!=='store'
            || !is_array($sole) || !in_array($sole['group']??null,['top','bottom'],true)
            || ($sole['ordinal']??null)!==1) return $understanding;
        $question=(string)($safeQuestion['question']??'');
        if (preg_match_all('/(?:这|那|该)(?:个|家|间)?(?:门店|店)/u',$question,$mentions)!==1
            || preg_match('/除(?:了|外)|以外|之外|不是|不包括|另(?:外|一个)|其他(?:门店|店)/u',$question)) return $understanding;
        preg_match_all('/\[(local_condition_[0-9]+)\]/',$question,$conditionTokens);
        foreach (array_unique($conditionTokens[1]) as $token) {
            // An opaque current store is an independent location. A role
            // token describes the personnel being ranked, not another shop.
            if (($privateKinds[$token]??null)!=='position') return $understanding;
        }
        $deictic=$mentions[0][0];
        // A second location word makes a model's extra store carrier
        // potentially independent; only one current location mention may
        // collapse into the prior signed row.
        $outsideMention=preg_replace('/'.preg_quote($deictic,'/').'/u','',$question,1);
        if (!is_string($outsideMention) || preg_match('/门店|店/u',$outsideMention)) return $understanding;
        $requirements=(array)($understanding['requirements']??[]);$storeCandidates=[];$referencePresent=false;
        foreach ($requirements as $index=>$requirement) {
            $fields=(array)($requirement['fields']??[]);
            if (in_array('result_reference',$fields,true)) {
                if (($requirement['values']['result_reference']??null)!==$sole) return $understanding;
                $referencePresent=true;
            }
            if (!in_array('store_term',$fields,true)) continue;
            $term=$requirement['values']['store_term']??null;
            // With no second location in the current question, any extra
            // store_term is a duplicate or a mistyped adjacent subject. It
            // must still be grounded in this turn before it can be removed.
            if (!is_string($term)) return $understanding;
            $grounded=false;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current'
                    && mb_strpos((string)($evidence['quote']??''),$term,0,'UTF-8')!==false) $grounded=true;
            }
            if (!$grounded) return $understanding;
            $storeCandidates[]=$index;
        }
        if ($storeCandidates===[] && $referencePresent) return $understanding;
        if ($storeCandidates===[]) {
            if (count($requirements)>=12) return $understanding;
            $used=array_column($requirements,'id');$id=null;
            for ($number=1;$number<=999;$number++) {
                if (!in_array('r'.$number,$used,true)) {$id='r'.$number;break;}
            }
            if ($id===null) return $understanding;
            $requirements[]=['id'=>$id,'meaning'=>$deictic,'fields'=>['result_reference'],
                'values'=>['result_reference'=>$sole],
                'evidence'=>[['message_id'=>'current','quote'=>$question]]];
        } else {
            foreach ($storeCandidates as $candidate) {
                // One singular current store cannot also request a separate
                // catalog lookup. Keep its other independently understood
                // fields, but remove redundant deictic store carriers.
                $requirements[$candidate]['fields']=array_values(array_diff($requirements[$candidate]['fields'],['store_term']));
                unset($requirements[$candidate]['values']['store_term']);
                if (!$referencePresent) {
                    $requirements[$candidate]['fields'][]='result_reference';
                    $requirements[$candidate]['values']['result_reference']=$sole;
                    $referencePresent=true;
                }
                if ($requirements[$candidate]['fields']===[]) unset($requirements[$candidate]);
                elseif ($requirements[$candidate]['values']===[]) unset($requirements[$candidate]['values']);
            }
        }
        // The prior identity is a signed reference; keep the accepted turn
        // relation alongside it so later binding cannot reinterpret scope.
        $understanding['requirements']=array_values($requirements);
        return self::normalize($understanding,$safeQuestion);
    }

    /**
     * A locally masked, uniquely named position restricts the personnel
     * cohort; it is not a separate rankable answer-row kind. Normalize that
     * typed role carrier to person before capability binding, while leaving
     * its opaque current-turn condition in place for the authorized resolver.
     * Generic position analyses and multiple identities stay untouched.
     */
    public static function reconcileSelectedPositionAsPersonnel(array $understanding,array $safeQuestion,array $privateKinds): array
    {
        if (($understanding['status']??null)!=='understood' || isset($understanding['groups'])
            || isset($understanding['request_kind'])) return $understanding;
        $current=(string)($safeQuestion['question']??'');$positionTokens=[];
        foreach ($privateKinds as $reference=>$kind) {
            if ($kind==='position' && is_string($reference) && preg_match('/^local_condition_[0-9]+$/D',$reference)
                && strpos($current,'['.$reference.']')!==false) $positionTokens[]='['.$reference.']';
        }
        if (count($positionTokens)!==1) return $understanding;
        $requirements=(array)($understanding['requirements']??[]);$positionIndexes=[];
        foreach ($requirements as $index=>$requirement) {
            $values=(array)($requirement['values']??[]);$kind=$values['object_kind']??null;
            if ($kind==='person' && ($values['object_relation']??null)==='selection') return $understanding;
            if ($kind!=='position') continue;
            $grounded=false;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)==='current') $grounded=true;
            }
            // The model may cite the generic role word while the server has
            // masked its exact name elsewhere in the same current question.
            // The unique current position token, not the model excerpt, owns
            // that identity and remains unresolved until authorization.
            if (!$grounded) return $understanding;
            $positionIndexes[]=$index;
        }
        if ($positionIndexes===[]) return $understanding;
        foreach ($requirements as $requirement) {
            $values=(array)($requirement['values']??[]);
            if (($values['object_relation']??null)==='analysis'
                && !in_array($values['object_kind']??null,['person','position'],true)) return $understanding;
        }
        foreach ($positionIndexes as $index) {
            $requirements[$index]['values']['object_kind']='person';
            $requirements[$index]['values']['object_relation']='analysis';
            $requirements[$index]['fields']=array_values(array_unique(array_merge(
                (array)$requirements[$index]['fields'],['object_kind','object_relation']
            )));
        }
        // Reclassifying the selected role changes no topic or request kind.
        $understanding['requirements']=$requirements;
        return self::normalize($understanding,$safeQuestion);
    }

    /**
     * A metric's registered fact grain can disambiguate a ranking noun that
     * occurs only inside that metric's title. With one current typed position
     * and one person-grain metric, rank personnel within that position; a
     * separately stated project/product object is never rewritten.
     */
    public static function reconcileRankedRoleMetricObject(
        array $understanding,array $safeQuestion,array $privateKinds,array $personMetricCodes,array $allMetricCodes,array $objectVocabulary
    ): array {
        if (($understanding['status']??null)!=='understood' || isset($understanding['groups']) || isset($understanding['request_kind'])) return $understanding;
        $question=(string)($safeQuestion['question']??'');$positions=[];
        foreach ($privateKinds as $reference=>$kind) if ($kind==='position' && is_string($reference)
            && preg_match('/^local_condition_[0-9]+$/D',$reference)
            && strpos($question,'['.$reference.']')!==false) $positions[]=$reference;
        if (count($positions)!==1) return $understanding;
        $match=\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText($question,$allMetricCodes);
        if (!is_array($match) || !in_array($match['metric_code']??null,$personMetricCodes,true)) return $understanding;
        $requirements=(array)($understanding['requirements']??[]);$objectIndex=null;$hasRanking=false;
        foreach ($requirements as $index=>$requirement) {
            $values=(array)($requirement['values']??[]);
            if (isset($values['aggregate_condition']) || isset($values['result_reference']) || isset($values['object_detail'])
                || isset($values['condition_update']) || isset($values['metric_exclusions'])) return $understanding;
            if (($values['operation']??null)==='ranking' && isset($values['ranking'])) $hasRanking=true;
            if (!in_array('object_kind',(array)($requirement['fields']??[]),true)) continue;
            if ($objectIndex!==null || ($values['object_relation']??null)!=='analysis') return $understanding;
            $objectIndex=$index;
        }
        if (!$hasRanking || $objectIndex===null) return $understanding;
        $kind=$requirements[$objectIndex]['values']['object_kind']??null;
        if ($kind==='person' || !is_string($kind)) return $understanding;
        // The only competing object label must be part of the exact metric
        // phrase, not a separate customer-stated object elsewhere in the turn.
        $labels=[];
        foreach ($objectVocabulary as $item) if (($item['object_kind']??null)===$kind && is_string($item['object_label']??null)) {
            $labels[]=$item['object_label'];
        }
        if ($labels===[]) return $understanding;
        $remainder=str_replace((string)$match['term'],'',$question);$overlap=false;
        foreach ($labels as $label) {
            if ($label!=='' && mb_strpos((string)$match['term'],$label,0,'UTF-8')!==false) $overlap=true;
            if ($label!=='' && mb_strpos($remainder,$label,0,'UTF-8')!==false) return $understanding;
        }
        if (!$overlap) return $understanding;
        $requirements[$objectIndex]['values']['object_kind']='person';
        // A registry-owned object correction preserves every turn-level
        // decision; only the corrected typed requirements are revalidated.
        $understanding['requirements']=$requirements;
        return self::normalize($understanding,$safeQuestion);
    }

    public static function modelInstruction(): string
    {
        // Grounding remains strict. Explain contiguous quoting on the first
        // pass so coordinated wording does not need an avoidable repair call.
        return 'Return one JSON object following '.self::VERSION.': '
            . 'For a partly intelligible needs_clarification request preserve the clear conditions only. Omit clarification_suggestions; executable recommendations are proposed separately from server-verified capabilities. A vague measurement does not erase a clearly named analytical subject: preserve its object_kind and object_relation even when the measurement still needs clarification. Do not infer operation=summary merely from a vague request about an amount situation. Preserve operation only when its answer form was actually fixed by the customer. '
            . 'When clarification_context is present, current is the customer reply to the paused question. Merge it with clarification_context.understanding and original evidence: retain unchanged conditions, explicitly corrected conditions take precedence, and an independent new topic discards the paused meaning. Emit the complete merged requirements, citing current for corrections and original for preserved fields. Do not copy a condition contradicted by the current reply. '
            . 'When prior_query or clarification_context exists, add top-level topic_relation="continuation|new_topic". Decide from the complete current meaning: a reference to a prior result or an omitted condition the customer intends to keep is continuation, including a move from a store to people in that store. An independent new question that does not refer to the preceding answer is new_topic, even if it omits a period or store. Changing an analytical object alone does not prove a new topic. Do not copy prior conditions into a new_topic. This relation grants no object identity or permission. Omit topic_relation only when both prior_query and clarification_context are absent. When prior_query.verified_result_set has a kind and count, a plural reference such as "这两家" may refer to that complete verified set even though prior_query.operation is breakdown; express that as object_detail target=set, and express a requested per-object measurement as operation=breakdown. The server alone resolves the identities. '
            . 'A named store restricting another analytical subject uses a separate requirement fields=["store_term"], values.store_term="exact named-store text or opaque reference", with current-message evidence. Keep the compared people/items in object_kind with object_relation=analysis. Never put the store identity into the analytical object_term or infer a store from a person name. Do not use store_term for a pronoun referring to a previous result; use the supported result reference instead. An opaque local store reference is intentionally hidden from you but is not an ambiguous customer request: preserve its store_term carrier and let the authorized server catalogue resolve it. When one measured object is requested at both extremes, use one ranking operation with direction=top_and_bottom; do not split or discard the common metric, object, period or named-store scope. '
            . 'scope=current_store means the authenticated store of the application entry, NOT a named store, a location mentioned in this question, or the store from a prior answer. A named-store reference must have store_term even if followed by words meaning this store. Do not add scope=current_store for that reference; retain the authorized origin while independently narrowing with store_term. '
            . '{"goal":"brief business goal","requirements":[{"id":"r1","meaning":"one part of the customer request","fields":["metric_codes"],"values":{"metric_terms":["exact customer term"]},"evidence":[{"message_id":"current","quote":"exact text from that de-identified message"}]}],"status":"understood|needs_clarification"}. '
            . 'Evidence is copied source text, not a rewritten description: never join non-adjacent words, remove conjunctions or insert an object name into a quote. When several measurements share an object or time expression, use the entire original current message verbatim for their current-message evidence rather than constructing a shorter sentence. Keep their semantic requirements distinct; sharing evidence does not merge requirements. '
            . 'When one measurement is asked at both extrema in the same sentence, preserve one shared measurement and one top_and_bottom ranking; if you emit multiple requirements, cite the complete current message verbatim for each instead of inventing a shortened or punctuated quote. '
            // The existence of a requirement controls whether the customer
            // receives partial-query clarification or general query help.
            // Preserve business meaning, not arbitrary words made to fit a
            // field; this is a semantic boundary, not a keyword blacklist.
            . 'Requirements must preserve intelligible business-query meaning. If neither the complete current message nor its verified context expresses any identifiable business query, return status=needs_clarification with requirements=[]; do not manufacture an object, measurement, operation or unbound requirement merely because the sentence contains nouns or verbs. Conversely, preserve every identifiable business condition in an incomplete query, including its time or analytical subject, rather than treating partial understanding as total incomprehension. '
            . 'The only top-level keys are goal, requirements, status and optional groups, request_kind, topic_relation or clarification_suggestions. request_kind is "open_overview" for one store operating overview or "overview_comparison" for that same broad registered overview explicitly compared across two periods. Use either marker only when the complete current message names no independent measurement, ranking, object selection, exclusion or condition. An open_overview requirement carries metric_codes, object_kind, object_relation, operation and periods with the exact broad customer words as metric_terms, object_kind="store", object_relation="analysis", operation="summary" and exactly one typed period. An overview_comparison requirement carries the same fields, operation="comparison" and exactly two typed periods in the customer order. The markers are semantic types, never permission to invent metrics. Omit request_kind for every other request. Every meaningful part of the customer request needs one or more requirements; do not collapse exclusions, comparison relationships, quantity or time into a vague summary. When status is needs_clarification and no concrete meaning can yet be preserved, requirements may be an empty array; do not use unbound for ambiguity. When the customer explicitly asks independent results for two to four analytical subjects, include groups as [{"id":"q1","requirement_ids":["r1"]}]. Each group lists the requirements needed for one result; a shared date, metric, ranking or scope requirement may occur in every applicable group. Do not create groups for AND/OR predicates over one candidate population, multi-metric summary of one subject, or an ambiguous phrase. '
            . 'A requirement has exactly id, meaning, fields, values and evidence. fields may contain only '.implode(', ',self::MODEL_FIELDS).'; use only fields actually expressed by this requirement. metric_terms and metric_exclusions are values keys, never fields: declare metric_codes in fields and put the exact customer words in values.metric_terms or values.metric_exclusions. unbound has no values key. object_detail is a semantic continuation request, not a metric, ranking or result_reference: values.object_detail is exactly {"view":"summary|rights","target":"single|set","ordinal":null-or-positive-integer}. Use it when the customer asks to inspect, expand, explain, see more about or otherwise continue into the concrete object or object set shown by the preceding verified answer, even when natural speech does not contain the literal word detail. view=summary means a context-relevant verified overview; view=rights is only for member assets or entitlements. target=single means one current or explicitly ordinal object; target=set means the complete verified result set of the stated object kind. ordinal is non-null only when the customer explicitly names a position such as the second object. Words such as this one, that person, it, these or expand it do not invent an ordinal. A pure object_detail continuation never also carries result_reference: the server resolves the target from the preceding verified result and rejects ambiguous sets. A field is a completed semantic commitment, never a note or a sketch. values is optional only when the request supplies no safe structured value for that field. When the customer meaning clearly establishes an object kind or relation, result form, period, ranking, scope, aggregate condition, condition update, object detail or result reference, include the matching complete typed value in values so the later binding cannot silently change it. A temporal expression about the whole answer is an independent periods requirement. When different dates belong to individual numerical predicates, put one typed period on each dated aggregate_condition item instead; do not collapse those dates into one whole-query periods field. Before emitting the object, check the complete customer message again: preserve every expressed relative-day, calendar-month or explicit-range condition in its proper typed carrier and exact evidence. Do not absorb a time condition into a goal or metric term. When present, values may contain only same-named keys for declared fields except the metric_codes and unbound cases above; typed values must be complete and valid. Do not omit a field merely because its execution-shaped value is not available in this phase; preserve the natural-language meaning and evidence, and let the later binding phase derive the executable form. id is r followed by a positive number and is valid only in this request. evidence is an array of {"message_id":"current","quote":"exact excerpt"}; message_id must name an entry in question.evidence_messages. Do not output character offsets. The excerpt must occur exactly once in that one de-identified message; include more adjacent text if needed to distinguish repeated words. '
            . 'Understand the complete phrase before choosing an analytical object. When the customer asks which day has the highest or lowest value, use object_kind=business_date, object_relation=analysis, operation=ranking and the stated ranking direction; keep the calendar range as a separate periods requirement. business_date means grouping the authorized metric by its registered business day, never selecting one date as a filter. Highest or lowest supplies only the ranking direction and never selects store or another object by itself. '
            . 'Every customer-stated business measurement belongs in fields as metric_codes and carries either values.metric_terms or values.metric_exclusions, even if it is everyday language rather than a registered indicator name; metric_codes is only the name of the later binding slot, never a request to output a code. These are nonempty arrays of exact customer terms present in an evidence excerpt. Never put a registered metric code in values. A single cumulative member-money threshold may retain the legacy aggregate_condition {"subject":"member","aggregation":"period_total","operator":"gte|gt|lte|lt|eq","amount_cents":positive-integer} with operation=threshold_count. For one or more conditions over a candidate object set, preserve aggregate_condition as {"subject":"person|member|store|order|sale_line|card|project|product","relation":"all|any","result_form":"count|list","conditions":[{"metric_term":"exact customer measurement","operator":"gte|gt|lte|lt|eq","quantity":"normalized nonnegative decimal","unit":"yuan|count|day"}]} and set operation to condition_count or condition_list to match result_form. Every generic condition item has metric_term, operator, quantity and unit, plus optional period when that particular predicate has its own date. period uses the same typed date_range, relative_days or calendar_period object as values.periods. If one predicate has its own period, every period-based sibling predicate must have its own period; current-state assets have no historical period. quantity and unit are always JSON strings. Convert Chinese amount magnitude into yuan: “5万元” is quantity "50000" and unit "yuan"; “24次” is quantity "24" and unit "count"; “超过90天没来” keeps quantity "90" and unit "day". A current positive state such as “有剩余项目次数” is a condition with operator gt, quantity "0" and unit "count"; do not invent a period total for it. Logical relation is part of the requested business meaning, not a default: relation all means every condition must hold (for example, “并且”), while relation any means at least one condition may hold (for example, “或者”). Never default a multi-condition request to all, and never replace an explicit alternative with a conjunction. For a mixed grouping, add expression={"relation":"all|any","children":[zero-based condition index or another group]} while keeping the top-level relation equal to its root. Every condition index must appear exactly once; use at most three group levels. For example A and (B or C) has conditions [A,B,C] and expression={"relation":"all","children":[0,{"relation":"any","children":[1,2]}]}. Omit expression for a simple flat all/any set. If the grouping changes the answer but cannot be determined, keep it unresolved instead of inventing precedence. For example, “销售业绩达到5万元并且劳动业绩达到5万元的员工有几个” uses subject person, relation all, result_form count and two condition items with metric_term “销售业绩” and “劳动业绩”, operator gte, quantity "50000", unit "yuan"; the same request joined by “或者” uses relation any. Preserve every condition in customer order; do not collapse AND/OR, invent a metric, or turn a count request into a list. quantity is a normalized decimal string such as "50000" or "24", never a result. When prior_query contains a verified generic aggregate_condition and the current turn clearly changes an existing predicate, use condition_update for that predicate; separately preserve any period or count/list form change expressed in the same current message with values.condition_update={"target_term":"exact current words identifying that prior condition","operator":"gte|gt|lte|lt|eq","quantity":"normalized nonnegative decimal","unit":"yuan|count|day"}. Do not also emit metric_codes, aggregate_condition or object_kind merely to restate unchanged context; include operation only when the customer separately changes count/list form. condition_update is never a standalone query, never adds or removes a condition, and must not be used when the target could denote more than one prior predicate. If the current turn changes only time, object, range or response form and names no measurement, do not add a metric_codes field merely because a verified prior query has one; retain that prior meaning through context only. A time, object or response-form requirement does not replace the separate measurement requirement. object_kind is one of store, business_date, person, position, guide, sales_manager, member, product, project, category, partner, inventory, course, organization, order, sale_line, card, unknown. object_relation is analysis when that kind is what the customer wants compared, grouped or listed, and selection only when the customer identifies a particular object whose records should narrow the data. An analytical object is never itself a data-range restriction. operation is one of summary, breakdown, trend, ranking, comparison, threshold_count, condition_count, condition_list, definition, unknown. Use breakdown when the customer asks for each, every, individual or separately listed object value without requesting an order, winner or threshold; “各个门店业绩” and “每位员工业绩” are breakdowns, not summaries and not rankings. Use ranking when the requested answer identifies leading, trailing or ordered comparable objects; the fact that a ranking compares peer values does not make it operation=comparison. Use comparison only when the customer asks to contrast two stated business sides such as periods, objects or measurements. scope is one of current_store, authorized, unspecified. ranking is exactly {"direction":"top|bottom|top_and_bottom|unspecified","limit":null}. Set limit to an integer from 1 to 999 when the customer asks for a specific count or semantically asks for one winner, leader, best or worst object; leave it null only for an open-ended plural ranking with no count. A breakdown always keeps ranking direction=unspecified and limit=null. result_reference is exactly {"group":"top|bottom","ordinal":positive-integer} only when the customer explicitly refers to a displayed rank result; it identifies a position in the previous answer, never a name, ID or value. periods is an array of at most two objects, each exactly one of {"kind":"date_range","start":"YYYY-MM-DD","end":"YYYY-MM-DD"}, {"kind":"relative_days","days":1,"end_offset_days":0}, or {"kind":"calendar_period","unit":"week|month|quarter|year","offset":-1,"count":1,"end_mode":"full|to_reference"}; numeric examples illustrate JSON types, not defaults. A relative-day count is a positive integer; calendar_period offset is a signed integer, count is a positive integer, and end_mode=to_reference only applies to offset 0. Resolve calendar weeks Monday through Sunday, calendar quarters January–March/April–June/July–September/October–December, and calendar years January–December. Use full for earlier periods and to_reference for the current period. Do not turn a calendar week or quarter into a rolling seven days or one month. preserve the customer meaning without imposing execution coverage limits here. A comparison that expresses both sides in the customer wording must preserve those two periods in stated order; do not leave either side for a date form. Omit a values key and its field when that meaning was not supplied, except a genuinely inherited meaning must remain explicit. '
            . 'A condition_update may coexist with independently stated changes to the period or count/list answer form in the same current message. Keep one existing predicate target per condition_update and preserve every untouched predicate, its AND/OR relation, subject, metric and authorised scope. A request to inspect objects after a count refers to the matching population; the count has no identities, so ask for the bounded matching list before any individual detail. These are typed changes to verified context, not permission to reuse an unrelated earlier topic or to guess which of multiple equal-unit predicates changed. '
            . 'One explicitly singular highest or lowest object has ranking limit=1; both singular extremes share one measurement with direction=top_and_bottom and limit=1. Tied objects are resolved by the registered query, not by asking for a count. '
            . 'When the customer coordinates two or more independently named measurements, preserve every measurement separately, either as separate requirements or as separate metric_terms in one requirement; never collapse the conjunction into one vague measurement or turn it into a choice between terms. Interpret a distributive word from its grammatical target, not from a nearby noun: when “separately” applies to a coordinated list of measurements, return those measurements in one summary; use breakdown only when the customer distributes the answer across each object, such as each/every store or each employee. A singular object label followed by several measurements does not by itself request one row per object. If one sentence asks for both a per-object value list and a different metric’s highest/lowest object, these are independent answer forms even when both concern the same object kind: put the breakdown metrics and their operation in one group and the ranked metric, direction and operation in another, sharing only the stated period. Do not let the ranking operation overwrite the breakdown operation, or group a plain multi-metric summary by metric. Prefer copying each metric_term character-for-character from the de-identified customer message. Do not declare metric_codes on a requirement that describes only the analytical object. In a natural construction such as “每位员工销售业绩多少”, “员工” is the person object, “销售业绩” is the exact measurement term, and “每位” requests breakdown; metric_terms must contain “销售业绩”, never an invented contraction such as “员工业绩”. Apply the same grammatical separation to any registered object and measurement rather than memorizing this example. For a generic aggregate condition only, you may instead use one exact registered business-language alias that means the same measurement as an exact registered phrase in the evidence; never invent a paraphrase or insert an object qualifier. '
            . 'A metric term is the measured business fact, not every noun or modifier in the sentence. Never mark an analytical object noun, date expression, question word, or ranking direction such as highest, lowest, most, least, best or worst as metric_codes. For “which project has the most sales records and which has the least”, sales records is the one measurement, project is the analytical object, and most plus least are the two ranking directions; do not create extra measurement requirements for project, most or least. '
            . 'In a population question, the people or objects being counted and the words asking how many define subject and result_form=count; they are not a second business measurement. Keep one monetary predicate as one metric-bearing requirement even when its wording includes an amount and a count question. A bare amount without comparative language means equality (operator=eq); use gte, gt, lte or lt only when the customer actually expresses that comparison. This applies to every registered metric, not one fixed question or amount. '
            . 'For actual historical results, a bare mention of the current calendar year means year-to-date through question.reference_date, since later days have not happened. Preserve an explicitly stated future endpoint or full-year comparison exactly instead of shortening it. Apply the same distinction to calendar periods generally; never change a customer-specified start or end to fit data coverage. '
            . 'For object_relation, analysis also covers inspecting, summarizing or evaluating a stated object, not only comparing, grouping or listing it. A broad evaluation or overview of a stated object must carry that object_kind with object_relation=analysis; a time condition never replaces or erases it. object_kind may be store, business_date, person, position, guide, sales_manager, member, product, project, category, partner, inventory, course, organization, order, sale_line, card or unknown. '
            . 'Object-detail continuations must not be forced into a new analytical ranking or a result-reference binding. For example, after one verified person is shown, “详情呢”, “具体看看”, “展开说说” or “他怎么样” is one requirement with fields ["object_detail"] and values {"object_detail":{"view":"summary","target":"single","ordinal":null}}. After one verified store, the same semantic form applies. “第二个会员的详情” uses view=summary, target=single and ordinal=2. “这些会员的权益” uses view=rights, target=set and ordinal=null. “这个会员的权益明细给我看一下” may also carry object_kind=member and object_relation=analysis, but still uses object_detail. A request about every or all objects of a kind is not object_detail merely because it also says details, expand or be specific: when no concrete prior object or verified result set is selected, retain the plural object as object_relation=analysis and use operation=breakdown. If that continuation changes only the analytical object or answer grain and states no concrete business measurement, do not copy a previous metric into a new metric_codes requirement and do not manufacture a metric term from a broad word such as operating details; verified context and the registered capability policy handle that boundary. These examples explain semantic structure rather than a phrase list: infer the customer intent from the complete conversation, copy evidence from the actual current message and never invent an identity. '
            . 'A first-turn ranking can request details of its ranked objects (stores, people, members or another analytical object). Preserve the ranking and a separate object_detail requirement with target=set, ordinal=null and view=summary; details do not replace the ranking and do not mean a new measurement. This is compositional semantics, not a keyword rule. A first-turn population filter can also request member details. In that case preserve BOTH the complete aggregate_condition (result_form=list) AND a separate object_detail requirement (target=set, ordinal=null, view=summary for details or view=rights for entitlement/card details). A condition list answers who qualifies; object_detail answers what those members currently hold. One cannot replace the other. This applies equally to exact registry names and everyday measurement language. Before returning, check whether a requested detail or rights view has been lost merely because conditions were also present. Do not add object_detail for a request that asks only who qualifies or how many qualify. When a verified population has already been returned, asking to see that population more concretely is a presentation continuation, not a new condition_list query. Use object_detail target=set when the customer wants its records or profiles; if only the existing measurement list is requested, preserve the existing breakdown meaning without inventing a new filter or metric. '
            . 'Do not output metric codes, action codes, object IDs, names hidden behind local references, calculated dates, formulas, SQL, query steps, permissions or result values. An explicit customer date range may be preserved as a period; never calculate a relative period into calendar dates. '
            . 'understood means the business request is clear even when no current capability can perform it. needs_clarification means the business request itself has more than one plausible reading. A broad request to understand overall operating conditions without naming a specific business fact is still understood: retain it as a metric_codes requirement with its exact customer term, so the later binding may propose a clearly labelled initial observation rather than requiring the customer to learn a metric name. If the customer did state a measurement that can reasonably mean several different business facts and neither the current wording nor verified context chooses among them, preserve that measurement requirement and mark needs_clarification; never turn a category label into one of its examples. unbound is allowed only with understood: ambiguity is not an unavailable capability. For a two-period comparison, copy the entire de-identified current question into each evidence.quote; do not paraphrase, alter date words or quote a repeated fragment. This object grants nothing and is later bound by the server.';
    }

    public static function normalize($value, array $safeQuestion): array
    {
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        // Read historical language suggestions without treating them as plans.
        // Current recommendation execution uses separately verified carriers.
        $suggestions=is_array($value)?($value['clarification_suggestions']??[]):[];
        if (is_array($value)) unset($value['clarification_suggestions']);
        // A fresh conversation has no previous topic to continue or reset.
        // Ignore this non-executable model decoration instead of discarding
        // otherwise grounded current-turn meaning and spending a repair call.
        if (is_array($value) && ($safeQuestion['prior_query']??null)===null
            && !isset($safeQuestion['clarification_context'])) unset($value['topic_relation']);
        // Some providers place an already typed period beside requirements.
        // Relocate it only when exactly one requirement explicitly owns the
        // periods field and has no conflicting value. This repairs JSON
        // packaging, never derives a date or chooses a customer condition.
        if (is_array($value) && isset($value['periods']) && self::periods($value['periods'])
            && $value['periods']!==[] && is_array($value['requirements']??null)) {
            $owners=[];
            foreach ($value['requirements'] as $index=>$requirement) {
                if (is_array($requirement) && in_array('periods',(array)($requirement['fields']??[]),true)) $owners[]=$index;
            }
            if (count($owners)===1) {
                $index=$owners[0];$existing=$value['requirements'][$index]['values']['periods']??null;
                if (($existing===null || $existing===$value['periods'])
                    && is_array($value['requirements'][$index]['values']??null)) {
                    $value['requirements'][$index]['values']['periods']=$value['periods'];
                    unset($value['periods']);
                }
            } elseif (count($owners)===2 && count($value['requirements'])===2
                && !isset($value['groups'])) {
                // A shared top-level date is another provider packaging of
                // the same two-sided ranking. Require current evidence on
                // both ends, then reuse the strict rank/date compatibility
                // gate rather than assigning a date to unrelated queries.
                $evidence=[];
                foreach ($value['requirements'] as $requirement) {
                    $current=array_values(array_filter((array)($requirement['evidence']??[]),static function($item): bool {
                        return is_array($item)&&($item['message_id']??null)==='current';
                    }));
                    if ($current===[]) {$evidence=[];break;}
                    $evidence[]=$current[0];
                }
                if (count($evidence)===2) {
                    $candidate=$value['requirements'];
                    $candidate[]=['fields'=>['periods'],'values'=>['periods'=>$value['periods']],
                        'evidence'=>$evidence];
                    $attached=self::attachSharedRankingPeriod($candidate);
                    if (count($attached)===2) {
                        $value['requirements']=$attached;
                        unset($value['periods']);
                    }
                }
            }
        }
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys, SORT_STRING);
        if (!in_array($keys,[
            ['goal','requirements','status'],
            ['goal','groups','requirements','status'],
            ['goal','request_kind','requirements','status'],
            ['goal','groups','request_kind','requirements','status'],
            ['goal','requirements','status','topic_relation'],
            ['goal','groups','requirements','status','topic_relation'],
            ['goal','request_kind','requirements','status','topic_relation'],
            ['goal','groups','request_kind','requirements','status','topic_relation'],
        ],true)) self::fail('shape');
        // A topic boundary is a relation between turns, not a query field.
        // It can discard prior semantics only after the model has grounded
        // the relation in this turn, even if its query needs clarification;
        // clients never author this decision.
        if (array_key_exists('topic_relation',$value)
            && (!in_array($value['topic_relation'],['continuation','new_topic'],true)
                || (($safeQuestion['prior_query']??null)===null && !isset($safeQuestion['clarification_context'])))) self::fail('topic_relation');
        if (array_key_exists('request_kind',$value) && !in_array($value['request_kind'],['open_overview','overview_comparison'],true)) self::fail('request_kind');
        if (!self::text($value['goal'], 240)) self::fail('goal');
        if (!in_array($value['status'], ['understood', 'needs_clarification'], true)) self::fail('status');
        $messages = self::messages($safeQuestion);
        $requirements = $value['requirements'];
        // Repair only a malformed calendar carrier when the shared calendar
        // parser independently proves one explicit current range. This saves
        // a second model call for JSON-shape errors without choosing a metric,
        // changing a threshold, or guessing either side of a comparison.
        if (is_array($requirements)) {
            // Models sometimes emit one shared date requirement beside the
            // two ends of a ranking, while declaring that same period on
            // each end without repeating its typed value. Move only that
            // single, current-evidenced period into the two compatible ends.
            // Conflicting or independently grouped periods remain untouched
            // and fail the ordinary contract instead of changing the query.
            if (!isset($value['groups'])) {
                $requirements=self::attachSharedRankingPeriod($requirements);
            }
            $periodIndexes=[];
            foreach ($requirements as $index=>$requirement) {
                if (is_array($requirement) && in_array('periods',(array)($requirement['fields']??[]),true)) $periodIndexes[]=$index;
            }
            // One customer-stated period can be shared by several condition
            // requirements or independent result groups. Repair only missing
            // typed carriers with current-message evidence; do not infer a
            // second date or replace an already valid model period.
            if ($periodIndexes!==[] && count($periodIndexes)<=12 && is_string($safeQuestion['reference_date']??null)) {
                $missing=[];
                foreach ($periodIndexes as $index) {
                    if (self::periods($requirements[$index]['values']['periods']??null)) continue;
                    if (!array_filter((array)($requirements[$index]['evidence']??[]),static function($e): bool {
                        return is_array($e)&&($e['message_id']??null)==='current';
                    })) continue;
                    $missing[]=$index;
                }
                if ($missing!==[]) {
                    $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse((string)($safeQuestion['question']??''));
                    if (($projection['date_grouping_ambiguous']??true)===false && count((array)($projection['date_terms']??[]))===1) {
                        $range=(new \app\services\ai\execution\AiWorkflowPlanner())->normalizePeriod($projection['date_terms'][0],$safeQuestion['reference_date']);
                        foreach ($missing as $index) {
                            $requirements[$index]['values']['periods']=[['kind'=>'date_range','start'=>$range['start'],'end'=>$range['end']]];
                        }
                    }
                }
            }
            // A provider may omit the typed period altogether while still
            // preserving every business condition. Recover only one complete
            // current calendar span, without changing any object, metric or
            // predicate. The period-only guard below still rejects a reply
            // that omitted the rest of a business question.
            $hasBusinessCarrier=false;
            foreach ($requirements as $requirement) {
                if (is_array($requirement) && array_diff((array)($requirement['fields']??[]),['metric_codes'])!==[]) {
                    $hasBusinessCarrier=true;break;
                }
            }
            if (!isset($value['groups']) && $periodIndexes===[] && $hasBusinessCarrier && $value['status']==='understood'
                &&count($requirements)<12 && is_string($safeQuestion['reference_date']??null)) {
                $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse((string)($safeQuestion['question']??''));
                if (($projection['date_grouping_ambiguous']??true)===false
                    &&count((array)($projection['date_terms']??[]))===1) {
                    $range=(new \app\services\ai\execution\AiWorkflowPlanner())->normalizePeriod(
                        $projection['date_terms'][0],$safeQuestion['reference_date']
                    );
                    $usedIds=array_column(array_filter($requirements,'is_array'),'id');
                    for ($number=1;$number<=13;$number++) {
                        $id='r'.$number;
                        if (in_array($id,$usedIds,true)) continue;
                        $requirements[]=['id'=>$id,'meaning'=>'current stated reporting period','fields'=>['periods'],
                            'values'=>['periods'=>[['kind'=>'date_range','start'=>$range['start'],'end'=>$range['end']]]],
                            'evidence'=>[['message_id'=>'current','quote'=>(string)$safeQuestion['question']]]];
                        break;
                    }
                }
            }
        }
        // A provider can duplicate a valid calendar carrier into the metric
        // slot even after one repair.  Remove only that structurally proven
        // duplicate: a valid periods carrier must already exist and every
        // proposed metric term must itself be nothing but one closed-grammar
        // date expression.  This never chooses a business metric or infers a
        // query; the later binding and semantic-review gates still have to
        // understand the complete current question before anything executes.
        $requirements = self::discardPeriodOnlyMetricProjection(
            $requirements,
            array_key_exists('groups', $value)
        );
        $requirements = self::discardConditionCountMetricProjection(
            $requirements,$safeQuestion,array_key_exists('groups',$value)
        );
        if (!is_array($requirements) || (($requirements===[]) && $value['status']!=='needs_clarification') || count($requirements) > 12
            || ($requirements!==[] && array_keys($requirements) !== range(0, count($requirements) - 1))) self::fail('requirements_collection');
        $ids = []; $normalized = [];
        foreach ($requirements as $requirement) {
            if ($requirement instanceof \stdClass) $requirement = get_object_vars($requirement);
            // The overview marker already commits to one response shape.
            // Restore only a declared but omitted operation carrier; never
            // derive a shape from customer words or overwrite a conflicting
            // value. The later overview admission still rejects extra metrics,
            // predicates and ungrounded periods before any Reader runs.
            $overviewOperation=($value['request_kind']??null)==='open_overview'?'summary':
                ((($value['request_kind']??null)==='overview_comparison')?'comparison':null);
            if ($overviewOperation!==null && is_array($requirement)
                && in_array('operation',(array)($requirement['fields']??[]),true)
                && is_array($requirement['values']??null)
                && !array_key_exists('operation',$requirement['values'])) {
                $requirement['values']['operation']=$overviewOperation;
            }
            // A typed top/bottom ranking already fixes the response form.
            // Restore only the missing operation carrier; never infer a rank
            // from customer wording or from an unspecified ranking value.
            if (is_array($requirement) && in_array('operation',(array)($requirement['fields']??[]),true)
                && in_array('ranking',(array)($requirement['fields']??[]),true)
                && is_array($requirement['values']??null)
                && !array_key_exists('operation',$requirement['values'])
                && in_array($requirement['values']['ranking']['direction']??null,['top','bottom','top_and_bottom'],true)) {
                $requirement['values']['operation']='ranking';
            }
            $requirement=self::groundUniqueUnitConditionUpdate($requirement,$safeQuestion,$messages);
            $requirement=self::collapseVerifiedConditionResponseFormContinuation($requirement,$safeQuestion,$messages);
            $requirement=self::alignExplicitConditionResponseForm($requirement,$safeQuestion);
            $requirement=self::projectAggregateConditionFields($requirement);
            $requirementKeys = is_array($requirement) ? array_keys($requirement) : [];
            sort($requirementKeys, SORT_STRING);
            if (!in_array($requirementKeys, [['evidence', 'fields', 'id', 'meaning'], ['evidence', 'fields', 'id', 'meaning', 'values']], true)) self::fail('requirement_keys');
            if (!is_string($requirement['id'] ?? null)
                || !preg_match('/^r[1-9][0-9]{0,2}$/D', $requirement['id']) || isset($ids[$requirement['id']])) self::fail('requirement_id');
            if (!self::text($requirement['meaning'] ?? null, 240)) self::fail('requirement_meaning');
            $unknownFieldCategory=null;
            $fieldIssue=self::fieldIssue($requirement['fields']??null,$unknownFieldCategory);
            if ($fieldIssue!==null) self::fail('requirement_fields_'.$fieldIssue,$unknownFieldCategory);
            $evidence = $requirement['evidence'];
            if (!is_array($evidence) || count($evidence) < 1 || count($evidence) > 6
                || array_keys($evidence) !== range(0, count($evidence) - 1)) self::fail('requirement_evidence_collection');
            $seen = []; $located = [];
            foreach ($evidence as $item) {
                if ($item instanceof \stdClass) $item = get_object_vars($item);
                $itemKeys = is_array($item) ? array_keys($item) : [];
                sort($itemKeys, SORT_STRING);
                // A normalized server-owned record may re-enter this boundary
                // during the binding phase. Its start offset is never trusted:
                // we recalculate it below from the same de-identified message.
                if (!in_array($itemKeys, [['message_id', 'quote'], ['message_id', 'quote', 'start']], true) || !is_string($item['message_id'] ?? null)
                    || !isset($messages[$item['message_id']]) || !self::text($item['quote'] ?? null, 160)) self::fail('requirement_evidence_shape');
                $key = $item['message_id']."\0".$item['quote'];
                if (isset($seen[$key])) self::fail('requirement_evidence_duplicate');
                $seen[$key] = true;
                $starts = self::positions($messages[$item['message_id']], $item['quote']);
                // Selecting the first repeated word would make the model's
                // character counting decide business meaning.  When the
                // model supplied a real excerpt but did not make it unique,
                // retain the complete de-identified message as the audit
                // anchor instead.  It is one trusted customer message, not a
                // server-created condition; later semantic admission still
                // decides whether the proposed field is faithful. A quote
                // with different words remains invalid. Whitespace and
                // terminal-punctuation differences are formatting only, so
                // they can use the original de-identified message as their
                // anchor without teaching PHP any business synonym.
                if (count($starts) === 0) {
                    if (!self::formatEquivalentExcerpt($messages[$item['message_id']], $item['quote'])) self::fail('evidence_not_unique');
                    $located[] = ['message_id' => $item['message_id'], 'quote' => $messages[$item['message_id']], 'start' => 0];
                    continue;
                }
                if (count($starts) !== 1) {
                    if (mb_strlen($messages[$item['message_id']], 'UTF-8') > 16384) self::fail('evidence_not_unique');
                    $located[] = ['message_id' => $item['message_id'], 'quote' => $messages[$item['message_id']], 'start' => 0];
                    continue;
                }
                $located[] = ['message_id' => $item['message_id'], 'quote' => $item['quote'], 'start' => $starts[0]];
            }
            // An exact current store carrier can accompany a shorter current
            // excerpt (for example only the analytical subject). Extend that
            // evidence to the actual current message rather than losing a
            // literal identity. Never synthesize a name or borrow old text.
            $storeCarrier=$requirement['values']['store_term']??null;
            if (is_string($storeCarrier) && $storeCarrier!==''
                && strpos($messages['current'],$storeCarrier)!==false) {
                foreach ($located as $index=>$item) {
                    if ($item['message_id']==='current' && strpos($item['quote'],$storeCarrier)===false) {
                        $located[$index]=['message_id'=>'current','quote'=>$messages['current'],'start'=>0];
                        break;
                    }
                }
            }
            // Typed values are optional semantic-carrier detail.  They never
            // authorize a Reader request, and a malformed optional carrier
            // must not erase the independently grounded requirement itself.
            // The binding model receives the preserved meaning and evidence
            // and supplies its own executable candidate under the later,
            // strict execution contract.
            try {
                $values=self::values($requirement['values']??[],(array)$requirement['fields'],$located);
            } catch (AiContractException $error) {
                $diagnostic=$error->diagnostic();
                $requiresTypedValue=array_diff((array)$requirement['fields'],['metric_codes','unbound'])!==[];
                $requiresMetricCarrier=in_array('metric_codes',(array)$requirement['fields'],true)
                    && ($diagnostic['predicate']??null)==='values:metric_terms';
                if (($diagnostic['stage']??null)!=='intent_understanding_contract'
                    || strpos((string)($diagnostic['predicate']??''),'values:')!==0
                    // A declared typed condition whose carrier is missing
                    // would let the binding phase reinterpret a response
                    // form, object or time scope.  It is a repairable model
                    // structural error, not optional presentation detail.
                    || $requiresTypedValue || $requiresMetricCarrier
                    // A result reference is a request to narrow a later
                    // query to a private prior result.  It cannot be treated
                    // as an optional display carrier: losing its current-turn
                    // evidence would make a historical sentence authorize a
                    // new restriction.
                    || in_array('result_reference',(array)$requirement['fields'],true)) throw $error;
                $values=[];
            }
            // A calendar expression can establish when a query runs, but it
            // cannot itself be the business measurement.  Providers
            // occasionally put "this month" into metric_terms on a short
            // date continuation; accepting that carrier makes the later
            // binding model invent a second business change.  This checks a
            // closed time grammar only.  It does not map customer wording to
            // any business metric or decide whether the conversation changed
            // topic.
            if (in_array('metric_codes',(array)$requirement['fields'],true)
                && self::metricTermsAreOnlyPeriods($values['metric_terms']??null)) {
                self::fail('period_term_as_metric');
            }
            // “unbound” records a clear customer condition that the current
            // product cannot execute.  It is not a generic marker for an
            // ambiguous question: mixing the two previously let a later
            // clarification discard the condition and run a reduced query.
            if ($value['status'] !== 'understood' && in_array('unbound', (array)$requirement['fields'], true)) self::fail('unbound_requires_understood');
            $ids[$requirement['id']] = true;
            $normalized[] = ['id' => $requirement['id'], 'meaning' => trim($requirement['meaning']), 'fields' => array_values($requirement['fields']), 'values'=>$values, 'evidence' => $located];
        }
        if (($safeQuestion['prior_query'] ?? null) !== null && !isset($safeQuestion['clarification_context'])) {
            // prior_query is the only signed carrier of inherited meaning.
            // A model may cite an earlier sentence to explain a short turn,
            // but a requirement supported exclusively by that old sentence
            // is not a new customer requirement and must not be rebound as a
            // metric, object or condition. Requirements that also cite the
            // current message remain intact for ordinary semantic review.
            $currentRequirements=array_values(array_filter($normalized,static function(array $requirement): bool {
                foreach ((array)($requirement['evidence']??[]) as $evidence) {
                    if (($evidence['message_id']??null)==='current') return true;
                }
                return false;
            }));
            // A fully implicit turn still needs the ordinary clarification
            // path. Filter historical duplication only when the model also
            // produced at least one current, evidence-backed requirement.
            if ($currentRequirements!==[]) $normalized=$currentRequirements;
        }
        // Two model requirements may describe opposite ends of the *same*
        // ranking. Merge only identical typed measurements, object, period and
        // restrictions; a different query or an explicit group stays separate.
        // This repairs provider packaging, not customer language or a metric.
        if (empty($value['groups'])) $normalized=self::mergeOppositeRankingEnds($normalized,$messages['current']);
        // A top/bottom ordinal can only point into a verified ranked prior
        // answer.  This is a structural context invariant, not a phrase rule:
        // a count, list, summary or empty prior has no rank group to narrow.
        // Reject the model carrier once so the language pass can remove its
        // hallucinated reference while preserving the actual continuation.
        foreach ($normalized as $requirement) {
            if (!in_array('result_reference',(array)($requirement['fields']??[]),true)) continue;
            if (($safeQuestion['prior_query']['operation']??null)!=='ranking') {
                self::fail('result_reference_without_ranked_prior');
            }
        }
        // The period-only reuse path intentionally skips the second model
        // binding pass. It is safe only when the understanding model anchors
        // that *entire* current message as a time-only continuation. A short
        // excerpt such as just the time word could otherwise hide a new
        // business fact later in the same sentence and replay the verified
        // predecessor query. This checks evidence coverage, not a vocabulary,
        // metric name, report, or customer-specific phrase.
        $periodOnly=$normalized!==[];
        foreach ($normalized as $requirement) {
            if (($requirement['fields']??null)!==['periods']) {$periodOnly=false;break;}
        }
        if (($safeQuestion['prior_query'] ?? null) !== null && $periodOnly) {
            // Full-span evidence proves where the model read, not that it
            // preserved every meaning in that span. If the local structural
            // parser still sees a non-calendar business signal, a period-only
            // result is incomplete and must be repaired before any verified
            // condition, object or ranking can be inherited. This parser does
            // not select a metric or answer; it only closes the date-only
            // shortcut against complete new questions containing a date.
            $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse((string)($safeQuestion['question']??''));
            $calendarSignals=['TODAY','YESTERDAY','DAY_BEFORE_YESTERDAY','THIS_MONTH','LAST_MONTH'];
            $calendar=\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence((string)($safeQuestion['question']??''));
            $remainder=preg_replace('/[\s，,。？?！!、]+/u','',(string)($calendar['remainder']??''));
            // A signed condition set may explicitly say its condition stays
            // unchanged while only the reporting period moves. The bounded
            // residue is evidence of preservation, not a new business fact;
            // unknown extra words still fail the ordinary period-only gate.
            $sameCondition=isset($safeQuestion['prior_query']['aggregate_condition'])
                && ($calendar['complete']??false)===true
                && count($calendar['periods']??[])===1
                && in_array($remainder,['条件不变','其他条件不变','条件不变有多少人','其他条件不变有多少人',
                    '条件不变人数是多少','其他条件不变人数是多少'],true);
            // The local calendar parser deliberately does not translate every
            // natural-language week/quarter form. If the model provided one
            // typed calendar period, a whole-turn structural time expression
            // may reuse the signed query without pretending its unparsed time
            // word is a new business condition. Named metrics, objects and
            // restrictions still fail this closed shortcut.
            $modelOnlyCalendar=\app\services\ai\semantic\AiSemanticIntentParser::modelOnlyCalendarContinuation(
                (string)($safeQuestion['question']??''),(array)($normalized[0]['values']['periods']??[]));
            if (!$sameCondition && !$modelOnlyCalendar && (array_diff((array)($projection['signals']??[]),$calendarSignals)!==[]
                ||($projection['unresolved_condition']??false)
                ||(array)($projection['semantic_intent']['constraints']??[])!==[])) {
                self::fail('period_only_business_residue');
            }
            // Several period requirements for one short continuation are not
            // several customer conditions. They are provider duplication,
            // commonly one citation of the current phrase plus one citation
            // of the prior question. Reject that shape once so downstream
            // binding can never reinterpret the signed metric or ranking.
            if (count($normalized)!==1) self::fail('period_only_multiple');
            $current = $messages['current'] ?? '';
            $covered = false;
            foreach ($normalized[0]['evidence'] as $evidence) {
                if (($evidence['message_id'] ?? null) === 'current'
                    && ($evidence['start'] ?? null) === 0
                    && ($evidence['quote'] ?? null) === $current) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) self::fail('period_only_coverage');
        }
        $out=['goal' => trim($value['goal']), 'requirements' => $normalized, 'status' => $value['status']];
        // Recommendations never establish meaning. A provider may append
        // generic help to an empty understanding; discard that decoration so
        // a valid no-meaning result remains plain help, not a technical error.
        if ($suggestions!==[] && $value['status']==='needs_clarification' && $normalized!==[]) {
            if (!is_array($suggestions) || count($suggestions)!==3 || array_keys($suggestions)!==[0,1,2]) self::fail('clarification_suggestions');
            foreach ($suggestions as $suggestion) {
                if (!self::text($suggestion,120) || strpos($suggestion,'local_condition_')!==false
                    || preg_match('/\bsk-|https?:\/\/|[\x00-\x1f]/iu',$suggestion)) self::fail('clarification_suggestions');
            }
            $out['clarification_suggestions']=array_map('trim',$suggestions);
            if (count(array_unique($out['clarification_suggestions']))!==3) self::fail('clarification_suggestions');
        }
        if (isset($value['topic_relation'])) $out['topic_relation']=$value['topic_relation'];
        // The marker records model-understood request type only. It remains
        // non-executable until the gateway independently proves the complete
        // typed requirements, current evidence and registry overview profile.
        if (array_key_exists('request_kind',$value)) $out['request_kind']=$value['request_kind'];
        // Two independently registered measurements with two stated numeric
        // predicates cannot become one unqualified metric summary. This is
        // only a completeness check on a model interpretation: the registry
        // does not choose the subject, Boolean relation, thresholds or result
        // form. A failed check requests one bounded language repair and never
        // authorizes a query or silently drops either customer condition.
        if ($out['status']==='understood' && empty($value['groups'])) {
            $current=(string)($safeQuestion['question']??'');
            $terms=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText($current);
            preg_match_all('/[0-9]+(?:\.[0-9]+)?\s*(?:元|万元|件|次|个|项|人|笔)/u',$current,$numericPredicates);
            if (count($terms)>=2 && count((array)($numericPredicates[0]??[]))>=2
                && preg_match('/并且|而且|同时|以及|或者|(?<!或)且|(?<!或)或/u',$current)===1) {
                $conditionItems=[];
                foreach ($normalized as $requirement) {
                    foreach ((array)($requirement['values']['aggregate_condition']['conditions']??[]) as $item) {
                        $conditionItems[]=$item;
                    }
                }
                if (count($conditionItems)<count($terms)) self::fail('condition_completeness');
            }
        }
        if (array_key_exists('groups',$value)) {
            try {
                $groups=self::groups($value['groups'],$normalized);
            } catch (AiContractException $error) {
                // Groups are ownership metadata, not customer meaning.  When
                // the independently accepted requirements mechanically prove
                // their ownership, recover that metadata instead of asking a
                // model to restate the same analytical request merely because
                // an array carrier was malformed.
                $groups=self::derivedIndependentGroups($normalized)??self::derivedStoreViewGroups($normalized);
                // A non-collection request needs no group carrier at all.
                // Providers occasionally attach a one-item or stale prior
                // group to a short continuation; rejecting the independently
                // valid requirements would turn optional routing metadata
                // into a customer-visible failure. Only a mechanically proven
                // multi-result request receives derived groups above.
            }
        } else {
            $groups=self::derivedIndependentGroups($normalized)??self::derivedStoreViewGroups($normalized);
        }
        if ($groups!==null) $out['groups']=$groups;
        return $out;
    }

    /** Combine duplicate opposite extrema only when their executable meaning is identical. */
    private static function mergeOppositeRankingEnds(array $requirements,string $currentQuestion): array
    {
        if (count($requirements)!==2) return $requirements;
        $first=$requirements[0];$second=$requirements[1];
        $left=$first['values']??[];$right=$second['values']??[];
        if (($left['operation']??null)!=='ranking' || ($right['operation']??null)!=='ranking'
            || ($left['ranking']['limit']??null)!==1 || ($right['ranking']['limit']??null)!==1
            || !in_array([$left['ranking']['direction']??null,$right['ranking']['direction']??null],
                [['top','bottom'],['bottom','top']],true)) return $requirements;
        $leftFields=$first['fields']??[];$rightFields=$second['fields']??[];
        sort($leftFields,SORT_STRING);sort($rightFields,SORT_STRING);
        if ($leftFields!==$rightFields || !in_array('metric_codes',$leftFields,true)
            || !in_array('object_kind',$leftFields,true) || !in_array('periods',$leftFields,true)) return $requirements;
        // A provider can omit the repeated metric carrier on one end while
        // still naming that exact metric in both independently cited meanings.
        // Fill it only from that verbatim shared term; a different second
        // measurement must not inherit the first one's code by proximity.
        $leftTerms=$left['metric_terms']??null;$rightTerms=$right['metric_terms']??null;
        if ($leftTerms!==$rightTerms) {
            $terms=is_array($leftTerms)&&$rightTerms===null?$leftTerms:
                (is_array($rightTerms)&&$leftTerms===null?$rightTerms:null);
            $missing=$leftTerms===null?$first:$second;
            if ($terms===null || $terms===[] || isset($missing['values']['metric_exclusions'])) return $requirements;
            $explicitMetrics=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText($currentQuestion);
            if (count($terms)!==1 || count($explicitMetrics)!==1
                || ($explicitMetrics[0]['term']??null)!==$terms[0]) return $requirements;
            foreach ($terms as $term) {
                if (!is_string($term) || $term==='' || strpos((string)$missing['meaning'],$term)===false) return $requirements;
                $cited=false;
                foreach ((array)$missing['evidence'] as $item) {
                    if (($item['message_id']??null)==='current'
                        && strpos($currentQuestion,(string)($item['quote']??''))!==false
                        && strpos($currentQuestion,$term)!==false) $cited=true;
                }
                if (!$cited) return $requirements;
            }
            $left['metric_terms']=$terms;$right['metric_terms']=$terms;
        }
        $left['ranking']['direction']='top_and_bottom';
        $right['ranking']['direction']='top_and_bottom';
        if ($left!=$right) return $requirements;
        $evidence=$first['evidence'];$seen=[];
        foreach ($evidence as $item) $seen[$item['message_id']."\0".$item['quote']]=true;
        foreach ($second['evidence'] as $item) {
            $key=$item['message_id']."\0".$item['quote'];
            if (isset($seen[$key])) continue;
            if (count($evidence)>=6) return $requirements;
            $evidence[]=$item;$seen[$key]=true;
        }
        $first['values']=$left;$first['evidence']=$evidence;
        return [$first];
    }

    /** Reattach one model-extracted shared date to both ends of one ranking. */
    private static function attachSharedRankingPeriod(array $requirements): array
    {
        if (count($requirements)!==3) return $requirements;
        $dateIndex=null;$rankIndexes=[];
        foreach ($requirements as $index=>$requirement) {
            if (!is_array($requirement) || !is_array($requirement['fields']??null)
                || !is_array($requirement['values']??null)) return $requirements;
            $fields=$requirement['fields'];$values=$requirement['values'];
            if ($fields===['periods'] && self::periods($values['periods']??null)
                && count($values['periods'])===1) {
                if ($dateIndex!==null) return $requirements;
                $dateIndex=$index;continue;
            }
            if (!in_array($values['operation']??null,[null,'ranking'],true)
                || !in_array('periods',$fields,true)
                || !in_array('operation',$fields,true)
                || !in_array('ranking',$fields,true)
                || !in_array('metric_codes',$fields,true)
                || !in_array('object_kind',$fields,true)
                || (($values['ranking']['limit']??null)!==1)) return $requirements;
            $rankIndexes[]=$index;
        }
        if ($dateIndex===null || count($rankIndexes)!==2) return $requirements;
        $directions=array_map(static function($index) use ($requirements) {
            return $requirements[$index]['values']['ranking']['direction']??null;
        },$rankIndexes);
        sort($directions,SORT_STRING);
        if ($directions!==['bottom','top']) return $requirements;
        $date=$requirements[$dateIndex];
        $currentEvidence=array_filter((array)($date['evidence']??[]),static function($item): bool {
            return is_array($item) && ($item['message_id']??null)==='current';
        });
        if ($currentEvidence===[]) return $requirements;
        foreach ($rankIndexes as $index) {
            $existing=$requirements[$index]['values']['periods']??null;
            if ($existing!==null && $existing!==$date['values']['periods']) return $requirements;
        }
        foreach ($rankIndexes as $index) {
            $requirements[$index]['values']['periods']=$date['values']['periods'];
            foreach ($currentEvidence as $item) {
                if (!in_array($item,(array)($requirements[$index]['evidence']??[]),true)) {
                    $requirements[$index]['evidence'][]=$item;
                }
            }
        }
        unset($requirements[$dateIndex]);
        return array_values($requirements);
    }

    /**
     * A generic condition object already contains the analytical object,
     * response form and ordered measurement list. Project those redundant
     * carriers before validation so provider omission cannot erase a complete
     * customer condition. This performs no language interpretation and does
     * not alter relation, operator, quantity, unit or result form.
     */
    private static function projectAggregateConditionFields($requirement)
    {
        if (!is_array($requirement) || !is_array($requirement['fields']??null)
            || !is_array($requirement['values']??null)) return $requirement;
        $condition=$requirement['values']['aggregate_condition']??null;
        if (!is_array($condition) || !isset($condition['conditions']) || !self::aggregateCondition($condition)) return $requirement;
        foreach (['metric_codes','object_kind','object_relation','operation','aggregate_condition'] as $field) {
            if (!in_array($field,$requirement['fields'],true)) $requirement['fields'][]=$field;
        }
        $requirement['values']['metric_terms']=array_column($condition['conditions'],'metric_term');
        $requirement['values']['object_kind']=$condition['subject'];
        // A condition set selects rows of its stated subject for analysis;
        // it never selects one named target. Its relation is fixed by the
        // accepted condition carrier, not by a guessed customer synonym.
        if (!array_key_exists('object_relation',$requirement['values'])) {
            $requirement['values']['object_relation']='analysis';
        }
        $requirement['values']['operation']=$condition['result_form']==='count'?'condition_count':'condition_list';
        return $requirement;
    }

    /**
     * Drops only a redundant model-authored metric projection that is fully
     * represented by an independently valid period carrier. Grouped requests
     * stay on the strict repair path because removing one requirement there
     * could otherwise change ownership between independent requested results.
     */
    private static function discardPeriodOnlyMetricProjection($requirements,bool $hasGroups)
    {
        if ($hasGroups || !is_array($requirements) || $requirements===[]
            || array_keys($requirements)!==range(0,count($requirements)-1)) return $requirements;
        $hasValidPeriod=false;
        foreach ($requirements as $requirement) {
            $fields=is_array($requirement)?($requirement['fields']??null):null;
            $values=is_array($requirement)?($requirement['values']??null):null;
            if (is_array($fields) && in_array('periods',$fields,true)
                && is_array($values) && self::periods($values['periods']??null)) {
                $hasValidPeriod=true;
                break;
            }
        }
        if (!$hasValidPeriod) return $requirements;
        $normalized=[];
        foreach ($requirements as $requirement) {
            $fields=is_array($requirement)?($requirement['fields']??null):null;
            $values=is_array($requirement)?($requirement['values']??null):null;
            $isPeriodOnlyMetric=is_array($fields) && in_array('metric_codes',$fields,true)
                && is_array($values) && !array_key_exists('metric_exclusions',$values)
                && self::metricTermsAreOnlyPeriods($values['metric_terms']??null);
            if (!$isPeriodOnlyMetric) {$normalized[]=$requirement;continue;}
            $requirement['fields']=array_values(array_diff($fields,['metric_codes']));
            unset($requirement['values']['metric_terms']);
            // A requirement containing only the duplicated metric projection
            // contributes no customer meaning beyond the separate period row.
            if ($requirement['fields']===[]) continue;
            if ($requirement['values']===[]) unset($requirement['values']);
            $normalized[]=$requirement;
        }
        return $normalized===[]?$requirements:$normalized;
    }

    /**
     * A count-form phrase describes the output grain, not a second financial
     * measurement. Remove only a provider's metric field wholly contained in
     * that explicit phrase when another accepted requirement already carries
     * one complete, registry-owned condition. Every predicate and any other
     * fields on the provider row survive; unknown independent measurements,
     * exclusions and grouped requests still fail through the normal gate.
     */
    private static function discardConditionCountMetricProjection($requirements,array $safeQuestion,bool $hasGroups)
    {
        if ($hasGroups || !is_array($requirements) || count($requirements)<2) return $requirements;
        $question=$safeQuestion['question']??null;
        if (!is_string($question) || \app\services\ai\semantic\AiConditionResponseFormResolver::resolve($question)!=='count') return $requirements;
        $conditions=[];
        foreach ($requirements as $requirement) {
            $candidate=is_array($requirement)?($requirement['values']['aggregate_condition']??null):null;
            if (!is_array($candidate) || !isset($candidate['conditions'])) continue;
            if (!self::aggregateCondition($candidate) || ($candidate['result_form']??null)!=='count'
                || ($candidate['subject']??null)!=='member') return $requirements;
            foreach ($candidate['conditions'] as $condition) $conditions[]=$condition;
        }
        if (count($conditions)!==1) return $requirements;
        $conditionTerm=$conditions[0]['metric_term']??null;
        $owner=is_string($conditionTerm)
            ?\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText($conditionTerm):null;
        $stated=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText($question);
        if (!is_array($owner) || count($stated)!==1
            || ($stated[0]['metric_code']??null)!==$owner['metric_code']) return $requirements;
        // When the customer's entire sentence proves one registered money
        // predicate and one population count, provider-only extra metric names
        // cannot be independent customer conditions. The proof also checks the
        // operator and quantity, so a wrong model predicate is never repaired.
        $closedCount=\app\services\ai\semantic\AiConditionResponseFormResolver::closedMemberMoneyCount(
            $question,(string)$stated[0]['term'],$conditions[0]
        );
        $out=[];$changed=false;
        foreach ($requirements as $requirement) {
            $fields=is_array($requirement)?($requirement['fields']??null):null;
            $values=is_array($requirement)?($requirement['values']??null):null;
            if (!is_array($fields) || !in_array('metric_codes',$fields,true) || !is_array($values)
                || isset($values['aggregate_condition']) || !empty($values['metric_exclusions'])) {
                $out[]=$requirement;continue;
            }
            $terms=$values['metric_terms']??null;
            if (!is_array($terms) || $terms===[]) {$out[]=$requirement;continue;}
            if (!$closedCount) foreach ($terms as $term) {
                if (!is_string($term) || $term===''
                    || mb_strpos($question,$term,0,'UTF-8')===false
                    || !\app\services\ai\semantic\AiConditionResponseFormResolver::countPhraseContains($question,$term)
                    || \app\services\query\metric\MetricSemanticCatalog::uniqueTermInText($term)!==null) {
                    $out[]=$requirement;continue 2;
                }
            }
            $changed=true;
            $requirement['fields']=array_values(array_diff($fields,['metric_codes']));
            unset($requirement['values']['metric_terms']);
            if ($requirement['fields']===[]) continue;
            if ($requirement['values']===[]) unset($requirement['values']);
            $out[]=$requirement;
        }
        return $changed && $out!==[]?array_values($out):$requirements;
    }

    /**
     * Some providers repeat the complete signed predecessor condition when a
     * customer asks only to switch its presentation from list to count (or
     * back).  Repeating those metrics in current-turn evidence is invalid.
     * Collapse only an exact predecessor predicate: subject, relation,
     * ordered registered metric owners, operators, quantities and units must
     * all match.  A changed threshold or newly stated metric therefore keeps
     * the normal grounding gate and cannot be mistaken for inheritance.
     */
    private static function collapseVerifiedConditionResponseFormContinuation($requirement,array $safeQuestion,array $messages)
    {
        if (!is_array($requirement) || !is_array($requirement['fields']??null)
            || !is_array($requirement['values']??null)) return $requirement;
        $candidate=$requirement['values']['aggregate_condition']??null;
        $prior=$safeQuestion['prior_query']??null;
        $priorCondition=is_array($prior)?($prior['aggregate_condition']??null):null;
        if (!self::aggregateCondition($candidate) || !isset($candidate['conditions'])
            || !is_array($priorCondition) || !isset($priorCondition['conditions'])
            || !in_array($prior['operation']??null,['condition_count','condition_list'],true)
            || ($candidate['subject']??null)!==($priorCondition['subject']??null)
            || ($candidate['relation']??null)!==($priorCondition['relation']??null)
            || count($candidate['conditions'])!==count($priorCondition['conditions'])) return $requirement;
        $current=is_string($messages['current']??null)?$messages['current']:'';
        foreach ($candidate['conditions'] as $index=>$condition) {
            $previous=$priorCondition['conditions'][$index]??null;
            $code=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$condition['metric_term']]);
            if (!is_array($previous) || $code===null || $code!==($previous['metric_code']??null)
                || ($condition['operator']??null)!==($previous['operator']??null)
                || ($condition['quantity']??null)!==($previous['quantity']??null)
                || ($condition['unit']??null)!==($previous['unit']??null)) return $requirement;
            if (mb_strpos($current,$condition['metric_term'],0,'UTF-8')!==false
                || self::registeredTermGroundedInEvidence($condition['metric_term'],[['quote'=>$current]])) return $requirement;
        }
        $requirement['fields']=array_values(array_diff($requirement['fields'],['metric_codes','aggregate_condition']));
        if (!in_array('operation',$requirement['fields'],true)) $requirement['fields'][]='operation';
        unset($requirement['values']['metric_terms'],$requirement['values']['metric_exclusions'],$requirement['values']['aggregate_condition']);
        $requirement['values']['operation']=$candidate['result_form']==='count'?'condition_count':'condition_list';
        return $requirement;
    }

    /**
     * A terse threshold edit may omit the previous measurement name. When
     * exactly one signed predicate has the model-understood unit, its current
     * evidence uniquely identifies the delta without copying an unstated prior
     * label into the new turn. Multiple same-unit predicates remain ambiguous.
     */
    private static function groundUniqueUnitConditionUpdate($requirement,array $safeQuestion,array $messages)
    {
        if (!is_array($requirement) || !is_array($requirement['fields']??null)
            || !is_array($requirement['values']['condition_update']??null)) return $requirement;
        $fields=$requirement['fields'];sort($fields,SORT_STRING);
        if ($fields!==['condition_update']) return $requirement;
        $update=$requirement['values']['condition_update'];$unit=$update['unit']??null;
        if (!in_array($unit,['yuan','count','day'],true)) return $requirement;
        $prior=$safeQuestion['prior_query']['aggregate_condition']??null;
        if (!is_array($prior) || !is_array($prior['conditions']??null)) return $requirement;
        $matches=0;
        foreach ($prior['conditions'] as $condition) {
            if (is_array($condition) && ($condition['unit']??null)===$unit) $matches++;
        }
        if ($matches!==1) return $requirement;
        $current=$messages['current']??null;
        if (!is_string($current) || $current==='') return $requirement;
        $target=$update['target_term']??null;
        if (is_string($target) && $target!=='' && mb_strpos($current,$target,0,'UTF-8')!==false) return $requirement;
        foreach ((array)($requirement['evidence']??[]) as $evidence) {
            if (($evidence['message_id']??null)!=='current' || !is_string($evidence['quote']??null)
                || $evidence['quote']==='' || mb_strpos($current,$evidence['quote'],0,'UTF-8')===false) continue;
            $requirement['values']['condition_update']['target_term']=$evidence['quote'];
            return $requirement;
        }
        return $requirement;
    }

    public static function ids(array $understanding): array
    {
        $ids = [];
        foreach ((array)($understanding['requirements'] ?? []) as $requirement) if (is_string($requirement['id'] ?? null)) $ids[] = $requirement['id'];
        return $ids;
    }

    /**
     * A deterministic server date parser may correct only the typed carrier
     * of an already accepted period-only follow-up. It cannot create a period
     * requirement, remove another requirement or change any business meaning.
     */
    public static function withResolvedPeriodOnly(array $understanding,array $periods): array
    {
        if (!self::periods($periods)) throw new AiContractException('AI_MODEL_INPUT_INVALID');
        if (($understanding['status']??null)!=='understood'
            || count((array)($understanding['requirements']??[]))!==1
            || ($understanding['requirements'][0]['fields']??null)!==['periods']) return $understanding;
        $understanding['requirements'][0]['values']['periods']=$periods;
        return $understanding;
    }

    /**
     * Corrects one explicitly stated period, including identical copies carried
     * by several metrics. The caller must establish a single unambiguous date
     * in the current question. Multiple carriers must agree and cite only the
     * current turn; comparisons and historical windows are never collapsed.
     * This cannot create conditions or change measurements/nested predicates.
     */
    public static function withResolvedSinglePeriod(array $understanding,array $periods): array
    {
        if (!self::periods($periods) || count($periods)!==1
            || !in_array($understanding['status']??null,['understood','needs_clarification'],true)) {
            throw new AiContractException('AI_MODEL_INPUT_INVALID');
        }
        $matches=[];
        foreach ((array)($understanding['requirements']??[]) as $index=>$requirement) {
            if (in_array('periods',(array)($requirement['fields']??[]),true)) $matches[]=$index;
        }
        if ($matches===[]) return $understanding;
        $shared=null;
        // Validate every carrier before updating any: an independent window
        // must not leave a partially corrected multi-metric request behind.
        foreach ($matches as $index) {
            $requirement=$understanding['requirements'][$index];
            $original=(array)($requirement['values']['periods']??[]);
            if (count($original)!==1 || !self::periods($original)) return $understanding;
            if (count($matches)>1) {
                if (empty($requirement['evidence'])) return $understanding;
                foreach ($requirement['evidence'] as $evidence) {
                    if (($evidence['message_id']??null)!=='current') return $understanding;
                }
                if ($shared!==null && $original!=$shared) return $understanding;
            }
            $shared=$original;
        }
        foreach ($matches as $index) $understanding['requirements'][$index]['values']['periods']=$periods;
        return $understanding;
    }

    /**
     * An explicit population-list/count phrase is a response-form constraint,
     * not a metric synonym. Correct only that redundant carrier after the
     * model has preserved the complete typed condition set. This prevents a
     * number inside a predicate from turning an explicit list into a count,
     * without teaching PHP how to choose a metric, predicate or population.
     */
    private static function alignExplicitConditionResponseForm(array $requirement,array $safeQuestion): array
    {
        $condition=$requirement['values']['aggregate_condition']??null;
        if (!is_array($condition)||!is_array($condition['conditions']??null)||$condition['conditions']===[]) return $requirement;
        $expected=\app\services\ai\semantic\AiConditionResponseFormResolver::resolve(
            (string)($safeQuestion['question']??'')
        );
        if ($expected===null||!in_array($condition['result_form']??null,['count','list'],true)) return $requirement;
        $requirement['values']['aggregate_condition']['result_form']=$expected;
        if (in_array('operation',(array)($requirement['fields']??[]),true)) {
            $requirement['values']['operation']=$expected==='list'?'condition_list':'condition_count';
        }
        return $requirement;
    }

    /**
     * A completed provider response may be corrected once only when it failed
     * this structural contract. Transport, authority and unknown outcomes are
     * deliberately never retried as if they were a missing customer detail.
     */
    public static function repairable(?string $predicate): bool
    {
        return is_string($predicate) && $predicate !== ''
            && preg_match('/^(shape|goal|status|requirements|requirements_collection|requirement_shape|requirement_keys|requirement_id|requirement_meaning|requirement_fields(?:_(?:shape|empty|too_many|duplicate|unknown))?|requirement_evidence_collection|requirement_evidence_shape|requirement_evidence_duplicate|groups|message_projection|evidence_not_unique|period_only_business_residue|period_only_coverage|period_only_multiple|period_term_as_metric|result_reference_without_ranked_prior|condition_completeness|values(?::[a-z_]+)?)$/D',$predicate) === 1;
    }

    /** A structural correction never interprets a customer phrase in PHP. */
    public static function repairInstruction(string $predicate): string
    {
        if (!self::repairable($predicate)) throw new AiContractException('AI_MODEL_INPUT_INVALID');
        if ($predicate === 'shape') {
            // A correct period emitted beside requirements is still an
            // invalid carrier; repair structure without changing its meaning.
            return 'Return exactly one intent-understanding JSON object. Top-level keys are goal, requirements and status, with only documented optional keys. Never put periods, ranking, object_kind, operation or metric_terms at top level. Each such meaning belongs in one evidence-backed requirement: declare its protocol field in fields and put the complete typed value under that same requirement.values. Keep all customer conditions and their original evidence; move misplaced values without calculating dates or changing business meaning.';
        }
        if ($predicate === 'condition_completeness') {
            return 'The previous understanding dropped at least one separately stated numerical business condition. Re-read the entire current customer message, not a prior answer. Preserve every measured term, comparison, amount and unit as one complete aggregate_condition over the customer-stated subject. Preserve the explicit AND/OR relation and count/list result form. Ground each condition in the exact current evidence; do not choose a metric code, assume a missing value, merge two predicates, or turn the question into a one-metric summary. If any predicate remains genuinely unclear, keep the understood parts and use needs_clarification instead of executing a reduced query.';
        }
        if ($predicate === 'requirement_fields_unknown') {
            // Correct only the carrier, never the customer meaning. In
            // particular a values-only key is not another semantic field.
            return 'The previous response used an unsupported requirement.fields name. Use only these fields: '.implode(', ',self::MODEL_FIELDS).'. Put exact customer measurement words in values.metric_terms or values.metric_exclusions while declaring metric_codes in fields; neither values key belongs in fields. Keep every customer condition, requirement meaning and exact current-message evidence. Move only a misplaced supported value key into values with its corresponding field; for any other unsupported name, express the original meaning using supported fields and preserve the complete evidence. Do not silently discard, add, bind or replace a condition.';
        }
        if ($predicate === 'period_only_business_residue') {
            return 'The previous response reduced the complete current message to a date-only continuation, but a separate structural check found non-calendar business meaning in that same current message. Re-read the whole current message and preserve every additional goal, measurement, object, result form, ranking, scope or condition as its own evidence-backed requirement. Do not copy the prior query, guess a metric code, or satisfy this correction merely by expanding the period evidence quote.';
        }
        if ($predicate === 'period_only_coverage') {
            return 'The previous response called this a time-only continuation but its evidence did not cover the complete current customer message. Re-read the whole current message. Return only one periods requirement only when the whole message changes no business fact, object, result form, range, ranking, scope, condition or measurement. Otherwise preserve every additional current meaning with its own requirement and current-message evidence. Do not invent, bind or select a metric.';
        }
        if ($predicate === 'groups') {
            return 'The previous response used malformed groups for independently requested analytical results. Return the same complete understanding again with no invented business meaning. If the current customer asks for two to four independent results, groups must be an array of two to four objects, each having exactly id and requirement_ids. Use unique ids q1, q2, q3 or q4. Each requirement_ids value must be a nonempty JSON array of unique existing requirement ids, and every emitted requirement id must appear in at least one group. A shared date, ranking or scope requirement may occur in every applicable group. Do not output groups for conditions over one population, a one-subject summary, or an ambiguous request. Preserve every requirement, its evidence and its customer meaning; correct only the groups carrier.';
        }
        if ($predicate === 'period_only_multiple') {
            return 'The previous response duplicated a time-only continuation into several period requirements. Re-read the complete current message and return exactly one periods requirement with complete current-message evidence. Do not repeat the prior question as another requirement and do not add, replace or copy its metric, object, result form, ranking, scope or condition; the verified prior query is inherited later.';
        }
        if ($predicate === 'period_term_as_metric') {
            return 'The previous response used a calendar expression as a business measurement. Re-read the complete current customer message and keep time only in a periods requirement. If the customer changes only the time of a verified prior query, return one periods requirement with complete current-message evidence and no metric_codes. If the customer also names a business measurement, preserve that separate measurement exactly; do not invent one from the date or copy a prior metric into current wording.';
        }
        if ($predicate === 'evidence_not_unique') {
            return 'The previous response used an evidence quote that was not an exact unique excerpt of the de-identified current message. Return the same complete understanding again, but for every current requirement use the complete de-identified current message verbatim as its evidence quote. Do not restore a hidden name, shorten, paraphrase, add, remove, reinterpret or bind any requirement.';
        }
        if ($predicate === 'result_reference_without_ranked_prior') {
            return 'The previous response added a result_reference although the verified prior query is not a ranking and therefore has no top or bottom rank group to reference. Re-read only the complete current customer message and return its actual continuation meaning. Remove result_reference without removing the requested response form, period, object or other current condition. Do not invent a rank, metric, result or identity.';
        }
        if (strpos($predicate, 'values:') === 0) {
            $key = substr($predicate, strlen('values:'));
            if ($key === 'missing_typed') {
                return 'The previous response declared a clear object, response form, period, ranking, scope or result reference but omitted its typed value. Return the complete understanding again. Preserve the same customer meaning and include every declared typed field with its complete valid value; do not bind it to an indicator or add a condition.';
            }
            if ($key === 'comparison_ranking_conflict') {
                return 'The previous response combined a comparison response form with a ranked leading-or-trailing result. Return the complete understanding again and preserve the actual response form requested by the customer. Do not invent another period, indicator or condition.';
            }
            if ($key === 'metric_terms') {
                return 'The previous response declared metric_codes without an exact customer measurement or exclusion. Return the complete understanding again. Every metric_codes requirement must carry a valid values.metric_terms or values.metric_exclusions from its evidence. Copy each term character-for-character from the de-identified current message; a shorter exact substring is valid, but a canonical title, inserted object qualifier, paraphrase or combined conjunction is not. Separate grammar roles before copying: in “每位员工销售业绩多少”, employee is the analytical object, sales performance is the exact measurement, and each requests breakdown, so metric_terms is [“销售业绩”], not [“员工业绩”]. Apply this structure generically to the actual message. Preserve every independently named measurement as its own term. If the customer did not state a measurement in that requirement, remove metric_codes and retain only the actually expressed conditions; do not invent a metric or use a prior metric as current customer wording. For a continuation that only changes time, return a periods requirement with a complete values.periods carrier and current-message evidence, but no metric_codes; the verified prior query is handled later by context, not copied into current customer wording.';
            }
            if ($key === 'aggregate_condition') {
                return 'The previous response used an aggregate condition that was not grounded in the current customer message. Re-read only the complete current message. If it merely changes the response form of a verified prior condition query, such as asking “有几个” after a list, return only an operation requirement with values.operation=condition_count or condition_list and current-message evidence; omit metric_codes, object_kind and aggregate_condition because the signed prior query is inherited later by the context merger. If the current message itself states one or more conditions, return the complete understanding again and preserve all of those current conditions. For a generic object-set condition use exactly subject, relation, result_form and conditions, plus expression only for a mixed grouping; each condition item uses metric_term, operator, quantity and unit, plus period when the customer dates that specific predicate. metric_term must be copied character-for-character from the current de-identified customer evidence, never paraphrased or rewritten to a canonical label. The same requirement must declare metric_codes and values.metric_terms; values.metric_terms must contain exactly the condition metric_term values in the same order. It must also declare object_kind equal to subject and operation equal to condition_count for result_form=count or condition_list for result_form=list. You may keep all current conditions in one requirement or split them into one condition per requirement, but never copy an unstated prior predicate into current evidence. operator is gte, gt, lte, lt or eq. quantity is a JSON string containing the normalized decimal, never a JSON number: “5万元” becomes "50000" with unit "yuan", “24次” becomes "24" with unit "count", and “90天” becomes "90" with unit "day". Do not add a registered metric code, remove a current predicate or change AND/OR.';
            }
            if (in_array($key, ['object_kind', 'object_relation', 'operation', 'periods', 'ranking', 'scope', 'result_reference', 'aggregate_condition'], true)) {
                return 'The previous response included malformed values.'.$key.'. Return the complete understanding again. Preserve the same already understood customer condition; either emit a complete valid values.'.$key.' in that requirement or omit that optional detail without removing, reinterpreting, binding or replacing the condition.';
            }
        }
        return 'The previous completed understanding response did not satisfy the required structure ('.$predicate.'). Return one complete intent-understanding object again. Correct only the structural omission or evidence excerpt; do not add, remove, reinterpret or bind any customer requirement.';
    }

    /** @return array<string,array{fields:array,values:array,evidence:array}> */
    public static function requirements(array $understanding): array
    {
        $out=[];
        foreach ((array)($understanding['requirements'] ?? []) as $requirement) {
            if (is_string($requirement['id'] ?? null)) $out[$requirement['id']]=[
                'fields'=>(array)($requirement['fields']??[]),'values'=>(array)($requirement['values']??[]),'evidence'=>(array)($requirement['evidence']??[])];
        }
        return $out;
    }

    /** @return array<int,array{id:string,requirement_ids:array<int,string>}> */
    public static function queryGroups(array $understanding): array
    {
        $groups=$understanding['groups']??null;
        if (!is_array($groups) || $groups===[]) return [['id'=>'q1','requirement_ids'=>self::ids($understanding)]];
        return $groups;
    }

    /** Groups are semantic ownership only; they never add an executable query. */
    private static function groups($groups,array $requirements): array
    {
        if (!is_array($groups) || count($groups)<2 || count($groups)>4 || array_keys($groups)!==range(0,count($groups)-1)) self::fail('groups');
        $known=[];foreach($requirements as $requirement) $known[$requirement['id']]=true;
        $seen=[];$covered=[];$out=[];
        foreach($groups as $group) {
            if ($group instanceof \stdClass) $group=get_object_vars($group);
            $groupKeys=is_array($group)?array_keys($group):[];sort($groupKeys,SORT_STRING);
            if (!is_array($group) || $groupKeys!==['id','requirement_ids'] || !is_string($group['id']??null)
                || !preg_match('/^q[1-4]$/D',$group['id']) || isset($seen[$group['id']])
                || !is_array($group['requirement_ids']) || $group['requirement_ids']===[] || count($group['requirement_ids'])>12
                || array_keys($group['requirement_ids'])!==range(0,count($group['requirement_ids'])-1)) self::fail('groups');
            $local=[];foreach($group['requirement_ids'] as $id) {
                if (!is_string($id) || !isset($known[$id]) || isset($local[$id])) self::fail('groups');
                $local[$id]=true;$covered[$id]=true;
            }
            $seen[$group['id']]=true;$out[]=['id'=>$group['id'],'requirement_ids'=>array_keys($local)];
        }
        if (array_diff_key($known,$covered)!==[]) self::fail('groups');
        return $out;
    }

    /**
     * Derive result ownership only from already accepted typed semantics. This
     * is deliberately narrower than language interpretation: it admits two
     * to four distinct analytical ranking dimensions and leaves conditions,
     * comparisons, selections and one-subject summaries untouched.
     *
     * @return array<int,array{id:string,requirement_ids:array<int,string>}>|null
     */
    private static function derivedIndependentGroups(array $requirements): ?array
    {
        $operation=null;$hasRanking=false;$byObject=[];$shared=[];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement) || !is_string($requirement['id']??null)) return null;
            $fields=(array)($requirement['fields']??[]);$values=(array)($requirement['values']??[]);
            if (in_array('operation',$fields,true)) {
                $candidate=$values['operation']??null;
                if (!is_string($candidate) || ($operation!==null && $operation!==$candidate)) return null;
                $operation=$candidate;
            }
            if (in_array('ranking',$fields,true)) $hasRanking=true;
            if (!in_array('object_kind',$fields,true)) {$shared[]=$requirement['id'];continue;}
            $object=$values['object_kind']??null;
            if (!is_string($object) || in_array($object,['person','store','unknown'],true)
                // Object relation is optional at the semantic boundary. In a
                // multi-dimension ranking, the typed operation already makes
                // an omitted relation analytical; only an explicit selection
                // may block independent-result ownership.
                || (($values['object_relation']??'analysis')!=='analysis')) return null;
            $byObject[$object][]=$requirement['id'];
        }
        if ($operation!=='ranking' || !$hasRanking || count($byObject)<2 || count($byObject)>4) return null;
        $out=[];$index=1;
        foreach ($byObject as $ids) {
            $out[]=['id'=>'q'.$index,'requirement_ids'=>array_values(array_unique(array_merge($ids,$shared)))];
            $index++;
        }
        return $out;
    }

    /**
     * Recover omitted grouping only when the model already accepted two
     * different store result forms over the same explicit period. The grouped
     * breakdown and ranking still pass independent metric binding and Reader
     * capability checks; this never divides a condition or guesses a metric.
     */
    private static function derivedStoreViewGroups(array $requirements): ?array
    {
        if (count($requirements)<2 || count($requirements)>8) return null;
        $byOperation=[];$period=null;
        foreach ($requirements as $requirement) {
            if (!is_array($requirement) || !is_string($requirement['id']??null)) return null;
            $values=(array)($requirement['values']??[]);
            $operation=$values['operation']??null;
            if (!in_array($operation,['breakdown','ranking'],true)
                || ($values['object_kind']??null)!=='store'
                || ($values['object_relation']??'analysis')!=='analysis'
                || !is_array($values['periods']??null) || count($values['periods'])!==1
                || !is_array($values['metric_terms']??null) || $values['metric_terms']===[]
                || isset($values['aggregate_condition']) || isset($values['store_term'])
                || isset($values['result_reference'])
                || isset($values['metric_exclusions'])) return null;
            if ($period!==null && $period!==$values['periods']) return null;
            $period=$values['periods'];
            $ranking=$values['ranking']??null;
            if ($operation==='ranking' && (!is_array($ranking)
                || !in_array($ranking['direction']??null,['top','bottom','top_and_bottom'],true))) return null;
            if ($operation==='breakdown' && is_array($ranking)
                && ($ranking['direction']??'unspecified')!=='unspecified') return null;
            $byOperation[$operation][]=$requirement['id'];
        }
        if (count($byOperation)!==2 || !isset($byOperation['breakdown'],$byOperation['ranking'])) return null;
        return [
            ['id'=>'q1','requirement_ids'=>$byOperation['breakdown']],
            ['id'=>'q2','requirement_ids'=>$byOperation['ranking']],
        ];
    }

    /**
     * Validates only that a proposed measurement term is one whole temporal
     * expression under the shared closed date grammar.  Business vocabulary
     * remains model-owned and is never inspected here.
     */
    private static function metricTermsAreOnlyPeriods($terms): bool
    {
        if (!is_array($terms) || $terms===[]) return false;
        require_once dirname(__DIR__).'/semantic/AiSemanticIntentParser.php';
        $parser=new \app\services\ai\semantic\AiSemanticIntentParser();
        foreach ($terms as $term) {
            if (!is_string($term) || trim($term)==='') return false;
            $projection=$parser->parse(trim($term));
            $dateTerms=(array)($projection['date_terms']??[]);
            $signals=array_values(array_filter((array)($projection['signals']??[]),static function($signal): bool {
                return is_string($signal) && $signal!=='';
            }));
            $date=$dateTerms[0]??null;
            if (($projection['date_grouping_ambiguous']??true) || count($dateTerms)!==1 || !is_array($date)
                || !is_string($date['code']??null) || $signals!==[$date['code']]) return false;
        }
        return true;
    }

    private static function messages(array $safeQuestion): array
    {
        $messages = $safeQuestion['evidence_messages'] ?? null;
        if (!is_array($messages) || count($messages) < 1 || count($messages) > 21
            || array_keys($messages) !== range(0, count($messages) - 1)) self::fail('message_projection');
        $out = [];
        foreach ($messages as $message) {
            if ($message instanceof \stdClass) $message = get_object_vars($message);
            $keys = is_array($message) ? array_keys($message) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['id', 'text'] || !is_string($message['id'] ?? null)
                || !preg_match('/^(current|original|recent_[1-9][0-9]?)$/D', $message['id'])
                || isset($out[$message['id']]) || !self::text($message['text'] ?? null, 16384)) self::fail('message_projection');
            $out[$message['id']] = $message['text'];
        }
        if (!isset($out['current']) || $out['current'] !== ($safeQuestion['question'] ?? null)) self::fail('message_projection');
        return $out;
    }

    private static function positions(string $text, string $needle): array
    {
        $positions = []; $offset = 0;
        while (($position = mb_strpos($text, $needle, $offset, 'UTF-8')) !== false) {
            $positions[] = $position; $offset = $position + max(1, mb_strlen($needle, 'UTF-8'));
        }
        return $positions;
    }

    /** Tolerates presentation spacing only; never maps a business word or synonym. */
    private static function formatEquivalentExcerpt(string $message, string $quote): bool
    {
        $normalize = static function (string $text): string {
            $value = preg_replace('/[\\s\\p{P}]+/u', '', $text);
            return is_string($value) ? $value : '';
        };
        $needle = $normalize($quote);
        return mb_strlen($needle, 'UTF-8') >= 2 && mb_strpos($normalize($message), $needle, 0, 'UTF-8') !== false;
    }

    private static function text($value, int $maximum): bool
    {
        return is_string($value) && trim($value) !== '' && preg_match('//u', $value) === 1
            && mb_strlen($value, 'UTF-8') <= $maximum && !preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    private static function fields($value): bool
    {
        return self::fieldIssue($value)===null;
    }

    private static function fieldIssue($value,?string &$unknownCategory=null): ?string
    {
        $allowed=array_merge(self::MODEL_FIELDS,self::LEGACY_FIELDS);
        if (!is_array($value) || ($value!==[]&&array_keys($value)!==range(0,count($value)-1))) return 'shape';
        if ($value===[]) return 'empty';
        // A complete requirement can legitimately carry metric, subject,
        // relation, period, ranking and independent location together. Bound
        // by the declared vocabulary, not an obsolete six-field transport cap.
        if (count($value)>count($allowed)) return 'too_many';
        if (count(array_unique($value))!==count($value)) return 'duplicate';
        foreach ($value as $field) if (!is_string($field) || !in_array($field,$allowed,true)) {
            // This closed category is safe for a 24-hour Run diagnostic; the
            // raw provider field may contain customer text and is never saved.
            $unknownCategory=in_array($field,['metric_terms','metric_exclusions'],true)?'value_key':'other';
            return 'unknown';
        }
        return null;
    }

    /**
     * `values` is not a second metric catalogue. It records only the bounded
     * effects already understood from this one customer request, so the binding
     * phase cannot turn "top five" into "top nine" or rewrite its period.
     */
    private static function values($value,array $fields,array $evidence): array
    {
        if (!is_array($value)) self::fail('values:shape');
        $allowed=array_merge(['metric_exclusions','metric_terms'],array_values(array_diff(self::MODEL_FIELDS,['metric_codes','unbound'])),self::LEGACY_FIELDS);
        // `values` is an optional transport carrier, not a place where an
        // unexpected key can grant authority. Drop an unrecognised or
        // field-mismatched extra while preserving valid typed meaning in the
        // same requirement; otherwise a harmless model decoration would
        // erase an already-understood period or response form.
        foreach (array_keys($value) as $key) {
            if (!in_array($key,$allowed,true)) { unset($value[$key]); continue; }
            $field=in_array($key,['metric_terms','metric_exclusions'],true)?'metric_codes':$key;
            if (!in_array($field,$fields,true)) unset($value[$key]);
        }
        // Metric words remain natural-language evidence and may not have a
        // typed execution value yet. Every other declared field is a model
        // assertion about object, response form, time, ranking, scope or a
        // prior-result relation. Carrying its bounded value prevents the
        // later binding model from silently changing understood meaning.
        foreach ($fields as $field) {
            if (in_array($field,['metric_codes','unbound'],true)) continue;
            if (!array_key_exists($field,$value)) self::fail('values:missing_typed',$field);
        }
        if (isset($value['object_kind']) && !in_array($value['object_kind'],['store','business_date','person','position','guide','sales_manager','member','product','project','category','partner','inventory','course','organization','order','sale_line','card','unknown'],true)) self::fail('values:object_kind');
        if (isset($value['object_relation']) && !in_array($value['object_relation'],['analysis','selection'],true)) self::fail('values:object_relation');
        if (isset($value['operation']) && !in_array($value['operation'],['summary','breakdown','trend','ranking','comparison','threshold_count','condition_count','condition_list','definition','unknown'],true)) self::fail('values:operation');
        // A declared condition carrier must contain its full typed set.
        // JSON null is not an understood condition and must enter the one
        // bounded repair, otherwise a later path sees a present-but-empty
        // condition and can silently lose one of the customer's predicates.
        if (array_key_exists('aggregate_condition',$value)) {
            if (!self::aggregateCondition($value['aggregate_condition'])) self::fail('values:aggregate_condition','shape');
            // A generic condition label is the semantic key later bound to
            // one registered metric. Prefer an exact excerpt. Natural speech
            // can separate a registered concept from its counter (for
            // example a verb followed by “at least N times”), while a model
            // may return another *registered* alias for that same concept.
            // Admit only registry-proven alias equivalence; unknown or
            // cross-metric paraphrases still fail closed. This keeps language
            // in the dictionary instead of teaching this contract phrases.
            if (isset($value['aggregate_condition']['conditions'])) {
                foreach ($value['aggregate_condition']['conditions'] as $condition) {
                    $found=false;
                    foreach ($evidence as $item) {
                        if (mb_strpos($item['quote'],$condition['metric_term'],0,'UTF-8')!==false) {
                            $found=true;
                            break;
                        }
                    }
                    if (!$found && !self::registeredTermGroundedInEvidence($condition['metric_term'],$evidence)) {
                        self::fail('values:aggregate_condition','grounding');
                    }
                }
            }
        }
        if (isset($value['condition_update'])) {
            if (!self::conditionUpdate($value['condition_update'])) self::fail('values:condition_update','shape');
            $grounded=false;
            foreach ($evidence as $item) if (mb_strpos($item['quote'],$value['condition_update']['target_term'],0,'UTF-8')!==false) {
                $grounded=true;break;
            }
            if (!$grounded) self::fail('values:condition_update','grounding');
        }
        if (isset($value['scope']) && !in_array($value['scope'],['current_store','authorized','unspecified'],true)) self::fail('values:scope');
        // Store identity remains an evidence-grounded phrase, never a model
        // supplied ID. Only the current authorized catalogue may resolve it.
        if (array_key_exists('store_term',$value)) {
            if (!self::text($value['store_term'],160)) self::fail('values:store_term','shape_'.gettype($value['store_term']));
            $grounded=false;
            foreach ($evidence as $item) if (($item['message_id']??null)==='current'
                && mb_strpos($item['quote'],$value['store_term'],0,'UTF-8')!==false) $grounded=true;
            if (!$grounded) self::fail('values:store_term','grounding');
        }
        if (isset($value['ranking'])) {
            $ranking=$value['ranking'];$rankingKeys=is_array($ranking)?array_keys($ranking):[];sort($rankingKeys,SORT_STRING);
            if ($rankingKeys!==['direction','limit'] || !in_array($ranking['direction']??null,['top','bottom','top_and_bottom','unspecified'],true)
                || (!is_null($ranking['limit']??null)&&(!is_int($ranking['limit'])||$ranking['limit']<1||$ranking['limit']>999))) self::fail('values:ranking');
        }
        if (isset($value['periods']) && !self::periods($value['periods'])) self::fail('values:periods');
        if (isset($value['result_reference'])) {
            $reference=$value['result_reference'];$referenceKeys=is_array($reference)?array_keys($reference):[];sort($referenceKeys,SORT_STRING);
            if ($referenceKeys!==['group','ordinal'] || !in_array($reference['group']??null,['top','bottom'],true)
                || !is_int($reference['ordinal']??null) || $reference['ordinal']<1 || $reference['ordinal']>1000) self::fail('values:result_reference');
            // A previous question may provide conversational background, but
            // only this customer turn can request narrowing to a displayed
            // result row. Otherwise a model could turn an old reference into
            // a new, unasked-for data filter.
            if (!array_filter($evidence, static function(array $item): bool { return $item['message_id']==='current'; })) {
                self::fail('values:result_reference_current_evidence');
            }
        }
        if (isset($value['member_detail'])) {
            $detail=$value['member_detail'];$detailKeys=is_array($detail)?array_keys($detail):[];sort($detailKeys,SORT_STRING);
            if ($detailKeys!==['ordinal','target','view']
                || !in_array($detail['view']??null,['rights','summary'],true)
                || !in_array($detail['target']??null,['single','set'],true)
                || (!is_null($detail['ordinal']??null)
                    && (!is_int($detail['ordinal'])||$detail['ordinal']<1||$detail['ordinal']>1000))
                || (($detail['target']??null)==='set' && ($detail['ordinal']??null)!==null)) {
                self::fail('values:member_detail');
            }
            // A member-detail transition must be requested by this turn. A
            // historical phrase cannot silently turn a new analytics question
            // into a disclosure of private member assets.
            if (!array_filter($evidence, static function(array $item): bool { return $item['message_id']==='current'; })) {
                self::fail('values:member_detail_current_evidence');
            }
        }
        if (isset($value['object_detail'])) {
            $detail=$value['object_detail'];$detailKeys=is_array($detail)?array_keys($detail):[];sort($detailKeys,SORT_STRING);
            if ($detailKeys!==['ordinal','target','view']
                || !in_array($detail['view']??null,['rights','summary'],true)
                || !in_array($detail['target']??null,['single','set'],true)
                || (!is_null($detail['ordinal']??null)
                    && (!is_int($detail['ordinal'])||$detail['ordinal']<1||$detail['ordinal']>1000))
                || (($detail['target']??null)==='set' && ($detail['ordinal']??null)!==null)) {
                self::fail('values:object_detail');
            }
            // The typed carrier records only meaning stated in this turn. The
            // server, not the model, resolves an identity from signed results.
            if (!array_filter($evidence, static function(array $item): bool { return $item['message_id']==='current'; })) {
                self::fail('values:object_detail_current_evidence');
            }
        }
        foreach (['metric_terms','metric_exclusions'] as $key) if (isset($value[$key])) {
            // These are optional evidence aids for the binding model, rather
            // than a second attempt to mechanically restate customer prose.
            // A model can understand the request correctly while quoting a
            // nearby paraphrase.  Keep the requirement, its meaning and its
            // server-located evidence in that case; dropping only this aid is
            // safer than converting a correct understanding into a technical
            // failure or asking the customer to repeat themselves.
            // A generic condition can repeat the same measurement for its
            // lower and upper bounds; only the condition carrier, not a
            // deduplicated audit list, determines predicate cardinality.
            $repeatedConditionTerms=$key==='metric_terms' && isset($value['aggregate_condition']['conditions']);
            if (!is_array($value[$key]) || $value[$key]===[] || count($value[$key])>8
                || (!$repeatedConditionTerms && count(array_unique($value[$key]))!==count($value[$key]))) {
                unset($value[$key]); continue;
            }
            foreach ($value[$key] as $term) {
                if (!self::text($term,160)) { unset($value[$key]); continue 2; }
                $found=false;foreach($evidence as $item) if (mb_strpos($item['quote'],$term,0,'UTF-8')!==false) {$found=true;break;}
                $conditionTerms=isset($value['aggregate_condition']['conditions'])
                    ? array_column($value['aggregate_condition']['conditions'],'metric_term') : [];
                if (!$found && !($key==='metric_terms'
                    && in_array($term,$conditionTerms,true)
                    && self::registeredTermGroundedInEvidence($term,$evidence))) {
                    unset($value[$key]); continue 2;
                }
            }
        }
        if (in_array('metric_codes',$fields,true) && !isset($value['metric_terms']) && !isset($value['metric_exclusions'])) {
            // A clear ranking subject can be expressed without naming a
            // registered measurement (for example, asking which registered
            // dimension "does best"). Do not force the language model to
            // invent a specific metric term merely to cross this semantic
            // boundary. The later binding stage still selects one compatible
            // registered metric, and the execution contract rechecks it.
            // This never applies to a condition, a time-only continuation,
            // or a question that already contains an exact registered term.
            if (!self::canDeferGroundedMetricCarrier($fields,$value,$evidence)) self::fail('values:metric_terms');
        }
        if (isset($value['aggregate_condition']['conditions'])) {
            $conditionTerms=array_column($value['aggregate_condition']['conditions'],'metric_term');
            $expectedOperation=$value['aggregate_condition']['result_form']==='count'?'condition_count':'condition_list';
            // `metric_terms` is a redundant audit projection for a generic
            // condition set. Every condition term above has already been
            // independently grounded in exact evidence or a registry-proven
            // alias, so project that validated ordered list instead of asking
            // the provider to repeat the same wording byte-for-byte twice.
            // Operators, quantities, relation and result form are untouched.
            if (in_array('metric_codes',$fields,true)) $value['metric_terms']=$conditionTerms;
            if (!in_array('metric_codes',$fields,true)
                || !isset($value['metric_terms'])
                || $value['metric_terms']!==$conditionTerms) self::fail('values:aggregate_condition','metric_audit');
            if (!in_array('object_kind',$fields,true)
                || ($value['object_kind']??null)!==$value['aggregate_condition']['subject']) {
                self::fail('values:aggregate_condition','object_audit');
            }
            if (!in_array('operation',$fields,true)
                || ($value['operation']??null)!==$expectedOperation) self::fail('values:aggregate_condition','operation_audit');
        }
        return $value;
    }

    /**
     * Accept a normalized condition term only when both it and at least one
     * literal term in the customer's evidence have the same unique,
     * AI-query-ready registry owner. No fuzzy matching or local synonym list
     * is used here.
     */
    private static function registeredTermGroundedInEvidence(string $term,array $evidence): bool
    {
        $catalog='\\app\\services\\query\\metric\\MetricSemanticCatalog';
        $code=$catalog::uniqueCodeForTerms([$term]);
        if ($code===null) return false;
        $entry=$catalog::entries()[$code]??null;
        if (!is_array($entry)) return false;
        foreach ($evidence as $item) {
            $quote=is_string($item['quote']??null)?$item['quote']:'';
            foreach ((array)($entry['terms']??[]) as $registeredTerm) {
                if (is_string($registeredTerm) && $registeredTerm!==''
                    && mb_strpos($quote,$registeredTerm,0,'UTF-8')!==false) return true;
            }
        }
        return false;
    }

    /**
     * A registry-only check distinguishes an unnamed ranking perspective from
     * a customer-stated measurement. It does not classify phrases, select a
     * metric, or weaken a condition; it only prevents an invented metric term
     * from turning a clear analytical request into a model-format failure.
     */
    private static function canDeferGroundedMetricCarrier(array $fields,array $value,array $evidence): bool
    {
        if (isset($value['aggregate_condition'])) return false;
        $catalog='\\app\\services\\query\\metric\\MetricSemanticCatalog';
        $unnamedRanking=in_array('object_kind',$fields,true) && in_array('operation',$fields,true)
            && in_array('ranking',$fields,true) && ($value['operation']??null)==='ranking';
        $registeredMeasurementCodes=[];
        foreach ($evidence as $item) {
            if (($item['message_id']??null)!=='current' || !is_string($item['quote']??null)) continue;
            $matches=$catalog::registeredNonOverlappingTermsInText($item['quote']);
            foreach ($matches as $match) if (is_string($match['metric_code']??null)) {
                $registeredMeasurementCodes[$match['metric_code']]=true;
            }
            // An unnamed "best/worst" perspective is intentionally selected
            // later by the registered ranking policy. If the question does
            // contain registry terms, however, they must still resolve to one
            // owner before this special case can defer its optional echo.
            if ($unnamedRanking) {
                $registered=$catalog::registeredTermsInText($item['quote']);
                if ($registered!==[] && $catalog::uniqueTermInText($item['quote'])===null) return false;
            }
        }
        // `metric_terms` is only an audit projection. When the model merges
        // two coordinated labels into a malformed phrase, exact registry
        // owners in the original current-message evidence still prove that
        // measurements were stated. Preserve the requirement and let the
        // later binding contract account for every owner; do not spend a
        // second model call repairing redundant wording. Vague labels have
        // no exact owner and continue to the ordinary repair/clarification
        // path. Conditions stay excluded because their ordered predicates
        // require a complete typed carrier.
        // The coordinated-measurement recovery is deliberately multi-only.
        // With one exact measurement, a different model term is a semantic
        // contradiction and must still be repaired instead of discarded.
        if (count($registeredMeasurementCodes)>1 || $unnamedRanking) return true;
        if ($registeredMeasurementCodes!==[]) return false;
        // A broad analytical request such as an object's general performance
        // may have no exact registry owner yet. The model can still establish
        // the object and requested response form while misspelling its
        // optional metric-term echo. Dropping only that echo lets the binding
        // model choose a labelled professional first reading. This remains
        // unavailable to selections, conditions and unknown response forms;
        // an exact registered term above always wins and a contradictory
        // paraphrase is still rejected.
        return in_array('object_kind',$fields,true)
            && in_array('object_relation',$fields,true)
            && ($value['object_relation']??null)==='analysis'
            && in_array('operation',$fields,true)
            && in_array($value['operation']??null,['summary','breakdown'],true);
    }

    private static function periods($periods): bool
    {
        if (!is_array($periods)||count($periods)>2||($periods!==[]&&array_keys($periods)!==range(0,count($periods)-1))) return false;
        // One typed-period boundary for understanding, binding and execution.
        foreach ($periods as $period) if (!\app\services\ai\execution\AiTemporalPeriodResolver::valid($period)) return false;
        return true;
    }

    private static function aggregateCondition($condition): bool
    {
        $keys=is_array($condition)?array_keys($condition):[];sort($keys,SORT_STRING);
        if ($keys===['aggregation','amount_cents','operator','subject']) return
            ($condition['subject']??null)==='member' && ($condition['aggregation']??null)==='period_total'
            && in_array($condition['operator']??null,['gte','gt','lte','lt','eq'],true)
            && is_int($condition['amount_cents']??null) && $condition['amount_cents']>0
            && $condition['amount_cents']<=100000000000;
        $hasExpression=$keys===['conditions','expression','relation','result_form','subject'];
        if (!$hasExpression && $keys!==['conditions','relation','result_form','subject']) return false;
        if (!in_array($condition['subject']??null,['person','member','store','order','sale_line','card','project','product'],true)
            ||!in_array($condition['relation']??null,['all','any'],true)||!in_array($condition['result_form']??null,['count','list'],true)
            ||!is_array($condition['conditions']??null)||count($condition['conditions'])<1||count($condition['conditions'])>\app\services\query\metric\MetricDefinitionRegistry::MAX_CONDITIONS
            ||array_keys($condition['conditions'])!==range(0,count($condition['conditions'])-1)) return false;
        if ($hasExpression && (!in_array($condition['subject'],['member','person','store'],true)
            ||$condition['relation']!==($condition['expression']['relation']??null)
            ||!\app\services\query\metric\MetricConditionExpression::valid(
                $condition['expression'],count($condition['conditions'])
            ))) return false;
        foreach ($condition['conditions'] as $item) {
            $itemKeys=is_array($item)?array_keys($item):[];sort($itemKeys,SORT_STRING);
            $hasPeriod=$itemKeys===['metric_term','operator','period','quantity','unit'];
            if ((!$hasPeriod && $itemKeys!==['metric_term','operator','quantity','unit'])
                ||($hasPeriod && !\app\services\ai\execution\AiTemporalPeriodResolver::valid($item['period']))
                ||!self::text($item['metric_term']??null,160)
                ||!in_array($item['operator']??null,['gte','gt','lte','lt','eq'],true)
                ||!is_string($item['quantity']??null)||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D',$item['quantity'])
                ||!in_array($item['unit']??null,['yuan','count','day'],true)) return false;
        }
        return true;
    }

    /** One bounded mutation of a previously verified condition set. */
    private static function conditionUpdate($update): bool
    {
        $keys=is_array($update)?array_keys($update):[];sort($keys,SORT_STRING);
        return $keys===['operator','quantity','target_term','unit']
            &&self::text($update['target_term']??null,160)
            &&in_array($update['operator']??null,['gte','gt','lte','lt','eq'],true)
            &&is_string($update['quantity']??null)
            &&preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D',$update['quantity'])===1
            &&in_array($update['unit']??null,['yuan','count','day'],true);
    }

    private static function date($value): bool
    {
        if (!is_string($value)||!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D',$value)) return false;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Asia/Shanghai'));
        return $date!==false&&$date->format('Y-m-d')===$value;
    }

    private static function fail(string $predicate,?string $field=null): void
    {
        $diagnostic=[
            'stage' => 'intent_understanding_contract', 'predicate' => $predicate,
        ];
        if ($field!==null) $diagnostic['field']=$field;
        throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',$diagnostic);
    }
}
