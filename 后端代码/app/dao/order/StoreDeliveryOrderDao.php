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

namespace app\dao\order;


use app\dao\BaseDao;
use app\model\order\StoreDeliveryOrder;

/**
 * 发货订单
 * Class StoreDeliveryOrderDao
 * @package app\dao\order
 */
class StoreDeliveryOrderDao extends BaseDao
{

    /**
     * @return string
     */
    protected function setModel(): string
    {
        return StoreDeliveryOrder::class;
    }

    /**
     * 查询
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['station_type']) && $where['station_type'] == '', function ($query) use ($where) {
            $query->where('station_type', '>', 0);
        });
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
        return $this->search($where)->field($field)->when($with, function($query) use ($with) {
			$query->with($with);
		})->when($page && $limit, function($query) use ($page, $limit) {
			$query->page($page, $limit);
		})->order('id desc')->select()->toArray();
    }



}
