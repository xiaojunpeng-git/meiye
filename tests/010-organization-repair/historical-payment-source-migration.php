<?php
/** Run the exact batch on the isolated 010 copy; verify full rollback and protected tables. */
$c = parse_ini_file('/root/.mysql57-008.cnf', true, INI_SCANNER_RAW)['client'];
$db = new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_migration_test_refresh;charset=utf8mb4', $c['user'] ?? 'root', $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'mohe_010_migration_test_refresh') { throw new RuntimeException('ISOLATION_REQUIRED'); }
require __DIR__ . '/19-payment-source.php';
$planPath = __DIR__ . '/payment-source-plan.json';
$tables = ['eb_store_order', 'eb_user', 'eb_user_money', 'eb_user_card_holder', 'eb_store_order_writeoff',
    'eb_store_order_cart_info', 'eb_store_order_refund', 'eb_system_store', 'eb_system_store_staff',
    'eb_system_admin', 'eb_system_menus', 'eb_store_product', 'eb_system_config'];
$checksum = static function () use ($db, $tables): array {
    $rows = $db->query('CHECKSUM TABLE ' . implode(',', $tables))->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) { if ($row['Checksum'] === null) { throw new RuntimeException('CHECKSUM_UNAVAILABLE:' . $row['Table']); } }
    return $rows;
};
$before = $checksum();
$result = repair010HistoricalPaymentSources($db, $planPath, 'rehearse');
if ($result['applied'] !== 22421 || !$result['rolledBack'] || $before !== $checksum()) { throw new RuntimeException('REHEARSAL_OR_ROLLBACK_FAILED'); }
if ((int)$db->query('SELECT COUNT(*) FROM codex_010_history_payment_source_audit')->fetchColumn() !== 0) { throw new RuntimeException('AUDIT_NOT_ROLLED_BACK'); }
// A late baseline conflict must roll back thousands of already staged updates, not leave a partial batch.
$plan = json_decode(file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
$last = end($plan['rows']); $lastId = (int)$last['orderId'];
$db->exec('UPDATE eb_store_order SET paid_balance_ready=1 WHERE id=' . $lastId);
$conflict = '';
try { repair010HistoricalPaymentSources($db, $planPath, 'rehearse'); }
catch (Throwable $error) { $conflict = $error->getMessage(); }
finally { $db->exec('UPDATE eb_store_order SET paid_balance_ready=0 WHERE id=' . $lastId); }
if ($conflict !== 'ORDER_BASELINE_CHANGED:' . $lastId . ':paid_balance_ready' || $before !== $checksum()) { throw new RuntimeException('LATE_CONFLICT_ROLLBACK_FAILED:' . $conflict); }
$wrongDatabaseRejected = false;
try { repair010HistoricalPaymentSources($db, $planPath, 'apply'); }
catch (Throwable $error) { $wrongDatabaseRejected = $error->getMessage() === '010_DATABASE_BOUNDARY'; }
if (!$wrongDatabaseRejected) { throw new RuntimeException('DATABASE_BOUNDARY_FAILED'); }
echo json_encode(['status' => 'PASS', 'result' => $result, 'protectedTables' => count($tables),
    'fullRollbackVerified' => true, 'lateConflictRollbackVerified' => true, 'wrongDatabaseRejected' => true], JSON_PRETTY_PRINT), PHP_EOL;
