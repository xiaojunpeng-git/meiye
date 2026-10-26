<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

// Local regression only: temporary catalogue edits are always rolled back.
// Service/performance/sales facts are never updated; verify both classification and monetary invariants.
require '/var/www/html/vendor/autoload.php';
$app=new \think\App('/var/www/html/');
$app->initialize();
set_exception_handler(static function(Throwable $error):void{
    file_put_contents('php://stderr',$error->getMessage()."\n");exit(1);
});
$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok)throw new RuntimeException($message);
    $checks++;echo "PASS: $message\n";
};
$service=new StoreUnifiedReportPhaseSixServices();
$read=new ReflectionMethod($service,'salaryServiceProductCategories');$read->setAccessible(true);
$range=['start'=>'2026-10-01','end'=>'2026-10-08'];
$query=static function(array $input=[])use($service,$range):array{
    return $service->query('phase_six_salary_detail',[126],$range,$input+['_internal_all'=>true]);
};
$before=$query();
$product=Db::name('store_product')->where('id',364982)->find();
$parent=Db::name('store_product')->where('id',(int)$product['pid'])->find();
$sv=Db::name('cashier_v3_entitlement_service_fact')->where('store_id',126)->where('project_id',364982)
    ->whereBetween('business_date',[$range['start'],$range['end']])->where('service_status','completed')->find();
$assert($product!==null && $parent!==null && $sv!==null,'local missing-category fixture exists');
$assert(Db::name('store_product_category')->where('id',26332)->where('pid',26302)->value('cate_name')==='卡项',
    'configured target fixture is a real platform category');
$sourceFacts=static function()use($range):array{
    return Db::name('cashier_v3_entitlement_service_fact')->where('store_id',126)
        ->whereBetween('business_date',[$range['start'],$range['end']])->order('id')->select()->toArray();
};
$originalFacts=$sourceFacts();
$fact=['store_id'=>126,'service_fact_id'=>$sv['service_fact_id'],'category_path'=>'','cash_cents'=>0,
    'consumption_cents'=>12900,'labor_fee_cents'=>0,'project_count'=>'1'];
$project=static function(array $f)use($read,$service):array{return $read->invoke($service,[$f],[126])[0];};
$assert($project($fact)===$fact,'dangling category reference is not guessed from the project name');

Db::startTrans();
try{
    Db::name('store_product')->where('id',$product['id'])->lock(true)->find();
    Db::name('store_product')->where('id',$parent['id'])->lock(true)->find();
    Db::name('store_product')->where('id',$product['id'])->update(['cate_id'=>'26302,26332']);
    $expected=$fact;$expected['category_path']='生美/卡项';
    $assert($project($fact)===$expected,'actual linked project category supplies the deepest two-level path');
    $after=$query();
    $strip=static function(array $rows):array{
        foreach($rows as &$row)unset($row['performance_category']);unset($row);return $rows;
    };
    $assert($strip($after['records'])===$strip($before['records']) && $after['summary_row']===$before['summary_row'],
        'all row keys, employees, amounts, counts and totals are unchanged');
    $changed=0;
    foreach($after['records'] as $i=>$row){
        if($row['performance_category']!==$before['records'][$i]['performance_category']){
            $assert($row['detail']===$product['store_name'] && $row['performance_category']==='生美/卡项',
                'only missing-category rows of the actual linked project change');$changed++;
        }
    }
    $assert($changed===3,'all three service rows of this project use the same category');
    $screen=$service->query('phase_six_salary_detail',[126],$range,[]);
    $assert($screen['summary_row']===$after['summary_row']
        && $screen['records']===array_slice($after['records'],0,count($screen['records'])),
        'screen and complete export projection use the same category reader');
    $known=$fact;$known['category_path']='历史 / 已记录分类';
    $assert($project($known)===$expected,'historical display classification follows the explicitly linked current product');
    $foreign=$fact;$foreign['store_id']=15;
    $assert($project($foreign)===$foreign,'same service ID cannot cross the authorized store boundary');
    $refund=$fact;$refund['service_fact_id']='';
    $assert($project($refund)===$refund,'refunds without a service project do not inherit arbitrary categories');

    Db::name('store_product')->where('id',$product['id'])->update(['cate_id'=>'']);
    Db::name('store_product')->where('id',$parent['id'])->update(['cate_id'=>'26332']);
    $assert($project($fact)===$expected,'only the explicitly linked platform parent supplies a missing category');
    Db::name('store_product')->where('id',$parent['id'])->update(['cate_id'=>'']);
    $assert($project($fact)===$fact,'project and parent without categories remain unclassified');
    $blank=$fact;$blank['category_path']='';
    $assert($project($blank)===$fact,'missing current category consistently stays empty');
    Db::name('store_product')->where('id',$product['id'])->update(['cate_id'=>'26332','relation_id'=>15]);
    $assert($project($fact)===$fact,'a project belonging to another store cannot supply the category');
    Db::name('store_product')->where('id',$product['id'])->update(['relation_id'=>126,'is_del'=>1]);
    $assert($project($fact)===$fact,'deleted current products are not used for missing historical dimensions');
    $assert($sourceFacts()===$originalFacts,'classification never writes original service snapshots');
}finally{
    Db::rollback();
}
$assert(Db::name('store_product')->where('id',$product['id'])->find()===$product
    && Db::name('store_product')->where('id',$parent['id'])->find()===$parent,'temporary catalogue edits fully roll back');
$assert($query()['records']===$before['records'] && $sourceFacts()===$originalFacts,
    'refresh after rollback returns original data without persistent edits');
echo "PASS: $checks salary service-category checks\n";
