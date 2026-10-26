<?php
namespace app\dao\target;

use app\dao\BaseDao;
use app\model\target\StoreTarget;

/**
 * 门店目标 Dao
 * Class StoreTargetDao
 * @package app\dao\target
 */
class StoreTargetDao extends BaseDao
{
    /**
     * @return string
     */
    protected function setModel(): string
    {
        return StoreTarget::class;
    }

    /**
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)
            ->where('is_del', 0)
            ->when(isset($where['store_id']) && $where['store_id'] !== '', function ($query) use ($where) {
                if (is_array($where['store_id'])) {
                    $ids = array_filter(array_map('intval', $where['store_id']));
                    if ($ids) {
                        $query->whereIn('store_id', $ids);
                    }
                } else {
                    $query->where('store_id', (int)$where['store_id']);
                }
            })
            ->when(!empty($where['store_ids']) && is_array($where['store_ids']), function ($query) use ($where) {
                $ids = array_filter(array_map('intval', $where['store_ids']));
                if ($ids) {
                    $query->whereIn('store_id', $ids);
                }
            })
            ->when(isset($where['year']) && $where['year'] !== '', function ($query) use ($where) {
                $query->where('year', (int)$where['year']);
            })
            ->when(isset($where['object_type']) && $where['object_type'] !== '', function ($query) use ($where) {
                $objectType = (int)$where['object_type'];
                // 2=全部门店：展示区域内各门店目标，不按 object_type=2 过滤
                if ($objectType !== 2) {
                    $query->where('object_type', $objectType);
                }
            })
            ->when(isset($where['status']) && $where['status'] !== '', function ($query) use ($where) {
                $query->where('status', (int)$where['status']);
            })
            ->when(isset($where['keyword']) && $where['keyword'] !== '', function ($query) use ($where) {
                $query->whereLike('name|object_name', '%' . $where['keyword'] . '%');
            });
    }

    /**
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param string $order
     * @return array
     */
    public function getList(array $where, int $page = 0, int $limit = 0, string $order = 'id desc'): array
    {
        $result = $this->search($where)->order($order)
            ->when($page > 0 && $limit > 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select();
        if (empty($result)) {
            return [];
        }
        return $result->toArray();
    }
}
