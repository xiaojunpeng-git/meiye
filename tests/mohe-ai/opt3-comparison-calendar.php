<?php
declare(strict_types=1);

/** Calendar correction must reuse the shared ordered date projection only
 * for one complete, current two-window comparison. Synthetic malformed model
 * dates reproduce the recovery gap; they are not the recorded live payload. */
require __DIR__.'/fixture-autoload.php';

use app\services\ai\AiGatewayServices;
use app\services\ai\execution\AiTemporalPeriodResolver;
use app\services\query\metric\MetricQueryDatePolicy;
use app\services\query\metric\MetricQueryContractException;

$gateway=(new ReflectionClass(AiGatewayServices::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod($gateway,'resolveExactStatedPeriods');
if (PHP_VERSION_ID<80100) $method->setAccessible(true);
$today='2026-10-11';$checks=0;$failures=[];
$check=static function(bool $ok,string $label)use(&$checks,&$failures):void {
    $checks++;if (!$ok) $failures[]=$label;
};
$meaning=static function(string $text,array $periods):array {
    return ['goal'=>'compare the two stated periods','status'=>'understood','topic_relation'=>'new_topic',
        'requirements'=>[
            ['id'=>'r1','meaning'=>'stated measurement','fields'=>['metric_codes'],
                'values'=>['metric_terms'=>['现金业绩']],'evidence'=>[['message_id'=>'current','quote'=>$text]]],
            ['id'=>'r2','meaning'=>'compare values','fields'=>['operation'],
                'values'=>['operation'=>'comparison'],'evidence'=>[['message_id'=>'current','quote'=>$text]]],
            ['id'=>'r3','meaning'=>'ordered comparison dates','fields'=>['periods'],
                'values'=>['periods'=>$periods],'evidence'=>[['message_id'=>'current','quote'=>$text]]],
        ]];
};
$wrong=[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0],
    ['kind'=>'relative_days','days'=>1,'end_offset_days'=>1]];
foreach ([
    '今天跟昨天现金业绩对比'=>['2026-10-11','2026-10-10'],
    '昨天和今天现金业绩对比'=>['2026-10-10','2026-10-11'],
] as $text=>$dates) {
    $input=$meaning($text,$wrong);
    $out=$method->invoke($gateway,$input,['question'=>$text],$today);
    $ranges=array_map(static function(array $period)use($today):array {
        return AiTemporalPeriodResolver::resolve($period,$today);
    },$out['requirements'][2]['values']['periods']);
    $check($ranges===[['start'=>$dates[0],'end'=>$dates[0]],['start'=>$dates[1],'end'=>$dates[1]]],
        $text.' resolves both windows in the stated order');
    $expected=$input;$expected['requirements'][2]['values']['periods']=$out['requirements'][2]['values']['periods'];
    $check($out===$expected,'calendar repair preserves every non-date carrier and topic boundary');
}
$text='今天跟昨天现金业绩对比';$base=$meaning($text,$wrong);
$grouped=$base;$grouped['groups']=[['id'=>'q1','requirement_ids'=>['r1','r2']],['id'=>'q2','requirement_ids'=>['r3']]];
$check($method->invoke($gateway,$grouped,['question'=>$text],$today)===$grouped,
    'independent object/result groups never receive guessed period ownership');
$historical=$base;$historical['requirements'][2]['evidence'][0]['message_id']='recent_1';
$check($method->invoke($gateway,$historical,['question'=>$text],$today)===$historical,
    'historical date evidence cannot authorize a current comparison correction');
$split=$base;$split['requirements'][2]['values']['periods']=[$wrong[0]];
$split['requirements'][]=['id'=>'r4','meaning'=>'separate period owner','fields'=>['periods'],
    'values'=>['periods'=>[$wrong[1]]],'evidence'=>[['message_id'=>'current','quote'=>$text]]];
$check($method->invoke($gateway,$split,['question'=>$text],$today)===$split,
    'separate date carriers cannot be assigned to comparison sides by array order');
$nonComparison=$base;$nonComparison['requirements'][1]['values']['operation']='summary';
$check($method->invoke($gateway,$nonComparison,['question'=>$text],$today)===$nonComparison,
    'two date words cannot turn an accepted noncomparison into a comparison');
$ambiguous=$meaning('最近跟之前现金业绩对比',$wrong);
$check($method->invoke($gateway,$ambiguous,['question'=>'最近跟之前现金业绩对比'],$today)===$ambiguous,
    'incomplete calendar wording remains model-owned');

// Explicit future meaning remains unavailable: correction is not a clamp
// that silently rewrites tomorrow as today to bypass the Reader date policy.
$futureText='今天和明天现金业绩对比';$future=$meaning($futureText,$wrong);
$out=$method->invoke($gateway,$future,['question'=>$futureText],$today);
$range=AiTemporalPeriodResolver::resolve($out['requirements'][2]['values']['periods'][1],$today);
$check($range===['start'=>'2026-10-12','end'=>'2026-10-12'],'explicit future window remains future');
try {MetricQueryDatePolicy::assertExecutable($range,$today);$check(false,'future date must remain rejected');}
catch (MetricQueryContractException $error) {$check($error->getErrorCode()==='METRIC_QUERY_FUTURE_UNAVAILABLE','future date policy remains enforced');}

foreach ($failures as $failure) fwrite(STDERR,'FAIL '.$failure.PHP_EOL);
if ($failures!==[]) exit(1);
echo 'PASS OPT3 ordered comparison calendar checks='.$checks.PHP_EOL;
