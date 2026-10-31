<?php
declare(strict_types=1);
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__,3).'/后端代码').'/vendor/autoload.php';
use app\services\report\ServiceOrderArchiveProvenanceServices as Proof;
/** Full rows, sealed batch metadata and scope are independent mandatory proof boundaries. */
$row=['id'=>21,'order_type'=>2,'is_system_del'=>1,'store_id'=>4,'uid'=>12,'pay_price'=>'58.00',
    'link_id'=>93,'link_order'=>20,'note'=>"原/单\u{2028}",'nullable'=>null];
$proof=['tenant_id'=>'0','fact_id'=>'legacy-order:21','purpose'=>Proof::PURPOSE,
    'snapshot_sha256'=>Proof::fingerprint($row),'source_batch_key'=>'sealed-import-batch',
    'source_archive_sha256'=>str_repeat('a',64),'plan_sha256'=>str_repeat('b',64)];
$reader=new Proof();$read=static fn(array $rows,array $proofs):array=>$reader->certified($rows,static fn():array=>$proofs);
if($read([$row],[$proof])!==[21=>true])throw new RuntimeException('valid archive proof');
$strings=$row;foreach($strings as &$v)if($v!==null)$v=(string)$v;unset($v);
if(Proof::fingerprint($strings)!==Proof::fingerprint($row))throw new RuntimeException('PDO stringify parity');
foreach(['store_id'=>5,'link_order'=>22,'pay_price'=>'58.01','note'=>'changed','nullable'=>''] as $key=>$value){
    $changed=$row;$changed[$key]=$value;if($read([$changed],[$proof])!==[])throw new RuntimeException('row drift '.$key);
}
foreach(['tenant_id'=>'1','purpose'=>'employee_type_snapshot_v1','source_batch_key'=>'',
    'source_archive_sha256'=>'bad','plan_sha256'=>''] as $key=>$value){
    $changed=$proof;$changed[$key]=$value;if($read([$row],[$changed])!==[])throw new RuntimeException('provenance drift '.$key);
}
if($read([$row],[])!==[])throw new RuntimeException('absent proof must fail closed');
$changed=$proof;$changed['fact_id']='legacy-order:22';
try{$read([$row],[$changed]);throw new RuntimeException('scope escape');}catch(LogicException $expected){}
$changed=$row;$changed['order_type']=0;
if($read([$changed],[$proof])!==[])throw new RuntimeException('purchase proof cannot stand in for child');
echo "service-order-archive-provenance PASS\n";
