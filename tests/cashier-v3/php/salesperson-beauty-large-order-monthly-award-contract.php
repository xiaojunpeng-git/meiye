<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\StoreUnifiedReportPhaseTwoServices;
use app\services\report\StoreLargeOrderInstallmentServices;

$method = new ReflectionMethod(StoreUnifiedReportPhaseTwoServices::class, 'beautyLargeOrderMonthlyAwards');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);

$facts = [
    // 同一人同月：眼部累计 5 万，面部累计 4 万，只能落 5 万眼部档。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 1, 'member_id' => 11, 'business_date' => '2026-09-01', 'beauty_category' => '眼部', 'amount_cents' => 2000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 1, 'member_id' => 11, 'business_date' => '2026-09-02', 'beauty_category' => '面部', 'amount_cents' => 4000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 1, 'member_id' => 11, 'business_date' => '2026-09-03', 'beauty_category' => '眼部', 'amount_cents' => 3000000],
    // 第二人只达到 3 万档。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 2, 'member_id' => 21, 'business_date' => '2026-09-02', 'beauty_category' => '身体', 'amount_cents' => 3000000],
    // 第三人未达门槛，不得显示。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 3, 'member_id' => 31, 'business_date' => '2026-09-02', 'beauty_category' => '面部', 'amount_cents' => 2999999],
    // 退款在成功日冲减同一人、同一分类的月累计，5万回落至3万档。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 4, 'member_id' => 41, 'business_date' => '2026-09-01', 'beauty_category' => '眼部', 'amount_cents' => 5000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 4, 'member_id' => 41, 'business_date' => '2026-09-04', 'beauty_category' => '眼部', 'amount_cents' => -1000000],
    // 另一人退款后跌破3万，不能继续保留达标结果。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 5, 'member_id' => 51, 'business_date' => '2026-09-01', 'beauty_category' => '身体', 'amount_cents' => 3000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 5, 'member_id' => 51, 'business_date' => '2026-09-04', 'beauty_category' => '身体', 'amount_cents' => -100],
    // 同一员工可分别获得销售人和销售经理归属，两个角色不得互相抵扣。
    ['tenant_id' => 't', 'role_snapshot' => 'sales_manager', 'employee_id' => 4, 'member_id' => 41, 'business_date' => '2026-09-01', 'beauty_category' => '眼部', 'amount_cents' => 5000000],
    ['tenant_id' => 't', 'role_snapshot' => 'sales_manager', 'employee_id' => 4, 'member_id' => 41, 'business_date' => '2026-09-04', 'beauty_category' => '眼部', 'amount_cents' => -2000000],
    // 会员成为月度统计身份：同销售人的两个 2 万会员不能合并凑 3 万门槛。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 6, 'member_id' => 61, 'business_date' => '2026-09-05', 'beauty_category' => '眼部', 'amount_cents' => 2000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 6, 'member_id' => 62, 'business_date' => '2026-09-06', 'beauty_category' => '眼部', 'amount_cents' => 2000000],
    // 同销售人、同分类、同月份的两位达标会员必须各有独立应计行和稳定行键。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 7, 'member_id' => 71, 'business_date' => '2026-09-07', 'beauty_category' => '眼部', 'amount_cents' => 3500000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 7, 'member_id' => 72, 'business_date' => '2026-09-08', 'beauty_category' => '眼部', 'amount_cents' => 3100000],
    // 退款只能抵减相同会员的月度分类金额，不能冲减另一位会员的达标业绩。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 8, 'member_id' => 81, 'business_date' => '2026-09-09', 'beauty_category' => '眼部', 'amount_cents' => 5000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 8, 'member_id' => 82, 'business_date' => '2026-09-10', 'beauty_category' => '眼部', 'amount_cents' => -1000000],
    // 同一会员的两种分类各 2 万、跨两个月各 2 万，都不能拼成单个达标月分类。
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 9, 'member_id' => 91, 'business_date' => '2026-09-11', 'beauty_category' => '眼部', 'amount_cents' => 2000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 9, 'member_id' => 91, 'business_date' => '2026-09-12', 'beauty_category' => '面部', 'amount_cents' => 2000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 9, 'member_id' => 92, 'business_date' => '2026-09-13', 'beauty_category' => '眼部', 'amount_cents' => 2000000],
    ['tenant_id' => 't', 'role_snapshot' => 'salesperson', 'employee_id' => 9, 'member_id' => 92, 'business_date' => '2026-10-13', 'beauty_category' => '眼部', 'amount_cents' => 2000000],
];

$awards = $method->invoke(null, $facts);
$checks = [
    'only one highest category is selected for each salesperson-member-month' => count($awards) === 7 && isset($awards[2]) && !isset($awards[1]),
    'five-wan winner uses only the five-wan interval' => ($awards[2]['beauty_category'] ?? '') === '眼部'
        && ($awards[2]['amount_cents'] ?? 0) === 5000000 && ($awards[2]['threshold_cents'] ?? 0) === 5000000,
    'three-wan winner uses the three-wan interval' => isset($awards[3]) && ($awards[3]['threshold_cents'] ?? 0) === 3000000,
    'below-three-wan category remains blank' => !isset($awards[4]),
    'refund lowers a five-wan sale to the three-wan interval' => ($awards[6]['amount_cents'] ?? 0) === 4000000
        && ($awards[6]['threshold_cents'] ?? 0) === 3000000 && !isset($awards[5]),
    'refund below three-wan removes the award' => !isset($awards[7]) && !isset($awards[8]),
    'manager refund is independent from the salesperson role' => ($awards[10]['amount_cents'] ?? 0) === 3000000
        && ($awards[10]['threshold_cents'] ?? 0) === 3000000,
    'two members below threshold cannot combine' => !isset($awards[11]) && !isset($awards[12]),
    'two qualifying members remain separate' => ($awards[13]['amount_cents'] ?? 0) === 3500000
        && ($awards[14]['amount_cents'] ?? 0) === 3100000,
    'one members refund does not lower another members award' => ($awards[15]['amount_cents'] ?? 0) === 5000000
        && !isset($awards[16]),
    'two categories below threshold cannot combine' => !isset($awards[17]) && !isset($awards[18]),
    'two months below threshold cannot combine' => !isset($awards[19]) && !isset($awards[20]),
];

$due = StoreLargeOrderInstallmentServices::dueRows($facts, $awards, '2026-09');
$memberDue = array_values(array_filter($due, static fn(array $row): bool => (int)$row['employee_id'] === 7));
$checks['separate members have distinct stable accrual rows'] = count($memberDue) === 2
    && count(array_unique(array_column($memberDue, 'row_key'))) === 2
    && array_sum(array_column($memberDue, 'due_amount_cents')) === 2640000;
$nextDue = StoreLargeOrderInstallmentServices::dueRows($facts, $awards, '2026-10');
$nextMemberDue = array_values(array_filter($nextDue, static fn(array $row): bool => (int)$row['employee_id'] === 7));
$checks['next month keeps separate members and original installment share'] = count($nextMemberDue) === 2
    && count(array_unique(array_column($nextMemberDue, 'row_key'))) === 2
    && array_sum(array_column($nextMemberDue, 'due_amount_cents')) === 1980000;

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$passed) $failed++;
}
echo 'ASSERT_FAILED=' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
