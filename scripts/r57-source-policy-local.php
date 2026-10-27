<?php
declare(strict_types=1);

/** Local Docker only. Migration changes four configuration columns, never
 * orders/balances/facts. Probe modes use a server-enforced read-only snapshot.
 */
$root = '/var/www/html';
require $root . '/vendor/autoload.php';
(new \think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', get_class($error) . ': ' . $error->getMessage() . PHP_EOL); exit(1);
});
$config = config('database.connections.mysql');
if (!in_array($config['hostname'], ['mysql', 'mohe-mysql'], true) || $config['database'] !== 'ruihao' || $config['prefix'] !== 'eb_') throw new RuntimeException('Local database required');
// Open the local writer connection before asking ThinkPHP for its PDO handle.
\think\facade\Db::execute('SET SESSION sql_safe_updates = 1');
$pdo = \think\facade\Db::connect()->getPdo();
$mode = $argv[1] ?? '';
if ($mode === 'migrate') {
    $before = \think\facade\Db::name('member_commerce_settings')->where('id', 1)->find();
    $file = $root . '/database/upgrades/2026-10-25-业务来源策略/01-schema.sql';
    // This controlled migration format has one complete SQL statement per
    // non-comment line. PDO closes each result, including rerun SELECT guards.
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $statement) {
        if (trim($statement) === '' || strpos(ltrim($statement), '--') === 0) continue;
        $pdo->query($statement)->closeCursor();
    }
    $after = \think\facade\Db::name('member_commerce_settings')->where('id', 1)->find();
    foreach ($before as $key => $value) if (!in_array($key, ['source_enabled', 'inherit_previous', 'repayment_primary_id', 'repayment_secondary_id'], true) && $after[$key] != $value) throw new RuntimeException('Existing settings changed');
    echo json_encode(['result' => 'PASS', 'localOnly' => true, 'before' => $before, 'after' => $after,
        'migrationSHA256' => hash_file('sha256', $file), 'businessWrites' => 0]), PHP_EOL;
    return;
}
if ($mode !== 'probe') throw new RuntimeException('migrate|probe required');
/** Override configuration in memory only: exercise the real MySQL history and
 * source catalog without changing the local instance's approved manual mode.
 */
class R57ReadOnlySourcePolicy extends \app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices
{
    public $fixtureSettings = [];
    protected function settingsRow(): array { return $this->fixtureSettings; }
    public function history(string $kind, string $tenant, int $store, int $member): array
    {
        return $this->latestChoice($kind, $tenant, $store, $member);
    }
}
\think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY'); $pdo->beginTransaction();
try {
    $policy = new \app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices();
    $state = $policy->read();
    if (!$state['configured']) throw new RuntimeException('Expected migrated local policy');
    // The product manager may switch the local instance while validating.
    // Test manual initialization in memory, never reset their saved policy.
    $row = (array)\think\facade\Db::name('member_commerce_settings')->where('id', 1)->find();
    $manual = new R57ReadOnlySourcePolicy();
    $manual->fixtureSettings = array_replace($row, ['source_enabled' => 1, 'inherit_previous' => 0]);
    $count = 0;
    foreach (['purchase', 'recharge', 'repayment'] as $kind) {
        if ($manual->initialChoice($kind, '0', 1, 1)['primarySourceId'] !== 0) throw new RuntimeException('Manual mode inherited history');
        $count++;
    }
    $probe = new R57ReadOnlySourcePolicy();
    $probe->fixtureSettings = $row;
    $probe->fixtureSettings['source_enabled'] = 0;
    // Use an already configured source for all three virtual defaults, leaving
    // the actual repayment setting and all other persisted values untouched.
    foreach (['purchase', 'recharge', 'repayment'] as $kind) {
        $probe->fixtureSettings[$kind . '_primary_id'] = (int)$row['purchase_primary_id'];
        $probe->fixtureSettings[$kind . '_secondary_id'] = (int)$row['purchase_secondary_id'];
        $resolved = $probe->resolveChoice($kind, '0', 1, 0, ['primarySourceId' => 999999]);
        if ($resolved['primarySourceId'] !== (int)$row['purchase_primary_id'] || $resolved['displayNameSnapshot'] === '') throw new RuntimeException('Fixed catalog resolution failed');
        $count++;
    }
    $plans = [];
    foreach (['purchase', 'recharge', 'repayment'] as $kind) {
        $previous = $probe->history($kind, 'R57-no-such-tenant', 2147483000, 2147483000);
        $sql = \think\facade\Db::getLastSql();
        $plans[$kind] = \think\facade\Db::query('EXPLAIN ' . $sql);
        if ((int)($previous['primarySourceId'] ?? 0) !== 0) throw new RuntimeException('History leaked across identity boundary');
        $count++;
    }
    echo json_encode(['result' => 'PASS', 'localOnly' => true, 'serverReadOnly' => true, 'mode' => $state['mode'], 'checks' => $count, 'historyPlans' => $plans, 'businessWrites' => 0]), PHP_EOL;
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
