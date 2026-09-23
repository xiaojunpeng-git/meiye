<?php
namespace app\services\ai\contract;

/**
 * The first model boundary records what the customer means before any metric
 * catalogue, query form or permission scope is considered. It intentionally
 * carries no executable code or business identity.
 */
final class AiIntentUnderstandingContract
{
    public const VERSION = 'intent-understanding-v14';

    public static function modelInstruction(): string
    {
        return 'Return one JSON object following '.self::VERSION.': '
            . '{"goal":"brief business goal","requirements":[{"id":"r1","meaning":"one part of the customer request","fields":["metric_codes"],"values":{"metric_terms":["exact customer term"]},"evidence":[{"message_id":"current","quote":"exact text from that de-identified message"}]}],"status":"understood|needs_clarification"}. '
            . 'The only top-level keys are goal, requirements, status and optional groups or request_kind. request_kind is "open_overview" for one store operating overview or "overview_comparison" for that same broad registered overview explicitly compared across two periods. Use either marker only when the complete current message names no independent measurement, ranking, object selection, exclusion or condition. An open_overview requirement carries metric_codes, object_kind, object_relation, operation and periods with the exact broad customer words as metric_terms, object_kind="store", object_relation="analysis", operation="summary" and exactly one typed period. An overview_comparison requirement carries the same fields, operation="comparison" and exactly two typed periods in the customer order. The markers are semantic types, never permission to invent metrics. Omit request_kind for every other request. Every meaningful part of the customer request needs one or more requirements; do not collapse exclusions, comparison relationships, quantity or time into a vague summary. When status is needs_clarification and no concrete meaning can yet be preserved, requirements may be an empty array; do not use unbound for ambiguity. When the customer explicitly asks independent results for two to four analytical subjects, include groups as [{"id":"q1","requirement_ids":["r1"]}]. Each group lists the requirements needed for one result; a shared date, metric, ranking or scope requirement may occur in every applicable group. Do not create groups for AND/OR predicates over one candidate population, multi-metric summary of one subject, or an ambiguous phrase. '
            . 'A requirement has exactly id, meaning, fields, values and evidence. fields may contain metric_codes, object_kind, object_relation, operation, periods, ranking, scope, result_reference, aggregate_condition, condition_update or unbound; use only fields actually expressed by this requirement. A field is a completed semantic commitment, never a note or a sketch. values is optional only when the request supplies no safe structured value for that field. When the customer meaning clearly establishes an object kind or relation, result form, period, ranking, scope, aggregate condition, condition update or result reference, include the matching complete typed value in values so the later binding cannot silently change it. A temporal expression that fixes when the answer concerns is always an independent period requirement, even when the customer asks broadly about overall conditions rather than naming a metric. Before emitting the object, check the complete customer message again: every expressed relative-day, calendar-month or explicit-range condition must have a periods field, a complete values.periods carrier and evidence anchored to that expression. Do not absorb a time condition into a goal or metric term. When present, values may contain only values matching fields and must be complete and valid for each value it carries. Do not omit a field merely because its execution-shaped value is not available in this phase; preserve the natural-language meaning and evidence, and let the later binding phase derive the executable form. id is r followed by a positive number and is valid only in this request. evidence is an array of {"message_id":"current","quote":"exact excerpt"}; message_id must name an entry in question.evidence_messages. Do not output character offsets. The excerpt must occur exactly once in that one de-identified message; include more adjacent text if needed to distinguish repeated words. '
            . 'Understand the complete phrase before choosing an analytical object. When the customer asks which day has the highest or lowest value, use object_kind=business_date, object_relation=analysis, operation=ranking and the stated ranking direction; keep the calendar range as a separate periods requirement. business_date means grouping the authorized metric by its registered business day, never selecting one date as a filter. Highest or lowest supplies only the ranking direction and never selects store or another object by itself. '
            . 'The values object contains only keys named by fields. Every customer-stated business measurement belongs in fields as metric_codes and carries either values.metric_terms or values.metric_exclusions, even if it is everyday language rather than a registered indicator name; metric_codes is only the name of the later binding slot, never a request to output a code. These are nonempty arrays of exact customer terms present in an evidence excerpt. Never put a registered metric code in values. A single cumulative member-money threshold may retain the legacy aggregate_condition {"subject":"member","aggregation":"period_total","operator":"gte|gt|lte|lt|eq","amount_cents":positive-integer} with operation=threshold_count. For one or more conditions over a candidate object set, preserve aggregate_condition as {"subject":"person|member|store|order|sale_line|card|project|product","relation":"all|any","result_form":"count|list","conditions":[{"metric_term":"exact customer measurement","operator":"gte|gt|lte|lt|eq","quantity":"normalized nonnegative decimal","unit":"yuan|count|day"}]} and set operation to condition_count or condition_list to match result_form. Every generic condition item has exactly metric_term, operator, quantity and unit. quantity and unit are always JSON strings. Convert Chinese amount magnitude into yuan: “5万元” is quantity "50000" and unit "yuan"; “24次” is quantity "24" and unit "count"; “超过90天没来” keeps quantity "90" and unit "day". A current positive state such as “有剩余项目次数” is a condition with operator gt, quantity "0" and unit "count"; do not invent a period total for it. Logical relation is part of the requested business meaning, not a default: relation all means every condition must hold (for example, “并且”), while relation any means at least one condition may hold (for example, “或者”). Never default a multi-condition request to all, and never replace an explicit alternative with a conjunction. For example, “销售业绩达到5万元并且劳动业绩达到5万元的员工有几个” uses subject person, relation all, result_form count and two condition items with metric_term “销售业绩” and “劳动业绩”, operator gte, quantity "50000", unit "yuan"; the same request joined by “或者” uses relation any. Preserve every condition in customer order; do not collapse AND/OR, invent a metric, or turn a count request into a list. quantity is a normalized decimal string such as "50000" or "24", never a result. When prior_query contains a verified generic aggregate_condition and the current turn clearly changes exactly one existing predicate while leaving every other condition, object, relation, period and result form unchanged, use only condition_update with values.condition_update={"target_term":"exact current words identifying that prior condition","operator":"gte|gt|lte|lt|eq","quantity":"normalized nonnegative decimal","unit":"yuan|count|day"}. Do not also emit metric_codes, aggregate_condition, object_kind or operation for this one-field delta. condition_update is never a standalone query, never adds or removes a condition, and must not be used when the target could denote more than one prior predicate. If the current turn changes only time, object, range or response form and names no measurement, do not add a metric_codes field merely because a verified prior query has one; retain that prior meaning through context only. A time, object or response-form requirement does not replace the separate measurement requirement. object_kind is one of store, person, position, guide, sales_manager, member, product, project, category, partner, inventory, course, organization, order, sale_line, card, unknown. object_relation is analysis when that kind is what the customer wants compared, grouped or listed, and selection only when the customer identifies a particular object whose records should narrow the data. An analytical object is never itself a data-range restriction. operation is one of summary, trend, ranking, comparison, threshold_count, condition_count, condition_list, definition, unknown. Use ranking when the requested answer identifies leading, trailing or ordered comparable objects; the fact that a ranking compares peer values does not make it operation=comparison. Use comparison only when the customer asks to contrast two stated business sides such as periods, objects or measurements. scope is one of current_store, authorized, unspecified. ranking is exactly {"direction":"top|bottom|top_and_bottom|unspecified","limit":null}. Set limit to an integer from 1 to 999 when the customer asks for a specific count or semantically asks for one winner, leader, best or worst object; leave it null only for an open-ended plural ranking with no count. result_reference is exactly {"group":"top|bottom","ordinal":positive-integer} only when the customer explicitly refers to a displayed rank result; it identifies a position in the previous answer, never a name, ID or value. periods is an array of at most two objects, each exactly one of {"kind":"date_range","start":"YYYY-MM-DD","end":"YYYY-MM-DD"}, {"kind":"relative_days","days":1,"end_offset_days":0}, or {"kind":"month_offset","offset_months":0}; numeric examples illustrate JSON types, not defaults. A relative-day count is a positive integer, and a month offset is an integer; preserve the customer meaning without imposing execution coverage limits here. A comparison that expresses both sides in the customer wording must preserve those two periods in stated order; do not leave either side for a date form. Omit a values key and its field when that meaning was not supplied, except a genuinely inherited meaning must remain explicit. '
            . 'When the customer coordinates two or more independently named measurements, preserve every measurement separately, either as separate requirements or as separate metric_terms in one requirement; never collapse the conjunction into one vague measurement or turn it into a choice between terms. Prefer copying each metric_term character-for-character from the de-identified customer message. For a generic aggregate condition only, you may instead use one exact registered business-language alias that means the same measurement as an exact registered phrase in the evidence; never invent a paraphrase or insert an object qualifier. '
            . 'A metric term is the measured business fact, not every noun or modifier in the sentence. Never mark an analytical object noun, date expression, question word, or ranking direction such as highest, lowest, most, least, best or worst as metric_codes. For “which project has the most sales records and which has the least”, sales records is the one measurement, project is the analytical object, and most plus least are the two ranking directions; do not create extra measurement requirements for project, most or least. '
            . 'For object_relation, analysis also covers inspecting, summarizing or evaluating a stated object, not only comparing, grouping or listing it. A broad evaluation or overview of a stated object must carry that object_kind with object_relation=analysis; a time condition never replaces or erases it. object_kind may be store, business_date, person, position, guide, sales_manager, member, product, project, category, partner, inventory, course, organization, order, sale_line, card or unknown. '
            . 'Do not output metric codes, action codes, object IDs, names hidden behind local references, calculated dates, formulas, SQL, query steps, permissions or result values. An explicit customer date range may be preserved as a period; never calculate a relative period into calendar dates. '
            . 'understood means the business request is clear even when no current capability can perform it. needs_clarification means the business request itself has more than one plausible reading. A broad request to understand overall operating conditions without naming a specific business fact is still understood: retain it as a metric_codes requirement with its exact customer term, so the later binding may propose a clearly labelled initial observation rather than requiring the customer to learn a metric name. If the customer did state a measurement that can reasonably mean several different business facts and neither the current wording nor verified context chooses among them, preserve that measurement requirement and mark needs_clarification; never turn a category label into one of its examples. unbound is allowed only with understood: ambiguity is not an unavailable capability. For a two-period comparison, copy the entire de-identified current question into each evidence.quote; do not paraphrase, alter date words or quote a repeated fragment. This object grants nothing and is later bound by the server.';
    }

    public static function normalize($value, array $safeQuestion): array
    {
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys, SORT_STRING);
        if (!in_array($keys,[
            ['goal','requirements','status'],
            ['goal','groups','requirements','status'],
            ['goal','request_kind','requirements','status'],
            ['goal','groups','request_kind','requirements','status'],
        ],true)) self::fail('shape');
        if (array_key_exists('request_kind',$value) && !in_array($value['request_kind'],['open_overview','overview_comparison'],true)) self::fail('request_kind');
        if (!self::text($value['goal'], 240)) self::fail('goal');
        if (!in_array($value['status'], ['understood', 'needs_clarification'], true)) self::fail('status');
        $messages = self::messages($safeQuestion);
        $requirements = $value['requirements'];
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
        if (!is_array($requirements) || (($requirements===[]) && $value['status']!=='needs_clarification') || count($requirements) > 12
            || ($requirements!==[] && array_keys($requirements) !== range(0, count($requirements) - 1))) self::fail('requirements_collection');
        $ids = []; $normalized = [];
        foreach ($requirements as $requirement) {
            if ($requirement instanceof \stdClass) $requirement = get_object_vars($requirement);
            $requirement=self::groundUniqueUnitConditionUpdate($requirement,$safeQuestion,$messages);
            $requirement=self::collapseVerifiedConditionResponseFormContinuation($requirement,$safeQuestion,$messages);
            $requirement=self::projectAggregateConditionFields($requirement);
            $requirementKeys = is_array($requirement) ? array_keys($requirement) : [];
            sort($requirementKeys, SORT_STRING);
            if (!in_array($requirementKeys, [['evidence', 'fields', 'id', 'meaning'], ['evidence', 'fields', 'id', 'meaning', 'values']], true)) self::fail('requirement_keys');
            if (!is_string($requirement['id'] ?? null)
                || !preg_match('/^r[1-9][0-9]{0,2}$/D', $requirement['id']) || isset($ids[$requirement['id']])) self::fail('requirement_id');
            if (!self::text($requirement['meaning'] ?? null, 240)) self::fail('requirement_meaning');
            $fieldIssue=self::fieldIssue($requirement['fields']??null);
            if ($fieldIssue!==null) self::fail('requirement_fields_'.$fieldIssue);
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
        if (($safeQuestion['prior_query'] ?? null) !== null) {
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
            if (array_diff((array)($projection['signals']??[]),$calendarSignals)!==[]
                ||($projection['unresolved_condition']??false)
                ||(array)($projection['semantic_intent']['constraints']??[])!==[]) {
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
        // The marker records model-understood request type only. It remains
        // non-executable until the gateway independently proves the complete
        // typed requirements, current evidence and registry overview profile.
        if (array_key_exists('request_kind',$value)) $out['request_kind']=$value['request_kind'];
        if (array_key_exists('groups',$value)) {
            try {
                $groups=self::groups($value['groups'],$normalized);
            } catch (AiContractException $error) {
                // Groups are ownership metadata, not customer meaning.  When
                // the independently accepted requirements mechanically prove
                // their ownership, recover that metadata instead of asking a
                // model to restate the same analytical request merely because
                // an array carrier was malformed.
                $groups=self::derivedIndependentGroups($normalized);
                // A non-collection request needs no group carrier at all.
                // Providers occasionally attach a one-item or stale prior
                // group to a short continuation; rejecting the independently
                // valid requirements would turn optional routing metadata
                // into a customer-visible failure. Only a mechanically proven
                // multi-result request receives derived groups above.
            }
        } else {
            $groups=self::derivedIndependentGroups($normalized);
        }
        if ($groups!==null) $out['groups']=$groups;
        return $out;
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
        foreach (['metric_codes','object_kind','operation','aggregate_condition'] as $field) {
            if (!in_array($field,$requirement['fields'],true)) $requirement['fields'][]=$field;
        }
        $requirement['values']['metric_terms']=array_column($condition['conditions'],'metric_term');
        $requirement['values']['object_kind']=$condition['subject'];
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
     * Corrects the value of one period that the model already understood and
     * grounded. It cannot create a date condition, remove another requirement
     * or alter a metric, object, ranking, scope or result reference.
     */
    public static function withResolvedSinglePeriod(array $understanding,array $periods): array
    {
        if (!self::periods($periods) || count($periods)!==1 || ($understanding['status']??null)!=='understood') {
            throw new AiContractException('AI_MODEL_INPUT_INVALID');
        }
        $matches=[];
        foreach ((array)($understanding['requirements']??[]) as $index=>$requirement) {
            if (in_array('periods',(array)($requirement['fields']??[]),true)) $matches[]=$index;
        }
        if (count($matches)!==1) return $understanding;
        $index=$matches[0];
        if (count((array)($understanding['requirements'][$index]['values']['periods']??[]))!==1) return $understanding;
        $understanding['requirements'][$index]['values']['periods']=$periods;
        return $understanding;
    }

    /**
     * A completed provider response may be corrected once only when it failed
     * this structural contract. Transport, authority and unknown outcomes are
     * deliberately never retried as if they were a missing customer detail.
     */
    public static function repairable(?string $predicate): bool
    {
        return is_string($predicate) && $predicate !== ''
            && preg_match('/^(shape|goal|status|requirements|requirements_collection|requirement_shape|requirement_keys|requirement_id|requirement_meaning|requirement_fields(?:_(?:shape|empty|too_many|duplicate|unknown))?|requirement_evidence_collection|requirement_evidence_shape|requirement_evidence_duplicate|groups|message_projection|evidence_not_unique|period_only_business_residue|period_only_coverage|period_only_multiple|period_term_as_metric|result_reference_without_ranked_prior|values(?::[a-z_]+)?)$/D',$predicate) === 1;
    }

    /** A structural correction never interprets a customer phrase in PHP. */
    public static function repairInstruction(string $predicate): string
    {
        if (!self::repairable($predicate)) throw new AiContractException('AI_MODEL_INPUT_INVALID');
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
                return 'The previous response declared metric_codes without an exact customer measurement or exclusion. Return the complete understanding again. Every metric_codes requirement must carry a valid values.metric_terms or values.metric_exclusions from its evidence. Copy each term character-for-character from the de-identified current message; a shorter exact substring is valid, but a canonical title, inserted object qualifier, paraphrase or combined conjunction is not. Preserve every independently named measurement as its own term. If the customer did not state a measurement in that requirement, remove metric_codes and retain only the actually expressed conditions; do not invent a metric or use a prior metric as current customer wording. For a continuation that only changes time, return a periods requirement with a complete values.periods carrier and current-message evidence, but no metric_codes; the verified prior query is handled later by context, not copied into current customer wording.';
            }
            if ($key === 'aggregate_condition') {
                return 'The previous response used an aggregate condition that was not grounded in the current customer message. Re-read only the complete current message. If it merely changes the response form of a verified prior condition query, such as asking “有几个” after a list, return only an operation requirement with values.operation=condition_count or condition_list and current-message evidence; omit metric_codes, object_kind and aggregate_condition because the signed prior query is inherited later by the context merger. If the current message itself states one or more conditions, return the complete understanding again and preserve all of those current conditions. For a generic object-set condition use exactly subject, relation, result_form and conditions; each condition item uses exactly metric_term, operator, quantity and unit. metric_term must be copied character-for-character from the current de-identified customer evidence, never paraphrased or rewritten to a canonical label. The same requirement must declare metric_codes and values.metric_terms; values.metric_terms must contain exactly the condition metric_term values in the same order. It must also declare object_kind equal to subject and operation equal to condition_count for result_form=count or condition_list for result_form=list. You may keep all current conditions in one requirement or split them into one condition per requirement, but never copy an unstated prior predicate into current evidence. operator is gte, gt, lte, lt or eq. quantity is a JSON string containing the normalized decimal, never a JSON number: “5万元” becomes "50000" with unit "yuan", “24次” becomes "24" with unit "count", and “90天” becomes "90" with unit "day". Do not add a registered metric code, remove a current predicate or change AND/OR.';
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
                || !preg_match('/^(current|recent_[1-9][0-9]?)$/D', $message['id'])
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

    private static function fieldIssue($value): ?string
    {
        $allowed=['metric_codes','object_kind','object_relation','operation','periods','ranking','scope','result_reference','aggregate_condition','condition_update','unbound'];
        if (!is_array($value) || ($value!==[]&&array_keys($value)!==range(0,count($value)-1))) return 'shape';
        if ($value===[]) return 'empty';
        if (count($value)>6) return 'too_many';
        if (count(array_unique($value))!==count($value)) return 'duplicate';
        foreach ($value as $field) if (!is_string($field) || !in_array($field,$allowed,true)) return 'unknown';
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
        $allowed=['metric_exclusions','metric_terms','object_kind','object_relation','operation','periods','ranking','scope','result_reference','aggregate_condition','condition_update'];
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
        if (isset($value['operation']) && !in_array($value['operation'],['summary','trend','ranking','comparison','threshold_count','condition_count','condition_list','definition','unknown'],true)) self::fail('values:operation');
        if (isset($value['aggregate_condition'])) {
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
        foreach (['metric_terms','metric_exclusions'] as $key) if (isset($value[$key])) {
            // These are optional evidence aids for the binding model, rather
            // than a second attempt to mechanically restate customer prose.
            // A model can understand the request correctly while quoting a
            // nearby paraphrase.  Keep the requirement, its meaning and its
            // server-located evidence in that case; dropping only this aid is
            // safer than converting a correct understanding into a technical
            // failure or asking the customer to repeat themselves.
            if (!is_array($value[$key]) || $value[$key]===[] || count($value[$key])>8 || count(array_unique($value[$key]))!==count($value[$key])) {
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
            if (!self::canDeferUnnamedRankingMetric($fields,$value,$evidence)) self::fail('values:metric_terms');
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
    private static function canDeferUnnamedRankingMetric(array $fields,array $value,array $evidence): bool
    {
        if (!in_array('object_kind',$fields,true) || !in_array('operation',$fields,true)
            || !in_array('ranking',$fields,true) || ($value['operation']??null)!=='ranking'
            || isset($value['aggregate_condition'])) return false;
        $catalog='\\app\\services\\query\\metric\\MetricSemanticCatalog';
        foreach ($evidence as $item) {
            if (($item['message_id']??null)!=='current' || !is_string($item['quote']??null)) continue;
            $registered=$catalog::registeredTermsInText($item['quote']);
            if ($registered===[]) continue;
            // The metric catalogue, rather than a PHP synonym rule, already
            // proves one exact customer phrase has one registered owner. In
            // that case a malformed optional metric_terms echo adds no
            // semantic safety and must not force another model round-trip.
            // The binding contract still has to select that same registered
            // code and pass its normal requirement/admission checks.
            if ($catalog::uniqueTermInText($item['quote'])===null) return false;
        }
        return true;
    }

    private static function periods($periods): bool
    {
        if (!is_array($periods)||count($periods)>2||($periods!==[]&&array_keys($periods)!==range(0,count($periods)-1))) return false;
        foreach($periods as $period) {
            if (!is_array($period)||!is_string($period['kind']??null)) return false;$keys=array_keys($period);sort($keys,SORT_STRING);
            if ($period['kind']==='date_range') { if($keys!==['end','kind','start']||!self::date($period['start']??null)||!self::date($period['end']??null)||$period['start']>$period['end'])return false; }
            elseif ($period['kind']==='relative_days') { if($keys!==['days','end_offset_days','kind']||!is_int($period['days']??null)||$period['days']<1||!is_int($period['end_offset_days']??null))return false; }
            elseif ($period['kind']==='month_offset') { if($keys!==['kind','offset_months']||!is_int($period['offset_months']??null))return false; }
            else return false;
        }
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
        if ($keys!==['conditions','relation','result_form','subject']
            ||!in_array($condition['subject']??null,['person','member','store','order','sale_line','card','project','product'],true)
            ||!in_array($condition['relation']??null,['all','any'],true)||!in_array($condition['result_form']??null,['count','list'],true)
            ||!is_array($condition['conditions']??null)||count($condition['conditions'])<1||count($condition['conditions'])>4
            ||array_keys($condition['conditions'])!==range(0,count($condition['conditions'])-1)) return false;
        foreach ($condition['conditions'] as $item) {
            $itemKeys=is_array($item)?array_keys($item):[];sort($itemKeys,SORT_STRING);
            if ($itemKeys!==['metric_term','operator','quantity','unit']||!self::text($item['metric_term']??null,160)
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
