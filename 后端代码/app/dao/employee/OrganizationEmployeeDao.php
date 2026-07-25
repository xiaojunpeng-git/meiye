<?php
namespace app\dao\employee;

use app\dao\BaseDao;
use app\model\employee\OrganizationEmployee;

class OrganizationEmployeeDao extends BaseDao
{
    protected function setModel(): string
    {
        return OrganizationEmployee::class;
    }
}
