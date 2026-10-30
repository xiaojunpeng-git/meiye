<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../后端代码/app/services/report/Sidebar007UnconsumedV3ReadServices.php';
require_once __DIR__ . '/../../../后端代码/app/services/report/BasicEntitlementReportServices.php';

use app\services\report\Sidebar007UnconsumedV3ReadServices;

/** 注入事实只验证 007 列、原卡项目粒度、授权和缺口，不访问业务库。 */
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject(callable $fn, string $message): void
{
    try { $fn(); } catch (InvalidArgumentException|LogicException $e) { return; }
    throw new RuntimeException($message);
}

$reader = new Sidebar007UnconsumedV3ReadServices();
check(Sidebar007UnconsumedV3ReadServices::codes() === [
    'basic_unconsumed_summary','basic_unconsumed_detail','basic_unconsumed_as_of_detail'], '静态目录代码错误');
check(array_column(array_filter($reader->definitions('basic_unconsumed_detail'),
    static fn(array $f): bool => $f['defaultVisible']), 'label') === [
    '门店','客户','手机','本金','赠金','卡号','卡名称','购买金额','已使用金额','疗程权益金额'], '007 明细十列错误');
$source = static function (string $code): array {
    if ($code === 'internal_unconsumed_summary_007') return ['time_mode'=>'current','complete'=>true,'rows'=>[
        ['row_id'=>'entitlement:store:7','store_id'=>7,'store_name'=>'万象城店',
            'principal_amount'=>100,'gift_amount'=>20,'balance_amount'=>120,'rights_amount'=>300],
    ]];
    check($code === 'internal_unconsumed_components_007', '明细必须读取含已用完项目的专用权威');
    return ['time_mode'=>'current','complete'=>true,'rows'=>[
        ['row_id'=>'entitlement:balance:11','store_id'=>7,'member_id'=>11,'asset_type'=>'储值',
            'store_name'=>'万象城店','customer_name'=>'张三','phone'=>'13000000000',
            'principal_amount'=>100,'gift_amount'=>20],
        ['row_id'=>'entitlement:project:101:501','store_id'=>7,'member_id'=>11,'asset_type'=>'卡内项目',
            'card_holder_id'=>101,'project_detail_id'=>501,'origin_order_id'=>901,'card_store_id'=>7,
            'store_name'=>'万象城店','customer_name'=>'张三','phone'=>'13000000000',
            'card_no'=>'A01','card_name'=>'疗程卡','rights_amount'=>200],
        ['row_id'=>'entitlement:project:101:502','store_id'=>7,'member_id'=>11,'asset_type'=>'卡内项目',
            'card_holder_id'=>101,'project_detail_id'=>502,'origin_order_id'=>901,'card_store_id'=>7,
            'store_name'=>'万象城店','customer_name'=>'张三','phone'=>'13000000000',
            'card_no'=>'A01','card_name'=>'疗程卡','rights_amount'=>100],
        ['row_id'=>'entitlement:project:101:503','store_id'=>7,'member_id'=>11,'asset_type'=>'卡内项目',
            'card_holder_id'=>101,'project_detail_id'=>503,'origin_order_id'=>901,'card_store_id'=>7,
            'store_name'=>'万象城店','customer_name'=>'张三','phone'=>'13000000000',
            'card_no'=>'A01','card_name'=>'疗程卡','remaining_times'=>0,'rights_amount'=>0],
    ]];
};
$amounts = static fn(): array => [501=>['purchase_amount'=>260,'used_amount'=>60],
    502=>['purchase_amount'=>120,'used_amount'=>20],503=>['purchase_amount'=>90,'used_amount'=>90]];

$summary = $reader->read('basic_unconsumed_summary',[7],[], $source);
check(array_column(array_filter($summary['fields'], static fn(array $f): bool => $f['defaultVisible']),
    'label') === ['门店','本金','赠金','余额','疗程权益金额'], '统计表五列错误');
check($summary['rows'][0]['rights_amount'] === 300 && $summary['complete'] && $summary['data_gaps'] === [],
    '统计表必须保留当前权益事实');

$detail = $reader->read('basic_unconsumed_detail',[7],[], $source, $amounts);
check(count($detail['rows']) === 3 && $detail['rows'][0]['row_id'] === 'unconsumed:project:101:501'
    && $detail['rows'][1]['row_id'] === 'unconsumed:project:101:502', '同卡两个原项目须分别保留');
check($detail['rows'][0]['principal_amount'] === 100 && $detail['rows'][1]['principal_amount'] === 100,
    '同会员多项目须按身份关联储值');
check($detail['rows'][0]['purchase_amount'] === 260 && $detail['rows'][0]['used_amount'] === 60
    && $detail['rows'][0]['rights_amount'] === 200 && $detail['complete'], '购买、核销、剩余须独立取事实');
check($detail['rows'][2]['rights_amount'] === 0 && $detail['rows'][2]['used_amount'] === 90,
    '007 含已用完但仍有效的原卡项目组件');
check($detail['sum_fields'] === ['purchase_amount','used_amount'], '重复储值及共享权益不能直接逐行合计');
check(count($detail['field_sources']) === count($detail['fields']), '字段取值来源须覆盖显隐列');

$gap = $reader->read('basic_unconsumed_detail',[7],[], $source,
    static fn(): array => [501=>['purchase_amount'=>260],502=>['purchase_amount'=>120,'used_amount'=>20],
        503=>['purchase_amount'=>90,'used_amount'=>90]]);
check(!$gap['complete'] && $gap['rows'][0]['used_amount'] === null && count($gap['data_gaps']) === 1,
    '缺失真实核销不能以购买减剩余倒推');

// 普通明细保留只有储值的会员，实时明细原人口只显示卡项。
$wallet = static fn(): array => ['time_mode'=>'current','complete'=>true,'rows'=>[
    ['row_id'=>'entitlement:balance:11','store_id'=>7,'member_id'=>11,'asset_type'=>'储值',
        'store_name'=>'万象城店','customer_name'=>'张三','phone'=>'13000000000',
        'principal_amount'=>100,'gift_amount'=>20],
]];
$walletDetail = $reader->read('basic_unconsumed_detail',[7],[], $wallet);
check($walletDetail['complete'] && count($walletDetail['rows']) === 1
    && $walletDetail['rows'][0]['purchase_amount'] === 0, '普通明细需保留仅储值行');
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$walletRealtime = $reader->read('basic_unconsumed_as_of_detail',[7],['as_of_date'=>$today], $wallet);
check($walletRealtime['complete'] && $walletRealtime['rows'] === [], '实时明细不加仅储值人口');
reject(static fn() => $reader->read('basic_unconsumed_as_of_detail',[7],['as_of_date'=>'2026-01-01'], $source),
    '历史实时查询须拒绝');
reject(static fn() => $reader->read('basic_unconsumed_detail',[7],[],
    static fn(): array => ['time_mode'=>'current','complete'=>true,'rows'=>[['row_id'=>'x','store_id'=>8]]]),
    '越权门店行须拒绝');
reject(static fn() => $reader->read('basic_unconsumed_detail',[7],[],
    static fn(): array => ['time_mode'=>'current','complete'=>false,'rows'=>[]]),
    '来源不完整须拒绝');

$storeless = static function (): array { throw new RuntimeException('ENTITLEMENT_HOLDER_STORE_UNRESOLVED'); };
foreach (Sidebar007UnconsumedV3ReadServices::codes() as $code) {
    $result = $reader->read($code, [7], [], $storeless);
    check(!$result['complete'] && $result['rows'] === [] && $result['sum_fields'] === []
        && $result['data_gaps'][0]['code'] === 'ENTITLEMENT_HOLDER_STORE_UNRESOLVED'
    && !empty($result['data_gaps'][0]['label'])
        && count($result['field_sources']) === count($result['fields']),
        '来源门店无凭据时三张未消耗表均留空并报告同一明确缺口');
}
try {
    $reader->read('basic_unconsumed_summary', [7], [],
        static function (): array { throw new RuntimeException('ENTITLEMENT_UNEXPECTED_FAILURE'); });
    throw new RuntimeException('未知未消耗错误被误吞');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'ENTITLEMENT_UNEXPECTED_FAILURE', '未知未消耗错误须抛出');
}

echo "sidebar-007-unconsumed-v3-read-contract PASS\n";
