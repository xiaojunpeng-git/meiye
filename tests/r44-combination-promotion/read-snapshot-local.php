<?php
declare(strict_types=1);

/**
 * 真实本地 MySQL 双连接验证报表读快照。只写本脚本拥有的无订单关联测试归属行，
 * 所有提交限于该测试行；finally 按主键和随机所有权标记精确删除，不修改现有数据。
 */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

use think\facade\Db;
use app\services\activity\combination\CombinationPromotionServices;

$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('Local mysql/ruihao only');
}
$checks = 0;
function snapshotCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
    echo 'PASS ' . $message . "\n";
}

$connection = Db::connect();
$firstId = (int)$connection->query('SELECT CONNECTION_ID() AS id')[0]['id'];
$pdo = $connection->getPdo();
if (!$pdo || $pdo->inTransaction()) throw new RuntimeException('Test must own an idle local connection');
// 直接创建第二个PDO，不替换ThinkPHP默认连接，确保服务内Db调用始终使用第一个连接。
$writer = new PDO(
    'mysql:host=' . $config['hostname'] . ';port=' . ($config['hostport'] ?: '3306') . ';dbname=' . $config['database'] . ';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$secondId = (int)$writer->query('SELECT CONNECTION_ID()')->fetchColumn();
$table = ($config['prefix'] ?? 'eb_') . 'combination_promotion_order';
if (!preg_match('/^[a-z0-9_]+$/D', $table)) throw new RuntimeException('Invalid local table identifier');
$owner = 'r44-snapshot-' . bin2hex(random_bytes(10));
$orderId = 900000000000 + random_int(1, 999999999);
$created = false;
$service = new CombinationPromotionServices();
$readSnapshot = new ReflectionMethod($service, 'readSnapshot');
$readSnapshot->setAccessible(true);
$read = static function () use ($orderId, $owner): string {
    return (string)Db::name('combination_promotion_order')->where('order_id', $orderId)->where('promoter_name', $owner)->value('activity_name');
};
$write = static function (string $value) use ($writer, $table, $orderId, $owner): void {
    $statement = $writer->prepare('UPDATE `' . $table . '` SET activity_name=? WHERE order_id=? AND promoter_name=?');
    $statement->execute([$value, $orderId, $owner]);
    if ($statement->rowCount() !== 1) throw new RuntimeException('Owned fixture update did not affect exactly one row');
};
$externalRead = static function () use ($writer, $table, $orderId, $owner): string {
    $statement = $writer->prepare('SELECT activity_name FROM `' . $table . '` WHERE order_id=? AND promoter_name=?');
    $statement->execute([$orderId, $owner]);
    return (string)$statement->fetchColumn();
};

try {
    snapshotCheck($firstId !== $secondId, 'two independent real database connections');
    $insert = $writer->prepare('INSERT INTO `' . $table . '` (order_id,activity_id,promoter_name,activity_name,attribution_reason,created_at) VALUES (?,?,?,?,?,?)');
    $insert->execute([$orderId, 0, $owner, 'before', 'r44_snapshot', time()]);
    $created = true;
    $values = $readSnapshot->invoke($service, static function () use ($read, $write, $writer): array {
        $first = $read();
        $writer->beginTransaction();
        $write('committed-after-first-read');
        $writer->commit();
        return [$first, $read()];
    });
    snapshotCheck($values === ['before', 'before'], 'one report snapshot sees old value after concurrent commit');
    snapshotCheck(!$pdo->inTransaction(), 'successful read closes only its own transaction');
    snapshotCheck($externalRead() === 'committed-after-first-read', 'second connection committed its own update');
    $next = $readSnapshot->invoke($service, static function () use ($read): array { return [$read()]; });
    snapshotCheck($next === ['committed-after-first-read'], 'next report snapshot sees committed new value');

    Db::name('combination_promotion_order')->where('order_id', $orderId)->where('promoter_name', $owner)->update(['activity_name' => 'after-success-write']);
    snapshotCheck($externalRead() === 'after-success-write', 'same connection can write after successful read');

    $caught = false;
    try {
        $readSnapshot->invoke($service, static function () use ($read, $write): array {
            $read();
            $write('committed-during-failed-read');
            throw new RuntimeException('owned-read-failure');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'owned-read-failure') throw $error;
        $caught = true;
    }
    snapshotCheck($caught && !$pdo->inTransaction(), 'read exception propagates and rolls back owned snapshot');
    snapshotCheck($read() === 'committed-during-failed-read', 'exception rollback leaves no stale snapshot on connection');
    Db::name('combination_promotion_order')->where('order_id', $orderId)->where('promoter_name', $owner)->update(['activity_name' => 'after-error-write']);
    snapshotCheck($externalRead() === 'after-error-write', 'same connection can write after failed read');

    $writeRejected = false;
    try {
        $readSnapshot->invoke($service, static function () use ($orderId, $owner): array {
            Db::name('combination_promotion_order')->where('order_id', $orderId)->where('promoter_name', $owner)->update(['activity_name' => 'forbidden']);
            return [];
        });
    } catch (Throwable $error) {
        if (stripos($error->getMessage(), 'read only') === false && stripos($error->getMessage(), 'read-only') === false) throw $error;
        $writeRejected = true;
    }
    snapshotCheck($writeRejected && !$pdo->inTransaction() && $externalRead() === 'after-error-write', 'read-only snapshot rejects writes without changing fixture');
    Db::name('combination_promotion_order')->where('order_id', $orderId)->where('promoter_name', $owner)->update(['activity_name' => 'after-rejection-write']);
    snapshotCheck($externalRead() === 'after-rejection-write', 'read-only rejection does not leak into later writes');

    // 调用方已有业务事务时，读辅助方法必须保留事务所有权，不能提交或回滚调用方写入。
    $connection->startTrans();
    Db::name('combination_promotion_order')->where('order_id', $orderId)->where('promoter_name', $owner)->update(['activity_name' => 'caller-uncommitted']);
    $inside = $readSnapshot->invoke($service, static function () use ($read): array { return [$read()]; });
    snapshotCheck($inside === ['caller-uncommitted'] && $pdo->inTransaction(), 'nested read preserves caller transaction and own writes');
    snapshotCheck($externalRead() === 'after-rejection-write', 'nested read does not commit caller write');
    try {
        $readSnapshot->invoke($service, static function (): array { throw new RuntimeException('nested-read-failure'); });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'nested-read-failure') throw $error;
    }
    snapshotCheck($pdo->inTransaction() && $read() === 'caller-uncommitted', 'nested read exception does not roll back caller business transaction');
    $connection->rollback();
    snapshotCheck($read() === 'after-rejection-write', 'caller rollback restores only its uncommitted write');
} catch (Throwable $error) {
    $failed = true;
    echo 'FAIL ' . get_class($error) . ' ' . $error->getMessage() . "\n";
} finally {
    if ($pdo->inTransaction()) $connection->rollback();
    if ($writer->inTransaction()) $writer->rollBack();
    if ($created) {
        $cleanup = $writer->prepare('DELETE FROM `' . $table . '` WHERE order_id=? AND promoter_name=? AND attribution_reason=?');
        $cleanup->execute([$orderId, $owner, 'r44_snapshot']);
        snapshotCheck($cleanup->rowCount() === 1 && $externalRead() === '', 'owned committed fixture precisely removed');
    }
}
if (!empty($failed)) exit(1);
echo 'R44_READ_SNAPSHOT_CHECKS=' . $checks . "\n";
