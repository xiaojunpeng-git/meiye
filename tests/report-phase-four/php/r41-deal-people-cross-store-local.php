<?php

declare(strict_types=1);

/** 只在本机瑞昊开发库模拟跨店补交，事务结束必须还原原始财务事实。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreDealPeopleQualificationServices;
use think\facade\Db;

(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
if (getenv('R41_DEAL_PEOPLE_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('需要显式指定本机瑞昊开发库');
}
$range = ['start' => '2026-10-01', 'end' => '2026-10-17'];
$service = new StoreDealPeopleQualificationServices();
$events = $service->qualifiedDays([41, 108], $range);
$sample = null;
foreach ($events as $event) {
    if ((int)$event['store_id'] === 41
        && (string)$event['payment_source_document_type'] === 'debt_repayment') {
        $sample = $event;
        break;
    }
}
if ($sample === null) throw new RuntimeException('本机样本缺少原店 41 的补交达标事件');

Db::startTrans();
try {
    $factId = (string)$sample['fact_id'];
    $repaymentId = (string)$sample['payment_order_id'];
    $paymentChanged = Db::name('cashier_v3_payment_fact')->where('fact_id', $factId)
        ->where('store_id', 41)->update(['store_id' => 108]);
    $repaymentChanged = Db::name('cashier_v3_debt_repayment')->where('repayment_id', $repaymentId)
        ->where('store_id', 41)->update(['store_id' => 108]);
    if ($paymentChanged !== 1 || $repaymentChanged !== 1) {
        throw new RuntimeException('跨店样本的补交单与支付事实未同时切换');
    }
    $originalStore = $service->qualifiedDays([41], $range);
    $paymentStore = $service->qualifiedDays([108], $range);
    $contains = static function (array $days) use ($factId): bool {
        foreach ($days as $day) if ((string)$day['fact_id'] === $factId) return true;
        return false;
    };
    if (!$contains($originalStore) || $contains($paymentStore)) {
        throw new RuntimeException('跨店补交达标人数必须只归原销售门店');
    }
    echo "PASS R41 cross-store repayment belongs to original store\n";
} finally {
    Db::rollback();
}
