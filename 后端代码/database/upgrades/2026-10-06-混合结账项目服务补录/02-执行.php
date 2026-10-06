<?php
declare(strict_types=1);

/**
 * Backfill completed services for settled mixed-checkout project sale lines.
 * Dry-run is the default. The settled order, line and original labor facts are
 * the only monetary authorities; today's project rule must not rewrite history.
 */

$execute = in_array('--execute', $argv ?? [], true);
$allowRuihao = in_array('--allow-ruihao', $argv ?? [], true);
$envPath = getenv('MOHE_REPAIR_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env) || !is_array($env['DATABASE'] ?? null)) {
    throw new RuntimeException('database environment unavailable');
}
$config = $env['DATABASE'];
$database = (string)($config['DATABASE'] ?? '');
if ($database !== 'ruihao' || ($execute && !$allowRuihao)) {
    throw new RuntimeException('refuse: ruihao database and explicit write flag required');
}
$pdo = new PDO(
    'mysql:host=' . $config['HOSTNAME'] . ';port=' . $config['HOSTPORT'] . ';dbname=' . $database . ';charset=utf8mb4',
    $config['USERNAME'], $config['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// The anti-join is line-granular: reruns also find partially repaired orders.
$candidateSql = "FROM eb_cashier_v3_sales_order o
 JOIN eb_cashier_v3_checkout_request r ON r.tenant_id=o.tenant_id AND r.request_id=o.checkout_request_id
 JOIN eb_cashier_v3_sales_order_line l ON l.tenant_id=o.tenant_id AND l.order_id=o.order_id
 LEFT JOIN eb_cashier_v3_entitlement_service_fact sf ON sf.tenant_id=l.tenant_id
   AND sf.checkout_request_id=l.checkout_request_id AND sf.source_line_id=l.order_line_id
 WHERE o.composition='mixed' AND o.order_status='settled' AND o.order_direction='forward'
   AND r.request_status='succeeded' AND l.item_type='project' AND l.line_status='settled'
   AND l.line_direction='forward' AND sf.id IS NULL
   AND NOT EXISTS (SELECT 1 FROM eb_cashier_v3_sales_order_line rev
     WHERE rev.tenant_id=l.tenant_id AND rev.reversal_of_line_id=l.order_line_id)";
$candidates = $pdo->query('SELECT o.tenant_id,o.order_id,o.order_no,l.order_line_id ' . $candidateSql
    . ' ORDER BY o.business_date,o.id,l.line_no,l.id')->fetchAll();
$orderIds = array_unique(array_column($candidates, 'order_id'));
if (!$execute) {
    echo json_encode(['mode' => 'read_only', 'database' => $database,
        'orders' => count($orderIds), 'lines' => count($candidates),
        'orderNos' => array_values(array_unique(array_column($candidates, 'order_no')))],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$lockName = 'mixed_sale_project_service_backfill_ruihao';
$lock = $pdo->prepare('SELECT GET_LOCK(?,30)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('migration lock unavailable');
try {
    // DDL is outside row transactions; an interrupted run can resume safely.
    $pdo->exec("CREATE TABLE IF NOT EXISTS eb_cashier_v3_mixed_sale_service_repair_audit (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      checkout_request_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      order_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      source_line_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      source_order_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      source_line_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      original_labor_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      service_fact_id varchar(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      service_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      service_record_no varchar(32) NOT NULL,
      applied_at bigint unsigned NOT NULL,
      PRIMARY KEY(id), UNIQUE KEY uk_line(tenant_id,source_line_id),
      UNIQUE KEY uk_service(service_fact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Audited mixed checkout missing project services'");
    $applied = 0;
    foreach ($candidates as $candidate) {
        $pdo->beginTransaction();
        try {
            $order = one($pdo, 'SELECT * FROM eb_cashier_v3_sales_order WHERE tenant_id=? AND order_id=? FOR UPDATE',
                [$candidate['tenant_id'], $candidate['order_id']]);
            $line = one($pdo, 'SELECT * FROM eb_cashier_v3_sales_order_line WHERE tenant_id=? AND order_line_id=? FOR UPDATE',
                [$candidate['tenant_id'], $candidate['order_line_id']]);
            if (!$order || !$line || (string)$order['composition'] !== 'mixed'
                || (string)$order['order_status'] !== 'settled' || (string)$order['order_direction'] !== 'forward'
                || (string)$line['order_id'] !== (string)$order['order_id']
                || (string)$line['checkout_request_id'] !== (string)$order['checkout_request_id']
                || (int)$line['store_id'] !== (int)$order['store_id']
                || (int)$line['member_id'] !== (int)$order['member_id']
                || (string)$line['item_type'] !== 'project' || (string)$line['line_status'] !== 'settled'
                || (string)$line['line_direction'] !== 'forward'
                || (int)$line['item_id'] <= 0 || (int)$line['quantity'] <= 0) {
                throw new RuntimeException('source changed: ' . $candidate['order_line_id']);
            }
            $request = one($pdo, 'SELECT request_status FROM eb_cashier_v3_checkout_request WHERE tenant_id=? AND request_id=? FOR UPDATE',
                [$order['tenant_id'], $order['checkout_request_id']]);
            if (!$request || (string)$request['request_status'] !== 'succeeded') {
                throw new RuntimeException('checkout not successful: ' . $candidate['order_line_id']);
            }
            $existing = one($pdo, 'SELECT service_fact_id FROM eb_cashier_v3_entitlement_service_fact WHERE tenant_id=? AND checkout_request_id=? AND source_line_id=? FOR UPDATE',
                [$order['tenant_id'], $order['checkout_request_id'], $line['order_line_id']]);
            if ($existing) {
                $pdo->commit();
                continue;
            }
            if (one($pdo, 'SELECT id FROM eb_cashier_v3_sales_order_line WHERE tenant_id=? AND reversal_of_line_id=? LIMIT 1',
                [$order['tenant_id'], $line['order_line_id']])) {
                throw new RuntimeException('reversed sale line: ' . $line['order_line_id']);
            }
            // A mixed checkout already has an entitlement service and lifecycle
            // visit. Never append an old visit to today's lifecycle projection.
            if (!one($pdo, 'SELECT id FROM eb_cashier_v3_entitlement_service_fact WHERE tenant_id=? AND checkout_request_id=? LIMIT 1',
                [$order['tenant_id'], $order['checkout_request_id']])) {
                throw new RuntimeException('mixed checkout has no original service: ' . $order['order_id']);
            }
            if ((int)$order['member_id'] > 0 && !one($pdo,
                "SELECT id FROM eb_cashier_v3_customer_lifecycle_fact WHERE tenant_id=? AND natural_key=? LIMIT 1",
                [$order['tenant_id'], 'service-visit:' . $order['checkout_request_id']])) {
                throw new RuntimeException('historical lifecycle state requires review: ' . $order['order_id']);
            }
            $event = one($pdo,
                "SELECT event_no FROM eb_cashier_v3_business_event WHERE tenant_id=? AND source_id=?
                  AND event_type='checkout.completed' AND aggregate_type='sales_order'
                  AND aggregate_id=? AND source_type='submit-checkout'",
                [$order['tenant_id'], $order['checkout_request_id'], $order['order_id']]);
            if (!$event) throw new RuntimeException('checkout event missing: ' . $order['order_id']);
            $facts = all($pdo,
                "SELECT fact_id,allocation_base_amount_cents,labor_fee_amount_cents,rule_version_snapshot
                 FROM eb_cashier_v3_performance_fact WHERE tenant_id=? AND checkout_request_id=?
                   AND source_line_id=? AND performance_type='labor_performance_allocated'
                   AND fact_direction='forward' AND rule_code_snapshot LIKE 'SALE-PROJECT-LABOR%'
                 ORDER BY id FOR UPDATE",
                [$order['tenant_id'], $order['checkout_request_id'], $line['order_line_id']]);
            $craftsmen = json_decode((string)$line['craftsmen_snapshot_json'], true);
            if (!is_array($craftsmen) || ($craftsmen !== [] && !$facts)) {
                throw new RuntimeException('original craftsmen/labor facts incomplete: ' . $line['order_line_id']);
            }
            $baseAmounts = array_unique(array_map(static function (array $fact): int {
                return (int)$fact['allocation_base_amount_cents'];
            }, $facts));
            if (count($baseAmounts) > 1) throw new RuntimeException('conflicting original labor bases: ' . $line['order_line_id']);
            $laborAmount = $baseAmounts ? (int)reset($baseAmounts) : 0;
            $laborFee = array_sum(array_map(static function (array $fact): int {
                return (int)$fact['labor_fee_amount_cents'];
            }, $facts));
            if ($laborAmount < 0 || $laborFee < 0) throw new RuntimeException('negative original labor: ' . $line['order_line_id']);
            $ruleVersions = array_unique(array_column($facts, 'rule_version_snapshot'));
            if (count($ruleVersions) > 1 || ($ruleVersions && !preg_match('/^project-rule:[1-9][0-9]*$/D', (string)reset($ruleVersions)))) {
                throw new RuntimeException('original labor rule ambiguous: ' . $line['order_line_id']);
            }
            $laborMode = historicalLaborMode($pdo, $order, $line, $ruleVersions, $laborAmount);
            $categoryPath = categoryPath($pdo, (int)$line['category_id_snapshot'], (string)$line['category_name_snapshot']);
            $primaryStaffId = 0;
            foreach ($craftsmen as $person) {
                if (!is_array($person) || (int)($person['staffId'] ?? 0) <= 0) {
                    throw new RuntimeException('invalid original craftsman: ' . $line['order_line_id']);
                }
                if (!empty($person['isPrimary'])) $primaryStaffId = (int)$person['staffId'];
            }
            $natural = 'sale_project_service:' . $order['checkout_request_id'] . ':' . $line['order_line_id'];
            $factId = 'SSF-' . substr(hash('sha256', $natural), 0, 40);
            $now = time();
            $row = [
                'service_fact_id' => $factId, 'natural_key' => $natural,
                'business_event_no' => (string)$event['event_no'],
                'command_idempotency_key' => (string)$order['command_idempotency_key'],
                'tenant_id' => (string)$order['tenant_id'], 'tenant_name_snapshot' => '',
                'organization_id' => (string)$order['organization_id'],
                'organization_name_snapshot' => (string)$order['organization_name_snapshot'],
                'organization_path_snapshot' => (string)$order['organization_path_snapshot'],
                'store_id' => (int)$order['store_id'], 'store_name_snapshot' => (string)$order['store_name_snapshot'],
                'member_id' => (int)$order['member_id'], 'member_name_snapshot' => (string)$order['member_name_snapshot'],
                'operator_id' => (int)$order['operator_id'], 'operator_name_snapshot' => (string)$order['operator_name_snapshot'],
                'business_date' => (string)$order['business_date'], 'business_timezone' => (string)$order['business_timezone'],
                'occurred_at' => (int)$order['occurred_at'], 'settled_at' => (int)$order['settled_at'],
                'recorded_at' => $now, 'checkout_request_id' => (string)$order['checkout_request_id'],
                'document_id' => (string)$order['order_id'], 'document_no_snapshot' => (string)$order['order_no'],
                'source_document_type' => 'sales_order', 'source_document_id' => (int)$order['id'],
                'source_line_id' => (string)$line['order_line_id'], 'project_id' => (int)$line['item_id'],
                'project_name_snapshot' => (string)$line['item_name_snapshot'],
                'project_category_id_snapshot' => (int)$line['category_id_snapshot'],
                'project_category_name_snapshot' => (string)$line['category_name_snapshot'],
                'project_category_path_snapshot' => $categoryPath, 'quantity' => (int)$line['quantity'],
                'service_object' => (string)$line['service_object'],
                'friend_counts_as_customer' => (int)$line['friend_counts_as_customer'],
                'is_experience' => (int)$line['is_experience'],
                'labor_amount_cents' => $laborAmount, 'labor_fee_amount_cents' => $laborFee,
                'labor_mode' => $laborMode, 'primary_craftsman_staff_id' => $primaryStaffId,
                'craftsmen_snapshot_json' => (string)$line['craftsmen_snapshot_json'],
                'detail_remark_snapshot' => (string)($line['detail_remark_snapshot'] ?? ''),
                'service_status' => 'completed',
            ];
            $row['immutable_fingerprint'] = fingerprint($row);
            // Keep source enrichment outside the legacy immutable fingerprint.
            if ((int)$order['business_source_primary_id'] > 0) {
                foreach (['business_source_primary_id','business_source_primary_name_snapshot',
                    'business_source_secondary_id','business_source_secondary_name_snapshot',
                    'business_source_label_snapshot'] as $key) $row[$key] = $order[$key];
            }
            $row['service_record_no'] = allocateServiceNo($pdo, (string)$order['tenant_id'], $factId,
                (string)$order['business_date'], $now);
            insertRow($pdo, 'eb_cashier_v3_entitlement_service_fact', $row);
            $originalLaborIds = array_column($facts, 'fact_id');
            sort($originalLaborIds, SORT_STRING);
            insertRow($pdo, 'eb_cashier_v3_mixed_sale_service_repair_audit', [
                'tenant_id' => $order['tenant_id'], 'checkout_request_id' => $order['checkout_request_id'],
                'order_id' => $order['order_id'], 'source_line_id' => $line['order_line_id'],
                'source_order_fingerprint' => $order['immutable_fingerprint'],
                'source_line_fingerprint' => $line['immutable_fingerprint'],
                'original_labor_fingerprint' => hash('sha256', json_encode($originalLaborIds)),
                'service_fact_id' => $factId, 'service_fingerprint' => $row['immutable_fingerprint'],
                'service_record_no' => $row['service_record_no'], 'applied_at' => $now,
            ]);
            $pdo->commit();
            $applied++;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
    $remaining = (int)$pdo->query('SELECT COUNT(*) ' . $candidateSql)->fetchColumn();
    if ($remaining !== 0) throw new RuntimeException('eligible lines remain: ' . $remaining);
    // Register the completed instance repair only after every eligible line
    // has a service. An interrupted run can resume without claiming success.
    $version = trim((string)(getenv('MOHE_RELEASE_COMMIT') ?: 'local-uncommitted'));
    $key = '20261006-ruihao-mixed-sale-project-service-repair';
    $pdo->prepare("INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
      SELECT ?,?,?,?, ?,NOW(),?,? FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key=?)")
        ->execute([$key, '混合结账购买项目已完成服务补录',
            'database/upgrades/2026-10-06-混合结账项目服务补录/02-执行.php',
            hash_file('sha256', __FILE__), $version, 'Codex',
            '按冻结销售和劳动事实逐行补录；审计表记录明细与服务指纹', $key]);
    echo json_encode(['mode' => 'executed', 'database' => $database, 'previewLines' => count($candidates),
        'appliedLines' => $applied, 'remaining' => $remaining], JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}

function one(PDO $pdo, string $sql, array $args): ?array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($args);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function all(PDO $pdo, string $sql, array $args): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($args);
    return $statement->fetchAll();
}

/** A recorded rule version is required before consulting the still-matching rule row. */
function historicalLaborMode(PDO $pdo, array $order, array $line, array $versions, int $laborAmount): string
{
    if (!$versions) return 'actual_entitlement_amount';
    $version = (int)substr((string)reset($versions), strlen('project-rule:'));
    $rule = one($pdo, 'SELECT labor_mode,current_version FROM eb_cashier_v3_project_performance_rule WHERE tenant_id=? AND project_id=?',
        [$order['tenant_id'], (int)$line['item_id']]);
    if (!$rule) {
        // Rule version 1 is also the sale writer's frozen default when no row exists.
        if ($version !== 1 || $laborAmount !== 0 && $laborAmount !== (int)$line['sale_amount_cents']) {
            throw new RuntimeException('historical labor mode not provable: ' . $line['order_line_id']);
        }
        return 'actual_entitlement_amount';
    }
    if ((int)$rule['current_version'] !== $version
        || !in_array((string)$rule['labor_mode'], ['actual_entitlement_amount','project_configured_amount'], true)) {
        throw new RuntimeException('historical labor rule changed: ' . $line['order_line_id']);
    }
    return (string)$rule['labor_mode'];
}

/** Keep the frozen leaf name if the present catalog hierarchy no longer matches it. */
function categoryPath(PDO $pdo, int $categoryId, string $fallback): string
{
    $names = [];
    $seen = [];
    for ($guard = 0; $categoryId > 0 && $guard < 16; $guard++) {
        if (isset($seen[$categoryId])) throw new RuntimeException('category cycle');
        $seen[$categoryId] = true;
        $row = one($pdo, 'SELECT pid,cate_name FROM eb_store_product_category WHERE id=?', [$categoryId]);
        if (!$row) break;
        array_unshift($names, trim((string)$row['cate_name']));
        $categoryId = (int)$row['pid'];
    }
    $fallback = trim($fallback);
    if (!$names || end($names) !== $fallback) return $fallback;
    return implode(' / ', array_filter($names, static function (string $name): bool { return $name !== ''; }));
}

/** Match the live FW number allocator and reserve the mapping in this row transaction. */
function allocateServiceNo(PDO $pdo, string $tenant, string $factId, string $date, int $now): string
{
    $existing = one($pdo,
        'SELECT document_no FROM eb_cashier_v3_business_document_no WHERE tenant_id=? AND source_type=? AND source_id=? FOR UPDATE',
        [$tenant, 'sale_project_service_fact', $factId]);
    if ($existing) return (string)$existing['document_no'];
    $pdo->prepare("INSERT INTO eb_cashier_v3_business_document_sequence
      (tenant_id,document_type,business_date,current_value,created_at,updated_at) VALUES (?,'service',?,1,?,?)
      ON DUPLICATE KEY UPDATE current_value=LAST_INSERT_ID(current_value+1),updated_at=VALUES(updated_at)")
        ->execute([$tenant, $date, $now, $now]);
    $sequence = one($pdo,
        "SELECT current_value FROM eb_cashier_v3_business_document_sequence WHERE tenant_id=? AND document_type='service' AND business_date=? FOR UPDATE",
        [$tenant, $date]);
    $number = (int)($sequence['current_value'] ?? 0);
    if ($number < 1 || $number > 99999) throw new RuntimeException('service number sequence exhausted');
    $serviceNo = 'FW' . date('md', strtotime($date)) . str_pad((string)$number, 5, '0', STR_PAD_LEFT);
    insertRow($pdo, 'eb_cashier_v3_business_document_no', [
        'tenant_id' => $tenant, 'source_type' => 'sale_project_service_fact', 'source_id' => $factId,
        'document_type' => 'service', 'business_date' => $date, 'document_no' => $serviceNo,
        'created_at' => $now,
    ]);
    return $serviceNo;
}

function fingerprint(array $row): string
{
    unset($row['service_record_no'], $row['immutable_fingerprint']);
    ksort($row, SORT_STRING);
    return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function insertRow(PDO $pdo, string $table, array $row): void
{
    $keys = array_keys($row);
    $columns = implode(',', array_map(static function (string $key): string { return '`' . $key . '`'; }, $keys));
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $pdo->prepare('INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . $placeholders . ')')
        ->execute(array_values($row));
}
