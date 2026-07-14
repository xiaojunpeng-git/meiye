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


use app\model\order\StoreOrder;
use app\model\order\StoreOrderRefund;
use app\model\product\product\StoreProduct;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 出入库单
 * Class StoreProductStockInOrder
 * @package app\model\product\Inventory
 */
class StoreProductStockOrder extends BaseModel
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
    protected $name = 'store_product_stock_order';

	/**
	 * 关联出入库明细
	 * @return \think\model\relation\HasMany
	 */
	public function detail()
	{
		return $this->hasMany(StoreProductStockDetail::class, 'order_id', 'id');
	}

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
	 * 关联退款单
	 * @return \think\model\relation\HasOne
	 */
	public function refundOrderId()
	{
		return $this->hasOne(StoreOrderRefund::class, 'id', 'refund_order_id')->field('id,order_id')->bind([
			'order_sn' => 'order_id'
		]);
	}

	/**
	 * 关联退款单
	 * @return \think\model\relation\HasOne
	 */
	public function storeOrderId()
	{
		return $this->hasOne(StoreOrder::class, 'id', 'store_order_id')->field('id,order_id')->bind([
			'order_sn' => 'order_id'
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
	 * 库存类型搜索器
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
	 * 出入库类型
	 * @param Model $query
	 * @param $value
	 */
	public function searchOrderTypeAttr($query, $value, $data)
	{
		if (is_array($value)) {
			if ($value) {
				$query->whereIn('order_type', $value);
			}
		} else {
			if ($value !== '') {
				if (isset($data['stock_type'])) {//有出入库类型
					$query->where('order_type', $value);
				} else {
					if ($value > 20) {
						//出库类型会+20
						$query->where('stock_type', 2)->where('order_type',  bcsub((string)$value,'20'));
					} else {
						$query->where('stock_type', 1)->where('order_type', $value);
					}
				}
			}
		}
	}


    /**
     * 出入库单号搜索器
     * @param $query
     * @param $value
     */
    public function searchOrderIdAttr($query, $value)
    {
        if ($value != '') $query->where('order_id', $value);
    }

	/**
	 * 销售出库关联订单单搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchStoreOrderIdAttr($query, $value)
	{
		if ($value != '') $query->where('store_order_id', $value);
	}

	/**
	 * 退货入库关联退款单搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchRefundOrderIdAttr($query, $value)
	{
		if ($value != '') $query->where('refund_order_id', $value);
	}


	/**
	 * 时间段搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchStockTimeAttr($query, $value)
	{
		if ($value) {
			$timeKey = 'stock_time';
			if (is_array($value)) {
				$startTime = $value[0] ?? 0;
				$endTime = $value[1] ?? 0;
				if ($startTime || $endTime) {
					try {
						date('Y-m-d', $startTime);
					} catch (\Throwable $e) {
						$startTime = strtotime($startTime);
					}
					try {
						date('Y-m-d', $endTime);
					} catch (\Throwable $e) {
						$endTime = strtotime($endTime);
					}
					if ($startTime == $endTime || $endTime == strtotime(date('Y-m-d', $endTime))) {
						$endTime = $endTime + 86399;
					}
					$query->whereBetween($timeKey, [$startTime, $endTime]);
				}
			} else {
				if (strstr($value, '-') !== false) {
					[$startTime, $endTime] = explode('-', $value);
					$startTime = trim($startTime) ? strtotime($startTime) : 0;
					$endTime = trim($endTime) ? strtotime($endTime) : 0;
					if ($startTime && $endTime) {
						if ($startTime == $endTime || $endTime == strtotime(date('Y-m-d', $endTime))) {
							$endTime = $endTime + 86399;
						}
						$query->whereBetween($timeKey, [$startTime, $endTime]);
					} else if (!$startTime && $endTime) {
						$query->whereTime($timeKey, '<', $endTime + 86399);
					} else if ($startTime && !$endTime) {
						$query->whereTime($timeKey, '>=', $startTime);
					}
				}
			}

		}
	}

	/**
	 * 关键词搜索
	 * @param $query
	 * @param $value
	 */
	public function searchKeywordAttr($query, $value)
	{
		if ($value !== '') $query->whereLike('id|order_id|relation_id|store_order_id|refund_order_id|remark', "%" . trim($value) . "%");
	}


}
