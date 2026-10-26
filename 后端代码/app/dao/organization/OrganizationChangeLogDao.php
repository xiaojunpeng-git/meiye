<?php
namespace app\dao\organization;

use app\dao\BaseDao;
use app\model\organization\OrganizationChangeLog;

class OrganizationChangeLogDao extends BaseDao
{
    protected function setModel(): string
    {
        return OrganizationChangeLog::class;
    }

    public function getList(array $where, int $page = 1, int $limit = 20): array
    {
        $query = $this->search($where)->order('id desc');
        $count = (clone $query)->count();
        $list = $query->page($page, $limit)->select()->toArray();
        return compact('list', 'count', 'page', 'limit');
    }
}
