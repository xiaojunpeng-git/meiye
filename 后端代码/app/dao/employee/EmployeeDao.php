<?php
namespace app\dao\employee;

use app\dao\BaseDao;
use app\model\employee\Employee;

class EmployeeDao extends BaseDao
{
    protected function setModel(): string
    {
        return Employee::class;
    }
}
