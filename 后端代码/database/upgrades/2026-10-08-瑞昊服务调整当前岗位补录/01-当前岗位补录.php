<?php
declare(strict_types=1);

/**
 * Apply the product manager's current-position decision to twelve named RH adjustments.
 * The captured position is explicitly a manual assumption, not historical proof.
 * Dry-run is the default; application requires its unchanged plan fingerprint.
 */

$operationNos = [
    'STY2610011153356B0A42', 'STY26100111552133E270',
    'STY261001115617C8828A', 'STY26100112042441D2B3',
    'STY261001143241ED2D2C', 'STY261001143410C24D00',
    'STY2610011747401D84DD', 'STY26100117480211B5B1',
    'STY2610011748217CB3EA', 'STY2610020936182349A3',
    'STY2610031650401D4CF2', 'STY2610041858329AA138',
];
$apply = false;
$expectedPlanHash = '';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
    } elseif (strpos($argument, '--expected-plan-hash=') === 0) {
        $expectedPlanHash = substr($argument, strlen('--expected-plan-hash='));
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedPlanHash)) {
            throw new RuntimeException('invalid plan hash');
        }
    } else {
        throw new RuntimeException('unknown argument: ' . $argument);
    }
}
if ($apply && $expectedPlanHash === '') throw new RuntimeException('apply requires dry-run plan hash');

$environment = parse_ini_file(getenv('MOHE_REPAIR_ENV_PATH') ?: '/var/www/html/.env', true);
if (!is_array($environment) || !is_array($environment['DATABASE'] ?? null)) {
    throw new RuntimeException('database environment unavailable');
}
$database = $environment['DATABASE'];
if ((string)($database['DATABASE'] ?? '') !== 'ruihao') {
    throw new RuntimeException('this repair is scoped to the ruihao database');
}
$pdo = new PDO(
    'mysql:host=' . $database['HOSTNAME'] . ';port=' . $database['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    (string)$database['USERNAME'], (string)$database['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET NAMES utf8mb4');
$tableExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_service_customer_group_fact'")->fetchColumn();
if ($tableExists !== 1) throw new RuntimeException('group fact schema unavailable');

$operationQuery = $pdo->prepare("SELECT * FROM eb_cashier_v3_service_record_adjustment_operation WHERE operation_no=? AND status='succeeded' FOR UPDATE");
$laborQuery = $pdo->prepare("SELECT fact_id,store_id,checkout_request_id,source_line_id FROM eb_cashier_v3_performance_fact WHERE tenant_id=? AND business_event_no=? AND employee_id=? AND performance_type='labor_performance_allocated' AND fact_direction='forward' AND status='effective' FOR UPDATE");
$staffQuery = $pdo->prepare('SELECT id FROM eb_system_store_staff WHERE id=? AND employee_id=? AND store_id=? FOR UPDATE');
$jobQuery = $pdo->prepare('SELECT id,position_id FROM eb_staff_job_position WHERE staff_id=? AND employee_id=? AND store_id=? AND is_del=0 AND start_time<=? AND (end_time=0 OR end_time>?) FOR UPDATE');
// Lock the current position through insertion so the approved plan cannot drift mid-transaction.
$positionQuery = $pdo->prepare('SELECT id,performance_independent,version,update_time FROM eb_position WHERE id=? FOR UPDATE');
$existingQuery = $pdo->prepare('SELECT allocation_group_key,source_fingerprint,capture_mode FROM eb_cashier_v3_service_customer_group_fact WHERE tenant_id=? AND performance_fact_id=?');
$insert = $pdo->prepare('INSERT INTO eb_cashier_v3_service_customer_group_fact (tenant_id,store_id,service_fact_id,operation_id,business_event_no,performance_fact_id,employee_id,staff_id,staff_job_position_id,position_id,position_version,position_update_time,performance_independent,allocation_group_key,capture_mode,source_fingerprint,occurred_at,recorded_at,immutable_fingerprint) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$pdo->beginTransaction();
try {
    $planned = [];
    foreach ($operationNos as $operationNo) {
        $operationQuery->execute([$operationNo]);
        $operation = $operationQuery->fetch();
        if (!$operation) throw new RuntimeException('successful operation not found: ' . $operationNo);
        $allocations = json_decode((string)$operation['after_snapshot_json'], true);
        if (!is_array($allocations) || $allocations === []) throw new RuntimeException('invalid adjustment snapshot: ' . $operationNo);
        $seenEmployees = [];
        $hasManuallyAssumedIndependent = false;
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) throw new RuntimeException('invalid allocation: ' . $operationNo);
            $employeeId = (int)($allocation['employeeId'] ?? 0);
            $staffId = (int)($allocation['staffId'] ?? 0);
            if ($employeeId <= 0 || $staffId <= 0 || isset($seenEmployees[$employeeId])
                || (string)($allocation['personnelSource'] ?? '') !== 'store') {
                throw new RuntimeException('ambiguous store employee: ' . $operationNo);
            }
            $seenEmployees[$employeeId] = true;
            $staffQuery->execute([$staffId, $employeeId, $operation['store_id']]);
            if (count($staffQuery->fetchAll()) !== 1) throw new RuntimeException('staff identity mismatch: ' . $operationNo . '/' . $employeeId);
            $jobQuery->execute([$staffId, $employeeId, $operation['store_id'], $operation['occurred_at'], $operation['occurred_at']]);
            $jobs = $jobQuery->fetchAll();
            if (count($jobs) !== 1) throw new RuntimeException('historical employment ambiguous: ' . $operationNo . '/' . $employeeId);
            $positionQuery->execute([$jobs[0]['position_id']]);
            $position = $positionQuery->fetch();
            if (!$position) throw new RuntimeException('current position missing: ' . $operationNo . '/' . $employeeId);
            $independent = (int)$position['performance_independent'] === 1 ? 1 : 0;
            $hasManuallyAssumedIndependent = $hasManuallyAssumedIndependent
                || ($independent === 1 && (int)$position['update_time'] > (int)$operation['occurred_at']);
            $group = $independent ? 'independent:' . (int)$position['id'] : 'normal';
            $laborQuery->execute([$operation['tenant_id'], $operation['business_event_no'], $employeeId]);
            $labor = $laborQuery->fetchAll();
            if (count($labor) !== 1 || (int)$labor[0]['store_id'] !== (int)$operation['store_id']
                || (string)$labor[0]['checkout_request_id'] !== (string)$operation['checkout_request_id']
                || (string)$labor[0]['source_line_id'] !== (string)$operation['source_line_id']) {
                throw new RuntimeException('replacement labor fact mismatch: ' . $operationNo . '/' . $employeeId);
            }
            $existingQuery->execute([$operation['tenant_id'], $labor[0]['fact_id']]);
            $existing = $existingQuery->fetch();
            if ($existing && ((string)$existing['allocation_group_key'] !== $group
                || (string)$existing['source_fingerprint'] !== (string)$operation['immutable_fingerprint'])) {
                throw new RuntimeException('existing group fact conflicts: ' . $operationNo . '/' . $employeeId);
            }
            $planned[] = [
                'tenant_id' => (string)$operation['tenant_id'],
                'store_id' => (int)$operation['store_id'],
                'service_fact_id' => (int)$operation['service_fact_id'],
                'operation_id' => (string)$operation['operation_id'],
                'business_event_no' => (string)$operation['business_event_no'],
                'performance_fact_id' => (string)$labor[0]['fact_id'],
                'employee_id' => $employeeId,
                'staff_id' => $staffId,
                'staff_job_position_id' => (int)$jobs[0]['id'],
                'position_id' => (int)$position['id'],
                'position_version' => (int)$position['version'],
                'position_update_time' => (int)$position['update_time'],
                'performance_independent' => $independent,
                'allocation_group_key' => $group,
                'capture_mode' => 'manual_current_position',
                'source_fingerprint' => (string)$operation['immutable_fingerprint'],
                'occurred_at' => (int)$operation['occurred_at'],
                '_operation_no' => $operationNo,
                '_existing' => $existing !== false,
            ];
        }
        if (!$hasManuallyAssumedIndependent) {
            throw new RuntimeException('manual independent position not present: ' . $operationNo);
        }
    }
    if (count($planned) !== 22) throw new RuntimeException('unexpected allocation count');
    $planRows = array_map(static function (array $row): array {
        unset($row['_existing']);
        return $row;
    }, $planned);
    $planHash = hash('sha256', json_encode($planRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($apply && !hash_equals($expectedPlanHash, $planHash)) {
        throw new RuntimeException('position or source changed since dry-run');
    }
    $report = [];
    foreach ($planned as $row) {
        $report[] = [
            'operationNo' => $row['_operation_no'], 'employeeId' => $row['employee_id'],
            'performanceFactId' => $row['performance_fact_id'],
            'group' => $row['allocation_group_key'], 'existing' => $row['_existing'],
        ];
        $existing = $row['_existing'];
        unset($row['_operation_no'], $row['_existing']);
        if ($apply && !$existing) {
            $row['recorded_at'] = time();
            $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $insert->execute(array_values($row));
        }
    }
    if ($apply) $pdo->commit(); else $pdo->rollBack();
    echo json_encode([
        'database' => 'ruihao', 'mode' => $apply ? 'applied' : 'read_only',
        'planHash' => $planHash, 'rows' => $report,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
