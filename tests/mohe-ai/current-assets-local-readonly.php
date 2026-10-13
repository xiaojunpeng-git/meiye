<?php
declare(strict_types=1);

/** Read-only local-copy check; deliberately refuses an unexpected database. */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();

use app\services\query\metric\MemberCurrentAssetMetricReadServices;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use think\facade\Db;

if ((Db::query('SELECT DATABASE() database_name')[0]['database_name']??null)!=='ruihao') {
    throw new RuntimeException('LOCAL_DATABASE_IDENTITY_MISMATCH');
}
$row=Db::name('store_user')->alias('su')->join('user u','u.uid=su.uid')
    ->where('su.status',1)->where('u.status',1)->where('u.is_del',0)
    ->where('u.balance_version','>',0)->where('su.store_id','>',0)
    ->field('su.uid,su.store_id')->order('su.uid','asc')->find();
if (!is_array($row)) throw new RuntimeException('NO_AUTHORIZED_LOCAL_MEMBER');
$id=(int)$row['uid'];$store=(int)$row['store_id'];
$codes=['member_account_balance','member_remaining_project_amount','member_total_assets'];
$values=(new MemberCurrentAssetMetricReadServices())->values('0',[$store],[$id],$codes)[$id]??null;
if (!is_array($values) || array_keys($values)!==$codes
    ||$values['member_total_assets']!==$values['member_account_balance']+$values['member_remaining_project_amount']) {
    throw new RuntimeException('ASSET_DERIVATION_MISMATCH');
}
$balance=(new CashierV3MemberBalanceProvider())->readAiBalanceCents([$id],[$store]);
if ($values['member_account_balance']!==($balance[$id]??null)) {
    throw new RuntimeException('BALANCE_AUTHORITY_MISMATCH');
}
try {
    (new CashierV3MemberBalanceProvider())->readAiBalanceCents([$id],[0]);
    throw new RuntimeException('INVALID_SCOPE_WAS_ACCEPTED');
} catch (Throwable $error) {
    if ($error->getMessage()==='INVALID_SCOPE_WAS_ACCEPTED') throw $error;
}
echo "PASS local current assets: registered cents match cashier balance and additive total\n";

// A tiny store keeps the integration probe bounded while exercising the
// actual authorized-member Reader and both count/list response forms.
$tiny=Db::name('store_user')->alias('su')->join('user u','u.uid=su.uid')
    ->where('su.status',1)->where('u.status',1)->where('u.is_del',0)
    ->where('su.store_id','>',0)->group('su.store_id')
    ->fieldRaw('su.store_id,COUNT(DISTINCT su.uid) member_count')
    ->having('COUNT(DISTINCT su.uid) BETWEEN 1 AND 5')
    ->order('member_count','asc')->find();
if (!is_array($tiny)) throw new RuntimeException('NO_BOUNDED_LOCAL_STORE');
$condition=['subject'=>'member','relation'=>'all','conditions'=>[
    ['metric_code'=>'member_account_balance','operator'=>'gte','value'=>0],
    ['metric_code'=>'member_remaining_project_amount','operator'=>'gte','value'=>0],
    ['metric_code'=>'member_total_assets','operator'=>'gte','value'=>0],
]];
$reader=new RegisteredMetricReadServices();
$storeIds=[(int)$tiny['store_id']];$range=['start'=>date('Y-m-d'),'end'=>date('Y-m-d')];
$count=$reader->conditionMembers('0',$storeIds,$range,$condition,0);
$list=$reader->conditionMembers('0',$storeIds,$range,$condition,100);
if ($count['count']!==$list['count'] || $list['count']!==count($list['rows'])
    ||$list['count']>(int)$tiny['member_count']) throw new RuntimeException('CONDITION_COUNT_LIST_DRIFT');
foreach ($list['rows'] as $item) {
    $m=$item['metrics'];
    if (($m['member_total_assets']??null)!==($m['member_account_balance']??-1)+($m['member_remaining_project_amount']??-1)) {
        throw new RuntimeException('CONDITION_ASSET_VALUE_DRIFT');
    }
}
echo "PASS local mixed asset conditions: exact count and list agree\n";
$ageCondition=['metric_code'=>'member_days_since_last_visit','operator'=>'gt','value'=>90];
$ageAndAssets=['subject'=>'member','relation'=>'all','conditions'=>[
    $ageCondition,['metric_code'=>'member_total_assets','operator'=>'gte','value'=>50000],
]];
$ageAndCount=$reader->conditionMembers('0',$storeIds,$range,$ageAndAssets,0);
$ageAndList=$reader->conditionMembers('0',$storeIds,$range,$ageAndAssets,100);
if ($ageAndCount['count']!==$ageAndList['count'] || $ageAndList['count']!==count($ageAndList['rows'])) {
    throw new RuntimeException('AGE_ASSET_COUNT_LIST_DRIFT');
}
$ageOrAssets=$ageAndAssets;$ageOrAssets['relation']='any';
$ageOrCount=$reader->conditionMembers('0',$storeIds,$range,$ageOrAssets,0);
$ageOrList=$reader->conditionMembers('0',$storeIds,$range,$ageOrAssets,100);
if ($ageOrCount['count']!==$ageOrList['count'] || $ageOrList['count']!==count($ageOrList['rows'])
    ||$ageOrCount['count']<$ageAndCount['count']) throw new RuntimeException('AGE_ASSET_OR_DRIFT');
echo "PASS local age and current assets: AND/OR count and list agree\n";
$fiveCodes=['member_days_since_last_visit','member_account_balance',
    'member_remaining_project_amount','member_total_assets','member_service_visit_count'];
$five=['subject'=>'member','relation'=>'any','conditions'=>[
    $ageCondition,['metric_code'=>'member_account_balance','operator'=>'gte','value'=>0],
    ['metric_code'=>'member_remaining_project_amount','operator'=>'gt','value'=>0],
    ['metric_code'=>'member_total_assets','operator'=>'gte','value'=>50000],
    ['metric_code'=>'member_service_visit_count','operator'=>'gte','value'=>2],
]];
$fiveResult=$reader->conditionMembers('0',$storeIds,$range,$five,100);
if ($fiveResult['count']!==count($fiveResult['rows']) || $fiveResult['count']<1) {
    throw new RuntimeException('FIVE_CONDITION_POPULATION_INVALID');
}
foreach ($fiveResult['rows'] as $item) {
    if (array_keys($item['metrics'])!==$fiveCodes) throw new RuntimeException('FIVE_CONDITION_METRIC_LOSS');
    foreach ($item['metrics'] as $code=>$value) {
        if (!is_int($value) && !($code==='member_days_since_last_visit' && $value===null)) {
            throw new RuntimeException('FIVE_CONDITION_METRIC_VALUE_INVALID');
        }
    }
}
echo "PASS local five-condition member result: every registered value retained\n";
