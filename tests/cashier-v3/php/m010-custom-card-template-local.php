<?php
declare(strict_types=1);

/** Local Docker only: synthetic cards use real services and always roll back. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\card\CashierV3CustomCardTemplateServices;
use app\services\cashier\v3\card\CashierV3CustomCardConfigurationServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;

(new think\App('/var/www/html/'))->initialize();
// The optional dedicated Docker network has no published ports or customer
// data. Its connection override is process-local and never edits an .env file.
if (getenv('M010_ISOLATED_DB') === '1') {
    $databaseConfig = config('database');
    $databaseConfig['connections']['mysql'] = array_replace($databaseConfig['connections']['mysql'],
        ['hostname' => 'mysql', 'hostport' => '3306', 'database' => 'm010_custom_card',
            'username' => 'root', 'password' => '']);
    app()->config->set($databaseConfig, 'database');
}
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', get_class($e) . ': ' . $e->getMessage()
        . ($e instanceof CashierV3CommandException ? ' ' . json_encode($e->getDetail()) : '')
        . "\n" . $e->getTraceAsString() . "\n"); exit(1);
});
$expectedDatabase = getenv('M010_ISOLATED_DB') === '1' ? 'm010_custom_card' : 'ruihao';
if (getenv('M010_LOCAL_TEST') !== '1' || config('database.connections.mysql.hostname') !== 'mysql'
    || (string)Db::query('SELECT DATABASE() db')[0]['db'] !== $expectedDatabase) {
    throw new RuntimeException('Explicit local Docker test environment required');
}
date_default_timezone_set('Asia/Shanghai');
$checks = [];
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks[] = $label; echo 'PASS ' . $label . "\n";
};
function m010NewKey(): string
{
    $uuid = bin2hex(random_bytes(16));
    return 'CHECKOUT-' . substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4)
        . '-4' . substr($uuid, 13, 3) . '-8' . substr($uuid, 17, 3) . '-' . substr($uuid, 20, 12);
}
$tag = 'M010-' . bin2hex(random_bytes(8));
$baselineProbe = getenv('M010_BASELINE_PROBE') === '1';
$template = $baselineProbe ? null : new CashierV3CustomCardTemplateServices();
$catalog = new CashierV3SaleCatalogServices();
$configuration = new CashierV3CustomCardConfigurationServices($catalog);
$createdStore = 0;
Db::startTrans();
try {
    if (getenv('M010_ISOLATED_DB') === '1') {
        Db::name('cashier_v3_payment_method_config')->insert(['code' => 'cash',
            'default_name' => '现金', 'display_name' => '现金', 'status' => 1,
            'version' => 1, 'created_at' => time(), 'updated_at' => time()]);
    }
    $createdStore = (int)Db::name('system_store')->insertGetId(['name' => $tag, 'is_del' => 0]);
    $organization = (int)Db::name('organization')->insertGetId(['name' => $tag, 'pid' => 0, 'is_del' => 0]);
    Db::name('organization_store')->insert(['org_id' => $organization, 'store_id' => $createdStore]);
    $employeeId = (int)Db::name('employee')->insertGetId(['name' => $tag, 'status' => 1, 'is_del' => 0,
        'employment_type_code' => 'internal', 'employment_type_version' => 1]);
    $staffId = (int)Db::name('system_store_staff')->insertGetId(['store_id' => $createdStore, 'employee_id' => $employeeId,
        'staff_name' => $tag, 'account' => $tag, 'level' => 0, 'status' => 1, 'is_del' => 0]);
    $positionId = (int)Db::name('position')->insertGetId(['name' => $tag, 'status' => 1, 'use_store' => 1]);
    Db::name('staff_job_position')->insert(['employee_id' => $employeeId, 'staff_id' => $staffId,
        'store_id' => $createdStore, 'position_id' => $positionId, 'request_token' => $tag, 'status' => 1]);
    Db::name('job_position_channel_rule')->insert(['position_id' => $positionId,
        'channel' => 'store_v3', 'rules' => '301010,301012,301006,301032', 'status' => 1]);
    $staff = (array)Db::name('system_store_staff')->where('id', $staffId)->find();
    $member = (int)Db::name('user')->insertGetId(['account' => $tag, 'nickname' => $tag,
        'real_name' => $tag, 'belong_store_id' => $createdStore, 'status' => 1, 'is_del' => 0]);
    Db::name('store_user')->insert(['uid' => $member, 'store_id' => $createdStore, 'status' => 1]);
    $category = (int)Db::name('store_product_category')->insertGetId(['type' => 1,
        'relation_id' => $createdStore, 'cate_name' => $tag, 'is_show' => 1]);
    $project = (int)Db::name('store_product')->insertGetId(['type' => 1, 'relation_id' => $createdStore,
        'product_type' => 6, 'store_name' => $tag, 'cate_id' => (string)$category,
        'is_del' => 0, 'is_verify' => 1, 'is_show' => 0, 'is_inventory' => 0]);
    $sku = (int)Db::name('store_product_attr_value')->insertGetId(['product_id' => $project,
        'product_type' => 6, 'type' => 0, 'is_show' => 1, 'unique' => substr(md5($tag), 0, 8),
        'suk' => '默认', 'price' => '1960.00', 'ot_price' => '1960.00', 'cost' => '10.00']);
    $operator = new CashierV3OperatorScope($createdStore, $staffId, (string)$organization, '0');
    $scope = CashierV3Bootstrap::dispatcher()->dataScopeFactory()->build(
        $createdStore, $staffId, $staff, '0', (string)$organization);
    $payload = ['cardName' => $tag, 'validityEnd' => '2099-10-22', 'activateOnPurchase' => true,
        'components' => [['skuId' => $sku, 'times' => 3, 'amount' => '1960']]];
    if ($baselineProbe) {
        // Run this one branch against an immutable unpatched Git archive.
        // It proves the missing-template business failure, not the unretained
        // original HTTP 500 stack from the customer's earlier submission.
        $reason = '';
        try { $configuration->createSaleLineAfterGatewayLocksInTx($payload, $tag, $tag,
            $tag, [], $operator, $scope, $member, true, 'accepted-line'); }
        catch (CashierV3CommandException $e) { $reason = (string)($e->getDetail()['reason'] ?? ''); }
        $check($reason === 'custom_card_shell_not_available', 'unpatched baseline reproduces missing-template checkout rejection');
        return;
    }
    $check($template->find($createdStore) === null, 'new store has no inherited customer template');
    $catalog->discoverCustomCardShellResources($operator, $scope);
    $check($template->find($createdStore) === null, 'read-only discovery creates no carrier');
    Db::execute('SAVEPOINT m010_configuration_only');
    $sale = $configuration->createSaleLineAfterGatewayLocksInTx($payload, $tag, $tag,
        $tag, [], $operator, $scope, $member, true, 'accepted-line');
    $identity = $template->find($createdStore);
    $check($identity !== null && $identity['productId'] !== 8154 && $identity['skuId'] > 0,
        'missing template materializes without fixed product ID');
    $check((int)$sale['catalog_product_id'] === $identity['productId']
        && $sale['kind_code'] === 'custom_card' && (int)$sale['unit_price_cents'] === 196000,
        'configured amount is authoritative; carrier price remains zero');
    $again = $configuration->createSaleLineAfterGatewayLocksInTx($payload, $tag, $tag,
        $tag, [], $operator, $scope, $member, true, 'accepted-line');
    $check($again === $sale && Db::name('store_product')->where('keyword', $template::marker($createdStore))->count() === 1,
        'configuration replay retains one template and one immutable line');
    $row = Db::name(CashierV3CustomCardConfigurationServices::TABLE)->where('created_command_idempotency_key', $tag)->find();
    $check($row['workspace_line_key'] === 'sale:accepted-line', 'configuration retains browser line identity');
    $items = $catalog->catalog($operator, $scope);
    $check(!in_array($identity['skuId'], array_map('intval', array_column($items['customCardProjects'], 'id')), true),
        'internal carrier is not offered as a card component');
    $issued = $catalog->customCardIssuanceLineInTx(array_merge($row,
        ['configuration_snapshot' => json_decode($row['configuration_snapshot_json'], true)]), $operator, $scope);
    $check($issued['catalog_sku_id'] === $sale['catalog_sku_id'], 'issuer resolves the frozen template identity');
    Db::execute('ROLLBACK TO SAVEPOINT m010_configuration_only');
    $check($template->find($createdStore) === null, 'configuration-only trial leaves no carrier before the actual first checkout');

    // Invoke the actual production submit handler, not a parallel mock issuer.
    $handler = CashierV3Bootstrap::dispatcher()->handlers()->requireCommand('submit-checkout');
    $key = m010NewKey();
    $snapshot = ['memberId' => $member, 'businessDate' => date('Y-m-d'), 'occurredAt' => time(), 'lines' => [[
        'lineId' => 'real-submit-line', 'lineRole' => 'sale', 'kindCode' => 'custom_card',
        'quantity' => 1, 'amount' => '1960', 'originalAmount' => '1960',
        'customCardConfiguration' => $payload,
        'cardPurchaseSnapshot' => ['sourceKind' => 'custom_card', 'components' => [],
            'cardNameSnapshot' => $tag, 'activateOnPurchase' => true],
    ], ['lineId' => 'cash-payment', 'lineRole' => 'payment', 'quantity' => 1, 'method' => 'cash', 'amount' => '1960']]];
    $session = ['client_session_id' => str_replace('CHECKOUT-', 'SESSION-', $key),
        'state_context_id' => '', 'operator_ip' => '127.0.0.1'];
    $submit = static function (string $requestKey, array $input, ?callable $executor = null) use ($operator, $session, $handler, $scope): array {
        return CashierV3Bootstrap::gateway()->execute('submit-checkout',
            ['idempotencyKey' => $requestKey, 'contexts' => []], ['checkoutSnapshot' => $input],
            $operator, $session, $executor ?? $handler, $scope,
            CashierV3ActionManifest::requireAction('submit-checkout'));
    };
    $result = $submit($key, $snapshot);
    if (($result['status'] ?? '') !== 'success') throw new RuntimeException(json_encode($result['result'] ?? $result));
    $check(!empty($result['data']['checkoutSubmission']), 'production gateway completes a custom-card checkout');
    $identity = $template->find($createdStore);
    $check($identity !== null && Db::name('store_product')->where('keyword', $template::marker($createdStore))->count() === 1,
        'first real checkout creates its missing carrier inside the same final transaction');
    $check((int)Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $key)->value('sale_amount_cents') === 196000,
        'formal sale records the exact 1960-yuan amount');
    $check((int)Db::name('cashier_v3_payment_fact')->where('command_idempotency_key', $key)->sum('amount_cents') === 196000,
        'cash facts reconcile with the sale');
    $check(Db::name('user_card_holder')->where('uid', $member)->count() === 1,
        'checkout creates exactly one current-right holder');
    $receipt = (array)Db::name('cashier_v3_card_purchase_receipt')->where('member_id', $member)->find();
    $holderId = (int)$receipt['card_holder_id'];
    $check(Db::name('cashier_v3_card_state')->where('card_holder_id', $holderId)->value('card_status') === 'enabled'
        && (int)Db::name('user_card_holder')->where('id', $holderId)->value('write_surplus_times') === 3,
        'immediate activation preserves all three purchased uses');
    $accepted = (array)Db::name(CashierV3CustomCardConfigurationServices::TABLE)
        ->where('created_command_idempotency_key', $key . ':snapshot:0')->find();
    $check($accepted['status'] === 'settled' && $accepted['workspace_line_key'] === 'sale:real-submit-line'
        && (int)$accepted['card_holder_id'] === $holderId, 'configuration is bound to the actual signed card');
    $replay = $submit($key, $snapshot);
    $check(!empty($replay['replay']) && Db::name('user_card_holder')->where('uid', $member)->count() === 1
        && Db::name('cashier_v3_payment_fact')->where('command_idempotency_key', $key)->count() === 1,
        'repeated command never duplicates order payment or rights');
    $changed = $snapshot; $changed['lines'][0]['customCardConfiguration']['components'][0]['times'] = 4;
    $conflict = false;
    try { $submit($key, $changed); } catch (CashierV3CommandException $e) { $conflict = true; }
    $check($conflict, 'changed intent cannot reuse a successful request key');

    // Roll back through the real gateway after its complete business handler.
    // No injected mock replaces order, payment, issuer or event persistence.
    $faultKey = m010NewKey();
    $faultSnapshot = $snapshot; $faultSnapshot['lines'][0]['lineId'] = 'fault-submit-line';
    $failed = false;
    try {
        $submit($faultKey, $faultSnapshot, static function (array $execution) use ($handler): array {
            $handler($execution);
            throw new RuntimeException('M010 injected failure after card signing');
        });
    } catch (RuntimeException $e) { $failed = $e->getMessage() === 'M010 injected failure after card signing'; }
    $check($failed && Db::name('cashier_v3_command_receipt')->where('idempotency_key', $faultKey)->count() === 0
        && Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $faultKey)->count() === 0
        && Db::name('cashier_v3_payment_fact')->where('command_idempotency_key', $faultKey)->count() === 0
        && Db::name('user_card_holder')->where('uid', $member)->count() === 1
        && Db::name(CashierV3CustomCardConfigurationServices::TABLE)->where('created_command_idempotency_key', $faultKey . ':snapshot:0')->count() === 0,
        'late failure atomically removes request sale payment configuration and signed rights');
    $bound = (new \app\services\cashier\v3\CashierV3CommandFailureEnvelopeServices())->fromThrowable([
        'action' => 'submit-checkout', 'stateContextId' => $result['state_context_id'],
        'correlationId' => 'CORR-' . substr($key, 9),
        'command' => ['action' => 'submit-checkout', 'idempotencyKey' => $faultKey],
    ], new RuntimeException('M010 injected failure after card signing'));
    $check($bound['result']['status'] === 'failed' && $bound['boundIdempotencyKey'] === $faultKey,
        'rolled-back server exception is returned as a bound failure, not indefinite processing');

    $delayedKey = m010NewKey();
    $delayed = $snapshot; $delayed['lines'][0]['customCardConfiguration']['activateOnPurchase'] = false;
    $delayed['lines'][0]['lineId'] = 'delayed-submit-line';
    $delayed['lines'][0]['cardPurchaseSnapshot']['activateOnPurchase'] = false;
    $submit($delayedKey, $delayed);
    $delayedReceipt = (array)Db::name('cashier_v3_card_purchase_receipt')->where('command_idempotency_key', $delayedKey)->find();
    $check(Db::name('cashier_v3_card_state')->where('card_holder_id', $delayedReceipt['card_holder_id'])->value('card_status') === 'disabled',
        'delayed activation retains the original disabled-card business rule');
    $readiness = new CashierV3CashierReadinessGuard();
    $sources = (new CashierV3EntitlementProjectionServices($readiness,
        new CashierV3CashierWorkspaceServices($readiness), new CashierV3EntitlementResourceVersionProvider($readiness),
        CashierV3Bootstrap::dispatcher()->versionServices()))
        ->reservationSources($member, $operator, $scope);
    $byHolder = [];
    foreach ($sources as $source) $byHolder[(int)($source['id'] ?? $source['entitlementInstanceId'] ?? 0)] = $source;
    $check(!empty($byHolder[$holderId]['isCustomCard']) && !empty($byHolder[$holderId]['selectable'])
        && empty($byHolder[$holderId]['disabled']), 'purchased card is recognized and available through the existing entitlement reader');
    $check(!empty($byHolder[(int)$delayedReceipt['card_holder_id']]['disabled'])
        && empty($byHolder[(int)$delayedReceipt['card_holder_id']]['selectable']),
        'inactive purchased card remains unavailable for write-off selection');

    $detailIds = json_decode($receipt['benefit_detail_ids_json'], true);
    foreach ([653, 653, 654] as $index => $amount) {
        $writeoff = ['memberId' => $member, 'occurredAt' => time(), 'businessDate' => date('Y-m-d'), 'lines' => [[
            'lineId' => 'use-' . $index, 'lineRole' => 'entitlement_service', 'quantity' => 1,
            'entitlementInstanceType' => 'card_holder', 'entitlementInstanceId' => $holderId,
            'entitlementSourceDetailId' => (int)$detailIds[0], 'projectId' => $project,
            'actualAmount' => (string)$amount, 'serviceObject' => '本人', 'friendCountsAsCustomer' => true,
            'name' => $tag, 'entitlementSourceName' => $tag,
            'fullCardNo' => (string)Db::name('user_card_holder')->where('id', $holderId)->value('card_no'),
            'craftsmen' => [['staffId' => $staffId, 'id' => $staffId, 'name' => $tag, 'isMain' => true]],
        ]]];
        $used = $submit(m010NewKey(), $writeoff);
        $check($used['status'] === 'success'
            && (int)Db::name('store_order_cart_info')->where('id', $detailIds[0])->value('write_surplus_times') === 2 - $index,
            'real write-off consumes one use and preserves whole-yuan tail ' . $index);
    }
    $check((int)Db::name('cashier_v3_entitlement_writeoff_fact')->where('holder_id', $holderId)
        ->sum('actual_entitlement_amount_cents') === 196000,
        'three integer-yuan write-off facts conserve the 1960-yuan purchase');
    $inactiveUse = $writeoff;
    $inactiveUse['lines'][0]['entitlementInstanceId'] = (int)$delayedReceipt['card_holder_id'];
    $inactiveDetails = json_decode($delayedReceipt['benefit_detail_ids_json'], true);
    $inactiveUse['lines'][0]['entitlementSourceDetailId'] = (int)$inactiveDetails[0];
    $inactiveUse['lines'][0]['fullCardNo'] = (string)Db::name('user_card_holder')
        ->where('id', $delayedReceipt['card_holder_id'])->value('card_no');
    $inactiveUse['lines'][0]['actualAmount'] = '653';
    $inactiveDenied = false;
    try { $submit(m010NewKey(), $inactiveUse); } catch (Throwable $e) {
        $inactiveDenied = $e->getMessage() === 'authority_card_operation_state_not_active';
    }
    $check($inactiveDenied && (int)Db::name('store_order_cart_info')->where('id', $inactiveDetails[0])->value('write_surplus_times') === 3,
        'formal write-off still rejects an inactive card without consuming uses');
    $voidKey = 'M010-VOID-' . substr(m010NewKey(), 9);
    $recorder = new CashierV3BusinessEventRecorder();
    $voidScope = ['operator_scope' => $operator, 'data_scope' => $scope,
        'idempotency_key' => $voidKey,
        'payload' => ['orderId' => $delayedReceipt['sales_order_id'], 'reason' => 'M010 synthetic rollback regression'],
        'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution('void-sales-order', $voidKey, $operator, $scope, $tag),
        'event_contract' => CashierV3ActionManifest::eventContractFor('void-sales-order')];
    $voided = (new \app\services\cashier\v3\order\CashierV3OrderLifecycleServices())->executeInTx('void-sales-order', $voidScope);
    $check($voided['status'] === 'succeeded'
        && (int)Db::name('user_card_holder')->where('id', $delayedReceipt['card_holder_id'])->value('is_del') === 1
        && (int)Db::name('cashier_v3_payment_fact')->where('order_id', $delayedReceipt['sales_order_id'])->sum('amount_cents') === 0,
        'existing full-order void revokes custom-card rights and balances the payment facts');

    // A normal configured card must still use its own catalog identity and
    // the ordinary issuer path, not the internal custom-card carrier.
    $normalProduct = (int)Db::name('store_product')->insertGetId(['type' => 1, 'relation_id' => $createdStore,
        'product_type' => 5, 'store_name' => $tag . '-normal', 'cate_id' => (string)$category,
        'is_del' => 0, 'is_verify' => 1, 'is_show' => 1]);
    $normalSku = (int)Db::name('store_product_attr_value')->insertGetId(['product_id' => $normalProduct,
        'product_type' => 5, 'type' => 0, 'is_show' => 1, 'unique' => substr(md5($tag . '-normal'), 0, 8),
        'suk' => '默认', 'price' => '1000.00', 'ot_price' => '1000.00', 'cost' => '0.00', 'write_valid' => 1]);
    Db::name('store_card_related')->insert(['card_product_id' => $normalProduct, 'product_id' => $project,
        'product_type' => 6, 'product_attr_unique' => substr(md5($tag), 0, 8),
        'price' => '500.00', 'cost' => '10.00', 'write_times' => 2, 'status' => 1]);
    $normalItems = $catalog->catalog($operator, $scope)['items'];
    $normalItem = array_values(array_filter($normalItems, static function (array $item) use ($normalSku): bool {
        return (int)$item['id'] === $normalSku;
    }))[0];
    $normalSnapshot = ['memberId' => $member, 'occurredAt' => time(), 'businessDate' => date('Y-m-d'), 'lines' => [[
        'lineId' => 'ordinary-card', 'lineRole' => 'sale', 'quantity' => 1, 'itemId' => $normalSku,
        'kindCode' => 'card_package', 'amount' => '1000', 'originalAmount' => '1000',
        'cardPurchaseSnapshot' => $normalItem['cardPurchaseSnapshot'],
    ], ['lineId' => 'ordinary-payment', 'lineRole' => 'payment', 'quantity' => 1, 'method' => 'cash', 'amount' => '1000']]];
    $normalKey = m010NewKey(); $submit($normalKey, $normalSnapshot);
    $normalReceipt = (array)Db::name('cashier_v3_card_purchase_receipt')->where('command_idempotency_key', $normalKey)->find();
    $check((int)$normalReceipt['catalog_product_id'] === $normalProduct
        && Db::name(CashierV3CustomCardConfigurationServices::TABLE)->where('created_command_idempotency_key', $normalKey . ':snapshot:0')->count() === 0,
        'ordinary card checkout retains its catalog identity without a custom-card configuration');

    foreach ([['amount' => '1960.01'], ['times' => 0], ['amount' => '1']] as $index => $badComponent) {
        $bad = $payload; $bad['components'][0] = array_replace($bad['components'][0], $badComponent);
        $denied = false;
        try { $configuration->createSaleLineAfterGatewayLocksInTx($bad, $tag, $tag, $tag . '-bad-' . $index,
            [], $operator, $scope, $member, true, 'bad-line'); } catch (CashierV3CommandException $e) { $denied = true; }
        $check($denied, 'fractional amount zero times or below-cost amount rejected ' . $index);
    }
    $denied = false;
    try { $configuration->createSaleLineAfterGatewayLocksInTx($payload, $tag, $tag, $tag . '-guest',
        [], $operator, $scope, 0, true, 'guest-line'); } catch (CashierV3CommandException $e) { $denied = true; }
    $check($denied, 'guest checkout cannot create a custom card');
    $denied = false;
    try { $catalog->ensureCustomCardTemplateInTx(new CashierV3OperatorScope($createdStore + 100000, $staffId,
        (string)$organization, '0'), $scope); } catch (CashierV3CommandException $e) { $denied = true; }
    $check($denied, 'forged operating store cannot reuse another store template');
    Db::execute('SAVEPOINT m010_disabled');
    Db::name('store_product_attr_value')->where('id', $identity['skuId'])->update(['is_show' => 0]);
    $denied = false;
    try { $template->ensureInTx($createdStore); } catch (CashierV3CommandException $e) { $denied = true; }
    Db::execute('ROLLBACK TO SAVEPOINT m010_disabled');
    $check($denied, 'disabled internal SKU is not silently replaced');
    echo json_encode(['checks' => $checks, 'source' => 'production submit handler'], JSON_UNESCAPED_UNICODE) . "\n";
} finally { Db::rollback(); }
$remaining = [];
foreach (['cashier_v3_custom_card_configuration', 'cashier_v3_command_receipt', 'cashier_v3_checkout_request',
    'cashier_v3_sales_order', 'cashier_v3_sales_order_line', 'cashier_v3_payment_fact',
    'cashier_v3_entitlement_writeoff_fact', 'user_card_holder', 'store_order', 'system_store_staff'] as $table) {
    if (Db::name($table)->where('store_id', $createdStore)->count() !== 0) $remaining[] = $table;
}
$check(!$remaining && Db::name('system_store')->where('id', $createdStore)->count() === 0
    && Db::name('user')->where('uid', $member)->count() === 0
    && Db::name('store_product')->where('type', 1)->where('relation_id', $createdStore)->count() === 0
    && Db::name('store_product_attr_value')->whereIn('product_id', [$project, $identity['productId'], $normalProduct])->count() === 0,
    'all synthetic store member carrier request order payment and entitlement rows roll back');
echo 'M010 PASS ' . count($checks) . "\n";
