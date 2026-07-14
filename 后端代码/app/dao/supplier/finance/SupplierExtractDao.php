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

namespace app\dao\supplier\finance;


use app\dao\BaseDao;
use app\model\supplier\finance\SupplierExtract;

/**
 * 门店提现
 * Class StoreExtractDao
 * @package app\dao\supplier\finance
 */
class SupplierExtractDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SupplierExtract::class;
    }


    /**
     * 获取提现列表
     * @param array $where
     * @param string $field
     * @param array $with
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getExtractList(array $where, string $field = '*', array $with = [], int $page = 0, int $limit = 0)
    {
        return $this->search($where)->field($field)->when($with, function ($query) use ($with) {
            $query->with($with);
        })->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('id desc')->select()->toArray();
    }

    /**
     * @param array $where
     * @param string $field
     * @return float
     */
    public function getExtractMoneyByWhere(array $where, string $field)
    {
        return $this->search($where)->sum($field);
    }
}
