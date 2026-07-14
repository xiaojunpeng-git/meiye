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
     * 门店分成款业绩（分成员工 is_fencheng=1 的销售/充值业绩）
     */
    protected function getStoreFenchengYeji(int $storeId, string $timeRange): string
    {
        if ($storeId <= 0 || $timeRange === '') {
            return '0.00';
        }
        $fenchengStaffIds = SystemStoreStaff::where('is_fencheng', 1)->column('id');
        if (!$fenchengStaffIds) {
            return '0.00';
        }
        /** @var StaffYejiDao $yejiDao */
        $yejiDao = app()->make(StaffYejiDao::class);
        $sum = $yejiDao->search([
            'store_id' => $storeId,
            'staff_id' => $fenchengStaffIds,
            'created_time' => $timeRange,
            'type' => [1, 2],
        ])->sum('yeji');
        return bcadd((string)($sum ?: 0), '0', 2);
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
        $order_where = ['paid' => 1,'not_old'=>1,'pid' =>-3, 'is_system_del' => 0, 'refund_status' =>0,'link_type'=>[0,1]];
        $hand_where = ['paid' => 1, 'pid' =>-2, 'is_system_del' => 0, 'refund_status' => [0, 3],'link_type'=>2];
        /** @var BranchOrderServices $branchOrderServices */
        $branchOrderServices = app()->make(BranchOrderServices::class);
        $data['store_income'] = $branchOrderServices->sumStoreCashIncome($where);
        $oldYeji=$this->oldYeji($where,1);
        $data['store_income']=bcadd($data['store_income'],$oldYeji,2);
        $data['store_writeoff_order_price']=$this->dao->sum($where + $hand_where, 'pay_price', true);
        $oldYeji=$this->oldYeji($where,2);
        $data['store_writeoff_order_price']=bcadd($data['store_writeoff_order_price'],$oldYeji,2);
		$storeIds = $where['store_id'] ?? [];
		if (!is_array($storeIds)) {
			$storeIds = $storeIds ? [(int)$storeIds] : [];
		}
		$storeIds = array_values(array_filter(array_map('intval', $storeIds)));
		$totalFencheng = '0.00';
		$rangeStr = $timeRangeStr !== '' ? $timeRangeStr : (date('Y/m/d', $start) . '-' . date('Y/m/d', $end));
		foreach ($storeIds as $storeId) {
			$totalFencheng = bcadd($totalFencheng, $this->getStoreFenchengYeji($storeId, $rangeStr), 2);
		}
		$data['actual_performance'] = bcsub((string)$data['store_income'], $totalFencheng, 2);
		if (bccomp($data['actual_performance'], '0', 2) < 0) {
			$data['actual_performance'] = '0.00';
		}
       	$result = [
			['title' => '现金业绩', 'number' => $data['store_income'], 'growth_rate' => 0],
			['title' => '实际业绩', 'number' => $data['actual_performance'], 'growth_rate' => 0],
			['title' => '客户消耗金额', 'number' => $data['store_writeoff_order_price'], 'growth_rate' => 0],
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
     * show_type 1收款金额 2消耗金额 7实际业绩(现金业绩-分成款)
	 * @param array $where
	 * @return array
	 */
	public function storeChart(array $where = [], string $orderBy = 'pay_price DESC')
	{
		$timeRangeStr = (string)($where['time'] ?? '');
		[$start, $end] = $this->timeHandle($where['time']);
		$where['time'] = [$start, $end];
        $showType = (int)($where['show_type'] ?? 1);
        if ($showType === 1 || $showType === 7) {
            //现金收款（排除旧卡录入 cash_choose=9，含组合支付明细）
            $order_where = ['paid' => 1, 'valid_cash_only' => 1, 'pid' => -2, 'is_system_del' => 0, 'refund_status' => 0, 'link_type' => [0, 1]];
            $where = $order_where + $where;
            $field = 'cash_pay_price';
        }else{
            $hand_where = ['paid' => 1, 'pid' =>-2, 'is_system_del' => 0, 'refund_status' => [0, 3],'link_type'=>2];
            $where=$hand_where+$where;
            $field="pay_price";
        }
		$sortOrder = 'DESC';
		$isUnitPrice = strpos($orderBy,'unit_price') !== false;
		if ($isUnitPrice) {//客单价
			$sortOrder = explode(' ', $orderBy)[1] ?? 'DESC';
			$orderBy = 'pay_price DESC';
		}
		$validCashAmount = ($showType === 1 || $showType === 7);
		$has = $this->dao->getAgentStoreOrderRanking($where, $orderBy, $field, $validCashAmount);
        $orderRanking=[];
        $agentStoreIds = $where['store_id'] ?? [];
        if (!is_array($agentStoreIds)) {
            $agentStoreIds = $agentStoreIds ? [(int)$agentStoreIds] : [];
        }
        $agentStoreIds = array_values(array_filter(array_map('intval', $agentStoreIds)));
        if (!$agentStoreIds) {
            return ['chart' => ['bing_xdata' => [], 'bing_data' => []], 'ranking' => []];
        }
        $store = SystemStore::where('is_show', 1)->where('is_del', 0)->whereIn('id', $agentStoreIds)->select();
        foreach ($store as $k=>$v){
            $result=[
                'store_id'=>$v['id'],
                'id'=>$v['id'],
                'order_number'=>0,
                'user_number'=>0,
                'pay_price'=>0,
                'name'=>$v['name']
            ];
            foreach ($has as $kk=>$vv){
                    if($vv['store_id'] == $v['id']){
                        $result=$vv;
                    }
            }
            $orderRanking[]=$result;
        }
		$ranking = [];
		$bing_data = $bing_xdata = $pay = [];
        $stores=[];
		$i = 1;
		$count = count($orderRanking) > 6 ? 5 : 6;
		$sumPayPrice = 0;
		if ($orderRanking) {
			foreach ($orderRanking as $order) {
                $oldWhere=$where;
                $oldWhere['store_id']=$order['store_id'];
                $cashType = ($showType === 7 || $showType === 1) ? 1 : $showType;
                $oldYeji=$this->oldYeji($oldWhere, $cashType);
                $order['pay_price']=bcadd($order['pay_price'],$oldYeji,2);
                if ($showType === 7) {
                    $rangeStr = $timeRangeStr !== '' ? $timeRangeStr : (date('Y/m/d', $start) . '-' . date('Y/m/d', $end));
                    $fenchengYeji = $this->getStoreFenchengYeji((int)$order['store_id'], $rangeStr);
                    $order['pay_price'] = bcsub((string)$order['pay_price'], $fenchengYeji, 2);
                    if (bccomp($order['pay_price'], '0', 2) < 0) {
                        $order['pay_price'] = '0.00';
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
