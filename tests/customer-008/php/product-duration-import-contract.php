<?php
/**
 * Contract for the 008 duration-only data migration. Synthetic rows exercise
 * the exact reviewed root/child count without opening either live database.
 */
declare(strict_types=1);

require __DIR__ . '/../../../后端代码/database/upgrades/2026-10-13-008渼约商品服务时长补导/01-执行.php';

$plan = m008DurationPlan(__DIR__ . '/../../../scripts/import/customer_008_morestore_catalog_plan.json');
$oldStore = (int)array_key_first($plan['store_map']);
$newStore = (int)$plan['store_map'][(string)$oldStore];
$source = [];
foreach ($plan['roots'] as $root) {
    $source[] = [
        'id' => (string)$root['old_id'], 'pid' => '0', 'type' => '0',
        'relation_id' => '0', 'store_name' => '根' . $root['old_id'],
        'work_time' => (int)$root['old_id'] === 697 ? '50' : '0',
        'add_work_time' => (int)$root['old_id'] === 697 ? '30' : '0',
    ];
}
for ($n = 0; $n < 3113; $n++) {
    $source[] = [
        'id' => (string)(100000 + $n), 'pid' => (string)$plan['roots'][$n % 160]['old_id'],
        'type' => '1', 'relation_id' => (string)$oldStore,
        'store_name' => '门店副本' . $n,
        'work_time' => $n === 0 ? '45' : '0',
        'add_work_time' => $n === 0 ? '20' : '0',
    ];
}
$expected = m008DurationExpected($plan, $source);
if (count($expected) !== 3273 || $expected[697]['project'] !== 50
    || $expected[697]['addon'] !== 30 || $expected[100000]['project'] !== 45
    || $expected[100000]['addon'] !== 20 || $expected[100000]['store'] !== $newStore) {
    throw new RuntimeException('Root/child duration mapping or store scope failed');
}
$before = array_fill_keys(array_keys($expected), [0, 0]);
$counts = m008DurationCounts($expected, $before);
if ($counts['scoped'] !== 3273 || $counts['changed_rows'] !== 2 || $counts['mismatch'] !== 2) {
    throw new RuntimeException('Duration change count failed');
}
$after = [];
foreach ($expected as $id => $row) {
    $after[$id] = [$row['project'], $row['addon']];
}
if (m008DurationCounts($expected, $after)['mismatch'] !== 0) {
    throw new RuntimeException('Duration verification count failed');
}
foreach (['-1', '10000', '30.5'] as $invalid) {
    try {
        m008DurationMinutes($invalid);
        throw new LogicException('Invalid source minute accepted');
    } catch (RuntimeException $expectedError) {
        // A rejected value protects the target editor's 0..9999 minute range.
    }
}
$source[160]['relation_id'] = '999999';
try {
    m008DurationExpected($plan, $source);
    throw new LogicException('An unreviewed old store was accepted');
} catch (RuntimeException $expectedError) {
    // Old closed/unmapped store children must never enter the target scope.
}
echo json_encode(['ok' => true, 'source_rows' => count($source), 'changed_fixture_rows' => 2], JSON_THROW_ON_ERROR), PHP_EOL;
