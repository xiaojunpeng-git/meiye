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
use app\model\system\form\SystemForm;

/**
 *
 * Class SystemFormDao
 * @package app\dao\system\form
 */
class SystemFormDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemForm::class;
    }


	/**
	 * 获取系统表单
	 * @param array $where
	 * @param array $field
	 * @param int $page
	 * @param int $limit
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getFormList(array $where, array $field = ['*'], int $page = 0, int $limit = 0)
	{
		return $this->search($where)->field($field)->where('is_del', 0)
			->when($page && $limit, function($query) use ($page, $limit){$query->page($page, $limit);
			})->order('id desc')->select()->toArray();
	}

}
