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
use app\model\user\UserCard;

/**
 * 用户领取卡券
 * Class UserCardDao
 * @package app\dao\user
 */
class UserCardDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return UserCard::class;
    }

    /**
     * 获取激活会员卡列表
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
    public function getList(array $where, string $field = '*', array $with = [], int $page = 0, $limit = 0)
    {
        return $this->search($where)->field($field)
            ->when($with, function ($query) use ($with) {
                $query->with($with);
            })->when($page && $limit, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->order('add_time desc,id desc')->select()->toArray();
    }

	/**
	 * 获取一段时间内推广人数
	 * @param array $where
	 * @param array $time
	 * @param string $timeType
	 * @param string $countField
	 * @param string $groupField
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function cardTimeList(array $where, array $time, string $timeType = "week", string $countField = '*', string $groupField = 'add_time')
	{
		return $this->getModel()->where($where)
			->where($groupField, 'between time', $time)
			->when($timeType, function ($query) use ($timeType, $countField, $groupField) {
				switch ($timeType) {
					case "hour":
						$timeUnix = "%H";
						break;
					case "day" :
						$timeUnix = "%Y-%m-%d";
						break;
					case "week" :
						$timeUnix = "%w";
						break;
					case "month" :
						$timeUnix = "%d";
						break;
					case "weekly" :
						$timeUnix = "%W";
						break;
					case "year" :
						$timeUnix = "%Y-%m";
						break;
					default:
						$timeUnix = "%Y-%m-%d";
						break;
				}
				$query->field("FROM_UNIXTIME(`" . $groupField . "`,'$timeUnix') as day,count(" . $countField . ") as count");
				$query->group("FROM_UNIXTIME($groupField, '$timeUnix')");
			})->order('add_time asc')->select()->toArray();
	}
}
