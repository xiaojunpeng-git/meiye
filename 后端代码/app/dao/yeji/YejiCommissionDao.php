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
namespace app\dao\yeji;

use app\dao\BaseDao;
use app\model\yeji\YejiCommission;

/**
 * 文章dao
 * Class ArticleDao
 * @package app\dao\article
 */
class YejiCommissionDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return YejiCommission::class;
    }

    public function search(array $where = [])
    {
        return parent::search($where)->field("a.*,b.store_name")
            ->alias('a')
            ->join('StoreProduct b', 'b.id = a.product_id','left')
            ->where("b.pid",0)
            ->when(isset($where['keyword']) && $where['keyword'], function ($query) use ($where) {
                $query->whereLike('b.store_name|b.id', '%' . $where['keyword'] . '%');
        });
    }

    public function getList(array $where, int $page = 0, int $limit = 0, string $order = '', array $with = [])
    {
        return $this->search($where)->order('a.id desc')
            ->when(count($with), function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select()->toArray();
    }
}
