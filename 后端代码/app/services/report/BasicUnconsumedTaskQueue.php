<?php
declare(strict_types=1);

namespace app\services\report;

use app\jobs\report\BasicUnconsumedTaskJob;

/** 独立实例队列，消息只含不可猜的任务号，不携带会员或门店资料。 */
final class BasicUnconsumedTaskQueue
{
    public static function name(): string
    {
        // 队列按本实例数据库身份隔离，不依赖 AI 是否启用，也不把数据库名
        // 明文暴露到 Redis 队列键；消费者必须计算同一完整名称。
        $db = (array)config('database.connections.mysql', []);
        $identity = [(string)($db['hostname'] ?? ''), (string)($db['hostport'] ?? ''),
            (string)($db['database'] ?? ''), (string)($db['prefix'] ?? '')];
        if ($identity[0] === '' || $identity[2] === '') throw new \RuntimeException('实例数据库身份未配置');
        return 'MOHE_PRO_BASIC_UNCONSUMED:' . substr(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), 0, 32);
    }

    public static function push(string $taskId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $taskId)) throw new \InvalidArgumentException('报表任务编号无效');
        if (config('queue.connections.unconsumed_redis.type') !== 'redis') {
            throw new \RuntimeException('报表队列未配置');
        }
        $result = \think\facade\Queue::connection('unconsumed_redis')->push(BasicUnconsumedTaskJob::class,
            ['do' => 'doJob', 'data' => [$taskId], 'errorCount' => 1], self::name());
        if (!$result) throw new \RuntimeException('报表任务投递失败');
    }
}
