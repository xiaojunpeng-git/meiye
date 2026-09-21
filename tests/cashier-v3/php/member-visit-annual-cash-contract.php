<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$start = strpos($source, 'private function memberVisitAnalysis(');
$end = strpos($source, 'private function memberVisitAnnualSummary(', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('member visit report method is missing');
}
$method = substr($source, $start, $end - $start);

// The selected date range chooses visitors; cash columns always reconcile to
// successful payment facts in the selected natural year, never imported orders.
$checks = [
    'visits retain selected-date filter' => strpos($method, "->whereBetween('member_visit_service.business_date', [\$range['start'], \$range['end']])") !== false,
    'cash uses selected natural year' => strpos($method, "->whereBetween('business_date', [sprintf('%04d-01-01', \$year), sprintf('%04d-12-31', \$year)])") !== false,
    'cash is scoped to listed members' => strpos($method, "->whereIn('member_id', \$memberIds)") !== false,
    'cash comes from payment facts' => strpos($method, '$this->cashFacts($stores)') !== false && strpos($method, 'store_order') === false,
    'annual cash sums the month facts' => strpos($method, "\$records[\$id]['annual_cash_cents']+=(int)\$fact['amount_cents']") !== false,
    'report metric version is revised' => strpos($method, "store-member-visit-analysis-v2") !== false,
];

foreach ($checks as $name => $passes) {
    echo ($passes ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passes) exit(1);
}
