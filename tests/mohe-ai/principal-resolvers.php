<?php
namespace think\facade {
    class Db { public static $rows=[]; public static function name($table){return new Query($table);} }
    class Query {private $table,$filters=[];function __construct($table){$this->table=$table;}function where($key,$op,$value=null){$this->filters[]=[$key,$value===null?'=':$op,$value===null?$op:$value];return $this;}function filtered(){return array_values(array_filter(Db::$rows[$this->table]??[],function($row){foreach($this->filters as [$key,$op,$value]){if($op==='=' && ($row[$key]??null)!=$value)return false;if($op==='<>' && ($row[$key]??null)==$value)return false;}return true;}));}function find(){return $this->filtered()[0]??null;}function column($field){return array_column($this->filtered(),$field);} }
}
namespace app\services\organization {
    class EmployeeDataScopeServices {const MODE_PERSONAL='personal';public static $scope=[1];public static $mode='store';function resolveEffectiveStoreIds(...$args){return self::$scope;}function resolvePrimaryHqScopeMode(...$args){return self::$mode;}function resolveCurrentActiveStoreIds(...$args){return [1];}}
    class OrganizationScopeService {function getResolvedStoreIdsByLegacyAgentId($id){return [2];}}
}
namespace app\services\system {class SystemRoleServices {public static $allow=true;function getRolesByAuth($roles,$kind){return self::$allow?array_map(function($code){return ['unique_auth'=>$code];},['mohe-ai-entry','mohe-ai-config','admin-report-group-management-dashboard']):[];}}}
namespace app\services\mobile\merchant {class MobileMerchantRequestContextResolver {public static $account=9;public static $actions=['MERCHANT_WAREHOUSE_VIEW','MOHE_AI_USE'];function trustedBusinessPrincipal($employee,$staff,$store,$org){return ['accountId'=>self::$account,'employeeId'=>$employee,'staffId'=>$staff,'storeId'=>$store,'organizationId'=>$org,'availableActions'=>self::$actions];}}}
namespace app\services\mobile\warehouse {class MobileWarehouseServices {public static $personal=false;function aiReportContext($merchant){return ['terminal'=>'merchant','account_id'=>$merchant['accountId'],'can_use'=>!self::$personal,'store_ids'=>self::$personal?[]:[1]];}}}
namespace app\model\store {class SystemStoreStaff {static function where(...$args){return new self;}function __call($name,$args){if($name==='find')return $this;return $this;}function toArray(){return ['employee_id'=>11,'level'=>1];}}}
namespace app\services\cashier\v3 {class CashierV3DataScopeContext {const MODE_NONE='none';}}
namespace app\services\cashier\v3\permission {class CashierV3FeatureResolver {public static $features=['cashier.v3.ai','cashier.v3.management_center'];function resolveGrantedFeatures($p){return self::$features;}}}
namespace app\services\cashier\v3\bootstrap {class CashierV3Bootstrap {static function dispatcher(){return new class {
 function scopeResolver(){return $this;}function operatorScope(...$a){return $this;}function tenantId(){return '0';}function organizationId(){return '0';}
 function dataScopeFactory(){return $this;}function build(...$a){return $this;}function isSelfParticipantMode(){return false;}function visibleStoreIds(){return [1];}function authorizationMode(){return 'stores';}function permissionVersion(){return 'fixture';}
};}}}
namespace {
function app(){return new class {function make($class){return new $class;}};}
$root=dirname(__DIR__,2).'/后端代码/app/services/ai/execution/';require $root.'AiPlatformPrincipalResolver.php';require $root.'AiMerchantPrincipalResolver.php';
$count=0;function ok($value){global $count;if(!$value)throw new \RuntimeException('principal check failed');$count++;}function denied($call){try{$call();}catch(\RuntimeException $e){ok(true);return;}throw new \RuntimeException('expected deny');}
\think\facade\Db::$rows=['system_admin'=>[['id'=>7,'status'=>1,'is_del'=>0,'level'=>1,'admin_type'=>0,'roles'=>'1','employee_id'=>11]],'employee'=>[['id'=>11,'status'=>1,'is_del'=>0]],'system_store'=>[['id'=>1,'is_del'=>0,'name'=>'A']]];
$p=new \app\services\ai\execution\AiPlatformPrincipalResolver();$b=['terminal'=>'platform','principal_kind'=>'platform_admin','account_id'=>7];ok($p->worker($b)['store_ids']===[1]);
// R6: role grants, super-admin level, display name and lookalikes cannot grant configuration.
foreach ([null,'operator','Admin','admin ','admin-other'] as $account) {
    \think\facade\Db::$rows['system_admin'][0]['account']=$account;
    \think\facade\Db::$rows['system_admin'][0]['real_name']='admin';
    ok($p->authenticated(7)['can_configure']===false);
    ok($p->authenticated(7)['can_use']===true);
}
\think\facade\Db::$rows['system_admin'][0]['level']=0;
ok($p->authenticated(7)['can_configure']===false);
\think\facade\Db::$rows['system_admin'][0]['level']=1;
\think\facade\Db::$rows['system_admin'][0]['account']='admin';
ok($p->authenticated(7)['can_configure']===true);
\app\services\system\SystemRoleServices::$allow=false;
ok($p->authenticated(7)['can_configure']===true);
ok($p->authenticated(7)['can_use']===false);
\app\services\system\SystemRoleServices::$allow=true;
\app\services\organization\EmployeeDataScopeServices::$scope=[];
ok($p->worker($b)['scope_mode']==='self_participant');ok($p->worker($b)['employee_id']===11);ok($p->worker($b)['store_ids']===[1]);
\app\services\organization\EmployeeDataScopeServices::$scope=[1];
\app\services\system\SystemRoleServices::$allow=false;denied(fn()=>$p->worker($b));\app\services\system\SystemRoleServices::$allow=true;
\think\facade\Db::$rows['system_admin'][0]['status']=0;denied(fn()=>$p->worker($b));\think\facade\Db::$rows['system_admin'][0]['status']=1;
\think\facade\Db::$rows['system_admin'][0]['admin_type']=3;\think\facade\Db::$rows['system_admin'][0]['relation_id']=0;ok($p->worker($b)['scope_mode']==='none');
\think\facade\Db::$rows['system_admin'][0]['relation_id']=4;ok($p->worker($b)['store_ids']===[2]);
ok($p->authenticated(7)['can_configure']===false);
$m=new \app\services\ai\execution\AiMerchantPrincipalResolver();$mb=['terminal'=>'merchant','principal_kind'=>'merchant_employee','account_id'=>9,'employee_id'=>11,'staff_id'=>5,'origin_store_id'=>1,'origin_organization_id'=>'3'];ok($m->worker($mb)['employee_id']===11);
ok($m->worker($mb)['can_configure']===false);
\app\services\mobile\merchant\MobileMerchantRequestContextResolver::$account=10;denied(fn()=>$m->worker($mb));\app\services\mobile\merchant\MobileMerchantRequestContextResolver::$account=9;
\app\services\organization\EmployeeDataScopeServices::$scope=[];ok($m->worker($mb)['scope_mode']==='self_participant');ok($m->worker($mb)['store_ids']===[1]);
\app\services\organization\EmployeeDataScopeServices::$scope=[1,2];ok($m->worker($mb)['store_ids']===[1,2]);
\app\services\mobile\merchant\MobileMerchantRequestContextResolver::$actions=['MOHE_AI_USE'];ok($m->worker($mb)['can_use']);ok(!$m->worker($mb)['store_report_authorized']);
\app\services\mobile\merchant\MobileMerchantRequestContextResolver::$actions=['MERCHANT_WAREHOUSE_VIEW'];denied(fn()=>$m->worker($mb));
$mb['employee_id']='11';denied(fn()=>$m->worker($mb));
require dirname(__DIR__,2).'/后端代码/app/services/cashier/v3/permission/CashierV3StaffFeatureOverrideServices.php';
ok(in_array('cashier.v3.ai',\app\services\cashier\v3\permission\CashierV3StaffFeatureOverrideServices::operationFeatureCodes(),true));
require $root.'AiTrustedPrincipalResolver.php';require $root.'AiAuthority.php';
$s=new \app\services\ai\execution\AiTrustedPrincipalResolver();
\app\services\organization\EmployeeDataScopeServices::$scope=[1,2];
$sc=$s->storeAuthenticated(1,5,[]);ok($sc['store_ids']===[1,2]);ok($sc['can_use']);ok($sc['store_report_authorized']);
ok(\app\services\ai\execution\AiAuthority::currentStoreId($sc)===1);
ok(\app\services\ai\execution\AiAuthority::currentStoreId(array_replace($sc,['origin_store_id'=>9]))===null);
ok(\app\services\ai\execution\AiAuthority::currentStoreId(array_replace($sc,['origin_store_id'=>'1']))===null);
\app\services\organization\EmployeeDataScopeServices::$scope=[];
$sc=$s->storeAuthenticated(1,5,[]);ok($sc['scope_mode']==='self_participant');ok($sc['employee_id']===11);ok($sc['store_ids']===[1]);
\app\services\cashier\v3\permission\CashierV3FeatureResolver::$features=['cashier.v3.ai','cashier.v3.cashier'];
$sc=$s->storeAuthenticated(1,5,[]);ok($sc['can_use']);ok(!$sc['store_report_authorized']);ok(!$sc['analysis_personnel_grants']['staff_labor_yeji']);
\app\services\cashier\v3\permission\CashierV3FeatureResolver::$features=['cashier.v3.management_center'];ok(!$s->storeAuthenticated(1,5,[])['can_use']);
echo "Principal adapters: $count checks PASS (authority fixtures; no live DB)\n";
}
