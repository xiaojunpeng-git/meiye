<?php
declare(strict_types=1);

/**
 * 第 9 轮本地只读校验：补交的其他销售人必须由同集团任职重新解析，
 * 两类补交均生成真实员工 ID 与整元分配；不提交收款或持久化事实。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3PersonnelIdentity;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use app\services\cashier\v3\settlement\CashierV3RechargeDebtRepaymentServices;
use app\services\report\StoreSalespersonNetReadServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if ((string)(Db::query('SELECT DATABASE() db')[0]['db'] ?? '') !== 'ruihao') {
    throw new RuntimeException('This contract only runs against the local ruihao test database.');
}
$operator = new CashierV3OperatorScope(165, 1, '175', '0');
$staffId = CashierV3PersonnelIdentity::organizationStaffId(752);
$selection = [[
    'staffId' => $staffId, 'personnelSource' => 'other',
    'allocationWeight' => 100, 'isPreSale' => false,
]];

Db::startTrans();
try {
    foreach ([
        [new CashierV3DebtRepaymentServices(), 'resolveRepaymentSalespeople'],
        [new CashierV3RechargeDebtRepaymentServices(), 'resolveSalespeople'],
    ] as [$service, $methodName]) {
        $method = new ReflectionMethod($service, $methodName);
        $method->setAccessible(true);
        $rows = $method->invoke($service, $selection, 9800, $operator);
        $person = (array)($rows[0] ?? []);
        if (count($rows) !== 1 || (int)($person['employeeId'] ?? 0) !== 752
            || (int)($person['staffId'] ?? 0) !== $staffId
            || (int)($person['amountCents'] ?? 0) !== 9800
            || (string)($person['allocationGroupKey'] ?? '') !== 'normal') {
            throw new RuntimeException($methodName . ' did not resolve the group salesperson as a fact-compatible employee.');
        }
        $wrongSource = $selection;
        unset($wrongSource[0]['personnelSource']);
        try {
            $method->invoke($service, $wrongSource, 9800, $operator);
            throw new RuntimeException($methodName . ' accepted a virtual staff id without source authority.');
        } catch (CashierV3CommandException $expected) {
            // 身份来源校验必须先于事实生成失败。
        }
        $sameEmployee = [
            ['staffId' => 1471, 'allocationWeight' => 50],
            ['staffId' => CashierV3PersonnelIdentity::organizationStaffId(919),
                'personnelSource' => 'other', 'allocationWeight' => 50],
        ];
        try {
            $method->invoke($service, $sameEmployee, 9800, $operator);
            throw new RuntimeException($methodName . ' counted one employee twice.');
        } catch (CashierV3CommandException $expected) {
            if (!str_ends_with((string)($expected->getDetail()['reason'] ?? ''), 'salesperson_duplicate')) {
                throw new RuntimeException($methodName . ' failed before duplicate employee validation.');
            }
        }
    }
    // 已有跨店销售与退款事实在同一真实员工下回显；补交也写入该员工 ID。
    $report = (new StoreSalespersonNetReadServices())->detail(
        [165], ['start' => '2026-10-01', 'end' => '2026-10-31'], [752], 16, 1, 20
    );
    $types = array_column($report['records'], 'performance_type');
    sort($types);
    if ((int)$report['total'] !== 2 || (int)$report['amount_cents'] !== 0
        || $types !== ['refund_sales_deduction', 'sales_performance_allocated']) {
        throw new RuntimeException('Cross-store salesperson is missing from the shared report fact reader.');
    }
    echo "R9_DEBT_OTHER_SALESPERSON_READONLY_OK\n";
} finally {
    Db::rollback();
}
