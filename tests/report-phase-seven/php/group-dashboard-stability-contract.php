<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$aggregate = (string)file_get_contents($root . '/后端代码/app/services/report/GroupManagementDashboardDailyAggregateServices.php');
$dashboard = (string)file_get_contents($root . '/后端代码/app/services/report/GroupManagementDashboardServices.php');
$command = (string)file_get_contents($root . '/后端代码/app/command/GroupManagementDashboardAggregate.php');

function stabilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

stabilityAssert(
    str_contains($aggregate, "'status_code' => " . '$statusCode')
    && str_contains($aggregate, "'lag_seconds' => " . '$lagSeconds')
    && str_contains($aggregate, "'checked_at' => " . '$checkedAt')
    && str_contains($aggregate, "\$stale = (int)(\$status['stale_group_count'] ?? 0)"),
    'aggregate status exposes a stable code, lag and check timestamp'
);
stabilityAssert(
    str_contains($dashboard, "'status_code' => 'not_applicable'")
    && str_contains($dashboard, "'scope' => 'category_filtered'")
    && str_contains($dashboard, "'aggregation_health' => " . '$aggregationHealth'),
    'dashboard distinguishes category-filtered reads from stale unfiltered aggregates'
);
stabilityAssert(
    str_contains($command, "fopen(" . '$path' . ", 'c')")
    && str_contains($command, 'LOCK_EX | LOCK_NB')
    && str_contains($command, 'finally')
    && str_contains($command, 'releaseLock'),
    'aggregate command rejects overlapping runs and always releases its runtime lock'
);

echo "group dashboard stability contract: PASS\n";
