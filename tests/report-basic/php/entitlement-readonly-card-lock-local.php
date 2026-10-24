<?php
declare(strict_types=1);
/** R51 local MySQL regression for the single shared-card read-lock opt-out.
 * Run with R51_LOCAL_TEST=1 from mohe-app via stdin. All native facts are read
 * only; session-temporary member tables bound Host population to one real user.
 * No issuance fixtures, checkout commands, production access or persistent DML.
 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope as Operator;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices as Member;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices as Rules;
use app\services\report\BasicReportHostServices as Host;
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL); exit(1); });
$cfg=config('database.connections.mysql');
if (getenv('R51_LOCAL_TEST')!=='1' || !in_array($cfg['hostname'],['mysql','mohe-mysql'],true)
    || (Db::query('SELECT DATABASE() db')[0]['db']??'')!=='ruihao') throw new RuntimeException('LOCAL_MYSQL_ONLY');
$checks=0;
function r51Ro(bool $ok,string $label): void { global $checks; ++$checks; if (!$ok) throw new RuntimeException($label); }
// Product/main-task verified native fixture, never manufactured if missing.
$uid=1100207; $store=55;
$state=(array)Db::name('cashier_v3_card_rule_state')->where('id',11595)->where('member_id',$uid)->where('rule_type','choice_count')->find();
r51Ro($state && (int)$state['shared_remaining_times']>0,'real active shared fixture required');
$holder=(array)Db::name('user_card_holder')->where('id',$state['card_holder_id'])->find();
$order=(array)Db::name('store_order')->where('id',$holder['oid'])->find(); $order['tenant_id']='0';
$component=(array)Db::name('cashier_v3_card_rule_component')->where('rule_state_id',$state['id'])->order('id')->find();
$detail=(array)Db::name('store_order_cart_info')->where('id',$component['legacy_detail_id'])->find();
r51Ro((int)$holder['uid']===$uid && (int)$holder['store_id']===$store && $detail,'native holder/order/component relation');
$sourceHash=static function () use ($uid,$holder,$order): string {
    $rows=[];
    foreach (['user_card_holder'=>['uid',$uid],'store_order'=>['id',$order['id']],
        'store_order_cart_info'=>['oid',$order['id']],'store_debt'=>['order_id',$order['id']],
        'cashier_v3_card_rule_state'=>['card_holder_id',$holder['id']],
        'cashier_v3_card_rule_component'=>['card_holder_id',$holder['id']]] as $table=>$key)
        $rows[$table]=Db::name($table)->where($key[0],$key[1])->order('id')->select()->toArray();
    return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
};
$before=$sourceHash(); $temporary=[]; $sql=[]; $capture=false;
Db::listen(static function ($query) use (&$sql,&$capture): void { if ($capture) $sql[]=(string)$query; });
$member=new Member(); $rules=new Rules();
$scope=new Scope(1,0,$store,'0','',[$store],'stores',[],false,'','r51-readonly',[],[],true);
$operator=new Operator($store,1,'','0');
$read=static function () use ($member,$uid,$operator,$scope): array { return $member->readAiRightsSet([$uid],$operator,$scope); };
try {
    foreach (['user','store_user'] as $table) {
        $rows=Db::name($table)->where('uid',$uid)->select()->toArray();
        $ddl=Db::query('SHOW CREATE TABLE eb_'.$table)[0]['Create Table'];
        Db::execute(str_replace('CREATE TABLE `eb_'.$table.'`','CREATE TEMPORARY TABLE `eb_'.$table.'`',$ddl));
        $temporary[]=$table;
        if ($rows) Db::name($table)->insertAll($rows);
    }
    $ordinary=$read(); $cap=$rules->debtLimitForDetail($detail,$order,false);
    $cards=array_column($ordinary[$uid]['cards'],null,'id');
    r51Ro(isset($cards[(string)$holder['id']]),'shared card present in public member reader');
    r51Ro($cards[(string)$holder['id']]['cardRuleType']==='choice_count','shared branch actually exercised');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    $context=Host::context([$store],'stores',1,'platform',false,0,'basic_customer_entitlements');
    $payload=['pageCode'=>'basic_customer_entitlements','start_date'=>$today,'end_date'=>$today,'as_of_date'=>$today,'page'=>1,'limit'=>20];
    $normalHost=Host::query($context,$payload);
    // Default checkout lock reproduces the old call's 1792 in READ ONLY.
    Db::execute('SET SESSION TRANSACTION READ ONLY'); Db::startTrans();
    try {
        $rejected=false;
        try { $rules->debtLimitForDetail($detail,$order); }
        catch (Throwable $e) { $rejected=strpos($e->getMessage(),'1792')!==false || strpos($e->getMessage(),'25006')!==false; }
        r51Ro($rejected,'default checkout lock remains forbidden in readonly transaction');
    } finally { Db::rollback(); }
    $capture=true; Db::startTrans();
    try {
        r51Ro((int)Db::query('SELECT @@session.tx_read_only ro')[0]['ro']===1,'real MySQL READ ONLY session');
        $readonly=$read();
        r51Ro($readonly[$uid]['cards']===$ordinary[$uid]['cards'],'all card/project money and times equal ordinary read');
        r51Ro($rules->debtLimitForDetail($detail,$order,false)===$cap,'debt cap equals ordinary read');
        $host=Host::query($context,$payload);
        foreach (['rows','summaries','pagination'] as $key) r51Ro($host[$key]===$normalHost[$key],'real Host parity '.$key);
        r51Ro($host['pagination']['limit']===20 && count($host['rows'])<=20,'Host query limit20');
    } finally { Db::rollback(); $capture=false; Db::execute('SET SESSION TRANSACTION READ WRITE'); }
    r51Ro(count($sql)>10,'SQL listener observed real database reads');
    r51Ro(!preg_grep('/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i',$sql),'readonly projection emits no locking SELECT');
    r51Ro(!preg_grep('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$sql),'readonly projection emits no DML/DDL');
} finally {
    if (Db::connect()->getPdo()->inTransaction()) Db::rollback();
    Db::execute('SET SESSION TRANSACTION READ WRITE');
    foreach (array_reverse($temporary) as $table) Db::execute('DROP TEMPORARY TABLE IF EXISTS eb_'.$table);
}
r51Ro($sourceHash()===$before,'native holder/order/debt/state/component hashes unchanged');
echo json_encode(['pass'=>true,'checks'=>$checks,'rule_state_id'=>11595,'sql_count'=>count($sql),
    'shared_remaining_times'=>(int)$state['shared_remaining_times'],'debt_cap'=>$cap,
    'source_sha256_before'=>$before,'source_sha256_after'=>$sourceHash(),
    'combination_live_fixture'=>'none_not_fabricated','persistent_writes'=>false,'remote_access'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
