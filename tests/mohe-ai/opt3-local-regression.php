<?php
/**
 * OPT3 local-only real-model regression: five independent conversations,
 * ten questions each. This is a newly authored mixed suite, not a replay of
 * the unavailable original 50-question transcript. Only AI audit state and
 * immutable Reader snapshots are written; business facts and config are not.
 * Run from the backend root with MOHE_OPT3_LOCAL=RUN inside the verified local
 * container. PLAN prints the public test plan without loading application state.
 */
ini_set('display_errors','0');
date_default_timezone_set('Asia/Shanghai');

/** Expected semantics are test data, never runtime phrase-to-query rules. */
function opt3Cases(): array
{
    $s=static function(string $q,string $metric,string $period,array $extra=[]):array {
        return ['question'=>$q,'metric'=>$metric,'period'=>$period,'shape'=>'summary','kind'=>'store']+$extra;
    };
    $r=static function(string $q,string $metric,string $period,int $limit=1,string $direction='top',string $kind='store'):array {
        return ['question'=>$q,'metric'=>$metric,'period'=>$period,'shape'=>'ranking','kind'=>$kind,
            'ranking'=>['direction'=>$direction,'limit'=>$limit]];
    };
    $c=static function(string $q,string $period,string $operator='eq',string $amount='4980'):array {
        // The shared business dictionary defines plain 消费 as actual cash
        // paid. Sales turnover and entitlement consumption are distinct.
        return ['question'=>$q,'metric'=>'cash_performance','period'=>$period,
            'shape'=>'condition_count','kind'=>'member','operator'=>$operator,'quantity'=>$amount];
    };
    $compare=static function(string $q,string $metric):array {
        return ['question'=>$q,'metric'=>$metric,'period'=>'today','compare_period'=>'yesterday','shape'=>'comparison','kind'=>'store'];
    };
    $help=static function(string $q):array {return ['question'=>$q,'capability_help'=>true];};
    $guide=static function(string $q):array {
        return ['question'=>$q,'metric'=>'actual_performance','period'=>'month','shape'=>'summary','kind'=>'store',
            'guidance_required'=>true,'supplement'=>'我想查本月实际业绩'];
    };
    $rounds=[
        ['name'=>'metric-condition-ranking-topic','turns'=>[
            $s('今天现金业绩多少','cash_performance','today'),
            $s('那实际业绩呢','actual_performance','today'),
            $s('昨天呢','actual_performance','yesterday'),
            $c('今年消费4980有多少人','year'),
            $c('上个月呢','last_month'),
            $c('改成消费大于5000元','last_month','gt','5000'),
            $r('本月实际业绩最高的门店是哪家','actual_performance','month'),
            $r('前三名','actual_performance','month',3),
            ['question'=>'第二名呢','period'=>'month','reference_ordinal'=>2],
            $help('今天天气怎么样'),
        ]],
        ['name'=>'guidance-and-comparison','turns'=>[
            $guide('门店怎么样'),
            $s('那消耗业绩呢','consume_amount','month'),
            $r('按门店排名','consume_amount','month',3)+['ranking_limit_unspecified'=>true,'supplement'=>'按消耗业绩从高到低查看本月前三家门店'],
            $r('最低的三家','consume_amount','month',3,'bottom'),
            $s('今天现金业绩是多少','cash_performance','today'),
            $s('本月退款业绩多少','refund_performance','month'),
            $s('上个月现金业绩多少','cash_performance','last_month'),
            $compare('今天跟昨天现金业绩对比','cash_performance'),
            $c('本月消费低于500的客户有几个','month','lt','500'),
            $c('今年消费4980有多少人','year'),
        ]],
        ['name'=>'personnel-and-fresh-topic','turns'=>[
            $r('本月销售业绩最高的员工是谁','staff_sales_yeji','month',1,'top','person'),
            $r('前三名','staff_sales_yeji','month',3,'top','person'),
            $r('上个月呢','staff_sales_yeji','last_month',3,'top','person'),
            $s('今天现金业绩多少','cash_performance','today'),
            $s('昨天实际业绩多少','actual_performance','yesterday'),
            $s('那消耗业绩呢','consume_amount','yesterday'),
            $r('本月各门店实际业绩排名','actual_performance','month',3)+['ranking_limit_unspecified'=>true,'supplement'=>'按本月实际业绩从高到低查看前三家门店'],
            $r('前两名','actual_performance','month',2),
            $c('今年消费4980有多少人','year'),
            $help('明天会下雨吗'),
        ]],
        ['name'=>'broad-goal-and-time-changes','turns'=>[
            $guide('业绩怎么样'),
            $s('今天现金业绩多少','cash_performance','today'),
            $s('昨天呢','cash_performance','yesterday'),
            $s('那实际业绩呢','actual_performance','yesterday'),
            $r('本月消耗业绩最高的门店是谁','consume_amount','month'),
            $r('前三名','consume_amount','month',3),
            $compare('今天跟昨天比怎么样','consume_amount'),
            $s('上个月现金业绩多少','cash_performance','last_month'),
            $c('今年消费4980有多少人','year'),
            $s('本月退款业绩多少','refund_performance','month'),
        ]],
        ['name'=>'condition-delta-and-ranking-metric','turns'=>[
            $c('今年消费4980有多少人','year'),
            $c('上个月呢','last_month'),
            $c('改为大于5000元','last_month','gt','5000'),
            $s('今天现金业绩多少','cash_performance','today'),
            $s('实际业绩呢','actual_performance','today'),
            $r('本月最高的门店呢','actual_performance','month'),
            $r('前三名','actual_performance','month',3),
            $r('按消耗业绩看呢','consume_amount','month',3),
            $r('上个月实际业绩最低的门店是哪家','actual_performance','last_month',1,'bottom'),
            $help('今天天气怎么样'),
        ]],
    ];
    // The transcript deliberately contains elliptical follow-ups. A failed
    // source question leaves their expected inherited meaning unestablished;
    // record that dependency instead of diagnosing every later mismatch as
    // a fresh context defect. These links are test intent, not runtime rules.
    $dependencies=[
        [2=>1,3=>2,5=>4,6=>5,8=>7,9=>8],
        [2=>1,3=>2,4=>3],
        [2=>1,3=>2,6=>5,8=>7],
        [3=>2,4=>3,6=>5,7=>6],
        [2=>1,3=>2,5=>4,6=>5,7=>6,8=>7],
    ];
    foreach ($dependencies as $round=>$links) foreach ($links as $turn=>$source) {
        $rounds[$round]['turns'][$turn-1]['depends_on_turn']=$source;
    }
    return $rounds;
}

/** Re-run only the approved affected chains while retaining their original
 * full-suite identifiers. Dependencies are remapped to the selected conversation
 * positions, never inferred from question text or silently dropped. */
function opt3Plan(string $mode): array
{
    $full=opt3Cases();
    if ($mode==='full') return $full;
    if ($mode!=='affected') throw new RuntimeException('TEST_PLAN_UNSUPPORTED');
    $selection=[2=>[1,2,3,4,8,9,10],3=>[1,2,3],5=>[4,5,6,7,8]];$out=[];
    foreach ($selection as $round=>$turns) {
        $selected=['name'=>'affected-'.$full[$round-1]['name'],'original_round'=>$round,'turns'=>[]];
        foreach ($turns as $originalTurn) {
            $case=$full[$round-1]['turns'][$originalTurn-1];
            $case['original_round']=$round;$case['original_turn']=$originalTurn;
            if (isset($case['depends_on_turn'])) {
                $mapped=array_search($case['depends_on_turn'],$turns,true);
                if ($mapped===false) throw new RuntimeException('TEST_DEPENDENCY_NOT_SELECTED');
                $case['depends_on_turn']=$mapped+1;
            }
            // Both conditions are already complete in the actual question.
            // A correct result after an avoidable extra card is still a UX
            // regression. Unspecified ranking direction/quantity may guide.
            if ($round===2 && in_array($originalTurn,[9,10],true)) $case['guidance_required']=false;
            $selected['turns'][]=$case;
        }
        $out[]=$selected;
    }
    return $out;
}

/** Only bounded internal codes may leave an exception; SQL, credentials,
 * business names and arbitrary provider text never enter the evidence. */
function opt3Error(Throwable $error): string
{
    $message=$error->getMessage();
    return preg_match('/^[A-Z][A-Z0-9_:.\-]{0,120}$/D',$message)?$message:'UNEXPECTED_'.str_replace('\\','_',get_class($error));
}
function opt3Emit(array $value): void {echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;flush();}
function opt3Range(string $period): array
{
    $today=new DateTimeImmutable('today');
    if ($period==='today') return [$today->format('Y-m-d'),$today->format('Y-m-d')];
    if ($period==='yesterday') {$day=$today->modify('-1 day')->format('Y-m-d');return [$day,$day];}
    if ($period==='month') return [$today->format('Y-m-01'),$today->format('Y-m-d')];
    if ($period==='year') return [$today->format('Y-01-01'),$today->format('Y-m-d')];
    if ($period==='last_month') return [$today->modify('first day of last month')->format('Y-m-d'),$today->modify('last day of last month')->format('Y-m-d')];
    throw new RuntimeException('TEST_PERIOD_UNSUPPORTED');
}

/** Persist an allow-listed projection only. Tokens, private source views,
 * names, phone numbers and complete model responses stay in process memory. */
function opt3Save(string $path,array $report): void
{
    $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (file_put_contents($path.'.tmp',$json."\n",LOCK_EX)===false || !rename($path.'.tmp',$path)) {
        throw new RuntimeException('TEST_EVIDENCE_WRITE_FAILED');
    }
    chmod($path,0600);
}
function opt3QueryProjection(array $query): array
{
    $keys=['query_shape','metric_codes','start_date','end_date','compare_range','ranking','aggregate_condition','condition_set'];
    $projection=array_intersect_key($query,array_fill_keys($keys,true));
    $projection['object_kind']=$query['business_filters']['object_kind']??'store';
    $projection['requested_store_count']=count((array)($query['store_ids']??[]));
    $projection['business_filter_fields']=array_keys((array)($query['business_filters']??[]));
    return $projection;
}
function opt3ResultProjection(array $results): array
{
    $out=[];
    foreach ($results as $result) {
        $summary=array_intersect_key($result,array_fill_keys(['period','metric_code','storage_unit','amount_cents','count','object_count','active_object_count','aggregate_value','has_more'],true));
        $rows=(array)($result['rows']??[]);$groups=[];
        foreach (['top','bottom'] as $direction) if (isset($rows[$direction])) {
            $groups[$direction]=array_map(static function(array $row,int $index):array {
                return ['position'=>$index+1]+array_intersect_key($row,array_fill_keys(['amount_cents','metric_value','business_date'],true));
            },$rows[$direction],array_keys($rows[$direction]));
        }
        if ($groups!==[]) $summary['ranking']=$groups;
        else $summary['row_count']=count($rows);
        $out[]=$summary;
    }
    return $out;
}

/** Audit transport/Reader timing without exporting prompts, completions,
 * payload hashes or private storage references from the Run counters. */
function opt3Telemetry(string $instance,string $runId): array
{
    $row=think\facade\Db::name('mohe_ai_run')->where('instance_id',$instance)->where('run_id',$runId)
        ->field('created_at,counters_json')->find();
    if (!is_array($row)) throw new RuntimeException('TEST_AUDIT_RUN_MISSING');
    $counts=json_decode($row['counters_json'],true)?:[];
    $keys=array_fill_keys(['stage','predicate','http_status','transport_errno','elapsed_ms'],true);
    $diagnostics=[];
    foreach ((array)($counts['model_diagnostics']??[]) as $code=>$diagnostic) {
        if (is_array($diagnostic)) $diagnostics[$code]=array_intersect_key($diagnostic,$keys);
    }
    $attempts=think\facade\Db::name('mohe_ai_attempt')->where('instance_id',$instance)->where('run_id',$runId)
        ->field('attempt_code,kind,state,created_at')->order('created_at asc')->select()->toArray();
    foreach ($attempts as &$attempt) {
        $code=$attempt['attempt_code'];
        $attempt['elapsed_ms']=$counts['attempt_elapsed_ms'][$code]??null;
        if (isset($diagnostics[$code])) $attempt['diagnostic']=$diagnostics[$code];
    }
    unset($attempt);
    return ['created_at_ms'=>(int)$row['created_at'],'attempts'=>$attempts,'diagnostics'=>$diagnostics,
        'latest_diagnostic'=>array_intersect_key((array)($counts['model_diagnostic']??[]),$keys)];
}

/** Supplement the actual missing slot with offered values or the public
 * single-field free-text contract used by browser-entry. Unsupported
 * guidance fails; never pick the first business candidate for completion. */
function opt3Choices(array $clarification,array $case): array
{
    $choices=[];$range=opt3Range($case['period']??'month');
    $metric=$case['metric']??null;
    $labels=['actual_performance'=>'实际业绩','cash_performance'=>'现金业绩','consume_amount'=>'消耗业绩',
        'refund_performance'=>'退款业绩','staff_sales_yeji'=>'销售业绩'];
    foreach ((array)($clarification['fields']??[]) as $field) {
        $key=$field['key']??'';$type=$field['type']??'';
        if ($type==='date') {
            $period=strpos($key,'compare_')===0?opt3Range($case['compare_period']??'yesterday'):$range;
            $choices[$key]=strpos($key,'end')!==false?$period[1]:$period[0];continue;
        }
        $options=(array)($field['options']??[]);$values=array_column($options,'value');
        if ($key==='semantic_reply' && count($clarification['fields'])===1) {
            $choices[$key]='__supplement__';
            $choices['supplement_text']=opt3Supplement($case);continue;
        }
        $wanted=[];
        if (strpos($key,'metric')!==false && is_string($metric)) $wanted=[$metric,'metric:'.$metric];
        elseif (strpos($key,'direction')!==false) {
            $direction=$case['ranking']['direction']??'top';$wanted=[$direction,'ranking_direction:'.$direction];
        } elseif (strpos($key,'limit')!==false) {
            $limit=(string)($case['ranking']['limit']??3);$wanted=[$limit,'ranking_limit:'.$limit];
        } elseif (strpos($key,'operation')!==false) $wanted=[$case['shape']??'summary','operation:'.($case['shape']??'summary')];
        elseif ($key==='analysis_object' && is_string($metric)) {
            $definition=app\services\query\metric\MetricDefinitionRegistry::get($metric);
            $wanted=[(string)($definition['analysis_default_selection_ref']??'')];
        } elseif (strpos($key,'store_scope')!==false || $key==='pending_scope') $wanted=['clear_store_scope','authorized'];
        $selected=null;
        foreach ($wanted as $value) if ($value!=='' && in_array($value,$values,true)) {$selected=$value;break;}
        if ($selected===null && strpos($key,'metric')!==false && isset($labels[$metric])) {
            foreach ($options as $option) {
                if (strpos((string)($option['label']??''),$labels[$metric])===0) {$selected=$option['value'];break;}
            }
        }
        if ($selected===null) throw new RuntimeException('TEST_GUIDANCE_UNSUPPORTED_SLOT');
        $choices[$key]=$selected;
    }
    if ($choices===[]) throw new RuntimeException('TEST_GUIDANCE_EMPTY');
    return $choices;
}

/** A semantic card exposes a free-text query slot rather than machine-readable
 * missing subfields. Reply with the scenario's complete intended query so it
 * actually supplies date/metric/operation omissions; repeating the original
 * ambiguous sentence would test an invalid reply. No candidate identity is
 * invented and the server still interprets the natural-language supplement. */
function opt3Supplement(array $case): string
{
    if (!empty($case['supplement'])) return $case['supplement'];
    $metrics=['actual_performance'=>'实际业绩','cash_performance'=>'现金业绩','consume_amount'=>'消耗业绩',
        'refund_performance'=>'退款业绩','staff_sales_yeji'=>'销售业绩'];
    $metric=$metrics[$case['metric']??'']??null;
    if ($metric===null || !isset($case['period'])) throw new RuntimeException('TEST_GUIDANCE_MEANING_UNSPECIFIED');
    [$start,$end]=opt3Range($case['period']);
    $period=$start.'至'.$end;
    $shape=$case['shape']??null;
    if ($shape==='condition_count') {
        $operators=['eq'=>'等于','gt'=>'大于','lt'=>'小于'];
        $operator=$operators[$case['operator']??'']??null;
        if ($operator===null || !isset($case['quantity'])) throw new RuntimeException('TEST_GUIDANCE_CONDITION_UNSPECIFIED');
        return '请统计'.$period.'在当前权限范围内，累计'.$metric.$operator.$case['quantity'].'元的客户人数';
    }
    if ($shape==='ranking') {
        $kind=($case['kind']??'store')==='person'?'员工':'门店';
        $direction=($case['ranking']['direction']??null)==='bottom'?'从低到高':'从高到低';
        if (!isset($case['ranking']['limit'])) throw new RuntimeException('TEST_GUIDANCE_RANKING_UNSPECIFIED');
        return '请查'.$period.'当前权限范围内'.$kind.'的'.$metric.'，'.$direction.'排名，查看前'.$case['ranking']['limit'].'名';
    }
    if ($shape==='comparison') {
        if (!isset($case['compare_period'])) throw new RuntimeException('TEST_GUIDANCE_COMPARISON_UNSPECIFIED');
        [$otherStart,$otherEnd]=opt3Range($case['compare_period']);
        return '请对比'.$period.'与'.$otherStart.'至'.$otherEnd.'当前权限范围内的'.$metric;
    }
    if ($shape==='summary') return '请查'.$period.'当前权限范围内的'.$metric.'合计';
    throw new RuntimeException('TEST_GUIDANCE_OPERATION_UNSPECIFIED');
}

/** A clarification accepted by an async worker can still return the original
 * waiting envelope. Identity is the signed card plus semantic/step revision,
 * not the Run's mutable row version. Submit each identity once, then poll until
 * execution finishes or a genuinely new card appears. Fake clocks/callbacks
 * make this transport protocol independently testable without business calls. */
function opt3DriveRun(array &$run,array $case,callable $status,callable $clarify,array &$guidance,
    ?callable $pause=null,?callable $clock=null): array
{
    $clock=$clock??static function():float{return microtime(true);};
    $pause=$pause??static function():void{usleep(250000);};
    $deadline=$clock()+210;$submitted=[];$steps=0;
    while (true) {
        if (($run['status']??null)==='WAITING_CLARIFICATION') {
            $card=$run['clarification']??null;
            if (!is_array($card)||!is_string($card['id']??null)
                ||!is_int($card['step_revision']??null)||!is_int($card['intent_revision']??null)) {
                throw new RuntimeException('TEST_GUIDANCE_ENVELOPE_INVALID');
            }
            $identity=json_encode([$card['id'],$card['step_revision'],$card['intent_revision']]);
            if (!isset($submitted[$identity])) {
                if (++$steps>6) throw new RuntimeException('TEST_GUIDANCE_DID_NOT_CONVERGE');
                $choices=opt3Choices($card,$case);
                $guidance[]=['step'=>$steps,'fields'=>array_column($card['fields'],'key'),
                    'step_revision'=>$card['step_revision'],'intent_revision'=>$card['intent_revision'],
                    'choices'=>array_diff_key($choices,['supplement_text'=>true]),
                    'supplement_provided'=>isset($choices['supplement_text']),
                    // This text is generated solely from public test data;
                    // recording it proves the actual submitted missing input.
                    // No provider text or customer identity is copied here.
                    'supplement_text'=>$choices['supplement_text']??null];
                $submitted[$identity]=true;
                $run=$clarify($run,$card,$choices);
                continue;
            }
        } elseif (!in_array($run['status']??null,['RECEIVED','WORKFLOW_EXECUTING','WAITING_EXPORT'],true)) {
            return $run;
        }
        if ($clock()>$deadline) throw new RuntimeException('TEST_RUN_POLL_TIMEOUT');
        $pause();$run=$status($run);
    }
}

/** Classify the evidence without converting a technical/driver failure into
 * a pass or blaming inheritance when its source question never succeeded. */
function opt3Classification(array $entry): string
{
    if (!empty($entry['pass'])) return 'passed';
    if (($entry['checks']['direct_first_answer']??true)===false) return 'unnecessary_guidance';
    $reason=(string)($entry['reason']??'');
    if (strpos($reason,'TEST_')===0 || $reason==='AI_CLARIFICATION_IN_PROGRESS') return 'test_driver_failure';
    $diagnostic=$entry['telemetry']['diagnostics']['terminal_contract']
        ??$entry['telemetry']['latest_diagnostic']??[];
    if (($entry['status']??null)==='FAILED' && ($diagnostic['stage']??null)==='transport'
        && (strpos($reason,'AI_MODEL_')===0 || $reason==='AI_ATTEMPT_UNRESOLVED')) {
        return 'provider_dependency_failure';
    }
    if (in_array($entry['status']??null,['FAILED','CANCELLED','NOT_ADMITTED'],true)) return 'failed_run';
    if (!empty($entry['upstream_failures'])) return 'unverified_upstream_dependency';
    if (($entry['checks']['fresh_reader_results']??true)===false || ($entry['checks']['same_authority']??true)===false) return 'reader_or_authority_mismatch';
    return 'semantic_mismatch';
}

/** The registered legacy threshold_count schema already stores integer cents.
 * Normalize that exact single-member predicate for the test oracle instead of
 * sending it through the newer human-unit condition-set compiler. This accepts
 * no missing/extra fields, multiple metrics, non-period aggregation or competing
 * condition_set, so equivalence cannot conceal a different business question. */
function opt3LegacyCondition(array $query): ?array
{
    if (($query['query_shape']??null)!=='threshold_count') return null;
    $condition=$query['aggregate_condition']??null;$keys=is_array($condition)?array_keys($condition):[];
    sort($keys,SORT_STRING);$metrics=$query['metric_codes']??null;
    if ($keys!==['aggregation','amount_cents','operator','subject']
        || ($condition['subject']??null)!=='member'||($condition['aggregation']??null)!=='period_total'
        || !in_array($condition['operator']??null,['eq','gt','gte','lt','lte'],true)
        || !is_int($condition['amount_cents']??null)||$condition['amount_cents']<1||$condition['amount_cents']>100000000000
        || !is_array($metrics)||count($metrics)!==1||!is_string($metrics[0]??null)
        || ($query['condition_set']??null)!==null) return null;
    return ['subject'=>'member','relation'=>'all','conditions'=>[
        ['metric_code'=>$metrics[0],'operator'=>$condition['operator'],'value'=>$condition['amount_cents']]]];
}

/** Compare expected meaning separately from fresh-Reader numeric parity;
 * executing a perfectly consistent wrong query still fails this suite. */
function opt3SemanticChecks(array $query,array $case,?array $previousView): array
{
    $checks=[];
    if (isset($case['metric'])) $checks['metric']=($query['metric_codes']??[])===[$case['metric']];
    if (isset($case['shape'])) $checks['shape']=($query['query_shape']??null)===$case['shape']
        || ($case['shape']==='condition_count' && ($case['kind']??null)==='member' && opt3LegacyCondition($query)!==null);
    if (isset($case['kind'])) $checks['object']=($query['business_filters']['object_kind']??'store')===$case['kind'];
    // This suite never chooses a named store/cohort; only the explicit
    // displayed-ordinal question may narrow to a prior snapshot identity.
    if (!isset($case['reference_ordinal'])) $checks['authorized_scope']=($query['store_ids']??[])===[];
    if (isset($case['period'])) $checks['period']=[$query['start_date']??null,$query['end_date']??null]===opt3Range($case['period']);
    if (isset($case['compare_period'])) {
        $expected=opt3Range($case['compare_period']);
        $checks['comparison']=[$query['compare_range']['start']??null,$query['compare_range']['end']??null]===$expected;
    }
    if (isset($case['ranking'])) {
        // No explicit count means the registered presentation bound may apply.
        // Exact counts in later questions are still asserted without tolerance.
        $limit=$query['ranking']['limit']??null;
        $checks['ranking']=($query['ranking']['direction']??null)===$case['ranking']['direction']
            && (!empty($case['ranking_limit_unspecified'])
                ? is_int($limit) && $limit>=1 && $limit<=20 : $limit===$case['ranking']['limit']);
    }
    if (isset($case['operator'])) {
        // Reader execution owns integer canonical units. The test scenarios
        // use whole yuan, so expected cents are computed independently of the
        // production condition compiler; a retained human-unit carrier must
        // also compile to the same exact condition instead of contradicting it.
        if (!preg_match('/^[0-9]+$/D',$case['quantity'])) throw new RuntimeException('TEST_EXPECTED_WHOLE_YUAN_REQUIRED');
        $expected=['subject'=>$case['kind'],'relation'=>'all','conditions'=>[
            ['metric_code'=>$case['metric'],'operator'=>$case['operator'],'value'=>(int)$case['quantity']*100]]];
        $legacy=opt3LegacyCondition($query);
        $actual=$legacy??($query['condition_set']??null);
        $human=$query['aggregate_condition']??null;
        if (is_array($human) && $legacy===null) {
            $compiled=(new app\services\ai\execution\AiConditionSetCompiler())->compile($human,
                ['metric_readiness'=>app\services\query\metric\MetricReadViewServices::metricCapabilities()],$case['shape']);
            $checks['condition_carrier_equivalence']=app\services\query\UnifiedQueryJson::encode($compiled)
                ===app\services\query\UnifiedQueryJson::encode($expected);
            if ($actual===null) $actual=$compiled;
        }
        $checks['condition']=is_array($actual) && app\services\query\UnifiedQueryJson::encode($actual)
            ===app\services\query\UnifiedQueryJson::encode($expected);
    }
    if (isset($case['reference_ordinal'])) {
        $point=$previousView['results'][0]['rows']['top'][$case['reference_ordinal']-1]??null;
        $checks['displayed_reference']=is_array($point) && isset($point['store_id'])
            && ($query['store_ids']??[])===[$point['store_id']];
        $checks['reference_not_reranked']=($query['query_shape']??null)!=='ranking';
    }
    return $checks;
}

function opt3Run(): void
{
    $driverHash=(string)getenv('MOHE_OPT3_DRIVER_SHA256');
    if (!preg_match('/^[a-f0-9]{64}$/D',$driverHash)) throw new RuntimeException('TEST_DRIVER_FINGERPRINT_REQUIRED');
    $planName=(string)(getenv('MOHE_OPT3_TEST_PLAN')?:'full');$cases=opt3Plan($planName);
    $total=array_sum(array_map(static function(array $round):int{return count($round['turns']);},$cases));
    require getcwd().'/vendor/autoload.php';
    (new think\App())->initialize();
    $db=(array)config('database.connections.'.config('database.default'));
    if (($db['hostname']??null)!=='mysql' || ($db['database']??null)!=='ruihao'
        || (think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid']??null)!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a') {
        throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
    }
    $runtime=app\services\ai\execution\AiRuntimeFactory::make();
    if (!app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($runtime['config']->read())) throw new RuntimeException('LOCAL_AI_AUTH_REQUIRED');
    $id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
    $resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
    $context=$resolver->authenticated($id);
    if (empty($context['can_use'])) throw new RuntimeException('LOCAL_ADMIN_AUTH_REQUIRED');
    $refresh=static function()use($resolver,$id):array{return $resolver->authenticated($id);};
    $context['_refresh']=$refresh;
    $active=think\facade\Db::name('mohe_ai_run')->where('instance_id',$runtime['instance'])->where('account_id',$id)
        ->where('terminal','platform')->whereNotIn('status',['COMPLETED','PARTIAL_SUCCEEDED','FAILED','CANCELLED'])->count();
    if ($active) throw new RuntimeException('LOCAL_USER_CONVERSATION_ACTIVE');
    // A terminal audit status does not prove a worker released its slot.
    // Do not accumulate work behind an unrecovered process/Reader failure.
    if (think\facade\Db::name('mohe_ai_run')->where('instance_id',$runtime['instance'])->where('slot_held',1)->count()) {
        throw new RuntimeException('LOCAL_EXECUTION_SLOT_NOT_RECOVERED');
    }
    $gateway=new app\services\ai\AiGatewayServices();
    $reader=new app\services\query\metric\MetricReadViewServices($runtime['views'],static function()use($refresh,$runtime):array {
        return app\services\ai\execution\AiAuthority::reportBinding($refresh(),$runtime['instance'],$runtime['private']->signingKey());
    },null,null,new app\services\query\metric\PersonnelAnalysisObjectServices(
        static function(string $table){return think\facade\Db::name($table);},static function()use($refresh):array {
            $c=$refresh();return ['personnel_authorized'=>!empty($c['personnel_data_authorized']),
                'store_ids'=>$c['store_ids'],'employee_id'=>($c['scope_mode']??null)==='self_participant'?(int)$c['employee_id']:0,
                'permission_version'=>app\services\ai\execution\AiAuthority::permissionHash($c)];
        }),new app\services\query\metric\MemberAnalysisObjectServices(
        static function(string $table){return think\facade\Db::name($table);},static function()use($refresh):array {
            $c=$refresh();return ['member_authorized'=>!empty($c['member_data_authorized']),
                'store_ids'=>$c['store_ids'],'scope_mode'=>$c['scope_mode'],
                'permission_version'=>app\services\ai\execution\AiAuthority::permissionHash($c)];
        }));
    $directory=rtrim(app()->getRuntimePath(),DIRECTORY_SEPARATOR).'/mohe-ai-opt3-local';
    if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('TEST_EVIDENCE_DIRECTORY_FAILED');
    $batch='OPT3-local-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));$path=$directory.'/'.$batch.'.json';
    $files=['app/services/ai/AiGatewayServices.php','app/services/ai/execution/AiRunStore.php',
        'app/services/ai/execution/AiRuntimeFactory.php','app/services/ai/execution/AiRunExecutionRuntime.php',
        'app/command/MoheAiExecutionWorker.php',
        'app/services/ai/contract/AiIntentUnderstandingContract.php','app/services/ai/contract/AiIntentResultContract.php',
        'app/services/ai/context/ResultReferenceResolver.php',
        'app/services/ai/context/ObjectDetailContinuationResolver.php',
        'app/services/ai/context/MemberDetailContinuationResolver.php',
        'app/services/ai/model/SiliconFlowClient.php',
        'app/services/query/metric/ServiceCustomerMetricReadServices.php',
        'app/services/query/metric/RegisteredMetricReadServices.php',
        'app/services/query/metric/GroupPerformanceMetricReadServices.php',
        'app/services/query/metric/MetricReadTransaction.php','app/services/query/metric/MetricReadViewServices.php',
        'app/services/query/metric/PersonnelAnalysisObjectServices.php',
        // These pre-existing workspace dependencies define business meaning;
        // recording HEAD alone would not identify the executed oracle/source.
        'app/services/metric/MetricDictionaryServices.php','app/services/query/metric/MetricDefinitionRegistry.php',
        'app/services/report/StoreUnifiedReportPhaseTwoServices.php'];$hashes=[];
    foreach ($files as $file) $hashes[$file]=hash_file('sha256',getcwd().'/'.$file);
    $report=['suite'=>$planName==='full'?'OPT3-new-mixed-local-5x10':'OPT3-affected-local-15',
        'test_plan'=>$planName,'original_transcript_replay'=>false,'started_at'=>date('c'),
        'original_task_baseline'=>(string)getenv('MOHE_OPT3_TASK_BASELINE'),
        'execution_start_head'=>(string)getenv('MOHE_OPT3_BASELINE'),'driver_sha256'=>$driverHash,'source_sha256'=>$hashes,
        'target'=>['container'=>'mohe-platform-app','database'=>'ruihao','server_uuid'=>'3506c91f-98cb-11f1-af5f-9e1a52451c2a'],
        'boundary'=>'local AI audit and immutable query snapshots only; fresh same-authority Reader cross-check',
        'rounds'=>[],'pass'=>false];
    opt3Save($path,$report);opt3Emit(['event'=>'started','batch'=>$batch,'evidence_path'=>$path]);
    $allRunIds=[];
    foreach ($cases as $roundIndex=>$round) {
        $client='opt3-local-'.bin2hex(random_bytes(8));$conversation='opt3-local-'.bin2hex(random_bytes(8));
        $boot=$gateway->handle('bootstrap',$context,['client_session_id'=>$client]);
        $history=[];$reference=null;$previousView=null;
        $roundReport=['round'=>$roundIndex+1,'name'=>$round['name'],'conversation_id'=>$conversation,'turns'=>[]];
        foreach ($round['turns'] as $turnIndex=>$case) {
            $entry=['turn'=>$turnIndex+1,'question'=>$case['question'],'expected'=>$case,'status'=>null,
                'reason'=>null,'guidance'=>[],'admission_wait_ms'=>0,'checks'=>[],'pass'=>false];
            $entry['upstream_failures']=[];
            if (isset($case['depends_on_turn'])) {
                $source=$roundReport['turns'][$case['depends_on_turn']-1]??null;
                if (!is_array($source)) throw new RuntimeException('TEST_DEPENDENCY_ORDER_INVALID');
                if (empty($source['pass'])) $entry['upstream_failures'][]=$case['depends_on_turn'];
                $entry['upstream_failures']=array_values(array_unique(array_merge($entry['upstream_failures'],$source['upstream_failures']??[])));
            }
            $run=null;$delivery=null;$started=microtime(true);$admissionStarted=$started;
            try {
                $input=['client_request_id'=>'opt3-'.bin2hex(random_bytes(10)),
                    'conversation_id'=>$conversation,'client_session_id'=>$client,'window_token'=>$boot['window_token'],
                    'question'=>$case['question'],'history'=>array_slice($history,-20),'output_format'=>'screen',
                    'guidance_schema_version'=>'mohe-clarification-v2'];
                if ($reference!==null) $input['context_ref']=$reference;
                while (true) {
                    $run=$gateway->handle('create',$context,$input);
                    if (isset($run['run_id'])) break;
                    if (($run['reason']??null)!=='RATE_LIMITED') throw new RuntimeException('RUN_NOT_ADMITTED_'.(string)($run['reason']??'UNKNOWN'));
                    if (microtime(true)-$admissionStarted>1800) throw new RuntimeException('TEST_RATE_LIMIT_WAIT_EXHAUSTED');
                    $now=(int)floor(microtime(true)*1000);
                    $oldest=think\facade\Db::name('mohe_ai_run')->where('instance_id',$runtime['instance'])
                        ->where('account_id',$id)->where('terminal','platform')->where('created_at','>',$now-600000)->min('created_at');
                    $wait=max(1000,(int)$oldest+600100-$now);$entry['admission_wait_ms']+=$wait;
                    opt3Emit(['event'=>'rate_limit_wait','round'=>$roundIndex+1,'turn'=>$turnIndex+1,'wait_ms'=>$wait]);
                    // Bounded sleeps allow the orchestrator to report progress.
                    $until=microtime(true)+$wait/1000;
                    while (microtime(true)<$until) usleep((int)(min(5,$until-microtime(true))*1000000));
                    // A rejected request has an immutable receipt. A fresh key
                    // after the existing window expires retries admission;
                    // no Run, quota, config or timestamp is edited.
                    $input['client_request_id']='opt3-'.bin2hex(random_bytes(10));
                }
                $allRunIds[]=$run['run_id'];$entry['run_id']=$run['run_id'];
                $delivery=['client_session_id'=>$client,'generation'=>$run['generation'],'run_delivery_token'=>$run['run_delivery_token']];
                $executionStarted=microtime(true);
                $run=$gateway->handle('execute',$context,$input+$delivery,$run['run_id']);
                opt3DriveRun($run,$case,
                    static function(array $current)use($gateway,$context,$delivery):array {
                        return $gateway->handle('status',$context,$delivery,$current['run_id']);
                    },static function(array $current,array $card,array $choices)use($gateway,$context,$delivery):array {
                        return $gateway->handle('clarify',$context,$delivery+[
                            'schema_version'=>'mohe-clarification-v2','clarification_id'=>$card['id'],
                            'step_revision'=>$card['step_revision'],'intent_revision'=>$card['intent_revision'],
                            'client_submission_id'=>'opt3-answer-'.bin2hex(random_bytes(8)),'choices'=>$choices],$current['run_id']);
                    },$entry['guidance']);
                $entry['elapsed_ms']=(int)round((microtime(true)-$executionStarted)*1000);
                $entry['status']=$run['status']??null;$entry['reason']=$run['reason']??null;
                $entry['topic_boundary']=$run['topic_boundary']??null;
                if (!empty($case['capability_help'])) {
                    $entry['checks']['capability_boundary']=($run['status']??null)==='FAILED'
                        && ($run['reason']??null)==='AI_INTENT_UNRESOLVED'
                        && ($run['failure_guidance']['kind']??null)==='capability_help' && $entry['guidance']===[];
                    $entry['answer_summary']='系统能力范围说明；无业务查询结果';
                } else {
                    $entry['checks']['completed']=($run['status']??null)==='COMPLETED';
                    if (($run['status']??null)==='COMPLETED') {
                        $row=think\facade\Db::name('mohe_ai_run')->where('run_id',$run['run_id'])->find();
                        $evidence=$runtime['private']->read($row['evidence_ref']);
                        $query=$evidence['query']??null;$viewRef=$evidence['view_ref']??null;
                        if (!is_array($query)||!is_string($viewRef)) throw new RuntimeException('TEST_SINGLE_QUERY_EVIDENCE_REQUIRED');
                        $view=$runtime['views']->get($viewRef);
                        $entry['query']=opt3QueryProjection($query);
                        $entry['checks']+=opt3SemanticChecks($query,$case,$previousView);
                        $fresh=$reader->create(['account_id'=>$id],$query);
                        $entry['checks']['fresh_reader_results']=app\services\query\UnifiedQueryJson::encode($view['results'])
                            ===app\services\query\UnifiedQueryJson::encode($fresh['results']);
                        $entry['checks']['same_authority']=app\services\query\UnifiedQueryJson::encode($view['binding'])
                            ===app\services\query\UnifiedQueryJson::encode($fresh['binding']);
                        $entry['result_summary']=opt3ResultProjection($view['results']);
                        $entry['answer_summary']='查询完成；'.count($view['results']).'组已核对指标结果；姓名与联系方式不进入证据';
                        $previousView=$view;
                    }
                    if (array_key_exists('guidance_required',$case)) {
                        $entry['checks'][$case['guidance_required']?'required_guidance':'direct_first_answer']
                            =($entry['guidance']!==[])===$case['guidance_required'];
                    }
                }
                $entry['pass']=$entry['checks']!==[] && !in_array(false,$entry['checks'],true);
                if (($run['topic_boundary']??null)==='reset') {
                    $history=[];$reference=null;
                    // A successful new topic has just established its own
                    // frozen result; a failed new topic has established none.
                    if (($run['status']??null)!=='COMPLETED') $previousView=null;
                }
                $answer=$run['answer']['summary']??$run['failure_guidance']['question']??'';
                $history[]=['question'=>$case['question'],'answer'=>(string)$answer];
                if (is_string($run['answer']['context_ref']??null)) $reference=$run['answer']['context_ref'];
                elseif (in_array($run['status']??null,['COMPLETED','PARTIAL_SUCCEEDED','CANCELLED'],true)) $reference=null;
            } catch (Throwable $error) {
                $entry['reason']=opt3Error($error);$entry['status']=$run['status']??'NOT_ADMITTED';
                $entry['pass']=false;
                // Only this suite's own unresolved Run may be stopped after
                // recording the failure, so the next independent test can
                // proceed without cancelling a customer's active work.
                if (is_array($run) && isset($run['run_id']) && is_array($delivery)
                    && !in_array($run['status']??null,['COMPLETED','PARTIAL_SUCCEEDED','FAILED','CANCELLED'],true)) {
                    try {$gateway->handle('cancel',$context,$delivery,$run['run_id']);$entry['cleanup_cancelled']=true;}
                    catch(Throwable $cleanup) {$entry['cleanup_error']=opt3Error($cleanup);}
                }
                if (($run['topic_boundary']??null)==='reset') {$history=[];$reference=null;$previousView=null;}
            }
            if (isset($entry['run_id'])) {
                try {$entry['telemetry']=opt3Telemetry($runtime['instance'],$entry['run_id']);}
                catch (Throwable $auditError) {$entry['telemetry_error']=opt3Error($auditError);$entry['pass']=false;}
            }
            $entry['classification']=opt3Classification($entry);
            $entry['total_wall_ms']=(int)round((microtime(true)-$started)*1000);
            $roundReport['turns'][]=$entry;$report['rounds'][$roundIndex]=$roundReport;opt3Save($path,$report);
            opt3Emit(['event'=>'turn','round'=>$roundIndex+1,'turn'=>$turnIndex+1,'run_id'=>$entry['run_id']??null,
                'original_case'=>isset($case['original_round'],$case['original_turn'])?'R'.$case['original_round'].'T'.$case['original_turn']:null,
                'status'=>$entry['status'],'reason'=>$entry['reason'],'elapsed_ms'=>$entry['elapsed_ms']??null,
                'classification'=>$entry['classification'],'pass'=>$entry['pass']]);
        }
        $roundReport['passed']=count(array_filter($roundReport['turns'],static function(array $entry):bool{return $entry['pass'];}));
        $roundReport['classifications']=array_count_values(array_column($roundReport['turns'],'classification'));
        $report['rounds'][$roundIndex]=$roundReport;opt3Save($path,$report);
        opt3Emit(['event'=>'round_finished','round'=>$roundIndex+1,'passed'=>$roundReport['passed'],
            'classifications'=>$roundReport['classifications'],'total'=>count($round['turns'])]);
    }
    $report['finished_at']=date('c');$report['total']=$total;
    $report['passed']=array_sum(array_column($report['rounds'],'passed'));
    $classes=[];
    foreach ($report['rounds'] as $round) $classes=array_merge($classes,array_column($round['turns'],'classification'));
    $report['classifications']=array_count_values($classes);
    $report['active_suite_runs']=$allRunIds?think\facade\Db::name('mohe_ai_run')->whereIn('run_id',$allRunIds)
        ->whereNotIn('status',['COMPLETED','PARTIAL_SUCCEEDED','FAILED','CANCELLED'])->count():0;
    $finalHashes=[];
    foreach ($files as $file) $finalHashes[$file]=hash_file('sha256',getcwd().'/'.$file);
    $report['source_unchanged']=$finalHashes===$hashes;
    $report['pass']=$report['passed']===$total && $report['active_suite_runs']===0 && $report['source_unchanged'];
    opt3Save($path,$report);opt3Emit(['event'=>'finished','evidence_path'=>$path,'passed'=>$report['passed'],
        'total'=>$total,'active_suite_runs'=>$report['active_suite_runs'],'pass'=>$report['pass']]);
    if (!$report['pass']) exit(1);
}

// Protocol tests load only pure driver functions and never application state,
// credentials, database connections, model calls or business Readers.
if (defined('MOHE_OPT3_DRIVER_LIBRARY') && MOHE_OPT3_DRIVER_LIBRARY===true) return;

try {
    if (getenv('MOHE_OPT3_LOCAL')==='PLAN') {
        $planName=(string)(getenv('MOHE_OPT3_TEST_PLAN')?:'full');$cases=opt3Plan($planName);
        opt3Emit(['suite'=>$planName==='full'?'OPT3-new-mixed-local-5x10':'OPT3-affected-local-15','rounds'=>count($cases),
            'questions'=>array_sum(array_map(static function(array $round):int{return count($round['turns']);},$cases)),
            'cases'=>$cases]);
    } elseif (getenv('MOHE_OPT3_LOCAL')==='RUN') opt3Run();
    else throw new RuntimeException('LOCAL_ONLY_OPT_IN_REQUIRED');
} catch (Throwable $error) {opt3Emit(['event'=>'fatal','reason'=>opt3Error($error)]);exit(2);}
