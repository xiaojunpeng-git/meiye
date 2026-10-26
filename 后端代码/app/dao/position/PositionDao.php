<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\dao\position;

use app\dao\BaseDao;
use app\model\position\Position;

/**
 * 文章dao
 * Class ArticleDao
 * @package app\dao\article
 */
class PositionDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return Position::class;
    }

    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['name']) && !empty($where['name']), function ($query) use ($where) {
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
