<?php
namespace app\dao\target;

use app\dao\BaseDao;
use app\model\target\StoreTargetAllocate;

/**
 * 门店目标员工分配 Dao
 * Class StoreTargetAllocateDao
 * @package app\dao\target
 */
class StoreTargetAllocateDao extends BaseDao
{
    /**
     * @return string
     */
    protected function setModel(): string
    {
        return StoreTargetAllocate::class;
    }

    /**
     * @param int $targetId
     * @param string $refKey
     * @param int $allocateType
     * @return array
     */
    public function getByTargetAndRef(int $targetId, string $refKey, int $allocateType = 0): array
    {
        $query = $this->getModel()->where('target_id', $targetId)->where('ref_key', $refKey);
        if ($allocateType > 0) {
            $query->where('allocate_type', $allocateType);
        }
        $list = $query->order('id asc')->select();
        return $list ? $list->toArray() : [];
    }

    /**
     * @param int $targetId
     * @return array
     */
    public function getByTargetId(int $targetId): array
    {
        $list = $this->getModel()->where('target_id', $targetId)->order('id asc')->select();
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

    /**
     * @param int $targetId
     * @param string $refKey
     * @param int $allocateType
     * @return bool
     */
    public function deleteByTargetAndRef(int $targetId, string $refKey, int $allocateType = 0): bool
    {
        $query = $this->getModel()->where('target_id', $targetId)->where('ref_key', $refKey);
        if ($allocateType > 0) {
            $query->where('allocate_type', $allocateType);
        }
        return (bool)$query->delete();
    }
}
