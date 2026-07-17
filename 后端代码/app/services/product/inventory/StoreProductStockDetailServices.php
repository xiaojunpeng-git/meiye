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

namespace app\services\product\inventory;


use app\dao\product\inventory\StoreProductStockDetailDao;
use app\services\BaseServices;
use app\services\product\sku\StoreProductAttrValueServices;
use function app;

/**
 * 商品库存记录
 * Class StoreProductStockDetailServices
 * @package app\services\product\inventory
 * @mixin StoreProductStockDetailDao
 */
class StoreProductStockDetailServices extends BaseServices
{
	/**
	 * 1 => '采购入库',2 => '其他入库',3 => '退货入库',4 => '残次品转良品',5 => '盘盈入库'
	 * 另：入库 7=院装退回、8=调拨入库（与出库 8=院装领用 双语义，统计须带 stock_type）
	 * @var string[]
	 */
	public $inStockTypes = ['purchase', 'other_in', 'return', 'defective_to_good', 'profit'];

	/**
	 * 1 => '销售出库',2 => '过期退货',3 => '试用出库',4 => '报废出库',5 => '良品转残次品',6 => '其他出库',7 => '盘亏出库'
	 * 另：出库 8=院装领用、9=调拨出库
	 * @var string[]
	 */
	public $outStockTypes = ['sale', 'expired_return', 'use_out', 'scrap_out', 'good_to_defective', 'other_out', 'loss'];

	// 定义类型名称映射
	public $typeNames = [
		'purchase' => '采购入库',
		'other_in' => '其他入库',
		'return' => '退货入库',
		'defective_to_good' => '残次品转良',
		'profit' => '盘盈入库',
		'salon_return' => '院装退回',
		'transfer_in' => '调拨入库',
		'sale' => '销售出库',
		'expired_return' => '过期退货',
		'use_out' => '使用出库',
		'scrap_out' => '报废出库',
		'good_to_defective' => '良品转残次品',
		'other_out' => '其他出库',
		'loss' => '盘亏出库',
		'salon_out' => '院装领用',
		'transfer_out' => '调拨出库',
	];

    /**
     * StoreProductStockDetailServices constructor.
     * @param StoreProductStockDetailDao $dao
     */
    public function __construct(StoreProductStockDetailDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 获取库存详情列表
	 * @param array $where
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getStockDetailList(array $where, int $type = 0, $relation_id = 0, bool $allStores = false)
	{
		$where['type'] = $type;
		if ($allStores) {
			$where['relation_id'] = \think\facade\Db::name('system_store')
				->where('is_del', 0)->where('is_show', 1)->column('id');
			if (!$where['relation_id']) {
				return ['count' => 0, 'list' => []];
			}
		} else {
			$where['relation_id'] = $relation_id;
		}
		$count = $this->dao->count($where);
		[$page, $limit] = $this->getPageValue();
		$list = $this->dao->getList($where, '*', $page, $limit);
		if ($list) {
			foreach ($list as &$item) {
				$item['change_stock'] = $item['count_stock'] > -1 ? (int)bcsub((string)$item['count_stock'], (string)$item['stock'], 0) : 0;
				$item['change_defective_stock'] = $item['count_defective_stock'] > -1 ? (int)bcsub((string)$item['count_defective_stock'], (string)$item['defective_stock'], 0) : 0;
				if (isset($where['stock_type']) && $where['stock_type'] == 2) {//出库
					if ($item['order_type'] != 5) {//排除良品转残次品 正数展示
						$item['stock'] = abs($item['stock']);
						$item['defective_stock'] = abs($item['defective_stock']);
					} else {
						$item['stock'] = abs($item['stock']);
						$item['defective_stock'] = -abs($item['defective_stock']);
					}
				}
			}
		}
		return compact('count', 'list');
	}

	/**
	 * 商品sku库存明细顶部统计数据
	 * @param array $where
	 * @param int $type
	 * @param int $relation_id
	 * @return array[]
	 */
	public function getProductAttrStatistics(array $where = [], int $type = 0, $relation_id = 0, bool $allStores = false, int $splitDisplay = 0)
	{
		$where['type'] = $type;
		if ($allStores) {
			$where['all_stores'] = 1;
			unset($where['relation_id']);
		} else {
			$where['relation_id'] = $relation_id;
			unset($where['all_stores']);
		}
		unset($where['product_type']); // 死参数，避免误以为已过滤
		/** @var StoreProductAttrValueServices $productAttrValueServices */
		$productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
		// 余额：单组合计；是否含院装用于显示精度
		$stockData = $productAttrValueServices->joinAttrSearch($where)->field([
			"SUM(a.stock) as total_good_stock",
			"SUM(a.defective_stock) as total_defective_stock",
			"MAX(IFNULL(p.salon_stock_enabled,0)) as has_salon",
		])->find();
		$stockData = $stockData ? $stockData->toArray() : [];

		// 流水：与余额同一筛选（含院装产品）；时间只影响流水
		$movementWhere = $where;
		unset($movementWhere['type'], $movementWhere['relation_id'], $movementWhere['all_stores']);
		$movementQuery = $this->dao->search($movementWhere)->alias('a')
			->join('store_product p', 'a.product_id = p.id')
			->join('store_product_attr_value av', 'av.product_id = a.product_id AND av.unique = a.unique AND av.type = 0')
			->where('a.type', $type)
			->where('p.is_del', 0)
			->where('p.is_inventory', 1)
			->where('p.type', $type);
		if ($allStores) {
			$movementQuery->where('a.relation_id', '>', 0)
				->where('p.relation_id', '>', 0)
				->whereIn('a.relation_id', function ($sub) {
					$sub->name('system_store')->where('is_del', 0)->where('is_show', 1)->field('id');
				});
		} else {
			$movementQuery->where('a.relation_id', $relation_id)->where('p.relation_id', $relation_id);
		}
		if (isset($where['keyword']) && $where['keyword'] !== '') {
			$kw = trim($where['keyword']);
			$movementQuery->where(function ($q) use ($kw) {
				$q->whereLike('av.bar_code|av.code|av.unique', '%' . $kw . '%')
					->whereOr('p.store_name|p.keyword|p.id|p.bar_code', 'like', '%' . $kw . '%');
			});
		}
		if (isset($where['stock_range']) && $where['stock_range']) {
			$stock_range = explode('-', $where['stock_range']);
			if (count($stock_range) == 2 && ($stock_range[0] !== '' || $stock_range[1] !== '')) {
				if ($stock_range[0] === '') {
					$movementQuery->where('av.stock', '<=', $stock_range[1]);
				} elseif ($stock_range[1] === '') {
					$movementQuery->where('av.stock', '>=', $stock_range[0]);
				} else {
					$movementQuery->whereBetween('av.stock', $stock_range);
				}
			}
		}
		if (isset($where['hide_zero']) && (int)$where['hide_zero'] === 1) {
			$movementQuery->where('av.stock', '>', 0);
		}
		if (isset($where['salon_stock_enabled']) && $where['salon_stock_enabled'] !== '' && $where['salon_stock_enabled'] !== null) {
			if ((int)$where['salon_stock_enabled'] === 1) {
				$movementQuery->where('p.salon_stock_enabled', 1);
			} else {
				$movementQuery->whereRaw('IFNULL(p.salon_stock_enabled,0)=0');
			}
		}
		$fieldArray = [
			"SUM(CASE WHEN a.stock_type = 1 THEN a.stock ELSE 0 END) as total_good_in",
			"SUM(CASE WHEN a.stock_type = 2 THEN a.stock ELSE 0 END) as total_good_out",
			"SUM(CASE WHEN a.stock_type = 1 THEN a.defective_stock ELSE 0 END) as total_defective_in",
			"SUM(CASE WHEN a.stock_type = 2 THEN a.defective_stock ELSE 0 END) as total_defective_out",
			"MAX(IFNULL(p.salon_stock_enabled,0)) as has_salon",
		];
		$statistics = $movementQuery->field($fieldArray)->find();
		$stockMovement = $statistics ? $statistics->toArray() : [];

		$filterSalon = isset($where['salon_stock_enabled']) && $where['salon_stock_enabled'] !== '' && $where['salon_stock_enabled'] !== null
			? (int)$where['salon_stock_enabled']
			: null;
		$hasSalonInStock = (int)($stockData['has_salon'] ?? 0) === 1;
		$hasSalonInMovement = (int)($stockMovement['has_salon'] ?? 0) === 1;
		// 筛选「是」或聚合含院装 → 固定两位；筛选「否」或全非院装 → 整数
		$useSalonScale = ($filterSalon === 1) || ($filterSalon === null && ($hasSalonInStock || $hasSalonInMovement));
		if ($filterSalon === 0) {
			$useSalonScale = false;
		}

		$fmtCard = function (string $name, string $className, $total) use ($useSalonScale) {
			$count = $useSalonScale
				? $this->formatSalonQtyFixed($total)
				: $this->formatNormalQty($total);
			return [
				'name' => $name,
				'field' => '件',
				'count' => $count,
				'has_salon_product' => $useSalonScale ? 1 : 0,
				'split_display' => 0,
				'className' => $className,
				'col' => 4,
			];
		};

		return [
			$fmtCard('良品库存', 'iconhexiaodingdanjine', $stockData['total_good_stock'] ?? 0),
			$fmtCard('良品入库数量', 'iconruku', $stockMovement['total_good_in'] ?? 0),
			$fmtCard('良品出库数量', 'iconchuku', abs($stockMovement['total_good_out'] ?? 0)),
			$fmtCard('残次品库存', 'iconfenpeidingdanjine', $stockData['total_defective_stock'] ?? 0),
			$fmtCard('残次品入库数量', 'iconruku', $stockMovement['total_defective_in'] ?? 0),
			$fmtCard('残次品出库数量', 'iconchuku', abs($stockMovement['total_defective_out'] ?? 0)),
		];
	}

	protected function formatNormalQty($qty): string
	{
		$str = bcadd((string)($qty ?? 0), '0', 4);
		return (string)(int)bcmul($str, '1', 0);
	}

	/** 院装固定两位：100→100.00，100.2→100.20 */
	protected function formatSalonQtyFixed($qty): string
	{
		return bcadd((string)($qty ?? 0), '0', 2);
	}

	/**
	 * 获取出入库统计顶部统计数据
	 * @param int $stockType
	 * @param array $where
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 */
	public function getStockOrderOverallStatistics(int $stockType = 1, array $where = [], int $type = 0, $relation_id = 0, bool $allStores = false)
	{
		$where['type'] = $type;
		if ($allStores) {
			$where['relation_id'] = \think\facade\Db::name('system_store')
				->where('is_del', 0)->where('is_show', 1)->column('id');
			if (!$where['relation_id']) {
				return [];
			}
		} else {
			$where['relation_id'] = $relation_id;
		}
		if (in_array($stockType, [1, 2])) $where['stock_type'] = $stockType;
		if (isset($where['add_time'])) {
			$value = $where['add_time'];
			if (is_string($value)) {
				$value = explode('-', $value);
			}
			if (count($value) == 2) {
				$startTime = strtotime($value[0]);
				$endTime = strtotime($value[1]) + 86400 - 1;
				$value = [$startTime, $endTime];
			}
			$where['time'] = $value;
			unset($where['add_time']);
		}
		$model = $this->dao->search($where);

		// 定义入库和出库类型
		$inStockTypes = $this->inStockTypes;
		$outStockTypes = $this->outStockTypes;
		$fieldArray = [];
		// 入库统计（含 7=院装退回 / 8=调拨入库，强制 stock_type=1）
		if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 1) {
			foreach ($inStockTypes as $key => $type) {
				if ($key == 3) {//残次品转良品
					$fieldArray[] = "SUM(CASE WHEN stock_type = 1 and order_type = " . ($key + 1) . " THEN stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN stock_type = 1 and order_type = " . ($key + 1) . " THEN stock + defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
			$fieldArray[] = "SUM(CASE WHEN stock_type = 1 and order_type = 7 THEN stock + defective_stock ELSE 0 END) as salon_return_stock";
			$fieldArray[] = "SUM(CASE WHEN stock_type = 1 and order_type = 8 THEN stock + defective_stock ELSE 0 END) as transfer_in_stock";
		}
		// 出库统计（含 8=院装领用 / 9=调拨出库，强制 stock_type=2）
		if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 2) {
			foreach ($outStockTypes as $key => $type) {
				if ($key == 4) {//良品转残次品
					$fieldArray[] = "SUM(CASE WHEN stock_type = 2 and order_type = " . ($key + 1) . " THEN defective_stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN stock_type = 2 and order_type = " . ($key + 1) . " THEN stock + defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
			$fieldArray[] = "SUM(CASE WHEN stock_type = 2 and order_type = 8 THEN stock + defective_stock ELSE 0 END) as salon_out_stock";
			$fieldArray[] = "SUM(CASE WHEN stock_type = 2 and order_type = 9 THEN stock + defective_stock ELSE 0 END) as transfer_out_stock";
		}
		$stats = $model->field($fieldArray)->find();
		$stats = $stats ? $stats->toArray() : [];
		$result = [];
		$inKeys = ['purchase_stock', 'other_in_stock', 'return_stock', 'defective_to_good_stock', 'profit_stock', 'salon_return_stock', 'transfer_in_stock'];
		$outKeys = ['sale_stock', 'expired_return_stock', 'use_out_stock', 'scrap_out_stock', 'good_to_defective_stock', 'other_out_stock', 'loss_stock', 'salon_out_stock', 'transfer_out_stock'];
		//入库统计
		if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 1) {
			$total_in_stock = '0';
			foreach ($inKeys as $k) {
				$total_in_stock = bcadd($total_in_stock, (string)($stats[$k] ?? 0), 4);
			}
			$result = array_merge($result, [
				['name' => '总入库数', 'field' => '件', 'count' => abs((float)$total_in_stock), 'className' => 'icondingdanjine', 'col' => 4],
				['name' => '采购入库', 'field' => '件', 'count' => abs($stats['purchase_stock'] ?? 0), 'className' => 'iconshouyintai-shouyin1', 'col' => 4,],
				['name' => '盘盈入库', 'field' => '件', 'count' => abs($stats['profit_stock']?? 0), 'className' => 'iconhexiaodingdanjine', 'col' => 4,],
				['name' => '退货入库', 'field' => '件', 'count' => abs($stats['return_stock']?? 0), 'className' => 'iconshouhou_tuikuan', 'col' => 4,],
				['name' => '其他入库', 'field' => '件', 'count' => abs($stats['other_in_stock']?? 0), 'className' => 'icontuikuandingdanliang', 'col' => 4,],
				['name' => '残次品转良品', 'field' => '件', 'count' => abs($stats['defective_to_good_stock']?? 0), 'className' => 'iconfenpeidingdanjine', 'col' => 4,],
				['name' => '院装退回', 'field' => '件', 'count' => abs($stats['salon_return_stock'] ?? 0), 'className' => 'iconshouhou_tuikuan', 'col' => 4,],
				['name' => '调拨入库', 'field' => '件', 'count' => abs($stats['transfer_in_stock'] ?? 0), 'className' => 'icondingdanjine', 'col' => 4,],
			]);
		}
		//出库统计
		if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 2) {
			$total_out_stock = '0';
			foreach ($outKeys as $k) {
				$total_out_stock = bcadd($total_out_stock, (string)($stats[$k] ?? 0), 4);
			}
			$result = array_merge($result, [
				['name' => '总出库数', 'field' => '件', 'count' => abs((float)$total_out_stock), 'className' => 'iconchuku', 'col' => 4,],
				['name' => '销售出库', 'field' => '件', 'count' => abs($stats['sale_stock'] ?? 0), 'className' => 'iconzaishoushangpin', 'col' => 4,],
				['name' => '盘亏出库', 'field' => '件', 'count' => abs($stats['loss_stock'] ?? 0), 'className' => 'icondaishenhe-shequneirong', 'col' => 4,],
				['name' => '过期退货', 'field' => '件', 'count' => abs($stats['expired_return_stock'] ?? 0), 'className' => 'iconshouyintai-tuihuo1', 'col' => 4,],
				['name' => '试用出库', 'field' => '件', 'count' => abs($stats['use_out_stock'] ?? 0), 'className' => 'iconshouhou-tuikuan-lv', 'col' => 4,],
				['name' => '报废出库', 'field' => '件', 'count' => abs($stats['scrap_out_stock'] ?? 0), 'className' => 'iconjingjiekucun', 'col' => 4,],
				['name' => '其他出库', 'field' => '件', 'count' => abs($stats['other_out_stock'] ?? 0), 'className' => 'icontuikuandingdanliang', 'col' => 4,],
				['name' => '良品转残次品', 'field' => '件', 'count' => abs($stats['good_to_defective_stock'] ?? 0), 'className' => 'iconfenpeidingdanjine', 'col' => 4,],
				['name' => '院装领用', 'field' => '件', 'count' => abs($stats['salon_out_stock'] ?? 0), 'className' => 'iconchuku', 'col' => 4,],
				['name' => '调拨出库', 'field' => '件', 'count' => abs($stats['transfer_out_stock'] ?? 0), 'className' => 'iconchuku', 'col' => 4,],
			]);
		}
		return $result;
	}


	/**
	 * 商品出入库统计列表数据
	 * @param int $stockType
	 * @param array $where
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 */
	public function getStockStatisticsList(int $stockType = 1, array $where = [], int $type = 0, $relation_id = 0, bool $allStores = false)
	{
		$where['type'] = $type;
		if ($allStores) {
			$validStoreIds = \think\facade\Db::name('system_store')
				->where('is_del', 0)->where('is_show', 1)->column('id');
			if (!$validStoreIds) {
				return ['list' => [], 'count' => 0];
			}
			$where['relation_id'] = $validStoreIds;
		} else {
			$where['relation_id'] = $relation_id;
		}
		if (in_array($stockType, [1, 2])) $where['stock_type'] = $stockType;
		$model = $this->dao->search([])->alias('a')
			->join('store_product s', 'a.product_id = s.id');
		foreach ($where as $key => $value) {
			if ($value !== '' && in_array($key, ['type', 'relation_id', 'stock_type', 'add_time'])) {
				if ($key == 'add_time') {
					if (is_string($value)) {
						$value = explode('-', $value);
					}
					if (count($value) == 2) {
						$startTime = strtotime($value[0]);
						$endTime = strtotime($value[1]) + 86400 - 1;
						$model->whereBetween('a.' . $key, [$startTime, $endTime]);
					}
				} elseif ($key == 'relation_id' && is_array($value)) {
					$model->whereIn('a.relation_id', $value);
				} else {
					$model->where('a.' . $key, $value);
				}
			}
		}
		// 添加搜索条件
		if (!empty($where['keyword'])) {
			$model = $model->whereLike('a.product_id|a.product_name|a.sku|a.code|a.bar_code|s.store_name|s.code|s.bar_code|s.keyword', '%' . $where['keyword'] . '%');
		}
		[$page, $limit] = $this->getPageValue();

		// 定义入库和出库类型
		$inStockTypes = $this->inStockTypes;
		$outStockTypes = $this->outStockTypes;

		// 全部门店汇总：按平台 pid + sku 聚合；否则按门店商品 product_id+unique
		$platformPid = 'IF(s.pid > 0, s.pid, s.id)';
		$groupExpr = $allStores ? ($platformPid . ', a.sku') : 'a.product_id, a.unique';
		$fieldArray = $allStores
			? [
				$platformPid . ' AS product_id',
				'MIN(a.product_name) AS product_name',
				'a.sku',
				'MIN(a.image) AS image',
				'MIN(a.code) AS code',
				'MIN(a.bar_code) AS bar_code',
				'COUNT(DISTINCT a.relation_id) AS store_count',
				'SUM(a.stock + a.defective_stock) as total_stock',
			]
			: [
				'a.product_id', 'a.product_name', 'a.sku', 'a.image', 'a.code', 'a.bar_code',
				'a.unique',
				'SUM(a.stock + a.defective_stock) as total_stock',
			];

		// 根据查询类型添加对应的统计字段（8 双语义：入库调拨 / 出库院装，CASE 内强制 stock_type）
		if (!isset($where['stock_type']) || $where['stock_type'] === '') {
			foreach ($inStockTypes as $key => $type) {
				if ($key == 3) {//残次品转良品
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = " . ($key + 1) . " THEN a.stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = " . ($key + 1) . " THEN a.stock + a.defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
			$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = 7 THEN a.stock + a.defective_stock ELSE 0 END) as salon_return_stock";
			$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = 8 THEN a.stock + a.defective_stock ELSE 0 END) as transfer_in_stock";
			foreach ($outStockTypes as $key => $type) {
				if ($key == 4) {//良品转残次品
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = " . ($key + 1) . " THEN a.defective_stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = " . ($key + 1) . " THEN a.stock + a.defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
			$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = 8 THEN a.stock + a.defective_stock ELSE 0 END) as salon_out_stock";
			$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = 9 THEN a.stock + a.defective_stock ELSE 0 END) as transfer_out_stock";
		} elseif ($where['stock_type'] == 1) {
			foreach ($inStockTypes as $key => $type) {
				if ($key == 3) {//残次品转良品
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = " . ($key + 1) . " THEN a.stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = " . ($key + 1) . " THEN a.stock + a.defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
			$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = 7 THEN a.stock + a.defective_stock ELSE 0 END) as salon_return_stock";
			$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = 8 THEN a.stock + a.defective_stock ELSE 0 END) as transfer_in_stock";
		} elseif ($where['stock_type'] == 2) {
			foreach ($outStockTypes as $key => $type) {
				if ($key == 4) {//良品转残次品
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = " . ($key + 1) . " THEN a.defective_stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = " . ($key + 1) . " THEN a.stock + a.defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
			$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = 8 THEN a.stock + a.defective_stock ELSE 0 END) as salon_out_stock";
			$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = 9 THEN a.stock + a.defective_stock ELSE 0 END) as transfer_out_stock";
		}
		// 分组总数：子查询 COUNT，禁止全量 select 进 PHP
		$countSql = (clone $model)->field($groupExpr)->group($groupExpr)->buildSql();
		$count = (int)\think\facade\Db::table([$countSql => 't'])->count();
		$list = $model->field($fieldArray)
			->group($groupExpr)
			->page($page, $limit)
			->order($allStores ? 'product_id DESC' : 's.sort DESC, s.id DESC')
			->select()
			->toArray();
		if ($list) {
			foreach ($list as &$item) {
				$item['total_stock'] = abs($item['total_stock'] ?? 0);
				//包含出库 数量转为正数展示
				if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 2) {
					foreach (array_merge($outStockTypes, ['salon_out', 'transfer_out']) as $type) {
						$key = $type . '_stock';
						$item[$key] = abs($item[$key] ?? 0);
					}
				}
			}
		}
		return compact('count', 'list');
	}

	/**
	 * 处理商品库存变动到出入库单
	 * @param int $id
	 * @param array $attrs
	 * @param int $type
	 * @param int $relation_id
	 * @param int $adminId
	 * @return bool
	 */
	public function handelProductStock(int $id, array $attrs, int $type = 0, int $relation_id = 0, int $adminId = 0)
	{
		if (!$attrs) return true;
		try {
			//整理盘点库存 盘盈||盘亏
			$inProductDetailData = $outProductDetailData = [];
			foreach ($attrs as $attr) {
				$productId = $id ?: ($attr['product_id'] ?? 0);
				if (!$productId || !isset($attr['unique']) || !isset($attr['stock'])) continue;
				$pm = $attr['pm'] ?? 1;
				$data = [
					'product_id' => $productId,
					'unique' => $attr['unique'],
					'stock' => $attr['stock'],
				];
				if ($pm == 1) {//入库
					$inProductDetailData[] = $data;
				} else {//出库
					$outProductDetailData[] = $data;
				}
			}
			/** @var StoreProductStockOrderServices $stockOrderServices */
			$stockOrderServices = app()->make(StoreProductStockOrderServices::class);
			if ($inProductDetailData) {//盘盈入库
				$stockOrderServices->saveData(1, [
					'count_id' => $id,
					'order_type' => 2,
					'stock_time' => date('Y-m-d'),
					'remark' => '',
					'in_product_detail' => $inProductDetailData
				], $type, $relation_id, $adminId, false);
			}
			if ($outProductDetailData) {//盘亏出库
				$stockOrderServices->saveData(2, [
					'count_id' => $id,
					'order_type' => 6,
					'stock_time' => date('Y-m-d'),
					'remark' => '',
					'out_product_detail' => $outProductDetailData
				], $type, $relation_id, $adminId, false);
			}
		} catch (\Throwable $e) {

		}
		return true;
	}


}
