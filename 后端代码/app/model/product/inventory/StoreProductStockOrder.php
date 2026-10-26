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
	 * order_type=8 双语义：须与 stock_type 联用（入库=调拨入库，出库=院装领用）
	 * 禁止裸筛 order_type（含数组 whereIn）：无方向会把调拨入库与院装领用混查
	 * 单值/数组约定：
	 * - 已带 stock_type：值均为该方向下的原始 order_type
	 * - 未带 stock_type：入库用原值，出库用 20+order_type（与流水页一致）
	 * @param Model $query
	 * @param $value
	 * @param array $data
	 */
	public function searchOrderTypeAttr($query, $value, $data)
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
				// 入/出库列表已固定方向，数组仅表示该方向下的多个子类型
				$query->where('stock_type', $stockType)->whereIn('order_type', $values);
				return;
			}
			// 无 stock_type：按编码拆成入库组 / 出库组，禁止无方向 whereIn(order_type)
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
