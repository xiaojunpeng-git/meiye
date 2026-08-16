<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 3);
$backend = is_dir('/workspace/后端代码') ? '/workspace/后端代码'
    : (is_dir('/var/www/html/app') ? '/var/www/html' : $repoRoot . '/后端代码');
require $backend . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportPhaseThreeServices;
use app\services\report\StoreOperationsReportAnnotationServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.type' => 'mysql', 'DATABASE_TYPE' => 'mysql',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql',
    'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306',
    'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao',
    'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao',
    'database.username' => getenv('DB_USERNAME') ?: 'root',
    'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123',
    'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) {
    $app->env->set($key, $value);
}
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'phase_three_report_smoke_skip_dotenv_reload');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$stores = array_values(array_map('intval', Db::name('system_store')
    ->where('is_del', 0)->where('name', '<>', '总部')->order('id', 'asc')->column('id')));
if ($stores === []) {
    throw new RuntimeException('六维报表数据库烟测至少需要一个本地门店');
}

$service = new StoreUnifiedReportPhaseThreeServices();
$failed = 0;
$input = [
    'page' => 1,
    'limit' => 10,
    '_authorized_store_ids' => $stores,
    '_report_scope' => ['mode' => 'all'],
];
$allInput = array_merge($input, ['_internal_all' => true]);
$reportResults = [];
$annotationRollbackExpectation = null;

Db::startTrans();
try {
    if (!Db::name('store_product_category')->where('pid', 0)->where('is_show', 1)->where('cate_name', '六维')->find()) {
        Db::name('store_product_category')->insert([
            'pid' => 0, 'type' => 0, 'relation_id' => 0, 'sync_cate_id' => 0,
            'cate_name' => '六维', 'path' => '', 'level' => 0, 'sort' => 0,
            'pic' => '', 'is_show' => 1, 'mobile_card_show' => 1,
            'add_time' => time(), 'big_pic' => '', 'adv_pic' => '', 'adv_link' => '',
        ]);
    }
$expectedHeaders = [
    'six_dimension_item_deal_analysis' => [
        '分公司', '门店', '商品名称', '体验人数', '购买人数', '成交率', '购买次数', '购买金额', '平均销售价格', '平均单产',
    ],
    'six_dimension_cash_consumption_analysis' => [
        '分公司', '门店', '消费分级', '累计消费人数', '人数占比', '分类消费金额', '总消费金额', '分类消费金额占比',
    ],
    'six_dimension_consumption_refund_detail' => [
        '分公司', '门店', '操作师', '商品名称', '购买日期', '耗卡金额', '咨询师', '实收业绩', '转卡业绩', '客诉数量', '退款金额',
    ],
    'six_dimension_performance_deal' => [
        '分公司', '门店', '老客见诊人次', '新客见诊人次/购买', '新客见诊人次/赠送',
        '老客成交人次', '新客成交人次/购买', '新客成交人次/赠送', '老客成交业绩',
        '新客成交业绩/购买', '新客成交业绩/赠送', '老客成交单产',
        '新客成交单产/购买', '新客成交单产/赠送',
    ],
    'six_dimension_performance_distribution' => [
        '城市经理', '城市经理店面数量', '上月/完成金额', '上月/应完成业绩', '上月/完成率', '上月/完成率排名',
        '本月/完成金额', '本月/应完成业绩', '本月/完成率', '本月/完成率排名',
    ],
];

foreach (StoreUnifiedReportPhaseThreeServices::reportCodes() as $reportCode) {
    try {
        $range = $reportCode === 'six_dimension_performance_distribution'
            ? ['start' => '2026-10-01', 'end' => '2026-10-31']
            : ['start' => StoreUnifiedReportPhaseThreeServices::COVERAGE_START, 'end' => StoreUnifiedReportPhaseThreeServices::COVERAGE_START];
        $startedAt = microtime(true);
        $result = $service->query($reportCode, $stores, $range, $input);
        $pagedMilliseconds = (microtime(true) - $startedAt) * 1000;
        $startedAt = microtime(true);
        $allResult = $service->query($reportCode, $stores, $range, $allInput);
        $allMilliseconds = (microtime(true) - $startedAt) * 1000;
        $reportResults[$reportCode] = ['paged' => $result, 'all' => $allResult, 'range' => $range];
        $columns = (array)($result['columns'] ?? []);
        $explanationsComplete = $columns !== [];
        foreach ($columns as $column) {
            if (trim((string)($column['label'] ?? '')) === ''
                || trim((string)($column['source_explanation'] ?? '')) === '') {
                $explanationsComplete = false;
                break;
            }
        }
        $headers = array_map(static function (array $column): string {
            $group = trim((string)($column['group_label'] ?? ''));
            return ($group === '' ? '' : $group . '/') . (string)($column['label'] ?? '');
        }, $columns);
        $headersMatch = $reportCode === 'six_dimension_performance_market_distribution'
            ? count($headers) >= 2
                && $headers[0] === '月份'
                && $headers[1] === '集团六维完成业绩'
                && count(array_filter(array_slice($headers, 2), static function (string $header): bool {
                    return substr($header, -strlen('/完成金额')) === '/完成金额'
                        || substr($header, -strlen('/业绩占比')) === '/业绩占比';
                })) === count($headers) - 2
            : $headers === ($expectedHeaders[$reportCode] ?? []);
        $summary = (array)($result['summary_row'] ?? []);
        $summaryContract = $columns !== []
            && array_key_exists((string)$columns[0]['key'], $summary)
            && $summary[(string)$columns[0]['key']] === '合计';
        foreach ($columns as $index => $column) {
            $key = (string)($column['key'] ?? '');
            if ($key === '' || !array_key_exists($key, $summary)) {
                $summaryContract = false;
                break;
            }
            if ($index > 0 && empty($column['summable']) && $summary[$key] !== '-') {
                $summaryContract = false;
                break;
            }
        }
        $pageAndAllConsistent = $result['columns'] === $allResult['columns']
            && $result['summary_row'] === $allResult['summary_row']
            && (int)$result['total'] === (int)$allResult['total'];
        $valid = $explanationsComplete
            && $headersMatch
            && $summaryContract
            && $pageAndAllConsistent
            && is_array($result['records'] ?? null)
            && is_array($allResult['records'] ?? null)
            && is_array($result['summary_row'] ?? null)
            && (string)($result['metric_version'] ?? '') === StoreUnifiedReportPhaseThreeServices::METRIC_VERSION
            && !empty($result['table_layout']['fixed']);
        if (!$valid) {
            $failed++;
            echo "FAIL {$reportCode}: unified response or Excel header contract incomplete\n";
            continue;
        }
        echo 'PASS ' . $reportCode . ': columns=' . count($columns)
            . ' page_records=' . count((array)$result['records'])
            . ' all_records=' . count((array)$allResult['records'])
            . ' total=' . (int)$result['total']
            . ' summary=PASS page_all=PASS'
            . ' query_ms(page=' . number_format($pagedMilliseconds, 2, '.', '')
            . ',all=' . number_format($allMilliseconds, 2, '.', '') . ")\n";
    } catch (Throwable $error) {
        $failed++;
        echo 'FAIL ' . $reportCode . ': ' . get_class($error) . ': ' . $error->getMessage() . "\n";
    }
}

$marketCode = 'six_dimension_performance_market_distribution';
$marketFixture = (array)($reportResults[$marketCode] ?? []);
if ($marketFixture !== []) {
    $marketResult = (array)$marketFixture['all'];
    $marketColumns = (array)($marketResult['columns'] ?? []);
    $dynamicAmountColumns = [];
    $dynamicCompanyIds = [];
    foreach (array_slice($marketColumns, 2) as $index => $column) {
        $key = (string)($column['key'] ?? '');
        if ($index % 2 === 0 && preg_match('/^company_(.+)_amount$/D', $key, $matches) === 1) {
            $shareColumn = (array)($marketColumns[$index + 3] ?? []);
            $expectedShareKey = 'company_' . $matches[1] . '_share';
            if ((string)($shareColumn['key'] ?? '') !== $expectedShareKey
                || (string)($shareColumn['group_label'] ?? '') !== (string)($column['group_label'] ?? '')) {
                $failed++;
                echo "FAIL market distribution dynamic amount/share columns are not adjacent pairs\n";
                break;
            }
            $dynamicAmountColumns[$key] = $expectedShareKey;
            $dynamicCompanyIds[] = (string)$matches[1];
        }
    }
    if (count(array_slice($marketColumns, 2)) !== count($dynamicAmountColumns) * 2) {
        $failed++;
        echo "FAIL market distribution dynamic columns must be complete amount/share pairs\n";
    }

    $zeroAmountChecks = 0;
    foreach ((array)($marketResult['records'] ?? []) as $record) {
        foreach ($dynamicAmountColumns as $amountKey => $shareKey) {
            if ((float)($record[$amountKey] ?? 0) !== 0.0) continue;
            $zeroAmountChecks++;
            if (($record[$shareKey] ?? null) !== '-') {
                $failed++;
                echo "FAIL market distribution zero company amount must show '-' share: {$amountKey}\n";
            }
        }
    }
    if ($dynamicAmountColumns !== [] && $zeroAmountChecks === 0) {
        $futureRange = ['start' => '2099-12-01', 'end' => '2099-12-31'];
        $futureMarket = $service->query($marketCode, $stores, $futureRange, $allInput);
        foreach ((array)($futureMarket['records'] ?? []) as $record) {
            foreach ((array)($futureMarket['columns'] ?? []) as $column) {
                $amountKey = (string)($column['key'] ?? '');
                if (preg_match('/^company_(.+)_amount$/D', $amountKey, $matches) !== 1
                    || (float)($record[$amountKey] ?? 0) !== 0.0) continue;
                $zeroAmountChecks++;
                $shareKey = 'company_' . $matches[1] . '_share';
                if (($record[$shareKey] ?? null) !== '-') {
                    $failed++;
                    echo "FAIL market distribution future zero company amount must show '-' share: {$amountKey}\n";
                }
            }
        }
    }
    if ($dynamicAmountColumns !== [] && $zeroAmountChecks === 0) {
        $failed++;
        echo "FAIL market distribution did not provide a zero company amount row for share validation\n";
    } else {
        echo "PASS market distribution zero-amount share checks={$zeroAmountChecks}\n";
    }

    if ($dynamicCompanyIds !== []) {
        $rangeDate = (string)$marketFixture['range']['end'];
        $configured = Db::name('cashier_v3_report_organization_dimension')
            ->where('tenant_id', '0')->where('dimension_code', 'company')->where('enabled', 1)
            ->whereIn('organization_id', $dynamicCompanyIds)->where('valid_from', '<=', $rangeDate)
            ->where(function ($query) use ($rangeDate): void {
                $query->whereNull('valid_to')->whereOr('valid_to', '>=', $rangeDate);
            })
            ->field('id,organization_id,organization_name_snapshot,display_order')->select()->toArray();
        $configuredByOrganization = [];
        foreach ($configured as $row) $configuredByOrganization[(string)$row['organization_id']] = $row;
        $expectedOrder = $dynamicCompanyIds;
        usort($expectedOrder, static function (string $left, string $right) use ($configuredByOrganization): int {
            $leftConfig = (array)($configuredByOrganization[$left] ?? []);
            $rightConfig = (array)($configuredByOrganization[$right] ?? []);
            return (int)($leftConfig['display_order'] ?? PHP_INT_MAX) <=> (int)($rightConfig['display_order'] ?? PHP_INT_MAX)
                ?: strcmp((string)($leftConfig['organization_name_snapshot'] ?? ''), (string)($rightConfig['organization_name_snapshot'] ?? ''));
        });
        if (count($configuredByOrganization) !== count($dynamicCompanyIds) || $dynamicCompanyIds !== $expectedOrder) {
            $failed++;
            echo "FAIL market distribution company columns do not follow configured display order\n";
        } else {
            echo 'PASS market distribution company order=' . implode(',', $dynamicCompanyIds) . "\n";
        }
    }
}

$detailCode = 'six_dimension_consumption_refund_detail';
$detailFixture = (array)($reportResults[$detailCode] ?? []);
$detailRecords = (array)($detailFixture['all']['records'] ?? []);
if ($detailRecords !== []) {
    $target = (array)$detailRecords[0];
    $subjectKey = (string)($target['annotation_subject_key'] ?? '');
    $storeId = (int)($target['store_id'] ?? 0);
    $existingAnnotation = Db::name('cashier_v3_report_annotation')->where('tenant_id', '0')
        ->where('report_code', $detailCode)->where('subject_type', 'business_event_line')
        ->where('subject_key', $subjectKey)->where('field_key', 'complaint_count')->find();
    $annotationRollbackExpectation = [
        'subject_key' => $subjectKey,
        'existed' => is_array($existingAnnotation),
        'value' => (string)($existingAnnotation['field_value'] ?? ''),
        'version' => (int)($existingAnnotation['version'] ?? 0),
    ];
    $complaintValue = (string)($target['complaint_count'] ?? '') === '37' ? '38' : '37';
    $annotationService = new StoreOperationsReportAnnotationServices();
    $annotationService->saveAnnotation([
        'tenant_id' => '0', 'store_id' => $storeId, 'store_ids' => $stores,
        'operator_id' => 0, 'operator_name' => '第三阶段查询烟测', 'authorization_mode' => 'stores',
    ], [
        'store_id' => $storeId, 'report_code' => $detailCode,
        'subject_type' => 'business_event_line', 'subject_key' => $subjectKey,
        'field_key' => 'complaint_count', 'field_value' => $complaintValue,
        'expected_version' => (int)($target['complaint_count_version'] ?? 0),
        'idempotency_key' => 'phase3-query-smoke-' . substr(hash('sha256', $subjectKey), 0, 40),
        'source_fact_id' => (int)($target['source_fact_id'] ?? 0),
        'source_order_id' => (string)($target['source_order_id'] ?? ''),
        'source_line_id' => (string)($target['source_line_id'] ?? ''),
    ]);
    $range = (array)$detailFixture['range'];
    $detailPagedAfterSave = $service->query($detailCode, $stores, $range, $input);
    $detailAllAfterSave = $service->query($detailCode, $stores, $range, $allInput);
    $findSubject = static function (array $records, string $subjectKey): ?array {
        foreach ($records as $record) {
            if ((string)($record['annotation_subject_key'] ?? '') === $subjectKey) return $record;
        }
        return null;
    };
    $pageRow = $findSubject((array)$detailPagedAfterSave['records'], $subjectKey);
    $allRow = $findSubject((array)$detailAllAfterSave['records'], $subjectKey);
    $manualTotal = 0;
    foreach ((array)$detailAllAfterSave['records'] as $record) {
        $value = trim((string)($record['complaint_count'] ?? ''));
        if ($value !== '') $manualTotal += (int)$value;
    }
    $annotationMerged = is_array($pageRow) && is_array($allRow)
        && (string)$pageRow['complaint_count'] === $complaintValue
        && (string)$allRow['complaint_count'] === $complaintValue
        && $detailPagedAfterSave['summary_row'] === $detailAllAfterSave['summary_row']
        && (int)($detailAllAfterSave['summary_row']['complaint_count'] ?? -1) === $manualTotal;
    if (!$annotationMerged) {
        $failed++;
        echo "FAIL complaint count was not merged consistently into page, all-query and summary\n";
    } else {
        echo "PASS complaint count save/read/all-query/summary value={$complaintValue}\n";
    }
} else {
    echo "SKIP complaint count query merge: no real consumption/refund event rows\n";
}

$restrictedCompany = Db::name('cashier_v3_report_organization_dimension')->alias('d')
    ->join('organization_store os', 'os.org_id = d.organization_id')
    ->join('system_store s', 's.id = os.store_id')
    ->where('d.tenant_id', '0')->where('d.dimension_code', 'company')->where('d.enabled', 1)
    ->where('s.is_del', 0)->where('s.is_show', 1)
    ->field('d.organization_name_snapshot,os.store_id')->order('d.display_order', 'asc')->find();
if ($restrictedCompany) {
    $restrictedStoreId = (int)$restrictedCompany['store_id'];
    $restrictedInput = array_merge($input, [
        '_authorized_store_ids' => [$restrictedStoreId],
    ]);
    $restrictedResult = $service->query(
        'six_dimension_performance_market_distribution',
        [$restrictedStoreId],
        ['start' => StoreUnifiedReportPhaseThreeServices::COVERAGE_START, 'end' => StoreUnifiedReportPhaseThreeServices::COVERAGE_START],
        $restrictedInput
    );
    $visibleCompanies = array_values(array_unique(array_filter(array_map(static function (array $column): string {
        return trim((string)($column['group_label'] ?? ''));
    }, array_slice((array)($restrictedResult['columns'] ?? []), 2)))));
    if ($visibleCompanies !== [(string)$restrictedCompany['organization_name_snapshot']]) {
        $failed++;
        echo 'FAIL restricted market distribution exposes unauthorized company columns: '
            . implode(',', $visibleCompanies) . "\n";
    } else {
        echo 'PASS restricted market distribution exposes only the authorized company column' . "\n";
    }
}
} finally {
    Db::rollback();
}

if (is_array($annotationRollbackExpectation)) {
    $rolledBack = Db::name('cashier_v3_report_annotation')->where('tenant_id', '0')
        ->where('report_code', 'six_dimension_consumption_refund_detail')
        ->where('subject_type', 'business_event_line')
        ->where('subject_key', (string)$annotationRollbackExpectation['subject_key'])
        ->where('field_key', 'complaint_count')->find();
    $rollbackValid = !empty($annotationRollbackExpectation['existed'])
        ? is_array($rolledBack)
            && (string)$rolledBack['field_value'] === (string)$annotationRollbackExpectation['value']
            && (int)$rolledBack['version'] === (int)$annotationRollbackExpectation['version']
        : !is_array($rolledBack);
    if (!$rollbackValid) {
        $failed++;
        echo "FAIL complaint count transaction did not roll back to its original state\n";
    } else {
        echo "PASS complaint count transaction rollback restored original state\n";
    }
}

echo "PHASE3_REPORT_QUERY_SMOKE failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
