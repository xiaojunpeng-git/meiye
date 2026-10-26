<?php

require __DIR__ . '/mysql-fixture.php';

use app\services\customer\care\CustomerCareDomainException;
use app\services\customer\care\CustomerCareErrorCode;
use think\facade\Db;

$careMysqlPassed = 0;
$careMysqlFailed = 0;

function careMysqlIntegrationOk(string $name, bool $condition, string $detail = ''): void
{
    global $careMysqlPassed, $careMysqlFailed;
    if ($condition) {
        $careMysqlPassed++;
        echo "PASS {$name}\n";
        return;
    }
    $careMysqlFailed++;
    echo 'FAIL ' . $name . ($detail === '' ? '' : ' -> ' . $detail) . "\n";
}

careMysqlBoot();
careMysqlEnsureLegacySchema();

echo "== real ThinkPHP repository command coverage ==\n";
careMysqlResetDomain();
$service = careMysqlService();
$actor = careMysqlActor();

$createdOne = $service->createTask($actor, careMysqlCreateTaskCommand(1));
$createReplay = $service->createTask($actor, careMysqlCreateTaskCommand(1));
$startedOne = $service->startTask($actor, [
    'idempotencyKey' => careMysqlIdem('START_TASK', 2),
    'taskId' => $createdOne['taskId'],
    'expectedVersion' => $createdOne['taskVersion'],
]);
$completeOneCommand = array_merge([
    'idempotencyKey' => careMysqlIdem('COMPLETE_TASK', 3),
    'taskId' => $createdOne['taskId'],
    'expectedVersion' => $startedOne['taskVersion'],
    'createNextTask' => true,
    'nextPlannedAt' => 1785373200,
    'nextOwnerId' => 102,
], careMysqlRecordContent(3));
$completedOne = $service->completeTask($actor, $completeOneCommand);
$completedOneReplay = $service->completeTask($actor, $completeOneCommand);

$createdTwo = $service->createTask($actor, careMysqlCreateTaskCommand(10));
$deletedTwo = $service->deleteTask($actor, [
    'idempotencyKey' => careMysqlIdem('DELETE_TASK', 11),
    'taskId' => $createdTwo['taskId'],
    'expectedVersion' => $createdTwo['taskVersion'],
    'reason' => '真实仓储删除审计',
]);

$createdThree = $service->createTask($actor, careMysqlCreateTaskCommand(20));
$reassignedThree = $service->reassignTask(careMysqlActor(900), [
    'idempotencyKey' => careMysqlIdem('REASSIGN_TASK', 21),
    'taskId' => $createdThree['taskId'],
    'expectedVersion' => $createdThree['taskVersion'],
    'targetStaffId' => 102,
    'reason' => '真实仓储转派审计',
]);
$startedThree = $service->startTask(careMysqlActor(102), [
    'idempotencyKey' => careMysqlIdem('START_TASK', 22),
    'taskId' => $createdThree['taskId'],
    'expectedVersion' => $reassignedThree['taskVersion'],
]);
$voidedThree = $service->voidTask(careMysqlActor(102), [
    'idempotencyKey' => careMysqlIdem('VOID_TASK', 23),
    'taskId' => $createdThree['taskId'],
    'expectedVersion' => $startedThree['taskVersion'],
    'reason' => '真实仓储作废审计',
]);

$standaloneCommand = careMysqlCreateRecordCommand(30);
$standalone = $service->createRecord($actor, $standaloneCommand);
$voidedStandalone = $service->voidRecord($actor, [
    'idempotencyKey' => careMysqlIdem('VOID_RECORD', 31),
    'recordId' => $standalone['recordId'],
    'expectedRecordVersion' => $standalone['recordVersion'],
    'reason' => '真实仓储记录作废审计',
]);

$operationTypes = Db::name('customer_care_operation')
    ->distinct(true)
    ->order('operation_type', 'asc')
    ->column('operation_type');
$expectedOperationTypes = [
    'COMPLETE_TASK','CREATE_RECORD','CREATE_TASK','DELETE_TASK',
    'REASSIGN_TASK','START_TASK','VOID_RECORD','VOID_TASK',
];
sort($operationTypes);
careMysqlIntegrationOk('all eight command methods use the production repository',
    $operationTypes === $expectedOperationTypes,
    json_encode($operationTypes));
careMysqlIntegrationOk('sequential replay returns the first operation without duplicate rows',
    $createReplay['replayed'] === true
        && $createReplay['operationId'] === $createdOne['operationId']
        && (int)Db::name('customer_care_operation')->count() === 11
        && (int)Db::name('cashier_v3_command_receipt')
            ->whereLike('idempotency_key', 'CARE_RECEIPT-%')->count() === 11);
careMysqlIntegrationOk('task state visibility reassignment and void persist through MySQL CAS',
    $completedOne['taskStatus'] === 'COMPLETED'
        && $deletedTwo['isVisible'] === false
        && $reassignedThree['ownerStaffId'] === 102
        && $voidedThree['taskStatus'] === 'VOIDED');
careMysqlIntegrationOk('completion creates one linked next task and replay returns it unchanged',
    $completedOne['nextTaskId'] > 0
        && $completedOne['nextTaskVersion'] === 1
        && $completedOneReplay['replayed'] === true
        && $completedOneReplay['nextTaskId'] === $completedOne['nextTaskId']
        && Db::name('customer_care_task')->where('id', $completedOne['nextTaskId'])
            ->where('source_type', 'PREVIOUS_FOLLOWUP')->where('owner_staff_id', 102)->count() === 1
        && Db::name('customer_care_record')->where('id', $completedOne['recordId'])
            ->where('requires_next_followup', 1)
            ->where('next_task_id', $completedOne['nextTaskId'])->count() === 1
        && Db::name('customer_care_operation')->where('id', $completedOne['operationId'])
            ->where('next_task_id_after', $completedOne['nextTaskId'])
            ->where('next_task_version_after', 1)->count() === 1);
$completeReceiptKey = careMysqlReceiptKey('TENANT_1', $completeOneCommand['idempotencyKey']);
$completeReceipt = Db::name('cashier_v3_command_receipt')
    ->where('idempotency_key', $completeReceiptKey)->find();
$completeResultJson = is_array($completeReceipt) ? (string)$completeReceipt['result_json'] : '';
$corruptedNextResult = json_decode($completeResultJson, true);
$corruptedNextReplayRejected = false;
if (is_array($corruptedNextResult)) {
    $corruptedNextResult['next_task_id_after'] = $completedOne['nextTaskId'] + 1000;
    Db::name('cashier_v3_command_receipt')->where('idempotency_key', $completeReceiptKey)->update([
        'result_json' => json_encode(
            $corruptedNextResult,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
    ]);
    try {
        careMysqlService()->completeTask($actor, $completeOneCommand);
    } catch (CustomerCareDomainException $exception) {
        $corruptedNextReplayRejected = $exception->getErrorCode()
            === CustomerCareErrorCode::REPOSITORY_CONFLICT;
    } finally {
        Db::name('cashier_v3_command_receipt')->where('idempotency_key', $completeReceiptKey)->update([
            'result_json' => $completeResultJson,
        ]);
    }
}
careMysqlIntegrationOk(
    'corrupted replay next-task identity fails against the immutable operation',
    $corruptedNextReplayRejected
);
careMysqlIntegrationOk('standalone record remains taskless and is voided append-only',
    $standalone['taskId'] === 0
        && $voidedStandalone['recordStatus'] === 'VOIDED'
        && Db::name('customer_care_record')->where('id', $standalone['recordId'])
            ->whereNull('task_id')->where('version', 2)->count() === 1);
$startReceipt = Db::name('cashier_v3_command_receipt')
    ->where('idempotency_key', careMysqlReceiptKey('TENANT_1', careMysqlIdem('START_TASK', 2)))
    ->find();
$startContext = is_array($startReceipt)
    ? json_decode((string)$startReceipt['contexts_json'], true)
    : null;
careMysqlIntegrationOk('shared receipt freezes normalized resource context and matching hash',
    is_array($startReceipt)
        && preg_match('/^CARE_CONTEXT-[0-9a-f-]{36}$/D', (string)$startReceipt['state_context_id']) === 1
        && is_array($startContext)
        && $startContext['taskId'] === $createdOne['taskId']
        && $startContext['expectedVersion'] === $createdOne['taskVersion']
        && hash_equals(
            (string)$startReceipt['contexts_hash'],
            hash('sha256', (string)$startReceipt['contexts_json'])
        ));
$createReceiptKey = careMysqlReceiptKey('TENANT_1', careMysqlCreateTaskCommand(1)['idempotencyKey']);
$createReceipt = Db::name('cashier_v3_command_receipt')
    ->where('idempotency_key', $createReceiptKey)->find();
$originalResultJson = is_array($createReceipt) ? (string)$createReceipt['result_json'] : '';
$corruptedResult = json_decode($originalResultJson, true);
$corruptedReplayRejected = false;
if (is_array($corruptedResult)) {
    $corruptedResult['actor_staff_id'] = 999;
    Db::name('cashier_v3_command_receipt')->where('idempotency_key', $createReceiptKey)->update([
        'result_json' => json_encode($corruptedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    try {
        careMysqlService()->createTask($actor, careMysqlCreateTaskCommand(1));
    } catch (CustomerCareDomainException $exception) {
        $corruptedReplayRejected = $exception->getErrorCode()
            === CustomerCareErrorCode::REPOSITORY_CONFLICT;
    } finally {
        Db::name('cashier_v3_command_receipt')->where('idempotency_key', $createReceiptKey)->update([
            'result_json' => $originalResultJson,
        ]);
    }
}
careMysqlIntegrationOk('corrupted replay operation identity fails closed', $corruptedReplayRejected);

echo "== rollback then same-key retry ==\n";
careMysqlResetDomain();
$rollbackCommand = careMysqlCreateTaskCommand(8001);
$rollbackReceiptKey = careMysqlReceiptKey('TENANT_1', $rollbackCommand['idempotencyKey']);
Db::execute('DROP TRIGGER IF EXISTS `care_test_fail_operation`');
Db::execute("CREATE TRIGGER `care_test_fail_operation`
  BEFORE INSERT ON `eb_customer_care_operation`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CARE_TEST_OPERATION_FAILURE'");
$rollbackFailed = false;
try {
    careMysqlService()->createTask($actor, $rollbackCommand);
} catch (Throwable $throwable) {
    $rollbackFailed = strpos($throwable->getMessage(), 'CARE_TEST_OPERATION_FAILURE') !== false;
} finally {
    Db::execute('DROP TRIGGER IF EXISTS `care_test_fail_operation`');
}
careMysqlIntegrationOk('operation failure reaches the real transaction boundary', $rollbackFailed);
careMysqlIntegrationOk('failed command rolls back receipt task and operation together',
    Db::name('cashier_v3_command_receipt')->where('idempotency_key', $rollbackReceiptKey)->count() === 0
        && Db::name('customer_care_task')->where('task_key', $rollbackCommand['taskKey'])->count() === 0
        && Db::name('customer_care_operation')
            ->where('command_idempotency_key', $rollbackCommand['idempotencyKey'])->count() === 0);
$retryResult = careMysqlService()->createTask($actor, $rollbackCommand);
$retryReplay = careMysqlService()->createTask($actor, $rollbackCommand);
careMysqlIntegrationOk('same idempotency key succeeds after rollback and then replays',
    $retryResult['replayed'] === false
        && $retryReplay['replayed'] === true
        && $retryReplay['operationId'] === $retryResult['operationId']
        && Db::name('cashier_v3_command_receipt')->where('idempotency_key', $rollbackReceiptKey)
            ->where('status', 1)->count() === 1
        && Db::name('customer_care_task')->where('task_key', $rollbackCommand['taskKey'])->count() === 1
        && Db::name('customer_care_operation')
            ->where('command_idempotency_key', $rollbackCommand['idempotencyKey'])->count() === 1);

echo "== completion next-task atomic rollback and retry ==\n";
careMysqlResetDomain();
$atomicSource = careMysqlService()->createTask($actor, careMysqlCreateTaskCommand(8100));
$atomicStarted = careMysqlService()->startTask($actor, [
    'idempotencyKey' => careMysqlIdem('START_TASK', 8101),
    'taskId' => $atomicSource['taskId'],
    'expectedVersion' => $atomicSource['taskVersion'],
]);
$atomicCompleteCommand = array_merge([
    'idempotencyKey' => careMysqlIdem('COMPLETE_TASK', 8102),
    'taskId' => $atomicSource['taskId'],
    'expectedVersion' => $atomicStarted['taskVersion'],
    'createNextTask' => true,
    'nextPlannedAt' => 1785373200,
    'nextOwnerId' => 102,
], careMysqlRecordContent(8102));
$atomicNextIdentity = careMysqlNextTaskIdentity(
    'TENANT_1',
    $atomicCompleteCommand['idempotencyKey']
);
$atomicReceiptKey = careMysqlReceiptKey(
    'TENANT_1',
    $atomicCompleteCommand['idempotencyKey']
);
Db::execute('DROP TRIGGER IF EXISTS `care_test_fail_operation`');
Db::execute("CREATE TRIGGER `care_test_fail_operation`
  BEFORE INSERT ON `eb_customer_care_operation`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CARE_TEST_ATOMIC_COMPLETION_FAILURE'");
$atomicCompletionFailed = false;
try {
    careMysqlService()->completeTask($actor, $atomicCompleteCommand);
} catch (Throwable $throwable) {
    $atomicCompletionFailed = strpos(
        $throwable->getMessage(),
        'CARE_TEST_ATOMIC_COMPLETION_FAILURE'
    ) !== false;
} finally {
    Db::execute('DROP TRIGGER IF EXISTS `care_test_fail_operation`');
}
careMysqlIntegrationOk('operation failure aborts completion after next task and record writes',
    $atomicCompletionFailed);
careMysqlIntegrationOk('failed completion rolls back current state next task record operation and receipt',
    Db::name('customer_care_task')->where('id', $atomicSource['taskId'])
        ->where('status', 'IN_PROGRESS')->where('version', 2)->count() === 1
        && Db::name('customer_care_task')->where('task_key', $atomicNextIdentity['taskKey'])->count() === 0
        && Db::name('customer_care_record')
            ->where('record_key', $atomicCompleteCommand['recordKey'])->count() === 0
        && Db::name('customer_care_operation')
            ->where('command_idempotency_key', $atomicCompleteCommand['idempotencyKey'])->count() === 0
        && Db::name('cashier_v3_command_receipt')
            ->where('idempotency_key', $atomicReceiptKey)->count() === 0);
$atomicCompleted = careMysqlService()->completeTask($actor, $atomicCompleteCommand);
$atomicReplay = careMysqlService()->completeTask($actor, $atomicCompleteCommand);
careMysqlIntegrationOk('same completion key retries atomically and replays the same next task',
    $atomicCompleted['replayed'] === false
        && $atomicReplay['replayed'] === true
        && $atomicReplay['operationId'] === $atomicCompleted['operationId']
        && $atomicReplay['nextTaskId'] === $atomicCompleted['nextTaskId']
        && Db::name('customer_care_task')->where('task_key', $atomicNextIdentity['taskKey'])->count() === 1
        && Db::name('customer_care_record')
            ->where('record_key', $atomicCompleteCommand['recordKey'])
            ->where('next_task_id', $atomicCompleted['nextTaskId'])->count() === 1
        && Db::name('customer_care_operation')
            ->where('command_idempotency_key', $atomicCompleteCommand['idempotencyKey'])->count() === 1
        && Db::name('cashier_v3_command_receipt')->where('idempotency_key', $atomicReceiptKey)
            ->where('status', 1)->count() === 1);

echo "ASSERT_PASSED={$careMysqlPassed}\n";
echo "ASSERT_FAILED={$careMysqlFailed}\n";
if ($careMysqlFailed > 0) {
    exit(1);
}
echo "CUSTOMER_CARE_MYSQL_REPOSITORY_INTEGRATION=PASS\n";
