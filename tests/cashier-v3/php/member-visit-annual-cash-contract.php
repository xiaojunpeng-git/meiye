<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$start = strpos($source, 'private function memberVisitAnalysis(');
$end = strpos($source, 'private function memberVisitAnnualSummary(', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('member visit report method is missing');
}
$method = substr($source, $start, $end - $start);

// Selected-date cash or eligible completed services choose members; visit
// detail shares the member-day fact scope while cash keeps the natural year.
$checks = [
    'positive collected cash seeds members' => strpos($method, "\$payingMembers = \$this->cashFacts(\$stores)") !== false
        && strpos($method, "->whereBetween('business_date', [\$range['start'], \$range['end']])") !== false
        && strpos($method, "->where('fact_direction', 'forward')") !== false
        && strpos($method, "->where('amount_cents', '>', 0)") !== false,
    'refund adjustments seed members only when completed' => strpos($method, '$refundReader = new StoreMemberConsumptionRefundReadServices()') !== false
        && strpos($method, '$adjustmentMembers = (clone $periodRefunds)') !== false,
    'refund reversals seed members through normal fact scope' => strpos($method, "->where('fact_direction', 'reversal')") !== false
        && strpos($method, '$reversalMembers = $this->cashFacts($stores)') !== false,
    'pure service members are seeded before annual cash' => strpos($method, '$services = $this->memberVisitServiceFacts($stores, $range, $input)') !== false
        && strpos($method, "if (!isset(\$records[\$id])) \$records[\$id] =") !== false,
    'visits deduplicate per business date' => strpos($method, 'COUNT(DISTINCT member_visit_service.business_date) month_visits') !== false,
    'cash uses selected natural year' => strpos($method, "->whereBetween('business_date', [sprintf('%04d-01-01', \$year), sprintf('%04d-12-31', \$year)])") !== false,
    'cash is scoped to listed members' => strpos($method, "->whereIn('member_id', \$memberIds)") !== false,
    'cash comes from payment facts' => strpos($method, '$this->cashFacts($stores)') !== false && strpos($method, 'store_order') === false,
    'annual cash sums the month facts' => strpos($method, "\$records[\$id]['annual_cash_cents']+=(int)\$fact['amount_cents']") !== false,
    'annual cash also includes completed refund adjustments' => strpos($method, '$annualAdjustments = $refundReader->query(') !== false
        && strpos($method, "->whereIn('payment.member_id', \$memberIds)") !== false,
    'report metric version is revised' => strpos($method, "store-member-visit-analysis-v6") !== false,
    'member and month counts declare exact detail' => strpos($method, "'report'=>'member_visit_detail'") !== false
        && strpos($method, "'visit_month'=>\$month") !== false,
];

foreach ($checks as $name => $passes) {
    echo ($passes ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passes) exit(1);
}
