<?php
declare(strict_types=1);

/** 本地回滚验证：分页单位是单据，当前页单据必须携带完整明细和准确合计。 */
require '/var/www/html/vendor/autoload.php';

use app\services\fund\StoreFundServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('This probe requires the local mysql container.');
}
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$subject = (array)Db::name('store_fund_subject')->where('is_enabled', 1)->find();
if (!$subject) throw new RuntimeException('No active local subject.');

$service = new StoreFundServices();
$storeId = random_int(900000000, 999999999);
$scope = ['mode'=>'store', 'store_id'=>$storeId];
$now = date('Y-m-d H:i:s');
$documentIds = [];
Db::startTrans();
try {
    for ($index = 1; $index <= 101; $index++) {
        $documentId = (int)Db::name('store_fund_document')->insertGetId([
            'document_no'=>sprintf('R49-%d-%02d', $storeId, $index), 'store_id'=>$storeId,
            'business_date'=>'2099-12-31', 'direction'=>'EXPENSE', 'document_status'=>'APPROVED',
            'summary'=>'R49 rollback pagination probe', 'remark'=>'', 'attachments_json'=>'[]',
            'source_document_id'=>0, 'created_by_type'=>'STORE_STAFF', 'created_by_id'=>1,
            'created_by_name_snapshot'=>'R49 local probe', 'audited_by_type'=>'STORE_STAFF', 'audited_by_id'=>1,
            'audited_at'=>$now, 'audit_revision'=>1, 'created_at'=>$now, 'updated_at'=>$now,
        ]);
        $documentIds[] = $documentId;
        foreach ([100 + $index, 200 + $index] as $lineIndex=>$amountCents) {
            Db::name('store_fund_document_line')->insert([
                'document_id'=>$documentId, 'line_no'=>$lineIndex + 1, 'subject_id'=>$subject['id'],
                'subject_code_snapshot'=>$subject['subject_code'], 'subject_name_snapshot'=>$subject['subject_name'],
                'subject_type_snapshot'=>$subject['subject_type'], 'amount_cents'=>$amountCents,
                'summary'=>'R49 line '.($lineIndex + 1), 'remark'=>'', 'created_at'=>$now, 'updated_at'=>$now,
            ]);
        }
    }
    // 即使客户端沿用旧的 20，服务端也必须执行已确认的固定 100 张单据分页契约。
    $filters = ['date_from'=>'2099-12-31', 'date_to'=>'2099-12-31', 'limit'=>20];
    $first = $service->listDocuments($scope, $filters + ['page'=>1]);
    $second = $service->listDocuments($scope, $filters + ['page'=>2]);
    $empty = $service->listDocuments($scope, $filters + ['page'=>3]);
    $assert((int)$first['limit'] === 100 && (int)$first['count'] === 101 && count($first['list']) === 100, 'First page contains 100 of 101 documents');
    $assert((int)$second['limit'] === 100 && (int)$second['count'] === 101 && count($second['list']) === 1, 'Second page contains final document');
    $assert((int)$empty['limit'] === 100 && (int)$empty['count'] === 101 && count($empty['list']) === 0, 'Out-of-range page does not duplicate rows');
    foreach (array_merge($first['list'], $second['list']) as $row) {
        $assert((int)$row['line_count'] === 2 && count($row['lines']) === 2, 'Every paged document carries both detail lines');
        $assert((int)$row['amount_cents'] === array_sum(array_column($row['lines'], 'amount_cents')), 'Document total equals grouped line total');
    }
    $assert((int)$service->listDocuments(['mode'=>'store','store_id'=>$storeId - 1], $filters)['count'] === 0, 'Store scope cannot read another store group');

    // 台账以明细为分页粒度，服务端固定 100 条；跨页余额必须延续完整时间序列。
    $ledgerFirst = $service->ledger($scope, $filters + ['page'=>1]);
    $ledgerSecond = $service->ledger($scope, $filters + ['page'=>2]);
    $ledgerThird = $service->ledger($scope, $filters + ['page'=>3]);
    $ledgerEmpty = $service->ledger($scope, $filters + ['page'=>4]);
    $assert((int)$ledgerFirst['limit'] === 100 && (int)$ledgerFirst['count'] === 202 && count($ledgerFirst['list']) === 100, 'Ledger first page contains 100 of 202 lines');
    $assert((int)$ledgerSecond['limit'] === 100 && count($ledgerSecond['list']) === 100, 'Ledger second page contains the next 100 lines');
    $assert((int)$ledgerThird['limit'] === 100 && count($ledgerThird['list']) === 2, 'Ledger third page contains final two lines');
    $assert((int)$ledgerEmpty['count'] === 202 && count($ledgerEmpty['list']) === 0, 'Ledger out-of-range page does not duplicate rows');
    $lastFirst = end($ledgerFirst['list']);
    $firstSecond = $ledgerSecond['list'][0];
    $assert((int)$firstSecond['balance_cents'] === (int)$lastFirst['balance_cents'] + (int)$firstSecond['signed_amount_cents'], 'Ledger running balance continues across page boundary');
    $fullLedger = $service->ledger($scope, $filters, false);
    $assert(count($fullLedger['list']) === 202 && (int)$fullLedger['count'] === 202, 'Ledger export projection keeps all authorized rows');
} finally {
    Db::rollback();
}
$assert((int)Db::name('store_fund_document')->whereIn('id', $documentIds)->count() === 0, 'Pagination probe rolled back');
echo json_encode(['result'=>'PASS','checks'=>$checks,'rollback'=>true], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), PHP_EOL;
