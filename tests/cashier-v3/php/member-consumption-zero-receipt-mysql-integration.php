<?php

declare(strict_types=1);

/**
 * 事务内克隆一条有效销售明细，不写入任何收款分摊。
 * 验证会员消费明细不会因收款为 0 隐藏真实成交，完成后回滚，不污染门店数据。
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
date_default_timezone_set('Asia/Shanghai');

$seed = Db::name('cashier_v3_sale_fact')->alias('s')
    ->join('cashier_v3_report_sale_dimension_fact d', 'd.sale_fact_id=s.fact_id')
    ->where('s.status', 'effective')
    ->where('s.store_id', '>', 0)
    ->field('s.fact_id')->order('s.id', 'desc')->find();
if (!$seed) throw new RuntimeException('Missing local sale fact fixture');

$seedSale = Db::name('cashier_v3_sale_fact')->where('fact_id', (string)$seed['fact_id'])->find();
$seedDimension = Db::name('cashier_v3_report_sale_dimension_fact')->where('sale_fact_id', (string)$seed['fact_id'])->find();
$storeId = (int)$seedSale['store_id'];
$date = (string)$seedSale['business_date'];
$random = bin2hex(random_bytes(20));
$factId = 'SF-ZERO-' . $random;
$input = [
    'report' => 'member_consumption_detail',
    'start_date' => $date,
    'end_date' => $date,
    'page' => 1,
    'limit' => 100,
];
$reports = new StoreUnifiedReportServices();
$before = $reports->query([$storeId], $input);

Db::startTrans();
try {
    $sale = $seedSale;
    unset($sale['id']);
    $sale['fact_id'] = $factId;
    $sale['business_event_no'] = 'zero-receipt-' . $random;
    $sale['natural_key'] = 'zero-receipt-' . $random;
    $sale['command_idempotency_key'] = 'zero-receipt-' . $random;
    $sale['immutable_fingerprint'] = hash('sha256', $random . '|sale');
    $sale['source_line_id'] = 'SL-ZERO-' . $random;
    $sale['item_id'] = 'ITEM-ZERO-' . $random;
    $sale['item_name_snapshot'] = '零收款有效销售测试';
    $sale['source_type'] = 'item';
    $sale['quantity'] = 1;
    $sale['original_amount_cents'] = 10000;
    $sale['discount_amount_cents'] = 0;
    $sale['sale_amount_cents'] = 10000;
    $sale['debt_amount_cents'] = 10000;
    Db::name('cashier_v3_sale_fact')->insert($sale);

    $dimension = $seedDimension;
    unset($dimension['id']);
    $dimension['sale_fact_id'] = $factId;
    $dimension['source_line_id'] = $sale['source_line_id'];
    $dimension['item_id'] = $sale['item_id'];
    $dimension['item_name_snapshot'] = $sale['item_name_snapshot'];
    // 临时样本不制造合作方分成，只验证“收款为 0 仍入表”这一边界。
    $dimension['cash_performance_amount_cents'] = 0;
    $dimension['partner_category_id_snapshot'] = 0;
    $dimension['partner_category_name_snapshot'] = '';
    $dimension['partner_category_path_snapshot'] = '';
    $dimension['partner_default_ratio_snapshot'] = 0;
    $dimension['partner_config_version_snapshot'] = 0;
    $dimension['partner_share_amount_cents'] = 0;
    $dimension['immutable_fingerprint'] = hash('sha256', $random . '|dimension');
    Db::name('cashier_v3_report_sale_dimension_fact')->insert($dimension);

    $after = $reports->query([$storeId], $input);
    $matched = array_values(array_filter($after['records'], static function (array $row) use ($factId): bool {
        return (string)($row['fact_id'] ?? '') === $factId;
    }));
    if ((int)$after['total'] !== (int)$before['total'] + 1 || count($matched) !== 1) {
        throw new RuntimeException('Zero-receipt valid sale was still hidden from member consumption detail');
    }
    if ((string)($matched[0]['receipt_total'] ?? '') !== '0') {
        throw new RuntimeException('Zero-receipt valid sale did not expose receipt total 0');
    }
    if ((string)($after['summary_row']['receipt_total'] ?? '') !== (string)($before['summary_row']['receipt_total'] ?? '')) {
        throw new RuntimeException('Zero-receipt sale changed the authoritative receipt summary');
    }

    echo "member consumption zero receipt mysql integration: PASS\n";
    Db::rollback();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
