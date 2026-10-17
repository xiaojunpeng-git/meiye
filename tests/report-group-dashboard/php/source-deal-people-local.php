<?php

declare(strict_types=1);

/** Read-only local Ruihao check: dashboard source people must share the market member-day cohort. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\query\metric\StoreCustomerVisitEligibility;
use app\services\report\GroupManagementDashboardServices;
use app\services\report\StoreReportImportedRepaymentCountScope;
use app\services\report\StoreUnifiedReportPhaseTwoServices;
use think\facade\Db;

(new think\App(rtrim($backend, '/\\') . '/'))->initialize();
if (getenv('GROUP_SOURCE_DEAL_PEOPLE_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Explicit local Ruihao test database required');
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $stores = [126];
    $range = ['start' => '2026-10-08', 'end' => '2026-10-08'];
    $service = new GroupManagementDashboardServices();
    $market = new StoreUnifiedReportPhaseTwoServices();
    $qualified = $market->marketQualifiedMemberDays($stores, $range);
    if ($qualified === []) throw new RuntimeException('Qualifying local fixture is empty');

    $cashMethod = new ReflectionMethod($service, 'cashRows');
    $cashMethod->setAccessible(true);
    $serviceMethod = new ReflectionMethod($service, 'serviceRows');
    $serviceMethod->setAccessible(true);
    $sourcesMethod = new ReflectionMethod($service, 'sources');
    $sourcesMethod->setAccessible(true);
    $cash = $cashMethod->invoke($service, '0', $stores, $range, []);
    $services = $serviceMethod->invoke($service, '0', $stores, $range, []);
    $unfiltered = $sourcesMethod->invoke($service, $stores, $range, $cash, $services, []);
    $expectedBySource = [];
    foreach ($qualified as $fact) {
        $sourceId = (int)$fact['business_source_primary_id'];
        $expectedBySource[$sourceId] = ($expectedBySource[$sourceId] ?? 0) + 1;
    }
    foreach ($unfiltered['records'] as $record) {
        $sourceId = (int)$record['source_id'];
        if ((int)$record['deal_count'] !== ($expectedBySource[$sourceId] ?? 0)) {
            throw new RuntimeException('Unfiltered source people differ from market qualifying member-days');
        }
        // Source money now follows signed actual performance; visit and
        // qualifying-person counts remain independent of that amount.
        $matching = array_filter($cash, static function (array $row) use ($sourceId): bool {
            return (int)$row['business_source_primary_id'] === $sourceId;
        });
        $expectedAmount = array_sum(array_map(static function (array $row): int {
            return (int)$row['amount_cents'];
        }, $matching));
        $visits = StoreCustomerVisitEligibility::filter(array_values(array_filter($services,
            static function (array $row) use ($sourceId): bool {
                return (int)$row['business_source_primary_id'] === $sourceId;
            })));
        if ((int)$record['deal_amount_cents'] !== $expectedAmount || (int)$record['visit_count'] !== count($visits)) {
            throw new RuntimeException('Source amount or visit metric changed');
        }
    }

    $categoryId = 0;
    foreach ($cash as $row) {
        if ((int)($row['category_id'] ?? 0) > 0) {
            $categoryId = (int)$row['category_id'];
            break;
        }
    }
    if ($categoryId <= 0) throw new RuntimeException('Category fixture is empty');
    $categoryCash = $cashMethod->invoke($service, '0', $stores, $range, [$categoryId]);
    $categoryServices = $serviceMethod->invoke($service, '0', $stores, $range, [$categoryId]);
    $filtered = $sourcesMethod->invoke($service, $stores, $range, $categoryCash, $categoryServices, [$categoryId]);
    $netByDay = [];
    foreach (StoreReportImportedRepaymentCountScope::exclude($categoryCash, $stores) as $row) {
        if ((int)$row['member_id'] <= 0) continue;
        $key = $market->marketEffectiveMemberDayKey($row);
        $netByDay[$key] = ($netByDay[$key] ?? 0) + (int)$row['amount_cents'];
    }
    $expectedCategory = [];
    foreach ($qualified as $key => $fact) {
        if (($netByDay[$key] ?? 0) <= 0) continue;
        $sourceId = (int)$fact['business_source_primary_id'];
        $expectedCategory[$sourceId] = ($expectedCategory[$sourceId] ?? 0) + 1;
    }
    if (array_sum($expectedCategory) === 0) throw new RuntimeException('Positive-net qualifying category fixture is empty');
    foreach ($filtered['records'] as $record) {
        $sourceId = (int)$record['source_id'];
        if ((int)$record['deal_count'] !== ($expectedCategory[$sourceId] ?? 0)) {
            throw new RuntimeException('Category source people differ from positive-net qualifying member-days');
        }
    }
    if (strpos($filtered['source_explanation'], '平台设置的来源门槛') === false) {
        throw new RuntimeException('Dashboard source explanation omits the configurable shared threshold');
    }
    echo 'PASS group dashboard source people, signed actual amount and visits', PHP_EOL;
} finally {
    Db::rollback();
}
