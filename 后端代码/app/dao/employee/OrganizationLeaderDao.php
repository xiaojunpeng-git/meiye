<?php
namespace app\dao\employee;

use app\dao\BaseDao;
use app\model\employee\OrganizationLeader;

class OrganizationLeaderDao extends BaseDao
{
    protected function setModel(): string
    {
        return OrganizationLeader::class;
    }
}
