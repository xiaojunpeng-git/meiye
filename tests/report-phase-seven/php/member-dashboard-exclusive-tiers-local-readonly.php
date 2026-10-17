<?php

declare(strict_types=1);

/** Read-only local reconciliation of all member and guest facts against the overview. */
require '/var/www/html/vendor/autoload.php';

use app\services\report\MemberManagementDashboardServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local ruihao Docker database is allowed');
}

$end = $argv[1] ?? date('Y-m-d');
$start = substr($end, 0, 7) . '-01';
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $stores = array_map('intval', Db::name('system_store')->where('is_del', 0)
        ->where('name', '<>', '总部')->column('id'));
    $service = new MemberManagementDashboardServices();
    $context = ['tenant_id' => '0', 'store_ids' => $stores];
    $dashboard = $service->dashboard($context, ['start_date' => $start, 'end_date' => $end]);

    // The member column must partition every signed actual-performance fact;
    // the new-customer column removes source A exactly once.
    $cashMethod = new ReflectionMethod($service, 'cashRows');
    $subjectsMethod = new ReflectionMethod($service, 'tierSubjects');
    $sourceMethod = new ReflectionMethod($service, 'sourceAIds');
    if (PHP_VERSION_ID < 80100) {
        $cashMethod->setAccessible(true);
        $subjectsMethod->setAccessible(true);
        $sourceMethod->setAccessible(true);
    }
    $cash = $cashMethod->invoke($service, '0', $stores, ['start' => $start, 'end' => $end]);
    $subjects = $subjectsMethod->invoke($service, $cash, $sourceMethod->invoke($service));
    $newCustomers = array_filter($subjects, static fn(array $subject): bool => !$subject['source_a']);
    $memberRows = $dashboard['tiers']['member'];
    $newRows = $dashboard['tiers']['new_customer'];
    $overviewPeople = (int)$dashboard['customer_overview']['consumption']['people']['value'];
    $overviewAmount = (int)$dashboard['customer_overview']['consumption']['amount']['value_cents'];
    $memberAmount = array_sum(array_column($memberRows, 'amount_cents'));

    if (array_sum(array_column($memberRows, 'people')) !== $overviewPeople
        || $overviewPeople !== count($subjects)
        || $memberAmount !== array_sum(array_column($subjects, 'amount'))
        || $overviewAmount !== $memberAmount
        || (int)$dashboard['tier_totals']['member']['people'] !== $overviewPeople
        || (int)$dashboard['tier_totals']['member']['amount_cents'] !== $overviewAmount
        || array_sum(array_column($newRows, 'people')) !== count($newCustomers)
        || array_sum(array_column($newRows, 'amount_cents')) !== array_sum(array_column($newCustomers, 'amount'))) {
        throw new RuntimeException('Exclusive tier totals do not reconcile to their member and guest subjects');
    }

    printf("PASS local member tiers: %s..%s stores=%d overview_people=%d member_amount_cents=%d new_people=%d new_amount_cents=%d\n",
        $start, $end, count($stores), $overviewPeople, $memberAmount,
        array_sum(array_column($newRows, 'people')),
        array_sum(array_column($newRows, 'amount_cents')));
} finally {
    Db::rollback();
}
