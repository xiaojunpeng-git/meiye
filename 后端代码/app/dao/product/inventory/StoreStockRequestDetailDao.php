<?php
declare(strict_types=1);

namespace app\dao\product\inventory;

use app\dao\BaseDao;
use app\model\product\inventory\StoreStockRequestDetail;

class StoreStockRequestDetailDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreStockRequestDetail::class;
    }

    public function getByRequestId(int $requestId): array
    {
        return $this->search(['request_id' => $requestId])->order('id asc')->select()->toArray();
    }

    public function deleteByRequestId(int $requestId): void
    {
        $this->getModel()->where('request_id', $requestId)->delete();
    }
}
