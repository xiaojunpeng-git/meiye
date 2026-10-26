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

namespace app\dao\agent;


use app\dao\BaseDao;
use app\model\agent\SystemRegionAgent;

/**
 * 区域代理商
 * Class SystemRegionAgentDao
 * @package app\dao\agent
 */
class SystemRegionAgentDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemRegionAgent::class;
    }

	/**
	 * 获取列表
	 * @param array $where
	 * @param string $field
	 * @param int $page
	 * @param int $limit
	 * @param array $with
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0, array $with = [])
	{
		return $this->search($where)->field($field)
			->when($page && $limit, function ($query) use ($page, $limit) {
				$query->page($page, $limit);
			})->when(count($with), function ($query) use ($with) {
				$query->with($with);
			})->order('sort desc,id desc')->select()->toArray();
	}

	/**
	 * 根据城市查询区域
	 * @param array $where
	 * @param string $field
	 * @return array|\mohe\basic\BaseModel|mixed|\think\Model|null
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getRegion(array $where, string $field = '*')
	{
		return $this->search($where)->field($field)->find();
	}


}
