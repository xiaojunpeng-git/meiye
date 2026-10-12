<?php

declare(strict_types=1);

/**
 * 本地只读业务回归：从真实 G 来源补交和消费中选样，验证业务日期、合计、导出和来源说明。
 * 必须显式启用并指定补交单号；事务设为只读，不造单、不改余额、不回填事实。
 */
if (getenv('R13_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit local test flag required');
$repaymentNo = trim((string)getenv('R13_REPAYMENT_NO'));
if ($repaymentNo === '') throw new RuntimeException('R13_REPAYMENT_NO is required');
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
date_default_timezone_set('Asia/Shanghai');

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

/** 失败立即停止，不能把“无样本”或结构正确冒充业务验证通过。 */
function r13Check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
}

/** 金额比较回到分，避免浮点或页面整数格式掩盖对账差异。 */
function r13Cents(string $money): int
{
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $money, $parts)) {
        throw new RuntimeException('Invalid money: ' . $money);
    }
    $cents = (int)$parts[2] * 100 + (int)str_pad($parts[3] ?? '', 2, '0');
    return $parts[1] === '-' ? -$cents : $cents;
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
$testFailed = false;
try {
    $sample = Db::name('cashier_v3_payment_fact')->alias('p')
        ->join('cashier_v3_business_source s', 's.id=p.business_source_primary_id')
        ->where('p.order_no_snapshot', $repaymentNo)->where('p.status', 'effective')
        ->where('p.source_document_type', 'debt_repayment')->where('p.fact_direction', 'forward')
        ->where('s.parent_id', 0)->where('s.status', 1)->where('s.name', 'like', 'G%')
        ->field('p.*')->find();
    r13Check(is_array($sample), 'fixture is an effective G-source debt repayment');
    $storeId = (int)$sample['store_id'];
    $memberId = (int)$sample['member_id'];
    $day = (string)$sample['business_date'];
    $orderId = (string)$sample['order_id'];
    $service = new StoreUnifiedReportServices();
    $input = [
        'report' => 'cross_industry_customer_detail',
        'start_date' => date('Y-m-01', strtotime($day)),
        'end_date' => date('Y-m-t', strtotime($day)),
        'page' => 1, 'limit' => 20, '_report_scope' => ['mode' => 'stores'],
    ];
    $month = $service->query([$storeId], $input + ['_internal_all' => true]);
    $matches = array_values(array_filter($month['records'], static fn(array $row): bool => $row['order_id'] === $orderId));
    r13Check(count($matches) === 1, 'G-source repayment appears once, not duplicated by a supplement union');
    r13Check($matches[0]['receipt_business_date'] === $day && !array_key_exists('care_date', $matches[0]),
        'repayment displays its own business date, not the first care date');
    $labels = array_column($month['columns'], 'label', 'key');
    r13Check(($labels['receipt_business_date'] ?? '') === '业务日期' && count($labels) === 9
        && !isset($labels['care_date']) && !isset($labels['company']), 'business date replaces care date without adding organization columns');
    r13Check(array_keys($labels) === ['source', 'receipt_business_date', 'store_name', 'document_no', 'member_name', 'phone', 'full_payment', 'reward', 'remark'],
        'document number is immediately left of member and phone immediately right');
    r13Check($matches[0]['document_no'] === $repaymentNo, 'repayment displays its own document number');
    r13Check($matches[0]['phone'] === (string)Db::name('user')->where('uid', $memberId)->value('phone'), 'detail phone comes from the matching member profile');
    r13Check($month['metric_version'] === 'cross-industry-cash-business-date-v3', 'date semantic change has an explicit metric version');
    $expectedCents = (int)Db::name('cashier_v3_payment_fact')->where('order_id', $orderId)
        ->where('tenant_id', $sample['tenant_id'])->where('store_id', $storeId)->where('status', 'effective')
        ->whereBetween('business_date', [$input['start_date'], $input['end_date']])->sum('amount_cents');
    r13Check(r13Cents((string)$matches[0]['full_payment']) === $expectedCents, 'repayment row reconciles to collected cents, not original order total');
    $sum = array_sum(array_map(static fn(array $row): int => (int)$row['amount_cents'], $month['records']));
    r13Check(r13Cents((string)$month['summary_row']['full_payment']) === $sum, 'summary reconciles to all filtered rows');
    $export = $service->export([$storeId], $input);
    r13Check($export['records'] === $month['records'] && $export['summary_row'] === $month['summary_row'], 'export and query share the complete result and summary');
    r13Check($export['columns'] === $month['columns'], 'export and page share column explanations');

    $onDay = $service->query([$storeId], array_merge($input, ['start_date' => $day, 'end_date' => $day]));
    $outsideDay = date('Y-m-d', strtotime($day . ' +1 day'));
    $outside = $service->query([$storeId], array_merge($input, ['start_date' => $outsideDay, 'end_date' => $outsideDay]));
    r13Check(in_array($orderId, array_column($onDay['records'], 'order_id'), true)
        && !in_array($orderId, array_column($outside['records'], 'order_id'), true), 'repayment follows its collection date');

    $gSourceIds = Db::name('cashier_v3_business_source')->where('parent_id', 0)
        ->where('status', 1)->where('name', 'like', 'G%')->column('id');
    $nonGOrders = Db::name('cashier_v3_payment_fact')->where('store_id', $storeId)
        ->where('source_document_type', 'debt_repayment')->where('fact_direction', 'forward')
        ->whereBetween('business_date', [$input['start_date'], $input['end_date']])
        ->whereNotIn('business_source_primary_id', $gSourceIds)->column('order_id');
    r13Check(count($nonGOrders) > 0, 'local fixture includes other-source repayments');
    r13Check(!array_intersect($nonGOrders, array_column($month['records'], 'order_id')), 'other-source repayments are not mixed into G report');

    // 本地 118 店同月同时存在正常消费和补交；逐行对照收款事实，不以列名改对代替日期取值验证。
    $salesStoreId = (int)(getenv('R13_SALES_STORE_ID') ?: 118);
    $sales = $service->query([$salesStoreId], $input + ['_internal_all' => true]);
    $types = [];
    r13Check($sales['total'] > 0, 'normal-sale comparison store contains report data');
    foreach ($sales['records'] as $row) {
        $facts = Db::name('cashier_v3_payment_fact')->where('tenant_id', $sample['tenant_id'])
            ->where('store_id', $salesStoreId)->where('order_id', $row['order_id'])
            ->where('checkout_request_id', $row['checkout_request_id'])->where('member_id', $row['member_id'])
            ->where('business_date', $row['receipt_business_date'])->where('status', 'effective')
            ->whereIn('business_source_primary_id', $gSourceIds)->select()->toArray();
        r13Check(count($facts) > 0 && $row['receipt_business_date'] >= $input['start_date']
            && $row['receipt_business_date'] <= $input['end_date'], 'visible business date matches the row collection facts and query period');
        r13Check(array_sum(array_column($facts, 'amount_cents')) === (int)$row['amount_cents'],
            'each dated row reconciles to same-day collection cents');
        r13Check(count(array_unique(array_column($facts, 'order_no_snapshot'))) === 1
            && $row['document_no'] === $facts[0]['order_no_snapshot'], 'normal-sale and repayment document numbers match their receipt snapshots');
        $types = array_merge($types, array_column($facts, 'source_document_type'));
        $oneDay = $service->query([$salesStoreId], array_replace($input, [
            'start_date' => $row['receipt_business_date'], 'end_date' => $row['receipt_business_date'], '_internal_all' => true,
        ]));
        r13Check(in_array($row, $oneDay['records'], true), 'one-day query returns the same dated row as the month query');
    }
    r13Check(in_array('cashier_snapshot', $types, true) && in_array('debt_repayment', $types, true),
        'date regression covers both normal consumption and repayment');
    $salesExport = $service->export([$salesStoreId], $input);
    r13Check($salesExport['records'] === $sales['records'] && $salesExport['columns'] === $sales['columns']
        && $salesExport['summary_row'] === $sales['summary_row'], 'normal-sale and repayment export shares business dates, headings and summary');

    // 会员后续款入口不按来源过滤，用它验证作废排除，而非让 G 筛选掩盖作废测试。
    $followup = $service->query([$storeId], $input + ['member_id' => $memberId, 'include_followup_cash' => 1, '_internal_all' => true]);
    $voidIds = Db::name('cashier_v3_order_center_void_operation')->where('tenant_id', $sample['tenant_id'])
        ->where('store_id', $storeId)->where('source_kind', 'sales_supplement')->where('status', 'succeeded')->column('source_id');
    r13Check(count($voidIds) > 0, 'local fixture includes succeeded supplement voids');
    r13Check(!array_intersect($voidIds, array_column($followup['records'], 'order_id')), 'voided repayments remain excluded in follow-up view');
    r13Check(array_unique(array_column($followup['records'], 'member_id')) == [$memberId], 'follow-up view stays within the selected member');
    $noMember = $service->query([$storeId], $input + ['include_followup_cash' => 1, '_internal_all' => true]);
    r13Check($noMember['records'] === $month['records'], 'follow-up flag alone cannot broaden source range');
    $otherStore = $service->query([2147483647], $input);
    r13Check($otherStore['total'] === 0, 'store scope cannot reveal the repayment');
    $self = $service->query([$storeId], array_replace($input, ['_report_scope' => ['mode' => 'self_participant', 'employee_id' => 2147483647]]));
    r13Check($self['total'] === 0, 'unrelated personal scope cannot reveal the repayment');
    $denied = false;
    try {
        $service->query([$storeId], array_replace($input, ['_report_scope' => ['mode' => 'none']]));
    } catch (InvalidArgumentException $error) {
        $denied = true;
    }
    r13Check($denied, 'no-data permission fails closed');

    // 汇总名单不能仅靠销售事实：纯补交会员也要入表，正常销售与补交的同一门店会员只能一行。
    $summaryInput = array_replace($input, ['report' => 'cross_industry_customer_summary', '_internal_all' => true]);
    $summary = $service->query([$storeId], $summaryInput);
    $summaryMatches = array_values(array_filter($summary['records'], static fn(array $row): bool => (int)$row['member_id'] === $memberId));
    r13Check(count($summaryMatches) === 1, 'G repayment-only member appears once in annual summary');
    $summaryRow = $summaryMatches[0];
    r13Check(r13Cents($summaryRow['month_' . (int)substr($day, 5, 2)]) === $expectedCents, 'repayment month is included in summary');
    // 合并看多店时仍必须与逐店读取一致，不能在同一会员的不同门店行重复累计。
    $combined = $service->query([$storeId, $salesStoreId], $summaryInput);
    $combinedByKey = [];
    foreach ($combined['records'] as $row) $combinedByKey[$row['store_id'] . '|' . $row['member_id']] = $row;
    foreach ([$storeId, $salesStoreId] as $testStore) {
        $annual = $service->query([$testStore], $summaryInput);
        $rowKeys = array_map(static fn(array $row): string => $row['store_id'] . '|' . $row['member_id'], $annual['records']);
        r13Check(count($rowKeys) === count(array_unique($rowKeys)), 'summary deduplicates sale and repayment member entries');
        foreach ($annual['records'] as $row) {
            $combinedRow = $combinedByKey[$row['store_id'] . '|' . $row['member_id']] ?? [];
            foreach (['annual_cash', 'remaining_service_count', 'remaining_service_amount', 'first_visit_at'] as $field) {
                r13Check(($combinedRow[$field] ?? null) === $row[$field], 'combined-store query preserves per-store ' . $field);
            }
            $sumMonths = 0;
            foreach (range(1, 12) as $monthNo) $sumMonths += r13Cents($row['month_' . $monthNo]);
            r13Check(r13Cents($row['annual_cash']) === $sumMonths, 'annual amount equals all twelve months');
            foreach ((array)$row['_drilldown'] as $field => $drilldown) {
                r13Check(($drilldown['param_map']['store_ids'] ?? '') === 'store_id', 'cash drilldown retains exact row store scope');
                $drilled = $service->query([$testStore], array_merge($input, $drilldown['params'], [
                    'report' => $drilldown['report'], 'member_id' => $row['member_id'], '_internal_all' => true,
                ]));
                r13Check(r13Cents($drilled['summary_row']['full_payment']) === r13Cents($row[$field]), 'summary cash drilldown exactly explains its cell');
            }
        }
        $annualExport = $service->export([$testStore], $summaryInput);
        r13Check($annualExport['records'] === $annual['records'] && $annualExport['summary_row'] === $annual['summary_row'], 'annual summary export matches the query');
    }
    r13Check($service->query([2147483647], $summaryInput)['total'] === 0, 'annual summary preserves store permission');
    r13Check($service->query([$storeId], array_replace($summaryInput, ['_report_scope' => ['mode' => 'self_participant', 'employee_id' => 2147483647]]))['total'] === 0,
        'annual summary preserves personal participant permission');
    $resolver = new \app\services\report\StoreReportParticipantScopeServices();
    r13Check($summaryRow['annotation_subject_type'] === 'cross_industry_repayment'
        && (int)$resolver->resolveSubject((string)$sample['tenant_id'], 'cross_industry_repayment', $summaryRow['annotation_subject_key'])['store_id'] === $storeId,
        'repayment-only manual fields bind to a real scoped receipt');
    r13Check($resolver->resolveSubject((string)$sample['tenant_id'], 'cross_industry_repayment', $summaryRow['annotation_subject_key'], 2147483647) === null,
        'manual subject rejects an unrelated employee');

    $explanations = array_column($month['columns'], 'source_explanation', 'key');
    $followupExplanations = array_column($followup['columns'], 'source_explanation', 'key');
    r13Check(count($explanations) === 9, 'all nine visible columns have individual explanations');
    foreach ($explanations as $key => $explanation) {
        r13Check($explanation !== '' && !preg_match('/统一事实口径|payment_fact|source_document|SQL|API/', $explanation), $key . ' uses business language');
    }
    r13Check(strpos($explanations['source'], '补交按本次补交选择的来源') !== false
        && strpos($explanations['full_payment'], '本次补交日期') !== false, 'explanations state repayment source and date rules');
    r13Check(strpos($explanations['receipt_business_date'], '本次补交业务日期') !== false
        && strpos($explanations['receipt_business_date'], '查询周期也按此日期筛选') !== false,
        'date explanation matches both displayed dates and filtering');
    r13Check(strpos($followupExplanations['source'], '各类来源收款') !== false
        && $followupExplanations['source'] !== $explanations['source'], 'follow-up explanation matches its different source range');
    echo json_encode(['repayment_no' => $repaymentNo, 'store_id' => $storeId, 'amount_cents' => $expectedCents,
        'month_total' => $month['total'], 'summary' => $month['summary_row']['full_payment'],
        'writes' => 0], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    // Think 的默认异常处理可能以 0 退出；测试失败必须返回非零，并仍执行只读事务清理。
    $testFailed = true;
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
} finally {
    Db::rollback();
}
if ($testFailed) exit(1);
