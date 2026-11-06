<?php
declare(strict_types=1);

/** Pure policy regression: no configuration writes, database or HTTP calls. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/app/services/cashier/v3/CashierV3ResultCode.php';
require $backend . '/app/services/cashier/v3/CashierV3CommandException.php';
require $backend . '/app/services/cashier/v3/settlement/CashierV3ProjectPurchasePolicy.php';
use app\services\cashier\v3\settlement\CashierV3ProjectPurchasePolicy as Policy;
use app\services\cashier\v3\CashierV3CommandException;
$value = null; $count = 0;
function sys_config(string $key, $default = '') { global $value; return $value === null ? $default : $value; }
$check = static function (bool $ok, string $name) use (&$count): void {
    if (!$ok) throw new RuntimeException($name); ++$count;
};
$denied = static function (callable $call, string $code) use ($check): void {
    try { $call(); } catch (CashierV3CommandException $e) { $check($e->getResultCode() === $code, $code); return; }
    throw new RuntimeException('Expected rejection ' . $code);
};
$check(Policy::enabled() && Policy::resolve(false, []), 'missing config and old clients preserve default on');
$check(!Policy::resolve(true, []), 'member purchase always defers');
$value = '0';
$check(!Policy::enabled() && !Policy::resolve(false, ['projectAutoComplete' => false]), 'explicit off');
$denied(static function (): void { Policy::resolve(false, []); }, 'CASHIER_PROJECT_MODE_CHANGED');
$denied(static function (): void { Policy::resolve(false, ['projectAutoComplete' => true]); }, 'CASHIER_PROJECT_MODE_CHANGED');
$denied(static function (): void { Policy::assertMember(0); }, 'CASHIER_PROJECT_MEMBER_REQUIRED');
$check(Policy::deferred(['project_auto_complete' => 0]), 'saved off remains deferred');
$check(!Policy::deferred([]), 'old store orders remain immediate despite live switch off');
$check(Policy::deferred(['source_document_type' => 'member_checkout']), 'old member orders remain deferred');
$value = '1';
$check(Policy::enabled() && Policy::deferred(['project_auto_complete' => 0]), 're-enabling never rewrites saved fulfillment');
$denied(static function (): void { Policy::resolve(false, ['projectAutoComplete' => false]); }, 'CASHIER_PROJECT_MODE_CHANGED');
echo 'R47_PROJECT_POLICY passed=' . $count, "\n";
