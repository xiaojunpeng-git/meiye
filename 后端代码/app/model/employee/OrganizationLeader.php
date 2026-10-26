<?php
namespace app\model\employee;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class OrganizationLeader extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'organization_leader';
    protected $autoWriteTimestamp = false;
}
