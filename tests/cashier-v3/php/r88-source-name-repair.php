<?php
declare(strict_types=1);
// Disposable database only: exercise all target schemas, exact updates, rollback and concurrency guards.
define('R88_LIBRARY_ONLY', true);
require '/var/www/html/database/upgrades/2026-10-17-瑞昊二级来源名称同步/repair.php';
$cfg = parse_ini_file('/var/www/html/.env', true)['DATABASE'];
if ($cfg['HOSTNAME'] !== 'mysql') throw new RuntimeException('Local Docker only');
$db = new PDO('mysql:host=mysql;port=' . $cfg['HOSTPORT'] . ';charset=utf8mb4', $cfg['USERNAME'], $cfg['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true]);
$name = 'r88_names_test_' . bin2hex(random_bytes(5));
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$db->exec("USE `$name`");
$checks = 0;
function r88Assert(bool $ok): void { global $checks; if (!$ok) throw new RuntimeException('Test failed at ' . $checks); $checks++; }
try {
    $db->exec('CREATE TABLE eb_cashier_v3_business_source (id BIGINT PRIMARY KEY,parent_id BIGINT,name VARCHAR(64)) ENGINE=InnoDB');
    $db->exec("INSERT INTO eb_cashier_v3_business_source VALUES (4,0,'Parent'),(16,4,'EPartner'),(118,4,'EGuide')");
    foreach (r88Tables() as $table => $f) {
        $db->exec("CREATE TABLE `$table` (id BIGINT PRIMARY KEY,`{$f[0]}` BIGINT,`{$f[1]}` BIGINT,
            `{$f[2]}` VARCHAR(64),`{$f[3]}` VARCHAR(64),`{$f[4]}` VARCHAR(140),amount BIGINT,business_date DATE) ENGINE=InnoDB");
        $db->exec("INSERT INTO `$table` VALUES
            (1,4,16,'HistoricalParent','Partner','HistoricalParent / Partner',-123,'2026-10-17'),
            (2,4,118,'Parent','Guide','Parent / Guide',999,'2026-09-01'),
            (3,4,16,'Parent','EPartner','Parent / EPartner',100,'2026-09-01'),
            (4,5,16,'WrongParent','Partner','WrongParent / Partner',100,'2026-09-01'),
            (5,4,999,'Parent','Missing','Parent / Missing',100,'2026-09-01'),
            (6,0,0,'','','',100,'2026-09-01')");
    }
    $plan = r88Plan($db);
    r88Assert(array_sum($plan['counts']) === 26);
    r88Assert(json_decode(r88Json($plan), true) === $plan);
    $db->beginTransaction();
    r88Assert(r88Apply($db, $plan) === 26);
    r88Assert(array_sum(r88Plan($db)['counts']) === 0);
    r88Assert(r88Apply($db, $plan, true) === 26);
    r88Assert(r88Plan($db) === $plan);
    $db->commit();
    // A concurrent business edit must abort, not be overwritten by the recovery plan.
    $db->exec('UPDATE eb_cashier_v3_balance_fact SET amount=888 WHERE id=1');
    $db->beginTransaction();
    try { r88Apply($db, $plan); throw new LogicException('Missing concurrency protection'); }
    catch (RuntimeException $e) { r88Assert($e->getMessage() === 'Protected columns changed'); }
    $db->rollBack();
    $db->exec('UPDATE eb_cashier_v3_balance_fact SET amount=-123 WHERE id=1');
    $db->exec("UPDATE eb_cashier_v3_business_source SET name='NewerName' WHERE id=16");
    $db->beginTransaction();
    try { r88Apply($db, $plan); throw new LogicException('Missing catalog protection'); }
    catch (RuntimeException $e) { r88Assert($e->getMessage() === 'Catalog changed'); }
    $db->rollBack();
    $db->exec("UPDATE eb_cashier_v3_business_source SET name='EPartner' WHERE id=16");
    $db->exec("UPDATE eb_cashier_v3_balance_fact SET business_source_label_snapshot='Unexpected' WHERE id=1");
    try { r88Plan($db); throw new LogicException('Missing label protection'); }
    catch (RuntimeException $e) { r88Assert($e->getMessage() === 'Unexpected source label'); }
    echo r88Json(['checks' => $checks, 'tables' => count(r88Tables()), 'update_restore_rows' => 26, 'result' => 'PASS']), "\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE `$name`");
}
