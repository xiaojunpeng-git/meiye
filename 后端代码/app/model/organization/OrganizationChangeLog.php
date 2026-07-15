<?php
namespace app\model\organization;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class OrganizationChangeLog extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'organization_change_log';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

    public function searchOrgIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('org_id', $value);
        }
    }
}
