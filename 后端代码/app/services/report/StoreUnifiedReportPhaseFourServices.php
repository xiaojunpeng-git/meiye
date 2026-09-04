<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\BaseServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 第四阶段运营中心与产品变现报表。
 *
 * 金额统一从 payment_sale_allocation_fact 的成功、可追溯分摊事实读取；
 * 卡项在分组前按冻结的内含项目分类再次展开。调用方只能传入认证后裁剪
 * 的门店范围，本服务不会读取客户端的租户或扩大组织范围。
 */
final class StoreUnifiedReportPhaseFourServices extends BaseServices
{
    public const METRIC_VERSION = 'report-phase-four-v1';

    private const SIX_DIMENSION_ANALYSIS = 'six_dimension_analysis';

    /** @var array<string,array<string,mixed>> */
    private const REPORTS = [
        'operations_pre_sale_bdegh' => ['name' => '售前报表（BDEGH）', 'family' => '运营中心数据', 'kind' => 'pre_sale'],
        'operations_customer_status_bdegh' => ['name' => '顾客状态表（BDEGH）', 'family' => '运营中心数据', 'kind' => 'customer_status'],
        'operations_referral_beautician_pre_sale' => ['name' => '售前报表（老带新+美容师卖卡）', 'family' => '运营中心数据', 'kind' => 'pre_sale'],
        'operations_performance_comparison' => ['name' => '业绩对比报表', 'family' => '运营中心数据', 'kind' => 'comparison'],
        'operations_health_data' => ['name' => '运营健康数据报表', 'family' => '运营中心数据', 'kind' => 'health'],
        'operations_beauty_item' => ['name' => '运营生美品项表', 'family' => '运营中心数据', 'kind' => 'item', 'category' => '生美'],
        'operations_annual_member_consumption' => ['name' => '年会员消费统计（福建）', 'family' => '运营中心数据', 'kind' => 'member_consumption'],
        self::SIX_DIMENSION_ANALYSIS => ['name' => '六维数据分析表', 'family' => '财务中心/前台', 'kind' => 'item', 'category' => '六维', 'both_ends' => true],

        'marketing_acquisition_pre_sale' => ['name' => '品牌营销-售前报表（营销拓客）', 'family' => '运营中心数据', 'kind' => 'pre_sale'],
        'marketing_referral_pre_sale' => ['name' => '品牌营销-售前报表（老带新）', 'family' => '运营中心数据', 'kind' => 'pre_sale'],
        'marketing_post_sale_performance' => ['name' => '品牌营销-售后业绩报表', 'family' => '运营中心数据', 'kind' => 'post_sale'],
        'marketing_health_data' => ['name' => '品牌营销-健康数据报表', 'family' => '运营中心数据', 'kind' => 'health'],
        'marketing_beauty_new_item' => ['name' => '品牌营销-生美新品/主推品项报表', 'family' => '运营中心数据', 'kind' => 'item', 'category' => '生美'],
        'marketing_customer_status' => ['name' => '品牌营销-顾客状态表', 'family' => '运营中心数据', 'kind' => 'customer_status'],

        'product_monetization_performance_total' => ['name' => '产品变现-业绩总表', 'family' => '运营中心数据', 'kind' => 'performance_total'],
        'product_monetization_beauty_performance_total' => ['name' => '产品变现-生美业绩总表', 'family' => '运营中心数据', 'kind' => 'category_total', 'category' => '生美'],
        'product_monetization_beauty_item' => ['name' => '产品变现-生美品项表', 'family' => '运营中心数据', 'kind' => 'item', 'category' => '生美'],
        'product_monetization_beauty_market_distribution' => ['name' => '产品变现-生美业绩市场分布表', 'family' => '运营中心数据', 'kind' => 'market', 'category' => '生美'],
        'product_monetization_six_dimension_performance' => ['name' => '产品变现-六维业绩', 'family' => '运营中心数据', 'kind' => 'category_total', 'category' => '六维'],
        'product_monetization_six_dimension_item_performance' => ['name' => '产品变现-六维品项业绩', 'family' => '运营中心数据', 'kind' => 'item', 'category' => '六维'],
        'product_monetization_six_dimension_efficiency' => ['name' => '产品变现-六维业绩成交效率', 'family' => '运营中心数据', 'kind' => 'efficiency', 'category' => '六维'],
        'product_monetization_six_dimension_market_distribution' => ['name' => '产品变现-六维业绩市场分布', 'family' => '运营中心数据', 'kind' => 'market', 'category' => '六维'],
        'product_monetization_haomei_performance' => ['name' => '产品变现-昊美业绩', 'family' => '运营中心数据', 'kind' => 'category_total', 'category' => '昊美'],
        'product_monetization_private_performance' => ['name' => '产品变现-私密业绩', 'family' => '运营中心数据', 'kind' => 'category_total', 'category' => '花园'],
        'product_monetization_private_item_performance' => ['name' => '产品变现-私密品项业绩', 'family' => '运营中心数据', 'kind' => 'item', 'category' => '花园'],
        'product_monetization_private_efficiency' => ['name' => '产品变现-私密业绩成交效率', 'family' => '运营中心数据', 'kind' => 'efficiency', 'category' => '花园'],
        'product_monetization_private_market_distribution' => ['name' => '产品变现-私密业绩市场分布', 'family' => '运营中心数据', 'kind' => 'market', 'category' => '花园'],
    ];

    /** @var int */
    private $participantEmployeeId = 0;

    public static function reportCodes(): array { return array_keys(self::REPORTS); }

    public static function platformOnlyReportCodes(): array
    {
        return array_values(array_filter(self::reportCodes(), static function (string $code): bool {
            return $code !== self::SIX_DIMENSION_ANALYSIS;
        }));
    }

    public static function catalogEntries(bool $platform = true): array
    {
        $entries = [];
        foreach (self::REPORTS as $code => $definition) {
            if (!$platform && empty($definition['both_ends'])) continue;
            $entries[] = [
                'folder' => (string)$definition['family'], 'code' => $code,
                'name' => (string)$definition['name'], 'platform_only' => empty($definition['both_ends']),
            ];
        }
        return $entries;
    }

    public function supports(string $report): bool { return isset(self::REPORTS[$report]); }

    public function query(string $report, $storeIds, array $range, array $input): array
    {
        if (!$this->supports($report)) throw new \InvalidArgumentException('不支持的第四阶段报表类型');
        $scope = is_array($input['_report_scope'] ?? null) ? $input['_report_scope'] : [];
        if ((string)($scope['mode'] ?? '') === 'none') throw new \InvalidArgumentException('当前账号没有可查看的数据范围');
        $this->participantEmployeeId = (string)($scope['mode'] ?? '') === 'self_participant' ? max(0, (int)($scope['employee_id'] ?? 0)) : 0;
        if ((string)($scope['mode'] ?? '') === 'self_participant' && $this->participantEmployeeId <= 0) throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
        $stores = $this->storeIds($storeIds);
        if ($stores === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        $range = $this->range($range, $input);
        $definition = self::REPORTS[$report];
        if ($report === self::SIX_DIMENSION_ANALYSIS) {
            return $this->sixDimensionAnalysisReport($stores, $range, $input, $definition);
        }
        switch ((string)$definition['kind']) {
            case 'item': return $this->itemReport($report, $definition, $stores, $range, $input);
            case 'market': return $this->marketReport($report, $definition, $stores, $range, $input);
            case 'efficiency': return $this->efficiencyReport($report, $definition, $stores, $range, $input);
            case 'category_total': return $this->categoryTotalReport($report, $definition, $stores, $range, $input);
            case 'performance_total': return $this->performanceTotalReport($report, $definition, $stores, $range, $input);
            case 'customer_status': return $this->customerStatusReport($report, $definition, $stores, $range, $input);
            case 'member_consumption': return $this->memberConsumptionReport($report, $definition, $stores, $range, $input);
            case 'post_sale': return $this->postSaleReport($report, $definition, $stores, $range, $input);
            case 'comparison': return $this->comparisonReport($report, $definition, $stores, $range, $input);
            case 'health': return $this->healthReport($report, $definition, $stores, $range, $input);
            default: return $this->preSaleReport($report, $definition, $stores, $range, $input);
        }
    }

    /**
     * 六维数据分析表：严格对应已确认的 20 个业务列。金额来自成功收款
     * 分摊事实；卡项已在 cashRows() 按冻结的内含项目分类展开。
     */
    private function sixDimensionAnalysisReport(array $stores, array $range, array $input, array $definition): array
    {
        $categoryIds = $this->categoryIds('六维', $input);
        $periodCash = $this->cashRows($stores, $range, $categoryIds);
        $historyCash = $this->cashRows($stores, ['end' => $range['end']], $categoryIds);
        $services = $this->serviceRows($stores, $range, $categoryIds);
        // The workbook defines new/old by the member's six-dimension purchase
        // history, not by the member-import/source flag used by other reports.
        $firstPositive = [];
        foreach ($historyCash as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId <= 0 || (int)($fact['amount_cents'] ?? 0) <= 0) continue;
            $date = (string)($fact['business_date'] ?? '');
            if ($date === '') continue;
            if (!isset($firstPositive[$memberId]) || $date < $firstPositive[$memberId]) $firstPositive[$memberId] = $date;
        }
        $rows = [];
        $ensure = function (array $fact) use (&$rows): string {
            $projected = $fact;
            $this->organization()->project($projected, (string)($fact['organization_id'] ?? ''), (string)($fact['organization_path_snapshot'] ?? ''), (string)($fact['business_date'] ?? ''));
            $company = (string)($projected['company'] ?? '未配置分公司');
            $storeId = (int)($fact['store_id'] ?? 0);
            $store = (string)($fact['store_name'] ?? '');
            $key = $company . '|' . $storeId;
            if (!isset($rows[$key])) $rows[$key] = [
                'company_name' => $company, 'store_name' => $store === '' ? ('门店' . $storeId) : $store,
                'store_id' => $storeId, 'new_visit_people' => [], 'old_visit_people' => [],
                'new_deal_people' => [], 'old_deal_people' => [], 'new_deal_cents' => 0, 'old_deal_cents' => 0,
                'debt_cents' => 0, 'self_received_cents' => 0, 'partner_received_cents' => 0,
            ];
            return $key;
        };
        foreach ($services as $service) {
            if ((int)($service['is_experience'] ?? 0) !== 1) continue;
            $key = $ensure($service); $memberId = (int)($service['member_id'] ?? 0);
            if ($memberId <= 0) continue;
            $firstPurchaseDate = (string)($firstPositive[$memberId] ?? '');
            if ($firstPurchaseDate === '' || $firstPurchaseDate > (string)$service['business_date']) $rows[$key]['new_visit_people'][(string)$memberId] = true;
            else $rows[$key]['old_visit_people'][(string)$memberId] = true;
        }
        $dealCandidates = [];
        foreach ($periodCash as $fact) {
            $key = $ensure($fact); $memberId = (int)($fact['member_id'] ?? 0); $amount = (int)($fact['amount_cents'] ?? 0);
            if ($memberId > 0) $dealCandidates[$key][$memberId] = (int)($dealCandidates[$key][$memberId] ?? 0) + $amount;
            $path = (string)($fact['category_path'] ?? '');
            if (str_contains($path, '合作')) $rows[$key]['partner_received_cents'] += $amount;
            else $rows[$key]['self_received_cents'] += $amount;
        }
        foreach ($dealCandidates as $key => $members) {
            foreach ($members as $memberId => $amount) {
                // "购买现金业绩1000元以上" is evaluated per member in the selected period.
                if ($amount < 100000) continue;
                $firstPurchaseDate = (string)($firstPositive[(int)$memberId] ?? '');
                $isNewDeal = $firstPurchaseDate !== '' && $firstPurchaseDate >= $range['start'] && $firstPurchaseDate <= $range['end'];
                if ($isNewDeal) {
                    $rows[$key]['new_deal_people'][(string)$memberId] = true;
                    $rows[$key]['new_deal_cents'] += $amount;
                } else {
                    $rows[$key]['old_deal_people'][(string)$memberId] = true;
                    $rows[$key]['old_deal_cents'] += $amount;
                }
            }
        }
        foreach ($this->outstandingDebtRows($stores, $categoryIds) as $debt) {
            $key = $ensure($debt);
            $rows[$key]['debt_cents'] += (int)($debt['amount_cents'] ?? 0);
        }
        foreach ($rows as $key => &$row) {
            $row['new_visit_people'] = count($row['new_visit_people']); $row['old_visit_people'] = count($row['old_visit_people']);
            $row['total_visit_people'] = $row['new_visit_people'] + $row['old_visit_people'];
            $row['new_deal_people'] = count($row['new_deal_people']); $row['old_deal_people'] = count($row['old_deal_people']);
            $row['total_deal_people'] = $row['new_deal_people'] + $row['old_deal_people'];
            $row['new_deal_performance'] = $this->money((int)$row['new_deal_cents']); $row['old_deal_performance'] = $this->money((int)$row['old_deal_cents']);
            $total = (int)$row['new_deal_cents'] + (int)$row['old_deal_cents'];
            $row['total_deal_performance'] = $this->money($total);
            $row['new_deal_rate'] = $this->ratio((int)$row['new_deal_people'], (int)$row['new_visit_people']);
            $row['old_deal_rate'] = $this->ratio((int)$row['old_deal_people'], (int)$row['old_visit_people']);
            $row['debt'] = $this->money((int)$row['debt_cents']);
            $row['self_received_performance'] = $this->money((int)$row['self_received_cents']);
            // The workbook formula is Q=P: this is the displayed self-run
            // actual received performance for the selected period.
            $row['self_to_date_performance'] = $this->money((int)$row['self_received_cents']);
            $row['new_unit_output'] = $this->moneyRatio((int)$row['new_deal_cents'], (int)$row['new_deal_people']);
            $row['old_unit_output'] = $this->moneyRatio((int)$row['old_deal_cents'], (int)$row['old_deal_people']);
            $row['partner_received_performance'] = $this->money((int)$row['partner_received_cents']);
            // The workbook formula is U=Q+T, independent from the qualified
            // 1,000-yuan conversion threshold used in the deal columns.
            $row['all_received_performance'] = $this->money((int)$row['self_received_cents'] + (int)$row['partner_received_cents']);
            unset($row['new_deal_cents'], $row['old_deal_cents'], $row['debt_cents'], $row['self_received_cents'], $row['partner_received_cents']);
        }
        unset($row);
        $columns = [
            $this->pathColumn('company_name', '分公司', '取业务门店所属组织向上匹配配置为分公司的统计维度名称；未配置显示“未配置分公司”。', ['分公司'], false, true, 130),
            $this->pathColumn('store_name', '门店', '取业务发生时保存的门店名称快照。', ['门店'], false, true, 140),
            $this->pathColumn('new_visit_people', '新客人数', '期间内完成六维及下级分类服务且带体验标记、此前未购买过六维的不同会员数。', ['见诊人数', '新客人数'], true, false, 110, [], '见诊人数', 'integer'),
            $this->pathColumn('old_visit_people', '老客人数', '期间内完成六维及下级分类服务且带体验标记、此前已购买过六维的不同会员数。', ['见诊人数', '老客人数'], true, false, 110, [], '见诊人数', 'integer'),
            $this->pathColumn('total_visit_people', '合计见诊人数', '新客见诊人数与老客见诊人数之和。', ['合计见诊人数'], true, false, 120, [], '', 'integer'),
            $this->pathColumn('new_deal_people', '新客人数', '期间内首次购买六维且该会员本期六维现金业绩达到1,000元的不同会员数。', ['成交人数', '新客人数'], true, false, 110, [], '成交人数', 'integer'),
            $this->pathColumn('old_deal_people', '老客人数', '此前购买过六维、且本期六维现金业绩达到1,000元的不同会员数。', ['成交人数', '老客人数'], true, false, 110, [], '成交人数', 'integer'),
            $this->pathColumn('total_deal_people', '合计成交人数', '新客成交人数与老客成交人数之和。', ['合计成交人数'], true, false, 120, [], '', 'integer'),
            $this->pathColumn('new_deal_performance', '新客业绩', '新客首次六维购买的成功收款分摊金额；欠款补交和退款分别按成功日期正负计入。', ['成交业绩', '新客业绩'], true, false, 120, $this->drill('六维'), '成交业绩'),
            $this->pathColumn('old_deal_performance', '老客业绩', '老客六维购买的成功收款分摊金额；退款按退款成功日期负向计入。', ['成交业绩', '老客业绩'], true, false, 120, $this->drill('六维'), '成交业绩'),
            $this->pathColumn('total_deal_performance', '合计成交业绩', '新客业绩与老客业绩之和。', ['合计成交业绩'], true, false, 130, $this->drill('六维')),
            $this->pathColumn('new_deal_rate', '新客成交率', '新客成交人数除以新客见诊人数；分母为零显示“-”。', ['成交率', '新客成交率'], false, false, 120, [], '成交率'),
            $this->pathColumn('old_deal_rate', '老客成交率', '老客成交人数除以老客见诊人数；分母为零显示“-”。', ['成交率', '老客成交率'], false, false, 120, [], '成交率'),
            $this->pathColumn('debt', '欠款', '已确认但尚未成功收款的六维销售欠款；没有可追溯欠款事实时为0。', ['欠款'], true),
            $this->pathColumn('self_received_performance', '自营实收业绩', '期间内六维自营分类及下级的成功收款分摊金额。', ['自营实收业绩'], true, false, 130, $this->drill('六维 / 自营')),
            $this->pathColumn('self_to_date_performance', '自营截止今日合计业绩', '完全按原表公式，等于“自营实收业绩”。', ['自营截止今日合计业绩'], true, false, 150, $this->drill('六维 / 自营')),
            $this->pathColumn('new_unit_output', '新客单产', '新客业绩除以新客成交人数；分母为零显示“-”。', ['成交单产', '新客单产'], false, false, 110, [], '成交单产'),
            $this->pathColumn('old_unit_output', '老客单产', '老客业绩除以老客成交人数；分母为零显示“-”。', ['成交单产', '老客单产'], false, false, 110, [], '成交单产'),
            $this->pathColumn('partner_received_performance', '合作六维实收业绩', '期间内六维合作分类及下级的成功收款分摊金额。', ['合作六维实收业绩'], true, false, 140, $this->drill('六维 / 合作')),
            $this->pathColumn('all_received_performance', '六维全部实收业绩', '期间内六维及全部下级分类的成功收款净额，等于自营与合作实收业绩之和。', ['六维全部实收业绩'], true, false, 140, $this->drill('六维')),
        ];
        return $this->result((string)$definition['name'], $columns, array_values($rows), $range, $input, ['category' => true]);
    }

    private function itemReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        if ($report === 'operations_beauty_item') return $this->operationsBeautyItemReport($definition, $stores, $range, $input);
        if ($report === 'marketing_beauty_new_item') return $this->marketingBeautyNewItemReport($definition, $stores, $range, $input);
        $categoryIds = $this->categoryIds((string)($definition['category'] ?? ''), $input);
        $rows = [];
        foreach ($this->cashRows($stores, $range, $categoryIds) as $fact) {
            $key = (string)$fact['category_path'] . '|' . (string)$fact['item_id'];
            if (!isset($rows[$key])) $rows[$key] = [
                'category_name' => $this->leafCategory((string)$fact['category_path']), 'item_name' => (string)$fact['item_name'],
                'item_id' => (string)$fact['item_id'], '_item_key' => $key, 'cash_cents' => 0, 'quantity' => 0,
            ];
            $rows[$key]['cash_cents'] += (int)$fact['amount_cents'];
            $rows[$key]['quantity'] += max(0, (int)$fact['quantity']);
        }
        $previousRange = [
            'start' => date('Y-m-d', strtotime($range['start'] . ' -1 year')),
            'end' => date('Y-m-d', strtotime($range['end'] . ' -1 year')),
        ];
        $previousByItem = [];
        foreach ($this->cashRows($stores, $previousRange, $categoryIds) as $fact) {
            $key = (string)$fact['category_path'] . '|' . (string)$fact['item_id'];
            $previousByItem[$key] = (int)($previousByItem[$key] ?? 0) + (int)$fact['amount_cents'];
        }
        $total = array_sum(array_column($rows, 'cash_cents'));
        foreach ($rows as &$row) {
            $row['cash_performance'] = $this->money((int)$row['cash_cents']);
            $row['performance_share'] = $this->ratio((int)$row['cash_cents'], $total);
            $row['unit_price'] = $this->moneyRatio((int)$row['cash_cents'], (int)$row['quantity']);
            $row['product_cost'] = '-'; $row['gross_margin'] = '-'; $row['gross_profit'] = '-';
            $previous = (int)($previousByItem[(string)$row['_item_key']] ?? 0);
            $row['last_year_performance'] = $this->money($previous);
            $row['last_year_share'] = $this->ratio($previous, (int)$row['cash_cents']);
            $row['difference'] = $this->money((int)$row['cash_cents'] - $previous);
        }
        unset($row);
        usort($rows, static fn(array $a, array $b): int => ((int)$b['cash_cents'] <=> (int)$a['cash_cents']) ?: strcmp((string)$a['item_name'], (string)$b['item_name']));
        foreach ($rows as $index => &$row) $row['serial_no'] = $index + 1;
        unset($row);
        foreach ($rows as &$row) unset($row['_item_key'], $row['cash_cents'], $row['quantity']);
        unset($row);
        $itemLabel = (string)($definition['category'] ?? '') === '花园' ? '花园商品名称'
            : ((string)($definition['category'] ?? '') === '六维' ? '品项名称' : '商品名称');
        $costLabel = (string)($definition['category'] ?? '') === '花园' ? '产品成本' : '单次产品成本';
        $columns = [
            $this->column('serial_no', '序号', '按本期现金业绩从高到低排序。', false, true, 70),
            $this->column('category_name', '品类', '取成交时冻结的商品分类路径中的末级分类。', false, true, 130),
            $this->column('item_name', $itemLabel, '取成交时保存的商品名称快照；卡项按内含项目展开。', false, true, 180),
            $this->column('cash_performance', '现金业绩', '成功收款按销售明细分摊，欠款补交和退款分别按成功日期正负计入。', true, false, 120, $this->drill($definition['category'] ?? '')),
            $this->column('performance_share', '业绩占比', '本商品现金业绩除以当前报表商品范围内现金业绩合计；分母为零显示“-”。'),
            $this->column('unit_price', '单次价格', '本商品现金业绩除以成交数量；数量为零显示“-”。'),
            $this->column('product_cost', $costLabel, '取成交时冻结的单件成本快照；当前事实未保存成本时显示“-”。'),
            $this->column('gross_margin', '毛利率', '（单次价格－产品成本）÷ 单次价格；缺少成本或分母为零显示“-”。'),
            $this->column('gross_profit', '毛利额', '单次价格－产品成本；缺少成本时显示“-”。'),
            $this->column('last_year_performance', '去年同期业绩', '上一自然年同一日期范围、同商品和同分类的净现金业绩。', true),
            $this->column('last_year_share', '业绩占比', '去年同期业绩除以本期现金业绩，完全按原表公式；本期为零显示“-”。'),
            $this->column('difference', '差额', '本期现金业绩减去年同期业绩。', true),
        ];
        return $this->result((string)$definition['name'], $columns, array_values($rows), $range, $input, ['category' => true]);
    }

    private function marketingBeautyNewItemReport(array $definition, array $stores, array $range, array $input): array
    {
        $ids = $this->categoryIds('生美', $input);
        $allYear = $this->cashRows($stores, ['end' => $range['end']], $ids);
        $rows = [];
        foreach ($this->months($range) as $month) {
            $monthRange = ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01'))];
            $facts = $this->cashRows($stores, $monthRange, $ids);
            $total = $this->factAmount($this->cashRows($stores, $monthRange, []));
            $serviceRows = $this->serviceRows($stores, $monthRange, $ids);
            $active = $this->memberCount($this->serviceRows($stores, $monthRange, []));
            foreach ($facts as $fact) {
                $key = $month . '|' . (string)$fact['item_id'];
                if (!isset($rows[$key])) $rows[$key] = ['month' => $month, 'item_name' => (string)$fact['item_name'], 'item_id' => (string)$fact['item_id'], 'month_total_cents' => $total, 'cash_cents' => 0, 'members' => [], 'experience_members' => [], 'annual_members' => [], 'annual_repeat_members' => [], 'active_members' => $active];
                $rows[$key]['cash_cents'] += (int)$fact['amount_cents'];
                if ((int)$fact['member_id'] > 0) $rows[$key]['members'][(string)$fact['member_id']] = true;
            }
            foreach ($serviceRows as $service) {
                foreach ($rows as $key => &$row) {
                    if (!str_starts_with($key, $month . '|')) continue;
                    if ((string)($service['project_id'] ?? '') !== (string)($row['item_id'] ?? '')) continue;
                    if ((int)$service['is_experience'] === 1 && (int)$service['member_id'] > 0) $row['experience_members'][(string)$service['member_id']] = true;
                }
                unset($row);
            }
        }
        foreach ($rows as &$row) {
            $annualLines = [];
            foreach ($allYear as $fact) {
                if ((string)$fact['item_id'] !== (string)$row['item_id'] || substr((string)$fact['business_date'], 0, 4) !== substr((string)$row['month'], 0, 4) || (string)$fact['business_date'] > $row['month'] . '-31') continue;
                $memberId = (int)$fact['member_id']; if ($memberId <= 0) continue;
                $row['annual_members'][(string)$memberId] = true;
                $annualLines[$memberId . '|' . (string)$fact['source_line_id'] . '|' . (string)$fact['business_date']] = $memberId;
            }
            $counts = array_count_values($annualLines);
            foreach ($counts as $memberId => $count) if ($count >= 2) $row['annual_repeat_members'][(string)$memberId] = true;
            $people = count($row['members']);
            $annualPeople = count($row['annual_members']);
            $repeatPeople = count($row['annual_repeat_members']);
            $row['month_total_performance'] = $this->money((int)$row['month_total_cents']);
            $row['item_performance'] = $this->money((int)$row['cash_cents']);
            $row['item_share'] = $this->ratio((int)$row['cash_cents'], (int)$row['month_total_cents']);
            $row['experience_people'] = count($row['experience_members']);
            $row['deal_people'] = $people;
            $row['deal_rate'] = $this->ratio($people, count($row['experience_members']));
            $row['deal_unit_output'] = $this->moneyRatio((int)$row['cash_cents'], $people);
            $row['annual_repeat_people'] = $repeatPeople;
            $row['annual_repeat_rate'] = $this->ratio($repeatPeople, $annualPeople);
            $row['annual_deal_people'] = $annualPeople;
            $row['annual_active_penetration'] = $this->ratio($annualPeople, (int)$row['active_members']);
            foreach (['month_total_cents', 'cash_cents', 'members', 'experience_members', 'annual_members', 'annual_repeat_members', 'active_members', 'item_id'] as $key) unset($row[$key]);
        }
        unset($row);
        $columns = [
            $this->column('month', '月份', '按所选年份展开的自然月。', false, true, 100),
            $this->column('item_name', '品项名称', '取成交时保存的生美商品名称快照；卡项按内含项目展开。', false, true, 180),
            $this->column('month_total_performance', '月度总业绩', '当前权限范围当月全部商品分类的净现金业绩。', true, false, 120, $this->drill('')),
            $this->column('item_performance', '品项业绩', '该生美品项当月成功收款按销售明细和卡内项目分摊后的净现金业绩。', true, false, 120, $this->drill('生美')),
            $this->column('item_share', '品项业绩占比', '品项业绩除以月度总业绩；分母为零显示“-”。'),
            $this->column('experience_people', '体验人数', '当月完成该生美分类服务且带体验标记的不同会员数。', true),
            $this->column('deal_people', '成交人头', '当月购买该品项的不同会员数。', true),
            $this->column('deal_rate', '成交率', '成交人头除以体验人数；分母为零显示“-”。'),
            $this->column('deal_unit_output', '成交单产', '品项业绩除以成交人头；分母为零显示“-”。'),
            $this->column('annual_repeat_people', '年度购买≥2次人数', '截至当前月，本年度购买该品项不少于两次的不同会员数。', true),
            $this->column('annual_repeat_rate', '年度复购率', '年度购买≥2次人数除以年度成交人头；分母为零显示“-”。'),
            $this->column('annual_deal_people', '年度成交人头', '截至当前月，本年度购买该品项的不同会员数。', true),
            $this->column('annual_active_penetration', '年度活客普及率', '年度成交人头除以当月活客数；分母为零显示“-”。'),
        ];
        foreach ($columns as $index => &$column) {
            if ($index === 0) $column['header_path'] = ['月份'];
            elseif ($index === 1) $column['header_path'] = ['品项名称'];
            elseif ($index <= 8) $column['header_path'] = ['当月数据', (string)$column['label']];
            else $column['header_path'] = ['年度累计', (string)$column['label']];
        }
        unset($column);
        return $this->result((string)$definition['name'], $columns, array_values($rows), $range, $input, ['year' => true, 'category' => true]);
    }

    private function operationsBeautyItemReport(array $definition, array $stores, array $range, array $input): array
    {
        if (substr($range['start'], 0, 7) !== substr($range['end'], 0, 7)) throw new \InvalidArgumentException('运营生美品项表的查询时间不允许跨自然月');
        $min = max(0, (int)round((float)($input['unit_price_min'] ?? 0) * 100));
        $maxInput = trim((string)($input['unit_price_max'] ?? ''));
        $max = $maxInput === '' ? PHP_INT_MAX : max($min, (int)round((float)$maxInput * 100));
        $rows = []; $rangeFacts = $this->cashRows($stores, $range, $this->categoryIds('生美', $input));
        foreach ($rangeFacts as $fact) {
            $quantity = max(1, (int)($fact['quantity'] ?? 1));
            $unitPrice = intdiv(abs((int)($fact['sale_amount_cents'] ?? $fact['amount_cents'])), $quantity);
            if ($unitPrice < $min || $unitPrice > $max) continue;
            $key = (string)$fact['item_id'];
            if (!isset($rows[$key])) $rows[$key] = ['item_name' => (string)$fact['item_name'], 'cash_cents' => 0, 'members' => []];
            $rows[$key]['cash_cents'] += (int)$fact['amount_cents'];
            if ((int)$fact['member_id'] > 0) $rows[$key]['members'][(string)$fact['member_id']] = (int)($rows[$key]['members'][(string)$fact['member_id']] ?? 0) + (int)$fact['amount_cents'];
        }
        $allServiceMembers = $this->memberCount($this->serviceRows($stores, $range, []));
        $storeCash = $this->factAmount($this->cashRows($stores, $range, []));
        foreach ($rows as &$row) {
            $qualified = 0;
            foreach ($row['members'] as $amount) if ($amount >= 100000) $qualified++;
            $row['cash_performance'] = $this->money((int)$row['cash_cents']);
            $row['deal_people'] = $qualified;
            $row['deal_unit_output'] = $this->moneyRatio((int)$row['cash_cents'], $qualified);
            $row['people_share'] = $this->ratio($qualified, $allServiceMembers);
            $row['performance_share'] = $this->ratio((int)$row['cash_cents'], $storeCash);
            unset($row['cash_cents'], $row['members']);
        }
        unset($row);
        usort($rows, static fn(array $left, array $right): int => strcmp((string)$left['item_name'], (string)$right['item_name']));
        return $this->result((string)$definition['name'], [
            $this->column('item_name', '商品名称', '取成交时保存的商品名称快照；卡项按内含项目展开。', false, true, 180),
            $this->column('cash_performance', '现金业绩', '该商品在筛选期间的成功收款按销售明细、卡内项目分摊后的净现金业绩。', true, false, 120, $this->drill('生美')),
            $this->column('deal_people', '成交人头', '该商品在筛选商品分类与单次成交价格区间内，累计现金业绩达到1,000元的不同会员数。', true),
            $this->column('deal_unit_output', '成交单产', '该商品现金业绩除以成交人头；分母为零显示“-”。'),
            $this->column('people_share', '人头占比', '成交人头除以本月完成有效服务的不同会员数；分母为零显示“-”。'),
            $this->column('performance_share', '业绩占比', '该商品现金业绩除以当前门店范围当月全部商品净现金业绩；分母为零显示“-”。'),
        ], array_values($rows), $range, $input, ['category' => true, 'single_month' => true, 'unit_price_range' => true]);
    }

    private function categoryTotalReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $category = (string)$definition['category'];
        $categoryIds = $this->categoryIds($category, $input);
        $months = $this->months($range);
        $totals = $this->cashTotalsByMonth($stores, $range, $categoryIds);
        $rows = [];
        foreach ($months as $month) {
            $amount = (int)($totals[$month] ?? 0);
            $row = ['month' => $month, 'target_amount' => '-', 'target_amount_version' => 0, 'cash_performance' => $this->money($amount)];
            $subject = $this->manualSubject($report, $month, 0);
            $manual = $this->manualValue($report, $subject, 'target_amount');
            if ($this->hasManualValue($manual)) { $row['target_amount'] = $this->money((int)$manual['value']); $row['target_amount_version'] = (int)$manual['version']; }
            $row['completion_rate'] = $row['target_amount'] === '-' ? '-' : $this->ratio($amount, (int)$manual['value']);
            $rows[] = $row;
        }
        $this->attachManualSubjects($rows, $report);
        $categoryLabel = $category . '总业绩';
        $detailLabels = [
            '六维' => ['自营1', '自营2', '自营3', '自营4', '合作1', '合作2', '合作3', '合作4'],
            '昊美' => ['昊美m', '昊美n'],
            '花园' => ['花园口服', '花园卡项', '花园家居', '花园私美'],
            '生美' => ['口服', '家居', '面部', '综合', '身体'],
        ][$category] ?? [];
        $detailTotals = [];
        // These are fixed report components, not user filters. A component that
        // is absent from the category tree must contribute zero, never all sales.
        foreach ($detailLabels as $label) $detailTotals[$label] = $this->cashTotalsByMonth($stores, $range, $this->descendantsByPath($category . ' / ' . $label), true);
        foreach ($rows as &$row) foreach ($detailLabels as $label) $row['category_' . $this->key($label)] = $this->money((int)($detailTotals[$label][$row['month']] ?? 0));
        unset($row);
        if ($category === '生美') {
            // 同名“综合”必须依据完整分类路径区分到面部或身体，不能用名称模糊
            // 查询后复制进两个表格列，否则会造成业绩重复。
            $faceComprehensiveIds = $this->descendantsByPath('生美 / 面部 / 综合');
            $bodyComprehensiveIds = $this->descendantsByPath('生美 / 身体 / 综合');
            $components = [
                ['key' => 'beauty_oral', 'label' => '口服', 'group' => '产品', 'ids' => $this->descendantsByPath('生美 / 产品 / 口服')],
                ['key' => 'beauty_home', 'label' => '家居', 'group' => '产品', 'ids' => $this->descendantsByPath('生美 / 产品 / 家居')],
                ['key' => 'beauty_face', 'label' => '面部', 'group' => '面部', 'ids' => array_values(array_diff($this->descendantsByPath('生美 / 面部'), $faceComprehensiveIds))],
                ['key' => 'beauty_face_comprehensive', 'label' => '综合', 'group' => '面部', 'ids' => $faceComprehensiveIds],
                ['key' => 'beauty_body', 'label' => '身体', 'group' => '身体', 'ids' => array_values(array_diff($this->descendantsByPath('生美 / 身体'), $bodyComprehensiveIds))],
                ['key' => 'beauty_body_comprehensive', 'label' => '综合', 'group' => '身体', 'ids' => $bodyComprehensiveIds],
                ['key' => 'beauty_face_body_card', 'label' => '面身综合卡', 'group' => '面身综合卡', 'ids' => $this->descendantsByPath('生美 / 面身综合卡')],
                ['key' => 'beauty_other', 'label' => '其他', 'group' => '其他', 'ids' => $this->descendantsByPath('生美 / 其他')],
            ];
            foreach ($components as &$component) $component['totals'] = $this->cashTotalsByMonth($stores, $range, (array)$component['ids'], true);
            unset($component);
            foreach ($rows as &$row) foreach ($components as $component) $row[(string)$component['key']] = $this->money((int)($component['totals'][$row['month']] ?? 0));
            unset($row);
            $columns = [
                $this->pathColumn('month', '月份', '按所选年份展开1月至12月。', ['月份'], false, true, 100, [], '', 'text'),
                $this->pathColumn('cash_performance', '生美总业绩', '商品分类“生美”及全部下级分类的净现金业绩。', ['生美总业绩'], true, false, 130, $this->drill('生美')),
            ];
            foreach ($components as $component) {
                $label = (string)$component['label']; $group = (string)$component['group'];
                $headerPath = $group === $label ? [$label] : [$group, $label];
                $categoryPath = $group === $label ? '生美 / ' . $label : '生美 / ' . $group . ' / ' . $label;
                $columns[] = $this->pathColumn((string)$component['key'], $label, '商品分类“' . $categoryPath . '”及全部下级分类的净现金业绩；分类未配置时为0。', $headerPath, true, false, 120, $this->drill('生美'));
            }
            return $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
        }
        if ($category === '六维') {
            foreach ($rows as &$row) {
                $selfSubtotal = 0; $partnerSubtotal = 0;
                foreach (['自营1', '自营2', '自营3', '自营4'] as $label) $selfSubtotal += $this->cents($row['category_' . $this->key($label)] ?? '-');
                foreach (['合作1', '合作2', '合作3', '合作4'] as $label) $partnerSubtotal += $this->cents($row['category_' . $this->key($label)] ?? '-');
                $row['self_category_subtotal'] = $this->money($selfSubtotal);
                $row['partner_category_subtotal'] = $this->money($partnerSubtotal);
            }
            unset($row);
            $target = $this->manualColumn('target_amount', '目标业绩', '手动输入当前权限范围该月六维总业绩目标，保存为独立报表补充记录，不覆盖订单或收款事实。');
            $target['header_path'] = ['目标业绩'];
            $columns = [
                $this->pathColumn('month', '月份', '按所选年份展开1月至12月。', ['月份'], false, true, 100, [], '', 'text'),
                $target,
                $this->pathColumn('cash_performance', '六维总业绩', '商品分类“六维”及全部下级分类的净现金业绩。', ['六维总业绩'], true, false, 130, $this->drill('六维')),
                $this->pathColumn('completion_rate', '目标达成率', '六维总业绩除以目标业绩；目标为空或为零显示“-”。', ['目标达成率'], false, false, 110, [], '', 'percentage'),
            ];
            foreach (['自营1', '自营2', '自营3', '自营4'] as $label) {
                $columns[] = $this->pathColumn('category_' . $this->key($label), $label, '商品分类“六维 / ' . $label . '”及全部下级分类的净现金业绩。', ['自营品类业绩', $label], true, false, 120, $this->drill('六维 / ' . $label));
            }
            $columns[] = $this->pathColumn('self_category_subtotal', '小计', '自营1至自营4业绩之和。', ['自营品类业绩', '小计'], true, false, 120);
            foreach (['合作1', '合作2', '合作3', '合作4'] as $label) {
                $columns[] = $this->pathColumn('category_' . $this->key($label), $label, '商品分类“六维 / ' . $label . '”及全部下级分类的净现金业绩。', ['合作品类业绩', $label], true, false, 120, $this->drill('六维 / ' . $label));
            }
            $columns[] = $this->pathColumn('partner_category_subtotal', '小计', '合作1至合作4业绩之和。', ['合作品类业绩', '小计'], true, false, 120);
            return $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
        }
        $columns = [
            $this->column('month', '月份', '按所选日期范围内的自然月分组。', false, true, 100),
            $this->manualColumn('target_amount', '目标业绩', '手动输入当前权限范围该月' . $categoryLabel . '目标，保存为独立报表补充记录，不覆盖订单或收款事实。'),
            $this->column('cash_performance', $category === '昊美' ? '昊美总业绩' : ($category === '花园' ? '私密总业绩' : $categoryLabel), '商品分类“' . $category . '”及全部下级分类的净现金业绩。', true, false, 130, $this->drill($category)),
            $this->column('completion_rate', '目标达成率', $categoryLabel . '除以目标业绩；目标为空或为零显示“-”。'),
        ];
        foreach ($detailLabels as $label) {
            $group = $category === '六维' ? (str_starts_with($label, '自营') ? '自营品类业绩' : '合作品类业绩') : ($category === '花园' ? '合作品类业绩' : '');
            $display = preg_replace('/^花园/', '', $label);
            $columns[] = $this->column('category_' . $this->key($label), (string)$display, '商品分类“' . $label . '”及全部下级分类的净现金业绩。', true, false, 120, $this->drill($label), $group);
        }
        if (in_array($category, ['六维', '昊美', '花园'], true)) {
            $key = 'category_subtotal';
            foreach ($rows as &$row) { $sum = 0; foreach ($detailLabels as $label) $sum += $this->cents($row['category_' . $this->key($label)] ?? '-'); $row[$key] = $this->money($sum); }
            unset($row);
            $columns[] = $this->column($key, '小计', '同一业务分组内各已列分类业绩之和。', true, false, 120, [], $category === '花园' ? '合作品类业绩' : '');
        }
        return $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
    }

    private function performanceTotalReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $months = $this->months($range);
        $categories = ['生美', '六维', '昊美', '花园'];
        $totals = [];
        foreach ($categories as $category) $totals[$category] = $this->cashTotalsByMonth($stores, $range, $this->categoryIds($category, []));
        $all = $this->cashTotalsByMonth($stores, $range, []);
        $rows = [];
        foreach ($months as $month) {
            $row = ['month' => $month, 'total_performance' => $this->money((int)($all[$month] ?? 0))];
            $known = 0;
            foreach ($categories as $category) { $amount = (int)($totals[$category][$month] ?? 0); $known += $amount; $key = $this->key($category); $row[$key . '_performance'] = $this->money($amount); $row[$key . '_share'] = $this->ratio($amount, (int)($all[$month] ?? 0)); }
            $row['other_performance'] = $this->money((int)($all[$month] ?? 0) - $known);
            $row['other_share'] = $this->ratio((int)($all[$month] ?? 0) - $known, (int)($all[$month] ?? 0));
            $row['external_subtotal'] = $this->money((int)($totals['六维'][$month] ?? 0) + (int)($totals['昊美'][$month] ?? 0) + (int)($totals['花园'][$month] ?? 0) + ((int)($all[$month] ?? 0) - $known));
            $rows[] = $row;
        }
        $columns = [
            $this->column('month', '月份', '按所选日期范围内的自然月分组。', false, true, 100),
            $this->column('total_performance', '总业绩', '当前权限范围内全部商品分类的净现金业绩。', true, false, 120),
        ];
        foreach ($categories as $category) {
            $key = $this->key($category);
            $columns[] = $this->column($key . '_performance', $category . '业绩', '商品分类“' . $category . '”及全部下级分类的净现金业绩。', true, false, 120, $this->drill($category));
            $columns[] = $this->column($key . '_share', $category . '占比', $category . '业绩除以总业绩；分母为零显示“-”。');
        }
        $columns[] = $this->column('other_performance', '其他', '总业绩扣除生美、六维、昊美和花园业绩后的余额。', true);
        $columns[] = $this->column('other_share', '其他占比', '其他业绩除以总业绩；分母为零显示“-”。');
        $columns[] = $this->column('external_subtotal', '外接小计', '六维、昊美、花园和其他业绩之和；完全按原表公式。', true, false, 120);
        return $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
    }

    private function marketReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $category = (string)$definition['category'];
        $months = $this->months($range); $categoryIds = $this->categoryIds($category, $input);
        $companies = $this->organization()->filterSchema($range);
        $companyOptions = (array)(($companies[0]['options'] ?? []));
        $byMonth = $this->cashTotalsByMonth($stores, $range, $categoryIds);
        $byCompany = [];
        foreach ($this->cashRows($stores, $range, $categoryIds) as $fact) {
            $row = $fact; $this->organization()->project($row, (string)$fact['organization_id'], (string)$fact['organization_path_snapshot'], (string)$fact['business_date']);
            $id = (string)$row['company_dimension_id']; if ($id === '') continue;
            $month = substr((string)$fact['business_date'], 0, 7); $byCompany[$month][$id] = (int)($byCompany[$month][$id] ?? 0) + (int)$fact['amount_cents'];
        }
        $rows = [];
        foreach ($months as $month) {
            $total = (int)($byMonth[$month] ?? 0); $subject = $this->manualSubject($report, $month, 0); $manual = $this->manualValue($report, $subject, 'target_amount'); $hasTarget = $this->hasManualValue($manual);
            $row = ['month' => $month, 'target_amount' => $hasTarget ? $this->money((int)$manual['value']) : '-', 'target_amount_version' => $manual === null ? 0 : (int)$manual['version'], 'completed_amount' => $this->money($total), 'completion_rate' => $hasTarget ? $this->ratio($total, (int)$manual['value']) : '-'];
            foreach ($companyOptions as $company) {
                $id = (string)$company['value']; $key = 'company_' . $id; $amount = (int)($byCompany[$month][$id] ?? 0);
                $companyTarget = $this->manualValue($report, $subject, $key . '_target_amount'); $hasCompanyTarget = $this->hasManualValue($companyTarget);
                $row[$key . '_target_amount'] = $hasCompanyTarget ? $this->money((int)$companyTarget['value']) : '-';
                $row[$key . '_target_amount_version'] = $companyTarget === null ? 0 : (int)$companyTarget['version'];
                $row[$key . '_amount'] = $this->money($amount); $row[$key . '_completion_rate'] = $hasCompanyTarget ? $this->ratio($amount, (int)$companyTarget['value']) : '-'; $row[$key . '_share'] = $this->ratio($amount, $total);
            }
            $rows[] = $row;
        }
        $this->attachManualSubjects($rows, $report);
        $group = '集团' . $category . '业绩完成';
        $columns = [
            $this->column('month', '月份', '按所选日期范围内的自然月分组。', false, true, 100),
            $this->manualColumn('target_amount', '目标金额', '手动输入当前权限范围当月' . $category . '目标，保存为独立报表补充记录。', $group),
            $this->column('completed_amount', '完成金额', '商品分类“' . $category . '”及全部下级分类的净现金业绩。', true, false, 120, $this->drill($category), $group),
            $this->column('completion_rate', '完成率', '完成金额除以目标金额；目标为空或为零显示“-”。', false, false, 110, [], $group),
        ];
        foreach ($companyOptions as $company) {
            $name = (string)$company['label']; $key = 'company_' . (string)$company['value'];
            $amountLabel = $category === '花园' ? '销售业绩' : '完成金额';
            if ($category === '生美') {
                $columns[] = $this->manualColumn($key . '_target_amount', '目标金额', '手动输入分公司“' . $name . '”该月生美目标，保存为独立报表补充记录。', $name);
                $columns[] = $this->column($key . '_amount', $amountLabel, '统计分公司“' . $name . '”有效下级门店的当月' . $category . '净现金业绩。', true, false, 120, $this->drill($category, ['company_dimension_id' => (string)$company['value']]), $name);
                $columns[] = $this->column($key . '_completion_rate', '完成率', $amountLabel . '除以目标金额；分母为零显示“-”。', false, false, 110, [], $name);
                continue;
            }
            $columns[] = $this->column($key . '_amount', $amountLabel, '统计分公司“' . $name . '”有效下级门店的当月' . $category . '净现金业绩。', true, false, 120, $this->drill($category, ['company_dimension_id' => (string)$company['value']]), $name);
            $columns[] = $this->column($key . '_share', '业绩占比', '该分公司' . $amountLabel . '除以集团' . $category . '完成金额；分母为零显示“-”。', false, false, 110, [], $name);
        }
        return $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
    }

    private function efficiencyReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $category = (string)$definition['category']; $ids = $this->categoryIds($category, $input); $rows = [];
        $cashHistory = $this->cashRows($stores, ['end' => $range['end']], $ids);
        foreach ($this->months($range) as $month) {
            $monthRange = ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01'))];
            $monthCash = $this->cashRows($stores, $monthRange, $ids);
            $monthServices = $this->serviceRows($stores, $monthRange, $ids);
            foreach ([['key' => 'self', 'name' => '自营', 'partner' => false], ['key' => 'partner', 'name' => '合作', 'partner' => true]] as $team) {
                $cash = $this->teamFacts($monthCash, (bool)$team['partner']);
                $services = $this->teamFacts($monthServices, (bool)$team['partner']);
                $history = $this->teamFacts($cashHistory, (bool)$team['partner']);
                $amount = $this->factAmount($cash); $count = $this->memberCount($cash);
                $subject = $this->manualSubject($report, $month, 0, 'team:' . (string)$team['key']);
                $visitManual = $this->manualValue($report, $subject, 'visit_count'); $newVisitManual = $this->manualValue($report, $subject, 'new_visit_count');
                $metrics = $this->preSaleMetrics($history, $monthRange, false);
                $refundLines = []; $refundAmount = 0;
                foreach ($cash as $fact) if ((int)$fact['amount_cents'] < 0) { $refundLines[(string)($fact['id'] ?? $fact['source_line_id'])] = true; $refundAmount += abs((int)$fact['amount_cents']); }
                $hasVisit = $this->hasManualValue($visitManual); $hasNewVisit = $this->hasManualValue($newVisitManual);
                $newVisit = $hasNewVisit ? (int)$newVisitManual['value'] : '-';
                $newPeople = (int)$metrics['first_people']; $newAmount = (int)$metrics['first_amount'];
                $rows[] = [
                    'month' => $month, 'team_name' => (string)$team['name'], 'annotation_dimension_key' => 'team:' . (string)$team['key'],
                    'visit_count' => $hasVisit ? (int)$visitManual['value'] : '-', 'visit_count_version' => $visitManual === null ? 0 : (int)$visitManual['version'],
                    'deal_people' => $count, 'deal_rate' => $hasVisit ? $this->ratio($count, (int)$visitManual['value']) : '-', 'deal_amount' => $this->money($amount), 'unit_output' => $this->moneyRatio($amount, $count),
                    'new_visit_count' => $newVisit, 'new_visit_count_version' => $newVisitManual === null ? 0 : (int)$newVisitManual['version'],
                    'new_deal_people' => $newPeople, 'new_deal_rate' => $newVisit === '-' ? '-' : $this->ratio($newPeople, (int)$newVisit), 'new_deal_amount' => $this->money($newAmount), 'new_unit_output' => $this->moneyRatio($newAmount, $newPeople),
                    'service_count' => $this->serviceCount($services), 'consumption_amount' => $this->money($this->serviceConsumptionAmount($services)), 'service_unit_amount' => $this->moneyRatio($this->serviceConsumptionAmount($services), $this->serviceCount($services)),
                    'refund_count' => count($refundLines), 'refund_amount' => $this->money($refundAmount),
                ];
            }
        }
        $this->attachManualSubjects($rows, $report);
        return $this->result((string)$definition['name'], [
            $this->column('month', '月份', '按所选日期范围内的自然月分组。', false, true, 100),
            $this->column('team_name', $category . '团队', '当前权限范围内汇总；合作方团队按成交事实合作方快照分组时另行展示。', false, true, 120),
            $this->manualColumn('visit_count', '见诊人数', '手动录入该团队当月见诊人数，保存为独立报表补充记录。', '总成交数据'),
            $this->column('deal_people', '成交人数', '当月购买' . $category . '及下级商品的去重会员数。', true, false, 110, [], '总成交数据'),
            $this->column('deal_rate', '成交率', '成交人数除以见诊人数；见诊人数为空或为零显示“-”。', false, false, 110, [], '总成交数据'),
            $this->column('deal_amount', '成交金额', '当月' . $category . '及下级商品的净现金业绩。', true, false, 120, $this->drill($category), '总成交数据'),
            $this->column('unit_output', '成交单产', '成交金额除以成交人数；分母为零显示“-”。', false, false, 110, [], '总成交数据'),
            $this->manualColumn('new_visit_count', '见诊人数', '手动输入当月新客见诊人数，保存为独立报表补充记录，不覆盖服务或收款事实。', '新客成交数据'),
            $this->column('new_deal_people', '成交人数', '当月第一次购买' . $category . '及下级商品的不同会员数。', true, false, 110, [], '新客成交数据'),
            $this->column('new_deal_rate', '成交率', '新客成交人数除以新客见诊人数；分母为零显示“-”。', false, false, 110, [], '新客成交数据'),
            $this->column('new_deal_amount', '成交金额', '当月新客第一次购买' . $category . '及下级商品的现金业绩。', true, false, 120, $this->drill($category), '新客成交数据'),
            $this->column('new_unit_output', '成交单产', '新客成交金额除以新客成交人数；分母为零显示“-”。', false, false, 110, [], '新客成交数据'),
            $this->column('service_count', '服务项目数', '当月完成服务且服务项目分类为' . $category . '及下级的项目数量。', true, false, 120, [], '服务消耗'),
            $this->column('consumption_amount', '耗卡金额', '服务成功核销后形成的消耗业绩金额。', true, false, 120, [], '服务消耗'),
            $this->column('service_unit_amount', '单次金额', '耗卡金额除以服务项目数；分母为零显示“-”。', false, false, 110, [], '服务消耗'),
            $this->column('refund_count', '退款数量', '当月退款成功的' . $category . '分类项目数量。', true, false, 110, [], '服务消耗'),
            $this->column('refund_amount', '退款金额', '当月退款成功后按项目分摊的实际退款金额，按绝对金额展示。', true, false, 120, [], '服务消耗'),
        ], $rows, $range, $input, ['year' => true]);
    }

    private function preSaleReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $marketing = in_array($report, ['marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true);
        $referral = $report === 'marketing_referral_pre_sale';
        $experienceActualLabel = $referral ? '实际达成' : '体验实际达成人数';
        $newCustomerActualLabel = $referral ? '实际达成' : '新客实际达成人数';
        $newCustomerAmountTargetLabel = $referral ? '业绩目标' : '新客业绩目标';
        $specs = $marketing ? [
            $this->monthlySpec('experience_target', '体验目标', '手动输入当月体验目标人数，保存为独立报表补充记录，不覆盖体验或收款事实。', true, ['体验目标']),
            $this->monthlySpec('experience_actual', $experienceActualLabel, '当月结账购物车带体验标签且符合本表来源范围的不同会员数。', false, [$experienceActualLabel]),
            $this->monthlySpec('experience_completion_rate', '达成率', '体验实际达成人数除以体验目标；分母为零或未填写显示“-”。', false, ['达成率']),
            $this->monthlySpec('new_customer_target', '新客目标', '手动输入当月新客目标人数，保存为独立报表补充记录。', true, ['新客目标']),
            $this->monthlySpec('new_customer_actual', $newCustomerActualLabel, '当月来源符合本表规则且首次产生现金业绩的不同会员数。', false, [$newCustomerActualLabel]),
            $this->monthlySpec('new_customer_completion_rate', '达成率', '新客实际达成人数除以新客目标；分母为零或未填写显示“-”。', false, ['达成率']),
            $this->monthlySpec('new_customer_amount_target', $newCustomerAmountTargetLabel, '手动输入当月新客现金业绩目标，保存为独立报表补充记录。', true, [$newCustomerAmountTargetLabel]),
            $this->monthlySpec('new_customer_amount_actual', '实际达成', '当月来源符合本表规则的新客成功收款，按销售明细、卡内项目分摊后的净现金业绩。', false, ['实际达成']),
            $this->monthlySpec('new_customer_amount_completion_rate', '达成率', '新客实际达成金额除以新客业绩目标；分母为零或未填写显示“-”。', false, ['达成率']),
            $this->monthlySpec('new_customer_deal_rate', '新客成交率', '新客实际达成人数除以体验实际达成人数；分母为零显示“-”。', false, ['新客成交率']),
            $this->monthlySpec('new_customer_unit_output', '新客成交单产', '新客实际达成金额除以新客实际达成人数；分母为零显示“-”。', false, ['新客成交单产']),
            $this->monthlySpec('transition_people', '售中转换人数', '来源符合本表规则、当月发生第二次现金业绩的不同会员数。', false, ['售中转换人数']),
            $this->monthlySpec('transition_amount', '售中转换业绩', '售中转换会员当月成功收款按销售明细分摊后的净现金业绩。', false, ['售中转换业绩']),
            $this->monthlySpec('transition_rate', '售中转换率', '售中转换人数除以新客实际达成人数；分母为零显示“-”。', false, ['售中转换率']),
            $this->monthlySpec('transition_unit_output', '售中转换单产', '售中转换业绩除以售中转换人数；分母为零显示“-”。', false, ['售中转换单产']),
            $this->monthlySpec('first_year_amount', '首年业绩', '本年度首次成为本表来源会员的会员，自首次成交起至所选月份末的累计净现金业绩。', false, ['首年业绩']),
        ] : [
            $this->monthlySpec('experience_people', '体验人数', '手动输入当月体验人数，保存为独立报表补充记录。', true, ['体验人数']),
            $this->monthlySpec('deal_people', '成交人数', '当月来源符合本表规则且第一次现金业绩达到500元的不同会员数。', false, ['成交人数']),
            $this->monthlySpec('deal_amount', '成交金额', '当月来源符合本表规则的第一次成功现金业绩，按销售明细和卡内项目分摊。', false, ['成交金额']),
            $this->monthlySpec('deal_rate', '成交率', '成交人数除以体验人数；分母为零或未填写显示“-”。', false, ['成交率']),
            $this->monthlySpec('deal_unit_output', '成交单产', '成交金额除以成交人数；分母为零显示“-”。', false, ['成交单产']),
            $this->monthlySpec('transition_people', '售中转换人数', '来源符合本表规则且第二次产生现金业绩的不同会员数。', false, ['售中转换人数']),
            $this->monthlySpec('transition_amount', '售中转换业绩', '售中转换会员第二次成功现金业绩，按销售明细和卡内项目分摊。', false, ['售中转换业绩']),
            $this->monthlySpec('transition_rate', '售中转换率', '售中转换人数除以成交人数；分母为零显示“-”。', false, ['售中转换率']),
            $this->monthlySpec('first_year_amount', '首年业绩', '本年度首次成为本表来源会员的会员，在本年度内累计形成的净现金业绩。', false, ['首年业绩']),
        ];
        return $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, $specs, $this->preSaleRule($report));
    }

    private function comparisonReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $specs = [
            $this->monthlySpec('store_count', '店面数', '当前权限范围内有效门店数量。', false, ['店面数']),
            $this->monthlySpec('full_target_amount', '2个亿目标', '手动输入当月全额现金业绩目标，保存为独立报表补充记录。', true, ['全额', '2个亿目标']),
            $this->monthlySpec('all_current_amount', '今年现金业绩', '所选年份当月，全部商品分类成功收款按销售明细分摊后的净现金业绩。', false, ['全额', '今年现金业绩']),
            $this->monthlySpec('all_completion_rate', '本年完成率', '今年现金业绩除以2个亿目标；分母为零或未填写显示“-”。', false, ['全额', '本年完成率']),
            $this->monthlySpec('all_previous_amount', '去年现金业绩', '上一自然年同月，全部商品分类的净现金业绩。', false, ['全额', '去年现金业绩']),
            $this->monthlySpec('all_difference', '业绩同比增减额', '今年现金业绩减去年现金业绩。', false, ['全额', '业绩同比增减额']),
        ];
        foreach ([['six_dimension', '六维'], ['private', '花园'], ['haomei', '昊美'], ['beauty', '生美']] as [$key, $category]) {
            $specs[] = $this->monthlySpec($key . '_current_amount', "今年\n{$category}业绩", '所选年份当月商品分类“' . $category . '”及全部下级分类的净现金业绩。', false, [$category, "今年\n{$category}业绩"]);
            $specs[] = $this->monthlySpec($key . '_previous_amount', "去年\n{$category}业绩", '上一自然年同月商品分类“' . $category . '”及全部下级分类的净现金业绩。', false, [$category, "去年\n{$category}业绩"]);
            $specs[] = $this->monthlySpec($key . '_difference', '业绩同比增减额', '今年' . $category . '业绩减去年' . $category . '业绩。', false, [$category, '业绩同比增减额']);
        }
        return $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, $specs, []);
    }

    private function healthReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $marketing = $report === 'marketing_health_data';
        $specs = $marketing ? [
            $this->monthlySpec('store_count', '门店数', '当前权限范围内有效门店数量。', false, ['门店数']),
            $this->monthlySpec('performance_target', '业绩目标', '手动输入当月业绩目标，保存为独立报表补充记录。', true, ['业绩目标']),
            $this->monthlySpec('all_current_amount', '今年现金业绩', '当月全部商品分类的净现金业绩。', false, ['今年现金业绩']),
            $this->monthlySpec('performance_completion_rate', '去年完成率', '今年现金业绩除以业绩目标；保留原表列名，分母为零或未填写显示“-”。', false, ['去年完成率']),
            $this->monthlySpec('all_previous_amount', '去年现金业绩', '上一自然年同月全部商品分类的净现金业绩。', false, ['去年现金业绩']),
            $this->monthlySpec('all_difference', '业绩同比增减额', '今年现金业绩减去年现金业绩。', false, ['业绩同比增减额']),
            $this->monthlySpec('consumption_amount', '耗卡业绩', '当月完成服务后形成的生美消耗业绩；按项目服务事实及消耗业绩事实统计。', false, ['耗卡业绩']),
            $this->monthlySpec('consumption_share', '耗卡占比', '耗卡业绩除以今年现金业绩；分母为零显示“-”。', false, ['耗卡占比']),
            $this->monthlySpec('required_staff', '员工应配人数', '手动输入当月应配置员工人数，保存为独立报表补充记录。', true, ['员工应配人数']),
            $this->monthlySpec('actual_staff', '员工实配人数', '手动输入当月实际配置员工人数，保存为独立报表补充记录。', true, ['员工实配人数']),
            $this->monthlySpec('staff_difference', '差值', '员工应配人数减员工实配人数；任一值未填写显示“-”。', false, ['差值']),
            $this->monthlySpec('beauty_service_count', '生美总消耗项目数', '当月完成服务且商品分类为生美及下级分类的项目数量。', false, ['生美总消耗项目数']),
            $this->monthlySpec('post_sale_visits', '售后人次', '当月完成有效服务的项目次数。', false, ['售后人次']),
            $this->monthlySpec('active_members', '活客数', '当月完成有效服务的不同会员数。', false, ['活客数']),
            $this->monthlySpec('beautician_monthly_projects', '美容师月均项目', '生美总消耗项目数除以员工实配人数；分母为零或未填写显示“-”。', false, ['美容师月均项目']),
            $this->monthlySpec('beautician_monthly_visits', '美容师月均服务人次', '售后人次除以员工实配人数；分母为零或未填写显示“-”。', false, ['美容师月均服务人次']),
            $this->monthlySpec('store_average_visits', '店均售后人次', '售后人次除以门店数；分母为零显示“-”。', false, ['店均售后人次']),
            $this->monthlySpec('weak_store_count', '10万以下弱店数', '当月现金业绩低于10万元的门店数量。', false, ['10万以下弱店数']),
            $this->monthlySpec('strong_store_count', '20万以上店数', '当月现金业绩高于20万元的门店数量。', false, ['20万以上店数']),
        ] : [
            $this->monthlySpec('store_count', '门店数', '当前权限范围内有效门店数量。', false, ['门店数']),
            $this->monthlySpec('active_members', '活客数', '当月完成有效服务的不同会员数。', false, ['活客数']),
            $this->monthlySpec('consuming_members', '消费人数', '当月有老客首护成功现金业绩的不同会员数。', false, ['消费人数']),
            $this->monthlySpec('consumption_rate', '消费率', '消费人数除以活客数；分母为零显示“-”。', false, ['消费率']),
            $this->monthlySpec('post_sale_visits', '售后人次', '当月完成有效服务的项目次数。', false, ['售后人次']),
            $this->monthlySpec('store_average_visits', "店均\n售后人次", '售后人次除以门店数；分母为零显示“-”。', false, ["店均\n售后人次"]),
            $this->monthlySpec('actual_staff', '美容师人数', '手动输入当月美容师人数，保存为独立报表补充记录。', true, ['美容师人数']),
            $this->monthlySpec('staff_unit_output', '人均产值', '店均业绩除以美容师人数；分母为零或未填写显示“-”。', false, ['人均产值']),
            $this->monthlySpec('monthly_service_visits', "月均\n服务人次", '售后人次除以12；完全按原表公式。', false, ["月均\n服务人次"]),
            $this->monthlySpec('consumption_amount', '生美消耗', '当月完成服务后形成的生美消耗业绩。', false, ['生美消耗']),
            $this->monthlySpec('staff_consumption_output', '人均消耗', '生美消耗除以美容师人数；分母为零或未填写显示“-”。', false, ['人均消耗']),
            $this->monthlySpec('store_average_amount', '店均业绩', '当月销售门店净现金业绩除以门店数；分母为零显示“-”。', false, ['店均业绩']),
        ];
        return $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, $specs, []);
    }

    private function customerStatusReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $activeLabel = $report === 'operations_customer_status_bdegh' ? '总活客数' : '活客数';
        $specs = [
            $this->monthlySpec('active_members', $activeLabel, '当月完成有效服务的不同会员数。', false, [$activeLabel]),
            $this->monthlySpec('one_to_two_visits', '当月到店1-2次', '当月完成有效服务1至2次的不同会员数。', false, ['当月到店1-2次']),
            $this->monthlySpec('one_to_two_visit_share', '占比', '当月到店1至2次人数除以当月总活客数；分母为零显示“-”。', false, ['占比']),
            $this->monthlySpec('three_plus_visits', '当月到店3次及以上', '当月完成有效服务不少于3次的不同会员数。', false, ['当月到店3次及以上']),
            $this->monthlySpec('three_plus_visit_share', '占比', '当月到店3次及以上人数除以当月总活客数；分母为零显示“-”。', false, ['占比']),
            $this->monthlySpec('regular_customers', '常客数(90天以内到店1次)', '截至当月末向前90天内完成有效服务恰好1次的不同会员数。', false, ['常客数(90天以内到店1次)']),
            $this->monthlySpec('inactive_customers', '死客（90天以上未到店）', '截至当月末向前90天以上未完成有效服务、且历史存在服务记录的不同会员数。', false, ['死客（90天以上未到店）']),
            $this->monthlySpec('active_rate', "活客率\n(活客数/(活客数+死客数))", '活客数除以活客数与死客数之和；分母为零显示“-”。', false, ["活客率\n(活客数/(活客数+死客数))"]),
        ];
        if ($report !== 'operations_customer_status_bdegh') {
            $specs = array_values(array_filter($specs, static fn(array $spec): bool => !in_array((string)$spec['key'], ['one_to_two_visit_share', 'three_plus_visit_share'], true)));
        }
        return $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, $specs, []);
    }

    private function memberConsumptionReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $haomei = array_fill_keys($this->descendantsByName('昊美'), true);
        $rows = [];
        foreach ($this->cashRows($stores, $range, []) as $fact) {
            if (isset($haomei[(int)($fact['category_id'] ?? 0)])) continue;
            $memberId = (int)($fact['member_id'] ?? 0); if ($memberId <= 0) continue;
            $row = $fact; $this->organization()->project($row, (string)$fact['organization_id'], (string)$fact['organization_path_snapshot'], (string)$fact['business_date']);
            $key = (string)($row['company_dimension_id'] ?? '') . '|' . (string)$fact['store_id'] . '|' . $memberId;
            if (!isset($rows[$key])) $rows[$key] = ['company_name' => (string)($row['company_name'] ?? '未配置分公司'), 'store_name' => (string)$fact['store_name'], 'member_name' => (string)($fact['member_name'] ?? ('会员#' . $memberId)), 'cash_performance_cents' => 0];
            $rows[$key]['cash_performance_cents'] += (int)$fact['amount_cents'];
        }
        $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['cash_performance_cents'] >= 3000000));
        foreach ($rows as &$row) { $row['cash_performance'] = $this->money((int)$row['cash_performance_cents']); unset($row['cash_performance_cents']); } unset($row);
        usort($rows, static fn(array $left, array $right): int => strcmp($left['company_name'] . $left['store_name'] . $left['member_name'], $right['company_name'] . $right['store_name'] . $right['member_name']));
        return $this->result((string)$definition['name'], [
            $this->column('company_name', '分公司', '按门店所属组织链上配置为“分公司”的统计维度读取；未配置时显示“未配置分公司”。', false, true, 130),
            $this->column('store_name', '门店', '取成功收款业务发生时保存的门店名称快照。', false, true, 150),
            $this->column('member_name', '会员', '取成功收款业务关联的会员名称快照；历史名称缺失时以会员编号定位。', false, true, 130),
            $this->column('cash_performance', '现金业绩', '筛选期间内该会员除“昊美”及其下级分类外的成功收款，按销售明细和卡内项目分摊汇总；仅保留累计现金业绩不少于30,000元的会员；欠款补交和退款按各自成功日期正负计入。', true, false, 130, $this->drill('')),
        ], array_values($rows), $range, $input, []);
    }

    private function postSaleReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        return $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, [
            $this->monthlySpec('store_count', '店面数', '当前权限范围内有效门店数量。', false, ['店面数']),
            $this->monthlySpec('active_members', '活客数', '当月完成有效服务的不同会员数。', false, ['活客数']),
            $this->monthlySpec('post_sale_visits', '售后人次', '当月完成有效服务的项目次数。', false, ['售后人次']),
            $this->monthlySpec('deal_visits', '成交人次', '当月购买生美卡项商品名称的会员项目人次，不以同一会员去重。', false, ['成交人次']),
            $this->monthlySpec('visit_deal_rate', '人次成交率', '成交人次除以售后人次；分母为零显示“-”。', false, ['人次成交率']),
            $this->monthlySpec('deal_people', '成交人头', '当月购买生美卡项商品名称的不同会员数。', false, ['成交人头']),
            $this->monthlySpec('people_deal_rate', '人头成交率', '成交人头除以售后人次；完全按原表公式，分母为零显示“-”。', false, ['人头成交率']),
            $this->monthlySpec('post_sale_amount', '售后业绩', '当月生美卡项成功收款，按销售明细和卡内项目分摊后的净现金业绩。', false, ['售后业绩']),
            $this->monthlySpec('visit_unit_output', '人次单产', '售后业绩除以成交人次；分母为零显示“-”。', false, ['人次单产']),
            $this->monthlySpec('store_average_amount', '店均业绩', '售后业绩除以店面数；分母为零显示“-”。', false, ['店均业绩']),
        ], ['post_sale' => true]);
    }

    /** A field matrix is declared per report; this only evaluates the common facts. */
    private function monthlySpec(string $key, string $label, string $explanation, bool $manual, array $headerPath): array
    {
        return compact('key', 'label', 'explanation', 'manual', 'headerPath');
    }

    private function monthlyProjection(string $title, string $report, array $stores, array $range, array $input, array $specs, array $rule): array
    {
        $rows = [];
        $requiresCashHistory = in_array($report, [
            'operations_pre_sale_bdegh', 'operations_referral_beautician_pre_sale',
            'marketing_acquisition_pre_sale', 'marketing_referral_pre_sale',
        ], true);
        $cashHistory = $requiresCashHistory
            ? $this->cashRows($stores, ['end' => $range['end']], []) : [];
        $serviceHistory = in_array($report, ['operations_customer_status_bdegh', 'marketing_customer_status'], true)
            ? $this->serviceRows($stores, ['end' => $range['end']], []) : [];
        foreach ($this->months($range) as $month) {
            $monthRange = ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01'))];
            $allCash = $this->cashRows($stores, $monthRange, []);
            $cash = $this->filterSourceFacts($allCash, $rule);
            $services = $this->serviceRows($stores, $monthRange, []);
            $previousMonth = date('Y-m', strtotime($month . '-01 -1 year'));
            $previousCash = $this->cashRows($stores, ['start' => $previousMonth . '-01', 'end' => date('Y-m-t', strtotime($previousMonth . '-01'))], []);
            $row = ['month' => $month];
            $context = compact('report', 'stores', 'monthRange', 'allCash', 'cash', 'services', 'previousCash', 'rule', 'cashHistory', 'serviceHistory');
            foreach ($specs as $spec) {
                $key = (string)$spec['key'];
                if (!empty($spec['manual'])) {
                    $manual = $this->manualValue($report, $this->manualSubject($report, $month, 0), $key);
                    $row[$key] = !$this->hasManualValue($manual) ? '-' : (str_contains($key, 'amount') || str_contains($key, 'performance') ? $this->money((int)$manual['value']) : (int)$manual['value']);
                    $row[$key . '_version'] = $manual === null ? 0 : (int)$manual['version'];
                    continue;
                }
                $row[$key] = $this->monthlyValue($key, $row, $context);
            }
            $rows[] = $row;
        }
        $this->attachManualSubjects($rows, $report);
        $columns = [$this->column('month', '月份', '按所选年份展开1月至12月；每月一行，切换年份后读取该年的已保存手动值。', false, true, 100)];
        $columns[0]['header_path'] = ['月份'];
        foreach ($specs as $spec) {
            $key = (string)$spec['key']; $label = (string)$spec['label']; $manual = !empty($spec['manual']);
            $column = $manual ? $this->manualColumn($key, $label, (string)$spec['explanation']) : $this->column($key, $label, (string)$spec['explanation'], $this->isMonthlySummable($key, $label), false, 120);
            $column['header_path'] = (array)$spec['headerPath'];
            $column['value_type'] = $this->monthlyValueType($key, $label);
            $columns[] = $column;
        }
        return $this->result($title, $columns, $rows, $range, $input, ['year' => true]);
    }

    private function monthlyValue(string $key, array $row, array $context)
    {
        $cash = (array)$context['cash']; $allCash = (array)$context['allCash']; $services = (array)$context['services'];
        $report = (string)($context['report'] ?? '');
        // A 老客首护由成功业务发生时冻结的来源代码 A 识别，不能用名称中的
        // 任意字母 A 做模糊匹配，也不能由当前会员资料反推历史来源。
        $healthSourceServices = $report === 'operations_health_data' ? $this->filterSourceFacts($services, ['source_codes' => ['A']]) : $services;
        $healthSourceCash = $report === 'operations_health_data' ? $this->filterSourceFacts($allCash, ['source_codes' => ['A']]) : $allCash;
        $amount = $this->factAmount($allCash); $sourceAmount = $this->factAmount($cash); $active = $this->memberCount($healthSourceServices);
        $beautyCash = $this->categoryAmount($allCash, '生美'); $beautyServices = $this->categoryServices($services, '生美');
        $amountByCategory = function (string $category) use ($allCash): int { return $this->categoryAmount($allCash, $category); };
        if ($key === 'store_count') return count((array)$context['stores']);
        if (in_array($key, ['all_current_amount'], true)) return $this->money($amount);
        if (in_array($key, ['all_previous_amount'], true)) return $this->money($this->factAmount((array)$context['previousCash']));
        if ($key === 'all_difference') return $this->money($amount - $this->cents($row['all_previous_amount'] ?? '-'));
        if ($key === 'all_completion_rate') return $this->ratio($amount, $this->cents($row['full_target_amount'] ?? '-'));
        if ($key === 'performance_completion_rate') return $this->ratio($amount, $this->cents($row['performance_target'] ?? '-'));
        if (preg_match('/^(six_dimension|private|haomei|beauty)_(current|previous)_amount$/', $key, $m)) {
            $category = ['six_dimension' => '六维', 'private' => '花园', 'haomei' => '昊美', 'beauty' => '生美'][$m[1]];
            return $this->money($m[2] === 'current' ? $amountByCategory($category) : $this->categoryAmount((array)$context['previousCash'], $category));
        }
        if (preg_match('/^(six_dimension|private|haomei|beauty)_difference$/', $key, $m)) return $this->money($this->cents($row[$m[1] . '_current_amount'] ?? '-') - $this->cents($row[$m[1] . '_previous_amount'] ?? '-'));
        if ($key === 'active_members') return $active;
        if ($key === 'post_sale_visits') return $this->serviceCount($healthSourceServices);
        if ($key === 'one_to_two_visits') return $this->visitBandMembers($services, 1, 2);
        if ($key === 'one_to_two_visit_share') return $this->ratio((int)($row['one_to_two_visits'] ?? 0), $active);
        if ($key === 'three_plus_visits') return $this->visitBandMembers($services, 3, null);
        if ($key === 'three_plus_visit_share') return $this->ratio((int)($row['three_plus_visits'] ?? 0), $active);
        if ($key === 'regular_customers') return $this->visitBandMembers($services, 1, 1);
        if ($key === 'inactive_customers') return $this->inactiveMemberCount((array)$context['serviceHistory'], (string)$context['monthRange']['end']);
        if ($key === 'active_rate') return $this->ratio($active, $active + (int)($row['inactive_customers'] ?? 0));
        if ($key === 'consuming_members') return $this->memberCount($healthSourceCash);
        if ($key === 'consumption_rate') return $this->ratio((int)($row['consuming_members'] ?? 0), $active);
        if ($key === 'store_average_visits') return $this->moneyRatio($this->serviceCount($healthSourceServices) * 100, count((array)$context['stores']));
        if ($key === 'monthly_service_visits') return $this->moneyRatio($this->serviceCount($healthSourceServices) * 100, 12);
        if ($key === 'beauty_service_count') return $this->serviceCount($beautyServices);
        if ($key === 'consumption_amount') return $this->money($this->serviceConsumptionAmount($beautyServices));
        if ($key === 'consumption_share') return $this->ratio($this->cents($row['consumption_amount'] ?? '-'), $amount);
        if ($key === 'staff_difference') return ($row['required_staff'] ?? '-') === '-' || ($row['actual_staff'] ?? '-') === '-' ? '-' : (int)$row['required_staff'] - (int)$row['actual_staff'];
        if ($key === 'beautician_monthly_projects') return $this->moneyRatio($this->serviceCount($beautyServices) * 100, (int)($row['actual_staff'] ?? 0));
        if ($key === 'beautician_monthly_visits') return $this->moneyRatio($this->serviceCount($services) * 100, (int)($row['actual_staff'] ?? 0));
        if ($key === 'store_average_amount') return $this->moneyRatio($amount, count((array)$context['stores']));
        if ($key === 'staff_unit_output') return $this->moneyRatio($this->cents($row['store_average_amount'] ?? '-'), (int)($row['actual_staff'] ?? 0));
        if ($key === 'staff_consumption_output') return $this->moneyRatio($this->cents($row['consumption_amount'] ?? '-'), (int)($row['actual_staff'] ?? 0));
        if ($key === 'weak_store_count' || $key === 'strong_store_count') return $this->storeAmountBand($allCash, $key === 'weak_store_count' ? 10000000 : 20000000, $key === 'weak_store_count');
        if ($key === 'deal_visits') return $this->factLineCount($beautyCash === 0 ? [] : $this->categoryFacts($allCash, '生美'));
        if ($key === 'deal_people') return !empty($context['rule']['post_sale']) ? $this->memberCount($this->categoryFacts($allCash, '生美')) : $this->memberCount($cash);
        if ($key === 'post_sale_amount') return $this->money($beautyCash);
        if ($key === 'visit_deal_rate') return $this->ratio((int)($row['deal_visits'] ?? 0), $this->serviceCount($services));
        if ($key === 'people_deal_rate') return $this->ratio((int)($row['deal_people'] ?? 0), $this->serviceCount($services));
        if ($key === 'visit_unit_output') return $this->moneyRatio($beautyCash, (int)($row['deal_visits'] ?? 0));
        if ($key === 'experience_actual') return $this->experienceMemberCount($services);
        if (in_array($key, ['new_customer_actual', 'new_customer_amount_actual', 'deal_people', 'deal_amount', 'first_year_amount', 'transition_people', 'transition_amount'], true)) {
            $history = $this->filterSourceFacts((array)$context['cashHistory'], (array)$context['rule']);
            $metrics = $this->preSaleMetrics($history, (array)$context['monthRange'], $key === 'deal_people' || $key === 'deal_amount');
            if ($key === 'new_customer_actual' || $key === 'deal_people') return (int)$metrics['first_people'];
            if ($key === 'new_customer_amount_actual' || $key === 'deal_amount') return $this->money((int)$metrics['first_amount']);
            if ($key === 'first_year_amount') return $this->money((int)$metrics['first_year_amount']);
            if ($key === 'transition_people') return (int)$metrics['transition_people'];
            return $this->money((int)$metrics['transition_amount']);
        }
        if ($key === 'experience_completion_rate') return $this->ratio((int)($row['experience_actual'] ?? 0), (int)($row['experience_target'] ?? 0));
        if ($key === 'new_customer_completion_rate') return $this->ratio((int)($row['new_customer_actual'] ?? 0), (int)($row['new_customer_target'] ?? 0));
        if ($key === 'new_customer_amount_completion_rate') return $this->ratio($this->cents($row['new_customer_amount_actual'] ?? '-'), $this->cents($row['new_customer_amount_target'] ?? '-'));
        if ($key === 'new_customer_deal_rate') return $this->ratio((int)($row['new_customer_actual'] ?? 0), (int)($row['experience_actual'] ?? 0));
        if ($key === 'new_customer_unit_output') return $this->moneyRatio($this->cents($row['new_customer_amount_actual'] ?? '-'), (int)($row['new_customer_actual'] ?? 0));
        if ($key === 'experience_people') return $row[$key] ?? '-';
        if ($key === 'deal_rate') return $this->ratio((int)($row['deal_people'] ?? 0), (int)($row['experience_people'] ?? 0));
        if ($key === 'deal_unit_output') return $this->moneyRatio($this->cents($row['deal_amount'] ?? '-'), (int)($row['deal_people'] ?? 0));
        if ($key === 'transition_rate') return $this->ratio((int)($row['transition_people'] ?? 0), (int)($row['new_customer_actual'] ?? $row['deal_people'] ?? 0));
        if ($key === 'transition_unit_output') return $this->moneyRatio($this->cents($row['transition_amount'] ?? '-'), (int)($row['transition_people'] ?? 0));
        return '-';
    }

    /**
     * Customer analysis read-only access to the same signed cash allocation
     * facts used by phase-four reports.  Keeping the allocator here prevents
     * customer pages from rebuilding a second payment/reversal join.
     */
    public function customerCashRows(array $stores, array $range, array $scope = []): array
    {
        $this->applyCustomerParticipantScope($scope);
        return $this->cashRows($stores, $range, []);
    }

    /** Read-only service facts for customer analysis (same source as health/status reports). */
    public function customerServiceRows(array $stores, array $range, array $scope = []): array
    {
        $this->applyCustomerParticipantScope($scope);
        return $this->serviceRows($stores, $range, []);
    }

    /** Apply the authenticated report scope when customer analytics calls the
     * read-only fact helpers directly instead of going through query(). */
    private function applyCustomerParticipantScope(array $scope): void
    {
        if ((string)($scope['mode'] ?? '') !== 'self_participant') return;
        $this->participantEmployeeId = max(0, (int)($scope['employee_id'] ?? 0));
        if ($this->participantEmployeeId <= 0) {
            throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
        }
    }

    private function cashRows(array $stores, array $range, array $categoryIds): array
    {
        $base = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('p')
            ->leftJoin('cashier_v3_payment_sale_allocation_fact original', 'original.tenant_id=p.tenant_id AND original.allocation_fact_id=p.reversal_of')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.fact_id=COALESCE(original.sale_fact_id,p.sale_fact_id)')
            ->where('p.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('p.store_id', $stores)
            ->where('p.status', 'effective');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($base, 's.tenant_id', 's.order_id');
        if (isset($range['start']) && (string)$range['start'] !== '') $base->whereBetween('p.business_date', [$range['start'], $range['end']]);
        else $base->where('p.business_date', '<=', (string)$range['end']);
        if ($this->participantEmployeeId > 0) (new StoreReportParticipantScopeServices())->applyOrder($base, 'p.order_id', $this->participantEmployeeId);

        // Non-card sales have one frozen sale-dimension row. Cards deliberately
        // do not use that outer category: their payment is allocated to each
        // frozen contained-project row below.
        $direct = clone $base;
        $direct->join('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
            ->where('s.source_type', '<>', 'card');
        if ($categoryIds !== []) $direct->whereIn('d.category_id_snapshot', $categoryIds);
        $rows = $direct->fieldRaw('p.id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,p.amount_cents,p.sale_amount_cents,p.organization_id,s.organization_path_snapshot,s.business_source_primary_id,MAX(s.store_name_snapshot) store_name,MAX(s.member_name_snapshot) member_name,MAX(s.business_source_label_snapshot) business_source_label,d.item_id,d.item_name_snapshot item_name,d.category_id_snapshot category_id,d.category_path_snapshot category_path,s.quantity')
            ->group('p.id')->select()->toArray();

        $card = clone $base;
        $payments = $card->where('s.source_type', 'card')->fieldRaw('p.id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,p.amount_cents,p.organization_id,s.organization_path_snapshot,s.business_source_primary_id,MAX(s.store_name_snapshot) store_name,MAX(s.member_name_snapshot) member_name,MAX(s.business_source_label_snapshot) business_source_label,s.fact_id sale_fact_id')
            ->group('p.id')->select()->toArray();
        if ($payments === []) return $rows;
        $saleFactIds = array_values(array_unique(array_filter(array_column($payments, 'sale_fact_id'))));
        if ($saleFactIds === []) return $rows;
        $bySale = [];
        foreach (Db::name('cashier_v3_card_sale_item_allocation_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sale_fact_id', $saleFactIds)->where('status', 'effective')
            ->field('allocation_fact_id,sale_fact_id,component_product_id,item_name_snapshot,category_id_snapshot,category_path_snapshot,component_count,sale_amount_cents,configured_amount_cents')->select()->toArray() as $item) {
            $bySale[(string)$item['sale_fact_id']][] = $item;
        }
        $allowed = array_fill_keys(array_map('intval', $categoryIds), true);
        foreach ($payments as $payment) {
            $items = (array)($bySale[(string)$payment['sale_fact_id']] ?? []);
            if ($items === []) continue;
            $allocated = $this->allocateSigned((int)$payment['amount_cents'], $items);
            foreach ($items as $item) {
                if ($allowed !== [] && !isset($allowed[(int)$item['category_id_snapshot']])) continue;
                $rows[] = [
                    'id' => (int)$payment['id'], 'store_id' => (int)$payment['store_id'], 'member_id' => (int)$payment['member_id'],
                    'order_id' => (string)$payment['order_id'], 'source_line_id' => (string)$payment['source_line_id'], 'business_date' => (string)$payment['business_date'],
                    'amount_cents' => (int)($allocated[(string)$item['allocation_fact_id']] ?? 0), 'sale_amount_cents' => (int)($item['sale_amount_cents'] ?? 0), 'organization_id' => (string)$payment['organization_id'], 'business_source_primary_id' => (int)($payment['business_source_primary_id'] ?? 0),
                    'organization_path_snapshot' => (string)$payment['organization_path_snapshot'], 'store_name' => (string)$payment['store_name'], 'member_name' => (string)$payment['member_name'], 'business_source_label' => (string)$payment['business_source_label'],
                    'item_id' => (string)$item['component_product_id'], 'item_name' => (string)$item['item_name_snapshot'],
                    'category_id' => (int)$item['category_id_snapshot'], 'category_path' => (string)$item['category_path_snapshot'], 'quantity' => (int)$item['component_count'],
                ];
            }
        }
        return $rows;
    }

    private function serviceRows(array $stores, array $range, array $categoryIds): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sv.store_id', $stores)->where('sv.service_status', 'completed');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        if (isset($range['start']) && (string)$range['start'] !== '') $query->whereBetween('sv.business_date', [$range['start'], $range['end']]);
        else $query->where('sv.business_date', '<=', (string)$range['end']);
        if ($categoryIds !== []) $query->whereIn('sv.project_category_id_snapshot', $categoryIds);
        if ($this->participantEmployeeId > 0) (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        return $query->fieldRaw("sv.service_fact_id,sv.store_id,sv.member_id,sv.business_date,sv.organization_id,sv.organization_path_snapshot,sv.store_name_snapshot store_name,sv.project_id,sv.project_name_snapshot,sv.project_category_path_snapshot category_path,sv.is_experience,sv.source_line_id,sv.quantity,(SELECT MAX(sf.business_source_label_snapshot) FROM eb_cashier_v3_sale_fact sf WHERE sf.tenant_id=sv.tenant_id AND sf.source_line_id=sv.source_line_id AND sf.fact_direction='forward' AND sf.status='effective') business_source_label,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.tenant_id=sv.tenant_id AND pf.source_line_id=sv.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective') consumption_cents")->select()->toArray();
    }

    /**
     * Current outstanding sales debt, apportioned from the authoritative debt
     * record to the frozen sale/category dimensions. A payment fact is not
     * available until repayment succeeds, so this remains a separate balance
     * projection and never contaminates the received-cash facts.
     */
    private function outstandingDebtRows(array $stores, array $categoryIds): array
    {
        // Start with the V3 authority mapping: its tenant/store index is the
        // report scope and avoids scanning historical legacy debt rows.
        $salesQuery = Db::name('cashier_v3_debt_authority')->alias('a')
            ->join('store_debt d', 'd.id=a.debt_id')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=a.tenant_id AND s.order_id=a.sales_order_id')
            ->where('a.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('a.store_id', $stores)->where('d.status', 0)
            ->where('s.fact_direction', 'forward')->where('s.status', 'effective')
            ->where('s.debt_amount_cents', '>', 0);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($salesQuery, 's.tenant_id', 's.order_id');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyOrder($salesQuery, 's.order_id', $this->participantEmployeeId);
        }
        $sales = $salesQuery->fieldRaw('d.id debt_id,d.total_debt,d.repaid_debt,s.fact_id,s.source_type,s.store_id,s.member_id,s.order_id,s.source_line_id,s.business_date,s.organization_id,s.organization_path_snapshot,s.store_name_snapshot store_name,s.member_name_snapshot member_name,s.business_source_label_snapshot business_source_label,s.debt_amount_cents')
            ->select()->toArray();
        if ($sales === []) return [];

        $byDebt = [];
        foreach ($sales as $sale) $byDebt[(int)$sale['debt_id']][] = $sale;
        $lineBalances = $this->outstandingDebtBySaleLine(array_keys($byDebt));
        $allocatedSales = [];
        foreach ($byDebt as $debtId => $debtSales) {
            $open = max(0, $this->cents($debtSales[0]['total_debt'] ?? '0') - $this->cents($debtSales[0]['repaid_debt'] ?? '0'));
            if ($open === 0) continue;
            $exact = []; $exactTotal = 0;
            foreach ($debtSales as $sale) {
                $lineKey = $debtId . '|' . (string)($sale['source_line_id'] ?? '');
                if (!array_key_exists($lineKey, $lineBalances)) continue;
                $amount = (int)$lineBalances[$lineKey];
                $exact[(string)$sale['fact_id']] = $amount;
                $exactTotal += $amount;
            }
            // Since the repayment writer updates store_debt_item per line in
            // the same transaction as its header, an exact complete mapping
            // is authoritative. Older V3 debts had no frozen line mapping and
            // retain the historic proportional compatibility path below.
            if (count($exact) === count($debtSales) && $exactTotal === $open) {
                foreach ($debtSales as $sale) {
                    $amount = (int)($exact[(string)$sale['fact_id']] ?? 0);
                    if ($amount > 0) $allocatedSales[(string)$sale['fact_id']] = ['sale' => $sale, 'amount_cents' => $amount];
                }
                continue;
            }
            $weights = [];
            foreach ($debtSales as $sale) $weights[] = ['fact_id' => (string)$sale['fact_id'], 'sale_amount_cents' => (int)$sale['debt_amount_cents'], 'debt_amount_cents' => 0];
            $allocation = StoreReportPartnerCategorySnapshotServices::allocateAmountBySaleFact($open, $weights);
            foreach ($debtSales as $sale) {
                $amount = (int)($allocation[(string)$sale['fact_id']] ?? 0);
                if ($amount > 0) $allocatedSales[(string)$sale['fact_id']] = ['sale' => $sale, 'amount_cents' => $amount];
            }
        }
        if ($allocatedSales === []) return [];

        $saleIds = array_keys($allocatedSales);
        $allowed = array_fill_keys(array_map('intval', $categoryIds), true);
        $result = [];
        $directDimensions = Db::name('cashier_v3_report_sale_dimension_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sale_fact_id', $saleIds)
            ->field('sale_fact_id,item_id,item_name_snapshot,category_id_snapshot,category_path_snapshot')->select()->toArray();
        foreach ($directDimensions as $dimension) {
            $sale = (array)($allocatedSales[(string)$dimension['sale_fact_id']] ?? []);
            if ($sale === [] || (string)($sale['sale']['source_type'] ?? '') === 'card') continue;
            if (!isset($allowed[(int)$dimension['category_id_snapshot']])) continue;
            $row = (array)$sale['sale'];
            $row['amount_cents'] = (int)$sale['amount_cents'];
            $row['item_id'] = (string)$dimension['item_id'];
            $row['item_name'] = (string)$dimension['item_name_snapshot'];
            $row['category_id'] = (int)$dimension['category_id_snapshot'];
            $row['category_path'] = (string)$dimension['category_path_snapshot'];
            $result[] = $row;
        }

        $cardSales = [];
        foreach ($allocatedSales as $saleId => $sale) if ((string)($sale['sale']['source_type'] ?? '') === 'card') $cardSales[(string)$sale['sale']['fact_id']] = $sale;
        if ($cardSales === []) return $result;
        $itemsBySale = [];
        foreach (Db::name('cashier_v3_card_sale_item_allocation_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sale_fact_id', array_keys($cardSales))->where('status', 'effective')
            ->field('allocation_fact_id,sale_fact_id,component_product_id,item_name_snapshot,category_id_snapshot,category_path_snapshot,component_count,sale_amount_cents,configured_amount_cents')->select()->toArray() as $item) {
            $itemsBySale[(string)$item['sale_fact_id']][] = $item;
        }
        foreach ($cardSales as $saleFactId => $sale) {
            $items = (array)($itemsBySale[$saleFactId] ?? []);
            if ($items === []) continue;
            $allocated = $this->allocateSigned((int)$sale['amount_cents'], $items);
            foreach ($items as $item) {
                if (!isset($allowed[(int)$item['category_id_snapshot']])) continue;
                $amount = (int)($allocated[(string)$item['allocation_fact_id']] ?? 0);
                if ($amount === 0) continue;
                $row = (array)$sale['sale'];
                $row['amount_cents'] = $amount;
                $row['item_id'] = (string)$item['component_product_id'];
                $row['item_name'] = (string)$item['item_name_snapshot'];
                $row['category_id'] = (int)$item['category_id_snapshot'];
                $row['category_path'] = (string)$item['category_path_snapshot'];
                $result[] = $row;
            }
        }
        return $result;
    }

    /** @return array<string,int> debt_id|order_line_id => current outstanding cents */
    private function outstandingDebtBySaleLine(array $debtIds): array
    {
        $debtIds = array_values(array_unique(array_filter(array_map('intval', $debtIds))));
        if ($debtIds === []) return [];
        $result = [];
        foreach (Db::name('store_debt_item')->alias('item')
            ->join('cashier_v3_debt_item_personnel_authority line', 'line.debt_item_id=item.id AND line.debt_id=item.debt_id')
            ->where('line.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('line.debt_id', $debtIds)
            ->field('line.debt_id,line.order_line_id,item.debt_amount,item.repaid_debt')->select()->toArray() as $row) {
            $lineId = trim((string)($row['order_line_id'] ?? ''));
            if ($lineId === '') continue;
            $remaining = $this->cents($row['debt_amount'] ?? '0') - $this->cents($row['repaid_debt'] ?? '0');
            if ($remaining < 0) continue;
            $result[(int)$row['debt_id'] . '|' . $lineId] = $remaining;
        }
        return $result;
    }

    private function preSaleRule(string $report): array
    {
        return [
            'operations_pre_sale_bdegh' => ['tokens' => ['BDEGH']],
            'operations_referral_beautician_pre_sale' => ['tokens' => ['CF']],
            'marketing_acquisition_pre_sale' => ['tokens' => ['B导购引流']],
            'marketing_referral_pre_sale' => ['tokens' => ['C', 'F']],
        ][$report] ?? [];
    }
    private function filterSourceFacts(array $facts, array $rule): array
    {
        $sourceCodes = array_values(array_filter(array_map(static fn($code): string => strtoupper(trim((string)$code)), (array)($rule['source_codes'] ?? []))));
        if ($sourceCodes !== []) {
            return array_values(array_filter($facts, static function (array $fact) use ($sourceCodes): bool {
                $source = trim((string)($fact['business_source_label'] ?? ''));
                return $source !== '' && in_array(strtoupper(substr($source, 0, 1)), $sourceCodes, true);
            }));
        }
        $tokens = (array)($rule['tokens'] ?? []); if ($tokens === []) return $facts;
        return array_values(array_filter($facts, static function (array $fact) use ($tokens): bool {
            $source = (string)($fact['business_source_label'] ?? '');
            foreach ($tokens as $token) if ($token !== '' && mb_stripos($source, (string)$token) !== false) return true;
            return false;
        }));
    }
    private function factAmount(array $facts): int { $total = 0; foreach ($facts as $fact) $total += (int)($fact['amount_cents'] ?? 0); return $total; }
    private function memberCount(array $facts): int { $members = []; foreach ($facts as $fact) if ((int)($fact['member_id'] ?? 0) > 0) $members[(string)$fact['member_id']] = true; return count($members); }
    private function factLineCount(array $facts): int { $lines = []; foreach ($facts as $fact) { $key = (string)($fact['source_line_id'] ?? $fact['id'] ?? ''); if ($key !== '') $lines[$key] = true; } return count($lines); }
    private function serviceCount(array $services): int { $count = 0; foreach ($services as $service) $count += max(0, (int)($service['quantity'] ?? 0)); return $count; }
    private function experienceMemberCount(array $services): int { $members = []; foreach ($services as $service) if ((int)($service['is_experience'] ?? 0) === 1 && (int)($service['member_id'] ?? 0) > 0) $members[(string)$service['member_id']] = true; return count($members); }
    private function visitBandMembers(array $services, int $min, ?int $max): int { $counts=[]; foreach($services as $service){$member=(int)($service['member_id']??0);if($member>0)$counts[$member]=(int)($counts[$member]??0)+max(0,(int)($service['quantity']??0));}$matched=0;foreach($counts as $count)if($count>=$min&&($max===null||$count<=$max))$matched++;return $matched; }
    /**
     * 一次付款分摊会展开为多条商品行；这里按会员、销售明细和业务日期还原为
     * 一次购买事件，避免多支付/卡内多项目把首次或第二次购买重复计数。
     */
    private function preSaleMetrics(array $facts, array $monthRange, bool $minimumFirstAmount): array
    {
        $events = [];
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            $date = (string)($fact['business_date'] ?? '');
            if ($memberId <= 0 || $date === '' || $date > (string)$monthRange['end']) continue;
            $line = trim((string)($fact['source_line_id'] ?? ''));
            $eventKey = $memberId . '|' . ($line !== '' ? $line : (string)($fact['id'] ?? '')) . '|' . $date;
            if (!isset($events[$memberId][$eventKey])) $events[$memberId][$eventKey] = ['date' => $date, 'amount_cents' => 0];
            $events[$memberId][$eventKey]['amount_cents'] += (int)($fact['amount_cents'] ?? 0);
        }
        $firstPeople = 0; $firstAmount = 0; $transitionPeople = 0; $transitionAmount = 0; $firstYearAmount = 0;
        $year = substr((string)$monthRange['start'], 0, 4);
        foreach ($events as $memberEvents) {
            $positive = array_values(array_filter($memberEvents, static fn(array $event): bool => (int)$event['amount_cents'] > 0));
            usort($positive, static fn(array $left, array $right): int => strcmp((string)$left['date'], (string)$right['date']));
            if ($positive === []) continue;
            $first = $positive[0];
            $firstWithinMonth = (string)$first['date'] >= (string)$monthRange['start'] && (string)$first['date'] <= (string)$monthRange['end'];
            if ($firstWithinMonth && (!$minimumFirstAmount || (int)$first['amount_cents'] >= 50000)) {
                $firstPeople++;
                $firstAmount += (int)$first['amount_cents'];
            }
            if (isset($positive[1]) && (string)$positive[1]['date'] >= (string)$monthRange['start'] && (string)$positive[1]['date'] <= (string)$monthRange['end']) {
                $transitionPeople++;
                $transitionAmount += (int)$positive[1]['amount_cents'];
            }
            if (substr((string)$first['date'], 0, 4) === $year) {
                foreach ($memberEvents as $event) {
                    if ((string)$event['date'] >= (string)$first['date'] && (string)$event['date'] <= (string)$monthRange['end']) $firstYearAmount += (int)$event['amount_cents'];
                }
            }
        }
        return compact('firstPeople', 'firstAmount', 'transitionPeople', 'transitionAmount', 'firstYearAmount') + [
            'first_people' => $firstPeople, 'first_amount' => $firstAmount,
            'transition_people' => $transitionPeople, 'transition_amount' => $transitionAmount,
            'first_year_amount' => $firstYearAmount,
        ];
    }
    private function inactiveMemberCount(array $services, string $monthEnd): int
    {
        $cutoff = date('Y-m-d', strtotime($monthEnd . ' -90 days'));
        $members = [];
        foreach ($services as $service) {
            $memberId = (int)($service['member_id'] ?? 0);
            if ($memberId > 0 && (string)($service['business_date'] ?? '') < $cutoff) $members[$memberId] = true;
        }
        return count($members);
    }
    private function categoryFacts(array $facts, string $category): array { return array_values(array_filter($facts, static function (array $fact) use ($category): bool { return preg_match('/^' . preg_quote($category, '/') . '(?:\\s*\\/|$)/u', trim((string)($fact['category_path'] ?? ''))) === 1; })); }
    /** 自营/合作由发生时冻结的商品分类路径判断，不能由现时商品名称或人员档案反推。 */
    private function teamFacts(array $facts, bool $partner): array
    {
        return array_values(array_filter($facts, static function (array $fact) use ($partner): bool {
            $isPartner = str_contains((string)($fact['category_path'] ?? ''), '合作');
            return $isPartner === $partner;
        }));
    }
    private function categoryAmount(array $facts, string $category): int { return $this->factAmount($this->categoryFacts($facts, $category)); }
    private function categoryServices(array $services, string $category): array { return array_values(array_filter($services, static function (array $service) use ($category): bool { return preg_match('/^' . preg_quote($category, '/') . '(?:\\s*\\/|$)/u', trim((string)($service['category_path'] ?? ''))) === 1; })); }
    private function serviceConsumptionAmount(array $services): int { $amount = 0; foreach ($services as $service) $amount += (int)($service['consumption_cents'] ?? 0); return $amount; }
    private function storeAmountBand(array $facts, int $thresholdCents, bool $lessThan): int { $totals=[];foreach($facts as $fact)$totals[(int)($fact['store_id']??0)]=(int)($totals[(int)($fact['store_id']??0)]??0)+(int)($fact['amount_cents']??0);$count=0;foreach($totals as $total)if($lessThan?$total<$thresholdCents:$total>$thresholdCents)$count++;return $count; }
    private function isRatioLabel(string $label): bool { return str_contains($label, '率') || str_contains($label, '占比') || str_contains($label, '单产') || str_contains($label, '单价') || str_contains($label, '差值'); }
    private function monthlyValueType(string $key, string $label): string { return str_contains($key, 'amount') || str_contains($key, 'performance') || str_contains($label, '业绩') || str_contains($label, '产值') || str_contains($label, '消耗') || str_contains($label, '单产') ? 'money' : 'integer'; }
    private function isMonthlySummable(string $key, string $label): bool { return !$this->isRatioLabel($label) && !str_contains($label, '店均') && !str_contains($label, '人均') && !str_contains($label, '月均'); }

    private function cashTotalsByMonth(array $stores, array $range, array $categoryIds, bool $emptyCategoryMeansNone = false): array
    {
        // An empty user filter intentionally means all categories. Dynamic report
        // columns instead opt into this guard so an unconfigured category is zero.
        if ($emptyCategoryMeansNone && $categoryIds === []) return [];
        $totals = [];
        foreach ($this->cashRows($stores, $range, $categoryIds) as $row) {
            $month = substr((string)$row['business_date'], 0, 7);
            $totals[$month] = (int)($totals[$month] ?? 0) + (int)$row['amount_cents'];
        }
        return $totals;
    }
    private function allocateSigned(int $amountCents, array $items): array { $sign=$amountCents<0?-1:1; $sales=[]; foreach($items as $item){$weight=abs((int)($item['sale_amount_cents']??0));if($weight===0)$weight=abs((int)($item['configured_amount_cents']??0));if($weight===0)$weight=max(1,(int)($item['component_count']??1));$sales[]=['fact_id'=>(string)$item['allocation_fact_id'],'sale_amount_cents'=>$sign*$weight,'debt_amount_cents'=>0];} return StoreReportPartnerCategorySnapshotServices::allocateAmountBySaleFact($amountCents,$sales); }
    private function categoryIds(string $rootName, array $input): array { $requested = trim((string)($input['category_path'] ?? '')); if ($requested !== '') return $this->descendantsByPath($requested); return $rootName === '' ? [] : $this->descendantsByName($rootName); }
    private function descendantsByName(string $rootName): array { $roots = Db::name('store_product_category')->where('is_show', 1)->where('cate_name', $rootName)->column('id'); return $this->descendants($roots); }
    private function descendantsByPath(string $path): array { $all = Db::name('store_product_category')->where('is_show', 1)->field('id,pid,cate_name')->select()->toArray(); $byId=[]; foreach ($all as $row) $byId[(int)$row['id']]=$row; $roots=[]; foreach ($byId as $id=>$row) { $parts=[]; for ($cursor=$id,$guard=0;$cursor>0&&isset($byId[$cursor])&&$guard++<32;$cursor=(int)$byId[$cursor]['pid']) array_unshift($parts,(string)$byId[$cursor]['cate_name']); if (implode(' / ',$parts)===$path) $roots[]=$id; } return $this->descendants($roots, $all); }
    private function descendants(array $roots, ?array $all = null): array { $all=$all??Db::name('store_product_category')->where('is_show',1)->field('id,pid')->select()->toArray(); $children=[]; foreach($all as $row)$children[(int)$row['pid']][]=(int)$row['id']; $out=[];$queue=array_values(array_unique(array_map('intval',$roots)));while($queue){$id=array_shift($queue);if($id<=0||isset($out[$id]))continue;$out[$id]=true;foreach((array)($children[$id]??[])as$child)$queue[]=$child;}return array_keys($out); }
    private function months(array $range): array { $out=[]; for($cursor=date('Y-m-01',strtotime($range['start']));$cursor<=date('Y-m-01',strtotime($range['end']));$cursor=date('Y-m-01',strtotime($cursor.' +1 month')))$out[]=substr($cursor,0,7);return $out; }
    private function range(array $range,array $input): array { $start=trim((string)($range['start']??$input['start_date']??''));$end=trim((string)($range['end']??$input['end_date']??'')); if($start===''||$end===''){ $year=max(2000,(int)($input['year']??date('Y')));$start=$year.'-01-01';$end=$year.'-12-31'; } if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end)||$start>$end)throw new \InvalidArgumentException('日期范围不正确');return ['start'=>$start,'end'=>$end]; }
    private function storeIds($ids): array { $ids=array_values(array_unique(array_filter(array_map('intval',(array)$ids))));sort($ids);return $ids; }
    private function organization(): StoreUnifiedReportOrganizationDimensionServices { static $service;return $service?:$service=new StoreUnifiedReportOrganizationDimensionServices(); }
    private function manualSubject(string $report,string $month,int $storeId,string $dimension = ''): string{return 'phase4:'.$report.':'.$month.':'.($dimension === '' ? $storeId : $dimension);}
    private function attachManualSubjects(array &$rows, string $report): void { foreach ($rows as &$row) { $month = trim((string)($row['month'] ?? '')); if ($month === '') continue; $row['annotation_subject_type'] = 'phase_four_month'; $row['annotation_subject_key'] = $this->manualSubject($report, $month, 0, trim((string)($row['annotation_dimension_key'] ?? ''))); $row['annotation_store_id'] = 0; } unset($row); }
    private function manualValue(string $report,string $subject,string $field): ?array { $row=Db::name(StoreOperationsReportAnnotationServices::ANNOTATION_TABLE)->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('report_code',$report)->where('subject_type','phase_four_month')->where('subject_key',$subject)->where('field_key',$field)->find();return is_array($row) ? ['value'=>(string)$row['field_value'],'version'=>(int)$row['version']] : null; }
    private function hasManualValue(?array $manual): bool { return $manual !== null && (string)($manual['value'] ?? '') !== ''; }
    private function result(string $title, array $columns, array $records, array $range, array $input, array $filters = []): array
    {
        $page = max(1, (int)($input['page'] ?? 1)); $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $visible = !empty($input['_internal_all']) ? $records : array_slice($records, ($page - 1) * $limit, $limit);
        $summary = []; foreach ($columns as $column) $summary[(string)$column['key']] = '-'; if ($columns) $summary[(string)$columns[0]['key']] = '合计';
        foreach ($columns as $column) {
            if (empty($column['summable'])) continue; $key = (string)$column['key']; $sum = 0;
            foreach ($records as $record) { $value = $record[$key] ?? '-'; $sum += (string)($column['value_type'] ?? 'money') === 'integer' ? (int)$value : $this->cents($value); }
            $summary[$key] = (string)($column['value_type'] ?? 'money') === 'integer' ? $sum : $this->money($sum);
        }
        $groups = []; foreach ($columns as $column) if (($label = trim((string)($column['group_label'] ?? ''))) !== '') $groups[$label][] = (string)$column['key'];
        $columnGroups = []; foreach ($groups as $label => $keys) $columnGroups[] = ['label' => $label, 'column_keys' => $keys, 'colspan' => count($keys), 'rowspan' => 1];
        if ($columnGroups) foreach ($columns as &$column) $column['header_rowspan'] = trim((string)($column['group_label'] ?? '')) === '' ? 2 : 1; unset($column);
        return ['title' => $title, 'columns' => $columns, 'records' => $visible, 'total' => count($records), 'page' => $page, 'page_size' => $limit,
            'summary_row' => $summary, 'column_groups' => $columnGroups,
            'filter_schema' => array_merge([['key' => 'organization_store_scope', 'label' => '组织 / 门店', 'type' => 'organization_store_scope'], ['key' => 'date_range', 'label' => '日期', 'type' => !empty($filters['year']) ? 'year' : 'date_range', 'inclusive' => true, 'single_natural_month' => !empty($filters['single_month'])]], !empty($filters['category']) ? [['key' => 'category_path', 'label' => '商品分类', 'type' => 'category_tree', 'include_descendants' => true]] : [], !empty($filters['unit_price_range']) ? [['key' => 'unit_price_range', 'label' => '项目单价成交区间（元）', 'type' => 'money_range', 'min_key' => 'unit_price_min', 'max_key' => 'unit_price_max']] : []),
            'source_explanations' => array_values(array_map(static fn(array $column): array => ['key' => (string)$column['key'], 'label' => (string)$column['label'], 'source_explanation' => (string)$column['source_explanation']], $columns)),
            'drilldown_keys' => ['start_date', 'end_date', 'store_id', 'category_path', 'company_dimension_id', 'metric_code'],
            'table_layout' => ['fixed' => true, 'sticky_query' => true, 'sticky_header' => true, 'sticky_summary' => true, 'result_scroll' => true], 'metric_version' => self::METRIC_VERSION,
            'data_as_of' => date('Y-m-d H:i:s'), 'aggregation_status' => 'reconciled', 'scope' => ['date_range' => $range]];
    }
    private function column(string $key,string $label,string $explanation,bool $summable=false,bool $fixed=false,int $width=110,array $drilldown=[],string $group=''):array{$column=['key'=>$key,'label'=>$label,'source_explanation'=>$explanation,'summable'=>$summable,'width'=>$width];if($fixed){$column['fixed']='left';$column['fixed_width']=$width;}if($drilldown)$column['drilldown']=$drilldown;if($group!=='')$column['group_label']=$group;return $column;}
    /**
     * Excel 的两行及以上表头由后端完整声明，浏览器不从显示列名猜测分组。
     */
    private function pathColumn(string $key, string $label, string $explanation, array $headerPath, bool $summable = false, bool $fixed = false, int $width = 110, array $drilldown = [], string $group = '', string $valueType = 'money'): array
    {
        $column = $this->column($key, $label, $explanation, $summable, $fixed, $width, $drilldown, $group);
        $column['header_path'] = $headerPath;
        $column['value_type'] = $valueType;
        return $column;
    }
    private function manualColumn(string $key,string $label,string $explanation,string $group=''):array{$column=$this->column($key,$label,$explanation,false,false,120,[],$group);$column['manual_input']=['subject_type'=>'phase_four_month','field_key'=>$key,'value_type'=>(str_contains($key,'amount')||str_contains($key,'performance'))?'integer_cents':'integer'];return $column;}
    private function drill(string $category,array $params=[]):array{return ['report'=>'six_dimension_consumption_refund_detail','params'=>array_merge(['metric_code'=>'phase_four_amount','category_path'=>$category],$params)];}
    private function money(int $cents):string{$negative=$cents<0;$cents=abs($cents);$value=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);return ($negative?'-':'').rtrim(rtrim($value,'0'),'.');}
    private function cents($value):int{$value=trim((string)$value);if($value===''||$value==='-')return 0;$negative=substr($value,0,1)==='-';$parts=explode('.',ltrim($value,'+-'),2);$cents=((int)($parts[0]??0))*100+(int)str_pad(substr((string)($parts[1]??''),0,2),2,'0');return $negative?-abs($cents):$cents;}
    private function ratio(int $num,int $den):string
    {
        if ($den === 0) return '-';
        $text = rtrim(rtrim(number_format(round($num * 10000 / $den) / 100, 2, '.', ''), '0'), '.');
        return ($text === '' ? '0' : $text) . '%';
    }
    private function moneyRatio(int $cents,int $den):string{return $den===0?'-':$this->money((int)round($cents/$den));}
    private function leafCategory(string $path):string{$parts=preg_split('/\s*\/\s*/',$path);return (string)end($parts);}
    private function key(string $name): string
    {
        // Never derive report field keys from Chinese display labels. Several
        // confirmed category labels share a name or would normalize to the
        // same underscores, which silently overwrites columns in a row.
        $keys = [
            '生美' => 'beauty', '六维' => 'six_dimension', '昊美' => 'haomei', '花园' => 'private',
            '自营1' => 'six_self_1', '自营2' => 'six_self_2', '自营3' => 'six_self_3', '自营4' => 'six_self_4',
            '合作1' => 'six_partner_1', '合作2' => 'six_partner_2', '合作3' => 'six_partner_3', '合作4' => 'six_partner_4',
            '昊美m' => 'haomei_m', '昊美n' => 'haomei_n',
            '花园口服' => 'private_oral', '花园卡项' => 'private_card', '花园家居' => 'private_home', '花园私美' => 'private_intimate',
            '口服' => 'beauty_oral', '家居' => 'beauty_home', '面部' => 'beauty_face', '综合' => 'beauty_composite', '身体' => 'beauty_body',
        ];
        if (isset($keys[$name])) return $keys[$name];
        return 'category_' . substr(hash('sha256', $name), 0, 16);
    }
}
