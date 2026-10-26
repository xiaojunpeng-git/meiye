<?php
namespace app\dao\organization;

use app\dao\BaseDao;
use app\model\organization\OrganizationStore;

class OrganizationStoreDao extends BaseDao
{
    protected function setModel(): string
    {
        return OrganizationStore::class;
    }

    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0): array
    {
        return $this->search($where)->field($field)
            ->when($page && $limit, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })
            ->order('id asc')
            ->select()->toArray();
    }
}
