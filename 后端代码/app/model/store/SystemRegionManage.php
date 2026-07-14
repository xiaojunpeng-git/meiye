<?php
namespace app\model\store;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 区域架构
 */
class SystemRegionManage extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'system_region_manage';

    public function searchPidAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('pid', $value);
        }
    }

    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('is_del', $value);
        }
    }

    public function searchKeywordAttr($query, $value)
    {
        if ($value !== '') {
            $query->whereLike('id|name', '%' . trim($value) . '%');
        }
    }
}
