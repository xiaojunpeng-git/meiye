<?php
namespace app\services\merchant;

use app\dao\yeji\StaffYejiDao;
use app\services\BaseServices;
use app\services\metric\MetricDictionaryServices;
use app\services\order\ValidCashOrderServices;
use think\facade\Db;

/**
 * 客户类指标统一出口（客群 / 我的客户 / 数仓客户分析必须共用）
 *
 * 新增客户口径（产品确认 2026-07-16）：
 * 第一次在系统产生「现金业绩有效订单」的客户
 * （订单集合与 ValidCashOrderServices::sumStoreCashIncome 一致；全系统首单时间落在统计窗，且该首单门店 ∈ scope）
 *
 * 单一出口：listFirstOrderCustomerUids；指标人数 = count(同一 UID 集合)，禁止平行 SQL。
 */
class MerchantCustomerMetricServices extends BaseServices
{
    public const CODE_NEW_CUSTOMER = 'new_customer';

    /** @var string 产品确认：系统首次现金业绩有效下单 */
    public const NEW_CUSTOMER_MODE = 'first_order';

    /**
     * 新增客户统一聚合（唯一数值出口 = 对 listFirstOrderCustomerUids 计数）
     *
     * @param int[] $scopeStoreIds
     * @return array{
     *   metric_code: string,
     *   title: string,
     *   number: ?int,
     *   developing: bool,
     *   note: string,
     *   detail_api: string|null,
     *   detail_developing: bool,
     *   tooltip_api: string
     * }
     */
    public function newCustomerMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_NEW_CUSTOMER) ?: [];

        $out = [
            'metric_code' => self::CODE_NEW_CUSTOMER,
            'title' => (string)($def['name'] ?? '新增客户数'),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => null,
            'detail_developing' => true,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_NEW_CUSTOMER,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $uids = $this->listFirstOrderCustomerUids($scopeStoreIds, $startTs, $endTs);
            $out['number'] = count($uids);
            $out['developing'] = false;
            $out['detail_developing'] = false;
            $out['detail_api'] = '/pages/merchant/customer/index';
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '新增客户统计暂时不可用';
            return $out;
        }
    }

    /**
     * 人数便捷方法：与名单同一 UID 出口（禁止独立 SQL）
     *
     * @param int[] $scopeStoreIds
     */
    public function countFirstOrderCustomers(array $scopeStoreIds, int $startTs, int $endTs): int
    {
        return count($this->listFirstOrderCustomerUids($scopeStoreIds, $startTs, $endTs));
    }

    /**
     * 首次现金业绩有效下单客户 uid 列表（指标 / 客群 / 数仓唯一查询出口）
     *
     * @param int[] $scopeStoreIds
     * @return int[]
     */
    public function listFirstOrderCustomerUids(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return [];
        }

        $uids = $this->buildFirstOrderUidQuery($scopeStoreIds, $startTs, $endTs)->column('o.uid');
        if (!is_array($uids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $uids))));
    }

    /**
     * 唯一首单 UID 查询构造器（数值与名单必须共用，禁止平行复制）
     *
     * 订单范围对齐现金业绩：paid + valid_cash_only + pid=-3 语义 + refund_status=0 + link_type[0,1]
     *
     * @param int[] $scopeStoreIds
     * @return \think\db\BaseQuery|\think\db\Query
     */
    protected function buildFirstOrderUidQuery(array $scopeStoreIds, int $startTs, int $endTs)
    {
        $firstSub = $this->newCashPerformanceOrderQuery()
            ->field('uid, MIN(add_time) AS first_time')
            ->group('uid')
            ->buildSql(true);

        return $this->newCashPerformanceOrderQuery('o')
            ->join([$firstSub => 'f'], 'f.uid = o.uid AND f.first_time = o.add_time')
            ->whereBetween('o.add_time', [$startTs, $endTs])
            ->whereIn('o.store_id', $scopeStoreIds)
            ->group('o.uid')
            ->field('o.uid');
    }

    /**
     * 现金业绩有效订单集合（与 ValidCashOrderServices::sumStoreCashIncome 订单 where 一致）
     *
     * @return \think\db\BaseQuery|\think\db\Query
     */
    protected function newCashPerformanceOrderQuery(string $alias = '')
    {
        $query = Db::name('store_order');
        if ($alias !== '') {
            $query->alias($alias);
        }
        $p = $alias !== '' ? ($alias . '.') : '';

        $query->where($p . 'paid', 1)
            ->where($p . 'is_system_del', 0)
            ->where($p . 'refund_status', 0)
            ->where($p . 'uid', '>', 0)
            // pid=-3：pid>=0 或 pid=-2（与 StoreOrder::searchPidAttr 一致）
            ->where(function ($q) use ($p) {
                $q->where($p . 'pid', '>=', 0)->whereOr($p . 'pid', -2);
            })
            // link_type 为 StoreOrderDao 历史筛选参数，实际订单字段为 order_type（与 sumStoreCashIncome 的 link_type=[0,1] 同义）
            ->whereIn($p . 'order_type', [0, 1]);

        ValidCashOrderServices::applyScope($query, $alias);
        ValidCashOrderServices::applyHasValidCash($query, $alias);

        return $query;
    }

    public const CODE_RESERVATION_CUSTOMER = 'reservation_customer';

    /**
     * 预约客户数（方案：周期内产生预约的去重客户数）
     * 单一出口：listReservationCustomerUids
     *
     * @param int[] $scopeStoreIds
     */
    public function reservationCustomerMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_RESERVATION_CUSTOMER) ?: [];

        $out = [
            'metric_code' => self::CODE_RESERVATION_CUSTOMER,
            'title' => (string)($def['name'] ?? '预约客户数'),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => '/pages/admin/reservation_list/index?merchant=1',
            'detail_developing' => false,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_RESERVATION_CUSTOMER,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $uids = $this->listReservationCustomerUids($scopeStoreIds, $startTs, $endTs);
            $out['number'] = count($uids);
            $out['developing'] = false;
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '预约客户统计暂时不可用';
            return $out;
        }
    }

    /**
     * 预约客户 uid 唯一出口（指标人数 = count 本列表）
     *
     * @param int[] $scopeStoreIds
     * @return int[]
     */
    public function listReservationCustomerUids(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return [];
        }

        $uids = $this->buildReservationCustomerUidQuery($scopeStoreIds, $startTs, $endTs)->column('uid');
        if (!is_array($uids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $uids))));
    }

    /**
     * @param int[] $scopeStoreIds
     * @return \think\db\BaseQuery|\think\db\Query
     */
    protected function buildReservationCustomerUidQuery(array $scopeStoreIds, int $startTs, int $endTs)
    {
        // 按预约日期 reservation_time；含已退回等状态（产生过预约即计）；按客户去重
        return Db::name('store_reservation_order')
            ->whereIn('store_id', $scopeStoreIds)
            ->where('is_del', 0)
            ->where('uid', '>', 0)
            ->whereBetween('reservation_time', [$startTs, $endTs])
            ->group('uid')
            ->field('uid');
    }

    public const CODE_SERVICE_VISIT = 'service_visit';

    /**
     * 服务客次（方案：周期内完成服务的总客次）
     * 与目标「服务客次」/门店劳动侧一致：StaffYejiDao::serviceNum(sum_type=2)，逐店相加
     *
     * @param int[] $scopeStoreIds
     */
    public function serviceVisitMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_SERVICE_VISIT) ?: [];

        $out = [
            'metric_code' => self::CODE_SERVICE_VISIT,
            'title' => (string)($def['name'] ?? '服务客次'),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => null,
            'detail_developing' => true,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_SERVICE_VISIT,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $out['number'] = $this->countServiceVisits($scopeStoreIds, $startTs, $endTs);
            $out['developing'] = false;
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '服务客次统计暂时不可用';
            return $out;
        }
    }

    /**
     * 服务客次唯一数值出口（禁止平行算法）
     *
     * @param int[] $scopeStoreIds
     */
    public function countServiceVisits(array $scopeStoreIds, int $startTs, int $endTs): float
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0.0;
        }

        /** @var StaffYejiDao $yejiDao */
        $yejiDao = app()->make(StaffYejiDao::class);
        $timeStr = date('Y/m/d H:i:s', $startTs) . '-' . date('Y/m/d H:i:s', $endTs);
        $total = 0.0;
        foreach ($scopeStoreIds as $sid) {
            $total += (float)$yejiDao->serviceNum([
                'created_time' => $timeStr,
                'store_id' => (int)$sid,
                'sum_type' => 2,
            ]);
        }
        return $total;
    }
}
