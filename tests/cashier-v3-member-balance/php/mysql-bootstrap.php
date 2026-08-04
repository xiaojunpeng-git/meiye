<?php
declare(strict_types=1);

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use think\facade\Config;
use think\facade\Db;

function mbaMysqlBoot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $backend = getenv('CHECKOUT_BALANCE_BACKEND_ROOT') ?: '/workspace/后端代码';
    require_once $backend . '/vendor/autoload.php';
    $app = new \think\App($backend . '/');
    foreach ([
        'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
        'database.type' => 'mysql', 'DATABASE_TYPE' => 'mysql',
        'database.hostname' => getenv('DB_HOST') ?: '127.0.0.1',
        'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: '127.0.0.1',
        'database.hostport' => getenv('DB_PORT') ?: '3306',
        'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
        'database.database' => getenv('DB_DATABASE') ?: 'checkout_balance',
        'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'checkout_balance',
        'database.username' => getenv('DB_USERNAME') ?: 'root',
        'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
        'database.password' => getenv('DB_PASSWORD') ?: '',
        'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: '',
        'database.prefix' => 'eb_', 'DATABASE_PREFIX' => 'eb_',
    ] as $key => $value) {
        $app->env->set($key, $value);
    }
    $envName = new ReflectionProperty($app, 'envName');
    $envName->setAccessible(true);
    $envName->setValue($app, 'checkout_balance_test_skip_dotenv_reload');
    $app->initialize();
    Config::set(['default' => 'file'], 'cache');
    Db::connect('mysql', true)->query('SELECT 1');
    $booted = true;
}

function mbaOperator(
    int $storeId = 7,
    int $operatorId = 21,
    string $tenantId = 'TENANT-1'
): CashierV3OperatorScope {
    return new CashierV3OperatorScope($storeId, $operatorId, 'ORG-3', $tenantId);
}

function mbaScope(
    int $storeId = 7,
    int $operatorId = 21,
    string $tenantId = 'TENANT-1',
    string $mode = CashierV3DataScopeContext::MODE_STORES
): CashierV3DataScopeContext {
    $visible = $mode === CashierV3DataScopeContext::MODE_ALL ? null : [$storeId];
    if ($mode === CashierV3DataScopeContext::MODE_NONE
        || $mode === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT) {
        $visible = [];
    }
    return new CashierV3DataScopeContext(
        $operatorId,
        701,
        $storeId,
        $tenantId,
        'ORG-3',
        $visible,
        $mode,
        ['mode' => $mode, 'store_ids' => $visible],
        $mode === CashierV3DataScopeContext::MODE_ALL,
        '',
        'balance-test-permission-v1',
        ['cashier.v3.cashier'],
        ['id' => $operatorId, 'employee_id' => 701]
    );
}

function mbaSeedMember(int $memberId, string $principal, string $gift, ?string $total = null): void
{
    $total = $total === null ? bcadd($principal, $gift, 2) : $total;
    Db::name('user_money')->where('uid', $memberId)->delete();
    Db::name('user')->where('uid', $memberId)->delete();
    Db::name('user')->insert([
        'uid' => $memberId,
        'now_money' => $total,
        'ben_money' => $principal,
        'give_money' => $gift,
        'balance_version' => 1,
        'status' => 1,
        'is_del' => 0,
        'delete_time' => null,
        'belong_store_id' => 7,
    ]);
}

function mbaMember(int $memberId): array
{
    $row = Db::name('user')->where('uid', $memberId)->find();
    if (is_object($row) && method_exists($row, 'toArray')) {
        $row = $row->toArray();
    }
    return is_array($row) ? $row : [];
}
