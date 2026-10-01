<?php
declare(strict_types=1);

/** Local rollback-only probe: a stale snapshot must never hide a qualified member. */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();

use app\services\query\metric\AiMemberRightsAmountIndex;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

$tenant='0';$threshold=5000000;
$index=new AiMemberRightsAmountIndex();
if (!$index->ready($tenant)) throw new RuntimeException('AI_RIGHTS_INDEX_NOT_READY');
$stores=array_map('intval',Db::name('system_store')->column('id'));
$snapshot=Db::name('ai_member_rights_amount_snapshot')->where('tenant_id',$tenant)
    ->where('amount_cents','>',$threshold)->order('member_id','asc')->find();
if (!$snapshot) throw new RuntimeException('NO_QUALIFIED_LOCAL_MEMBER');
$id=(int)$snapshot['member_id'];$amount=(int)$snapshot['amount_cents'];
$assert=function(string $case)use($index,$tenant,$stores,$threshold,$id,$amount):void {
    $result=$index->query($tenant,$stores,'gt',$threshold,RegisteredMetricReadServices::AI_DETAIL_LIMIT);
    $rows=array_column($result['rows'],'metrics','member_id');
    if (($rows[$id]['member_remaining_project_amount']??null)!==$amount) throw new RuntimeException($case.'_FILTER_DRIFT');
    $summary=$index->summaryForMembers($tenant,$stores,[$id]);
    if (($summary[$id]['amount_cents']??null)!==$amount) throw new RuntimeException($case.'_DETAIL_DRIFT');
};
foreach (['source_write','time_transition'] as $case) {
    Db::startTrans();
    try {
        Db::name('ai_member_rights_amount_snapshot')->where('tenant_id',$tenant)->where('member_id',$id)
            ->update(['amount_cents'=>0,'next_transition_at'=>$case==='time_transition'?time()-1:(int)$snapshot['next_transition_at']]);
        if ($case==='source_write') Db::execute('CALL eb_ai_rights_mark_member(?)',[$id]);
        $assert($case);
    } finally { Db::rollback(); }
}
$after=Db::name('ai_member_rights_amount_snapshot')->where('tenant_id',$tenant)->where('member_id',$id)->find();
if ((int)$after['amount_cents']!==$amount) throw new RuntimeException('ROLLBACK_FAILED');
echo "R57_RIGHTS_OVERLAY_PASS\n";
