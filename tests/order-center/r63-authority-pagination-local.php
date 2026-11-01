<?php
/** 本地数据库只读对照：优化分页与原联合查询必须返回相同身份、时间、总数。 */
$root = getenv('R63_LOCAL_BACKEND') ?: dirname(__DIR__, 2) . '/后端代码';
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
if (Db::query('SELECT DATABASE() AS db')[0]['db'] !== 'ruihao'
    || !in_array(config('database.connections.mysql.hostname'), ['mysql','mohe-mysql','127.0.0.1','localhost'], true)) {
    throw new RuntimeException('local development database required');
}
$reader = new CashierV3SalesOrderQueryServices();
$invoke = static function ($name, ...$args) use ($reader) {
    $method = new ReflectionMethod($reader, $name);
    $method->setAccessible(true);
    return $method->invoke($reader, ...$args);
};
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $tenant = (string)Db::name('cashier_v3_sales_order')->value('tenant_id');
    $operator = new CashierV3OperatorScope(1, 1, '', $tenant);
    foreach (getenv('R63_RECORDS_ONLY') ? [] : [null, [1]] as $stores) {
        $scope = new CashierV3DataScopeContext(1,1,1,$tenant,'',$stores,
            $stores === null ? 'all' : 'stores', [], true, 'local-test', '1', ['cashier.v3.order_center'], []);
        $criteria = $invoke('listCriteria', ['dateFrom'=>'2026-10-01','dateTo'=>'2026-10-31','status'=>'normal'], $operator, $scope, false);
        $criteria = array_replace($criteria, ['queryCutoffTimestamp'=>time(),'snapshotMaxOrderId'=>PHP_INT_MAX,
            'afterPayTime'=>null,'afterOrderId'=>null]);
        $base = $invoke('authorityOrderQuery', $criteria);
        $start = microtime(true);
        $expectedTotal = (int)(clone $base)->count('o.id');
        $actualTotal = $invoke('authorityOrderTotal', $criteria, $base);
        if ($expectedTotal !== $actualTotal) throw new RuntimeException("total mismatch: $expectedTotal != $actualTotal");
        foreach ([1,2] as $page) {
            $criteria['page'] = $page;
            $expected = (clone $base)->field('o.id,o.order_id,o.settled_at')->order('o.settled_at','desc')->order('o.id','desc')->page($page,20)->select()->toArray();
            $actual = $invoke('authorityNormalOffsetRows', $criteria);
            $normalize = static function ($rows) { return array_map(static function ($r) {
                return [(string)$r['id'],(string)$r['order_id'],(string)$r['settled_at']];
            }, $rows); };
            if ($normalize($expected) !== $normalize($actual)) throw new RuntimeException('page identity mismatch: ' . $page);
        }
        echo json_encode(['stores'=>$stores,'total'=>$actualTotal,'pages'=>[1,2],'parity'=>'PASS','seconds'=>round(microtime(true)-$start,3)]) . "\n";
    }
    // 直接调用同一个记录 Reader，测量完整月度而非空的当天查询。
    $records = new app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices();
    $allScope = new CashierV3DataScopeContext(1,1,1,$tenant,'',null,'all',[],true,'local-test','1',['cashier.v3.order_center'],[]);
    foreach (['recharge','refund','service','gift','card_operation'] as $type) {
        $start = microtime(true);
        $result = $records->queryRecords(['recordType'=>$type,'businessDateFrom'=>'2026-10-01',
            'businessDateTo'=>'2026-10-31','page'=>1,'pageSize'=>20], $operator, $allScope);
        echo json_encode(['type'=>$type,'total'=>$result['total'],'returned'=>count($result['records']),
            'seconds'=>round(microtime(true)-$start,3)]) . "\n";
    }
} finally { Db::rollback(); }
