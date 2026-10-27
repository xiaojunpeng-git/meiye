<?php
declare(strict_types=1);

// Execute the actual Gateway replay-scope reader against memory-only receipts.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
if (!class_exists(\Swoole\Table::class)) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING=7; public const TYPE_INT=1; }');
}
$root = dirname(__DIR__, 3) . '/后端代码'; require $root . '/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3CommandGatewayServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
$app = new \think\App($root . '/'); $manager = new \think\DbManager();
$manager->setConfig(['default' => 'r53', 'connections' => ['r53' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_', 'fields_cache' => false]]]);
\think\Container::getInstance()->instance(\think\DbManager::class, $manager);
Db::execute('CREATE TABLE eb_cashier_v3_card_operation (tenant_id TEXT,store_id INTEGER,operator_id INTEGER,
    command_idempotency_key TEXT,operation_type TEXT,operation_status TEXT,operation_no TEXT,
    source_card_holder_id INTEGER,member_id_after INTEGER,source_remaining_value_cents INTEGER)');
Db::name('cashier_v3_card_operation')->insert(['tenant_id' => 'T', 'store_id' => 3, 'operator_id' => 1,
    'command_idempotency_key' => 'K', 'operation_type' => 'card_to_balance', 'operation_status' => 'completed',
    'operation_no' => 'NO', 'source_card_holder_id' => 10, 'member_id_after' => 20, 'source_remaining_value_cents' => 50000]);
$gateway = (new ReflectionClass(CashierV3CommandGatewayServices::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($gateway, 'cardConversionReplayScopeFailure'); $method->setAccessible(true);
$operator = new CashierV3OperatorScope(3, 1, 'ORG', 'T');
$scope = static fn(array $features, bool $readOnly = false, array $stores = [3]) =>
    new CashierV3DataScopeContext(1, 2, 3, 'T', 'ORG', $stores, 'stores', [], false,
        '', 'test', $features, [], $readOnly);
$validScope = $scope(['cashier.v3.cashier.card.to_balance']);
$receipt = ['idempotency_key' => 'K', 'business_no' => 'NO'];
$payload = ['sourceCardHolderId' => 10, 'memberId' => 20, 'confirmedAmountCents' => 50000];
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException($name); $checks++;
};
$call = static fn(array $r, array $p, $s): string => $method->invoke($gateway, $r, $p, $operator, $s);
$check($call($receipt, $payload, $validScope) === '', 'valid closed-card receipt replays without a resource version');
$check($call($receipt, $payload, $scope([])) !== '', 'revoked grant denies historical data');
$check($call($receipt, $payload, $scope(['cashier.v3.cashier.card.to_balance'], true)) !== '', 'read-only session denies replay');
$check($call($receipt, $payload, $scope(['cashier.v3.cashier.card.to_balance'], false, [])) !== '', 'narrowed store denies replay');
foreach (['sourceCardHolderId' => 11, 'memberId' => 21, 'confirmedAmountCents' => 49900] as $key => $value) {
    $check($call($receipt, array_replace($payload, [$key => $value]), $validScope) !== '', 'wrong business identity denied: ' . $key);
}
foreach (['idempotency_key' => 'OTHER', 'business_no' => 'OTHER'] as $key => $value) {
    $check($call(array_replace($receipt, [$key => $value]), $payload, $validScope) !== '', 'wrong receipt denied: ' . $key);
}
foreach (['tenant_id' => 'OTHER', 'store_id' => 4, 'operator_id' => 2,
    'operation_type' => 'card_disable', 'operation_status' => 'failed'] as $key => $value) {
    Db::startTrans();
    try {
        Db::name('cashier_v3_card_operation')->where('command_idempotency_key', 'K')->update([$key => $value]);
        $check($call($receipt, $payload, $validScope) !== '', 'wrong persisted binding denied: ' . $key);
    } finally { Db::rollback(); }
}
echo "PASS R53 Gateway conversion replay scope {$checks} checks\n";
