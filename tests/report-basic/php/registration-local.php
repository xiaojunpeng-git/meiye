<?php
/** 真实统一查询Runtime+MySQL临时表；不导入客户数据，不改任何已有业务表。 */
$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root . '/vendor/autoload.php';
// 测试独立挂载的源码时，Composer 共享依赖不应把业务类指向另一份工作区。
if (getenv('LOCAL_TEST_ENV')) spl_autoload_register(static function (string $class) use ($root): void {
    if (strpos($class, 'app\\') !== 0) return;
    $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
}, true, true);
$app = new \think\App(rtrim($root, '/') . '/');
if ($localEnv = getenv('LOCAL_TEST_ENV')) $app->env->load($localEnv);
$app->initialize();
use think\facade\Db;
use app\services\report\BasicReportHostServices as Host;
use app\services\report\BasicRegistrationWriteServices as Writer;
use app\services\report\query\BasicRegistrationQueryProvider as Provider;
$config = config('database.connections.mysql');
if (!in_array($config['hostname'], ['mohe-mysql', 'mysql'], true)) throw new RuntimeException('Local-only integration test');
$n = 0;
function check($pass, $label) { global $n; ++$n; if (!$pass) throw new RuntimeException($label); }
function rejects(callable $fn, $label) { try { $fn(); } catch (InvalidArgumentException $e) { check(true, $label); return; } throw new RuntimeException('Expected rejection: ' . $label); }
set_exception_handler(static function ($e) { file_put_contents('php://stderr', (string)$e . PHP_EOL); exit(1); });
$tables = ['salary_store', 'salary_table', 'system_store', 'basic_report_registration_audit'];
try {
    Db::execute('CREATE TEMPORARY TABLE eb_salary_table (id INT PRIMARY KEY,name VARCHAR(100),type INT) ENGINE=InnoDB');
    Db::execute('CREATE TEMPORARY TABLE eb_system_store (id INT PRIMARY KEY,name VARCHAR(100)) ENGINE=InnoDB');
    Db::execute('CREATE TEMPORARY TABLE eb_salary_store (id INT PRIMARY KEY AUTO_INCREMENT,date BIGINT,salary_info TEXT,store_id INT,table_ids VARCHAR(255),staff_id INT) ENGINE=InnoDB');
    $migration = file_get_contents($root . '/database/upgrades/2026-10-23-基础报表/01-刷单登记审计.sql');
    Db::execute(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $migration));
    Db::name('salary_table')->insert(['id' => 919, 'name' => '刷单登记', 'type' => 4]);
    Db::name('system_store')->insertAll([['id' => 901, 'name' => '夹具一店'], ['id' => 902, 'name' => '夹具二店']]);
    for ($i = 1; $i <= 45; $i++) Db::name('salary_store')->insert([
        'date' => $i === 1 ? 0 : 1792684800,
        'salary_info' => json_encode(['shuadanpingtai' => '抖音', 'shuadanquanma' => 'R51-' . $i,
            'shuadanjine' => '100', 'quanmabeizhu' => '门店', 'legacy_note' => '保留证据'], JSON_UNESCAPED_UNICODE),
        'store_id' => $i === 45 ? 902 : 901, 'table_ids' => '919', 'staff_id' => 0,
    ]);
    $context = Host::context([901], 'stores', 999901, 'platform', true);
    $first = Host::query($context, []);
    check(count($first['rows']) === 20 && $first['pagination']['total'] === 44, 'Framework page one and complete total');
    check((int)$first['summaries']['amount:sum'] === 4400, 'Full authorized sum rather than current-page sum');
    check((int)Host::query($context, ['summaries' => []])['summaries']['amount:sum'] === 4400, 'Cleared optional summaries cannot erase fixed amount total');
    // 旧录入可能留有小数点但没有小数；整表查询、合计、导出应按原金额读取且不得改写源记录。
    $originalInfo = Db::name('salary_store')->where('id', 1)->value('salary_info');
    $historical = json_decode($originalInfo, true); $historical['shuadanjine'] = '326.';
    $historicalInfo = json_encode($historical, JSON_UNESCAPED_UNICODE);
    Db::name('salary_store')->where('id', 1)->update(['salary_info' => $historicalInfo]);
    $historicalQuery = Host::query($context, ['export' => ['scope' => 'query', 'fields' => ['registration_id', 'amount']]]);
    check($historicalQuery['pagination']['total'] === 44 && (int)$historicalQuery['summaries']['amount:sum'] === 4626, 'Historical trailing decimal remains readable and contributes to whole sum');
    $historicalExport = array_values(array_filter($historicalQuery['exportRows'], static function ($r) { return (int)$r['registration_id'] === 1; }));
    check(count($historicalExport) === 1 && (int)$historicalExport[0]['amount'] === 326, 'Export matches historical amount');
    check(Db::name('salary_store')->where('id', 1)->value('salary_info') === $historicalInfo, 'Read does not alter original legacy value');
    Db::name('salary_store')->where('id', 1)->update(['salary_info' => $originalInfo]);
    $second = Host::query($context, ['page' => 2]);
    check(count($second['rows']) === 20 && $second['rows'][0]['registration_id'] !== $first['rows'][0]['registration_id'], 'Page two stable and not duplicated');
    check(count(Host::query($context, ['page' => 3])['rows']) === 4, 'Last-page remainder');
    $all = Host::query($context, ['export' => ['scope' => 'query', 'fields' => ['registration_id', 'amount']]]);
    check(count($all['exportRows']) === 44, 'Export uses complete authorized result');
    $filtered = Host::query($context, ['filters' => [['field' => 'coupon_code', 'operator' => 'eq', 'value' => 'R51-1']]]);
    check($filtered['pagination']['total'] === 1 && $filtered['rows'][0]['registered_date'] === null, 'Exact field filter and missing-date preservation');
    check((int)$filtered['summaries']['amount:sum'] === 100, 'Filtered sum matches filtered export');
    $empty = Host::query($context, ['keyword' => 'no-matching-value']);
    check($empty['pagination']['total'] === 0, 'Framework keyword empty result');
    $injected = Host::query($context, ['filters' => [['field' => 'coupon_code', 'operator' => 'eq', 'value' => "' OR 1=1 --"]]]);
    check($injected['pagination']['total'] === 0, 'SQL-like user text remains a value');
    rejects(static function () { Host::context([], 'stores', 1, 'store', true); }, 'Empty scope fails closed');
    rejects(static function () { Host::context([901], 'self_participant', 1, 'store', true); }, 'Personal scope not expanded');
    $row = $first['rows'][0];
    $payload = ['id' => $row['registration_id'], 'store_id' => 901, 'expected_version' => $row['record_version'],
        'expected_source_hash' => $row['source_hash'], 'idempotency_key' => 'r51-edit-0000000000000001',
        'platform' => '大众', 'registered_date' => '2026-10-23', 'coupon_code' => 'changed', 'amount' => '200', 'registration_type' => '探店'];
    $writer = new Writer(); $saved = $writer->save($context, $payload);
    check($saved['record_version'] === 2, 'First legacy edit establishes monotonic audit sequence');
    $readback = Host::query($context, ['filters' => [['field' => 'registration_id', 'operator' => 'eq', 'value' => $row['registration_id']]]]);
    check($readback['rows'][0]['amount_cents'] === 20000 && $readback['rows'][0]['platform'] === '大众', 'Saved values restored by fresh query');
    $info = json_decode(Db::name('salary_store')->where('id', $row['registration_id'])->value('salary_info'), true);
    check($info['legacy_note'] === '保留证据', 'Preserve non-edited legacy evidence');
    check($writer->save($context, $payload)['replayed'] === true, 'Idempotent replay');
    rejects(static function () use ($writer, $context, $payload) { $writer->save($context, array_merge($payload, ['amount' => '201'])); }, 'Same request cannot change payload');
    rejects(static function () use ($writer, $context, $payload) { $writer->save($context, array_merge($payload, ['idempotency_key' => 'r51-stale-0000000000001'])); }, 'Stale edit fails');
    foreach (['1.5', '-1', '1e2', '100.00'] as $bad) rejects(static function () use ($payload, $bad) { Writer::values(array_merge($payload, ['amount' => $bad])); }, 'Whole-yuan write: ' . $bad);
    rejects(static function () use ($payload) { Writer::values(array_merge($payload, ['registered_date' => '2026-02-30'])); }, 'Invalid calendar date');
    $readonly = $context; $readonly['basic_registration_write'] = false;
    rejects(static function () use ($writer, $readonly, $payload) { $writer->save($readonly, $payload); }, 'Read-only context cannot write');
    $foreign = $payload; $foreign['store_id'] = 902;
    rejects(static function () use ($writer, $context, $foreign) { $writer->save($context, $foreign); }, 'Cross-store write denied');
    $fresh = $readback['rows'][0];
    Db::name('salary_store')->where('id', $fresh['registration_id'])->update(['date' => 0]);
    $stale = array_merge($payload, ['expected_version' => $fresh['record_version'], 'expected_source_hash' => $fresh['source_hash'], 'idempotency_key' => 'r51-old-entry-edit-000001']);
    rejects(static function () use ($writer, $context, $stale) { $writer->save($context, $stale); }, 'Legacy editor change detected by same business-content hash');
    $create = array_merge($payload, ['id' => 0, 'expected_version' => 0, 'expected_source_hash' => '', 'coupon_code' => '', 'registered_date' => '', 'amount' => '0', 'idempotency_key' => 'r51-create-000000000001']);
    $new = $writer->save($context, $create);
    check($new['id'] > 45 && $new['record_version'] === 1, 'Create zero amount and explicitly empty fields');
    check($writer->save($context, $create)['id'] === $new['id'], 'Create replay produces no duplicate');
    check(Db::name('basic_report_registration_audit')->count() === 2, 'Only successful mutations have audits');
    $beforeFailure = Db::name('salary_store')->where('id', $new['id'])->find();
    $current = Provider::project($beforeFailure + ['store_name' => '夹具一店'], 1);
    // 强制审计插入失败：验证源登记修改与审计属于同一事务，不能只保存其中一半。
    $oldMode = Db::query('SELECT @@SESSION.sql_mode AS value')[0]['value'];
    Db::execute("SET SESSION sql_mode='STRICT_ALL_TABLES'");
    Db::execute('ALTER TABLE eb_basic_report_registration_audit ADD COLUMN fixture_required INT NOT NULL');
    $conflict = array_merge($create, ['id' => $new['id'], 'expected_version' => 1, 'expected_source_hash' => $current['source_hash'],
        'amount' => '999', 'idempotency_key' => 'r51-atomic-failure-000001']);
    $failed = false;
    try { $writer->save($context, $conflict); } catch (\think\db\exception\PDOException $e) { $failed = true; }
    check($failed, 'Force real audit insert failure after source mutation');
    check(Db::name('salary_store')->where('id', $new['id'])->find() === $beforeFailure, 'Failed mutation leaves original row unchanged');
    check(Db::name('basic_report_registration_audit')->count() === 2, 'Failed transaction adds no audit');
    Db::execute('SET SESSION sql_mode=?', [$oldMode]);
    check(Provider::cents('88.05') === 8805, 'Historical cents remain exact');
    check(Provider::cents('326.') === 32600 && Provider::cents('-3.') === -300, 'Trailing decimal parses exactly');
    foreach (['326..', '.', '1.001', '1e2', '1,000'] as $bad) rejects(static function () use ($bad) { Provider::cents($bad); }, 'Invalid historical amount rejected: ' . $bad);
    echo json_encode(['pass' => true, 'checks' => $n, 'source' => 'real_unified_query_runtime_and_temporary_mysql_tables', 'persistent_business_writes' => false]), PHP_EOL;
} finally {
    foreach ($tables as $table) Db::execute('DROP TEMPORARY TABLE IF EXISTS eb_' . $table);
}
