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

namespace app\dao\product\inventory;


use app\dao\BaseDao;
use app\model\product\inventory\StoreProductStockOrder;

/**
 * 入库单
 * Class StoreProductStockInOrderDao
 * @package app\dao\product\Inventory
 */
class StoreProductStockOrderDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreProductStockOrder::class;
    }

	public function search(array $where = [])
	{
		return parent::search($where)
			->when(isset($where['unique']) && $where['unique'], function ($query) use ($where) {
				$query->where('id', 'IN', function ($detail) use ($where) {
					$detail->name('store_product_stock_detail')->whereIn('stock_type', [1, 2])->where('unique', $where['unique'])->field('order_id')->select();
				});
			});
	}

	/**
	 * @param array $where
	 * @param string $field
	 * @param int $page
	 * @param int $limit
	 * @param array $with
	 * @param string $order
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0, array $with = [], string $order = 'add_time desc, stock_time desc')
	{
		return $this->search($where)->field($field)
			->when($with, function ($query) use ($with) {
				$query->with($with);
			})->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
				$query->page($page, $limit);
			})->order($order)->select()->toArray();
	}


}
