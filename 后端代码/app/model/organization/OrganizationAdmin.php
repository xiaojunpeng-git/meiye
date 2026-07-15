<?php
namespace app\model\organization;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class OrganizationAdmin extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'organization_admin';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

    public function searchOrgIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('org_id', $value);
        }
    }

    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('is_del', $value);
        }
    }

    public function searchLegacyAgentIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('legacy_agent_id', $value);
        }
    }

    public function searchUidAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('uid', $value);
        }
    }

    public function searchPhoneAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('phone', $value);
        }
    }
}
