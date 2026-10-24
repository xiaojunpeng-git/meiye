<?php
declare(strict_types=1);

/** R57: run the real settings service and source resolver against SQLite memory only.
 * No app initialization, .env, migration execution, HTTP or business database is used.
 * Fixture DDL models the settings/audit contract; MySQL locking/migrations remain separate gates.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
if (!class_exists(\Swoole\Table::class)) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}
$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
require_once $backend . '/vendor/topthink/framework/src/helper.php';
// Unrelated configurable text sanitization is isolated: it must not open the
// business configuration/cache while testing the real request parameter path.
function filter_str($value) { return $value; }

use app\services\member\MemberCommerceSettingsServices;
use think\exception\ValidateException;
use think\facade\Db;

$app = new \think\App($backend . '/');
$db = new \think\DbManager();
$db->setConfig(['default' => 'r57_isolated', 'connections' => ['r57_isolated' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_',
    'fields_strict' => true, 'fields_cache' => false,
]]]);
\think\Container::getInstance()->instance(\think\DbManager::class, $db);
if (Db::connect()->getConfig('type') !== 'sqlite' || Db::connect()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('r57_isolated_sqlite_required');
}
Db::execute('CREATE TABLE eb_member_commerce_settings (id INTEGER PRIMARY KEY,
    purchase_primary_id INTEGER NOT NULL DEFAULT 0, purchase_secondary_id INTEGER NOT NULL DEFAULT 0,
    recharge_primary_id INTEGER NOT NULL DEFAULT 0, recharge_secondary_id INTEGER NOT NULL DEFAULT 0,
    repayment_primary_id INTEGER NOT NULL DEFAULT 0, repayment_secondary_id INTEGER NOT NULL DEFAULT 0,
    source_enabled INTEGER NOT NULL DEFAULT 1, inherit_previous INTEGER NOT NULL DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 1, updated_by INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)');
Db::execute('CREATE TABLE eb_member_commerce_settings_audit (id INTEGER PRIMARY KEY AUTOINCREMENT,
    command_key TEXT NOT NULL UNIQUE, admin_id INTEGER NOT NULL, input_hash TEXT NOT NULL,
    before_json TEXT NOT NULL, after_json TEXT NOT NULL, created_at INTEGER NOT NULL)');
Db::execute('CREATE TABLE eb_cashier_v3_business_source (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL, status INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0,
    require_secondary INTEGER NOT NULL DEFAULT 0, attribution_type TEXT NOT NULL DEFAULT \'other\',
    is_fixed INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL DEFAULT 1)');
Db::execute('CREATE TABLE eb_cashier_v3_payment_method_config (id INTEGER PRIMARY KEY, code TEXT, default_name TEXT,
    display_name TEXT, status INTEGER DEFAULT 1, sort INTEGER DEFAULT 0, version INTEGER DEFAULT 1, updated_at INTEGER DEFAULT 1)');
foreach ([
    ['id' => 10, 'name' => 'A老客首护'],
    ['id' => 20, 'name' => 'D线上引流', 'require_secondary' => 1],
    ['id' => 21, 'name' => '抖音', 'parent_id' => 20],
    ['id' => 22, 'name' => '停用二级', 'parent_id' => 20, 'status' => 0],
    ['id' => 30, 'name' => 'E地推拓客', 'require_secondary' => 1],
    ['id' => 31, 'name' => '合作方', 'parent_id' => 30],
    ['id' => 40, 'name' => '停用一级', 'status' => 0],
    ['id' => 50, 'name' => 'K退款顾客'],
] as $source) Db::name('cashier_v3_business_source')->insert($source);
$directoryBefore = Db::name('cashier_v3_business_source')->order('id')->select()->toArray();

$service = new MemberCommerceSettingsServices();
$checks = 0;
$failures = [];
$check = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) $failures[] = $label;
};
$snapshot = static fn(): array => [
    Db::name('member_commerce_settings')->order('id')->select()->toArray(),
    Db::name('member_commerce_settings_audit')->order('id')->select()->toArray(),
];
// Every scenario gets a fresh authority row and audit log, preventing a failed
// rejection assertion from contaminating subsequent tests or disguising a write.
$reset = static function (array $changes = []): void {
    Db::name('member_commerce_settings')->where('id', '>', 0)->delete();
    Db::name('member_commerce_settings_audit')->where('id', '>', 0)->delete();
    Db::name('member_commerce_settings')->insert(array_replace([
        'id' => 1, 'purchase_primary_id' => 10, 'purchase_secondary_id' => 0,
        'recharge_primary_id' => 20, 'recharge_secondary_id' => 21,
        'repayment_primary_id' => 30, 'repayment_secondary_id' => 31,
        'source_enabled' => 1, 'inherit_previous' => 0, 'version' => 7,
        'updated_by' => 1, 'updated_at' => 1,
    ], $changes));
};
$sequence = 0;
$input = static function (array $changes = []) use (&$sequence): array {
    $row = Db::name('member_commerce_settings')->where('id', 1)->find();
    unset($row['id'], $row['updated_by'], $row['updated_at']);
    return array_replace($row, ['idempotency_key' => 'R57-SETTINGS-COMMAND-' . (++$sequence)], $changes);
};
$reject = static function (array $command, string $label, int $admin = 9) use ($service, $snapshot, $check): void {
    $before = $snapshot();
    $rejected = false;
    try { $service->save($command, $admin); }
    catch (ValidateException $expected) { $rejected = true; }
    $check($rejected, $label . ':validation');
    $check($snapshot() === $before, $label . ':no_partial_write_or_audit');
};

$reset();
$missing = false;
Db::name('member_commerce_settings')->where('id', 1)->delete();
try { $service->read(); } catch (ValidateException $expected) { $missing = true; }
$check($missing && Db::name('member_commerce_settings')->count() === 0, 'missing_settings_rejected_without_auto_seed');
$reset();
$before = $snapshot();
$read = $service->read();
$check((int)$read['source_enabled'] === 1 && (int)$read['inherit_previous'] === 0, 'read_manual_mode');
$check((int)$read['repayment_primary_id'] === 30 && (int)$read['repayment_secondary_id'] === 31, 'read_repayment_ids');
$check($snapshot() === $before, 'read_has_no_side_effect');
$roots = array_column($read['sources'], null, 'id');
$check(isset($roots[10], $roots[20], $roots[30]) && !isset($roots[40], $roots[50]), 'read_real_directory_excludes_disabled_and_refund');
$check(count($roots[20]['children']) === 1 && $roots[20]['children'][0]['id'] === 21, 'read_real_secondary_directory');

foreach ([[0, 0], [1, 0], [1, 1], [0, 1], [false, false], [true, true], ['0', '0'], ['1', '1']] as [$enabled, $inherit]) {
    $reset();
    $result = $service->save($input(['source_enabled' => $enabled, 'inherit_previous' => $inherit]), 9);
    $check((int)$result['source_enabled'] === (int)$enabled && (int)$result['inherit_previous'] === (int)$inherit, 'mode_flags_roundtrip:' . json_encode([$enabled, $inherit]));
    $persisted = (new MemberCommerceSettingsServices())->read();
    $check((int)$persisted['source_enabled'] === (int)$enabled && (int)$persisted['version'] === 8, 'refresh_mode_and_version');
    $check((int)$persisted['purchase_primary_id'] === 10 && (int)$persisted['purchase_secondary_id'] === 0
        && (int)$persisted['recharge_primary_id'] === 20 && (int)$persisted['recharge_secondary_id'] === 21
        && (int)$persisted['repayment_primary_id'] === 30 && (int)$persisted['repayment_secondary_id'] === 31, 'all_three_fixed_defaults_persist');
    $audit = Db::name('member_commerce_settings_audit')->where('command_key', 'R57-SETTINGS-COMMAND-' . $sequence)->find();
    $auditBefore = json_decode($audit['before_json'], true);
    $auditAfter = json_decode($audit['after_json'], true);
    $check((int)$audit['admin_id'] === 9 && (int)$auditBefore['version'] === 7 && (int)$auditAfter['version'] === 8
        && (int)$auditAfter['source_enabled'] === (int)$enabled, 'audit_before_after_and_operator');
}

foreach (['source_enabled', 'inherit_previous'] as $field) {
    foreach ([-1, 2, 1.0, 0.5, null, '', 'false', 'true', '1x', '1.0', [], new stdClass()] as $invalid) {
        $reset();
        $reject($input([$field => $invalid]), 'strict_flag:' . $field . ':' . json_encode($invalid));
    }
}

foreach (['purchase', 'recharge', 'repayment'] as $kind) {
    foreach ([
        ['primary_id' => 0, 'secondary_id' => 0],
        ['primary_id' => 20, 'secondary_id' => 0],
        ['primary_id' => 20, 'secondary_id' => 31],
        ['primary_id' => 20, 'secondary_id' => 22],
        ['primary_id' => 40, 'secondary_id' => 0],
        ['primary_id' => 999, 'secondary_id' => 0],
        ['primary_id' => 50, 'secondary_id' => 0],
    ] as $invalid) {
        $reset();
        $reject($input(['source_enabled' => 0, $kind . '_primary_id' => $invalid['primary_id'],
            $kind . '_secondary_id' => $invalid['secondary_id']]), 'closed_source:' . $kind . ':' . json_encode($invalid));
    }
}

$reset();
$zeroDefaults = ['source_enabled' => 1];
foreach (['purchase', 'recharge', 'repayment'] as $kind) {
    $zeroDefaults[$kind . '_primary_id'] = 0;
    $zeroDefaults[$kind . '_secondary_id'] = 0;
}
$enabled = $service->save($input($zeroDefaults), 9);
$check((int)$enabled['purchase_primary_id'] === 0 && (int)$enabled['repayment_primary_id'] === 0, 'manual_mode_allows_unused_empty_defaults');
$reject($input(['source_enabled' => 0]), 'closing_requires_complete_defaults');

// Sequential stale writes exercise optimistic concurrency, not SQLite row-lock semantics.
$reset();
$firstCommand = $input(['source_enabled' => 0]);
$otherCommand = $input(['inherit_previous' => 1]);
$first = $service->save($firstCommand, 9);
$reject($otherCommand, 'stale_version_conflict');
$beforeReplay = $snapshot();
$replay = $service->save($firstCommand, 9);
$check($snapshot() === $beforeReplay && (int)$replay['version'] === 8 && count($snapshot()[1]) === 1, 'exact_replay_single_version_single_audit');
foreach (['source_enabled', 'inherit_previous', 'repayment_primary_id', 'repayment_secondary_id'] as $field) {
    $reject(array_replace($firstCommand, [$field => (int)$firstCommand[$field] + 1]), 'same_key_changed_field:' . $field);
}
$reject($firstCommand, 'same_key_other_admin', 10);
$service->save($input(['source_enabled' => 1, 'inherit_previous' => 1]), 9);
$beforeLateReplay = $snapshot();
$late = $service->save($firstCommand, 9);
$check((int)$late['source_enabled'] === 0 && (int)$late['version'] === 8 && $snapshot() === $beforeLateReplay,
    'late_replay_returns_original_without_reverting_current_policy');

// Old clients submit just the original purchase/recharge fields. Omission of
// the four new fields must preserve both policy modes and repayment defaults.
foreach ([[0, 0], [0, 1], [1, 0], [1, 1]] as [$enabled, $inherit]) {
    $reset(['source_enabled' => $enabled, 'inherit_previous' => $inherit]);
    $legacy = $input(['purchase_primary_id' => 20, 'purchase_secondary_id' => 21]);
    foreach (['source_enabled', 'inherit_previous', 'repayment_primary_id', 'repayment_secondary_id'] as $field) unset($legacy[$field]);
    $result = $service->save($legacy, 9);
    $check((int)$result['source_enabled'] === $enabled && (int)$result['inherit_previous'] === $inherit
        && (int)$result['repayment_primary_id'] === 30 && (int)$result['repayment_secondary_id'] === 31, 'old_client_preserves_policy:' . $enabled . ':' . $inherit);
    $before = $snapshot();
    $service->save($legacy, 9);
    $check($snapshot() === $before, 'old_client_replay_no_reset_or_extra_audit');
}

$reset();
$reject($input(), 'invalid_operator', 0);
$reject($input(['idempotency_key' => 'short']), 'invalid_command_key');
$reject($input(['version' => 0]), 'missing_version');
$reset(['source_enabled' => 0]);
foreach (['purchase' => [10, 0], 'recharge' => [20, 21], 'repayment' => [30, 31]] as $kind => $ids) {
    $resolved = $service->source($kind);
    $check($resolved['primarySourceId'] === $ids[0] && $resolved['secondarySourceId'] === $ids[1], 'fixed_source_snapshot:' . $kind);
}

// If audit insertion fails after the update, the production transaction must
// roll back the settings row as well. The failure trigger exists only in memory.
$before = $snapshot();
Db::execute("CREATE TRIGGER r57_audit_failure BEFORE INSERT ON eb_member_commerce_settings_audit BEGIN SELECT RAISE(ABORT, 'r57_fixture_audit_failure'); END");
$threw = false;
try { $service->save($input(['source_enabled' => 1]), 9); }
catch (Throwable $expected) { $threw = strpos($expected->getMessage(), 'r57_fixture_audit_failure') !== false; }
Db::execute('DROP TRIGGER r57_audit_failure');
$check($threw && $snapshot() === $before, 'audit_failure_rolls_back_settings_atomically');
$check(Db::name('cashier_v3_business_source')->order('id')->select()->toArray() === $directoryBefore,
    'settings_never_modify_source_directory');

// Exercise the actual HTTP controller whitelist with the real Request parser
// and service. Service-only tests missed dropped policy fields at this boundary.
$controllerClass = new ReflectionClass(\app\controller\admin\v1\system\MemberCommerceSettings::class);
$controller = $controllerClass->newInstanceWithoutConstructor();
foreach (['adminId' => 9, 'adminInfo' => ['id' => 9, 'level' => 0], 'adminType' => 0] as $name => $value) {
    $property = $controllerClass->getProperty($name);
    $property->setAccessible(true);
    $property->setValue($controller, $value);
}
$httpSave = static function (array $command) use ($controller): array {
    $request = new \app\Request();
    $request->withServer(['REQUEST_METHOD' => 'PUT'])->setMethod('PUT');
    $request->withHeader(['content-type' => 'application/json'])->withInput(json_encode($command));
    return $controller->update($request)->getData()['data'];
};
foreach ([0, false, '0'] as $zero) {
    $reset();
    $result = $httpSave($input(['source_enabled' => $zero, 'inherit_previous' => $zero,
        'repayment_primary_id' => 20, 'repayment_secondary_id' => 21]));
    $check((int)$result['source_enabled'] === 0 && (int)$result['inherit_previous'] === 0, 'http_put_preserves_explicit_zero:' . json_encode($zero));
    $check((int)$result['repayment_primary_id'] === 20 && (int)$result['repayment_secondary_id'] === 21, 'http_put_retains_repayment_fields');
    $fresh = $controller->read()->getData()['data'];
    $check((int)$fresh['source_enabled'] === 0 && (int)$fresh['repayment_secondary_id'] === 21, 'http_fresh_read_stays_disabled');
}
$reset(['source_enabled' => 0]);
$legacy = $input();
foreach (['source_enabled', 'inherit_previous', 'repayment_primary_id', 'repayment_secondary_id'] as $field) unset($legacy[$field]);
$legacyResult = $httpSave($legacy);
$check((int)$legacyResult['source_enabled'] === 0 && (int)$legacyResult['repayment_primary_id'] === 30, 'http_old_client_cannot_reset_policy');
$before = $snapshot();
$threw = false;
try { $httpSave($input(['source_enabled' => 2])); } catch (ValidateException $expected) { $threw = true; }
$check($threw && $snapshot() === $before, 'http_invalid_policy_rejected_without_write');

echo json_encode(['status' => $failures ? 'FAIL' : 'PASS', 'checks' => $checks, 'failures' => $failures,
    'database' => 'sqlite::memory:', 'service_sha256' => hash_file('sha256', $backend . '/app/services/member/MemberCommerceSettingsServices.php'),
    'controller_sha256' => hash_file('sha256', $backend . '/app/controller/admin/v1/system/MemberCommerceSettings.php'),
    'business_database_writes' => 0, 'limits' => 'Fixture DDL; real MySQL migration and concurrent row locking not covered.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures ? 1 : 0);
