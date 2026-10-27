<?php
declare(strict_types=1);

/**
 * Import one frozen 007 catalog graph into the 010 test instance. This script
 * never connects to 007 and never updates an existing 010 catalog row.
 */
use think\facade\Db;

const SNAPSHOT_SHA256 = '34afae32fd05e61286b9a61be9bc41a829f353fc296f78541363e5ad770dd706';
const UPGRADE_KEY = '20261027-010-007-product-catalog-34afae32fd05';
const TARGET_ROOT = '/www/wwwroot/010.cc3798.com';
const TARGET_SNAPSHOT = '/www/backups/010/20261027-product-catalog-007-34afae32/candidate/007-product-snapshot.json';
const IMPORT_COUNTS = [
    'store_product' => 96,
    'store_product_attr' => 96,
    'store_product_attr_result' => 96,
    'store_product_attr_value' => 96,
    'store_product_description' => 96,
    'store_product_relation' => 132,
    'store_card_related' => 36,
    'store_product_reservation_time' => 720,
];

/** Check the entire fixed source graph before opening any target connection. */
function loadSnapshot(string $path): array
{
    if (!is_file($path) || hash_file('sha256', $path) !== SNAPSHOT_SHA256) {
        throw new RuntimeException('Frozen source snapshot SHA-256 mismatch');
    }
    $snapshot = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot) || ($snapshot['format'] ?? '') !== '010-product-catalog-increment-v1' ||
        ($snapshot['source']['site'] ?? '') !== '007.cc3798.com' ||
        ($snapshot['source']['database'] ?? '') !== 'linlin' ||
        ($snapshot['expected_target']['site'] ?? '') !== '010.cc3798.com' ||
        ($snapshot['expected_target']['database'] ?? '') !== 'mohe_010_app' ||
        (int) ($snapshot['source_max_product_id'] ?? 0) !== 9307 ||
        ($snapshot['counts'] ?? null) !== IMPORT_COUNTS ||
        array_keys($snapshot['rows'] ?? []) !== array_keys(IMPORT_COUNTS)) {
        throw new RuntimeException('Frozen source identity, counts or table set mismatch');
    }
    $newIds = range(9212, 9307);
    $idSet = array_fill_keys($newIds, true);
    $parents = [];
    $skuByProduct = [];
    $skuValues = [];
    foreach (IMPORT_COUNTS as $table => $expected) {
        $rows = $snapshot['rows'][$table];
        if (!is_array($rows) || count($rows) !== $expected) {
            throw new RuntimeException("Unexpected {$table} row count");
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException("Malformed {$table} row");
            $foreign = $table === 'store_product' ? 'id' :
                ($table === 'store_card_related' ? 'card_product_id' : 'product_id');
            if (!isset($idSet[(int) ($row[$foreign] ?? 0)])) {
                throw new RuntimeException("{$table} has a row outside this product batch");
            }
            $key = $table === 'store_product_description'
                ? (string) $row['product_id'] . ':' . (string) $row['type']
                : (string) ($row['id'] ?? '');
            if ($key === '' || isset($seen[$key])) throw new RuntimeException("Duplicate {$table} identity");
            $seen[$key] = true;
            if ($table === 'store_product' && (int) $row['type'] === 0) $parents[(int) $row['id']] = true;
            if ($table === 'store_product_attr_value') {
                if (isset($skuByProduct[(int) $row['product_id']])) {
                    throw new RuntimeException('A new product has more than one SKU in this fixed graph');
                }
                $skuByProduct[(int) $row['product_id']] = (string) $row['unique'];
                if ($row['unique'] === '' || isset($skuValues[(string) $row['unique']])) {
                    throw new RuntimeException('New SKU identities are blank or duplicated');
                }
                $skuValues[(string) $row['unique']] = true;
            }
        }
    }
    if (array_map('intval', array_column($snapshot['rows']['store_product'], 'id')) !== $newIds ||
        count($parents) !== 8 || count($skuByProduct) !== 96) {
        throw new RuntimeException('The frozen platform/store product graph changed');
    }
    foreach ($snapshot['rows']['store_product'] as $row) {
        if ((int) $row['type'] === 0) {
            if ((int) $row['pid'] !== 0 || (int) $row['relation_id'] !== 0) {
                throw new RuntimeException('Platform product identity is invalid');
            }
        } elseif ((int) $row['type'] !== 1 || !isset($parents[(int) $row['pid']])) {
            throw new RuntimeException('Store product has no imported platform parent');
        }
    }
    foreach ($snapshot['rows']['store_product_reservation_time'] as $row) {
        if ($skuByProduct[(int) $row['product_id']] !== (string) $row['sku_unique']) {
            throw new RuntimeException('Reservation time references another SKU');
        }
    }
    return $snapshot;
}

/** Ensure the target still has an empty identity range and every external reference resolves. */
function inspectTarget(array $snapshot): array
{
    $identity = Db::query('SELECT DATABASE() AS db, @@version AS mysql_version')[0];
    if (($identity['db'] ?? '') !== 'mohe_010_app') {
        throw new RuntimeException('010 target database identity mismatch');
    }
    $physical = array_map(static function (string $table): string { return 'eb_' . $table; },
        array_merge(array_keys(IMPORT_COUNTS), ['database_upgrade_log']));
    $engines = Db::query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' .
        implode(',', array_fill(0, count($physical), '?')) . ')', $physical);
    if (count($engines) !== count($physical)) throw new RuntimeException('010 target table missing');
    foreach ($engines as $table) {
        if (strtoupper((string) $table['ENGINE']) !== 'INNODB') {
            throw new RuntimeException('010 import requires transactional InnoDB tables');
        }
    }
    $marker = Db::name('database_upgrade_log')->where('upgrade_key', UPGRADE_KEY)->find();
    if ($marker) {
        if ((string) $marker['sql_checksum'] !== SNAPSHOT_SHA256) {
            throw new RuntimeException('Import marker has a different source checksum');
        }
        foreach (IMPORT_COUNTS as $table => $expected) {
            $rows = $snapshot['rows'][$table];
            $present = $table === 'store_product_description'
                ? Db::name($table)->whereBetween('product_id', [9212, 9307])->count()
                : Db::name($table)->whereIn('id', array_column($rows, 'id'))->count();
            if ($present !== $expected) throw new RuntimeException("Applied {$table} graph is incomplete");
        }
        return ['status' => 'already_applied', 'mysql_version' => $identity['mysql_version']];
    }
    foreach (IMPORT_COUNTS as $table => $count) {
        $rows = $snapshot['rows'][$table];
        if ($table === 'store_product_description') {
            $present = Db::name($table)->whereBetween('product_id', [9212, 9307])->count();
        } else {
            $present = Db::name($table)->whereIn('id', array_column($rows, 'id'))->count();
        }
        if ($present !== 0) throw new RuntimeException("010 {$table} identity collision or partial import");
        $columnRows = Db::query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['eb_' . $table]);
        $targetColumns = array_fill_keys(array_column($columnRows, 'COLUMN_NAME'), true);
        foreach (array_keys($rows[0]) as $field) {
            if (!isset($targetColumns[$field])) throw new RuntimeException("010 {$table}.{$field} column missing");
        }
    }
    $newSkuValues = array_column($snapshot['rows']['store_product_attr_value'], 'unique');
    if (Db::name('store_product_attr_value')->whereIn('unique', $newSkuValues)->count() !== 0) {
        throw new RuntimeException('010 already uses a frozen 007 SKU identity');
    }
    $categories = $snapshot['references']['categories'];
    foreach ($categories as $source) {
        $target = Db::name('store_product_category')->where('id', (int) $source['id'])
            ->field('id,pid,type,relation_id,cate_name,is_show')->find();
        if (!$target || array_map('strval', $target) !== array_map('strval', $source)) {
            throw new RuntimeException('010 category mapping differs from 007');
        }
    }
    foreach ($snapshot['references']['stores'] as $source) {
        $target = Db::name('system_store')->where('id', (int) $source['id'])->field('id,name')->find();
        if (!$target || (string) $target['name'] !== (string) $source['name']) {
            throw new RuntimeException('010 store identity mapping differs from 007');
        }
    }
    $skuPairs = [];
    foreach ($snapshot['references']['card_dependency_skus'] as $sku) {
        $skuPairs[(int) $sku['product_id'] . ':' . (string) $sku['unique']] = true;
    }
    foreach ($snapshot['rows']['store_card_related'] as $row) {
        $key = (int) $row['product_id'] . ':' . (string) $row['product_attr_unique'];
        if (!isset($skuPairs[$key]) || !Db::name('store_product_attr_value')
            ->where('product_id', (int) $row['product_id'])
            ->where('unique', (string) $row['product_attr_unique'])->find()) {
            throw new RuntimeException('010 combination card dependency SKU is missing');
        }
    }
    return ['status' => 'ready', 'mysql_version' => $identity['mysql_version']];
}

if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI required');
$args = $argv;
array_shift($args);
$mode = $args[0] ?? '';
$snapshotPath = $args[1] ?? '';
if ($mode === '--validate-snapshot') {
    loadSnapshot($snapshotPath);
    echo "PASS frozen snapshot validated\n";
    exit(0);
}
if (!in_array($mode, ['--dry-run', '--apply'], true) || $snapshotPath !== TARGET_SNAPSHOT ||
    getenv('TARGET_SITE_ROOT') !== TARGET_ROOT) {
    throw new RuntimeException('Expected --dry-run/--apply, exact private snapshot path and 010 site root');
}
$codeVersion = $args[2] ?? '';
if ($mode === '--apply' && !preg_match('/^[0-9a-f]{40}$/', $codeVersion)) {
    throw new RuntimeException('Approved committed source hash required for apply');
}
$snapshot = loadSnapshot($snapshotPath);
require TARGET_ROOT . '/vendor/autoload.php';
$app = new \think\App(TARGET_ROOT . '/');
$app->env->load(TARGET_ROOT . '/.env');
$app->initialize();
if ($mode === '--dry-run') {
    Db::execute('SET TRANSACTION READ ONLY');
    Db::startTrans();
    try {
        $state = inspectTarget($snapshot);
        echo 'PASS 010 catalog dry-run ' . json_encode($state, JSON_UNESCAPED_UNICODE) . "\n";
    } finally {
        Db::rollback();
    }
    exit(0);
}

// A single transaction makes the catalog graph and its immutable batch marker atomic.
Db::startTrans();
try {
    $state = inspectTarget($snapshot);
    if ($state['status'] === 'already_applied') {
        Db::rollback();
        echo "PASS already_applied; no catalog rows changed\n";
        exit(0);
    }
    foreach (IMPORT_COUNTS as $table => $expected) {
        $inserted = 0;
        foreach (array_chunk($snapshot['rows'][$table], 40) as $chunk) {
            $inserted += (int) Db::name($table)->insertAll($chunk);
        }
        if ($inserted !== $expected) throw new RuntimeException("{$table} insert count mismatch");
    }
    Db::name('database_upgrade_log')->insert([
        'upgrade_key' => UPGRADE_KEY,
        'title' => '010 007 商品资料固定增量',
        'task_file' => '后端代码/database/upgrades/2026-10-27-渼约010商品增量导入/01-商品资料固定增量导入.php',
        'sql_checksum' => SNAPSHOT_SHA256,
        'code_version' => $codeVersion,
        'executed_at' => date('Y-m-d H:i:s'),
        'executed_by' => 'Codex',
        'result_note' => '96 products; 1272 catalog dependencies; 007 read-only snapshot',
    ]);
    foreach (IMPORT_COUNTS as $table => $expected) {
        $field = $table === 'store_product_description' ? 'product_id' : 'id';
        $query = Db::name($table);
        $actual = $table === 'store_product_description'
            ? $query->whereBetween($field, [9212, 9307])->count()
            : $query->whereIn($field, array_column($snapshot['rows'][$table], $field))->count();
        if ($actual !== $expected) throw new RuntimeException("{$table} post-insert count mismatch");
    }
    Db::commit();
    echo 'PASS 010 catalog imported ' . json_encode(IMPORT_COUNTS, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}
