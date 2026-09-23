<?php
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\semantic\AiExactRankingCollectionAdmission;

$admission=new AiExactRankingCollectionAdmission();
$objects=[
    ['object_kind'=>'business_date','object_label'=>'哪天'],
    ['object_kind'=>'business_date','object_label'=>'日期'],
    ['object_kind'=>'project','object_label'=>'项目'],
    ['object_kind'=>'store','object_label'=>'门店'],
];
$metrics=['sales_amount','sales_record_count','cash_performance'];
$checks=0;
$verify=static function(bool $condition,string $message)use(&$checks):void{
    $checks++;if(!$condition)throw new RuntimeException($message);
};

$compound=$admission->match('这个月的销售额最高是哪天，销售记录最多的项目是哪个，最低是哪个',$objects,$metrics);
$verify(is_array($compound)&&count($compound)===2,'complete compound ranking should be admitted');
$verify(($compound[0]['metric_code']??null)==='sales_amount'
    &&($compound[0]['object_kind']??null)==='business_date'
    &&($compound[0]['direction']??null)==='top','date extremum keeps its registered metric and dimension');
$verify(($compound[1]['metric_code']??null)==='sales_record_count'
    &&($compound[1]['object_kind']??null)==='project'
    &&($compound[1]['direction']??null)==='top_and_bottom','coordinated bottom tail stays with the preceding project metric');

$verify($admission->match('这个月销售额最高是哪天，顺便分析原因',$objects,$metrics)===null,
    'open analysis residue must remain on the model path');
$verify($admission->match('这个月业绩最高是哪天，销售记录最多的项目是哪个',$objects,$metrics)===null,
    'an unregistered or ambiguous measurement must not receive a guessed metric');
$singleDate=$admission->match('这个月销售额最高是哪天',$objects,$metrics);
$verify(is_array($singleDate)&&count($singleDate)===1
    &&($singleDate[0]['metric_code']??null)==='sales_amount'
    &&($singleDate[0]['object_kind']??null)==='business_date'
    &&($singleDate[0]['direction']??null)==='top',
    'a fully closed single date extremum uses the same exact semantic boundary');
$singleHeadTail=$admission->match('这个月销售记录最多的项目是哪个，最低又是哪个',$objects,$metrics);
$verify(is_array($singleHeadTail)&&count($singleHeadTail)===1
    &&($singleHeadTail[0]['metric_code']??null)==='sales_record_count'
    &&($singleHeadTail[0]['object_kind']??null)==='project'
    &&($singleHeadTail[0]['direction']??null)==='top_and_bottom',
    'one metric may preserve a coordinated highest-and-lowest result without becoming two subjects');

echo "exact ranking collection admission: {$checks} checks PASS\n";
