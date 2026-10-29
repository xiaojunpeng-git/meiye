#!/usr/bin/env python3
"""Collect bounded, read-only reconciliation evidence; excludes customer/contact/coupon data."""
import argparse
import json
import pathlib
import re
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--site', choices=['007', '010'], required=True)
parser.add_argument('--month', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
if not re.fullmatch(r'\d{4}-(0[1-9]|1[0-2])', args.month):
    raise SystemExit('invalid month')
php = r'''<?php
$site=__SITE__; $month=__MONTH__;
$root='/www/wwwroot/'.$site.'.cc3798.com';chdir($root);require 'vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==($site==='007'?'linlin':'mohe_010_app'))throw new RuntimeException('wrong database');
Db::execute('SET SESSION TRANSACTION READ ONLY');Db::startTrans();
try{
 $tz=new DateTimeZone('Asia/Shanghai');$start=new DateTimeImmutable($month.'-01 00:00:00',$tz);$end=$start->modify('last day of this month')->setTime(23,59,59);
 $time=[$start->getTimestamp(),$end->getTimestamp()];
 $stores=app()->make(app\dao\store\SystemStoreDao::class)->getStore(['salary_status'=>1]);$ids=array_map('intval',array_column($stores,'id'));
 $types=Db::name('cash_type')->order('id','asc')->select()->toArray();
 $orders=Db::name('store_order')->whereIn('store_id',$ids)->whereBetween('add_time',$time)
  ->field('id,store_id,add_time,paid,pay_type,cash_choose,cash_pay_price,pay_price,yue_pay_price,pid,is_system_del,refund_status,order_type')->limit(50001)->select()->toArray();
 if(count($orders)>50000)throw new RuntimeException('source too large');
 $combinations=[];foreach(array_chunk(array_column($orders,'id'),500)as$chunk){
  $part=Db::name('combination_order')->whereIn('order_id',$chunk)->field('id,order_id,price,cash_choose,active_pay,pay_sub_type')->select()->toArray();
  $combinations=array_merge($combinations,$part);
 }
 if(count($combinations)>50000)throw new RuntimeException('source too large');
 $archiveProof=[];
 if($site==='010')foreach(array_chunk(array_column($orders,'id'),500)as$chunk){
  $proofs=Db::name('cashier_v3_sales_order')->alias('h')->join('store_order o','h.source_document_id=CAST(o.id AS CHAR) AND h.order_no=o.order_id AND h.store_id=o.store_id AND h.member_id=o.uid')
   ->where('h.tenant_id','0')->where('h.contract_version','legacy-import-data-v1')->where('h.source_document_type','legacy_store_order')
   ->where('h.order_status','settled')->where('h.order_direction','forward')->whereIn('o.id',$chunk)->where('o.is_system_del',1)
   ->field('h.order_id,h.source_document_id,h.store_id')->select()->toArray();
  foreach($proofs as$p)$archiveProof[]=$p;
 }
 $registrations=[];foreach(Db::name('salary_store')->whereIn('store_id',$ids)->whereBetween('date',$time)->field('id,store_id,date,salary_info')->select()->toArray()as$r){
  $info=json_decode($r['salary_info'],true);if(!in_array($info['shuadanpingtai']??'', ['抖音','大众'],true))continue;
  $r['salary_info']=json_encode(array_intersect_key($info,array_flip(['shuadanpingtai','shuadanjine'])),JSON_UNESCAPED_UNICODE);$registrations[]=$r;
 }
 $oracle=[];$service=app()->make(app\services\order\store\BranchOrderServices::class);
 foreach($types as$t){if((int)$t['id']===9)continue;$line=['code'=>'legacy_'.$t['id'],'name'=>$t['name'],'cells'=>[]];foreach($stores as$s)$line['cells'][(int)$s['id']]=$service->reportOrder(['store_id'=>(int)$s['id'],'time'=>$time,'cash_choose'=>(int)$t['id']]);$oracle[]=$line;}
 foreach(['抖音'=>'brush_douyin','大众'=>'brush_dianping']as$name=>$code){$line=['code'=>$code,'name'=>$name.'刷单','cells'=>[]];foreach($stores as$s){$sum='0.00';foreach($registrations as$r){$i=json_decode($r['salary_info'],true);if((int)$r['store_id']===(int)$s['id']&&$i['shuadanpingtai']===$name)$sum=bcadd($sum,(string)($i['shuadanjine']??0),2);}$line['cells'][(int)$s['id']]=$sum;}$oracle[]=$line;}
 $sample=(int)$stores[0]['id'];$daily=[];
 for($day=1;$day<=(int)$end->format('j');$day++){
  $a=$start->setDate((int)$start->format('Y'),(int)$start->format('m'),$day);$b=$a->setTime(23,59,59);$line=[];
  foreach($types as$t){if((int)$t['id']===9)continue;$line['legacy_'.$t['id']]=$service->reportOrder(['store_id'=>$sample,'time'=>[$a->getTimestamp(),$b->getTimestamp()],'cash_choose'=>(int)$t['id']]);}
  $daily[$day]=$line;
 }
 // Only non-sensitive dimensions are exported; original order numbers and member IDs are omitted.
 $hashes=[];foreach(['app/services/order/ValidCashOrderServices.php','app/controller/admin/v1/report/ReportData.php','app/services/report/ReceiveReportServices.php']as$f)if(is_file($root.'/'.$f))$hashes[$f]=hash_file('sha256',$root.'/'.$f);
 echo json_encode(['site'=>$site,'month'=>$month,'read_only'=>true,'captured_at'=>date(DATE_ATOM),'source_hashes'=>$hashes,
  'stores'=>$stores,'types'=>$types,'orders'=>$orders,'combinations'=>$combinations,'registrations'=>$registrations,'archive_proof'=>$archiveProof,
  'oracle'=>$oracle,'daily_store_id'=>$sample,'daily'=>$daily],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}finally{Db::rollback();}
'''
php = php.replace('__SITE__', json.dumps(args.site)).replace('__MONTH__', json.dumps(args.month))
result = subprocess.run(['ssh', '-q', '-o', 'BatchMode=yes', 'mohe-server', '/www/server/php/74/bin/php'],
                        input=php, text=True, capture_output=True, timeout=180, check=True)
value = json.loads(result.stdout)
if value['site'] != args.site or value['read_only'] is not True:
    raise SystemExit('source identity mismatch')
output = pathlib.Path(args.output)
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps(value, ensure_ascii=False), encoding='utf-8')
print(json.dumps({'site': args.site, 'orders': len(value['orders']), 'combinations': len(value['combinations']),
                  'registrations': len(value['registrations']), 'path': str(output)}, ensure_ascii=False))
