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
