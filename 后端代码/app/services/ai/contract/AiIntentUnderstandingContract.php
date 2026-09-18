<?php
namespace app\services\ai\contract;

/**
 * The first model boundary records what the customer means before any metric
 * catalogue, query form or permission scope is considered. It intentionally
 * carries no executable code or business identity.
 */
final class AiIntentUnderstandingContract
{
    public const VERSION = 'intent-understanding-v6';

    public static function modelInstruction(): string
    {
        return 'Return one JSON object following '.self::VERSION.': '
            . '{"goal":"brief business goal","requirements":[{"id":"r1","meaning":"one part of the customer request","fields":["metric_codes"],"values":{"metric_terms":["exact customer term"]},"evidence":[{"message_id":"current","quote":"exact text from that de-identified message"}]}],"status":"understood|needs_clarification"}. '
            . 'The only top-level keys are goal, requirements and status. Every meaningful part of the customer request needs one or more requirements; do not collapse exclusions, comparison relationships, quantity or time into a vague summary. When status is needs_clarification and no concrete meaning can yet be preserved, requirements may be an empty array; do not use unbound for ambiguity. '
            . 'A requirement has exactly id, meaning, fields, values and evidence. fields may contain metric_codes, object_kind, object_relation, operation, periods, ranking, scope, result_reference or unbound; use only fields actually expressed by this requirement. A field is a completed semantic commitment, never a note or a sketch. values is optional only when the request supplies no safe structured value for that field. When the customer meaning clearly establishes an object kind or relation, result form, period, ranking, scope or result reference, include the matching complete typed value in values so the later binding cannot silently change it. A temporal expression that fixes when the answer concerns is always an independent period requirement, even when the customer asks broadly about overall conditions rather than naming a metric. Before emitting the object, check the complete customer message again: every expressed relative-day, calendar-month or explicit-range condition must have a periods field, a complete values.periods carrier and evidence anchored to that expression. Do not absorb a time condition into a goal or metric term. When present, values may contain only values matching fields and must be complete and valid for each value it carries. Do not omit a field merely because its execution-shaped value is not available in this phase; preserve the natural-language meaning and evidence, and let the later binding phase derive the executable form. id is r followed by a positive number and is valid only in this request. evidence is an array of {"message_id":"current","quote":"exact excerpt"}; message_id must name an entry in question.evidence_messages. Do not output character offsets. The excerpt must occur exactly once in that one de-identified message; include more adjacent text if needed to distinguish repeated words. '
            . 'The values object contains only keys named by fields. Every customer-stated business measurement belongs in fields as metric_codes and carries either values.metric_terms or values.metric_exclusions, even if it is everyday language rather than a registered indicator name; metric_codes is only the name of the later binding slot, never a request to output a code. These are nonempty arrays of exact customer terms present in an evidence excerpt. Never put a registered metric code in values. If the customer asks how many members/people meet a cumulative monetary threshold in a period, preserve aggregate_condition exactly as {"subject":"member","aggregation":"period_total","operator":"gte|gt|lte|lt|eq","amount_cents":positive-integer} and operation=threshold_count; amount_cents is the customer amount converted to cents, never a result. If the current turn changes only time, object, range or response form and names no measurement, do not add a metric_codes field merely because a verified prior query has one; retain that prior meaning through context only. A time, object or response-form requirement does not replace the separate measurement requirement. object_kind is one of store, person, position, guide, sales_manager, member, product, project, category, partner, inventory, course, organization, unknown. object_relation is analysis when that kind is what the customer wants compared, grouped or listed, and selection only when the customer identifies a particular object whose records should narrow the data. An analytical object is never itself a data-range restriction. operation is one of summary, trend, ranking, comparison, threshold_count, definition, unknown. Use ranking when the requested answer identifies leading, trailing or ordered comparable objects; the fact that a ranking compares peer values does not make it operation=comparison. Use comparison only when the customer asks to contrast two stated business sides such as periods, objects or measurements. scope is one of current_store, authorized, unspecified. ranking is exactly {"direction":"top|bottom|top_and_bottom|unspecified","limit":null}. Set limit to an integer from 1 to 999 when the customer asks for a specific count or semantically asks for one winner, leader, best or worst object; leave it null only for an open-ended plural ranking with no count. result_reference is exactly {"group":"top|bottom","ordinal":positive-integer} only when the customer explicitly refers to a displayed rank result; it identifies a position in the previous answer, never a name, ID or value. periods is an array of at most two objects, each exactly one of {"kind":"date_range","start":"YYYY-MM-DD","end":"YYYY-MM-DD"}, {"kind":"relative_days","days":1,"end_offset_days":0}, or {"kind":"month_offset","offset_months":0}; numeric examples illustrate JSON types, not defaults. A relative-day count is a positive integer, and a month offset is an integer; preserve the customer meaning without imposing execution coverage limits here. A comparison that expresses both sides in the customer wording must preserve those two periods in stated order; do not leave either side for a date form. Omit a values key and its field when that meaning was not supplied, except a genuinely inherited meaning must remain explicit. '
            . 'For object_relation, analysis also covers inspecting, summarizing or evaluating a stated object, not only comparing, grouping or listing it. A broad evaluation or overview of a stated object must carry that object_kind with object_relation=analysis; a time condition never replaces or erases it. '
            . 'Do not output metric codes, action codes, object IDs, names hidden behind local references, calculated dates, formulas, SQL, query steps, permissions or result values. An explicit customer date range may be preserved as a period; never calculate a relative period into calendar dates. '
            . 'understood means the business request is clear even when no current capability can perform it. needs_clarification means the business request itself has more than one plausible reading. A broad request to understand overall operating conditions without naming a specific business fact is still understood: retain it as a metric_codes requirement with its exact customer term, so the later binding may propose a clearly labelled initial observation rather than requiring the customer to learn a metric name. If the customer did state a measurement that can reasonably mean several different business facts and neither the current wording nor verified context chooses among them, preserve that measurement requirement and mark needs_clarification; never turn a category label into one of its examples. unbound is allowed only with understood: ambiguity is not an unavailable capability. This object grants nothing and is later bound by the server.';
    }

    public static function normalize($value, array $safeQuestion): array
    {
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['goal', 'requirements', 'status']) self::fail('shape');
        if (!self::text($value['goal'], 240)) self::fail('goal');
        if (!in_array($value['status'], ['understood', 'needs_clarification'], true)) self::fail('status');
        $messages = self::messages($safeQuestion);
        $requirements = $value['requirements'];
        if (!is_array($requirements) || (($requirements===[]) && $value['status']!=='needs_clarification') || count($requirements) > 12
            || ($requirements!==[] && array_keys($requirements) !== range(0, count($requirements) - 1))) self::fail('requirements');
        $ids = []; $normalized = [];
        foreach ($requirements as $requirement) {
            if ($requirement instanceof \stdClass) $requirement = get_object_vars($requirement);
            $requirementKeys = is_array($requirement) ? array_keys($requirement) : [];
            sort($requirementKeys, SORT_STRING);
            if (!in_array($requirementKeys, [['evidence', 'fields', 'id', 'meaning'], ['evidence', 'fields', 'id', 'meaning', 'values']], true)
                || !is_string($requirement['id'] ?? null)
                || !preg_match('/^r[1-9][0-9]{0,2}$/D', $requirement['id']) || isset($ids[$requirement['id']])
                || !self::text($requirement['meaning'] ?? null, 240) || !self::fields($requirement['fields'] ?? null)) self::fail('requirements');
            $evidence = $requirement['evidence'];
            if (!is_array($evidence) || count($evidence) < 1 || count($evidence) > 6
                || array_keys($evidence) !== range(0, count($evidence) - 1)) self::fail('requirements');
            $seen = []; $located = [];
            foreach ($evidence as $item) {
                if ($item instanceof \stdClass) $item = get_object_vars($item);
                $itemKeys = is_array($item) ? array_keys($item) : [];
                sort($itemKeys, SORT_STRING);
                // A normalized server-owned record may re-enter this boundary
                // during the binding phase. Its start offset is never trusted:
                // we recalculate it below from the same de-identified message.
                if (!in_array($itemKeys, [['message_id', 'quote'], ['message_id', 'quote', 'start']], true) || !is_string($item['message_id'] ?? null)
                    || !isset($messages[$item['message_id']]) || !self::text($item['quote'] ?? null, 160)) self::fail('requirements');
                $key = $item['message_id']."\0".$item['quote'];
                if (isset($seen[$key])) self::fail('requirements');
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
            // “unbound” records a clear customer condition that the current
            // product cannot execute.  It is not a generic marker for an
            // ambiguous question: mixing the two previously let a later
            // clarification discard the condition and run a reduced query.
            if ($value['status'] !== 'understood' && in_array('unbound', (array)$requirement['fields'], true)) self::fail('unbound_requires_understood');
            $ids[$requirement['id']] = true;
            $normalized[] = ['id' => $requirement['id'], 'meaning' => trim($requirement['meaning']), 'fields' => array_values($requirement['fields']), 'values'=>$values, 'evidence' => $located];
        }
        // The period-only reuse path intentionally skips the second model
        // binding pass. It is safe only when the understanding model anchors
        // that *entire* current message as a time-only continuation. A short
        // excerpt such as just the time word could otherwise hide a new
        // business fact later in the same sentence and replay the verified
        // predecessor query. This checks evidence coverage, not a vocabulary,
        // metric name, report, or customer-specific phrase.
        if (($safeQuestion['prior_query'] ?? null) !== null && count($normalized) === 1
            && $normalized[0]['fields'] === ['periods']) {
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
        return ['goal' => trim($value['goal']), 'requirements' => $normalized, 'status' => $value['status']];
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
            && preg_match('/^(shape|goal|status|requirements|message_projection|evidence_not_unique|period_only_coverage|values(?::[a-z_]+)?)$/D',$predicate) === 1;
    }

    /** A structural correction never interprets a customer phrase in PHP. */
    public static function repairInstruction(string $predicate): string
    {
        if (!self::repairable($predicate)) throw new AiContractException('AI_MODEL_INPUT_INVALID');
        if ($predicate === 'period_only_coverage') {
            return 'The previous response called this a time-only continuation but its evidence did not cover the complete current customer message. Re-read the whole current message. Return only one periods requirement only when the whole message changes no business fact, object, result form, range, ranking, scope, condition or measurement. Otherwise preserve every additional current meaning with its own requirement and current-message evidence. Do not invent, bind or select a metric.';
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
                return 'The previous response declared metric_codes without an exact customer measurement or exclusion. Return the complete understanding again. Every metric_codes requirement must carry a valid values.metric_terms or values.metric_exclusions from its evidence. If the customer did not state a measurement in that requirement, remove metric_codes and retain only the actually expressed conditions; do not invent a metric or use a prior metric as current customer wording. For a continuation that only changes time, return a periods requirement with a complete values.periods carrier and current-message evidence, but no metric_codes; the verified prior query is handled later by context, not copied into current customer wording.';
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
        $allowed=['metric_codes','object_kind','object_relation','operation','periods','ranking','scope','result_reference','aggregate_condition','unbound'];
        if (!is_array($value) || $value===[] || count($value)>6 || count(array_unique($value))!==count($value)) return false;
        foreach ($value as $field) if (!is_string($field) || !in_array($field,$allowed,true)) return false;
        return true;
    }

    /**
     * `values` is not a second metric catalogue. It records only the bounded
     * effects already understood from this one customer request, so the binding
     * phase cannot turn "top five" into "top nine" or rewrite its period.
     */
    private static function values($value,array $fields,array $evidence): array
    {
        if (!is_array($value)) self::fail('values:shape');
        $allowed=['metric_exclusions','metric_terms','object_kind','object_relation','operation','periods','ranking','scope','result_reference','aggregate_condition'];
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
        if (isset($value['object_kind']) && !in_array($value['object_kind'],['store','person','position','guide','sales_manager','member','product','project','category','partner','inventory','course','organization','unknown'],true)) self::fail('values:object_kind');
        if (isset($value['object_relation']) && !in_array($value['object_relation'],['analysis','selection'],true)) self::fail('values:object_relation');
        if (isset($value['operation']) && !in_array($value['operation'],['summary','trend','ranking','comparison','threshold_count','definition','unknown'],true)) self::fail('values:operation');
        if (isset($value['aggregate_condition']) && !self::aggregateCondition($value['aggregate_condition'])) self::fail('values:aggregate_condition');
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
                if (!$found) { unset($value[$key]); continue 2; }
            }
        }
        if (in_array('metric_codes',$fields,true) && !isset($value['metric_terms']) && !isset($value['metric_exclusions'])) {
            self::fail('values:metric_terms');
        }
        return $value;
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
        return $keys===['aggregation','amount_cents','operator','subject']
            && ($condition['subject']??null)==='member' && ($condition['aggregation']??null)==='period_total'
            && in_array($condition['operator']??null,['gte','gt','lte','lt','eq'],true)
            && is_int($condition['amount_cents']??null) && $condition['amount_cents']>0
            && $condition['amount_cents']<=100000000000;
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
