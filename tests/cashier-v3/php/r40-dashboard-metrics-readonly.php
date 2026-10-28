<?php
/** 本地只读逐指标对账：卡片、趋势和全量分页明细须使用同一事实和门店日期范围。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\dashboard\CashierV3BusinessDashboardReadModel;
if (config('database.connections.mysql.database') !== 'ruihao' || config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('LOCAL_ONLY');
Db::execute('SET TRANSACTION READ ONLY'); Db::startTrans();
try {
    $id = (int)Db::name('system_store')->where('name', '扬州欧尚店')->value('id');
    if ($id <= 0) throw new RuntimeException('LOCAL_STORE_MISSING');
    $operator = new CashierV3OperatorScope($id, 1, '', '0');
    $scope = new CashierV3DataScopeContext(1, 1, $id, '0', '', [$id], 'stores', [], false, '', '', ['cashier.v3.management.dashboard'], []);
    $reader = new CashierV3BusinessDashboardReadModel(static function (): bool { return true; });
    $base = ['startDate' => '2026-10-01', 'endDate' => '2026-10-28']; $results = [];
    foreach (CashierV3BusinessDashboardReadModel::METRICS as $code) {
        $dashboard = $reader->dashboard($base + ['metricCode' => $code], $operator, $scope);
        $cards = array_values(array_filter($dashboard['cards'], static function ($card) use ($code) { return $card['metricCode'] === $code; }));
        if (count($cards) !== 1) throw new RuntimeException($code . ': missing card');
        $card = $cards[0]; $expected = (int)($card['valueCents'] ?? $card['value']);
        $trend = array_sum(array_map(static function ($point) { return $point['valueCents'] ?? $point['value']; }, $dashboard['trend']['points']));
        $sum = 0; $count = 0; $ids = []; $page = 1;
        do {
            $detail = $reader->detail($base + ['metricCode' => $code, 'page' => $page], $operator, $scope);
            if (in_array('status', array_column($detail['columns'], 'key'), true)) throw new RuntimeException($code . ': technical status exposed');
            foreach ($detail['records'] as $row) {
                if (isset($ids[$row['id']])) throw new RuntimeException($code . ': duplicate across pages');
                $ids[$row['id']] = true; $sum += (int)($row['amountCents'] ?? $row['amount']); ++$count;
            }
            ++$page;
        } while ($count < $detail['total'] && $page <= 500);
        if ($expected !== (int)$trend || $expected !== $sum || $count !== $detail['total']) throw new RuntimeException($code . ': card, trend and detail mismatch');
        $results[$code] = ['detailRows' => $count, 'pages' => $page - 1];
    }
    echo json_encode(['result' => 'PASS', 'readOnly' => true, 'metrics' => $results], JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally { Db::rollback(); }
