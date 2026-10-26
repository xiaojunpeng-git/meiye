<?php

namespace app\dao\position;

use app\dao\BaseDao;
use app\model\position\Position;
use app\model\position\PositionYeji;

/**
 * 文章dao
 * Class ArticleDao
 * @package app\dao\article
 */
class PositionYejiDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return PositionYeji::class;
    }

    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['position_id']) && !empty($where['position_id']), function ($query) use ($where) {
            $query->where('position_id',$where['position_id']);
        })->when(isset($where['name']) && !empty($where['name']), function ($query) use ($where) {
            $query->where('name','like','%'.$where['name']."%");
        });
    }

    public function getList(array $where, int $page = 0, int $limit = 0, string $order = '', array $with = [])
    {
        return $this->search($where)->order(($order ? $order . ' ,' : '') . 'id desc')
            ->when(count($with), function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select()->toArray();
    }
}
