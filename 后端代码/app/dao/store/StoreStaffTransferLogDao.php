<?php
namespace app\dao\store;

use app\dao\BaseDao;
use app\model\store\StoreStaffTransferLog;

class StoreStaffTransferLogDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreStaffTransferLog::class;
    }

    public function getList(array $where, int $page = 0, int $limit = 0, string $order = 'id desc'): array
    {
        $query = $this->search($where)->order($order);
        $count = (int)(clone $query)->count();
        if ($page && $limit) {
            $query->page($page, $limit);
        }
        $list = $query->select()->toArray();
        return compact('list', 'count');
    }
}
