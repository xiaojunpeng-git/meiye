<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\BaseServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 第三阶段平台六维报表。
 *
 * 控制器必须先按平台账号权限裁剪 storeIds。本服务只读取 V3 不可变事实、
 * 第三阶段投影及报表补充记录，不接受客户端传入的租户或扩权范围。
 */
final class StoreUnifiedReportPhaseThreeServices extends BaseServices
{
    public const METRIC_VERSION = 'six-dimension-phase-three-v1';
    // The card-item projection is introduced by the Phase 3 release and has no
    // historical backfill. This date remains the first-purchase classification
    // boundary, but it must not prevent operators from querying earlier facts.
    public const COVERAGE_START = '2026-08-17';
    private const UPGRADE_KEY = '20260816-002-phase-three-six-dimension-report-foundation';

    private const REPORTS = [
        'six_dimension_item_deal_analysis',
        'six_dimension_cash_consumption_analysis',
        'six_dimension_consumption_refund_detail',
        'six_dimension_performance_deal',
        'six_dimension_performance_distribution',
        'six_dimension_performance_market_distribution',
    ];

    /** @var int */
    private $participantEmployeeId = 0;

    public static function reportCodes(): array
    {
        return self::REPORTS;
    }

    public static function catalogEntries(): array
    {
        $names = [
            'six_dimension_item_deal_analysis' => '品项成交分析表',
            'six_dimension_cash_consumption_analysis' => '现金消费分析表',
            'six_dimension_consumption_refund_detail' => '消耗及退款明细',
            'six_dimension_performance_deal' => '业绩成交表',
            'six_dimension_performance_distribution' => '业绩分布表',
            'six_dimension_performance_market_distribution' => '业绩市场分布表',
        ];
        $rows = [];
        foreach ($names as $code => $name) {
            $rows[] = ['folder' => '六维数据中心', 'code' => $code, 'name' => $name, 'platform_only' => true];
        }
        return $rows;
    }

    public function supports(string $report): bool
    {
        return in_array($report, self::REPORTS, true);
    }

    public function query(string $report, $storeIds, array $range, array $input): array
    {
        if (!$this->supports($report)) {
            throw new \InvalidArgumentException('不支持的第三阶段报表类型');
        }
        $scope = is_array($input['_report_scope'] ?? null) ? $input['_report_scope'] : [];
        if ((string)($scope['mode'] ?? '') === 'none') {
            throw new \InvalidArgumentException('当前账号没有可查看的数据范围');
        }
        $this->participantEmployeeId = (string)($scope['mode'] ?? '') === 'self_participant'
            ? max(0, (int)($scope['employee_id'] ?? 0)) : 0;
        if ((string)($scope['mode'] ?? '') === 'self_participant' && $this->participantEmployeeId <= 0) {
            throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
        }
        $stores = $this->storeIds($storeIds);
        if ($stores === []) {
            throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        }
        $range = $this->validRange($range);
        switch ($report) {
            case 'six_dimension_item_deal_analysis':
                return $this->itemDealAnalysis($stores, $range, $input);
            case 'six_dimension_cash_consumption_analysis':
                return $this->cashConsumptionAnalysis($stores, $range, $input);
            case 'six_dimension_consumption_refund_detail':
                return $this->consumptionRefundDetail($stores, $range, $input);
            case 'six_dimension_performance_deal':
                return $this->performanceDeal($stores, $range, $input);
            case 'six_dimension_performance_distribution':
                return $this->performanceDistribution($stores, $range, $input);
            case 'six_dimension_performance_market_distribution':
                return $this->performanceMarketDistribution($stores, $range, $input);
        }
        throw new \InvalidArgumentException('不支持的第三阶段报表类型');
    }

    private function itemDealAnalysis(array $stores, array $range, array $input): array
    {
        $categoryIds = $this->categoryScope($input);
        $records = [];
        foreach ($this->completedServices($stores, $range, $categoryIds) as $service) {
            if ((int)$service['is_experience'] !== 1 || (int)$service['member_id'] <= 0) continue;
            $key = $this->itemKey($service);
            $this->ensureItemRow($records, $key, $service, (string)$service['business_date']);
            $records[$key]['_experience_members'][(string)$service['member_id']] = true;
        }
        foreach ($this->purchaseItemRows($stores, $range, $categoryIds) as $sale) {
            $key = $this->itemKey($sale);
            $this->ensureItemRow($records, $key, $sale, (string)$sale['business_date']);
            if ((int)$sale['member_id'] > 0) $records[$key]['_purchase_members'][(string)$sale['member_id']] = true;
            $records[$key]['purchase_count'] += (int)$sale['quantity'];
        }
        foreach ($this->cashItemRows($stores, $range, $categoryIds) as $cash) {
            $key = $this->itemKey($cash);
            $this->ensureItemRow($records, $key, $cash, (string)$cash['business_date']);
            $records[$key]['purchase_amount_cents'] += (int)$cash['amount_cents'];
        }
        foreach ($records as &$row) {
            $row['experience_people'] = count($row['_experience_members']);
            $row['purchase_people'] = count($row['_purchase_members']);
            $row['conversion_rate'] = $this->ratio($row['purchase_people'], $row['experience_people']);
            $row['purchase_amount'] = $this->money($row['purchase_amount_cents']);
            $row['average_sale_price'] = $this->moneyRatio($row['purchase_amount_cents'], $row['purchase_count']);
            $row['average_unit_output'] = $this->moneyRatio($row['purchase_amount_cents'], $row['purchase_people']);
            unset($row['_experience_members'], $row['_purchase_members']);
        }
        unset($row);
        $columns = [
            $this->column('company_name', '分公司', '读取业务所属组织路径中配置为分公司的组织名称；没有配置时显示“未配置分公司”。', false, true, 130),
            $this->column('store_name', '门店', '取成交或服务实际发生时保存的门店名称。', false, true, 140),
            $this->column('item_name', '商品名称', '取成交项目名称快照；卡项按购买时冻结的内含项目逐项展开。', false, true, 180),
            $this->column('experience_people', '体验人数', '期间内完成该体验项目服务的会员数，同门店同商品会员去重。', true, false, 110),
            $this->column('purchase_people', '购买人数', '期间内成功取得该商品或项目的会员数，同门店同商品会员去重。', true, false, 110),
            $this->column('conversion_rate', '成交率', '购买人数除以体验人数；体验人数为零时显示“-”。', false),
            $this->column('purchase_count', '购买次数', '单独购买项目取成交数量；卡项取购买时冻结的卡内项目数量。', true, false, 110),
            $this->column('purchase_amount', '购买金额', '成功收款按销售明细分摊到商品或卡内项目；欠款补交和退款按各自成功日期计入。', true, false, 120, $this->drill('purchase_amount', [], ['store_id' => 'store_id', 'item_id' => 'item_id'])),
            $this->column('average_sale_price', '平均销售价格', '购买金额除以购买次数；购买次数为零时显示“-”。', false),
            $this->column('average_unit_output', '平均单产', '购买金额除以购买人数；购买人数为零时显示“-”。', false),
        ];
        return $this->result('品项成交分析表', $columns, array_values($records), $input, $range, [
            'category' => true,
        ]);
    }

    private function cashConsumptionAnalysis(array $stores, array $range, array $input): array
    {
        $categoryIds = $this->categoryScope($input);
        $authorizedStores = $this->storeIds((array)($input['_authorized_store_ids'] ?? $stores));
        if ($authorizedStores === []) $authorizedStores = $stores;
        $memberIds = $this->activeMemberIds($authorizedStores, $range);
        $memberTotals = $this->memberCashTotals($authorizedStores, $range, []);
        $categoryTotals = $this->memberCashTotals($authorizedStores, $range, $categoryIds);
        $assignments = $this->memberStoreAssignments($memberIds, $range['end'], $authorizedStores);
        $tiers = $this->foundation()->consumptionTiers(CashierV3ScopeResolver::TENANT_SCOPE_ID);
        $records = [];
        $activeByAssignmentStore = [];
        foreach ($memberIds as $memberId) {
            $assignment = (array)($assignments[(string)$memberId] ?? $assignments[(int)$memberId] ?? []);
            $storeId = (int)($assignment['store_id'] ?? 0);
            if ($storeId > 0 && !in_array($storeId, $stores, true)) continue;
            if ($storeId <= 0 && !$this->sameStoreScope($stores, $authorizedStores)) continue;
            $activeByAssignmentStore[$storeId][(string)$memberId] = true;
            $total = (int)($memberTotals[$memberId] ?? 0);
            $tier = $this->matchTier($tiers, max(0, $total));
            if ($tier === null) continue;
            $key = $storeId . '|' . (string)$tier['tier_id'];
            if (!isset($records[$key])) {
                $storeName = $storeId > 0 ? $this->storeName($storeId) : '未配置归属门店';
                $records[$key] = [
                    'company_name' => $storeId > 0 ? $this->dimensionName('company', $storeId, $range['end']) : '未配置分公司',
                    'store_id' => $storeId, 'store_name' => $storeName,
                    'consumption_tier' => (string)$tier['name'], '_members' => [],
                    'category_amount_cents' => 0, 'total_amount_cents' => 0,
                ];
            }
            $records[$key]['_members'][(string)$memberId] = true;
            $records[$key]['category_amount_cents'] += (int)($categoryTotals[$memberId] ?? 0);
            $records[$key]['total_amount_cents'] += $total;
        }
        foreach ($records as &$row) {
            $row['accumulated_consumers'] = count($row['_members']);
            $activeCount = count((array)($activeByAssignmentStore[(int)$row['store_id']] ?? []));
            $row['people_share'] = $this->ratio($row['accumulated_consumers'], $activeCount);
            $row['category_consumption_amount'] = $this->money($row['category_amount_cents']);
            $row['total_consumption_amount'] = $this->money($row['total_amount_cents']);
            $row['category_consumption_share'] = $this->ratio($row['category_amount_cents'], $row['total_amount_cents']);
            unset($row['_members']);
        }
        unset($row);
        $columns = [
            $this->column('company_name', '分公司', '读取会员截止日归属门店所属组织路径中配置为分公司的组织名称。', false, true, 130),
            $this->column('store_name', '门店', '取会员在筛选截止日生效的归属门店；无法还原时显示“未配置归属门店”。', false, true, 140),
            $this->column('consumption_tier', '消费分级', '按会员期间内全部门店、全部分类的净现金业绩匹配当前启用分级；负数按零进入最低档。', false, true, 150),
            $this->column('accumulated_consumers', '累计消费人数', '落入当前消费分级的不同会员数，同一会员只计一次。', true, false, 120),
            $this->column('people_share', '人数占比', '当前分级累计消费人数除以同期该归属门店完成有效服务的不同会员数。', false),
            $this->column('category_consumption_amount', '分类消费金额', '当前分级会员在所选分类及全部下级分类产生的净现金业绩。', true, false, 130),
            $this->column('total_consumption_amount', '总消费金额', '当前分级会员在期间内全部门店、全部商品分类产生的净现金业绩。', true, false, 130),
            $this->column('category_consumption_share', '分类消费金额占比', '当前分级分类消费金额合计除以当前分级总消费金额合计；分母为零时显示“-”。', false, false, 150),
        ];
        return $this->result('现金消费分析表', $columns, array_values($records), $input, $range, ['category' => true]);
    }

    private function consumptionRefundDetail(array $stores, array $range, array $input): array
    {
        $range = $this->drilldownRange($range, $input);
        $stores = $this->drilldownStores($stores, $range, $input);
        $categoryIds = !empty($input['six_dimension_only']) ? $this->sixDimensionCategoryIds() : [];
        $itemId = trim((string)($input['item_id'] ?? ''));
        $metricCode = trim((string)($input['metric_code'] ?? ''));
        $cashOnly = in_array($metricCode, [
            'purchase_amount', 'previous_completed', 'current_completed', 'group_total', 'company_amount',
        ], true);
        $events = [];
        $cashRows = $stores === [] ? [] : $this->cashItemRows($stores, $range, $categoryIds);
        foreach ($cashRows as $cash) {
            if ($itemId !== '' && (string)$cash['item_id'] !== $itemId) continue;
            $isReversal = (string)$cash['fact_direction'] === 'reversal';
            $isRefund = $isReversal && (string)($cash['reversal_type'] ?? '') === 'refund';
            $isVoid = $isReversal && (string)($cash['reversal_type'] ?? '') === 'void';
            $events[] = [
                'event_sort_at' => (int)$cash['occurred_at'],
                'event_type' => $isRefund ? '退款' : ($isVoid ? '作废' : '收款'),
                'company_name' => $this->dimensionNameForEvent(
                    'company', (string)($cash['organization_id'] ?? ''),
                    (string)($cash['organization_path_snapshot'] ?? ''),
                    (int)$cash['store_id'], (string)$cash['business_date']
                ),
                'store_id' => (int)$cash['store_id'], 'store_name' => (string)$cash['store_name'],
                'craftsman' => '-', 'item_name' => (string)$cash['item_name'],
                'purchase_date' => (string)$cash['purchase_date'], 'consumption_amount_cents' => 0,
                'consultant' => (string)($cash['sales_manager_name'] ?: '-'),
                'received_amount_cents' => $isRefund ? 0 : (int)$cash['amount_cents'],
                'transfer_amount_cents' => 0,
                'refund_amount_cents' => $isRefund ? abs((int)$cash['amount_cents']) : 0,
                'annotation_subject_key' => 'payment-allocation:' . (string)$cash['allocation_fact_id'],
                'annotation_subject_type' => 'business_event_line',
                'source_fact_id' => (int)($cash['source_fact_row_id'] ?? 0),
                'source_order_id' => (string)$cash['order_id'],
                'source_line_id' => (string)$cash['source_line_id'],
            ];
        }
        $serviceRows = $cashOnly || $stores === [] ? [] : $this->serviceEventRows($stores, $range);
        foreach ($serviceRows as $service) {
            if ($itemId !== '' && (string)$service['project_id'] !== $itemId) continue;
            $events[] = [
                'event_sort_at' => (int)$service['occurred_at'], 'event_type' => '服务消耗',
                'company_name' => $this->dimensionNameForEvent(
                    'company', (string)($service['organization_id'] ?? ''),
                    (string)($service['organization_path_snapshot'] ?? ''),
                    (int)$service['store_id'], (string)$service['business_date']
                ),
                'store_id' => (int)$service['store_id'], 'store_name' => (string)$service['store_name_snapshot'],
                'craftsman' => $this->craftsmanNames((string)$service['craftsmen_snapshot_json']),
                'item_name' => (string)$service['project_name_snapshot'],
                'purchase_date' => (string)$service['purchase_date'],
                'consumption_amount_cents' => (int)$service['consumption_amount_cents'],
                'consultant' => (string)($service['sales_manager_name'] ?: '-'),
                'received_amount_cents' => 0, 'transfer_amount_cents' => 0, 'refund_amount_cents' => 0,
                'annotation_subject_key' => 'service:' . (string)$service['service_fact_id'],
                'annotation_subject_type' => 'business_event_line',
                'source_fact_id' => (int)$service['id'], 'source_order_id' => (string)$service['origin_order_id'],
                'source_line_id' => (string)$service['source_line_id'],
            ];
        }
        $transferRows = $cashOnly || $stores === [] ? [] : $this->transferEventRows($stores, $range);
        foreach ($transferRows as $transfer) {
            $events[] = [
                'event_sort_at' => (int)$transfer['occurred_at'], 'event_type' => '转卡',
                'company_name' => $this->dimensionNameForEvent(
                    'company', (string)($transfer['organization_id'] ?? ''),
                    (string)($transfer['organization_path_snapshot'] ?? ''),
                    (int)$transfer['store_id'], (string)$transfer['business_date']
                ),
                'store_id' => (int)$transfer['store_id'], 'store_name' => (string)$transfer['store_name_snapshot'],
                'craftsman' => '-', 'item_name' => (string)$transfer['item_name'],
                'purchase_date' => (string)$transfer['purchase_date'], 'consumption_amount_cents' => 0,
                'consultant' => (string)($transfer['sales_manager_name'] ?: '-'), 'received_amount_cents' => 0,
                'transfer_amount_cents' => (int)$transfer['transfer_amount_cents'], 'refund_amount_cents' => 0,
                'annotation_subject_key' => (string)$transfer['annotation_subject_key'],
                'annotation_subject_type' => 'business_event_line',
                'source_fact_id' => (int)$transfer['line_row_id'], 'source_order_id' => (string)$transfer['origin_order_id'],
                'source_line_id' => (string)($transfer['operation_line_id'] ?: $transfer['operation_id']),
            ];
        }
        usort($events, static function (array $left, array $right): int {
            return (int)$right['event_sort_at'] <=> (int)$left['event_sort_at']
                ?: strcmp((string)$right['annotation_subject_key'], (string)$left['annotation_subject_key']);
        });
        $annotations = $this->annotations(
            'six_dimension_consumption_refund_detail',
            $stores,
            array_column($events, 'annotation_subject_key')
        );
        foreach ($events as &$row) {
            $manual = (array)($annotations[(string)$row['annotation_subject_key']]['complaint_count'] ?? []);
            $row['consumption_amount'] = $this->money((int)$row['consumption_amount_cents']);
            $row['received_amount'] = $this->money((int)$row['received_amount_cents']);
            $row['transfer_amount'] = $this->money((int)$row['transfer_amount_cents']);
            $row['complaint_count'] = $manual === [] ? '' : (string)$manual['value'];
            // The page uses the common manual-input component. The version is transport
            // metadata only and is not presented as a business concept to the operator.
            $row['complaint_count_version'] = (int)($manual['version'] ?? 0);
            $row['refund_amount'] = $this->money((int)$row['refund_amount_cents']);
            unset($row['event_sort_at']);
        }
        unset($row);
        $columns = [
            $this->column('company_name', '分公司', '取该业务事件所属组织路径中配置为分公司的组织名称。', false, true, 130),
            $this->column('store_name', '门店', '取该事件实际发生时保存的门店名称。', false, true, 140),
            $this->column('craftsman', '操作师', '服务消耗取实际完成项目的手艺人；其他事件没有操作师时显示“-”。', false, true, 120),
            $this->column('item_name', '商品名称', '取该收款、服务、转卡或退款分摊行对应的商品、项目或卡内项目名称快照。', false, true, 180),
            $this->column('purchase_date', '购买日期', '取该项目原始成功购买的业务日期；服务和退款仍显示原购买日期。', false, false, 112),
            $this->column('consumption_amount', '耗卡金额', '仅服务消耗事件显示项目成功核销后形成的消耗业绩。', true, false, 120),
            $this->column('consultant', '咨询师', '取原成交业务确认并保存的销售经理名称；没有时显示“-”。', false, false, 120),
            $this->column('received_amount', '实收业绩', '仅成功收款事件显示该笔收款分摊到当前商品或项目的现金业绩。', true, false, 120),
            $this->column('transfer_amount', '转卡业绩', '卡升级取旧卡剩余金额；项目升级或替换取原卡内对应项目的实际支付分摊金额。', true, false, 120),
            $this->column('complaint_count', '客诉数量', '由有权限的人员人工填写并保存，刷新和导出读取同一保存结果。', true, false, 110),
            $this->column('refund_amount', '退款金额', '仅退款成功事件显示分摊到当前商品或项目的实际退款金额，按退款成功日期统计。', true, false, 120),
        ];
        $result = $this->result('消耗及退款明细', $columns, $events, $input, $range);
        $result['editable_fields'] = [[
            'key' => 'complaint_count', 'label' => '客诉数量', 'type' => 'number', 'integer' => true,
            'min' => 0, 'allow_empty' => true, 'report_code' => 'six_dimension_consumption_refund_detail',
            'subject_type' => 'business_event_line',
        ]];
        return $result;
    }

    private function performanceDeal(array $stores, array $range, array $input): array
    {
        $categoryIds = $this->categoryScope($input);
        $purchaseRows = $this->purchaseItemRows($stores, $range, $categoryIds);
        $periodCashRows = $this->cashItemRows($stores, $range, $categoryIds);
        $memberIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
            array_column($purchaseRows, 'member_id'),
            array_column($periodCashRows, 'member_id')
        )))));
        $historyStores = $this->historicalStoreIds();
        if ($historyStores === []) $historyStores = $stores;
        $historicalPurchases = $memberIds === [] ? [] : $this->purchaseItemRows(
            $historyStores,
            ['start' => $this->coverageStart(), 'end' => $range['end']],
            $categoryIds,
            false,
            $memberIds
        );
        $historicalCashRows = $memberIds === [] ? [] : $this->cashItemRows(
            $historyStores,
            ['start' => $this->coverageStart(), 'end' => $range['end']],
            $categoryIds,
            false,
            $memberIds
        );
        $origins = $this->memberOrigins($memberIds);
        $identityMap = $this->memberIdentityMap($memberIds);
        $firstPurchase = $this->firstPurchaseKeysFromRows($historicalPurchases, $origins, $identityMap);
        $lineMeta = [];
        foreach ($historicalPurchases as $purchase) {
            $memberId = (int)$purchase['member_id'];
            if ($memberId <= 0) continue;
            $memberKey = (string)($identityMap[$memberId] ?? ('member:' . $memberId));
            $lineKey = $this->saleLineKey($purchase);
            $eventKey = $this->purchaseBusinessEventKey($purchase);
            $lineMeta[$lineKey] = [
                'member_key' => $memberKey,
                'is_first' => isset($firstPurchase[$memberKey])
                    && (string)$firstPurchase[$memberKey] === $this->purchaseEventSortKey($purchase),
                'event_key' => $eventKey,
                'store_id' => (int)$purchase['store_id'], 'store_name' => (string)$purchase['store_name'],
                'purchase_date' => (string)$purchase['business_date'],
            ];
        }
        $paidEvents = [];
        foreach ($historicalCashRows as $cash) {
            if ((string)$cash['fact_direction'] === 'forward' && (int)$cash['amount_cents'] > 0) {
                $cashMeta = (array)($lineMeta[$this->saleLineKey($cash)] ?? []);
                if (trim((string)($cashMeta['event_key'] ?? '')) !== '') {
                    $paidEvents[(string)$cashMeta['event_key']] = true;
                }
            }
        }
        $rows = [];
        $events = [];
        foreach ($purchaseRows as $purchase) {
            $storeId = (int)$purchase['store_id'];
            $memberKey = (string)($identityMap[(int)$purchase['member_id']] ?? ('member:' . (int)$purchase['member_id']));
            $date = (string)$purchase['business_date'];
            $rowKey = $this->purchaseBusinessEventKey($purchase);
            if (!isset($events[$rowKey])) {
                $events[$rowKey] = [
                    'store_id' => $storeId, 'store_name' => (string)$purchase['store_name'],
                    'organization_id' => (string)($purchase['organization_id'] ?? ''),
                    'organization_path_snapshot' => (string)($purchase['organization_path_snapshot'] ?? ''),
                    'date' => $date, 'member_key' => $memberKey,
                    'is_experience' => false, 'event_key' => $rowKey,
                    'event_sort_key' => $this->purchaseEventSortKey($purchase),
                ];
            }
            $events[$rowKey]['is_experience'] = $events[$rowKey]['is_experience'] || (int)$purchase['is_experience'] === 1;
        }
        $peopleSeen = [];
        foreach ($events as $event) {
            $storeId = (int)$event['store_id'];
            $date = (string)$event['date'];
            $memberKey = (string)$event['member_key'];
            $groupKey = $this->performanceGroupKey($event, $date);
            if (!isset($rows[$groupKey])) {
                $rows[$groupKey] = $this->emptyPerformanceDealRow(
                    $storeId, (string)$event['store_name'], $date, $event
                );
            }
            $isFirst = isset($firstPurchase[$memberKey])
                && (string)$firstPurchase[$memberKey] === (string)$event['event_sort_key'];
            $isGift = !isset($paidEvents[(string)$event['event_key']]);
            $peopleKey = $groupKey . '|' . $date . '|' . $memberKey;
            if ($event['is_experience']) {
                $metric = $isFirst ? ($isGift ? 'new_visit_gift' : 'new_visit_purchase') : 'old_visit_people';
                if (!isset($peopleSeen[$metric][$peopleKey])) {
                    $rows[$groupKey][$metric]++;
                    $peopleSeen[$metric][$peopleKey] = true;
                }
            }
            $metric = $isFirst ? 'new_deal_' . ($isGift ? 'gift' : 'purchase') : 'old_deal_people';
            if (!isset($peopleSeen[$metric][$peopleKey])) {
                $rows[$groupKey][$metric]++;
                $peopleSeen[$metric][$peopleKey] = true;
            }
        }
        foreach ($periodCashRows as $cash) {
            $lineKey = $this->saleLineKey($cash);
            $meta = (array)($lineMeta[$lineKey] ?? []);
            $storeId = (int)$cash['store_id'];
            $groupKey = $this->performanceGroupKey($cash, (string)$cash['business_date']);
            if (!isset($rows[$groupKey])) {
                $rows[$groupKey] = $this->emptyPerformanceDealRow(
                    $storeId,
                    (string)$cash['store_name'],
                    (string)$cash['business_date'],
                    $cash
                );
            }
            if (!empty($meta['is_first'])) {
                $suffix = isset($paidEvents[(string)($meta['event_key'] ?? '')]) ? 'purchase' : 'gift';
                $rows[$groupKey]['new_performance_' . $suffix . '_cents'] += (int)$cash['amount_cents'];
            } else {
                $rows[$groupKey]['old_performance_cents'] += (int)$cash['amount_cents'];
            }
        }
        foreach ($rows as &$row) {
            foreach (['old_performance', 'new_performance_purchase', 'new_performance_gift'] as $key) {
                $row[$key] = $this->money((int)$row[$key . '_cents']);
            }
            $row['old_unit_output'] = $this->moneyRatio($row['old_performance_cents'], $row['old_deal_people']);
            $row['new_unit_output_purchase'] = $this->moneyRatio($row['new_performance_purchase_cents'], $row['new_deal_purchase']);
            $row['new_unit_output_gift'] = $this->moneyRatio($row['new_performance_gift_cents'], $row['new_deal_gift']);
        }
        unset($row);
        $columns = [
            $this->column('company_name', '分公司', '取成交业务所属组织路径中配置为分公司的组织名称。', false, true, 130),
            $this->column('store_name', '门店', '取成交实际发生时保存的门店名称。', false, true, 140),
            $this->column('old_visit_people', '老客见诊人次', '更早购买过所选分类、当前又发生带体验标记有效业务的会员人次；按业务日期和会员身份去重。', true, false, 120),
            $this->column('new_visit_purchase', '购买', '第一次购买所选分类、成功现金业绩大于零且带体验标记的会员人次。', true, false, 100, [], '新客见诊人次'),
            $this->column('new_visit_gift', '赠送', '第一次取得所选分类、现金业绩为零且带体验标记的会员人次。', true, false, 100, [], '新客见诊人次'),
            $this->column('old_deal_people', '老客成交人次', '已购买过所选分类后再次成功取得该分类的会员人次。', true, false, 120),
            $this->column('new_deal_purchase', '购买', '第一次购买所选分类且成功现金业绩大于零的会员人次。', true, false, 100, [], '新客成交人次'),
            $this->column('new_deal_gift', '赠送', '第一次取得所选分类且现金业绩为零的会员人次。', true, false, 100, [], '新客成交人次'),
            $this->column('old_performance', '老客成交业绩', '老客再次购买所选分类后按销售明细分摊到的净现金业绩。', true, false, 130),
            $this->column('new_performance_purchase', '购买', '新客第一次付费购买所选分类后分摊到的净现金业绩。', true, false, 110, [], '新客成交业绩'),
            $this->column('new_performance_gift', '赠送', '新客第一次零现金取得所选分类对应的现金业绩，正常情况下为零。', true, false, 110, [], '新客成交业绩'),
            $this->column('old_unit_output', '老客成交单产', '老客成交业绩除以老客成交人次；分母为零时显示“-”。', false),
            $this->column('new_unit_output_purchase', '购买', '新客成交业绩/购买除以新客成交人次/购买；分母为零时显示“-”。', false, false, 110, [], '新客成交单产'),
            $this->column('new_unit_output_gift', '赠送', '新客成交业绩/赠送除以新客成交人次/赠送；分母为零时显示“-”。', false, false, 110, [], '新客成交单产'),
        ];
        return $this->result('业绩成交表', $columns, array_values($rows), $input, $range, ['category' => true]);
    }

    private function performanceDistribution(array $stores, array $range, array $input): array
    {
        [$monthStart, $monthEnd] = $this->naturalMonth($range);
        $previousStart = date('Y-m-01', strtotime($monthStart . ' -1 month'));
        $previousEnd = date('Y-m-t', strtotime($previousStart));
        $dimensions = $this->organizationDimensions('city_manager', $stores, $monthEnd);
        $sixDimensionCategories = $this->sixDimensionCategoryIds();
        $currentCash = $this->cashByDimension(
            'city_manager', $stores, ['start' => $monthStart, 'end' => $monthEnd], $sixDimensionCategories
        );
        $previousCash = $this->cashByDimension(
            'city_manager', $stores, ['start' => $previousStart, 'end' => $previousEnd], $sixDimensionCategories
        );
        $grouped = [];
        foreach ($dimensions as $dimension) {
            $dimensionId = (string)$dimension['dimension_id'];
            if (!isset($grouped[$dimensionId])) {
                $grouped[$dimensionId] = [
                    'city_manager_dimension_id' => $dimensionId,
                    'city_manager' => (string)$dimension['dimension_name'], '_store_ids' => [],
                    'previous_completed_cents' => 0, 'current_completed_cents' => 0,
                    '_sort' => (int)($dimension['sort_order'] ?? 0),
                ];
            }
            $storeId = (int)$dimension['store_id'];
            if (!in_array($storeId, $stores, true)) continue;
            $grouped[$dimensionId]['_store_ids'][$storeId] = true;
        }
        $definitions = $this->configuredDimensionsAcrossRange('city_manager', [
            'start' => $previousStart, 'end' => $monthEnd,
        ], $stores);
        foreach (array_values(array_unique(array_merge(array_keys($previousCash), array_keys($currentCash)))) as $dimensionId) {
            $dimensionId = (string)$dimensionId;
            if (isset($grouped[$dimensionId]) || !isset($definitions[$dimensionId])) continue;
            $definition = $definitions[$dimensionId];
            $grouped[$dimensionId] = [
                'city_manager_dimension_id' => $dimensionId,
                'city_manager' => (string)$definition['name'], '_store_ids' => [],
                'previous_completed_cents' => 0, 'current_completed_cents' => 0,
                '_sort' => (int)$definition['sort_order'],
            ];
        }
        foreach ($grouped as &$row) {
            $dimensionId = (string)$row['city_manager_dimension_id'];
            $row['previous_completed_cents'] = (int)($previousCash[$dimensionId] ?? 0);
            $row['current_completed_cents'] = (int)($currentCash[$dimensionId] ?? 0);
            $row['store_count'] = count($row['_store_ids']);
            $row['previous_target_cents'] = $row['store_count'] * 2000000;
            $row['current_target_cents'] = $row['store_count'] * 2000000;
            $row['previous_completed'] = $this->money($row['previous_completed_cents']);
            $row['previous_target'] = $this->money($row['previous_target_cents']);
            $row['previous_rate'] = $this->ratio($row['previous_completed_cents'], $row['previous_target_cents']);
            $row['current_completed'] = $this->money($row['current_completed_cents']);
            $row['current_target'] = $this->money($row['current_target_cents']);
            $row['current_rate'] = $this->ratio($row['current_completed_cents'], $row['current_target_cents']);
        }
        unset($row);
        $this->denseRanks($grouped, 'previous_completed_cents', 'previous_target_cents', 'previous_rank');
        $this->denseRanks($grouped, 'current_completed_cents', 'current_target_cents', 'current_rank');
        uasort($grouped, static function (array $left, array $right): int {
            return (int)$left['_sort'] <=> (int)$right['_sort'] ?: strcmp((string)$left['city_manager'], (string)$right['city_manager']);
        });
        foreach ($grouped as &$row) unset($row['_store_ids'], $row['_sort']);
        unset($row);
        $columns = [
            $this->column('city_manager', '城市经理', '取配置为城市经理统计维度的组织名称。', false, true, 150),
            $this->column('store_count', '城市经理店面数量', '统计该城市经理节点下当前有效且在当前账号权限范围内的门店数量。', true, true, 140),
            $this->column('previous_completed', '完成金额', '上一自然月下级门店六维及全部下级分类的净现金业绩。', true, false, 120, $this->drill('previous_completed', [
                'start_date' => $previousStart, 'end_date' => $previousEnd, 'six_dimension_only' => 1,
                'dimension_as_of' => $monthEnd,
            ], ['city_manager_dimension_id' => 'city_manager_dimension_id']), '上月'),
            $this->column('previous_target', '应完成业绩', '城市经理店面数量乘以20,000元。', true, false, 120, [], '上月'),
            $this->column('previous_rate', '完成率', '上月完成金额除以上月应完成业绩；分母为零时显示“-”。', false, false, 100, [], '上月'),
            $this->column('previous_rank', '完成率排名', '全部可见城市经理按上月完成率从高到低排名，同率同名次。', false, false, 110, [], '上月'),
            $this->column('current_completed', '完成金额', '所选自然月下级门店六维及全部下级分类的净现金业绩。', true, false, 120, $this->drill('current_completed', [
                'start_date' => $monthStart, 'end_date' => $monthEnd, 'six_dimension_only' => 1,
                'dimension_as_of' => $monthEnd,
            ], ['city_manager_dimension_id' => 'city_manager_dimension_id']), '本月'),
            $this->column('current_target', '应完成业绩', '城市经理店面数量乘以20,000元。', true, false, 120, [], '本月'),
            $this->column('current_rate', '完成率', '本月完成金额除以本月应完成业绩；分母为零时显示“-”。', false, false, 100, [], '本月'),
            $this->column('current_rank', '完成率排名', '全部可见城市经理按本月完成率从高到低排名，同率同名次。', false, false, 110, [], '本月'),
        ];
        return $this->result('业绩分布表', $columns, array_values($grouped), $input, [
            'start' => $monthStart, 'end' => $monthEnd,
        ], ['natural_month' => true]);
    }

    private function performanceMarketDistribution(array $stores, array $range, array $input): array
    {
        $companies = $this->configuredDimensionsAcrossRange('company', $range, $stores);
        uasort($companies, static function (array $left, array $right): int {
            return (int)$left['sort_order'] <=> (int)$right['sort_order'] ?: strcmp((string)$left['name'], (string)$right['name']);
        });
        $rows = [];
        foreach ($this->months($range) as $month) {
            $rows[$month] = ['month' => $month, 'group_total_cents' => 0];
            foreach ($companies as $company) $rows[$month]['company_' . $company['id'] . '_cents'] = 0;
        }
        foreach ($this->cashItemRows($stores, $range, $this->sixDimensionCategoryIds()) as $cash) {
            $month = substr((string)$cash['business_date'], 0, 7);
            if (!isset($rows[$month])) continue;
            $amount = (int)$cash['amount_cents'];
            $rows[$month]['group_total_cents'] += $amount;
            $dimension = $this->dimensionForEvent(
                'company', (string)($cash['organization_id'] ?? ''),
                (string)($cash['organization_path_snapshot'] ?? ''),
                (int)$cash['store_id'], (string)$cash['business_date']
            );
            $companyId = (string)($dimension['organization_id'] ?? '');
            if ($companyId !== '' && isset($companies[$companyId])) {
                $rows[$month]['company_' . $companyId . '_cents'] += $amount;
            }
        }
        foreach ($rows as &$row) {
            $row['group_total'] = $this->money($row['group_total_cents']);
            foreach ($companies as $company) {
                $key = 'company_' . $company['id'];
                $row[$key . '_amount'] = $this->money((int)$row[$key . '_cents']);
                $row[$key . '_share'] = $this->ratio((int)$row[$key . '_cents'], (int)$row['group_total_cents']);
            }
        }
        unset($row);
        $columns = [
            $this->column('month', '月份', '将顶部日期范围内的数据按自然月分组显示。', false, true, 100),
            $this->column('group_total', '集团六维完成业绩', '当前权限范围内所有门店六维及全部下级分类的当月净现金业绩合计。', true, true, 160, $this->drill('group_total', [
                'six_dimension_only' => 1, 'dimension_as_of' => $range['end'],
            ], ['drill_month' => 'month'])),
        ];
        foreach ($companies as $company) {
            $key = 'company_' . $company['id'];
            $columns[] = $this->column(
                $key . '_amount', '完成金额',
                '统计“' . $company['name'] . '”配置节点下有效且有权门店当月六维净现金业绩；没有成交时为0。',
                true, false, 120, $this->drill('company_amount', [
                    'company_dimension_id' => $company['id'], 'six_dimension_only' => 1,
                    'dimension_as_of' => $range['end'],
                ], ['drill_month' => 'month']),
                (string)$company['name']
            );
            $columns[] = $this->column(
                $key . '_share', '业绩占比',
                '“' . $company['name'] . '”完成金额除以当月集团六维完成业绩；分母为零时显示“-”。',
                false, false, 110, [], (string)$company['name']
            );
        }
        return $this->result('业绩市场分布表', $columns, array_values($rows), $input, $range);
    }

    private function result(string $title, array $columns, array $records, array $input, array $range, array $filters = []): array
    {
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $visible = !empty($input['_internal_all']) ? $records : array_slice($records, ($page - 1) * $limit, $limit);
        $summary = [];
        foreach ($columns as $column) $summary[(string)$column['key']] = '-';
        if ($columns !== []) $summary[(string)$columns[0]['key']] = '合计';
        foreach ($columns as $column) {
            if (empty($column['summable'])) continue;
            $key = (string)$column['key'];
            if ($this->moneyColumn($key)) {
                $cents = 0;
                foreach ($records as $record) $cents += $this->decimalCents($record[$key] ?? 0);
                $summary[$key] = $this->money($cents);
            } else {
                $value = 0;
                foreach ($records as $record) $value += (int)($record[$key] ?? 0);
                $summary[$key] = $value;
            }
        }
        $groups = [];
        foreach ($columns as $column) {
            $label = trim((string)($column['group_label'] ?? ''));
            if ($label !== '') $groups[$label][] = (string)$column['key'];
        }
        $columnGroups = [];
        foreach ($groups as $label => $keys) {
            $columnGroups[] = ['label' => $label, 'column_keys' => $keys, 'colspan' => count($keys), 'rowspan' => 1];
        }
        if ($columnGroups !== []) {
            foreach ($columns as &$column) {
                $column['header_rowspan'] = trim((string)($column['group_label'] ?? '')) === '' ? 2 : 1;
            }
            unset($column);
        }
        $filterSchema = [
            ['key' => 'organization_store_scope', 'label' => '组织 / 门店', 'type' => 'organization_store_scope'],
        ];
        if (!empty($filters['natural_month'])) {
            $filterSchema[] = ['key' => 'month', 'label' => '月份', 'type' => 'month', 'single_natural_month' => true];
        } else {
            $filterSchema[] = ['key' => 'date_range', 'label' => '日期', 'type' => 'date_range', 'inclusive' => true];
        }
        if (!empty($filters['category'])) {
            $filterSchema[] = ['key' => 'category_path', 'label' => '商品分类', 'type' => 'category_tree', 'include_descendants' => true];
        }
        return [
            'title' => $title, 'columns' => $columns, 'records' => $visible,
            'total' => count($records), 'page' => $page, 'page_size' => $limit,
            'summary_row' => $summary, 'column_groups' => $columnGroups,
            'filter_schema' => $filterSchema,
            'source_explanations' => array_values(array_map(static function (array $column): array {
                return ['key' => (string)$column['key'], 'label' => (string)$column['label'], 'source_explanation' => (string)$column['source_explanation']];
            }, $columns)),
            'drilldown_keys' => [
                'store_id', 'item_id', 'category_id', 'metric_code', 'company_dimension_id',
                'city_manager_dimension_id', 'dimension_as_of', 'drill_month', 'six_dimension_only',
                'start_date', 'end_date',
            ],
            'table_layout' => [
                'fixed' => true, 'sticky_query' => true, 'sticky_header' => true,
                'sticky_summary' => true, 'result_scroll' => true,
            ],
            'metric_version' => self::METRIC_VERSION,
            'coverage_start' => $this->coverageStart(),
            'fact_completeness' => '卡内项目分摊事实从当前实例第三阶段升级后的首个完整自然日开始完整覆盖；导入和来源无法证明的会员按老客处理。',
            'data_as_of' => date('Y-m-d H:i:s'), 'aggregation_status' => 'reconciled',
            'scope' => ['date_range' => $range],
        ];
    }

    private function column(
        string $key,
        string $label,
        string $explanation,
        bool $summable = false,
        bool $fixed = false,
        int $width = 110,
        array $drilldown = [],
        string $groupLabel = ''
    ): array {
        $column = [
            'key' => $key, 'label' => $label, 'source_explanation' => $explanation,
            'summable' => $summable, 'width' => $width,
        ];
        if ($fixed) {
            $column['fixed'] = 'left';
            $column['fixed_width'] = $width;
        }
        if ($drilldown !== []) $column['drilldown'] = $drilldown;
        if ($groupLabel !== '') $column['group_label'] = $groupLabel;
        return $column;
    }

    private function drill(string $metric, array $params = [], array $paramMap = []): array
    {
        $result = [
            'report' => 'six_dimension_consumption_refund_detail',
            'params' => array_merge(['metric_code' => $metric], $params),
        ];
        if ($paramMap !== []) $result['param_map'] = $paramMap;
        return $result;
    }

    private function purchaseItemRows(
        array $stores,
        array $range,
        array $categoryIds,
        bool $applyParticipantScope = true,
        array $memberIds = []
    ): array
    {
        $direct = Db::name('cashier_v3_sale_fact')->alias('s')
            ->join('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
            ->leftJoin('user u', 'u.uid=s.member_id')
            ->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('s.store_id', $stores)->whereBetween('s.business_date', [$range['start'], $range['end']])
            ->where('s.status', 'effective')->where('s.fact_direction', 'forward')->where('s.source_type', '<>', 'card');
        $this->excludeVoidedSalesOrders($direct, 's.tenant_id', 's.order_id');
        if ($memberIds !== []) $direct->whereIn('s.member_id', $memberIds);
        if ($applyParticipantScope && $this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyOrder($direct, 's.order_id', $this->participantEmployeeId);
        }
        if ($categoryIds !== []) $direct->whereIn('d.category_id_snapshot', $categoryIds);
        $rows = $direct->fieldRaw("s.id,s.fact_id,s.business_event_no,s.organization_id,s.organization_path_snapshot,s.store_id,s.store_name_snapshot store_name,s.member_id,u.phone,s.order_id,s.source_line_id,s.business_date,s.occurred_at,s.quantity,d.item_id,d.item_name_snapshot item_name,d.category_id_snapshot category_id,d.category_path_snapshot category_path,d.is_experience")
            ->order('s.business_date', 'asc')->order('s.id', 'asc')->select()->toArray();

        $card = Db::name('cashier_v3_card_sale_item_allocation_fact')->alias('ci')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=ci.tenant_id AND s.fact_id=ci.sale_fact_id')
            ->leftJoin('user u', 'u.uid=ci.member_id')
            ->where('ci.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('ci.store_id', $stores)->whereBetween('ci.business_date', [$range['start'], $range['end']])
            ->where('ci.status', 'effective');
        $this->excludeVoidedSalesOrders($card, 'ci.tenant_id', 'ci.order_id');
        if ($memberIds !== []) $card->whereIn('ci.member_id', $memberIds);
        if ($applyParticipantScope && $this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyOrder($card, 'ci.order_id', $this->participantEmployeeId);
        }
        if ($categoryIds !== []) $card->whereIn('ci.category_id_snapshot', $categoryIds);
        foreach ($card->fieldRaw("ci.id,ci.allocation_fact_id fact_id,s.business_event_no,s.organization_id,s.organization_path_snapshot,ci.store_id,s.store_name_snapshot store_name,ci.member_id,u.phone,ci.order_id,ci.source_line_id,ci.business_date,ci.occurred_at,ci.component_count quantity,ci.component_product_id item_id,ci.item_name_snapshot item_name,ci.category_id_snapshot category_id,ci.category_path_snapshot category_path,0 is_experience")
            ->order('ci.business_date', 'asc')->order('ci.id', 'asc')->select()->toArray() as $row) $rows[] = $row;
        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)$left['business_date'], (string)$right['business_date'])
                ?: ((int)$left['occurred_at'] <=> (int)$right['occurred_at'])
                ?: ((int)$left['id'] <=> (int)$right['id']);
        });
        return $rows;
    }

    private function cashItemRows(
        array $stores,
        array $range,
        array $categoryIds,
        bool $applyParticipantScope = true,
        array $memberIds = []
    ): array
    {
        $query = $this->paymentAllocationQuery($stores, $range, $applyParticipantScope, $memberIds)
            ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.fact_id=COALESCE(original_allocation.sale_fact_id,p.sale_fact_id)')
            ->join('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
            ->leftJoin('cashier_v3_business_event payment_event', 'payment_event.tenant_id=p.tenant_id AND payment_event.event_no=p.business_event_no')
            ->leftJoin('cashier_v3_checkout_request payment_request', 'payment_request.tenant_id=p.tenant_id AND payment_request.request_id=p.checkout_request_id')
            ->leftJoin('cashier_v3_sales_manager_fact sm', "sm.tenant_id=s.tenant_id AND sm.order_id=s.order_id AND sm.source_line_id=s.source_line_id")
            ->where('s.source_type', '<>', 'card');
        if ($categoryIds !== []) $query->whereIn('d.category_id_snapshot', $categoryIds);
        $rows = $query->fieldRaw("p.id source_fact_row_id,p.allocation_fact_id,p.business_event_no,p.fact_direction,MAX(financial_reversal.reversal_type) reversal_type,p.organization_id,COALESCE(MAX(payment_event.organization_path),MAX(payment_request.organization_path),'') organization_path_snapshot,p.store_id,COALESCE(MAX(payment_event.store_name_snapshot),MAX(payment_request.store_name_snapshot),'') store_name,p.member_id,p.order_id,p.source_line_id,p.business_date,p.occurred_at,p.amount_cents,s.business_date purchase_date,d.item_id,d.item_name_snapshot item_name,d.category_id_snapshot category_id,d.category_path_snapshot category_path,MAX(sm.sales_manager_name_snapshot) sales_manager_name")
            ->group('p.id')->order('p.business_date', 'asc')->order('p.id', 'asc')->select()->toArray();
        foreach ($rows as &$row) {
            if (trim((string)$row['store_name']) === '') $row['store_name'] = $this->storeName((int)$row['store_id']);
        }
        unset($row);

        $cardPayments = $this->paymentAllocationQuery($stores, $range, $applyParticipantScope, $memberIds)
            ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.fact_id=COALESCE(original_allocation.sale_fact_id,p.sale_fact_id)')
            ->leftJoin('cashier_v3_business_event payment_event', 'payment_event.tenant_id=p.tenant_id AND payment_event.event_no=p.business_event_no')
            ->leftJoin('cashier_v3_checkout_request payment_request', 'payment_request.tenant_id=p.tenant_id AND payment_request.request_id=p.checkout_request_id')
            ->leftJoin('cashier_v3_sales_manager_fact sm', "sm.tenant_id=s.tenant_id AND sm.order_id=s.order_id AND sm.source_line_id=s.source_line_id")
            ->where('s.source_type', 'card')
            ->fieldRaw("p.id source_fact_row_id,p.allocation_fact_id,p.business_event_no,p.fact_direction,MAX(financial_reversal.reversal_type) reversal_type,p.organization_id,COALESCE(MAX(payment_event.organization_path),MAX(payment_request.organization_path),'') organization_path_snapshot,p.store_id,COALESCE(MAX(payment_event.store_name_snapshot),MAX(payment_request.store_name_snapshot),'') store_name,p.member_id,p.order_id,p.source_line_id,p.business_date,p.occurred_at,p.amount_cents,s.fact_id effective_sale_fact_id,s.business_date purchase_date,MAX(sm.sales_manager_name_snapshot) sales_manager_name")
            ->group('p.id')->order('p.business_date', 'asc')->order('p.id', 'asc')->select()->toArray();
        foreach ($cardPayments as &$payment) {
            if (trim((string)$payment['store_name']) === '') $payment['store_name'] = $this->storeName((int)$payment['store_id']);
        }
        unset($payment);
        if ($cardPayments === []) return $rows;
        $saleFactIds = array_values(array_unique(array_column($cardPayments, 'effective_sale_fact_id')));
        $itemQuery = Db::name('cashier_v3_card_sale_item_allocation_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sale_fact_id', $saleFactIds)->where('status', 'effective');
        $categoryFilter = array_fill_keys(array_map('intval', $categoryIds), true);
        $itemsBySale = [];
        foreach ($itemQuery->field('id,allocation_fact_id,sale_fact_id,component_product_id,item_name_snapshot,category_id_snapshot,category_path_snapshot,component_count,sale_amount_cents,configured_amount_cents')->order('allocation_fact_id', 'asc')->select()->toArray() as $item) {
            $itemsBySale[(string)$item['sale_fact_id']][] = $item;
        }
        foreach ($cardPayments as $payment) {
            $items = (array)($itemsBySale[(string)$payment['effective_sale_fact_id']] ?? []);
            if ($items === []) continue;
            $allocations = $this->allocateSigned((int)$payment['amount_cents'], $items);
            foreach ($items as $item) {
                if ($categoryFilter !== [] && !isset($categoryFilter[(int)$item['category_id_snapshot']])) continue;
                $rows[] = [
                    'source_fact_row_id' => (int)$payment['source_fact_row_id'],
                    'allocation_fact_id' => (string)$payment['allocation_fact_id'] . ':' . (string)$item['allocation_fact_id'],
                    'business_event_no' => (string)$payment['business_event_no'],
                    'fact_direction' => (string)$payment['fact_direction'],
                    'reversal_type' => (string)($payment['reversal_type'] ?? ''),
                    'organization_id' => (string)$payment['organization_id'],
                    'organization_path_snapshot' => (string)$payment['organization_path_snapshot'],
                    'store_id' => (int)$payment['store_id'],
                    'store_name' => (string)$payment['store_name'], 'member_id' => (int)$payment['member_id'],
                    'order_id' => (string)$payment['order_id'], 'source_line_id' => (string)$payment['source_line_id'],
                    'business_date' => (string)$payment['business_date'], 'occurred_at' => (int)$payment['occurred_at'],
                    'amount_cents' => (int)($allocations[(string)$item['allocation_fact_id']] ?? 0),
                    'purchase_date' => (string)$payment['purchase_date'], 'item_id' => (int)$item['component_product_id'],
                    'item_name' => (string)$item['item_name_snapshot'], 'category_id' => (int)$item['category_id_snapshot'],
                    'category_path' => (string)$item['category_path_snapshot'],
                    'sales_manager_name' => (string)$payment['sales_manager_name'],
                ];
            }
        }
        return $rows;
    }

    private function paymentAllocationQuery(
        array $stores,
        array $range,
        bool $applyParticipantScope = true,
        array $memberIds = []
    )
    {
        $query = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('p')
            ->leftJoin('cashier_v3_payment_sale_allocation_fact original_allocation', 'original_allocation.tenant_id=p.tenant_id AND original_allocation.allocation_fact_id=p.reversal_of')
            ->leftJoin('cashier_v3_order_lifecycle_operation lifecycle_operation', "lifecycle_operation.tenant_id=p.tenant_id AND lifecycle_operation.business_event_no=p.business_event_no AND lifecycle_operation.source_order_id=p.order_id AND lifecycle_operation.status='succeeded'")
            ->leftJoin('cashier_v3_order_lifecycle_financial_reversal financial_reversal', "financial_reversal.tenant_id=p.tenant_id AND financial_reversal.operation_id=lifecycle_operation.operation_id AND financial_reversal.status='succeeded'")
            ->where('p.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('p.store_id', $stores)->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective');
        // Refunds and voids are append-only signed allocations. Keeping both
        // rows preserves the original month and records the adjustment date.
        if ($memberIds !== []) $query->whereIn('p.member_id', $memberIds);
        if ($applyParticipantScope && $this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyOrder($query, 'p.order_id', $this->participantEmployeeId);
        }
        return $query;
    }

    private function excludeVoidedSalesOrders($query, string $tenantColumn, string $orderColumn): void
    {
        $query->whereNotExists(function ($operation) use ($tenantColumn, $orderColumn): void {
            $operation->name('cashier_v3_order_lifecycle_operation')->alias('void_operation')
                ->whereRaw('void_operation.tenant_id = ' . $tenantColumn)
                ->whereRaw('void_operation.source_order_id = ' . $orderColumn)
                ->where('void_operation.source_type', 'sales')
                ->where('void_operation.operation_type', 'void')
                ->where('void_operation.status', 'succeeded');
        });
    }

    private function completedServices(array $stores, array $range, array $categoryIds): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)->whereBetween('sv.business_date', [$range['start'], $range['end']])
            ->where('sv.service_status', 'completed');
        if ($categoryIds !== []) $query->whereIn('sv.project_category_id_snapshot', $categoryIds);
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        }
        return $query->fieldRaw("sv.id,sv.service_fact_id fact_id,sv.organization_id,sv.organization_path_snapshot,sv.store_id,sv.store_name_snapshot store_name,sv.member_id,sv.business_date,sv.occurred_at,sv.quantity,sv.project_id item_id,sv.project_name_snapshot item_name,sv.project_category_id_snapshot category_id,sv.project_category_path_snapshot category_path,sv.is_experience")
            ->order('sv.business_date', 'asc')->order('sv.id', 'asc')->select()->toArray();
    }

    private function memberCashTotals(array $stores, array $range, array $categoryIds): array
    {
        $totals = [];
        foreach ($this->cashItemRows($stores, $range, $categoryIds) as $row) {
            $memberId = (int)$row['member_id'];
            if ($memberId > 0) $totals[$memberId] = (int)($totals[$memberId] ?? 0) + (int)$row['amount_cents'];
        }
        return $totals;
    }

    private function activeMemberIds(array $stores, array $range): array
    {
        $result = [];
        foreach ($this->completedServices($stores, $range, []) as $service) {
            $memberId = (int)$service['member_id'];
            if ($memberId > 0) $result[(string)$memberId] = $memberId;
        }
        return array_values($result);
    }

    private function serviceEventRows(array $stores, array $range): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->leftJoin('cashier_v3_entitlement_writeoff_fact w', 'w.tenant_id=sv.tenant_id AND w.checkout_request_id=sv.checkout_request_id AND w.source_line_id=sv.source_line_id')
            ->leftJoin('cashier_v3_card_purchase_receipt cr', 'cr.tenant_id=sv.tenant_id AND cr.legacy_order_id=w.origin_order_id')
            ->leftJoin('cashier_v3_sale_fact sale', 'sale.tenant_id=sv.tenant_id AND sale.order_id=cr.sales_order_id AND sale.source_line_id=cr.sales_order_line_id AND sale.fact_direction=\'forward\' AND sale.status=\'effective\'')
            ->leftJoin('store_order legacy_order', 'legacy_order.id=w.origin_order_id')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)->whereBetween('sv.business_date', [$range['start'], $range['end']])
            ->where('sv.service_status', 'completed');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        }
        $rows = $query->fieldRaw("sv.id,sv.service_fact_id,sv.organization_id,sv.organization_path_snapshot,sv.store_id,sv.store_name_snapshot,sv.business_date,sv.occurred_at,sv.checkout_request_id,sv.source_line_id,sv.project_id,sv.project_name_snapshot,sv.craftsmen_snapshot_json,MAX(w.origin_order_id) origin_order_id,MAX(cr.sales_order_id) origin_sales_order_id,MAX(cr.sales_order_line_id) origin_sales_order_line_id,COALESCE(MIN(sale.business_date),FROM_UNIXTIME(MAX(legacy_order.pay_time),'%Y-%m-%d'),FROM_UNIXTIME(MAX(legacy_order.add_time),'%Y-%m-%d'),'-') purchase_date")
            ->group('sv.id')->order('sv.business_date', 'desc')->order('sv.id', 'desc')->select()->toArray();
        if ($rows === []) return [];

        $checkoutIds = array_values(array_unique(array_filter(array_column($rows, 'checkout_request_id'))));
        $lineIds = array_values(array_unique(array_filter(array_column($rows, 'source_line_id'))));
        $consumptionByServiceLine = [];
        if ($checkoutIds !== [] && $lineIds !== []) {
            $performanceRows = Db::name('cashier_v3_performance_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('checkout_request_id', $checkoutIds)->whereIn('source_line_id', $lineIds)
                ->where('performance_type', 'consumption_performance_recorded')
                ->where('fact_direction', 'forward')->where('status', 'effective')
                ->fieldRaw('checkout_request_id,source_line_id,SUM(amount_cents) amount_cents')
                ->group('checkout_request_id,source_line_id')->select()->toArray();
            foreach ($performanceRows as $performance) {
                $key = (string)$performance['checkout_request_id'] . '|' . (string)$performance['source_line_id'];
                $consumptionByServiceLine[$key] = (int)$performance['amount_cents'];
            }
        }

        $salesOrderIds = array_values(array_unique(array_filter(array_column($rows, 'origin_sales_order_id'))));
        $managerBySaleLine = [];
        if ($salesOrderIds !== []) {
            $managerRows = Db::name('cashier_v3_sales_manager_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('order_id', $salesOrderIds)->where('status', 'effective')
                ->fieldRaw('order_id,source_line_id,MAX(sales_manager_name_snapshot) sales_manager_name')
                ->group('order_id,source_line_id')->select()->toArray();
            foreach ($managerRows as $manager) {
                $key = (string)$manager['order_id'] . '|' . (string)$manager['source_line_id'];
                $managerBySaleLine[$key] = (string)$manager['sales_manager_name'];
            }
        }
        foreach ($rows as &$row) {
            $serviceKey = (string)$row['checkout_request_id'] . '|' . (string)$row['source_line_id'];
            $saleKey = (string)$row['origin_sales_order_id'] . '|' . (string)$row['origin_sales_order_line_id'];
            $row['consumption_amount_cents'] = (int)($consumptionByServiceLine[$serviceKey] ?? 0);
            $row['sales_manager_name'] = (string)($managerBySaleLine[$saleKey] ?? '');
        }
        unset($row);
        return $rows;
    }

    private function transferEventRows(array $stores, array $range): array
    {
        $headerQuery = Db::name('cashier_v3_card_operation')->alias('op')
            ->leftJoin('store_order origin_order', 'origin_order.id=op.origin_order_id')
            ->leftJoin('cashier_v3_card_purchase_receipt transfer_receipt', 'transfer_receipt.tenant_id=op.tenant_id AND transfer_receipt.legacy_order_id=op.origin_order_id')
            ->leftJoin('cashier_v3_sales_manager_fact transfer_manager', "transfer_manager.tenant_id=op.tenant_id AND transfer_manager.order_id=transfer_receipt.sales_order_id AND transfer_manager.source_line_id=transfer_receipt.sales_order_line_id AND transfer_manager.status='effective'")
            ->where('op.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('op.store_id', $stores)->whereBetween('op.business_date', [$range['start'], $range['end']])
            ->where('op.operation_status', 'succeeded')->where('op.operation_type', 'card_upgrade');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($headerQuery, 'op.checkout_request_id', $this->participantEmployeeId);
        }
        $rows = $headerQuery->fieldRaw("op.id line_row_id,op.operation_id,op.operation_type,op.organization_id,op.organization_path_snapshot,op.store_id,op.store_name_snapshot,op.origin_order_id,op.card_name_snapshot,op.source_remaining_value_cents,op.business_date,op.occurred_at,FROM_UNIXTIME(COALESCE(NULLIF(origin_order.pay_time,0),origin_order.add_time),'%Y-%m-%d') purchase_date,MAX(transfer_manager.sales_manager_name_snapshot) sales_manager_name")
            ->group('op.id')
            ->order('op.business_date', 'desc')->order('op.id', 'desc')->select()->toArray();
        $normalized = [];
        foreach ($rows as $row) {
            $row['operation_line_id'] = '';
            $row['item_name'] = (string)$row['card_name_snapshot'];
            $row['transfer_amount_cents'] = abs((int)$row['source_remaining_value_cents']);
            $row['annotation_subject_key'] = 'card-operation:' . (string)$row['operation_id'];
            $normalized[] = $row;
        }

        $lineQuery = Db::name('cashier_v3_card_operation')->alias('op')
            ->join('cashier_v3_card_operation_line line', 'line.tenant_id=op.tenant_id AND line.operation_id=op.operation_id')
            ->leftJoin('store_order origin_order', 'origin_order.id=op.origin_order_id')
            ->leftJoin('cashier_v3_card_purchase_receipt transfer_receipt', 'transfer_receipt.tenant_id=op.tenant_id AND transfer_receipt.legacy_order_id=op.origin_order_id')
            ->leftJoin('cashier_v3_sales_manager_fact transfer_manager', "transfer_manager.tenant_id=op.tenant_id AND transfer_manager.order_id=transfer_receipt.sales_order_id AND transfer_manager.source_line_id=transfer_receipt.sales_order_line_id AND transfer_manager.status='effective'")
            ->where('op.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('op.store_id', $stores)->whereBetween('op.business_date', [$range['start'], $range['end']])
            ->where('op.operation_status', 'succeeded')
            ->whereIn('op.operation_type', ['project_upgrade', 'project_replacement'])
            ->where('line.line_role', 'source_project');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($lineQuery, 'op.checkout_request_id', $this->participantEmployeeId);
        }
        $projectRows = $lineQuery->fieldRaw("line.id line_row_id,line.operation_line_id,line.line_snapshot_json,line.amount_cents,op.operation_id,op.operation_type,op.organization_id,op.organization_path_snapshot,op.store_id,op.store_name_snapshot,op.origin_order_id,op.card_name_snapshot,op.source_remaining_value_cents,op.business_date,op.occurred_at,FROM_UNIXTIME(COALESCE(NULLIF(origin_order.pay_time,0),origin_order.add_time),'%Y-%m-%d') purchase_date,MAX(transfer_manager.sales_manager_name_snapshot) sales_manager_name")
            ->group('line.id')
            ->order('op.business_date', 'desc')->order('line.id', 'desc')->select()->toArray();
        foreach ($projectRows as $row) {
            $snapshot = json_decode((string)$row['line_snapshot_json'], true);
            $row['item_name'] = trim((string)($snapshot['projectNameSnapshot'] ?? $snapshot['sourceProjectName'] ?? ''))
                ?: (string)$row['card_name_snapshot'];
            $row['transfer_amount_cents'] = abs((int)$row['amount_cents']);
            $row['annotation_subject_key'] = 'card-operation-line:' . (string)$row['operation_line_id'];
            $normalized[] = $row;
        }
        return $normalized;
    }

    private function firstPurchaseKeysFromRows(array $rows, array $origins, array $identityMap): array
    {
        $first = [];
        $returningIdentities = [];
        foreach ($rows as $row) {
            $memberId = (int)$row['member_id'];
            if ($memberId <= 0) continue;
            $key = (string)($identityMap[$memberId] ?? ('member:' . $memberId));
            if (!$this->newMemberEligible((array)($origins[$memberId] ?? $origins[(string)$memberId] ?? []))) {
                $returningIdentities[$key] = true;
                continue;
            }
            $value = $this->purchaseEventSortKey($row);
            if (!isset($first[$key]) || strcmp($value, $first[$key]) < 0) $first[$key] = $value;
        }
        foreach (array_keys($returningIdentities) as $key) unset($first[$key]);
        return $first;
    }

    private function memberIdentityMap(array $memberIds): array
    {
        return $this->foundation()->memberIdentityKeys($memberIds);
    }

    private function saleLineKey(array $row): string
    {
        return (string)($row['order_id'] ?? '') . '|' . (string)($row['source_line_id'] ?? '');
    }

    private function purchaseBusinessEventKey(array $row): string
    {
        $eventNo = trim((string)($row['business_event_no'] ?? ''));
        if ($eventNo !== '') return 'event:' . $eventNo;
        return 'order:' . (string)($row['order_id'] ?? '');
    }

    private function purchaseEventSortKey(array $row): string
    {
        return (string)($row['business_date'] ?? '') . '|'
            . str_pad((string)max(0, (int)($row['occurred_at'] ?? 0)), 20, '0', STR_PAD_LEFT) . '|'
            . $this->purchaseBusinessEventKey($row);
    }

    private function newMemberEligible(array $origin): bool
    {
        return !empty($origin['is_new_customer_eligible']);
    }

    private function ensureItemRow(array &$records, string $key, array $source, string $date): void
    {
        if (isset($records[$key])) return;
        $storeId = (int)$source['store_id'];
        $records[$key] = [
            'company_name' => $this->dimensionNameForEvent(
                'company', (string)($source['organization_id'] ?? ''),
                (string)($source['organization_path_snapshot'] ?? ''), $storeId, $date
            ),
            'store_id' => $storeId, 'store_name' => (string)$source['store_name'],
            'item_id' => (string)$source['item_id'], 'item_name' => (string)$source['item_name'],
            '_experience_members' => [], '_purchase_members' => [],
            'purchase_count' => 0, 'purchase_amount_cents' => 0,
        ];
    }

    private function emptyPerformanceDealRow(int $storeId, string $storeName, string $date, array $source = []): array
    {
        return [
            'company_name' => $this->dimensionNameForEvent(
                'company', (string)($source['organization_id'] ?? ''),
                (string)($source['organization_path_snapshot'] ?? ''), $storeId, $date
            ),
            'store_id' => $storeId, 'store_name' => $storeName,
            'old_visit_people' => 0, 'new_visit_purchase' => 0, 'new_visit_gift' => 0,
            'old_deal_people' => 0, 'new_deal_purchase' => 0, 'new_deal_gift' => 0,
            'old_performance_cents' => 0, 'new_performance_purchase_cents' => 0,
            'new_performance_gift_cents' => 0,
        ];
    }

    private function itemKey(array $row): string
    {
        $date = (string)($row['business_date'] ?? self::COVERAGE_START);
        $company = $this->dimensionNameForEvent(
            'company',
            (string)($row['organization_id'] ?? ''),
            (string)($row['organization_path_snapshot'] ?? ''),
            (int)$row['store_id'],
            $date
        );
        return implode('|', [
            $company,
            (string)(int)$row['store_id'],
            (string)($row['store_name'] ?? ''),
            (string)($row['item_id'] ?? ''),
            (string)($row['item_name'] ?? ''),
        ]);
    }

    private function performanceGroupKey(array $row, string $date): string
    {
        $company = $this->dimensionNameForEvent(
            'company',
            (string)($row['organization_id'] ?? ''),
            (string)($row['organization_path_snapshot'] ?? ''),
            (int)($row['store_id'] ?? 0),
            $date
        );
        return implode('|', [
            $company,
            (string)(int)($row['store_id'] ?? 0),
            (string)($row['store_name'] ?? ''),
        ]);
    }

    /** First-purchase classification spans every store with persisted V3 sale facts. */
    private function historicalStoreIds(): array
    {
        return $this->storeIds(Db::name('cashier_v3_sale_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('status', 'effective')->column('store_id'));
    }

    private function categoryScope(array $input): array
    {
        $root = (int)($input['category_id'] ?? 0);
        $requestedPath = trim((string)($input['category_path'] ?? ''));
        if ($root <= 0 && $requestedPath === '') return [];
        $categories = Db::name('store_product_category')->where('is_show', 1)
            ->field('id,pid,cate_name')->select()->toArray();
        $children = [];
        $byId = [];
        foreach ($categories as $row) {
            $byId[(int)$row['id']] = $row;
            $children[(int)$row['pid']][] = (int)$row['id'];
        }
        $roots = $root > 0 ? [$root] : [];
        if ($requestedPath !== '') {
            foreach ($byId as $id => $row) {
                $parts = [];
                $cursor = $id;
                $seen = [];
                while ($cursor > 0 && isset($byId[$cursor]) && !isset($seen[$cursor])) {
                    $seen[$cursor] = true;
                    array_unshift($parts, trim((string)$byId[$cursor]['cate_name']));
                    $cursor = (int)$byId[$cursor]['pid'];
                }
                if (implode(' / ', array_filter($parts, static fn(string $part): bool => $part !== '')) === $requestedPath) {
                    $roots[] = $id;
                }
            }
        }
        $result = [];
        $queue = array_values(array_unique(array_map('intval', $roots)));
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id <= 0 || isset($result[$id])) continue;
            $result[$id] = true;
            foreach ((array)($children[$id] ?? []) as $child) $queue[] = $child;
        }
        return array_keys($result);
    }

    private function sixDimensionCategoryIds(): array
    {
        $roots = Db::name('store_product_category')->where('is_show', 1)->where('pid', 0)
            ->where('cate_name', '六维')->order('id', 'asc')->column('id');
        if ($roots === []) throw new \RuntimeException('六维根商品分类尚未配置，无法生成六维业绩报表');
        if (count($roots) !== 1) throw new \RuntimeException('存在多个启用的六维根商品分类，请先修正分类配置');
        return $this->categoryScope(['category_id' => (int)$roots[0]]);
    }

    private function cashByDimension(string $type, array $stores, array $range, array $categoryIds): array
    {
        $totals = [];
        foreach ($this->cashItemRows($stores, $range, $categoryIds) as $row) {
            $dimension = $this->dimensionForEvent(
                $type, (string)($row['organization_id'] ?? ''),
                (string)($row['organization_path_snapshot'] ?? ''),
                (int)$row['store_id'], (string)$row['business_date']
            );
            $dimensionId = (string)($dimension['organization_id'] ?? '');
            if ($dimensionId === '') continue;
            $totals[$dimensionId] = (int)($totals[$dimensionId] ?? 0) + (int)$row['amount_cents'];
        }
        return $totals;
    }

    private function matchTier(array $tiers, int $amountCents): ?array
    {
        foreach ($tiers as $tier) {
            $minimum = (int)($tier['minimum_cents'] ?? $tier['lower_bound_cents'] ?? 0);
            $maximumValue = $tier['maximum_cents'] ?? $tier['upper_bound_cents'] ?? null;
            $maximum = $maximumValue !== null ? (int)$maximumValue : null;
            $includeMaximum = !empty($tier['include_maximum']);
            if ($amountCents < $minimum) continue;
            if ($maximum !== null && ($includeMaximum ? $amountCents > $maximum : $amountCents >= $maximum)) continue;
            return [
                'tier_id' => (string)($tier['tier_id'] ?? $tier['id'] ?? ''),
                'name' => (string)($tier['name'] ?? $tier['tier_name'] ?? ''),
            ];
        }
        return null;
    }

    private function memberStoreAssignments(array $memberIds, string $cutoffDate, array $visibleStores): array
    {
        $result = $this->foundation()->memberStoreAssignmentsAt(
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            $memberIds,
            $cutoffDate
        );
        foreach ($result as $memberId => &$assignment) {
            if ((int)($assignment['store_id'] ?? 0) > 0
                && !in_array((int)$assignment['store_id'], $visibleStores, true)) {
                $assignment = ['store_id' => 0, 'store_name' => '未配置归属门店', 'configured' => false];
            }
        }
        unset($assignment);
        return $result;
    }

    private function memberOrigins(array $memberIds): array
    {
        return $this->foundation()->memberOrigins(CashierV3ScopeResolver::TENANT_SCOPE_ID, $memberIds);
    }

    /** Expand configured organization dimensions to the authorized descendant stores. */
    private function organizationDimensions(string $type, array $stores, string $date): array
    {
        $configs = $this->foundation()->organizationDimensions(
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            $type,
            $date
        );
        if ($configs === []) return [];
        $configured = [];
        foreach ($configs as $config) $configured[(string)$config['organization_id']] = $config;
        $rows = [];
        foreach ($stores as $storeId) {
            $path = $this->storeOrganizationPath((int)$storeId);
            $match = null;
            foreach (array_reverse($path) as $organizationId) {
                if (isset($configured[(string)$organizationId])) {
                    $match = $configured[(string)$organizationId];
                    break;
                }
            }
            if (!$match) continue;
            $rows[] = [
                'dimension_id' => (string)$match['organization_id'],
                'dimension_name' => (string)$match['organization_name_snapshot'],
                'sort_order' => (int)$match['display_order'],
                'store_id' => (int)$storeId,
            ];
        }
        return $rows;
    }

    /** @return array<int,string> root-to-leaf ids */
    private function storeOrganizationPath(int $storeId): array
    {
        static $cache = [];
        if (isset($cache[$storeId])) return $cache[$storeId];
        $organizationId = (int)(Db::name('organization_store')->where('store_id', $storeId)->value('org_id') ?? 0);
        $path = [];
        $seen = [];
        for ($guard = 0; $organizationId > 0 && $guard < 64; $guard++) {
            if (isset($seen[$organizationId])) throw new \RuntimeException('组织路径存在循环，无法生成报表');
            $seen[$organizationId] = true;
            $node = Db::name('organization')->where('id', $organizationId)->where('is_del', 0)->field('id,pid')->find();
            if (!$node) break;
            array_unshift($path, (string)$node['id']);
            $organizationId = (int)$node['pid'];
        }
        return $cache[$storeId] = $path;
    }

    private function dimensionName(string $type, int $storeId, string $date): string
    {
        static $cache = [];
        $key = $type . '|' . $storeId . '|' . $date;
        if (!array_key_exists($key, $cache)) {
            $rows = $this->organizationDimensions($type, [$storeId], $date);
            $cache[$key] = $rows === [] ? '' : (string)($rows[0]['dimension_name'] ?? '');
        }
        return $cache[$key] !== '' ? $cache[$key] : ($type === 'company' ? '未配置分公司' : '未配置城市经理');
    }

    private function dimensionNameForEvent(
        string $type,
        string $organizationId,
        string $organizationPathSnapshot,
        int $storeId,
        string $date
    ): string {
        $dimension = $this->dimensionForEvent(
            $type, $organizationId, $organizationPathSnapshot, $storeId, $date
        );
        if ($dimension) return (string)$dimension['organization_name_snapshot'];
        return $type === 'company' ? '未配置分公司' : '未配置城市经理';
    }

    private function dimensionForEvent(
        string $type,
        string $organizationId,
        string $organizationPathSnapshot,
        int $storeId,
        string $date
    ): ?array {
        $path = array_values(array_filter(explode('/', trim($organizationPathSnapshot, '/')), static function ($id): bool {
            return preg_match('/^\d+$/D', (string)$id) === 1;
        }));
        if ($path === [] && preg_match('/^\d+$/D', $organizationId) === 1) {
            $path = $this->organizationPathFromNode((int)$organizationId);
        }
        if ($path !== []) {
            $dimension = $this->foundation()->resolveOrganizationDimension(
                CashierV3ScopeResolver::TENANT_SCOPE_ID, $path, $type, $date
            );
            return $dimension ?: null;
        }
        $rows = $this->organizationDimensions($type, [$storeId], $date);
        if ($rows === []) return null;
        return [
            'organization_id' => (string)$rows[0]['dimension_id'],
            'organization_name_snapshot' => (string)$rows[0]['dimension_name'],
            'display_order' => (int)($rows[0]['sort_order'] ?? 0),
        ];
    }

    /** Include every configured dimension version active in at least one queried month. */
    private function configuredDimensionsAcrossRange(string $type, array $range, array $stores): array
    {
        $dates = [$range['start'], $range['end']];
        foreach ($this->months($range) as $month) {
            $dates[] = max($range['start'], $month . '-01');
            $dates[] = min($range['end'], date('Y-m-t', strtotime($month . '-01')));
        }
        sort($dates, SORT_STRING);
        $definitions = [];
        foreach (array_values(array_unique($dates)) as $date) {
            foreach ($this->organizationDimensions($type, $stores, $date) as $dimension) {
                $id = (string)$dimension['dimension_id'];
                $definitions[$id] = [
                    'id' => $id,
                    'name' => (string)$dimension['dimension_name'],
                    'sort_order' => (int)$dimension['sort_order'],
                ];
            }
        }
        return $definitions;
    }

    /** @return array<int,string> root-to-leaf organization ids */
    private function organizationPathFromNode(int $organizationId): array
    {
        $path = [];
        $seen = [];
        for ($guard = 0; $organizationId > 0 && $guard < 64; $guard++) {
            if (isset($seen[$organizationId])) throw new \RuntimeException('组织路径存在循环，无法生成报表');
            $seen[$organizationId] = true;
            $node = Db::name('organization')->where('id', $organizationId)->where('is_del', 0)->field('id,pid')->find();
            if (!$node) break;
            array_unshift($path, (string)$node['id']);
            $organizationId = (int)$node['pid'];
        }
        return $path;
    }

    private function foundation(): StoreUnifiedReportPhaseThreeFoundationServices
    {
        static $service;
        if (!$service) $service = new StoreUnifiedReportPhaseThreeFoundationServices();
        return $service;
    }

    private function storeName(int $storeId): string
    {
        static $cache = [];
        if (!array_key_exists($storeId, $cache)) {
            $cache[$storeId] = (string)(Db::name('system_store')->where('id', $storeId)->value('name') ?? '');
        }
        return $cache[$storeId] !== '' ? $cache[$storeId] : ('门店' . $storeId);
    }

    private function denseRanks(array &$rows, string $amountKey, string $targetKey, string $rankKey): void
    {
        $rates = [];
        foreach ($rows as $key => $row) {
            $target = (int)$row[$targetKey];
            $rates[$key] = $target > 0 ? (int)round((int)$row[$amountKey] * 1000000 / $target) : null;
        }
        $unique = array_values(array_unique(array_filter($rates, static fn($value): bool => $value !== null)));
        rsort($unique, SORT_NUMERIC);
        $ranks = [];
        foreach ($unique as $index => $rate) $ranks[(string)$rate] = $index + 1;
        foreach ($rows as $key => &$row) $row[$rankKey] = $rates[$key] === null ? '-' : (int)$ranks[(string)$rates[$key]];
        unset($row);
    }

    private function naturalMonth(array $range): array
    {
        $start = date('Y-m-01', strtotime($range['start']));
        $end = date('Y-m-t', strtotime($start));
        if ($range['start'] !== $start || $range['end'] !== $end) {
            throw new \InvalidArgumentException('业绩分布表必须选择一个完整自然月');
        }
        return [$start, $end];
    }

    private function drilldownRange(array $range, array $input): array
    {
        $month = trim((string)($input['drill_month'] ?? ''));
        if ($month === '') return $range;
        if (preg_match('/^\d{4}-\d{2}$/D', $month) !== 1) {
            throw new \InvalidArgumentException('下钻月份不正确');
        }
        $start = $month . '-01';
        $timestamp = strtotime($start);
        if ($timestamp === false || date('Y-m', $timestamp) !== $month) {
            throw new \InvalidArgumentException('下钻月份不正确');
        }
        $monthRange = ['start' => $start, 'end' => date('Y-m-t', $timestamp)];
        $narrowed = [
            'start' => max($range['start'], $monthRange['start']),
            'end' => min($range['end'], $monthRange['end']),
        ];
        if ($narrowed['start'] > $narrowed['end']) {
            throw new \InvalidArgumentException('下钻月份超出当前查询范围');
        }
        return $this->validRange($narrowed);
    }

    /** Narrow an already-authorized store scope by a persisted statistic dimension. */
    private function drilldownStores(array $stores, array $range, array $input): array
    {
        $dimensionType = '';
        $dimensionId = '';
        if (trim((string)($input['company_dimension_id'] ?? '')) !== '') {
            $dimensionType = 'company';
            $dimensionId = trim((string)$input['company_dimension_id']);
        } elseif (trim((string)($input['city_manager_dimension_id'] ?? '')) !== '') {
            $dimensionType = 'city_manager';
            $dimensionId = trim((string)$input['city_manager_dimension_id']);
        }
        if ($dimensionType === '') return $stores;
        $asOf = trim((string)($input['dimension_as_of'] ?? '')) ?: $range['end'];
        $asOf = $this->validDimensionDate($asOf);
        $visible = [];
        foreach ($this->organizationDimensions($dimensionType, $stores, $asOf) as $row) {
            if ((string)$row['dimension_id'] === $dimensionId) $visible[] = (int)$row['store_id'];
        }
        return array_values(array_unique($visible));
    }

    private function validDimensionDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('组织统计维度日期不正确');
        }
        return $date;
    }

    private function months(array $range): array
    {
        $current = date('Y-m-01', strtotime($range['start']));
        $last = date('Y-m-01', strtotime($range['end']));
        $months = [];
        while ($current <= $last) {
            $months[] = substr($current, 0, 7);
            $current = date('Y-m-01', strtotime($current . ' +1 month'));
        }
        return $months;
    }

    private function allocateSigned(int $amountCents, array $items): array
    {
        $sign = $amountCents < 0 ? -1 : 1;
        $sales = [];
        foreach ($items as $item) {
            $weight = abs((int)($item['sale_amount_cents'] ?? 0));
            if ($weight === 0) $weight = abs((int)($item['configured_amount_cents'] ?? 0));
            if ($weight === 0) $weight = max(1, (int)($item['component_count'] ?? 1));
            $sales[] = [
                'fact_id' => (string)$item['allocation_fact_id'],
                'sale_amount_cents' => $sign * $weight, 'debt_amount_cents' => 0,
            ];
        }
        return StoreReportPartnerCategorySnapshotServices::allocateAmountBySaleFact($amountCents, $sales);
    }

    private function annotations(string $report, array $stores, array $subjectKeys): array
    {
        if ($subjectKeys === []) return [];
        $rows = Db::name('cashier_v3_report_annotation')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('report_code', $report)
            ->whereIn('store_id', $stores)->whereIn('subject_key', array_values(array_unique($subjectKeys)))
            ->field('subject_key,field_key,field_value,version')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['subject_key']][(string)$row['field_key']] = [
                'value' => (string)$row['field_value'], 'version' => (int)$row['version'],
            ];
        }
        return $result;
    }

    private function craftsmanNames(string $json): string
    {
        $names = [];
        foreach ((array)json_decode($json, true) as $row) {
            $name = trim((string)($row['employeeName'] ?? $row['employee_name'] ?? $row['name'] ?? ''));
            if ($name !== '') $names[$name] = true;
        }
        return $names === [] ? '-' : implode('、', array_keys($names));
    }

    private function validRange(array $range): array
    {
        $start = trim((string)($range['start'] ?? ''));
        $end = trim((string)($range['end'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1
            || $start > $end) {
            throw new \InvalidArgumentException('日期范围不正确');
        }
        return ['start' => $start, 'end' => $end];
    }

    /** Deployment-day writes can be partial, so completeness starts on the following day. */
    private function coverageStart(): string
    {
        static $coverageStart;
        if ($coverageStart !== null) return $coverageStart;
        $coverageStart = self::COVERAGE_START;
        try {
            $executedAt = trim((string)(Db::name('database_upgrade_log')
                ->where('upgrade_key', self::UPGRADE_KEY)->value('executed_at') ?? ''));
            if ($executedAt !== '') {
                $timestamp = strtotime($executedAt . ' +1 day');
                if ($timestamp !== false) {
                    $deploymentCoverage = date('Y-m-d', $timestamp);
                    if ($deploymentCoverage > $coverageStart) $coverageStart = $deploymentCoverage;
                }
            }
        } catch (\Throwable $exception) {
            // Readiness and migration checks remain authoritative when the log table is unavailable.
        }
        return $coverageStart;
    }

    private function storeIds($storeIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', is_array($storeIds) ? $storeIds : [$storeIds]))));
    }

    private function sameStoreScope(array $left, array $right): bool
    {
        sort($left, SORT_NUMERIC);
        sort($right, SORT_NUMERIC);
        return $left === $right;
    }

    private function ratio(int $numerator, int $denominator): string
    {
        if ($denominator === 0) return '-';
        return $this->trimDecimal(round($numerator * 10000 / $denominator) / 100) . '%';
    }

    private function moneyRatio(int $cents, int $denominator): string
    {
        if ($denominator === 0) return '-';
        $negative = $cents < 0;
        $absolute = abs($cents);
        $quotient = intdiv($absolute, $denominator);
        $remainder = $absolute % $denominator;
        $roundedCents = $quotient + (int)round($remainder / $denominator);
        return ($negative ? '-' : '') . $this->money($roundedCents);
    }

    private function money(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $value = intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
        return ($negative ? '-' : '') . rtrim(rtrim($value, '0'), '.');
    }

    private function decimalCents($money): int
    {
        $value = trim((string)$money);
        if ($value === '' || $value === '-') return 0;
        $negative = substr($value, 0, 1) === '-';
        $parts = explode('.', ltrim($value, '+-'), 2);
        $cents = ((int)($parts[0] ?? 0)) * 100 + (int)str_pad(substr((string)($parts[1] ?? ''), 0, 2), 2, '0');
        return $negative ? -abs($cents) : $cents;
    }

    private function trimDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function moneyColumn(string $key): bool
    {
        return preg_match('/(amount|performance|target|completed|unit_output|sale_price|group_total)$/', $key) === 1;
    }
}
