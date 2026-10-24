<?php
declare(strict_types=1);

// Execute real normalizer, permission policy and module context registration;
// no checkout, wallet mutation or synthetic production grant is performed.
$root = is_dir('/var/www/html/vendor') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
require $root . '/vendor/autoload.php';
use app\services\cashier\v3\CashierV3RequestNormalizer;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\card\CashierV3CardOperationModule;
use app\services\cashier\v3\permission\CashierV3PermissionPolicyRegistry;
use app\services\cashier\v3\registry\CashierV3ContextPolicyRegistry;

$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException($name);
    $checks++;
};
$payload = ['operationType' => 'card_to_balance', 'sourceCardHolderId' => 123,
    'memberId' => 456, 'confirmedAmountCents' => 223100, 'reason' => '会员申请转换'];
$normal = CashierV3RequestNormalizer::normalize('submit-card-operation', $payload)['normalized'];
$check($normal == $payload, 'one business intent is preserved without versions');
foreach (['confirmedAmountCents' => [0, -100, 101, 100.5, '1e3', true, [], null],
    'memberId' => [0, -1, true, [], '1.1'], 'reason' => ['']] as $field => $values) {
    foreach ($values as $value) {
        $bad = $payload; $bad[$field] = $value;
        try {
            CashierV3RequestNormalizer::normalize('submit-card-operation', $bad);
            throw new RuntimeException('accepted invalid ' . $field);
        } catch (CashierV3CommandException $expected) { $checks++; }
    }
}
foreach (['sourceCardHolderVersion', 'catalogVersion', 'amount', 'balanceKind', 'refundAmount'] as $field) {
    try {
        CashierV3RequestNormalizer::normalize('submit-card-operation', $payload + [$field => 1]);
        throw new RuntimeException('accepted unrelated override ' . $field);
    } catch (CashierV3CommandException $expected) { $checks++; }
}
$permissions = new CashierV3PermissionPolicyRegistry();
$scope = static function (array $features): CashierV3DataScopeContext {
    return new CashierV3DataScopeContext(1, 2, 3, 'test', '0', [3], 'stores', [], false,
        '', 'test', $features, []);
};
foreach ([[], ['cashier.v3.cashier'], ['cashier.v3.cashier.card.disable'],
    ['cashier.v3.order.refund'], ['cashier.v3.cashier.gift']] as $features) {
    try {
        $permissions->assertAllowed('policy:cashier_card_operation', $scope($features), $normal, 'submit-card-operation');
        throw new RuntimeException('implicit conversion grant');
    } catch (CashierV3CommandException $expected) { $checks++; }
}
$permissions->assertAllowed('policy:cashier_card_operation',
    $scope(['cashier.v3.cashier.card.to_balance']), $normal, 'submit-card-operation');
$checks++;
$reflection = new ReflectionClass(CashierV3ActionDispatcher::class);
$dispatcher = $reflection->newInstanceWithoutConstructor();
$property = $reflection->getProperty('policies'); $property->setAccessible(true);
$registry = new CashierV3ContextPolicyRegistry(); $property->setValue($dispatcher, $registry);
$register = new ReflectionMethod(CashierV3CardOperationModule::class, 'registerPolicy');
$register->setAccessible(true); $register->invoke(null, $dispatcher);
$policy = $registry->requirePolicy('submit-card-operation')->resolve($normal);
$check($policy['allows_empty_contexts'] && $policy['identities'] === [], 'no browser versions');
$check($policy['allowed'] === [], 'client cannot attach a second resource snapshot');
$check($policy['required_touched_roles'] === [] && $policy['allows_empty_touched_result'], 'domain writes own mutation locks');
$check($policy['expand_from_server_resource_discovery'] === false, 'no competing resource snapshot');
$legacy = $registry->requirePolicy('submit-card-operation')->resolve([
    'operationType' => 'card_disable', 'sourceCardHolderId' => 123, 'reason' => 'test']);
$check($legacy['identities'][0]['kind'] === 'card_holder', 'old operation contract unchanged');
echo "PASS R53 normalizer/permission/context {$checks} checks\n";
