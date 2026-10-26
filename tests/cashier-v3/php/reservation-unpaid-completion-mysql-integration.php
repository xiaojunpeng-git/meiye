<?php

declare(strict_types=1);

/** Unpurchased appointment lines complete the workflow without business facts. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\cashier\v3\reservation\CashierV3ReservationCompletionFactServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();

$fixture = Db::name('cashier_v3_reservation_line')->alias('rl')
    ->join('cashier_v3_reservation r', 'r.id = rl.reservation_id')
    ->where('rl.project_source', 'ENTITLEMENT')
    ->field('rl.*,r.tenant_id AS fixture_tenant_id')
    ->order('rl.id desc')->find();
if (!$fixture) throw new RuntimeException('An entitlement reservation line is required for the mixed-path check');
$header = Db::name('cashier_v3_reservation')->where('id', (int)$fixture['reservation_id'])->find();
if (!$header) throw new RuntimeException('Entitlement reservation header is missing');

$writer = new CashierV3ReservationCompletionFactServices();
$run = static function (array $lines, int $reservationId) use ($writer, $header): array {
    $syntheticHeader = $header;
    $syntheticHeader['id'] = $reservationId;
    $syntheticHeader['service_order_id'] = 0;
    $syntheticHeader['service_order_no_snapshot'] = '';
    return $writer->persistInTx($syntheticHeader, $lines, [], [
        'eventNo' => 'reservation-unpaid-test:' . $reservationId,
        'commandIdempotencyKey' => 'reservation-unpaid-test:' . $reservationId,
        'operatorId' => 1,
        'operatorName' => 'Local test',
    ], time());
};
$unpaid = $fixture;
$unpaid['id'] = random_int(1000000000, 1500000000);
$unpaid['project_source'] = 'UNPAID';
$unpaid['entitlement_source_detail_id'] = 0;
$unpaid['point_customer_staff_ids'] = [];
$entitlement = $fixture;
$entitlement['id'] = $unpaid['id'] + 1;
$entitlement['point_customer_staff_ids'] = [];
$unpaidReservationId = random_int(1500000001, 1750000000);
$mixedReservationId = random_int(1750000001, 2000000000);
$tenantId = (string)$header['tenant_id'];

Db::startTrans();
try {
    try {
        $run([], $unpaidReservationId);
        throw new RuntimeException('Empty appointment lines were accepted');
    } catch (LogicException $error) {
        if ($error->getMessage() !== 'reservation_completion_lines_empty') throw $error;
    }
    $none = $run([$unpaid], $unpaidReservationId);
    $unpaidRequest = 'reservation:' . $unpaidReservationId;
    if ($none !== ['service' => 0, 'writeoff' => 0, 'performance' => 0]
        || Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $tenantId)->where('checkout_request_id', $unpaidRequest)->count() !== 0
        || Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', $tenantId)->where('checkout_request_id', $unpaidRequest)->count() !== 0
        || Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)->where('checkout_request_id', $unpaidRequest)->count() !== 0
        || Db::name('cashier_v3_customer_lifecycle_fact')->where('tenant_id', $tenantId)->where('natural_key', 'service-visit:' . $unpaidRequest)->count() !== 0) {
        throw new RuntimeException('Unpurchased reservation generated a business fact');
    }

    // A mixed appointment still records the purchased line, never its unpaid line.
    $mixed = $run([$unpaid, $entitlement], $mixedReservationId);
    $mixedRequest = 'reservation:' . $mixedReservationId;
    $serviceRows = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $tenantId)
        ->where('checkout_request_id', $mixedRequest)->field('source_line_id')->select()->toArray();
    if ($mixed['service'] !== 1 || count($serviceRows) !== 1
        || (string)$serviceRows[0]['source_line_id'] !== $mixedRequest . ':' . $entitlement['id']
        || Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)
            ->where('checkout_request_id', $mixedRequest)
            ->where('source_line_id', $mixedRequest . ':' . $unpaid['id'])->count() !== 0) {
        throw new RuntimeException('Mixed reservation did not isolate the purchased line');
    }
    echo "reservation unpaid completion integration: PASS\n";
} finally {
    Db::rollback();
}
