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

echo "sidebar-007-partner-service-allocation-contract PASS\n";
