<?php

declare(strict_types=1);

/**
 * Compare the target rankings with the independently rendered salary summary.
 * This is read-only and uses the local Yangzhou Oushang refund sample; never
 * point it at a customer instance or use it to alter historical salary facts.
 */
if (getenv('STORE_TARGET_SALARY_READONLY') !== '1') {
    throw new RuntimeException('explicit read-only local flag required');
}
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->initialize();

use app\services\cashier\v3\dashboard\CashierV3StoreTargetDashboardReadModel;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

function targetSalaryCheck(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function targetSalaryCents($amount): int
{
    $text = trim((string)$amount);
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $text)) throw new RuntimeException('invalid salary amount');
    $negative = $text[0] === '-';
    [$whole, $fraction] = array_pad(explode('.', ltrim($text, '-'), 2), 2, '');
    $cents = (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    return $negative ? -$cents : $cents;
}

$storeId = (int)Db::name('system_store')->where('name', '扬州欧尚店')->value('id');
targetSalaryCheck($storeId > 0, 'local comparison store exists');
$range = ['start' => '2026-10-01', 'end' => '2026-10-13'];
$salaryService = new StoreUnifiedReportPhaseSixServices();
$salary = $salaryService->query('phase_six_salary_summary', [$storeId], $range);
$rankingRows = $salaryService->salaryRankingRows([$storeId], $range);
$reader = new CashierV3StoreTargetDashboardReadModel();
$cashMethod = new ReflectionMethod($reader, 'cashRanking');
$cashMethod->setAccessible(true);
$consumptionMethod = new ReflectionMethod($reader, 'consumptionRanking');
$consumptionMethod->setAccessible(true);
$cash = $cashMethod->invoke($reader, '0', $storeId, $range, $rankingRows)['records'];
$consumption = $consumptionMethod->invoke($reader, $rankingRows)['records'];
$cashByEmployee = array_column($cash, null, 'employee_id');
$consumptionByEmployee = array_column($consumption, null, 'employee_id');
$expectedCashCount = 0;
$expectedConsumptionCount = 0;
foreach ($salary['records'] as $row) {
    $employeeId = (int)$row['employee_id'];
    if ($employeeId <= 0) continue;
    $cashCents = 0;
    foreach ($row as $key => $value) {
        if ($key === 'salary_presale_cash' || strpos((string)$key, 'salary_category_cash_') === 0) {
            $cashCents += targetSalaryCents($value);
        }
    }
    if ($cashCents !== 0) {
        ++$expectedCashCount;
        targetSalaryCheck(isset($cashByEmployee[$employeeId])
            && (int)$cashByEmployee[$employeeId]['cash_performance_cents'] === $cashCents,
            "employee {$employeeId} cash equals salary categories plus presale after refund");
    }
    $consumptionCents = targetSalaryCents($row['consumption_amount']);
    $laborCents = targetSalaryCents($row['labor_fee']);
    $hasService = $consumptionCents !== 0 || $laborCents !== 0
        || (float)$row['project_count'] !== 0.0 || (float)$row['service_visit_count'] !== 0.0
        || (float)$row['service_people_count'] !== 0.0;
    if (!$hasService) continue;
    ++$expectedConsumptionCount;
    $actual = $consumptionByEmployee[$employeeId] ?? [];
    targetSalaryCheck((int)($actual['consumption_performance_cents'] ?? -1) === $consumptionCents
        && (int)($actual['labor_cents'] ?? -1) === $laborCents
        && abs((float)($actual['project_count'] ?? -1) - (float)$row['project_count']) < 0.000001
        && abs((float)($actual['service_count'] ?? -1) - (float)$row['service_visit_count']) < 0.000001
        && abs((float)($actual['customer_count'] ?? -1) - (float)$row['service_people_count']) < 0.000001,
        "employee {$employeeId} consumption, labor, projects and customers equal salary");
}
targetSalaryCheck(count($cash) === $expectedCashCount && count($consumption) === $expectedConsumptionCount,
    'ranking employee sets equal nonzero salary sets');
targetSalaryCheck((int)($cashByEmployee[1386]['cash_performance_cents'] ?? 0) === 640871,
    'fixed refund sample deducts Wang Panpan to 6408.71 yuan');
targetSalaryCheck((int)($cashByEmployee[1391]['cash_performance_cents'] ?? 0) === 165912
    && (int)($cashByEmployee[1425]['cash_performance_cents'] ?? 0) === 165712,
    'fixed refund sample deducts the other two salespeople');
echo 'TARGET_SALARY_PARITY ' . json_encode(['store_id' => $storeId, 'range' => $range,
    'cash_employees' => count($cash), 'service_employees' => count($consumption)],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
