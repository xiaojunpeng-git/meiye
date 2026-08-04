<?php
declare(strict_types=1);

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use think\facade\Config;
use think\facade\Db;

function checkoutFactMysqlBoot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $backend = getenv('CHECKOUT_FACT_BACKEND_ROOT') ?: '/workspace/后端代码';
    require_once $backend . '/vendor/autoload.php';
    $app = new \think\App($backend . '/');
    foreach ([
        'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
        'database.type' => 'mysql', 'DATABASE_TYPE' => 'mysql',
        'database.hostname' => getenv('DB_HOST') ?: '127.0.0.1',
        'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: '127.0.0.1',
        'database.hostport' => getenv('DB_PORT') ?: '3306',
        'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
        'database.database' => getenv('DB_DATABASE') ?: 'checkout_facts',
        'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'checkout_facts',
        'database.username' => getenv('DB_USERNAME') ?: 'root',
        'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
        'database.password' => getenv('DB_PASSWORD') ?: '',
        'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: '',
    ] as $key => $value) {
        $app->env->set($key, $value);
    }
    $envName = new ReflectionProperty($app, 'envName');
    $envName->setAccessible(true);
    $envName->setValue($app, 'checkout_fact_test_skip_dotenv_reload');
    $app->initialize();
    Config::set(['default' => 'file'], 'cache');
    Db::connect('mysql', true)->query('SELECT 1');
    $booted = true;
}

function checkoutFactOperator(int $storeId = 7): CashierV3OperatorScope
{
    return new CashierV3OperatorScope($storeId, 21, 'ORG-3', 'TENANT-1');
}

function checkoutFactScope(int $storeId = 7, string $mode = CashierV3DataScopeContext::MODE_STORES): CashierV3DataScopeContext
{
    $visible = $mode === CashierV3DataScopeContext::MODE_ALL ? null : [$storeId];
    if ($mode === CashierV3DataScopeContext::MODE_NONE
        || $mode === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT) {
        $visible = [];
    }
    return new CashierV3DataScopeContext(
        21, 701, $storeId, 'TENANT-1', 'ORG-3', $visible, $mode, [],
        $mode === CashierV3DataScopeContext::MODE_ALL, '', 'fact-test-v1', [], []
    );
}

function checkoutFactReset(): void
{
    foreach (['performance_fact','balance_fact','payment_fact','sale_fact'] as $suffix) {
        Db::execute('DELETE FROM `eb_cashier_v3_' . $suffix . '`');
    }
    Db::execute('DELETE FROM `eb_cashier_v3_business_event`');
}

function checkoutFactInsertEvent(array $input): void
{
    $context = $input['context'];
    Db::name('cashier_v3_business_event')->insert([
        'event_no' => $context['businessEventNo'],
        'event_type' => 'checkout.completed',
        'aggregate_type' => 'sales_order',
        'aggregate_id' => $context['orderId'],
        'source_type' => 'submit-checkout',
        'source_id' => $context['checkoutRequestId'],
        'command_idempotency_key' => $input['commandIdempotencyKey'],
        'tenant_id' => $context['tenantId'],
        'organization_id' => $context['organizationId'],
        'store_id' => $context['storeId'],
        'member_id' => $context['memberId'],
        'operator_id' => $context['operatorId'],
        'business_date' => $context['businessDate'],
        'occurred_at' => $context['occurredAt'],
        'settled_at' => $context['settledAt'],
        'recorded_at' => $context['recordedAt'],
    ]);
}
