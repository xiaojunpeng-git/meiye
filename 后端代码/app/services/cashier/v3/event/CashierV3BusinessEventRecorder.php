<?php

namespace app\services\cashier\v3\event;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Transaction-only business event writer. This class has no request state;
 * all mutable event identities live in CashierV3BusinessEventExecution.
 */
final class CashierV3BusinessEventRecorder
{
    public const EVENT_TABLE = 'cashier_v3_business_event';
    public const OUTBOX_TABLE = 'cashier_v3_outbox';

    public function newExecution(
        string $action,
        string $idempotencyKey,
        CashierV3OperatorScope $operatorScope,
        \app\services\cashier\v3\CashierV3DataScopeContext $dataScope,
        string $stateContextId
    ): CashierV3BusinessEventExecution {
        return new CashierV3BusinessEventExecution(
            $action,
            $idempotencyKey,
            $operatorScope,
            $dataScope,
            $stateContextId
        );
    }

    /**
     * @param array $event event_type, aggregate_type, aggregate_id,
     * aggregate_version, payload and optional source/time/snapshot fields.
     * @return array{event_id:int,event_no:string,event_key:string,event_type:string}
     */
    public function recordInTx(
        CashierV3BusinessEventExecution $execution,
        array $contract,
        array $event
    ): array {
        CashierV3TransactionGuard::assertInTransaction('businessEvent.recordInTx');
        $contract = CashierV3BusinessEventContractRegistry::normalize(
            ['event_contract' => $contract],
            $execution->action()
        );
        $type = trim((string)($event['event_type'] ?? ''));
        if ($type === '' || !in_array($type, (array)($contract['allowed_event_types'] ?? []), true)) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING, 'event_type_not_allowed', $execution, $type);
        }
        if (!$execution->idempotencyKey()) {
            throw self::failure(CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY, 'idempotency_key_missing', $execution, $type);
        }
        $aggregateType = trim((string)($event['aggregate_type'] ?? ''));
        $aggregateId = trim((string)($event['aggregate_id'] ?? ''));
        $aggregateVersion = $this->positiveVersion($event['aggregate_version'] ?? null, $execution, $type, 'aggregate_version_invalid');
        $eventVersion = $this->positiveVersion($event['event_version'] ?? 1, $execution, $type, 'event_version_invalid');
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,31}$/', $aggregateType)
            || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $aggregateId)) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'aggregate_identity_invalid', $execution, $type);
        }
        $detailId = trim((string)($event['detail_id'] ?? ''));
        if ($detailId !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $detailId)) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'detail_identity_invalid', $execution, $type);
        }
        $reversalOf = trim((string)($event['reversal_of'] ?? ''));
        if ($reversalOf !== '' && !preg_match('/^EV-[A-Fa-f0-9]{32}$/', $reversalOf)) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'reversal_identity_invalid', $execution, $type);
        }
        $eventKey = $this->eventKey($type, $aggregateType, $aggregateId, $aggregateVersion, $detailId, $reversalOf);
        $payload = $event['payload'] ?? [];
        if (!is_array($payload)) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'payload_not_array', $execution, $type);
        }
        $payloadJson = json_encode($this->sortRecursive($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'payload_encode_failed', $execution, $type);
        }
        $operatorScope = $execution->operatorScope();
        $occurredAt = (int)($event['occurred_at'] ?? time());
        $settledAt = (int)($event['settled_at'] ?? $occurredAt);
        $recordedAt = time();
        if ($occurredAt <= 0 || $settledAt <= 0 || $recordedAt <= 0) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'time_invalid', $execution, $type);
        }
        $sourceType = trim((string)($event['source_type'] ?? $execution->action()));
        $sourceId = trim((string)($event['source_id'] ?? $aggregateId));
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $sourceType)
            || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $sourceId)) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'source_identity_invalid', $execution, $type);
        }
        $dimensions = $this->authoritativeDimensions($execution, $type);
        $eventNo = $this->newEventNo();
        $routeFingerprint = $this->routeFingerprint($sourceType, $type, $contract);
        $row = [
            'event_no' => $eventNo,
            'event_key' => $eventKey,
            'event_type' => $type,
            'event_version' => $eventVersion,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'aggregate_version' => $aggregateVersion,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_detail_id' => mb_substr($detailId, 0, 64),
            'command_idempotency_key' => $execution->idempotencyKey(),
            'reversal_of' => $reversalOf,
            'tenant_id' => $operatorScope->tenantId(),
            'organization_id' => $dimensions['organization_id'],
            'organization_path' => $dimensions['organization_path'],
            'store_id' => $operatorScope->storeId(),
            'member_id' => (int)($event['member_id'] ?? 0),
            'operator_id' => $operatorScope->operatorId(),
            'business_date' => $this->businessDate($occurredAt),
            'occurred_at' => $occurredAt,
            'settled_at' => $settledAt,
            'recorded_at' => $recordedAt,
            'aggregate_name_snapshot' => mb_substr((string)($event['aggregate_name_snapshot'] ?? ''), 0, 128),
            'organization_name_snapshot' => $dimensions['organization_name_snapshot'],
            'store_name_snapshot' => mb_substr((string)($event['store_name_snapshot'] ?? ''), 0, 128),
            'operator_name_snapshot' => $dimensions['operator_name_snapshot'],
            'payload' => $payloadJson,
            'payload_sha256' => hash('sha256', $payloadJson),
            'route_fingerprint' => $routeFingerprint,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ];
        $this->assertEventRule($row, $contract, $execution, $type);
        $reusedExistingEvent = false;
        try {
            $inserted = Db::name(self::EVENT_TABLE)->insertGetId($row);
            $eventId = (int)$inserted;
        } catch (\Throwable $exception) {
            if (!$this->isEventKeyDuplicate($exception)) {
                throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'event_insert_failed', $execution, $type, $exception);
            }
            $existing = Db::name(self::EVENT_TABLE)->where('event_key', $eventKey)->lock(true)->find();
            if (!$existing) {
                throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'event_insert_failed', $execution, $type, $exception);
            }
            $this->assertDuplicateImmutable($existing, $row, $execution, $type, $exception);
            $eventId = (int)$existing['id'];
            $eventNo = (string)$existing['event_no'];
            $row = $existing;
            $reusedExistingEvent = true;
        }
        $row['id'] = $eventId;
        $row['event_no'] = $eventNo;
        $descriptor = $this->descriptorFromRow($row);
        $alreadyEmitted = $this->executionDescriptorByEventKey($execution, $eventKey);
        if ($alreadyEmitted !== null) {
            $this->assertDescriptorEqualsStored($alreadyEmitted, $descriptor, $execution);
            return ['event_id' => $eventId, 'event_no' => $eventNo, 'event_key' => $eventKey, 'event_type' => $type];
        }
        if ($reusedExistingEvent) {
            $this->assertOutboxRoutesPersisted($eventId, $type, $contract, $execution);
            $execution->addEvent($descriptor);
            return ['event_id' => $eventId, 'event_no' => $eventNo, 'event_key' => $eventKey, 'event_type' => $type];
        }
        foreach ((array)($contract['consumers'][$type] ?? []) as $consumerCode) {
            $this->insertOutbox($eventId, $eventNo, $consumerCode, $operatorScope, $row, $recordedAt);
        }
        $execution->addEvent($descriptor);
        return ['event_id' => $eventId, 'event_no' => $eventNo, 'event_key' => $eventKey, 'event_type' => $type];
    }

    public function assertRequiredPersistedInTx(
        CashierV3BusinessEventExecution $execution,
        array $contract
    ): void {
        CashierV3TransactionGuard::assertInTransaction('businessEvent.assertRequiredPersistedInTx');
        $contract = CashierV3BusinessEventContractRegistry::normalize(
            ['event_contract' => $contract],
            $execution->action()
        );
        // The command idempotency key is the event-set boundary. A handler
        // must not hide extra rows behind a second Execution collector.
        $rows = Db::name(self::EVENT_TABLE)
            ->where('command_idempotency_key', $execution->idempotencyKey())
            ->lock(true)
            ->select()
            ->toArray();
        $storedById = [];
        foreach ($rows as $row) {
            $storedById[(int)($row['id'] ?? 0)] = $this->descriptorFromRow($row);
        }
        foreach ($execution->emittedEvents() as $descriptor) {
            $eventId = (int)($descriptor['event_id'] ?? 0);
            if (!isset($storedById[$eventId])) {
                throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'event_row_not_found_or_scope_mismatch', $execution, (string)($descriptor['event_type'] ?? ''));
            }
            $this->assertDescriptorEqualsStored($descriptor, $storedById[$eventId], $execution);
        }
        foreach ($storedById as $descriptor) {
            $this->assertOutboxRoutesPersisted(
                (int)$descriptor['event_id'],
                (string)$descriptor['event_type'],
                $contract,
                $execution
            );
        }
        $this->assertExecutionContract($execution, $contract, array_values($storedById));
    }

    /**
     * A succeeded receipt for a required-event action is not sufficient proof
     * on replay. The immutable event rows must still exist and satisfy the
     * original action contract; a missing row is result-unknown and is never
     * repaired inside the user request.
     */
    public function assertReplayPersistedInTx(
        string $action,
        string $idempotencyKey,
        CashierV3OperatorScope $operatorScope,
        \app\services\cashier\v3\CashierV3DataScopeContext $dataScope,
        string $stateContextId,
        array $contract
    ): void {
        CashierV3TransactionGuard::assertInTransaction('businessEvent.assertReplayPersistedInTx');
        $execution = $this->newExecution(
            $action,
            $idempotencyKey,
            $operatorScope,
            $dataScope,
            $stateContextId
        );
        try {
            $rows = Db::name(self::EVENT_TABLE)
                ->where('command_idempotency_key', $idempotencyKey)
                ->lock(true)
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $execution->addEvent($this->descriptorFromRow($row));
            }
            $this->assertRequiredPersistedInTx($execution, $contract);
        } catch (CashierV3CommandException $exception) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_UNKNOWN,
                '历史成功回执缺少完整业务事件，系统无法安全判定结果，请联系管理员对账。',
                CashierV3ResultCode::STATUS_RESULT_UNKNOWN,
                [
                    'action' => $action,
                    'reason' => 'required_event_replay_inconsistent',
                    'event_reason' => (string)($exception->getDetail()['reason'] ?? $exception->getResultCode()),
                ]
            );
        } catch (\Throwable $exception) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_UNKNOWN,
                '历史成功回执的业务事件无法核验，系统无法安全判定结果，请联系管理员对账。',
                CashierV3ResultCode::STATUS_RESULT_UNKNOWN,
                [
                    'action' => $action,
                    'reason' => 'required_event_replay_check_failed',
                    'previous' => get_class($exception),
                ]
            );
        } finally {
            $execution->close();
        }
    }

    private function assertExecutionContract(
        CashierV3BusinessEventExecution $execution,
        array $contract,
        ?array $events = null
    ): void
    {
        $events = $events === null ? $execution->emittedEvents() : $events;
        $rules = (array)($contract['event_rules'] ?? []);
        if (!$rules && $events) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING,
                'unexpected_event_for_eventless_action',
                $execution,
                implode(',', $execution->eventTypes())
            );
        }
        $counts = [];
        $eventsByKey = [];
        foreach ($events as $descriptor) {
            $type = trim((string)($descriptor['event_type'] ?? ''));
            if ($type === '' || !isset($rules[$type])) {
                throw self::failure(
                    CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING,
                    'event_type_not_allowed',
                    $execution,
                    $type
                );
            }
            $eventKey = trim((string)($descriptor['event_key'] ?? ''));
            if ($eventKey === '') {
                throw self::failure(
                    CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE,
                    'event_descriptor_identity_mismatch',
                    $execution,
                    $type
                );
            }
            if (isset($eventsByKey[$eventKey])) {
                $this->assertDescriptorEqualsStored($descriptor, $eventsByKey[$eventKey], $execution);
                continue;
            }
            $eventsByKey[$eventKey] = $descriptor;
            $counts[$type] = (int)($counts[$type] ?? 0) + 1;
            $this->assertEventRule($descriptor, $contract, $execution, $type);
            $this->assertDescriptorExecutionIdentity($descriptor, $contract, $execution);
        }
        foreach ($rules as $type => $rule) {
            $count = (int)($counts[$type] ?? 0);
            if ($count < (int)$rule['min_count']) {
                throw self::failure(
                    CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING,
                    'required_event_missing',
                    $execution,
                    (string)$type
                );
            }
            if ($count > (int)$rule['max_count']) {
                throw self::failure(
                    CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING,
                    'event_cardinality_exceeded',
                    $execution,
                    (string)$type
                );
            }
        }
    }

    private function assertEventRule(array $event, array $contract, CashierV3BusinessEventExecution $execution, string $type): void
    {
        $rule = $contract['event_rules'][$type] ?? null;
        if (!is_array($rule)) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING, 'event_rule_missing', $execution, $type);
        }
        if ($rule['aggregate_type'] !== ''
            && (string)($event['aggregate_type'] ?? '') !== (string)$rule['aggregate_type']) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING, 'event_aggregate_type_mismatch', $execution, $type);
        }
        if ($rule['source_type'] !== ''
            && (string)($event['source_type'] ?? '') !== (string)$rule['source_type']) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING, 'event_source_type_mismatch', $execution, $type);
        }
        if ($rule['aggregate_version'] !== null
            && (int)($event['aggregate_version'] ?? 0) !== (int)$rule['aggregate_version']) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_CONTRACT_MISSING, 'event_aggregate_version_mismatch', $execution, $type);
        }
    }

    private function assertDescriptorExecutionIdentity(
        array $descriptor,
        array $contract,
        CashierV3BusinessEventExecution $execution
    ): void {
        $scope = $execution->operatorScope();
        $type = (string)($descriptor['event_type'] ?? '');
        $sourceType = (string)($descriptor['source_type'] ?? '');
        $expectedRoute = $this->routeFingerprint($sourceType, $type, $contract);
        if ((string)($descriptor['command_idempotency_key'] ?? '') !== $execution->idempotencyKey()
            || (string)($descriptor['tenant_id'] ?? '') !== $scope->tenantId()
            || (string)($descriptor['organization_id'] ?? '') !== $scope->organizationId()
            || (int)($descriptor['store_id'] ?? 0) !== $scope->storeId()
            || (int)($descriptor['operator_id'] ?? 0) !== $scope->operatorId()
            || (int)($descriptor['event_version'] ?? 0) <= 0
            || (int)($descriptor['aggregate_version'] ?? 0) <= 0
            || !preg_match('/^[a-f0-9]{64}$/', (string)($descriptor['payload_sha256'] ?? ''))
            || (string)($descriptor['route_fingerprint'] ?? '') !== $expectedRoute
            || trim((string)($descriptor['organization_path'] ?? '')) === ''
            || trim((string)($descriptor['organization_name_snapshot'] ?? '')) === ''
            || trim((string)($descriptor['operator_name_snapshot'] ?? '')) === '') {
            throw self::failure(
                CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE,
                'event_descriptor_identity_mismatch',
                $execution,
                $type
            );
        }
    }

    private function assertDescriptorEqualsStored(
        array $descriptor,
        array $stored,
        CashierV3BusinessEventExecution $execution
    ): void {
        foreach ($this->immutableDescriptorFields() as $field) {
            if ((string)($descriptor[$field] ?? '') !== (string)($stored[$field] ?? '')) {
                throw self::failure(
                    CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE,
                    'event_descriptor_storage_mismatch',
                    $execution,
                    (string)($descriptor['event_type'] ?? ''),
                    null,
                    ['field' => $field]
                );
            }
        }
    }

    private function assertDuplicateImmutable(
        array $existing,
        array $candidate,
        CashierV3BusinessEventExecution $execution,
        string $type,
        \Throwable $previous
    ): void {
        $existingDescriptor = $this->descriptorFromRow($existing);
        $candidate['id'] = (int)($existing['id'] ?? 0);
        $candidateDescriptor = $this->descriptorFromRow($candidate);
        foreach ($this->immutableDescriptorFields() as $field) {
            if ((string)($existingDescriptor[$field] ?? '') !== (string)($candidateDescriptor[$field] ?? '')) {
                throw self::failure(
                    CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                    'event_key_conflict',
                    $execution,
                    $type,
                    $previous,
                    ['field' => $field]
                );
            }
        }
    }

    /** @return string[] */
    private function immutableDescriptorFields(): array
    {
        return [
            'event_id', 'event_key', 'event_type', 'event_version',
            'aggregate_type', 'aggregate_id', 'aggregate_version',
            'source_type', 'source_id', 'source_detail_id',
            'command_idempotency_key', 'reversal_of',
            'tenant_id', 'organization_id', 'organization_path',
            'store_id', 'member_id', 'operator_id', 'business_date',
            'occurred_at', 'settled_at', 'aggregate_name_snapshot',
            'organization_name_snapshot', 'store_name_snapshot',
            'operator_name_snapshot', 'payload', 'payload_sha256',
            'route_fingerprint',
        ];
    }

    private function descriptorFromRow(array $row): array
    {
        return [
            'event_id' => (int)($row['id'] ?? $row['event_id'] ?? 0),
            'event_no' => (string)($row['event_no'] ?? ''),
            'event_key' => (string)($row['event_key'] ?? ''),
            'event_type' => (string)($row['event_type'] ?? ''),
            'event_version' => (int)($row['event_version'] ?? 0),
            'aggregate_type' => (string)($row['aggregate_type'] ?? ''),
            'aggregate_id' => (string)($row['aggregate_id'] ?? ''),
            'aggregate_version' => (int)($row['aggregate_version'] ?? 0),
            'source_type' => (string)($row['source_type'] ?? ''),
            'source_id' => (string)($row['source_id'] ?? ''),
            'source_detail_id' => (string)($row['source_detail_id'] ?? ''),
            'command_idempotency_key' => (string)($row['command_idempotency_key'] ?? ''),
            'reversal_of' => (string)($row['reversal_of'] ?? ''),
            'tenant_id' => (string)($row['tenant_id'] ?? ''),
            'organization_id' => (string)($row['organization_id'] ?? ''),
            'organization_path' => (string)($row['organization_path'] ?? ''),
            'store_id' => (int)($row['store_id'] ?? 0),
            'member_id' => (int)($row['member_id'] ?? 0),
            'operator_id' => (int)($row['operator_id'] ?? 0),
            'business_date' => (string)($row['business_date'] ?? ''),
            'occurred_at' => (int)($row['occurred_at'] ?? 0),
            'settled_at' => (int)($row['settled_at'] ?? 0),
            'aggregate_name_snapshot' => (string)($row['aggregate_name_snapshot'] ?? ''),
            'organization_name_snapshot' => (string)($row['organization_name_snapshot'] ?? ''),
            'store_name_snapshot' => (string)($row['store_name_snapshot'] ?? ''),
            'operator_name_snapshot' => (string)($row['operator_name_snapshot'] ?? ''),
            'payload' => (string)($row['payload'] ?? ''),
            'payload_sha256' => (string)($row['payload_sha256'] ?? ''),
            'route_fingerprint' => (string)($row['route_fingerprint'] ?? ''),
        ];
    }

    private function executionDescriptorByEventKey(
        CashierV3BusinessEventExecution $execution,
        string $eventKey
    ): ?array {
        foreach ($execution->emittedEvents() as $descriptor) {
            if ((string)($descriptor['event_key'] ?? '') === $eventKey) {
                return $descriptor;
            }
        }
        return null;
    }

    /**
     * Resolve immutable organization and operator snapshots from locked
     * server-side sources. Handler/client supplied snapshot fields are ignored.
     *
     * @return array{organization_id:string,organization_path:string,organization_name_snapshot:string,operator_name_snapshot:string}
     */
    private function authoritativeDimensions(CashierV3BusinessEventExecution $execution, string $type): array
    {
        $scope = $execution->operatorScope();
        $dataScope = $execution->dataScope();
        if ($scope->storeId() <= 0
            || $dataScope->forcedStoreId() !== $scope->storeId()
            || $dataScope->operatorId() !== $scope->operatorId()
            || $dataScope->tenantId() !== $scope->tenantId()
            || $dataScope->organizationId() !== $scope->organizationId()) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'event_scope_snapshot_mismatch', $execution, $type);
        }

        try {
            $binding = Db::name('organization_store')
                ->where('store_id', $scope->storeId())
                ->lock(true)
                ->find();
        } catch (\Throwable $exception) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'organization_binding_unavailable', $execution, $type, $exception);
        }
        $organizationId = (int)($binding['org_id'] ?? 0);
        if ($organizationId <= 0 || (string)$organizationId !== $scope->organizationId()) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'store_organization_mismatch', $execution, $type);
        }

        $seen = [];
        $ids = [];
        $leafName = '';
        $currentId = $organizationId;
        for ($depth = 0; $depth < 64; $depth++) {
            if (isset($seen[$currentId])) {
                throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'organization_cycle_detected', $execution, $type);
            }
            $seen[$currentId] = true;
            try {
                $node = Db::name('organization')
                    ->where('id', $currentId)
                    ->where('is_del', 0)
                    ->lock(true)
                    ->find();
            } catch (\Throwable $exception) {
                throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'organization_node_unavailable', $execution, $type, $exception);
            }
            if (!$node) {
                throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'organization_node_missing', $execution, $type);
            }
            $name = trim((string)($node['name'] ?? ''));
            if ($name === '') {
                throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'organization_name_missing', $execution, $type);
            }
            if ($leafName === '') {
                $leafName = $name;
            }
            $ids[] = (string)$currentId;
            $parentId = (int)($node['pid'] ?? 0);
            if ($parentId === 0) {
                break;
            }
            if ($parentId < 0) {
                throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'organization_parent_invalid', $execution, $type);
            }
            $currentId = $parentId;
        }
        if (!$ids || (int)end($ids) !== $currentId) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'organization_depth_exceeded', $execution, $type);
        }
        $path = implode('/', array_reverse($ids));
        if (strlen($path) > 255) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'organization_path_too_long', $execution, $type);
        }

        $profile = $dataScope->operatorProfile();
        if (isset($profile['id']) && (int)$profile['id'] > 0 && (int)$profile['id'] !== $scope->operatorId()) {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'operator_snapshot_mismatch', $execution, $type);
        }
        $operatorName = '';
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $candidate = trim((string)($profile[$field] ?? ''));
            if ($candidate !== '') {
                $operatorName = $candidate;
                break;
            }
        }
        if ($operatorName === '') {
            throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, 'operator_name_snapshot_missing', $execution, $type);
        }

        return [
            'organization_id' => (string)$organizationId,
            'organization_path' => $path,
            'organization_name_snapshot' => mb_substr($leafName, 0, 128),
            'operator_name_snapshot' => mb_substr($operatorName, 0, 128),
        ];
    }

    private function positiveVersion($value, CashierV3BusinessEventExecution $execution, string $type, string $reason): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value)) {
            $normalized = (int)$value;
            if ($normalized > 0 && (string)$normalized === $value) {
                return $normalized;
            }
        }
        throw self::failure(CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE, $reason, $execution, $type);
    }

    private function routeFingerprint(string $sourceType, string $type, array $contract): string
    {
        return CashierV3EventRouteFingerprint::calculate(
            $sourceType,
            $type,
            (array)($contract['consumers'][$type] ?? [])
        );
    }

    private function isEventKeyDuplicate(\Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            $message = strtolower($cursor->getMessage());
            $is1062 = (int)$cursor->getCode() === 1062 || strpos($message, '1062') !== false;
            if ($is1062 && strpos($message, 'uk_event_key') !== false) {
                return true;
            }
        }
        return false;
    }

    private function insertOutbox(
        int $eventId,
        string $eventNo,
        string $consumerCode,
        CashierV3OperatorScope $scope,
        array $eventRow,
        int $now
    ): void
    {
        $consumerCode = trim($consumerCode);
        if ($consumerCode === '') {
            throw new \LogicException('consumer code cannot be empty');
        }
        $inserted = Db::name(self::OUTBOX_TABLE)->insert([
            'event_id' => $eventId,
            'event_no' => $eventNo,
            'consumer_code' => $consumerCode,
            'tenant_id' => $scope->tenantId(),
            'organization_id' => $scope->organizationId(),
            'organization_path' => (string)($eventRow['organization_path'] ?? ''),
            'store_id' => $scope->storeId(),
            'member_id' => (int)($eventRow['member_id'] ?? 0),
            'operator_id' => $scope->operatorId(),
            'status' => 10,
            'attempts' => 0,
            'available_at' => $now,
            'lease_until' => 0,
            'lease_token' => '',
            'fencing_generation' => 0,
            'last_error' => '',
            'last_error_at' => 0,
            'processed_at' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ((int)$inserted !== 1) {
            throw new \RuntimeException('outbox insert returned non-one');
        }
    }

    private function assertOutboxRoutesPersisted(
        int $eventId,
        string $eventType,
        array $contract,
        CashierV3BusinessEventExecution $execution
    ): void {
        $expected = array_values((array)($contract['consumers'][$eventType] ?? []));
        sort($expected, SORT_STRING);
        $rows = Db::name(self::OUTBOX_TABLE)
            ->where('event_id', $eventId)
            ->field('consumer_code')
            ->lock(true)
            ->select()
            ->toArray();
        $actual = array_values(array_map(function (array $row): string {
            return (string)($row['consumer_code'] ?? '');
        }, $rows));
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE,
                'event_outbox_route_incomplete',
                $execution,
                $eventType,
                null,
                ['event_id' => $eventId, 'expected_consumers' => $expected, 'actual_consumers' => $actual]
            );
        }
    }

    private function eventKey(string $type, string $aggregateType, string $aggregateId, int $version, string $detailId, string $reversalOf): string
    {
        $key = $type . ':' . $aggregateType . ':' . $aggregateId . ':' . $version;
        if ($detailId !== '') {
            $key .= ':' . $detailId;
        }
        if ($reversalOf !== '') {
            $key .= ':reversal_of:' . $reversalOf;
        }
        if (strlen($key) > 255) {
            $suffix = ':sha256:' . hash('sha256', $key);
            $key = substr($key, 0, 255 - strlen($suffix)) . $suffix;
        }
        return $key;
    }

    private function newEventNo(): string
    {
        try {
            return 'EV-' . bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::SECURE_RANDOM_UNAVAILABLE,
                '系统无法生成安全事件编号，本次操作已取消。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
    }

    private function businessDate(int $timestamp): string
    {
        $date = new \DateTimeImmutable('@' . $timestamp);
        return $date->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    }

    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        if ($this->isAssoc($value)) {
            ksort($value);
        }
        return $value;
    }

    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private static function failure(
        string $code,
        string $reason,
        CashierV3BusinessEventExecution $execution,
        string $type = '',
        $previous = null,
        array $extra = []
    ): CashierV3CommandException
    {
        $detail = array_merge(
            ['action' => $execution->action(), 'reason' => $reason, 'event_type' => $type],
            $extra
        );
        if ($previous instanceof \Throwable) {
            $detail['previous'] = get_class($previous);
        }
        return new CashierV3CommandException(
            $code,
            '业务事件记录未完成，本次操作已回滚，请重试。',
            CashierV3ResultCode::STATUS_FAILED,
            $detail
        );
    }
}
