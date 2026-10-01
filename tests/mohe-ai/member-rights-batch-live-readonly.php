<?php
/**
 * Local read-only regression for AI's bounded member-rights batch path.
 * Existing authorized members only; prints no identity or balance values.
 */
require getcwd().'/vendor/autoload.php';
$app=new think\App();$app->initialize();

use think\facade\Db;
use app\services\query\metric\MemberAnalysisObjectServices;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;

$store=(int)Db::name('system_store')->where('is_del',0)->order('id','asc')->value('id');
if ($store<1) throw new RuntimeException('NO_EXISTING_STORE');
$ids=array_map('intval',Db::name('store_user')->alias('su')->join('user u','u.uid=su.uid')
    ->where('su.store_id',$store)->where('su.status',1)->where('u.status',1)->where('u.is_del',0)
    ->order('su.uid','asc')->limit(20)->column('su.uid'));
$ids=array_values(array_unique($ids));
if (count($ids)<2) throw new RuntimeException('NO_EXISTING_MEMBER_SET');

$authority=['member_authorized'=>true,'store_ids'=>[$store],'scope_mode'=>'stores','permission_version'=>'r54-batch-readonly'];
$objects=new MemberAnalysisObjectServices(static function(string $table){return Db::name($table);},static function()use(&$authority){return $authority;});
$refs=array_map(static fn(int $id):string=>'member:'.$id,$ids);
$selections=$objects->selections($refs);
if (count($selections)!==count($ids)) throw new RuntimeException('BATCH_SELECTION_MISMATCH');
$operator=new CashierV3OperatorScope($store,1,'','0');
$scope=new Scope(1,0,$store,'0','',[$store],Scope::MODE_STORES,[],false,'','r54-batch-readonly',[],[],true);
$reader=new CashierV3MemberDetailQueryServices();
$started=microtime(true);
$checkpoints=0;
$batch=$reader->readAiRightsSet($ids,$operator,$scope,static function()use(&$checkpoints): void {$checkpoints++;});
$batchMs=(int)round((microtime(true)-$started)*1000);
if ($checkpoints!==count($ids)+count(array_chunk($ids,100))) throw new RuntimeException('BATCH_CHECKPOINT_MISMATCH');
$started=microtime(true);
foreach ($ids as $id) {
    $single=$reader->readAiRights($id,$operator,$scope);
    if (($single['member']??null)!==($batch[$id]['member']??null)
        ||($single['summary']??null)!==($batch[$id]['summary']??null)
        ||($single['cards']??null)!==($batch[$id]['cards']??null)) {
        throw new RuntimeException('BATCH_RIGHTS_MISMATCH');
    }
}
$singleMs=(int)round((microtime(true)-$started)*1000);
echo 'PASS batch member rights match single reads; count='.count($ids).'; batch_ms='.$batchMs.'; single_ms='.$singleMs.PHP_EOL;
