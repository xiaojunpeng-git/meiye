<?php
declare(strict_types=1);

/** End-to-end read-model checks on the disposable 008 rights database only. */
$backend = getenv('M008_RIGHTS_TEST_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
$host = getenv('M008_RIGHTS_TEST_HOST') ?: '127.0.0.1';
$port = getenv('M008_RIGHTS_TEST_PORT') ?: '13318';
if (!in_array($host, ['127.0.0.1', 'm008-rights-isolate'], true)
    || !in_array($port, ['13318', '3306'], true)) {
    throw new RuntimeException('Disposable 008 database endpoint guard failed');
}
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\card\CashierV3HistoricalEntitlementReadServices;
use app\services\mobile\customer\MobileCustomerAssetRecordServices;
use app\services\mobile\customer\MobileCustomerQueryServices;
use app\services\query\provider\MemberUnifiedQueryProvider;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'database.hostname' => $host, 'DATABASE_HOSTNAME' => $host,
    'database.hostport' => $port, 'DATABASE_HOSTPORT' => $port,
    'database.database' => 'mohe_008', 'DATABASE_DATABASE' => 'mohe_008',
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => 'rights-test-local', 'DATABASE_PASSWORD' => 'rights-test-local',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'merchant_imported_card_isolated_skip_dotenv_reload');
$app->initialize();
Db::connect('mysql', true)->query('SELECT 1');
if ((string)Db::query('SELECT DATABASE() db')[0]['db'] !== 'mohe_008'
    || (int)Db::name('migration_entitlement_scope')->count() !== 6531) {
    throw new RuntimeException('Disposable 008 rights fixture missing');
}

/** Reject a broken member list, detail or cross-brand projection immediately. */
function cardAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$reader = new CashierV3HistoricalEntitlementReadServices();
$meiyue = $reader->activeHolders([421], '0', 11);
$geni = $reader->activeHolders([421], '0', 22);
$times = array_sum(array_map(static function (array $holder): int {
    return (int)$holder['write_surplus_times'];
}, $meiyue));
cardAssert(count($meiyue) === 15 && $times > 0, 'PoPo imported rights missing from authorized store');
cardAssert($geni === [], 'Sibling brand read PoPo imported rights');

$provider = (new ReflectionClass(MemberUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
$summariesMethod = new ReflectionMethod(MemberUnifiedQueryProvider::class, 'cardSummaries');
$summariesMethod->setAccessible(true);
$summary = $summariesMethod->invoke($provider, [421], [11], false, ['tenant_id' => '0', 'store_id' => 11]);
$deniedSummary = $summariesMethod->invoke($provider, [421], [22], false, ['tenant_id' => '0', 'store_id' => 22]);
cardAssert((int)($summary[421]['active_card_count'] ?? 0) === 15
    && (int)($summary[421]['remaining_project_times'] ?? 0) === $times
    && (string)($summary[421]['remaining_project_amount'] ?? '') === '0.00',
    'Merchant list count, shared times or zero-value migration amount differs');
cardAssert((int)($deniedSummary[421]['active_card_count'] ?? 0) === 0,
    'Sibling brand customer list disclosed imported rights');

$assets = new MobileCustomerAssetRecordServices(new MobileCustomerQueryServices());
$detailMethod = new ReflectionMethod(MobileCustomerAssetRecordServices::class, 'entitlements');
$detailMethod->setAccessible(true);
$detail = $detailMethod->invoke($assets, 421, [11], 11, 1, 30);
$deniedDetail = $detailMethod->invoke($assets, 421, [22], 22, 1, 30);
$detailTimes = array_sum(array_map(static function (array $card): int {
    return (int)$card['remainingTimes'];
}, $detail['records']));
cardAssert((int)$detail['total'] === 15 && $detailTimes === $times,
    'Merchant asset detail differs from customer card summary');
cardAssert((int)$deniedDetail['total'] === 0,
    'Sibling brand asset detail disclosed imported rights');
$choice = Db::name('cashier_v3_card_rule_state')
    ->whereIn('card_holder_id', array_keys($meiyue))
    ->where('rule_type', 'choice_count')->field('card_holder_id')->find();
cardAssert(is_array($choice), 'Choice-count migration fixture missing');
$choiceId = (int)$choice['card_holder_id'];
$choiceRecord = null;
foreach ($detail['records'] as $record) {
    if ($record['id'] === 'holder:' . $choiceId) $choiceRecord = $record;
}
$componentTimes = array_sum(array_map(static function (array $project): int {
    return (int)$project['remainingTimes'];
}, (array)($choiceRecord['projects'] ?? [])));
cardAssert($choiceRecord !== null
    && (int)$choiceRecord['remainingTimes'] === (int)$meiyue[$choiceId]['write_surplus_times']
    && $componentTimes > (int)$choiceRecord['remainingTimes'],
    'Choice-count component alternatives were added as separate grants');

// Revoking the source scope must take effect on the next read; the disposable
// fixture transaction rolls back so the test cannot change later rehearsals.
$firstId = (int)array_key_first($meiyue);
Db::startTrans();
try {
    Db::name('migration_entitlement_scope')->where('card_holder_id', $firstId)
        ->update(['status' => 'inactive']);
    cardAssert(count($reader->activeHolders([421], '0', 11)) === 14,
        'Revoked migration scope remained visible');
} finally {
    Db::rollback();
}
echo json_encode(['ok' => true, 'active_cards' => count($meiyue),
    'remaining_times' => $times, 'list_detail_parity' => true,
    'sibling_brand_denied' => true, 'scope_revocation' => true]) . PHP_EOL;
