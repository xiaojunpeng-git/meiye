<?php
declare(strict_types=1);
namespace app\jobs\user;

use app\services\user\UserStoreTransferBatchServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;
use think\exception\ValidateException;

/** 每个队列消息只处理一个有限批次；数据库状态负责幂等，页面关闭不影响后台继续执行。 */
class UserStoreTransferBatchJob extends BaseJobs
{
    use QueueTrait;

    protected static function queueName()
    {
        return (string)config('queue.connections.' . config('queue.default') . '.batch_queue');
    }

    public function doJob(string $batchNo): bool
    {
        if (!preg_match('/^BST-[a-f0-9]{32}$/D', $batchNo)) return true;
        $service = new UserStoreTransferBatchServices();
        try {
            if ($service->processChunk($batchNo)) $service->enqueue($batchNo);
        } catch (\Throwable $error) {
            // BaseJobs会吞掉异常并删除消息，因此必须先持久化受阻状态，不能静默丢失任务。
            Log::error('member_store_transfer ' . $batchNo . ': ' . $error->getMessage());
            $reason = $error instanceof ValidateException ? $error->getMessage() : '请继续执行；若仍失败，请联系管理员核对该批次';
            $service->block($batchNo, '本批执行失败，当前批已回滚。' . $reason);
        }
        return true;
    }
}
