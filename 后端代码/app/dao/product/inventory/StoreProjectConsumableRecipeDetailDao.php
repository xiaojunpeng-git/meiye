<?php
declare(strict_types=1);

namespace app\dao\product\inventory;

use app\dao\BaseDao;
use app\model\product\inventory\StoreProjectConsumableRecipeDetail;

class StoreProjectConsumableRecipeDetailDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreProjectConsumableRecipeDetail::class;
    }

    public function getByRecipeId(int $recipeId): array
    {
        return $this->search(['recipe_id' => $recipeId])->order('id asc')->select()->toArray();
    }

    public function deleteByRecipeId(int $recipeId): void
    {
        $this->getModel()->where('recipe_id', $recipeId)->delete();
    }
}
