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
declare (strict_types=1);

namespace app\dao\activity\lottery;

use app\dao\BaseDao;
use app\model\activity\lottery\LuckLotteryRecord;

/**
 * 中奖记录
 * Class LuckLotteryRecordDao
 * @package app\dao\activity\lottery
 */
class LuckLotteryRecordDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return LuckLotteryRecord::class;
    }

    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['factor']) && $where['factor'], function ($query) use ($where) {
            $query->where('lottery_id', 'in', function ($que) use ($where) {
                $que->name('luck_lottery')->where('factor',$where['factor'])->field(['id'])->select();
            });
        });
    }

    /**
     * 获取列表
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
    public function getList(array $where, $field = '*', array $with = [], int $page = 0, int $limit = 10)
    {
        return $this->search($where)->when($with, function ($query) use ($with) {
            $query->with($with);
        })->field($field)->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('add_time desc')->select()->toArray();
    }

    /**
     * @param array $where
     * @param string $group
     * @return int
     */
    public function getCount(array $where, string $group = '')
    {
        $add_time = 0;
        if (isset($where['add_time']) && $where['add_time']) {
            $add_time = $where['add_time'];
            unset($where['add_time']);
        }
        return $this->search($where)->when($add_time > 0, function ($query) use ($where, $add_time) {
            $query->where('add_time', '>=', $add_time);
        })->when($group, function ($query) use ($group) {
            $query->group($group);
        })->count();
    }
}
