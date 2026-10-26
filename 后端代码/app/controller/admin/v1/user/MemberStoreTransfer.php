<?php
declare(strict_types=1);
namespace app\controller\admin\v1\user;

use app\controller\admin\AuthController;
use app\services\user\UserStoreTransferBatchServices;

/** 平台登录态下的所属会员批量转移；操作员身份只取服务端登录态。 */
class MemberStoreTransfer extends AuthController
{
    public function preview($id, UserStoreTransferBatchServices $service)
    {
        return $this->success($service->preview((int)$id, (int)$this->adminId));
    }

    public function create(UserStoreTransferBatchServices $service)
    {
        [$source, $target, $reason, $count, $key] = $this->request->postMore([
            ['source_store_id', 0], ['target_store_id', 0], ['reason', ''], ['expected_count', 0], ['request_key', ''],
        ], true);
        $batch = $service->create((int)$source, (int)$target, (string)$reason, (int)$count, (string)$key, (int)$this->adminId);
        $service->enqueue((string)$batch['batch_no']);
        return $this->success('转移批次已保存，请查看执行进度', $service->status((string)$batch['batch_no'], (int)$this->adminId));
    }

    public function status($batchNo, UserStoreTransferBatchServices $service)
    {
        return $this->success($service->status((string)$batchNo, (int)$this->adminId, (int)$this->request->get('page', 1)));
    }

    public function resume($batchNo, UserStoreTransferBatchServices $service)
    {
        return $this->success('已继续原转移批次', $service->resume((string)$batchNo, (int)$this->adminId));
    }
}
