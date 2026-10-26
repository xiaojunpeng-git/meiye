<?php
declare(strict_types=1);

namespace app\dao\order;

use app\dao\BaseDao;
use app\model\order\StoreOrderTerminalOperation;

class StoreOrderTerminalOperationDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreOrderTerminalOperation::class;
    }

    public function getByStoreOrderId(int $storeOrderId, bool $lock = false): ?array
    {
        $query = \think\facade\Db::name('store_order_terminal_operation')->where('store_order_id', $storeOrderId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    public function getByOperationNo(string $operationNo, bool $lock = false): ?array
    {
        $query = \think\facade\Db::name('store_order_terminal_operation')->where('operation_no', $operationNo);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    public function getByRequestToken(string $requestToken, bool $lock = false): ?array
    {
        if ($requestToken === '') {
            return null;
        }
        $query = \think\facade\Db::name('store_order_terminal_operation')->where('request_token', $requestToken);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    public function getLocked(int $id): ?array
    {
        $row = \think\facade\Db::name('store_order_terminal_operation')->where('id', $id)->lock(true)->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    /**
     * 带原状态条件的 CAS 更新；返回影响行数（0=未抢到执行权）
     */
    public function casUpdateByState(int $id, int $expectState, array $data): int
    {
        return (int)\think\facade\Db::name('store_order_terminal_operation')
            ->where('id', $id)
            ->where('state', $expectState)
            ->update($data);
    }

    /**
     * 状态 + owner + epoch 三重 CAS（A2-R1 fencing）
     */
    public function casUpdateByStateAndFence(
        int $id,
        int $expectState,
        string $owner,
        int $epoch,
        array $data
    ): int {
        if ($owner === '' || $epoch <= 0) {
            return 0;
        }
        return (int)\think\facade\Db::name('store_order_terminal_operation')
            ->where('id', $id)
            ->where('state', $expectState)
            ->where('execution_owner', $owner)
            ->where('execution_epoch', $epoch)
            ->update($data);
    }

    public function casUpdateByFence(int $id, string $owner, int $epoch, array $data): int
    {
        if ($owner === '' || $epoch <= 0) {
            return 0;
        }
        return (int)\think\facade\Db::name('store_order_terminal_operation')
            ->where('id', $id)
            ->where('execution_owner', $owner)
            ->where('execution_epoch', $epoch)
            ->update($data);
    }
}
