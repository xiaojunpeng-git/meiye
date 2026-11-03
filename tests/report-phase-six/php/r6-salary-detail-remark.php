<?php

declare(strict_types=1);

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\report\StoreOperationsReportAnnotationServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

// Only local MySQL: synthetic source snapshots are rolled back; no checkout or historical fact is rewritten.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function(Throwable $error):void {
    file_put_contents('php://stderr',$error->getMessage()."\n");exit(1);
});
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao'){
    throw new RuntimeException('Local ruihao database required');
}
$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok)throw new RuntimeException($message);
    $checks++;echo "PASS $message\n";
};
$tenant=CashierV3ScopeResolver::TENANT_SCOPE_ID;
$service=new StoreUnifiedReportPhaseSixServices();
$project=new ReflectionMethod($service,'salaryDetailRemarks');$project->setAccessible(true);
$serviceSource=Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id',$tenant)->where('service_status','completed')->find();
$saleSource=Db::name('cashier_v3_sales_order_line')->where('tenant_id',$tenant)->where('line_direction','forward')->find();
$assert(is_array($serviceSource)&&is_array($saleSource),'service and sale source fixtures exist');
$tag='R6-'.bin2hex(random_bytes(8));
Db::startTrans();
try{
    $sv=$serviceSource;unset($sv['id']);
    foreach(['service_fact_id','service_record_no','natural_key','checkout_request_id','source_line_id'] as $key)$sv[$key]=$tag.'-'.$key;
    $sv['detail_remark_snapshot']="本项目：轻柔护理\n第二行🙂";
    Db::name('cashier_v3_entitlement_service_fact')->insert($sv);
    $sale=$saleSource;unset($sale['id']);
    foreach(['order_line_id','natural_key','order_id'] as $key)$sale[$key]=$tag.'-'.$key;
    // Same checkout/line IDs deliberately belong to a different store: never substitute its sale remark for a service.
    $sale['checkout_request_id']=$sv['checkout_request_id'];$sale['checkout_line_id']=$sv['source_line_id'];
    $sale['store_id']=(int)$sv['store_id']+100000;
    $sale['detail_remark_snapshot']='销售商品独立备注';
    Db::name('cashier_v3_sales_order_line')->insert($sale);
    $serviceFact=['store_id'=>(int)$sv['store_id'],'service_fact_id'=>$sv['service_fact_id'],
        'checkout_request_id'=>$sv['checkout_request_id'],'source_line_id'=>$sv['source_line_id'],'row_key'=>'employee-a'];
    $second=$serviceFact;$second['row_key']='employee-b';
    $saleFact=['store_id'=>(int)$sale['store_id'],'service_fact_id'=>'','checkout_request_id'=>$sale['checkout_request_id'],
        'source_line_id'=>$sale['checkout_line_id'],'row_key'=>'salesperson'];
    $refund=$saleFact;$refund['checkout_request_id']='refund-request';$refund['source_line_id']='refund-line';
    $refund['source_sales_order_id']=$sale['order_id'];$refund['source_sales_line_id']=$sale['order_line_id'];
    $wrongOrder=$refund;$wrongOrder['source_sales_order_id']='unrelated-order';
    $wrongRequest=$saleFact;$wrongRequest['checkout_request_id']='unrelated-request';
    $scope=[(int)$sv['store_id'],(int)$sale['store_id']];
    $out=$project->invoke($service,[$serviceFact,$second,$saleFact,$refund,$wrongOrder,$wrongRequest],$scope);
    $assert($out[0]['detail_remark']===$sv['detail_remark_snapshot']&&$out[1]['detail_remark']===$sv['detail_remark_snapshot'],
        'same service multiple employees share exact Unicode/multiline snapshot');
    $assert($out[2]['detail_remark']===$sale['detail_remark_snapshot'],'pure sale uses exact checkout-line snapshot');
    $assert($out[3]['detail_remark']===$sale['detail_remark_snapshot'],'refund uses verified original sales order and line');
    $assert($out[4]['detail_remark']===''&&$out[5]['detail_remark']==='','wrong order/request cannot borrow a same-number line');
    $restricted=$project->invoke($service,[$saleFact],[(int)$sv['store_id']]);
    $assert($restricted[0]['detail_remark']==='','unauthorized store cannot supply a remark');
    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id',$sv['service_fact_id'])->update(['detail_remark_snapshot'=>'']);
    $blank=$project->invoke($service,[$serviceFact],$scope);
    $assert($blank[0]['detail_remark']==='','empty service snapshot stays empty without sale/manual fallback');
    $missing=$serviceFact;$missing['service_fact_id']='missing-service';
    $assert($project->invoke($service,[$missing],$scope)[0]['detail_remark']==='','missing source is blank without guessing by name');
}finally{Db::rollback();}
$assert(!Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id',$tag.'-service_fact_id')->find(),
    'synthetic snapshots rolled back');

// Independently reconcile actual report rows with source snapshots; page and complete export must be identical.
$range=['start'=>'2026-10-01','end'=>'2026-11-30'];
$detail=$service->query('phase_six_salary_detail',[23],$range);
$export=$service->query('phase_six_salary_detail',[23],$range,['_internal_all'=>true]);
$assert(count($detail['records'])>0&&$detail['records']===$export['records']&&$detail['summary_row']===$export['summary_row'],
    'screen and full export share records and totals');
$remarkColumn=array_values(array_filter($detail['columns'],static fn(array $c):bool=>$c['key']==='备注'))[0];
$assert(empty($remarkColumn['manual_input'])&&strpos($remarkColumn['source_explanation'],'明细备注')!==false,
    'remark is read-only with business source explanation');
$nonempty=0;
foreach($detail['records'] as $row){
    $sv=Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id',$tenant)->where('store_id',23)
        ->where('source_line_id',$row['source_line_id'])->where('service_status','completed')->find();
    $line=Db::name('cashier_v3_sales_order_line')->where('tenant_id',$tenant)->where('store_id',23)
        ->where('order_id',$row['document_order_id'])->where('checkout_line_id',$row['source_line_id'])->where('line_direction','forward')->find();
    $expected=(string)(($sv??$line)['detail_remark_snapshot']??'');
    $assert($row['备注']===$expected,'actual row reads its source snapshot: '.$row['row_key']);
    if($expected!=='')$nonempty++;
}
$assert($nonempty>0,'actual historical nonempty remarks covered');
$annotation=new StoreOperationsReportAnnotationServices();
$field=new ReflectionMethod($annotation,'fieldKey');$field->setAccessible(true);
$rejected=false;
try{$field->invoke($annotation,'phase_six_salary_detail','备注');}catch(InvalidArgumentException $e){$rejected=$e->getMessage()==='该报表字段不允许编辑';}
$assert($rejected,'backend rejects obsolete salary annotation writes');
$assert($field->invoke($annotation,'phase_six_other_multi_payment','备注')==='备注','other report annotation remains editable');
echo "PASS R6 salary detail remark checks=$checks\n";
