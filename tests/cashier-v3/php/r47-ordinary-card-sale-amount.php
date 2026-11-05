<?php
declare(strict_types=1);

// Pure money/signature regression. No database, HTTP, collection or catalogue writes.
function config($name) { return 'r47-local-test-only-ordinary-sale-key'; }
function sys_config($name, $default = null) { return $GLOBALS['r47Enabled'] ?? 1; }
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
spl_autoload_register(static function ($class) use ($backend) {
    if (strpos($class, 'app\\') === 0) require_once $backend . '/' . str_replace('\\', '/', $class) . '.php';
});
use app\services\cashier\v3\card\CashierV3OrdinaryCardSaleAdjustment as Rule;
use app\services\cashier\v3\CashierV3CommandException;
function check47(string $name, bool $ok): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}
function denied47(string $name, callable $run): void {
    try { $run(); } catch (CashierV3CommandException $e) { check47($name, true); return; }
    throw new RuntimeException('Unexpected acceptance: ' . $name);
}
$base = ['sourceKind' => 'card_package', 'ruleType' => 'normal', 'productId' => 47,
    'components' => [['relationId' => 1, 'productId' => 471, 'skuUnique' => 'r47-project',
        'nameSnapshot' => '胶原肽紧塑冻干', 'productType' => 6, 'writeTimes' => 10, 'configuredPriceCents' => 0]]];
$sealed = Rule::seal($base, 1, 47, 298000);
// Deliberately reconstruct a previously signed hang: its accepted raw floors
// must survive deployment, without recalculating it from a newer catalogue.
$legacy = $sealed;
unset($legacy['ordinarySaleBaseline']['definition']['componentSaleAmountsCents']);
$sign = new ReflectionMethod(Rule::class, 'signature');
if (PHP_VERSION_ID < 80100) $sign->setAccessible(true);
$legacy['ordinarySaleBaseline']['signature'] = $sign->invoke(null, $legacy['ordinarySaleBaseline']['definition']);
if (getenv('R47_MODE') === 'export') {
    echo json_encode(['purchase' => $sealed, 'legacyPurchase' => $legacy, 'base' => $base], JSON_UNESCAPED_UNICODE);
    exit;
}
if (getenv('R47_MODE') === 'verify') {
    $cases = json_decode(file_get_contents('php://stdin'), true);
    foreach ($cases as $case) {
        $increase = Rule::validate($case['purchase'], [], 1, 47);
        check47($case['name'] . ' preserves signed business amounts', $increase === $case['increase']);
        Rule::assertSaleAmount($case['purchase'], $increase, $case['quantity'] ?? 1, $case['amountCents'], $case['couponCents'] ?? 0);
    }
    exit;
}
check47('zero-weight single project receives 2980 yuan', $sealed['ordinarySaleBaseline']['definition']['componentSaleAmountsCents'] === [298000]);
check47('catalogue weights and times are unchanged', $sealed['components'] === $base['components']);
check47('unmodified card retains manual pricing semantics', Rule::validate($sealed, $base, 1, 47) === 0 && Rule::minimumPrice($sealed, 0) === null);
$same = $sealed; $same['components'][0]['configuredAmountCents'] = 298000;
check47('save unchanged 2980 does not become 5960', Rule::validate($same, [], 1, 47) === 0);
$edited = $same; $edited['components'][0]['configuredAmountCents'] = 308000;
check47('only added 100 yuan is added to card price', Rule::validate($edited, [], 1, 47) === 10000 && Rule::minimumPrice($edited, 10000) === 308000);
$times = $same; $times['components'][0]['writeTimes'] = 12;
check47('more times preserve the confirmed total', Rule::validate($times, [], 1, 47) === 0 && Rule::minimumPrice($times, 0) === 298000);
Rule::assertSaleAmount($edited, 10000, 2, 606000, 10000);
check47('two-card quantity and coupon preserve whole-card floor', true);
denied47('insufficient final amount is rejected', static function () use ($edited) { Rule::assertSaleAmount($edited, 10000, 1, 307900, 0); });
foreach (['lower amount' => 297900, 'fractional yuan' => 298001] as $name => $amount) {
    $bad = $same; $bad['components'][0]['configuredAmountCents'] = $amount;
    denied47($name, static function () use ($bad) { Rule::validate($bad, [], 1, 47); });
}
$bad = $same; $bad['ordinarySaleBaseline']['definition']['componentSaleAmountsCents'][0] = 0;
denied47('allocation proof cannot be lowered', static function () use ($bad) { Rule::validate($bad, [], 1, 47); });
$bad = $same; unset($bad['ordinarySaleBaseline']['definition']['componentSaleAmountsCents']);
denied47('allocation proof cannot be removed', static function () use ($bad) { Rule::validate($bad, [], 1, 47); });
$bad = $same; $bad['components'][0]['productId']++;
denied47('project identity cannot be replaced', static function () use ($bad) { Rule::validate($bad, [], 1, 47); });
$GLOBALS['r47Enabled'] = 0;
check47('unchanged save does not require edit switch', Rule::validate($same, [], 1, 47) === 0);
denied47('live switch still blocks edited card', static function () use ($edited) { Rule::validate($edited, [], 1, 47); });
$GLOBALS['r47Enabled'] = 1;
$legacy['components'][0]['configuredAmountCents'] = 10000;
check47('old signed zero-floor hang retains original 100-yuan increase', Rule::validate($legacy, [], 1, 47) === 10000 && Rule::minimumPrice($legacy, 10000) === 308000);
$multi = $base;
$multi['components'][] = array_replace($base['components'][0], ['relationId' => 2, 'productId' => 472]);
$multi['components'][0]['configuredPriceCents'] = 10000; $multi['components'][0]['writeTimes'] = 3;
$multi['components'][1]['configuredPriceCents'] = 20000; $multi['components'][1]['writeTimes'] = 2;
$weighted = Rule::seal($multi, 1, 47, 60000);
check47('multi-project totals use card price and stable final remainder', $weighted['ordinarySaleBaseline']['definition']['componentSaleAmountsCents'] === [25700, 34300]);
$multi['components'] = array_fill(0, 3, $base['components'][0]);
$zero = Rule::seal($multi, 1, 47, 10000);
check47('all-zero weights use equal shares with final remainder', $zero['ordinarySaleBaseline']['definition']['componentSaleAmountsCents'] === [3300, 3300, 3400]);
$multi['components'][0]['configuredPriceCents'] = 100;
$multi['components'][1]['configuredPriceCents'] = 100;
$tiny = Rule::seal($multi, 1, 47, 100);
check47('zero-weight gift stays zero when paid projects exist', $tiny['ordinarySaleBaseline']['definition']['componentSaleAmountsCents'] === [0, 100, 0]);
foreach ([0, 100, 10000, 298000, 99999999900] as $price) {
    $amounts = Rule::seal($multi, 1, 47, $price)['ordinarySaleBaseline']['definition']['componentSaleAmountsCents'];
    check47('integer allocation conserves ' . $price, array_sum($amounts) === $price && count(array_filter($amounts, static fn($v) => is_int($v) && $v >= 0 && $v % 100 === 0)) === 3);
}
