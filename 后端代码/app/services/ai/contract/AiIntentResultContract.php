<?php
namespace app\services\ai\contract;

/**
 * One source-owned boundary between natural-language understanding and the
 * server compiler. It describes a bounded semantic result, never business
 * values, SQL, permissions or query implementation.
 */
final class AiIntentResultContract
{
    const VERSION='intent-result-v2';

    public static function manifest(): array
    {
        return ['code'=>'intent_result','version'=>self::VERSION,'hash'=>hash('sha256',self::modelInstruction(true))];
    }

    /** Convert strict JSON objects to PHP arrays without changing scalar meaning. */
    public static function native($value)
    {
        if ($value instanceof \stdClass) $value=get_object_vars($value);
        if (is_array($value)) foreach ($value as $key=>$item) $value[$key]=self::native($item);
        return $value;
    }

    public static function modelInstruction(bool $hasPriorQuery): string
    {
        $context=$hasPriorQuery
            ? 'A verified prior query exists. You must include context_conditions with store_scope and business_filters, each inherit, replace or clear. If the current question only changes time, result form, or another non-filter concern, retain every prior metric code and set both context values to inherit. Current explicit metric or filter meaning replaces only the corresponding prior meaning.'
            : 'No verified prior query exists. context_conditions is optional and must be omitted unless the current question explicitly changes a previously supplied condition.';
        return 'Return one JSON object that follows intent_result '.self::VERSION.'. Required keys are object_kind, object_term, operation, metric_codes, action_codes, needs_metric_choice and unresolved_fragments. ranking, periods and scope are optional. '.$context.' object_term is a string: use an exact customer term or an empty string when no named object is needed. needs_metric_choice is true only when an ordinary customer must choose between legal metric meanings before a query can be made; when true metric_codes must be empty. It is false when the current wording identifies the metric meaning, or when a verified prior query supplies the unchanged metric meaning for a natural follow-up. When current wording contains only an umbrella business idea shared by multiple candidates, set needs_metric_choice to true and metric_codes to []. periods, if present, is an ordered array of zero, one or two period objects; omit it only when no period can be understood. Every period object is exactly one of {"kind":"date_range","start":"YYYY-MM-DD","end":"YYYY-MM-DD"}, {"kind":"relative_days","days":positive-integer,"end_offset_days":integer-from--365-to-0}, or {"kind":"month_offset","offset_months":integer-from--24-to-0}. Express a one-day period ending on question.reference_date as {"kind":"relative_days","days":1,"end_offset_days":0}; do not use date labels, a plain string, or a start/end object without kind. ranking may be omitted when the customer did not ask for a ranking; if present it is {"direction":"top|bottom|top_and_bottom|unspecified","limit":integer-or-null}. Scope and accessible stores are chosen only by the trusted server; omit scope. metric_codes and action_codes are arrays containing only supplied codes. unresolved_fragments contains exact customer text for conditions whose meaning remains unrepresented. Never omit a required key, add a key, calculate a value, invent a condition, or turn an unknown condition into a default.';
    }

    /** @return array Canonical internal intent, with supplied markers retained for the compiler. */
    public static function normalize($value,array $metricCodes,array $actionCodes,array $safeQuestion): array
    {
        $value=self::native($value);
        if (!is_array($value)) self::fail('root_not_object');
        $keys=array_keys($value); sort($keys,SORT_STRING);
        $required=['action_codes','metric_codes','needs_metric_choice','object_kind','object_term','operation','unresolved_fragments'];
        $allowed=array_merge($required,['ranking','periods','scope','context_conditions']); sort($allowed,SORT_STRING);
        foreach ($required as $key) if (!array_key_exists($key,$value)) self::fail('missing_key:'.$key);
        if (array_diff($keys,$allowed)) self::fail('unknown_key');
        $contextSupplied=array_key_exists('context_conditions',$value);
        $periodsSupplied=array_key_exists('periods',$value);
        $ranking=$value['ranking']??['direction'=>'unspecified','limit'=>null];
        $context=$contextSupplied?$value['context_conditions']:['store_scope'=>'inherit','business_filters'=>'inherit'];
        $periods=$periodsSupplied?$value['periods']:[];
        $rankingKeys=is_array($ranking)?array_keys($ranking):[];sort($rankingKeys,SORT_STRING);
        $contextKeys=is_array($context)?array_keys($context):[];sort($contextKeys,SORT_STRING);
        if (($safeQuestion['prior_query']??null)!==null && !$contextSupplied) self::fail('missing_context_conditions');
        if (!is_bool($value['needs_metric_choice'])) self::fail('bad_type:needs_metric_choice');
        if (!is_string($value['object_term']) || mb_strlen($value['object_term'],'UTF-8')>160) self::fail('bad_value:object_term');
        if (!in_array($value['object_kind'],['store','person','position','member','product','project','category','partner','inventory','course','organization','unknown'],true)) self::fail('bad_value:object_kind');
        if (!in_array($value['operation'],['summary','trend','ranking','comparison','definition','unknown'],true)) self::fail('bad_value:operation');
        if (!self::codes($value['metric_codes'])) self::fail('bad_value:metric_codes');
        foreach ($value['metric_codes'] as $code) if (!in_array($code,$metricCodes,true)) {
            throw new AiContractException('AI_MODEL_METRIC_UNKNOWN',['stage'=>'intent_contract','predicate'=>'unknown_metric_code']);
        }
        if ($value['needs_metric_choice'] && $value['metric_codes']!==[]) self::fail('ambiguous_metric_codes_present');
        if (($safeQuestion['prior_query']??null)!==null && !$value['needs_metric_choice'] && $value['metric_codes']===[]) self::fail('missing_metric_codes');
        if (!self::codes($value['action_codes'])) self::fail('bad_value:action_codes');
        foreach ($value['action_codes'] as $code) if (!in_array($code,$actionCodes,true)) self::fail('bad_value:action_codes');
        if (!is_array($value['unresolved_fragments']) || count($value['unresolved_fragments'])>8 || count(array_unique($value['unresolved_fragments']))!==count($value['unresolved_fragments'])) self::fail('bad_value:unresolved_fragments');
        if (!is_array($ranking) || $rankingKeys!==['direction','limit'] || !in_array($ranking['direction']??null,['top','bottom','top_and_bottom','unspecified'],true)
            || (!is_null($ranking['limit']??null) && (!is_int($ranking['limit']) || $ranking['limit']<1 || $ranking['limit']>999))) self::fail('bad_value:ranking');
        if (!self::periods($periods)) self::fail('bad_value:periods');
        if (array_key_exists('scope',$value) && !in_array($value['scope'],['current_store','authorized','unspecified'],true)) self::fail('bad_value:scope');
        if (!is_array($context) || $contextKeys!==['business_filters','store_scope']
            || !in_array($context['store_scope'],['inherit','replace','clear'],true)
            || !in_array($context['business_filters'],['inherit','replace','clear'],true)) self::fail('bad_value:context_conditions');
        $texts=array_merge([(string)($safeQuestion['question']??'')],(array)($safeQuestion['recent_questions']??[]));
        // A generic paraphrase such as "门店" must not become a named-object
        // filter merely because the model used it.  It is not a contract
        // failure: retain the safe fact that no named object was supplied and
        // let the caller emit a payload-free diagnostic.
        $objectTermNormalized=$value['object_term']!=='' && !self::contained($value['object_term'],$texts);
        if ($objectTermNormalized) $value['object_term']='';
        foreach ($value['unresolved_fragments'] as $fragment) {
            if (!is_string($fragment)||$fragment===''||mb_strlen($fragment,'UTF-8')>160||!self::contained($fragment,$texts)) self::fail('bad_value:unresolved_fragments');
        }
        return ['object_kind'=>$value['object_kind'],'object_term'=>$value['object_term'],'operation'=>$value['operation'],
            'metric_codes'=>$value['metric_codes'],'action_codes'=>$value['action_codes'],'needs_metric_choice'=>$value['needs_metric_choice'],
            'ranking'=>$ranking,'periods'=>$periods,'scope'=>array_key_exists('scope',$value)?$value['scope']:'unspecified',
            'context_conditions'=>$context,'unresolved_fragments'=>$value['unresolved_fragments'],
            '_periods_supplied'=>$periodsSupplied,'_scope_supplied'=>array_key_exists('scope',$value),
            '_context_conditions_supplied'=>$contextSupplied,'_object_term_normalized'=>$objectTermNormalized,
            '_contract_version'=>self::VERSION];
    }

    private static function codes($values): bool
    {
        if (!is_array($values)||count($values)>8||count(array_unique($values))!==count($values)) return false;
        foreach ($values as $value) if (!is_string($value)) return false;
        return true;
    }

    private static function periods($periods): bool
    {
        if (!is_array($periods)||count($periods)>2||($periods!==[]&&array_keys($periods)!==range(0,count($periods)-1))) return false;
        foreach ($periods as $period) {
            if (!is_array($period)||!is_string($period['kind']??null)) return false;
            $keys=array_keys($period);sort($keys,SORT_STRING);
            if ($period['kind']==='date_range') {
                if ($keys!==['end','kind','start']||!is_string($period['start'])||!is_string($period['end'])||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$period['start'])||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$period['end'])) return false;
            } elseif ($period['kind']==='relative_days') {
                if ($keys!==['days','end_offset_days','kind']||!is_int($period['end_offset_days'])||$period['end_offset_days'] < -365||$period['end_offset_days']>0||!is_int($period['days'])||$period['days']<1||$period['days']>366) return false;
            } elseif ($period['kind']==='month_offset') {
                if ($keys!==['kind','offset_months']||!is_int($period['offset_months'])||$period['offset_months'] < -24||$period['offset_months']>0) return false;
            } else return false;
        }
        return true;
    }

    private static function contained(string $value,array $texts): bool
    {
        foreach ($texts as $text) if (is_string($text)&&mb_strpos($text,$value,0,'UTF-8')!==false) return true;
        return false;
    }

    private static function fail(string $predicate): void
    {
        throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_contract','predicate'=>$predicate]);
    }
}
