<?php
namespace app\dao\store;

use app\dao\BaseDao;
use app\model\store\SystemRegionManage;

class SystemRegionManageDao extends BaseDao
{
    protected function setModel(): string
    {
        return SystemRegionManage::class;
    }

    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0)
    {
        return $this->search($where)->field($field)
            ->when($page && $limit, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })
            ->order('sort desc,id asc')
            ->select()->toArray();
    }
}
