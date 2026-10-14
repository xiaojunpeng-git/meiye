<?php
declare(strict_types=1);

// Read-only local regression: the refund is a cash adjustment, never a new visit.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreMemberConsumptionRefundReadServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

if ((string)Db::query('SELECT DATABASE() db')[0]['db'] !== 'ruihao') {
    throw new RuntimeException('This probe is restricted to the local ruihao database.');
}
$cashFacts = Db::name('cashier_v3_payment_fact')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
    ->where('store_id', 23)->where('member_id', 1130058)->where('status', 'effective');
// The local member can receive more completed refunds after this probe is written.
// Reconcile to the current effective cents instead of freezing a former balance.
$annualCashCents = (int)(clone $cashFacts)->whereBetween('business_date', ['2026-01-01', '2026-12-31'])
    ->sum('amount_cents');
$octoberCashCents = (int)(clone $cashFacts)->whereBetween('business_date', ['2026-10-01', '2026-10-31'])
    ->sum('amount_cents');
$input = ['report' => 'member_visit_analysis', 'start_date' => '2026-10-13',
    'end_date' => '2026-10-13', 'page' => 1, 'page_size' => 20];
$service = new StoreUnifiedReportServices();
foreach (['page' => $service->query(23, $input), 'export' => $service->export(23, $input)] as $kind => $result) {
    $rows = array_values(array_filter($result['records'], static fn(array $row): bool =>
        (int)($row['member_id'] ?? 0) === 1130058));
    if (count($rows) !== 1 || ($kind === 'page' && (int)($result['total'] ?? 0) < 1)
        || (int)$rows[0]['annual_cash_cents'] !== $annualCashCents
        || (int)$rows[0]['month_10_cash_cents'] !== $octoberCashCents
        || (int)$rows[0]['total_visits'] !== 1
        || (int)$rows[0]['month_10_visits'] !== 1) {
        throw new RuntimeException("{$kind} must include the completed refund once without adding a visit.");
    }
    echo "PASS {$kind}: refund-date member, annual/month net cash, existing service visit and total\n";
}
$otherStore = $service->query(126, $input);
if (in_array(1130058, array_map('intval', array_column($otherStore['records'], 'member_id')), true)) {
    throw new RuntimeException('Another store must not read the member refund.');
}
echo "PASS another store remains isolated\n";
$combined = $service->query(23, array_replace($input, ['start_date' => '2026-10-10']));
$combinedRows = array_values(array_filter($combined['records'], static fn(array $row): bool =>
    (int)($row['member_id'] ?? 0) === 1130058));
if (count($combinedRows) !== 1 || (int)$combinedRows[0]['month_10_cash_cents'] !== $octoberCashCents
    || (int)$combinedRows[0]['annual_cash_cents'] !== $annualCashCents
    || (int)$combinedRows[0]['total_visits'] !== 2) {
    throw new RuntimeException('Including the sale and refund dates must not double-count either cash or visits.');
}
echo "PASS sale-to-refund range counts the adjustment once and preserves service visits\n";
$nextDayRefunds = (new StoreMemberConsumptionRefundReadServices())->query([23],
    ['start' => '2026-10-14', 'end' => '2026-10-14'], [], 0);
if ((int)$nextDayRefunds->where('refund_operation.operation_no', 'TK2610130001')->count() !== 0) {
    throw new RuntimeException('The October 13 refund must not appear in the next day population.');
}
echo "PASS next-day refund population excludes TK2610130001\n";
