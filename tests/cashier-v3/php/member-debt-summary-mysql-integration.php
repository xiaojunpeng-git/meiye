<?php
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\cashier\CashierV3CashierMemberSummaryServices;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use think\facade\Config;
use think\facade\Db;

$backend = '/var/www/html';
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) {
    $app->env->set($key, $value);
}
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'member_debt_summary_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$failed = 0;
$check = static function (string $name, bool $condition) use (&$failed): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
};

$memberId = 913087;
$projection = new CashierV3MemberDebtProjectionServices();
$check('V3 authority-aware amount is 310.00 without a store boundary', $projection->amountForMember($memberId) === '310.00');

$summary = (new CashierV3CashierMemberSummaryServices())->read($memberId, 118);
$check('cashier member summary uses the same 310.00 amount', (string)($summary['outstandingDebtAmount'] ?? '') === '310.00');

$expected = (string)Db::query(
    'SELECT COALESCE(SUM(d.total_debt-d.repaid_debt),0) amount '
    . 'FROM eb_store_debt d '
    . 'JOIN eb_cashier_v3_debt_authority a ON a.debt_id=d.id '
    . 'JOIN eb_cashier_v3_sales_order s ON s.order_id=a.sales_order_id '
    . 'WHERE d.uid=? AND d.status=0 AND d.total_debt>d.repaid_debt '
    . 'AND s.member_id=?',
    [$memberId, $memberId]
)[0]['amount'];
$check('summary matches V3 sales debt authority total', bccomp((string)$summary['outstandingDebtAmount'], $expected, 2) === 0);

echo "MEMBER_DEBT_SUMMARY_MYSQL failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
