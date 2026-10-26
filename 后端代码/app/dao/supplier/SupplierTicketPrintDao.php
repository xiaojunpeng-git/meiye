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

namespace app\dao\supplier;

use app\dao\BaseDao;
use app\model\supplier\SupplierTicketPrint;

/**
 * 供应商小票打印
 * Class SystemSupplierDao
 * @package app\dao\supplier
 */
class SupplierTicketPrintDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SupplierTicketPrint::class;
    }

	/**
	 * 获取列表
	 * @param array $where
	 * @param int $page
	 * @param int $limit
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getList(array $where = [], int $page = 0, int $limit = 0)
	{
		return $this->search($where)
			->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
				$query->page($page, $limit);
			})->order('id desc')->select()->toArray();
	}
}
