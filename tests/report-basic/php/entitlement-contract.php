<?php
declare(strict_types=1);

/**
 * R51 scope evidence, product decision 2026-10-23: deliver the realtime detail
 * as today's live report; historical date selection/reconstruction is outside
 * this confirmed delivery, not an unresolved business choice. Keep all codes.
 * This scope decision is not acceptance of a fixed implementation version.
 * Metadata must work without Composer, ThinkPHP, a connection or transaction.
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/app/services/report/BasicEntitlementReportServices.php';
use app\services\report\BasicEntitlementReportServices as Reports;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks; ++$checks;
    if (!$ok) throw new RuntimeException($label);
}
function rejects(callable $call, string $message): void {
    try { $call(); } catch (Throwable $e) {
        check(strpos($e->getMessage(), $message) !== false, 'Wrong rejection: ' . $e->getMessage()); return;
    }
    throw new RuntimeException('Missing rejection: ' . $message);
}
$service = new Reports();
check(count(Reports::codes()) === 4, 'Fourth report supports today/current only');
check(Reports::codes() === ['basic_unconsumed_summary', 'basic_unconsumed_detail', 'basic_unconsumed_as_of_detail', 'basic_customer_entitlements'], 'Codes retain exact identities and order');
$customerFields = array_column(Reports::definitions('basic_customer_entitlements'), null, 'key');
foreach (['category_name', 'card_name', 'project_name', 'remaining_times', 'order_no', 'project_detail_id'] as $key) check(isset($customerFields[$key]), 'Project-grain field: ' . $key);
check(!$customerFields['remaining_times']['summable'] && !$customerFields['rights_amount']['summable'], 'Alternative shared-pool rights cannot be summed');
check(!isset($customerFields['total_assets']), 'No misleading member asset total on project rows');
foreach (Reports::codes() as $code) {
    $fields = $service->definitions($code);
    check(count(array_unique(array_column($fields, 'key'))) === count($fields), 'Unique fields');
    foreach ($fields as $field) {
        check(array_keys($field) === ['key', 'label', 'type', 'defaultVisible', 'defaultQuick', 'width', 'fixed', 'money', 'summable'], 'Complete associative contract');
        check(is_bool($field['money']) && is_bool($field['summable']), 'Explicit money/sum metadata');
        check(in_array($field['type'], ['text', 'date', 'integer', 'decimal'], true), 'Runtime field type');
        check(!$field['money'] || strpos($field['label'], '元') !== false, 'Money unit explicit');
    }
}
rejects(static function () use ($service) { $service->definitions('unknown'); }, 'ENTITLEMENT_REPORT_UNKNOWN');
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
foreach (Reports::codes() as $code) {
    foreach ([$today->modify('-1 day'), $today->modify('+1 day')] as $otherDay) {
        rejects(static function () use ($service, $code, $otherDay) {
            $service->read($code, [1], ['as_of_date' => $otherDay->format('Y-m-d')]);
        }, 'ENTITLEMENT_AS_OF_UNAVAILABLE');
    }
}
// Both permitted time inputs reach the permission gate without touching DB.
foreach ([[], ['as_of_date' => $today->format('Y-m-d')]] as $timeInput) {
    rejects(static function () use ($service, $timeInput) {
        $service->read('basic_unconsumed_as_of_detail', [1], $timeInput);
    }, 'ENTITLEMENT_TRUSTED_SCOPE_REQUIRED');
}
foreach (['2026-02-30', '2026-1-01', 'tomorrow', 1792684800] as $date) {
    rejects(static function () use ($service, $date) { $service->read('basic_customer_entitlements', [1], ['start_date' => $date]); }, 'ENTITLEMENT_DATE_INVALID');
}
rejects(static function () use ($service) { $service->read('basic_customer_entitlements', [], []); }, 'ENTITLEMENT_SCOPE_EMPTY');
rejects(static function () use ($service) { $service->read('basic_customer_entitlements', ['1 OR 1=1'], []); }, 'ENTITLEMENT_STORE_INVALID');
echo json_encode(['pass' => true, 'checks' => $checks, 'database' => 'not_loaded']), PHP_EOL;
