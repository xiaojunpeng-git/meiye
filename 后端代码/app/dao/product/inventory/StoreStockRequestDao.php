<?php
declare(strict_types=1);

namespace app\dao\product\inventory;

use app\dao\BaseDao;
use app\model\product\inventory\StoreStockRequest;

class StoreStockRequestDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreStockRequest::class;
    }

    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0, array $with = [], string $order = 'id desc'): array
    {
        return $this->search($where)->field($field)
            ->when($with, function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->order($order)->select()->toArray();
    }

    public function lockById(int $id): array
    {
        $row = $this->getModel()->where('id', $id)->lock(true)->find();
        return $row ? $row->toArray() : [];
    }
}
