<?php
namespace app\model\employee;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class EmployeeChangeLog extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'employee_change_log';
    protected $autoWriteTimestamp = false;
}
