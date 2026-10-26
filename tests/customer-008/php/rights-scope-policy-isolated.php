<?php
declare(strict_types=1);

/** Real ThinkPHP organization-scope test against the disposable rights DB. */

$backend = getenv('M008_RIGHTS_TEST_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
$testHost = getenv('M008_RIGHTS_TEST_HOST') ?: '127.0.0.1';
$testPort = getenv('M008_RIGHTS_TEST_PORT') ?: '13318';
if (!in_array($testHost, ['127.0.0.1', 'm008-rights-isolate'], true)
    || !in_array($testPort, ['13318', '3306'], true)) {
    throw new RuntimeException('Disposable database endpoint guard changed');
}
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy as Policy;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'database.hostname' => $testHost, 'DATABASE_HOSTNAME' => $testHost,
    'database.hostport' => $testPort, 'DATABASE_HOSTPORT' => $testPort,
    'database.database' => 'mohe_008', 'DATABASE_DATABASE' => 'mohe_008',
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => 'rights-test-local', 'DATABASE_PASSWORD' => 'rights-test-local',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'rights_scope_isolated_skip_dotenv_reload');
$app->initialize();
Db::connect('mysql', true)->query('SELECT 1');
$ids = array_map('intval', Db::name('migration_entitlement_scope')
    ->where('source_system', 'morestore')->column('card_holder_id'));
if (count($ids) !== 16) throw new RuntimeException('Rights fixture missing');
$allowed = Policy::allowedHolderIds($ids, '0', 11);
$denied = Policy::allowedHolderIds($ids, '0', 22);
if (count($allowed) !== 16 || $denied !== []) {
    throw new RuntimeException('Cross-brand historical right scope leaked');
}
$sample = Policy::sourcePaidPrice($ids[0], '0');
if ($sample !== '119.37') throw new RuntimeException('Old paid cents display mismatch');
Db::name('system_store')->where('id', 11)->update(['business_status' => 0]);
try {
    if (Policy::allowedHolderIds($ids, '0', 11) !== []) {
        throw new RuntimeException('Closed store retained historical rights');
    }
} finally {
    Db::name('system_store')->where('id', 11)->update(['business_status' => 1]);
}
Db::name('organization_store')->where('store_id', 11)->update(['org_id' => 2]);
try {
    if (Policy::allowedHolderIds($ids, '0', 11) !== []) {
        throw new RuntimeException('Changed store organization retained stale grant');
    }
} finally {
    Db::name('organization_store')->where('store_id', 11)->update(['org_id' => 3]);
}
echo json_encode(['ok' => true, 'meiyue_allowed' => count($allowed),
    'geni_denied' => count($ids), 'closed_store_denied' => true,
    'org_move_revoked' => true, 'source_paid_price' => $sample]) . PHP_EOL;
