<?php
/** R37 逐实例只读集成检查：真实配置表单、验证器与根组装器显示选项；用测试分区避免生成工作台或业务事实。 */
$site=$argv[1]??'';$mode=$argv[2]??'';
$sites=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];
if(!isset($sites[$site])||!in_array($mode,['before','candidate','after'],true))throw new RuntimeException('EXPLICIT_INSTANCE_MODE');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;
require $root.'/vendor/autoload.php';
// Inject fixed candidate Git classes here, before application autoload.
(new think\App($root.'/'))->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
$cfg=config('database.connections.mysql');if($cfg['database']!==$database||(string)$cfg['hostport']!==$port)throw new RuntimeException('WRONG_DATABASE');
use think\facade\Db;
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try{
 $key='cashier_labor_fee_mode';$row=Db::name('system_config')->where('menu_name',$key)->where('is_store',0)->field('id,value')->find();
 $other=Db::name('system_config')->where('menu_name','<>',$key)->order('id')->select()->toArray();
 $form=app()->make(app\services\system\config\SystemConfigServices::class)->shopBaseFormBuild();$found=null;
 $strip=function($nodes)use(&$strip,&$found){if(!is_array($nodes))return $nodes;if(($nodes['field']??null)==='cashier_labor_fee_mode'){$found=$nodes;return null;}$out=[];foreach($nodes as $k=>$v){$clean=$strip($v);if($clean!==null)$out[$k]=$clean;}return array_is_list_r37($nodes)?array_values($out):$out;};
 $oldForm=$strip($form);$checks=[];
 if($mode!=='before'){
  if(!$found||count($found['options']??[])!==2||(int)$found['value']!==(int)($row['value']??0))throw new RuntimeException('FORM_VALUE_MISMATCH');
  $v=new app\validate\admin\setting\SystemConfigValidate();foreach([0=>true,1=>true,2=>false,-1=>false,'invalid'=>false]as$input=>$expected)if($v->check([$key=>$input])!==$expected)throw new RuntimeException('ENUM_INVALID');
  // 命名空间配置替身只作用于本 CLI 进程；不写线上配置或缓存，真实业务分区不执行。
  eval('namespace app\\services\\cashier\\v3\\projection; function sys_config($key,$default=""){return $key==="cashier_labor_fee_mode"?$GLOBALS["r37TestMode"]:\\sys_config($key,$default);}');
  // 权限目录的 snapshot() 带历史初始化；本测试直接读取其现有规则，禁止触发任何迁移或审计写入。
  if(class_exists('app\\services\\organization\\StorePermissionCatalogServices',false))throw new RuntimeException('NAV_READER_ALREADY_LOADED');
  eval('namespace app\\services\\organization; class StorePermissionCatalogServices {public function snapshot():array{return ["rules"=>json_decode(\\think\\facade\\Db::name("store_v3_permission_catalog")->where("id",1)->value("rules_json"),true)?:[]];}}');
  $assembler=new class extends app\services\cashier\v3\projection\CashierV3RootDomainAssembler{
   public function isReadyForFullRoot():bool{return true;}
   protected function loadStore(int $id){return ['id'=>$id,'name'=>'R37只读验证'];}
   protected function operatorDetails(array $p):array{return [];}
   protected function operatorDisplayName(array $p):string{return 'R37只读验证';}
   protected function ensureWorkspaceVersion(string $id,app\services\cashier\v3\CashierV3OperatorScope $s):int{return 1;}
  };
  foreach($assembler::REQUIRED_BUSINESS_PARTITIONS as$key){$provider=new class($key)implements app\services\cashier\v3\projection\CashierV3RootPartitionProvider{
   private $key;public function __construct($key){$this->key=$key;}public function partitionKey():string{return $this->key;}
   public function readPartition(string $a,string $b,app\services\cashier\v3\CashierV3OperatorScope $c,app\services\cashier\v3\CashierV3DataScopeContext $d,array $e=[]):array{return ['ready'=>true,'payload'=>$this->key==='pendingHangCount'?0:[]];}
  };$assembler->registerPartitionProvider($provider);}
  $storeId=(int)Db::name('system_store')->where('is_del',0)->value('id');if($storeId<1)throw new RuntimeException('NO_STORE');
  $scope=new app\services\cashier\v3\CashierV3OperatorScope($storeId,1,'','default');
  $data=new app\services\cashier\v3\CashierV3DataScopeContext(1,0,$storeId,'default','',[$storeId],'stores',[],false,'','r37',[],[],true);
  foreach([0,1,null]as$value){$GLOBALS['r37TestMode']=$value;$options=$assembler->assemble('r37-read-only','1',$scope,$data)['storeDisplayOptions'];if($options['laborFeeVisible']!==($value!==1))throw new RuntimeException('ROOT_VISIBILITY_INVALID');$checks[]=['mode'=>$value,'visible'=>$options['laborFeeVisible']];}
 }
 echo json_encode(['site'=>$site,'mode'=>$mode,'result'=>'PASS','config'=>$row,'effective'=>(int)\sys_config('cashier_labor_fee_mode',0),'existingFormSHA256'=>hash('sha256',json_encode($oldForm)),'otherConfigSHA256'=>hash('sha256',json_encode($other)),'rootVisibilityCases'=>$checks,'readOnly'=>true,'businessWrites'=>0],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}
/** PHP7.4兼容：判断数字列表，仅用于删除新增表单项后比较既有表单。 */
function array_is_list_r37(array $a):bool{return array_keys($a)===range(0,count($a)-1)||$a===[];}
