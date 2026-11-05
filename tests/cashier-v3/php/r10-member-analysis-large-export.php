<?php
/** 本地恢复库真实超过一万名会员的完整导出：分批、同范围、不断尾、不写业务表。 */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
if(strpos((string)config('database.connections.mysql.hostname'),'mysql')===false)throw new Exception('local only');
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try {
 $rt=app\services\query\UnifiedQueryRuntime::runtime();$admin=(int)Db::name('system_admin')->where('status',1)->where('is_del',0)->where('admin_type',0)->order('id')->value('id');
 $scope=(new app\services\user\PlatformMemberScopeServices())->build($admin);
 $ctx=(new app\services\query\provider\MemberUnifiedQueryContextFactory($rt['contextFactory']))->make($scope['operator'],$scope['data'],[]);
 $ctx['query_cutoff_date']='2026-11-05';$ctx['all_stores']=false;$stores=[];
 $provider=app\services\query\UnifiedQueryRuntime::service('providers')->resolve('platform_member_list');
 $authorized=new ReflectionMethod($provider,'authorizedUserQuery');$authorized->setAccessible(true);
 foreach($scope['stores'] as $store){$stores[]=(int)$store['id'];$ctx['visible_store_ids']=$stores;[$q]=$authorized->invoke($provider,$ctx,[],true);$total=(int)$q->count('u.uid');if($total>10000)break;}
 if($total<=10000)throw new Exception('insufficient real local sample');
 $payload=['pageCode'=>'platform_member_list','limit'=>20,'memberAnalysis'=>['visitCount'=>['min'=>'0','max'=>'']]];
 $list=$provider->query($ctx,$payload);$plan=$rt['execution']->validatedPlan('platform_member_list',[],$payload,$ctx);
 $heartbeats=0;$ctx['_export_checkpoint']=static function()use(&$heartbeats){++$heartbeats;};$t=microtime(true);
 $export=$provider->executeFrozenPlan($ctx,$plan,'query',['member_name','phone']);
 if(!($export['exportRows'] instanceof app\services\query\UnifiedQueryExternalRows))throw new Exception('not bounded streaming rows');
 if(count($export['exportRows'])!==$total || $list['total']!==$total)throw new Exception('large export truncated or lost filters');
 $first=[];$n=0;foreach($export['exportRows'] as $row){if($n<20)$first[]=$row['phone'];++$n;}
 if($first!==array_column($list['records'],'phone')||$n!==$total||$heartbeats<50)throw new Exception('pagination/order/heartbeat mismatch');
 // 使用实际worker同款写入器生成XLSX，再检查物理行数，不能仅凭返回的预计数量认定成功。
 $file=tempnam(sys_get_temp_dir(),'r10-member-export-');
 try {
  $written=(new app\services\query\UnifiedQueryStreamingWorkbook())->write($file,
    [['key'=>'member_name','label'=>'会员姓名','type'=>'text'],['key'=>'phone','label'=>'完整手机号','type'=>'text']],
    $export['exportRows'],[],static function()use(&$heartbeats){++$heartbeats;});
  $zip=new ZipArchive();if($zip->open($file)!==true)throw new Exception('xlsx invalid');
  $xml=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();
  $xlsxRows=substr_count($xml,'<row ')-1;$xlsxBytes=filesize($file);
  if($written!==$total || $xlsxRows!==$total)throw new Exception('xlsx physical rows truncated');
 } finally {if(is_file($file))unlink($file);}
 echo json_encode(['result'=>'PASS','stores'=>count($stores),'queryTotal'=>$total,'exportRows'=>$n,'xlsxRows'=>$xlsxRows,'xlsxBytes'=>$xlsxBytes,'batchRows'=>200,'heartbeats'=>$heartbeats,'durationMs'=>round((microtime(true)-$t)*1000),'peakMemoryBytes'=>memory_get_peak_usage(true),'writes'=>0]),"\n";
} finally {Db::rollback();}
