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

namespace app\dao\user;

use app\dao\BaseDao;
use app\model\user\UserSign;

/**
 *
 * Class UserSignDao
 * @package app\dao\user
 */
class UserSignDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return UserSign::class;
    }

    /**
     * 获取本周、本月、某月的签到列表
     * @param $type
     * @param $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserSignList($type, $uid, $time): array
    {
        return $this->getModel()->where('uid', $uid)->where(function ($query) use ($type,$time) {
            if ($type == 1) {
                $query->whereWeek('add_time');
            } else {
                $query->whereMonth('add_time', $time);
            }
        })->order('id asc')->select()->toArray();
    }

    /**
     * 获取累积签到次数
     * @param $uid
     * @return int
     * @throws \think\db\exception\DbException
     */
    public function getCumulativeDays($uid)
    {
        return $this->getModel()->where('uid', $uid)->count();
    }

    /**
     * 获取列表
     * @param array $where
     * @param string $field
     * @param int $page
     * @param int $limit
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, string $field, int $page, int $limit)
    {
        return $this->search($where)->field($field)->order('id desc')->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->select()->toArray();
    }

    /**
     * 获取列表
     * @param array $where
     * @param string $field
     * @param int $page
     * @param int $limit
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getListGroup(array $where, string $field, int $page, int $limit, string $group)
    {
        return $this->search($where)->field($field)->order('id desc')->group($group)->page($page, $limit)->select()->toArray();
    }
}
