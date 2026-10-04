<?php
declare(strict_types=1);

/** Local-only note edit smoke: all fixture and audit rows are rolled back. */
$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
if (getenv('PRESALE_REMARK_LOCAL_TEST') !== '1') throw new RuntimeException('LOCAL_TEST_OPT_IN_REQUIRED');
$databasePassword = (string)getenv('DB_PASSWORD');
if ($databasePassword === '') throw new RuntimeException('LOCAL_DB_PASSWORD_REQUIRED');
$databaseHost = (string)(getenv('DB_HOST') ?: '127.0.0.1');
$databasePort = (string)(getenv('DB_PORT') ?: '3307');
if (!in_array($databaseHost, ['127.0.0.1', 'mysql'], true)) throw new RuntimeException('LOCAL_DB_HOST_REQUIRED');
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => $databaseHost, 'DATABASE_HOSTNAME' => $databaseHost,
    'database.hostport' => $databasePort, 'DATABASE_HOSTPORT' => $databasePort,
    'database.database' => 'ruihao', 'DATABASE_DATABASE' => 'ruihao',
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => $databasePassword, 'DATABASE_PASSWORD' => $databasePassword,
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'presale_remark_local_skip_dotenv_reload');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$candidates = Db::query("SELECT c.id,c.store_id,s.id AS staff_id FROM eb_cashier_v3_presale_claim c
    JOIN eb_inventory_location l ON l.tenant_id=c.tenant_id AND l.store_id=c.store_id AND l.location_type='STORE' AND l.is_default=1 AND l.location_status='ACTIVE'
    JOIN eb_system_store_staff s ON s.store_id=c.store_id AND s.status=1 AND s.is_del=0 LIMIT 1");
$candidate = $candidates[0] ?? null;
if (!$candidate) throw new RuntimeException('LOCAL_CLAIM_SCOPE_FIXTURE_MISSING:' . (string)Db::query('SELECT DATABASE() AS db')[0]['db'] . ':' . (string)Db::name('cashier_v3_presale_claim')->count());
$original = (array)Db::name('cashier_v3_presale_claim')->where('id', (int)$candidate['id'])->find();
$claimId = 'PCC-R9-LOCAL-REMARK-' . bin2hex(random_bytes(8));
$fixture = $original;
unset($fixture['id']);
$fixture['claim_id'] = $claimId;
$fixture['claim_no'] = 'R9-LOCAL-' . bin2hex(random_bytes(6));
$fixture['idempotency_key'] = 'R9-LOCAL-' . bin2hex(random_bytes(10));
$fixture['remark'] = '';
$fixture['remark_version'] = 1;
$service = new CashierV3PresaleClaimServices();
$started = false;
try {
    Db::startTrans();
    $started = true;
    Db::name('cashier_v3_presale_claim')->insert($fixture);
    $saved = $service->updateRemarkForStore((int)$candidate['store_id'], (int)$candidate['staff_id'], $claimId, [
        'remark' => '第一次备注', 'expected_version' => 1,
    ]);
    if ($saved['remark_version'] !== 2 || $saved['remark'] !== '第一次备注') throw new RuntimeException('NOTE_WRITE_FAILED');
    try {
        $service->updateRemarkForStore((int)$candidate['store_id'], (int)$candidate['staff_id'], $claimId, [
            'remark' => '旧版本覆盖', 'expected_version' => 1,
        ]);
        throw new RuntimeException('STALE_VERSION_ACCEPTED');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'presale_claim_remark_version_changed') throw $error;
    }
    $history = Db::name('cashier_v3_presale_claim_remark_history')->where('claim_id', $claimId)->select()->toArray();
    $row = Db::name('cashier_v3_presale_claim')->where('claim_id', $claimId)->find();
    if (count($history) !== 1 || $history[0]['previous_remark'] !== '' || $history[0]['new_remark'] !== '第一次备注'
        || (string)$row['remark'] !== '第一次备注' || (int)$row['remark_version'] !== 2) throw new RuntimeException('NOTE_AUDIT_FAILED');
    echo "PRESALE_CLAIM_REMARK_LOCAL_INTEGRATION_OK\n";
} finally {
    if ($started) Db::rollback();
}
if (Db::name('cashier_v3_presale_claim')->where('claim_id', $claimId)->count() !== 0
    || Db::name('cashier_v3_presale_claim_remark_history')->where('claim_id', $claimId)->count() !== 0) throw new RuntimeException('FIXTURE_ROLLBACK_FAILED');
