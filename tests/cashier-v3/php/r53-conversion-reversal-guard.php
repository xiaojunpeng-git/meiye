<?php
declare(strict_types=1);

// Actual query execution in SQLite memory isolates ID collisions and reversal
// boundaries without touching either the local business DB or any customer.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
if (!class_exists(\Swoole\Table::class)) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING=7; public const TYPE_INT=1; }');
}
$root = dirname(__DIR__, 3) . '/后端代码';
require $root . '/vendor/autoload.php';
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\card\CashierV3CardConversionGuard as Guard;
use think\facade\Db;
$app = new \think\App($root . '/');
$manager = new \think\DbManager();
$manager->setConfig(['default' => 'r53', 'connections' => ['r53' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_', 'fields_cache' => false]]]);
\think\Container::getInstance()->instance(\think\DbManager::class, $manager);
Db::execute('CREATE TABLE eb_cashier_v3_card_operation (tenant_id TEXT,source_card_holder_id INTEGER,origin_order_id INTEGER,operation_type TEXT,operation_status TEXT)');
Db::execute('CREATE TABLE eb_cashier_v3_debt_authority (tenant_id TEXT,debt_id INTEGER,sales_order_id TEXT,sales_order_record_id INTEGER)');
Db::execute('CREATE TABLE eb_cashier_v3_card_purchase_receipt (tenant_id TEXT,sales_order_id TEXT,sales_order_line_id TEXT,legacy_order_id INTEGER,status TEXT)');
Db::execute('CREATE TABLE eb_cashier_v3_sales_order_line (tenant_id TEXT,order_line_id TEXT,debt_amount_cents INTEGER)');
Db::execute('CREATE TABLE eb_cashier_v3_debt_repayment_item_settlement (tenant_id TEXT,repayment_id TEXT,order_line_id TEXT,amount_cents INTEGER)');
$checks = 0;
$reject = static function (callable $call) use (&$checks): void {
    try { $call(); throw new RuntimeException('converted reversal accepted'); }
    catch (CashierV3CommandException $expected) { $checks++; }
};
$allow = static function (callable $call) use (&$checks): void { $call(); $checks++; };
$reject(static fn() => Guard::assertHolderNotConverted(1, 'T'));
Db::startTrans();
try {
    Db::name('cashier_v3_card_operation')->insert(['tenant_id' => 'T', 'source_card_holder_id' => 1,
        'origin_order_id' => 20, 'operation_type' => 'card_to_balance', 'operation_status' => 'completed']);
    $reject(static fn() => Guard::assertHolderNotConverted(1, 'T'));
    $allow(static fn() => Guard::assertHolderNotConverted(1, 'OTHER'));
    $allow(static fn() => Guard::assertHolderNotConverted(2, 'T'));
    $reject(static fn() => Guard::assertDebtNotConverted(['id' => 1, 'order_id' => 20], 'T'));
    Db::name('cashier_v3_debt_authority')->insert(['tenant_id' => 'T', 'debt_id' => 2,
        'sales_order_id' => 'V3', 'sales_order_record_id' => 20]);
    // The numeric native-sale record ID matches a legacy card order by coincidence.
    $allow(static fn() => Guard::assertDebtNotConverted(['id' => 2, 'order_id' => 20], 'T'));
    Db::name('cashier_v3_card_purchase_receipt')->insert(['tenant_id' => 'T', 'sales_order_id' => 'V3',
        'sales_order_line_id' => 'L', 'legacy_order_id' => 20, 'status' => 'completed']);
    Db::name('cashier_v3_sales_order_line')->insert(['tenant_id' => 'T', 'order_line_id' => 'L', 'debt_amount_cents' => 0]);
    $allow(static fn() => Guard::assertDebtNotConverted(['id' => 2, 'order_id' => 20], 'T'));
    Db::name('cashier_v3_sales_order_line')->where('order_line_id', 'L')->update(['debt_amount_cents' => 10000]);
    $reject(static fn() => Guard::assertDebtNotConverted(['id' => 2, 'order_id' => 20], 'T'));
    Db::name('cashier_v3_debt_repayment_item_settlement')->insert(['tenant_id' => 'T',
        'repayment_id' => 'RP', 'order_line_id' => 'L', 'amount_cents' => 0]);
    Db::name('cashier_v3_debt_repayment_item_settlement')->insert(['tenant_id' => 'T',
        'repayment_id' => 'RP', 'order_line_id' => 'OTHER-LINE', 'amount_cents' => 10000]);
    $allow(static fn() => Guard::assertDebtNotConverted(['id' => 2, 'order_id' => 20], 'T', 'RP'));
    Db::name('cashier_v3_debt_repayment_item_settlement')->where('order_line_id', 'L')->update(['amount_cents' => 100]);
    $reject(static fn() => Guard::assertDebtNotConverted(['id' => 2, 'order_id' => 20], 'T', 'RP'));
    Db::name('cashier_v3_card_operation')->where('source_card_holder_id', 1)->update(['operation_status' => 'failed']);
    $allow(static fn() => Guard::assertHolderNotConverted(1, 'T'));
    $allow(static fn() => Guard::assertDebtNotConverted(['id' => 1, 'order_id' => 20], 'T'));
} finally { Db::rollback(); }
echo "PASS R53 conversion reversal guards {$checks} checks\n";
