<?php
declare(strict_types=1);

require __DIR__.'/../../../后端代码/vendor/autoload.php';

use app\services\report\Sidebar007PartnerServiceAllocationReadServices;

function checkInside(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$source=static fn():array=>['complete'=>true,'gaps'=>[],
    'by_store'=>[
        7=>['consumption_amount_cents'=>20000,'service_customer_count'=>2],
        8=>['consumption_amount_cents'=>0,'service_customer_count'=>0]],
    'qualified_entries'=>[
        ['store_id'=>7,'service_fact_id'=>'S1','order_id'=>'service:101'],
        ['store_id'=>7,'service_fact_id'=>'S1','order_id'=>'service:102']]];
$facts=static fn():array=>[
    ['service_fact_id'=>'S1','service_store_id'=>8,'labor_amount_cents'=>10000,
        'fact_id'=>'P1','employee_id'=>1,'employee_type_snapshot'=>'partner',
        'employee_type_authority_version'=>1,'fact_direction'=>'forward','amount_cents'=>3000],
    ['service_fact_id'=>'S1','service_store_id'=>8,'labor_amount_cents'=>10000,
        'fact_id'=>'O1','employee_id'=>2,'employee_type_snapshot'=>'outsourced',
        'employee_type_authority_version'=>1,'fact_direction'=>'forward','amount_cents'=>2000],
    ['service_fact_id'=>'S1','service_store_id'=>8,'labor_amount_cents'=>10000,
        'fact_id'=>'I1','employee_id'=>3,'employee_type_snapshot'=>'internal',
        'employee_type_authority_version'=>1,'fact_direction'=>'forward','amount_cents'=>5000]];
$reader=new Sidebar007PartnerServiceAllocationReadServices($source,$facts);
$result=$reader->read([7,8],['start'=>'2026-10-01','end'=>'2026-10-30']);
checkInside($result['complete']&&$result['partner_by_store'][7]===6000
    &&$result['by_store'][7]===14000&&$result['by_store'][8]===0,
    '跨店旧子单按归属店重复两笔，扣同服务partner实配，不扣outsourced/internal');
$noLaborSource=static fn():array=>['complete'=>true,'gaps'=>[],
    'by_store'=>[7=>['consumption_amount_cents'=>9900,'service_customer_count'=>1]],
    'qualified_entries'=>[['store_id'=>7,'service_fact_id'=>'S2']]];
$zeroLabor=static fn():array=>[['service_fact_id'=>'S2','service_store_id'=>7,
    'labor_amount_cents'=>0,'fact_id'=>'']];
$zero=(new Sidebar007PartnerServiceAllocationReadServices($noLaborSource,$zeroLabor))
    ->read([7],['start'=>'2026-10-01','end'=>'2026-10-30']);
checkInside($zero['complete']&&$zero['by_store'][7]===9900,
    '有消费无劳动的服务仍能认证partner实配为0，不要求劳动额等于消费额');
$missing=(new Sidebar007PartnerServiceAllocationReadServices($source,static fn():array=>[]))
    ->read([7,8],['start'=>'2026-10-01','end'=>'2026-10-30']);
checkInside(!$missing['complete']&&$missing['by_store'][7]===null,
    '同次服务身份缺失不能把partner分配默认为0');
$unknown=(new Sidebar007PartnerServiceAllocationReadServices($source,
    static fn():array=>[['service_fact_id'=>'S1','service_store_id'=>8,'labor_amount_cents'=>10000,
        'fact_id'=>'X','employee_id'=>1,'employee_type_snapshot'=>'',
        'employee_type_authority_version'=>0,'fact_direction'=>'forward','amount_cents'=>3000]]))
    ->read([7,8],['start'=>'2026-10-01','end'=>'2026-10-30']);
checkInside(!$unknown['complete']&&$unknown['gaps'][0]['code']==='PARTNER_SERVICE_EMPLOYEE_SNAPSHOT_UNVERIFIED',
    '人员类型快照不明时整列留缺口');

$certified=(new Sidebar007PartnerServiceAllocationReadServices($source,
    static fn():array=>[['service_fact_id'=>'S1','service_store_id'=>8,'labor_amount_cents'=>10000,
        'fact_id'=>'X','employee_id'=>1,'employee_type_snapshot'=>'partner',
        'employee_type_authority_version'=>0,'employee_type_certified'=>1,
        'fact_direction'=>'forward','amount_cents'=>3000]]))
    ->read([7,8],['start'=>'2026-10-01','end'=>'2026-10-30']);
checkInside($certified['complete']&&$certified['partner_by_store'][7]===6000,
    '只有同事实完整指纹认证通过的历史类型才可使用，仍按原子单笔数扣减');

// A positive consumption/labor base may legitimately allocate zero to every craftsman.
$person=['employee_id'=>1,'staff_id'=>2,'store_id'=>7,'employee_type_snapshot'=>'partner',
    'employee_type_authority_version'=>1,'amount_cents'=>0,'labor_fee_cents'=>0,'project_count_decimal'=>'0'];
$native=['service_fact_id'=>'S2','service_store_id'=>7,'labor_amount_cents'=>9900,
    'fact_id'=>'','source_document_type'=>'direct','craftsmen_snapshot_json'=>json_encode([$person])];
$runZero=static function(array $row)use($noLaborSource):array{
    return (new Sidebar007PartnerServiceAllocationReadServices($noLaborSource,static fn():array=>[$row]))
        ->read([7],['start'=>'2026-10-01','end'=>'2026-10-30']);
};
checkInside($runZero($native)['complete'],'完整唯一快照证明原生零分配，正基数不强制有零值事实');
foreach(['amount_cents'=>100,'labor_fee_cents'=>100,'project_count_decimal'=>'1',
    'employee_type_authority_version'=>0,'store_id'=>8] as $field=>$value){
    $bad=$person;$bad[$field]=$value;$row=$native;$row['craftsmen_snapshot_json']=json_encode([$bad]);
    checkInside(!$runZero($row)['complete'],'缺少或非零分配不能借零快照放行:'.$field);
}
foreach(['[]','invalid'] as $json){$row=$native;$row['craftsmen_snapshot_json']=$json;
    checkInside(!$runZero($row)['complete'],'空或损坏快照不是零分配证明');}
$row=$native;$row['source_document_type']='legacy_writeoff';
checkInside(!$runZero($row)['complete'],'历史来源不能冒用原生writer零分配规则');

$historySource=['complete'=>true,'gaps'=>[],
    'by_store'=>[7=>['consumption_amount_cents'=>20000]],
    'qualified_entries'=>[['store_id'=>7,'service_fact_id'=>'historical:writeoff:9'],
        ['store_id'=>7,'service_fact_id'=>'historical:writeoff:9']],
    'historical_services'=>[['source_kind'=>'historical_007_card_service','source_writeoff_id'=>9,
        'service_fact_id'=>'historical:writeoff:9','service_store_id'=>7]]];
$historyPerson=['source_kind'=>'historical_007_card_labor','source_writeoff_id'=>9,'source_labor_id'=>11,
    'service_store_id'=>7,'employee_type_snapshot'=>'partner','amount_cents'=>3000];
$historyReader=static fn():array=>['complete'=>true,'gaps'=>[],'rows'=>[$historyPerson]];
$h=(new Sidebar007PartnerServiceAllocationReadServices(null,static function($stores,$ids):array{
    checkInside($ids===[],'原核销人员不能通过伪造V3身份查询');return [];
},$historyReader))->read([7],[], $historySource);
checkInside($h['complete']&&$h['partner_by_store'][7]===6000&&$h['by_store'][7]===14000,
    '认证原核销人员按007有效子单笔数分配，同来源不重查另一套SQL');
$h=(new Sidebar007PartnerServiceAllocationReadServices(null,static fn():array=>[],
    static fn():array=>['complete'=>true,'gaps'=>[],'rows'=>[]]))->read([7],[],$historySource);
checkInside($h['complete']&&$h['partner_by_store'][7]===0,'007原关联无人员时SUM为0，不伪造员工');
$h=(new Sidebar007PartnerServiceAllocationReadServices(null,static fn():array=>[],
    static fn():array=>['complete'=>false,'gaps'=>[['code'=>'IDENTITY']],'rows'=>[]]))->read([7],[],$historySource);
checkInside(!$h['complete'],'有原人员但身份无法认证时保留精确缺口');

echo "sidebar-007-partner-service-allocation-contract PASS\n";
