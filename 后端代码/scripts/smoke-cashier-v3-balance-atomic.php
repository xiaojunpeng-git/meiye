<?php
declare(strict_types=1);

/**
 * C1-00002 共享余额原子基线真实 MySQL 门禁。
 *
 * 本脚本直接调用生产 UserBalanceAtomicServices，并以独立表前缀隔离测试数据。
 * 只允许在显式开启的本地一次性测试库运行：
 *
 * C1_BALANCE_TEST_ENABLE=1 \
 * DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=c1_balance_test \
 * DB_USERNAME=root DB_PASSWORD=localdev123 \
 * php scripts/smoke-cashier-v3-balance-atomic.php
 *
 * --worker 仅由本脚本的主进程启动，不作为人工入口。
 */

use app\services\user\UserBalanceAtomicServices;
use think\exception\ValidateException;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$GLOBALS['BAT_PASS'] = 0;
$GLOBALS['BAT_FAIL'] = 0;

function batAssert(string $id, string $name, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['BAT_PASS']++;
        echo "PASS [{$id}] {$name}\n";
        echo "GATE_PASS={$id}\n";
        return;
    }
    $GLOBALS['BAT_FAIL']++;
    echo "FAIL [{$id}] {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

function batJson($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : 'JSON_ENCODE_FAILED';
}

function batPrefix(): string
{
    $prefix = getenv('C1_BALANCE_TEST_PREFIX') ?: 'c1bat_';
    if (!preg_match('/^c1bat_[a-z0-9_]{0,20}$/', $prefix)) {
        throw new RuntimeException('C1_BALANCE_TEST_PREFIX must start with c1bat_ and contain only lowercase letters, digits or underscores.');
    }
    return $prefix;
}

function batTable(string $logicalName): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $logicalName)) {
        throw new RuntimeException('Invalid test table name.');
    }
    return batPrefix() . $logicalName;
}

function batQuote(string $identifier): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $identifier)) {
        throw new RuntimeException('Invalid SQL identifier.');
    }
    return '`' . $identifier . '`';
}

function batRequireTestMode(): void
{
    if ((string)getenv('C1_BALANCE_TEST_ENABLE') !== '1') {
        throw new RuntimeException('Refusing to run: set C1_BALANCE_TEST_ENABLE=1 in an isolated local test database.');
    }
    batPrefix();
}

function batBootThinkApp(): think\App
{
    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $app = new think\App($root);
    $envFile = $root . '.env';
    if (!is_file($envFile)) {
        throw new RuntimeException('Backend .env is required to boot ThinkPHP in the isolated test container.');
    }
    $app->env->load($envFile);
    $app->env->set('cache.driver', 'file');
    $app->env->set('CACHE_DRIVER', 'file');
    $app->env->set('database.hostname', getenv('DB_HOST') ?: '127.0.0.1');
    $app->env->set('database.hostport', getenv('DB_PORT') ?: '3306');
    $app->env->set('database.database', getenv('DB_DATABASE') ?: 'c1_balance_test');
    $app->env->set('database.username', getenv('DB_USERNAME') ?: 'root');
    $app->env->set('database.password', getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '');
    $envName = new ReflectionProperty($app, 'envName');
    $envName->setAccessible(true);
    $envName->setValue($app, 'c1_balance_test_skip_dotenv_reload');
    $app->initialize();

    $database = (array)$app->config->get('database');
    $connection = (array)($database['connections']['mysql'] ?? []);
    $connection['type'] = 'mysql';
    $connection['hostname'] = getenv('DB_HOST') ?: ($connection['hostname'] ?? '127.0.0.1');
    $connection['hostport'] = getenv('DB_PORT') ?: ($connection['hostport'] ?? '3306');
    $connection['database'] = getenv('DB_DATABASE') ?: ($connection['database'] ?? 'c1_balance_test');
    $connection['username'] = getenv('DB_USERNAME') ?: ($connection['username'] ?? 'root');
    $connection['password'] = getenv('DB_PASSWORD') !== false
        ? (string)getenv('DB_PASSWORD')
        : (string)($connection['password'] ?? '');
    $connection['charset'] = 'utf8mb4';
    $connection['prefix'] = batPrefix();
    $connection['fields_strict'] = true;
    $connection['fields_cache'] = false;
    $connection['break_reconnect'] = false;
    $database['default'] = 'mysql';
    $database['connections']['mysql'] = $connection;

    $app->config->set($database, 'database');
    Db::connect('mysql', true);
    return $app;
}

function batPdo(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_DATABASE') ?: 'c1_balance_test';
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '';
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function batDropSchema(PDO $pdo): void
{
    foreach ([
        'store_order_missing', 'store_order_full', 'store_order',
        'user_money_incomplete', 'user_money_full', 'user_money',
        'balance_test_barrier', 'user',
    ] as $logicalName) {
        $pdo->exec('DROP TABLE IF EXISTS ' . batQuote(batTable($logicalName)));
    }
}

function batPrepareSchema(PDO $pdo): void
{
    batDropSchema($pdo);
    $pdo->exec(
        'CREATE TABLE ' . batQuote(batTable('user')) . ' (
          `uid` INT UNSIGNED NOT NULL,
          `now_money` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `ben_money` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `give_money` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
    $pdo->exec(
        'CREATE TABLE ' . batQuote(batTable('user_money')) . ' (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `uid` INT UNSIGNED NOT NULL,
          `link_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `type` VARCHAR(64) NOT NULL DEFAULT \'\',
          `title` VARCHAR(255) NOT NULL DEFAULT \'\',
          `number` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `mark` VARCHAR(255) NOT NULL DEFAULT \'\',
          `pm` TINYINT UNSIGNED NOT NULL DEFAULT 0,
          `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
          `ben_money` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `give_money` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `ben_change_amount` DECIMAL(12,2) NULL DEFAULT NULL,
          `give_change_amount` DECIMAL(12,2) NULL DEFAULT NULL,
          `idempotency_key` VARCHAR(128) NULL DEFAULT NULL,
          `idempotency_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
          `add_time` INT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_idempotency_key` (`idempotency_key`),
          KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
    $pdo->exec(
        'CREATE TABLE ' . batQuote(batTable('store_order')) . ' (
          `id` INT UNSIGNED NOT NULL,
          `paid_ben_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `paid_give_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `paid_balance_ready` TINYINT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
    $pdo->exec(
        'CREATE TABLE ' . batQuote(batTable('balance_test_barrier')) . ' (
          `barrier_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `worker_token` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `released` TINYINT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (`barrier_id`,`worker_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

function batSeedUser(PDO $pdo, int $uid, string $ben, string $give, ?string $total = null): void
{
    $total = $total === null ? bcadd($ben, $give, 2) : $total;
    $delete = $pdo->prepare('DELETE FROM ' . batQuote(batTable('user_money')) . ' WHERE `uid`=?');
    $delete->execute([$uid]);
    $replace = $pdo->prepare(
        'REPLACE INTO ' . batQuote(batTable('user')) . ' (`uid`,`now_money`,`ben_money`,`give_money`) VALUES (?,?,?,?)'
    );
    $replace->execute([$uid, $total, $ben, $give]);
}

function batSeedOrder(PDO $pdo, int $orderId): void
{
    $replace = $pdo->prepare(
        'REPLACE INTO ' . batQuote(batTable('store_order'))
        . ' (`id`,`paid_ben_amount`,`paid_give_amount`,`paid_balance_ready`) VALUES (?,0.00,0.00,0)'
    );
    $replace->execute([$orderId]);
}

function batUser(PDO $pdo, int $uid): array
{
    $query = $pdo->prepare(
        'SELECT `uid`,`now_money`,`ben_money`,`give_money` FROM ' . batQuote(batTable('user')) . ' WHERE `uid`=?'
    );
    $query->execute([$uid]);
    return (array)$query->fetch();
}

function batOrder(PDO $pdo, int $orderId): array
{
    $query = $pdo->prepare(
        'SELECT `id`,`paid_ben_amount`,`paid_give_amount`,`paid_balance_ready` FROM '
        . batQuote(batTable('store_order')) . ' WHERE `id`=?'
    );
    $query->execute([$orderId]);
    return (array)$query->fetch();
}

function batLedgerByKey(PDO $pdo, string $key): array
{
    $query = $pdo->prepare(
        'SELECT * FROM ' . batQuote(batTable('user_money')) . ' WHERE `idempotency_key`=?'
    );
    $query->execute([$key]);
    return (array)$query->fetch();
}

function batLedgerCount(PDO $pdo, int $uid): int
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM ' . batQuote(batTable('user_money')) . ' WHERE `uid`=?');
    $query->execute([$uid]);
    return (int)$query->fetchColumn();
}

function batError(callable $callable, string $contains = ''): array
{
    try {
        $callable();
    } catch (Throwable $throwable) {
        $message = $throwable->getMessage();
        return [
            'ok' => $throwable instanceof ValidateException
                && ($contains === '' || strpos($message, $contains) !== false),
            'class' => get_class($throwable),
            'message' => $message,
        ];
    }
    return ['ok' => false, 'class' => '', 'message' => 'NO_EXCEPTION'];
}

function batWorkerEnvironment(): array
{
    $environment = [];
    $all = getenv();
    if (is_array($all)) {
        foreach ($all as $key => $value) {
            if (is_scalar($value)) {
                $environment[(string)$key] = (string)$value;
            }
        }
    }
    $environment['C1_BALANCE_TEST_ENABLE'] = '1';
    $environment['C1_BALANCE_TEST_PREFIX'] = batPrefix();
    return $environment;
}

function batStartWorker(array $payload): array
{
    $encoded = base64_encode((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --worker ' . escapeshellarg($encoded);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), batWorkerEnvironment());
    if (!is_resource($process)) {
        return ['process' => null, 'pipes' => [], 'command' => $command];
    }
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes, 'command' => $command];
}

function batCollectWorker(array $worker): array
{
    if (!is_resource($worker['process'] ?? null)) {
        return ['exit' => -1, 'status' => 'start_failed', 'raw' => ''];
    }
    $stdout = (string)stream_get_contents($worker['pipes'][1]);
    $stderr = (string)stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    $decoded = null;
    if (preg_match('/^BAT_WORKER_RESULT=(\{.*\})$/m', $stdout, $matches)) {
        $decoded = json_decode($matches[1], true);
    }
    return [
        'exit' => $exit,
        'status' => is_array($decoded) ? (string)($decoded['status'] ?? '') : 'missing_result',
        'result' => is_array($decoded) ? $decoded : null,
        'raw' => $stdout,
        'stderr' => $stderr,
    ];
}

function batRunConcurrent(PDO $pdo, array $operations): array
{
    $barrierId = 'BAT-' . bin2hex(random_bytes(12));
    $workers = [];
    foreach ($operations as $index => $operation) {
        $operation['barrier_id'] = $barrierId;
        $operation['worker_token'] = 'W' . $index . '-' . bin2hex(random_bytes(5));
        $workers[] = batStartWorker($operation);
    }

    $ready = false;
    $deadline = microtime(true) + 20.0;
    $countQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . batQuote(batTable('balance_test_barrier')) . ' WHERE `barrier_id`=?'
    );
    while (microtime(true) < $deadline) {
        $countQuery->execute([$barrierId]);
        if ((int)$countQuery->fetchColumn() === count($operations)) {
            $ready = true;
            break;
        }
        usleep(20000);
    }
    $release = $pdo->prepare(
        'UPDATE ' . batQuote(batTable('balance_test_barrier')) . ' SET `released`=1 WHERE `barrier_id`=?'
    );
    $release->execute([$barrierId]);

    $results = [];
    foreach ($workers as $worker) {
        $results[] = batCollectWorker($worker);
    }
    $delete = $pdo->prepare('DELETE FROM ' . batQuote(batTable('balance_test_barrier')) . ' WHERE `barrier_id`=?');
    $delete->execute([$barrierId]);
    return ['ready' => $ready, 'workers' => $results];
}

function batWorker(UserBalanceAtomicServices $service, array $payload): array
{
    $barrierId = (string)($payload['barrier_id'] ?? '');
    $workerToken = (string)($payload['worker_token'] ?? '');
    if ($barrierId === '' || $workerToken === '') {
        throw new RuntimeException('WORKER_BARRIER_INVALID');
    }
    Db::name('balance_test_barrier')->insert([
        'barrier_id' => $barrierId,
        'worker_token' => $workerToken,
        'released' => 0,
    ]);
    $deadline = microtime(true) + 20.0;
    do {
        $released = (int)Db::name('balance_test_barrier')
            ->where(['barrier_id' => $barrierId, 'worker_token' => $workerToken])
            ->value('released');
        if ($released === 1) {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    if ($released !== 1) {
        throw new RuntimeException('WORKER_BARRIER_TIMEOUT');
    }

    $method = (string)($payload['method'] ?? '');
    if ($method === 'deductPreferBen') {
        return $service->deductPreferBen(
            (int)$payload['uid'],
            (string)$payload['amount'],
            (string)$payload['bill_type'],
            (int)$payload['link_id'],
            'balance atomic worker',
            (string)$payload['key']
        );
    }
    if ($method === 'deductBenGive') {
        return $service->deductBenGive(
            (int)$payload['uid'],
            (string)$payload['ben'],
            (string)$payload['give'],
            (string)$payload['bill_type'],
            (int)$payload['link_id'],
            'balance atomic worker',
            (string)$payload['key']
        );
    }
    throw new RuntimeException('WORKER_METHOD_INVALID');
}

function batWorkerPayload(int $uid, string $amount, int $linkId, string $key): array
{
    return [
        'method' => 'deductPreferBen',
        'uid' => $uid,
        'amount' => $amount,
        'bill_type' => 'pay_product',
        'link_id' => $linkId,
        'key' => $key,
    ];
}

function batRunMain(PDO $pdo, UserBalanceAtomicServices $service): void
{
    echo "== C1-00002 balance atomic production-service gate ==\n";
    echo 'MYSQL_VERSION=' . (string)$pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
    echo 'ISOLATED_TABLE_PREFIX=' . batPrefix() . "\n";

    $reflection = new ReflectionClass(UserBalanceAtomicServices::class);
    $expectedSignatures = [
        'lockUser' => ['uid'],
        'assertInvariant' => ['user'],
        'deductPreferBen' => ['uid', 'amount', 'billType', 'linkId', 'title', 'idempotencyKey'],
        'creditBenGive' => ['uid', 'ben', 'give', 'billType', 'linkId', 'title', 'idempotencyKey'],
        'deductBenGive' => ['uid', 'ben', 'give', 'billType', 'linkId', 'title', 'idempotencyKey'],
        'writeOrderPaidSnapshot' => ['orderId', 'paidBen', 'paidGive'],
    ];
    $signatureOk = true;
    foreach ($expectedSignatures as $methodName => $expectedParameters) {
        if (!$reflection->hasMethod($methodName) || !$reflection->getMethod($methodName)->isPublic()) {
            $signatureOk = false;
            continue;
        }
        $actual = array_map(function (ReflectionParameter $parameter): string {
            return $parameter->getName();
        }, $reflection->getMethod($methodName)->getParameters());
        $signatureOk = $signatureOk && $actual === $expectedParameters;
    }
    batAssert('BAL-01', '生产服务可加载且冻结公共 API 签名完整', $signatureOk);

    $serviceRoot = dirname(__DIR__) . '/app/services/order/';
    $callContracts = [
        'StoreOrderRefundDomainServices.php' => ['deductBenGive'],
        'StoreOrderRefundServices.php' => ['creditBenGive'],
        'StoreOrderVoidServices.php' => ['deductBenGive', 'creditBenGive'],
        'StoreDebtServices.php' => ['deductPreferBen', 'writeOrderPaidSnapshot'],
    ];
    $callsOk = true;
    foreach ($callContracts as $file => $methods) {
        $body = is_file($serviceRoot . $file) ? (string)file_get_contents($serviceRoot . $file) : '';
        foreach ($methods as $method) {
            $callsOk = $callsOk && strpos($body, '->' . $method . '(') !== false;
        }
    }
    batAssert('BAL-02', '四个既有调用方仍命中冻结 API', $callsOk);

    batSeedUser($pdo, 100, '10.00', '5.00');
    $outsideLock = batError(function () use ($service): void {
        $service->lockUser(100);
    }, '必须在数据库事务内');
    $insideLock = Db::transaction(function () use ($service): array {
        return $service->lockUser(100);
    });
    batAssert(
        'BAL-02-TX',
        '公开会员锁拒绝事务外误用且事务内返回权威三字段',
        $outsideLock['ok']
            && $insideLock['ben_money'] === '10.00'
            && $insideLock['give_money'] === '5.00'
            && $insideLock['now_money'] === '15.00',
        batJson(['outside' => $outsideLock, 'inside' => $insideLock])
    );

    batSeedUser($pdo, 101, '100.00', '50.00');
    $principal = $service->deductPreferBen(101, '40', 'pay_product', 1001, 'principal enough', 'BAT-03');
    $principalUser = batUser($pdo, 101);
    batAssert(
        'BAL-03',
        '本金足够时只扣本金',
        $principal['paid_ben'] === '40.00'
            && $principal['paid_give'] === '0.00'
            && $principal['after'] === ['ben' => '60.00', 'give' => '50.00', 'total' => '110.00']
            && $principalUser['ben_money'] === '60.00'
            && $principalUser['give_money'] === '50.00'
            && $principalUser['now_money'] === '110.00',
        batJson(['result' => $principal, 'user' => $principalUser])
    );

    batSeedUser($pdo, 102, '20.00', '50.00');
    $split = $service->deductPreferBen(102, '35.00', 'pay_product', 1002, 'principal insufficient', 'BAT-04');
    batAssert(
        'BAL-04',
        '本金不足时本金清零且差额扣赠金',
        $split['change_ben'] === '-20.00'
            && $split['change_give'] === '-15.00'
            && $split['after'] === ['ben' => '0.00', 'give' => '35.00', 'total' => '35.00'],
        batJson($split)
    );

    batSeedUser($pdo, 103, '10.00', '5.00');
    $beforeInsufficient = batUser($pdo, 103);
    $insufficient = batError(function () use ($service): void {
        $service->deductPreferBen(103, '20.00', 'pay_product', 1003, '', 'BAT-05');
    }, '余额不足');
    batAssert(
        'BAL-05',
        '总余额不足整笔失败且不写字段或流水',
        $insufficient['ok'] && batUser($pdo, 103) === $beforeInsufficient && batLedgerCount($pdo, 103) === 0,
        batJson($insufficient)
    );

    batSeedUser($pdo, 104, '10.00', '50.00');
    $beforeSpecified = batUser($pdo, 104);
    $benInsufficient = batError(function () use ($service): void {
        $service->deductBenGive(104, '11.00', '0.00', 'pay_product', 1004, '', 'BAT-06-BEN');
    }, '本金不足');
    $giveInsufficient = batError(function () use ($service): void {
        $service->deductBenGive(104, '0.00', '51.00', 'pay_product', 1004, '', 'BAT-06-GIVE');
    }, '赠金不足');
    batAssert(
        'BAL-06',
        '指定本赠金任一分量不足均整笔失败',
        $benInsufficient['ok'] && $giveInsufficient['ok']
            && batUser($pdo, 104) === $beforeSpecified && batLedgerCount($pdo, 104) === 0,
        batJson([$benInsufficient, $giveInsufficient])
    );

    batSeedUser($pdo, 105, '0.00', '0.00');
    $credited = $service->creditBenGive(105, '30.00', '20.00', 'pay_product_refund', 1005, 'refund', 'BAT-07');
    batAssert(
        'BAL-07',
        '退款按来源本赠金加回且不受当前低余额限制',
        $credited['change_ben'] === '30.00'
            && $credited['change_give'] === '20.00'
            && $credited['after'] === ['ben' => '30.00', 'give' => '20.00', 'total' => '50.00']
            && batUser($pdo, 105)['now_money'] === '50.00',
        batJson($credited)
    );

    batSeedUser($pdo, 106, '100.00', '50.00');
    $invalidInputs = ['abc', '-1', '1.001', 'INF', '999999999.00'];
    $invalidOk = true;
    foreach ($invalidInputs as $index => $invalidInput) {
        $error = batError(function () use ($service, $invalidInput, $index): void {
            $service->deductPreferBen(106, $invalidInput, 'pay_product', 1100 + $index, '', 'BAT-08-' . $index);
        }, '金额');
        $invalidOk = $invalidOk && $error['ok'];
    }
    batAssert(
        'BAL-08',
        '非数字、负数、三位小数、无限表示和越界金额全部 fail-closed',
        $invalidOk && batUser($pdo, 106)['now_money'] === '150.00' && batLedgerCount($pdo, 106) === 0
    );

    batSeedUser($pdo, 107, '10.00', '5.00', '99.00');
    $invariantBefore = batUser($pdo, 107);
    $invariantError = batError(function () use ($service): void {
        $service->deductPreferBen(107, '1.00', 'pay_product', 1007, '', 'BAT-09');
    }, '余额数据异常');
    batAssert(
        'BAL-09',
        '会员三字段不变量异常时拒绝写入',
        $invariantError['ok'] && batUser($pdo, 107) === $invariantBefore && batLedgerCount($pdo, 107) === 0,
        batJson($invariantError)
    );

    batSeedUser($pdo, 108, '100.00', '50.00');
    $first = $service->deductPreferBen(108, '30.00', 'pay_product', 1008, 'first', 'BAT-10-A');
    $service->deductPreferBen(108, '10.00', 'pay_product', 1009, 'later', 'BAT-10-B');
    $replay = $service->deductPreferBen(108, '30.00', 'pay_product', 1008, 'changed title ignored', 'BAT-10-A');
    batAssert(
        'BAL-10',
        '串行同键重试仅变更一次并返回第一次原始 before/after',
        $first['idempotent'] === false
            && $replay['idempotent'] === true
            && $replay['money_id'] === $first['money_id']
            && $replay['before'] === ['ben' => '100.00', 'give' => '50.00', 'total' => '150.00']
            && $replay['after'] === ['ben' => '70.00', 'give' => '50.00', 'total' => '120.00']
            && batUser($pdo, 108)['now_money'] === '110.00'
            && batLedgerCount($pdo, 108) === 2,
        batJson(['first' => $first, 'replay' => $replay])
    );

    batSeedUser($pdo, 118, '50.00', '50.00');
    $legacyInsert = $pdo->prepare(
        'INSERT INTO ' . batQuote(batTable('user_money')) . ' '
        . '(`uid`,`link_id`,`type`,`title`,`number`,`balance`,`mark`,`pm`,`status`,`ben_money`,`give_money`,'
        . '`ben_change_amount`,`give_change_amount`,`idempotency_key`,`idempotency_fingerprint`,`add_time`) '
        . 'VALUES (118,1018,\'pay_product_refund\',\'legacy\',30.00,100.00,\'legacy\',1,1,50.00,50.00,NULL,NULL,?,NULL,?)'
    );
    $legacyInsert->execute(['BAT-10-LEGACY-A', time()]);
    $legacyInsert->execute(['BAT-10-LEGACY-B', time()]);
    $legacyA = batError(function () use ($service): void {
        $service->creditBenGive(118, '30.00', '0.00', 'pay_product_refund', 1018, '', 'BAT-10-LEGACY-A');
    }, '旧余额流水');
    $legacyB = batError(function () use ($service): void {
        $service->creditBenGive(118, '0.00', '30.00', 'pay_product_refund', 1018, '', 'BAT-10-LEGACY-B');
    }, '旧余额流水');
    batAssert(
        'BAL-10-LEGACY',
        '旧流水缺指纹和本赠金分量时不得信任本次请求猜测历史拆分',
        $legacyA['ok'] && $legacyB['ok']
            && batUser($pdo, 118) === ['uid' => 118, 'now_money' => '100.00', 'ben_money' => '50.00', 'give_money' => '50.00']
            && batLedgerCount($pdo, 118) === 2,
        batJson([$legacyA, $legacyB])
    );

    batSeedUser($pdo, 109, '100.00', '50.00');
    $sameConcurrent = batRunConcurrent($pdo, [
        batWorkerPayload(109, '30.00', 1010, 'BAT-11-SAME'),
        batWorkerPayload(109, '30.00', 1010, 'BAT-11-SAME'),
    ]);
    $sameResults = array_map(function (array $worker) {
        return $worker['result'] ?? null;
    }, $sameConcurrent['workers']);
    $sameOk = $sameConcurrent['ready'];
    $idempotentFlags = [];
    foreach ($sameConcurrent['workers'] as $worker) {
        $sameOk = $sameOk && $worker['exit'] === 0 && $worker['status'] === 'ok';
        $idempotentFlags[] = (bool)($worker['result']['data']['idempotent'] ?? false);
    }
    sort($idempotentFlags);
    batAssert(
        'BAL-11',
        '同键同内容两个真实并发 PHP 进程只写一次流水',
        $sameOk && $idempotentFlags === [false, true]
            && batUser($pdo, 109)['now_money'] === '120.00'
            && batLedgerCount($pdo, 109) === 1,
        batJson($sameResults)
    );

    batSeedUser($pdo, 119, '100.00', '50.00');
    $rrReplay = null;
    $rrError = null;
    $rrWorker = null;
    Db::startTrans();
    try {
        // 先做一致性读建立 REPEATABLE READ 快照，再让另一个真实连接完成首单。
        Db::name('user_money')->where('idempotency_key', 'BAT-11-RR')->count();
        $rrWorker = batRunConcurrent($pdo, [
            batWorkerPayload(119, '30.00', 1019, 'BAT-11-RR'),
        ]);
        try {
            $rrReplay = $service->deductPreferBen(119, '30.00', 'pay_product', 1019, '', 'BAT-11-RR');
        } catch (Throwable $throwable) {
            $rrError = ['class' => get_class($throwable), 'message' => $throwable->getMessage()];
        }
    } finally {
        try {
            Db::rollback();
        } catch (Throwable $ignored) {
        }
    }
    $rrWorkerOk = is_array($rrWorker)
        && !empty($rrWorker['ready'])
        && (int)($rrWorker['workers'][0]['exit'] ?? -1) === 0
        && (string)($rrWorker['workers'][0]['status'] ?? '') === 'ok';
    batAssert(
        'BAL-11-RR',
        '外层 RR 旧快照下同键重放仍读取已提交首单并返回原结果',
        $rrWorkerOk
            && $rrError === null
            && is_array($rrReplay)
            && $rrReplay['idempotent'] === true
            && $rrReplay['before'] === ['ben' => '100.00', 'give' => '50.00', 'total' => '150.00']
            && $rrReplay['after'] === ['ben' => '70.00', 'give' => '50.00', 'total' => '120.00']
            && batUser($pdo, 119)['now_money'] === '120.00'
            && batLedgerCount($pdo, 119) === 1,
        batJson(['worker' => $rrWorker, 'replay' => $rrReplay, 'error' => $rrError])
    );

    batSeedUser($pdo, 110, '100.00', '100.00');
    batSeedUser($pdo, 111, '100.00', '100.00');
    $service->deductBenGive(110, '10.00', '5.00', 'pay_product', 1011, 'base', 'BAT-12-CONFLICT');
    $member110BeforeConflicts = batUser($pdo, 110);
    $member111BeforeConflicts = batUser($pdo, 111);
    $conflictCalls = [
        function () use ($service): void {
            $service->deductBenGive(110, '11.00', '5.00', 'pay_product', 1011, '', 'BAT-12-CONFLICT');
        },
        function () use ($service): void {
            $service->deductBenGive(111, '10.00', '5.00', 'pay_product', 1011, '', 'BAT-12-CONFLICT');
        },
        function () use ($service): void {
            $service->deductBenGive(110, '10.00', '5.00', 'pay_product', 1012, '', 'BAT-12-CONFLICT');
        },
        function () use ($service): void {
            $service->deductBenGive(110, '9.00', '6.00', 'pay_product', 1011, '', 'BAT-12-CONFLICT');
        },
        function () use ($service): void {
            $service->deductBenGive(110, '0.00', '0.00', 'pay_product', 1011, '', 'BAT-12-CONFLICT');
        },
        function () use ($service): void {
            $service->deductBenGive(110, '10.00', '5.00', 'pay_product', 1011, '', 'bat-12-conflict');
        },
    ];
    $allConflicts = true;
    $conflictDetails = [];
    foreach ($conflictCalls as $conflictCall) {
        $error = batError($conflictCall, '幂等键与原业务不一致');
        $conflictDetails[] = $error;
        $allConflicts = $allConflicts && $error['ok'];
    }
    batAssert(
        'BAL-12',
        '同键不同金额、会员、来源或本赠金拆分全部拒绝且不改余额',
        $allConflicts
            && batUser($pdo, 110) === $member110BeforeConflicts
            && batUser($pdo, 111) === $member111BeforeConflicts
            && batLedgerCount($pdo, 110) === 1
            && batLedgerCount($pdo, 111) === 0,
        batJson($conflictDetails)
    );

    $invalidKey = batError(function () use ($service): void {
        $service->deductBenGive(110, '1.00', '0.00', 'pay_product', 1011, '', ' BAT-12-SPACE ');
    }, '幂等标识无效');
    $invalidType = batError(function () use ($service): void {
        $service->deductBenGive(110, '1.00', '0.00', ' pay_product ', 1011, '', 'BAT-12-TYPE');
    }, '操作类型无效');
    batAssert(
        'BAL-12-NORMALIZE',
        '幂等键大小写不折叠，空白键和首尾空白业务类型均拒绝',
        $invalidKey['ok'] && $invalidType['ok']
            && batUser($pdo, 110) === $member110BeforeConflicts
            && batLedgerCount($pdo, 110) === 1,
        batJson(['key' => $invalidKey, 'type' => $invalidType])
    );

    batSeedUser($pdo, 120, '100.00', '0.00');
    batSeedUser($pdo, 121, '100.00', '0.00');
    $crossMember = batRunConcurrent($pdo, [
        batWorkerPayload(120, '10.00', 1020, 'BAT-12-XUID'),
        batWorkerPayload(121, '10.00', 1020, 'BAT-12-XUID'),
    ]);
    $crossSuccess = 0;
    $crossRejected = 0;
    foreach ($crossMember['workers'] as $worker) {
        if ($worker['exit'] === 0 && $worker['status'] === 'ok') {
            $crossSuccess++;
        }
        $message = (string)($worker['result']['message'] ?? '');
        if ($worker['exit'] === 0 && $worker['status'] === 'domain_error'
            && (strpos($message, '幂等键') !== false || strpos($message, '正在处理中') !== false)) {
            $crossRejected++;
        }
    }
    $winnerUid = batUser($pdo, 120)['now_money'] === '90.00' ? 120 : 121;
    $loserUid = $winnerUid === 120 ? 121 : 120;
    $loserRetry = batError(function () use ($service, $loserUid): void {
        $service->deductPreferBen($loserUid, '10.00', 'pay_product', 1020, '', 'BAT-12-XUID');
    }, '幂等键与原业务不一致');
    batAssert(
        'BAL-12-XUID',
        '不同会员并发误用同一幂等键只允许一笔成功，失败方重试明确冲突',
        $crossMember['ready'] && $crossSuccess === 1 && $crossRejected === 1 && $loserRetry['ok']
            && batUser($pdo, $winnerUid)['now_money'] === '90.00'
            && batUser($pdo, $loserUid)['now_money'] === '100.00'
            && batLedgerCount($pdo, $winnerUid) === 1
            && batLedgerCount($pdo, $loserUid) === 0,
        batJson(['workers' => $crossMember['workers'], 'retry' => $loserRetry])
    );

    batSeedUser($pdo, 112, '50.00', '0.00');
    $differentConcurrent = batRunConcurrent($pdo, [
        batWorkerPayload(112, '40.00', 1013, 'BAT-13-A'),
        batWorkerPayload(112, '40.00', 1014, 'BAT-13-B'),
    ]);
    $okWorkers = 0;
    $balanceErrors = 0;
    foreach ($differentConcurrent['workers'] as $worker) {
        if ($worker['exit'] === 0 && $worker['status'] === 'ok') {
            $okWorkers++;
        }
        if ($worker['exit'] === 0
            && $worker['status'] === 'domain_error'
            && strpos((string)($worker['result']['message'] ?? ''), '余额不足') !== false) {
            $balanceErrors++;
        }
    }
    batAssert(
        'BAL-13',
        '不同键并发扣同一会员由行锁串行化且余额不为负',
        $differentConcurrent['ready'] && $okWorkers === 1 && $balanceErrors === 1
            && batUser($pdo, 112)['now_money'] === '10.00'
            && batLedgerCount($pdo, 112) === 1,
        batJson($differentConcurrent['workers'])
    );

    batSeedUser($pdo, 113, '100.00', '50.00');
    batSeedOrder($pdo, 2014);
    $rollbackBefore = batUser($pdo, 113);
    $rollbackRaised = false;
    try {
        Db::transaction(function () use ($service): void {
            $service->deductPreferBen(113, '25.00', 'pay_product', 2014, 'rollback', 'BAT-14');
            $service->writeOrderPaidSnapshot(2014, '25.00', '0.00');
            throw new RuntimeException('BAT_OUTER_ROLLBACK');
        });
    } catch (RuntimeException $runtimeException) {
        $rollbackRaised = $runtimeException->getMessage() === 'BAT_OUTER_ROLLBACK';
    }
    $rollbackOrder = batOrder($pdo, 2014);
    batAssert(
        'BAL-14',
        '外层事务回滚同时撤销会员三字段、流水和订单快照',
        $rollbackRaised
            && batUser($pdo, 113) === $rollbackBefore
            && batLedgerCount($pdo, 113) === 0
            && $rollbackOrder['paid_ben_amount'] === '0.00'
            && $rollbackOrder['paid_give_amount'] === '0.00'
            && (int)$rollbackOrder['paid_balance_ready'] === 0,
        batJson($rollbackOrder)
    );

    $missingOrder = batError(function () use ($service): void {
        Db::transaction(function () use ($service): void {
            $service->writeOrderPaidSnapshot(999999, '1.00', '0.00');
        });
    }, '订单不存在');
    $orderTable = batTable('store_order');
    $fullOrderTable = batTable('store_order_full');
    $schemaMissing = ['ok' => false, 'message' => 'NOT_RUN'];
    try {
        $pdo->exec('RENAME TABLE ' . batQuote($orderTable) . ' TO ' . batQuote($fullOrderTable));
        $pdo->exec(
            'CREATE TABLE ' . batQuote($orderTable) . ' (`id` INT UNSIGNED NOT NULL PRIMARY KEY) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec('INSERT INTO ' . batQuote($orderTable) . ' (`id`) VALUES (2015)');
        $schemaMissing = batError(function () use ($service): void {
            Db::transaction(function () use ($service): void {
                $service->writeOrderPaidSnapshot(2015, '1.00', '0.00');
            });
        }, '快照结构未就绪');
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS ' . batQuote($orderTable));
        $pdo->exec('RENAME TABLE ' . batQuote($fullOrderTable) . ' TO ' . batQuote($orderTable));
    }
    batAssert(
        'BAL-15',
        '订单不存在或快照字段缺失均 fail-closed',
        $missingOrder['ok'] && $schemaMissing['ok'],
        batJson([$missingOrder, $schemaMissing])
    );

    batSeedOrder($pdo, 2016);
    $standaloneRejected = batError(function () use ($service): void {
        $service->writeOrderPaidSnapshot(2016, '12.00', '3.00');
    }, '必须与余额变更在同一事务内');
    Db::transaction(function () use ($service): void {
        $service->writeOrderPaidSnapshot(2016, '12.00', '3.00');
        $service->writeOrderPaidSnapshot(2016, '12.00', '3.00');
    });
    $standaloneConflict = batError(function () use ($service): void {
        Db::transaction(function () use ($service): void {
            $service->writeOrderPaidSnapshot(2016, '11.00', '4.00');
        });
    }, '快照冲突');
    $standaloneOrder = batOrder($pdo, 2016);
    batAssert(
        'BAL-15-STANDALONE',
        '快照拒绝脱离外层事务；事务内同内容幂等且不同内容冲突',
        $standaloneRejected['ok'] && $standaloneConflict['ok']
            && $standaloneOrder['paid_ben_amount'] === '12.00'
            && $standaloneOrder['paid_give_amount'] === '3.00'
            && (int)$standaloneOrder['paid_balance_ready'] === 1,
        batJson(['order' => $standaloneOrder, 'standalone' => $standaloneRejected, 'conflict' => $standaloneConflict])
    );

    $ledger03 = batLedgerByKey($pdo, 'BAT-03');
    $ledger04 = batLedgerByKey($pdo, 'BAT-04');
    batAssert(
        'BAL-16',
        '新流水本赠金变动、after 快照和 SHA-256 指纹准确',
        $ledger03['ben_change_amount'] === '-40.00'
            && $ledger03['give_change_amount'] === '0.00'
            && $ledger03['ben_money'] === '60.00'
            && $ledger03['give_money'] === '50.00'
            && $ledger03['balance'] === '110.00'
            && preg_match('/^[a-f0-9]{64}$/', (string)$ledger03['idempotency_fingerprint']) === 1
            && $ledger04['ben_change_amount'] === '-20.00'
            && $ledger04['give_change_amount'] === '-15.00'
            && $ledger04['ben_money'] === '0.00'
            && $ledger04['give_money'] === '35.00'
            && $ledger04['balance'] === '35.00',
        batJson(['BAT-03' => $ledger03, 'BAT-04' => $ledger04])
    );

    batSeedUser($pdo, 114, '100.00', '50.00');
    $dependencyBefore = batUser($pdo, 114);
    $moneyTable = batTable('user_money');
    $fullMoneyTable = batTable('user_money_full');
    $dependencyError = ['ok' => false, 'message' => 'NOT_RUN'];
    $savepointError = ['ok' => false, 'message' => 'NOT_RUN'];
    $savepointOuterCommitted = false;
    $incompleteCount = -1;
    try {
        $pdo->exec('RENAME TABLE ' . batQuote($moneyTable) . ' TO ' . batQuote($fullMoneyTable));
        $pdo->exec(
            'CREATE TABLE ' . batQuote($moneyTable) . ' (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `uid` INT UNSIGNED NOT NULL,
              `link_id` INT UNSIGNED NOT NULL DEFAULT 0,
              `type` VARCHAR(64) NOT NULL DEFAULT \'\',
              `title` VARCHAR(255) NOT NULL DEFAULT \'\',
              `number` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `mark` VARCHAR(255) NOT NULL DEFAULT \'\',
              `pm` TINYINT UNSIGNED NOT NULL DEFAULT 0,
              `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
              `ben_money` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `give_money` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `idempotency_key` VARCHAR(128) NULL DEFAULT NULL,
              `add_time` INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_idempotency_key` (`idempotency_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $dependencyError = batError(function () use ($service): void {
            $service->deductPreferBen(114, '10.00', 'pay_product', 1017, '', 'BAT-17');
        }, '结构未就绪');
        Db::transaction(function () use ($service, &$savepointError): void {
            $savepointError = batError(function () use ($service): void {
                $service->deductPreferBen(114, '10.00', 'pay_product', 1017, '', 'BAT-17-SAVEPOINT');
            }, '结构未就绪');
            Db::name('balance_test_barrier')->insert([
                'barrier_id' => 'BAT-17-SAVEPOINT',
                'worker_token' => 'OUTER-CONTINUED',
                'released' => 1,
            ]);
        });
        $savepointOuterCommitted = (int)$pdo->query(
            'SELECT COUNT(*) FROM ' . batQuote(batTable('balance_test_barrier'))
            . " WHERE `barrier_id`='BAT-17-SAVEPOINT' AND `worker_token`='OUTER-CONTINUED'"
        )->fetchColumn() === 1;
        $pdo->exec(
            'DELETE FROM ' . batQuote(batTable('balance_test_barrier'))
            . " WHERE `barrier_id`='BAT-17-SAVEPOINT'"
        );
        $incompleteCount = (int)$pdo->query('SELECT COUNT(*) FROM ' . batQuote($moneyTable))->fetchColumn();
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS ' . batQuote($moneyTable));
        $pdo->exec('RENAME TABLE ' . batQuote($fullMoneyTable) . ' TO ' . batQuote($moneyTable));
    }
    batAssert(
        'BAL-17',
        '006 流水结构缺失时生产写入阻断且会员更新整体回滚',
        $dependencyError['ok'] && $savepointError['ok'] && $savepointOuterCommitted
            && batUser($pdo, 114) === $dependencyBefore
            && $incompleteCount === 0
            && batLedgerCount($pdo, 114) === 0,
        batJson([
            'error' => $dependencyError,
            'savepointError' => $savepointError,
            'outerContinued' => $savepointOuterCommitted,
            'incompleteRows' => $incompleteCount,
        ])
    );

    $serviceSource = (string)file_get_contents($reflection->getFileName());
    $noRuntimeDdl = preg_match('/(?:CREATE|ALTER|DROP)\s+TABLE/i', $serviceSource) !== 1;
    $noStaticSchemaCache = preg_match('/static\s+\$[^;]*(?:schema|column|ready)/i', $serviceSource) !== 1;
    batAssert(
        'BAL-18-RUNTIME',
        '生产余额服务不执行运行时 DDL 且不以 static 结构缓存掩盖缺表',
        $noRuntimeDdl && $noStaticSchemaCache
    );
    echo "EXTERNAL_SQL_GATE_REQUIRED=BAL-17-PREFLIGHT:01-升级前检查.sql must reject missing 001/002 dependencies\n";
    echo "EXTERNAL_SQL_GATE_REQUIRED=BAL-18-MYSQL56:02-正式升级.sql must execute twice on disposable MySQL 5.6\n";
}

batRequireTestMode();
$app = batBootThinkApp();
$service = $app->make(UserBalanceAtomicServices::class);

if (($argv[1] ?? '') === '--worker') {
    try {
        $payload = json_decode((string)base64_decode((string)($argv[2] ?? ''), true), true);
        if (!is_array($payload)) {
            throw new RuntimeException('WORKER_PAYLOAD_INVALID');
        }
        $data = batWorker($service, $payload);
        echo 'BAT_WORKER_RESULT=' . batJson(['status' => 'ok', 'data' => $data]) . "\n";
        exit(0);
    } catch (ValidateException $validateException) {
        echo 'BAT_WORKER_RESULT=' . batJson([
            'status' => 'domain_error',
            'class' => get_class($validateException),
            'message' => $validateException->getMessage(),
        ]) . "\n";
        exit(0);
    } catch (Throwable $throwable) {
        echo 'BAT_WORKER_RESULT=' . batJson([
            'status' => 'unexpected_error',
            'class' => get_class($throwable),
            'message' => $throwable->getMessage(),
        ]) . "\n";
        exit(1);
    }
}

$pdo = batPdo();
try {
    batPrepareSchema($pdo);
    batRunMain($pdo, $service);
} catch (Throwable $throwable) {
    $GLOBALS['BAT_FAIL']++;
    echo 'FATAL ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n";
    echo $throwable->getTraceAsString() . "\n";
} finally {
    if ((string)getenv('C1_BALANCE_TEST_KEEP_SCHEMA') !== '1') {
        try {
            batDropSchema($pdo);
            echo "ISOLATED_SCHEMA_CLEANED=1\n";
        } catch (Throwable $cleanupError) {
            $GLOBALS['BAT_FAIL']++;
            echo 'CLEANUP_FAILED ' . $cleanupError->getMessage() . "\n";
        }
    }
}

echo "\n== C1-00002 BALANCE ATOMIC SUMMARY ==\n";
echo 'ASSERT_PASSED=' . (int)$GLOBALS['BAT_PASS'] . "\n";
echo 'ASSERT_FAILED=' . (int)$GLOBALS['BAT_FAIL'] . "\n";
if ((int)$GLOBALS['BAT_FAIL'] > 0) {
    exit(1);
}
echo "RUNNER_OK=C1-00002-balance-atomic\n";
exit(0);
