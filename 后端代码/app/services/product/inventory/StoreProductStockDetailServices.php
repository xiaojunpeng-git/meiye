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
	 * 1 => '采购入库',2 => '其他入库',3 => '退货入库',4 => '残次品转良品',5 => '盘盈入库',
	 * @var string[]
	 */
	public $inStockTypes = ['purchase', 'other_in', 'return', 'defective_to_good', 'profit'];

	/**
	 * 1 => '销售出库',2 => '过期退货',3 => '试用出库',4 => '报废出库',5 => '良品转残次品',6 => '其他出库',7 => '盘亏出库'
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
		'sale' => '销售出库',
		'expired_return' => '过期退货',
		'use_out' => '使用出库',
		'scrap_out' => '报废出库',
		'good_to_defective' => '良品转残次品',
		'other_out' => '其他出库',
		'loss' => '盘亏出库'
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
	public function getStockDetailList(array $where, int $type = 0, int $relation_id = 0)
	{
		$where['type'] = $type;
		$where['relation_id'] = $relation_id;
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
	public function getProductAttrStatistics(array $where = [], int $type = 0, int $relation_id = 0)
	{
		$where['type'] = $type;
		$where['relation_id'] = $relation_id;
		/** @var StoreProductAttrValueServices $productAttrValueServices */
		$productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
		// 获取当前库存数量（不受时间筛选影响）
		$stockData = $productAttrValueServices->joinAttrSearch($where)->field('SUM(a.stock) as good_stock, SUM(a.defective_stock) as defective_stock')
			->find();
		$stockData = $stockData ? $stockData->toArray() : ['good_stock' => 0, 'defective_stock' => 0];

		// 获取出入库统计数量（根据时间筛选）
		$fieldArray = [
			// 良品入库数量
			'SUM(CASE WHEN a.stock_type = 1 THEN a.stock ELSE 0 END) as good_in_stock',
			// 良品出库数量
			'SUM(CASE WHEN a.stock_type = 2 THEN a.stock ELSE 0 END) as good_out_stock',
			// 残次品入库数量
			'SUM(CASE WHEN a.stock_type = 1 THEN a.defective_stock ELSE 0 END) as defective_in_stock',
			// 残次品出库数量
			'SUM(CASE WHEN a.stock_type = 2 THEN a.defective_stock ELSE 0 END) as defective_out_stock'
		];
		$model = $this->dao->search($where)->alias('a');
		$statistics = $model->field($fieldArray)->find();
		$stockMovement = $statistics ? $statistics->toArray() : [
			'good_in_stock' => 0,
			'good_out_stock' => 0,
			'defective_in_stock' => 0,
			'defective_out_stock' => 0
		];
		return [
			['name' => '良品库存', 'field' => '件', 'count' => $stockData['good_stock'] ?? 0, 'className' => 'iconhexiaodingdanjine', 'col' => 4],
			['name' => '良品入库数量', 'field' => '件', 'count' => $stockMovement['good_in_stock'] ?? 0, 'className' => 'iconruku', 'col' => 4],
			['name' => '良品出库数量', 'field' => '件', 'count' => abs($stockMovement['good_out_stock'] ?? 0), 'className' => 'iconchuku', 'col' => 4],
			['name' => '残次品库存', 'field' => '件', 'count' => $stockData['defective_stock'] ?? 0, 'className' => 'iconfenpeidingdanjine', 'col' => 4],
			['name' => '残次品入库数量', 'field' => '件', 'count' => $stockMovement['defective_in_stock'] ?? 0, 'className' => 'iconruku', 'col' => 4],
			['name' => '残次品出库数量', 'field' => '件', 'count' => abs($stockMovement['defective_out_stock'] ?? 0), 'className' => 'iconchuku', 'col' => 4],
		];
	}

	/**
	 * 获取出入库统计顶部统计数据
	 * @param int $stockType
	 * @param array $where
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 */
	public function getStockOrderOverallStatistics(int $stockType = 1, array $where = [], int $type = 0, int $relation_id = 0)
	{
		$where['type'] = $type;
		$where['relation_id'] = $relation_id;
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
		// 入库统计
		if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 1) {
			//$fieldArray[] = 'SUM(CASE WHEN stock_type = 1 THEN stock + defective_stock ELSE 0 END) as total_in_stock';
			foreach ($inStockTypes as $key => $type) {
				if ($key == 3) {//残次品转良品
					$fieldArray[] = "SUM(CASE WHEN stock_type = 1 and order_type = " . ($key + 1) . " THEN stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN stock_type = 1 and order_type = " . ($key + 1) . " THEN stock + defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
		}
		// 出库统计
		if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 2) {
			//$fieldArray[] = 'SUM(CASE WHEN stock_type = 2 THEN stock + defective_stock ELSE 0 END) as total_out_stock';
			foreach ($outStockTypes as $key => $type) {
				if ($key == 4) {//良品转残次品
					$fieldArray[] = "SUM(CASE WHEN stock_type = 2 and order_type = " . ($key + 1) . " THEN defective_stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN stock_type = 2 and order_type = " . ($key + 1) . " THEN stock + defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
		}
		$stats = $model->field($fieldArray)->find();
		$stats = $stats ? $stats->toArray() : [];
		$result = [];
		//入库统计
		if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 1) {
			$total_in_stock = 0;
			if ($stats) {
				foreach ($stats as $value) {
					$total_in_stock = bcadd((string)$total_in_stock, (string)$value);
				}
			}
			$result = array_merge($result, [
				['name' => '总入库数', 'field' => '件', 'count' => abs($total_in_stock), 'className' => 'icondingdanjine', 'col' => 4],
				['name' => '采购入库', 'field' => '件', 'count' => abs($stats['purchase_stock'] ?? 0), 'className' => 'iconshouyintai-shouyin1', 'col' => 4,],
				['name' => '盘盈入库', 'field' => '件', 'count' => abs($stats['profit_stock']?? 0), 'className' => 'iconhexiaodingdanjine', 'col' => 4,],
				['name' => '退货入库', 'field' => '件', 'count' => abs($stats['return_stock']?? 0), 'className' => 'iconshouhou_tuikuan', 'col' => 4,],
				['name' => '其他入库', 'field' => '件', 'count' => abs($stats['other_in_stock']?? 0), 'className' => 'icontuikuandingdanliang', 'col' => 4,],
				['name' => '残次品转良品', 'field' => '件', 'count' => abs($stats['defective_to_good_stock']?? 0), 'className' => 'iconfenpeidingdanjine', 'col' => 4,]
			]);
		}
		//出库统计
		if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 2) {
			$total_out_stock = 0;
			if ($stats) {
				foreach ($stats as $value) {
					$total_out_stock = bcadd((string)$total_out_stock, (string)$value);
				}
			}
			$result = array_merge($result, [
				['name' => '总出库数', 'field' => '件', 'count' => abs($total_out_stock), 'className' => 'iconchuku', 'col' => 4,],
				['name' => '销售出库', 'field' => '件', 'count' => abs($stats['sale_stock'] ?? 0), 'className' => 'iconzaishoushangpin', 'col' => 4,],
				['name' => '盘亏出库', 'field' => '件', 'count' => abs($stats['loss_stock'] ?? 0), 'className' => 'icondaishenhe-shequneirong', 'col' => 4,],
				['name' => '过期退货', 'field' => '件', 'count' => abs($stats['expired_return_stock'] ?? 0), 'className' => 'iconshouyintai-tuihuo1', 'col' => 4,],
				['name' => '试用出库', 'field' => '件', 'count' => abs($stats['use_out_stock'] ?? 0), 'className' => 'iconshouhou-tuikuan-lv', 'col' => 4,],
				['name' => '报废出库', 'field' => '件', 'count' => abs($stats['scrap_out_stock'] ?? 0), 'className' => 'iconjingjiekucun', 'col' => 4,],
				['name' => '其他出库', 'field' => '件', 'count' => abs($stats['other_out_stock'] ?? 0), 'className' => 'icontuikuandingdanliang', 'col' => 4,],
				['name' => '良品转残次品', 'field' => '件', 'count' => abs($stats['good_to_defective_stock'] ?? 0), 'className' => 'iconfenpeidingdanjine', 'col' => 4,]
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
	public function getStockStatisticsList(int $stockType = 1, array $where = [], int $type = 0, int $relation_id = 0)
	{
		$where['type'] = $type;
		$where['relation_id'] = $relation_id;
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

		// 按商品规格唯一值分组统计，分别统计各种类型的数量
		$fieldArray = [
			'a.product_id','a.product_name', 'a.sku', 'a.image', 'a.code', 'a.bar_code',
			'a.unique',
			'SUM(a.stock + a.defective_stock) as total_stock'
		];

		// 根据查询类型添加对应的统计字段
		if (!isset($where['stock_type']) || $where['stock_type'] === '') {
			// 如果没有指定类型，统计所有类型
			foreach ($inStockTypes as $key => $type) {
				if ($key == 3) {//残次品转良品
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = " . ($key + 1) . " THEN a.stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = " . ($key + 1) . " THEN a.stock + a.defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
			foreach ($outStockTypes as $key => $type) {
				if ($key == 4) {//良品转残次品
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = " . ($key + 1) . " THEN a.defective_stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = " . ($key + 1) . " THEN a.stock + a.defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
		} elseif ($where['stock_type'] == 1) {
			// 入库统计
			foreach ($inStockTypes as $key => $type) {
				if ($key == 3) {//残次品转良品
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = " . ($key + 1) . " THEN a.stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 1 and a.order_type = " . ($key + 1) . " THEN a.stock + a.defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
		} elseif ($where['stock_type'] == 2) {
			// 出库统计
			foreach ($outStockTypes as $key => $type) {
				if ($key == 4) {//良品转残次品
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = " . ($key + 1) . " THEN a.defective_stock ELSE 0 END) as {$type}_stock";
				} else {
					$fieldArray[] = "SUM(CASE WHEN a.stock_type = 2 and a.order_type = " . ($key + 1) . " THEN a.stock + a.defective_stock ELSE 0 END) as {$type}_stock";
				}
			}
		}
		// 获取分组后的总数
		$countResult = $model->field('a.product_id, a.unique')->group('a.product_id, a.unique')->select();
		$count = count($countResult);
		$list = $model->field($fieldArray)
			->group('a.product_id, a.unique')
			->page($page, $limit)
			->order('s.sort DESC, s.id DESC')
			->select()
			->toArray();
		if ($list) {
			foreach ($list as &$item) {
				$item['total_stock'] = abs($item['total_stock'] ?? 0);
				//包含出库 数量转为正数展示
				if (!isset($where['stock_type']) || $where['stock_type'] === '' || $where['stock_type'] == 2) {
					foreach ($outStockTypes as $type) {
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
