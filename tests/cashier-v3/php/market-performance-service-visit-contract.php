<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$marketPerformance = substr($service, strpos($service, 'private function marketPerformance'), strpos($service, 'private function marketDetail') - strpos($service, 'private function marketPerformance'));

$checks = [
    'market performance uses shared completed service fact resolver' => strpos($service, '$this->marketServiceVisitFacts($stores, $range, $input)') !== false,
    'service facts are not dropped by an inner sales-order join' => strpos($marketPerformance, "->join('cashier_v3_sales_order o'") === false,
    'historical service rows can resolve through origin order payment source' => strpos($service, 'cashier_v3_payment_fact p2') !== false && strpos($service, 'wf.origin_order_id') !== false,
    'card service rows bridge legacy origin orders to the sale order' => strpos($service, 'cashier_v3_card_purchase_receipt cr') !== false && strpos($service, 'cr.sales_order_id') !== false,
    'voided service facts are excluded as reversals' => strpos($service, "cashier_v3_service_record_void_operation vo") !== false && strpos($service, '->whereNull(\'vo.id\')') !== false,
    'service facts remain deduplicated at service_fact_id grain' => strpos($service, 'sv.store_id,sv.service_fact_id,sv.checkout_request_id') !== false,
    'shared service resolver filters selected array rows rather than the query object' => preg_match('/\\$rows\\s*=\\s*\\$query\\s*->fieldRaw\\(/s', $service) === 1
        && strpos($service, 'return array_values(array_filter($rows, static function (array $row): bool {') !== false,
    'market detail identifies one normal-service visit per member day and source' => strpos($service, "\$dayKey = (int)\$serviceVisit['store_id'] . '|' . (string)\$serviceVisit['service_business_date'] . '|' . \$memberId . '|' . \$sourceId") !== false,
    'market performance deduplicates visits by member day and source' => strpos($marketPerformance, 'if (isset($visitedMemberDays[$memberDayKey])) continue;') !== false,
    'successful order void payment reversals are included in report net cash' => strpos($service, "reversal_operation.operation_type IN ('refund','void')") !== false,
    'market performance keeps order void reversals in their original source channel' => strpos($service, 'is_refund_reversal') !== false
        && strpos($service, "refund_operation.operation_type='refund'") !== false
        && strpos($service, "(int)(\$fact['is_refund_reversal'] ?? 0) === 1") !== false,
    'market detail hides documents whose forward and reversal payment facts net to zero' => strpos($service, "->having('SUM(p.amount_cents) <> 0')") !== false,
    'member visit reports exclude succeeded service void reversals through the shared fact scope' => strpos($service, 'private function completedUnvoidedServiceFacts') !== false
        && strpos($service, "cashier_v3_service_record_void_operation ' . \$voidAlias") !== false
        && strpos($service, "->whereNull(\$voidAlias . '.id')") !== false
        // 参数是否并排书写不影响服务作废范围，按调用参数而非空白格式验证。
        && preg_match("/'member_visit_service'\\s*,\\s*'member_visit_void'/", $service) === 1
        && preg_match("/'annual_visit_service'\\s*,\\s*'annual_visit_void'/", $service) === 1,
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n";
    if ($valid) $passed++;
}
echo "market performance service-visit contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
