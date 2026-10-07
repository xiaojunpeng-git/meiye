<?php
declare(strict_types=1);

/**
 * Read-only R61 performance probe for the existing authorized member-summary
 * path. It prints counts/timing only: no member identity or rights leave the
 * local process, and a full-card sample cross-check protects amount parity.
 */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();

use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;

$stores=array_map('intval',Db::name('system_store')->column('id'));
if ($stores===[]) throw new RuntimeException('NO_LOCAL_STORES');
$ids=array_map('intval',Db::name('ai_member_rights_amount_snapshot')->alias('s')
    ->join('user u','u.uid=s.member_id')
    ->where('s.tenant_id','0')->where('u.status',1)->where('u.is_del',0)
    ->whereIn('s.member_id',static function($query)use($stores):void {
        $query->name('store_user')->where('status',1)->whereIn('store_id',$stores)->field('uid');
    })->order('s.member_id','asc')->limit(205)->column('s.member_id'));
if (count($ids)<100) throw new RuntimeException('INSUFFICIENT_LOCAL_SAMPLE');

$operator=new CashierV3OperatorScope(1,1,'','0');
$scope=new CashierV3DataScopeContext(1,0,1,'0','',$stores,
    CashierV3DataScopeContext::MODE_STORES,[],false,'','r61-summary-probe',[],[],true);
$reader=new CashierV3MemberDetailQueryServices();
$started=microtime(true);
$summaries=$reader->readAiRightsSummarySet($ids,$operator,$scope);
$elapsed=round(microtime(true)-$started,3);
if (count($summaries)!==count($ids)) throw new RuntimeException('SUMMARY_SET_INCOMPLETE');
$indexRows=Db::name('ai_member_rights_amount_snapshot')->where('tenant_id','0')
    ->whereIn('member_id',$ids)
    ->field('member_id,amount_cents,remaining_project_times,processed_version,next_transition_at,calculated_at')
    ->select()->toArray();
$indexById=[];foreach ($indexRows as $row) $indexById[(int)$row['member_id']]=$row;
$dirty=Db::name('ai_member_rights_amount_dirty')->whereIn('member_id',$ids)->column('version','member_id');
$indexMismatches=0;$markedFreshMismatches=0;$now=time();$mismatchBeforeOct5=0;$matchBeforeOct5=0;
$cachedGreater=0;$cachedLess=0;$amountOnly=0;$timesOnly=0;
foreach ($ids as $id) {
    $cached=$indexById[$id]??null;
    if (!$cached) continue;
    $liveCents=(int)bcmul((string)$summaries[$id]['summary']['remainingProjectAmount'],'100',0);
    $liveTimes=(int)$summaries[$id]['summary']['remainingProjectTimes'];
    if ((int)$cached['amount_cents']===$liveCents
        &&(int)$cached['remaining_project_times']===$liveTimes) {
        if ((int)$cached['calculated_at']<strtotime('2026-10-05 00:00:00')) ++$matchBeforeOct5;
        continue;
    }
    ++$indexMismatches;
    if ((int)$cached['amount_cents']>$liveCents) ++$cachedGreater;
    if ((int)$cached['amount_cents']<$liveCents) ++$cachedLess;
    if ((int)$cached['amount_cents']!==$liveCents && (int)$cached['remaining_project_times']===$liveTimes) ++$amountOnly;
    if ((int)$cached['amount_cents']===$liveCents && (int)$cached['remaining_project_times']!==$liveTimes) ++$timesOnly;
    if ((int)$cached['calculated_at']<strtotime('2026-10-05 00:00:00')) ++$mismatchBeforeOct5;
    if ((int)$cached['processed_version']===(int)($dirty[$id]??0)
        &&(int)$cached['next_transition_at']>$now) ++$markedFreshMismatches;
}
$sample=array_slice($ids,0,3);
$full=$reader->readAiRightsSet($sample,$operator,$scope);
foreach ($sample as $id) {
    foreach (['summary','member'] as $section) {
        $fast=(array)($summaries[$id][$section]??[]);
        $slow=(array)($full[$id][$section]??[]);
        $changed=[];
        foreach (array_unique(array_merge(array_keys($fast),array_keys($slow))) as $key) {
            if (($fast[$key]??null)!==($slow[$key]??null)) $changed[]=$key;
        }
        if ($changed!==[]) {
            throw new RuntimeException('SUMMARY_PARITY_MISMATCH_'.$section.'_'.implode('_',$changed));
        }
    }
}
echo json_encode(['members'=>count($ids),'summary_seconds'=>$elapsed,
    'full_sample_checked'=>count($sample),'index_mismatches'=>$indexMismatches,
    'marked_fresh_mismatches'=>$markedFreshMismatches,'mismatch_before_oct5'=>$mismatchBeforeOct5,
    'match_before_oct5'=>$matchBeforeOct5,'cached_greater'=>$cachedGreater,'cached_less'=>$cachedLess,
    'amount_only'=>$amountOnly,'times_only'=>$timesOnly,'status'=>'PASS']),PHP_EOL;
