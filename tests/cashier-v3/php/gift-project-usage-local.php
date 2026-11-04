<?php
declare(strict_types=1);
/** Disposable local MySQL verification of gift-project identity, net writeoffs, legacy gaps and store scope. */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/'); $app->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\order\CashierV3GiftProjectUsageQueryServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;

$config = config('database'); $default = $config['default']; $connection = $config['connections'][$default];
if (!in_array($connection['hostname'], ['mysql', 'mohe-mysql'], true)) throw new RuntimeException('LOCAL_ONLY');
$source = $connection['database']; $original = Db::connect(); $db = 'gift_project_' . bin2hex(random_bytes(5)); $created = false; $checks = 0;
function gpCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; }
/** Synthetic rows fill schema requirements only; no customer data or production write path is used. */
function gpInsert(string $table, array $row): void {
    static $counter = 0; ++$counter; $token = 'PROJECT-' . $table . '-' . $counter;
    foreach (Db::query('SHOW COLUMNS FROM eb_' . $table) as $column) {
        $name = $column['Field'];
        if (array_key_exists($name, $row) || $column['Null'] === 'YES' || $column['Default'] !== null || strpos($column['Extra'], 'auto_increment') !== false) continue;
        $row[$name] = preg_match('/int|decimal|float|double/', $column['Type']) ? 0
            : (preg_match('/date|time/', $column['Type']) ? '2026-11-04' : substr(hash('sha256', $token . $name), 0, 32));
    }
    Db::name($table)->insert($row);
}
function gpScope(array $stores = [901], string $tenant = '0', bool $platform = false, bool $feature = true): CashierV3DataScopeContext {
    return new CashierV3DataScopeContext(1, $platform ? 0 : 1, 901, $tenant, '1', $stores,
        CashierV3DataScopeContext::MODE_STORES, [], false, '', 'project-test', $feature ? ['cashier.v3.order_center'] : [], []);
}
function gpDenied(callable $call, string $label): void {
    try { $call(); } catch (CashierV3CommandException $e) { gpCheck(true, $label); return; }
    throw new RuntimeException('expected denial: ' . $label);
}
function gpGift(string $fact, string $kind, int $orderId, int $detailId, int $holderId, int $times, int $remaining): void {
    $base = $kind === 'direct_gift' ? 'cashier_v3_direct_gift' : 'cashier_v3_recharge_gift';
    $giftId = 'G-' . $fact; $itemId = 'I-' . $fact;
    gpInsert($base . '_authority', ['tenant_id' => '0', 'gift_id' => $giftId, 'gift_no' => 'ZS-' . $fact,
        'store_id' => 901, 'member_id' => 9001, 'status' => 'issued']);
    gpInsert($base . '_item', ['gift_id' => $giftId, 'item_id' => $itemId, 'item_no' => 1, 'gift_kind' => 'project',
        'quantity' => 1, 'legacy_order_id' => $orderId, 'card_holder_id' => $holderId, 'benefit_detail_id' => $detailId,
        'content_snapshot_json' => '{}', 'coupon_user_ids_json' => '[]', 'status' => 'issued']);
    gpInsert('cashier_v3_gift_fact', ['gift_fact_id' => $fact, 'tenant_id' => '0', 'store_id' => 901, 'member_id' => 9001,
        'operator_id' => 1, 'source_type' => $kind, 'source_id' => $giftId, 'source_detail_id' => $itemId,
        'gift_kind' => 'project', 'quantity' => 1, 'content_name_snapshot' => 'Synthetic project',
        'content_snapshot_json' => '{}', 'status' => 'effective', 'settled_at' => time()]);
    gpInsert('store_order', ['id' => $orderId, 'uid' => 9001, 'store_id' => 901, 'order_id' => 'LEG-' . $fact, 'unique' => 'project-' . $fact,
        'channel_type' => 'cashier_v3_' . $kind, 'paid' => 1, 'is_system_del' => 0]);
    gpInsert('store_order_cart_info', ['id' => $detailId, 'oid' => $orderId, 'uid' => 9001, 'relation_id' => 901,
        'source_type' => 'cashier_v3_' . $kind, 'is_gift' => 1, 'product_type' => 6,
        'write_times' => $times, 'write_surplus_times' => $remaining, 'cart_num' => 1,
        'cart_info' => '{"productInfo":{"store_name":"Synthetic project"}}']);
    gpInsert('user_card_holder', ['id' => $holderId, 'oid' => $orderId, 'uid' => 9001, 'store_id' => 901,
        'write_times' => $times, 'write_surplus_times' => $remaining]);
}
try {
    $original->execute('CREATE DATABASE `' . $db . '`'); $created = true;
    foreach ($original->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=\'BASE TABLE\'', [$source]) as $row) {
        $table = $row['TABLE_NAME']; if (!preg_match('/^eb_[A-Za-z0-9_]+$/D', $table)) continue;
        $original->execute('CREATE TABLE `' . $db . '`.`' . $table . '` LIKE `' . $source . '`.`' . $table . '`');
    }
    $config['connections'][$db] = array_replace($connection, ['database' => $db]); $config['default'] = $db; config($config, 'database');
    gpCheck(Db::query('SELECT DATABASE() db')[0]['db'] === $db, 'isolated database');
    gpInsert('user', ['uid' => 9001, 'account' => 'synthetic-project', 'real_name' => 'Synthetic', 'nickname' => 'Synthetic', 'status' => 1, 'is_del' => 0]);
    gpInsert('system_store', ['id' => 901, 'name' => 'Synthetic store']);
    gpGift('D1', 'direct_gift', 7001, 5001, 6001, 3, 2);
    gpGift('R1', 'recharge_gift', 7002, 5002, 6002, 4, 4);
    gpGift('D2', 'direct_gift', 7004, 5004, 6004, 2, 2);
    Db::name('cashier_v3_direct_gift_item')->where('item_id', 'I-D2')->update(['status' => 'voided']);
    Db::name('cashier_v3_direct_gift_authority')->where('gift_id', 'G-D2')->update(['status' => 'voided']);
    gpInsert('cashier_v3_entitlement_writeoff_fact', ['writeoff_id' => 'W-1', 'natural_key' => 'N-1', 'checkout_request_id' => 'C-1', 'source_line_id' => 'L-1', 'tenant_id' => '0', 'member_id' => 9001,
        'store_id' => 901, 'holder_id' => 6001, 'source_detail_id' => 5001, 'origin_order_id' => 7001,
        'quantity' => 1, 'status' => 'effective', 'document_no_snapshot' => 'FW-ONE', 'settled_at' => time() - 100]);
    gpInsert('cashier_v3_entitlement_writeoff_fact', ['writeoff_id' => 'W-2', 'natural_key' => 'N-2', 'checkout_request_id' => 'C-2', 'source_line_id' => 'L-2', 'tenant_id' => '0', 'member_id' => 9001,
        'store_id' => 901, 'holder_id' => 6001, 'source_detail_id' => 5001, 'origin_order_id' => 7001,
        'quantity' => 1, 'status' => 'effective', 'document_no_snapshot' => 'FW-VOID', 'settled_at' => time() - 50]);
    gpInsert('cashier_v3_entitlement_reversal_fact', ['reversal_fact_id' => 'ER-2', 'tenant_id' => '0', 'member_id' => 9001,
        'store_id' => 901, 'holder_id' => 6001, 'source_detail_id' => 5001, 'original_writeoff_id' => 'W-2',
        'quantity' => 1, 'service_fact_id' => 2, 'operation_id' => 'OP-2', 'command_idempotency_key' => 'KEY-2']);
    gpInsert('store_order', ['id' => 7003, 'uid' => 9001, 'store_id' => 901, 'order_id' => 'LEGACY', 'unique' => 'project-legacy', 'paid' => 1, 'is_system_del' => 0]);
    gpInsert('store_order_cart_info', ['id' => 5003, 'oid' => 7003, 'uid' => 9001, 'relation_id' => 901,
        'is_gift' => 1, 'product_type' => 6, 'write_times' => 5, 'write_surplus_times' => 3, 'cart_num' => 1,
        'cart_info' => '{"productInfo":{"store_name":"Legacy project"}}']);
    gpInsert('store_order', ['id' => 7005, 'uid' => 9001, 'store_id' => 901, 'order_id' => 'LEGACY-VOID', 'unique' => 'project-legacy-void', 'paid' => 1, 'is_system_del' => 0, 'terminal_action' => 3]);
    gpInsert('store_order_cart_info', ['id' => 5005, 'oid' => 7005, 'uid' => 9001, 'relation_id' => 901,
        'is_gift' => 1, 'product_type' => 6, 'write_times' => 5, 'write_surplus_times' => 0, 'cart_num' => 1,
        'cart_info' => '{"productInfo":{"store_name":"Voided legacy project"}}']);
    $service = new CashierV3GiftProjectUsageQueryServices(); $operator = new CashierV3OperatorScope(901, 1, '1', '0');
    Db::execute('SET TRANSACTION READ ONLY'); Db::startTrans();
    try {
        $direct = $service->query(['recordId' => 'v3-direct-gift:D1'], $operator, gpScope());
        $platform = $service->query(['recordId' => 'v3-direct-gift:D1'], $operator, gpScope([901], '0', true));
        unset($direct['dataAsOf'], $platform['dataAsOf']); gpCheck($direct === $platform, 'platform and store same reader');
        gpCheck($direct['issuedQuantity'] === 3 && $direct['usedQuantity'] === 1 && $direct['remainingQuantity'] === 2, 'SKU times rather than gift item count');
        gpCheck($direct['total'] === 2 && $direct['records'][0]['usageStatus'] === '已作废' && $direct['records'][1]['serviceRecordNo'] === 'FW-ONE', 'writeoff and reversal stay traceable');
        gpCheck($direct['unrecordedQuantity'] === 0, 'no fabricated historical gap');
        $recharge = $service->query(['recordId' => 'v3-gift:R1'], $operator, gpScope());
        gpCheck($recharge['issuedQuantity'] === 4 && $recharge['usedQuantity'] === 0 && $recharge['records'] === [], 'recharge gift exact project projection');
        $legacy = $service->query(['recordId' => 'gift:5003'], $operator, gpScope());
        gpCheck($legacy['usedQuantity'] === 2 && $legacy['unrecordedQuantity'] === 2 && $legacy['records'] === [], 'legacy usage gap shown without invented order');
        $voided = $service->query(['recordId' => 'v3-direct-gift:D2'], $operator, gpScope());
        gpCheck($voided['usedQuantity'] === 0 && $voided['remainingQuantity'] === 0 && $voided['availabilityStatus'] === '已作废', 'voided V3 gift does not count revoked rights as use');
        $oldVoid = $service->query(['recordId' => 'gift:5005'], $operator, gpScope());
        gpCheck($oldVoid['usedQuantity'] === null && $oldVoid['usageKnown'] === false, 'voided legacy gift preserves unknown use count');
        gpDenied(static function () use ($service, $operator) { $service->query(['recordId' => 'v3-direct-gift:D1'], $operator, gpScope([902])); }, 'store denied');
        gpDenied(static function () use ($service, $operator) { $service->query(['recordId' => 'v3-direct-gift:D1', 'storeIds' => [902]], $operator, gpScope()); }, 'client filter narrows');
        gpDenied(static function () use ($service, $operator) { $service->query(['recordId' => 'v3-direct-gift:D1'], $operator, gpScope([901], 'other')); }, 'tenant denied');
        gpDenied(static function () use ($service, $operator) { $service->query(['recordId' => 'v3-gift:D1'], $operator, gpScope()); }, 'source kind denied');
        gpDenied(static function () use ($service, $operator) { $service->query(['recordId' => 'v3-direct-gift:D1'], $operator, gpScope([901], '0', false, false)); }, 'feature denied');
        $page = (new CashierV3OrderCenterRecordQueryServices())->queryRecords(['recordType' => 'gift'], $operator, gpScope());
        $row = current(array_filter($page['records'], static function ($item) { return $item['id'] === 'v3-direct-gift:D1'; }));
        gpCheck($row && $row['projectUsageRecordId'] === 'v3-direct-gift:D1' && !$row['couponUsageRecordId'], 'project button capability only on project row');
    } finally { Db::rollback(); }
    echo json_encode(['status' => 'PASS', 'checks' => $checks, 'productionWrites' => 0], JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    config(array_replace($config, ['default' => $default]), 'database');
    if ($created) $original->execute('DROP DATABASE `' . $db . '`');
}
