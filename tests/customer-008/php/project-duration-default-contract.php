<?php
/**
 * Protect the 008 duration migration's per-column defaulting rule.
 *
 * The fixtures reproduce the reviewed zero distribution while keeping a
 * nonstandard positive project/add-on duration that must never be overwritten.
 */
declare(strict_types=1);

require dirname(__DIR__, 3)
    . '/后端代码/database/upgrades/2026-10-13-008项目时长默认值补齐/01-执行.php';

function m008DefaultAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rows = [];
for ($id = 1; $id <= 1373; ++$id) {
    $project = $id <= 982 ? '0' : ($id <= 1005 ? '45' : '75');
    $addon = $id <= 1005 ? '0' : '20';
    $rows[] = [
        'id' => (string)$id, 'pid' => '0', 'type' => '0',
        'relation_id' => '0', 'store_name' => '项目' . $id,
        'is_show' => '1', 'is_del' => '0',
        'project_service_duration' => $project,
        'addon_service_duration' => $addon,
    ];
}
[$after, $changes, $counts] = m008DefaultDurationPlan($rows);
m008DefaultAssert(count($changes) === 1005 && $counts['project_filled'] === 982
    && $counts['addon_filled'] === 1005, 'Unexpected per-column update count');
m008DefaultAssert($after[0]['project_service_duration'] === '60'
    && $after[0]['addon_service_duration'] === '30', 'Both missing minutes must be filled');
m008DefaultAssert($after[982]['project_service_duration'] === '45'
    && $after[982]['addon_service_duration'] === '30', 'Positive project minutes must be preserved');
m008DefaultAssert($after[1005]['project_service_duration'] === '75'
    && $after[1005]['addon_service_duration'] === '20', 'Both positive values must be preserved');
m008DefaultAssert($rows[0]['project_service_duration'] === '0',
    'Planning must not mutate the source snapshot');

// Exercise the guarded SQL in a rollback transaction; a stale value must stop
// the whole batch instead of leaving a partly updated catalogue.
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE eb_store_product (id INTEGER PRIMARY KEY,product_type INTEGER,'
    . 'is_del INTEGER,project_service_duration INTEGER,addon_service_duration INTEGER)');
$insert = $db->prepare('INSERT INTO eb_store_product VALUES (?,?,?,?,?)');
foreach ($rows as $row) {
    $insert->execute([(int)$row['id'], 6, 0,
        (int)$row['project_service_duration'], (int)$row['addon_service_duration']]);
}
$db->beginTransaction();
m008DefaultDurationApplyChanges($db, $changes);
$filled = (int)$db->query('SELECT COUNT(*) FROM eb_store_product '
    . 'WHERE project_service_duration=60 AND addon_service_duration=30')->fetchColumn();
m008DefaultAssert($filled === 982, 'Guarded SQL did not fill all both-zero projects');
$db->rollBack();
m008DefaultAssert((int)$db->query('SELECT project_service_duration FROM eb_store_product '
    . 'WHERE id=1')->fetchColumn() === 0, 'The rehearsal did not roll back');
$db->beginTransaction();
$db->exec('UPDATE eb_store_product SET project_service_duration=10 WHERE id=1');
try {
    m008DefaultDurationApplyChanges($db, $changes);
    throw new RuntimeException('Stale project minutes were accepted');
} catch (RuntimeException $error) {
    m008DefaultAssert(strpos($error->getMessage(), 'changed during import') !== false,
        'Unexpected stale-row failure');
}
$db->rollBack();

$invalid = $rows;
$invalid[0]['project_service_duration'] = '-1';
try {
    m008DefaultDurationPlan($invalid);
    throw new RuntimeException('Negative minutes were accepted');
} catch (RuntimeException $error) {
    m008DefaultAssert(strpos($error->getMessage(), 'outside editor range') !== false,
        'Unexpected negative-minute failure');
}

echo json_encode(['ok' => true, 'scope' => count($rows),
    'changed_rows' => count($changes), 'positive_minutes_preserved' => true,
    'guarded_sql_and_rollback' => true],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
