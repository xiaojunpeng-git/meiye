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
	 * 出入库类型（必须带 stock_type 上下文；兼容流水页 20+order_type 编码）
	 * order_type=8 双语义：入库=调拨入库 / 出库=院装领用
	 * 禁止裸筛 order_type（含数组 whereIn）：无方向会把调拨入库与院装领用混查
	 * 单值/数组约定：
	 * - 已带 stock_type：值均为该方向下的原始 order_type
	 * - 未带 stock_type：入库用原值，出库用 20+order_type（与流水页一致）
	 * @param Model $query
	 * @param $value
	 * @param array $data
	 */
	public function searchOrderTypeAttr($query, $value, $data = [])
	{
		if ($value === '' || $value === null || $value === []) {
			return;
		}
		$hasStockType = isset($data['stock_type']) && $data['stock_type'] !== '' && $data['stock_type'] !== null;
		$stockType = $hasStockType ? (int)$data['stock_type'] : 0;

		if (is_array($value)) {
			$values = array_values(array_unique(array_map('intval', $value)));
			if (!$values) {
				return;
			}
			if ($hasStockType) {
				$query->where('stock_type', $stockType)->whereIn('order_type', $values);
				return;
			}
			$inTypes = [];
			$outTypes = [];
			foreach ($values as $raw) {
				if ($raw > 20) {
					$outTypes[] = (int)bcsub((string)$raw, '20');
				} else {
					$inTypes[] = $raw;
				}
			}
			$inTypes = array_values(array_unique($inTypes));
			$outTypes = array_values(array_unique($outTypes));
			$query->where(function ($q) use ($inTypes, $outTypes) {
				if ($inTypes && $outTypes) {
					$q->where(function ($q2) use ($inTypes) {
						$q2->where('stock_type', 1)->whereIn('order_type', $inTypes);
					})->whereOr(function ($q2) use ($outTypes) {
						$q2->where('stock_type', 2)->whereIn('order_type', $outTypes);
					});
				} elseif ($inTypes) {
					$q->where('stock_type', 1)->whereIn('order_type', $inTypes);
				} elseif ($outTypes) {
					$q->where('stock_type', 2)->whereIn('order_type', $outTypes);
				} else {
					$q->whereRaw('1 = 0');
				}
			});
			return;
		}

		$raw = (int)$value;
		if ($hasStockType) {
			$query->where('stock_type', $stockType)->where('order_type', $raw);
			return;
		}
		if ($raw > 20) {
			$query->where('stock_type', 2)->where('order_type', (int)bcsub((string)$raw, '20'));
		} else {
			$query->where('stock_type', 1)->where('order_type', $raw);
		}
	}


	/**
	 * 入库单号搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchOrderIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('order_id', $value);
		} else {
			if ($value != '') $query->where('order_id', $value);
		}
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
