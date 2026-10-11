<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseSixServices;

// Read-only local fixture: one employee's presale allocation must not reclassify
// another employee or the completed service on the same business line.
require '/var/www/html/vendor/autoload.php';
(new \think\App('/var/www/html/'))->initialize();

$service = new StoreUnifiedReportPhaseSixServices();
$range = ['start' => '2026-10-08', 'end' => '2026-10-08'];
$storeId = 126;
$employeeId = 1032;
$presaleKey = 'salary_presale_cash';
$summary = $service->query('phase_six_salary_summary', [$storeId], $range,
    ['employee_name' => '汪敏美容师']);
$columns = array_column($summary['columns'], 'key');
$presaleIndex = array_search($presaleKey, $columns, true);
$categoryIndex = null;
foreach ($columns as $index => $key) {
    if (strpos($key, 'salary_category_cash_') === 0) {
        $categoryIndex = $index;
        break;
    }
}
if ($presaleIndex === false || $categoryIndex !== $presaleIndex + 1
    || ($summary['columns'][$presaleIndex]['label'] ?? '') !== '售前业绩') {
    throw new RuntimeException('presale column must stand alone immediately before product categories');
}
$rows = array_values(array_filter($summary['records'], static function (array $row) use ($employeeId): bool {
    return (int)$row['employee_id'] === $employeeId;
}));
if (count($rows) !== 1 || $rows[0][$presaleKey] !== '99'
    || $summary['summary_row'][$presaleKey] !== '99') {
    throw new RuntimeException('presale summary must contain the employee allocation exactly once');
}
$categoryTotal = 0;
foreach ($columns as $key) {
    if (strpos($key, 'salary_category_cash_') === 0) $categoryTotal += (int)$rows[0][$key];
}
if ($categoryTotal !== 0) throw new RuntimeException('presale cash leaked into a product category');

$drillInput = ['salary_employee_id' => $employeeId, 'salary_store_id' => $storeId,
    'salary_category_key' => $presaleKey];
$detail = $service->query('phase_six_salary_detail', [$storeId], $range, $drillInput);
$export = $service->query('phase_six_salary_detail', [$storeId], $range,
    $drillInput + ['_internal_all' => true]);
if ($detail['records'] !== $export['records'] || $detail['summary_row'] !== $export['summary_row']
    || count($detail['records']) !== 1 || $detail['summary_row']['cash_amount'] !== '99') {
    throw new RuntimeException('presale drilldown and full export must reconcile with the clicked cell');
}
$presale = $detail['records'][0];
if ($presale['performance_category'] !== '售前' || $presale['cash_amount_cents'] !== 9900
    || $presale['project_count'] !== '0' || $presale['consumption_amount'] !== '0'
    || $presale['labor_fee'] !== '0') {
    throw new RuntimeException('presale row must hold only that salesperson cash allocation');
}
$plain = $service->query('phase_six_salary_detail', [$storeId], $range,
    ['salary_employee_id' => $employeeId, 'salary_store_id' => $storeId]);
$serviceRows = array_values(array_filter($plain['records'], static function (array $row) use ($presale): bool {
    return $row['source_line_id'] === $presale['source_line_id']
        && $row['row_key'] !== $presale['row_key'];
}));
if (count($serviceRows) !== 1 || $serviceRows[0]['performance_category'] === '售前'
    || $serviceRows[0]['project_count'] !== '1'
    || $serviceRows[0]['consumption_amount'] !== '99'
    || $serviceRows[0]['cash_amount'] !== '0') {
    throw new RuntimeException('same-line completed service must retain its category and metrics');
}

$ordinary = $service->query('phase_six_salary_summary', [$storeId], $range,
    ['employee_name' => '彭菲']);
$ordinaryRows = array_values(array_filter($ordinary['records'], static function (array $row): bool {
    return (int)$row['employee_id'] === 663;
}));
if (count($ordinaryRows) !== 1 || $ordinaryRows[0][$presaleKey] !== '0'
    || (int)($ordinaryRows[0]['salary_category_cash_26321'] ?? 0) <= 0) {
    throw new RuntimeException('unmarked salesperson must remain in the product category');
}

// Refunds carry their own signed salesperson role; the projection must use it
// without altering the original sale or inventing a product category.
$project = new ReflectionMethod($service, 'salaryCategoryAmountsByColumn');
$project->setAccessible(true);
$signed = $project->invoke($service,
    ['role_snapshot' => 'salesperson:presale', 'cash_cents' => -123,
        'presale_cash_cents' => -123, 'sales_category_cents' => []], []);
if ($signed !== [$presaleKey => -123]) throw new RuntimeException('presale refund did not deduct its own bucket');
$regular = $project->invoke($service,
    ['role_snapshot' => 'salesperson:postsale', 'cash_cents' => -123,
        'presale_cash_cents' => 0, 'sales_category_cents' => [7 => -123]],
    [7 => 'salary_category_cash_7']);
if ($regular !== ['salary_category_cash_7' => -123]) {
    throw new RuntimeException('regular refund must stay in its product category');
}
try {
    $service->query('phase_six_salary_detail', [15], $range, $drillInput);
    throw new RuntimeException('unauthorized presale drilldown was accepted');
} catch (InvalidArgumentException $error) {
    if (strpos($error->getMessage(), '无权限') === false) throw $error;
}

echo "PASS salary presale summary, detail, export, service split, regular category, refund and scope\n";
