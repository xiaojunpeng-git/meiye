<?php
declare(strict_types=1);

use app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

if ((string)(Db::getConfig()['connections']['mysql']['hostname'] ?? '') !== 'mysql') {
    throw new RuntimeException('local database required');
}

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
    echo "PASS {$message}\n";
};
$moneyCents = static function ($value): int {
    $text = trim((string)$value);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $text, $match)) {
        throw new RuntimeException('invalid report money: ' . $text);
    }
    $cents = ((int)$match[2] * 100) + (int)str_pad((string)($match[3] ?? ''), 2, '0');
    return ($match[1] ?? '') === '-' ? -$cents : $cents;
};
$findRepayment = static function (array $report, string $repaymentId): array {
    foreach ((array)($report['records'] ?? []) as $row) {
        if ((string)($row['repayment_id'] ?? '') === $repaymentId) return $row;
    }
    throw new RuntimeException('repayment report row not found: ' . $repaymentId);
};

// This local fixture is a completed single-item debt in the 花园/花园产品
// category. The transaction temporarily replays the production 980-yuan case
// and rolls every business/configuration change back after verification.
$repaymentId = 'DRP-a03c1efb1b281f58b06017193a8a330f98a9ba90';
$repayment = (array)Db::name('cashier_v3_debt_repayment')->where('repayment_id', $repaymentId)->find();
$assert($repayment !== [], 'local completed repayment fixture exists');
$debt = (array)Db::name('store_debt')->where('id', (int)$repayment['debt_id'])->find();
$items = Db::name('store_debt_item')->where('debt_id', (int)$repayment['debt_id'])->order('id')->select()->toArray();
$assert($debt !== [] && count($items) === 1, 'fixture has one authoritative debt item');
$payment = (array)Db::name('cashier_v3_payment_fact')->where('order_id', $repaymentId)
    ->where('source_document_type', 'debt_repayment')->where('status', 'effective')->find();
$allocation = (array)Db::name('cashier_v3_payment_sale_allocation_fact')
    ->where('payment_fact_id', (string)$payment['fact_id'])->where('status', 'effective')->find();
$sale = (array)Db::name('cashier_v3_sale_fact')->where('fact_id', (string)$allocation['sale_fact_id'])->find();
$config = (array)Db::name('cashier_v3_report_category_config')
    ->where('tenant_id', (string)$repayment['tenant_id'])->where('category_id', 26321)->find();
$assert($payment !== [] && $allocation !== [] && $sale !== [], 'repayment cash allocation fact chain exists');
$assert((int)($config['enabled'] ?? 0) === 1 && (int)($config['partner_default_ratio'] ?? 0) === 30,
    'fixture partner category is enabled at 30 percent');
$assert(!Db::name(CashierV3SalesDebtBalanceServices::TABLE)->where('repayment_id', $repaymentId)->count(),
    'fixture has no pre-existing settlement snapshot');

$service = new StoreUnifiedReportServices();
$input = [
    'report' => 'member_consumption_detail',
    'start_date' => (string)$repayment['business_date'],
    'end_date' => (string)$repayment['business_date'],
    '_internal_all' => true,
];
$itemId = (int)$items[0]['id'];
$originalPaymentAmount = (int)$payment['amount_cents'];
$originalAllocationAmount = (int)$allocation['amount_cents'];
$originalRepaidDebt = (string)$items[0]['repaid_debt'];
$originalRatio = (int)$config['partner_default_ratio'];

Db::startTrans();
try {
    // Recreate the reported 980-yuan supplement without retaining test money:
    // the item starts at 1,000 yuan and this repayment completes it at 1,980.
    Db::name('cashier_v3_payment_fact')->where('fact_id', (string)$payment['fact_id'])
        ->update(['amount_cents' => 98000]);
    Db::name('cashier_v3_payment_sale_allocation_fact')->where('id', (int)$allocation['id'])
        ->update(['amount_cents' => 98000]);
    Db::name('store_debt_item')->where('id', $itemId)->update(['repaid_debt' => '1000.00']);
    $items[0]['repaid_debt'] = '1000.00';

    $before = $service->query([(int)$repayment['store_id']], $input);
    $beforeRow = $findRepayment($before, $repaymentId);
    $assert($moneyCents($beforeRow['receipt_total']) === 98000
        && $moneyCents($beforeRow['partner_performance']) === 0
        && $moneyCents($beforeRow['actual_cash_performance']) === 98000,
        'legacy row starts as 980 receipt with no guessed partner share');

    CashierV3SalesDebtBalanceServices::freezeInTx(
        (string)$repayment['tenant_id'],
        $repaymentId,
        $debt,
        $items,
        [$itemId => 198000],
        [$itemId => ['authority' => ['order_line_id' => (string)$sale['source_line_id']]]],
        0
    );
    $snapshot = (array)Db::name(CashierV3SalesDebtBalanceServices::TABLE)
        ->where('repayment_id', $repaymentId)->where('debt_item_id', $itemId)->find();
    $assert((int)$snapshot['cash_performance_amount_cents'] === 98000
        && (int)$snapshot['partner_category_id_snapshot'] === 26321
        && (int)$snapshot['partner_default_ratio_snapshot'] === 30
        && (int)$snapshot['partner_share_amount_cents'] === 29400,
        'repayment freezes 980 cash and 294 partner share in the same transaction');

    $after = $service->query([(int)$repayment['store_id']], $input);
    $afterRow = $findRepayment($after, $repaymentId);
    $assert($moneyCents($afterRow['partner_category_26321']) === 29400
        && $moneyCents($afterRow['partner_performance']) === 29400
        && $moneyCents($afterRow['actual_cash_performance']) === 68600,
        'detail row reports 294 partner and 686 after-share performance');
    $assert($moneyCents($after['summary_row']['partner_category_26321'])
            - $moneyCents($before['summary_row']['partner_category_26321']) === 29400
        && $moneyCents($after['summary_row']['partner_performance'])
            - $moneyCents($before['summary_row']['partner_performance']) === 29400
        && $moneyCents($after['summary_row']['actual_cash_performance'])
            - $moneyCents($before['summary_row']['actual_cash_performance']) === -29400,
        'full-result summary uses the same frozen supplement share');

    $export = $service->export([(int)$repayment['store_id']], $input);
    $exportRow = $findRepayment($export, $repaymentId);
    $assert($moneyCents($exportRow['partner_performance']) === 29400
        && $moneyCents($exportRow['actual_cash_performance']) === 68600
        && $export['summary_row'] === $after['summary_row'],
        'export and screen share one detail and summary projection');

    Db::name('cashier_v3_report_category_config')->where('id', (int)$config['id'])
        ->update(['partner_default_ratio' => 5, 'version' => (int)$config['version'] + 1]);
    $changed = $service->query([(int)$repayment['store_id']], $input);
    $changedRow = $findRepayment($changed, $repaymentId);
    $assert($moneyCents($changedRow['partner_performance']) === 29400
        && $moneyCents($changedRow['actual_cash_performance']) === 68600,
        'later partner configuration changes do not rewrite history');
} finally {
    Db::rollback();
}

$assert((int)Db::name('cashier_v3_payment_fact')->where('fact_id', (string)$payment['fact_id'])->value('amount_cents')
        === $originalPaymentAmount
    && (int)Db::name('cashier_v3_payment_sale_allocation_fact')->where('id', (int)$allocation['id'])->value('amount_cents')
        === $originalAllocationAmount
    && (string)Db::name('store_debt_item')->where('id', $itemId)->value('repaid_debt') === $originalRepaidDebt
    && (int)Db::name('cashier_v3_report_category_config')->where('id', (int)$config['id'])->value('partner_default_ratio')
        === $originalRatio
    && !Db::name(CashierV3SalesDebtBalanceServices::TABLE)->where('repayment_id', $repaymentId)->count(),
    'transaction rollback restores all fixture and configuration facts');

echo "PASS {$checks} supplement partner-share checks\n";
