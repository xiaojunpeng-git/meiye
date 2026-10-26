<?php
declare(strict_types=1);

/** 本机镜像专项回归：真实事实只读，分类开关的边界测试只在事务中修改并无条件回滚。 */
$root = getenv('CASHIER_V3_BACKEND_ROOT') ?: '/var/www/html';
require $root . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\report\StoreDealPeopleQualificationServices;
use app\services\report\StorePartnerIndependentServiceFacts;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

(new think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
});
if (getenv('R87_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('R87 only permits the explicitly selected local mysql/ruihao mirror');
}
function check87(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function invoke87($service, string $method, array $arguments) {
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($service, $arguments);
}
$tenant = CashierV3ScopeResolver::TENANT_SCOPE_ID;
$stores = [(int)(getenv('R87_STORE_ID') ?: 126)];
$range = ['start'=>'2026-10-01', 'end'=>'2026-10-17'];
$input = ['start_date'=>$range['start'], 'end_date'=>$range['end'], '_internal_all'=>true];
$configQuery = static fn() => Db::name('cashier_v3_report_category_config')->where('tenant_id', $tenant);
$beforeConfig = $configQuery()->order('id')->select()->toArray();
$qualificationFile = $root . '/app/services/report/StoreDealPeopleQualificationServices.php';
$qualificationHash = hash_file('sha256', $qualificationFile);
$results = [];
Db::startTrans();
try {
    $report = new StoreUnifiedReportServices();
    $allowed = invoke87($report, 'partnerReportCategoryIds', [$stores]);
    check87(isset($allowed[26315], $allowed[26316], $allowed[26317], $allowed[26339])
        && !isset($allowed[26332]), 'Existing configured categories/descendants do not match expected scope');
    $qualification = (new StoreDealPeopleQualificationServices())->qualifiedDays($stores, $range);
    $eligible = array_values(array_filter($qualification,
        static fn(array $day): bool => isset($allowed[(int)($day['category_id'] ?? 0)])));
    $start = microtime(true);
    $detail = $report->query($stores, $input + ['report'=>'partner_item_detail']);
    $elapsed = round((microtime(true) - $start) * 1000);
    $summary = $report->query($stores, $input + ['report'=>'partner_item_summary']);
    $people = $report->query($stores, $input + ['report'=>'partner_item_detail', 'metric_code'=>'member_count']);
    check87($detail['records'] !== [] && $eligible !== [], 'Need nonempty monetary and qualified-member fixtures');
    foreach ($detail['records'] as $row) {
        check87(isset($allowed[(int)$row['category_id_snapshot']]), 'Disabled category leaked into detail');
        if (!empty($row['_sale_activity'])) {
            $expectedLabor = (int)Db::name('cashier_v3_performance_fact')
                ->where('source_line_id', (string)$row['source_line_id'])
                ->where('performance_type', 'labor_performance_allocated')->where('status', 'effective')
                ->sum('labor_fee_amount_cents');
            check87((int)$row['labor_amount_cents'] === $expectedLabor, 'Batch labor query changed original cents');
        }
    }
    check87(count($people['records']) === count($eligible)
        && (int)$summary['summary_row']['member_count'] === count($eligible), 'People scope changed qualification or disagrees with summary');
    foreach (['partner_item_summary', 'partner_item_detail'] as $code) {
        $query = $report->query($stores, $input + ['report'=>$code]);
        $export = $report->export($stores, $input + ['report'=>$code]);
        check87($query['records'] === $export['records'] && $query['summary_row'] === $export['summary_row'], 'Export mismatch: ' . $code);
    }
    $peopleExport = $report->export($stores, $input + ['report'=>'partner_item_detail', 'metric_code'=>'member_count']);
    check87($peopleExport['records'] === $people['records'], 'People export differs');
    foreach ($summary['records'] as $group) {
        $drillInput = $input + ['report'=>'partner_item_detail', 'category_path_exact'=>$group['category_path_snapshot']];
        $drill = $report->query($stores, $drillInput);
        foreach (['sale_amount_cents','consumption_amount_cents','labor_amount_cents'] as $metric) {
            check87(array_sum(array_column($drill['records'], $metric)) === (int)$group[$metric], 'Amount drilldown mismatch: ' . $metric);
        }
        $drillPeople = $report->query($stores, $drillInput + ['metric_code'=>'member_count']);
        check87((int)$drillPeople['total'] === (int)$group['member_count'], 'People drilldown mismatch');
    }
    $rawServices = (new StorePartnerIndependentServiceFacts())->read($stores, $range, []);
    $expectedServices = array_values(array_filter($rawServices,
        static fn(array $row): bool => isset($allowed[(int)$row['category_id_snapshot']])));
    $actualServices = array_values(array_filter($detail['records'],
        static fn(array $row): bool => $row['source_type'] === 'service'));
    check87(count($rawServices) > count($expectedServices), 'Need disabled independent-service regression fixture');
    $expectedIds = array_column($expectedServices, 'fact_id'); sort($expectedIds);
    $actualIds = array_column($actualServices, 'fact_id'); sort($actualIds);
    check87($actualIds === $expectedIds, 'Enabled service facts lost or disabled service facts included');
    $results['real_data'] = ['detail_rows'=>count($detail['records']), 'summary_rows'=>count($summary['records']),
        'store_id'=>$stores[0], 'source_types'=>array_count_values(array_column($detail['records'], 'source_type')),
        'qualified_people'=>count($eligible), 'excluded_service_rows'=>count($rawServices)-count($expectedServices), 'query_ms'=>$elapsed];

    // 原始成交人数资格不得随合作方报表开关改变；只允许报表读模型裁剪既定结果。
    $configQuery()->update(['enabled'=>0]);
    foreach (['partner_item_summary', 'partner_item_detail'] as $code) {
        $empty = $report->query($stores, $input + ['report'=>$code]);
        check87($empty['records'] === [] && (int)$empty['total'] === 0, 'All-disabled report not empty');
        check87($report->export($stores, $input + ['report'=>$code])['records'] === [], 'All-disabled export not empty');
    }
    check87($report->query($stores, $input + ['report'=>'partner_item_detail', 'metric_code'=>'member_count'])['records'] === [], 'All-disabled people drilldown not empty');
    $configQuery()->where('category_id', 26305)->update(['enabled'=>1]);
    $rootScope = invoke87($report, 'partnerReportCategoryIds', [$stores]);
    check87(isset($rootScope[26305], $rootScope[26316], $rootScope[26317], $rootScope[26339]), 'Enabled root must cover descendants');
    $configQuery()->where('category_id', 26317)->update(['enabled'=>1]);
    $childScope = invoke87($report, 'partnerReportCategoryIds', [$stores]);
    check87(isset($childScope[26317], $childScope[26339]) && !isset($childScope[26305]) && !isset($childScope[26316]), 'Configured second level must replace root, excluding sibling');
    // 临时开启真实服务所在分类，证明不是简单删除所有服务行；姓名空值不能替代分类资格。
    $configQuery()->update(['enabled'=>0]);
    $serviceCategory = 26332;
    if ($configQuery()->where('category_id', $serviceCategory)->count()) {
        $configQuery()->where('category_id', $serviceCategory)->update(['enabled'=>1]);
    } else {
        $fixture = $beforeConfig[0];
        unset($fixture['id']);
        $fixture['category_id'] = $serviceCategory;
        $fixture['enabled'] = 1;
        Db::name('cashier_v3_report_category_config')->insert($fixture);
    }
    $enabledServiceDetail = $report->query($stores, $input + ['report'=>'partner_item_detail']);
    $serviceActual = array_values(array_filter($enabledServiceDetail['records'],
        static fn(array $row): bool => $row['source_type'] === 'service'));
    $serviceExpected = array_values(array_filter($rawServices,
        static fn(array $row): bool => (int)$row['category_id_snapshot'] === $serviceCategory));
    check87($serviceExpected !== [] && count($serviceActual) === count($serviceExpected)
        && array_sum(array_column($serviceActual, 'labor_amount_cents')) === array_sum(array_column($serviceExpected, 'labor_amount_cents')),
        'Enabling category must retain its independent services and original labor cents');
    foreach ($enabledServiceDetail['records'] as $row) check87((int)$row['category_id_snapshot'] === $serviceCategory, 'Enabled-service scope leaked other categories');
    $rawCash = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory()->categoryRows(
        'cash_performance', $tenant, $stores, $range, [$serviceCategory], []);
    $rechargeExpected = array_values(array_filter($rawCash,
        static fn(array $row): bool => ($row['product_type_snapshot'] ?? '') === 'recharge'));
    $rechargeActual = array_values(array_filter($enabledServiceDetail['records'],
        static fn(array $row): bool => $row['source_type'] === 'recharge'));
    check87(array_sum(array_column($rechargeExpected, 'amount_cents')) === array_sum(array_column($rechargeActual, 'sale_amount_cents')),
        'Enabled recharge receipts must retain original cash amounts without a fabricated sale');
    $results['temporarily_enabled_category'] = ['service_rows'=>count($serviceActual), 'recharge_rows'=>count($rechargeActual)];
    $afterQualification = (new StoreDealPeopleQualificationServices())->qualifiedDays($stores, $range);
    check87($afterQualification === $qualification, 'Partner category switch changed deal-people algorithm');
    check87(hash_file('sha256', $qualificationFile) === $qualificationHash, 'Qualification source changed during test');
    $results['boundaries'] = ['all_disabled','root_descendants','second_level_overrides_root','enabled_services_retained','qualification_unchanged'];
} finally {
    Db::rollback();
}
check87($configQuery()->order('id')->select()->toArray() === $beforeConfig, 'Configuration rollback mismatch');
$results['result'] = 'PASS';
$results['config_restored'] = true;
echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
