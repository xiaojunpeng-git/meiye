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

namespace app\dao\system;

use app\dao\BaseDao;
use app\model\system\SystemSignReward;

/**
 * 签到奖励
 * Class SystemSignRewardDao
 * @package app\dao\system
 */
class SystemSignRewardDao extends BaseDao
{
	/**
	 * 设置模型名
	 * @return string
	 */
    protected function setModel(): string
    {
        return SystemSignReward::class;
    }


	/**
	 * 获取列表
	 * @param array $where
	 * @param string $field
	 * @param int $page
	 * @param int $limit
	 * @param array $typeWhere
	 * @return array
	 */
	public function getList(array $where, string $field = '*', array $with = [], int $page = 0, int $limit = 0)
	{
		return $this->search($where)->field($field)->when($with, function ($query) use ($with) {
			$query->with($with);
		})->when($page && $limit, function ($query) use ($page, $limit) {
			$query->page($page, $limit);
		})->order('days desc')->select()->toArray();
	}

}
