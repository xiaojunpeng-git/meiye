<?php
declare(strict_types=1);

/**
 * Append frozen group facts only for explicitly named, provable old adjustments.
 * Dry-run is the default. Original service, labor and adjustment facts are read-only.
 */

$operationNos = [];
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
    } elseif (strpos($argument, '--operation-no=') === 0) {
        $operationNo = substr($argument, strlen('--operation-no='));
        if (!preg_match('/^STY[0-9A-Z]{10,40}$/D', $operationNo)) {
            throw new RuntimeException('invalid operation number');
        }
        $operationNos[$operationNo] = true;
    } else {
        throw new RuntimeException('unknown argument: ' . $argument);
    }
}
if ($operationNos === []) throw new RuntimeException('explicit --operation-no is required');

$environment = parse_ini_file(getenv('MOHE_REPAIR_ENV_PATH') ?: '/var/www/html/.env', true);
if (!is_array($environment) || !is_array($environment['DATABASE'] ?? null)) {
    throw new RuntimeException('database environment unavailable');
}
$database = $environment['DATABASE'];
$databaseName = (string)($database['DATABASE'] ?? '');
$pdo = new PDO(
    'mysql:host=' . $database['HOSTNAME'] . ';port=' . $database['HOSTPORT'] . ';dbname=' . $databaseName . ';charset=utf8mb4',
    (string)$database['USERNAME'],
    (string)$database['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET NAMES utf8mb4');
$tableExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_service_customer_group_fact'")->fetchColumn();
if ($tableExists !== 1) throw new RuntimeException('apply the group-fact schema first');

$operationQuery = $pdo->prepare("SELECT * FROM eb_cashier_v3_service_record_adjustment_operation WHERE operation_no=? AND status='succeeded' FOR UPDATE");
$laborQuery = $pdo->prepare("SELECT fact_id,tenant_id,store_id,employee_id,checkout_request_id,source_line_id,business_event_no FROM eb_cashier_v3_performance_fact WHERE tenant_id=? AND business_event_no=? AND employee_id=? AND performance_type='labor_performance_allocated' AND fact_direction='forward' AND status='effective' FOR UPDATE");
$staffQuery = $pdo->prepare('SELECT id,employee_id,store_id FROM eb_system_store_staff WHERE id=? AND employee_id=? AND store_id=?');
$jobQuery = $pdo->prepare('SELECT id,position_id,start_time,end_time FROM eb_staff_job_position WHERE staff_id=? AND employee_id=? AND store_id=? AND is_del=0 AND start_time<=? AND (end_time=0 OR end_time>?)');
$positionQuery = $pdo->prepare('SELECT id,performance_independent,version,update_time FROM eb_position WHERE id=?');
$existingQuery = $pdo->prepare('SELECT allocation_group_key,source_fingerprint FROM eb_cashier_v3_service_customer_group_fact WHERE tenant_id=? AND performance_fact_id=?');
$insert = $pdo->prepare('INSERT INTO eb_cashier_v3_service_customer_group_fact (tenant_id,store_id,service_fact_id,operation_id,business_event_no,performance_fact_id,employee_id,staff_id,staff_job_position_id,position_id,position_version,position_update_time,performance_independent,allocation_group_key,capture_mode,source_fingerprint,occurred_at,recorded_at,immutable_fingerprint) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$pdo->beginTransaction();
try {
    $planned = [];
    foreach (array_keys($operationNos) as $operationNo) {
        $operationQuery->execute([$operationNo]);
        $operation = $operationQuery->fetch();
        if (!$operation) throw new RuntimeException('successful operation not found: ' . $operationNo);
        $allocations = json_decode((string)$operation['after_snapshot_json'], true);
        if (!is_array($allocations) || $allocations === []) throw new RuntimeException('invalid adjustment snapshot: ' . $operationNo);
        $seenEmployees = [];
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) throw new RuntimeException('invalid allocation: ' . $operationNo);
            $employeeId = (int)($allocation['employeeId'] ?? 0);
            $staffId = (int)($allocation['staffId'] ?? 0);
            $source = (string)($allocation['personnelSource'] ?? '');
            if ($employeeId <= 0 || $staffId <= 0 || isset($seenEmployees[$employeeId])) {
                throw new RuntimeException('ambiguous employee allocation: ' . $operationNo);
            }
            $seenEmployees[$employeeId] = true;
            $laborQuery->execute([$operation['tenant_id'], $operation['business_event_no'], $employeeId]);
            $labor = $laborQuery->fetchAll();
            if (count($labor) !== 1 || (int)$labor[0]['store_id'] !== (int)$operation['store_id']
                || (string)$labor[0]['checkout_request_id'] !== (string)$operation['checkout_request_id']
                || (string)$labor[0]['source_line_id'] !== (string)$operation['source_line_id']) {
                throw new RuntimeException('replacement labor fact mismatch: ' . $operationNo . '/' . $employeeId);
            }
            $jobId = 0; $positionId = 0; $positionVersion = 0; $positionUpdate = 0; $independent = 0;
            if ($source === 'store') {
                $staffQuery->execute([$staffId, $employeeId, $operation['store_id']]);
                if (count($staffQuery->fetchAll()) !== 1) throw new RuntimeException('staff identity mismatch: ' . $operationNo . '/' . $employeeId);
                $jobQuery->execute([$staffId, $employeeId, $operation['store_id'], $operation['occurred_at'], $operation['occurred_at']]);
                $jobs = $jobQuery->fetchAll();
                if (count($jobs) !== 1) throw new RuntimeException('historical position ambiguous: ' . $operationNo . '/' . $employeeId);
                $jobId = (int)$jobs[0]['id'];
                $positionId = (int)$jobs[0]['position_id'];
                $positionQuery->execute([$positionId]);
                $position = $positionQuery->fetch();
                if (!$position || (int)$position['update_time'] > (int)$operation['occurred_at']) {
                    throw new RuntimeException('position changed after adjustment; historical group unprovable: ' . $operationNo . '/' . $employeeId);
                }
                $positionVersion = (int)$position['version'];
                $positionUpdate = (int)$position['update_time'];
                $independent = (int)$position['performance_independent'] === 1 ? 1 : 0;
            } elseif ($source !== 'other' || $staffId !== 1000000000 + $employeeId) {
                throw new RuntimeException('unsupported personnel source: ' . $operationNo . '/' . $employeeId);
            }
            $group = $independent ? 'independent:' . $positionId : 'normal';
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
                'employee_id' => $employeeId, 'staff_id' => $staffId,
                'staff_job_position_id' => $jobId, 'position_id' => $positionId,
                'position_version' => $positionVersion, 'position_update_time' => $positionUpdate,
                'performance_independent' => $independent,
                'allocation_group_key' => $group, 'capture_mode' => 'historical_verified',
                'source_fingerprint' => (string)$operation['immutable_fingerprint'],
                'occurred_at' => (int)$operation['occurred_at'], 'recorded_at' => time(),
                '_existing' => $existing !== false, '_operation_no' => $operationNo,
            ];
        }
    }
    $report = [];
    foreach ($planned as $row) {
        $report[] = [
            'operationNo' => $row['_operation_no'], 'employeeId' => $row['employee_id'],
            'performanceFactId' => $row['performance_fact_id'], 'group' => $row['allocation_group_key'],
            'existing' => $row['_existing'],
        ];
        $existing = $row['_existing'];
        unset($row['_existing'], $row['_operation_no']);
        if ($apply && !$existing) {
            $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $insert->execute(array_values($row));
        }
    }
    if ($apply) $pdo->commit(); else $pdo->rollBack();
    echo json_encode(['database' => $databaseName, 'mode' => $apply ? 'applied' : 'read_only', 'rows' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
