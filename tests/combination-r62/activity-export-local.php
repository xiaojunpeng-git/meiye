<?php
declare(strict_types=1);
/** Local-only read contract: checks activity scope, refunds, virtual seats and the accepted 16-column template. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\activity\combination\CombinationRecordServices;
use app\services\activity\combination\StorePinkServices;
$app = new think\App('/var/www/html/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao')
    throw new RuntimeException('Local Docker database only');
function verify(bool $ok, string $name): void { if (!$ok) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name."\n"; }
$roots = Db::name('store_pink')->whereIn('id', [160, 162])->select()->toArray();
verify(count($roots) === 2 && strpos((string)$roots[0]['order_id'], 'R61-') === 0
    && strpos((string)$roots[1]['order_id'], 'R61-') === 0, 'uses only fabricated local groups');
Db::startTrans();
try {
    // Temporarily place two fabricated groups under one activity, then roll the change back.
    Db::name('store_pink')->where('id', 162)->update(['cid' => (int)$roots[0]['cid']]);
    $records = (new CombinationRecordServices())->exportActivity((int)$roots[0]['cid']);
    verify(count($records['header']) === 16 && $records['header'][0] === '门店名称'
        && $records['header'][15] === '支付状态', 'accepted column order');
    $groups = array_unique(array_column($records['export'], 'group_no'));
    verify(in_array('160', $groups, true) && in_array('162', $groups, true), 'all groups in activity');
    verify(!in_array('163', $groups, true), 'another activity excluded');
    verify(!array_filter($records['export'], static function ($row) { return $row['member_no'] === '虚拟名额'; }), 'no virtual member label');
    verify(count($records['export']) === count((new CombinationRecordServices())->export(160)['export'])
        + count((new CombinationRecordServices())->export(162)['export']), 'activity covers all real participants');
    foreach ([160, 162] as $rootId) {
        $expected = (new CombinationRecordServices())->export($rootId)['export'];
        $actual = array_values(array_filter($records['export'], static function ($row) use ($rootId) {
            return $row['group_no'] === (string)$rootId;
        }));
        $sameValues = count($actual) === count($expected);
        foreach ($expected as $i => $row) foreach (CombinationRecordServices::KEYS as $key)
            $sameValues = $sameValues && ($actual[$i][$key] ?? null) === ($row[$key] ?? null);
        verify($sameValues, 'activity and group rows have identical 16 values for '.$rootId);
    }
    $refunded = (new CombinationRecordServices())->exportActivity(102);
    verify(count($refunded['export']) === 1 && $refunded['export'][0]['payment_status'] === '已退款',
        'refunded participant retained and virtual seat excluded');
    $filtered = app()->make(StorePinkServices::class)->systemPage(['cid' => (int)$roots[0]['cid'], 'group_id' => 160, 'status' => '', 'data' => '']);
    verify((int)$filtered['count'] === 1 && (int)$filtered['list'][0]['id'] === 160, 'group filter and full total agree');
    $unfiltered = app()->make(StorePinkServices::class)->systemPage(['cid' => '', 'group_id' => '', 'status' => '', 'data' => '']);
    verify((int)$unfiltered['count'] >= 2, 'empty optional filters retain the full list');
    echo "R62 LOCAL ACCEPTANCE COMPLETE\n";
} finally {
    Db::rollback();
}
