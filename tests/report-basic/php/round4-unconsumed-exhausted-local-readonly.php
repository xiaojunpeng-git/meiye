<?php
/** 本地瑞昊只读抽样：已用完原卡项目能否进入007专用当前权益读取，且统计SQL热点。 */
declare(strict_types=1);

$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root.'/vendor/autoload.php';
(new think\App($root.'/'))->initialize();

use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;

$connection = config('database.connections.mysql');
if (!in_array($connection['hostname'] ?? '', ['mysql','mohe-mysql'], true))
    throw new RuntimeException('Local database only');
Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
Db::execute('SET SESSION TRANSACTION READ ONLY');
$store = (int)(getenv('REPORT_TEST_STORE') ?: 105);
$candidate = Db::name('store_order_cart_info')->alias('ci')
    ->join('store_order o', 'o.id=ci.oid AND o.uid=ci.uid')
    ->join('user_card_holder h', 'h.oid=o.id AND h.uid=ci.uid')
    ->join('user u', 'u.uid=h.uid')
    ->join('store_user su', 'su.uid=u.uid AND su.store_id=u.belong_store_id')
    ->where('u.belong_store_id', $store)->where('u.status', 1)->where('u.is_del', 0)
    ->where('su.status', 1)->where('h.is_del', 0)
    ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_user_del', 0)
    ->where('o.is_system_del', 0)->where('o.refund_status', 0)
    ->where('ci.cart_type', 2)->where('ci.product_type', 6)
    ->where('ci.write_times', '>', 0)->where('ci.write_surplus_times', 0)
    ->field('h.uid,ci.id')->order('ci.id asc')->find();
if (!$candidate) {
    echo json_encode(['local_only'=>true,'business_writes'=>0,'store_id'=>$store,
        'candidate_found'=>false], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}
$member = (int)$candidate['uid']; $component = (int)$candidate['id'];
$tenant = CashierV3ScopeResolver::TENANT_SCOPE_ID;
$scope = new CashierV3DataScopeContext(999901, 0, $store, $tenant, '', [$store], 'stores',
    [], false, '', '', [], [], true);
$operator = new CashierV3OperatorScope($store, 999901, '', $tenant);
$sql = [];
Db::listen(static function ($query, $seconds) use (&$sql): void {
    $statement = strtolower((string)$query);
    $table = 'other';
    foreach (['cashier_v3_debt_authority','cashier_v3_card_rule','store_order_cart_info',
        'cashier_v3_card_purchase_receipt','user_card_holder'] as $name) {
        if (strpos($statement, $name) !== false) { $table = $name; break; }
    }
    $sql[] = ['table'=>$table,'ms'=>(int)round((float)$seconds*1000)];
});
$reader = new CashierV3MemberDetailQueryServices();
$started = microtime(true);
$active = $reader->readAiRightsSet([$member], $operator, $scope);
$all = $reader->readAiRightsSet([$member], $operator, $scope, null, true);
$visible = static function (array $set, int $member, int $component): bool {
    foreach ($set[$member]['cards'] ?? [] as $card) {
        if (($card['statusCode'] ?? '') !== 'enabled') continue;
        foreach ($card['projects'] ?? [] as $project) {
            if ((int)($project['id'] ?? 0) === $component && (int)($project['remainingTimes'] ?? -1) === 0)
                return true;
        }
    }
    return false;
};
$counts = [];
foreach ($sql as $item) {
    $key = $item['table'];
    if (!isset($counts[$key])) $counts[$key] = ['queries'=>0,'ms'=>0];
    ++$counts[$key]['queries']; $counts[$key]['ms'] += $item['ms'];
}
echo json_encode(['local_only'=>true,'business_writes'=>0,'store_id'=>$store,
    'candidate_found'=>true,'active_excludes'=>!$visible($active,$member,$component),
    'report_all_includes'=>$visible($all,$member,$component),
    'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
    'query_groups'=>$counts], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), PHP_EOL;
Db::execute('SET SESSION TRANSACTION READ WRITE');
