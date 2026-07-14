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

namespace app\model\product\inventory;


use app\model\product\product\StoreProduct;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 库存盘点
 * Class StoreProductStockCount
 * @package app\model\product\inventory
 */
class StoreProductStockCount extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_product_stock_count';

	protected $updateTime = false;

	/**
	 * id
	 * @param Model $query
	 * @param $value
	 */
	public function searchIdAttr($query, $value)
	{
		if (is_array($value))
			$query->whereIn('id', $value);
		else
			$query->where('id', $value);
	}

	/**
	 * ids搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchIdsAttr($query, $value)
	{
		if ($value) $query->whereIn('id', $value);
	}

	/**
	 * 商户搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchTypeAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('type', $value);
		} else {
			if ($value !== '') $query->where('type', $value);
		}
	}

	/**
	 * 关联门店ID、供应商ID搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchRelationIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('relation_id', $value);
		} else {
			if ($value !== '') $query->where('relation_id', $value);
		}
	}


	/**
	 * 盘点单号搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchOrderIdAttr($query, $value)
	{
		if ($value != '') $query->where('order_id', $value);
	}

	/**
	 * 状态搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchStatusAttr($query, $value)
	{
		if ($value != '') $query->where('status', $value);
	}

	/**
	 * 关键词搜索
	 * @param $query
	 * @param $value
	 */
	public function searchKeywordAttr($query, $value)
	{
		if ($value !== '') $query->whereLike('id|order_id|remark', "%" . trim($value) . "%");
	}


}
