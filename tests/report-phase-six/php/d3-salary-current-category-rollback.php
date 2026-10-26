<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

// The PM-approved current-category dimension is tested by changing only local catalogue fixtures.
// Original sale/service/card/performance/refund facts are read back unchanged; all edits roll back.
require '/var/www/html/vendor/autoload.php';
$app=new \think\App('/var/www/html/');$app->initialize();
set_exception_handler(static function(Throwable $error):void{
    file_put_contents('php://stderr',$error->getMessage()."\n");exit(1);
});
$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok)throw new RuntimeException($message);
    $checks++;echo "PASS: $message\n";
};
$s=new StoreUnifiedReportPhaseSixServices();
$range=['start'=>'2026-10-01','end'=>'2026-10-09'];
$query=static function(string $report='phase_six_salary_detail',array $input=[])use($s,$range):array{
    return $s->query($report,[126],$range,$input+['_internal_all'=>true]);
};
$method=static function(string $name)use($s):ReflectionMethod{
    $m=new ReflectionMethod($s,$name);$m->setAccessible(true);return $m;
};
$factsRead=$method('salaryFactsWithReportingDimensions');
$saleRead=$method('salaryCurrentSaleCategories');$cardRead=$method('salaryCardCategoryAllocations');
$allocate=$method('salarySaleCategoryAmounts');$componentRead=$method('salaryRefundComponentCategories');
$sources=static function():array{
    $out=[];
    foreach(['cashier_v3_sale_fact','cashier_v3_entitlement_service_fact',
        'cashier_v3_card_sale_category_allocation_fact','cashier_v3_performance_fact','cashier_v3_refund_adjustment'] as $table){
        $out[$table]=Db::name($table)->where('store_id',126)->whereBetween('business_date',['2026-10-01','2026-10-09'])
            ->order('id')->select()->toArray();
    }
    return $out;
};
$sourceBefore=$sources();$before=$query();$factsBefore=$factsRead->invoke($s,[126],$range);
$ids=[466474,364970,91111,466208,64544,91558,91038,364496];
$products=Db::name('store_product')->whereIn('id',$ids)->order('id')->select()->toArray();
$assert(count($products)===count($ids),'actual sale, service, card component, refund and parent fixtures exist');
$cardSale=Db::name('cashier_v3_sale_fact')->where('fact_id','CFS-843eed8343194c676b4653242a42208b5f308fa9')
    ->field('fact_id sale_fact_id,store_id,source_type')->find();
$assert($cardSale!==null && $cardSale['source_type']==='card','historical card allocation fixture exists');
$componentFixture=['target_type'=>'card','card_component_id'=>4123033,'card_holder_id'=>1746649,'store_id'=>126];
$assert($componentRead->invoke($s,[$componentFixture],[126])===[],
    'historical card component with an invalid current category remains unclassified');
$assert($before['metric_version']==='report-phase-six-v2:salary-presale-allocation-v1',
    'salary identifies the presale allocation and current-product classification version');
$strip=static function(array $rows):array{
    foreach($rows as &$row)unset($row['performance_category']);unset($row);return $rows;
};
$money=static function(array $facts):array{
    $out=[];foreach($facts as $fact)$out[$fact['row_key']]=[
        $fact['employee_id'],$fact['store_id'],$fact['business_date'],$fact['cash_cents'],
        $fact['consumption_cents'],$fact['labor_fee_cents'],$fact['project_count'],
        $fact['service_visit_tenths'],$fact['service_people_tenths']];
    ksort($out);return $out;
};
Db::startTrans();
try{
    Db::name('store_product')->whereIn('id',$ids)->order('id')->lock(true)->select();
    // Existing historical classifications must change in the query, not remain frozen as a fallback.
    Db::name('store_product')->where('id',466474)->update(['cate_id'=>'26332']);
    Db::name('store_product')->whereIn('id',[364970,91111])->update(['cate_id'=>'26321']);
    Db::name('store_product')->where('id',466208)->update(['cate_id'=>'26332']);
    Db::name('store_product')->where('id',364496)->update(['cate_id'=>'26321']);
    $assert($componentRead->invoke($s,[$componentFixture],[126])===
        ['126|4123033'=>['id'=>26321,'path'=>'花园/花园产品']],
        'historical card-project refund resolves its real component through the common product reader');
    $wrongHolder=$componentFixture;$wrongHolder['card_holder_id']=1746651;
    $assert($componentRead->invoke($s,[$wrongHolder],[126])===[],
        'card project cannot borrow a different holder component');
    $assert($componentRead->invoke($s,[$componentFixture],[15])===[],
        'historical component lookup cannot cross authorized stores');
    $after=$query();$factsAfter=$factsRead->invoke($s,[126],$range);
    $assert($strip($after['records'])===$strip($before['records']) && $after['summary_row']===$before['summary_row'],
        'all historical row identities, amounts, counts and totals remain unchanged');
    $assert($money($factsAfter)===$money($factsBefore),'cent-precision amounts and employee customer metrics stay unchanged');
    $sales=array_values(array_filter($after['records'],static fn(array $r):bool=>
        in_array($r['document_no'],['XS26100800169','XS26100800196'],true) && (int)$r['employee_id']===663));
    $assert(count($sales)===2 && array_unique(array_column($sales,'performance_category'))===['生美/卡项'],
        'past positive sales follow the current product even when their old category exists');
    $refunds=array_values(array_filter($after['records'],static fn(array $r):bool=>$r['document_no']==='TK2610090001'));
    $assert(count($refunds)===2 && array_unique(array_column($refunds,'performance_category'))===['生美/卡项'],
        'negative catalogue refunds use the same current-product classification');
    $changedServices=0;
    foreach($factsAfter as $fact){
        if(trim((string)($fact['service_fact_id']??''))!=='' && $fact['item_name']==='热敷'){
            $assert($fact['category_path']==='花园/花园产品','known historical service category follows its current project');
            $changedServices++;
        }
    }
    $assert($changedServices>0,'current service reader covers historical classified services');
    $card=$cardRead->invoke($s,[$cardSale]);
    $assert($card[$cardSale['sale_fact_id']][0]['category_id']===26321,
        'card component category is looked up by actual component product ID');
    $parts=$allocate->invoke($s,['source_type'=>'card','amount_cents'=>101],$card[$cardSale['sale_fact_id']]);
    $assert($parts===[26321=>['cents'=>101,'path'=>'花园/花园产品']],
        'card current category retains original monetary weight and full cent amount');
    $screen=$s->query('phase_six_salary_detail',[126],$range,[]);
    $assert($screen['summary_row']===$after['summary_row']
        && $screen['records']===array_slice($after['records'],0,count($screen['records'])),
        'screen and complete export use the same current classification');
    $expected=[];
    foreach($factsAfter as $fact)foreach((array)($fact['sales_category_cents']??[]) as $id=>$cents){
        if(in_array((int)$id,[26321,26332],true)){
            $key=(int)$fact['employee_id'].'|'.(int)$id;
            $expected[$key]=($expected[$key]??0)+(int)$cents;
        }
    }
    $summary=$query('phase_six_salary_summary');$summaryByEmployee=array_column($summary['records'],null,'employee_id');
    foreach($expected as $key=>$cents){
        [$employee,$category]=array_map('intval',explode('|',$key));$column='salary_category_cash_'.$category;
        $drill=$query('phase_six_salary_detail',['salary_employee_id'=>$employee,'salary_store_id'=>126,'salary_category_key'=>$column]);
        $assert(array_sum(array_column($drill['records'],'cash_amount_cents'))===$cents
            && $summaryByEmployee[$employee][$column]===$drill['summary_row']['cash_amount'],
            'reclassified employee/category summary exactly reconciles with cent-precision drilldown');
    }
    // Neither a present historical snapshot nor a same-name item is a substitute for real current configuration.
    Db::name('store_product')->whereIn('id',[364970,91558,91111,91038])->update(['cate_id'=>'']);
    $empty=$query();
    foreach($empty['records'] as $row){
        if($row['detail']==='热敷')$assert($row['performance_category']==='',
            'no current project category stays blank despite a historical category snapshot');
    }
    $card=$cardRead->invoke($s,[$cardSale]);
    $assert($card[$cardSale['sale_fact_id']][0]['category_id']===0,
        'unconfigured card component does not inherit its old or outer-card category');
    $input=[['store_id'=>126,'product_id'=>466474,'source_type'=>'product','category_id'=>26321,'category_path'=>'旧快照']];
    $resolved=$saleRead->invoke($s,$input);
    $assert($resolved[0]['category_id']===26332 && $resolved[0]['category_path']==='生美/卡项',
        'original-sale product lookup replaces any old category consistently');
    $input[0]['product_id']=0;
    $resolved=$saleRead->invoke($s,$input);
    $assert($resolved[0]['category_id']===0 && $resolved[0]['category_path']==='',
        'missing actual product ID never revives an old classification');
    $assert($sources()===$sourceBefore,'all immutable source facts remain byte-for-byte unchanged');
}finally{Db::rollback();}
$assert(Db::name('store_product')->whereIn('id',$ids)->order('id')->select()->toArray()===$products,
    'catalogue edits fully roll back');
$assert($query()['records']===$before['records'] && $sources()===$sourceBefore,
    'refresh returns normal configured categories with unchanged original facts');
echo "PASS: $checks unified current salary-category checks\n";
