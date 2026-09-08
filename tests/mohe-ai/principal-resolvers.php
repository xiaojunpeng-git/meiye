<?php
namespace think\facade {
    class Db { public static $rows=[]; public static function name($table){return new Query($table);} }
    class Query {private $table,$filters=[];function __construct($table){$this->table=$table;}function where($key,$op,$value=null){$this->filters[]=[$key,$value===null?'=':$op,$value===null?$op:$value];return $this;}function filtered(){return array_values(array_filter(Db::$rows[$this->table]??[],function($row){foreach($this->filters as [$key,$op,$value]){if($op==='=' && ($row[$key]??null)!=$value)return false;if($op==='<>' && ($row[$key]??null)==$value)return false;}return true;}));}function find(){return $this->filtered()[0]??null;}function column($field){return array_column($this->filtered(),$field);} }
}
namespace app\services\organization {
    class EmployeeDataScopeServices {const MODE_PERSONAL='personal';public static $scope=[1];public static $mode='store';function resolveEffectiveStoreIds(...$args){return self::$scope;}function resolvePrimaryHqScopeMode(...$args){return self::$mode;}}
    class OrganizationScopeService {function getResolvedStoreIdsByLegacyAgentId($id){return [2];}}
}
namespace app\services\system {class SystemRoleServices {public static $allow=true;function getRolesByAuth($roles,$kind){return self::$allow?array_map(function($code){return ['unique_auth'=>$code];},['mohe-ai-entry','mohe-ai-config','admin-report-group-management-dashboard']):[];}}}
namespace app\services\mobile\merchant {class MobileMerchantRequestContextResolver {public static $account=9;function trustedBusinessPrincipal($employee,$staff,$store,$org){return ['accountId'=>self::$account,'employeeId'=>$employee,'staffId'=>$staff,'storeId'=>$store,'organizationId'=>$org,'availableActions'=>['MERCHANT_WAREHOUSE_VIEW']];}}}
namespace app\services\mobile\warehouse {class MobileWarehouseServices {public static $personal=false;function aiReportContext($merchant){return ['terminal'=>'merchant','account_id'=>$merchant['accountId'],'can_use'=>!self::$personal,'store_ids'=>self::$personal?[]:[1]];}}}
namespace {
function app(){return new class {function make($class){return new $class;}};}
$root=dirname(__DIR__,2).'/后端代码/app/services/ai/execution/';require $root.'AiPlatformPrincipalResolver.php';require $root.'AiMerchantPrincipalResolver.php';
$count=0;function ok($value){global $count;if(!$value)throw new \RuntimeException('principal check failed');$count++;}function denied($call){try{$call();}catch(\RuntimeException $e){ok(true);return;}throw new \RuntimeException('expected deny');}
\think\facade\Db::$rows=['system_admin'=>[['id'=>7,'status'=>1,'is_del'=>0,'level'=>1,'admin_type'=>0,'roles'=>'1','employee_id'=>11]],'employee'=>[['id'=>11,'status'=>1,'is_del'=>0]],'system_store'=>[['id'=>1,'is_del'=>0,'name'=>'A']]];
$p=new \app\services\ai\execution\AiPlatformPrincipalResolver();$b=['terminal'=>'platform','principal_kind'=>'platform_admin','account_id'=>7];ok($p->worker($b)['store_ids']===[1]);
\app\services\organization\EmployeeDataScopeServices::$mode='personal';denied(fn()=>$p->worker($b));\app\services\organization\EmployeeDataScopeServices::$mode='store';
\app\services\system\SystemRoleServices::$allow=false;denied(fn()=>$p->worker($b));\app\services\system\SystemRoleServices::$allow=true;
\think\facade\Db::$rows['system_admin'][0]['status']=0;denied(fn()=>$p->worker($b));\think\facade\Db::$rows['system_admin'][0]['status']=1;
\think\facade\Db::$rows['system_admin'][0]['admin_type']=3;\think\facade\Db::$rows['system_admin'][0]['relation_id']=0;denied(fn()=>$p->worker($b));
\think\facade\Db::$rows['system_admin'][0]['relation_id']=4;ok($p->worker($b)['store_ids']===[2]);
$m=new \app\services\ai\execution\AiMerchantPrincipalResolver();$mb=['terminal'=>'merchant','principal_kind'=>'merchant_employee','account_id'=>9,'employee_id'=>11,'staff_id'=>5,'origin_store_id'=>1,'origin_organization_id'=>'3'];ok($m->worker($mb)['employee_id']===11);
\app\services\mobile\merchant\MobileMerchantRequestContextResolver::$account=10;denied(fn()=>$m->worker($mb));\app\services\mobile\merchant\MobileMerchantRequestContextResolver::$account=9;
\app\services\mobile\warehouse\MobileWarehouseServices::$personal=true;denied(fn()=>$m->worker($mb));$mb['employee_id']='11';denied(fn()=>$m->worker($mb));
echo "Principal adapters: $count checks PASS (authority fixtures; no live DB)\n";
}
