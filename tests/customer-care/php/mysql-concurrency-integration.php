<?php

require __DIR__ . '/mysql-fixture.php';

$careConcurrencyPassed = 0;
$careConcurrencyFailed = 0;

function careConcurrencyOk(string $name, bool $condition, string $detail = ''): void
{
    global $careConcurrencyPassed, $careConcurrencyFailed;
    if ($condition) {
        $careConcurrencyPassed++;
        echo "PASS {$name}\n";
        return;
    }
    $careConcurrencyFailed++;
    echo 'FAIL ' . $name . ($detail === '' ? '' : ' -> ' . $detail) . "\n";
}

function careConcurrencyPdo(): PDO
{
    return new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306')
            . ';dbname=' . getenv('DB_DATABASE') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/** @return array{process:resource,pipes:array<int,resource>,stdoutBuffer:string,stderrBuffer:string} */
function careConcurrencyStartWorker(string $label): array
{
    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/mysql-repository-worker.php') . ' '
        . escapeshellarg('concurrent-create') . ' '
        . escapeshellarg($label);
    $pipes = [];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('WORKER_PROCESS_START_FAILED label=' . $label);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return [
        'process' => $process,
        'pipes' => $pipes,
        'stdoutBuffer' => '',
        'stderrBuffer' => '',
    ];
}

function careConcurrencyReadWorkerDiagnostics(array &$worker): string
{
    $worker['stdoutBuffer'] .= (string)stream_get_contents($worker['pipes'][1]);
    $worker['stderrBuffer'] .= (string)stream_get_contents($worker['pipes'][2]);
    return ' stdout=' . trim($worker['stdoutBuffer'])
        . ' stderr=' . trim($worker['stderrBuffer']);
}

function careConcurrencyWorkerConnectionId(array &$worker): int
{
    careConcurrencyReadWorkerDiagnostics($worker);
    $matched = [];
    if (!preg_match('/^CARE_WORKER_CONNECTION_ID=(\d+)$/m', $worker['stderrBuffer'], $matched)) {
        return 0;
    }
    return (int)$matched[1];
}

function careConcurrencyAssertWorkerRunning(array &$worker, string $label): void
{
    $status = proc_get_status($worker['process']);
    if (!$status['running']) {
        throw new RuntimeException(
            'WORKER_EXITED_BEFORE_GATE label=' . $label
            . careConcurrencyReadWorkerDiagnostics($worker)
        );
    }
}

function careConcurrencyPoll(callable $probe, string $failure, float $timeoutSeconds = 20.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        if ($probe()) {
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException($failure);
}

/** @return array{exitCode:int,stdout:string,stderr:string} */
function careConcurrencyFinishWorker(array $worker, float $timeoutSeconds = 30.0): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    $exitCode = -1;
    $status = ['running' => true];
    do {
        $status = proc_get_status($worker['process']);
        if (!$status['running']) {
            $exitCode = (int)$status['exitcode'];
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        throw new RuntimeException('WORKER_PROCESS_TIMEOUT');
    }

    $stdout = $worker['stdoutBuffer'] . (string)stream_get_contents($worker['pipes'][1]);
    $stderr = $worker['stderrBuffer'] . (string)stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $closeCode = proc_close($worker['process']);
    if ($exitCode < 0) {
        $exitCode = (int)$closeCode;
    }
    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

function careConcurrencyTerminateWorker(?array $worker): void
{
    if ($worker === null || !is_resource($worker['process'])) {
        return;
    }
    $status = proc_get_status($worker['process']);
    if ($status['running']) {
        proc_terminate($worker['process']);
        usleep(100000);
    }
    foreach ($worker['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($worker['process']);
}

function careConcurrencyWorkerResult(array $finished): array
{
    if ($finished['exitCode'] !== 0) {
        throw new RuntimeException(
            'WORKER_FAILED exit=' . $finished['exitCode']
            . ' stderr=' . trim($finished['stderr'])
            . ' stdout=' . trim($finished['stdout'])
        );
    }
    $matched = [];
    if (!preg_match('/^CARE_WORKER_RESULT=(\{.*\})$/m', $finished['stdout'], $matched)) {
        throw new RuntimeException('WORKER_RESULT_MISSING stdout=' . trim($finished['stdout']));
    }
    $result = json_decode($matched[1], true);
    if (!is_array($result)) {
        throw new RuntimeException('WORKER_RESULT_INVALID');
    }
    return $result;
}

function careConcurrencySnapshot(PDO $pdo): string
{
    $queries = [
        'processlist' => 'SHOW FULL PROCESSLIST',
        'transactions' => 'SELECT * FROM information_schema.INNODB_TRX',
        'locks' => 'SELECT * FROM information_schema.INNODB_LOCKS',
        'lock_waits' => 'SELECT * FROM information_schema.INNODB_LOCK_WAITS',
        'triggers' => 'SHOW TRIGGERS',
        'counts' => "SELECT
          (SELECT COUNT(*) FROM eb_cashier_v3_command_receipt
            WHERE idempotency_key LIKE 'CARE_RECEIPT-%') AS receipts,
          (SELECT COUNT(*) FROM eb_customer_care_task) AS tasks,
          (SELECT COUNT(*) FROM eb_customer_care_operation) AS operations",
    ];
    $snapshot = [];
    foreach ($queries as $name => $query) {
        try {
            $snapshot[$name] = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $throwable) {
            $snapshot[$name] = ['error' => get_class($throwable) . ':' . $throwable->getMessage()];
        }
    }
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : 'SNAPSHOT_JSON_FAILED';
}

function careConcurrencyCount(PDO $pdo, string $sql, string $value): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute([$value]);
    return (int)$statement->fetchColumn();
}

$pdo = careConcurrencyPdo();
$workerA = null;
$workerB = null;
$releaseHeld = false;

try {
    $pdo->exec('DROP TRIGGER IF EXISTS `care_test_pause_task`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DELETE FROM `eb_customer_care_operation`');
    $pdo->exec('DELETE FROM `eb_customer_care_record`');
    $pdo->exec('DELETE FROM `eb_customer_care_task`');
    $pdo->exec("DELETE FROM `eb_cashier_v3_command_receipt`
      WHERE `idempotency_key` LIKE 'CARE_RECEIPT-%'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $assignmentCount = (int)$pdo->query("SELECT COUNT(*)
      FROM `eb_system_store_staff` staff
      JOIN `eb_employee` employee ON employee.id=staff.employee_id
      WHERE staff.id=101 AND staff.store_id=8 AND staff.status=1 AND staff.is_del=0
        AND employee.status=1 AND employee.is_del=0")->fetchColumn();
    if ($assignmentCount !== 1) {
        throw new RuntimeException('CONCURRENCY_FIXTURE_ASSIGNMENT_MISSING');
    }

    $releaseHeld = (int)$pdo->query(
        "SELECT GET_LOCK('customer_care_test_release',0)"
    )->fetchColumn() === 1;
    if (!$releaseHeld) {
        throw new RuntimeException('COORDINATOR_RELEASE_LOCK_FAILED');
    }

    // MySQL 5.6 permits one named lock per connection, so readiness is proven via PROCESSLIST.
    $pdo->exec("CREATE TRIGGER `care_test_pause_task`
      BEFORE INSERT ON `eb_customer_care_task`
      FOR EACH ROW
      BEGIN
        SET @care_release_lock=GET_LOCK('customer_care_test_release',30);
        IF @care_release_lock<>1 THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CARE_TEST_RELEASE_LOCK_TIMEOUT';
        END IF;
        SET @care_release_released=RELEASE_LOCK('customer_care_test_release');
      END");

    echo "== worker A reaches the task trigger ==\n";
    $workerA = careConcurrencyStartWorker('A');
    $workerAConnectionId = 0;
    careConcurrencyPoll(function () use ($pdo, &$workerA, &$workerAConnectionId): bool {
        careConcurrencyAssertWorkerRunning($workerA, 'A');
        $workerAConnectionId = careConcurrencyWorkerConnectionId($workerA);
        if ($workerAConnectionId <= 0) {
            return false;
        }
        $statement = $pdo->prepare(
            'SELECT STATE, INFO FROM information_schema.PROCESSLIST WHERE ID=?'
        );
        $statement->execute([$workerAConnectionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || strcasecmp((string)($row['STATE'] ?? ''), 'User lock') !== 0) {
            return false;
        }
        $info = (string)($row['INFO'] ?? '');
        return stripos($info, 'customer_care_task') !== false
            || stripos($info, 'customer_care_test_release') !== false;
    }, 'WORKER_A_NOT_PROVEN_WAITING_IN_TASK_TRIGGER');
    careConcurrencyOk('worker A is paused inside the task insert trigger', $workerAConnectionId > 0);

    echo "== worker B waits on the uncommitted receipt unique key ==\n";
    $workerB = careConcurrencyStartWorker('B');
    $workerBConnectionId = 0;
    careConcurrencyPoll(function () use (
        $pdo,
        &$workerB,
        &$workerBConnectionId,
        $workerAConnectionId
    ): bool {
        careConcurrencyAssertWorkerRunning($workerB, 'B');
        $workerBConnectionId = careConcurrencyWorkerConnectionId($workerB);
        if ($workerBConnectionId <= 0) {
            return false;
        }
        $statement = $pdo->prepare("SELECT COUNT(*)
          FROM information_schema.INNODB_LOCK_WAITS waits
          JOIN information_schema.INNODB_LOCKS requested
            ON requested.lock_id=waits.requested_lock_id
          JOIN information_schema.INNODB_TRX requester
            ON requester.trx_id=waits.requesting_trx_id
          JOIN information_schema.INNODB_TRX blocker
            ON blocker.trx_id=waits.blocking_trx_id
          WHERE requester.trx_mysql_thread_id=?
            AND blocker.trx_mysql_thread_id=?
            AND requested.lock_table LIKE '%eb_cashier_v3_command_receipt%'
            AND requested.lock_index='uk_idempotency_key'");
        $statement->execute([$workerBConnectionId, $workerAConnectionId]);
        return (int)$statement->fetchColumn() === 1;
    }, 'WORKER_B_NOT_PROVEN_BLOCKED_ON_RECEIPT_UNIQUE_KEY');
    careConcurrencyOk(
        'worker B is blocked by worker A on the receipt idempotency unique key',
        $workerBConnectionId > 0
    );

    $released = (int)$pdo->query(
        "SELECT RELEASE_LOCK('customer_care_test_release')"
    )->fetchColumn();
    $releaseHeld = false;
    if ($released !== 1) {
        throw new RuntimeException('COORDINATOR_RELEASE_FAILED');
    }

    $resultA = careConcurrencyWorkerResult(careConcurrencyFinishWorker($workerA));
    $workerA = null;
    $resultB = careConcurrencyWorkerResult(careConcurrencyFinishWorker($workerB));
    $workerB = null;

    $command = careMysqlCreateTaskCommand(9001);
    $receiptKey = careMysqlReceiptKey('TENANT_1', $command['idempotencyKey']);
    careConcurrencyOk(
        'first worker executes and the blocked second worker replays',
        $resultA['replayed'] === false
            && $resultB['replayed'] === true
            && $resultA['operationId'] === $resultB['operationId']
    );
    careConcurrencyOk(
        'concurrent same-key command creates one receipt task and operation',
        careConcurrencyCount(
            $pdo,
            'SELECT COUNT(*) FROM `eb_cashier_v3_command_receipt`'
                . ' WHERE `idempotency_key`=? AND `status`=1',
            $receiptKey
        ) === 1
            && careConcurrencyCount(
                $pdo,
                'SELECT COUNT(*) FROM `eb_customer_care_task` WHERE `task_key`=?',
                $command['taskKey']
            ) === 1
            && careConcurrencyCount(
                $pdo,
                'SELECT COUNT(*) FROM `eb_customer_care_operation`'
                    . ' WHERE `command_idempotency_key`=?',
                $command['idempotencyKey']
            ) === 1
    );

    echo "== blocked worker takes over after the first transaction rolls back ==\n";
    $pdo->exec('DROP TRIGGER IF EXISTS `care_test_pause_task`');
    $pdo->exec('DROP TRIGGER IF EXISTS `care_test_fail_first_operation`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DELETE FROM `eb_customer_care_operation`');
    $pdo->exec('DELETE FROM `eb_customer_care_record`');
    $pdo->exec('DELETE FROM `eb_customer_care_task`');
    $pdo->exec("DELETE FROM `eb_cashier_v3_command_receipt`
      WHERE `idempotency_key` LIKE 'CARE_RECEIPT-%'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $releaseHeld = (int)$pdo->query(
        "SELECT GET_LOCK('customer_care_test_release',0)"
    )->fetchColumn() === 1;
    if (!$releaseHeld) {
        throw new RuntimeException('ROLLBACK_COORDINATOR_RELEASE_LOCK_FAILED');
    }
    $pdo->exec("CREATE TRIGGER `care_test_pause_task`
      BEFORE INSERT ON `eb_customer_care_task`
      FOR EACH ROW
      BEGIN
        SET @care_release_lock=GET_LOCK('customer_care_test_release',30);
        IF @care_release_lock<>1 THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CARE_TEST_RELEASE_LOCK_TIMEOUT';
        END IF;
        SET @care_release_released=RELEASE_LOCK('customer_care_test_release');
      END");

    $workerA = careConcurrencyStartWorker('ROLLBACK_A');
    $rollbackWorkerAConnectionId = 0;
    careConcurrencyPoll(function () use (
        $pdo,
        &$workerA,
        &$rollbackWorkerAConnectionId
    ): bool {
        careConcurrencyAssertWorkerRunning($workerA, 'ROLLBACK_A');
        $rollbackWorkerAConnectionId = careConcurrencyWorkerConnectionId($workerA);
        if ($rollbackWorkerAConnectionId <= 0) {
            return false;
        }
        $statement = $pdo->prepare(
            'SELECT STATE, INFO FROM information_schema.PROCESSLIST WHERE ID=?'
        );
        $statement->execute([$rollbackWorkerAConnectionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || strcasecmp((string)($row['STATE'] ?? ''), 'User lock') !== 0) {
            return false;
        }
        $info = (string)($row['INFO'] ?? '');
        return stripos($info, 'customer_care_task') !== false
            || stripos($info, 'customer_care_test_release') !== false;
    }, 'ROLLBACK_WORKER_A_NOT_PROVEN_WAITING_IN_TASK_TRIGGER');

    $pdo->exec("CREATE TRIGGER `care_test_fail_first_operation`
      BEFORE INSERT ON `eb_customer_care_operation`
      FOR EACH ROW
      BEGIN
        IF CONNECTION_ID()={$rollbackWorkerAConnectionId} THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CARE_TEST_FIRST_TRANSACTION_FAILURE';
        END IF;
      END");

    $workerB = careConcurrencyStartWorker('ROLLBACK_B');
    $rollbackWorkerBConnectionId = 0;
    careConcurrencyPoll(function () use (
        $pdo,
        &$workerB,
        &$rollbackWorkerBConnectionId,
        $rollbackWorkerAConnectionId
    ): bool {
        careConcurrencyAssertWorkerRunning($workerB, 'ROLLBACK_B');
        $rollbackWorkerBConnectionId = careConcurrencyWorkerConnectionId($workerB);
        if ($rollbackWorkerBConnectionId <= 0) {
            return false;
        }
        $statement = $pdo->prepare("SELECT COUNT(*)
          FROM information_schema.INNODB_LOCK_WAITS waits
          JOIN information_schema.INNODB_LOCKS requested
            ON requested.lock_id=waits.requested_lock_id
          JOIN information_schema.INNODB_TRX requester
            ON requester.trx_id=waits.requesting_trx_id
          JOIN information_schema.INNODB_TRX blocker
            ON blocker.trx_id=waits.blocking_trx_id
          WHERE requester.trx_mysql_thread_id=?
            AND blocker.trx_mysql_thread_id=?
            AND requested.lock_table LIKE '%eb_cashier_v3_command_receipt%'
            AND requested.lock_index='uk_idempotency_key'");
        $statement->execute([
            $rollbackWorkerBConnectionId,
            $rollbackWorkerAConnectionId,
        ]);
        return (int)$statement->fetchColumn() === 1;
    }, 'ROLLBACK_WORKER_B_NOT_PROVEN_BLOCKED_ON_RECEIPT_UNIQUE_KEY');

    $released = (int)$pdo->query(
        "SELECT RELEASE_LOCK('customer_care_test_release')"
    )->fetchColumn();
    $releaseHeld = false;
    if ($released !== 1) {
        throw new RuntimeException('ROLLBACK_COORDINATOR_RELEASE_FAILED');
    }

    $failedWorkerA = careConcurrencyFinishWorker($workerA);
    $workerA = null;
    $takeoverResult = careConcurrencyWorkerResult(careConcurrencyFinishWorker($workerB));
    $workerB = null;
    careConcurrencyOk(
        'first transaction fails after the second worker is already waiting',
        $failedWorkerA['exitCode'] !== 0
            && strpos($failedWorkerA['stderr'], 'CARE_TEST_FIRST_TRANSACTION_FAILURE') !== false
    );
    careConcurrencyOk(
        'blocked worker takes over as executor after the first receipt rolls back',
        $takeoverResult['replayed'] === false
            && (int)$takeoverResult['operationId'] > 0
    );
    careConcurrencyOk(
        'rollback takeover still creates one receipt task and operation',
        careConcurrencyCount(
            $pdo,
            'SELECT COUNT(*) FROM `eb_cashier_v3_command_receipt`'
                . ' WHERE `idempotency_key`=? AND `status`=1',
            $receiptKey
        ) === 1
            && careConcurrencyCount(
                $pdo,
                'SELECT COUNT(*) FROM `eb_customer_care_task` WHERE `task_key`=?',
                $command['taskKey']
            ) === 1
            && careConcurrencyCount(
                $pdo,
                'SELECT COUNT(*) FROM `eb_customer_care_operation`'
                    . ' WHERE `command_idempotency_key`=?',
                $command['idempotencyKey']
            ) === 1
    );
} catch (Throwable $throwable) {
    $workerDetails = $workerA === null ? '' : ' workerA=' . careConcurrencyReadWorkerDiagnostics($workerA);
    $workerDetails .= $workerB === null ? '' : ' workerB=' . careConcurrencyReadWorkerDiagnostics($workerB);
    fwrite(STDERR, 'CARE_CONCURRENCY_SNAPSHOT=' . careConcurrencySnapshot($pdo) . "\n");
    careConcurrencyOk(
        'deterministic two-process concurrency coordinator',
        false,
        get_class($throwable) . ':' . $throwable->getMessage() . $workerDetails
    );
} finally {
    if ($releaseHeld) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('customer_care_test_release')");
        } catch (Throwable $ignored) {
        }
    }
    careConcurrencyTerminateWorker($workerA);
    careConcurrencyTerminateWorker($workerB);
    try {
        $pdo->exec('DROP TRIGGER IF EXISTS `care_test_pause_task`');
        $pdo->exec('DROP TRIGGER IF EXISTS `care_test_fail_first_operation`');
    } catch (Throwable $ignored) {
    }
}

echo "ASSERT_PASSED={$careConcurrencyPassed}\n";
echo "ASSERT_FAILED={$careConcurrencyFailed}\n";
if ($careConcurrencyFailed > 0) {
    exit(1);
}
echo "CUSTOMER_CARE_MYSQL_CONCURRENCY_INTEGRATION=PASS\n";
