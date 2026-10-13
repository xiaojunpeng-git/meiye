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
    str_contains($aggregate, "METRIC_VERSION = 'registered-actual-consume-v1'")
    && str_contains($aggregate, "'actual_performance' => 'actual_performance_cents'")
    && str_contains($aggregate, "'consume_amount' => 'consumption_performance_cents'")
    && str_contains($aggregate, "'metric_version' => self::METRIC_VERSION")
    && !str_contains($aggregate, 'actual_performance_recorded')
    && !str_contains($aggregate, 'consumption_performance_recorded'),
    'daily aggregate rebuild follows registered metrics and marks its formula version'
);
stabilityAssert(
    str_contains($dashboard, "'status_code' => " . '$categoryIds === []' . " ? 'realtime' : 'not_applicable'")
    && str_contains($dashboard, "'aggregation_health' => " . '$aggregationHealth')
    && !str_contains($dashboard, 'aggregateMatchesFacts(')
    && !str_contains($dashboard, 'dailyAggregate->status('),
    'dashboard reads current registered facts without per-request legacy aggregate checks'
);
stabilityAssert(
    str_contains($command, "fopen(" . '$path' . ", 'c')")
    && str_contains($command, 'LOCK_EX | LOCK_NB')
    && str_contains($command, 'finally')
    && str_contains($command, 'releaseLock'),
    'aggregate command rejects overlapping runs and always releases its runtime lock'
);

echo "group dashboard stability contract: PASS\n";
