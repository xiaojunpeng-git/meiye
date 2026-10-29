<?php
/**
 * 在全新本地数据库重放脱敏原单证据，对照未修改的007/010原服务逐格结果。
 * 不连接远端、不修改既有数据库；所有测试表和新增事实均在finally中销毁。
 */
$root=getenv('BACKEND_ROOT')?:getcwd();require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($root):void{if(strpos($class,'app\\')!==0)return;$path=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($path))require $path;},true,true);
$app=new think\App($root.'/');if($env=getenv('LOCAL_TEST_ENV'))$app->env->load($env);$app->initialize();
use think\facade\Db;
use app\services\report\StoreReconciliationReportServices as Report;
use app\services\report\StoreReconciliationReceiptReader as Reader;
$config=config('database.connections.mysql');if(!in_array($config['hostname'],['mohe-mysql','mysql'],true))throw new RuntimeException('local database only');
$original=Db::query('SELECT DATABASE() db')[0]['db'];$database='r11_reconciliation_'.bin2hex(random_bytes(5));
if(!preg_match('/^[a-zA-Z0-9_]+$/D',$original))throw new RuntimeException('database name invalid');
$tables=['store_order','combination_order','cash_type','system_store','system_menus','system_role','cashier_v3_payment_method_config','cashier_v3_payment_fact','cashier_v3_sales_order','cashier_v3_card_purchase_receipt','cashier_v3_order_lifecycle_operation','cashier_v3_order_center_void_operation','salary_store'];
$schema=[];$checks=0;$results=[];
function verify11(bool $ok,string $label):void{global$checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function insert11(string $table,array $values):void{
 global$schema;
 if(!isset($schema[$table]))$schema[$table]=Db::query('SHOW COLUMNS FROM `eb_'.$table.'`');
 $row=[];foreach($schema[$table]as$c){$key=$c['Field'];if(strpos($c['Extra'],'auto_increment')!==false||strpos($c['Extra'],'GENERATED')!==false)continue;
  if($c['Default']!==null||$c['Null']==='YES')continue;
  if(preg_match('/int|decimal|float|double/',$c['Type']))$row[$key]=0;
  elseif(strpos($c['Type'],'date')!==false)$row[$key]='2026-09-01';else$row[$key]='';
 }
 $fields=array_fill_keys(array_column($schema[$table],'Field'),true);
 if(isset($fields['unique'])&&!isset($values['unique']))$row['unique']=hash('md5',$table.':'.($values['id']??bin2hex(random_bytes(8))));
 foreach(['operation_no','receipt_no']as$key)if(isset($fields[$key])&&!isset($values[$key]))$row[$key]='TEST-'.($values['operation_id']??$values['receipt_id']??bin2hex(random_bytes(8)));
 Db::name($table)->insert(array_replace($row,array_intersect_key($values,$fields)));
}
Db::execute('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
try{
 foreach($tables as$t)Db::execute('CREATE TABLE `'.$database.'`.`eb_'.$t.'` LIKE `'.$original.'`.`eb_'.$t.'`');
 Db::execute('USE `'.$database.'`');
 foreach(array_slice($argv,1)as$file){
  $source=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
  foreach($tables as$t)Db::execute('TRUNCATE TABLE `eb_'.$t.'`');
  insert11('system_menus',['id'=>201,'pid'=>1986,'type'=>1,'menu_name'=>'门店统计','unique_auth'=>'admin-report-basic-group-store','is_del'=>0,'path'=>'1986']);
  insert11('system_role',['id'=>87,'rules'=>'201','role_name'=>'合成原岗位']);
  $priorRoles=Db::name('system_role')->select()->toArray();
  $sql=file_get_contents($root.'/database/upgrades/2026-10-28-门店对账单/01-平台菜单.sql');
  Db::connect()->getPdo()->exec($sql);Db::connect()->getPdo()->exec($sql);
  verify11(Db::name('system_menus')->where('unique_auth','admin-report-basic_store_reconciliation')->count()===1,'platform leaf migration is idempotent');
  verify11(Db::name('system_role')->select()->toArray()===$priorRoles,'menu migration never grants existing roles');
  foreach($source['stores']as$i=>$s)insert11('system_store',$s+['salary_status'=>1,'add_time'=>100000-$i]);
  foreach($source['types']as$type)insert11('cash_type',$type);
  $labels=['cash'=>'现金','unionpay'=>'银联','wechat'=>'微信','alipay'=>'支付宝','dianping_voucher'=>'大众验券','douyin_voucher'=>'抖音验券','partner_collection'=>'合作方收款','other_collection'=>'其他收款'];$i=0;
  foreach($labels as$code=>$label)insert11('cashier_v3_payment_method_config',['id'=>++$i,'code'=>$code,'default_name'=>$label,'display_name'=>$label,'status'=>1,'sort'=>$i]);
  Db::startTrans();
  foreach($source['orders']as$order)insert11('store_order',$order+['order_id'=>'source-order-'.$order['id'],'uid'=>0]);
  foreach($source['combinations']as$row)insert11('combination_order',$row);
  foreach($source['registrations']as$row)insert11('salary_store',$row);
  foreach($source['archive_proof']??[]as$row)insert11('cashier_v3_sales_order',$row+['tenant_id'=>'0','contract_version'=>'legacy-import-data-v1',
   'source_document_type'=>'legacy_store_order','order_no'=>'source-order-'.$row['source_document_id'],'member_id'=>0,'order_status'=>'settled','order_direction'=>'forward','natural_key'=>'migrated-'.$row['source_document_id'],'checkout_request_id'=>'request-'.$row['source_document_id']]);
  Db::commit();
  $ids=array_map('intval',array_column($source['stores'],'id'));
  $ctx=['tenant_id'=>'0','visible_store_ids'=>$ids,'basic_scope_mode'=>'all','basic_actor_key'=>'platform:1','query_cutoff_date'=>'2026-10-28','data_as_of'=>1793145600];
  $month=$source['month'];$last=(new DateTimeImmutable($month.'-01'))->format('Y-m-t');
  $query=['pageCode'=>Report::CODE,'start_date'=>$month.'-01','end_date'=>$last];
  $service=new Report();$started=microtime(true);$actual=$service->query($ctx,$query);$duration=microtime(true)-$started;$byMethod=array_column($actual['rows'],null,'method_code');
  $originalTime=[strtotime($month.'-01 00:00:00'),strtotime($last.' 23:59:59')];
  foreach($source['oracle']as$line)if(strpos($line['code'],'legacy_')===0)foreach($line['cells']as$store=>$expected){
   $legacy=app()->make(app\services\order\store\BranchOrderServices::class)->reportOrder(['store_id'=>(int)$store,'time'=>$originalTime,'cash_choose'=>(int)substr($line['code'],7)]);
   verify11(bccomp($legacy,(string)$expected,2)===0,'existing legacy report algorithm remains identical');
  }
  // 在隔离库中临时恢复“已核实为技术归档”的原删除标记，让未改口径的旧汇总独立算出期望。
  // 其他普通删除单保持删除；恢复仅作用于测试库，线上数据从未修改。
  $oracle=$source['oracle'];$dailyOracle=$source['daily'];
  if(!empty($source['archive_proof'])){
   $archiveIds=array_map('intval',array_column($source['archive_proof'],'source_document_id'));
   Db::name('store_order')->whereIn('id',$archiveIds)->update(['is_system_del'=>0]);
   $time=[strtotime($month.'-01 00:00:00'),strtotime($last.' 23:59:59')];
   foreach($oracle as&$line)if(strpos($line['code'],'legacy_')===0)foreach($line['cells']as$store=>&$amount)$amount=app()->make(app\services\order\store\BranchOrderServices::class)->reportOrder(['store_id'=>(int)$store,'time'=>$time,'cash_choose'=>(int)substr($line['code'],7)]);
   unset($line,$amount);
   foreach($dailyOracle as$day=>&$line)foreach($line as$method=>&$amount)$amount=app()->make(app\services\order\store\BranchOrderServices::class)->reportOrder(['store_id'=>(int)$source['daily_store_id'],'time'=>[strtotime($month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT).' 00:00:00'),strtotime($month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT).' 23:59:59')],'cash_choose'=>(int)substr($method,7)]);
   unset($line,$amount);
   Db::name('store_order')->whereIn('id',$archiveIds)->update(['is_system_del'=>1]);
  }
  foreach($oracle as$line){foreach($line['cells']as$store=>$expected){
   verify11(bccomp($byMethod[$line['code']]['store_'.$store],(string)$expected,2)===0,$source['site'].' '.$line['code'].' store '.$store.' expected '.$expected.' got '.$byMethod[$line['code']]['store_'.$store]);
  }}
  $daily=$service->query($ctx,$query+['reconciliation_mode'=>'days','reconciliation_store_id'=>(int)$source['daily_store_id'],'month'=>$month]);$byDay=array_column($daily['rows'],null,'method_code');
  foreach($dailyOracle as$day=>$methods)foreach($methods as$method=>$amount)verify11(bccomp($byDay[$method]['day_'.$day],(string)$amount,2)===0,'single store daily '.$day.' '.$method);
  $fields=array_column($actual['queryFields'],'key');$export=$service->query($ctx,$query+['export'=>['scope'=>'query','fields'=>$fields]]);
  verify11(count($export['exportRows'])===count($actual['rows']),'export all matrix rows');
  $store=(int)$source['daily_store_id'];
  foreach(['legacy_1','legacy_2','brush_dianping']as$method){
   $detail=$service->query($ctx,$query+['detail'=>['method_code'=>$method,'column_key'=>'store_'.$store],
    'export'=>['scope'=>'query','fields'=>['business_date','document_no','amount']]]);
   $sum='0.00';foreach($detail['exportRows']as$row)$sum=bcadd($sum,$row['amount'],2);
   verify11(bccomp($sum,$byMethod[$method]['store_'.$store],2)===0,'detail export precisely explains '.$method);
  }
  // Already migrated legacy payment projections must not repeat a historical receipt.
  insert11('cashier_v3_payment_fact',['fact_id'=>'already-imported','tenant_id'=>'0','store_id'=>$store,'business_date'=>$month.'-02',
   'fact_type'=>'payment_received','source_document_type'=>'legacy_store_order','payment_method'=>'unionpay','amount_cents'=>99999900,'status'=>'effective','fact_direction'=>'forward','natural_key'=>'imported']);
  $unchanged=$service->query($ctx,$query);verify11($unchanged['rows']===$actual['rows'],'imported payment fact not double counted');
  // Native V3 fact has no legacy carrier qualifying for reportOrder, so is added exactly once.
  insert11('cashier_v3_sales_order',['order_id'=>'native-order','order_no'=>'NATIVE','tenant_id'=>'0','store_id'=>$store,'member_id'=>0,
   'business_date'=>$month.'-01','order_status'=>'settled','order_direction'=>'forward','natural_key'=>'native-order','checkout_request_id'=>'native-request']);
  insert11('cashier_v3_payment_fact',['fact_id'=>'native','tenant_id'=>'0','store_id'=>$store,'business_date'=>$month.'-02',
   'fact_type'=>'payment_collected','source_document_type'=>'sales_order','payment_method'=>'unionpay','amount_cents'=>1200,'status'=>'effective','fact_direction'=>'forward','natural_key'=>'native','order_id'=>'native-order','order_no_snapshot'=>'NATIVE']);
  $withNative=$service->query($ctx,$query);$nativeBy=array_column($withNative['rows'],null,'method_code');
  verify11(bccomp($nativeBy['legacy_1']['store_'.$store],bcadd($byMethod['legacy_1']['store_'.$store],'12.00',2),2)===0,'native V3 receipt once');
  $nativeDaily=array_column($service->query($ctx,$query+['reconciliation_mode'=>'days','reconciliation_store_id'=>$store,'month'=>$month])['rows'],null,'method_code');
  verify11(bccomp($nativeDaily['legacy_1']['day_1'],bcadd($byDay['legacy_1']['day_1'],'12.00',2),2)===0
   &&bccomp($nativeDaily['legacy_1']['day_2'],$byDay['legacy_1']['day_2'],2)===0,'native original uses order business date rather than later receipt date');
  insert11('cashier_v3_order_lifecycle_operation',['operation_id'=>'native-refund','tenant_id'=>'0','store_id'=>$store,'source_type'=>'sales','source_order_id'=>'native-order','operation_type'=>'refund','status'=>'succeeded','command_idempotency_key'=>'native-refund']);
  $refunded=$service->query($ctx,$query);verify11($refunded['rows']===$actual['rows'],'successful refund removes original by007 rule');
  insert11('store_order',['id'=>9000001,'order_id'=>'genuinely-deleted','uid'=>0,'store_id'=>$store,'add_time'=>strtotime($month.'-02 12:00:00'),
   'pay_type'=>'cash','cash_choose'=>1,'cash_pay_price'=>'50000.00','pay_price'=>'50000.00','yue_pay_price'=>'0.00','pid'=>0,'paid'=>1,'is_system_del'=>1,'refund_status'=>0,'order_type'=>0]);
  verify11($service->query($ctx,$query)['rows']===$actual['rows'],'genuine deletion is never restored as migration archive');
  insert11('cashier_v3_sales_order',['order_id'=>'forged-mismatched-proof','order_no'=>'genuinely-deleted','source_document_id'=>'9000001','source_document_type'=>'legacy_store_order',
   'contract_version'=>'legacy-import-data-v1','tenant_id'=>'0','store_id'=>$store,'member_id'=>999,'order_status'=>'settled','order_direction'=>'forward','natural_key'=>'mismatched-proof','checkout_request_id'=>'mismatched-request']);
  verify11($service->query($ctx,$query)['rows']===$actual['rows'],'mismatched archive member proof fails closed');
  $reader=new Reader();$raw=$reader->receipts($ids,$reader->methods(),['start_date'=>$month.'-01','end_date'=>$last],'0');
  $proofBySource=array_column($source['archive_proof']??[],null,'source_document_id');
  foreach($raw as$receipt){
   if(!preg_match('/^legacy-order:(\d+):/', $receipt['receipt_id'],$match)||!isset($proofBySource[$match[1]])||$receipt['amount_cents']<=0)continue;
   $proof=$proofBySource[$match[1]];$method=$receipt['method_code'];$column='store_'.$receipt['store_id'];
   $expected=bcsub($byMethod[$method][$column],bcdiv((string)$receipt['amount_cents'],'100',2),2);
   insert11('cashier_v3_order_lifecycle_operation',['operation_id'=>'archive-test-void','tenant_id'=>'0','store_id'=>$receipt['store_id'],
    'source_type'=>'sales','source_order_id'=>$proof['order_id'],'operation_type'=>'void','status'=>'failed','command_idempotency_key'=>'archive-test-void']);
   verify11($service->query($ctx,$query)['rows']===$actual['rows'],'failed void cannot remove archived original receipt');
   Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id','archive-test-void')->update(['status'=>'succeeded']);
   $voided=array_column($service->query($ctx,$query)['rows'],null,'method_code');
   verify11(bccomp($voided[$method][$column],$expected,2)===0,'successful native void hides verified archived receipt');
   Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id','archive-test-void')->delete();
   insert11('cashier_v3_card_purchase_receipt',['receipt_id'=>'archive-test-issue','tenant_id'=>'0','store_id'=>$receipt['store_id'],'sales_order_id'=>$proof['order_id'],
    'sales_order_line_id'=>'archive-test-line','card_holder_id'=>9000099,'status'=>'completed','natural_key'=>'archive-test-issue']);
   insert11('cashier_v3_order_lifecycle_operation',['operation_id'=>'archive-test-refund','tenant_id'=>'0','store_id'=>$receipt['store_id'],
    'source_type'=>'imported_card','source_order_id'=>'9000099','operation_type'=>'refund','status'=>'succeeded','command_idempotency_key'=>'archive-test-refund']);
   $cardRefunded=array_column($service->query($ctx,$query)['rows'],null,'method_code');
   verify11(bccomp($cardRefunded[$method][$column],$expected,2)===0,'imported card refund follows verified original issue receipt');
   Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id','archive-test-refund')->delete();
   Db::name('cashier_v3_card_purchase_receipt')->where('receipt_id','archive-test-issue')->delete();
   break;
  }
  foreach([['pending','0',$store],['effective','foreign-tenant',$store],['effective','0',999999998]]as$i=>$case)insert11('cashier_v3_payment_fact',[
   'fact_id'=>'excluded-'.$i,'tenant_id'=>$case[1],'store_id'=>$case[2],'business_date'=>$month.'-02','fact_type'=>'payment_collected','source_document_type'=>'sales_order',
   'payment_method'=>'unionpay','amount_cents'=>99999900,'status'=>$case[0],'fact_direction'=>'forward','natural_key'=>'excluded-'.$i,'order_id'=>'excluded-'.$i]);
  verify11($service->query($ctx,$query)['rows']===$actual['rows'],'pending, foreign tenant and foreign store receipts are excluded');
  $visibility=new ReflectionMethod(Reader::class,'legacyVisibility');$visibility->setAccessible(true);
  $explainQuery=app\services\order\ValidCashOrderServices::storeCashIncomeByCashChooseQuery(['time'=>$originalTime,'cash_choose'=>1],static function($q,$alias)use($visibility,$reader){$visibility->invoke($reader,$q,$alias);})->whereIn('store_id',$ids)->field('id')->limit(50001)->buildSql(false);
  $plan=Db::query('EXPLAIN '.$explainQuery);
  $results[]=['site'=>$source['site'],'checks'=>$checks,'source_orders'=>count($source['orders']),'raw_receipts'=>count($raw),'matrix'=>$actual,'daily'=>$daily,
   'query_seconds'=>$duration,'query_plan'=>$plan,
   'source'=>['stores'=>$source['stores'],'methods'=>$reader->methods(),'receipts'=>$raw]];
 }
 if($path=getenv('R11_RESULT'))file_put_contents($path,json_encode(['passed'=>true,'checks'=>$checks,'datasets'=>$results],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 echo json_encode(['passed'=>true,'checks'=>$checks,'datasets'=>array_column($results,'site')],JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable $error){$failure=$error;}
finally{Db::rollback();Db::execute('USE `'.$original.'`');Db::execute('DROP DATABASE `'.$database.'`');}
if(isset($failure)){fwrite(STDERR,get_class($failure).': '.$failure->getMessage()."\n");exit(1);}
