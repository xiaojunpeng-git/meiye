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

namespace app\dao\system\form;

use app\dao\BaseDao;
use app\model\system\form\SystemFormData;

/**
 *
 * Class SystemFormDataDao
 * @package app\dao\system\form
 */
class SystemFormDataDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemFormData::class;
    }

	/**
	 * @param array $where
	 * @return \mohe\basic\BaseModel|mixed|\think\Model
	 */
	public function search(array $where = [])
	{
		return parent::search($where)->when(isset($where['store_name']) && $where['store_name'], function($query) use ($where) {
			$query->where('product_id', 'in', function ($que) use ($where) {
				$que->name('store_product')->whereLike('id|store_name|keyword', '%' . $where['store_name'] . '%')->field(['id'])->select();
			});
		})->when(isset($where['user_name']) && $where['user_name'], function($query) use ($where) {
			$query->where('uid', 'in', function ($que) use ($where) {
				$que->name('user')->whereLike('uid|nickname|phone|real_name', '%' . $where['user_name'] . '%')->field(['uid'])->select();
			});
		});
	}


	/**
	 * 获取表单数据
	 * @param array $where
	 * @param array $field
	 * @param int $page
	 * @param int $limit
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getList(array $where, array $field = ['*'], int $page = 0, int $limit = 0, array $with = [])
	{
		return $this->search($where)->field($field)
			->when($with, function ($query) use ($with) {
				$query->with($with);
			})->when($page && $limit, function($query) use ($page, $limit){
				$query->page($page, $limit);
			})->order('id desc')->select()->toArray();
	}



}
