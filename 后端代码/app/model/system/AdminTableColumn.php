<?php
namespace app\model\system;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 后台/门店表格列配置
 */
class AdminTableColumn extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'admin_table_column';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = 'update_time';

    public function searchAdminTypeAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('admin_type', (int)$value);
        }
    }

    public function searchAdminIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('admin_id', (int)$value);
        }
    }

    public function searchTableKeyAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('table_key', (string)$value);
        }
    }
}
