<?php
declare(strict_types=1);

$backendRoot = getenv('CASHIER_V3_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backendRoot . '/app/services/report/StoreReportGuestDisplayServices.php';
require $backendRoot . '/app/services/report/StoreReportResultRowFilterServices.php';

use app\services\report\StoreReportResultRowFilterServices;

function checkResultRowFilter(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

$columns = [
    ['key'=>'month','filter'=>['type'=>'month','operators'=>['eq']]],
    ['key'=>'store_summary','filter'=>['type'=>'text','operators'=>['contains']]],
    ['key'=>'sale_amount'],
    ['key'=>'hidden_id'],
];
$declared = StoreReportResultRowFilterServices::declareDimensions([
    ['key'=>'company_name','fixed'=>'left'], ['key'=>'business_date'],
    ['key'=>'sale_amount','summable'=>true], ['key'=>'serial_no','fixed'=>'left'],
    ['key'=>'manual_note','manual_input'=>['value_type'=>'text']],
], ['company_name','business_date','sale_amount','serial_no','manual_note']);
checkResultRowFilter(array_column(array_filter($declared, static fn(array $column): bool => !empty($column['filter'])), 'key') === ['company_name','business_date'], 'only declared static dimensions become filters');
$rows = [
    ['month'=>'2026-09','store_summary'=>'扬州甲店','sale_amount'=>'0','hidden_id'=>1],
    ['month'=>'2026-10','store_summary'=>'扬州乙店','sale_amount'=>'2086','hidden_id'=>2],
    ['month'=>'2026-10','store_summary'=>'宣城丙店','sale_amount'=>'-5','hidden_id'=>3],
];
$filter = static function (array $conditions) use ($rows, $columns): array {
    return StoreReportResultRowFilterServices::filter($rows, $columns, json_encode($conditions, JSON_UNESCAPED_UNICODE));
};
$condition = static function (string $key, string $op, $value = ''): array {
    return ['key'=>$key,'op'=>$op,'value'=>$value];
};
checkResultRowFilter($filter([]) === $rows, 'empty filter preserves all already-calculated rows');
checkResultRowFilter(array_column($filter([$condition('month','eq','2026-10')]), 'hidden_id') === [2,3], 'month filter keeps row values');
checkResultRowFilter(array_column($filter([$condition('store_summary','contains','扬州')]), 'hidden_id') === [1,2], 'text contains uses displayed column');
checkResultRowFilter(array_column($filter([$condition('month','eq','2026-10'), $condition('store_summary','contains','扬州')]), 'hidden_id') === [2], 'conditions combine with AND');
checkResultRowFilter($rows[1]['sale_amount'] === '2086', 'source rows remain unchanged');

// Guest display and fixed-field filtering must agree without changing source rows.
$guestColumns = StoreReportResultRowFilterServices::declareDimensions([
    ['key'=>'member_name'], ['key'=>'customer_name'],
], ['member_name','customer_name']);
$guestRows = [
    ['member_id'=>0,'member_name'=>'','customer_name'=>'旧游客姓名','amount'=>100],
    ['member_id'=>'0','member_name'=>'历史姓名','customer_name'=>'','amount'=>200],
    ['member_id'=>42,'member_name'=>'张女士','customer_name'=>'张女士','amount'=>300],
    ['member_id'=>null,'member_name'=>'','customer_name'=>'','amount'=>400],
    ['member_name'=>'','customer_name'=>'','amount'=>500],
];
foreach (['member_name','customer_name'] as $nameKey) {
    $matchedGuests = StoreReportResultRowFilterServices::filter($guestRows, $guestColumns,
        json_encode([$condition($nameKey,'contains','游客')], JSON_UNESCAPED_UNICODE));
    checkResultRowFilter($matchedGuests === array_slice($guestRows, 0, 2), $nameKey . ' matches visible guests and preserves original identities and amounts');
}
checkResultRowFilter(StoreReportResultRowFilterServices::filter($guestRows, $guestColumns,
    json_encode([$condition('member_name','contains','历史姓名')], JSON_UNESCAPED_UNICODE)) === [], 'hidden guest snapshot does not match visible-name filter');

foreach ([
    [$condition('hidden_id','eq','1'), 'hidden identifier'],
    [$condition('sale_amount','eq','2086'), 'numeric metric'],
    [$condition('store_summary','eq','扬州乙店'), 'unsupported operator'],
    [$condition('month','eq','2026-13'), 'invalid month'],
    [$condition('store_summary','contains',''), 'empty value'],
] as [$invalid, $label]) {
    try {
        $filter([$invalid]);
        throw new RuntimeException("{$label} was accepted");
    } catch (InvalidArgumentException $exception) {
        checkResultRowFilter(true, "{$label} rejected");
    }
}
try {
    $filter(array_fill(0, 17, $condition('month','eq','2026-10')));
    throw new RuntimeException('too many conditions were accepted');
} catch (InvalidArgumentException $exception) {
    checkResultRowFilter(true, 'more than sixteen conditions rejected');
}

$manyRows = array_fill(0, 20000, $rows[1]);
$start = microtime(true);
$matched = StoreReportResultRowFilterServices::filter($manyRows, $columns, json_encode([$condition('store_summary','contains','扬州')]));
checkResultRowFilter(count($matched) === 20000, 'large in-memory row set keeps exact results');
echo 'FILTER_20K_ROWS_MS=' . (int)round((microtime(true) - $start) * 1000) . "\n";
