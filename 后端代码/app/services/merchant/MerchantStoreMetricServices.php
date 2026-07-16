<?php
declare(strict_types=1);

namespace app\services\merchant;

use app\dao\order\StoreOrderDao;
use app\dao\order\StoreOrderWriteoffDao;
use app\model\product\product\StoreProductRelation;
use app\services\BaseServices;
use app\services\order\ValidCashOrderServices;
use app\services\order\agent\AgentOrderServices;
use app\services\report\ReportServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 店级经营指标明细（权威出口 = AgentOrderServices::homeStatics）
 * 本类仅提供与头部同口径的下钻列表，禁止改用 yeji/store 排行当明细。
 */
class MerchantStoreMetricServices extends BaseServices
{
    /**
     * 现金业绩明细：与 homeStatics 现金项同 where（valid_cash + oldYeji type=1）
     */
    public function cashDetail(array $access, array $filter): array
    {
        $ctx = $this->prepareCashContext($access, $filter, '当前身份不可查看店级现金业绩明细');
        if ($ctx['empty']) {
            return $this->emptyCashPayload($filter, $ctx['scope_store_ids']);
        }

        $page = $ctx['page'];
        $built = $this->buildCashOrderPage($ctx);
        $list = $built['list'];
        if ($page === 1) {
            $list = array_merge($list, $this->appendOldShopRows($ctx));
        }

        return [
            'metric_code' => 'cash_performance',
            'title' => '现金业绩',
            'header_number' => $ctx['cash_header'],
            'order_sum' => $ctx['order_sum'],
            'old_sum' => $ctx['old_sum'],
            'note' => '口径与首页/数仓现金业绩一致：sumStoreCashIncome(buildAmountExpr) + oldYeji(type=1)；时间字段 store_order.add_time',
            'list' => $list,
            'count' => $built['count'],
            'filter' => $ctx['filter'],
            'scope_store_ids' => $ctx['scope_store_ids'],
        ];
    }

    /**
     * 实收业绩明细：逐店 max(0, 现金 − 分成) 再求和（与 homeStatics / storeChart=7 同口径）
     */
    public function actualDetail(array $access, array $filter): array
    {
        $ctx = $this->prepareCashContext($access, $filter, '当前身份不可查看店级实际业绩明细');
        if ($ctx['empty']) {
            return $this->emptyActualPayload($filter, $ctx['scope_store_ids']);
        }

        /** @var AgentOrderServices $agentOrder */
        $agentOrder = app()->make(AgentOrderServices::class);
        $fenchengSum = $agentOrder->sumStoreFenchengYeji($ctx['scope_store_ids'], $ctx['range_str']);
        $cashWhere = [
            'time' => [$ctx['t_start'], $ctx['t_end']],
            'store_id' => $ctx['store_param'],
        ];
        $headerNumber = $agentOrder->sumActualPerformanceByStores(
            $ctx['scope_store_ids'],
            $cashWhere,
            $ctx['range_str']
        );

        $page = $ctx['page'];
        $built = $this->buildCashOrderPage($ctx);
        $list = $built['list'];
        if ($page === 1) {
            $list = array_merge($list, $this->appendOldShopRows($ctx));
            $list = array_merge($list, $this->appendFenchengRows($ctx, $agentOrder));
        }

        return [
            'metric_code' => 'actual_performance',
            'title' => '实际业绩',
            'header_number' => $headerNumber,
            'cash_sum' => $ctx['cash_header'],
            'order_sum' => $ctx['order_sum'],
            'old_sum' => $ctx['old_sum'],
            'fencheng_sum' => bcadd((string)$fenchengSum, '0', 2),
            'note' => '口径与首页/数仓/门店排行一致：逐店 max(0, 现金业绩 − 分成员工(is_fencheng=1)销售/充值业绩) 再求和；不等于 max(0, 总现金−总分成)；订单行 buildAmountExpr；分成 staff_yeji.created_time',
            'list' => $list,
            'count' => $built['count'],
            'filter' => $ctx['filter'],
            'scope_store_ids' => $ctx['scope_store_ids'],
        ];
    }

    /**
     * 消耗金额明细：activeYeji + oldYeji(type=2)，与 homeStatics 消耗项同口径
     */
    public function consumeDetail(array $access, array $filter): array
    {
        $ctx = $this->prepareMetricScope($access, $filter, '当前身份不可查看店级消耗明细');
        if ($ctx['empty']) {
            return $this->emptyConsumePayload($filter, $ctx['scope_store_ids']);
        }

        /** @var AgentOrderServices $agentOrder */
        $agentOrder = app()->make(AgentOrderServices::class);
        $where = [
            'time' => [$ctx['t_start'], $ctx['t_end']],
            'store_id' => $ctx['store_param'],
        ];
        $activeSum = $this->sumActiveYejiByStores($ctx['scope_store_ids'], $ctx['t_start'], $ctx['t_end']);
        $oldSum = bcadd((string)$agentOrder->oldYeji($where, 2), '0', 2);
        $headerNumber = bcadd((string)$activeSum, (string)$oldSum, 2);

        $page = $ctx['page'];
        $built = $this->buildWriteoffPage($ctx);
        $list = $built['list'];
        if ($page === 1) {
            $list = array_merge($list, $this->appendOldShopConsumeRows($ctx, $oldSum));
        }

        return [
            'metric_code' => 'consume_amount',
            'title' => '消耗业绩',
            'header_number' => $headerNumber,
            'active_sum' => $activeSum,
            'old_sum' => $oldSum,
            'note' => '口径与首页/数仓消耗一致：activeYeji（排除合作类 relation_id=78）+ oldYeji(type=2)；时间字段 store_order_writeoff.add_time',
            'list' => $list,
            'count' => $built['count'],
            'filter' => $ctx['filter'],
            'scope_store_ids' => $ctx['scope_store_ids'],
        ];
    }

    /**
     * 门店/日期范围上下文（不含现金汇总），供消耗等明细复用
     */
    protected function prepareMetricScope(array $access, array $filter, string $denyMsg): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $accessServices->requireAnyPermission(
            $access,
            ['merchant.data.store', 'merchant.data.region'],
            '暂无门店/区域经营数据权限'
        );
        if (!$accessServices->canAggregateStoreMetrics($access)) {
            throw new ValidateException($denyMsg);
        }
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $base = [
            'empty' => true,
            'scope_store_ids' => $scopeStoreIds ?: [],
            'store_param' => [],
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d'),
            'range_str' => '',
            't_start' => 0,
            't_end' => 0,
            'page' => 1,
            'limit' => 20,
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $filter['start_date'] ?? date('Y-m-d'),
                'end_date' => $filter['end_date'] ?? date('Y-m-d'),
            ],
            'store_names' => [],
        ];
        if (!$scopeStoreIds) {
            return $base;
        }

        $startDate = trim((string)($filter['start_date'] ?? date('Y-m-d')));
        $endDate = trim((string)($filter['end_date'] ?? date('Y-m-d')));
        if ($startDate === '' || $endDate === '') {
            throw new ValidateException('请选择有效的日期范围');
        }
        $rangeStr = str_replace('-', '/', $startDate) . '-' . str_replace('-', '/', $endDate);

        /** @var AgentOrderServices $agentOrder */
        $agentOrder = app()->make(AgentOrderServices::class);
        [$tStart, $tEnd] = $agentOrder->timeHandle($rangeStr, false, true);
        $storeParam = count($scopeStoreIds) === 1 ? $scopeStoreIds[0] : $scopeStoreIds;

        return [
            'empty' => false,
            'scope_store_ids' => $scopeStoreIds,
            'store_param' => $storeParam,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'range_str' => $rangeStr,
            't_start' => $tStart,
            't_end' => $tEnd,
            'page' => max(1, (int)($filter['page'] ?? 1)),
            'limit' => min(50, max(1, (int)($filter['limit'] ?? 20))),
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'store_names' => $this->storeNameMap($scopeStoreIds),
        ];
    }

    /**
     * @return array{
     *   empty: bool,
     *   scope_store_ids: array,
     *   store_param: int|array,
     *   start_date: string,
     *   end_date: string,
     *   range_str: string,
     *   t_start: int,
     *   t_end: int,
     *   order_sum: string,
     *   old_sum: string,
     *   cash_header: string,
     *   page: int,
     *   limit: int,
     *   filter: array,
     *   store_names: array
     * }
     */
    protected function prepareCashContext(array $access, array $filter, string $denyMsg): array
    {
        $scope = $this->prepareMetricScope($access, $filter, $denyMsg);
        if ($scope['empty']) {
            return $scope + [
                'order_sum' => '0.00',
                'old_sum' => '0.00',
                'cash_header' => '0.00',
            ];
        }

        /** @var AgentOrderServices $agentOrder */
        $agentOrder = app()->make(AgentOrderServices::class);
        $where = [
            'time' => [$scope['t_start'], $scope['t_end']],
            'store_id' => $scope['store_param'],
        ];
        $orderSum = ValidCashOrderServices::sumStoreCashIncome($where);
        $oldSum = bcadd((string)$agentOrder->oldYeji($where, 1), '0', 2);
        $cashHeader = bcadd((string)$orderSum, (string)$oldSum, 2);

        return $scope + [
            'order_sum' => bcadd((string)$orderSum, '0', 2),
            'old_sum' => $oldSum,
            'cash_header' => $cashHeader,
        ];
    }

    /** @param array $ctx prepareCashContext 非 empty 结果 */
    protected function buildCashOrderPage(array $ctx): array
    {
        $orderWhere = [
            'paid' => 1,
            'valid_cash_only' => 1,
            'pid' => -3,
            'is_system_del' => 0,
            'refund_status' => 0,
            'link_type' => [0, 1],
            'store_id' => $ctx['store_param'],
            'time' => [$ctx['t_start'], $ctx['t_end']],
        ];

        /** @var StoreOrderDao $orderDao */
        $orderDao = app()->make(StoreOrderDao::class);
        $count = (int)$orderDao->search($orderWhere)->count();
        $amountExpr = ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price');
        $rows = $orderDao->search($orderWhere)
            ->field("id,order_id,uid,store_id,cash_pay_price,pay_price,add_time,real_name,user_phone,({$amountExpr}) as cash_amount")
            ->order('add_time DESC,id DESC')
            ->page($ctx['page'], $ctx['limit'])
            ->select()
            ->toArray();

        $storeNames = $ctx['store_names'];
        $list = [];
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            $list[] = [
                'row_type' => 'order',
                'id' => (int)($row['id'] ?? 0),
                'order_id' => (string)($row['order_id'] ?? ''),
                'uid' => (int)($row['uid'] ?? 0),
                'store_id' => $sid,
                'store_name' => $storeNames[$sid] ?? ('门店#' . $sid),
                'amount' => bcadd((string)($row['cash_amount'] ?? 0), '0', 2),
                'user_name' => (string)($row['real_name'] ?? ''),
                'user_phone' => (string)($row['user_phone'] ?? ''),
                'add_time' => (int)($row['add_time'] ?? 0),
                'add_time_text' => !empty($row['add_time']) ? date('Y-m-d H:i', (int)$row['add_time']) : '',
            ];
        }

        return ['list' => $list, 'count' => $count];
    }

    protected function appendOldShopRows(array $ctx): array
    {
        if (bccomp((string)$ctx['old_sum'], '0', 2) <= 0) {
            return [];
        }
        $storeParam = $ctx['store_param'];
        $storeNames = $ctx['store_names'];
        $oldRows = Db::name('old_shop_money')
            ->when(true, function ($q) use ($storeParam) {
                if (is_array($storeParam)) {
                    $q->whereIn('store_id', $storeParam);
                } else {
                    $q->where('store_id', $storeParam);
                }
            })
            ->whereBetween('add_time', [$ctx['t_start'], $ctx['t_end']])
            ->field('id,store_id,cash_money,add_time,mark')
            ->order('add_time DESC,id DESC')
            ->limit(100)
            ->select()
            ->toArray();
        $list = [];
        foreach ($oldRows as $or) {
            $sid = (int)($or['store_id'] ?? 0);
            $list[] = [
                'row_type' => 'old_shop',
                'id' => (int)($or['id'] ?? 0),
                'order_id' => '旧店录入',
                'uid' => 0,
                'store_id' => $sid,
                'store_name' => $storeNames[$sid] ?? ('门店#' . $sid),
                'amount' => bcadd((string)($or['cash_money'] ?? 0), '0', 2),
                'user_name' => '',
                'user_phone' => '',
                'add_time' => (int)($or['add_time'] ?? 0),
                'add_time_text' => !empty($or['add_time']) ? date('Y-m-d H:i', (int)$or['add_time']) : '',
                'mark' => (string)($or['mark'] ?? ''),
            ];
        }
        return $list;
    }

    protected function appendFenchengRows(array $ctx, AgentOrderServices $agentOrder): array
    {
        $rows = $agentOrder->listStoreFenchengYeji($ctx['scope_store_ids'], $ctx['range_str'], 100);
        if (!$rows) {
            return [];
        }
        $storeNames = $ctx['store_names'];
        $typeLabel = static function (int $type): string {
            if ($type === 1) {
                return '销售业绩';
            }
            if ($type === 2) {
                return '充值业绩';
            }
            return '业绩';
        };
        $list = [];
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            $yeji = bcadd((string)($row['yeji'] ?? 0), '0', 2);
            $created = (string)($row['created_time'] ?? '');
            $list[] = [
                'row_type' => 'fencheng',
                'id' => (int)($row['id'] ?? 0),
                'order_id' => '分成扣减',
                'uid' => 0,
                'store_id' => $sid,
                'store_name' => $storeNames[$sid] ?? ('门店#' . $sid),
                'amount' => bcsub('0', $yeji, 2),
                'user_name' => (string)($row['staff_name'] ?? ''),
                'user_phone' => '',
                'staff_id' => (int)($row['staff_id'] ?? 0),
                'yeji_type' => (int)($row['type'] ?? 0),
                'yeji_type_text' => $typeLabel((int)($row['type'] ?? 0)),
                'add_time' => $created !== '' ? (int)strtotime($created) : 0,
                'add_time_text' => $created !== '' ? date('Y-m-d H:i', strtotime($created)) : '',
                'ref_order_id' => (int)($row['order_id'] ?? 0),
            ];
        }
        return $list;
    }

    /**
     * 逐店 activeYeji：委托 ReportServices::sumActiveYejiByStores（与 homeStatics 共用）
     *
     * @param int[] $storeIds
     */
    protected function sumActiveYejiByStores(array $storeIds, int $tStart, int $tEnd): string
    {
        /** @var ReportServices $report */
        $report = app()->make(ReportServices::class);
        return $report->sumActiveYejiByStores([
            'time' => [$tStart, $tEnd],
            'store_id' => $storeIds,
        ]);
    }

    /** 与 activeYeji 同条件分页核销列表 */
    protected function buildWriteoffPage(array $ctx): array
    {
        $productIds = StoreProductRelation::where('relation_id', 78)->where('type', 1)->column('product_id');
        $productIds = is_array($productIds) ? array_values(array_filter(array_map('intval', $productIds))) : [];

        /** @var StoreOrderWriteoffDao $dao */
        $dao = app()->make(StoreOrderWriteoffDao::class);
        $timeWhere = ['time' => [$ctx['t_start'], $ctx['t_end']]];
        $makeQuery = function () use ($dao, $timeWhere, $ctx, $productIds) {
            $query = $dao->search($timeWhere)
                ->whereIn('relation_id', $ctx['scope_store_ids']);
            if ($productIds) {
                $query->whereNotIn('product_id', $productIds);
            }
            return $query;
        };
        $count = (int)$makeQuery()->count();
        $rows = $makeQuery()
            ->field('id,oid,uid,relation_id,product_id,writeoff_price,add_time')
            ->order('add_time DESC,id DESC')
            ->page($ctx['page'], $ctx['limit'])
            ->select()
            ->toArray();

        $productNames = $this->productNameMap(array_column($rows, 'product_id'));
        $userNames = $this->userNameMap(array_column($rows, 'uid'));
        $storeNames = $ctx['store_names'];
        $list = [];
        foreach ($rows as $row) {
            $sid = (int)($row['relation_id'] ?? 0);
            $pid = (int)($row['product_id'] ?? 0);
            $uid = (int)($row['uid'] ?? 0);
            $list[] = [
                'row_type' => 'writeoff',
                'id' => (int)($row['id'] ?? 0),
                'order_id' => '核销#' . (int)($row['id'] ?? 0),
                'oid' => (int)($row['oid'] ?? 0),
                'uid' => $uid,
                'store_id' => $sid,
                'store_name' => $storeNames[$sid] ?? ('门店#' . $sid),
                'product_id' => $pid,
                'product_name' => $productNames[$pid] ?? ('商品#' . $pid),
                'amount' => bcadd((string)($row['writeoff_price'] ?? 0), '0', 2),
                'user_name' => $userNames[$uid] ?? '',
                'user_phone' => '',
                'add_time' => (int)($row['add_time'] ?? 0),
                'add_time_text' => !empty($row['add_time']) ? date('Y-m-d H:i', (int)$row['add_time']) : '',
            ];
        }

        return ['list' => $list, 'count' => $count];
    }

    protected function appendOldShopConsumeRows(array $ctx, string $oldSum): array
    {
        if (bccomp($oldSum, '0', 2) <= 0) {
            return [];
        }
        $storeParam = $ctx['store_param'];
        $storeNames = $ctx['store_names'];
        $oldRows = Db::name('old_shop_money')
            ->when(true, function ($q) use ($storeParam) {
                if (is_array($storeParam)) {
                    $q->whereIn('store_id', $storeParam);
                } else {
                    $q->where('store_id', $storeParam);
                }
            })
            ->whereBetween('add_time', [$ctx['t_start'], $ctx['t_end']])
            ->field('id,store_id,use_money,add_time,mark')
            ->order('add_time DESC,id DESC')
            ->limit(100)
            ->select()
            ->toArray();
        $list = [];
        foreach ($oldRows as $or) {
            $sid = (int)($or['store_id'] ?? 0);
            $list[] = [
                'row_type' => 'old_shop',
                'id' => (int)($or['id'] ?? 0),
                'order_id' => '旧店耗卡',
                'uid' => 0,
                'store_id' => $sid,
                'store_name' => $storeNames[$sid] ?? ('门店#' . $sid),
                'amount' => bcadd((string)($or['use_money'] ?? 0), '0', 2),
                'user_name' => '',
                'user_phone' => '',
                'add_time' => (int)($or['add_time'] ?? 0),
                'add_time_text' => !empty($or['add_time']) ? date('Y-m-d H:i', (int)$or['add_time']) : '',
                'mark' => (string)($or['mark'] ?? ''),
            ];
        }
        return $list;
    }

    /** @param int[] $productIds */
    protected function productNameMap(array $productIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }
        try {
            $rows = Db::name('store_product')->whereIn('id', $productIds)->column('store_name', 'id');
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @param int[] $uids */
    protected function userNameMap(array $uids): array
    {
        $uids = array_values(array_filter(array_map('intval', $uids)));
        if (!$uids) {
            return [];
        }
        try {
            $rows = Db::name('user')->whereIn('uid', $uids)->column('nickname', 'uid');
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function emptyCashPayload(array $filter, array $scope): array
    {
        return [
            'metric_code' => 'cash_performance',
            'title' => '现金业绩',
            'header_number' => '0.00',
            'order_sum' => '0.00',
            'old_sum' => '0.00',
            'note' => '无有效门店范围',
            'list' => [],
            'count' => 0,
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $filter['start_date'] ?? date('Y-m-d'),
                'end_date' => $filter['end_date'] ?? date('Y-m-d'),
            ],
            'scope_store_ids' => $scope,
        ];
    }

    protected function emptyActualPayload(array $filter, array $scope): array
    {
        return [
            'metric_code' => 'actual_performance',
            'title' => '实际业绩',
            'header_number' => '0.00',
            'cash_sum' => '0.00',
            'order_sum' => '0.00',
            'old_sum' => '0.00',
            'fencheng_sum' => '0.00',
            'note' => '无有效门店范围',
            'list' => [],
            'count' => 0,
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $filter['start_date'] ?? date('Y-m-d'),
                'end_date' => $filter['end_date'] ?? date('Y-m-d'),
            ],
            'scope_store_ids' => $scope,
        ];
    }

    protected function emptyConsumePayload(array $filter, array $scope): array
    {
        return [
            'metric_code' => 'consume_amount',
            'title' => '消耗业绩',
            'header_number' => '0.00',
            'active_sum' => '0.00',
            'old_sum' => '0.00',
            'note' => '无有效门店范围',
            'list' => [],
            'count' => 0,
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $filter['start_date'] ?? date('Y-m-d'),
                'end_date' => $filter['end_date'] ?? date('Y-m-d'),
            ],
            'scope_store_ids' => $scope,
        ];
    }

    /** @param int[] $storeIds */
    protected function storeNameMap(array $storeIds): array
    {
        if (!$storeIds) {
            return [];
        }
        try {
            $rows = Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id');
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
