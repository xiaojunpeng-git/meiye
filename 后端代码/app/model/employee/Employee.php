<?php
namespace app\model\employee;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class Employee extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'employee';
    protected $autoWriteTimestamp = false;
}
