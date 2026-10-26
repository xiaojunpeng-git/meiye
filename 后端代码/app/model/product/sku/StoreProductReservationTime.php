<?php


namespace app\model\product\sku;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 预约商品时段划分库存
 * Class StoreProductRule
 * @package app\common\model\product
 */
class StoreProductReservationTime extends BaseModel
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
    protected $name = 'store_product_reservation_time';


    /**
     * 商品搜索器
     * @param $query
     * @param $value
     */
    public function searchProductIdAttr($query, $value)
    {
        if ($value !== '') $query->where('product_id', $value);
    }

	/**
	 * 商品搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchSkuIdAttr($query, $value)
	{
		if ($value !== '') $query->where('sku_id', $value);
	}

    /**
     * 唯一值搜索器
     * @param $query
     * @param $value
     */
    public function searchSkuUniqueAttr($query, $value)
    {
		if ($value !== '') $query->where('sku_unique', $value);
    }

}
