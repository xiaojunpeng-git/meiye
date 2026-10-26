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
use app\model\user\UserCardHolder;

/**
 *
 * Class UserCardHolderDao
 * @package app\dao\user
 */
class UserCardHolderDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return UserCardHolder::class;
    }

	public function search(array $where = [])
	{
		return parent::search($where)->when(isset($where['product_type']) && $where['product_type'], function ($query) use ($where) {
            $query->where('product_type', $where['product_type']);
        });
	}

	/**
	 * 获取列表
	 * @param array $where
	 * @param string $order
	 * @param int $page
	 * @param int $limit
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getList(array $where = [], int $page = 0, int $limit = 0, array $with = []): array
	{
		return $this->search($where)->order('id desc')->when(count($with), function ($query) use ($with) {
            $query->with($with);
        })->when($page && $limit, function ($query) use ($page, $limit) {
			$query->page($page, $limit);
		})->select()->toArray();
	}
}
