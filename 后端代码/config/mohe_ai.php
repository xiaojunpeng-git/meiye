<?php
// Instance-local [MOHE_AI] environment settings. These are release attestations,
// not a substitute for the worker/source barrier, capacity tests or supervision.
// Never cast strings to bool: the string "false" must not enable a release gate.
$flag = static function (string $key): bool {
    return in_array(env('mohe_ai.'.$key, false), [true, 1, '1', 'true'], true);
};
$integer = static function (string $key, ?int $default = null, int $min = 1, int $max = PHP_INT_MAX): ?int {
    $value = env('mohe_ai.'.$key, $default);
    if (is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
        $value = filter_var($value, FILTER_VALIDATE_INT);
    }
    return is_int($value) && $value >= $min && $value <= $max ? $value : null;
};
$rate = env('mohe_ai.monitor_export_failure_rate', null);
if (is_string($rate) && preg_match('/^(?:0(?:\.[0-9]+)?|1(?:\.0+)?)$/D', $rate)) $rate = (float)$rate;
$rate = (is_float($rate) || is_int($rate)) && is_finite((float)$rate) && $rate > 0 && $rate <= 1 ? $rate : null;
return [
    'run_budget_ms'=>180000,
    'execution_slots'=>4,
    'active_run_limit'=>8,
    // Frozen into each new Run. A running question can never extend its guidance quota.
    'max_clarification_rounds'=>$integer('max_clarification_rounds', 3, 3, 5),
    // No implied healthy status until thresholds and supervision frequency are registered.
    'monitoring'=>[
        'registered'=>$flag('monitor_registered'),
        'capacity_count'=>$integer('monitor_capacity_count'),
        'export_min_samples'=>$integer('monitor_export_min_samples'),
        'export_failure_rate'=>$rate,
        'export_consecutive_failures'=>$integer('monitor_export_consecutive_failures'),
        'security_count'=>$integer('monitor_security_count'),
        'unknown_count'=>$integer('monitor_unknown_count'),
        'technical_count'=>$integer('monitor_technical_count'),
        'cleanup_stale_seconds'=>$integer('monitor_cleanup_stale_seconds'),
        'duration_ms'=>$integer('monitor_duration_ms'),
    ],
    // Instance release evidence, not end-user toggles. Do not enable before the source/consumer barrier.
    'export'=>[
        'compatible_workers_ready'=>$flag('export_compatible_workers_ready'),
        'reserved_slots_verified'=>$flag('export_reserved_slots_verified'),
        // Counts alone are not a validated alert pipeline or capacity profile.
        'monitoring_ready'=>$flag('export_monitoring_ready'),
        'reserved_slots'=>$integer('export_reserved_slots', 2, 1, 16),
        'queue_wait_budget_ms'=>$integer('export_queue_wait_budget_ms', 10000, 1, 10000),
        'publication_reserve_ms'=>$integer('export_publication_reserve_ms', 5000, 1000, 10000),
    ],
];
