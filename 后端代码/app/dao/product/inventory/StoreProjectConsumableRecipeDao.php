<?php
declare(strict_types=1);

namespace app\dao\product\inventory;

use app\dao\BaseDao;
use app\model\product\inventory\StoreProjectConsumableRecipe;

class StoreProjectConsumableRecipeDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreProjectConsumableRecipe::class;
    }

    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0, string $order = 'id desc'): array
    {
        return $this->search($where)->field($field)
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->order($order)->select()->toArray();
    }

    public function lockById(int $id): array
    {
        $row = $this->getModel()->where('id', $id)->lock(true)->find();
        return $row ? $row->toArray() : [];
    }

    /**
     * 按归属+项目SKU查配方（唯一定位）
     */
    public function findByOwnerProject(int $type, int $relationId, int $projectProductId, string $projectUnique): array
    {
        $row = $this->getModel()
            ->where('type', $type)
            ->where('relation_id', $relationId)
            ->where('project_product_id', $projectProductId)
            ->where('project_unique', $projectUnique)
            ->find();
        return $row ? $row->toArray() : [];
    }
}
