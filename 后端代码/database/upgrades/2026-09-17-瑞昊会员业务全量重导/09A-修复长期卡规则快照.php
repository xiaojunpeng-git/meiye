<?php
declare(strict_types=1);

/** Repairs the immutable snapshots created by 09 so the V3 authority can read imported time cards. */
$arguments = array_fill_keys(array_slice($argv, 1), true);
if (!isset($arguments['--execute'], $arguments['--allow-ruihao'], $arguments['--after-cleanup'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --after-cleanup\n");
    exit(2);
}

function rhTimeSnapshotCanonicalize($value) {
    if (!is_array($value)) return $value;
    if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) {
        return array_map('rhTimeSnapshotCanonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = rhTimeSnapshotCanonicalize($item);
    return $value;
}
function rhTimeSnapshotJson(array $value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('time-card snapshot json encode failed');
    return $json;
}
function rhTimeSnapshotFingerprint(array $value): string {
    return hash('sha256', rhTimeSnapshotJson(rhTimeSnapshotCanonicalize($value)));
}

$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$database = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: ($database['DATABASE'] ?? '');
if ($target !== 'ruihao') throw new RuntimeException("target guard failed: $target");
$pdo = new PDO(
    'mysql:host=' . $database['HOSTNAME'] . ';port=' . $database['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    $database['USERNAME'], $database['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$rows = $pdo->query(
    "SELECT s.*, c.id AS component_id, c.relation_id, c.project_product_id, c.project_sku_id,
            c.project_sku_unique, c.project_type, c.project_name_snapshot, c.writeoff_amount_cents
       FROM eb_cashier_v3_card_rule_state s
       JOIN eb_cashier_v3_card_rule_component c ON c.rule_state_id=s.id
      WHERE s.receipt_id LIKE 'RH-TIME-102-%'
      ORDER BY s.id, c.id"
)->fetchAll();

$updateState = $pdo->prepare('UPDATE eb_cashier_v3_card_rule_state SET immutable_fingerprint=?,definition_snapshot_json=?,update_time=? WHERE id=?');
$updateComponent = $pdo->prepare('UPDATE eb_cashier_v3_card_rule_component SET immutable_fingerprint=?,component_snapshot_json=?,update_time=? WHERE id=?');
$count = 0;
$now = time();
foreach ($rows as $row) {
    $component = [
        'relationId' => (int)$row['relation_id'], 'productId' => (int)$row['project_product_id'],
        'skuId' => (int)$row['project_sku_id'], 'skuUnique' => (string)$row['project_sku_unique'],
        'productType' => (int)$row['project_type'], 'nameSnapshot' => (string)$row['project_name_snapshot'],
        'writeTimes' => 0, 'configuredPriceCents' => 0,
        'writeoffAmountCents' => (int)$row['writeoff_amount_cents'],
    ];
    $snapshot = [
        'contractVersion' => 'cashier-v3-issued-card-rule-state-v1', 'receiptId' => (string)$row['receipt_id'],
        'salesOrderId' => (string)$row['sales_order_id'], 'salesOrderLineId' => (string)$row['sales_order_line_id'],
        'holderId' => (int)$row['card_holder_id'], 'catalogProductId' => (int)$row['catalog_product_id'],
        'catalogSkuId' => (int)$row['catalog_sku_id'], 'ruleType' => 'time', 'ruleVersion' => 1,
        'definitionVersion' => 1, 'choiceLimit' => 0, 'sharedTimes' => 0,
        'validity' => ['writeValid' => 3, 'writeDays' => 0, 'writeStart' => (int)$row['valid_from'], 'writeEnd' => (int)$row['valid_through']],
        'components' => [$component],
    ];
    $pdo->beginTransaction();
    try {
        $updateState->execute([rhTimeSnapshotFingerprint($snapshot), rhTimeSnapshotJson($snapshot), $now, (int)$row['id']]);
        $updateComponent->execute([rhTimeSnapshotFingerprint($component), rhTimeSnapshotJson($component), $now, (int)$row['component_id']]);
        $pdo->commit();
        $count++;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
echo json_encode(['repaired' => $count], JSON_UNESCAPED_UNICODE), PHP_EOL;
