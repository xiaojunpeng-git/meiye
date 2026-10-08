<?php
declare(strict_types=1);

/** Local-only transaction probe: empty refund personnel must not create employee deductions. */
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
if ((string)config('database.connections.mysql.hostname') !== 'mysql'
    || (string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Local ruihao DB only.');
}

$dispatcher = CashierV3Bootstrap::dispatcher();
$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$key = static function (): string {
    $hex = bin2hex(random_bytes(16));
    return 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
        . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
};
$operatorFor = static function (int $storeId) use ($key): array {
    $staff = (array)Db::name('system_store_staff')->alias('s')
        ->join('organization_store os', 'os.store_id=s.store_id')
        ->where('s.store_id', $storeId)->where('s.status', 1)->where('s.is_del', 0)
        ->where('s.level', 0)->field('s.*,os.org_id')->find();
    if (!$staff) throw new RuntimeException('Local operator fixture unavailable: ' . $storeId);
    $operator = new CashierV3OperatorScope($storeId, (int)$staff['id'], (string)$staff['org_id'], '0');
    $scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $storeId, '0',
        (string)$staff['org_id'], [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false,
        '', 'optional-refund-' . $key(), ['cashier.v3.order_center', 'cashier.v3.order.refund',
            'cashier.v3.order.staff_adjust'], $staff);
    return [$staff, $operator, $scope];
};
$execute = static function (string $action, array $payload, array $staff, CashierV3OperatorScope $operator,
    CashierV3DataScopeContext $scope) use ($dispatcher, $key): array {
    $commandKey = $key();
    return $dispatcher->gateway()->execute($action, ['idempotencyKey' => $commandKey, 'contexts' => []],
        $payload, $operator, ['client_session_id' => 'SESSION-' . substr($commandKey, 4),
            'operator_profile' => $staff], $dispatcher->handlers()->requireCommand($action), $scope,
        CashierV3ActionManifest::requireAction($action));
};

Db::startTrans();
try {
    [$cardStaff, $cardOperator, $cardScope] = $operatorFor(102);
    $cardMember = 978855;
    $cards = (new CashierV3UnifiedRefundServices())->candidates([
        'mode' => 'card', 'memberId' => $cardMember,
    ], $cardOperator, $cardScope)['cards'];
    $card = array_values(array_filter($cards, static fn(array $row): bool =>
        (int)$row['holderId'] === 2074451 && !empty($row['canRefund'])))[0] ?? null;
    if (!$card || empty($card['components'][0]['id'])) throw new RuntimeException('Local card fixture unavailable.');
    [$documentStaff, $documentOperator, $documentScope] = $operatorFor(115);
    $documentMember = 1020312;
    $lines = (new CashierV3UnifiedRefundServices())->candidates([
        'mode' => 'document', 'memberId' => $documentMember,
    ], $documentOperator, $documentScope)['lines'];
    $line = array_values(array_filter($lines, static fn(array $row): bool =>
        (int)$row['remainingAmountCents'] >= 100))[0] ?? null;
    if (!$line) throw new RuntimeException('Local document fixture unavailable.');
    $items = (new CashierV3UnifiedRefundServices())->candidates([
        'mode' => 'catalog', 'memberId' => $cardMember,
    ], $cardOperator, $cardScope)['items'];
    $item = $items[0] ?? null;
    if (!$item) throw new RuntimeException('Local catalog fixture unavailable.');

    $cases = [
        ['card', $cardMember, $cardStaff, $cardOperator, $cardScope,
            ['holderId' => 2074451, 'componentId' => (int)$card['components'][0]['id']]],
        ['document', $documentMember, $documentStaff, $documentOperator, $documentScope,
            ['lineId' => (string)$line['lineId']]],
        ['catalog', $cardMember, $cardStaff, $cardOperator, $cardScope,
            ['catalogItemId' => (int)$item['skuId']]],
    ];
    foreach ($cases as [$mode, $memberId, $staff, $operator, $scope, $target]) {
        $payload = array_merge([
            'mode' => $mode, 'memberId' => $memberId, 'holderId' => 0,
            'componentId' => 0, 'lineId' => '', 'catalogItemId' => 0,
            'refundAmount' => '1', 'balanceAmount' => '0', 'balanceKind' => 'bonus',
            'voidTarget' => false, 'validThrough' => 0, 'businessDate' => $date,
            'reason' => '可选销售人本地回滚验证', 'salespeople' => [],
        ], $target);
        $response = $execute('refund-card-or-line', $payload, $staff, $operator, $scope);
        if (($response['status'] ?? '') !== 'success') throw new RuntimeException($mode . ' refund failed: '
            . json_encode($response, JSON_UNESCAPED_UNICODE));
        $result = $response['data']['unifiedRefund'];
        $operationId = (string)$result['operationId'];
        $row = (array)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $operationId)->find();
        if ((int)$row['salespeople_version'] !== 1 || (string)$row['salespeople_snapshot_json'] !== '[]') {
            throw new RuntimeException($mode . ' empty personnel snapshot was not persisted.');
        }
        if ((int)Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)
            ->where('performance_type', 'refund_sales_deduction')->count() !== 0) {
            throw new RuntimeException($mode . ' refund deducted an employee without selection.');
        }
        if ((int)Db::name('cashier_v3_payment_fact')->where('order_id', $operationId)
            ->where('fact_type', 'manual_refund_adjustment')->sum('amount_cents') !== -100) {
            throw new RuntimeException($mode . ' refund lost the non-employee cash adjustment.');
        }
        $salary = (new StoreUnifiedReportPhaseSixServices())->query('phase_six_salary_detail',
            [$operator->storeId()], ['start' => $date, 'end' => $date],
            ['document_no' => (string)$result['operationNo'], '_internal_all' => true]);
        if (array_sum(array_map(static fn(array $record): int =>
            (int)($record['cash_amount_cents'] ?? 0), $salary['records'])) !== 0) {
            throw new RuntimeException($mode . ' empty personnel created salary deduction.');
        }
        echo "Optional {$mode} refund passed\n";
        if ($mode !== 'catalog') continue;

        // A later assignment and a subsequent clear must net to zero, preserving both audit revisions.
        $personnel = new CashierV3RefundSalespersonServices();
        $entry = $personnel->entry(['recordId' => $operationId], $operator, $scope);
        $candidate = $entry['salespeople'][0] ?? null;
        if (!$candidate || (int)$entry['recordVersion'] !== 1 || $entry['currentSalespeople'] !== []) {
            throw new RuntimeException('Empty personnel cannot be opened for editing.');
        }
        $assign = $execute('adjust-refund-personnel', [
            'recordId' => $operationId, 'recordVersion' => 1,
            'reason' => '补分配', 'personnel' => [[
                'staffId' => (int)$candidate['staffId'], 'allocationWeight' => 100,
            ]],
        ], $staff, $operator, $scope);
        if (($assign['status'] ?? '') !== 'success') throw new RuntimeException('Assignment from empty failed.');
        $clear = $execute('adjust-refund-personnel', [
            'recordId' => $operationId, 'recordVersion' => 2,
            'reason' => '清空分配', 'personnel' => [],
        ], $staff, $operator, $scope);
        if (($clear['status'] ?? '') !== 'success') throw new RuntimeException('Clearing assignment failed.');
        $entry = $personnel->entry(['recordId' => $operationId], $operator, $scope);
        $revisionCount = (int)Db::name('cashier_v3_refund_salesperson_revision')
            ->where('operation_id', $operationId)->count();
        $deductionFacts = Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)
            ->where('performance_type', 'refund_sales_deduction')
            ->field('fact_direction,amount_cents,reversal_of')->select()->toArray();
        // Reversal facts keep the original signed amount; direction is the accounting sign.
        $netDeduction = array_sum(array_map(static fn(array $fact): int =>
            (int)$fact['amount_cents'] * ($fact['fact_direction'] === 'reversal' ? -1 : 1),
            $deductionFacts));
        $clearedSalary = (new StoreUnifiedReportPhaseSixServices())->query('phase_six_salary_detail',
            [$operator->storeId()], ['start' => $date, 'end' => $date],
            ['document_no' => (string)$result['operationNo'], '_internal_all' => true]);
        $clearedSalaryCents = array_sum(array_map(static fn(array $record): int =>
            (int)($record['cash_amount_cents'] ?? 0), $clearedSalary['records']));
        if ((int)$entry['recordVersion'] !== 3 || $entry['currentSalespeople'] !== []
            || $revisionCount !== 2 || count($deductionFacts) !== 2
            || $netDeduction !== 0 || $clearedSalaryCents !== 0) {
            throw new RuntimeException('Clearing personnel did not reverse the salary deduction: '
                . json_encode(['version' => $entry['recordVersion'],
                    'selected' => $entry['currentSalespeople'], 'revisionCount' => $revisionCount,
                    'salaryCents' => $clearedSalaryCents, 'facts' => $deductionFacts], JSON_UNESCAPED_UNICODE));
        }
        echo "Optional personnel reassignment and clear passed\n";
    }
} finally {
    Db::rollback();
}
