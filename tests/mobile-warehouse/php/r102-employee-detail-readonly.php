<?php
/** Read-only local reconciliation of employee detail decoration against its source facts. */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use app\services\mobile\warehouse\MobileWarehouseServices;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local ruihao Docker database is allowed');
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $service = (new ReflectionClass(MobileWarehouseServices::class))->newInstanceWithoutConstructor();
    $contextMethod = new ReflectionMethod(MobileWarehouseServices::class, 'detailBusinessContext');
    $phoneMethod = new ReflectionMethod(MobileWarehouseServices::class, 'detailContactPhones');
    $rechargeMethod = new ReflectionMethod(MobileWarehouseServices::class, 'detailRechargeAmounts');
    foreach ([$contextMethod, $phoneMethod, $rechargeMethod] as $method) $method->setAccessible(true);
    $reader = new RegisteredMetricReadServices();
    $checked = 0;
    $checkedBySource = [];
    foreach ([
        ['recharge', 'staff_sales_yeji'],
        ['entitlement_completion', 'staff_labor_yeji'],
    ] as [$source, $metric]) {
        $sample = Db::name('cashier_v3_performance_fact')->where('tenant_id', '0')
            ->where('source_document_type', $source)->where('status', 'effective')
            ->where('fact_direction', 'forward')->where('employee_id', '>', 0)
            ->field('store_id,employee_id,business_date')->order('id', 'desc')->find();
        if (!$sample) continue;
        $storeId = (int)$sample['store_id'];
        $employeeId = (int)$sample['employee_id'];
        $date = (string)$sample['business_date'];
        $page = $reader->personnelDetailPage($metric, '0', [$storeId], ['start' => $date, 'end' => $date], $employeeId, 1, 20);
        $rows = $page['rows'];
        if ($rows === []) continue;
        $context = $contextMethod->invoke($service, $rows, [$storeId], $employeeId);
        $phones = $phoneMethod->invoke($service, $rows);
        $recharges = $rechargeMethod->invoke($service, $rows);
        foreach ($rows as $row) {
            $factId = (string)$row['fact_id'];
            if (!array_key_exists($factId, $context) || !array_key_exists($factId, $phones)) {
                throw new RuntimeException('Authorized detail decoration lost a source fact');
            }
            if ((string)$row['source_document_type'] === 'recharge'
                && !array_key_exists($factId, $recharges)) {
                throw new RuntimeException('Recharge principal missing for an authorized recharge fact');
            }
            if ((string)$row['source_document_type'] === 'entitlement_completion'
                && (int)($context[$factId]['service_fact_count'] ?? 0) === 0) {
                throw new RuntimeException('Completed entitlement service snapshot missing');
            }
            $checked++;
            $checkedBySource[$source] = ($checkedBySource[$source] ?? 0) + 1;
        }
    }
    if ($checked === 0) throw new RuntimeException('No local employee detail sample available');
    echo 'R102 local employee detail read-only rows: ' . json_encode($checkedBySource) . " PASS\n";
} finally {
    Db::rollback();
}
