<?php
declare(strict_types=1);

/** 只读核对真实退款：先按原规则守恒分摊，再裁剪分类，不把被排除金额重新摊给开启分类。 */
$root = getenv('CASHIER_V3_BACKEND_ROOT') ?: '/var/www/html';
require $root . '/vendor/autoload.php';
use app\services\report\StoreMemberConsumptionRefundReadServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;
(new think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $e): void { file_put_contents('php://stderr', $e->getMessage() . "\n"); exit(1); });
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local mirror only');
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $report = new StoreUnifiedReportServices();
    $reader = new StoreMemberConsumptionRefundReadServices();
    $method = new ReflectionMethod($report, 'partnerReportCategoryIds'); $method->setAccessible(true);
    $allowed = $method->invoke($report, [177,165,108,23,84]);
    // 混合分类退款的确定性边界：100元按1:2归整后，只保留开启分类的33元，不重分摊。
    $allocation = new ReflectionMethod($report, 'allocateRefundCategoryCents'); $allocation->setAccessible(true);
    $mixed = [['id'=>26317,'weight_cents'=>100], ['id'=>26332,'weight_cents'=>200]];
    $parts = $allocation->invokeArgs(null, [-10000, &$mixed]);
    if ($parts !== [-3300,-6700] || !isset($allowed[26317]) || isset($allowed[26332])) {
        throw new RuntimeException('Mixed-category refund boundary mismatch');
    }
    $checked = $included = $excluded = 0;
    foreach ([[177,'2026-10-16'],[165,'2026-10-16'],[108,'2026-10-15'],[23,'2026-10-16'],[84,'2026-10-12']] as [$store,$date]) {
        $range = ['start'=>$date, 'end'=>$date];
        $query = $reader->query([$store], $range, []);
        $refunds = $reader->rows($query, array_map('intval', (clone $query)->column('refund_operation.id')));
        $expected = [];
        foreach ($refunds as $refund) {
            $categories = $refund['_current_categories'] ?? [];
            if (!$categories) $categories = [['id'=>0, 'weight_cents'=>0]];
            $amount = (int)$refund['amount_cents'];
            $weights = array_map(static fn(array $row): int => (int)($row['weight_cents'] ?? 0), $categories);
            if (count($categories) > 1 && (array_sum($weights) <= 0 || min($weights) <= 0)) {
                $categories = [['id'=>0]]; $weights = [0];
            }
            $unit = $amount % 100 === 0 ? 100 : 1;
            $remaining = intdiv(abs($amount), $unit);
            $total = $remaining;
            foreach ($categories as $index=>$category) {
                $part = $index === count($categories)-1 ? $remaining : intdiv($total*$weights[$index], array_sum($weights));
                $remaining -= $part;
                $checked++;
                if (!isset($allowed[(int)$category['id']])) { $excluded++; continue; }
                $included++;
                $expected[(string)$refund['order_id'] . '|' . (int)$category['id']] = ($amount < 0 ? -1 : 1)*$part*$unit;
            }
        }
        $input = ['report'=>'partner_item_detail','start_date'=>$date,'end_date'=>$date,'_internal_all'=>true];
        $detail = $report->query([$store], $input);
        $actual = [];
        foreach ($detail['records'] as $row) if ($row['source_type'] === 'refund') {
            $actual[(string)$row['order_id'] . '|' . (int)$row['category_id_snapshot']] = (int)$row['sale_amount_cents'];
        }
        ksort($actual); ksort($expected);
        if ($actual !== $expected) throw new RuntimeException('Refund category/amount mismatch at store ' . $store);
        if ($report->export([$store], $input)['records'] !== $detail['records']) throw new RuntimeException('Refund export differs');
    }
    if ($checked === 0 || $excluded === 0) throw new RuntimeException('Need real disabled-category refund fixtures');
    echo json_encode(['result'=>'PASS','refund_categories_checked'=>$checked,'included'=>$included,'excluded'=>$excluded,
        'mixed_category_retained_cents'=>$parts[0]]) . "\n";
} finally { Db::rollback(); }
