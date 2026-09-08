<?php
return [
    'run_budget_ms'=>180000,
    'execution_slots'=>4,
    'active_run_limit'=>8,
    // No implied healthy status until thresholds and supervision frequency are registered.
    'monitoring'=>[
        'registered'=>false,
        'capacity_count'=>null,'export_min_samples'=>null,'export_failure_rate'=>null,
        'export_consecutive_failures'=>null,'security_count'=>null,'unknown_count'=>null,
        'technical_count'=>null,'cleanup_stale_seconds'=>null,'duration_ms'=>null,
    ],
    // Instance release evidence, not end-user toggles. Do not enable before the source/consumer barrier.
    'export'=>[
        'compatible_workers_ready'=>false,
        'reserved_slots_verified'=>false,
        // Counts alone are not a validated alert pipeline or capacity profile.
        'monitoring_ready'=>false,
        'reserved_slots'=>2,
        'queue_wait_budget_ms'=>10000,
        'publication_reserve_ms'=>5000,
    ],
];
