<?php
declare(strict_types=1);
/** 第九轮只读预检：显式实例、资源与迁移指纹；业务摘要固定主键范围，不导出客户明细。 */
$site=$argv[1]??'';$targets=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(!isset($targets[$site]))throw new RuntimeException('Explicit instance required');
chdir('/www/wwwroot/'.$site.'.cc3798.com');require 'vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(function($e){file_put_contents('php://stderr',$e->getMessage());exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Database identity mismatch');
$request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');Db::startTrans();
$bounds=$request['bounds']??['performance'=>(int)Db::name('cashier_v3_performance_fact')->max('id'),'orders'=>(int)Db::name('store_order')->max('id')];
$out=['site'=>$site,'database'=>$targets[$site][0],'mode'=>(int)sys_config('cashier_labor_fee_mode',0),'position_model'=>config('payroll.position_model','legacy'),'bounds'=>$bounds,'files'=>[]];
foreach($request['files'] as $file){if(strpos($file,'..')!==false)throw new RuntimeException('Invalid path');$out['files'][$file]=is_file($file)?hash_file('sha256',$file):null;}
$out['business']=['performance'=>Db::query('SELECT COUNT(*) n,COALESCE(SUM(amount_cents),0) amount,COALESCE(SUM(labor_fee_amount_cents),0) fee FROM eb_cashier_v3_performance_fact WHERE id<='.(int)$bounds['performance'])[0],'orders'=>Db::name('store_order')->where('id','<=',(int)$bounds['orders'])->count()];
// 规则、权限、人工工资与冻结配置均只核对摘要，不在此发布中改写。
foreach(['position_yeji','yeji_commission','salary_freeze','basic_salary_manual','basic_salary_month_control','basic_salary_manual_audit','job_position_channel_rule','store_v3_permission_catalog','system_role'] as $table){$rows=Db::name($table)->select()->toArray();usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out['business'][$table]=hash('sha256',json_encode($rows));}
$out['upgrades']=Db::name('database_upgrade_log')->field('upgrade_key,sql_checksum,code_version')->order('id','desc')->limit(20)->select()->toArray();
$out['mobile_assets']=[];
// 资源只读指纹用于验证现网整包基线，不能只用index.html判断功能完整。
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator('public/view_mobile_v3',FilesystemIterator::SKIP_DOTS));
foreach($iterator as $item)if($item->isFile())$out['mobile_assets'][$item->getPathname()]=hash_file('sha256',$item->getPathname());
$out['realtime_start_date']=config('payroll.realtime_start_date','');
$out['salary_info_sha256']=hash('sha256',json_encode(Db::name('salary_info')->order('id')->select()->toArray()));
$out['cashier_assets']=[];
$entry='public/view_cashier_v3/index.html';
if(is_file($entry)){
 $out['cashier_assets'][$entry]=hash_file('sha256',$entry);
 preg_match_all('#(?:src|href)=["\']([^"\']+\.(?:js|css))["\']#',file_get_contents($entry),$matches);
 foreach($matches[1] as$asset){$f='public'.parse_url($asset,PHP_URL_PATH);if(!is_file($f))throw new RuntimeException('Missing live entry asset');$out['cashier_assets'][$f]=hash_file('sha256',$f);}
}
Db::rollback();echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
