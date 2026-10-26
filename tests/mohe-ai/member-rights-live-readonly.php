<?php
/**
 * Local read-only acceptance: no fixtures, business writes or credentials.
 * Run inside the configured backend container; print only assertion results,
 * never member names, IDs, contact fields or full asset projections.
 */
require getcwd().'/vendor/autoload.php';
$app=new think\App();$app->initialize();
use think\facade\Db;
use app\services\query\metric\MemberAnalysisObjectServices;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;

$check=static function(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
};
// Prefer an existing cross-store card holder, then require an active member
// relation. This is a bounded discovery query, not a fabricated test member.
$candidates=Db::name('user_card_holder')->where('is_del',0)->where('store_id','>',0)
    ->fieldRaw('uid,COUNT(DISTINCT store_id) stores')->group('uid')->having('stores > 1')->limit(20)->select()->toArray();
$found=false;
foreach ($candidates as $candidate) {
    $uid=(int)$candidate['uid'];
    $stores=array_map('intval',Db::name('store_user')->where('uid',$uid)->where('status',1)->column('store_id'));
    if (!$stores) continue;
    $scope=['member_authorized'=>true,'store_ids'=>[$stores[0]],'scope_mode'=>'stores','permission_version'=>'r37-readonly'];
    $objects=new MemberAnalysisObjectServices(static function(string $table){return Db::name($table);},static function()use(&$scope){return $scope;});
    try {$objects->selection('member:'.$uid);} catch (RuntimeException $e) {continue;}
    $operator=new CashierV3OperatorScope($stores[0],1,'','0');
    $makeScope=static function(array $ids)use($stores): Scope {
        return new Scope(1,0,$stores[0],'0','',$ids,Scope::MODE_STORES,[],false,'','r37-readonly',[],[],true);
    };
    $reader=new CashierV3MemberDetailQueryServices();
    $one=$reader->read($uid,$operator,$makeScope([$stores[0]]),['tab'=>'assets','status'=>'active']);
    $all=$reader->read($uid,$operator,$makeScope($stores),['tab'=>'assets','status'=>'active']);
    $cardStores=array_unique(array_column($one['cards'],'storeName'));
    if (count($cardStores)<2) continue;
    $check($one['cards']===$all['cards'],'authorised member rights span stores without changing when the operator store set narrows');
    $amount='0.00';$times=0;
    foreach ($one['cards'] as $card) {
        if (($card['statusCode']??'')!=='enabled') continue;
        $amount=bcadd($amount,(string)$card['remainingAmount'],2);
        if (($card['cardRuleType']??'')!=='time') $times+=(int)$card['remainingTimes'];
    }
    $check($amount===$one['summary']['remainingProjectAmount']&&$times===$one['summary']['remainingProjectTimes'],
        'cross-store detail rows reconcile to exact-cent and count summaries');
    $scope['member_authorized']=false;
    try {$objects->selection('member:'.$uid);throw new LogicException('unauthorised member accepted');}
    catch (RuntimeException $e) {$check($e->getMessage()==='AI_MEMBER_PERMISSION_REQUIRED','member permission denial precedes all rights reads');}
    $scope['member_authorized']=true;
    $outside=(int)Db::name('system_store')->whereNotIn('id',$stores)->value('id');
    if ($outside>0) {
        $scope['store_ids']=[$outside];
        try {$objects->selection('member:'.$uid);throw new LogicException('unrelated store accepted');}
        catch (RuntimeException $e) {$check($e->getMessage()==='AI_OBJECT_BINDING_UNAVAILABLE','unrelated store cannot open the member');}
    }
    $found=true;break;
}
$check($found,'existing cross-store sample was verified without business writes');
