<?php
declare(strict_types=1);

/**
 * Read-only source snapshot for the 008 morestore member-rights migration.
 *
 * The old H5 "可使用" list, rather than every positive cart counter, defines
 * the import scope. This command never opens the target database and never
 * writes to the source. Its digest freezes every selected source row, including
 * the immutable purchase snapshot used to display its historical name.
 */

const RIGHTS_SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const RIGHTS_MAPPING_FILE = __DIR__ . '/../../../任务管理/008-独立正式站部署/2026-10-13-渼约商品导入实际映射.json';
const RIGHTS_CATALOG_PLAN_FILE = __DIR__ . '/customer_008_morestore_catalog_plan.json';

/** Accept only explicit file-path overrides; no credentials enter output. */
function rightsPrepareOption(array $args, string $name, string $default): string
{
    $prefix = '--' . $name . '=';
    foreach ($args as $arg) {
        if (strpos($arg, $prefix) === 0) {
            $value = substr($arg, strlen($prefix));
            if ($value === '') {
                throw new RuntimeException('Empty option: ' . $name);
            }
            return $value;
        }
    }
    return $default;
}

/** Source credentials stay in process memory, and database identity is checked. */
function rightsPrepareSource(string $path): PDO
{
    // Piped stdin keeps source credentials out of local files during a
    // read-only SSH tunnel rehearsal; normal server execution uses .env.
    $env = $path === '-' ? parse_ini_string((string)stream_get_contents(STDIN))
        : parse_ini_file($path);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== 'morestore'
        || (int)($env['HOSTPORT'] ?? 0) !== 3306) {
        throw new RuntimeException('Source environment is not morestore:3306');
    }
    $db = new PDO(
        'mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=morestore;charset=utf8mb4',
        $env['USERNAME'],
        $env['PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $identity = $db->query('SELECT DATABASE() database_name,@@port port')->fetch();
    if (($identity['database_name'] ?? '') !== 'morestore'
        || (int)($identity['port'] ?? 0) !== 3306) {
        throw new RuntimeException('Connected source database identity changed');
    }
    return $db;
}

/** The reviewed catalogue map is data, and old product IDs must be unique. */
function rightsPrepareMapping(string $mappingFile, string $planFile): array
{
    $map = json_decode((string)file_get_contents($mappingFile), true, 512, JSON_THROW_ON_ERROR);
    $plan = json_decode((string)file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);
    if (($map['target_database'] ?? '') !== 'mohe_008'
        || ($plan['source_database'] ?? '') !== 'morestore'
        || ($plan['target_database'] ?? '') !== 'mohe_008') {
        throw new RuntimeException('Catalogue mapping identity changed');
    }
    $products = [];
    foreach ($map['product_mapping'] ?? [] as $row) {
        $id = (int)($row['old_product_id'] ?? 0);
        if ($id <= 0 || isset($products[$id])) {
            throw new RuntimeException('Duplicate or invalid old product ID in mapping');
        }
        $products[$id] = $row;
    }
    if (count($products) !== 3273 || count($plan['store_map'] ?? []) !== 22) {
        throw new RuntimeException('Reviewed catalogue mapping coverage changed');
    }
    return [$products, array_fill_keys(array_map('intval', array_keys($plan['store_map'])), true)];
}

/** Match the old order_work/work_list?type=0 order filters and positive UI rows. */
function rightsPrepareRows(PDO $source): PDOStatement
{
    return $source->query(
        'SELECT o.id source_order_id,o.order_id source_order_no,o.uid source_uid,'
        . 'o.store_id source_store_id,o.add_time source_order_time,o.pay_time source_pay_time,'
        . 'o.total_price source_total_price,o.pay_price source_pay_price,o.status source_status,'
        . 'o.refund_status source_refund_status,c.id source_detail_id,c.cart_id source_cart_id,'
        . 'c.product_id source_product_id,c.sku_unique source_sku_unique,'
        . 'c.write_times source_total_times,c.write_surplus_times source_remaining_times,'
        . 'c.write_start source_write_start,c.write_end source_write_end,'
        . 'c.cart_info source_cart_info,p.store_name current_product_name '
        . 'FROM eb_store_order o '
        . 'JOIN eb_store_order_cart_info c ON c.oid=o.id '
        . 'LEFT JOIN eb_store_product p ON p.id=c.product_id '
        . 'WHERE o.paid=1 AND o.status IN (0,1,5) '
        . 'AND o.refund_status IN (0,3) AND o.shipping_type=2 '
        . 'AND o.product_type=4 AND o.is_del=0 AND o.is_system_del=0 '
        . 'AND c.write_surplus_times>0 ORDER BY c.id'
    );
}

/** Old cart_info captures the purchase-time label; current product may differ. */
function rightsPrepareName(array $row): string
{
    $snapshot = json_decode((string)$row['source_cart_info'], true);
    $name = is_array($snapshot) ? trim((string)($snapshot['productInfo']['store_name'] ?? '')) : '';
    return $name !== '' ? $name : trim((string)($row['current_product_name'] ?? ''));
}

/** Reproducible digest and exception IDs support a later exact-source guard. */
function rightsPrepareSummary(PDO $source, array $products, array $stores, string $snapshotFile = ''): array
{
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->beginTransaction();
    try {
        $snapshotAt = (int)$source->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        $context = hash_init('sha256');
        $result = [
            'source_database' => 'morestore',
            'target_database' => 'mohe_008',
            'source_snapshot_at' => $snapshotAt,
            'line_count' => 0,
            'member_count' => 0,
            'remaining_times' => 0,
            'kind_counts' => ['card' => 0, 'project' => 0, 'shell' => 0, 'unmapped' => 0],
            'unmapped_store_count' => 0,
            'unmapped_store_remaining_times' => 0,
            'expired_ui_count' => 0,
            'expired_ui_detail_ids' => [],
            'invalid_counter_detail_ids' => [],
            'missing_name_detail_ids' => [],
            'sample_uid_421' => ['line_count' => 0, 'remaining_times' => 0, 'detail_ids' => []],
        ];
        $members = [];
        $snapshot = [];
        foreach (rightsPrepareRows($source) as $row) {
            $id = (int)$row['source_detail_id'];
            $uid = (int)$row['source_uid'];
            $remaining = (int)$row['source_remaining_times'];
            $total = (int)$row['source_total_times'];
            $kind = (string)($products[(int)$row['source_product_id']]['kind'] ?? 'unmapped');
            if (!isset($result['kind_counts'][$kind])) {
                throw new RuntimeException('Unexpected mapped product kind for detail ' . $id);
            }
            $name = rightsPrepareName($row);
            if ($name === '') {
                $result['missing_name_detail_ids'][] = $id;
            }
            if ($total <= 0 || $remaining <= 0 || $remaining > $total) {
                $result['invalid_counter_detail_ids'][] = $id;
            }
            $targetEnd = (int)$row['source_write_end'];
            if ($targetEnd > 0 && $targetEnd < $snapshotAt) {
                $result['expired_ui_count']++;
                $result['expired_ui_detail_ids'][] = $id;
                // Product manager approved these six old-UI items as redeemable:
                // clear only their target expiry, retaining the old date in the
                // order-line audit snapshot and this frozen source digest.
                $targetEnd = 0;
            }
            $row['purchase_name'] = $name;
            $row['target_write_end'] = $targetEnd;
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            hash_update($context, $line);
            if ($snapshotFile !== '') {
                $snapshot[] = $line;
            }
            $result['line_count']++;
            $result['remaining_times'] += $remaining;
            $result['kind_counts'][$kind]++;
            $members[$uid] = true;
            if (!isset($stores[(int)$row['source_store_id']])) {
                $result['unmapped_store_count']++;
                $result['unmapped_store_remaining_times'] += $remaining;
            }
            if ($uid === 421) {
                $result['sample_uid_421']['line_count']++;
                $result['sample_uid_421']['remaining_times'] += $remaining;
                $result['sample_uid_421']['detail_ids'][] = $id;
            }
        }
        $result['member_count'] = count($members);
        $result['source_sha256'] = hash_final($context);
        if ($snapshotFile !== '') {
            // The live source may change between plan and apply. Freeze exactly
            // this repeatable-read set for idempotent import and later replay.
            if (file_exists($snapshotFile) || is_link($snapshotFile)) {
                throw new RuntimeException('Snapshot path already exists');
            }
            $temp = $snapshotFile . '.tmp.' . bin2hex(random_bytes(8));
            $handle = fopen($temp, 'x');
            if ($handle === false) throw new RuntimeException('Cannot create private snapshot');
            chmod($temp, 0600);
            try {
                foreach ($snapshot as $line) {
                    if (fwrite($handle, $line) !== strlen($line)) {
                        throw new RuntimeException('Incomplete source snapshot write');
                    }
                }
            } finally {
                fclose($handle);
            }
            if (!rename($temp, $snapshotFile)) {
                @unlink($temp);
                throw new RuntimeException('Cannot publish source snapshot');
            }
            if (!hash_equals($result['source_sha256'], (string)hash_file('sha256', $snapshotFile))) {
                throw new RuntimeException('Source snapshot checksum mismatch');
            }
            $result['snapshot_file'] = $snapshotFile;
        }
        $source->commit();
        return $result;
    } catch (Throwable $error) {
        if ($source->inTransaction()) {
            $source->rollBack();
        }
        throw $error;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $sourceEnv = rightsPrepareOption($argv, 'source-env', RIGHTS_SOURCE_ENV);
        $mappingFile = rightsPrepareOption($argv, 'mapping-file', RIGHTS_MAPPING_FILE);
        $planFile = rightsPrepareOption($argv, 'catalog-plan', RIGHTS_CATALOG_PLAN_FILE);
        $snapshotFile = rightsPrepareOption($argv, 'snapshot-file', '');
        [$products, $stores] = rightsPrepareMapping($mappingFile, $planFile);
        $result = rightsPrepareSummary(rightsPrepareSource($sourceEnv), $products, $stores, $snapshotFile);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(2);
    }
}
