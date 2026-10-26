<?php

namespace app\services\cashier\v3\service;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionOccupationWriter;

/**
 * Converts C3 service-order occupation inside the caller-owned checkout transaction.
 *
 * Legacy reservation occupation has no equivalent guarded, versioned write authority,
 * so that source remains deliberately fail-closed.
 */
final class ThinkPhpCashierV3EntitlementCompletionOccupationWriter
    implements CashierV3EntitlementCompletionOccupationWriter
{
    private const OPERATION_TYPE = 'entitlement_completion';

    /** @var CashierV3ServiceOrderRepository */
    private $repository;

    public function __construct(CashierV3ServiceOrderRepository $repository)
    {
        $this->repository = $repository;
    }

    public function convertInTx(array $context, array $deductions): array
    {
        CashierV3TransactionGuard::assertInTransaction('serviceOrderEntitlementCompletion.convertInTx');
        $context = $this->normalizeContext($context);
        $deductions = $this->normalizeDeductions($deductions);
        $expected = array_sum(array_column($deductions, 'convert_current_source_occupied_times'));

        if ($context['source_type'] === 'direct') {
            if ($expected !== 0) {
                throw self::failure('direct_occupation_conversion_forbidden');
            }
            return $this->publicResult('direct', 0, 0);
        }
        if ($context['source_type'] === 'reservation') {
            throw self::failure('reservation_occupation_conversion_not_supported');
        }
        if ($expected <= 0) {
            throw self::failure('service_order_occupation_conversion_empty');
        }

        $serviceOrderId = $context['service_order_id'];
        $fingerprint = $this->requestFingerprint($context, $deductions);
        $commandKey = $this->operationCommandKey($context);

        // All guards are acquired before any order lock. Sorting prevents two
        // multi-entitlement completions from taking the guard set in reverse.
        $guards = [];
        foreach ($deductions as $deduction) {
            if ($deduction['convert_current_source_occupied_times'] === 0) {
                continue;
            }
            $detailId = $deduction['source_detail_id'];
            $guard = $this->repository->lockOrCreateEntitlementGuard(
                $context['tenant_id'],
                $detailId
            );
            if ((string)($guard['tenant_id'] ?? '') !== $context['tenant_id']
                || (int)($guard['entitlement_source_detail_id'] ?? 0) !== $detailId
                || (int)($guard['current_version'] ?? 0) <= 0) {
                throw self::failure('completion_entitlement_guard_invalid', [
                    'entitlementSourceDetailId' => $detailId,
                ]);
            }
            $guards[$detailId] = $guard;
        }

        $existing = $this->repository->findOperation($context['tenant_id'], $commandKey);
        if ($existing !== null) {
            return $this->replayResult($existing, $context, $fingerprint, $expected);
        }

        $lockedOrder = null;
        $lockedLines = null;
        foreach (array_keys($guards) as $detailId) {
            $set = $this->repository->lockOccupationSet(
                $context['tenant_id'],
                $detailId,
                $serviceOrderId
            );
            $order = $this->currentOrder($set, $context);
            $lines = $this->currentOrderLines($set, $context);
            if ($lockedOrder === null) {
                $lockedOrder = $order;
                $lockedLines = $lines;
                continue;
            }
            if ($this->orderLockSnapshot($lockedOrder) !== $this->orderLockSnapshot($order)
                || $this->lineLockSnapshot($lockedLines) !== $this->lineLockSnapshot($lines)) {
                throw self::failure('service_order_lock_snapshot_changed');
            }
        }
        if ($lockedOrder === null || $lockedLines === null) {
            throw self::failure('service_order_occupation_lock_missing');
        }

        $statusBefore = (string)$lockedOrder['status'];
        $orderVersionBefore = (int)$lockedOrder['version'];
        if (!CashierV3ServiceOrderState::isActiveOrder($statusBefore)
            || $orderVersionBefore <= 0
            || $orderVersionBefore >= PHP_INT_MAX
            || (int)($lockedOrder['completed_at'] ?? 0) !== 0) {
            throw self::failure('service_order_not_completion_eligible');
        }

        $occupiedBefore = $this->activeOccupiedTimes($lockedLines);
        $detailAudits = [];
        foreach ($deductions as $deduction) {
            $toRelease = $deduction['convert_current_source_occupied_times'];
            if ($toRelease === 0) {
                continue;
            }
            $detailId = $deduction['source_detail_id'];
            $available = 0;
            foreach ($lockedLines as $line) {
                if ((int)$line['entitlement_source_detail_id'] === $detailId
                    && CashierV3ServiceOrderState::isActiveLine((string)$line['status'])) {
                    $available += (int)$line['occupied_times'];
                }
            }
            if ($available < $toRelease) {
                throw self::failure('service_order_occupation_release_exceeds_active', [
                    'entitlementSourceDetailId' => $detailId,
                    'requested' => $toRelease,
                    'active' => $available,
                ]);
            }

            $remaining = $toRelease;
            foreach ($lockedLines as $lineId => $line) {
                if ($remaining === 0
                    || (int)$line['entitlement_source_detail_id'] !== $detailId
                    || !CashierV3ServiceOrderState::isActiveLine((string)$line['status'])) {
                    continue;
                }
                $before = (int)$line['occupied_times'];
                $released = min($remaining, $before);
                $after = $before - $released;
                $version = (int)$line['version'];
                $statusAfter = $after === 0
                    ? CashierV3ServiceOrderState::LINE_RELEASED
                    : CashierV3ServiceOrderState::LINE_ACTIVE;
                if (!$this->repository->updateLineCas(
                    $context['tenant_id'],
                    $lineId,
                    $version,
                    [
                        'occupied_times' => $after,
                        'status' => $statusAfter,
                        'version' => $version + 1,
                        'updated_at' => $context['recorded_at'],
                    ]
                )) {
                    throw self::failure('service_order_line_version_conflict', ['lineId' => $lineId]);
                }
                $lockedLines[$lineId]['occupied_times'] = $after;
                $lockedLines[$lineId]['status'] = $statusAfter;
                $lockedLines[$lineId]['version'] = $version + 1;
                $remaining -= $released;
            }
            if ($remaining !== 0) {
                throw self::failure('service_order_occupation_release_incomplete', [
                    'entitlementSourceDetailId' => $detailId,
                    'remaining' => $remaining,
                ]);
            }
            $detailAudits[$detailId] = [
                'sourceDetailId' => $detailId,
                'convertedTimes' => $toRelease,
                'guardVersionBefore' => (int)$guards[$detailId]['current_version'],
                'guardVersionAfter' => 0,
            ];
        }

        $occupiedAfter = $this->activeOccupiedTimes($lockedLines);
        $allActiveReleased = $occupiedAfter === 0;
        $statusAfter = $allActiveReleased
            ? CashierV3ServiceOrderState::COMPLETED
            : $statusBefore;
        if ($allActiveReleased) {
            CashierV3ServiceOrderState::assertBusinessCompletionTransition($statusBefore, true);
        }
        $orderVersionAfter = $orderVersionBefore + 1;
        $orderFields = [
            'status' => $statusAfter,
            'version' => $orderVersionAfter,
            'updated_at' => $context['recorded_at'],
        ];
        if ($allActiveReleased) {
            $orderFields['completed_at'] = $context['settled_at'];
        }
        if (!$this->repository->updateServiceOrderCas(
            $context['tenant_id'],
            $serviceOrderId,
            $orderVersionBefore,
            $orderFields
        )) {
            throw self::failure('service_order_version_conflict', ['serviceOrderId' => $serviceOrderId]);
        }

        foreach ($detailAudits as $detailId => &$audit) {
            $audit['guardVersionAfter'] = $this->repository->bumpEntitlementGuardCas(
                $context['tenant_id'],
                $detailId,
                $audit['guardVersionBefore'],
                'entitlement_completion',
                $context['recorded_at']
            );
        }
        unset($audit);

        $result = $this->publicResult('service_order', $serviceOrderId, $expected);
        $operationPayload = [
            'contractVersion' => CashierV3EntitlementCompletionOccupationWriter::CONTRACT_VERSION,
            'requestFingerprint' => $fingerprint,
            'publicResult' => $result,
            'serviceOrder' => [
                'id' => $serviceOrderId,
                'statusBefore' => $statusBefore,
                'statusAfter' => $statusAfter,
                'versionBefore' => $orderVersionBefore,
                'versionAfter' => $orderVersionAfter,
                'occupiedTimesBefore' => $occupiedBefore,
                'occupiedTimesAfter' => $occupiedAfter,
            ],
            'details' => array_values($detailAudits),
        ];
        $singleAudit = count($detailAudits) === 1 ? reset($detailAudits) : null;
        $this->repository->insertOperation([
            'operation_key' => $this->operationKey($context),
            'command_idempotency_key' => $commandKey,
            'request_fingerprint' => $fingerprint,
            'tenant_id' => $context['tenant_id'],
            'business_store_id' => $context['store_id'],
            'operation_type' => self::OPERATION_TYPE,
            'service_order_id' => $serviceOrderId,
            'line_id' => 0,
            'entitlement_source_detail_id' => $singleAudit ? $singleAudit['sourceDetailId'] : 0,
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
            'service_order_version_before' => $orderVersionBefore,
            'service_order_version_after' => $orderVersionAfter,
            'line_version_before' => 0,
            'line_version_after' => 0,
            'occupied_times_before' => $occupiedBefore,
            'occupied_times_after' => $occupiedAfter,
            'guard_version_before' => $singleAudit ? $singleAudit['guardVersionBefore'] : 0,
            'guard_version_after' => $singleAudit ? $singleAudit['guardVersionAfter'] : 0,
            'actor_staff_id' => $context['operator_id'],
            'actor_employee_id' => 0,
            'actor_name_snapshot' => $context['operator_name_snapshot'],
            'reason' => 'entitlement checkout completion',
            'result_json' => $this->encode($operationPayload),
            'occurred_at' => $context['settled_at'],
            'recorded_at' => $context['recorded_at'],
        ]);
        return $result;
    }

    private function normalizeContext(array $context): array
    {
        foreach ([
            'tenant_id', 'store_id', 'member_id', 'operator_id', 'operator_name_snapshot',
            'occurred_at', 'settled_at', 'recorded_at', 'checkout_request_id',
            'command_idempotency_key', 'source_type', 'service_order_id', 'reservation_id',
        ] as $key) {
            if (!array_key_exists($key, $context)) {
                throw self::failure('completion_occupation_context_incomplete', ['missing' => $key]);
            }
        }
        $normalized = [
            'tenant_id' => self::token($context['tenant_id'], 32, 'completion_tenant_invalid'),
            'store_id' => self::positiveInt($context['store_id'], 'completion_store_invalid'),
            'member_id' => self::positiveInt($context['member_id'], 'completion_member_invalid'),
            'operator_id' => self::positiveInt($context['operator_id'], 'completion_operator_invalid'),
            'operator_name_snapshot' => self::text($context['operator_name_snapshot'], 64, 'completion_operator_name_invalid'),
            'occurred_at' => self::positiveInt($context['occurred_at'], 'completion_occurred_at_invalid'),
            'settled_at' => self::positiveInt($context['settled_at'], 'completion_settled_at_invalid'),
            'recorded_at' => self::positiveInt($context['recorded_at'], 'completion_recorded_at_invalid'),
            'checkout_request_id' => self::token($context['checkout_request_id'], 64, 'completion_request_id_invalid'),
            'command_idempotency_key' => self::token($context['command_idempotency_key'], 128, 'completion_idempotency_key_invalid'),
            'source_type' => (string)$context['source_type'],
            'service_order_id' => self::nonNegativeInt($context['service_order_id'], 'completion_service_order_id_invalid'),
            'reservation_id' => self::nonNegativeInt($context['reservation_id'], 'completion_reservation_id_invalid'),
        ];
        if (!in_array($normalized['source_type'], ['direct', 'reservation', 'service_order'], true)
            || ($normalized['source_type'] === 'direct'
                && ($normalized['service_order_id'] !== 0 || $normalized['reservation_id'] !== 0))
            || ($normalized['source_type'] === 'reservation'
                && ($normalized['reservation_id'] <= 0 || $normalized['service_order_id'] !== 0))
            || ($normalized['source_type'] === 'service_order'
                && ($normalized['service_order_id'] <= 0 || $normalized['reservation_id'] !== 0))
            || $normalized['settled_at'] < $normalized['occurred_at']
            || $normalized['recorded_at'] < $normalized['occurred_at']) {
            throw self::failure('completion_occupation_context_invalid');
        }
        return $normalized;
    }

    private function normalizeDeductions(array $deductions): array
    {
        if (!self::isList($deductions)) {
            throw self::failure('completion_occupation_deductions_invalid');
        }
        $result = [];
        foreach ($deductions as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('completion_occupation_deduction_invalid', ['index' => $index]);
            }
            foreach ([
                'source_key', 'entitlement_instance_type', 'entitlement_instance_id',
                'source_kind', 'is_gift', 'gift_source_type', 'gift_id', 'gift_version',
                'holder_id', 'origin_order_id', 'source_detail_id', 'project_id',
                'source_version', 'detail_version', 'expected_physical_remaining_times',
                'deduct_physical_times', 'convert_current_source_occupied_times', 'line_ids',
            ] as $key) {
                if (!array_key_exists($key, $row)) {
                    throw self::failure('completion_occupation_deduction_incomplete', [
                        'index' => $index,
                        'missing' => $key,
                    ]);
                }
            }
            $deduct = self::positiveInt($row['deduct_physical_times'], 'completion_deduct_times_invalid');
            $convert = self::nonNegativeInt(
                $row['convert_current_source_occupied_times'],
                'completion_convert_times_invalid'
            );
            if ($convert > $deduct || !is_array($row['line_ids']) || !self::isList($row['line_ids'])) {
                throw self::failure('completion_convert_times_invalid', ['index' => $index]);
            }
            $lineIds = [];
            foreach ($row['line_ids'] as $lineId) {
                $lineIds[] = self::token($lineId, 64, 'completion_line_identity_invalid');
            }
            $detailId = self::positiveInt($row['source_detail_id'], 'completion_source_detail_invalid');
            if (isset($result[$detailId])) {
                throw self::failure('completion_source_detail_duplicate', [
                    'entitlementSourceDetailId' => $detailId,
                ]);
            }
            $result[$detailId] = [
                'source_key' => self::token($row['source_key'], 128, 'completion_source_key_invalid'),
                'entitlement_instance_type' => self::token($row['entitlement_instance_type'], 32, 'completion_instance_type_invalid'),
                'entitlement_instance_id' => self::positiveInt($row['entitlement_instance_id'], 'completion_instance_id_invalid'),
                'source_kind' => self::token($row['source_kind'], 32, 'completion_source_kind_invalid'),
                'is_gift' => self::booleanInt($row['is_gift'], 'completion_gift_flag_invalid'),
                'gift_source_type' => self::token($row['gift_source_type'], 32, 'completion_gift_source_invalid'),
                'gift_id' => self::nonNegativeInt($row['gift_id'], 'completion_gift_id_invalid'),
                'gift_version' => self::nonNegativeInt($row['gift_version'], 'completion_gift_version_invalid'),
                'holder_id' => self::positiveInt($row['holder_id'], 'completion_holder_invalid'),
                'origin_order_id' => self::positiveInt($row['origin_order_id'], 'completion_origin_order_invalid'),
                'source_detail_id' => $detailId,
                'project_id' => self::positiveInt($row['project_id'], 'completion_project_invalid'),
                'source_version' => self::positiveInt($row['source_version'], 'completion_source_version_invalid'),
                'detail_version' => self::positiveInt($row['detail_version'], 'completion_detail_version_invalid'),
                'expected_physical_remaining_times' => self::positiveInt(
                    $row['expected_physical_remaining_times'],
                    'completion_expected_remaining_invalid'
                ),
                'deduct_physical_times' => $deduct,
                'convert_current_source_occupied_times' => $convert,
                'line_ids' => $lineIds,
            ];
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    private function currentOrder(array $set, array $context): array
    {
        if (!isset($set['orders'], $set['lines']) || !is_array($set['orders']) || !is_array($set['lines'])) {
            throw self::failure('service_order_occupation_set_invalid');
        }
        $current = null;
        foreach ($set['orders'] as $order) {
            if (!is_array($order) || (int)($order['id'] ?? 0) !== $context['service_order_id']) {
                continue;
            }
            if ($current !== null) {
                throw self::failure('service_order_lock_duplicate');
            }
            $current = $order;
        }
        if ($current === null
            || (string)($current['tenant_id'] ?? '') !== $context['tenant_id']
            || (int)($current['business_store_id'] ?? 0) !== $context['store_id']
            || (int)($current['member_id'] ?? 0) !== $context['member_id']) {
            throw self::failure('service_order_completion_scope_mismatch');
        }
        return $current;
    }

    private function currentOrderLines(array $set, array $context): array
    {
        $result = [];
        foreach ($set['lines'] as $line) {
            if (!is_array($line) || (int)($line['service_order_id'] ?? 0) !== $context['service_order_id']) {
                continue;
            }
            if ((string)($line['source_type'] ?? ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_ENTITLEMENT)
                !== ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_ENTITLEMENT) {
                continue;
            }
            $id = (int)($line['id'] ?? 0);
            $version = (int)($line['version'] ?? 0);
            $detailId = (int)($line['entitlement_source_detail_id'] ?? 0);
            $occupied = (int)($line['occupied_times'] ?? -1);
            $status = (string)($line['status'] ?? '');
            if ($id <= 0
                || isset($result[$id])
                || (string)($line['tenant_id'] ?? '') !== $context['tenant_id']
                || $version <= 0
                || $version >= PHP_INT_MAX
                || $detailId <= 0
                || $occupied < 0
                || !in_array($status, [
                    CashierV3ServiceOrderState::LINE_ACTIVE,
                    CashierV3ServiceOrderState::LINE_RELEASED,
                ], true)
                || ($status === CashierV3ServiceOrderState::LINE_ACTIVE && $occupied <= 0)
                || ($status === CashierV3ServiceOrderState::LINE_RELEASED && $occupied !== 0)) {
                throw self::failure('service_order_completion_line_invalid', ['lineId' => $id]);
            }
            $result[$id] = $line;
        }
        if (!$result) {
            throw self::failure('service_order_completion_lines_empty');
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    private function activeOccupiedTimes(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            if (CashierV3ServiceOrderState::isActiveLine((string)$line['status'])) {
                $total += (int)$line['occupied_times'];
            }
        }
        return $total;
    }

    private function replayResult(array $operation, array $context, string $fingerprint, int $expected): array
    {
        if ((string)($operation['operation_type'] ?? '') !== self::OPERATION_TYPE
            || (int)($operation['service_order_id'] ?? 0) !== $context['service_order_id']
            || (int)($operation['business_store_id'] ?? 0) !== $context['store_id']
            || !hash_equals((string)($operation['request_fingerprint'] ?? ''), $fingerprint)) {
            throw self::failure('completion_occupation_idempotency_conflict');
        }
        $payload = json_decode((string)($operation['result_json'] ?? ''), true);
        $result = is_array($payload) ? ($payload['publicResult'] ?? null) : null;
        if (!is_array($result)
            || $result !== $this->publicResult('service_order', $context['service_order_id'], $expected)) {
            throw self::failure('completion_occupation_replay_corrupted');
        }
        return $result;
    }

    private function requestFingerprint(array $context, array $deductions): string
    {
        return hash('sha256', $this->encode([
            'contractVersion' => CashierV3EntitlementCompletionOccupationWriter::CONTRACT_VERSION,
            'tenantId' => $context['tenant_id'],
            'storeId' => $context['store_id'],
            'memberId' => $context['member_id'],
            'sourceType' => $context['source_type'],
            'serviceOrderId' => $context['service_order_id'],
            'reservationId' => $context['reservation_id'],
            'checkoutRequestId' => $context['checkout_request_id'],
            'commandIdempotencyKey' => $context['command_idempotency_key'],
            'settledAt' => $context['settled_at'],
            'deductions' => $deductions,
        ]));
    }

    private function operationCommandKey(array $context): string
    {
        return 'ECO-CMD-' . hash(
            'sha256',
            $context['tenant_id'] . "\0" . $context['command_idempotency_key']
        );
    }

    private function operationKey(array $context): string
    {
        return 'ECO-' . substr(hash(
            'sha256',
            $context['tenant_id'] . "\0" . $context['command_idempotency_key']
        ), 0, 56);
    }

    private function orderLockSnapshot(array $order): string
    {
        return $this->encode([
            'id' => (int)($order['id'] ?? 0),
            'tenantId' => (string)($order['tenant_id'] ?? ''),
            'storeId' => (int)($order['business_store_id'] ?? 0),
            'memberId' => (int)($order['member_id'] ?? 0),
            'status' => (string)($order['status'] ?? ''),
            'version' => (int)($order['version'] ?? 0),
            'completedAt' => (int)($order['completed_at'] ?? 0),
        ]);
    }

    private function lineLockSnapshot(array $lines): string
    {
        $snapshot = [];
        foreach ($lines as $line) {
            $snapshot[] = [
                'id' => (int)$line['id'],
                'detailId' => (int)$line['entitlement_source_detail_id'],
                'occupiedTimes' => (int)$line['occupied_times'],
                'status' => (string)$line['status'],
                'version' => (int)$line['version'],
            ];
        }
        return $this->encode($snapshot);
    }

    private function publicResult(string $sourceType, int $sourceId, int $convertedTimes): array
    {
        return [
            'contractVersion' => CashierV3EntitlementCompletionOccupationWriter::CONTRACT_VERSION,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'convertedTimes' => $convertedTimes,
        ];
    }

    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('completion_occupation_json_invalid');
        }
        return $json;
    }

    private static function token($value, int $maxLength, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function text($value, int $maxLength, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > $maxLength) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function positiveInt($value, string $reason): int
    {
        if (!is_int($value) || $value <= 0 || $value >= PHP_INT_MAX) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        if (!is_int($value) || $value < 0 || $value >= PHP_INT_MAX) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function booleanInt($value, string $reason): int
    {
        if (!is_int($value) || ($value !== 0 && $value !== 1)) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function isList(array $value): bool
    {
        return !$value || array_keys($value) === range(0, count($value) - 1);
    }

    private static function failure(string $reason, array $detail = []): CashierV3ServiceOrderAuthorityException
    {
        return new CashierV3ServiceOrderAuthorityException($reason, $detail);
    }
}
