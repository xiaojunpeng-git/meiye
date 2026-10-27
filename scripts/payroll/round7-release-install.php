<?php
declare(strict_types=1);
/** 第七轮逐实例发布：固定文件和三个空人工表；不迁移权限、不改算法、不写工资或冻结值。 */
$site=$argv[1]??'';$backup=$argv[2]??'';$phase=$argv[3]??'';
$targets=['rh'=>['ruihao',3306,'/root/.my.cnf'],'008'=>['mohe_008',3307,'/root/.mysql57-008.cnf'],'010'=>['mohe_010_app',3307,'/root/.mysql57-008.cnf']];
if(!isset($targets[$site])||!preg_match('#^/www/backups/'.preg_quote($site,'#').'/round7-[a-f0-9]{12}-[0-9]{14}$#D',$backup)||!in_array($phase,['prepare','parity','install','activate','verify','record','rollback'],true))throw new RuntimeException('Explicit release required');
$root='/www/wwwroot/'.$site.'.cc3798.com';$manifest=json_decode(file_get_contents($backup.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if($manifest['site']!==$site)throw new RuntimeException('Wrong payload instance');
chdir($root);require 'vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(function($error){fwrite(STDERR,get_class($error).': '.$error->getMessage());exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Database mismatch');

/** 保留营业追加，只核对发布前已有记录的数量及金额；配置和冻结状态必须完全不变。 */
function round7Digest(array $bounds):array {
 return ['performance'=>Db::query('SELECT COUNT(*) n,COALESCE(SUM(amount_cents),0) amount,COALESCE(SUM(labor_fee_amount_cents),0) fee FROM eb_cashier_v3_performance_fact WHERE id<='.(int)$bounds['performance'])[0],
  'orders'=>Db::name('store_order')->where('id','<=',(int)$bounds['orders'])->count(),'freeze'=>hash('sha256',json_encode(Db::name('salary_freeze')->order('id')->select()->toArray()))];
}
function round7Hash(string $path):?string{return is_file($path)?hash_file('sha256',$path):null;}
function round7Copy(string $from,string $to):void {
 if(!is_dir(dirname($to)))mkdir(dirname($to),0755,true);
 $tmp=$to.'.round7-release';if(!copy($from,$tmp))throw new RuntimeException('Copy failed');
 chmod($tmp,0644);chown($tmp,'www');chgrp($tmp,'www');if(!rename($tmp,$to))throw new RuntimeException('Rename failed');
}
foreach($manifest['files'] as $file=>$hash) {
 if(strpos($file,'..')!==false||!preg_match('#^(app/|route/(admin|cashier-v3).php$|public/view_cashier_v3/)#',$file)||round7Hash($backup.'/payload/files/'.$file)!==$hash)throw new RuntimeException('Invalid fixed payload');
}
if($phase==='prepare') {
 if(is_file($backup.'/before.json'))throw new RuntimeException('Backup already prepared');
 foreach($manifest['before']['files'] as $file=>$hash)if(round7Hash($root.'/'.$file)!==$hash)throw new RuntimeException('Live baseline changed: '.$file);
 if(round7Digest($manifest['bounds'])!==$manifest['digests'])throw new RuntimeException('Existing data changed since inspection');
 $before=['files'=>$manifest['before']['files'],'mode'=>(int)sys_config('cashier_labor_fee_mode',0),'position_model'=>config('payroll.position_model','legacy'),'digests'=>round7Digest($manifest['bounds'])];
 foreach($before['files'] as $file=>$hash)if($hash!==null){$to=$backup.'/before/'.$file;if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);copy($root.'/'.$file,$to);chmod($to,0600);}
 // 原冻结表只备份；迁移绝不修改它的状态。
 $freeze=['ddl'=>Db::query('SHOW CREATE TABLE eb_salary_freeze'),'rows'=>Db::name('salary_freeze')->order('id')->select()->toArray()];
 file_put_contents($backup.'/freeze-before.json',json_encode($freeze,JSON_THROW_ON_ERROR));chmod($backup.'/freeze-before.json',0600);
 file_put_contents($backup.'/before.json',json_encode($before,JSON_THROW_ON_ERROR));chmod($backup.'/before.json',0600);
 $credentials=parse_ini_file($targets[$site][2],true,INI_SCANNER_RAW)['client'];
 $ddl=new PDO('mysql:host=127.0.0.1;port='.$targets[$site][1].';dbname='.$targets[$site][0].';charset=utf8mb4',$credentials['user']??'root',$credentials['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if($ddl->query('SELECT DATABASE()')->fetchColumn()!==$targets[$site][0])throw new RuntimeException('Wrong DDL database');
 $sql=file_get_contents($backup.'/payload/schema.sql');if(hash('sha256',$sql)!==$manifest['schema_sha256'])throw new RuntimeException('Migration drift');
 foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement){if(!preg_match('/CREATE TABLE IF NOT EXISTS `eb_basic_salary_(manual|month_control|manual_audit)`/',$statement))throw new RuntimeException('DDL outside approved tables');$ddl->exec($statement);}
 foreach(['basic_salary_manual','basic_salary_month_control','basic_salary_manual_audit'] as $table)if(Db::name($table)->count()!==0)throw new RuntimeException('New manual tables must be empty before release');
 echo json_encode(['result'=>'PREPARED','site'=>$site,'schema_empty'=>true]);exit;
}
$before=json_decode(file_get_contents($backup.'/before.json'),true,512,JSON_THROW_ON_ERROR);
if($phase==='parity') {
 // 同一只读事务比较已有工资的全部数值；排除本轮新增的显示列和元数据。
 require $backup.'/payload/files/app/services/report/BasicSalaryManualServices.php';
 $code=file_get_contents($backup.'/payload/files/app/services/report/BasicSalaryReportServices.php');
 $code=str_replace('final class BasicSalaryReportServices','final class Round7CandidateSalaryReportServices',$code);eval(substr($code,5));
 $tx=new class{public function run(callable $callback):array{return $callback();}};
 Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');Db::startTrans();
 try {
  $identity=Db::name('cashier_v3_performance_fact')->where('store_id','>',0)->field('store_id,COUNT(*) n')->group('store_id')->order('n','desc')->find();
  $store=(int)($identity['store_id']??0);if(!$store)$store=(int)Db::name('system_store')->where('is_del',0)->value('id');
  $input=['start_date'=>'2026-10-01','end_date'=>'2026-10-27','as_of_date'=>'2026-10-27','_report_scope'=>['mode'=>'stores']];
  $old=new app\services\report\BasicSalaryReportServices(null,$tx);$new=new app\services\report\Round7CandidateSalaryReportServices(null,$tx);
  $a=$old->read('basic_salary_realtime',[$store],$input);$b=$new->read('basic_salary_realtime',[$store],$input);
  $keys=[];foreach($a['fields'] as $field)if($field['type']==='number')$keys[$field['key']]=true;
  $project=function(array $rows)use($keys):array{$out=[];foreach($rows as $row){$values=array_intersect_key($row,$keys);ksort($values);$out[$row['row_id']]=$values;}ksort($out);return $out;};
  $left=$project($a['rows']);$right=$project($b['rows']);if($left!==$right)throw new RuntimeException('Existing salary amounts changed');
  if(count($a['rows'])!==count($b['rows']))throw new RuntimeException('Existing salary rows changed');
  Db::rollback();echo json_encode(['result'=>'PASS','site'=>$site,'store_id'=>$store,'rows'=>count($left),'numeric_fields'=>count($keys),'same_snapshot'=>true,'values_sha256'=>hash('sha256',json_encode($left))]);exit;
 }catch(Throwable $error){Db::rollback();throw $error;}
}
if($phase==='rollback') {
 foreach($before['files'] as $file=>$hash){$current=round7Hash($root.'/'.$file);if($current!==$hash&&$current!==$manifest['files'][$file])throw new RuntimeException('Concurrent file change prevents rollback: '.$file);}
 foreach($before['files'] as $file=>$hash){if($hash!==null)round7Copy($backup.'/before/'.$file,$root.'/'.$file);elseif(round7Hash($root.'/'.$file)===$manifest['files'][$file])unlink($root.'/'.$file);}
 echo json_encode(['result'=>'ROLLED_BACK','empty_schema_retained'=>true]);exit;
}
if($phase==='install') {
 foreach($before['files'] as $file=>$hash)if(round7Hash($root.'/'.$file)!==$hash)throw new RuntimeException('Prepared baseline changed: '.$file);
 if(round7Digest($manifest['bounds'])!==$before['digests'])throw new RuntimeException('Existing data changed before install');
 foreach($manifest['files'] as $file=>$hash)if($file!=='public/view_cashier_v3/index.html')round7Copy($backup.'/payload/files/'.$file,$root.'/'.$file);
 echo json_encode(['result'=>'INSTALLED','site'=>$site]);exit;
}
if($phase==='activate') {
 round7Copy($backup.'/payload/files/public/view_cashier_v3/index.html',$root.'/public/view_cashier_v3/index.html');
 echo json_encode(['result'=>'ACTIVATED','site'=>$site]);exit;
}
foreach($manifest['files'] as $file=>$hash)if(round7Hash($root.'/'.$file)!==$hash)throw new RuntimeException('Installed fingerprint mismatch: '.$file);
if(round7Digest($manifest['bounds'])!==$before['digests']||(int)sys_config('cashier_labor_fee_mode',0)!==$before['mode']||config('payroll.position_model','legacy')!==$before['position_model'])throw new RuntimeException('Business or algorithm changed');
// 切换后允许用户正常保存；发布器本身没有人工写入，不能因正常营业误判为失败。
$manualCounts=[];foreach(['basic_salary_manual','basic_salary_month_control','basic_salary_manual_audit'] as $table)$manualCounts[$table]=Db::name($table)->count();
if($phase==='record'){
 $key='20261027-basic-salary-manual-freeze';$row=Db::name('database_upgrade_log')->where('upgrade_key',$key)->find();
 if($row && $row['code_version']!==$manifest['source_commit'])throw new RuntimeException('Migration record conflicts');
 if(!$row)Db::name('database_upgrade_log')->insert(['upgrade_key'=>$key,'title'=>'工资人工补充与月份编辑冻结','task_file'=>'2026-10-27-工资人工项与编辑冻结/01-结构升级.sql','sql_checksum'=>$manifest['schema_sha256'],'code_version'=>$manifest['source_commit'],'executed_at'=>date('Y-m-d H:i:s'),'executed_by'=>'codex-release','result_note'=>'Three empty supplemental tables installed; algorithm and business facts preserved']);
}
echo json_encode(['result'=>'PASS','site'=>$site,'source_commit'=>$manifest['source_commit'],'files'=>count($manifest['files']),'mode'=>$before['mode'],'position_model'=>$before['position_model'],'existing_business_unchanged'=>true,'manual_counts'=>$manualCounts]);
