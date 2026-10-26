<?php
declare(strict_types=1);

/**
 * 第 19 轮只读回归：人员调整事实修正日期后，销售人业绩只能读取最终有效
 * 正向分配；旧销售事实与跨日冲销链继续留库审计，但不得重复进入汇总、明细和导出。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\report\StoreSalespersonNetReadServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if ((string)(Db::query('SELECT DATABASE() db')[0]['db'] ?? '') !== 'ruihao') {
    throw new RuntimeException('This regression only runs against the local ruihao test database.');
}

$reader = new StoreSalespersonNetReadServices();
$target = $reader->detail(
    [18], ['start' => '2026-10-01', 'end' => '2026-10-31'], [924], 0, 1, 20, false,
    ['order' => 'XS26100100024']
);
$rows = array_values(array_filter($target['records'], static fn(array $row): bool =>
    (string)($row['order_no_snapshot'] ?? '') === 'XS26100100024'));
if ((int)$target['total'] !== 1 || count($rows) !== 1 || (int)$target['amount_cents'] !== 50000
    || (int)($rows[0]['amount_cents'] ?? 0) !== 50000
    || strpos((string)($rows[0]['fact_id'] ?? ''), 'R47F-') !== 0
    || (string)($rows[0]['fact_direction'] ?? '') !== 'forward') {
    throw new RuntimeException('R47 corrected salesperson allocation was not folded to one terminal row.');
}

$day = $reader->detail(
    [18], ['start' => '2026-10-01', 'end' => '2026-10-01'], [924], 1, 1, 20
);
if ((int)$day['total'] !== 2 || (int)$day['amount_cents'] !== 100000) {
    throw new RuntimeException('October 1 salesperson detail still contains a duplicate or lost a valid order.');
}

echo "SALESPERSON_PERFORMANCE_TERMINAL_FACTS_READONLY_OK\n";
