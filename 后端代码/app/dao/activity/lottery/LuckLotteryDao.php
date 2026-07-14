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
use app\model\activity\lottery\LuckLottery;

/**
 * 抽奖活动
 * Class LuckLotteryDao
 * @package app\dao\activity\lottery
 */
class LuckLotteryDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return LuckLottery::class;
    }

    public function search(array $data = [])
    {
        return parent::search($data)->when(isset($data['id']) && $data['id'], function ($query) use ($data) {
            $query->where('id', $data['id']);
        })->when(isset($data['start']) && $data['start'] !== '', function ($query) use ($data) {
            $time = time();
            switch ($data['start']) {
                case 0:
                    $query->where('start_time', '>', $time)->where('status', 1);
                    break;
                case -1:
                    $query->where(function ($query1) use ($time) {
                        $query1->where('end_time', '<', $time)->whereOr('status', 0);
                    });
                    break;
                case 1:
                    $query->where('status', 1)->where(function ($query1) use ($time) {
                        $query1->where(function ($query2) use ($time) {
                            $query2->where('start_time', '<=', $time)->where('end_time', '>=', $time);
                        })->whereOr(function ($query3) {
                            $query3->where('start_time', 0)->where('end_time', 0);
                        });
                    });
                    break;
            }
        })->when(isset($data['time_ranges']) && $data['time_ranges'] !== '', function ($query) use ($data) {
            $time_ranges = explode('-', $data['time_ranges']);
            $query->where(function ($query) use ($time_ranges) {
                $time_range1 = strtotime($time_ranges[0]);
                $time_range2 = strtotime($time_ranges[1]);
                $query->where(function ($query1) use ($time_range1) {
                    $query1->where('start_time', '<=', $time_range1)->where('end_time', '>=',$time_range1);
                })->whereOr(function ($query2) use ($time_range2) {
                    $query2->where('start_time', '<=', $time_range2)->where('end_time', '>=', $time_range2);
                });
            });
        });
    }

    /**
     * 抽奖活动列表
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
    public function getList(array $where, string $field = '*', array $with = [], int $page = 0, int $limit = 0)
    {
        return $this->search($where)->field($field)->when($with, function ($query) use ($with) {
            $query->with($with);
        })->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->with(['records' => function ($query) {
            $query->field([
                'lottery_id',
                'COUNT(DISTINCT uid) AS total_user',      // 总参与人数
                'COUNT(DISTINCT CASE WHEN type != 1 THEN uid END) AS wins_user', // 中奖人数
                'COUNT(*) AS total_num',                    // 总参与次数
                'SUM(type != 1) AS wins_num',                  // 中奖次数
            ])->group('lottery_id');
        }])->order('add_time desc')->select()->toArray();
    }

    /**
     * 获取单个活动
     * @param int $id
     * @param string $field
     * @param array|string[] $with
     * @param bool $is_doing
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getLottery(int $id, string $field = '*', array $with = ['prize'], bool $is_doing = false)
    {
        $where = ['id' => $id];
        $where['is_del'] = 0;
        if ($is_doing) $where['start'] = 1;
        return $this->search($where)->field($field)->when($with, function ($query) use ($with) {
            $query->with($with);
        })->find();
    }

    /**
     * 获取某个抽奖类型的一条抽奖数据
     * @param int $factor
     * @param string $field
     * @param array|string[] $with
     * @param bool $is_doing
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getFactorLottery(int $factor = 1, string $field = '*', array $with = ['prize'], bool $is_doing = true)
    {
        switch ($factor) {
            case 1:
                $id = sys_config('points_lottery', 0);
                break;
            case 3:
                $id = sys_config('order_pay_lottery', 0);
                break;
            case 4:
                $id = sys_config('order_evaluate_lottery', 0);
                break;
            case 5:
                $id = sys_config('follow_lottery', 0);
                break;
            default:
                $id = 0;
                break;
        }
        $where = ['id' => $id, 'is_del' => 0];
        if ($is_doing) $where['start'] = 1;
        return $this->search($where)->field($field)->when($with, function ($query) use ($with) {
            $query->with($with);
        })->order('id desc')->find();
    }
}
