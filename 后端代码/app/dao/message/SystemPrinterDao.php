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

namespace app\dao\message;

use app\dao\BaseDao;
use app\model\message\SystemPrinter;

/**
 * Class SystemPrinterDao
 * @package app\dao\system\admin
 */
class SystemPrinterDao extends BaseDao
{
    /**
     * 设置模型名
     * @return string
     */
    protected function setModel(): string
    {
        return SystemPrinter::class;
    }

	/**
	 * @param array $where
	 * @return \mohe\basic\BaseModel|mixed|\think\Model
	 */
	public function search(array $where = [])
	{
		return parent::search($where)->where('is_del', 0)->when(isset($where['keyword']) && $where['keyword'] !== '', function ($query) use ($where) {
            $query->where('name', 'like', '%' . $where['keyword'] . '%');
        })->when(isset($where['plat_type']) && $where['plat_type'] != 0, function ($query) use ($where) {
            $query->where('plat_type', $where['plat_type']);
        })->when(isset($where['print_event']) && in_array($where['print_event'], [1, 2]), function($query) use ($where) {
			$query->whereFindinSet('print_event', $where['print_event']);
		});
	}

	/**
	 * 获取列表
	 * @param array $where
	 * @param string $field
	 * @param int $page
	 * @param int $limit
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0)
	{
		return $this->search($where)->field($field)
			->when($page && $limit, function ($query) use ($page, $limit) {
			$query->page($page, $limit);
		})->order('id desc')->select()->toArray();
	}

}
