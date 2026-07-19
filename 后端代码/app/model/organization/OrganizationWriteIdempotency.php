<?php
namespace app\model\organization;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class OrganizationWriteIdempotency extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'organization_write_idempotency';
    protected $autoWriteTimestamp = false;
}
