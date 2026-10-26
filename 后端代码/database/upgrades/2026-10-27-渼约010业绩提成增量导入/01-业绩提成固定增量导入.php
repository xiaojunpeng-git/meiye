<?php
declare(strict_types=1);

/**
 * Add only the five 007 commission settings visible on the project page to 010.
 * Source row 224 points to product 0 and is absent from the source page; keep it
 * in the frozen evidence, but do not create an invalid 010 commission setting.
 */
use think\facade\Db;

const SOURCE_SHA256 = '936d2827471a64fe194f0bc3a72ac921844694eca7f4473d088905c6fb81083b';
const UPGRADE_KEY = '20261027-010-007-yeji-commission-936d2827471a';
const TARGET_ROOT = '/www/wwwroot/010.cc3798.com';
const TARGET_SNAPSHOT = '/www/backups/010/20261027-yeji-commission-007/candidate/007-yeji-commission-snapshot.json';
const SOURCE_ONLY_IDS = [222, 223, 224, 225, 226, 227];
const VISIBLE_IDS = [222, 223, 225, 226, 227];

/** Compare SQL scalar values while preserving NULL and the exact JSON text. */
function sameRow(array $left, array $right): bool
{
    if (array_keys($left) !== array_keys($right)) return false;
    foreach ($left as $key => $value) {
        $other = $right[$key];
        if (($value === null) !== ($other === null)) return false;
        if ($value !== null && (string) $value !== (string) $other) return false;
    }
    return true;
}

/** Validate the complete frozen source, including the orphan deliberately excluded. */
function loadSnapshot(string $path): array
{
    if (!is_file($path) || hash_file('sha256', $path) !== SOURCE_SHA256) {
        throw new RuntimeException('Frozen 007 commission snapshot SHA-256 mismatch');
    }
    $snapshot = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot) || ($snapshot['format'] ?? '') !== '010-yeji-commission-increment-v1' ||
        ($snapshot['source']['site'] ?? '') !== '007.cc3798.com' ||
        ($snapshot['source']['database'] ?? '') !== 'linlin' ||
        ($snapshot['expected_target']['site'] ?? '') !== '010.cc3798.com' ||
        ($snapshot['expected_target']['database'] ?? '') !== 'mohe_010_app' ||
        ($snapshot['visible_new_ids'] ?? null) !== VISIBLE_IDS ||
        ($snapshot['excluded_orphan_id'] ?? null) !== 224) {
        throw new RuntimeException('Frozen 007 commission source identity or scope changed');
    }
    $rows = $snapshot['source_rows'] ?? [];
    $byId = array_column($rows, null, 'id');
    if (count($rows) !== 140 || count($byId) !== 140 ||
        array_keys(array_intersect_key($byId, array_flip(SOURCE_ONLY_IDS))) !== SOURCE_ONLY_IDS ||
        (int) $byId[224]['product_id'] !== 0 || count($snapshot['source_ranges'] ?? []) !== 5 ||
        count($snapshot['visible_products'] ?? []) !== 5) {
        throw new RuntimeException('Frozen commission row set is invalid');
    }
    $rangeLabels = array_map(static function (array $range): string {
        return $range['yeji_min'] . '-' . $range['yeji_max'];
    }, $snapshot['source_ranges']);
    $productIds = array_map('intval', array_column($snapshot['visible_products'], 'id'));
    foreach (VISIBLE_IDS as $id) {
        $row = $byId[$id];
        if (!in_array((int) $row['product_id'], $productIds, true) || (int) $row['yeji'] <= 0) {
            throw new RuntimeException("Source commission {$id} has an invalid project or amount");
        }
        $tiers = json_decode((string) $row['commission_json'], true, 512, JSON_THROW_ON_ERROR);
        if (array_keys($tiers) !== $rangeLabels) {
            throw new RuntimeException("Source commission {$id} tier names differ from the source range table");
        }
        foreach ($tiers as $rate) {
            if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
                throw new RuntimeException("Source commission {$id} has an invalid tier rate");
            }
        }
    }
    return $snapshot;
}

/** Reject changed 010 baselines and duplicate product settings before any insert. */
function inspectTarget(array $snapshot): array
{
    $identity = Db::query('SELECT DATABASE() AS db, @@version AS mysql_version')[0];
    if (($identity['db'] ?? '') !== 'mohe_010_app') {
        throw new RuntimeException('010 target database identity mismatch');
    }
    $tables = ['eb_yeji_commission', 'eb_yeji_range', 'eb_store_product', 'eb_database_upgrade_log'];
    $engines = Db::query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' .
        implode(',', array_fill(0, count($tables), '?')) . ')', $tables);
    if (count($engines) !== count($tables)) throw new RuntimeException('010 commission table missing');
    foreach ($engines as $table) {
        if (strtoupper((string) $table['ENGINE']) !== 'INNODB') {
            throw new RuntimeException('010 commission import requires transactional InnoDB tables');
        }
    }
    $source = array_column($snapshot['source_rows'], null, 'id');
    $marker = Db::name('database_upgrade_log')->where('upgrade_key', UPGRADE_KEY)->find();
    if ($marker) {
        if ((string) $marker['sql_checksum'] !== SOURCE_SHA256) {
            throw new RuntimeException('Existing commission marker has another source checksum');
        }
        $present = Db::name('yeji_commission')->whereIn('id', VISIBLE_IDS)->count();
        if ((int) $present !== count(VISIBLE_IDS)) {
            throw new RuntimeException('Applied commission batch is incomplete');
        }
        return ['status' => 'already_applied', 'mysql_version' => $identity['mysql_version']];
    }
    $targetRows = Db::name('yeji_commission')->order('id')->select()->toArray();
    $target = array_column($targetRows, null, 'id');
    if (count($targetRows) !== 134 || count($target) !== 134 ||
        array_values(array_diff(array_keys($source), array_keys($target))) !== SOURCE_ONLY_IDS ||
        array_diff(array_keys($target), array_keys($source))) {
        throw new RuntimeException('010 commission identity diff changed from the fixed candidate');
    }
    foreach ($target as $id => $row) {
        if (!sameRow($source[$id], $row)) {
            throw new RuntimeException("010 existing commission {$id} differs from 007; no overwrite is allowed");
        }
    }
    $targetRanges = Db::name('yeji_range')->order('id')->select()->toArray();
    if (count($targetRanges) !== 5) throw new RuntimeException('010 commission tier count changed');
    foreach ($targetRanges as $index => $range) {
        if (!sameRow($snapshot['source_ranges'][$index], $range)) {
            throw new RuntimeException('010 commission tier definition differs from 007');
        }
    }
    $products = Db::name('store_product')
        ->whereIn('id', array_column($snapshot['visible_products'], 'id'))
        ->field('id,store_name,pid,type,product_type,is_del')->order('id')->select()->toArray();
    if (count($products) !== 5) throw new RuntimeException('010 commission product missing');
    foreach ($products as $index => $product) {
        if (!sameRow($snapshot['visible_products'][$index], $product)) {
            throw new RuntimeException('010 commission product differs from frozen 007 reference');
        }
    }
    $duplicated = Db::name('yeji_commission')
        ->whereIn('product_id', array_column($snapshot['visible_products'], 'id'))->count();
    if ((int) $duplicated !== 0) {
        throw new RuntimeException('010 already has a commission for an imported project');
    }
    return ['status' => 'ready', 'mysql_version' => $identity['mysql_version'],
        'source_rows' => 140, 'target_rows' => 134, 'import_rows' => 5, 'excluded_orphan_id' => 224];
}

$args = array_slice($argv, 1);
$mode = $args[0] ?? '';
$path = $args[1] ?? '';
if ($mode === '--validate-snapshot') {
    loadSnapshot($path);
    echo "PASS frozen 007 commission snapshot validated\n";
    exit(0);
}
if (!in_array($mode, ['--dry-run', '--apply'], true) || $path !== TARGET_SNAPSHOT ||
    getenv('TARGET_SITE_ROOT') !== TARGET_ROOT) {
    throw new RuntimeException('Expected --dry-run/--apply, exact private snapshot path and 010 site root');
}
$codeVersion = $args[2] ?? '';
if ($mode === '--apply' && !preg_match('/^[0-9a-f]{40}$/', $codeVersion)) {
    throw new RuntimeException('Approved committed source hash required for apply');
}
$snapshot = loadSnapshot($path);
require TARGET_ROOT . '/vendor/autoload.php';
$app = new \think\App(TARGET_ROOT . '/');
$app->env->load(TARGET_ROOT . '/.env');
$app->initialize();

if ($mode === '--dry-run') {
    Db::execute('SET TRANSACTION READ ONLY');
    Db::startTrans();
    try {
        echo 'PASS 010 commission dry-run ' . json_encode(inspectTarget($snapshot), JSON_UNESCAPED_UNICODE) . "\n";
    } finally {
        Db::rollback();
    }
    exit(0);
}

// One target transaction protects all five settings and their immutable source marker.
Db::startTrans();
try {
    $state = inspectTarget($snapshot);
    if ($state['status'] === 'already_applied') {
        Db::rollback();
        echo "PASS already_applied; no commission rows changed\n";
        exit(0);
    }
    $source = array_column($snapshot['source_rows'], null, 'id');
    $rows = array_map(static function (int $id) use ($source): array { return $source[$id]; }, VISIBLE_IDS);
    $inserted = (int) Db::name('yeji_commission')->insertAll($rows);
    if ($inserted !== 5) throw new RuntimeException('Commission insert count mismatch');
    Db::name('database_upgrade_log')->insert([
        'upgrade_key' => UPGRADE_KEY,
        'title' => '010 007 项目业绩提成固定增量',
        'task_file' => '后端代码/database/upgrades/2026-10-27-渼约010业绩提成增量导入/01-业绩提成固定增量导入.php',
        'sql_checksum' => SOURCE_SHA256,
        'code_version' => $codeVersion,
        'executed_at' => date('Y-m-d H:i:s'),
        'executed_by' => 'Codex',
        'result_note' => '5 visible source settings imported; 007 orphan id 224 product_id 0 excluded',
    ]);
    $actual = Db::name('yeji_commission')->whereIn('id', VISIBLE_IDS)->order('id')->select()->toArray();
    if (count($actual) !== 5) throw new RuntimeException('Post-insert commission count mismatch');
    foreach ($actual as $index => $row) {
        if (!sameRow($rows[$index], $row)) {
            throw new RuntimeException('Post-insert commission value mismatch');
        }
    }
    if ((int) Db::name('yeji_commission')->count() !== 139 ||
        (int) Db::name('yeji_commission')->where('id', 224)->count() !== 0) {
        throw new RuntimeException('010 commission total or orphan exclusion mismatch');
    }
    Db::commit();
    echo "PASS 010 commission imported 5 visible settings; source orphan 224 excluded\n";
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}
