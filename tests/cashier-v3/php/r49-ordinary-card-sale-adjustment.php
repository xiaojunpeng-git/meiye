<?php
/** R49 pure authority tests: no DB, real funds, catalogue writes or remote dependencies. */
declare(strict_types=1);

function config($name) { return 'r49-test-instance-key-not-for-production'; }
function sys_config($name, $default = null) { return $GLOBALS['r49Enabled'] ?? 0; }
$root = dirname(__DIR__, 3);
spl_autoload_register(static function ($class) use ($root) {
    if (strpos($class, 'app\\') === 0) {
        require_once $root . '/后端代码/' . str_replace('\\', '/', $class) . '.php';
    }
});
use app\services\cashier\v3\card\CashierV3OrdinaryCardSaleAdjustment as Rule;
use app\services\cashier\v3\CashierV3CommandException;
function check49($name, $ok) {
    if (!$ok) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}
function rejected49($name, callable $change, array $sealed, array $base) {
    $change($sealed);
    try { Rule::validate($sealed, $base, 1, 12); }
    catch (CashierV3CommandException $error) { check49($name, true); return; }
    throw new RuntimeException('unexpected acceptance: ' . $name);
}
$base = ['sourceKind' => 'card_package', 'ruleType' => 'normal', 'productId' => 5,
    'components' => [
        ['relationId' => 1, 'productId' => 31, 'skuUnique' => 'a', 'writeTimes' => 3, 'configuredPriceCents' => 10000],
        ['relationId' => 2, 'productId' => 32, 'skuUnique' => 'b', 'writeTimes' => 2, 'configuredPriceCents' => 20000]
    ]];
// Keep the R49 independent-floor fixtures as previously signed hangs. R47
// covers newly signed per-card sale allocations separately; both must work.
function legacySeal49(array $base): array {
    $sealed = Rule::seal($base, 1, 12, 60000);
    unset($sealed['ordinarySaleBaseline']['definition']['componentSaleAmountsCents']);
    $sign = new ReflectionMethod(Rule::class, 'signature');
    if (PHP_VERSION_ID < 80100) $sign->setAccessible(true);
    $sealed['ordinarySaleBaseline']['signature'] = $sign->invoke(null, $sealed['ordinarySaleBaseline']['definition']);
    return $sealed;
}
$sealed = legacySeal49($base);
// The JS regression sends this signed business snapshot through the REAL final builder.
if (getenv('R49_EXPORT_FIXTURE') === '1') {
    $fixture = $sealed;
    $fixture['components'][0]['writeTimes'] = 7;
    $fixture['components'][0]['configuredAmountCents'] = 50000;
    echo json_encode(['purchase' => $fixture, 'base' => $base]);
    exit;
}
if (getenv('R49_VALIDATE_TRANSPORT') === '1') {
    $GLOBALS['r49Enabled'] = 1;
    $fixture = json_decode(file_get_contents('php://stdin'), true);
    check49('actual HTTP snapshot retains valid original-row proof', Rule::validate($fixture, $base, 1, 12) === 20000);
    exit;
}
$large = $base; $large['definitionVersion'] = 1152921504606846975;
$large['components'][0]['productVersion'] = 1152921504606846974;
$largeSealed = legacySeal49($large);
check49('unique sale snapshot contains no catalogue or envelope versions',
    !isset($largeSealed['definitionVersion']) && !isset($largeSealed['components'][0]['productVersion'])
    && !isset($largeSealed['ordinarySaleBaseline']['definition']['version']));
check49('business-only signature survives JSON and version removal', Rule::validate(json_decode(json_encode($largeSealed), true), $large, 1, 12) === 0);
check49('default switch off', !Rule::enabled());
check49('unchanged signed normal still usable when disabled', Rule::validate($sealed, $base, 1, 12) === 0);
check49('unchanged signed card retains existing manual-price behavior', Rule::minimumPrice($sealed, 0) === null);
$GLOBALS['r49Enabled'] = 1;
$edited = $sealed;
$edited['components'][0]['configuredAmountCents'] = 50000;
$edited['components'][0]['writeTimes'] = 7;
check49('row total increases independently of times', Rule::validate($edited, $base, 1, 12) === 20000);
check49('card price adds delta not row sum', Rule::minimumPrice($edited, 20000) === 80000);
Rule::assertSaleAmount($edited, 20000, 2, 150000, 10000);
check49('two cards preserve price floor before real coupon discount', true);
try {
    Rule::assertSaleAmount($edited, 20000, 2, 149900, 10000);
    throw new RuntimeException('manual price reduction accepted');
} catch (CashierV3CommandException $error) { check49('manual-price bypass rejected', true); }
$timesOnly = $sealed;
$timesOnly['components'][0]['writeTimes'] = 8;
$timesOnly['components'][0]['configuredAmountCents'] = 30000;
check49('increasing times alone keeps original total', Rule::validate($timesOnly, $base, 1, 12) === 0
    && Rule::minimumPrice($timesOnly, 0) === 60000);
check49('snapshot survives changed catalogue', Rule::validate($edited, [], 1, 12) === 20000);
check49('JSON hang roundtrip preserves signature and adjusted rows',
    Rule::validate(json_decode(json_encode($edited), true), $base, 1, 12) === 20000);
check49('legacy unchanged card still works', Rule::validate($base, $base, 1, 12) === 0);
$custom = ['sourceKind' => 'custom_card', 'components' => [['configuredAmountCents' => 10000]]];
check49('custom cards not reinterpreted as ordinary edits', Rule::validate($custom, $custom, 1, 12) === 0);
$upgrade = $sealed; $upgrade['multiCardUpgrade'] = ['operationId' => 'test'];
check49('upgrade metadata preserves floor validation', Rule::validate($upgrade, $base, 1, 12) === 0);
$cases = [
    'lower amount' => static function (&$p) { $p['components'][0]['configuredAmountCents'] = 29900; },
    'lower times' => static function (&$p) { $p['components'][0]['writeTimes'] = 2; },
    'changed times require explicit unchanged total' => static function (&$p) { $p['components'][0]['writeTimes'] = 4; },
    'fractional yuan' => static function (&$p) { $p['components'][0]['configuredAmountCents'] = 30001; },
    'fractional times' => static function (&$p) { $p['components'][0]['writeTimes'] = 3.5; },
    'deleted project' => static function (&$p) { array_pop($p['components']); },
    'added project' => static function (&$p) { $p['components'][] = $p['components'][0]; },
    'project replacement' => static function (&$p) { $p['components'][0]['productId'] = 33; },
    'specification replacement' => static function (&$p) { $p['components'][0]['skuUnique'] = 'c'; },
    'baseline tampering' => static function (&$p) { $p['ordinarySaleBaseline']['definition']['purchase']['components'][0]['writeTimes'] = 1; },
    'signature tampering' => static function (&$p) { $p['ordinarySaleBaseline']['signature'] = 'bad'; },
    'cross-store replay' => static function (&$p) { $p['ordinarySaleBaseline']['definition']['storeId'] = 2; },
    'deleted signature cannot bypass' => static function (&$p) { unset($p['ordinarySaleBaseline']); $p['components'][0]['writeTimes'] = 1; },
    'one project increase cannot offset another decrease' => static function (&$p) { $p['components'][0]['configuredAmountCents'] = 100000; $p['components'][1]['configuredAmountCents'] = 39900; },
    'overflow times' => static function (&$p) { $p['components'][0]['writeTimes'] = PHP_INT_MAX; },
];
foreach ($cases as $name => $change) rejected49($name, $change, $sealed, $base);
$GLOBALS['r49Enabled'] = 0;
rejected49('live switch stops pending edited checkout', static function (&$p) { $p['components'][0]['writeTimes'] = 4; $p['components'][0]['configuredAmountCents'] = 30000; }, $sealed, $base);
