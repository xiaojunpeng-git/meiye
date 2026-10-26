<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/product/inventory/query/InventoryItemDealAnalyticsServices.php';

use app\services\product\inventory\query\InventoryItemDealAnalyticsServices;

$service = new InventoryItemDealAnalyticsServices();
$rows = $service->analyze([
    ['service_fact_id' => 'svc-1', 'project_id' => 1, 'project_name' => '焕肤体验', 'member_id' => 101, 'occurred_at' => '2026-07-01 10:00:00', 'direction' => 1, 'quantity_units' => 1, 'quantity_scale' => 0, 'experience_tag_snapshot' => true],
    ['service_fact_id' => 'svc-2', 'project_id' => 1, 'project_name' => '焕肤体验', 'member_id' => 102, 'occurred_at' => '2026-07-02 10:00:00', 'direction' => 1, 'quantity_units' => 1, 'quantity_scale' => 0, 'experience_tag_snapshot' => true],
    ['service_fact_id' => 'svc-3', 'project_id' => 1, 'project_name' => '焕肤体验', 'member_id' => 103, 'occurred_at' => '2026-07-03 10:00:00', 'direction' => 1, 'quantity_units' => 1, 'quantity_scale' => 0, 'experience_tag_snapshot' => false],
    ['service_fact_id' => 'svc-void', 'project_id' => 2, 'project_name' => '无效体验', 'member_id' => 201, 'occurred_at' => '2026-07-01 10:00:00', 'direction' => 1, 'quantity_units' => 1, 'quantity_scale' => 0, 'experience_tag_snapshot' => true],
    ['service_fact_id' => 'svc-void', 'project_id' => 2, 'project_name' => '无效体验', 'member_id' => 201, 'occurred_at' => '2026-07-01 10:00:00', 'direction' => -1, 'quantity_units' => 1, 'quantity_scale' => 0, 'experience_tag_snapshot' => true],
], [
    ['sale_fact_id' => 'sale-1', 'project_id' => 1, 'project_name' => '焕肤体验', 'member_id' => 101, 'occurred_at' => '2026-07-10 12:00:00', 'direction' => 1, 'quantity_units' => 2, 'quantity_scale' => 0, 'sale_amount_cents' => 30000],
    ['sale_fact_id' => 'sale-2', 'project_id' => 1, 'project_name' => '焕肤体验', 'member_id' => 101, 'occurred_at' => '2026-07-12 12:00:00', 'direction' => 1, 'quantity_units' => 1, 'quantity_scale' => 0, 'sale_amount_cents' => 18000],
    ['sale_fact_id' => 'sale-late', 'project_id' => 1, 'project_name' => '焕肤体验', 'member_id' => 102, 'occurred_at' => '2026-08-15 12:00:00', 'direction' => 1, 'quantity_units' => 1, 'quantity_scale' => 0, 'sale_amount_cents' => 20000],
    ['sale_fact_id' => 'sale-refund', 'project_id' => 1, 'project_name' => '焕肤体验', 'member_id' => 102, 'occurred_at' => '2026-07-05 12:00:00', 'direction' => 1, 'quantity_units' => 1, 'quantity_scale' => 0, 'sale_amount_cents' => 20000],
    ['sale_fact_id' => 'sale-refund', 'project_id' => 1, 'project_name' => '焕肤体验', 'member_id' => 102, 'occurred_at' => '2026-07-05 12:00:00', 'direction' => -1, 'quantity_units' => 1, 'quantity_scale' => 0, 'sale_amount_cents' => 20000],
], 30);

$failed = 0;
function dealAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }

dealAssert('only effective experience-tag services become experience members', count($rows) === 1 && $rows[0]['experience_member_count'] === 2);
dealAssert('purchase attribution uses the backend window and de-duplicates members', $rows[0]['deal_member_count'] === 1 && $rows[0]['conversion_rate_percent'] === 50.0);
dealAssert('formal sales metrics preserve purchase facts and quantity precision', $rows[0]['purchase_count'] === 2 && $rows[0]['purchase_quantity'] === '3' && $rows[0]['purchase_amount'] === '480.00');
dealAssert('average prices have explicit denominators', $rows[0]['average_selling_price'] === '160.00' && $rows[0]['average_unit_output'] === '480.00');
dealAssert('refund or void facts cancel by logical fact id', $rows[0]['purchase_count'] === 2 && $rows[0]['purchase_amount'] === '480.00');
dealAssert('non-experience and voided services cannot create rows', !array_filter($rows, static fn(array $row): bool => $row['project_id'] === 2));
dealAssert('zero denominator has no synthetic conversion row', $service->analyze([], [['sale_fact_id' => 'sale-orphan', 'project_id' => 9, 'project_name' => '孤立销售', 'member_id' => 99, 'occurred_at' => '2026-07-01 12:00:00', 'direction' => 1, 'quantity_units' => 1, 'quantity_scale' => 0, 'sale_amount_cents' => 100]], 30) === []);

$invalid = false;
try {
    $service->analyze([], [], -1);
} catch (InvalidArgumentException $exception) {
    $invalid = $exception->getMessage() === 'inventory_deal_attribution_window_invalid';
}
dealAssert('invalid attribution window fails closed', $invalid);
exit($failed === 0 ? 0 : 1);
