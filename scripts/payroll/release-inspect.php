<?php
/** 只读发布预检；固定实例身份、源码指纹和本客户配置计划，不读取或复制其他客户数据。 */
declare(strict_types=1);
$site=$argv[1]??'';$targets=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(!isset($targets[$site]))throw new RuntimeException('Explicit target required');
chdir('/www/wwwroot/'.$site.'.cc3798.com');require 'vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(function($e){file_put_contents('php://stderr',get_class($e).': '.$e->getMessage());exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Database mismatch');
Db::execute('SET SESSION TRANSACTION READ ONLY');Db::startTrans();
$files=json_decode(stream_get_contents(STDIN),true);$out=['site'=>$site,'database'=>$targets[$site][0],'mode'=>(int)sys_config('cashier_labor_fee_mode',0),'position_model'=>config('payroll.position_model','legacy'),'platform_menu_all_stores'=>(bool)config('basic_report.platform_menu_all_stores',false),'files'=>[]];
foreach($files as $p)$out['files'][$p]=is_file($p)?hash_file('sha256',$p):null;
$out['business']=['performance'=>Db::query('SELECT COUNT(*) n,COALESCE(SUM(amount_cents),0) amount,COALESCE(SUM(labor_fee_amount_cents),0) fee FROM eb_cashier_v3_performance_fact')[0],'orders'=>Db::name('store_order')->count(),'old_wage_rules'=>hash('sha256',json_encode(Db::name('position_yeji')->order('id')->select()->toArray())),'tiers'=>hash('sha256',json_encode(Db::name('yeji_commission')->order('id')->select()->toArray()))];
$sources=Db::query('SELECT c.id,c.product_id,c.yeji,p.id project_exists,p.pid,p.product_type FROM eb_yeji_commission c LEFT JOIN eb_store_product p ON p.id=c.product_id ORDER BY c.id');
$rules=Db::name('cashier_v3_project_performance_rule')->where('tenant_id','0')->field('id,project_id,craftsman_default_unit_amount_cents')->order('id')->select()->toArray();
$cards=Db::query('SELECT r.id,r.product_id,r.craftsman_default_mode,r.craftsman_default_unit_amount_cents,p.pid,p.product_type FROM eb_store_card_related r LEFT JOIN eb_store_product p ON p.id=r.product_id WHERE r.status=1 AND r.product_type=6 ORDER BY r.id');
$amounts=[];$excluded=[];foreach($sources as $row){if($row['project_exists']===null){$excluded[]=(int)$row['id'];continue;}if((int)$row['pid']!==0||(int)$row['product_type']!==6||!preg_match('/^\d+$/',(string)$row['yeji']))throw new RuntimeException('Invalid local project configuration');$id=(int)$row['product_id'];if(isset($amounts[$id]))throw new RuntimeException('Duplicate local project');$amounts[$id]=(int)$row['yeji']*100;}
$byProject=[];foreach($rules as $row){$id=(int)$row['project_id'];if(isset($byProject[$id]))throw new RuntimeException('Duplicate current default rule');$byProject[$id]=$row;}
$projects=[];$preserved=0;foreach($amounts as $id=>$amount){$rule=$byProject[$id]??null;if($rule!==null&&$rule['craftsman_default_unit_amount_cents']!==null){$preserved++;continue;}$projects[]=[$id,$amount,$rule===null?0:(int)$rule['id']];}
$manual=[];$fallback=0;$preservedCards=0;foreach($cards as $row){$master=(int)$row['pid']>0?(int)$row['pid']:(int)$row['product_id'];if($row['craftsman_default_mode']!=='default_unit_price'||(int)$row['craftsman_default_unit_amount_cents']!==0){$preservedCards++;continue;}if(!array_key_exists($master,$amounts)){$fallback++;continue;}$manual[]=[(int)$row['id'],$amounts[$master]];}
$out['defaults_plan']=['projects'=>$projects,'cards'=>$manual,'excluded_sources'=>$excluded,'preserved_projects'=>$preserved,'preserved_cards'=>$preservedCards,'fallback_cards'=>$fallback,'source_hash'=>hash('sha256',json_encode([$sources,$rules,$cards]))];
$out['defaults_plan']['sha256']=hash('sha256',json_encode($out['defaults_plan']));
$out['permissions']=['catalog'=>Db::name('store_v3_permission_catalog')->where('id',1)->field('id,revision,rules_json')->find(),'platform_job_roles'=>Db::name('job_position_channel_rule')->where('channel','platform')->field('id,rules,version')->order('id')->select()->toArray(),'roles'=>Db::name('job_position_channel_rule')->where('channel','store_v3')->field('id,rules,version')->order('id')->select()->toArray(),'platform'=>Db::name('system_role')->field('id,rules')->order('id')->select()->toArray(),'menus'=>Db::name('system_menus')->where('unique_auth','like','admin-report%')->field('id,pid,unique_auth,menu_path,is_show')->order('id')->select()->toArray()];
$out['schema']=Db::query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_cashier_v3_project_performance_rule','eb_store_card_related','eb_position_salary_rule')");
Db::rollback();echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
