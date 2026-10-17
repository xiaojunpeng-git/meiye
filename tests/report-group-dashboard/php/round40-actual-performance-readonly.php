<?php

declare(strict_types=1);

use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

$backend = dirname(__DIR__, 3) . '/后端代码/';
if (!is_file($backend . 'vendor/autoload.php')) $backend = '/var/www/html/';
require $backend . 'vendor/autoload.php';
(new think\App($backend))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Read-only check requires the local ruihao database');
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
    $roots = [['id' => 26302, 'name' => '生美']];
    // The second local fixture contains an actual refund, so a positive-only
    // implementation cannot pass merely by matching a no-refund sales day.
    $cases = [
        ['stores' => [68], 'range' => ['start' => '2026-10-01', 'end' => '2026-10-17'], 'refund' => false],
        ['stores' => [23], 'range' => ['start' => '2026-10-16', 'end' => '2026-10-16'], 'refund' => true],
    ];
    foreach ($cases as $case) {
        foreach (['cash_performance', 'actual_performance'] as $metricCode) {
            $rows = $reader->categoryRows($metricCode, '0', $case['stores'], $case['range']);
            $cards = $reader->categoryDashboardCards($metricCode, '0', $case['stores'], $case['range'], $roots, []);
            $expected = array_sum(array_map(static fn(array $row): int => (int)$row['amount_cents'],
                $metricCode === 'actual_performance'
                    ? $rows
                    : array_values(array_filter($rows, static fn(array $row): bool => (int)$row['amount_cents'] > 0))));
            $actual = array_sum(array_column($cards, 'amount_cents'));
            if ($actual !== $expected) {
                throw new RuntimeException($metricCode . ': category card total does not reconcile with metric rows');
            }
            if (array_filter($cards, static fn(array $card): bool => ($card['metric_code'] ?? '') !== $metricCode)) {
                throw new RuntimeException($metricCode . ': category card metric identity changed');
            }
            $negativeCount = count(array_filter($rows, static fn(array $row): bool => (int)$row['amount_cents'] < 0));
            if ($case['refund'] && $metricCode === 'actual_performance' && $negativeCount === 0) {
                throw new RuntimeException('Refund fixture is no longer present in registered actual-performance rows');
            }
            echo $metricCode . ': stores=' . implode(',', $case['stores'])
                . ' rows=' . count($rows) . ' negative_rows=' . $negativeCount
                . ' cents=' . $actual . PHP_EOL;
        }
    }
} finally {
    Db::rollback();
}
