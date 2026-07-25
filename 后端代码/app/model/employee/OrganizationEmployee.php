<?php
namespace app\model\employee;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class OrganizationEmployee extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'organization_employee';
    protected $autoWriteTimestamp = false;
}
