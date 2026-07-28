<?php
/**
 * C1-A 永久回归｜共享断言库
 */

$GLOBALS['C1A_PASSED'] = 0;
$GLOBALS['C1A_FAILED'] = 0;
$GLOBALS['C1A_TEST_IDS'] = [];
$GLOBALS['C1A_GATE_IDS'] = [];

function ok(string $name, bool $condition, string $detail = '', ?string $testId = null): void
{
    if ($testId !== null) {
        $GLOBALS['C1A_TEST_IDS'][] = $testId;
    }
    if ($condition) {
        $GLOBALS['C1A_PASSED']++;
        echo "  PASS  {$name}" . ($testId ? " [{$testId}]" : '') . "\n";
        if ($testId !== null) {
            gatePass($testId);
        }
        return;
    }
    $GLOBALS['C1A_FAILED']++;
    echo "  FAIL  {$name}" . ($testId ? " [{$testId}]" : '') . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

/** 每个 required gate 必须输出唯一 GATE_PASS=<id> */
function gatePass(string $gateId): void
{
    if ($gateId === '' || isset($GLOBALS['C1A_GATE_IDS'][$gateId])) {
        return;
    }
    $GLOBALS['C1A_GATE_IDS'][$gateId] = true;
    echo "GATE_PASS={$gateId}\n";
}

function finish(string $suite): void
{
    $passed = (int)$GLOBALS['C1A_PASSED'];
    $failed = (int)$GLOBALS['C1A_FAILED'];
    echo "\n== {$suite} SUMMARY ==\n";
    echo "ASSERT_PASSED={$passed}\n";
    echo "ASSERT_FAILED={$failed}\n";
    if (!empty($GLOBALS['C1A_TEST_IDS'])) {
        echo 'TEST_IDS=' . implode(',', array_unique($GLOBALS['C1A_TEST_IDS'])) . "\n";
    }
    if ($failed > 0) {
        exit(1);
    }
    echo "RUNNER_OK={$suite}\n";
    exit(0);
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function rejects(string $name, string $expectedCode, callable $callable, string $expectedReason = '', ?string $testId = null): void
{
    try {
        $callable();
    } catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
        $codeOk = $exception->getResultCode() === $expectedCode;
        $reasonOk = $expectedReason === '' || (string)($exception->getDetail()['reason'] ?? '') === $expectedReason;
        ok($name, $codeOk && $reasonOk, 'code=' . $exception->getResultCode() . ' reason=' . (string)($exception->getDetail()['reason'] ?? ''), $testId);
        return;
    } catch (\Throwable $throwable) {
        ok($name, false, '非命令异常：' . get_class($throwable) . ' ' . $throwable->getMessage(), $testId);
        return;
    }
    ok($name, false, '未抛出异常', $testId);
}

function c1aForceTestDatabase(): void
{
    $host = getenv('DB_HOST') ?: '';
    if ($host === '') {
        return;
    }
    $config = [
        'type' => 'mysql',
        'hostname' => $host,
        'hostport' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: 'lin8',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'prefix' => 'eb_',
    ];
    if (class_exists(\think\facade\Config::class)) {
        \think\facade\Config::set($config, 'database.connections.mysql');
        \think\facade\Config::set(['default' => 'file'], 'cache');
    }
    if (class_exists(\think\facade\Db::class)) {
        try {
            \think\facade\Db::connect('mysql', true);
        } catch (\Throwable $e) {
        }
    }
}

/**
 * 启动 ThinkPHP：只读加载容器内叠层后的 /var/www/html/.env（测试最小配置），
 * 并证明读取的是临时值。不改写宿主机真实 .env。
 */
function c1aBootThinkApp(string $rootPath = '/var/www/html/'): \think\App
{
    foreach ([
        'CACHE_DRIVER' => 'file',
        'PHP_CACHE_DRIVER' => 'file',
    ] as $k => $v) {
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }

    $app = new \think\App($rootPath);
    $envFile = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile)) {
        throw new \RuntimeException('ENV_FILE_MISSING_AT_' . $envFile);
    }
    // 隔离 volume 内 /var/www/html/.env 已被临时最小配置覆盖；只读该路径。
    $app->env->load($envFile);
    $app->env->set('cache.driver', 'file');
    $app->env->set('CACHE_DRIVER', 'file');

    $dbHost = getenv('DB_HOST') ?: getenv('HOSTNAME') ?: '';
    if ($dbHost !== '') {
        $app->env->set('database.hostname', $dbHost);
        $app->env->set('DATABASE_HOSTNAME', $dbHost);
        $app->env->set('database.hostport', getenv('DB_PORT') ?: '3306');
        $app->env->set('DATABASE_HOSTPORT', getenv('DB_PORT') ?: '3306');
        $app->env->set('database.database', getenv('DB_DATABASE') ?: 'lin8');
        $app->env->set('DATABASE_DATABASE', getenv('DB_DATABASE') ?: 'lin8');
        $app->env->set('database.username', getenv('DB_USERNAME') ?: 'root');
        $app->env->set('DATABASE_USERNAME', getenv('DB_USERNAME') ?: 'root');
        $app->env->set('database.password', getenv('DB_PASSWORD') ?: '');
        $app->env->set('DATABASE_PASSWORD', getenv('DB_PASSWORD') ?: '');
        $app->env->set('database.type', 'mysql');
        $app->env->set('DATABASE_TYPE', 'mysql');
    }
    $redisHost = getenv('REDIS_HOSTNAME') ?: '';
    if ($redisHost !== '') {
        $app->env->set('redis.redis_hostname', $redisHost);
        $app->env->set('REDIS_REDIS_HOSTNAME', $redisHost);
        $app->env->set('REDIS_HOSTNAME', $redisHost);
    }

    // 门禁：证明 /var/www/html/.env 是临时叠层（PASSWORD=localdev123 + HOSTNAME=marker）
    $marker = getenv('C1A_TEMP_ENV_MARKER') ?: '';
    if ($marker !== '') {
        $envBody = (string)file_get_contents($envFile);
        if (strpos($envBody, 'HOSTNAME = ' . $marker) === false) {
            throw new \RuntimeException('TEMP_ENV_MARKER_MISSING_IN_HTML_ENV');
        }
        if (strpos($envBody, 'PASSWORD = localdev123') === false) {
            throw new \RuntimeException('TEMP_ENV_PASSWORD_MISSING_IN_HTML_ENV');
        }
        $hostname = (string)$app->env->get('HOSTNAME', $app->env->get('database.hostname', ''));
        $password = (string)$app->env->get('PASSWORD', $app->env->get('database.password', ''));
        if ($hostname !== $marker && $dbHost !== $marker) {
            throw new \RuntimeException("TEMP_ENV_NOT_LOADED expected={$marker} hostname={$hostname}");
        }
        if ($password !== 'localdev123' && strpos($envBody, 'PASSWORD = localdev123') === false) {
            throw new \RuntimeException('TEMP_ENV_PASSWORD_NOT_LOADED');
        }
        echo "TEMP_ENV_PROVEN=1 marker={$marker} envFile={$envFile}\n";
        gatePass('ISO-3-02');
    }

    $ref = new ReflectionProperty($app, 'envName');
    $ref->setAccessible(true);
    $ref->setValue($app, 'c1a_skip_reload_dotenv');

    $app->initialize();
    if (class_exists(\think\facade\Config::class)) {
        \think\facade\Config::set(['default' => 'file'], 'cache');
    } else {
        $app->config->set(['default' => 'file'], 'cache');
    }
    c1aForceTestDatabase();
    return $app;
}

/**
 * 为 RootProjector 就绪测试注册全部必需分区 stub（仅测试用）。
 */
function c1aRegisterReadyPartitionStubs(\app\services\cashier\v3\projection\CashierV3RootDomainAssembler $assembler): void
{
    foreach (\app\services\cashier\v3\projection\CashierV3RootDomainAssembler::REQUIRED_BUSINESS_PARTITIONS as $key) {
        $assembler->registerPartitionProvider(new class($key) implements \app\services\cashier\v3\projection\CashierV3RootPartitionProvider {
            private $key;
            public function __construct(string $key) { $this->key = $key; }
            public function partitionKey(): string { return $this->key; }
            public function readPartition(
                string $stateContextId,
                string $stateRevision,
                \app\services\cashier\v3\CashierV3OperatorScope $operatorScope,
                \app\services\cashier\v3\CashierV3DataScopeContext $dataScope,
                array $hints = []
            ): array {
                if ($this->key === 'pendingHangCount') {
                    return ['ready' => true, 'payload' => 0];
                }
                if (in_array($this->key, ['serviceCompletion'], true)) {
                    return ['ready' => true, 'payload' => [
                        'serviceOrder' => null, 'lines' => [], 'commandContexts' => [],
                    ]];
                }
                if (in_array($this->key, ['memberCenter'], true)) {
                    return ['ready' => true, 'payload' => [
                        'canBatchOperate' => false, 'records' => [], 'detail' => null,
                    ]];
                }
                if (in_array($this->key, ['memberSelector'], true)) {
                    return ['ready' => true, 'payload' => [
                        'records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false,
                    ]];
                }
                if ($this->key === 'queryEntitySelector') {
                    $page = ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false];
                    return ['ready' => true, 'payload' => [
                        'person' => $page, 'store' => $page, 'organization' => $page,
                    ]];
                }
                if ($this->key === 'managementCenter') {
                    return ['ready' => true, 'payload' => ['entries' => []]];
                }
                if ($this->key === 'hangOrders') {
                    return ['ready' => true, 'payload' => ['statusOptions' => [], 'records' => []]];
                }
                if ($this->key === 'orderCenter') {
                    return ['ready' => true, 'payload' => [
                        'businessTypes' => [], 'salesOrders' => [], 'salesOrderDetail' => null,
                    ]];
                }
                return ['ready' => true, 'payload' => []];
            }
        });
    }
}

function c1aTestsRoot(): string
{
    return dirname(__DIR__);
}

function c1aRepoRoot(): string
{
    return dirname(dirname(c1aTestsRoot()));
}

function c1aBackendRoot(): string
{
    return c1aRepoRoot() . '/后端代码';
}
