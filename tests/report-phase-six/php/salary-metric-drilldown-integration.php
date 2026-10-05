<?php

declare(strict_types=1);

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

// Read-only RH fixture: clicked salary cells must reconcile with authorized detail/export rows.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/');
$app->initialize();

$range = ['start' => '2026-10-01', 'end' => '2026-10-05'];
$storeId = (int)Db::name('cashier_v3_entitlement_service_fact')
    ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
    ->where('store_name_snapshot', '江苏江都店')->where('service_status', 'completed')
    ->whereBetween('business_date', [$range['start'], $range['end']])->value('store_id');
if ($storeId <= 0) {
    echo "SKIP Jiangdu salary fixture not available\n";
    exit(0);
}

try {
    $service = new StoreUnifiedReportPhaseSixServices();
    $summary = $service->query('phase_six_salary_summary', [$storeId], $range, ['_internal_all' => true]);
    $metrics = ['project_count', 'service_visit_count', 'service_people_count', 'consumption_amount'];
    $employeeIndex = array_search('employee_name', array_column($summary['columns'], 'key'), true);
    if (($summary['columns'][$employeeIndex + 1]['key'] ?? '') !== 'position_name') {
        throw new RuntimeException('summary position must follow employee');
    }
    $servicesByLine = [];
    foreach (Db::name('cashier_v3_entitlement_service_fact')
        ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
        ->where('store_id', $storeId)->where('service_status', 'completed')
        ->whereBetween('business_date', [$range['start'], $range['end']])
        ->field('source_line_id,service_object,member_id,business_date')->select()->toArray() as $serviceFact) {
        $servicesByLine[(string)$serviceFact['source_line_id']] = $serviceFact;
    }
    $checked = 0;
    $contextRowsChecked = 0;
    foreach ($summary['records'] as $row) {
        if ((int)$row['employee_id'] <= 0) continue;
        foreach ($metrics as $metric) {
            $columnIndex = array_search($metric, array_column($summary['columns'], 'key'), true);
            $column = $summary['columns'][$columnIndex] ?? [];
            if (($column['drilldown']['report'] ?? '') !== 'phase_six_salary_detail') {
                throw new RuntimeException("$metric has no declared salary detail drilldown");
            }
            $input = ['_internal_all' => true, 'salary_employee_id' => $row['employee_id'],
                'salary_store_id' => $row['store_id'], 'salary_metric_key' => $metric];
            $detail = $service->query('phase_six_salary_detail', [$storeId], $range, $input);
            if (($detail['summary_row'][$metric] ?? null) !== (string)$row[$metric]) {
                throw new RuntimeException("$metric detail total differs for employee {$row['employee_id']}: {$detail['summary_row'][$metric]} vs {$row[$metric]}");
            }
            $detailKeys = array_column($detail['columns'], 'key');
            $detailEmployeeIndex = array_search('employee_name', $detailKeys, true);
            if (($detailKeys[$detailEmployeeIndex + 1] ?? '') !== 'position_name') {
                throw new RuntimeException('detail position must follow employee');
            }
            $visitOrders = [];
            $visitMemberDays = [];
            if ($metric === 'service_visit_count') foreach ($detail['records'] as $fact) {
                if ((float)($fact['service_visit_count'] ?? 0) === 0.0) continue;
                $visitOrders[(string)$fact['document_no']] = true;
                $serviceFact = $servicesByLine[(string)$fact['source_line_id']] ?? [];
                if (($serviceFact['service_object'] ?? '') === 'self' && (int)($serviceFact['member_id'] ?? 0) > 0) {
                    $visitMemberDays[(string)$serviceFact['business_date'] . '|' . (int)$serviceFact['member_id']] = true;
                }
            }
            foreach ($detail['records'] as $fact) {
                if ((int)$fact['store_id'] !== $storeId || (int)$fact['employee_id'] !== (int)$row['employee_id']) {
                    throw new RuntimeException('salary metric drilldown widened the employee/store scope');
                }
                if ((float)($fact[$metric] ?? 0) === 0.0) {
                    // 跨单零人次只可来自同一会员本人同一天的已完成服务；朋友和游客不能按姓名串单。
                    $serviceFact = $servicesByLine[(string)$fact['source_line_id']] ?? [];
                    $memberDay = (string)($serviceFact['business_date'] ?? '') . '|' . (int)($serviceFact['member_id'] ?? 0);
                    $sameMemberDay = ($serviceFact['service_object'] ?? '') === 'self'
                        && isset($visitMemberDays[$memberDay]);
                    if ($metric !== 'service_visit_count'
                        || (!isset($visitOrders[(string)$fact['document_no']]) && !$sameMemberDay)) {
                        throw new RuntimeException('salary metric drilldown contains an unrelated noncontributing row');
                    }
                }
            }
            if ($metric === 'service_visit_count') {
                $plainContext = $service->query('phase_six_salary_detail', [$storeId], $range,
                    array_diff_key($input, ['salary_metric_key' => true]));
                $visibleKeys = array_fill_keys(array_column($detail['records'], 'row_key'), true);
                foreach ($plainContext['records'] as $fact) {
                    $serviceFact = $servicesByLine[(string)$fact['source_line_id']] ?? [];
                    if (!$serviceFact) continue;
                    $memberDay = (string)$serviceFact['business_date'] . '|' . (int)$serviceFact['member_id'];
                    $sameMemberDay = ($serviceFact['service_object'] ?? '') === 'self'
                        && isset($visitMemberDays[$memberDay]);
                    if (!isset($visitOrders[(string)$fact['document_no']]) && !$sameMemberDay) continue;
                    if (!isset($visibleKeys[(string)$fact['row_key']])) {
                        throw new RuntimeException('salary visit drilldown omitted a completed service from the clicked scope');
                    }
                    $contextRowsChecked++;
                }
            }
            if ($metric === 'service_visit_count' && !in_array($metric, $detailKeys, true)) {
                throw new RuntimeException('service-visit drilldown omitted the contributing value');
            }
            if ($metric === 'service_people_count' && !in_array($metric, $detailKeys, true)) {
                throw new RuntimeException('service-people drilldown omitted the contributing value');
            }
            $checked++;
        }
    }
    if ($checked < 4 || $contextRowsChecked === 0) {
        throw new RuntimeException('fixture had no salary metric cells or completed service context to verify');
    }

    // Discover a real cross-checkout member/employee grouping, rather than pinning an order number or row count.
    $targetRange = ['start' => '2026-10-05', 'end' => '2026-10-05'];
    $candidates = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
        ->join('cashier_v3_performance_fact pf',
            'pf.tenant_id=sv.tenant_id AND pf.checkout_request_id=sv.checkout_request_id AND pf.source_line_id=sv.source_line_id')
        ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
        ->where('sv.service_status', 'completed')->where('sv.service_object', 'self')->where('sv.member_id', '>', 0)
        ->where('sv.business_date', $targetRange['start'])
        ->where('pf.performance_type', 'labor_performance_allocated')
        ->where('pf.status', 'effective')->where('pf.fact_direction', 'forward')
        ->field('sv.store_id,pf.employee_id,sv.member_id,COUNT(DISTINCT sv.checkout_request_id) order_count')
        ->group('sv.store_id,pf.employee_id,sv.member_id')->having('order_count > 1')->limit(50)->select()->toArray();
    $crossOrderChecked = false;
    foreach ($candidates as $candidate) {
        $candidateStore = (int)$candidate['store_id'];
        $candidateEmployee = (int)$candidate['employee_id'];
        $candidateMember = (int)$candidate['member_id'];
        $input = ['_internal_all' => true, 'salary_employee_id' => $candidateEmployee,
            'salary_store_id' => $candidateStore];
        $plainContext = $service->query('phase_six_salary_detail', [$candidateStore], $targetRange, $input);
        $visitContext = $service->query('phase_six_salary_detail', [$candidateStore], $targetRange,
            $input + ['salary_metric_key' => 'service_visit_count']);
        $summaryContext = $service->query('phase_six_salary_summary', [$candidateStore], $targetRange,
            ['_internal_all' => true]);
        $summaryRows = array_values(array_filter($summaryContext['records'],
            static fn(array $row): bool => (int)$row['store_id'] === $candidateStore
                && (int)$row['employee_id'] === $candidateEmployee));
        if (count($summaryRows) !== 1
            || ($visitContext['summary_row']['service_visit_count'] ?? '') !== ($summaryRows[0]['service_visit_count'] ?? null)) {
            throw new RuntimeException('discovered employee visit drilldown differs from the clicked summary value');
        }
        $memberServices = [];
        foreach (Db::name('cashier_v3_entitlement_service_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $candidateStore)->where('business_date', $targetRange['start'])
            ->where('service_status', 'completed')->where('service_object', 'self')
            ->where('member_id', $candidateMember)->field('source_line_id')->select()->toArray() as $fact) {
            $memberServices[(string)$fact['source_line_id']] = true;
        }
        $positiveOrders = [];
        $visibleKeys = array_fill_keys(array_column($visitContext['records'], 'row_key'), true);
        foreach ($visitContext['records'] as $row) {
            if (isset($memberServices[(string)$row['source_line_id']])
                && (float)$row['service_visit_count'] > 0.0) $positiveOrders[(string)$row['document_no']] = true;
        }
        if ($positiveOrders === []) continue;
        $zeroOrder = '';
        foreach ($plainContext['records'] as $row) {
            if (!isset($memberServices[(string)$row['source_line_id']])) continue;
            if (!isset($visibleKeys[(string)$row['row_key']])) {
                throw new RuntimeException('cross-checkout member service omitted from clicked employee detail');
            }
            if (!isset($positiveOrders[(string)$row['document_no']])) $zeroOrder = (string)$row['document_no'];
        }
        if ($zeroOrder === '') continue;
        $filtered = $service->query('phase_six_salary_detail', [$candidateStore], $targetRange,
            $input + ['salary_metric_key' => 'service_visit_count', 'document_no' => $zeroOrder]);
        $expectedKeys = array_column(array_values(array_filter($visitContext['records'],
            static fn(array $row): bool => (string)$row['document_no'] === $zeroOrder)), 'row_key');
        $actualKeys = array_column($filtered['records'], 'row_key');
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($expectedKeys !== $actualKeys) {
            throw new RuntimeException('document-number filter does not match clicked service rows');
        }
        $crossOrderChecked = true;
        break;
    }
    if (!$crossOrderChecked) throw new RuntimeException('fixture had no verifiable cross-checkout member service');

    $plain = $service->query('phase_six_salary_detail', [$storeId], $range, ['_internal_all' => true]);
    if (in_array('service_visit_count', array_column($plain['columns'], 'key'), true)) {
        throw new RuntimeException('ordinary salary detail unexpectedly restored the removed visits column');
    }
    $document = null;
    foreach ($plain['records'] as $fact) {
        if (preg_match('/^[A-Z]+[0-9]+$/', (string)($fact['document_no'] ?? ''))) {
            $document = (string)$fact['document_no'];
            break;
        }
    }
    if ($document !== null) {
        $filtered = $service->query('phase_six_salary_detail', [$storeId], $range,
            ['_internal_all' => true, 'document_no' => $document]);
        if (!$filtered['records'] || ($filtered['filters']['document_no'] ?? '') !== $document) {
            throw new RuntimeException('document-number filter returned no matching rows');
        }
        foreach ($filtered['records'] as $fact) {
            if ((string)$fact['document_no'] !== $document) throw new RuntimeException('document filter leaked another order');
        }
    }
    echo "PASS $checked salary metric drilldowns reconcile with detail; positions and document filter verified\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL salary metric drilldown: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
