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
    'market detail matches visits by source as well as order identity' => strpos($service, ". '|' . \$sourceKey") !== false,
    'member visit reports exclude succeeded service void reversals through the shared fact scope' => strpos($service, 'private function completedUnvoidedServiceFacts') !== false
        && strpos($service, "cashier_v3_service_record_void_operation ' . \$voidAlias") !== false
        && strpos($service, "->whereNull(\$voidAlias . '.id')") !== false
        && strpos($service, "'member_visit_service',\n            'member_visit_void'") !== false
        && strpos($service, "'annual_visit_service','annual_visit_void'") !== false,
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n";
    if ($valid) $passed++;
}
echo "market performance service-visit contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
