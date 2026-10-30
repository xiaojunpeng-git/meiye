<?php
namespace app\services\report {
    /** 隔离领域来源；测试使用真实统一执行器，不访问业务数据库。 */
    final class BasicBusinessReportServices {
        public static $source=[];
        public static function fields($code){return [
            ['key'=>'row_id','label'=>'行','type'=>'text','summable'=>false],
            ['key'=>'store_id','label'=>'门店ID','type'=>'integer','summable'=>false],
            ['key'=>'amount','label'=>'金额','type'=>'decimal','summable'=>true],
            ['key'=>'ratio','label'=>'比率','type'=>'decimal','summable'=>false],
        ];}
        public static function read($code,$stores,$input,$context){return self::$source;}
    }
}
namespace think\facade { final class Db {public static function transaction($fn){return $fn();}} }
namespace {
require dirname(__DIR__,3).'/后端代码/vendor/autoload.php';
use app\services\report\BasicBusinessReportServices as Source;
use app\services\report\query\BasicBusinessQueryProvider;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\StructuredExpressionValidator;
use app\services\query\StructuredExpressionEvaluator;
class SummaryTestProvider extends BasicBusinessQueryProvider {protected const CODE='basic_item_consumption';}
class ExistingEmployeeSummaryProvider extends BasicBusinessQueryProvider {protected const CODE='basic_employee_summary';}
$registry=new UnifiedQueryPageRegistry();$fields=[];
foreach(Source::fields('') as $f)$fields[]=UnifiedQueryPageRegistry::field($f['key'],$f['label'],$f['type'],true,false);
$registry->registerPage('basic_item_consumption','测试',$fields,'row_id');$registry->registerPage('basic_employee_summary','既有员工统计',$fields,'row_id');$registry->freeze();
$provider=new SummaryTestProvider(new UnifiedQueryExecutionServices($registry,new StructuredExpressionValidator($registry),new StructuredExpressionEvaluator()));
$context=['query_cutoff_date'=>'2026-10-30','visible_store_ids'=>[8],'basic_scope_mode'=>'stores','permissions'=>[]];
$rows=[];for($i=0;$i<21;$i++)$rows[]=['row_id'=>(string)$i,'store_id'=>8,'amount'=>'1.25','ratio'=>10];
Source::$source=['rows'=>$rows,'fields'=>Source::fields(''),'sum_fields'=>['amount'],'field_sources'=>[], 'complete'=>true];
$checks=0;$check=static function($ok,$why)use(&$checks){++$checks;if(!$ok)throw new RuntimeException($why);};
$r=$provider->query($context,[]);
$check((float)$r['summaries']['amount:sum']===26.25&&count($r['rows'])===20,'合计覆盖完整21行，金额保留分');
try{$provider->query($context,['summaries'=>[['field'=>'store_id','aggregation'=>'sum']]]);throw new RuntimeException('门店ID被求和');}catch(InvalidArgumentException $e){$check(true,'拒非可加字段');}
Source::$source['table_layout']=['summary_position'=>'none'];
$r=$provider->query($context,['export'=>['scope'=>'query','fields'=>['row_id','amount']]]);
$check($r['summaries']===[]&&count($r['exportRows'])===21,'无合计表的API和导出均不生成合计');
Source::$source['summary_cards']=[['key'=>'amount','label'=>'金额','money'=>true]];
$r=$provider->query($context,['filters'=>[['field'=>'row_id','operator'=>'eq','value'=>'0']]]);
$check((float)$r['summary_cards'][0]['value']===1.25&&$r['pagination']['total']===1,'工具栏指标受同一筛选控制');
Source::$source['complete']=false;Source::$source['data_gaps']=[['code'=>'UNVERIFIED']];
$r=$provider->query($context,[]);
$check($r['summary_cards'][0]['value']===null&&$r['summaries']===[],'不完整来源不显示误导合计');
// 本轮共享缺口处理不得清空明确排除的员工统计表已核实金额列。
Source::$source['table_layout']=['summary_position'=>'first_row'];unset(Source::$source['summary_cards']);
$existing=new ExistingEmployeeSummaryProvider(new UnifiedQueryExecutionServices($registry,new StructuredExpressionValidator($registry),new StructuredExpressionEvaluator()));
$r=$existing->query($context,[]);
$check((float)$r['summaries']['amount:sum']===26.25,'既有员工统计表不因其他待核指标丢失已核实合计');
echo json_encode(['pass'=>true,'checks'=>$checks]),PHP_EOL;
}
