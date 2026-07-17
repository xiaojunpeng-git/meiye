<?php
declare(strict_types=1);

namespace app\model\product\inventory;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 项目耗材配方（院装）
 */
class StoreProjectConsumableRecipe extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_project_consumable_recipe';

    /** add_time/update_time 为整型时间戳，禁止 ORM 按日期字符串格式化（否则 PHP7.4 DateTime 报错） */
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

    public function details()
    {
        return $this->hasMany(StoreProjectConsumableRecipeDetail::class, 'recipe_id', 'id');
    }

    public function searchTypeAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('type', (int)$value);
        }
    }

    public function searchRelationIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('relation_id', (int)$value);
        }
    }

    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', (int)$value);
        }
    }

    public function searchProjectProductIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('project_product_id', (int)$value);
        }
    }
}
