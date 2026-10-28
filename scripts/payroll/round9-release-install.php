<?php
declare(strict_types=1);
/** 第9轮受控发布：精确文件备份/原子替换，仅010修改已验收的工资启用日期；不执行业务SQL。 */
$site=$argv[1]??'';$backup=$argv[2]??'';$phase=$argv[3]??'';
if(!in_array($site,['rh','008','010'],true)||!preg_match('#^/www/backups/'.preg_quote($site,'#').'/round9-[a-f0-9]{12}-[0-9]{14}$#D',$backup)||!in_array($phase,['prepare','install','activate','verify','rollback'],true))throw new RuntimeException('Explicit instance and phase required');
set_exception_handler(function($e){file_put_contents('php://stderr',(string)$e);exit(1);});
$root='/www/wwwroot/'.$site.'.cc3798.com';$m=json_decode(file_get_contents($backup.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if($m['site']!==$site)throw new RuntimeException('Wrong payload site');
function r9hash(string $p):?string{return is_file($p)?hash_file('sha256',$p):null;}
function r9copy(string $from,string $to,int $mode=0644):void{
 if(!is_dir(dirname($to)))mkdir(dirname($to),0755,true);$tmp=$to.'.round9-release';
 if(!copy($from,$tmp))throw new RuntimeException('Copy failed');chmod($tmp,$mode);chown($tmp,'www');chgrp($tmp,'www');if(!rename($tmp,$to))throw new RuntimeException('Atomic rename failed');
}
/** 只替换PAYROLL段内唯一目标键，其他实例配置与密钥保持原字节；拒绝重复段/键。 */
function r9env(string $text):string{
 $lines=preg_split('/(?<=\n)/',$text);$section=false;$seen=0;$keys=0;$at=null;
 foreach($lines as$i=>$line){
  if(preg_match('/^\s*\[([^\]]+)\]/',$line,$a)){
   if($section&&$at===null)$at=$i;$section=strcasecmp(trim($a[1]),'PAYROLL')===0;if($section){++$seen;$at=null;}
  }
  if($section&&preg_match('/^\s*REALTIME_START_DATE\s*=/i',$line)){++$keys;$lines[$i]="REALTIME_START_DATE = 2026-11-01\n";}
 }
 if($seen>1||$keys>1)throw new RuntimeException('Ambiguous payroll env section');
 if($seen===0)return rtrim($text,"\r\n")."\n\n[PAYROLL]\nREALTIME_START_DATE = 2026-11-01\n";
 if($keys===0){$at=$at??count($lines);if($at>0&&!preg_match('/\n$/',$lines[$at-1]))$lines[$at-1].="\n";array_splice($lines,$at,0,["REALTIME_START_DATE = 2026-11-01\n"]);}
 return implode('',$lines);
}
$allowed=['config/payroll.php','app/services/payroll/SalaryStatisticsSourcePolicy.php','app/services/report/SavedSalaryReportReadServices.php','app/services/query/metric/MetricReadTransaction.php','app/services/report/BasicSalaryManualServices.php','app/services/report/BasicSalaryReportServices.php','app/services/report/query/BasicBusinessQueryProvider.php'];
foreach($m['files']as$p=>$hash){if(strpos($p,'..')!==false||(!in_array($p,$allowed,true)&&!preg_match('#^public/view_cashier_v3/(index\.html|assets/[A-Za-z0-9_.-]+)$#D',$p))||r9hash($backup.'/payload/files/'.$p)!==$hash)throw new RuntimeException('Invalid fixed payload');}
if($phase==='prepare'){
 if(is_file($backup.'/before.json'))throw new RuntimeException('Already prepared');
 foreach($m['baseline_files']as$p=>$hash)if(r9hash($root.'/'.$p)!==$hash)throw new RuntimeException('Live baseline changed: '.$p);
 $before=['files'=>$m['before'],'env_before'=>r9hash($root.'/.env'),'env_after'=>null];
 foreach($before['files']as$p=>$hash)if($hash!==null){$to=$backup.'/before/'.$p;if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);if(!copy($root.'/'.$p,$to)||r9hash($to)!==$hash)throw new RuntimeException('Backup failed');chmod($to,0600);}
 if($site==='010'){
  if(!copy($root.'/.env',$backup.'/env-before'))throw new RuntimeException('Env backup failed');chmod($backup.'/env-before',0600);
  $new=r9env(file_get_contents($root.'/.env'));file_put_contents($backup.'/env-candidate',$new);chmod($backup.'/env-candidate',0600);$before['env_after']=hash('sha256',$new);
 }
 file_put_contents($backup.'/before.json',json_encode($before,JSON_THROW_ON_ERROR));chmod($backup.'/before.json',0600);
 echo json_encode(['result'=>'PREPARED','site'=>$site,'files'=>count($m['files']),'env_change'=>$site==='010','business_write'=>false]);exit;
}
$before=json_decode(file_get_contents($backup.'/before.json'),true,512,JSON_THROW_ON_ERROR);
if($phase==='rollback'){
 foreach($before['files']as$p=>$hash){$now=r9hash($root.'/'.$p);if($now!==$hash&&$now!==$m['files'][$p])throw new RuntimeException('Concurrent change prevents rollback: '.$p);}
 if($site==='010'&&!in_array(r9hash($root.'/.env'),[$before['env_before'],$before['env_after']],true))throw new RuntimeException('Concurrent env change prevents rollback');
 foreach($before['files']as$p=>$hash){if($hash!==null)r9copy($backup.'/before/'.$p,$root.'/'.$p);elseif(r9hash($root.'/'.$p)===$m['files'][$p])unlink($root.'/'.$p);}
 if($site==='010')r9copy($backup.'/env-before',$root.'/.env',0600);
 echo json_encode(['result'=>'ROLLED_BACK','site'=>$site]);exit;
}
if($phase==='install'){
 foreach($m['baseline_files']as$p=>$hash)if(r9hash($root.'/'.$p)!==$hash)throw new RuntimeException('Prepared baseline changed: '.$p);
 if(r9hash($root.'/.env')!==$before['env_before'])throw new RuntimeException('Prepared env changed');
 foreach($m['files']as$p=>$hash)if($p!=='public/view_cashier_v3/index.html')r9copy($backup.'/payload/files/'.$p,$root.'/'.$p);
 if($site==='010')r9copy($backup.'/env-candidate',$root.'/.env',0600);
 echo json_encode(['result'=>'INSTALLED','site'=>$site]);exit;
}
if($phase==='activate'){
 r9copy($backup.'/payload/files/public/view_cashier_v3/index.html',$root.'/public/view_cashier_v3/index.html');echo json_encode(['result'=>'ACTIVATED','site'=>$site]);exit;
}
foreach($m['files']as$p=>$hash)if(r9hash($root.'/'.$p)!==$hash)throw new RuntimeException('Installed file mismatch: '.$p);
foreach($m['preserve']as$p=>$hash)if(r9hash($root.'/'.$p)!==$hash)throw new RuntimeException('Preserved file changed: '.$p);
if(r9hash($root.'/.env')!==($site==='010'?$before['env_after']:$before['env_before']))throw new RuntimeException('Env verification failed');
chdir($root);require 'vendor/autoload.php';(new think\App())->initialize();
if(config('payroll.realtime_start_date','')!==($site==='010'?'2026-11-01':''))throw new RuntimeException('Cutover config not effective');
if((int)sys_config('cashier_labor_fee_mode',0)!==$m['mode']||config('payroll.position_model','legacy')!==$m['position_model'])throw new RuntimeException('Algorithm config changed');
echo json_encode(['result'=>'PASS','site'=>$site,'source_commit'=>$m['source_commit'],'files'=>count($m['files']),'cutover'=>config('payroll.realtime_start_date',''),'mode'=>$m['mode'],'position_model'=>$m['position_model'],'business_write'=>false]);
