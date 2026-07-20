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

namespace app\services\order\agent;


use app\model\order\CombinationOrder;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\dao\yeji\StaffYejiDao;
use app\services\order\store\BranchOrderServices;
use app\services\order\ValidCashOrderServices;
use app\services\pay\PayServices;
use app\services\store\SystemStoreServices;
use app\services\BaseServices;
use mohe\traits\ServicesTrait;
use app\dao\order\StoreOrderDao;
use think\facade\Db;

/**
 * 供应商订单
 * Class SupplierOrderServices
 * @package app\sservices\order\supplier
 * @mixin StoreOrderDao
 */
class AgentOrderServices extends BaseServices
{

    use ServicesTrait;

    /**
     * SupplierOrderServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    //type 1现金业绩  2耗卡业绩
    public function oldYeji($where,$type)
    {
        $filed = "cash_money";
        if ($type == 2) {
            $filed = "use_money";
        }
        $info = Db::name("old_shop_money")
            ->when(isset($where['store_id']) && !empty($where['store_id']), function ($query) use ($where) {
                if (is_array($where['store_id'])) {
                    $query->whereIn('store_id', $where['store_id']);
                } else {
                    $query->where('store_id', $where['store_id']);
                }
            })->when(isset($where['time']) && !empty($where['time']), function ($query) use ($where) {
                $query->whereBetween("add_time", $where['time']);
            })
            ->sum($filed);
        return $info;
    }

    /**
     * 旧店业绩按店 GROUP BY。
     *
     * @param array $where 含 store_id、time
     * @param int $type 1=现金 cash_money 2=耗卡 use_money
     * @return array<int, string>
     */
    public function mapOldYejiByStores(array $where, int $type): array
    {
        $both = $this->mapOldYejiCashAndConsumeByStores($where);
        return $type == 2 ? $both['consume'] : $both['cash'];
    }

    /**
     * 旧店现金+耗卡一次按店聚合（口径同分别调用 mapOldYejiByStores(type=1/2)）。
     *
     * @param array $where 含 store_id、time
     * @return array{cash: array<int,string>, consume: array<int,string>}
     */
    public function mapOldYejiCashAndConsumeByStores(array $where): array
    {
        $storeIds = $where['store_id'] ?? [];
        if (!is_array($storeIds)) {
            $storeIds = $storeIds !== '' && $storeIds !== null ? [(int)$storeIds] : [];
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $cash = [];
        $consume = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $cash[$sid] = '0.00';
                $consume[$sid] = '0.00';
            }
        }
        if (!$cash) {
            return ['cash' => [], 'consume' => []];
        }
        $rows = Db::name('old_shop_money')
            ->whereIn('store_id', array_keys($cash))
            ->when(!empty($where['time']), function ($query) use ($where) {
                $query->whereBetween('add_time', $where['time']);
            })
            ->field('store_id, SUM(cash_money) AS cash_total, SUM(use_money) AS use_total')
            ->group('store_id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if (isset($cash[$sid])) {
                $cash[$sid] = bcadd('0', (string)($row['cash_total'] ?? 0), 2);
                $consume[$sid] = bcadd('0', (string)($row['use_total'] ?? 0), 2);
            }
        }
        return ['cash' => $cash, 'consume' => $consume];
    }

    /**
     * 有效现金按店 map（委托 ValidCash）。
     *
     * @return array<int, string>
     */
    public function mapStoreCashIncomeByStores(array $where): array
    {
        return ValidCashOrderServices::mapStoreCashIncomeByStores($where);
    }

    /**
     * 分成员工 staff_yeji 按店合计（type∈[1,2]）。
     *
     * @param int[] $storeIds
     * @return array<int, string>
     */
    public function mapFenchengYejiByStores(array $storeIds, string $fenchengRangeStr): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $out = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = '0.00';
            }
        }
        if (!$out || $fenchengRangeStr === '') {
            return $out;
        }
        $times = explode('-', $fenchengRangeStr);
        $timeStart = trim((string)($times[0] ?? ''));
        if ($timeStart === '') {
            return $out;
        }
        $timeEnd = isset($times[1])
            ? (date('Y/m/d', strtotime(trim((string)$times[1]))) . ' 23:59:59')
            : date('Y/m/d H:i:s');
        // 一次 JOIN：分成员工 + staff_yeji 聚合（口径同先查 is_fencheng=1 再按 type∈[1,2]/status=0 汇总）
        $rows = Db::name('staff_yeji')->alias('y')
            ->join('system_store_staff s', 's.id = y.staff_id')
            ->where('s.is_fencheng', 1)
            ->whereIn('y.store_id', array_keys($out))
            ->whereIn('y.type', [1, 2])
            ->where('y.status', 0)
            ->whereTime('y.created_time', 'between', [$timeStart, $timeEnd])
            ->field('y.store_id, SUM(y.yeji) AS total')
            ->group('y.store_id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if (isset($out[$sid])) {
                $out[$sid] = bcadd('0', (string)($row['total'] ?? 0), 2);
            }
        }
        return $out;
    }

    /**
     * 门店分成款业绩合计（is_fencheng=1 员工的销售/充值业绩 type∈[1,2]）
     * 实收明细扣减行 / 单店扣减共用；多店实收总额请用 sumActualPerformanceByStores（逐店封顶再求和）。
     *
     * @param int|int[] $storeIdOrIds
     */
    public function sumStoreFenchengYeji($storeIdOrIds, string $timeRange): string
    {
        if ($timeRange === '') {
            return '0.00';
        }
        $storeIds = is_array($storeIdOrIds) ? $storeIdOrIds : [(int)$storeIdOrIds];
        $storeIds = array_values(array_filter(array_map('intval', $storeIds)));
        if (!$storeIds) {
            return '0.00';
        }
        $map = $this->mapFenchengYejiByStores($storeIds, $timeRange);
        $total = '0.00';
        foreach ($map as $sum) {
            $total = bcadd($total, (string)($sum ?: 0), 2);
        }
        return $total;
    }

    /**
     * 分成员工 staff_yeji 明细行（与 sumStoreFenchengYeji 同条件）
     *
     * @param int|int[] $storeIdOrIds
     * @return array<int, array>
     */
    public function listStoreFenchengYeji($storeIdOrIds, string $timeRange, int $limit = 100): array
    {
        if ($timeRange === '' || $limit <= 0) {
            return [];
        }
        $storeIds = is_array($storeIdOrIds) ? $storeIdOrIds : [(int)$storeIdOrIds];
        $storeIds = array_values(array_filter(array_map('intval', $storeIds)));
        if (!$storeIds) {
            return [];
        }
        $fenchengStaffIds = SystemStoreStaff::where('is_fencheng', 1)->column('id');
        if (!$fenchengStaffIds) {
            return [];
        }
        $storeParam = count($storeIds) === 1 ? $storeIds[0] : $storeIds;
        /** @var StaffYejiDao $yejiDao */
        $yejiDao = app()->make(StaffYejiDao::class);
        return $yejiDao->search([
            'store_id' => $storeParam,
            'staff_id' => $fenchengStaffIds,
            'created_time' => $timeRange,
            'type' => [1, 2],
        ])
            ->field('id,store_id,staff_id,staff_name,type,yeji,created_time,order_id')
            ->order('created_time DESC,id DESC')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * @deprecated 请用 sumStoreFenchengYeji；保留给图表等单店调用
     */
    protected function getStoreFenchengYeji(int $storeId, string $timeRange): string
    {
        return $this->sumStoreFenchengYeji($storeId, $timeRange);
    }

    /**
     * 实际业绩按店 map：预取现金/旧店/分成后逐店 max(0, cash−fencheng)。
     *
     * @param int|int[] $storeIdOrIds
     * @param array $cashWhere 与现金同口径的 where（须含 time）
     * @param string $fenchengRangeStr staff_yeji 时间串，如 2026/07/01-2026/07/16
     * @param array|null $prefetched 可选预取：['cash'=>map,'old'=>map,'fencheng'=>map]，避免排行重复查现金
     * @return array<int, string>
     */
    public function mapActualPerformanceByStores($storeIdOrIds, array $cashWhere, string $fenchengRangeStr, ?array $prefetched = null): array
    {
        $storeIds = is_array($storeIdOrIds) ? $storeIdOrIds : [(int)$storeIdOrIds];
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $out = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = '0.00';
            }
        }
        if (!$out || $fenchengRangeStr === '') {
            return $out;
        }
        $cashWhere['store_id'] = array_keys($out);
        $cashMap = isset($prefetched['cash']) && is_array($prefetched['cash'])
            ? $prefetched['cash']
            : $this->mapStoreCashIncomeByStores($cashWhere);
        $oldMap = isset($prefetched['old']) && is_array($prefetched['old'])
            ? $prefetched['old']
            : $this->mapOldYejiByStores($cashWhere, 1);
        $fenchengMap = isset($prefetched['fencheng']) && is_array($prefetched['fencheng'])
            ? $prefetched['fencheng']
            : $this->mapFenchengYejiByStores(array_keys($out), $fenchengRangeStr);
        foreach ($out as $sid => $_) {
            $cash = bcadd((string)($cashMap[$sid] ?? '0'), (string)($oldMap[$sid] ?? '0'), 2);
            $actual = bcsub($cash, (string)($fenchengMap[$sid] ?? '0'), 2);
            if (bccomp($actual, '0', 2) < 0) {
                $actual = '0.00';
            }
            $out[$sid] = $actual;
        }
        return $out;
    }

    /**
     * 实收业绩权威聚合（产品 2026-07-16 拍板）：
     * 逐店 max(0, 现金业绩 − 分成员工业绩)，再对授权门店求和。
     * 首页 / 数仓总额 / 明细 header_number / storeChart(show_type=7) 单店行必须同此口径；
     * 禁止用 max(0, 全店现金合计 − 全部分成合计)。
     *
     * @param int|int[] $storeIdOrIds
     * @param array $cashWhere 与现金同口径的 where（须含 time）；本方法按店覆写 store_id
     * @param string $fenchengRangeStr staff_yeji 时间串，如 2026/07/01-2026/07/16
     */
    public function sumActualPerformanceByStores($storeIdOrIds, array $cashWhere, string $fenchengRangeStr): string
    {
        $map = $this->mapActualPerformanceByStores($storeIdOrIds, $cashWhere, $fenchengRangeStr);
        $total = '0.00';
        foreach ($map as $actual) {
            $total = bcadd($total, (string)$actual, 2);
        }
        return $total;
    }

    /**
	 * 首页头部统计
	 * @param array $where
	 * @return \string[][]
	 */
	public function homeStatics(array $where = [])
	{
		$timeRangeStr = (string)($where['time'] ?? '');
		[$start, $end, $beforeStart, $beforeEnd] = $this->timeHandle($where['time'], false, true);
        $where['time'] = [$start, $end];
		$storeIds = $where['store_id'] ?? [];
		if (!is_array($storeIds)) {
			$storeIds = $storeIds ? [(int)$storeIds] : [];
		}
		$storeIds = array_values(array_filter(array_map('intval', $storeIds)));
		$rangeStr = $timeRangeStr !== '' ? $timeRangeStr : (date('Y/m/d', $start) . '-' . date('Y/m/d', $end));

        // 批量：现金 = ValidCash map + 旧店；实际 = 逐店封顶 map 求和；消耗 = activeYeji map + 旧店耗卡
        $cashMap = $this->mapStoreCashIncomeByStores($where);
        $oldBoth = $this->mapOldYejiCashAndConsumeByStores($where);
        $oldCashMap = $oldBoth['cash'] ?? [];
        $oldConsumeMap = $oldBoth['consume'] ?? [];
        $storeIncome = '0.00';
        foreach ($storeIds as $sid) {
            $storeIncome = bcadd(
                $storeIncome,
                bcadd((string)($cashMap[$sid] ?? '0'), (string)($oldCashMap[$sid] ?? '0'), 2),
                2
            );
        }
        // 无门店列表时回退合计（兼容旧调用）
        if (!$storeIds) {
            /** @var BranchOrderServices $branchOrderServices */
            $branchOrderServices = app()->make(BranchOrderServices::class);
            $storeIncome = bcadd((string)$branchOrderServices->sumStoreCashIncome($where), (string)$this->oldYeji($where, 1), 2);
        }
        $data['store_income'] = $storeIncome;

        /** @var \app\services\report\ReportServices $reportServices */
        $reportServices = app()->make(\app\services\report\ReportServices::class);
        $activeMap = $reportServices->mapActiveYejiByStores($where);
        $writeoff = '0.00';
        foreach ($storeIds as $sid) {
            $writeoff = bcadd(
                $writeoff,
                bcadd((string)($activeMap[$sid] ?? '0'), (string)($oldConsumeMap[$sid] ?? '0'), 2),
                2
            );
        }
        if (!$storeIds) {
            $writeoff = bcadd((string)$reportServices->sumActiveYejiByStores($where), (string)$this->oldYeji($where, 2), 2);
        }
        $data['store_writeoff_order_price'] = $writeoff;

		// 实收：复用已预取现金 map，避免再查一遍 ValidCash
		$fenchengMap = $this->mapFenchengYejiByStores($storeIds, $rangeStr);
		$actualTotal = '0.00';
		foreach ($storeIds as $sid) {
			$cash = bcadd((string)($cashMap[$sid] ?? '0'), (string)($oldCashMap[$sid] ?? '0'), 2);
			$actual = bcsub($cash, (string)($fenchengMap[$sid] ?? '0'), 2);
			if (bccomp($actual, '0', 2) < 0) {
				$actual = '0.00';
			}
			$actualTotal = bcadd($actualTotal, $actual, 2);
		}
		$data['actual_performance'] = $actualTotal;
		$result = [
			['title' => '现金业绩', 'number' => $data['store_income'], 'growth_rate' => 0, 'metric_code' => 'cash_performance'],
			['title' => '实际业绩', 'number' => $data['actual_performance'], 'growth_rate' => 0, 'metric_code' => 'actual_performance'],
			['title' => '消耗业绩', 'number' => $data['store_writeoff_order_price'], 'growth_rate' => 0, 'metric_code' => 'consume_amount'],
		];
		return $result;
	}

	/**
	 * 订单图表
	 * @param array $where
	 * @return array|array[]
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function orderCharts(array $where = [])
	{
		$series1 = ['normal' => ['color' => [
				'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
				'colorStops' => [
					[
						'offset' => 0,
						'color' => '#2D8CF0'
					],
					[
						'offset' => 0.5,
						'color' => '#2D8CF0'
					],
					[
						'offset' => 1,
						'color' => '#2D8CF0'
					]
				]
			]]
		];
		$series2 = ['normal' => ['color' => [
				'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
				'colorStops' => [
					[
						'offset' => 0,
						'color' => '#0FC6C2'
					],
					[
						'offset' => 0.5,
						'color' => '#0FC6C2'
					],
					[
						'offset' => 1,
						'color' => '#0FC6C2'
					]
				]
			]]
		];
		$chartdata = [];
		$data = [];//临时
		$chartdata['yAxis']['maxnum'] = 0;//最大值数量
		$chartdata['yAxis']['maxprice'] = 0;//最大值金额
		[$start, $end, $timeType, $timeKey, $beforeStart, $beforeEnd] = $this->timeHandle($where['time'], true, true);
		unset($where['time']);
		$order_list = $this->dao->orderAddTimeList($where, [$start, $end], $timeType);
		if ($order_list) {
			$order_list = array_combine(array_column($order_list, 'day'), $order_list);
		}
//		if (empty($order_list)) return ['yAxis' => [], 'legend' => [], 'xAxis' => [], 'serise' => [], 'pre_cycle' => [], 'cycle' => []];
		$cycle_list = [];
		foreach ($timeKey as $dd) {
			if (isset($order_list[$dd]) && !empty($order_list[$dd])) {
				$cycle_list[$dd] = $order_list[$dd];
			} else {
				$cycle_list[$dd] = ['count' => 0, 'day' => $dd, 'price' => ''];
			}
		}
		foreach ($cycle_list as $k => $v) {
			$data['day'][] = $v['day'];
			$data['count'][] = $v['count'];
			$data['price'][] = round($v['price'], 2);
			if ($chartdata['yAxis']['maxnum'] < $v['count'])
				$chartdata['yAxis']['maxnum'] = $v['count'];//日最大订单数
			if ($chartdata['yAxis']['maxprice'] < $v['price'])
				$chartdata['yAxis']['maxprice'] = $v['price'];//日最大金额
		}
		$chartdata['legend'] = ['订单金额', '订单数'];//分类
		$chartdata['xAxis'] = $data['day'];//X轴值
		$chartdata['series'][] = ['name' => $chartdata['legend'][0], 'type' => 'bar', 'itemStyle' => $series1, 'data' => $data['price']];//分类1值
		$chartdata['series'][] = ['name' => $chartdata['legend'][1], 'type' => 'line', 'itemStyle' => $series2, 'data' => $data['count'], 'yAxisIndex' => 1];//分类2值

		//统计总数上期
		$pre_total = $this->dao->preTotalFind($where, [$beforeStart, $beforeEnd]);
		if ($pre_total) {
			$chartdata['pre_cycle']['count'] = [
				'data' => $pre_total['count'] ?: 0
			];
			$chartdata['pre_cycle']['price'] = [
				'data' => $pre_total['price'] ?: 0
			];
		}
		//统计总数
		$total = $this->dao->preTotalFind($where, [$start, $end]);
		if ($total) {
			$cha_count = intval($pre_total['count']) - intval($total['count']);
			$pre_total['count'] = $pre_total['count'] == 0 ? 1 : $pre_total['count'];
			$chartdata['cycle']['count'] = [
				'data' => $total['count'] ?: 0,
				'percent' => round((abs($cha_count) / intval($pre_total['count']) * 100), 2),
				'is_plus' => $cha_count > 0 ? -1 : ($cha_count == 0 ? 0 : 1)
			];
			$cha_price = round($pre_total['price'], 2) - round($total['price'], 2);
			$pre_total['price'] = $pre_total['price'] == 0 ? 1 : $pre_total['price'];
			$chartdata['cycle']['price'] = [
				'data' => $total['price'] ?: 0,
				'percent' => round(abs($cha_price) / $pre_total['price'] * 100, 2),
				'is_plus' => $cha_price > 0 ? -1 : ($cha_price == 0 ? 0 : 1)
			];
		}
		return $chartdata;
	}


	/**
	 * 门店统计、排行
     * show_type 1收款金额 2消耗金额(activeYeji+旧店耗卡，与 homeStatics 消耗同口径) 7实收业绩(逐店 max(0,现金−分成)，与 homeStatics 实收同口径)
	 * @param array $where
	 * @return array
	 */
	public function storeChart(array $where = [], string $orderBy = 'pay_price DESC')
	{
		$timeRangeStr = (string)($where['time'] ?? '');
		[$start, $end] = $this->timeHandle($where['time']);
		$where['time'] = [$start, $end];
        $showType = (int)($where['show_type'] ?? 1);

        $agentStoreIds = $where['store_id'] ?? [];
        if (!is_array($agentStoreIds)) {
            $agentStoreIds = $agentStoreIds ? [(int)$agentStoreIds] : [];
        }
        $agentStoreIds = array_values(array_filter(array_map('intval', $agentStoreIds)));
        if (!$agentStoreIds) {
            return ['chart' => ['bing_xdata' => [], 'bing_data' => []], 'ranking' => []];
        }

		$sortOrder = 'DESC';
		$isUnitPrice = strpos($orderBy,'unit_price') !== false;
		if ($isUnitPrice) {//客单价
			$sortOrder = explode(' ', $orderBy)[1] ?? 'DESC';
			$orderBy = 'pay_price DESC';
		}

        $orderRanking = [];
        if ($showType === 2) {
            // 客户消耗：逐店 activeYeji + oldYeji(type=2)，与 homeStatics / consumeDetail 同口径
            /** @var \app\services\report\ReportServices $reportServices */
            $reportServices = app()->make(\app\services\report\ReportServices::class);
            $store = SystemStore::where('is_show', 1)->where('is_del', 0)->whereIn('id', $agentStoreIds)->select();
            foreach ($store as $v) {
                $sid = (int)$v['id'];
                $storeWhere = [
                    'time' => [$start, $end],
                    'store_id' => $sid,
                ];
                $active = bcadd((string)$reportServices->activeYeji($storeWhere), '0', 2);
                $old = bcadd((string)$this->oldYeji($storeWhere, 2), '0', 2);
                $orderRanking[] = [
                    'store_id' => $sid,
                    'id' => $sid,
                    'order_number' => 0,
                    'user_number' => 0,
                    'pay_price' => bcadd($active, $old, 2),
                    'name' => $v['name'],
                ];
            }
        } else {
            if ($showType === 1 || $showType === 7) {
                //现金收款（排除旧卡录入 cash_choose=9，含组合支付明细）
                $order_where = ['paid' => 1, 'valid_cash_only' => 1, 'pid' => -2, 'is_system_del' => 0, 'refund_status' => 0, 'link_type' => [0, 1]];
                $where = $order_where + $where;
                $field = 'cash_pay_price';
            } else {
                $hand_where = ['paid' => 1, 'pid' => -2, 'is_system_del' => 0, 'refund_status' => [0, 3], 'link_type' => 2];
                $where = $hand_where + $where;
                $field = 'pay_price';
            }
            $validCashAmount = ($showType === 1 || $showType === 7);
            $has = $this->dao->getAgentStoreOrderRanking($where, $orderBy, $field, $validCashAmount);
            $store = SystemStore::where('is_show', 1)->where('is_del', 0)->whereIn('id', $agentStoreIds)->select();
            foreach ($store as $v) {
                $result = [
                    'store_id' => $v['id'],
                    'id' => $v['id'],
                    'order_number' => 0,
                    'user_number' => 0,
                    'pay_price' => 0,
                    'name' => $v['name'],
                ];
                foreach ($has as $vv) {
                    if ($vv['store_id'] == $v['id']) {
                        $result = $vv;
                    }
                }
                $orderRanking[] = $result;
            }
        }

		$ranking = [];
		$bing_data = $bing_xdata = $pay = [];
        $stores=[];
		$i = 1;
		$count = count($orderRanking) > 6 ? 5 : 6;
		$sumPayPrice = 0;
		if ($orderRanking) {
			foreach ($orderRanking as $order) {
                if ($showType !== 2) {
                    $oldWhere = $where;
                    $oldWhere['store_id'] = $order['store_id'];
                    $cashType = ($showType === 7 || $showType === 1) ? 1 : $showType;
                    $oldYeji = $this->oldYeji($oldWhere, $cashType);
                    $order['pay_price'] = bcadd($order['pay_price'], $oldYeji, 2);
                    if ($showType === 7) {
                        $rangeStr = $timeRangeStr !== '' ? $timeRangeStr : (date('Y/m/d', $start) . '-' . date('Y/m/d', $end));
                        $fenchengYeji = $this->getStoreFenchengYeji((int)$order['store_id'], $rangeStr);
                        $order['pay_price'] = bcsub((string)$order['pay_price'], $fenchengYeji, 2);
                        if (bccomp($order['pay_price'], '0', 2) < 0) {
                            $order['pay_price'] = '0.00';
                        }
                    }
                }
				$ranking[] = [
					'name' => $order['name'] ?? '',
					'store_id' => $order['store_id'] ?? '',
					'number' => $order['pay_price'] ?? 0.00,
					'sales' => $order['order_number'] ?? 0,
					'unit_price' => ($order['user_number'] ?? 0) ? (float)bcdiv((string)($order['pay_price'] ?? 0), (string)($order['user_number'] ?? 0), 2) : 0.00
				];
				if ($i <= $count) {
					$bing_xdata[] = $order['name'] ?? '';
                    $stores[]=$order['store_id'] ?? '';
					$pay[] = (float)($order['pay_price'] ?? 0.00);
				}
				$i++;
			}

			$sumPayPrice = array_sum(array_column($ranking, 'number'));
			if ($count == 5) {
				$bing_xdata[] = '其他门店';
                $stores[] = 0;
				$pay[] = (float)bcsub((string)$sumPayPrice, array_sum($pay), 2);
			}
				$typeArr = array_column($ranking, 'number');
				array_multisort($typeArr, $sortOrder == 'asc' ? SORT_ASC : SORT_DESC, $ranking);
		}
		$chartdata['bing_xdata'] = $bing_xdata;
		$color = ['#2D8CF0', '#21CCFF', '#FF9900', '#FFCD27', '#F95C96', '#ED4014'];
		foreach ($pay as $key => $item) {
			$bing_data[] = [
				'name' => $bing_xdata[$key],
				'store_id' => $stores[$key],
				'value' => $item,
				'itemStyle' => ['color' => $color[$key]],
//				'labelText' => $item ? bcmul(bcdiv((string)$item, (string)$sumPayPrice, 4), 100, 2) : 0,
			];
		}
		$chartdata['bing_data'] = $bing_data;

		return ['chart' => $chartdata, 'ranking' => $ranking];
	}


}
