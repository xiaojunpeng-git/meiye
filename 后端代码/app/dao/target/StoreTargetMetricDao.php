<?php
namespace app\dao\target;

use app\dao\BaseDao;
use app\model\target\StoreTargetMetric;

/**
 * 门店目标指标 Dao
 * Class StoreTargetMetricDao
 * @package app\dao\target
 */
class StoreTargetMetricDao extends BaseDao
{
    /**
     * @return string
     */
    protected function setModel(): string
    {
        return StoreTargetMetric::class;
    }

    /**
     * @param int $targetId
     * @return array
     */
    public function getByTargetId(int $targetId): array
    {
        $list = $this->getModel()->where('target_id', $targetId)->order('sort asc,id asc')->select();
        return $list ? $list->toArray() : [];
    }

    /**
     * @param int $targetId
     * @return bool
     */
    public function deleteByTargetId(int $targetId): bool
    {
        return (bool)$this->getModel()->where('target_id', $targetId)->delete();
    }
}
