<?php
declare(strict_types=1);

/** Local-only transactional refund/personnel/salary probe; no customer change survives. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local DB only.');
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.status', 1)->where('s.is_del', 0)->where('s.level', 0)
    ->field('s.*,os.org_id')->find();
$storeId = (int)$staff['store_id'];
$operator = new CashierV3OperatorScope($storeId, (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $storeId, '0',
    (string)$staff['org_id'], [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r32-refund-personnel', ['cashier.v3.order_center', 'cashier.v3.order.refund', 'cashier.v3.order.staff_adjust'], $staff);
$personnel = new CashierV3RefundSalespersonServices();
$entry = $personnel->selection(['mode' => 'card', 'memberId' => 978855, 'holderId' => 2074451], $operator, $scope);
$normal = array_values(array_filter($entry['salespeople'], static fn(array $p): bool => !$p['performanceIndependent']));
if (count($normal) < 2) throw new RuntimeException('Two normal salesperson fixtures are required.');
$card = array_values(array_filter((new CashierV3UnifiedRefundServices())->candidates([
    'memberId' => 978855, 'mode' => 'card',
], $operator, $scope)['cards'], static fn(array $c): bool => (int)$c['holderId'] === 2074451))[0] ?? null;
if (!$card || !$card['canRefund']) throw new RuntimeException('Refund card fixture unavailable.');
$oldSaleCount = $entry['sourceSalesOrderId'] !== '' ? (int)Db::name('cashier_v3_performance_fact')
    ->where('order_id', $entry['sourceSalesOrderId'])->count() : 0;
$dispatcher = CashierV3Bootstrap::dispatcher();
$key = static function (): string {
    $hex = bin2hex(random_bytes(16));
    return 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
        . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
};
$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
Db::startTrans();
try {
    $refundKey = $key();
    $payload = ['mode' => 'card', 'memberId' => 978855, 'holderId' => 2074451,
        'componentId' => (int)$card['components'][0]['id'], 'lineId' => '',
        'refundAmount' => '101', 'balanceAmount' => '0', 'balanceKind' => 'bonus',
        'voidTarget' => false, 'validThrough' => 0, 'businessDate' => $date,
        'reason' => 'R32事务回滚验证', 'salespeople' => [
            ['staffId' => $normal[0]['staffId'], 'allocationWeight' => 33],
            ['staffId' => $normal[1]['staffId'], 'allocationWeight' => 67],
        ]];
    $response = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $refundKey, 'contexts' => []], $payload, $operator,
        ['client_session_id' => 'SESSION-' . substr($refundKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    if (($response['status'] ?? '') !== 'success') throw new RuntimeException('Refund failed: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
    $operationId = (string)$response['data']['unifiedRefund']['operationId'];
    $operationNo = (string)$response['data']['unifiedRefund']['operationNo'];
    $facts = Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)
        ->where('performance_type', 'refund_sales_deduction')->where('fact_direction', 'forward')
        ->order('id asc')->column('amount_cents');
    if (array_map('intval', $facts) !== [-3300, -6800]) throw new RuntimeException('Whole-yuan split/tail mismatch: ' . json_encode($facts));
    $report = new StoreUnifiedReportPhaseSixServices();
    $salary = $report->query('phase_six_salary_detail', [$storeId], ['start' => $date, 'end' => $date],
        ['document_no' => $operationNo, '_internal_all' => true]);
    $cash = array_sum(array_map(static fn(array $r): int => (int)($r['cash_amount_cents'] ?? 0), $salary['records']));
    if ($cash !== -10100 || count($salary['records']) !== 2) throw new RuntimeException('Salary refund mismatch: ' . json_encode($salary['records'], JSON_UNESCAPED_UNICODE));
    $current = $personnel->entry(['recordId' => $operationId], $operator, $scope);
    if ((int)$current['recordVersion'] !== 1 || count($current['currentSalespeople']) !== 2) throw new RuntimeException('Refund personnel snapshot mismatch.');
    // 迁移前退款没有人员快照。仅有人员调整权限的账号仍应能读取原单默认草稿。
    Db::name('cashier_v3_refund_adjustment')->where('operation_id', $operationId)
        ->update(['salespeople_version' => 0, 'salespeople_snapshot_json' => null]);
    $adjustOnlyScope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $storeId, '0',
        (string)$staff['org_id'], [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false,
        '', 'r32-adjust-only', ['cashier.v3.order.staff_adjust'], $staff);
    $legacyDraft = $personnel->entry(['recordId' => $operationId], $operator, $adjustOnlyScope);
    if ((int)$legacyDraft['recordVersion'] !== 0 || !array_key_exists('currentSalespeople', $legacyDraft)) {
        throw new RuntimeException('Legacy refund personnel read requires refund creation permission.');
    }
    Db::name('cashier_v3_refund_adjustment')->where('operation_id', $operationId)
        ->update(['salespeople_version' => 1, 'salespeople_snapshot_json' => json_encode($current['currentSalespeople'], JSON_UNESCAPED_UNICODE)]);
    $adjustKey = $key();
    $changed = $dispatcher->gateway()->execute('adjust-refund-personnel',
        ['idempotencyKey' => $adjustKey, 'contexts' => []], [
            'recordId' => $operationId, 'recordVersion' => 1, 'reason' => 'R32改派回滚验证',
            'personnel' => [['staffId' => $normal[1]['staffId'], 'allocationWeight' => 100]],
        ], $operator, ['client_session_id' => 'SESSION-' . substr($adjustKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('adjust-refund-personnel'), $scope,
        CashierV3ActionManifest::requireAction('adjust-refund-personnel'));
    if (($changed['status'] ?? '') !== 'success') throw new RuntimeException('Adjust failed: ' . json_encode($changed, JSON_UNESCAPED_UNICODE));
    $replayed = $dispatcher->gateway()->execute('adjust-refund-personnel',
        ['idempotencyKey' => $adjustKey, 'contexts' => []], [
            'recordId' => $operationId, 'recordVersion' => 1, 'reason' => 'R32改派回滚验证',
            'personnel' => [['staffId' => $normal[1]['staffId'], 'allocationWeight' => 100]],
        ], $operator, ['client_session_id' => 'SESSION-' . substr($adjustKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('adjust-refund-personnel'), $scope,
        CashierV3ActionManifest::requireAction('adjust-refund-personnel'));
    $revisionCount = (int)Db::name('cashier_v3_refund_salesperson_revision')->where('operation_id', $operationId)->count();
    if (!in_array((string)($replayed['status'] ?? ''), ['success', 'succeeded'], true) || $revisionCount !== 1) {
        throw new RuntimeException('Retry duplicated refund personnel revision: ' . json_encode([
            'response' => $replayed, 'revisionCount' => $revisionCount], JSON_UNESCAPED_UNICODE));
    }
    $staleKey = $key();
    try {
        $dispatcher->gateway()->execute('adjust-refund-personnel',
            ['idempotencyKey' => $staleKey, 'contexts' => []], [
                'recordId' => $operationId, 'recordVersion' => 1, 'reason' => '过期版本',
                'personnel' => [['staffId' => $normal[0]['staffId'], 'allocationWeight' => 100]],
            ], $operator, ['client_session_id' => 'SESSION-' . substr($staleKey, 4), 'operator_profile' => $staff],
            $dispatcher->handlers()->requireCommand('adjust-refund-personnel'), $scope,
            CashierV3ActionManifest::requireAction('adjust-refund-personnel'));
        throw new RuntimeException('Stale refund personnel version was accepted.');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '已被修改')) throw $error;
    }
    if ((int)Db::name('cashier_v3_refund_salesperson_revision')->where('operation_id', $operationId)->count() !== 1) {
        throw new RuntimeException('Stale refund personnel version was accepted.');
    }
    $salary = $report->query('phase_six_salary_detail', [$storeId], ['start' => $date, 'end' => $date],
        ['document_no' => $operationNo, '_internal_all' => true]);
    $cash = array_sum(array_map(static fn(array $r): int => (int)($r['cash_amount_cents'] ?? 0), $salary['records']));
    if ($cash !== -10100 || count($salary['records']) !== 1) throw new RuntimeException('Salary after reassignment mismatch.');
    $previousDate = (new DateTimeImmutable($date, new DateTimeZone('Asia/Shanghai')))
        ->modify('-1 day')->format('Y-m-d');
    $dateKey = $key();
    $dateChange = $dispatcher->gateway()->execute('correct-refund-date',
        ['idempotencyKey' => $dateKey, 'contexts' => []], [
            'recordType' => 'refund', 'recordId' => $operationId, 'field' => 'business_date',
            'expectedValue' => $date, 'newValue' => $previousDate,
        ], $operator, ['client_session_id' => 'SESSION-' . substr($dateKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('correct-refund-date'), $scope,
        CashierV3ActionManifest::requireAction('correct-refund-date'));
    if (($dateChange['status'] ?? '') !== 'success') throw new RuntimeException('Refund date correction failed: ' . json_encode($dateChange, JSON_UNESCAPED_UNICODE));
    $moved = Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)
        ->where('performance_type', 'refund_sales_deduction')->where('business_date', $previousDate)->count();
    if ($moved !== 5) throw new RuntimeException('Refund deduction/reversal dates not moved together.');
    $salary = $report->query('phase_six_salary_detail', [$storeId],
        ['start' => $previousDate, 'end' => $previousDate],
        ['document_no' => $operationNo, '_internal_all' => true]);
    $cash = array_sum(array_map(static fn(array $r): int => (int)($r['cash_amount_cents'] ?? 0), $salary['records']));
    if ($cash !== -10100 || count($salary['records']) !== 1) throw new RuntimeException('Salary did not follow refund date.');
    if ($entry['sourceSalesOrderId'] !== '' && (int)Db::name('cashier_v3_performance_fact')
        ->where('order_id', $entry['sourceSalesOrderId'])->count() !== $oldSaleCount) {
        throw new RuntimeException('Original sale performance facts changed.');
    }
    $independent = array_values(array_filter($entry['salespeople'],
        static fn(array $person): bool => $person['performanceIndependent']))[0] ?? null;
    if (!$independent) throw new RuntimeException('Independent-position fixture unavailable.');
    $groupKey = $key();
    $groupPayload = $payload;
    $groupPayload['refundAmount'] = '1';
    $groupPayload['balanceAmount'] = '0';
    $groupPayload['salespeople'] = [
        ['staffId' => $normal[0]['staffId'], 'allocationWeight' => 100],
        ['staffId' => $independent['staffId'], 'allocationWeight' => 100],
    ];
    $groupResponse = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $groupKey, 'contexts' => []], $groupPayload, $operator,
        ['client_session_id' => 'SESSION-' . substr($groupKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    if (($groupResponse['status'] ?? '') !== 'success') throw new RuntimeException('Independent refund failed.');
    $groupOperationId = (string)$groupResponse['data']['unifiedRefund']['operationId'];
    $employeeDeduction = (int)Db::name('cashier_v3_performance_fact')->where('order_id', $groupOperationId)
        ->where('performance_type', 'refund_sales_deduction')->sum('amount_cents');
    $businessRefund = (int)Db::name('cashier_v3_payment_fact')->where('order_id', $groupOperationId)
        ->where('fact_type', 'manual_refund_adjustment')->sum('amount_cents');
    if ($employeeDeduction !== -200 || $businessRefund !== -100) {
        throw new RuntimeException('Employee independent groups must not duplicate the non-employee refund amount.');
    }
    // 手填一人的最终业绩时，剩余自动分配仍只能产生整元，不能残留分级尾差。
    $manualKey = $key();
    $manualPayload = $payload;
    $manualPayload['salespeople'] = [
        ['staffId' => $normal[0]['staffId'], 'allocationWeight' => 33,
            'performanceAmountManual' => true, 'performanceAmountCents' => 5000],
        ['staffId' => $normal[1]['staffId'], 'allocationWeight' => 67],
    ];
    $manualResponse = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $manualKey, 'contexts' => []], $manualPayload, $operator,
        ['client_session_id' => 'SESSION-' . substr($manualKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    if (($manualResponse['status'] ?? '') !== 'success') throw new RuntimeException('Mixed manual refund failed.');
    $manualFacts = Db::name('cashier_v3_performance_fact')
        ->where('order_id', (string)$manualResponse['data']['unifiedRefund']['operationId'])
        ->where('performance_type', 'refund_sales_deduction')->order('id asc')->column('amount_cents');
    if (array_map('intval', $manualFacts) !== [-5000, -6700]) {
        throw new RuntimeException('Mixed manual/automatic refund produced fractional yuan.');
    }
    // 单据退款沿用同一人员合同，但权威来源是正式销售明细，不是旧卡签发凭证。
    $documentStaff = (array)Db::name('system_store_staff')->alias('s')
        ->join('organization_store os', 'os.store_id=s.store_id')
        ->where('s.store_id', 115)->where('s.status', 1)->where('s.is_del', 0)->where('s.level', 0)
        ->field('s.*,os.org_id')->find();
    if (!$documentStaff) throw new RuntimeException('Document store fixture unavailable.');
    $documentOperator = new CashierV3OperatorScope(115, (int)$documentStaff['id'], (string)$documentStaff['org_id'], '0');
    $documentScope = new CashierV3DataScopeContext((int)$documentStaff['id'], (int)$documentStaff['id'], 115, '0',
        (string)$documentStaff['org_id'], [115], CashierV3DataScopeContext::MODE_STORES, [], false,
        '', 'r32-document-refund', ['cashier.v3.order_center', 'cashier.v3.order.refund'], $documentStaff);
    $documentLines = (new CashierV3UnifiedRefundServices())->candidates([
        'mode' => 'document', 'memberId' => 1020312,
    ], $documentOperator, $documentScope)['lines'];
    $documentLine = array_values(array_filter($documentLines,
        static fn(array $line): bool => (int)$line['remainingAmountCents'] >= 1000))[0] ?? null;
    if (!$documentLine) throw new RuntimeException('Document line fixture unavailable.');
    $documentPersonnel = $personnel->selection([
        'mode' => 'document', 'memberId' => 1020312, 'lineId' => $documentLine['lineId'],
    ], $documentOperator, $documentScope)['salespeople'][0] ?? null;
    if (!$documentPersonnel) throw new RuntimeException('Document salesperson fixture unavailable.');
    $documentKey = $key();
    $documentResponse = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $documentKey, 'contexts' => []], [
            'mode' => 'document', 'memberId' => 1020312, 'holderId' => 0,
            'componentId' => 0, 'lineId' => $documentLine['lineId'],
            'refundAmount' => '10', 'balanceAmount' => '0', 'balanceKind' => 'bonus',
            'voidTarget' => false, 'validThrough' => 0, 'businessDate' => $date,
            'reason' => 'R32单据事务回滚验证',
            'salespeople' => [['staffId' => $documentPersonnel['staffId'], 'allocationWeight' => 100]],
        ], $documentOperator,
        ['client_session_id' => 'SESSION-' . substr($documentKey, 4), 'operator_profile' => $documentStaff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $documentScope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    if (($documentResponse['status'] ?? '') !== 'success') throw new RuntimeException('Document refund failed: ' . json_encode($documentResponse, JSON_UNESCAPED_UNICODE));
    $documentId = (string)$documentResponse['data']['unifiedRefund']['operationId'];
    $documentDeduction = (int)Db::name('cashier_v3_performance_fact')->where('order_id', $documentId)
        ->where('performance_type', 'refund_sales_deduction')->sum('amount_cents');
    if ($documentDeduction !== -1000) throw new RuntimeException('Document refund salary deduction mismatch.');
    echo 'PASS card/document salary, 33/68 tail, manual whole yuan, independent groups, one business refund, reassignment/replay/stale version, date correction, legacy permissions, original sale isolation', PHP_EOL;
} catch (Throwable $error) {
    $failure = $error;
} finally {
    Db::rollback();
}
if (isset($failure)) {
    file_put_contents('php://stderr', $failure->getMessage() . PHP_EOL);
    exit(1);
}
