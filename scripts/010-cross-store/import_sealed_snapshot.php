<?php
declare(strict_types=1);

/** Import one read-only 007 JSONL snapshot into the 010 report projection only.
 * Usage: php import_sealed_snapshot.php APP_ROOT|- SNAPSHOT_FILE SHA256 BATCH_ID
 * The caller owns a prior 010 backup and has applied the matching table DDL.
 * APP_ROOT=- validates the frozen file without any database connection/write.
 */
if ($argc !== 5) {
    fwrite(STDERR, "Usage: php import_sealed_snapshot.php APP_ROOT SNAPSHOT_FILE SHA256 BATCH_ID\n");
    exit(2);
}
[$program, $appRoot, $sourceFile, $expectedSha, $batchId] = $argv;
if (!preg_match('/^[a-f0-9]{64}$/', $expectedSha) || !preg_match('/^[a-zA-Z0-9-]{8,64}$/', $batchId)) {
    throw new InvalidArgumentException('Snapshot hash or batch ID is invalid');
}
if (!is_file($sourceFile) || !hash_equals($expectedSha, hash_file('sha256', $sourceFile))) {
    throw new RuntimeException('Snapshot file checksum mismatch');
}
/** Decimal money is converted exactly; binary floats cannot enter the archive. */
function cents($value): int {
    $text = (string)$value;
    if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $text, $parts)) {
        throw new RuntimeException('Source amount has invalid precision');
    }
    $whole = (int)$parts[1];
    if ($whole > intdiv(PHP_INT_MAX - 99, 100)) throw new RuntimeException('Source amount overflow');
    return $whole * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
}

/** Compute the same signed store net as 007: receiving store plus, payer minus. */
function addNet(array &$net, int $store, int $type, int $amount): void {
    if (!isset($net[$store])) throw new RuntimeException('Snapshot order references unknown store');
    $net[$store][$type] += $amount;
}

/** A replay must verify every stored projection field, not just a batch label. */
function verifyReadback(array $readback, array $rows, array $sourceStores, array $expectedNet): array {
    if (count($readback) !== count($rows)) throw new RuntimeException('Imported order count differs');
    $actualNet = array_fill_keys(array_keys($sourceStores), [0 => 0, 2 => 0]);
    foreach ($readback as $saved) {
        $id = (int)$saved['source_order_id'];
        $expected = $rows[$id] ?? null;
        if (!$expected) throw new RuntimeException('Unexpected imported order');
        foreach (['store_id','kua_store','order_type','business_date','source_add_time','amount_cents','source_row_sha256'] as $key) {
            if ((string)$saved[$key] !== (string)$expected[$key]) throw new RuntimeException('Imported order differs: ' . $id);
        }
        $type = (int)$saved['order_type'];
        $amount = (int)$saved['amount_cents'];
        addNet($actualNet, (int)$saved['store_id'], $type, $amount);
        addNet($actualNet, (int)$saved['kua_store'], $type, -$amount);
    }
    if ($actualNet !== $expectedNet) throw new RuntimeException('Per-store settlement differs');
    return $actualNet;
}

$file = new SplFileObject($sourceFile, 'r');
$meta = null;
$sourceStores = [];
$rows = [];
$expectedNet = [];
$lineNumber = 0;
while (!$file->eof()) {
    $line = trim((string)$file->fgets());
    if ($line === '') continue;
    ++$lineNumber;
    $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if ($item['kind'] === 'meta') {
        if ($meta !== null || $lineNumber !== 1 || ($item['db'] ?? '') !== 'linlin') throw new RuntimeException('Unexpected source metadata');
        $meta = $item;
        continue;
    }
    if ($item['kind'] === 'store') {
        if ($rows || (int)$item['salary_status'] !== 1) throw new RuntimeException('Store ordering/status invalid');
        $id = (int)$item['id'];
        if ($id < 1 || isset($sourceStores[$id])) throw new RuntimeException('Duplicate source store');
        $sourceStores[$id] = (string)$item['name'];
        $expectedNet[$id] = [0 => 0, 2 => 0];
        continue;
    }
    if ($item['kind'] !== 'order' || !$meta || count($sourceStores) !== 10) throw new RuntimeException('Unexpected snapshot record');
    $id = (int)$item['id'];
    $target = (int)$item['store_id'];
    $origin = (int)$item['kua_store'];
    $type = (int)$item['order_type'];
    $timestamp = (int)$item['add_time'];
    if ($id < 1 || isset($rows[$id]) || !isset($sourceStores[$target], $sourceStores[$origin])
        || !in_array($type, [0, 2], true) || $timestamp < 1) throw new RuntimeException('Invalid source order identity');
    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    if ($date < '2026-01-01' || $date > '2026-10-26' || $timestamp > strtotime($meta['captured_at'] . ' Asia/Shanghai')) {
        throw new RuntimeException('Source order is outside fixed snapshot time');
    }
    $amount = cents($type === 0 ? $item['yue_pay_price'] : $item['pay_price']);
    $rows[$id] = ['batch_id' => $batchId, 'source_order_id' => $id, 'store_id' => $target,
        'kua_store' => $origin, 'order_type' => $type, 'business_date' => $date,
        'source_add_time' => $timestamp, 'amount_cents' => $amount,
        'source_row_sha256' => hash('sha256', $line)];
    addNet($expectedNet, $target, $type, $amount);
    addNet($expectedNet, $origin, $type, -$amount);
}
if (!$meta || count($sourceStores) !== 10 || count($rows) < 1) throw new RuntimeException('Snapshot is incomplete');
if ($appRoot === '-') {
    echo json_encode(['status' => 'verified_read_only', 'batch_id' => $batchId,
        'captured_at' => $meta['captured_at'], 'order_count' => count($rows),
        'store_net_cents' => $expectedNet], JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
$appRoot = realpath($appRoot);
if (!$appRoot || !is_file($appRoot . '/vendor/autoload.php')) throw new RuntimeException('Application root is invalid');
chdir($appRoot);
require $appRoot . '/vendor/autoload.php';
$app = new \think\App($appRoot . '/');
$app->env->load($appRoot . '/.env');
$app->initialize();
$db = \think\facade\Db::class;
$database = $db::query('SELECT DATABASE() db')[0]['db'] ?? '';
if ($database !== 'mohe_010_app') throw new RuntimeException('Refusing a non-010 application database');

// The names and IDs must still designate the same 010 stores. A wrong database
// or migrated store identity is a hard stop before any report-table write.
$targetStores = $db::name('system_store')->where('salary_status', 1)->order('id')->column('name', 'id');
$targetStores = array_map('strval', $targetStores);
if ($sourceStores !== array_map('strval', $targetStores)) throw new RuntimeException('007 and 010 settlement stores differ');
$existing = $db::name('basic_cross_store_legacy_batch')->where('batch_id', $batchId)->find();
if ($existing) {
    if ((string)$existing['status'] !== 'sealed' || !hash_equals((string)$existing['snapshot_sha256'], $expectedSha)
        || (int)$existing['order_count'] !== count($rows)) throw new RuntimeException('Existing batch conflicts with snapshot');
    $readback = $db::name('basic_cross_store_legacy_order')->where('batch_id', $batchId)
        ->field('source_order_id,store_id,kua_store,order_type,business_date,source_add_time,amount_cents,source_row_sha256')
        ->select()->toArray();
    verifyReadback($readback, $rows, $sourceStores, $expectedNet);
    echo json_encode(['status' => 'already_sealed', 'batch_id' => $batchId, 'order_count' => count($rows)], JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

$db::startTrans();
try {
    $db::name('basic_cross_store_legacy_batch')->insert(['batch_id' => $batchId,
        'source_instance' => '007', 'snapshot_sha256' => $expectedSha,
        'captured_at' => $meta['captured_at'], 'range_start' => '2026-01-01',
        'range_end' => '2026-10-26', 'order_count' => count($rows),
        'status' => 'loading', 'created_at' => date('Y-m-d H:i:s')]);
    foreach (array_chunk(array_values($rows), 250) as $chunk) {
        $db::name('basic_cross_store_legacy_order')->insertAll($chunk);
    }
    $readback = $db::name('basic_cross_store_legacy_order')->where('batch_id', $batchId)
        ->field('source_order_id,store_id,kua_store,order_type,business_date,source_add_time,amount_cents,source_row_sha256')
        ->select()->toArray();
    $actualNet = verifyReadback($readback, $rows, $sourceStores, $expectedNet);
    $db::name('basic_cross_store_legacy_batch')->where('batch_id', $batchId)->update(['status' => 'sealed']);
    $db::commit();
    echo json_encode(['status' => 'sealed', 'batch_id' => $batchId, 'captured_at' => $meta['captured_at'],
        'order_count' => count($rows), 'store_net_cents' => $actualNet], JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    $db::rollback();
    throw $e;
}
