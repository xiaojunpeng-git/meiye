<?php
namespace app\services\merchant;

use app\dao\yeji\StaffYejiDao;
use app\model\product\product\StoreProductRelation;
use app\model\store\SystemStoreStaff;
use app\model\yeji\CashSource;
use app\services\BaseServices;
use app\services\metric\MetricDictionaryServices;
use app\services\order\ValidCashOrderServices;
use app\services\report\ReportServices;
use think\facade\Db;

/**
 * 客户类指标统一出口（客群 / 我的客户 / 数仓客户分析必须共用）
 *
 * 新增客户口径（产品确认 2026-07-16）：
 * 第一次在系统产生「现金业绩有效订单」的客户
 * （订单集合与 ValidCashOrderServices::sumStoreCashIncome 一致；全系统首单时间落在统计窗，且该首单门店 ∈ scope）
 *
 * 单一出口：listFirstOrderCustomerUids；指标人数 = count(同一 UID 集合)，禁止平行 SQL。
 *
 * 成交客户数（产品确认 2026-07-16）：与销售分析「新客数」一致，逐店 ReportServices::sourceOrder(isNew=1,isCount=1) 再求和。
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

    public const CODE_RESERVATION_ORDER = 'reservation_order';

    /**
     * 预约单数（按预约日期计单，不去重客户；与预约客户数区分）
     *
     * @param int[] $scopeStoreIds
     */
    public function reservationOrderMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_RESERVATION_ORDER) ?: [];

        $out = [
            'metric_code' => self::CODE_RESERVATION_ORDER,
            'title' => (string)($def['name'] ?? '预约单数'),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => '/pages/admin/reservation_list/index?merchant=1',
            'detail_developing' => false,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_RESERVATION_ORDER,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $out['number'] = $this->countReservationOrders($scopeStoreIds, $startTs, $endTs);
            $out['developing'] = false;
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '预约单数统计暂时不可用';
            return $out;
        }
    }

    /**
     * 预约单数唯一数值出口（禁止平行算法）
     *
     * @param int[] $scopeStoreIds
     */
    public function countReservationOrders(array $scopeStoreIds, int $startTs, int $endTs): int
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0;
        }

        return (int)Db::name('store_reservation_order')
            ->whereIn('store_id', $scopeStoreIds)
            ->where('is_del', 0)
            ->whereBetween('reservation_time', [$startTs, $endTs])
            ->count();
    }

    public const CODE_DEAL_CUSTOMER = 'deal_customer';

    /**
     * 成交客户数：与销售分析「新客数」/目标「成交新客」同一算法（禁止平行实现）
     * 数值 = 逐店 ReportServices::sourceOrder(isNew=1, isCount=1) 之和；人数可能 ≠ DISTINCT uid，明细暂不开放
     *
     * @param int[] $scopeStoreIds
     */
    public function dealCustomerMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_DEAL_CUSTOMER) ?: [];

        $out = [
            'metric_code' => self::CODE_DEAL_CUSTOMER,
            'title' => (string)($def['name'] ?? '成交客户数'),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => null,
            'detail_developing' => true,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_DEAL_CUSTOMER,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $out['number'] = $this->countDealCustomers($scopeStoreIds, $startTs, $endTs);
            $out['developing'] = false;
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '成交客户统计暂时不可用';
            return $out;
        }
    }

    /**
     * 成交客户数唯一数值出口：对齐 StoreTargetServices::calcCustomerMetric('new_customer')
     *
     * @param int[] $scopeStoreIds
     */
    public function countDealCustomers(array $scopeStoreIds, int $startTs, int $endTs): float
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0.0;
        }

        /** @var ReportServices $reportServices */
        $reportServices = app()->make(ReportServices::class);
        $range = [$startTs, $endTs];
        $productIds = StoreProductRelation::where('relation_id', 78)->where('type', 1)->column('product_id');
        if (!$productIds) {
            $productIds = [0];
        }
        $sourceAttr = CashSource::whereNotIn('id', [6, 7, 11, 12])->column('id');
        $total = 0.0;
        foreach ($scopeStoreIds as $storeId) {
            $total += (float)$reportServices->sourceOrder($sourceAttr, 1, $range, 1, (int)$storeId, $productIds);
        }
        return $total;
    }

    public const CODE_CARD_RECHARGE_CUSTOMER = 'card_recharge_customer';

    /**
     * 开卡充值客户数：周期内有开卡或余额充值成功单的去重客户（系统无续卡）
     * 单一出口：listCardRechargeCustomerUids
     *
     * @param int[] $scopeStoreIds
     */
    public function cardRechargeCustomerMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_CARD_RECHARGE_CUSTOMER) ?: [];

        $out = [
            'metric_code' => self::CODE_CARD_RECHARGE_CUSTOMER,
            'title' => (string)($def['name'] ?? '开卡充值客户数'),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => null,
            'detail_developing' => true,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_CARD_RECHARGE_CUSTOMER,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $uids = $this->listCardRechargeCustomerUids($scopeStoreIds, $startTs, $endTs);
            $out['number'] = count($uids);
            $out['developing'] = false;
            $out['detail_developing'] = false;
            $out['detail_api'] = '/pages/merchant/customer/index';
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '开卡充值客户统计暂时不可用';
            return $out;
        }
    }

    /**
     * 开卡∪充值去重客户 uid（人数 = count 本列表）
     *
     * @param int[] $scopeStoreIds
     * @return int[]
     */
    public function listCardRechargeCustomerUids(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return [];
        }

        $uids = $this->buildCardRechargeOrderQuery($scopeStoreIds, $startTs, $endTs)
            ->group('uid')
            ->column('uid');
        if (!is_array($uids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $uids))));
    }

    /**
     * 开卡∪充值订单集合（人数 / 金额共用，禁止平行 where）
     * 开卡：普通单含卡项；充值：充值单。无续卡。
     *
     * @param int[] $scopeStoreIds
     * @return \think\db\BaseQuery|\think\db\Query
     */
    public function buildCardRechargeOrderQuery(array $scopeStoreIds, int $startTs, int $endTs)
    {
        return Db::name('store_order')
            ->whereIn('store_id', $scopeStoreIds)
            ->where('paid', 1)
            ->where('is_system_del', 0)
            ->where('refund_status', 0)
            ->where('uid', '>', 0)
            ->whereBetween('add_time', [$startTs, $endTs])
            ->where(function ($q) {
                $q->where('pid', '>=', 0)->whereOr('pid', -2);
            })
            ->where(function ($q) {
                $q->where('order_type', 1)
                    ->whereOr(function ($q2) {
                        $q2->where('order_type', 0)->where('product_type', 5);
                    });
            });
    }

    public const CODE_VISIT_CUSTOMER = 'visit_customer';

    /**
     * 到店客户数：周期内有核销到店记录的去重客户
     * 单一出口：listVisitCustomerUids
     *
     * @param int[] $scopeStoreIds
     */
    public function visitCustomerMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_VISIT_CUSTOMER) ?: [];

        $out = [
            'metric_code' => self::CODE_VISIT_CUSTOMER,
            'title' => (string)($def['name'] ?? '到店客户数'),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => null,
            'detail_developing' => true,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_VISIT_CUSTOMER,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $uids = $this->listVisitCustomerUids($scopeStoreIds, $startTs, $endTs);
            $out['number'] = count($uids);
            $out['developing'] = false;
            $out['detail_developing'] = false;
            $out['detail_api'] = '/pages/merchant/customer/index';
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '到店客户统计暂时不可用';
            return $out;
        }
    }

    /**
     * 核销到店去重客户 uid（人数 = count 本列表）
     *
     * @param int[] $scopeStoreIds
     * @return int[]
     */
    public function listVisitCustomerUids(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return [];
        }

        $uids = $this->buildVisitCustomerUidQuery($scopeStoreIds, $startTs, $endTs)->column('uid');
        if (!is_array($uids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $uids))));
    }

    /**
     * 对齐 UserListStatServices 核销到店：store_order_writeoff + relation_id + add_time
     *
     * @param int[] $scopeStoreIds
     * @return \think\db\BaseQuery|\think\db\Query
     */
    protected function buildVisitCustomerUidQuery(array $scopeStoreIds, int $startTs, int $endTs)
    {
        return Db::name('store_order_writeoff')
            ->whereIn('relation_id', $scopeStoreIds)
            ->where('uid', '>', 0)
            ->whereBetween('add_time', [$startTs, $endTs])
            ->group('uid')
            ->field('uid');
    }

    public const CODE_REPURCHASE_CUSTOMER = 'repurchase_customer';

    /**
     * 复购客户数：历史已有「成交」底单 + 本周期再次成交的去重客户
     * 「成交」订单底与销售分析新客同一过滤底（不做是否新客分支）；单一出口 listRepurchaseCustomerUids
     *
     * @param int[] $scopeStoreIds
     */
    public function repurchaseCustomerMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_REPURCHASE_CUSTOMER) ?: [];

        $out = [
            'metric_code' => self::CODE_REPURCHASE_CUSTOMER,
            'title' => (string)($def['name'] ?? '复购客户数'),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => null,
            'detail_developing' => true,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_REPURCHASE_CUSTOMER,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $uids = $this->listRepurchaseCustomerUids($scopeStoreIds, $startTs, $endTs);
            $out['number'] = count($uids);
            $out['developing'] = false;
            $out['detail_developing'] = false;
            $out['detail_api'] = '/pages/merchant/customer/index';
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '复购客户统计暂时不可用';
            return $out;
        }
    }

    /**
     * 复购客户 uid = 历史成交 ∩ 本期成交（人数 = count 本列表）
     *
     * @param int[] $scopeStoreIds
     * @return int[]
     */
    public function listRepurchaseCustomerUids(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return [];
        }

        $hist = $this->listSalesAnalysisDealUids($scopeStoreIds, 0, $startTs - 1);
        if (!$hist) {
            return [];
        }
        $period = $this->listSalesAnalysisDealUids($scopeStoreIds, $startTs, $endTs);
        if (!$period) {
            return [];
        }
        $histMap = array_fill_keys($hist, true);
        $out = [];
        foreach ($period as $uid) {
            if (isset($histMap[$uid])) {
                $out[] = $uid;
            }
        }
        return $out;
    }

    /**
     * 销售分析「新客」订单底上的去重 uid（复购用；不含同日去重计数规则）
     * 历史：add_time ∈ [0, startTs-1]；本期：add_time ∈ [startTs, endTs]
     *
     * @param int[] $scopeStoreIds
     * @return int[]
     */
    protected function listSalesAnalysisDealUids(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        if ($endTs < $startTs) {
            return [];
        }
        $uids = [];
        foreach ($scopeStoreIds as $storeId) {
            $q = $this->buildSalesAnalysisDealOrderQuery((int)$storeId)
                ->whereBetween('a.add_time', [$startTs, $endTs]);
            $part = $q->group('a.uid')->column('a.uid');
            if (is_array($part) && $part) {
                $uids = array_merge($uids, $part);
            }
        }
        return array_values(array_unique(array_filter(array_map('intval', $uids))));
    }

    /**
     * 与 ReportServices::sourceOrder(isNew=1) 订单过滤底一致（不做 isCount 同日去重）
     *
     * @return \think\db\BaseQuery|\think\db\Query
     */
    protected function buildSalesAnalysisDealOrderQuery(int $storeId)
    {
        $sourceAttr = CashSource::whereNotIn('id', [6, 7, 11, 12])->column('id');
        $query = Db::name('store_order')->alias('a')
            ->where('a.paid', 1)
            ->where(function ($q) {
                $q->whereIn('a.pid', [0, -2])->whereOr('a.pid', '>', 0);
            })
            ->where('a.refund_status', 0)
            ->where('a.is_system_del', 0)
            ->where('a.store_id', $storeId)
            ->whereIn('a.order_type', [0, 1])
            ->whereIn('a.source', $sourceAttr ?: [-1])
            ->where('a.uid', '>', 0)
            ->where('a.cash_pay_price', '>=', 298);

        $fencheng = SystemStoreStaff::where('is_hezuofang', 1)->column('id');
        if (!empty($fencheng)) {
            $query->whereNotIn('a.id', function ($q) use ($fencheng, $storeId) {
                $q->name('staff_yeji')
                    ->where('status', 0)
                    ->where('store_id', $storeId)
                    ->whereIn('staff_id', $fencheng)
                    ->whereIn('type', [1, 2])
                    ->field('order_id');
            });
        }

        ValidCashOrderServices::applyScope($query, 'a');
        return $query;
    }
}
