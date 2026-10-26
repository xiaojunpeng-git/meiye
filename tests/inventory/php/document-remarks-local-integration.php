<?php
declare(strict_types=1);

// This test is only for the verified local ruihao Docker database; its outer transaction always rolls back.
if (getenv('INVENTORY_REMARK_LOCAL_TEST') !== 'yes') throw new RuntimeException('local_remark_test_flag_required');
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || gethostbyname('mysql') !== '172.18.0.2') throw new RuntimeException('local_database_target_mismatch');

use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\product\inventory\InventoryDocumentRemarkServices;
use think\facade\Db;

function checkRemark(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
}

$service = new InventoryDocumentRemarkServices();
$admin = (array)Db::name('system_admin')->where('id', 1)->find();
checkRemark((int)($admin['level'] ?? -1) === 0, 'local HQ operator');
$originalProfile = (array)(request()->storeStaffInfo ?? []);
$originalResolver = app()->make(CashierV3FeatureResolver::class);
$resolver = new class extends CashierV3FeatureResolver {
    // Only the test's trusted session is stubbed; domain document/warehouse scope still hits real local rows.
    public function resolveGrantedFeatures(array $profile): array
    {
        return ['cashier.v3.inventory.request', 'cashier.v3.inventory.usage', 'cashier.v3.inventory.transfer'];
    }
};
app()->instance(CashierV3FeatureResolver::class, $resolver);

$factsBefore = Db::name('inventory_batch_movement_fact')->count();
$stockBefore = (string)Db::name('inventory_stock')->sum('available_quantity_units');
$historyBefore = Db::name('inventory_document_remark_history')->count();
$stateBefore = Db::name('inventory_document_remark')->count();
$originalVoucherRemarks = [];
Db::startTrans();
try {
    $inbound = Db::name('inventory_batch_movement_fact')->where('source_type', 'manual_inbound')
        ->where('store_id', 0)->order('id desc')->field('source_id,location_id')->find();
    $outbound = Db::name('inventory_batch_movement_fact')->where('source_type', 'manual_outbound')
        ->where('store_id', 0)->order('id desc')->field('source_id,location_id')->find();
    $transfer = Db::name('inventory_cross_transfer_document')->where('from_party_type', 'HQ')
        ->where('document_status', 'RECEIVED')->order('id desc')->field('id,from_location_id')->find();
    foreach ([
        ['inbound', (string)$inbound['source_id'], (int)$inbound['location_id']],
        ['outbound', (string)$outbound['source_id'], (int)$outbound['location_id']],
        ['transfer', (string)$transfer['id'], (int)$transfer['from_location_id']],
    ] as [$type, $id, $locationId]) {
        $scope = ['hq_location_id' => $locationId];
        $before = $service->detailForPlatform($admin, $type, $id, $scope);
        checkRemark($before['remark_editable'] && count($before['lines']) > 0, $type . ' HQ editable');
        if ($type === 'inbound' || $type === 'outbound') {
            $service->initializeManual($type, '0', $id, (string)($before['document']['remark'] ?? ''));
            $initialized = $service->detailForPlatform($admin, $type, $id, $scope);
            checkRemark((int)$initialized['remark_version'] === (int)$before['remark_version']
                && $initialized['document']['remark'] === (string)($before['document']['remark'] ?? ''),
                $type . ' initialization preserves existing note projection');
        }
        $lineId = (int)($before['lines'][0]['line_id'] ?? $before['lines'][0]['id']);
        $newHeader = 'TEST remark ' . $type;
        $newLine = 'TEST line ' . $type;
        $lines = array_map(static fn (array $line): array => [
            'line_id' => (int)($line['line_id'] ?? $line['id']),
            'detail_remark' => (int)($line['line_id'] ?? $line['id']) === $lineId ? $newLine : (string)($line['detail_remark'] ?? ''),
        ], $before['lines']);
        $payload = ['type' => $type, 'version' => (int)$before['remark_version'], 'remark' => $newHeader, 'lines' => $lines];
        $saved = $service->saveForPlatform($admin, $type, $id, $scope, $payload);
        checkRemark((int)$saved['version'] === (int)$before['remark_version'] + 1, $type . ' increments version');
        $after = $service->detailForPlatform($admin, $type, $id, $scope);
        checkRemark($after['document']['remark'] === $newHeader && (string)$after['lines'][0]['detail_remark'] === $newLine
            && count($after['remark_history']) > 0, $type . ' header, line and history refresh');
        if ($type === 'transfer') checkRemark($after['remark'] === $newHeader, 'transfer existing detail view sees revised header');
        if ($type === 'transfer') {
            $originalVoucherRemarks['transfer'] = $before['remark'];
            checkRemark(Db::name('inventory_cross_transfer_document')->where('id', (int)$id)->value('remark') === $newHeader,
                'transfer list source sees revised header');
        }
        try { $service->saveForPlatform($admin, $type, $id, $scope, $payload); $staleRejected = false; }
        catch (RuntimeException $exception) { $staleRejected = $exception->getMessage() === 'inventory_document_remark_changed'; }
        checkRemark($staleRejected, $type . ' rejects stale version');
        $bad = $payload;
        $bad['version'] = (int)$after['remark_version'];
        $bad['lines'][0]['line_id'] = 999999999;
        try { $service->saveForPlatform($admin, $type, $id, $scope, $bad); $badRejected = false; }
        catch (InvalidArgumentException $exception) { $badRejected = true; }
        checkRemark($badRejected, $type . ' rejects foreign line');
    }

    $storeInbound = Db::name('inventory_batch_movement_fact')->where('source_type', 'manual_inbound')
        ->where('store_id', '>', 0)->order('id desc')->field('source_id,store_id,location_id')->find();
    $storeScope = ['subject' => 'STORE', 'storeId' => (int)$storeInbound['store_id'], 'locationId' => (int)$storeInbound['location_id']];
    $storeRead = $service->detailForPlatform($admin, 'inbound', (string)$storeInbound['source_id'], $storeScope);
    checkRemark($storeRead['remark_editable'] === true, 'platform function access permits scoped store-warehouse note edit');
    $service->saveForPlatform($admin, 'inbound', (string)$storeInbound['source_id'], $storeScope, [
        'type' => 'inbound', 'version' => (int)$storeRead['remark_version'], 'remark' => 'TEST platform store note',
        'lines' => array_map(static fn (array $line): array => ['line_id' => (int)$line['id'], 'detail_remark' => 'TEST platform store line'], $storeRead['lines']),
    ]);
    $storeAfter = $service->detailForPlatform($admin, 'inbound', (string)$storeInbound['source_id'], $storeScope);
    checkRemark($storeAfter['document']['remark'] === 'TEST platform store note'
        && $storeAfter['lines'][0]['detail_remark'] === 'TEST platform store line',
        'platform store-warehouse note edit persists only within transaction');

    $request = Db::name('inventory_stock_request_document')->where('document_status', 'CANCELLED')
        ->where('store_id', '>', 0)->order('id desc')->field('id,store_id,operator_id')->find();
    $usage = Db::name('inventory_salon_usage_document')->where('store_id', '>', 0)
        ->where('document_status', 'SETTLED')->order('id desc')->field('id,store_id,operator_id')->find();
    foreach ([['request', $request], ['usage', $usage]] as [$type, $doc]) {
        $employee = Db::name('employee')->where('id', (int)$doc['operator_id'])->where('status', 1)->where('is_del', 0)->find();
        checkRemark((bool)$employee, $type . ' valid test employee');
        request()->storeStaffInfo = ['id' => 900001, 'employee_id' => (int)$employee['id'],
            'store_id' => (int)$doc['store_id'], '_cashier_v3_organization' => 1,
            'session_mode' => 'store_organization', 'read_only' => false];
        $before = $service->detailForStore((int)$doc['store_id'], 900001, $type, (string)$doc['id']);
        checkRemark($before['remark_editable'] && count($before['lines']) > 0, $type . ' store editable');
        $originalVoucherRemarks[$type] = (string)$before['document']['remark'];
        $payload = ['type' => $type, 'version' => (int)$before['remark_version'], 'remark' => 'TEST ' . $type,
            'lines' => array_map(static fn (array $line): array => ['line_id' => (int)($line['line_id'] ?? $line['id']), 'detail_remark' => 'TEST detail'], $before['lines'])];
        $service->saveForStore((int)$doc['store_id'], 900001, $type, (string)$doc['id'], $payload);
        $after = $service->detailForStore((int)$doc['store_id'], 900001, $type, (string)$doc['id']);
        checkRemark($after['document']['remark'] === 'TEST ' . $type && (string)$after['lines'][0]['detail_remark'] === 'TEST detail', $type . ' store notes persist within transaction');
        $table = $type === 'request' ? 'inventory_stock_request_document' : 'inventory_salon_usage_document';
        checkRemark(Db::name($table)->where('id', (int)$doc['id'])->value('remark') === 'TEST ' . $type,
            $type . ' list source sees revised header');
        if ($type === 'usage') {
            // Store middleware turns JSON numeric scalars into strings before the controller reads POST.
            $filteredPayload = ['type' => 'usage', 'version' => (string)$after['remark_version'],
                'remark' => 'TEST filtered usage', 'lines' => array_map(static fn (array $line): array => [
                    'line_id' => (string)$line['line_id'], 'detail_remark' => 'TEST filtered detail',
                ], $after['lines'])];
            $service->saveForStore((int)$doc['store_id'], 900001, 'usage', (string)$doc['id'], $filteredPayload);
            $filteredAfter = $service->detailForStore((int)$doc['store_id'], 900001, 'usage', (string)$doc['id']);
            checkRemark($filteredAfter['document']['remark'] === 'TEST filtered usage'
                && $filteredAfter['lines'][0]['detail_remark'] === 'TEST filtered detail'
                && (int)$filteredAfter['remark_version'] === (int)$after['remark_version'] + 1,
                'usage accepts filtered integer strings without losing note history');
            $badFilteredPayload = $filteredPayload;
            $badFilteredPayload['version'] = (string)$filteredAfter['remark_version'];
            $badFilteredPayload['lines'][0]['line_id'] = '330.5';
            try { $service->saveForStore((int)$doc['store_id'], 900001, 'usage', (string)$doc['id'], $badFilteredPayload); $badFilteredRejected = false; }
            catch (InvalidArgumentException $exception) { $badFilteredRejected = true; }
            checkRemark($badFilteredRejected, 'usage still rejects fractional line identifiers');
        }
    }
    request()->storeStaffInfo['read_only'] = true;
    try {
        $service->detailForStore((int)$usage['store_id'], 900001, 'usage', (string)$usage['id']);
        $readOnlyDenied = false;
    } catch (RuntimeException $exception) { $readOnlyDenied = true; }
    checkRemark($readOnlyDenied, 'read-only store session retains existing detail denial');
    unset(request()->storeStaffInfo['read_only']);
    $storeOutbound = Db::name('inventory_batch_movement_fact')->where('source_type', 'manual_outbound')
        ->where('store_id', '>', 0)->order('id desc')->field('source_id,store_id')->find();
    request()->storeStaffInfo = ['id' => 900001, 'store_id' => (int)$storeOutbound['store_id'],
        'employee_id' => 1, '_cashier_v3_organization' => 1, '_cashier_v3_delegated' => 1,
        'session_mode' => 'store_read_only', 'read_only' => true];
    $outboundReadOnly = $service->detailForStore((int)$storeOutbound['store_id'], 900001, 'outbound', (string)$storeOutbound['source_id']);
    checkRemark($outboundReadOnly['remark_editable'] === false && count($outboundReadOnly['lines']) > 0,
        'delegated outbound can retain original read access without note write');
    try {
        $service->saveForStore((int)$storeOutbound['store_id'], 900001, 'outbound', (string)$storeOutbound['source_id'], [
            'type' => 'outbound', 'version' => (int)$outboundReadOnly['remark_version'], 'remark' => 'forbidden',
            'lines' => array_map(static fn (array $line): array => ['line_id' => (int)$line['id'], 'detail_remark' => 'forbidden'], $outboundReadOnly['lines']),
        ]);
        $outboundWriteDenied = false;
    } catch (RuntimeException $exception) { $outboundWriteDenied = true; }
    checkRemark($outboundWriteDenied, 'delegated outbound cannot write notes');
    request()->storeStaffInfo = ['id' => 900001, 'employee_id' => (int)$employee['id'],
        'store_id' => (int)$usage['store_id'], '_cashier_v3_organization' => 1,
        'session_mode' => 'store_organization', 'read_only' => false];
    app()->instance(CashierV3FeatureResolver::class, new class extends CashierV3FeatureResolver {
        public function resolveGrantedFeatures(array $profile): array { return ['cashier.v3.inventory.request']; }
    });
    try {
        $service->detailForStore((int)$usage['store_id'], 900001, 'usage', (string)$usage['id']);
        $crossPageDenied = false;
    } catch (RuntimeException $exception) { $crossPageDenied = true; }
    checkRemark($crossPageDenied, 'request-only grant cannot read usage note endpoint');
    app()->instance(CashierV3FeatureResolver::class, $resolver);
    $draftRequest = Db::name('inventory_stock_request_document')->where('document_status', 'APPLIED')
        ->where('store_id', '>', 0)->order('id desc')->field('id,store_id,operator_id')->find();
    $draftEmployee = Db::name('employee')->where('id', (int)$draftRequest['operator_id'])->where('status', 1)->where('is_del', 0)->find();
    checkRemark((bool)$draftEmployee, 'editable request has valid actor');
    request()->storeStaffInfo = ['id' => 900001, 'employee_id' => (int)$draftEmployee['id'],
        'store_id' => (int)$draftRequest['store_id'], '_cashier_v3_organization' => 1,
        'session_mode' => 'store_organization', 'read_only' => false];
    $draftRead = $service->detailForStore((int)$draftRequest['store_id'], 900001, 'request', (string)$draftRequest['id']);
    checkRemark(!empty($draftRead['can_edit']) && $draftRead['remark_editable'], 'full-edit request also exposes separate note editor');
    $draftPayload = ['type' => 'request', 'version' => (int)$draftRead['remark_version'], 'remark' => 'TEST separate edit',
        'lines' => array_map(static fn (array $line): array => [
            'line_id' => (int)($line['line_id'] ?? $line['id']), 'detail_remark' => 'TEST draft line',
        ], $draftRead['lines'])];
    $service->saveForStore((int)$draftRequest['store_id'], 900001, 'request', (string)$draftRequest['id'], $draftPayload);
    // A full voucher edit owns the document lock; simulate its new note values before invoking the rebase hook.
    Db::name('inventory_stock_request_document')->where('id', (int)$draftRequest['id'])->update(['remark' => 'TEST full edit']);
    Db::name('inventory_stock_request_line')->where('document_id', (int)$draftRequest['id'])->update(['detail_remark' => 'TEST full line']);
    $service->rebaseAfterFullEdit('request', (int)$draftRequest['id'], 'TEST full edit', ['type' => 'STORE_STAFF', 'id' => 900001]);
    $rebased = $service->detailForStore((int)$draftRequest['store_id'], 900001, 'request', (string)$draftRequest['id']);
    checkRemark($rebased['document']['remark'] === 'TEST full edit' && $rebased['lines'][0]['detail_remark'] === 'TEST full line'
        && (int)$rebased['remark_version'] === (int)$draftRead['remark_version'] + 2 && count($rebased['remark_history']) >= 2,
        'full edit rebases notes and preserves both audit revisions');
    checkRemark(Db::name('inventory_batch_movement_fact')->count() === $factsBefore
        && (string)Db::name('inventory_stock')->sum('available_quantity_units') === $stockBefore,
        'stock facts and balances unchanged');
} finally {
    Db::rollback();
    request()->storeStaffInfo = $originalProfile;
    app()->instance(CashierV3FeatureResolver::class, $originalResolver);
}
checkRemark(Db::name('inventory_document_remark')->count() === $stateBefore
    && Db::name('inventory_document_remark_history')->count() === $historyBefore,
    'outer transaction rolled back every test note');
checkRemark((string)Db::name('inventory_cross_transfer_document')->where('id', (int)$transfer['id'])->value('remark') === $originalVoucherRemarks['transfer']
    && (string)Db::name('inventory_stock_request_document')->where('id', (int)$request['id'])->value('remark') === $originalVoucherRemarks['request']
    && (string)Db::name('inventory_salon_usage_document')->where('id', (int)$usage['id'])->value('remark') === $originalVoucherRemarks['usage'],
    'outer transaction restored source voucher notes');
