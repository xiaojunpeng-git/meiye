<?php
declare(strict_types=1);

// All queries below run against the local RH mirror in a read-only transaction.
$root = getenv('CASHIER_V3_BACKEND_ROOT') ?: '/var/www/html';
require $root . '/vendor/autoload.php';

use app\services\report\StoreReportResultRowFilterServices;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreUnifiedReportPhaseTwoServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

(new think\App($root . '/'))->initialize();
if (config('database.connections.mysql.database') !== 'ruihao'
    || config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('Only the local RH mirror may run this test');
}

$phaseOne = [
    'partner_item_summary', 'partner_item_detail', 'member_consumption_detail',
    'store_item_analysis', 'store_craftsman_consumption', 'store_craftsman_consumption_detail',
    'store_salesperson_performance', 'store_salesperson_performance_detail',
];
$phaseTwo = [
    'market_performance', 'market_detail', 'member_visit_analysis', 'member_visit_detail',
    'member_visit_annual_summary', 'field_acquisition_detail', 'field_acquisition_summary',
    'cross_industry_customer_detail', 'cross_industry_customer_summary',
    'new_customer_analysis', 'new_customer_analysis_summary',
    'salesperson_large_order_statistics', 'store_refund_ledger',
];
$phaseFour = ['six_dimension_analysis'];
$phaseSix = ['phase_six_other_multi_payment', 'phase_six_salary_summary', 'phase_six_salary_detail'];
$range = ['start' => '2026-10-01', 'end' => '2026-10-17'];
$baseInput = ['start_date' => $range['start'], 'end_date' => $range['end'], '_internal_all' => true];
$unified = new StoreUnifiedReportServices();
$four = new StoreUnifiedReportPhaseFourServices();
$six = new StoreUnifiedReportPhaseSixServices();
$only = array_values(array_filter(explode(',', (string)(getenv('REPORT_FILTER_ONLY') ?: ''))));
$storeId = max(1, (int)(getenv('REPORT_FILTER_STORE_ID') ?: 126));
$read = static function (string $code, array $input) use ($unified, $four, $six, $range, $phaseFour, $phaseSix, $storeId): array {
    if (in_array($code, $phaseFour, true)) return $four->query($code, [$storeId], $range, $input);
    if (in_array($code, $phaseSix, true)) return $six->query($code, [$storeId], $range, $input);
    return $unified->query([$storeId], array_merge($input, ['report' => $code]));
};
$cents = static function ($value): int {
    $text = trim((string)$value);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $parts)) return 0;
    $amount = (int)$parts[2] * 100 + (int)str_pad($parts[3] ?? '', 2, '0');
    return $parts[1] === '-' ? -$amount : $amount;
};

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $failures = [];
    $visitMemberId = 0;
    foreach (array_merge($phaseOne, $phaseTwo, $phaseFour, $phaseSix) as $code) {
        if ($only && !in_array($code, $only, true)
            && !($code === 'member_visit_analysis' && in_array('member_visit_detail', $only, true))) continue;
        $start = microtime(true);
        try {
            $input = $baseInput;
            if ($code === 'member_visit_detail') $input += ['member_id' => $visitMemberId, 'visit_month' => 10];
            $original = $read($code, $input);
            $columns = array_values(array_filter((array)($original['columns'] ?? []),
                static fn(array $column): bool => !empty($column['filter'])));
            if (!$columns) throw new RuntimeException('no fixed-dimension filter declared; title=' . (string)($original['title'] ?? '') . '; first=' . json_encode(array_slice((array)($original['columns'] ?? []), 0, 3), JSON_UNESCAPED_UNICODE));
            $rows = (array)($original['records'] ?? []);
            if ($code === 'member_visit_analysis' && $rows) $visitMemberId = (int)($rows[0]['member_id'] ?? 0);
            $chosen = null;
            foreach ($columns as $column) {
                foreach ($rows as $row) {
                    $value = trim((string)($row[$column['key']] ?? ''));
                    if ($value !== '' && $value !== '-') {
                        $chosen = ['key' => $column['key'], 'op' => $column['filter']['type'] === 'month' ? 'eq' : 'contains', 'value' => $value];
                        break 2;
                    }
                }
            }
            $filteredCount = null;
            if ($chosen !== null) {
                $payload = json_encode([$chosen], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $filtered = $read($code, $input + ['row_filters' => $payload]);
                $expected = StoreReportResultRowFilterServices::filter($rows, $original['columns'], $payload);
                if (($filtered['records'] ?? []) !== $expected) throw new RuntimeException('filtered rows differ from displayed-row contract');
                if (isset($filtered['total']) && (int)$filtered['total'] !== count($expected)) {
                    throw new RuntimeException('filtered total differs from full row count');
                }
                $moneyKey = [
                    'partner_item_detail' => 'deal_amount',
                    'member_consumption_detail' => 'receipt_total',
                    'market_detail' => 'amount',
                    'phase_six_salary_summary' => 'consumption_amount',
                    'phase_six_salary_detail' => 'consumption_amount',
                ][$code] ?? '';
                if ($moneyKey !== '') {
                    $expectedCents = array_sum(array_map(static fn(array $row): int => $cents($row[$moneyKey] ?? '0'), $expected));
                    if ($cents($filtered['summary_row'][$moneyKey] ?? '0') !== $expectedCents) {
                        throw new RuntimeException('filtered money total does not equal selected rows: ' . $moneyKey
                            . '; summary=' . (string)($filtered['summary_row'][$moneyKey] ?? '')
                            . '; rows=' . $expectedCents . '; samples=' . json_encode(array_slice(array_column($expected, $moneyKey), 0, 25)));
                    }
                }
                if ($code === 'phase_six_other_multi_payment') {
                    $unique = [];
                    foreach ($expected as $row) $unique[(string)$row['sale_fact_id']] = $cents($row['receivable_amount'] ?? '0');
                    if ($cents($filtered['summary_row']['receivable_amount'] ?? '0') !== array_sum($unique)) {
                        throw new RuntimeException('other-payment receivable total duplicated a sale');
                    }
                }
                if ($code === 'partner_item_detail' && count($expected) > 20) {
                    $pageInput = $input;
                    unset($pageInput['_internal_all']);
                    $pageInput['row_filters'] = $payload;
                    $firstPage = $read($code, $pageInput + ['page' => 1, 'limit' => 20]);
                    $secondPage = $read($code, $pageInput + ['page' => 2, 'limit' => 20]);
                    if ($firstPage['records'] !== array_slice($expected, 0, 20)
                        || $secondPage['records'] !== array_slice($expected, 20, 20)
                        || $firstPage['summary_row'] !== $filtered['summary_row']
                        || (int)$secondPage['total'] !== count($expected)) {
                        throw new RuntimeException('filtered pagination or total differs from complete result');
                    }
                }
                $filteredCount = count($expected);
            }
            echo json_encode(['report' => $code, 'status' => 'PASS', 'rows' => count($rows),
                'filters' => array_column($columns, 'key'), 'matched' => $filteredCount,
                'ms' => (int)round((microtime(true) - $start) * 1000)], JSON_UNESCAPED_UNICODE) . "\n";
        } catch (Throwable $error) {
            $failures[] = $code . ': ' . $error->getMessage();
            echo json_encode(['report' => $code, 'status' => 'FAIL', 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    if (!$only || in_array('six_dimension_analysis', $only, true)) {
        // The sampled stores have no six-dimension rows; exercise its actual result builder without inventing facts.
        $method = new ReflectionMethod(StoreUnifiedReportPhaseFourServices::class, 'result');
        $method->setAccessible(true);
        $sampleColumns = [
            ['key'=>'company_name','label'=>'分公司','fixed'=>'left','value_type'=>'text','source_explanation'=>'分公司'],
            ['key'=>'store_name','label'=>'门店','fixed'=>'left','value_type'=>'text','source_explanation'=>'门店'],
            ['key'=>'cash_performance','label'=>'业绩','summable'=>true,'value_type'=>'money','source_explanation'=>'业绩'],
        ];
        $sampleRows = [
            ['company_name'=>'甲公司','store_name'=>'甲店','cash_performance'=>'12.34'],
            ['company_name'=>'乙公司','store_name'=>'乙店','cash_performance'=>'56.78'],
        ];
        $payload = json_encode([['key'=>'company_name','op'=>'contains','value'=>'乙']], JSON_UNESCAPED_UNICODE);
        $sample = $method->invoke($four, '六维数据分析表', $sampleColumns, $sampleRows, $range,
            ['row_filters'=>$payload,'page'=>1,'limit'=>20]);
        if ((int)$sample['total'] !== 1 || count($sample['records']) !== 1
            || $sample['records'][0]['store_name'] !== '乙店'
            || (string)$sample['summary_row']['cash_performance'] !== '56.78') {
            $failures[] = 'six_dimension_analysis synthetic result filtering failed';
        } else {
            echo json_encode(['report'=>'six_dimension_analysis:synthetic','status'=>'PASS',
                'matched'=>1,'summary'=>'56.78'], JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    if (!$only || in_array('cross_industry_customer_summary', $only, true)) {
        // The sampled stores have no G-source rows; validate the report's separate top totals on selected rows.
        $method = new ReflectionMethod(StoreUnifiedReportPhaseTwoServices::class, 'result');
        $method->setAccessible(true);
        $rows = [
            ['partner_store_name'=>'甲异业店','first_500'=>'500','reached_2400'=>'2400'],
            ['partner_store_name'=>'乙异业店','first_500'=>'600','reached_2400'=>'2600'],
        ];
        $sample = $method->invoke(new StoreUnifiedReportPhaseTwoServices(), '异业收客汇总表',
            [['key'=>'partner_store_name','label'=>'异业店名'], ['key'=>'first_500','label'=>'首次成交满500'],
                ['key'=>'reached_2400','label'=>'成交满2400']], $rows,
            ['row_filters'=>json_encode([['key'=>'partner_store_name','op'=>'contains','value'=>'乙']], JSON_UNESCAPED_UNICODE)],
            [], ['first_500_total'=>'1100','reached_2400_total'=>'5000']);
        $top = array_column((array)($sample['top_summaries'] ?? []), 'value', 'key');
        if ((int)$sample['total'] !== 1 || $sample['records'][0]['partner_store_name'] !== '乙异业店'
            || (string)($top['first_500_total'] ?? '') !== '600'
            || (string)($top['reached_2400_total'] ?? '') !== '2600') {
            $failures[] = 'cross-industry selected-row top summaries failed';
        } else {
            echo json_encode(['report'=>'cross_industry_customer_summary:synthetic','status'=>'PASS',
                'matched'=>1,'top_summaries'=>$top], JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    if ($failures) throw new RuntimeException(implode("\n", $failures));
} finally {
    Db::rollback();
}
