<?php
namespace app\model\organization;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class Organization extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'organization';
    /** add_time/update_time 为 unix 整型，关闭 ORM 时间戳格式化避免 DateTime 报错 */
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

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
