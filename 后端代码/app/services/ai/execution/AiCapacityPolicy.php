<?php
declare(strict_types=1);

namespace app\services\ai\execution;

/**
 * Conservative admission decision over a trusted, coherent capacity snapshot.
 * ADMIT does not reserve anything. The caller MUST atomically revalidate snapshot
 * version, acquire the slot/wait position and create Run, or reject the request.
 * Physical work remains occupied until its actual stop is established externally.
 */
class AiCapacityPolicy
{
    public static function decide(array $profile, array $snapshot, array $request): array
    {
        self::keys($profile, ['version', 'parent_slots', 'active_run_limit', 'queue_wait_ms',
            'control_slots', 'design_verified', 'load_verified', 'alert_thresholds_registered']);
        foreach (['version', 'parent_slots', 'active_run_limit', 'control_slots'] as $key) {
            self::integer($profile[$key], 1);
        }
        self::integer($profile['queue_wait_ms'], 0);
        if ($profile['queue_wait_ms'] > 10000) {
            self::fail('CAPACITY_PROFILE_INVALID');
        }
        foreach (['design_verified', 'load_verified', 'alert_thresholds_registered'] as $key) {
            if (!is_bool($profile[$key])) {
                self::fail('CAPACITY_PROFILE_INVALID');
            }
        }
        self::keys($snapshot, ['profile_version', 'healthy_parent_slots', 'occupied_parent_slots',
            'reserved_parent_slots', 'active_runs', 'pending_parent_nodes', 'available_control_slots',
            'release_upper_bounds_ms']);
        foreach (array_diff(array_keys($snapshot), ['release_upper_bounds_ms']) as $key) {
            self::integer($snapshot[$key], 0);
        }
        self::keys($request, ['remaining_execution_ms', 'required_after_start_ms', 'initial_wait_limit_ms']);
        self::integer($request['remaining_execution_ms'], 1);
        self::integer($request['required_after_start_ms'], 1);
        self::integer($request['initial_wait_limit_ms'], 0);
        if ($request['remaining_execution_ms'] > 300000 || $request['initial_wait_limit_ms'] > 10000) {
            self::fail('CAPACITY_REQUEST_INVALID');
        }
        if (!is_array($snapshot['release_upper_bounds_ms'])
            || array_values($snapshot['release_upper_bounds_ms']) !== $snapshot['release_upper_bounds_ms']
            || count($snapshot['release_upper_bounds_ms']) !== $snapshot['occupied_parent_slots']) {
            self::fail('CAPACITY_SNAPSHOT_INVALID');
        }
        foreach ($snapshot['release_upper_bounds_ms'] as $bound) {
            if ($bound !== null) {
                self::integer($bound, 1);
            }
        }
        if ($snapshot['healthy_parent_slots'] > $profile['parent_slots']
            || $snapshot['occupied_parent_slots'] > $profile['parent_slots']
            || $snapshot['reserved_parent_slots'] > $profile['parent_slots']
            || $snapshot['occupied_parent_slots'] + $snapshot['reserved_parent_slots'] > $profile['parent_slots']
            || $snapshot['pending_parent_nodes'] > $snapshot['active_runs']
            || $snapshot['available_control_slots'] > $profile['control_slots']) {
            self::fail('CAPACITY_SNAPSHOT_INVALID');
        }
        if (!$profile['design_verified'] || !$profile['load_verified'] || !$profile['alert_thresholds_registered']) {
            return self::reject('CAPACITY_PROFILE_NOT_READY');
        }
        if ($snapshot['profile_version'] !== $profile['version']) {
            return self::reject('CAPACITY_PROFILE_CHANGED');
        }
        if ($snapshot['available_control_slots'] < $profile['control_slots']) {
            return self::reject('CONTROL_CAPACITY_UNAVAILABLE');
        }
        if ($snapshot['active_runs'] >= $profile['active_run_limit']) {
            return self::reject('ACTIVE_RUN_LIMIT');
        }
        $waitLimit = min($profile['queue_wait_ms'], $request['initial_wait_limit_ms']);
        $free = $snapshot['healthy_parent_slots'] - $snapshot['occupied_parent_slots'] - $snapshot['reserved_parent_slots'];
        $wait = 0;
        if ($free <= 0) {
            // No queue simulation by average latency. Existing pending/recovery work
            // wins; this minimum policy cannot prove a later position and rejects it.
            if ($snapshot['pending_parent_nodes'] > 0 || $snapshot['reserved_parent_slots'] > 0
                || $snapshot['healthy_parent_slots'] !== $profile['parent_slots']) {
                return self::reject('NO_PROVABLE_WAIT_POSITION');
            }
            $bounds = array_values(array_filter($snapshot['release_upper_bounds_ms'], function ($bound): bool {
                return $bound !== null;
            }));
            if (!$bounds) {
                return self::reject('NO_PROVABLE_RELEASE');
            }
            $wait = min($bounds);
        } elseif ($snapshot['pending_parent_nodes'] > 0) {
            return self::reject('RECOVERY_CAPACITY_RESERVED');
        }
        if ($wait > $waitLimit || $request['required_after_start_ms'] > $request['remaining_execution_ms'] - $wait) {
            return self::reject('SHORT_WAIT_OR_REMAINING_BUDGET_EXCEEDED');
        }
        return ['decision' => 'ADMIT', 'reason' => 'CAPACITY_AVAILABLE',
            'latest_start_delay_ms' => $wait, 'requires_atomic_reservation' => true];
    }

    private static function reject(string $reason): array
    {
        return ['decision' => 'CAPACITY_REJECTED', 'reason' => $reason,
            'latest_start_delay_ms' => null, 'requires_atomic_reservation' => false];
    }

    private static function keys(array $input, array $keys): void
    {
        $actual = array_keys($input);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            self::fail('POLICY_FIELDS_INVALID');
        }
    }

    private static function integer($value, int $minimum): void
    {
        if (!is_int($value) || $value < $minimum) {
            self::fail('POLICY_INTEGER_REQUIRED');
        }
    }

    private static function fail(string $reason): void
    {
        throw new AiRuntimePolicyException($reason);
    }
}
