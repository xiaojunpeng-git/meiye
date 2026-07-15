<?php
declare(strict_types=1);

namespace app\model\product\inventory;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 项目耗材配方明细（院装）
 */
class StoreProjectConsumableRecipeDetail extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_project_consumable_recipe_detail';
}
