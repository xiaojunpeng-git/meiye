<?php
declare(strict_types=1);

/** Local MySQL integration only: clone empty schemas, seed synthetic historical
 * facts, invoke the real member readers in READ ONLY, and drop our unique DB in
 * finally. This test never imports customer rows or relaxes a writer authority.
 */
$root = getenv('BACKEND_ROOT') ?: getcwd();
require_once $root . '/vendor/autoload.php';
(new \think\App())->initialize();
// ThinkPHP's CLI renderer can otherwise format an exception yet exit zero.
set_exception_handler(static function (\Throwable $error): void {
    file_put_contents('php://stderr', json_encode(['result' => 'FAIL', 'exception' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
});

use think\facade\Db;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\report\BasicEntitlementReportServices;

$connection = config('database.connections.mysql');
if (!in_array($connection['hostname'] ?? '', ['mohe-mysql', 'mysql'], true)
    || ($connection['prefix'] ?? '') !== 'eb_') throw new RuntimeException('LOCAL_MYSQL_ONLY');
$source = $connection['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('UNSAFE_SOURCE_SCHEMA');
$database = 'r51_historical_read_' . bin2hex(random_bytes(8));
$checks = 0;
$inRead = false;
$readSql = [];
$readOnlyTransactions = 0;
$created = false;
$pdo = null;
$original = Db::connect();
$originalConfig = config('database');
$outcome = null;

function hcrCheck(bool $ok, string $name): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException('HISTORICAL_CARD_CONTRACT: ' . $name);
}

/** Use actual required columns/defaults, but never fill an unspecified business
 * amount or copy a source row. Each fixture insert rechecks the isolated DB.
 */
function hcrFixture(string $table, array $values): void
{
    global $database;
    static $columns = [];
    if (Db::query('SELECT DATABASE() AS db')[0]['db'] !== $database) throw new RuntimeException('FIXTURE_DATABASE_ESCAPE');
    if (!preg_match('/^[a-z0-9_]+$/D', $table)) throw new RuntimeException('FIXTURE_TABLE_INVALID');
    if (!isset($columns[$table])) $columns[$table] = Db::query('SHOW COLUMNS FROM `eb_' . $table . '`');
    foreach ($columns[$table] as $column) {
        $key = $column['Field'];
        if (array_key_exists($key, $values) || $column['Null'] === 'YES' || $column['Default'] !== null
            || strpos($column['Extra'], 'auto_increment') !== false) continue;
        $type = strtolower($column['Type']);
        $values[$key] = preg_match('/int|decimal|float|double/', $type) ? 0
            : (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false ? '2026-01-01 00:00:00'
            : (strpos($type, 'date') !== false ? '2026-01-01' : (strpos($type, 'json') !== false ? '{}' : '')));
    }
    Db::name($table)->insert($values);
}

/** Full row fingerprints include amounts, counts, snapshots and versions in all
 * copied tables, not just the two columns whose presentation is being repaired.
 */
function hcrFingerprint(array $tables): array
{
    $hashes = [];
    foreach ($tables as $table) {
        $rows = Db::query('SELECT * FROM `' . $table . '`');
        $encoded = array_map(static function (array $row): string { ksort($row); return json_encode($row, JSON_THROW_ON_ERROR); }, $rows);
        sort($encoded, SORT_STRING);
        $hashes[$table] = hash('sha256', json_encode($encoded, JSON_THROW_ON_ERROR));
    }
    ksort($hashes);
    return $hashes;
}

/** All final business queries use the default connection's actual PDO in a
 * server-enforced read-only transaction, including nested service queries.
 */
function hcrReadOnly(callable $read)
{
    global $pdo, $database, $inRead, $readOnlyTransactions;
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $inRead = true;
    try {
        $identity = Db::query('SELECT DATABASE() AS db,@@session.tx_read_only AS ro')[0];
        hcrCheck($identity['db'] === $database && (int)$identity['ro'] === 1 && $pdo->inTransaction(), 'real read-only transaction identity');
        $readOnlyTransactions++;
        return $read();
    } finally {
        $inRead = false;
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('SET SESSION TRANSACTION READ WRITE');
    }
}

function hcrDenied(string $label, callable $read): void
{
    try { $read(); }
    catch (CashierV3CommandException | CashierV3MemberBalanceContractException $error) { hcrCheck(true, $label); return; }
    throw new RuntimeException('EXPECTED_PERMISSION_REJECTION: ' . $label);
}

try {
    $original->execute('CREATE DATABASE `' . $database . '`');
    $created = true;
    // Use source metadata only. LIKE copies neither data nor triggers; every
    // cashier rule/debt/shared-pool/fact table is available as an empty schema.
    $available = $original->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=\'BASE TABLE\'', [$source]);
    $tables = [];
    $explicit = ['eb_user_card_holder', 'eb_system_store', 'eb_user', 'eb_store_user', 'eb_store_debt'];
    foreach ($available as $row) {
        $table = $row['TABLE_NAME'];
        if (strpos($table, 'eb_cashier_v3_') !== 0 && strpos($table, 'eb_store_order') !== 0 && !in_array($table, $explicit, true)) continue;
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('UNSAFE_SOURCE_TABLE');
        $original->execute('CREATE TABLE `' . $database . '`.`' . $table . '` LIKE `' . $source . '`.`' . $table . '`');
        $tables[] = $table;
    }
    foreach (array_merge($explicit, ['eb_store_order', 'eb_store_order_cart_info']) as $table) hcrCheck(in_array($table, $tables, true), 'required schema ' . $table);
    $name = $database;
    $config = $originalConfig;
    $config['connections'][$name] = array_replace($connection, ['database' => $database]);
    $config['default'] = $name;
    config($config, 'database');
    Db::query('SELECT 1');
    $pdo = Db::connect()->getPdo();
    hcrCheck($pdo instanceof PDO && Db::query('SELECT DATABASE() AS db')[0]['db'] === $database, 'default facade confined to fixture');
    foreach ($tables as $table) hcrCheck((int)Db::query('SELECT COUNT(*) AS n FROM `' . $table . '`')[0]['n'] === 0, 'no source data copied ' . $table);
    Db::listen(static function ($sql) use (&$inRead, &$readSql): void { if ($inRead) $readSql[] = (string)$sql; });

    hcrFixture('system_store', ['id' => 901, 'name' => 'R51 synthetic historical store']);
    foreach ([11, 12, 13, 14, 15, 16, 17] as $uid) {
        hcrFixture('user', ['uid' => $uid, 'account' => 'r51-history-' . $uid, 'nickname' => 'Synthetic ' . $uid,
            'real_name' => 'Synthetic ' . $uid, 'status' => 1, 'is_del' => 0, 'delete_time' => null, 'belong_store_id' => 901,
            'now_money' => $uid === 11 ? '12.00' : ($uid === 12 ? '20.00' : '0.00'),
            'ben_money' => $uid === 11 ? '10.00' : ($uid === 12 ? '20.00' : '0.00'),
            'give_money' => $uid === 11 ? '2.00' : '0.00', 'balance_version' => 1]);
        hcrFixture('store_user', ['uid' => $uid, 'store_id' => 901, 'status' => 1]);
    }
    // A real deletion timestamp overrides the apparently enabled flags and an
    // active same-store relation. Large assets make accidental inclusion visible;
    // the report must filter this member, not relax the shared balance provider.
    hcrFixture('user', ['uid' => 18, 'account' => 'r51-history-deleted', 'nickname' => 'Deleted synthetic',
        'status' => 1, 'is_del' => 0, 'delete_time' => '2026-01-02 03:04:05', 'belong_store_id' => 901,
        'now_money' => '1000.00', 'ben_money' => '900.00', 'give_money' => '100.00', 'balance_version' => 1]);
    hcrFixture('store_user', ['uid' => 18, 'store_id' => 901, 'status' => 1]);
    $seedCard = static function (int $id, int $uid, string $paid, int $remaining = 2, array $snapshot = [], array $order = [], array $holder = [], array $cart = []): void {
        hcrFixture('store_order', array_replace(['id' => $id, 'order_id' => 'R51-HIST-' . $id, 'unique' => md5('r51-history-' . $id),
            'uid' => $uid, 'store_id' => 901, 'pay_price' => $paid, 'paid' => 1, 'is_del' => 0, 'is_user_del' => 0,
            'is_system_del' => 0, 'card_upgrade_use_oid' => 0, 'refund_status' => 0, 'terminal_action' => 0,
            'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00', 'add_time' => time() - 86400], $order));
        hcrFixture('user_card_holder', array_replace(['id' => $id, 'uid' => $uid, 'oid' => $id, 'store_id' => 901,
            'card_name' => 'Synthetic historical card', 'card_no' => sprintf('%07d', $id), 'is_del' => 0,
            'write_times' => 3, 'write_surplus_times' => $remaining, 'write_start' => 0, 'write_end' => 0, 'add_time' => time() - 86400], $holder));
        hcrFixture('store_order_cart_info', array_replace(['id' => $id, 'oid' => $id, 'uid' => $uid, 'product_id' => 6000 + $id,
            'cart_type' => 2, 'product_type' => 6, 'is_writeoff' => 0, 'pay_price' => $paid, 'write_times' => 3,
            'write_surplus_times' => $remaining, 'write_start' => 0, 'write_end' => 0,
            'cart_info' => json_encode(array_replace(['productInfo' => ['store_name' => 'Synthetic service']], $snapshot), JSON_THROW_ON_ERROR)], $cart));
    };
    $seedCard(101, 11, '100.01');
    $seedCard(102, 12, '100.00');
    // A removed refunded use is not a second consumed service: 10001 - 3333
    // consumed - 3333 refunded = 3335 cents, with one remaining actual use.
    $seedCard(103, 13, '100.01', 1, ['r26RefundRemovedTimes' => 1, 'r26RefundedCents' => 3333]);
    $seedCard(104, 14, '100.01', 2, [], ['debt_amount' => '10.00']);
    hcrFixture('store_debt', ['id' => 104, 'debt_no' => 'R51-HIST-DEBT', 'uid' => 14, 'store_id' => 901,
        'order_id' => 104, 'status' => 0, 'total_debt' => '10.00', 'repaid_debt' => '0.00']);
    $seedCard(105, 15, '100.01', 2, [], [], ['write_end' => time() - 10]);
    $seedCard(106, 16, '100.01', 2, [], ['refund_status' => 1]);
    $seedCard(107, 17, '100.01', 2, [], [], ['is_del' => 1]);
    $seedCard(108, 17, '100.01', 2, [], ['is_system_del' => 1]);
    $seedCard(109, 17, '100.01', 2, [], [], [], ['write_end' => time() - 10]);
    $seedCard(110, 17, '100.01', 2, [], [], ['write_start' => time() + 86400]);
    $seedCard(111, 17, '100.01', 0);
    $seedCard(112, 17, '100.01', 2, [], ['terminal_action' => 1]);
    $seedCard(113, 17, '100.01', 2, [], ['paid' => 0]);
    $seedCard(114, 17, '100.01', 2, [], ['is_del' => 1]);
    $seedCard(115, 17, '100.01', 2, [], ['card_upgrade_use_oid' => 99]);
    $seedCard(116, 17, '100.01', 2, [], ['is_user_del' => 1]);
    $seedCard(117, 18, '100.01');
    $before = hcrFingerprint($tables);
    $service = new CashierV3MemberDetailQueryServices();
    $reflection = new ReflectionMethod($service, 'cardsForMembers');
    if (PHP_VERSION_ID < 80100) $reflection->setAccessible(true);
    $cards = static function (array $ids, string $status = 'active') use ($reflection, $service): array {
        return $reflection->invoke($service, $ids, '0', '', $status, '', '', 901);
    };
    $operator = new CashierV3OperatorScope(901, 991, 'TEST-ORG', '0');
    $scope = new CashierV3DataScopeContext(991, 0, 901, '0', 'TEST-ORG', [901], 'stores', [], false, '', 'test', [], []);

    hcrReadOnly(static function () use ($cards, $service, $operator, $scope, $tables, $before): void {
        $single = $cards([11]);
        hcrCheck($single[11][0]['remainingAmount'] === '66.68', 'single historical 100.01 / 3 with one used = 66.68 yuan');
        hcrCheck((int)bcmul($single[11][0]['remainingAmount'], '100', 0) === 6668, 'exact remaining historical cents are 6668');
        hcrCheck($single[11][0]['projects'][0]['purchaseTimes'] === 3 && $single[11][0]['projects'][0]['usedTimes'] === 1
            && $single[11][0]['projects'][0]['remainingTimes'] === 2, 'historical quantities are not normalized');
        $batch = $cards([12, 11, 13, 14, 15, 16, 17]);
        hcrCheck($batch[11] === $single[11], 'batch and single actual card projections agree');
        hcrCheck($batch[12][0]['remainingAmount'] === '67.00', 'whole 100 / 3 retains whole-yuan remaining 67');
        hcrCheck($batch[13][0]['remainingAmount'] === '33.35' && $batch[13][0]['projects'][0]['usedTimes'] === 1,
            'refund removed use and cents are not double counted');
        hcrCheck($batch[14][0]['remainingAmount'] === '56.68' && $batch[14][0]['debtLimitedRemainingTimes'] === 1,
            'historical fractional read retains real debt amount and count restriction');
        foreach ([15, 16, 17] as $uid) hcrCheck(empty($batch[$uid]), 'active filtering remains for member ' . $uid);
        $all = $cards([15], '');
        hcrCheck($all[15][0]['remainingAmount'] === '66.68', 'expired historical card stays readable for audit outside active filter');
        $rights = $service->readAiRightsSet([11, 12], $operator, $scope);
        hcrCheck($rights[11]['summary']['remainingProjectAmount'] === '66.68' && $rights[12]['summary']['remainingProjectAmount'] === '67.00', 'real AI set uses the same historical and whole-yuan card reader');
        hcrCheck($rights[11]['summary']['remainingProjectTimes'] === 2 && $rights[11]['summary']['activeCardCount'] === 1, 'AI count and card identity preserved');
        hcrCheck($service->readAiRemainingProjectAmounts([11, 12], '0', [901]) === [11 => 6668, 12 => 6700], 'scoped report amount reader returns exact cents');
        hcrDenied('wrong store cannot use report amount reader', static function () use ($service): void { $service->readAiRemainingProjectAmounts([11], '0', [902]); });
        $none = new CashierV3DataScopeContext(991, 0, 901, '0', 'TEST-ORG', [], 'none', [], false, '', 'test', [], []);
        hcrDenied('AI detail none scope rejected', static function () use ($service, $operator, $none): void { $service->readAiRightsSet([11], $operator, $none); });
        $wrongTenant = new CashierV3OperatorScope(901, 991, 'TEST-ORG', 'OTHER');
        hcrDenied('AI session tenant mismatch rejected', static function () use ($service, $wrongTenant, $scope): void { $service->readAiRightsSet([11], $wrongTenant, $scope); });
        hcrCheck(hcrFingerprint($tables) === $before, 'all fixture orders/card rows/quantities/amounts/versions/facts unchanged inside read-only transaction');
    });
    hcrReadOnly(static function () use ($cards, $tables, $before): void {
        hcrCheck($cards([11])[11][0]['remainingAmount'] === '66.68', 'fresh read-only transaction repeats exact historical result');
        hcrCheck(hcrFingerprint($tables) === $before, 'no persistence mutation after previous reader transaction');
    });
    hcrReadOnly(static function () use ($operator, $scope, $tables, $before): void {
        hcrCheck((int)Db::name('user')->where('uid', 18)->where('status', 1)->where('is_del', 0)
            ->whereNotNull('delete_time')->count() === 1
            && (int)Db::name('store_user')->where('uid', 18)->where('store_id', 901)->where('status', 1)->count() === 1,
            'soft-deleted enabled-flag fixture really has an active same-store relation');
        $provider = new CashierV3MemberBalanceProvider();
        $rejectedReads = [
            'single' => static function () use ($provider, $operator, $scope) { return $provider->readSnapshot(18, $operator, $scope); },
            'batch' => static function () use ($provider, $operator, $scope) { return $provider->readSnapshots([11, 18], $operator, $scope); },
            'AI cents' => static function () use ($provider) { return $provider->readAiBalanceCents([18], [901]); },
        ];
        foreach ($rejectedReads as $label => $read) {
            $rejected = false;
            try { $read(); }
            catch (CashierV3MemberBalanceContractException $error) { $rejected = $error->reason() === 'member_balance_member_not_found'; }
            hcrCheck($rejected, 'unchanged provider rejects deleted member via ' . $label);
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $input = ['_report_scope' => $scope, 'as_of_date' => $today];
        $report = new BasicEntitlementReportServices();
        $summary = $report->read('basic_unconsumed_summary', [901], $input);
        hcrCheck($summary['complete'] === true && count($summary['rows']) === 1
            && $summary['rows'][0]['store_id'] === 901, 'real summary succeeds for valid population despite one soft-deleted member');
        $row = $summary['rows'][0];
        // Valid rights: 6668 historical + 6700 whole + 3335 refund + 5668 debt.
        // Wallets: principal 30 + gift 2. Deleted member's 1000 and card are absent.
        hcrCheck((string)$row['rights_amount'] === '223.71' && (string)$row['principal_amount'] === '30'
            && (string)$row['gift_amount'] === '2' && (string)$row['balance_amount'] === '32'
            && (string)$row['total_assets'] === '255.71', 'report preserves exact valid historical/whole current assets and excludes deleted assets');
        $detail = $report->read('basic_unconsumed_detail', [901], $input);
        $population = array_values(array_unique(array_map('intval', array_column($detail['rows'], 'member_id'))));
        sort($population, SORT_NUMERIC);
        hcrCheck($detail['complete'] === true && $population === [11, 12, 13, 14, 15, 16, 17],
            'same report population excludes deleted member from every detail row');
        $rights = [];
        foreach ($detail['rows'] as $detailRow) {
            hcrCheck((int)$detailRow['member_id'] !== 18 && (int)$detailRow['store_id'] === 901,
                'detail never leaks excluded identity or widens store scope');
            if ($detailRow['asset_type'] === '卡项') $rights[(int)$detailRow['member_id']] = $detailRow['rights_amount'];
        }
        hcrCheck((string)$rights[11] === '66.68' && (string)$rights[12] === '67', 'real detail keeps historical cents and whole-yuan authority unchanged');
        hcrCheck($report->read('basic_unconsumed_summary', [901], $input)['rows'] === $summary['rows'],
            'repeat summary keeps identical current population and amounts');
        hcrCheck(hcrFingerprint($tables) === $before, 'report exclusion is read filtering only and never clears deletion or edits source assets');
    });
    hcrCheck($readSql !== [], 'real SQL statements captured');
    foreach ($readSql as $sql) {
        hcrCheck(!preg_match('/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $sql), 'no row locks in observed reader SQL');
        hcrCheck(!preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql), 'no mutation SQL in observed reader transaction');
    }
    $outcome = ['result' => 'PASS', 'checks' => $checks, 'database' => $database, 'copied_empty_tables' => count($tables),
        'read_only_transactions' => $readOnlyTransactions, 'observed_read_statements' => count($readSql),
        'historical_remaining_yuan' => '66.68', 'historical_remaining_cents' => 6668, 'whole_remaining_yuan' => '67.00',
        'soft_deleted_member_excluded' => true, 'deleted_member_direct_reads_rejected' => 3, 'report_active_members' => 7,
        'report_rights_yuan' => '223.71', 'report_balance_yuan' => '32.00', 'report_total_assets_yuan' => '255.71',
        'fixture_state_sha256' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
        'fixture_fingerprints_unchanged' => true, 'source_data_copied' => false, 'remote_access' => false, 'persistent_business_writes' => false];
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    config($originalConfig, 'database');
    if ($created) {
        if (!preg_match('/^r51_historical_read_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('CLEANUP_DATABASE_NOT_OWNED');
        $original->execute('DROP DATABASE `' . $database . '`');
        if ($original->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$database])) throw new RuntimeException('TEMPORARY_DATABASE_CLEANUP_FAILED');
        if ($outcome !== null) $outcome['temporary_database_dropped'] = true;
    }
}
if ($outcome !== null) echo json_encode($outcome, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
