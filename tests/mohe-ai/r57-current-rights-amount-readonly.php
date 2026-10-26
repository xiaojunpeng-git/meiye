<?php
declare(strict_types=1);

/**
 * Read-only local smoke test for the registered current rights amount.
 * The fixed store scope keeps this business-path check bounded; full-scope
 * performance is verified separately before any release.
 */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();

use app\services\query\metric\GroupPerformanceMetricReadServices;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;

$reader=new GroupPerformanceMetricReadServices();
$today=date('Y-m-d');
$condition=['subject'=>'member','relation'=>'all','conditions'=>[
    ['metric_code'=>'member_remaining_project_amount','operator'=>'gt','value'=>5000000],
]];
$started=microtime(true);
$list=$reader->conditionMembers('0',[1],['start'=>$today,'end'=>$today],$condition,1000);
$seconds=round(microtime(true)-$started,2);
if ($list['count']!==count($list['rows']) || $list['has_more']) {
    throw new RuntimeException('FAIL bounded list/count mismatch');
}
foreach ($list['rows'] as $row) {
    if (($row['metrics']['member_remaining_project_amount']??0)<=5000000) {
        throw new RuntimeException('FAIL current rights threshold mismatch');
    }
}
$detail=new CashierV3MemberDetailQueryServices();
$single=(new ReflectionClass($detail))->getMethod('cards');
$single->setAccessible(true);
$ids=array_column($list['rows'],'member_id');
$batch=$detail->readAiRemainingProjectAmounts($ids,'0',[1]);
foreach ($ids as $memberId) {
    $expected='0.00';
    foreach ($single->invoke($detail,$memberId,'0') as $card) {
        if (($card['statusCode']??'')==='enabled') $expected=bcadd($expected,(string)$card['remainingAmount'],2);
    }
    if ((int)bcmul($expected,'100',0)!==$batch[$memberId]) {
        throw new RuntimeException('FAIL batch/single rights amount parity for member '.$memberId);
    }
}
$count=$reader->conditionMembers('0',[1],['start'=>$today,'end'=>$today],$condition,0);
if ($count['count']!==$list['count'] || $count['rows']!==[]) {
    throw new RuntimeException('FAIL count/list parity');
}
echo 'PASS current rights amount threshold: count='.$list['count'].' list_seconds='.$seconds.PHP_EOL;
