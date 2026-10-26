<?php
namespace app\services\statistics;

use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\user\User;
use app\services\BaseServices;
use app\services\merchant\MerchantBusinessSecondaryMetricServices;
use app\services\merchant\MerchantCustomerMetricServices;
use app\services\metric\MetricDictionaryServices;
use app\services\order\agent\AgentOrderServices;
use app\services\order\ValidCashOrderServices;
use app\services\report\ReportServices;
use mohe\traits\ServicesTrait;
use think\facade\Db;

/**
 * 经营看板编排：概览 / 趋势 / 门店排行 / 员工排行 / 明细。
 * 指标口径只复用既有源服务，禁止在此重写公式。
 */
class BusinessDashboardServices extends BaseServices
{
    use ServicesTrait;

    public const METRIC_CODES = [
        'cash_performance',
        'actual_performance',
        'consume_amount',
        'refund_amount',
        'recharge_amount',
        'balance_deduction_amount',
        'new_profile_count',
        'casual_customer_count',
        'new_customer_count',
        'reservation_customer',
    ];

    public const DETAIL_TYPE = [
        'cash_performance' => 'business_money',
        'actual_performance' => 'business_money',
        'consume_amount' => 'business_money',
        'refund_amount' => 'business_money',
        'recharge_amount' => 'business_money',
        'balance_deduction_amount' => 'business_money',
        'new_profile_count' => 'customer',
        'casual_customer_count' => 'report_sale',
        'new_customer_count' => 'report_sale',
        'reservation_customer' => 'appointment',
    ];

    /** 金额类经营明细（走适配层，禁止伪装普通订单列表） */
    public const MONEY_DETAIL_METRICS = [
        'cash_performance',
        'actual_performance',
        'consume_amount',
        'refund_amount',
        'recharge_amount',
        'balance_deduction_amount',
    ];

    public const UNIT = [
        'cash_performance' => '元',
        'actual_performance' => '元',
        'consume_amount' => '元',
        'refund_amount' => '元',
        'recharge_amount' => '元',
        'balance_deduction_amount' => '元',
        'new_profile_count' => '人',
        'casual_customer_count' => '人',
        'new_customer_count' => '人',
        'reservation_customer' => '人',
    ];

    public const STAFF_SORT_WHITELIST = [
        'cash_performance',
        'labor_performance',
        'dianke_count',
        'service_customer_count',
        'service_project_count',
    ];

    /**
     * 一次预取全部按店 map（排行与概览共用）。
     *
     * @param int[] $storeIds
     * @return array<string, array<int, float|int|string>>
     */
    public function collectStoreMetricMaps(array $storeIds, int $startTs, int $endTs, string $timeRangeStr): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $empty = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $empty[$sid] = 0;
            }
        }
        if (!$empty || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return [
                'cash_performance' => [],
                'actual_performance' => [],
                'consume_amount' => [],
                'refund_amount' => [],
                'recharge_amount' => [],
                'balance_deduction_amount' => [],
                'new_profile_count' => [],
                'casual_customer_count' => [],
                'new_customer_count' => [],
                'reservation_customer' => [],
            ];
        }
        $ids = array_keys($empty);
        $cashWhere = ['store_id' => $ids, 'time' => [$startTs, $endTs]];
        $rangeStr = $timeRangeStr !== ''
            ? $timeRangeStr
            : (date('Y/m/d', $startTs) . '-' . date('Y/m/d', $endTs));

        /** @var AgentOrderServices $agent */
        $agent = app()->make(AgentOrderServices::class);
        /** @var ReportServices $report */
        $report = app()->make(ReportServices::class);
        /** @var MerchantBusinessSecondaryMetricServices $secondary */
        $secondary = app()->make(MerchantBusinessSecondaryMetricServices::class);
        /** @var MerchantCustomerMetricServices $customer */
        $customer = app()->make(MerchantCustomerMetricServices::class);

        $cashMap = $agent->mapStoreCashIncomeByStores($cashWhere);
        $oldBoth = $agent->mapOldYejiCashAndConsumeByStores($cashWhere);
        $oldCashMap = $oldBoth['cash'] ?? [];
        $oldConsumeMap = $oldBoth['consume'] ?? [];
        $fenchengMap = $agent->mapFenchengYejiByStores($ids, $rangeStr);
        $cashPerf = [];
        foreach ($ids as $sid) {
            $cashPerf[$sid] = (float)bcadd((string)($cashMap[$sid] ?? '0'), (string)($oldCashMap[$sid] ?? '0'), 2);
        }
        // 复用已取现金/旧店/分成，禁止 mapActual 内再查一遍现金
        $actualMap = $agent->mapActualPerformanceByStores($ids, $cashWhere, $rangeStr, [
            'cash' => $cashMap,
            'old' => $oldCashMap,
            'fencheng' => $fenchengMap,
        ]);
        $activeMap = $report->mapActiveYejiByStores($cashWhere);
        $consumeMap = [];
        foreach ($ids as $sid) {
            $consumeMap[$sid] = (float)bcadd((string)($activeMap[$sid] ?? '0'), (string)($oldConsumeMap[$sid] ?? '0'), 2);
        }
        $actualFloat = [];
        foreach ($ids as $sid) {
            $actualFloat[$sid] = (float)($actualMap[$sid] ?? 0);
        }

        return [
            'cash_performance' => $cashPerf,
            'actual_performance' => $actualFloat,
            'consume_amount' => $consumeMap,
            'refund_amount' => $secondary->mapRefundAmountByStores($ids, $startTs, $endTs),
            'recharge_amount' => $this->mapRechargeAmountByStores($ids, $startTs, $endTs),
            'balance_deduction_amount' => $this->mapBalanceDeductionByStores($ids, $startTs, $endTs),
            'new_profile_count' => $this->mapNewProfileCountByStores($ids, $startTs, $endTs),
            'casual_customer_count' => $report->countSourceOrderByStores($ids, 0, [$startTs, $endTs]),
            'new_customer_count' => $report->countSourceOrderByStores($ids, 1, [$startTs, $endTs]),
            'reservation_customer' => $customer->mapReservationCustomerByStores($ids, $startTs, $endTs),
        ];
    }

    /**
     * @param int[] $storeIds
     * @return array{cards:array,group_root_org_id:int}
     */
    public function overview(array $storeIds, int $startTs, int $endTs, string $timeRangeStr): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $cards = [];
        if (!$storeIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            foreach (self::METRIC_CODES as $code) {
                $cards[] = $this->buildCard($code, 0);
            }
            return ['cards' => $cards];
        }

        $rangeStr = $timeRangeStr !== ''
            ? $timeRangeStr
            : (date('Y/m/d', $startTs) . '-' . date('Y/m/d', $endTs));

        /** @var AgentOrderServices $agent */
        $agent = app()->make(AgentOrderServices::class);
        /** @var ReportServices $report */
        $report = app()->make(ReportServices::class);
        /** @var MerchantBusinessSecondaryMetricServices $secondary */
        $secondary = app()->make(MerchantBusinessSecondaryMetricServices::class);
        /** @var MerchantCustomerMetricServices $customer */
        $customer = app()->make(MerchantCustomerMetricServices::class);

        // 三项业绩：对外只取 homeStatics（内部已批量）
        $home = $agent->homeStatics([
            'store_id' => $storeIds,
            'time' => $rangeStr,
        ]);
        $homeByCode = [];
        foreach ($home as $row) {
            if (!empty($row['metric_code'])) {
                $homeByCode[$row['metric_code']] = $row['number'] ?? 0;
            }
        }

        // 其余 7 卡：按店 map 后求和（不重复跑现金/实际/消耗 map）
        $casualMap = $report->countSourceOrderByStores($storeIds, 0, [$startTs, $endTs]);
        $newMap = $report->countSourceOrderByStores($storeIds, 1, [$startTs, $endTs]);
        $refundMap = $secondary->mapRefundAmountByStores($storeIds, $startTs, $endTs);
        $rechargeMap = $this->mapRechargeAmountByStores($storeIds, $startTs, $endTs);
        $balanceMap = $this->mapBalanceDeductionByStores($storeIds, $startTs, $endTs);
        $profileMap = $this->mapNewProfileCountByStores($storeIds, $startTs, $endTs);
        $reserveMap = $customer->mapReservationCustomerByStores($storeIds, $startTs, $endTs);

        $values = [
            'cash_performance' => $homeByCode['cash_performance'] ?? 0,
            'actual_performance' => $homeByCode['actual_performance'] ?? 0,
            'consume_amount' => $homeByCode['consume_amount'] ?? 0,
            'refund_amount' => array_sum($refundMap),
            'recharge_amount' => array_sum($rechargeMap),
            'balance_deduction_amount' => array_sum($balanceMap),
            'new_profile_count' => array_sum($profileMap),
            'casual_customer_count' => array_sum($casualMap),
            'new_customer_count' => array_sum($newMap),
            'reservation_customer' => array_sum($reserveMap),
        ];
        foreach (self::METRIC_CODES as $code) {
            $cards[] = $this->buildCard($code, $values[$code] ?? 0);
        }
        return ['cards' => $cards];
    }

    /**
     * @param int[] $storeIds
     */
    public function storeRanking(
        array $storeIds,
        int $startTs,
        int $endTs,
        string $timeRangeStr,
        string $sortBy = 'cash_performance',
        string $sortOrder = 'desc'
    ): array {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!in_array($sortBy, self::METRIC_CODES, true)) {
            $sortBy = 'cash_performance';
        }
        $sortOrder = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';
        if (!$storeIds || $startTs <= 0 || $endTs <= 0) {
            return ['list' => [], 'sort_by' => $sortBy, 'sort_order' => $sortOrder];
        }
        $maps = $this->collectStoreMetricMaps($storeIds, $startTs, $endTs, $timeRangeStr);
        $names = SystemStore::whereIn('id', $storeIds)->column('name', 'id');
        $rows = [];
        foreach ($storeIds as $sid) {
            $row = [
                'store_id' => $sid,
                'store_name' => (string)($names[$sid] ?? ('门店' . $sid)),
            ];
            foreach (self::METRIC_CODES as $code) {
                $row[$code] = $maps[$code][$sid] ?? 0;
            }
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) use ($sortBy, $sortOrder) {
            $va = (float)($a[$sortBy] ?? 0);
            $vb = (float)($b[$sortBy] ?? 0);
            if ($va == $vb) {
                return strcmp((string)$a['store_name'], (string)$b['store_name']);
            }
            if ($sortOrder === 'asc') {
                return $va <=> $vb;
            }
            return $vb <=> $va;
        });
        $top = array_slice($rows, 0, 20);
        $rank = 1;
        foreach ($top as &$item) {
            $item['rank'] = $rank++;
        }
        unset($item);
        return ['list' => $top, 'sort_by' => $sortBy, 'sort_order' => $sortOrder];
    }

    /**
     * 员工排行（单店，禁止逐员工 staffInfo）。
     */
    public function staffRanking(
        int $storeId,
        int $startTs,
        int $endTs,
        string $timeRangeStr,
        string $sortBy = 'cash_performance',
        string $sortOrder = 'desc'
    ): array {
        if (!in_array($sortBy, self::STAFF_SORT_WHITELIST, true)) {
            $sortBy = 'cash_performance';
        }
        $sortOrder = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';
        if ($storeId <= 0 || $startTs <= 0 || $endTs <= 0) {
            return ['list' => [], 'sort_by' => $sortBy, 'sort_order' => $sortOrder];
        }
        $rangeStr = $timeRangeStr !== ''
            ? $timeRangeStr
            : (date('Y/m/d', $startTs) . '-' . date('Y/m/d', $endTs));
        $times = explode('-', $rangeStr);
        $timeStart = $times[0] ?? date('Y/m/d');
        $timeEnd = isset($times[1])
            ? (date('Y/m/d', strtotime($times[1])) . ' 23:59:59')
            : date('Y/m/d H:i:s');

        // SQL1：员工名单
        $staffList = SystemStoreStaff::where('store_id', $storeId)
            ->where('is_del', 0)
            ->field('id,staff_name')
            ->select()
            ->toArray();
        if (!$staffList) {
            return ['list' => [], 'sort_by' => $sortBy, 'sort_order' => $sortOrder];
        }
        $staffIds = array_values(array_filter(array_map('intval', array_column($staffList, 'id'))));
        $nameMap = [];
        foreach ($staffList as $s) {
            $nameMap[(int)$s['id']] = (string)($s['staff_name'] ?? '');
        }

        // SQL2：一次聚合 moneyYeji / 劳动 / 点客订单数 / 服务项目条数（对齐 staffInfo 口径）
        $moneyMap = [];
        $laborMap = [];
        $diankeMap = [];
        $projectMap = [];
        $aggRows = Db::name('staff_yeji')
            ->where('store_id', $storeId)
            ->where('status', 0)
            ->whereIn('staff_id', $staffIds)
            ->whereTime('created_time', 'between', [$timeStart, $timeEnd])
            ->field("staff_id,
                SUM(yeji) AS money_total,
                SUM(CASE WHEN type = 3 THEN yeji ELSE 0 END) AS labor_total,
                COUNT(DISTINCT CASE WHEN is_dian = 1 THEN order_id END) AS dianke_cnt,
                SUM(CASE WHEN type = 3 THEN 1 ELSE 0 END) AS project_cnt")
            ->group('staff_id')
            ->select()
            ->toArray();
        foreach ($aggRows as $row) {
            $sid = (int)($row['staff_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $moneyMap[$sid] = (float)($row['money_total'] ?? 0);
            $laborMap[$sid] = (float)($row['labor_total'] ?? 0);
            $diankeMap[$sid] = (int)($row['dianke_cnt'] ?? 0);
            $projectMap[$sid] = (int)($row['project_cnt'] ?? 0);
        }

        // SQL3：服务客户（劳动 type=3；会员 staff+uid 去重，游客/朋友按订单计次）
        $svcCustomerMap = [];
        $svcSeen = [];
        $svcRows = Db::name('staff_yeji')->alias('a')
            ->leftJoin('store_order b', 'b.id=a.order_id')
            ->leftJoin('store_order_writeoff w', 'w.id=a.link_id AND a.type=3')
            ->where('a.status', 0)
            ->where('a.store_id', $storeId)
            ->whereIn('a.staff_id', $staffIds)
            ->where('a.type', 3)
            ->whereTime('a.created_time', 'between', [$timeStart, $timeEnd])
            ->field('a.staff_id,a.order_id,b.uid,b.service_object as so_order,w.service_object as so_writeoff')
            ->select()
            ->toArray();
        foreach ($svcRows as $nv) {
            $sid = (int)($nv['staff_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            if (!isset($svcCustomerMap[$sid])) {
                $svcCustomerMap[$sid] = 0;
            }
            $uid = (int)($nv['uid'] ?? 0);
            $svcObj = trim((string)($nv['so_writeoff'] ?? ''));
            if ($svcObj === '') {
                $svcObj = trim((string)($nv['so_order'] ?? ''));
            }
            if ($uid <= 0 || $svcObj === '朋友') {
                $svcCustomerMap[$sid]++;
            } else {
                $key = $sid . '_' . $uid;
                if (!isset($svcSeen[$key])) {
                    $svcSeen[$key] = 1;
                    $svcCustomerMap[$sid]++;
                }
            }
        }

        $rows = [];
        foreach ($staffIds as $sid) {
            $sid = (int)$sid;
            $rows[] = [
                'staff_id' => $sid,
                'staff_name' => $nameMap[$sid] ?? ('员工' . $sid),
                'cash_performance' => $moneyMap[$sid] ?? 0,
                'labor_performance' => $laborMap[$sid] ?? 0,
                'dianke_count' => $diankeMap[$sid] ?? 0,
                'service_customer_count' => $svcCustomerMap[$sid] ?? 0,
                'service_project_count' => $projectMap[$sid] ?? 0,
            ];
        }
        usort($rows, function ($a, $b) use ($sortBy, $sortOrder) {
            $va = (float)($a[$sortBy] ?? 0);
            $vb = (float)($b[$sortBy] ?? 0);
            if ($va == $vb) {
                return strcmp((string)$a['staff_name'], (string)$b['staff_name']);
            }
            return $sortOrder === 'asc' ? ($va <=> $vb) : ($vb <=> $va);
        });
        $top = array_slice($rows, 0, 20);
        $rank = 1;
        foreach ($top as &$item) {
            $item['rank'] = $rank++;
        }
        unset($item);
        return ['list' => $top, 'sort_by' => $sortBy, 'sort_order' => $sortOrder];
    }

    /**
     * 单指标按日序列，空日补 0。
     *
     * @param int[] $storeIds
     */
    public function trend(array $storeIds, string $metric, int $startTs, int $endTs): array
    {
        if (!in_array($metric, self::METRIC_CODES, true)) {
            $metric = 'cash_performance';
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $days = [];
        $cursor = strtotime(date('Y-m-d', $startTs));
        $endDay = strtotime(date('Y-m-d', $endTs));
        while ($cursor <= $endDay) {
            $days[date('Y-m-d', $cursor)] = 0;
            $cursor = strtotime('+1 day', $cursor);
        }
        if (!$storeIds || !$days) {
            return [
                'metric_code' => $metric,
                'unit' => self::UNIT[$metric] ?? '',
                'xAxis' => array_keys($days),
                'series' => array_values($days),
            ];
        }

        $dayMap = $this->aggregateMetricByDay($storeIds, $metric, $startTs, $endTs);
        foreach ($dayMap as $day => $val) {
            if (isset($days[$day])) {
                $days[$day] = $val;
            }
        }
        return [
            'metric_code' => $metric,
            'unit' => self::UNIT[$metric] ?? '',
            'xAxis' => array_keys($days),
            'series' => array_values($days),
        ];
    }

    /**
     * @param int[] $storeIds
     * @return array{list:array,count:int}
     */
    public function reservationDetail(array $storeIds, int $startTs, int $endTs, int $page, int $limit): array
    {
        /** @var MerchantCustomerMetricServices $customer */
        $customer = app()->make(MerchantCustomerMetricServices::class);
        $result = $customer->listReservationCustomerDetail($storeIds, $startTs, $endTs, $page, $limit);
        $uids = array_values(array_unique(array_filter(array_map('intval', array_column($result['list'], 'uid')))));
        $users = [];
        if ($uids) {
            $userRows = User::whereIn('uid', $uids)->field('uid,real_name,phone,nickname')->select()->toArray();
            foreach ($userRows as $u) {
                $users[(int)$u['uid']] = $u;
            }
        }
        $storeIdsInList = array_values(array_unique(array_filter(array_map('intval', array_column($result['list'], 'store_id')))));
        $storeNames = $storeIdsInList
            ? SystemStore::whereIn('id', $storeIdsInList)->column('name', 'id')
            : [];
        foreach ($result['list'] as &$row) {
            $uid = (int)($row['uid'] ?? 0);
            $sid = (int)($row['store_id'] ?? 0);
            $u = $users[$uid] ?? [];
            $row['real_name'] = (string)($u['real_name'] ?? $u['nickname'] ?? '');
            $row['phone'] = (string)($u['phone'] ?? '');
            $row['store_name'] = (string)($storeNames[$sid] ?? '');
            $row['earliest_time_text'] = !empty($row['earliest_time'])
                ? date('Y-m-d H:i:s', (int)$row['earliest_time'])
                : '';
        }
        unset($row);
        return $result;
    }

    /**
     * 散客/新客明细：合计口径同 countSourceOrderByStores；列表为同条件订单行分页。
     *
     * @param int[] $storeIds
     * @return array{list:array,count:int,is_new:int}
     */
    public function sourceCustomerDetail(array $storeIds, int $isNew, int $startTs, int $endTs, int $page, int $limit): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $isNew = $isNew ? 1 : 0;
        if (!$storeIds || $startTs <= 0 || $endTs <= 0) {
            return ['list' => [], 'count' => 0, 'list_count' => 0, 'is_new' => $isNew];
        }
        /** @var ReportServices $report */
        $report = app()->make(ReportServices::class);
        $count = $report->sumSourceOrderCountsByStores($storeIds, $isNew, [$startTs, $endTs]);
        $ctx = $report->buildReportSaleSourceContext();
        $query = $report->buildSourceOrderBaseQuery(
            $storeIds,
            $ctx['sourceAttr'] ?? [],
            $isNew,
            [$startTs, $endTs],
            $ctx['hezuofangStaffIds'] ?? []
        );
        $listCount = (int)(clone $query)->count();
        $list = $query->field('a.id,a.order_id,a.store_id,a.uid,a.add_time,a.pay_price,a.service_object,a.source')
            ->order('a.add_time', 'desc')
            ->order('a.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $uids = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'uid')))));
        $users = [];
        if ($uids) {
            $userRows = User::whereIn('uid', $uids)->field('uid,real_name,phone,nickname')->select()->toArray();
            foreach ($userRows as $u) {
                $users[(int)$u['uid']] = $u;
            }
        }
        $sids = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'store_id')))));
        $storeNames = $sids ? SystemStore::whereIn('id', $sids)->column('name', 'id') : [];
        foreach ($list as &$row) {
            $uid = (int)($row['uid'] ?? 0);
            $sid = (int)($row['store_id'] ?? 0);
            $u = $users[$uid] ?? [];
            $row['real_name'] = (string)($u['real_name'] ?? $u['nickname'] ?? '');
            $row['phone'] = (string)($u['phone'] ?? '');
            $row['store_name'] = (string)($storeNames[$sid] ?? '');
            $row['add_time_text'] = !empty($row['add_time']) ? date('Y-m-d H:i:s', (int)$row['add_time']) : '';
        }
        unset($row);
        return ['list' => $list, 'count' => $count, 'list_count' => $listCount, 'is_new' => $isNew];
    }

    /**
     * 新建档明细（store_user 同条件服务端分页）。
     *
     * @param int[] $storeIds
     * @return array{list:array,count:int}
     */
    public function newProfileDetail(array $storeIds, int $startTs, int $endTs, int $page, int $limit): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        if (!$storeIds || $startTs <= 0 || $endTs <= 0) {
            return ['list' => [], 'count' => 0];
        }
        $query = Db::name('store_user')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('add_time', [$startTs, $endTs]);
        $count = (int)(clone $query)->count();
        $list = $query->field('id,uid,store_id,add_time')
            ->order('add_time', 'desc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $uids = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'uid')))));
        $users = [];
        if ($uids) {
            $userRows = User::whereIn('uid', $uids)->field('uid,real_name,phone,nickname')->select()->toArray();
            foreach ($userRows as $u) {
                $users[(int)$u['uid']] = $u;
            }
        }
        $sids = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'store_id')))));
        $storeNames = $sids ? SystemStore::whereIn('id', $sids)->column('name', 'id') : [];
        foreach ($list as &$row) {
            $uid = (int)($row['uid'] ?? 0);
            $sid = (int)($row['store_id'] ?? 0);
            $u = $users[$uid] ?? [];
            $row['real_name'] = (string)($u['real_name'] ?? $u['nickname'] ?? '');
            $row['phone'] = (string)($u['phone'] ?? '');
            $row['store_name'] = (string)($storeNames[$sid] ?? '');
            $row['add_time_text'] = !empty($row['add_time']) ? date('Y-m-d H:i:s', (int)$row['add_time']) : '';
        }
        unset($row);
        return ['list' => $list, 'count' => $count];
    }

    /**
     * @param int[] $storeIds
     * @return array<int, float>
     */
    public function mapRechargeAmountByStores(array $storeIds, int $startTs, int $endTs): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $out = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = 0.0;
            }
        }
        if (!$out || $startTs <= 0 || $endTs <= 0) {
            return $out;
        }
        // 与 BranchOrderServices::homeStatics recharge_price 一致：paid=1 + store + time(add_time)
        $rows = Db::name('user_recharge')
            ->whereIn('store_id', array_keys($out))
            ->where('paid', 1)
            ->whereBetween('add_time', [$startTs, $endTs])
            ->field('store_id, SUM(price) AS total')
            ->group('store_id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if (isset($out[$sid])) {
                $out[$sid] = round((float)($row['total'] ?? 0), 2);
            }
        }
        return $out;
    }

    /**
     * 余额扣款按店（与 BranchOrderServices homeStatics store_use_yue 同 where）。
     *
     * @param int[] $storeIds
     * @return array<int, float>
     */
    public function mapBalanceDeductionByStores(array $storeIds, int $startTs, int $endTs): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $out = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = 0.0;
            }
        }
        if (!$out || $startTs <= 0 || $endTs <= 0) {
            return $out;
        }
        /** @var \app\dao\order\StoreOrderDao $orderDao */
        $orderDao = app()->make(\app\dao\order\StoreOrderDao::class);
        $orderWhere = [
            'paid' => 1,
            'not_old' => 1,
            'pid' => -3,
            'is_system_del' => 0,
            'refund_status' => 0,
            'link_type' => [0, 1],
            'store_id' => array_keys($out),
            'time' => [$startTs, $endTs],
        ];
        $rows = $orderDao->search($orderWhere)
            ->field('store_id, SUM(yue_pay_price) AS total')
            ->group('store_id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if (isset($out[$sid])) {
                $out[$sid] = round((float)($row['total'] ?? 0), 2);
            }
        }
        return $out;
    }

    /**
     * @param int[] $storeIds
     * @return array<int, int>
     */
    public function mapNewProfileCountByStores(array $storeIds, int $startTs, int $endTs): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $out = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = 0;
            }
        }
        if (!$out || $startTs <= 0 || $endTs <= 0) {
            return $out;
        }
        $rows = Db::name('store_user')
            ->whereIn('store_id', array_keys($out))
            ->whereBetween('add_time', [$startTs, $endTs])
            ->field('store_id, COUNT(*) AS cnt')
            ->group('store_id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if (isset($out[$sid])) {
                $out[$sid] = (int)($row['cnt'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * 实际业绩按日：3～4 次 SQL 取「日期+门店」现金/旧店/分成，PHP 逐店 max(0,cash−分成) 后按日汇总。
     *
     * @param int[] $storeIds
     * @return array<string, float> day => actual
     */
    protected function aggregateActualPerformanceByDay(array $storeIds, int $startTs, int $endTs): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds || $startTs <= 0 || $endTs <= 0) {
            return [];
        }
        $amountExpr = ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price');
        /** @var \app\dao\order\StoreOrderDao $orderDao */
        $orderDao = app()->make(\app\dao\order\StoreOrderDao::class);
        // SQL1：有效现金按日+店
        $cashRows = $orderDao->search([
            'paid' => 1,
            'valid_cash_only' => 1,
            'pid' => -3,
            'is_system_del' => 0,
            'refund_status' => 0,
            'link_type' => [0, 1],
            'store_id' => $storeIds,
            'time' => [$startTs, $endTs],
        ])
            ->field("store_id, FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM({$amountExpr}) AS total")
            ->group("store_id, FROM_UNIXTIME(add_time,'%Y-%m-%d')")
            ->select()
            ->toArray();
        $cashByDayStore = [];
        foreach ($cashRows as $row) {
            $key = (string)$row['day'] . '#' . (int)$row['store_id'];
            $cashByDayStore[$key] = bcadd('0', (string)($row['total'] ?? 0), 2);
        }
        // SQL2：旧店现金按日+店
        $oldRows = Db::name('old_shop_money')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('add_time', [$startTs, $endTs])
            ->field("store_id, FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM(cash_money) AS total")
            ->group("store_id, FROM_UNIXTIME(add_time,'%Y-%m-%d')")
            ->select()
            ->toArray();
        $oldByDayStore = [];
        foreach ($oldRows as $row) {
            $key = (string)$row['day'] . '#' . (int)$row['store_id'];
            $oldByDayStore[$key] = bcadd('0', (string)($row['total'] ?? 0), 2);
        }
        // SQL3：分成员工 JOIN staff_yeji，按日+店一次聚合（口径同先查 is_fencheng 再汇总）
        $fenchengByDayStore = [];
        $timeStart = date('Y/m/d', $startTs);
        $timeEnd = date('Y/m/d', $endTs) . ' 23:59:59';
        $fenchengRows = Db::name('staff_yeji')->alias('y')
            ->join('system_store_staff s', 's.id = y.staff_id')
            ->where('s.is_fencheng', 1)
            ->whereIn('y.store_id', $storeIds)
            ->whereIn('y.type', [1, 2])
            ->where('y.status', 0)
            ->whereTime('y.created_time', 'between', [$timeStart, $timeEnd])
            ->field("y.store_id, DATE(y.created_time) AS day, SUM(y.yeji) AS total")
            ->group('y.store_id, DATE(y.created_time)')
            ->select()
            ->toArray();
        foreach ($fenchengRows as $row) {
            $key = (string)$row['day'] . '#' . (int)$row['store_id'];
            $fenchengByDayStore[$key] = bcadd('0', (string)($row['total'] ?? 0), 2);
        }
        $keys = array_unique(array_merge(
            array_keys($cashByDayStore),
            array_keys($oldByDayStore),
            array_keys($fenchengByDayStore)
        ));
        $dayMap = [];
        foreach ($keys as $key) {
            [$day, $sid] = explode('#', $key, 2);
            $cash = bcadd((string)($cashByDayStore[$key] ?? '0'), (string)($oldByDayStore[$key] ?? '0'), 2);
            $actual = bcsub($cash, (string)($fenchengByDayStore[$key] ?? '0'), 2);
            if (bccomp($actual, '0', 2) < 0) {
                $actual = '0.00';
            }
            $dayMap[$day] = round(($dayMap[$day] ?? 0) + (float)$actual, 2);
        }
        return $dayMap;
    }

    /**
     * @param int[] $storeIds
     * @return array<string, float|int>
     */
    protected function aggregateMetricByDay(array $storeIds, string $metric, int $startTs, int $endTs): array
    {
        $dayMap = [];
        switch ($metric) {
            case 'cash_performance':
                $amountExpr = ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price');
                /** @var \app\dao\order\StoreOrderDao $orderDao */
                $orderDao = app()->make(\app\dao\order\StoreOrderDao::class);
                $rows = $orderDao->search([
                    'paid' => 1,
                    'valid_cash_only' => 1,
                    'pid' => -3,
                    'is_system_del' => 0,
                    'refund_status' => 0,
                    'link_type' => [0, 1],
                    'store_id' => $storeIds,
                    'time' => [$startTs, $endTs],
                ])
                    ->field("FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM({$amountExpr}) AS total")
                    ->group("FROM_UNIXTIME(add_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $dayMap[(string)$row['day']] = (float)($row['total'] ?? 0);
                }
                $oldRows = Db::name('old_shop_money')
                    ->whereIn('store_id', $storeIds)
                    ->whereBetween('add_time', [$startTs, $endTs])
                    ->field("FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM(cash_money) AS total")
                    ->group("FROM_UNIXTIME(add_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($oldRows as $row) {
                    $d = (string)$row['day'];
                    $dayMap[$d] = round(($dayMap[$d] ?? 0) + (float)($row['total'] ?? 0), 2);
                }
                break;
            case 'actual_performance':
                // 按「日期+门店」批量聚合现金/旧店现金/分成，再 PHP 逐店封顶后按日汇总（≤4 SQL）
                $dayMap = $this->aggregateActualPerformanceByDay($storeIds, $startTs, $endTs);
                break;
            case 'consume_amount':
                $productIds = \app\model\product\product\StoreProductRelation::where('relation_id', 78)
                    ->where('type', 1)->column('product_id') ?: [];
                $q = Db::name('store_order_writeoff')
                    ->whereIn('relation_id', $storeIds)
                    ->where('status', 0)
                    ->whereBetween('add_time', [$startTs, $endTs]);
                if ($productIds) {
                    $q->whereNotIn('product_id', $productIds);
                }
                $rows = $q->field("FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM(writeoff_price) AS total")
                    ->group("FROM_UNIXTIME(add_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $dayMap[(string)$row['day']] = (float)($row['total'] ?? 0);
                }
                $oldRows = Db::name('old_shop_money')
                    ->whereIn('store_id', $storeIds)
                    ->whereBetween('add_time', [$startTs, $endTs])
                    ->field("FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM(use_money) AS total")
                    ->group("FROM_UNIXTIME(add_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($oldRows as $row) {
                    $d = (string)$row['day'];
                    $dayMap[$d] = round(($dayMap[$d] ?? 0) + (float)($row['total'] ?? 0), 2);
                }
                break;
            case 'refund_amount':
                $rows = Db::name('store_order_refund')
                    ->whereIn('store_id', $storeIds)
                    ->where('refund_type', 6)
                    ->where('is_cancel', 0)
                    ->where('is_del', 0)
                    ->whereBetween('refunded_time', [$startTs, $endTs])
                    ->field("FROM_UNIXTIME(refunded_time,'%Y-%m-%d') AS day, SUM(refunded_price) AS total")
                    ->group("FROM_UNIXTIME(refunded_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $dayMap[(string)$row['day']] = (float)($row['total'] ?? 0);
                }
                break;
            case 'recharge_amount':
                $rows = Db::name('user_recharge')
                    ->whereIn('store_id', $storeIds)
                    ->where('paid', 1)
                    ->whereBetween('add_time', [$startTs, $endTs])
                    ->field("FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM(price) AS total")
                    ->group("FROM_UNIXTIME(add_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $dayMap[(string)$row['day']] = (float)($row['total'] ?? 0);
                }
                break;
            case 'balance_deduction_amount':
                /** @var \app\dao\order\StoreOrderDao $orderDao */
                $orderDao = app()->make(\app\dao\order\StoreOrderDao::class);
                $rows = $orderDao->search([
                    'paid' => 1,
                    'not_old' => 1,
                    'pid' => -3,
                    'is_system_del' => 0,
                    'refund_status' => 0,
                    'link_type' => [0, 1],
                    'store_id' => $storeIds,
                    'time' => [$startTs, $endTs],
                ])
                    ->field("FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM(yue_pay_price) AS total")
                    ->group("FROM_UNIXTIME(add_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $dayMap[(string)$row['day']] = (float)($row['total'] ?? 0);
                }
                break;
            case 'new_profile_count':
                $rows = Db::name('store_user')
                    ->whereIn('store_id', $storeIds)
                    ->whereBetween('add_time', [$startTs, $endTs])
                    ->field("FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, COUNT(*) AS cnt")
                    ->group("FROM_UNIXTIME(add_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $dayMap[(string)$row['day']] = (int)($row['cnt'] ?? 0);
                }
                break;
            case 'casual_customer_count':
            case 'new_customer_count':
                // 一次拉取候选订单，按自然日 PHP 去重分桶（仅趋势；生产概览仍用 SQL 分组 B）
                $isNew = $metric === 'new_customer_count' ? 1 : 0;
                /** @var ReportServices $report */
                $report = app()->make(ReportServices::class);
                $ctx = $report->buildReportSaleSourceContext();
                $rows = $report->buildSourceOrderBaseQuery($storeIds, $ctx['sourceAttr'] ?? [], $isNew, [$startTs, $endTs])
                    ->field('a.store_id,a.uid,a.add_time,a.service_object')
                    ->select()
                    ->toArray();
                $seen = [];
                foreach ($rows as $nv) {
                    $day = date('Y-m-d', (int)($nv['add_time'] ?? 0));
                    $svcObj = trim((string)($nv['service_object'] ?? ''));
                    if ((int)($nv['uid'] ?? 0) === 0 || $svcObj === '朋友') {
                        $dayMap[$day] = ($dayMap[$day] ?? 0) + 1;
                    } else {
                        $key = $day . '_' . (int)$nv['store_id'] . '_' . (int)$nv['uid'];
                        if (!isset($seen[$key])) {
                            $seen[$key] = 1;
                            $dayMap[$day] = ($dayMap[$day] ?? 0) + 1;
                        }
                    }
                }
                break;
            case 'reservation_customer':
                $rows = Db::name('store_reservation_order')
                    ->whereIn('store_id', $storeIds)
                    ->where('is_del', 0)
                    ->where('uid', '>', 0)
                    ->whereBetween('reservation_time', [$startTs, $endTs])
                    ->field("FROM_UNIXTIME(reservation_time,'%Y-%m-%d') AS day, COUNT(DISTINCT uid) AS cnt")
                    ->group("FROM_UNIXTIME(reservation_time,'%Y-%m-%d')")
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $dayMap[(string)$row['day']] = (int)($row['cnt'] ?? 0);
                }
                break;
        }
        return $dayMap;
    }

    /**
     * 金额类经营明细适配层：total 必须与 overview 卡片同口径。
     *
     * @param int[] $storeIds
     * @return array{metric_code:string,name:string,unit:string,total:float,breakdown:array,list:array,list_count:int}
     */
    public function moneyMetricDetail(
        array $storeIds,
        string $metric,
        int $startTs,
        int $endTs,
        string $timeRangeStr,
        int $page,
        int $limit
    ): array {
        if (!in_array($metric, self::MONEY_DETAIL_METRICS, true)) {
            $metric = 'cash_performance';
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $rangeStr = $timeRangeStr !== ''
            ? $timeRangeStr
            : (date('Y/m/d', $startTs) . '-' . date('Y/m/d', $endTs));
        $empty = [
            'metric_code' => $metric,
            'name' => (string)(($this->buildCard($metric, 0))['name'] ?? $metric),
            'unit' => self::UNIT[$metric] ?? '元',
            'total' => 0.0,
            'breakdown' => [],
            'list' => [],
            'list_count' => 0,
        ];
        if (!$storeIds || $startTs <= 0 || $endTs <= 0) {
            return $empty;
        }
        $cashWhere = ['store_id' => $storeIds, 'time' => [$startTs, $endTs]];
        /** @var AgentOrderServices $agent */
        $agent = app()->make(AgentOrderServices::class);
        /** @var ReportServices $report */
        $report = app()->make(ReportServices::class);
        /** @var MerchantBusinessSecondaryMetricServices $secondary */
        $secondary = app()->make(MerchantBusinessSecondaryMetricServices::class);

        switch ($metric) {
            case 'cash_performance':
                $cashMap = $agent->mapStoreCashIncomeByStores($cashWhere);
                $oldBoth = $agent->mapOldYejiCashAndConsumeByStores($cashWhere);
                $oldCash = $oldBoth['cash'] ?? [];
                $validTotal = '0.00';
                $oldTotal = '0.00';
                foreach ($storeIds as $sid) {
                    $validTotal = bcadd($validTotal, (string)($cashMap[$sid] ?? '0'), 2);
                    $oldTotal = bcadd($oldTotal, (string)($oldCash[$sid] ?? '0'), 2);
                }
                $total = (float)bcadd($validTotal, $oldTotal, 2);
                $lines = $this->buildCashDetailLines($storeIds, $startTs, $endTs);
                break;
            case 'actual_performance':
                $cashMap = $agent->mapStoreCashIncomeByStores($cashWhere);
                $oldBoth = $agent->mapOldYejiCashAndConsumeByStores($cashWhere);
                $oldCash = $oldBoth['cash'] ?? [];
                $fenchengMap = $agent->mapFenchengYejiByStores($storeIds, $rangeStr);
                $actualMap = $agent->mapActualPerformanceByStores($storeIds, $cashWhere, $rangeStr, [
                    'cash' => $cashMap,
                    'old' => $oldCash,
                    'fencheng' => $fenchengMap,
                ]);
                $total = 0.0;
                $validTotal = '0.00';
                $oldTotal = '0.00';
                $fenchengTotal = '0.00';
                foreach ($storeIds as $sid) {
                    $validTotal = bcadd($validTotal, (string)($cashMap[$sid] ?? '0'), 2);
                    $oldTotal = bcadd($oldTotal, (string)($oldCash[$sid] ?? '0'), 2);
                    $fenchengTotal = bcadd($fenchengTotal, (string)($fenchengMap[$sid] ?? '0'), 2);
                    $total = round($total + (float)($actualMap[$sid] ?? 0), 2);
                }
                $names = SystemStore::whereIn('id', $storeIds)->column('name', 'id');
                $lines = [];
                foreach ($storeIds as $sid) {
                    $cash = (float)bcadd((string)($cashMap[$sid] ?? '0'), (string)($oldCash[$sid] ?? '0'), 2);
                    $fen = (float)($fenchengMap[$sid] ?? 0);
                    $act = (float)($actualMap[$sid] ?? 0);
                    $lines[] = [
                        'line_type' => 'store_actual',
                        'line_type_text' => '门店实际业绩',
                        'store_id' => $sid,
                        'store_name' => (string)($names[$sid] ?? ('门店' . $sid)),
                        'cash_amount' => $cash,
                        'fencheng_amount' => $fen,
                        'amount' => $act,
                        'remark' => '逐店 max(0, 现金−分成)',
                        'biz_time_text' => '',
                    ];
                }
                usort($lines, function ($a, $b) {
                    return ($b['amount'] <=> $a['amount']) ?: strcmp((string)$a['store_name'], (string)$b['store_name']);
                });
                break;
            case 'consume_amount':
                $activeMap = $report->mapActiveYejiByStores($cashWhere);
                $oldBoth = $agent->mapOldYejiCashAndConsumeByStores($cashWhere);
                $oldConsume = $oldBoth['consume'] ?? [];
                $writeTotal = '0.00';
                $oldTotal = '0.00';
                foreach ($storeIds as $sid) {
                    $writeTotal = bcadd($writeTotal, (string)($activeMap[$sid] ?? '0'), 2);
                    $oldTotal = bcadd($oldTotal, (string)($oldConsume[$sid] ?? '0'), 2);
                }
                $total = (float)bcadd($writeTotal, $oldTotal, 2);
                $validTotal = $writeTotal;
                $lines = $this->buildConsumeDetailLines($storeIds, $startTs, $endTs);
                break;
            case 'refund_amount':
                $refundMap = $secondary->mapRefundAmountByStores($storeIds, $startTs, $endTs);
                $total = round((float)array_sum($refundMap), 2);
                $lines = $this->buildRefundDetailLines($storeIds, $startTs, $endTs);
                $validTotal = null;
                $oldTotal = null;
                $fenchengTotal = null;
                break;
            case 'recharge_amount':
                $rechargeMap = $this->mapRechargeAmountByStores($storeIds, $startTs, $endTs);
                $total = round((float)array_sum($rechargeMap), 2);
                $lines = $this->buildRechargeDetailLines($storeIds, $startTs, $endTs);
                $validTotal = null;
                $oldTotal = null;
                $fenchengTotal = null;
                break;
            case 'balance_deduction_amount':
                $balMap = $this->mapBalanceDeductionByStores($storeIds, $startTs, $endTs);
                $total = round((float)array_sum($balMap), 2);
                $lines = $this->buildBalanceDetailLines($storeIds, $startTs, $endTs);
                $validTotal = null;
                $oldTotal = null;
                $fenchengTotal = null;
                break;
            default:
                return $empty;
        }

        $listCount = count($lines);
        $offset = ($page - 1) * $limit;
        $pageList = array_slice($lines, $offset, $limit);
        $breakdown = [];
        if ($metric === 'cash_performance') {
            $breakdown = [
                'valid_cash' => (float)$validTotal,
                'old_cash' => (float)$oldTotal,
            ];
        } elseif ($metric === 'actual_performance') {
            $breakdown = [
                'valid_cash' => (float)$validTotal,
                'old_cash' => (float)$oldTotal,
                'fencheng' => (float)$fenchengTotal,
                'actual' => $total,
            ];
        } elseif ($metric === 'consume_amount') {
            $breakdown = [
                'writeoff' => (float)$validTotal,
                'old_consume' => (float)$oldTotal,
            ];
        }
        $card = $this->buildCard($metric, $total);
        return [
            'metric_code' => $metric,
            'name' => (string)$card['name'],
            'unit' => self::UNIT[$metric] ?? '元',
            'total' => round((float)$total, 2),
            'breakdown' => $breakdown,
            'list' => $pageList,
            'list_count' => $listCount,
        ];
    }

    /**
     * @param int[] $storeIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildCashDetailLines(array $storeIds, int $startTs, int $endTs): array
    {
        $amountExpr = ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price');
        /** @var \app\dao\order\StoreOrderDao $orderDao */
        $orderDao = app()->make(\app\dao\order\StoreOrderDao::class);
        $orderRows = $orderDao->search([
            'paid' => 1,
            'valid_cash_only' => 1,
            'pid' => -3,
            'is_system_del' => 0,
            'refund_status' => 0,
            'link_type' => [0, 1],
            'store_id' => $storeIds,
            'time' => [$startTs, $endTs],
        ])
            ->field("id,order_id,store_id,uid,add_time,({$amountExpr}) AS amount")
            ->order('add_time', 'desc')
            ->select()
            ->toArray();
        $oldRows = Db::name('old_shop_money')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('add_time', [$startTs, $endTs])
            ->where('cash_money', '>', 0)
            ->field('store_id,add_time,cash_money AS amount')
            ->order('add_time', 'desc')
            ->select()
            ->toArray();
        return $this->mergeMoneyLines($orderRows, $oldRows, 'valid_cash', '旧店现金', $storeIds);
    }

    /**
     * @param int[] $storeIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildConsumeDetailLines(array $storeIds, int $startTs, int $endTs): array
    {
        /** @var ReportServices $report */
        $report = app()->make(ReportServices::class);
        $productIds = $report->buildReportSaleSourceContext()['productIds'] ?? [];
        $q = Db::name('store_order_writeoff')
            ->whereIn('relation_id', $storeIds)
            ->where('status', 0)
            ->whereBetween('add_time', [$startTs, $endTs]);
        if ($productIds) {
            $q->whereNotIn('product_id', $productIds);
        }
        $writeRows = $q->field('id,relation_id AS store_id,uid,add_time,writeoff_price AS amount,oid AS order_id')
            ->order('add_time', 'desc')
            ->select()
            ->toArray();
        $oldRows = Db::name('old_shop_money')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('add_time', [$startTs, $endTs])
            ->where('use_money', '>', 0)
            ->field('store_id,add_time,use_money AS amount')
            ->order('add_time', 'desc')
            ->select()
            ->toArray();
        return $this->mergeMoneyLines($writeRows, $oldRows, 'writeoff', '旧店耗卡', $storeIds);
    }

    /**
     * @param int[] $storeIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildRefundDetailLines(array $storeIds, int $startTs, int $endTs): array
    {
        $rows = Db::name('store_order_refund')
            ->whereIn('store_id', $storeIds)
            ->where('refund_type', 6)
            ->where('is_cancel', 0)
            ->where('is_del', 0)
            ->whereBetween('refunded_time', [$startTs, $endTs])
            ->field('id,store_id,uid,order_id,refunded_time AS add_time,refunded_price AS amount')
            ->order('refunded_time', 'desc')
            ->select()
            ->toArray();
        return $this->enrichMoneyLines($rows, 'refund', '退款', $storeIds);
    }

    /**
     * @param int[] $storeIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildRechargeDetailLines(array $storeIds, int $startTs, int $endTs): array
    {
        $rows = Db::name('user_recharge')
            ->whereIn('store_id', $storeIds)
            ->where('paid', 1)
            ->whereBetween('add_time', [$startTs, $endTs])
            ->field('id,store_id,uid,order_id,add_time,price AS amount')
            ->order('add_time', 'desc')
            ->select()
            ->toArray();
        return $this->enrichMoneyLines($rows, 'recharge', '储值', $storeIds);
    }

    /**
     * @param int[] $storeIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildBalanceDetailLines(array $storeIds, int $startTs, int $endTs): array
    {
        /** @var \app\dao\order\StoreOrderDao $orderDao */
        $orderDao = app()->make(\app\dao\order\StoreOrderDao::class);
        $rows = $orderDao->search([
            'paid' => 1,
            'not_old' => 1,
            'pid' => -3,
            'is_system_del' => 0,
            'refund_status' => 0,
            'link_type' => [0, 1],
            'store_id' => $storeIds,
            'time' => [$startTs, $endTs],
        ])
            ->where('yue_pay_price', '>', 0)
            ->field('id,order_id,store_id,uid,add_time,yue_pay_price AS amount')
            ->order('add_time', 'desc')
            ->select()
            ->toArray();
        return $this->enrichMoneyLines($rows, 'balance', '余额扣款', $storeIds);
    }

    /**
     * @param array<int, array> $primary
     * @param array<int, array> $secondary
     * @param int[] $storeIds
     * @return array<int, array<string, mixed>>
     */
    protected function mergeMoneyLines(array $primary, array $secondary, string $primaryType, string $secondaryText, array $storeIds): array
    {
        $lines = array_merge(
            $this->enrichMoneyLines($primary, $primaryType, $primaryType === 'valid_cash' ? '有效现金' : '核销消耗', $storeIds),
            $this->enrichMoneyLines($secondary, 'old_shop', $secondaryText, $storeIds)
        );
        usort($lines, function ($a, $b) {
            return strcmp((string)($b['biz_time_text'] ?? ''), (string)($a['biz_time_text'] ?? ''));
        });
        return $lines;
    }

    /**
     * @param array<int, array> $rows
     * @param int[] $storeIds
     * @return array<int, array<string, mixed>>
     */
    protected function enrichMoneyLines(array $rows, string $lineType, string $lineTypeText, array $storeIds): array
    {
        $sids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'store_id')))));
        $storeNames = $sids ? SystemStore::whereIn('id', $sids)->column('name', 'id') : [];
        $uids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'uid')))));
        $users = [];
        if ($uids) {
            foreach (User::whereIn('uid', $uids)->field('uid,real_name,phone,nickname')->select()->toArray() as $u) {
                $users[(int)$u['uid']] = $u;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            $uid = (int)($row['uid'] ?? 0);
            $u = $users[$uid] ?? [];
            $ts = (int)($row['add_time'] ?? 0);
            $out[] = [
                'line_type' => $lineType,
                'line_type_text' => $lineTypeText,
                'id' => (int)($row['id'] ?? 0),
                'order_id' => (string)($row['order_id'] ?? ''),
                'store_id' => $sid,
                'store_name' => (string)($storeNames[$sid] ?? ('门店' . $sid)),
                'uid' => $uid,
                'real_name' => (string)($u['real_name'] ?? $u['nickname'] ?? ''),
                'phone' => (string)($u['phone'] ?? ''),
                'amount' => round((float)($row['amount'] ?? 0), 2),
                'biz_time_text' => $ts > 0 ? date('Y-m-d H:i:s', $ts) : '',
            ];
        }
        return $out;
    }

    /**
     * @param float|int|string $value
     */
    protected function buildCard(string $code, $value): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode($code) ?: [];
        $tooltip = $dict->getTooltip($code);
        $isMoney = in_array($code, self::MONEY_DETAIL_METRICS, true);
        return [
            'metric_code' => $code,
            'name' => (string)($def['name'] ?? $code),
            'value' => $isMoney ? round((float)$value, 2) : (int)$value,
            'unit' => self::UNIT[$code] ?? '',
            'tooltip' => $tooltip,
            'detail_type' => self::DETAIL_TYPE[$code] ?? '',
        ];
    }
}
