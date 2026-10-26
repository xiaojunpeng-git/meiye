<?php
/** Unified business-event and Outbox integration gates. */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\event\CashierV3EventConsumerRegistry;
use app\services\cashier\v3\event\CashierV3OutboxServices;
use think\facade\Db;

$app = c1aBootThinkApp('/var/www/html/');

function eoContract(string $action, string $type, array $consumers = []): array
{
    return [
        'required_event_types' => [$type],
        'allowed_event_types' => [$type],
        'event_rules' => [
            $type => [
                'min_count' => 1,
                'max_count' => 1,
                'aggregate_type' => 'test_aggregate',
                'source_type' => $action,
                'aggregate_version' => 1,
            ],
        ],
        'eventless_reason' => '',
        'consumers' => [$type => $consumers],
    ];
}

function eoEvent(string $type, string $id, int $memberId = 0): array
{
    $now = time();
    return [
        'event_type' => $type,
        'event_version' => 1,
        'aggregate_type' => 'test_aggregate',
        'aggregate_id' => $id,
        'aggregate_version' => 1,
        'member_id' => $memberId,
        'occurred_at' => $now,
        'settled_at' => $now,
        'aggregate_name_snapshot' => '测试聚合',
        'store_name_snapshot' => '测试门店',
        'payload' => ['aggregate_id' => $id, 'amount' => 100],
    ];
}

/** @return array<string,int> */
function eoCounts(): array
{
    $counts = [];
    foreach ([
        'c1a_event_probe',
        'cashier_v3_business_event',
        'cashier_v3_outbox',
        'cashier_v3_outbox_attempt',
        'cashier_v3_consumer_once',
        'cashier_v3_command_receipt',
        'cashier_v3_resource_version',
    ] as $table) {
        $counts[$table] = (int)Db::name($table)->count();
    }
    return $counts;
}

function eoThrowableChainContains(Throwable $throwable, string $needle): bool
{
    for ($cursor = $throwable; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if (strpos($cursor->getMessage(), $needle) !== false) {
            return true;
        }
    }
    return false;
}

try {
    foreach (['cashier_v3_consumer_once', 'cashier_v3_outbox_attempt', 'cashier_v3_outbox', 'cashier_v3_business_event'] as $table) {
        Db::execute('DELETE FROM `eb_' . $table . '`');
    }
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_c1a_event_probe` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `probe_key` varchar(64) NOT NULL,
      PRIMARY KEY (`id`), UNIQUE KEY `uk_probe` (`probe_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute('TRUNCATE TABLE `eb_c1a_event_probe`');

    // Server-side organization snapshot: 1 -> 2 -> 3, store 8 belongs to leaf 3.
    Db::execute("REPLACE INTO `eb_organization` (`id`,`pid`,`name`,`is_del`) VALUES
      (1,0,'集团',0),(2,1,'事业部',0),(3,2,'华东门店组',0)");
    Db::execute("DELETE FROM `eb_organization_store` WHERE `store_id`=8");
    Db::execute("INSERT INTO `eb_organization_store` (`org_id`,`store_id`) VALUES (3,8)");
    Db::execute("REPLACE INTO `eb_system_store_staff`
      (`id`,`store_id`,`employee_id`,`account`,`staff_name`,`roles`,`level`,`status`,`is_del`)
      VALUES (1,8,1,'event-tester','事件测试员','1',1,1,0)");

    $operator = new CashierV3OperatorScope(8, 1, '3', '0');
    $dataScope = new CashierV3DataScopeContext(
        1,
        1,
        8,
        '0',
        '3',
        [8],
        CashierV3DataScopeContext::MODE_STORES,
        [],
        false,
        '',
        'eo-test',
        ['cashier.v3.member', 'cashier.v3.management_center'],
        ['id' => 1, 'employee_id' => 1, 'staff_name' => '事件测试员', 'account' => 'event-tester']
    );
    $recorder = new CashierV3BusinessEventRecorder();

    $outsideExecution = $recorder->newExecution('test-event-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
    rejects(
        '事务外事件写入 fail-closed',
        CashierV3ResultCode::COMMAND_TRANSACTION_REQUIRED,
        function () use ($recorder, $outsideExecution) {
            $recorder->recordInTx(
                $outsideExecution,
                eoContract('test-event-action', 'test.recorded'),
                eoEvent('test.recorded', 'outside')
            );
        },
        '',
        'EO-14-01'
    );

    $missingExecution = $recorder->newExecution('test-event-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
    rejects(
        'required event 缺失时合同拒绝',
        CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING,
        function () use ($recorder, $missingExecution) {
            Db::transaction(function () use ($recorder, $missingExecution) {
                $recorder->assertRequiredPersistedInTx(
                    $missingExecution,
                    eoContract('test-event-action', 'test.recorded')
                );
            });
        },
        'required_event_missing',
        'EO-14-01'
    );

    $commandKey = 'CMD-' . uuid();
    $aggregateId = 'EO-' . uuid();
    $persisted = Db::transaction(function () use ($recorder, $operator, $dataScope, $commandKey, $aggregateId) {
        $execution = $recorder->newExecution('test-event-action', $commandKey, $operator, $dataScope, 'SC-EO');
        $contract = eoContract('test-event-action', 'test.recorded', ['test.consumer']);
        $row = $recorder->recordInTx($execution, $contract, eoEvent('test.recorded', $aggregateId, 99));
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        return $row;
    });
    $event = Db::name('cashier_v3_business_event')->where('id', $persisted['event_id'])->find();
    $outbox = Db::name('cashier_v3_outbox')->where('event_id', $persisted['event_id'])->find();
    ok(
        '统一事件与真实消费者 Outbox 同事务落库',
        (bool)$event && (bool)$outbox
            && (string)$event['command_idempotency_key'] === $commandKey
            && (int)$event['aggregate_version'] === 1
            && (string)$outbox['consumer_code'] === 'test.consumer'
            && (int)$outbox['status'] === CashierV3OutboxServices::STATUS_PENDING,
        json_encode(['event' => $event, 'outbox' => $outbox], JSON_UNESCAPED_UNICODE),
        'EO-14-02'
    );
    ok(
        '四类时间与事件/Outbox显式权限维度完整',
        (int)$event['occurred_at'] > 0
            && (int)$event['settled_at'] > 0
            && (int)$event['recorded_at'] > 0
            && (string)$event['business_date'] !== ''
            && (string)$event['tenant_id'] === '0'
            && (string)$event['organization_id'] === '3'
            && (string)$event['organization_path'] === '1/2/3'
            && (string)$event['organization_name_snapshot'] === '华东门店组'
            && (string)$event['operator_name_snapshot'] === '事件测试员'
            && (int)$event['store_id'] === 8
            && (int)$event['member_id'] === 99
            && (int)$event['operator_id'] === 1
            && (string)$outbox['organization_path'] === '1/2/3'
            && (int)$outbox['member_id'] === 99
            && (int)$outbox['operator_id'] === 1,
        json_encode(['event' => $event, 'outbox' => $outbox], JSON_UNESCAPED_UNICODE),
        'EO-14-03'
    );

    $beforeRollback = eoCounts();
    $forcedRollbackCaught = false;
    try {
        Db::transaction(function () use ($recorder, $operator, $dataScope) {
            Db::name('c1a_event_probe')->insert(['probe_key' => 'rollback-' . uuid()]);
            $execution = $recorder->newExecution('test-rollback-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
            $contract = eoContract('test-rollback-action', 'test.rollback', ['test.consumer']);
            $recorder->recordInTx($execution, $contract, eoEvent('test.rollback', 'ROLL-' . uuid()));
            throw new RuntimeException('FORCE_ROLLBACK');
        });
    } catch (RuntimeException $expected) {
        $forcedRollbackCaught = $expected->getMessage() === 'FORCE_ROLLBACK';
    }
    ok(
        '业务异常时同事务探针、事件与Outbox全量回滚且其他基线不变',
        $forcedRollbackCaught && eoCounts() === $beforeRollback,
        json_encode(['before' => $beforeRollback, 'after' => eoCounts()]),
        'EO-14-04'
    );

    Db::execute('DROP TRIGGER IF EXISTS `eo_fail_outbox_insert`');
    Db::execute("CREATE TRIGGER `eo_fail_outbox_insert` BEFORE INSERT ON `eb_cashier_v3_outbox`
      FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='FORCE_OUTBOX_INSERT_FAILURE'");
    $beforeOutboxFailure = eoCounts();
    $outboxFailureCaught = false;
    try {
        Db::transaction(function () use ($recorder, $operator, $dataScope) {
            Db::name('c1a_event_probe')->insert(['probe_key' => 'outbox-failure-' . uuid()]);
            $execution = $recorder->newExecution('test-outbox-failure', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
            $contract = eoContract('test-outbox-failure', 'test.outbox_failure', ['test.consumer']);
            $recorder->recordInTx($execution, $contract, eoEvent('test.outbox_failure', 'FAIL-' . uuid()));
        });
    } catch (Throwable $expected) {
        $outboxFailureCaught = eoThrowableChainContains($expected, 'FORCE_OUTBOX_INSERT_FAILURE');
    }
    Db::execute('DROP TRIGGER IF EXISTS `eo_fail_outbox_insert`');
    ok(
        'Outbox插入失败时同事务所有写入回滚',
        $outboxFailureCaught && eoCounts() === $beforeOutboxFailure,
        json_encode(['caught' => $outboxFailureCaught, 'before' => $beforeOutboxFailure, 'after' => eoCounts()]),
        'EO-14-04'
    );

    $consumerEffects = [];
    $consumerCodes = [
        'test.consumer',
        'retry.consumer',
        'bad.consumer',
        'starve.consumer',
        'effect.consumer',
        'manual.consumer',
        'expiry.consumer',
        'crash.consumer',
    ];
    $consumerRegistry = new CashierV3EventConsumerRegistry();
    foreach ($consumerCodes as $consumerCode) {
        $consumerRegistry->register(
            $consumerCode,
            function (array $event) use (&$consumerEffects, $consumerCode): bool {
                $effect = $consumerEffects[$consumerCode] ?? null;
                return is_callable($effect) ? $effect($event) : true;
            }
        );
    }
    $consumerRegistry->freeze();
    $outboxService = new CashierV3OutboxServices($consumerRegistry);
    $consumeMethod = new ReflectionMethod(CashierV3OutboxServices::class, 'consumeOnceInTx');
    $consumeParameters = $consumeMethod->getParameters();
    $reflectedMetadataType = isset($consumeParameters[2]) ? $consumeParameters[2]->getType() : null;
    $metadataType = $reflectedMetadataType instanceof ReflectionNamedType
        ? $reflectedMetadataType->getName()
        : '';
    ok(
        'Outbox 使用注入的冻结消费者目录且公开消费接口不接受任意 callback',
        $outboxService->consumerRegistry() === $consumerRegistry
            && $consumerRegistry->isFrozen()
            && is_callable($consumerRegistry->requireConsumer('test.consumer'))
            && count($consumeParameters) === 3
            && (string)$consumeParameters[0]->getName() === 'lease'
            && (string)$consumeParameters[1]->getName() === 'effectKey'
            && (string)$consumeParameters[2]->getName() === 'metadata'
            && $metadataType === 'array',
        json_encode([
            'consumers' => $consumerRegistry->codes(),
            'parameters' => array_map(function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            }, $consumeParameters),
            'metadataType' => $metadataType,
        ]),
        'EO-14-14'
    );
    $unknownConsumerRejected = false;
    try {
        $outboxService->claim('unknown.consumer', 'worker-unknown', 1);
    } catch (Throwable $expected) {
        $unknownConsumerRejected = $expected instanceof \app\services\cashier\v3\CashierV3CommandException
            && $expected->getResultCode() === CashierV3ResultCode::OUTBOX_STATE_INVALID
            && (string)($expected->getDetail()['reason'] ?? '') === 'consumer_not_registered';
    }
    ok(
        '未注册消费者fail-closed而不是静默返回空队列',
        $unknownConsumerRejected,
        '',
        'EO-14-14'
    );
    $forcedDbNow = time() + 3600;
    Db::execute('SET timestamp=' . $forcedDbNow);
    $claimed = $outboxService->claim('test.consumer', 'worker-a', 1);
    $leaseA = $claimed[0] ?? [];
    $renewed = $outboxService->renew($leaseA);
    $renewedRow = Db::name('cashier_v3_outbox')->where('id', (int)($leaseA['id'] ?? 0))->find();
    Db::execute('SET timestamp=0');
    $attemptOps = Db::name('cashier_v3_outbox_attempt')
        ->where('outbox_id', (int)($leaseA['id'] ?? 0))
        ->order('id asc')
        ->column('operation');
    ok(
        '租约使用数据库时间、递增generation并追加claim/renew审计',
        count($claimed) === 1
            && $renewed
            && (int)($leaseA['status'] ?? 0) === CashierV3OutboxServices::STATUS_PROCESSING
            && (int)($leaseA['fencing_generation'] ?? 0) === 1
            && (int)($renewedRow['lease_until'] ?? 0) >= $forcedDbNow + CashierV3OutboxServices::LEASE_SECONDS
            && (int)($renewedRow['lease_until'] ?? 0) > time() + 3000
            && array_values($attemptOps) === ['claim', 'renew'],
        json_encode(['claimed' => $leaseA, 'renewed' => $renewedRow, 'ops' => $attemptOps]),
        'EO-14-05'
    );

    Db::name('cashier_v3_outbox')->where('id', (int)$leaseA['id'])->update(['lease_until' => 0]);
    $claimedAgain = $outboxService->claim('test.consumer', 'worker-b', 1);
    $leaseB = $claimedAgain[0] ?? [];
    $fenceFields = ['id', 'event_id', 'consumer_code', 'status', 'attempts', 'lease_until', 'lease_owner', 'lease_token', 'fencing_generation'];
    $beforeFenceRow = Db::name('cashier_v3_outbox')->where('id', (int)$leaseB['id'])->find();
    $forgedResults = [];
    foreach (['owner', 'token', 'generation', 'event_id', 'consumer_code'] as $forgedField) {
        $forged = $leaseB;
        if ($forgedField === 'owner') {
            $forged['lease_owner'] = 'worker-forged';
        } elseif ($forgedField === 'token') {
            $forged['lease_token'] = str_repeat('f', 32);
        } elseif ($forgedField === 'generation') {
            $forged['fencing_generation'] = (int)$forged['fencing_generation'] + 1;
        } elseif ($forgedField === 'event_id') {
            $forged['event_id'] = (int)$forged['event_id'] + 1;
        } else {
            $forged['consumer_code'] = 'forged.consumer';
        }
        $forgedResults[$forgedField] = $outboxService->renew($forged);
    }
    $afterFenceRow = Db::name('cashier_v3_outbox')->where('id', (int)$leaseB['id'])->find();
    $beforeFenceState = array_intersect_key($beforeFenceRow, array_flip($fenceFields));
    $afterFenceState = array_intersect_key($afterFenceRow, array_flip($fenceFields));
    ok(
        'owner/token/generation/event/consumer任一伪造都被独立拒绝且租约状态不变',
        !in_array(true, $forgedResults, true) && $beforeFenceState === $afterFenceState,
        json_encode(['results' => $forgedResults, 'before' => $beforeFenceState, 'after' => $afterFenceState]),
        'EO-14-15'
    );
    $beforeLateRow = Db::name('cashier_v3_outbox')->where('id', (int)$leaseB['id'])->find();
    $lateRenew = $outboxService->renew($leaseA);
    $lateFail = $outboxService->fail($leaseA, 'late failure');
    $lateAck = $outboxService->ack($leaseA);
    $staleConsumeRejected = false;
    $consumerEffects['test.consumer'] = function (): bool {
        Db::name('c1a_event_probe')->insert(['probe_key' => 'STALE-MUST-NOT-RUN']);
        return true;
    };
    try {
        Db::transaction(function () use ($outboxService, $leaseA) {
            $outboxService->consumeOnceInTx($leaseA, 'effect-stale');
        });
    } catch (Throwable $expected) {
        $staleConsumeRejected = $expected instanceof \app\services\cashier\v3\CashierV3CommandException
            && $expected->getResultCode() === CashierV3ResultCode::OUTBOX_LEASE_CONFLICT;
    }
    $afterLateRow = Db::name('cashier_v3_outbox')->where('id', (int)$leaseB['id'])->find();
    ok(
        '租约接管后旧generation晚renew/fail/ack/副作用全部拒绝',
        count($claimedAgain) === 1
            && (int)($leaseB['fencing_generation'] ?? 0) === 2
            && !$lateRenew && !$lateFail && !$lateAck && $staleConsumeRejected
            && array_intersect_key($beforeLateRow, array_flip($fenceFields))
                === array_intersect_key($afterLateRow, array_flip($fenceFields))
            && (int)Db::name('c1a_event_probe')->where('probe_key', 'STALE-MUST-NOT-RUN')->count() === 0,
        json_encode(['old' => $leaseA, 'new' => $leaseB]),
        'EO-14-06'
    );

    $expiryPersisted = Db::transaction(function () use ($recorder, $operator, $dataScope) {
        $execution = $recorder->newExecution('test-expiry-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
        $contract = eoContract('test-expiry-action', 'test.expiry', ['expiry.consumer']);
        $row = $recorder->recordInTx($execution, $contract, eoEvent('test.expiry', 'EXPIRY-' . uuid()));
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        return $row;
    });
    $expiryLease = $outboxService->claim('expiry.consumer', 'worker-expiry', 1)[0] ?? [];
    Db::name('cashier_v3_outbox')->where('id', (int)$expiryLease['id'])->update(['lease_until' => 0]);
    $expiredBefore = Db::name('cashier_v3_outbox')->where('id', (int)$expiryLease['id'])->find();
    $expiredRenew = $outboxService->renew($expiryLease);
    $expiredFail = $outboxService->fail($expiryLease, 'expired lease');
    $expiredAck = $outboxService->ack($expiryLease);
    $expiredAfter = Db::name('cashier_v3_outbox')->where('id', (int)$expiryLease['id'])->find();
    ok(
        '仅租约过期尚未接管时所有迟到操作也拒绝且任务状态不变',
        !$expiredRenew && !$expiredFail && !$expiredAck
            && array_intersect_key($expiredBefore, array_flip($fenceFields))
                === array_intersect_key($expiredAfter, array_flip($fenceFields)),
        json_encode(['before' => $expiredBefore, 'after' => $expiredAfter]),
        'EO-14-16'
    );

    $crashPersisted = Db::transaction(function () use ($recorder, $operator, $dataScope) {
        $execution = $recorder->newExecution('test-crash-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
        $contract = eoContract('test-crash-action', 'test.crash', ['crash.consumer']);
        $row = $recorder->recordInTx($execution, $contract, eoEvent('test.crash', 'CRASH-' . uuid()));
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        return $row;
    });
    $crashOutboxId = (int)Db::name('cashier_v3_outbox')
        ->where('event_id', $crashPersisted['event_id'])
        ->value('id');
    Db::name('cashier_v3_outbox')->where('id', $crashOutboxId)->update([
        'status' => CashierV3OutboxServices::STATUS_PROCESSING,
        'attempts' => 7,
        'lease_until' => 0,
        'lease_owner' => 'crashed-worker',
        'lease_token' => str_repeat('c', 32),
        'fencing_generation' => 7,
    ]);
    $eighthClaim = $outboxService->claim('crash.consumer', 'eighth-worker', 1);
    $eighthLease = $eighthClaim[0] ?? [];
    Db::name('cashier_v3_outbox')->where('id', $crashOutboxId)->update(['lease_until' => 0]);
    $crashClaim = $outboxService->claim('crash.consumer', 'recovery-worker', 1);
    $crashRow = Db::name('cashier_v3_outbox')->where('id', $crashOutboxId)->find();
    $crashAudit = Db::name('cashier_v3_outbox_attempt')
        ->where('outbox_id', $crashOutboxId)
        ->where('operation', 'lease_expired_manual_failed')
        ->find();
    ok(
        '允许第8次真实处理，第8次租约再过期才进入MANUAL_FAILED且不签发第9份租约',
        count($eighthClaim) === 1
            && (int)($eighthLease['attempts'] ?? 0) === CashierV3OutboxServices::MAX_ATTEMPTS
            && (int)($eighthLease['fencing_generation'] ?? 0) === 8
            && $crashClaim === []
            && (int)($crashRow['status'] ?? 0) === CashierV3OutboxServices::STATUS_MANUAL_FAILED
            && (int)($crashRow['attempts'] ?? 0) === CashierV3OutboxServices::MAX_ATTEMPTS
            && (int)($crashRow['fencing_generation'] ?? 0) === 9
            && (string)($crashRow['lease_owner'] ?? '') === ''
            && (string)($crashRow['lease_token'] ?? '') === ''
            && (string)($crashAudit['worker_id'] ?? '') === 'recovery-worker'
            && (int)($crashAudit['attempt_no'] ?? 0) === CashierV3OutboxServices::MAX_ATTEMPTS,
        json_encode(['eighthLease' => $eighthLease, 'row' => $crashRow, 'audit' => $crashAudit]),
        'EO-14-09'
    );

    $effectPersisted = Db::transaction(function () use ($recorder, $operator, $dataScope) {
        $execution = $recorder->newExecution('test-effect-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
        $contract = eoContract('test-effect-action', 'test.effect', ['effect.consumer']);
        $row = $recorder->recordInTx($execution, $contract, eoEvent('test.effect', 'EFFECT-' . uuid()));
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        return $row;
    });
    $effectLease = $outboxService->claim('effect.consumer', 'worker-effect', 1)[0] ?? [];
    $callbackInjectionRejected = false;
    $callerCallbackRan = false;
    try {
        Db::transaction(function () use ($outboxService, $effectLease, &$callerCallbackRan) {
            $outboxService->consumeOnceInTx(
                $effectLease,
                'effect-callback-injection',
                function () use (&$callerCallbackRan): bool {
                    $callerCallbackRan = true;
                    Db::name('c1a_event_probe')->insert(['probe_key' => 'CALLER-CALLBACK-MUST-NOT-RUN']);
                    return true;
                }
            );
        });
    } catch (TypeError $expected) {
        $callbackInjectionRejected = true;
    }
    ok(
        '旧式 callback 注入被类型合同拒绝且调用方代码零执行',
        $callbackInjectionRejected
            && !$callerCallbackRan
            && (int)Db::name('c1a_event_probe')
                ->where('probe_key', 'CALLER-CALLBACK-MUST-NOT-RUN')
                ->count() === 0
            && (int)Db::name('cashier_v3_consumer_once')
                ->where('event_id', $effectPersisted['event_id'])
                ->count() === 0,
        '',
        'EO-14-14'
    );
    $falseRejected = false;
    $consumerEffects['effect.consumer'] = function (): bool {
        Db::name('c1a_event_probe')->insert(['probe_key' => 'EFFECT-FALSE']);
        return false;
    };
    try {
        Db::transaction(function () use ($outboxService, $effectLease) {
            $outboxService->consumeOnceInTx($effectLease, 'effect-false');
        });
    } catch (Throwable $expected) {
        $falseRejected = $expected instanceof \app\services\cashier\v3\CashierV3CommandException
            && (string)($expected->getDetail()['reason'] ?? '') === 'consumer_effect_not_confirmed';
    }
    $throwRejected = false;
    $consumerEffects['effect.consumer'] = function (): bool {
        Db::name('c1a_event_probe')->insert(['probe_key' => 'EFFECT-THROW']);
        throw new RuntimeException('EFFECT_CALLBACK_THROW');
    };
    try {
        Db::transaction(function () use ($outboxService, $effectLease) {
            $outboxService->consumeOnceInTx($effectLease, 'effect-throw');
        });
    } catch (Throwable $expected) {
        $throwRejected = eoThrowableChainContains($expected, 'EFFECT_CALLBACK_THROW');
    }
    ok(
        '副作用返回false或抛异常时副作用与consumer_once一起回滚',
        $falseRejected && $throwRejected
            && (int)Db::name('c1a_event_probe')->whereIn('probe_key', ['EFFECT-FALSE', 'EFFECT-THROW'])->count() === 0
            && (int)Db::name('cashier_v3_consumer_once')->where('event_id', $effectPersisted['event_id'])->count() === 0,
        '',
        'EO-14-17'
    );

    $effectKey = 'effect-' . $persisted['event_id'];
    $consumerEffects['test.consumer'] = function () use ($effectKey): bool {
        Db::name('c1a_event_probe')->insert(['probe_key' => $effectKey]);
        return true;
    };
    $onceFirst = Db::transaction(function () use ($outboxService, $leaseB, $effectKey) {
        return $outboxService->consumeOnceInTx($leaseB, $effectKey, ['purpose' => 'crash-before-ack']);
    });
    // Simulate crash after side effect + once commit but before ACK.
    Db::name('cashier_v3_outbox')->where('id', (int)$leaseB['id'])->update(['lease_until' => 0]);
    $leaseC = $outboxService->claim('test.consumer', 'worker-c', 1)[0] ?? [];
    $consumerEffects['test.consumer'] = function (): bool {
        Db::name('c1a_event_probe')->insert(['probe_key' => 'DUPLICATE-MUST-NOT-RUN']);
        return true;
    };
    $onceReplay = Db::transaction(function () use ($outboxService, $leaseC, $effectKey) {
        return $outboxService->consumeOnceInTx($leaseC, $effectKey);
    });
    $acked = $outboxService->ack($leaseC);
    ok(
        'ACK前崩溃后新worker命中consumer_once只ACK且副作用恰好一次',
        $onceFirst && !$onceReplay && $acked
            && (int)Db::name('c1a_event_probe')->where('probe_key', $effectKey)->count() === 1
            && (int)Db::name('c1a_event_probe')->where('probe_key', 'DUPLICATE-MUST-NOT-RUN')->count() === 0
            && (int)Db::name('cashier_v3_consumer_once')->where('event_id', $persisted['event_id'])->count() === 1,
        '',
        'EO-14-07'
    );

    $illegalSucceeded = $outboxService->manualRequeue((int)$leaseC['id'], $operator, $dataScope, '非法重排成功任务');
    $manualPersisted = Db::transaction(function () use ($recorder, $operator, $dataScope) {
        $execution = $recorder->newExecution('test-manual-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
        $contract = eoContract('test-manual-action', 'test.manual', ['manual.consumer']);
        $row = $recorder->recordInTx($execution, $contract, eoEvent('test.manual', 'MANUAL-' . uuid()));
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        return $row;
    });
    Db::name('cashier_v3_outbox')->where('event_id', $manualPersisted['event_id'])->update(['attempts' => 7]);
    $manualLease = $outboxService->claim('manual.consumer', 'worker-manual', 1)[0] ?? [];
    $manualEffectKey = 'effect-manual-' . $manualPersisted['event_id'];
    $consumerEffects['manual.consumer'] = function () use ($manualEffectKey): bool {
        Db::name('c1a_event_probe')->insert(['probe_key' => $manualEffectKey]);
        return true;
    };
    $manualConsumed = Db::transaction(function () use ($outboxService, $manualLease, $manualEffectKey) {
        return $outboxService->consumeOnceInTx($manualLease, $manualEffectKey);
    });
    $manualFailed = $outboxService->fail($manualLease, 'ACK前模拟失败');
    $manualFailedRow = Db::name('cashier_v3_outbox')->where('id', (int)$manualLease['id'])->find();
    $deniedScope = new CashierV3DataScopeContext(
        1, 1, 8, '0', '3', [8], CashierV3DataScopeContext::MODE_STORES,
        [], false, '', 'eo-denied', [], ['id' => 1, 'employee_id' => 1]
    );
    $manualDenied = false;
    try {
        $outboxService->manualRequeue((int)$manualLease['id'], $operator, $deniedScope, '无权限重排');
    } catch (Throwable $expected) {
        $manualDenied = $expected instanceof \app\services\cashier\v3\CashierV3CommandException
            && $expected->getResultCode() === CashierV3ResultCode::PERMISSION_DENIED;
    }
    $manualAck = $outboxService->manualRequeue((int)$manualLease['id'], $operator, $dataScope, '人工核对后重排');
    $manualRow = Db::name('cashier_v3_outbox')->where('id', (int)$manualLease['id'])->find();
    $manualAudit = Db::name('cashier_v3_outbox_attempt')
        ->where('outbox_id', (int)$manualLease['id'])
        ->where('operation', 'manual_ack')
        ->find();
    ok(
        '人工重排校验管理权限且真实MANUAL_FAILED已有once固定ACK-only',
        !$illegalSucceeded && $manualConsumed && $manualFailed
            && (int)($manualFailedRow['status'] ?? 0) === CashierV3OutboxServices::STATUS_MANUAL_FAILED
            && $manualDenied && $manualAck
            && (int)($manualRow['status'] ?? 0) === CashierV3OutboxServices::STATUS_SUCCEEDED
            && (int)Db::name('cashier_v3_consumer_once')->where('event_id', $manualPersisted['event_id'])->count() === 1
            && (int)Db::name('c1a_event_probe')->where('probe_key', $manualEffectKey)->count() === 1
            && (string)($manualAudit['worker_id'] ?? '') === 'operator:1'
            && (string)($manualAudit['error_message'] ?? '') === '人工核对后重排'
            && (int)($manualAudit['fencing_generation'] ?? 0) === (int)$manualLease['fencing_generation'],
        json_encode(['row' => $manualRow, 'audit' => $manualAudit]),
        'EO-14-08'
    );

    $retryPersisted = Db::transaction(function () use ($recorder, $operator, $dataScope) {
        $execution = $recorder->newExecution('test-retry-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
        $contract = eoContract('test-retry-action', 'test.retry', ['retry.consumer']);
        $row = $recorder->recordInTx($execution, $contract, eoEvent('test.retry', 'RETRY-' . uuid()));
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        return $row;
    });
    Db::name('cashier_v3_outbox')->where('event_id', $retryPersisted['event_id'])->update(['attempts' => 7]);
    $retryLease = $outboxService->claim('retry.consumer', 'worker-retry', 1)[0] ?? [];
    $forgedLease = $retryLease;
    $forgedLease['attempts'] = 1;
    $ackWithoutOnce = $outboxService->ack($retryLease);
    $failed = $outboxService->fail($forgedLease, 'forced failure');
    $failedRow = Db::name('cashier_v3_outbox')->where('event_id', $retryPersisted['event_id'])->find();
    $manualPending = $outboxService->manualRequeue((int)$failedRow['id'], $operator, $dataScope, '排查完成重新投递');
    $pendingRow = Db::name('cashier_v3_outbox')->where('id', (int)$failedRow['id'])->find();
    $illegalPending = $outboxService->manualRequeue((int)$failedRow['id'], $operator, $dataScope, '禁止重复重排');
    ok(
        'ACK必须有once且失败次数取DB权威，人工失败无once才回PENDING',
        !$ackWithoutOnce && $failed
            && (int)($failedRow['status'] ?? 0) === CashierV3OutboxServices::STATUS_MANUAL_FAILED
            && (int)($failedRow['attempts'] ?? 0) === 8
            && $manualPending
            && (int)($pendingRow['status'] ?? 0) === CashierV3OutboxServices::STATUS_PENDING
            && !$illegalPending,
        json_encode(['failed' => $failedRow, 'pending' => $pendingRow]),
        'EO-14-09'
    );

    $badPersisted = Db::transaction(function () use ($recorder, $operator, $dataScope) {
        $execution = $recorder->newExecution('test-bad-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
        $contract = eoContract('test-bad-action', 'test.bad', ['bad.consumer']);
        $row = $recorder->recordInTx($execution, $contract, eoEvent('test.bad', 'BAD-' . uuid()));
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        return $row;
    });
    $badLease = $outboxService->claim('bad.consumer', 'worker-bad', 1)[0] ?? [];
    Db::name('cashier_v3_business_event')->where('id', $badPersisted['event_id'])->update(['payload_sha256' => str_repeat('0', 64)]);
    $badPayloadRejected = false;
    $consumerEffects['bad.consumer'] = function (): bool {
        Db::name('c1a_event_probe')->insert(['probe_key' => 'BAD-MUST-NOT-RUN']);
        return true;
    };
    try {
        Db::transaction(function () use ($outboxService, $badLease) {
            $outboxService->consumeOnceInTx($badLease, 'effect-bad');
        });
    } catch (Throwable $expected) {
        $badPayloadRejected = $expected instanceof \app\services\cashier\v3\CashierV3CommandException
            && $expected->getResultCode() === CashierV3ResultCode::OUTBOX_STATE_INVALID
            && (string)($expected->getDetail()['reason'] ?? '') === 'event_payload_invalid';
    }
    ok(
        '事件payload哈希损坏时消费者fail-closed且无副作用',
        $badPayloadRejected
            && (int)Db::name('c1a_event_probe')->where('probe_key', 'BAD-MUST-NOT-RUN')->count() === 0,
        '',
        'EO-14-10'
    );

    $now = (int)(Db::query('SELECT UNIX_TIMESTAMP() AS n')[0]['n'] ?? time());
    for ($i = 1; $i <= 160; $i++) {
        Db::name('cashier_v3_outbox')->insert([
            'event_id' => 900000 + $i,
            'event_no' => 'EV-' . str_pad(dechex(900000 + $i), 32, '0', STR_PAD_LEFT),
            'consumer_code' => 'starve.consumer',
            'tenant_id' => '0',
            'organization_id' => '3',
            'organization_path' => '1/2/3',
            'store_id' => 8,
            'member_id' => 0,
            'operator_id' => 1,
            'status' => CashierV3OutboxServices::STATUS_PROCESSING,
            'attempts' => 1,
            'available_at' => 0,
            'lease_until' => $now + 3600,
            'lease_owner' => 'busy-worker',
            'lease_token' => str_pad(dechex($i), 32, '0', STR_PAD_LEFT),
            'fencing_generation' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    $readyStarveId = (int)Db::name('cashier_v3_outbox')->insertGetId([
        'event_id' => 999999,
        'event_no' => 'EV-' . str_repeat('f', 32),
        'consumer_code' => 'starve.consumer',
        'tenant_id' => '0',
        'organization_id' => '3',
        'organization_path' => '1/2/3',
        'store_id' => 8,
        'member_id' => 0,
        'operator_id' => 1,
        'status' => CashierV3OutboxServices::STATUS_PENDING,
        'attempts' => 0,
        'available_at' => $now,
        'lease_until' => 0,
        'lease_owner' => '',
        'lease_token' => '',
        'fencing_generation' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $starveClaim = $outboxService->claim('starve.consumer', 'worker-ready', 1);
    ok(
        '大量未过期processing行不会饿死后续ready任务',
        count($starveClaim) === 1 && (int)($starveClaim[0]['id'] ?? 0) === $readyStarveId,
        json_encode(['expected' => $readyStarveId, 'claimed' => $starveClaim]),
        'EO-14-13'
    );

    $zeroPersisted = Db::transaction(function () use ($recorder, $operator, $dataScope) {
        $execution = $recorder->newExecution('test-zero-action', 'CMD-' . uuid(), $operator, $dataScope, 'SC-EO');
        $contract = eoContract('test-zero-action', 'test.zero', []);
        $row = $recorder->recordInTx($execution, $contract, eoEvent('test.zero', 'ZERO-' . uuid()));
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        return $row;
    });
    ok(
        '零消费者事件不制造空Outbox',
        (int)Db::name('cashier_v3_outbox')->where('event_id', $zeroPersisted['event_id'])->count() === 0,
        '',
        'EO-14-11'
    );

    $ops = Db::name('cashier_v3_outbox_attempt')
        ->where('outbox_id', (int)$leaseC['id'])
        ->order('id asc')
        ->column('operation');
    $manualOps = Db::name('cashier_v3_outbox_attempt')
        ->where('outbox_id', (int)$manualLease['id'])
        ->order('id asc')
        ->column('operation');
    ok(
        '领取/续租/拒绝/ACK/人工操作审计只追加不覆盖',
        in_array('claim', $ops, true)
            && in_array('renew', $ops, true)
            && in_array('renew_rejected', $ops, true)
            && in_array('fail_rejected', $ops, true)
            && in_array('ack_rejected', $ops, true)
            && in_array('ack', $ops, true)
            && in_array('manual_requeue_rejected', $ops, true)
            && in_array('claim', $manualOps, true)
            && in_array('fail', $manualOps, true)
            && in_array('manual_ack', $manualOps, true)
            && count($ops) >= 8,
        json_encode(['normal' => $ops, 'manual' => $manualOps]),
        'EO-14-12'
    );
} catch (Throwable $throwable) {
    try {
        Db::execute('SET timestamp=0');
        Db::execute('DROP TRIGGER IF EXISTS `eo_fail_outbox_insert`');
    } catch (Throwable $ignored) {
    }
    ok(
        '事件与Outbox集成套件无未处理异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage()
            . ' @ ' . $throwable->getFile() . ':' . $throwable->getLine()
    );
}

finish('event-outbox-integration');
