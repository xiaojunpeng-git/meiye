<?php
/**
 * Disposable MySQL 5.7 test for the 008-only legacy display import.
 * Connect only to the local test container and remove the scratch schema.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../scripts/import/customer_008_morestore_service_history.php';

$password = getenv('M008_TEST_PASSWORD');
if ($password === false || $password === '') {
    throw new RuntimeException('M008_TEST_PASSWORD is required for the disposable MySQL test');
}
$host = new PDO('mysql:host=127.0.0.1;port=13318;charset=utf8mb4', 'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = 'm008_service_history_test_' . bin2hex(random_bytes(4));
$host->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

try {
    $db = new PDO('mysql:host=127.0.0.1;port=13318;dbname=' . $schema . ';charset=utf8mb4',
        'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // Execute only the CREATE TABLE statements from the actual migration and
    // existing upgrade log schema; the test must exercise the release DDL.
    foreach ([
        __DIR__ . '/../../../后端代码/database/upgrades/0000-升级登记表初始化.sql',
        __DIR__ . '/../../../后端代码/database/upgrades/2026-10-13-008渼约历史服务读取/02-正式升级.sql',
    ] as $file) {
        $sql = preg_replace('/^--.*$/m', '', (string)file_get_contents($file));
        foreach (explode(';', (string)$sql) as $statement) {
            if (trim($statement) !== '') $db->exec($statement);
        }
    }

    $rows = [[
        'source_work_id' => 51080, 'source_member_id' => 421, 'member_id' => 421,
        'source_store_id' => 46, 'store_name_snapshot' => '苏州东环路店',
        'project_name_snapshot' => '面部卡（单项）、王牌 · 胶原瓷光肌',
        'source_card_name_snapshot' => '', 'quantity' => 2,
        'craftsman_name_snapshot' => '', 'business_date' => '2026-04-21',
        'completed_at' => 1776754404,
    ], [
        'source_work_id' => 51081, 'source_member_id' => 422, 'member_id' => 422,
        'source_store_id' => 47, 'store_name_snapshot' => '另一门店',
        'project_name_snapshot' => '护理项目', 'source_card_name_snapshot' => '护理卡',
        'quantity' => 1, 'craftsman_name_snapshot' => '测试手艺人',
        'business_date' => '2026-04-22', 'completed_at' => 1776840804,
    ]];
    $digest = hash_init('sha256');
    foreach ($rows as &$row) {
        $canonical = serviceHistoryCanonical($row);
        $row['source_row_sha256'] = hash('sha256', $canonical);
        hash_update($digest, $canonical . "\n");
    }
    unset($row);
    $sourceHash = hash_final($digest);
    putenv('MOHE_RELEASE_COMMIT=' . str_repeat('a', 40));
    serviceHistoryApply($db, $rows, $sourceHash, 1791896546);
    $verified = serviceHistoryVerify($db);
    if ($verified['rows'] !== 2 || $verified['source_sha256'] !== $sourceHash
        || $verified['popo_work_51080'] !== 1) {
        throw new RuntimeException('Imported source and target digests differ');
    }
    try {
        serviceHistoryApply($db, $rows, $sourceHash, 1791896546);
        throw new RuntimeException('Duplicate import was accepted');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'already imported')) throw $error;
    }
    $db->beginTransaction();
    try {
        $db->exec("UPDATE " . SERVICE_TABLE . " SET quantity=3 WHERE source_work_id=51080");
        try {
            serviceHistoryVerify($db);
            throw new RuntimeException('Tampered row passed verification');
        } catch (RuntimeException $error) {
            if (!str_contains($error->getMessage(), 'fingerprint differs')) throw $error;
        }
    } finally {
        $db->rollBack();
    }
    serviceHistoryVerify($db);
    echo "service-history-import-mysql PASS\n";
} finally {
    $host->exec('DROP DATABASE `' . $schema . '`');
}
