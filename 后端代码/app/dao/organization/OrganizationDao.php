<?php
namespace app\dao\organization;

use app\dao\BaseDao;
use app\model\organization\Organization;

class OrganizationDao extends BaseDao
{
    protected function setModel(): string
    {
        return Organization::class;
    }

    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0): array
    {
        return $this->search($where)->field($field)
            ->when($page && $limit, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })
            ->order('sort desc,id asc')
            ->select()->toArray();
    }
}
