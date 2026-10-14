<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryOperationalUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryTransferUnifiedQueryProvider;

/** In-transit transfer lists keep status and scope, but never inherit the selected business-date period. */
function transitPeriodAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$provider = (new ReflectionClass(InventoryTransferUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(InventoryOperationalUnifiedQueryProvider::class, 'withoutInTransitTransferPeriod');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$date = ['field_key' => 'business_date', 'operator' => 'between', 'value' => ['2026-10-14', '2026-10-14']];
$status = ['field_key' => 'status_name', 'operator' => 'equal', 'value' => '在途'];
$keyword = ['field_key' => 'order_sn', 'operator' => 'contains', 'value' => 'DB'];
$payload = ['filterRelation' => 'all', 'topFilterConditions' => [$date, $status, $keyword]];
$inTransit = $method->invoke($provider, $payload);
transitPeriodAssert($inTransit['topFilterConditions'] === [$status, $keyword], 'in-transit removes only the date, retaining status and other filters');
transitPeriodAssert($payload['topFilterConditions'][0] === $date, 'the submitted query is not mutated');

$otherStatus = $payload;
$otherStatus['topFilterConditions'][1]['value'] = '已收货';
transitPeriodAssert($method->invoke($provider, $otherStatus) === $otherStatus, 'other statuses still use the selected date');
$disjunctive = $payload;
$disjunctive['filterRelation'] = 'any';
transitPeriodAssert($method->invoke($provider, $disjunctive) === $disjunctive, 'OR filters cannot trigger a narrowing SQL pushdown');

$frontend = (string)file_get_contents(dirname(__DIR__, 3) . '/前端代码/inventory-vue3/src/App.vue');
$platform = (string)file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
transitPeriodAssert(strpos($frontend, "storeOperationalStatus.value === '在途'") !== false
    && strpos($frontend, "platformTransferStatus.value === 'DISPATCHED'") !== false
    && substr_count($frontend, 'filtersBusinessDatePeriod() && businessPeriod.min') === 2
    && strpos($frontend, "filtersBusinessDatePeriod() && mode.value === 'platform'") !== false
    && substr_count($frontend, 'v-if="filtersBusinessDatePeriod()"') === 2,
    'both hosts omit and hide their date period only for in-transit transfers');
transitPeriodAssert(strpos($platform, "if (\$status !== 'DISPATCHED' && \$dateFrom !== '' && \$dateTo !== '')") !== false,
    'platform direct listing also ignores dates for in-transit status');
echo "TRANSFER_IN_TRANSIT_PERIOD_CONTRACT_OK\n";
