<?php
namespace app\dao\employee;

use app\dao\BaseDao;
use app\model\employee\EmployeeChangeLog;

class EmployeeChangeLogDao extends BaseDao
{
    protected function setModel(): string
    {
        return EmployeeChangeLog::class;
    }
}
