<?php
namespace app\services\ai\contract;

/**
 * The model may understand prose, but it can only cross this boundary as a
 * bounded semantic candidate. Follow-ups use an explicit delta: absent is
 * never silently interpreted as “keep the old value”.
 */
final class AiIntentResultContract
{
    const VERSION='intent-result-v3';
    const REQUIRED_FIELDS=['action_codes','metric_codes','needs_metric_choice','object_kind','object_term','operation','unresolved_fragments'];
    const DELTA_FIELDS=['metric_codes','object','business_filters','store_scope','periods','operation','ranking_direction','ranking_limit','scope'];
    const DELTA_ACTIONS=['inherit','replace','clear','pending'];

    public static function repairableOmission(?string $predicate): bool
    {
        // A retry asks the model to emit its own complete answer. The server
        // never supplies omitted business semantics.
        if ($predicate==='missing_metric_codes') return true;
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
            ? 'A verified prior query exists. Include context_delta with exactly these keys: '.implode(', ',self::DELTA_FIELDS).'. Each value is one of inherit, replace, clear or pending. Use inherit only when the current wording leaves that exact meaning unchanged. Use replace only when current wording supplies a new meaning in the matching ordinary field. Use clear only when the customer explicitly removes a condition. Use pending when clarification is needed. Never omit a delta key and never infer inherit from an omitted field. A short continuation can replace just the analytical object while retaining the verified period, response form, ranking direction, ranking quantity, scope and other unchanged meaning. When that new object cannot legally use the previous metric under capabilities.object_contracts, mark metric_codes pending rather than returning operation unknown or an unresolved fragment; the server will present only registered choices.'
            : 'No verified prior query exists. Do not include context_delta.';
        return 'Return one JSON object following '.self::VERSION.'. Required keys: '.implode(', ',self::REQUIRED_FIELDS).'. Optional keys: ranking, periods, scope'.($hasPriorQuery?', context_delta':'').'. '.$context.' object_term is an exact customer term or an empty string where no named object is needed. needs_metric_choice is true only when the customer must choose between legal metric meanings; then metric_codes must be empty. periods, when present, is an ordered array of up to two period objects. A period is exactly one of {"kind":"date_range","start":"YYYY-MM-DD","end":"YYYY-MM-DD"}, {"kind":"relative_days","days":positive-integer,"end_offset_days":integer-from--365-to-0}, or {"kind":"month_offset","offset_months":integer-from--24-to-0}. A calendar-month meaning uses month_offset; relative_days is only for a stated rolling number of days. ranking is {"direction":"top|bottom|top_and_bottom|unspecified","limit":integer-or-null}. Scope and accessible stores are server-owned: omit scope unless current wording explicitly changes current-store versus authorized scope. metric_codes and action_codes only contain supplied codes. unresolved_fragments only contains exact current-question text that cannot be represented. Never calculate, query, invent a condition, discard a condition, or copy a previous result value.';
    }

    public static function normalize($value,array $metricCodes,array $actionCodes,array $safeQuestion): array
    {
        $value=self::native($value);
        if (!is_array($value)) self::fail('root_not_object');
        $hasPrior=($safeQuestion['prior_query']??null)!==null;
        foreach (self::REQUIRED_FIELDS as $key) if (!array_key_exists($key,$value)) self::fail('missing_key:'.$key);
        if ($hasPrior && !array_key_exists('context_delta',$value)) self::fail('missing_key:context_delta');
        $allowed=array_merge(self::REQUIRED_FIELDS,['ranking','periods','scope'],$hasPrior?['context_delta']:[]);sort($allowed,SORT_STRING);
        $keys=array_keys($value);sort($keys,SORT_STRING);
        if (array_diff($keys,$allowed)) self::fail('unknown_key');
        if (!$hasPrior && array_key_exists('context_delta',$value)) self::fail('unexpected_context_delta');
        if (!is_bool($value['needs_metric_choice'])) self::fail('bad_type:needs_metric_choice');
        if (!is_string($value['object_term']) || mb_strlen($value['object_term'],'UTF-8')>160) self::fail('bad_value:object_term');
        if (!in_array($value['object_kind'],['store','person','position','member','product','project','category','partner','inventory','course','organization','unknown'],true)) self::fail('bad_value:object_kind');
        if (!in_array($value['operation'],['summary','trend','ranking','comparison','definition','unknown'],true)) self::fail('bad_value:operation');
        if (!self::codes($value['metric_codes'])) self::fail('bad_value:metric_codes');
        foreach ($value['metric_codes'] as $code) if (!in_array($code,$metricCodes,true)) throw new AiContractException('AI_MODEL_METRIC_UNKNOWN',['stage'=>'intent_contract','predicate'=>'unknown_metric_code']);
        if ($value['needs_metric_choice'] && $value['metric_codes']!==[]) self::fail('ambiguous_metric_codes_present');
        if (!self::codes($value['action_codes'])) self::fail('bad_value:action_codes');
        foreach ($value['action_codes'] as $code) if (!in_array($code,$actionCodes,true)) self::fail('bad_value:action_codes');
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
        $periodsSupplied=array_key_exists('periods',$value);$periods=$periodsSupplied?$value['periods']:[];
        if (!self::periods($periods)) self::fail('bad_value:periods');
        $scopeSupplied=array_key_exists('scope',$value);$scope=$scopeSupplied?$value['scope']:'unspecified';
        if (!in_array($scope,['current_store','authorized','unspecified'],true)) self::fail('bad_value:scope');
        $delta=$hasPrior?self::delta($value['context_delta']):null;
        self::requireReplacementValues($delta,$value,$rankingSupplied,$periodsSupplied,$scopeSupplied);
        $texts=array_merge([(string)($safeQuestion['question']??'')],(array)($safeQuestion['recent_questions']??[]));
        $normalized=$value['object_term']!=='' && !self::contained($value['object_term'],$texts);
        if ($normalized) $value['object_term']='';
        foreach ($value['unresolved_fragments'] as $fragment) if (!is_string($fragment)||$fragment===''||mb_strlen($fragment,'UTF-8')>160||!self::contained($fragment,$texts)) self::fail('bad_value:unresolved_fragments');
        return ['object_kind'=>$value['object_kind'],'object_term'=>$value['object_term'],'operation'=>$value['operation'],'metric_codes'=>$value['metric_codes'],'action_codes'=>$value['action_codes'],'needs_metric_choice'=>$value['needs_metric_choice'],'ranking'=>$ranking,'periods'=>$periods,'scope'=>$scope,'context_delta'=>$delta,'unresolved_fragments'=>$value['unresolved_fragments'],'_periods_supplied'=>$periodsSupplied,'_scope_supplied'=>$scopeSupplied,'_ranking_supplied'=>$rankingSupplied,'_object_term_normalized'=>$normalized,'_contract_version'=>self::VERSION];
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

    private static function codes($values): bool { if (!is_array($values)||count($values)>8||count(array_unique($values))!==count($values)) return false;foreach($values as $v)if(!is_string($v))return false;return true; }
    private static function periods($periods): bool
    {
        if (!is_array($periods)||count($periods)>2||($periods!==[]&&array_keys($periods)!==range(0,count($periods)-1))) return false;
        foreach ($periods as $p) { if(!is_array($p)||!is_string($p['kind']??null))return false;$keys=array_keys($p);sort($keys,SORT_STRING);
            if($p['kind']==='date_range'){if($keys!==['end','kind','start']||!is_string($p['start'])||!is_string($p['end'])||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$p['start'])||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$p['end']))return false;}
            elseif($p['kind']==='relative_days'){if($keys!==['days','end_offset_days','kind']||!is_int($p['end_offset_days'])||$p['end_offset_days'] < -365||$p['end_offset_days']>0||!is_int($p['days'])||$p['days']<1||$p['days']>366)return false;}
            elseif($p['kind']==='month_offset'){if($keys!==['kind','offset_months']||!is_int($p['offset_months'])||$p['offset_months'] < -24||$p['offset_months']>0)return false;} else return false;
        } return true;
    }
    private static function contained(string $value,array $texts): bool {foreach($texts as $text)if(is_string($text)&&mb_strpos($text,$value,0,'UTF-8')!==false)return true;return false;}
    private static function fail(string $predicate): void {throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_contract','predicate'=>$predicate]);}
}
