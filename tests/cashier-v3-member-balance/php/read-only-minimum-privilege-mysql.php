<?php
declare(strict_types=1);

/** Local-only disposable MySQL fixture. Reproduces hidden trigger metadata with
 * a SELECT-only account, without granting production privileges or touching
 * real members. The tested provider is the canonical mounted PHP source. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function (Throwable $e): void {
    $label = $e instanceof RuntimeException && !($e instanceof PDOException)
        && preg_match('/^[a-zA-Z0-9_]+$/D',$e->getMessage()) ? $e->getMessage() : get_class($e);
    fwrite(STDERR,'R51_LOCAL_BALANCE_TEST_FAILED:'.$label.':'.$e->getLine().PHP_EOL); exit(1);
});

use think\facade\Db;
use think\facade\Config;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope as Operator;
use app\services\cashier\v3\CashierV3ResourceScope as ResourceScope;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider as Provider;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;

$config = config('database.connections.mysql');
$rootConfig = $config;
if (getenv('R51_LOCAL_TEST') !== '1' || !in_array($config['hostname'], ['mysql','mohe-mysql'], true))
    throw new RuntimeException('LOCAL_DISPOSABLE_MYSQL_ONLY');
$root = new PDO('mysql:host='.$config['hostname'].';port='.($config['hostport'] ?: 3306).';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$token = bin2hex(random_bytes(6));
$database = 'r51_balance_read_'.$token;
$username = 'r51_ro_'.$token;
$password = bin2hex(random_bytes(24));
$account = $root->quote($username)."@'%'";
$checks = 0; $captured = []; $capture = false;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    ++$checks; if (!$ok) throw new RuntimeException($label);
};
$reason = static function (callable $read): string {
    try { $read(); } catch (CashierV3MemberBalanceContractException $e) { return $e->reason(); }
    return '';
};
$scope = new Scope(21,701,7,'0','',[7],Scope::MODE_STORES,[],false,'','r51-read-only',[],[],true);
$operator = new Operator(7,21,'','0');
$hashRows = static function () use ($root,$database): string {
    return hash('sha256',json_encode($root->query('SELECT * FROM `'.$database.'`.eb_user ORDER BY uid')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
};
Db::listen(static function ($sql) use (&$captured,&$capture): void { if ($capture) $captured[] = (string)$sql; });
try {
    $root->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
    $root->exec('CREATE TABLE `'.$database.'`.eb_user (
        uid BIGINT UNSIGNED PRIMARY KEY, now_money DECIMAL(12,2) NOT NULL,
        ben_money DECIMAL(12,2) NOT NULL, give_money DECIMAL(12,2) NOT NULL,
        balance_version BIGINT UNSIGNED NOT NULL DEFAULT 1, status TINYINT NOT NULL DEFAULT 1,
        is_del TINYINT NOT NULL DEFAULT 0, delete_time BIGINT NULL,
        belong_store_id BIGINT NOT NULL DEFAULT 7) ENGINE=InnoDB');
    $root->exec('CREATE TABLE `'.$database.'`.eb_store_user (uid BIGINT NOT NULL,store_id BIGINT NOT NULL,status TINYINT NOT NULL) ENGINE=InnoDB');
    $root->exec('CREATE TRIGGER `'.$database.'`.eb_user_balance_version_bu BEFORE UPDATE ON `'.$database.'`.eb_user
        FOR EACH ROW BEGIN IF NOT (NEW.now_money <=> OLD.now_money) OR NOT (NEW.ben_money <=> OLD.ben_money)
        OR NOT (NEW.give_money <=> OLD.give_money) THEN SET NEW.balance_version=OLD.balance_version+1;
        ELSE SET NEW.balance_version=OLD.balance_version; END IF; END');
    $root->exec('INSERT INTO `'.$database.'`.eb_user (uid,now_money,ben_money,give_money,is_del,belong_store_id)
        VALUES (101,120,100,20,0,7),(102,200,100,20,0,7),(103,120,100,20,1,7),(104,120,100,20,0,8)');
    $root->exec('INSERT INTO `'.$database.'`.eb_store_user VALUES (101,7,1),(102,7,1),(103,7,1),(104,8,1)');
    $root->exec('CREATE USER '.$account.' IDENTIFIED BY '.$root->quote($password));
    $root->exec('GRANT SELECT ON `'.$database.'`.* TO '.$account);
    $config['database'] = $database; $config['username'] = $username; $config['password'] = $password;
    Config::set(['default'=>'r51_minimum','connections'=>['r51_minimum'=>$config]],'database');
    $connection = Db::connect('r51_minimum',true);
    $connection->query('SELECT 1');
    $pdo = $connection->getPdo();
    $assert($pdo->query('SELECT DATABASE()')->fetchColumn() === $database,'fixture_database_identity');
    $beforeGrants = hash('sha256',json_encode($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)));
    $query = $root->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND TRIGGER_NAME=?');
    $query->execute([$database,Provider::VERSION_TRIGGER]);
    $assert((int)$query->fetchColumn() === 1,'trigger_really_exists_for_privileged_inspector');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn() === 0,
        'select_only_account_cannot_see_trigger_metadata');
    $provider = new Provider();
    $assert($provider->readReadinessStatus()['ready'] === true,'read_schema_ready_without_trigger_metadata');
    $assert($provider->readinessStatus()['ready'] === false,'mutation_guard_still_rejects_unprovable_trigger');
    $beforeRows = $hashRows();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY'); $connection->startTrans(); $capture = true;
    try {
        $assert((int)$pdo->query('SELECT @@session.tx_read_only')->fetchColumn() === 1,'real_read_only_transaction');
        $single = $provider->readSnapshot(101,$operator,$scope);
        $assert($single['principalCents'] === 10000 && $single['giftCents'] === 2000 && $single['totalCents'] === 12000,
            'single_member_balance_exact_and_conserved');
        $assert($provider->readSnapshots([101],$operator,$scope)[101] === $single,'batch_matches_same_authority');
        $assert($provider->readAiBalanceCents([101],[7]) === [101=>12000],'scoped_report_balance_matches_detail');
        $assert($reason(static function () use ($provider,$operator,$scope): void { $provider->readSnapshot(102,$operator,$scope); })
            === 'member_balance_invariant_invalid','single_inconsistent_balance_rejected');
        $assert($reason(static function () use ($provider,$operator,$scope): void { $provider->readSnapshots([102],$operator,$scope); })
            === 'member_balance_invariant_invalid','batch_inconsistent_balance_rejected');
        $assert($reason(static function () use ($provider): void { $provider->readAiBalanceCents([102],[7]); })
            === 'member_balance_invariant_invalid','report_inconsistent_balance_rejected');
        $assert($reason(static function () use ($provider,$operator,$scope): void { $provider->readSnapshot(103,$operator,$scope); })
            === 'member_balance_member_not_found','deleted_member_rejected');
        $assert($reason(static function () use ($provider): void { $provider->readAiBalanceCents([104],[7]); })
            === 'member_balance_member_not_found','other_store_report_member_rejected');
        $wrongOperator = new Operator(8,21,'','0');
        $assert($reason(static function () use ($provider,$wrongOperator,$scope): void { $provider->readSnapshot(101,$wrongOperator,$scope); })
            === 'member_balance_data_scope_session_mismatch','session_scope_mismatch_rejected');
        $assert($reason(static function () use ($provider,$operator,$scope): void { $provider->lockSnapshotInTx(101,$operator,$scope); })
            === 'member_balance_provider_not_ready','locked_write_entry_not_relaxed');
        $assert($provider->resolveScopeWithDataScope('member_balance','101',$operator,$scope) === null,
            'mutation_resource_resolution_not_relaxed');
    } finally { $capture = false; $connection->rollback(); }
    $assert(!preg_grep('/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i',$captured),'no_locking_read');
    $assert(!preg_grep('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$captured),'no_business_writes_during_reads');
    $assert($hashRows() === $beforeRows,'fixture_member_balances_unchanged');
    $assert(hash('sha256',json_encode($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN))) === $beforeGrants,
        'application_grants_unchanged');
    // The untouched write guard still works for a writer that can establish the
    // real trigger proof. Its local fixture mutation is rolled back in full.
    $rootConfig['database'] = $database;
    Config::set(['default'=>'r51_writer','connections'=>['r51_writer'=>$rootConfig]],'database');
    $writer = Db::connect('r51_writer',true); $writer->query('SELECT 1');
    $assert($provider->readinessStatus()['ready'] === true,'writer_still_requires_real_visible_trigger');
    $writer->startTrans();
    try {
        $locked=$provider->lockSnapshotInTx(101,$operator,$scope);
        $assert($locked['totalCents']===12000 && $locked['accountVersion']===1,'writer_still_locks_original_account');
        Db::name('user')->where('uid',101)->update(['ben_money'=>'99.00','now_money'=>'119.00']);
        $after=$provider->lockSnapshotInTx(101,$operator,$scope);
        $assert($after['totalCents']===11900 && $after['accountVersion']===2,'real_trigger_advances_once_after_fixture_mutation');
        $assert($provider->bumpVersionWithDataScope(ResourceScope::of('tenant','0'),'member_balance','101','submit-checkout',$scope)===2,
            'writer_does_not_add_shadow_advance');
    } finally { $writer->rollback(); }
    $assert($hashRows()===$beforeRows,'writer_fixture_transaction_fully_rolled_back');
    // A genuine schema failure is still rejected rather than treated as zero.
    $root->exec('ALTER TABLE `'.$database.'`.eb_user MODIFY give_money DECIMAL(12,3) NOT NULL');
    $assert($provider->readReadinessStatus()['ready'] === false,'wrong_money_scale_not_readable');
    $assert($reason(static function () use ($provider,$operator,$scope): void { $provider->readSnapshot(101,$operator,$scope); })
        === 'member_balance_provider_not_ready','wrong_schema_single_read_rejected');
    $assert($reason(static function () use ($provider,$operator,$scope): void { $provider->readSnapshots([101],$operator,$scope); })
        === 'member_balance_provider_not_ready','wrong_schema_batch_read_rejected');
    $assert($reason(static function () use ($provider): void { $provider->readAiBalanceCents([101],[7]); })
        === 'member_balance_provider_not_ready','wrong_schema_report_read_rejected');
    echo json_encode(['result'=>'PASS','checks'=>$checks,'trigger_exists'=>true,'restricted_trigger_visible'=>false,
        'reads_work'=>true,'mutation_guard_preserved'=>true,'application_grants_unchanged'=>true,
        'local_fixture_only'=>true,'persistent_business_writes'=>false,'remote_access'=>false]),PHP_EOL;
} finally {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $root->exec('DROP USER IF EXISTS '.$account);
    $root->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
