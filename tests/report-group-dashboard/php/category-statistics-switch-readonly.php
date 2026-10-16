<?php

declare(strict_types=1);

/** Read-only local regression: category statistics visibility must not change cash facts. */
require '/var/www/html/vendor/autoload.php';

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\GroupManagementDashboardServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local ruihao Docker database is allowed');
}

function assertCategoryStatistics(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ', $message, PHP_EOL;
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $roots = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
        ->where('pid', 0)->field('id,cate_name,statistics_enabled,sort')
        ->order('sort', 'desc')->order('id', 'desc')->select()->toArray();
    $disabled = array_values(array_map('intval', array_column(array_filter($roots,
        static fn(array $row): bool => (int)$row['statistics_enabled'] === 0), 'id')));
    $enabled = array_values(array_map('intval', array_column(array_filter($roots,
        static fn(array $row): bool => (int)$row['statistics_enabled'] === 1), 'id')));
    assertCategoryStatistics($disabled !== [] && $enabled !== [], 'local catalog has enabled and disabled first-level categories');

    $context = ['tenant_id' => '0', 'store_ids' => [68]];
    $range = ['start_date' => '2026-10-14', 'end_date' => '2026-10-14'];
    $dashboard = (new GroupManagementDashboardServices())->dashboard($context, $range);
    $cardIds = array_map('intval', array_column($dashboard['categories'], 'category_id'));
    $filter = current(array_filter($dashboard['filter_schema'],
        static fn(array $field): bool => $field['key'] === 'category_id'));
    $optionIds = array_map('intval', array_column($filter['options'], 'value'));
    foreach ($disabled as $id) {
        assertCategoryStatistics(!in_array($id, $cardIds, true) && !in_array($id, $optionIds, true),
            'disabled category '.$id.' is absent from cards and filter');
    }
    foreach ($enabled as $id) {
        assertCategoryStatistics(in_array($id, $cardIds, true) && in_array($id, $optionIds, true),
            'enabled category '.$id.' remains in cards and filter');
    }
    assertCategoryStatistics(array_values(array_filter($cardIds, static fn(int $id): bool => $id > 0)) === $enabled,
        'category cards follow sort desc and id desc without client reshuffling');
    assertCategoryStatistics(array_slice($optionIds, 1) === $enabled, 'category filter and cards share the same configured order');

    $cashCard = current(array_filter($dashboard['cards'],
        static fn(array $card): bool => $card['metric_code'] === 'cash_performance'));
    $expectedCash = (new RegisteredMetricReadServices())->summary('cash_performance', '0', [68],
        ['start' => '2026-10-14', 'end' => '2026-10-14']);
    assertCategoryStatistics((int)$cashCard['value_cents'] === $expectedCash && $expectedCash > 0,
        'overall cash performance remains on the registered facts');

    try {
        (new GroupManagementDashboardServices())->dashboard($context, $range + ['category_id' => $disabled[0]]);
        throw new RuntimeException('disabled category filter unexpectedly accepted');
    } catch (InvalidArgumentException $exception) {
        assertCategoryStatistics(str_contains($exception->getMessage(), '已关闭统计'),
            'stale disabled-category filter asks for a new selection');
    }
} finally {
    Db::rollback();
}
