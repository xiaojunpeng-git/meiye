<?php

declare(strict_types=1);

require (getenv('CASHIER_V3_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportDetailXlsxExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

function r73Expect($actual, $expected, string $message): void
{
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
}

$records = [];
for ($i = 1; $i <= 45; $i++) {
    $records[] = [
        'member_name' => $i === 42 ? '=HYPERLINK("https://invalid.test")' : '会员' . $i,
        'order_no' => $i === 42 ? '00000133' : 'XS' . $i,
        'receipt_amount' => $i === 42 ? '1600.39' : '2.50',
    ];
}

$exporter = new StoreUnifiedReportDetailXlsxExporter();
r73Expect(StoreUnifiedReportDetailXlsxExporter::supports('operations_cash_fact_detail'), true, 'detail allowlist');
r73Expect(StoreUnifiedReportDetailXlsxExporter::supports('operations_health_data'), false, 'summary must not use detail exporter');
$bytes = $exporter->render('operations_cash_fact_detail', [
    'columns' => [
        ['key' => 'member_name', 'label' => '会员', 'source_explanation' => '会员姓名快照'],
        ['key' => 'order_no', 'label' => '单据号', 'source_explanation' => '业务单号'],
        ['key' => 'receipt_amount', 'label' => '收款金额', 'value_type' => 'money', 'source_explanation' => '收款事实'],
    ],
    'records' => $records,
    'summary_row' => ['member_name' => '合计', 'receipt_amount' => '1708.39'],
    'metric_version' => 'r73-fixture',
]);
r73Expect(substr($bytes, 0, 2), 'PK', 'real XLSX signature');
$temp = tempnam(sys_get_temp_dir(), 'r73-xlsx-');
if ($temp === false) throw new RuntimeException('temporary workbook unavailable');
try {
    file_put_contents($temp, $bytes);
    $book = IOFactory::load($temp);
    try {
        $sheet = $book->getSheet(0);
        r73Expect($sheet->getCell('A1')->getValue(), '会员', 'Chinese column label');
        r73Expect($sheet->getCell('B43')->getValue(), '00000133', 'row beyond page 1');
        r73Expect($sheet->getCell('B43')->getDataType(), DataType::TYPE_STRING, 'order identity stays text');
        r73Expect($sheet->getCell('A43')->getDataType(), DataType::TYPE_STRING, 'member text cannot become formula');
        r73Expect($sheet->getCell('C43')->getValue(), 1600.39, 'money keeps cent precision');
        r73Expect($sheet->getCell('A47')->getValue(), '合计', 'summary after complete detail');
        r73Expect($book->getSheet(1)->getCell('B1')->getValue(), 'r73-fixture', 'metric version');
        r73Expect($book->getSheet(1)->getCell('B7')->getValue(), '收款事实', 'column source');
    } finally {
        $book->disconnectWorksheets();
    }
} finally {
    unlink($temp);
}

echo "PASS R73 unified detail real XLSX full rows and safe cells\n";
