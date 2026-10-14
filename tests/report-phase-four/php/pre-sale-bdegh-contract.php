<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\StoreUnifiedReportPhaseFourServices;

// Exercise the report's pure source and event projection without changing data.
$service = (new ReflectionClass(StoreUnifiedReportPhaseFourServices::class))->newInstanceWithoutConstructor();
$call = static function (string $name, mixed ...$arguments) use ($service) {
    $method = new ReflectionMethod($service, $name);
    return $method->invokeArgs($service, $arguments);
};
$rule = $call('preSaleRule', 'operations_pre_sale_bdegh');
$labels = ['B导购引流', 'D线上引流 / 抖音', 'E地推拓客', 'G异业收客', 'H沉睡唤醒', 'A老客首护', 'C老带新客', 'F美带新客'];
$filtered = $call('filterSourceFacts', array_map(static fn(string $label): array => ['business_source_label' => $label], $labels), $rule);
$actual = array_column($filtered, 'business_source_label');
if ($actual !== array_slice($labels, 0, 5)) throw new RuntimeException('BDEGH must select five primary source codes');

$facts = [
    ['member_id' => 1, 'order_id' => 'order-1', 'source_line_id' => 'first-small', 'business_date' => '2026-04-02', 'amount_cents' => 30000, 'business_source_label' => 'B导购引流'],
    ['member_id' => 1, 'order_id' => 'order-3', 'source_line_id' => 'second-large', 'business_date' => '2026-05-03', 'amount_cents' => 60000, 'business_source_label' => 'B导购引流'],
    ['member_id' => 2, 'order_id' => 'order-2', 'source_line_id' => 'first-item', 'business_date' => '2026-04-04', 'amount_cents' => 30000, 'business_source_label' => 'D线上引流'],
    ['member_id' => 2, 'order_id' => 'order-2', 'source_line_id' => 'second-item-same-order', 'business_date' => '2026-04-04', 'amount_cents' => 30000, 'business_source_label' => 'D线上引流'],
    ['member_id' => 4, 'order_id' => 'order-4', 'source_line_id' => 'day-one', 'business_date' => '2026-04-10', 'amount_cents' => 30000, 'business_source_label' => 'E地推拓客'],
    ['member_id' => 4, 'order_id' => 'order-4', 'source_line_id' => 'day-two', 'business_date' => '2026-05-10', 'amount_cents' => 25000, 'business_source_label' => 'E地推拓客'],
];
$yearCash = array_merge($facts, [
    ['member_id' => 2, 'order_id' => 'order-a', 'source_line_id' => 'earlier-a', 'business_date' => '2026-02-02', 'amount_cents' => 10000, 'business_source_label' => 'A老客首护'],
    ['member_id' => 1, 'order_id' => 'order-a2', 'source_line_id' => 'other-source', 'business_date' => '2026-06-02', 'amount_cents' => 20000, 'business_source_label' => 'A老客首护'],
    ['member_id' => 1, 'order_id' => 'refund-1', 'source_line_id' => 'first-small', 'business_date' => '2026-07-02', 'amount_cents' => -10000, 'business_source_label' => 'B导购引流'],
    ['member_id' => 3, 'order_id' => 'order-2025', 'source_line_id' => 'old-b', 'business_date' => '2025-12-01', 'amount_cents' => 90000, 'business_source_label' => 'B导购引流'],
    ['member_id' => 3, 'order_id' => 'order-2026-a', 'source_line_id' => 'new-a', 'business_date' => '2026-04-01', 'amount_cents' => 5000, 'business_source_label' => 'A老客首护'],
]);
$metrics = $call('aggregateBdeghMonthlyMetrics', $yearCash, ['start' => '2026-01-01', 'end' => '2026-12-31'], $rule);
if ($metrics['2026-04']['deal_people'] !== 1) throw new RuntimeException('only first cash events at least 500 yuan count as deal people');
if ($metrics['2026-04']['deal_amount'] !== 120000) throw new RuntimeException('first cash amount must retain sales below 500 yuan and sum allocated lines');
if ($metrics['2026-05']['transition_people'] !== 2 || $metrics['2026-05']['transition_amount'] !== 85000) throw new RuntimeException('later-day cash on the same order is a second cash event');
if ($metrics['2026-04']['transition_people'] !== 0) throw new RuntimeException('two product lines in one order must not become two cash events');
if ($metrics['2026-04']['first_year_amount'] !== 225000) throw new RuntimeException('first-year cash must include earlier and later other-source cash and refunds for the April BDEGH cohort');
if ($metrics['2026-05']['first_year_amount'] !== 0) throw new RuntimeException('the annual cohort amount must not repeat in later months');
if (count($metrics) !== 12) throw new RuntimeException('the optimized report must still return all twelve months');

$sameDayFacts = [
    ['member_id' => 105, 'order_id' => 'first-order', 'business_date' => '2026-10-07', 'amount_cents' => 300000, 'business_source_label' => 'B导购引流'],
    ['member_id' => 105, 'order_id' => 'another-order', 'business_date' => '2026-10-07', 'amount_cents' => 150000, 'business_source_label' => 'D线上引流'],
    ['member_id' => 105, 'order_id' => 'next-day-payment', 'business_date' => '2026-10-08', 'amount_cents' => 20000, 'business_source_label' => 'B导购引流'],
];
$sameDayOnly = $call('aggregateBdeghMonthlyMetrics', array_slice($sameDayFacts, 0, 2), ['start' => '2026-01-01', 'end' => '2026-12-31'], $rule);
if ($sameDayOnly['2026-10']['deal_amount'] !== 450000 || $sameDayOnly['2026-10']['transition_people'] !== 0 || $sameDayOnly['2026-10']['transition_amount'] !== 0) {
    throw new RuntimeException('same-day distinct orders must not create a second cash event');
}
$sameDayMetrics = $call('aggregateBdeghMonthlyMetrics', $sameDayFacts, ['start' => '2026-01-01', 'end' => '2026-12-31'], $rule);
if ($sameDayMetrics['2026-10']['deal_people'] !== 1 || $sameDayMetrics['2026-10']['deal_amount'] !== 450000) {
    throw new RuntimeException('different orders for one member on one business day must be one first cash event');
}
if ($sameDayMetrics['2026-10']['transition_people'] !== 1 || $sameDayMetrics['2026-10']['transition_amount'] !== 20000) {
    throw new RuntimeException('only the later cash day may become the second cash event');
}
if ($sameDayMetrics['2026-10']['first_year_amount'] !== 470000) {
    throw new RuntimeException('first-year cash must retain all same-day and later-day receipts');
}

echo "pre-sale BDEGH contract: PASS\n";
