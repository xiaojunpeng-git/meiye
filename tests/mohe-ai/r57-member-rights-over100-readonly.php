<?php
declare(strict_types=1);

/** Read-only regression: a verified set larger than the old 100-person cap. */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();

use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\ai\presentation\AiMemberDetailAnswerRenderer;
use app\services\ai\presentation\AiMemberRightsExportProjection;
use app\services\ai\context\MemberDetailContinuationResolver;

$store=1;
$ids=array_values(array_unique(array_map('intval',Db::name('store_user')->alias('su')
    ->join('user u','u.uid=su.uid')->where('su.store_id',$store)->where('su.status',1)
    ->where('u.status',1)->where('u.is_del',0)->order('su.uid','asc')->limit(120)->column('su.uid'))));
if (count($ids)!==120) throw new RuntimeException('NO_EXISTING_120_MEMBER_SET');
$operator=new CashierV3OperatorScope($store,1,'','0');
$scope=new Scope(1,0,$store,'0','',[$store],Scope::MODE_STORES,[],false,'','r57-over100-readonly',[],[],true);
$started=microtime(true);
$rights=(new CashierV3MemberDetailQueryServices())->readAiRightsSet($ids,$operator,$scope);
if (count($rights)!==120) throw new RuntimeException('RIGHTS_SET_TRUNCATED');
$details=[];
foreach ($ids as $id) {
    $detail=$rights[$id]??null;
    if (!is_array($detail)) throw new RuntimeException('RIGHTS_ID_MISSING');
    $details[]=['detail'=>$detail,'label'=>$detail['member']['name'],'selection_ref'=>'member:'.$id];
}
$answer=(new AiMemberDetailAnswerRenderer())->renderSet($details,'summary');
$export=AiMemberRightsExportProjection::capture($details,'summary',[],$answer);
if (count($export['member_refs'])!==120 || count($answer['table']['rows'])!==120) {
    throw new RuntimeException('RIGHTS_EXPORT_TRUNCATED');
}
$view=['query'=>['query_shape'=>'condition_list','condition_set'=>['subject'=>'member']],
    'results'=>[['count'=>120,'has_more'=>false,'rows'=>array_map(static function(array $member): array {
        return ['member_id'=>(int)$member['detail']['member']['memberId'],
            'member_name'=>$member['label']];
    },$details)]]];
$resolved=(new MemberDetailContinuationResolver())->resolve($view['query'],$view,['target'=>'set','view'=>'summary']);
if (count($resolved['members'])!==120) throw new RuntimeException('RIGHTS_REFERENCE_TRUNCATED');
echo 'PASS rights preview/export complete: members=120 seconds='.round(microtime(true)-$started,2).PHP_EOL;
