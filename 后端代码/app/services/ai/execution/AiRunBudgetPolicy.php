<?php
declare(strict_types=1);

namespace app\services\ai\execution;

/**
 * Pure, fail-closed budget arithmetic. Times are trusted server milliseconds.
 * No clocks, database, locks, cancellation I/O or configuration are read here.
 * Every returned state must be committed by a generation-checked atomic adapter.
 * A policy success is NOT proof of cancellation, capacity reservation or expiry cleanup.
 */
class AiRunBudgetPolicy
{
    private const COUNTER_CAPS = [
        'stage_count' => 5, 'model_attempt_count' => 5,
        'model_recovery_count' => 1, 'failover_count' => 1,
        'clarification_count' => 5, 'supplement_count' => 1,
        'node_visit_count' => 12, 'skill_execution_count' => 8,
        'tool_call_count' => 8, 'workflow_transition_count' => 16,
        // Understanding, binding and independent semantic admission normally
        // use three stages. One bounded binding recovery may then need one
        // final independent admission review before any Reader call.
        // This is a hard per-Run reservation ceiling, not a normal-path target.
        'model_input_tokens' => 96000, 'model_output_tokens' => 6000,
    ];

    public static function defaults(): array
    {
        return [
            'version' => 1, 'run_execution_budget_ms' => 180000,
            'finalization_reserve_ms' => 5000, 'tool_timeout_ms' => 10000,
            'model_timeout_ms' => 20000, 'clarification_wait_ms' => 600000, 'max_clarification_rounds' => 3,
        ];
    }

    public static function validateProfile(array $profile): array
    {
        self::keys($profile, array_keys(self::defaults()));
        foreach ($profile as $value) {
            self::positive($value);
        }
        if ($profile['run_execution_budget_ms'] > 300000
            || $profile['finalization_reserve_ms'] >= $profile['run_execution_budget_ms']
            || $profile['tool_timeout_ms'] > 10000 || $profile['model_timeout_ms'] > 20000
            || $profile['clarification_wait_ms'] > 600000 || !in_array($profile['max_clarification_rounds'], [3,4,5], true)) {
            self::fail('BUDGET_PROFILE_INVALID');
        }
        return $profile;
    }

    /** Compiler supplies the proven full non-finalization path, not p95 sums. */
    public static function assertPathBudget(array $profile, $worstPathMs, $workflowLimitMs): void
    {
        self::validateProfile($profile);
        self::positive($worstPathMs);
        self::positive($workflowLimitMs);
        if ($worstPathMs > $workflowLimitMs
            || $workflowLimitMs > $profile['run_execution_budget_ms'] - $profile['finalization_reserve_ms']) {
            self::fail('WORKFLOW_PATH_BUDGET_EXCEEDED');
        }
    }

    public static function assertParallelism($count): void
    {
        self::positive($count);
        if ($count > 3) {
            self::fail('PARALLEL_TOOL_BUDGET_EXCEEDED');
        }
    }

    public static function create(array $profile, $nowMs): array
    {
        $profile = self::validateProfile($profile);
        self::clock($nowMs);
        return [
            'profile' => $profile, 'created_at_ms' => $nowMs,
            'expires_at_ms' => $nowMs + 86400000, 'last_clock_ms' => $nowMs,
            'remaining_execution_ms' => $profile['run_execution_budget_ms'],
            'execution_deadline_ms' => $nowMs + $profile['run_execution_budget_ms'],
            'paused_at_ms' => null, 'clarification_wait_used_ms' => 0,
            'counters' => array_fill_keys(array_keys(self::COUNTER_CAPS), 0),
        ];
    }

    public static function consume(array $state, $nowMs): array
    {
        self::validateState($state);
        self::clock($nowMs);
        if ($nowMs < $state['last_clock_ms']) {
            self::fail('CLOCK_REGRESSION');
        }
        if ($nowMs >= $state['expires_at_ms']) {
            self::fail('RUN_EXPIRED');
        }
        if ($state['paused_at_ms'] !== null) {
            if ($nowMs - $state['paused_at_ms'] + $state['clarification_wait_used_ms'] >= $state['profile']['clarification_wait_ms']) {
                self::fail('CLARIFICATION_EXPIRED');
            }
        } else {
            $state['remaining_execution_ms'] = max(0,
                $state['remaining_execution_ms'] - ($nowMs - $state['last_clock_ms']));
        }
        $state['last_clock_ms'] = $nowMs;
        return $state;
    }

    public static function pauseForClarification(array $state, $nowMs): array
    {
        $state = self::consume($state, $nowMs);
        self::assertWorking($state);
        $state = self::reserve($state, 'clarification_count', 1);
        $state['paused_at_ms'] = $nowMs;
        $state['execution_deadline_ms'] = null;
        return $state;
    }

    public static function resumeAfterClarification(array $state, $nowMs): array
    {
        $state = self::consume($state, $nowMs);
        if ($state['paused_at_ms'] === null || $state['counters']['clarification_count'] < 1) {
            self::fail('NOT_WAITING_CLARIFICATION');
        }
        $state['clarification_wait_used_ms'] += $nowMs - $state['paused_at_ms'];
        $state['paused_at_ms'] = null;
        $state['execution_deadline_ms'] = $nowMs + $state['remaining_execution_ms'];
        return $state;
    }

    /** Pre-reservation, including UNKNOWN attempts. Never refund on unknown outcome. */
    public static function reserve(array $state, $counter, $amount = 1): array
    {
        self::validateState($state);
        self::assertWorking($state);
        if (!is_string($counter) || !array_key_exists($counter, self::COUNTER_CAPS) || !is_int($amount) || $amount <= 0
            || ($counter !== 'model_input_tokens' && $counter !== 'model_output_tokens' && $amount !== 1)) {
            self::fail('COUNTER_REQUEST_INVALID');
        }
        $cap=$counter==='clarification_count'?$state['profile']['max_clarification_rounds']:self::COUNTER_CAPS[$counter];
        if ($amount > $cap - $state['counters'][$counter]) {
            self::fail('COUNTER_BUDGET_EXHAUSTED');
        }
        $state['counters'][$counter] += $amount;
        $counts = $state['counters'];
        if ($counts['model_attempt_count'] > $counts['stage_count'] + $counts['model_recovery_count']
            || $counts['failover_count'] > $counts['model_recovery_count']) {
            self::fail('MODEL_RECOVERY_NOT_RESERVED');
        }
        return $state;
    }

    public static function callTimeout(array $state, $kind, $stageLimitMs, $nowMs): int
    {
        $state = self::consume($state, $nowMs);
        self::assertWorking($state);
        if (!in_array($kind, ['model', 'tool'], true) || !is_int($stageLimitMs) || $stageLimitMs <= 0) {
            self::fail('CALL_BUDGET_INVALID');
        }
        return min($state['profile'][$kind . '_timeout_ms'], $stageLimitMs,
            $state['remaining_execution_ms'] - $state['profile']['finalization_reserve_ms']);
    }

    /** Final commit still needs live permission, generation, cancellation and evidence guards. */
    public static function assertCanPublish(array $state, $nowMs): void
    {
        $state = self::consume($state, $nowMs);
        if ($state['paused_at_ms'] !== null || $state['remaining_execution_ms'] <= 0) {
            self::fail('PUBLICATION_TIME_EXHAUSTED');
        }
    }

    private static function validateState(array $state): void
    {
        self::keys($state, ['profile', 'created_at_ms', 'expires_at_ms', 'last_clock_ms',
            'remaining_execution_ms', 'execution_deadline_ms', 'paused_at_ms', 'clarification_wait_used_ms', 'counters']);
        if (!is_array($state['profile']) || !is_array($state['counters'])) {
            self::fail('BUDGET_STATE_INVALID');
        }
        self::validateProfile($state['profile']);
        foreach (['created_at_ms', 'expires_at_ms', 'last_clock_ms', 'remaining_execution_ms', 'clarification_wait_used_ms'] as $key) {
            if (!is_int($state[$key]) || $state[$key] < 0) {
                self::fail('BUDGET_STATE_INVALID');
            }
        }
        if ($state['created_at_ms'] > $state['last_clock_ms']
            || $state['expires_at_ms'] > $state['created_at_ms'] + 86400000
            || $state['expires_at_ms'] <= $state['created_at_ms']
            || $state['remaining_execution_ms'] > $state['profile']['run_execution_budget_ms']
            || $state['clarification_wait_used_ms'] >= $state['profile']['clarification_wait_ms']) {
            self::fail('BUDGET_STATE_INVALID');
        }
        if ($state['paused_at_ms'] === null) {
            if (!is_int($state['execution_deadline_ms'])
                || $state['execution_deadline_ms'] !== $state['last_clock_ms'] + $state['remaining_execution_ms']) {
                // Exhausted states retain their original deadline; last clock may be later.
                if ($state['remaining_execution_ms'] !== 0 || !is_int($state['execution_deadline_ms'])
                    || $state['execution_deadline_ms'] > $state['last_clock_ms']) {
                    self::fail('BUDGET_STATE_INVALID');
                }
            }
        } elseif (!is_int($state['paused_at_ms']) || $state['paused_at_ms'] < $state['created_at_ms']
            || $state['paused_at_ms'] > $state['last_clock_ms'] || $state['execution_deadline_ms'] !== null) {
            self::fail('BUDGET_STATE_INVALID');
        }
        self::keys($state['counters'], array_keys(self::COUNTER_CAPS));
        foreach (self::COUNTER_CAPS as $key => $cap) {
            if ($key==='clarification_count') $cap=$state['profile']['max_clarification_rounds'];
            if (!is_int($state['counters'][$key]) || $state['counters'][$key] < 0 || $state['counters'][$key] > $cap) {
                self::fail('BUDGET_STATE_INVALID');
            }
        }
        if ($state['counters']['model_attempt_count'] > $state['counters']['stage_count'] + $state['counters']['model_recovery_count']
            || $state['counters']['failover_count'] > $state['counters']['model_recovery_count']
            || ($state['paused_at_ms'] !== null && $state['counters']['clarification_count'] < 1)) {
            self::fail('BUDGET_STATE_INVALID');
        }
    }

    private static function assertWorking(array $state): void
    {
        if ($state['paused_at_ms'] !== null) {
            self::fail('WAITING_CLARIFICATION');
        }
        if ($state['remaining_execution_ms'] <= $state['profile']['finalization_reserve_ms']) {
            self::fail('WORK_BUDGET_EXHAUSTED');
        }
    }

    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            self::fail('POLICY_FIELDS_INVALID');
        }
    }

    private static function positive($value): void
    {
        if (!is_int($value) || $value <= 0) {
            self::fail('POLICY_INTEGER_REQUIRED');
        }
    }

    private static function clock($value): void
    {
        if (!is_int($value) || $value < 0 || $value > PHP_INT_MAX - 86400000) {
            self::fail('CLOCK_INVALID');
        }
    }

    private static function fail(string $reason): void
    {
        throw new AiRuntimePolicyException($reason);
    }
}
