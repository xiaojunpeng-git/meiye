<?php

declare(strict_types=1);

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\report\StoreUnifiedReportOrganizationDimensionServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

// Read-only fixture: the page and export share order identities and historical category labels.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/');
$app->initialize();

$tenantId = CashierV3ScopeResolver::TENANT_SCOPE_ID;
$serviceSample = Db::name('cashier_v3_performance_fact')->alias('pf')
    ->join('cashier_v3_entitlement_service_fact sv',
        'sv.tenant_id=pf.tenant_id AND sv.checkout_request_id=pf.checkout_request_id AND sv.source_line_id=pf.source_line_id', 'INNER')
    ->where('pf.tenant_id', $tenantId)->where('pf.status', 'effective')
    ->where('pf.fact_direction', 'forward')->where('pf.performance_type', 'labor_performance_allocated')
    ->where('sv.service_status', 'completed')->where('sv.service_record_no', '<>', '')
    ->field('pf.fact_id,pf.store_id,pf.employee_id,pf.business_date,pf.checkout_request_id,sv.project_category_name_snapshot expected_category')
    ->order('pf.id', 'desc')->find();
$saleSample = Db::name('cashier_v3_performance_fact')->alias('pf')
    ->join('cashier_v3_sales_order so',
        "so.tenant_id=pf.tenant_id AND so.store_id=pf.store_id AND so.checkout_request_id=pf.checkout_request_id AND so.order_status='settled' AND so.order_direction='forward'", 'INNER')
    ->join('cashier_v3_sale_fact s',
        "s.tenant_id=pf.tenant_id AND s.checkout_request_id=pf.checkout_request_id AND s.source_line_id=pf.source_line_id AND s.fact_direction='forward' AND s.status='effective'", 'INNER')
    ->join('cashier_v3_report_sale_dimension_fact d',
        'd.tenant_id=pf.tenant_id AND d.sale_fact_id=s.fact_id', 'INNER')
    ->leftJoin('cashier_v3_entitlement_service_fact sv',
        'sv.tenant_id=pf.tenant_id AND sv.checkout_request_id=pf.checkout_request_id AND sv.source_line_id=pf.source_line_id')
    ->where('pf.tenant_id', $tenantId)->where('pf.status', 'effective')
    ->where('pf.fact_direction', 'forward')->where('pf.performance_type', 'sales_performance_allocated')
    ->whereNull('sv.id')->where('d.category_path_snapshot', '<>', '')
    ->field('pf.fact_id,pf.store_id,pf.employee_id,pf.business_date,pf.checkout_request_id,d.category_path_snapshot expected_category')
    ->order('pf.id', 'desc')->find();
if (!$serviceSample && !$saleSample) {
    echo "SKIP no salary facts with order center references in local fixture\n";
    exit(0);
}

/** Resolve independently from the same authoritative order headers used by order center. */
function expectedOrderReference(string $tenantId, array $sample): array
{
    $where = ['tenant_id' => $tenantId, 'store_id' => (int)$sample['store_id'],
        'checkout_request_id' => (string)$sample['checkout_request_id']];
    $sale = Db::name('cashier_v3_sales_order')->where($where)
        ->where('order_status', 'settled')->where('order_direction', 'forward')
        ->field('order_no,order_id')->find();
    if ($sale) return [(string)$sale['order_no'], (string)$sale['order_id']];
    $service = Db::name('cashier_v3_entitlement_service_fact')->where($where)
        ->where('service_status', 'completed')
        ->fieldRaw("MIN(COALESCE(NULLIF(service_record_no,''),service_fact_id)) order_no")
        ->find();
    $number = trim((string)($service['order_no'] ?? ''));
    return $number !== '' ? [$number, 'service:' . $sample['checkout_request_id']] : ['-', ''];
}

$service = new StoreUnifiedReportPhaseSixServices();
$dimensions = new StoreUnifiedReportOrganizationDimensionServices();
$checked = 0;
foreach ([$serviceSample, $saleSample] as $sample) {
    if (!$sample) continue;
    $storeId = (int)$sample['store_id'];
    $range = ['start' => (string)$sample['business_date'], 'end' => (string)$sample['business_date']];
    $detail = $service->query('phase_six_salary_detail', [$storeId], $range);
    $export = $service->query('phase_six_salary_detail', [$storeId], $range, ['_internal_all' => true]);
    $summary = $service->query('phase_six_salary_summary', [$storeId], $range);
    $keys = array_column($detail['columns'], 'key');
    $column = $detail['columns'][array_search('document_no', $keys, true) ?: 0] ?? [];
    $categoryColumn = $detail['columns'][array_search('performance_category', $keys, true) ?: 0] ?? [];
    if (($column['label'] ?? '') !== '单据号'
        || ($categoryColumn['label'] ?? '') !== '商品分类'
        || ($column['drilldown']['param_map']['order_id'] ?? '') !== 'document_order_id'
        || in_array('card_type', $keys, true)
        || in_array('service_visit_count', $keys, true)
        || $keys !== array_column($export['columns'], 'key')
        || !in_array('service_visit_count', array_column($summary['columns'], 'key'), true)) {
        throw new RuntimeException('salary detail/export columns, detail link, or summary visits regressed');
    }
    $record = null;
    foreach ($detail['records'] as $candidate) {
        if (($candidate['row_key'] ?? '') === $sample['fact_id']) { $record = $candidate; break; }
    }
    if (!$record) throw new RuntimeException('salary sample missing from detail');
    [$expectedNo, $expectedId] = expectedOrderReference($tenantId, $sample);
    $expectedCompany = $dimensions->resolve('company', '', '', $range['end'], $storeId)['name'];
    $summaryRecord = null;
    foreach ($summary['records'] as $candidate) {
        if ((int)($candidate['employee_id'] ?? 0) === (int)$sample['employee_id']) {
            $summaryRecord = $candidate;
            break;
        }
    }
    if (($record['document_no'] ?? '') !== $expectedNo
        || ($record['document_order_id'] ?? '') !== $expectedId
        || ($record['company_name'] ?? '') !== $expectedCompany
        || ($summaryRecord['company_name'] ?? '') !== $expectedCompany
        || ($record['performance_category'] ?? '') !== (string)$sample['expected_category']
        || isset($record['card_type']) || isset($record['service_visit_count'])) {
        throw new RuntimeException('salary order link or historical product category differs from its facts');
    }
    $exportRecord = null;
    foreach ($export['records'] as $candidate) {
        if (($candidate['row_key'] ?? '') === $sample['fact_id']) { $exportRecord = $candidate; break; }
    }
    if (($exportRecord['document_no'] ?? '') !== $expectedNo
        || ($exportRecord['company_name'] ?? '') !== $expectedCompany
        || ($exportRecord['performance_category'] ?? '') !== $record['performance_category']) {
        throw new RuntimeException('salary export document or product category differs from detail');
    }
    $checked++;
}
echo "PASS salary detail/summary/export company dimensions, order references and product categories for $checked facts\n";
