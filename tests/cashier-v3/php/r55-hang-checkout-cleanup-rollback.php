<?php
/** R55: exercise local-draft cleanup and duplicate guard inside one local rollback. */
if (getenv('R55_LOCAL_ROLLBACK') !== '1' || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Explicit local rollback flag required');
}
$root = '/var/www/html';
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\hang\CashierV3HangCheckoutBindingServices;
use app\services\cashier\v3\hang\CashierV3HangResumeServices;
use think\facade\Db;

$cfg = config('database.connections.mysql');
if (($cfg['database'] ?? '') !== 'ruihao' || ($cfg['hostname'] ?? '') !== 'mysql') {
    throw new RuntimeException('Not the local copy');
}
$sample = Db::name('cashier_v3_hang_order')->where('hang_mode', 'local_draft')
    ->order('id', 'desc')->limit(1)->select()->toArray()[0] ?? null;
$requestSample = Db::name('cashier_v3_checkout_request')->order('id', 'desc')
    ->limit(1)->select()->toArray()[0] ?? null;
if (!is_array($sample) || !is_array($requestSample)) {
    throw new RuntimeException('Local fixture missing');
}
$nonce = bin2hex(random_bytes(12));
$operator = new CashierV3OperatorScope(
    (int)$sample['store_id'], (int)$sample['operator_id'],
    (string)$sample['organization_id'], (string)$sample['tenant_id']
);
$scope = new CashierV3DataScopeContext(
    (int)$sample['operator_id'], (int)$sample['operator_id'], (int)$sample['store_id'],
    (string)$sample['tenant_id'], (string)$sample['organization_id'], [(int)$sample['store_id']],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r55-rollback', [], []
);
$cloneHang = static function (string $suffix) use ($sample, $nonce): array {
    $row = $sample;
    unset($row['id']);
    $row['hang_order_id'] = 'HGO' . hash('sha1', $nonce . $suffix);
    $row['hang_order_no'] = 'HGD-R55-' . $nonce . $suffix;
    $row['natural_key'] = 'r55:' . $nonce . ':' . $suffix;
    $row['command_idempotency_key'] = 'r55:' . $nonce . ':' . $suffix;
    $row['preparation_request_id'] = 'R55-' . $nonce . $suffix;
    $row['preparation_token'] = hash('sha256', $nonce . $suffix);
    $row['hang_status'] = 'pending_checkout';
    $row['resume_workspace_id'] = '';
    $row['resume_state_context_id'] = '';
    $row['checkout_request_id'] = '';
    $row['sales_order_id'] = '';
    $row['resumed_at'] = 0;
    $row['settled_at'] = 0;
    return $row;
};
$binding = new CashierV3HangCheckoutBindingServices();
Db::startTrans();
try {
    $fresh = $cloneHang('fresh');
    Db::name('cashier_v3_hang_order')->insert($fresh);
    $requestId = 'CKR-R55-' . $nonce;
    $result = $binding->completeFromCheckoutInTx(
        ['request' => ['request_id' => $requestId, 'member_id' => (int)$fresh['member_id'],
            'resumed_hang_order_id' => $fresh['hang_order_id']]],
        $requestId, $operator, $scope
    );
    if ($result['deleted'] !== true || $result['deletedLineCount'] !== 0
        || Db::name('cashier_v3_hang_order')->where('hang_order_id', $fresh['hang_order_id'])->count() !== 0) {
        throw new RuntimeException('Local-draft cleanup failed');
    }

    $stale = $cloneHang('stale');
    Db::name('cashier_v3_hang_order')->insert($stale);
    $oldRequest = $requestSample;
    unset($oldRequest['id']);
    $oldRequest['request_id'] = 'CKR-R55-OLD-' . $nonce;
    $oldRequest['tenant_id'] = $stale['tenant_id'];
    $oldRequest['store_id'] = $stale['store_id'];
    $oldRequest['member_id'] = $stale['member_id'];
    $oldRequest['request_status'] = 'succeeded';
    $oldRequest['resumed_hang_order_id'] = $stale['hang_order_id'];
    $oldRequest['creation_idempotency_key'] = 'r55:old:' . $nonce;
    $oldRequest['last_idempotency_key'] = 'r55:old:' . $nonce;
    Db::name('cashier_v3_checkout_request')->insert($oldRequest);
    $resume = new CashierV3HangResumeServices(
        new CashierV3CashierWorkspaceServices(new CashierV3CashierReadinessGuard()),
        new CashierV3SaleCatalogServices()
    );
    try {
        $resume->resumeDirectInTx($stale['hang_order_id'], $stale['state_context_id'], $operator, $scope);
        throw new RuntimeException('Stale direct resume was accepted');
    } catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
        if (strpos($exception->getMessage(), '已结账') === false) throw $exception;
    }
    try {
        $binding->completeFromCheckoutInTx(
            ['request' => ['request_id' => 'CKR-R55-NEW-' . $nonce,
                'member_id' => (int)$stale['member_id'], 'resumed_hang_order_id' => $stale['hang_order_id']]],
            'CKR-R55-NEW-' . $nonce, $operator, $scope
        );
        throw new RuntimeException('Duplicate checkout was accepted');
    } catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
        if (Db::name('cashier_v3_hang_order')->where('hang_order_id', $stale['hang_order_id'])->count() !== 1) {
            throw new RuntimeException('Duplicate guard removed the source');
        }
    }
    echo "PASS local-draft cleanup, direct-resume duplicate guard, and checkout duplicate guard\n";
} finally {
    Db::rollback();
}
