<?php
declare(strict_types=1);

/** Read-only MySQL regression: exercise ASCII-number/Chinese-name comparisons without business writes. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new think\App($backend . '/');
$app->initialize();
$service = new app\services\product\inventory\InventoryStockRequestQueryServices();
$method = new ReflectionMethod($service, 'applyKeyword');
$method->setAccessible(true);
$makeQuery = static fn() => think\facade\Db::name('inventory_stock_request_document')->alias('d');
$document = $makeQuery()->where('d.request_party_name_snapshot', '<>', '')->order('d.id desc')->find();
if (!$document) throw new RuntimeException('A request document is required for this read-only regression.');

// Search every available identity snapshot and confirm the exact source document survives.
foreach (['request_no', 'request_party_name_snapshot', 'supply_party_name_snapshot', 'acceptance_party_name_snapshot'] as $column) {
    $keyword = trim((string)($document[$column] ?? ''));
    if ($keyword === '') continue;
    $query = $makeQuery()->where('d.id', (int)$document['id']);
    $method->invoke($service, $query, $keyword);
    if ($query->count() !== 1) throw new RuntimeException('Search failed for ' . $column);
}
// The grouped OR must never override caller-injected date or document restrictions.
$query = $makeQuery()->where('d.id', -1);
$method->invoke($service, $query, (string)$document['request_party_name_snapshot']);
if ($query->count() !== 0) throw new RuntimeException('Keyword escaped the caller scope.');
$query = $makeQuery()->where('d.id', (int)$document['id'])->where('d.business_date', '1900-01-01');
$method->invoke($service, $query, (string)$document['request_no']);
if ($query->count() !== 0) throw new RuntimeException('Keyword escaped the business-date restriction.');
// Quotes remain parameter values; a malicious keyword cannot turn the search into an always-true predicate.
$query = $makeQuery();
$method->invoke($service, $query, "' OR 1=1 -- 不存在");
if ($query->count() !== 0) throw new RuntimeException('Unsafe keyword parameter binding.');
echo "REQUEST_KEYWORD_MYSQL_REGRESSION_OK\n";
