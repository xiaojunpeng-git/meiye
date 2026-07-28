<?php

namespace app\services\cashier\v3\event;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Generic Outbox relay state machine.
 *
 * MySQL 5.6 has no SKIP LOCKED. Candidate ids are therefore selected without
 * range locks and each row is then locked by primary key and revalidated. Every
 * accepted state transition and its append-only audit row share one transaction.
 */
final class CashierV3OutboxServices
{
    public const STATUS_PENDING = 10;
    public const STATUS_PROCESSING = 20;
    public const STATUS_RETRY_WAIT = 30;
    public const STATUS_SUCCEEDED = 40;
    public const STATUS_MANUAL_FAILED = 50;

    public const LEASE_SECONDS = 60;
    public const RENEW_AFTER_SECONDS = 20;
    public const MAX_ATTEMPTS = 8;

    private const BACKOFF = [5, 15, 60, 300, 900, 1800, 3600, 7200];

    /** @var CashierV3EventConsumerRegistry */
    private $consumerRegistry;

    /**
     * No production consumer is registered by default. The first real relay
     * must register its callable implementation in the shared composition.
     */
    public function __construct(CashierV3EventConsumerRegistry $consumerRegistry)
    {
        $this->consumerRegistry = $consumerRegistry;
    }

    public function consumerRegistry(): CashierV3EventConsumerRegistry
    {
        return $this->consumerRegistry;
    }

    /** @return array<int,array> */
    public function claim(string $consumerCode, string $workerId, int $limit = 50): array
    {
        $consumerCode = $this->consumerCode($consumerCode);
        if (!$this->consumerRegistry->isFrozen()) {
            throw $this->stateFailure('consumer_registry_not_frozen');
        }
        if (!$this->consumerRegistry->has($consumerCode)) {
            throw $this->stateFailure('consumer_not_registered', ['consumer_code' => $consumerCode]);
        }
        $workerId = $this->workerId($workerId);
        $limit = min(50, max(1, $limit));

        return Db::transaction(function () use ($consumerCode, $workerId, $limit) {
            $candidateIds = $this->candidateIds($consumerCode, $limit);
            $claimed = [];
            foreach ($candidateIds as $id) {
                $row = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                    ->where('id', $id)
                    ->lock(true)
                    ->find();
                $databaseNow = $this->databaseNow();
                if (!$row || !$this->isClaimable($row, $consumerCode, $databaseNow)) {
                    continue;
                }

                // A crashed worker never calls fail(). After the eighth real
                // lease expires, stop before issuing a ninth processing lease.
                if ($this->mustStopExpiredLease($row, $databaseNow)) {
                    $generation = (int)($row['fencing_generation'] ?? 0) + 1;
                    $terminalAttempts = max(self::MAX_ATTEMPTS, (int)($row['attempts'] ?? 0));
                    $affected = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                        ->where('id', $id)
                        ->where('consumer_code', $consumerCode)
                        ->where('status', self::STATUS_PROCESSING)
                        ->where('fencing_generation', (int)($row['fencing_generation'] ?? 0))
                        ->where('lease_until', '<=', Db::raw('UNIX_TIMESTAMP()'))
                        ->update([
                            'status' => self::STATUS_MANUAL_FAILED,
                            'attempts' => $terminalAttempts,
                            'available_at' => 0,
                            'lease_until' => 0,
                            'lease_owner' => '',
                            'lease_token' => '',
                            'fencing_generation' => $generation,
                            'last_error' => 'lease_expired_after_max_attempts',
                            'last_error_at' => Db::raw('UNIX_TIMESTAMP()'),
                            'processed_at' => 0,
                            'updated_at' => Db::raw('UNIX_TIMESTAMP()'),
                        ]);
                    if ((int)$affected !== 1) {
                        throw $this->stateFailure('expired_lease_terminal_cas_failed', ['outbox_id' => $id]);
                    }
                    $fresh = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                        ->where('id', $id)
                        ->find();
                    if (!$fresh) {
                        throw $this->stateFailure('expired_lease_terminal_row_missing', ['outbox_id' => $id]);
                    }
                    $this->appendAttempt(
                        $fresh,
                        'lease_expired_manual_failed',
                        $workerId,
                        '',
                        'lease_expired_after_max_attempts'
                    );
                    continue;
                }

                $token = $this->token();
                $generation = (int)($row['fencing_generation'] ?? 0) + 1;
                $affected = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                    ->where('id', $id)
                    ->where('consumer_code', $consumerCode)
                    ->where('status', (int)$row['status'])
                    ->where('fencing_generation', (int)($row['fencing_generation'] ?? 0))
                    ->update([
                        'status' => self::STATUS_PROCESSING,
                        'attempts' => Db::raw('attempts + 1'),
                        'lease_until' => Db::raw('UNIX_TIMESTAMP() + ' . self::LEASE_SECONDS),
                        'lease_owner' => $workerId,
                        'lease_token' => $token,
                        'fencing_generation' => $generation,
                        'last_error' => '',
                        'updated_at' => Db::raw('UNIX_TIMESTAMP()'),
                    ]);
                if ((int)$affected !== 1) {
                    continue;
                }

                $fresh = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                    ->where('id', $id)
                    ->find();
                if (!$fresh) {
                    throw $this->stateFailure('claimed_row_missing', ['outbox_id' => $id]);
                }
                $this->appendAttempt($fresh, 'claim', $workerId, $token);
                $claimed[] = $fresh;
                if (count($claimed) >= $limit) {
                    break;
                }
            }
            return $claimed;
        });
    }

    public function renew(array $lease): bool
    {
        return $this->transition($lease, 'renew', function (array $row): array {
            return [
                'lease_until' => Db::raw('GREATEST(lease_until + 1, UNIX_TIMESTAMP() + ' . self::LEASE_SECONDS . ')'),
                'updated_at' => Db::raw('UNIX_TIMESTAMP()'),
            ];
        });
    }

    public function ack(array $lease): bool
    {
        return $this->transition($lease, 'ack', function (array $row): array {
            $once = Db::name('cashier_v3_consumer_once')
                ->where('event_id', (int)$row['event_id'])
                ->where('consumer_code', (string)$row['consumer_code'])
                ->where('status', 1)
                ->lock(true)
                ->find();
            if (!$once) {
                throw $this->stateFailure('consumer_once_required_before_ack', [
                    'outbox_id' => (int)$row['id'],
                    'event_id' => (int)$row['event_id'],
                ]);
            }
            return [
                'status' => self::STATUS_SUCCEEDED,
                'lease_until' => 0,
                'lease_owner' => '',
                'lease_token' => '',
                'processed_at' => Db::raw('UNIX_TIMESTAMP()'),
                'updated_at' => Db::raw('UNIX_TIMESTAMP()'),
            ];
        }, true);
    }

    public function fail(array $lease, string $message): bool
    {
        return $this->transition($lease, 'fail', function (array $row) use ($message): array {
            $attempts = max(1, (int)($row['attempts'] ?? 1));
            $manual = $attempts >= self::MAX_ATTEMPTS;
            $delay = self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)];
            return [
                'status' => $manual ? self::STATUS_MANUAL_FAILED : self::STATUS_RETRY_WAIT,
                'available_at' => $manual ? 0 : Db::raw('UNIX_TIMESTAMP() + ' . $delay),
                'lease_until' => 0,
                'lease_owner' => '',
                'lease_token' => '',
                'last_error' => mb_substr($message, 0, 255),
                'last_error_at' => Db::raw('UNIX_TIMESTAMP()'),
                'updated_at' => Db::raw('UNIX_TIMESTAMP()'),
            ];
        }, false, $message);
    }

    /**
     * Execute the registered database consumer and persist its once marker atomically.
     *
     * The caller must wrap this method in Db::transaction(), and the registered
     * consumer may only perform database work on that same connection. External HTTP,
     * payment or notification effects need their own idempotency contract.
     * A stale worker is rejected before the marker or consumer can run. This
     * method returns false only when a prior successful marker exists. A newly
     * executed consumer must return literal true; false/null never commits once.
     */
    public function consumeOnceInTx(
        array $lease,
        string $effectKey,
        array $metadata = []
    ): bool {
        CashierV3TransactionGuard::assertInTransaction('outbox.consumeOnce');
        $effectKey = trim($effectKey);
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $effectKey)) {
            throw $this->stateFailure('effect_key_invalid');
        }

        $row = $this->lockValidLease($lease);
        if (!$row) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::OUTBOX_LEASE_CONFLICT,
                '投递任务已被其他处理器接管，本次处理已取消。',
                CashierV3ResultCode::STATUS_FAILED,
                ['outbox_id' => (int)($lease['id'] ?? 0)]
            );
        }
        $event = $this->loadAndValidateEvent($row);
        $consumer = $this->consumerRegistry->requireConsumer((string)$row['consumer_code']);

        $existing = Db::name('cashier_v3_consumer_once')
            ->where('event_id', (int)$row['event_id'])
            ->where('consumer_code', (string)$row['consumer_code'])
            ->lock(true)
            ->find();
        if ($existing) {
            if ((int)($existing['status'] ?? 0) === 1
                && (string)($existing['effect_key'] ?? '') === $effectKey) {
                return false;
            }
            throw $this->stateFailure('consumer_once_conflict', [
                'outbox_id' => (int)$row['id'],
                'event_id' => (int)$row['event_id'],
            ]);
        }

        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw $this->stateFailure('consumer_metadata_encode_failed');
        }
        $now = $this->databaseNow();
        try {
            $inserted = Db::name('cashier_v3_consumer_once')->insert([
                'event_id' => (int)$row['event_id'],
                'consumer_code' => (string)$row['consumer_code'],
                'effect_key' => $effectKey,
                'status' => 1,
                'metadata' => $json,
                'succeeded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ((int)$inserted !== 1) {
                throw $this->stateFailure('consumer_once_insert_non_one');
            }
        } catch (CashierV3CommandException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (!$this->isConsumerOnceDuplicate($e)) {
                throw $e;
            }
            $same = Db::name('cashier_v3_consumer_once')
                ->where('event_id', (int)$row['event_id'])
                ->where('consumer_code', (string)$row['consumer_code'])
                ->where('effect_key', $effectKey)
                ->where('status', 1)
                ->lock(true)
                ->find();
            if ($same) {
                return false;
            }
            throw $this->stateFailure('consumer_once_unique_conflict');
        }

        if ($consumer($event) !== true) {
            throw $this->stateFailure('consumer_effect_not_confirmed');
        }
        return true;
    }

    /**
     * Manual recovery is only legal from MANUAL_FAILED. If the once marker
     * already exists it is ACK-only; otherwise the row returns to PENDING.
     */
    public function manualRequeue(
        int $outboxId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $reason
    ): bool
    {
        $reason = trim($reason);
        if ($outboxId <= 0 || $reason === '') {
            throw new \InvalidArgumentException('outboxId and reason are required');
        }

        return (bool)Db::transaction(function () use ($outboxId, $operatorScope, $dataScope, $reason) {
            $row = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                ->where('id', $outboxId)
                ->lock(true)
                ->find();
            if (!$row) {
                return false;
            }
            $this->assertManualRecoveryScope($row, $operatorScope, $dataScope);
            $auditWorker = 'operator:' . $operatorScope->operatorId();
            if ((int)$row['status'] !== self::STATUS_MANUAL_FAILED) {
                $this->appendAttempt($row, 'manual_requeue_rejected', $auditWorker, '', 'illegal_from_status:' . (int)$row['status']);
                return false;
            }

            $once = Db::name('cashier_v3_consumer_once')
                ->where('event_id', (int)$row['event_id'])
                ->where('consumer_code', (string)$row['consumer_code'])
                ->where('status', 1)
                ->lock(true)
                ->find();
            $ackOnly = (bool)$once;
            $affected = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                ->where('id', $outboxId)
                ->where('status', self::STATUS_MANUAL_FAILED)
                ->where('fencing_generation', (int)$row['fencing_generation'])
                ->update([
                    'status' => $ackOnly ? self::STATUS_SUCCEEDED : self::STATUS_PENDING,
                    'available_at' => $ackOnly ? 0 : Db::raw('UNIX_TIMESTAMP()'),
                    'lease_until' => 0,
                    'lease_owner' => '',
                    'lease_token' => '',
                    'last_error' => '',
                    'processed_at' => $ackOnly ? Db::raw('UNIX_TIMESTAMP()') : 0,
                    'updated_at' => Db::raw('UNIX_TIMESTAMP()'),
                ]);
            if ((int)$affected !== 1) {
                throw $this->stateFailure('manual_requeue_cas_failed', ['outbox_id' => $outboxId]);
            }
            $fresh = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)->where('id', $outboxId)->find();
            $this->appendAttempt(
                $fresh ?: $row,
                $ackOnly ? 'manual_ack' : 'manual_requeue',
                $auditWorker,
                '',
                $reason
            );
            return true;
        });
    }

    private function assertManualRecoveryScope(
        array $row,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $authorized = $operatorScope->operatorId() === $dataScope->operatorId()
            && $operatorScope->tenantId() === $dataScope->tenantId()
            && (string)($row['tenant_id'] ?? '') === $operatorScope->tenantId()
            && $this->consumerRegistry->isFrozen()
            && $this->consumerRegistry->has((string)($row['consumer_code'] ?? ''))
            && $dataScope->hasFeature('cashier.v3.management_center')
            && $dataScope->allowsStore((int)($row['store_id'] ?? 0));
        if (!$authorized) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权处理该投递任务。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'outbox_manual_recovery_scope_denied']
            );
        }
    }

    /** @return int[] */
    private function candidateIds(string $consumerCode, int $limit): array
    {
        $ids = [];
        foreach ([self::STATUS_PENDING, self::STATUS_RETRY_WAIT] as $status) {
            $rows = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                ->where('consumer_code', $consumerCode)
                ->where('status', $status)
                ->where('available_at', '<=', Db::raw('UNIX_TIMESTAMP()'))
                ->order('available_at asc,id asc')
                ->limit($limit)
                ->column('id');
            foreach ((array)$rows as $id) {
                $ids[(int)$id] = (int)$id;
            }
        }
        $expired = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
            ->where('consumer_code', $consumerCode)
            ->where('status', self::STATUS_PROCESSING)
            ->where('lease_until', '<=', Db::raw('UNIX_TIMESTAMP()'))
            ->order('lease_until asc,id asc')
            ->limit($limit)
            ->column('id');
        foreach ((array)$expired as $id) {
            $ids[(int)$id] = (int)$id;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return array_slice($ids, 0, $limit);
    }

    private function isClaimable(array $row, string $consumerCode, int $now): bool
    {
        if ((string)($row['consumer_code'] ?? '') !== $consumerCode) {
            return false;
        }
        $status = (int)($row['status'] ?? 0);
        if ($status === self::STATUS_PENDING || $status === self::STATUS_RETRY_WAIT) {
            return (int)($row['available_at'] ?? 0) <= $now;
        }
        return $status === self::STATUS_PROCESSING && (int)($row['lease_until'] ?? 0) <= $now;
    }

    private function mustStopExpiredLease(array $row, int $now): bool
    {
        return (int)($row['status'] ?? 0) === self::STATUS_PROCESSING
            && (int)($row['lease_until'] ?? 0) <= $now
            && (int)($row['attempts'] ?? 0) >= self::MAX_ATTEMPTS;
    }

    private function transition(
        array $lease,
        string $operation,
        callable $dataBuilder,
        bool $stateFailureAsRejection = false,
        string $message = ''
    ): bool {
        return (bool)Db::transaction(function () use ($lease, $operation, $dataBuilder, $stateFailureAsRejection, $message) {
            $id = (int)($lease['id'] ?? 0);
            if ($id <= 0) {
                return false;
            }
            $row = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                ->where('id', $id)
                ->lock(true)
                ->find();
            if (!$row || !$this->leaseMatches($row, $lease, $this->databaseNow())) {
                if ($row) {
                    $auditRow = $row;
                    $auditRow['fencing_generation'] = (int)($lease['fencing_generation'] ?? 0);
                    $auditRow['attempts'] = (int)($lease['attempts'] ?? $row['attempts'] ?? 0);
                    $this->appendAttempt(
                        $auditRow,
                        $operation . '_rejected',
                        (string)($lease['lease_owner'] ?? ''),
                        (string)($lease['lease_token'] ?? ''),
                        'lease_conflict_or_expired'
                    );
                }
                return false;
            }

            try {
                $data = $dataBuilder($row);
            } catch (CashierV3CommandException $e) {
                if (!$stateFailureAsRejection) {
                    throw $e;
                }
                $this->appendAttempt($row, $operation . '_rejected', (string)$row['lease_owner'], (string)$row['lease_token'], $e->getMessage());
                return false;
            }
            $affected = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
                ->where('id', $id)
                ->where('status', self::STATUS_PROCESSING)
                ->where('lease_owner', (string)$row['lease_owner'])
                ->where('lease_token', (string)$row['lease_token'])
                ->where('fencing_generation', (int)$row['fencing_generation'])
                ->where('lease_until', '>', Db::raw('UNIX_TIMESTAMP()'))
                ->update($data);
            if ((int)$affected !== 1) {
                throw $this->stateFailure('fenced_transition_cas_failed', ['outbox_id' => $id, 'operation' => $operation]);
            }
            $this->appendAttempt($row, $operation, (string)$row['lease_owner'], (string)$row['lease_token'], $message);
            return true;
        });
    }

    private function lockValidLease(array $lease)
    {
        $id = (int)($lease['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $row = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
            ->where('id', $id)
            ->lock(true)
            ->find();
        return $row && $this->leaseMatches($row, $lease, $this->databaseNow()) ? $row : null;
    }

    private function leaseMatches(array $row, array $lease, int $now): bool
    {
        return (int)($row['status'] ?? 0) === self::STATUS_PROCESSING
            && (int)($row['id'] ?? 0) === (int)($lease['id'] ?? 0)
            && (int)($row['event_id'] ?? 0) === (int)($lease['event_id'] ?? 0)
            && (string)($row['consumer_code'] ?? '') === (string)($lease['consumer_code'] ?? '')
            && $this->consumerRegistry->isFrozen()
            && $this->consumerRegistry->has((string)($row['consumer_code'] ?? ''))
            && (string)($row['lease_owner'] ?? '') !== ''
            && (string)($row['lease_owner'] ?? '') === (string)($lease['lease_owner'] ?? '')
            && (string)($row['lease_token'] ?? '') !== ''
            && (string)($row['lease_token'] ?? '') === (string)($lease['lease_token'] ?? '')
            && (int)($row['fencing_generation'] ?? 0) > 0
            && (int)($row['fencing_generation'] ?? 0) === (int)($lease['fencing_generation'] ?? 0)
            && (int)($row['lease_until'] ?? 0) > $now;
    }

    private function loadAndValidateEvent(array $outbox): array
    {
        $event = Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)
            ->where('id', (int)$outbox['event_id'])
            ->find();
        if (!$event
            || !preg_match('/^EV-[A-Fa-f0-9]{32}$/', (string)($event['event_no'] ?? ''))
            || (string)($event['event_no'] ?? '') !== (string)($outbox['event_no'] ?? '')
            || trim((string)($event['event_type'] ?? '')) === ''
            || trim((string)($event['source_type'] ?? '')) === ''
            || trim((string)($event['aggregate_type'] ?? '')) === ''
            || trim((string)($event['aggregate_id'] ?? '')) === ''
            || (int)($event['event_version'] ?? 0) <= 0
            || (int)($event['aggregate_version'] ?? 0) <= 0
            || (string)($event['tenant_id'] ?? '') !== (string)($outbox['tenant_id'] ?? '')
            || (string)($event['organization_id'] ?? '') !== (string)($outbox['organization_id'] ?? '')
            || (string)($event['organization_path'] ?? '') !== (string)($outbox['organization_path'] ?? '')
            || (int)($event['store_id'] ?? 0) !== (int)($outbox['store_id'] ?? 0)
            || (int)($event['member_id'] ?? 0) !== (int)($outbox['member_id'] ?? 0)
            || (int)($event['operator_id'] ?? 0) !== (int)($outbox['operator_id'] ?? 0)) {
            throw $this->stateFailure('event_scope_or_identity_mismatch', ['outbox_id' => (int)$outbox['id']]);
        }
        $payload = (string)($event['payload'] ?? '');
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)
            || !hash_equals((string)($event['payload_sha256'] ?? ''), hash('sha256', $payload))) {
            throw $this->stateFailure('event_payload_invalid', ['event_id' => (int)$event['id']]);
        }
        $consumers = Db::name(CashierV3BusinessEventRecorder::OUTBOX_TABLE)
            ->where('event_id', (int)$event['id'])
            ->order('id asc')
            ->column('consumer_code');
        $consumers = array_values(array_map('strval', (array)$consumers));
        if (!in_array((string)$outbox['consumer_code'], $consumers, true)) {
            throw $this->stateFailure('consumer_route_missing', ['event_id' => (int)$event['id']]);
        }
        $expectedRoute = CashierV3EventRouteFingerprint::calculate(
            (string)$event['source_type'],
            (string)$event['event_type'],
            $consumers
        );
        if (!hash_equals((string)($event['route_fingerprint'] ?? ''), $expectedRoute)) {
            throw $this->stateFailure('event_route_fingerprint_invalid', ['event_id' => (int)$event['id']]);
        }
        $event['decoded_payload'] = $decoded;
        return $event;
    }

    private function appendAttempt(array $row, string $operation, string $workerId, string $leaseToken, string $message = ''): void
    {
        $inserted = Db::name('cashier_v3_outbox_attempt')->insert([
            'outbox_id' => (int)($row['id'] ?? 0),
            'event_id' => (int)($row['event_id'] ?? 0),
            'consumer_code' => (string)($row['consumer_code'] ?? ''),
            'fencing_generation' => (int)($row['fencing_generation'] ?? 0),
            'attempt_no' => (int)($row['attempts'] ?? 0),
            'operation' => mb_substr($operation, 0, 32),
            'worker_id' => mb_substr($workerId, 0, 64),
            'lease_token' => mb_substr($leaseToken, 0, 64),
            'error_message' => mb_substr($message, 0, 255),
            'occurred_at' => $this->databaseNow(),
            'created_at' => $this->databaseNow(),
        ]);
        if ((int)$inserted !== 1) {
            throw $this->stateFailure('attempt_audit_insert_non_one', ['outbox_id' => (int)($row['id'] ?? 0)]);
        }
    }

    private function consumerCode(string $consumerCode): string
    {
        $consumerCode = trim($consumerCode);
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $consumerCode)) {
            throw new \InvalidArgumentException('consumerCode is invalid');
        }
        return $consumerCode;
    }

    private function workerId(string $workerId): string
    {
        $workerId = trim($workerId);
        if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $workerId)) {
            throw new \InvalidArgumentException('workerId is invalid');
        }
        return $workerId;
    }

    private function isConsumerOnceDuplicate(\Throwable $e): bool
    {
        for ($cursor = $e; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            $message = strtolower($cursor->getMessage());
            $isDuplicate = (int)$cursor->getCode() === 1062 || strpos($message, '1062') !== false;
            if ($isDuplicate
                && (strpos($message, 'uk_event_consumer') !== false
                    || strpos($message, 'uk_consumer_effect') !== false)) {
                return true;
            }
        }
        return false;
    }

    private function databaseNow(): int
    {
        $row = Db::query('SELECT UNIX_TIMESTAMP() AS now_value');
        return max(1, (int)($row[0]['now_value'] ?? 0));
    }

    private function token(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::SECURE_RANDOM_UNAVAILABLE,
                '系统无法生成投递租约，本次投递已停止。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
    }

    private function stateFailure(string $reason, array $detail = []): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::OUTBOX_STATE_INVALID,
            '投递任务状态异常，本次处理已停止。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason] + $detail
        );
    }
}
