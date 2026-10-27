<?php
declare(strict_types=1);

// Read-only local integration: actual position grants must survive the production
// root operation catalog. No receipt, balance, permission or customer is written.
require '/var/www/html/vendor/autoload.php';
(new \think\App('/var/www/html/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . PHP_EOL); exit(1);
});
use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\permission\CashierV3StaffFeatureOverrideServices;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;

$config = Db::connect()->getConfig();
if (getenv('R53_LOCAL_ONLY') !== '1' || $config['hostname'] !== 'mysql' || $config['database'] !== 'ruihao') {
    throw new RuntimeException('Local read-only test only');
}
$feature = 'cashier.v3.cashier.card.to_balance';
$profile = (array)Db::name('system_store_staff')->where('id', 497)->where('status', 1)->where('is_del', 0)->find();
if (!$profile) throw new RuntimeException('Local test staff unavailable');
$features = app()->make(CashierV3FeatureResolver::class)->resolveGrantedFeatures($profile);
if (!in_array($feature, $features, true)) throw new RuntimeException('Actual position grant missing');
if (count(array_keys(CashierV3StaffFeatureOverrideServices::operationFeatureCodes(), $feature, true)) !== 1) {
    throw new RuntimeException('Root operation catalog registration missing/duplicated');
}
$storeId = (int)$profile['store_id'];
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$makeScope = static function (array $staff, bool $readOnly = false) use ($features, $storeId, $organizationId): CashierV3DataScopeContext {
    return new CashierV3DataScopeContext(497, (int)$staff['employee_id'], $storeId, '0', $organizationId,
        [$storeId], 'stores', [], false, '', 'r53-entry', $features, $staff, $readOnly);
};
$class = new ReflectionClass(CashierV3RootDomainAssembler::class);
$method = $class->getMethod('resolveOperationFeatures');
$method->setAccessible(true);
$assembler = $class->newInstanceWithoutConstructor();
if (!in_array($feature, $method->invoke($assembler, $makeScope($profile)), true)) {
    throw new RuntimeException('Actual root projection drops authorized entry');
}
if ($method->invoke($assembler, $makeScope($profile, true)) !== []) {
    throw new RuntimeException('Read-only operation permission leak');
}
$invalidProfile = array_replace($profile, ['id' => 0, 'employee_id' => 0]);
if (in_array($feature, $method->invoke($assembler, $makeScope($invalidProfile)), true)) {
    throw new RuntimeException('Projection trusts caller grants instead of current position');
}
echo "R53_CARD_TO_BALANCE_ENTRY_OK checks=5 actualPositionGrantAndRootProjection=PASS writes=0\n";
