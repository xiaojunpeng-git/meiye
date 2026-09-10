<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\StoreUnifiedReportPhaseTwoServices;

$method = new ReflectionMethod(StoreUnifiedReportPhaseTwoServices::class, 'beautyLargeOrderMonthlyAwards');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);

$facts = [
    // 同一人同月：眼部累计 5 万，面部累计 4 万，只能落 5 万眼部档。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 1, 'business_date' => '2026-09-01', 'beauty_category' => '眼部', 'amount_cents' => 2000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 1, 'business_date' => '2026-09-02', 'beauty_category' => '面部', 'amount_cents' => 4000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 1, 'business_date' => '2026-09-03', 'beauty_category' => '眼部', 'amount_cents' => 3000000],
    // 第二人只达到 3 万档。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 2, 'business_date' => '2026-09-02', 'beauty_category' => '身体', 'amount_cents' => 3000000],
    // 第三人未达门槛，不得显示。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 3, 'business_date' => '2026-09-02', 'beauty_category' => '面部', 'amount_cents' => 2999999],
];

$awards = $method->invoke(null, $facts);
$checks = [
    'only one highest category is selected for a salesperson-month' => count($awards) === 2 && isset($awards[2]) && !isset($awards[1]),
    'five-wan winner uses only the five-wan interval' => ($awards[2]['beauty_category'] ?? '') === '眼部'
        && ($awards[2]['amount_cents'] ?? 0) === 5000000 && ($awards[2]['threshold_cents'] ?? 0) === 5000000,
    'three-wan winner uses the three-wan interval' => isset($awards[3]) && ($awards[3]['threshold_cents'] ?? 0) === 3000000,
    'below-three-wan category remains blank' => !isset($awards[4]),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$passed) $failed++;
}
echo 'ASSERT_FAILED=' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
