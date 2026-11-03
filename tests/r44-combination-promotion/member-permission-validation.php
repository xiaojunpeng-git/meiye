<?php
/** R44 pure boundary regression. No framework boot, DB connection, or fixture reset. */
$root = dirname(__DIR__, 2);
require $root . '/后端代码/app/services/cashier/v3/CashierV3ResultCode.php';
require $root . '/后端代码/app/services/cashier/v3/CashierV3CommandException.php';
require $root . '/后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php';
use app\services\cashier\v3\member\CashierV3MemberModule;
use app\services\cashier\v3\CashierV3CommandException;
$method = new ReflectionMethod(CashierV3MemberModule::class, 'promotionPermissionUpdates');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$checks = 0;
function verifyPermission($condition, $label) { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
verifyPermission($method->invoke(null, ['name' => '老客户端']) === [], 'old clients preserve existing permission fields');
foreach ([0, 1, '0', '1'] as $value) {
    verifyPermission($method->invoke(null, ['spread_open' => $value, 'is_promoter' => $value]) === ['spread_open' => (int)$value, 'is_promoter' => (int)$value], 'only exact 0 and 1 accepted');
}
foreach (['spread_open', 'is_promoter'] as $field) {
    foreach ([null, true, false, 1.0, 0.0, -1, 2, '01', '1.0', '', [], ['1']] as $value) {
        try { $method->invoke(null, [$field => $value]); throw new RuntimeException('invalid permission accepted'); }
        catch (CashierV3CommandException $e) { verifyPermission(isset($e->getDetail()['fieldErrors'][$field]), 'field-specific validation preserved'); }
    }
}
verifyPermission($method->invoke(null, ['spread_open' => 0]) === ['spread_open' => 0], 'updating one field must not reset the other');
echo "PASS R44 member permission validation: {$checks} assertions\n";
