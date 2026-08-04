<?php
/**
 * 本地恢复库的收银 V3 真人验收命令通道。
 *
 * 只调用生产 CashierV3ActionDispatcher；不写 SQL 伪造订单、收款、权益、事件或事实。
 * 使用前须由调用方传入完整的 V3 请求体（JSON/base64 JSON）。
 */

declare(strict_types=1);

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use think\facade\Db;

const LOCAL_QA_DATABASE = 'ruihao_test_recovered_20260801';
const LOCAL_QA_STORE_ID = 179;
const LOCAL_QA_OPERATOR_ID = 399;

function localQaFail(string $message, int $status = 1): void
{
    fwrite(STDERR, "LOCAL_QA_FAIL={$message}\n");
    exit($status);
}

if ($argc !== 2) {
    localQaFail('usage: local-cashier-v3-command.php <base64-json-request>');
}

$raw = base64_decode((string)$argv[1], true);
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    localQaFail('request_json_invalid');
}

$backendRoot = is_file('/var/www/html/vendor/autoload.php')
    ? '/var/www/html/'
    : dirname(__DIR__) . '/后端代码/';
require $backendRoot . 'vendor/autoload.php';
$app = new \think\App($backendRoot);
$app->env->load($backendRoot . '.env');
$app->initialize();

$database = (string)(Db::query('SELECT DATABASE() AS name')[0]['name'] ?? '');
if ($database !== LOCAL_QA_DATABASE) {
    localQaFail('database_guard:' . $database);
}

$operator = Db::name('system_store_staff')
    ->where('id', LOCAL_QA_OPERATOR_ID)
    ->where('store_id', LOCAL_QA_STORE_ID)
    ->where('status', 1)
    ->where('is_del', 0)
    ->find();
if (!$operator || (int)($operator['level'] ?? -1) !== 0) {
    localQaFail('operator_guard');
}

$roles = array_values(array_filter(array_map('intval', explode(',', (string)($operator['roles'] ?? '')))));
$clientSessionId = trim((string)($body['clientSessionId'] ?? ''));
if ($clientSessionId === '') {
    localQaFail('client_session_required');
}

$session = [
    'store_id' => LOCAL_QA_STORE_ID,
    'operator_id' => LOCAL_QA_OPERATOR_ID,
    'operator_ip' => '127.0.0.1',
    'client_session_id' => $clientSessionId,
    'state_context_id' => (string)($body['stateContextId'] ?? ''),
    'operator_profile' => [
        'id' => LOCAL_QA_OPERATOR_ID,
        'level' => (int)$operator['level'],
        'roles' => $roles,
        'employee_id' => (int)($operator['employee_id'] ?? 0),
        'account' => (string)($operator['account'] ?? ''),
    ],
];

try {
    $result = CashierV3Bootstrap::dispatcher()->dispatch($body, $session);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (CashierV3CommandException $exception) {
    echo json_encode([
        'result' => [
            'status' => 'failed',
            'code' => $exception->getResultCode(),
            'message' => $exception->getMessage(),
        ],
        'detail' => $exception->getDetail(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
