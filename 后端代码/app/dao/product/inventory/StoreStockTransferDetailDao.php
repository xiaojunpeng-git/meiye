<?php
declare(strict_types=1);

namespace app\dao\product\inventory;

use app\dao\BaseDao;
use app\model\product\inventory\StoreStockTransferDetail;

class StoreStockTransferDetailDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreStockTransferDetail::class;
    }

    public function getByTransferId(int $transferId): array
    {
        return $this->search(['transfer_id' => $transferId])->order('id asc')->select()->toArray();
    }

    public function deleteByTransferId(int $transferId): void
    {
        $this->getModel()->where('transfer_id', $transferId)->delete();
    }

    public function lockByTransferId(int $transferId): array
    {
        return $this->getModel()->where('transfer_id', $transferId)->lock(true)->order('id asc')->select()->toArray();
    }
}
