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
 * 库存记录
 * Class StoreProductStockRecord
 * @package app\model\product\product
 */
class StoreProductStockDetail extends BaseModel
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
    protected $name = 'store_product_stock_detail';

    /**
     * 一对一关联
     * 商品记录关联商品名称
     * @return \think\model\relation\HasOne
     */
    public function storeName()
    {
        return $this->hasOne(StoreProduct::class, 'id', 'product_id')->bind([
            'store_name',
            'image',
            'product_price' => 'price'
        ]);
    }

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
	 * 入库类型
	 * @param Model $query
	 * @param $value
	 */
	public function searchOrderTypeAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('order_type', $value);
		} else {
			if ($value !== '') $query->where('order_type', $value);
		}
	}


	/**
	 * 入库单号搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchOrderIdAttr($query, $value)
	{
		if ($value != '') $query->where('order_id', $value);
	}

	/**
	 * 入库类型
	 * @param Model $query
	 * @param $value
	 */
	public function searchStockTypeAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('stock_type', $value);
		} else {
			if ($value !== '') $query->where('stock_type', $value);
		}
	}


    /**
     * 商品ID搜索器
     * @param $query
     * @param $value
     */
    public function searchProductIdAttr($query, $value)
    {
        if ($value != '') $query->where('product_id', $value);
    }

    /**
     * unique搜索器
     * @param $query
     * @param $value
     */
    public function searchUniqueAttr($query, $value)
    {
        if ($value != '') $query->where('unique', $value);
    }

    /**
     * pm搜索器
     * @param $query
     * @param $value
     */
    public function searchPmAttr($query, $value)
    {
        if ($value != '') $query->where('pm', $value);
    }

	/**
	 * 关键词搜索
	 * @param $query
	 * @param $value
	 */
	public function searchKeywordAttr($query, $value)
	{
		if ($value !== '') $query->whereLike('product_id|product_name|code|bar_code|sku|unique', "%" . trim($value) . "%");
	}
}
