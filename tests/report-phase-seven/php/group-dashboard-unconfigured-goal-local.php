<?php

declare(strict_types=1);

/** Read-only local regression: missing goals stay null while dashboard facts remain visible. */
require '/var/www/html/vendor/autoload.php';

use app\services\report\GroupManagementDashboardServices;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local ruihao Docker database is allowed');
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $storeId = (int)Db::name('system_store')->where('is_del', 0)->where('name', '<>', '总部')->value('id');
    if ($storeId <= 0) throw new RuntimeException('No authorized store found');
    $service = new GroupManagementDashboardServices();
    $context = ['tenant_id' => '0', 'store_ids' => [$storeId]];

    // 本地目标数据在 2026 年 8 月尚未配置月目标，但其他月份已有年度目标。
    $august = $service->dashboard($context, ['start_date' => '2026-08-15', 'end_date' => '2026-08-15']);
    if ($august['goals']['month']['target_amount_cents'] !== null
        || $august['goals']['month']['remaining_amount_cents'] !== null
        || $august['goals']['month']['achievement_rate'] !== null
        || count($august['cards']) !== 6
        || count($august['rankings']['store']['records']) !== 1
        || $august['rankings']['store']['records'][0]['target_amount_cents'] !== null
        || $august['rankings']['store']['records'][0]['achievement_rate'] !== null) {
        throw new RuntimeException('No-month-goal dashboard contract failed');
    }

    // 全年完全无目标时，目标、剩余和比率均不可计算，但组织排行仍存在。
    $future = $service->dashboard($context, ['start_date' => '2035-01-01', 'end_date' => '2035-01-01']);
    foreach (['month', 'year'] as $period) {
        $goal = $future['goals'][$period];
        if ($goal['target_amount_cents'] !== null || $goal['remaining_amount_cents'] !== null
            || $goal['achievement_rate'] !== null || $goal['actual_performance_cents'] !== 0) {
            throw new RuntimeException("No-goal {$period} projection failed");
        }
    }
    if (count($future['rankings']['store']['records']) !== 1
        || $future['rankings']['store']['records'][0]['target_amount_cents'] !== null) {
        throw new RuntimeException('No-goal ranking failed');
    }

    // 非零完成业绩同样不能因缺少目标变成 0%，组织排行应保留事实金额。
    $projection = new ReflectionMethod($service, 'goalProjection');
    $projection->setAccessible(true);
    $nonzero = $projection->invoke($service, '本月目标进度', null, 4500, '本地只读验证');
    $rankings = new ReflectionMethod($service, 'rankings');
    $rankings->setAccessible(true);
    $nonzeroRanks = $rankings->invoke($service, [$storeId], [], [$storeId => 4500], '2026-10-18');
    if ($nonzero['actual_performance_cents'] !== 4500 || $nonzero['achievement_rate'] !== null
        || $nonzeroRanks['store']['records'][0]['actual_performance_cents'] !== 4500
        || $nonzeroRanks['store']['records'][0]['achievement_rate'] !== null) {
        throw new RuntimeException('No-goal nonzero actual was converted into a rate');
    }

    // 已配置目标继续使用同一已注册实际业绩事实，并保留原达成率口径。
    $configured = $service->dashboard($context, ['start_date' => '2026-10-18', 'end_date' => '2026-10-18']);
    $month = $configured['goals']['month'];
    $reader = new RegisteredMetricReadServices();
    $expected = $reader->summary('actual_performance', '0', [$storeId], ['start' => '2026-10-01', 'end' => '2026-10-18']);
    if ($month['target_amount_cents'] === null || $month['target_amount_cents'] <= 0
        || $month['actual_performance_cents'] !== $expected
        || $month['achievement_rate'] !== round($expected / $month['target_amount_cents'] * 100, 1)) {
        throw new RuntimeException('Configured-goal formula regressed');
    }
    echo "group dashboard unconfigured goal: PASS\n";
} finally {
    Db::rollback();
}
