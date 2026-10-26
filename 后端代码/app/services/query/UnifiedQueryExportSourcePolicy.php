<?php

declare(strict_types=1);

namespace app\services\query;

/**
 * Pure source contract used by the shared export adapter (PHP 7.1 compatible).
 *
 * This policy alone neither authorizes AI creation nor proves
 * current permissions, publication, cancellation or physical worker shutdown.
 * Callers must supply a transactionally verified current owner and decoded plan.
 * It never repairs missing source data or mutates a task on a partition mismatch.
 */
final class UnifiedQueryExportSourcePolicy
{
    public static function partitionForSource($sourceType): string
    {
        if ($sourceType === 'REPORT') {
            return 'REPORT';
        }
        if ($sourceType === 'AI') {
            return 'AI_EXPORT';
        }
        throw new \InvalidArgumentException('EXPORT_SOURCE_INVALID');
    }

    public static function assertSourceUnchanged($before, $after): void
    {
        self::partitionForSource($before);
        self::partitionForSource($after);
        if ($before !== $after) {
            throw new \InvalidArgumentException('EXPORT_SOURCE_IMMUTABLE');
        }
    }

    /** Earliest source deadline, capped at creation + 24 hours; no completion renewal. */
    public static function expiresAt($createdAt, array $sourceExpiries): int
    {
        // Reuse the shared retention ceiling; AI differs by starting at creation
        // and taking the earliest source expiry, not by defining another limit.
        $retention = UnifiedQueryExportWorkerServices::RETENTION_SECONDS;
        if (!is_int($createdAt) || $createdAt <= 0
            || $createdAt > PHP_INT_MAX - $retention || !$sourceExpiries) {
            throw new \InvalidArgumentException('EXPORT_EXPIRY_INVALID');
        }
        $expiresAt = $createdAt + $retention;
        foreach ($sourceExpiries as $expiry) {
            if (!is_int($expiry) || $expiry <= $createdAt) {
                throw new \InvalidArgumentException('EXPORT_EXPIRY_INVALID');
            }
            $expiresAt = min($expiresAt, $expiry);
        }
        return $expiresAt;
    }

    /**
     * Normalized contract, not the existing persisted task schema.
     * $frozenPlan is decoded by the adapter's strict/versioned JSON validator.
     * AI uses ai_binding for trusted ownership, source references and deadlines.
     * REPORT cannot carry that binding. Optional partition copies are only checks.
     * This checks the source portion of claiming, not task status or lease CAS.
     */
    public static function assertClaimable(
        array $task,
        array $frozenPlan,
        string $workerPartition,
        array $expectedOwner,
        $now
    ): void {
        // Caller strict_types may be disabled; timestamps are never coerced.
        if (!is_int($now) || $now <= 0) {
            throw new \InvalidArgumentException('EXPORT_EXPIRY_INVALID');
        }
        $partition = self::partitionForSource($task['source_type'] ?? null);
        if (!in_array($workerPartition, ['REPORT', 'AI_EXPORT'], true)
            || $partition !== $workerPartition) {
            throw new \InvalidArgumentException('EXPORT_PARTITION_MISMATCH');
        }
        if (array_key_exists('execution_partition', $task)
            && $task['execution_partition'] !== $partition) {
            throw new \InvalidArgumentException('EXPORT_PARTITION_MISMATCH');
        }
        if ($partition === 'REPORT') {
            if (array_key_exists('ai_binding', $task)) {
                throw new \InvalidArgumentException('EXPORT_REPORT_AI_BINDING_FORBIDDEN');
            }
            // Ordinary query/page, leases and retention remain the existing service's job.
            return;
        }
        if (($task['export_scope'] ?? null) !== 'query'
            || !isset($frozenPlan['query']) || !is_array($frozenPlan['query'])
            || !isset($frozenPlan['query']['export']) || !is_array($frozenPlan['query']['export'])
            || ($frozenPlan['query']['export']['scope'] ?? null) !== 'query') {
            throw new \InvalidArgumentException('EXPORT_AI_QUERY_SCOPE_REQUIRED');
        }
        $binding = $task['ai_binding'] ?? null;
        if (!is_array($binding)) {
            throw new \InvalidArgumentException('EXPORT_AI_BINDING_REQUIRED');
        }
        foreach (['instance_fingerprint', 'terminal', 'conversation_id', 'run_id'] as $key) {
            if (!isset($binding[$key], $expectedOwner[$key])
                || !is_string($binding[$key]) || trim($binding[$key]) === ''
                || $binding[$key] !== $expectedOwner[$key]) {
                throw new \InvalidArgumentException('EXPORT_AI_OWNER_MISMATCH');
            }
        }
        if (!in_array($binding['terminal'], ['platform', 'store', 'merchant'], true)) {
            throw new \InvalidArgumentException('EXPORT_AI_OWNER_MISMATCH');
        }
        foreach (['account_id', 'generation'] as $key) {
            if (!isset($binding[$key], $expectedOwner[$key])
                || !is_int($binding[$key]) || $binding[$key] <= 0
                || $binding[$key] !== $expectedOwner[$key]) {
                throw new \InvalidArgumentException('EXPORT_AI_OWNER_MISMATCH');
            }
        }
        foreach (['evidence_ref', 'canonical_result_ref', 'read_consistency_ref'] as $key) {
            if (!isset($binding[$key]) || !is_string($binding[$key]) || trim($binding[$key]) === '') {
                throw new \InvalidArgumentException('EXPORT_AI_SOURCE_REQUIRED');
            }
        }
        foreach (['created_at', 'expires_at', 'execution_deadline_at'] as $key) {
            if (!isset($binding[$key]) || !is_int($binding[$key]) || $binding[$key] <= 0) {
                throw new \InvalidArgumentException('EXPORT_EXPIRY_INVALID');
            }
        }
        if (!isset($binding['source_expires_at']) || !is_array($binding['source_expires_at'])) {
            throw new \InvalidArgumentException('EXPORT_EXPIRY_INVALID');
        }
        $maximumExpiry = self::expiresAt($binding['created_at'], $binding['source_expires_at']);
        if ($now <= 0 || $now < $binding['created_at']
            || $binding['expires_at'] > $maximumExpiry
            || $binding['expires_at'] <= $now
            || $binding['execution_deadline_at'] <= $now) {
            throw new \InvalidArgumentException('EXPORT_AI_EXPIRED');
        }
    }
}
