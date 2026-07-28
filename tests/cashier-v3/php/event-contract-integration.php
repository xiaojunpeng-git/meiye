<?php
/** Unified business-event contract, snapshot and immutable identity gates. */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\event\CashierV3EventConsumerRegistry;
use app\services\cashier\v3\event\CashierV3EventRouteFingerprint;
use app\services\cashier\v3\event\CashierV3OutboxServices;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

function ecContract(
    string $action,
    string $type,
    string $aggregateType,
    int $min = 1,
    int $max = 1,
    int $aggregateVersion = 1,
    array $consumers = [],
    ?string $sourceType = null
): array {
    return [
        'required_event_types' => $min > 0 ? [$type] : [],
        'allowed_event_types' => [$type],
        'event_rules' => [
            $type => [
                'min_count' => $min,
                'max_count' => $max,
                'aggregate_type' => $aggregateType,
                'source_type' => $sourceType ?? $action,
                'aggregate_version' => $aggregateVersion,
            ],
        ],
        'eventless_reason' => $min > 0 ? '' : '测试可选事件合同。',
        'consumers' => [$type => $consumers],
    ];
}

function ecEvent(string $type, string $aggregateType, string $aggregateId, int $now): array
{
    return [
        'event_type' => $type,
        'event_version' => 1,
        'aggregate_type' => $aggregateType,
        'aggregate_id' => $aggregateId,
        'aggregate_version' => 1,
        'member_id' => 99,
        'occurred_at' => $now,
        'settled_at' => $now,
        'organization_path' => 'CLIENT/FORGED/PATH',
        'organization_name_snapshot' => '客户端伪造组织',
        'operator_name_snapshot' => '客户端伪造操作人',
        'aggregate_name_snapshot' => '测试聚合',
        'store_name_snapshot' => '测试门店',
        'payload' => ['aggregate_id' => $aggregateId, 'amount' => 100],
    ];
}

function ecExecution(
    CashierV3BusinessEventRecorder $recorder,
    string $action,
    string $idempotencyKey,
    CashierV3OperatorScope $operator,
    CashierV3DataScopeContext $dataScope
) {
    return $recorder->newExecution($action, $idempotencyKey, $operator, $dataScope, 'SC-EC');
}

try {
    Db::execute("REPLACE INTO `eb_organization` (`id`,`pid`,`name`,`is_del`) VALUES
      (1,0,'集团',0),(2,1,'事业部',0),(3,2,'当前组织',0)");
    $binding = Db::name('organization_store')->where('store_id', 8)->find();
    if ($binding) {
        Db::name('organization_store')->where('store_id', 8)->update(['org_id' => 3]);
    } else {
        Db::name('organization_store')->insert(['org_id' => 3, 'store_id' => 8]);
    }

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
        'ec-test',
        ['cashier.v3.member'],
        [
            'id' => 1,
            'employee_id' => 1,
            'staff_name' => '权威操作员',
            'account' => 'authority-operator',
        ]
    );
    $recorder = new CashierV3BusinessEventRecorder();

    $action = 'test-event-action';
    $type = 'test.recorded';
    $contract = ecContract($action, $type, 'test_aggregate');
    $now = time();
    $aggregateId = 'EC-' . uuid();
    $persisted = Db::transaction(function () use (
        $recorder,
        $action,
        $type,
        $contract,
        $now,
        $aggregateId,
        $operator,
        $dataScope
    ) {
        $execution = ecExecution($recorder, $action, 'CMD-' . uuid(), $operator, $dataScope);
        $row = $recorder->recordInTx(
            $execution,
            $contract,
            ecEvent($type, 'test_aggregate', $aggregateId, $now)
        );
        $recorder->assertRequiredPersistedInTx($execution, $contract);
        $descriptor = $execution->emittedEvents()[0] ?? [];
        $execution->close();
        return ['row' => $row, 'descriptor' => $descriptor];
    });
    $event = Db::name('cashier_v3_business_event')
        ->where('id', (int)$persisted['row']['event_id'])
        ->find();
    ok(
        '三级组织路径与组织、操作人名称均来自服务端权威快照',
        (string)($event['organization_path'] ?? '') === '1/2/3'
            && (string)($event['organization_name_snapshot'] ?? '') === '当前组织'
            && (string)($event['operator_name_snapshot'] ?? '') === '权威操作员'
            && (int)($event['aggregate_version'] ?? 0) === 1
            && (int)($event['event_version'] ?? 0) === 1,
        json_encode($event, JSON_UNESCAPED_UNICODE),
        'EO-15-03'
    );
    ok(
        'Execution 保留完整 emitted descriptor',
        (int)($persisted['descriptor']['event_id'] ?? 0) === (int)$persisted['row']['event_id']
            && (string)($persisted['descriptor']['event_key'] ?? '') !== ''
            && (string)($persisted['descriptor']['payload_sha256'] ?? '') === (string)($event['payload_sha256'] ?? '')
            && (string)($persisted['descriptor']['route_fingerprint'] ?? '') === (string)($event['route_fingerprint'] ?? '')
            && (int)($persisted['descriptor']['aggregate_version'] ?? 0) === 1,
        json_encode($persisted['descriptor'], JSON_UNESCAPED_UNICODE),
        'EO-15-03'
    );

    $routeAction = 'test-route-action';
    $routeSourceType = 'member-profile';
    $routeType = 'test.routed';
    $routeConsumer = 'route.consumer';
    $routeContract = ecContract(
        $routeAction,
        $routeType,
        'test_aggregate',
        1,
        1,
        1,
        [$routeConsumer],
        $routeSourceType
    );
    $routePersisted = Db::transaction(function () use (
        $recorder,
        $operator,
        $dataScope,
        $routeAction,
        $routeSourceType,
        $routeType,
        $routeContract,
        $now
    ) {
        $execution = ecExecution($recorder, $routeAction, 'CMD-' . uuid(), $operator, $dataScope);
        $event = ecEvent($routeType, 'test_aggregate', 'ROUTE-' . uuid(), $now);
        $event['source_type'] = $routeSourceType;
        $row = $recorder->recordInTx($execution, $routeContract, $event);
        $recorder->assertRequiredPersistedInTx($execution, $routeContract);
        return $row;
    });
    $routeEvent = Db::name('cashier_v3_business_event')
        ->where('id', (int)$routePersisted['event_id'])
        ->find();
    $consumedSourceType = '';
    $routeHandler = function (array $event) use (&$consumedSourceType): bool {
        $consumedSourceType = (string)($event['source_type'] ?? '');
        return true;
    };
    $routeRegistry = new CashierV3EventConsumerRegistry();
    $routeRegistry->register($routeConsumer, $routeHandler);
    $routeRegistry->freeze();
    $routeResolvedHandler = $routeRegistry->requireConsumer($routeConsumer);
    $routeService = new CashierV3OutboxServices($routeRegistry);
    $routeLease = $routeService->claim($routeConsumer, 'route-worker', 1)[0] ?? [];
    $routeConsumed = Db::transaction(function () use (
        $routeService,
        $routeLease,
        $routePersisted
    ) {
        return $routeService->consumeOnceInTx(
            $routeLease,
            'route-effect-' . (int)$routePersisted['event_id']
        );
    });
    $routeAcked = $routeService->ack($routeLease);
    ok(
        'source_type 不同于 action 时写入与真实消费使用同一路由指纹语义',
        $routeConsumed
            && $routeAcked
            && is_callable($routeResolvedHandler)
            && $consumedSourceType === $routeSourceType
            && (string)($routeEvent['source_type'] ?? '') === $routeSourceType
            && (string)($routeEvent['route_fingerprint'] ?? '') === CashierV3EventRouteFingerprint::calculate(
                $routeSourceType,
                $routeType,
                [$routeConsumer]
            )
            && (string)($routeEvent['route_fingerprint'] ?? '') !== CashierV3EventRouteFingerprint::calculate(
                $routeAction,
                $routeType,
                [$routeConsumer]
            ),
        json_encode(['event' => $routeEvent, 'lease' => $routeLease], JSON_UNESCAPED_UNICODE),
        'EO-15-03'
    );

    $longAction = 'test-long-key-action';
    $longType = 't' . str_repeat('e', 63);
    $longAggregateType = 'a' . str_repeat('g', 31);
    $longAggregateId = str_repeat('I', 64);
    $longContract = ecContract(
        $longAction,
        $longType,
        $longAggregateType,
        1,
        1,
        PHP_INT_MAX
    );
    $longEvent = ecEvent($longType, $longAggregateType, $longAggregateId, $now);
    $longEvent['aggregate_version'] = PHP_INT_MAX;
    $longEvent['detail_id'] = str_repeat('D', 64);
    $longEvent['reversal_of'] = 'EV-' . str_repeat('a', 32);
    $longCommand = 'CMD-' . uuid();
    $longKeys = [];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $longKeys[] = Db::transaction(function () use (
            $recorder,
            $operator,
            $dataScope,
            $longAction,
            $longCommand,
            $longContract,
            $longEvent
        ) {
            $execution = ecExecution($recorder, $longAction, $longCommand, $operator, $dataScope);
            $result = $recorder->recordInTx($execution, $longContract, $longEvent);
            $recorder->assertRequiredPersistedInTx($execution, $longContract);
            return (string)$result['event_key'];
        });
    }
    ok(
        '合法最大 ID/detail/reversal 使用稳定可读前缀与 SHA-256 键',
        strlen($longKeys[0] ?? '') <= 255
            && strpos((string)($longKeys[0] ?? ''), ':sha256:') !== false
            && ($longKeys[0] ?? '') === ($longKeys[1] ?? '')
            && (int)Db::name('cashier_v3_business_event')
                ->where('event_key', (string)($longKeys[0] ?? ''))
                ->count() === 1,
        json_encode($longKeys),
        'EO-15-04'
    );

    foreach ([
        ['aggregate_version', 0, 'aggregate_version_invalid'],
        ['event_version', 0, 'event_version_invalid'],
    ] as $invalidVersion) {
        rejects(
            $invalidVersion[0] . ' 必须大于 0',
            CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE,
            function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now, $invalidVersion) {
                Db::transaction(function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now, $invalidVersion) {
                    $execution = ecExecution($recorder, $action, 'CMD-' . uuid(), $operator, $dataScope);
                    $event = ecEvent($type, 'test_aggregate', 'INVALID-' . uuid(), $now);
                    $event[$invalidVersion[0]] = $invalidVersion[1];
                    $recorder->recordInTx($execution, $contract, $event);
                });
            },
            $invalidVersion[2],
            'EO-15-04'
        );
    }

    rejects(
        '组织父节点缺失时事件拒绝且回滚',
        CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE,
        function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now) {
            Db::transaction(function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now) {
                Db::name('organization')->where('id', 3)->update(['pid' => 999999]);
                $execution = ecExecution($recorder, $action, 'CMD-' . uuid(), $operator, $dataScope);
                $recorder->recordInTx($execution, $contract, ecEvent($type, 'test_aggregate', 'MISSING-' . uuid(), $now));
            });
        },
        'organization_node_missing',
        'EO-15-05'
    );
    rejects(
        '组织链存在环时事件拒绝且回滚',
        CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE,
        function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now) {
            Db::transaction(function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now) {
                Db::name('organization')->where('id', 1)->update(['pid' => 3]);
                $execution = ecExecution($recorder, $action, 'CMD-' . uuid(), $operator, $dataScope);
                $recorder->recordInTx($execution, $contract, ecEvent($type, 'test_aggregate', 'CYCLE-' . uuid(), $now));
            });
        },
        'organization_cycle_detected',
        'EO-15-05'
    );
    rejects(
        '门店权威组织与操作上下文不一致时 fail-closed',
        CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE,
        function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now) {
            Db::transaction(function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now) {
                Db::name('organization_store')->where('store_id', 8)->update(['org_id' => 2]);
                $execution = ecExecution($recorder, $action, 'CMD-' . uuid(), $operator, $dataScope);
                $recorder->recordInTx($execution, $contract, ecEvent($type, 'test_aggregate', 'SCOPE-' . uuid(), $now));
            });
        },
        'store_organization_mismatch',
        'EO-15-05'
    );

    foreach ([
        ['event_aggregate_type_mismatch', 'wrong_aggregate', null],
        ['event_source_type_mismatch', 'test_aggregate', 'wrong-source'],
    ] as $mismatch) {
        rejects(
            '事件聚合或来源与合同不一致时拒绝',
            CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING,
            function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now, $mismatch) {
                Db::transaction(function () use ($recorder, $operator, $dataScope, $action, $type, $contract, $now, $mismatch) {
                    $execution = ecExecution($recorder, $action, 'CMD-' . uuid(), $operator, $dataScope);
                    $event = ecEvent($type, $mismatch[1], 'RULE-' . uuid(), $now);
                    if ($mismatch[2] !== null) {
                        $event['source_type'] = $mismatch[2];
                    }
                    $recorder->recordInTx($execution, $contract, $event);
                });
            },
            $mismatch[0],
            'EO-15-06'
        );
    }

    rejects(
        '同类型事件超过 max_count 时整个业务事务回滚',
        CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING,
        function () use ($recorder, $operator, $dataScope, $now) {
            Db::transaction(function () use ($recorder, $operator, $dataScope, $now) {
                $cardinalityAction = 'test-cardinality-action';
                $cardinalityType = 'test.cardinality';
                $cardinalityContract = ecContract($cardinalityAction, $cardinalityType, 'test_aggregate', 1, 1);
                $execution = ecExecution($recorder, $cardinalityAction, 'CMD-' . uuid(), $operator, $dataScope);
                $recorder->recordInTx($execution, $cardinalityContract, ecEvent($cardinalityType, 'test_aggregate', 'COUNT-A-' . uuid(), $now));
                $recorder->recordInTx($execution, $cardinalityContract, ecEvent($cardinalityType, 'test_aggregate', 'COUNT-B-' . uuid(), $now));
                $recorder->assertRequiredPersistedInTx($execution, $cardinalityContract);
            });
        },
        'event_cardinality_exceeded',
        'EO-15-06'
    );

    $crossExecutionAction = 'test-cross-execution-cardinality';
    $crossExecutionType = 'test.cross_execution_cardinality';
    $crossExecutionCommand = 'CMD-' . uuid();
    $crossExecutionContract = ecContract(
        $crossExecutionAction,
        $crossExecutionType,
        'test_aggregate',
        1,
        1,
        1,
        ['cross-execution.consumer']
    );
    $crossExecutionBefore = [
        'events' => (int)Db::name('cashier_v3_business_event')->count(),
        'outbox' => (int)Db::name('cashier_v3_outbox')->count(),
    ];
    $crossExecutionCode = '';
    $crossExecutionReason = '';
    try {
        Db::transaction(function () use (
            $recorder,
            $operator,
            $dataScope,
            $crossExecutionAction,
            $crossExecutionType,
            $crossExecutionCommand,
            $crossExecutionContract,
            $now
        ) {
            $firstExecution = ecExecution(
                $recorder,
                $crossExecutionAction,
                $crossExecutionCommand,
                $operator,
                $dataScope
            );
            $secondExecution = ecExecution(
                $recorder,
                $crossExecutionAction,
                $crossExecutionCommand,
                $operator,
                $dataScope
            );
            $recorder->recordInTx(
                $firstExecution,
                $crossExecutionContract,
                ecEvent($crossExecutionType, 'test_aggregate', 'CROSS-A-' . uuid(), $now)
            );
            $recorder->recordInTx(
                $secondExecution,
                $crossExecutionContract,
                ecEvent($crossExecutionType, 'test_aggregate', 'CROSS-B-' . uuid(), $now)
            );
            $recorder->assertRequiredPersistedInTx($firstExecution, $crossExecutionContract);
        });
    } catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
        $crossExecutionCode = $exception->getResultCode();
        $crossExecutionReason = (string)($exception->getDetail()['reason'] ?? '');
    }
    $crossExecutionAfter = [
        'events' => (int)Db::name('cashier_v3_business_event')->count(),
        'outbox' => (int)Db::name('cashier_v3_outbox')->count(),
    ];
    ok(
        '同一命令键跨两个 Execution 仍按完整事件集合校验 max_count 并整事务回滚',
        $crossExecutionCode === CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING
            && $crossExecutionReason === 'event_cardinality_exceeded'
            && $crossExecutionAfter === $crossExecutionBefore
            && (int)Db::name('cashier_v3_business_event')
                ->where('command_idempotency_key', $crossExecutionCommand)
                ->count() === 0,
        json_encode([
            'code' => $crossExecutionCode,
            'reason' => $crossExecutionReason,
            'before' => $crossExecutionBefore,
            'after' => $crossExecutionAfter,
        ], JSON_UNESCAPED_UNICODE),
        'EO-15-06'
    );

    $duplicateAction = 'test-duplicate-event-action';
    $duplicateType = 'test.duplicate';
    $duplicateConsumer = 'duplicate.consumer';
    $duplicateContract = ecContract(
        $duplicateAction,
        $duplicateType,
        'test_aggregate',
        1,
        1,
        1,
        [$duplicateConsumer]
    );
    $duplicateAggregateId = 'DUPLICATE-' . uuid();
    $duplicateResult = Db::transaction(function () use (
        $recorder,
        $operator,
        $dataScope,
        $duplicateAction,
        $duplicateType,
        $duplicateConsumer,
        $duplicateContract,
        $duplicateAggregateId,
        $now
    ) {
        $execution = ecExecution($recorder, $duplicateAction, 'CMD-' . uuid(), $operator, $dataScope);
        $event = ecEvent($duplicateType, 'test_aggregate', $duplicateAggregateId, $now);
        $first = $recorder->recordInTx($execution, $duplicateContract, $event);
        $second = $recorder->recordInTx($execution, $duplicateContract, $event);
        $recorder->assertRequiredPersistedInTx($execution, $duplicateContract);
        return [
            'first' => $first,
            'second' => $second,
            'emitted_count' => count($execution->emittedEvents()),
            'outbox_count' => (int)Db::name('cashier_v3_outbox')
                ->where('event_id', (int)($first['event_id'] ?? 0))
                ->where('consumer_code', $duplicateConsumer)
                ->count(),
        ];
    });
    ok(
        '同一 execution 重复记录同一 event_key 只登记一条事件且不虚增基数',
        (int)($duplicateResult['first']['event_id'] ?? 0) > 0
            && (int)($duplicateResult['first']['event_id'] ?? 0) === (int)($duplicateResult['second']['event_id'] ?? 0)
            && (string)($duplicateResult['first']['event_key'] ?? '') === (string)($duplicateResult['second']['event_key'] ?? '')
            && (int)($duplicateResult['emitted_count'] ?? 0) === 1
            && (int)($duplicateResult['outbox_count'] ?? 0) === 1
            && (int)Db::name('cashier_v3_business_event')
                ->where('event_key', (string)($duplicateResult['first']['event_key'] ?? ''))
                ->count() === 1,
        json_encode($duplicateResult, JSON_UNESCAPED_UNICODE),
        'EO-15-06'
    );

    $crossDuplicateCommand = 'CMD-' . uuid();
    $crossDuplicateAggregateId = 'DUPLICATE-CROSS-' . uuid();
    $crossDuplicateResult = Db::transaction(function () use (
        $recorder,
        $operator,
        $dataScope,
        $duplicateAction,
        $duplicateType,
        $duplicateConsumer,
        $duplicateContract,
        $crossDuplicateCommand,
        $crossDuplicateAggregateId,
        $now
    ) {
        $firstExecution = ecExecution(
            $recorder,
            $duplicateAction,
            $crossDuplicateCommand,
            $operator,
            $dataScope
        );
        $secondExecution = ecExecution(
            $recorder,
            $duplicateAction,
            $crossDuplicateCommand,
            $operator,
            $dataScope
        );
        $event = ecEvent($duplicateType, 'test_aggregate', $crossDuplicateAggregateId, $now);
        $first = $recorder->recordInTx($firstExecution, $duplicateContract, $event);
        $second = $recorder->recordInTx($secondExecution, $duplicateContract, $event);
        $recorder->assertRequiredPersistedInTx($firstExecution, $duplicateContract);
        $recorder->assertRequiredPersistedInTx($secondExecution, $duplicateContract);
        return [
            'first' => $first,
            'second' => $second,
            'first_emitted_count' => count($firstExecution->emittedEvents()),
            'second_emitted_count' => count($secondExecution->emittedEvents()),
            'event_count' => (int)Db::name('cashier_v3_business_event')
                ->where('command_idempotency_key', $crossDuplicateCommand)
                ->count(),
            'outbox_count' => (int)Db::name('cashier_v3_outbox')
                ->where('event_id', (int)$first['event_id'])
                ->where('consumer_code', $duplicateConsumer)
                ->count(),
        ];
    });
    ok(
        '同一命令键跨两个 Execution 重复同一 event_key 时复用事件与 Outbox',
        (int)($crossDuplicateResult['first']['event_id'] ?? 0) > 0
            && (int)($crossDuplicateResult['first']['event_id'] ?? 0)
                === (int)($crossDuplicateResult['second']['event_id'] ?? 0)
            && (string)($crossDuplicateResult['first']['event_key'] ?? '')
                === (string)($crossDuplicateResult['second']['event_key'] ?? '')
            && (int)($crossDuplicateResult['first_emitted_count'] ?? 0) === 1
            && (int)($crossDuplicateResult['second_emitted_count'] ?? 0) === 1
            && (int)($crossDuplicateResult['event_count'] ?? 0) === 1
            && (int)($crossDuplicateResult['outbox_count'] ?? 0) === 1,
        json_encode($crossDuplicateResult, JSON_UNESCAPED_UNICODE),
        'EO-15-06'
    );

    rejects(
        '同一 event_key 重复调用不能用一行事件满足 min=max=2',
        CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING,
        function () use ($recorder, $operator, $dataScope, $duplicateAction, $duplicateType, $now) {
            Db::transaction(function () use ($recorder, $operator, $dataScope, $duplicateAction, $duplicateType, $now) {
                $twoRequiredContract = ecContract($duplicateAction, $duplicateType, 'test_aggregate', 2, 2);
                $execution = ecExecution($recorder, $duplicateAction, 'CMD-' . uuid(), $operator, $dataScope);
                $event = ecEvent($duplicateType, 'test_aggregate', 'DUPLICATE-TWO-' . uuid(), $now);
                $recorder->recordInTx($execution, $twoRequiredContract, $event);
                $recorder->recordInTx($execution, $twoRequiredContract, $event);
                $recorder->assertRequiredPersistedInTx($execution, $twoRequiredContract);
            });
        },
        'required_event_missing',
        'EO-15-06'
    );

    $conflictAction = 'test-conflict-action';
    $conflictType = 'test.conflict';
    $conflictContract = ecContract($conflictAction, $conflictType, 'test_aggregate');
    $conflictCommand = 'CMD-' . uuid();
    $conflictEvent = ecEvent($conflictType, 'test_aggregate', 'CONFLICT-' . uuid(), $now);
    Db::transaction(function () use ($recorder, $operator, $dataScope, $conflictAction, $conflictCommand, $conflictContract, $conflictEvent) {
        $execution = ecExecution($recorder, $conflictAction, $conflictCommand, $operator, $dataScope);
        $recorder->recordInTx($execution, $conflictContract, $conflictEvent);
        $recorder->assertRequiredPersistedInTx($execution, $conflictContract);
    });

    $conflicts = [
        'payload' => function (array $event): array {
            $event['payload']['amount'] = 101;
            return $event;
        },
        'route' => function (array $event): array { return $event; },
        'identity' => function (array $event): array {
            $event['member_id'] = 100;
            return $event;
        },
        'command_identity' => function (array $event): array { return $event; },
    ];
    foreach ($conflicts as $kind => $mutate) {
        rejects(
            '同 event_key 的 ' . $kind . ' 不可变字段冲突时拒绝',
            CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
            function () use (
                $recorder,
                $operator,
                $dataScope,
                $conflictAction,
                $conflictType,
                $conflictCommand,
                $conflictContract,
                $conflictEvent,
                $kind,
                $mutate
            ) {
                Db::transaction(function () use (
                    $recorder,
                    $operator,
                    $dataScope,
                    $conflictAction,
                    $conflictType,
                    $conflictCommand,
                    $conflictContract,
                    $conflictEvent,
                    $kind,
                    $mutate
                ) {
                    $candidateContract = $kind === 'route'
                        ? ecContract($conflictAction, $conflictType, 'test_aggregate', 1, 1, 1, ['changed.consumer'])
                        : $conflictContract;
                    $candidateCommand = $kind === 'command_identity' ? 'CMD-' . uuid() : $conflictCommand;
                    $execution = ecExecution($recorder, $conflictAction, $candidateCommand, $operator, $dataScope);
                    $recorder->recordInTx($execution, $candidateContract, $mutate($conflictEvent));
                });
            },
            'event_key_conflict',
            'EO-15-07'
        );
    }
} catch (\Throwable $throwable) {
    ok(
        '事件合同与快照集成套件无未处理异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage()
            . ' @ ' . $throwable->getFile() . ':' . $throwable->getLine()
            . "\n" . $throwable->getTraceAsString()
    );
}

finish('event-contract-integration');
