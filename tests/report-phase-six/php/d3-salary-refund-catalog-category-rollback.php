<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

// Local catalogue fixture only: roll back every temporary edit and never write refund/financial facts.
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
$service=new StoreUnifiedReportPhaseSixServices();
$read=new ReflectionMethod($service,'salaryRefundCatalogCategories');$read->setAccessible(true);
$range=['start'=>'2026-10-09','end'=>'2026-10-09'];
$query=static function(string $report='phase_six_salary_detail',array $input=[])use($service,$range):array{
    return $service->query($report,[126],$range,$input+['_internal_all'=>true]);
};
$operation='OLO-6D67780CC97F3F5FDEC0AFA025986B7653A0D580';
$source=static function()use($operation):array{
    return [Db::name('cashier_v3_performance_fact')->where('order_id',$operation)->order('id')->select()->toArray(),
        Db::name('cashier_v3_refund_adjustment')->where('operation_id',$operation)->order('id')->select()->toArray()];
};
$sourceBefore=$source();
$fixture=['target_type'=>'catalog','catalog_kind'=>'产品','catalog_item_id'=>450072,'store_id'=>126];
$resolve=static function(array $row,array $stores=[126])use($read,$service):array{
    return $read->invoke($service,[$row],$stores);
};
$sku=Db::name('store_product_attr_value')->where('id',450072)->find();
$product=Db::name('store_product')->where('id',466208)->find();
$parent=Db::name('store_product')->where('id',64544)->find();
$assert($sku!==null && (int)$sku['product_id']===466208 && (int)$product['pid']===64544,
    'actual refund SKU links to store product and explicit platform parent');
$collision=Db::name('store_product')->where('id',450072)->find();
$assert($collision!==null && (int)$collision['relation_id']!==126,'same-number product is a different store fixture');
$expected=['126|450072'=>['id'=>26321,'path'=>'花园/花园产品']];
$assert($resolve($fixture)===$expected,'category comes from the SKU-linked product, not the colliding product ID');
$detail=$query();
$rows=array_values(array_filter($detail['records'],static fn(array $r):bool=>$r['document_no']==='TK2610090001'));
$assert(count($rows)===2 && array_column($rows,'cash_amount_cents')===[-16600,-15000],
    'both employees retain their original cent-precision negative allocations');
$assert(array_unique(array_column($rows,'performance_category'))===['花园/花园产品'],
    'both refund detail rows show the configured category');
$assert($detail['summary_row']['cash_amount']==='-316','refund total remains minus 316 yuan');
$assert($service->query('phase_six_salary_detail',[126],$range,[])['records']===$detail['records'],
    'screen and complete export projection share the same classification');
$summary=$query('phase_six_salary_summary');
$assert($summary['summary_row']['salary_category_cash_26321']==='-316',
    'summary assigns the negative amount to the same actual category');
foreach($rows as $row){
    $input=['salary_employee_id'=>$row['employee_id'],'salary_store_id'=>126,'salary_category_key'=>'salary_category_cash_26321'];
    $drill=$query('phase_six_salary_detail',$input);
    $assert(count($drill['records'])===1 && $drill['records'][0]['cash_amount_cents']===$row['cash_amount_cents'],
        'exact employee/category drilldown reconciles with the refund allocation');
    $input['salary_category_key']='salary_category_cash_unclassified';
    $assert($query('phase_six_salary_detail',$input)['records']===[],
        'classified refund is not duplicated in unclassified drilldown');
}
$foreign=$fixture;$foreign['store_id']=15;
$assert($resolve($foreign,[15])===[] && $resolve($fixture,[15])===[],
    'SKU and caller scope cannot borrow another store product');
$card=$fixture;$card['catalog_kind']='卡项';
$assert($resolve($card)===[],'card outer classification cannot replace contained-project allocation');
$linked=$fixture;$linked['target_type']='document';
$assert($resolve($linked)===[],'original-document refunds do not use the catalogue fallback');

Db::startTrans();
try{
    Db::name('store_product')->where('id',466208)->lock(true)->find();
    Db::name('store_product')->where('id',64544)->lock(true)->find();
    Db::name('store_product')->where('id',466208)->update(['cate_id'=>'']);
    $assert($resolve($fixture)===$expected,'missing store category uses only its explicit platform parent');
    Db::name('store_product')->where('id',64544)->update(['cate_id'=>'']);
    $assert($resolve($fixture)===[],'no configured category stays missing without name-based guessing');
    $missing=$query();
    $strip=static function(array $records):array{
        foreach($records as &$row)unset($row['performance_category']);unset($row);return $records;
    };
    $assert($strip($missing['records'])===$strip($detail['records']) && $missing['summary_row']===$detail['summary_row'],
        'category changes leave row identities, all amounts, project counts and totals intact');
    $unclassified=$query('phase_six_salary_summary');
    $assert($unclassified['summary_row']['salary_category_cash_unclassified']==='-316',
        'truly unconfigured refunds remain in the unclassified summary bucket');
    Db::name('store_product')->where('id',466208)->update(['cate_id'=>'26321','is_del'=>1]);
    $assert($resolve($fixture)===[],'deleted actual products do not supply a current category');
    $assert($source()===$sourceBefore,'classification does not rewrite immutable refund or performance facts');
}finally{Db::rollback();}
$assert(Db::name('store_product')->where('id',466208)->find()===$product
    && Db::name('store_product')->where('id',64544)->find()===$parent,'all temporary catalogue edits roll back');
$assert($query()['records']===$detail['records'] && $source()===$sourceBefore,
    'refresh after rollback retains the corrected category and unchanged financial facts');
echo "PASS: $checks refund catalogue-category checks\n";
