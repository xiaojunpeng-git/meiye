<?php
namespace app\model\organization;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class OrganizationStore extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'organization_store';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

    public function searchOrgIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('org_id', $value);
        }
    }

    public function searchStoreIdAttr($query, $value)
    {
        if ($value !== '') {
            if (is_array($value)) {
                $query->whereIn('store_id', $value);
            } else {
                $query->where('store_id', $value);
            }
        }
    }
}
