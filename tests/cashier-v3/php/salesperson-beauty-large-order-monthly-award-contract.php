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
    // 退款在成功日冲减同一人、同一分类的月累计，5万回落至3万档。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 4, 'business_date' => '2026-09-01', 'beauty_category' => '眼部', 'amount_cents' => 5000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 4, 'business_date' => '2026-09-04', 'beauty_category' => '眼部', 'amount_cents' => -1000000],
    // 另一人退款后跌破3万，不能继续保留达标结果。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 5, 'business_date' => '2026-09-01', 'beauty_category' => '身体', 'amount_cents' => 3000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 5, 'business_date' => '2026-09-04', 'beauty_category' => '身体', 'amount_cents' => -100],
    // 同一员工可分别获得销售人和销售经理归属，两个角色不得互相抵扣。
    ['tenant_id' => 't', 'role_snapshot' => 'sales_manager', 'employee_id' => 4, 'business_date' => '2026-09-01', 'beauty_category' => '眼部', 'amount_cents' => 5000000],
    ['tenant_id' => 't', 'role_snapshot' => 'sales_manager', 'employee_id' => 4, 'business_date' => '2026-09-04', 'beauty_category' => '眼部', 'amount_cents' => -2000000],
];

$awards = $method->invoke(null, $facts);
$checks = [
    'only one highest category is selected for a salesperson-month' => count($awards) === 4 && isset($awards[2]) && !isset($awards[1]),
    'five-wan winner uses only the five-wan interval' => ($awards[2]['beauty_category'] ?? '') === '眼部'
        && ($awards[2]['amount_cents'] ?? 0) === 5000000 && ($awards[2]['threshold_cents'] ?? 0) === 5000000,
    'three-wan winner uses the three-wan interval' => isset($awards[3]) && ($awards[3]['threshold_cents'] ?? 0) === 3000000,
    'below-three-wan category remains blank' => !isset($awards[4]),
    'refund lowers a five-wan sale to the three-wan interval' => ($awards[6]['amount_cents'] ?? 0) === 4000000
        && ($awards[6]['threshold_cents'] ?? 0) === 3000000 && !isset($awards[5]),
    'refund below three-wan removes the award' => !isset($awards[7]) && !isset($awards[8]),
    'manager refund is independent from the salesperson role' => ($awards[10]['amount_cents'] ?? 0) === 3000000
        && ($awards[10]['threshold_cents'] ?? 0) === 3000000,
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$passed) $failed++;
}
echo 'ASSERT_FAILED=' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
