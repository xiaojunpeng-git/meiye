<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\BaseServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\query\metric\MetricDefinitionRegistry;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\target\TargetSchemeServices;
use think\facade\Db;

/**
 * 第四阶段运营中心与产品变现报表。
 *
 * 销售分类金额从成功、可追溯的收款分摊事实读取；已切换的产品变现报表
 * 复用登记的实际业绩分类指标，其他指标保持各自事实口径，避免混算。
 * 卡项在分组前按原分摊事实展开，再按内含商品关联的平台当前分类归组。调用方只能传入认证后裁剪
 * 的门店范围，本服务不会读取客户端的租户或扩大组织范围。
 */
final class StoreUnifiedReportPhaseFourServices extends BaseServices
{
    public const METRIC_VERSION = 'report-phase-four-current-category-v3';

    private const SIX_DIMENSION_ANALYSIS = 'six_dimension_analysis';

    // Annual member consumption excludes this sold product, not its whole category or card components.
    private const ANNUAL_MEMBER_EXCLUDED_PRODUCT_NAME = '昊美A';

    /** @var array<string,array<string,mixed>> */
    private const REPORTS = [
        'operations_pre_sale_bdegh' => ['name' => '售前报表（BDEGH）', 'family' => '运营中心数据', 'kind' => 'pre_sale'],
        // Only the authorized parent report opens this detail; it has no menu entry of its own.
        'operations_pre_sale_bdegh_detail' => ['name' => 'BDEGH收款明细', 'family' => '运营中心数据', 'kind' => 'pre_sale_detail', 'hidden' => true],
        'operations_referral_beautician_pre_sale_detail' => ['name' => 'C/F收款明细', 'family' => '运营中心数据', 'kind' => 'pre_sale_detail', 'hidden' => true],
        'operations_cash_fact_detail' => ['name' => '运营收款事实明细', 'family' => '运营中心数据', 'kind' => 'cash_fact_detail', 'hidden' => true],
        'operations_service_fact_detail' => ['name' => '运营服务事实明细', 'family' => '运营中心数据', 'kind' => 'service_fact_detail', 'hidden' => true],
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

    /** @var array<int,array<string,mixed>>|null Current platform category tree for one report request. */
    private ?array $categoryTreeRows = null;

    /** @var array<string,array<string,array<string,mixed>>>|null Manual cells for the current report and date range. */
    private ?array $manualLookup = null;

    /** @var array{start:string,end:string} */
    private array $manualLookupRange = [];

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
            if (!empty($definition['hidden']) || (!$platform && empty($definition['both_ends']))) continue;
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
        // Cache is confined to one query so a later request can see category edits.
        $this->categoryTreeRows = null;
        $scope = is_array($input['_report_scope'] ?? null) ? $input['_report_scope'] : [];
        if ((string)($scope['mode'] ?? '') === 'none') throw new \InvalidArgumentException('当前账号没有可查看的数据范围');
        $this->participantEmployeeId = (string)($scope['mode'] ?? '') === 'self_participant' ? max(0, (int)($scope['employee_id'] ?? 0)) : 0;
        if ((string)($scope['mode'] ?? '') === 'self_participant' && $this->participantEmployeeId <= 0) throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
        $stores = $this->storeIds($storeIds);
        if ($stores === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        $range = $this->range($range, $input);
        $this->manualLookup = null;
        $this->manualLookupRange = $range;
        $definition = self::REPORTS[$report];
        if ($report === self::SIX_DIMENSION_ANALYSIS) {
            return $this->sixDimensionAnalysisReport($stores, $range, $input, $definition);
        }
        switch ((string)$definition['kind']) {
            case 'pre_sale_detail': return $this->bdeghDetailReport($stores, $range, $input, $report);
            case 'cash_fact_detail': return $this->operationsCashFactDetail($stores, $range, $input);
            case 'service_fact_detail': return $this->operationsServiceFactDetail($stores, $range, $input);
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
     * 分摊事实；卡项已在 cashRows() 按内含项目的平台当前分类展开。
     */
    private function sixDimensionAnalysisReport(array $stores, array $range, array $input, array $definition): array
    {
        $categoryIds = $this->categoryIds('六维', $input);
        $periodCash = $this->cashRows($stores, $range, $categoryIds);
        $historyCash = $this->cashRows($stores, ['end' => $range['end']], $categoryIds);
        // 六维见诊取实际完成的体验服务；人员不计数开关不能过滤门店见诊人数。
        // 金额、核销数量和手工费仍各自读取原有权威事实，不受人数口径反向影响。
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
        // 这里的说明直接展示给门店用户：明确新老客只看六维购买历史、
        // 1,000 元门槛只限制成交列；“历史总欠款”是当前未还余额，不随所选期间重算。
        $columns = [
            $this->pathColumn('company_name', '分公司', '按业务门店所属分公司展示；没有配置分公司时显示“未配置分公司”。', ['分公司'], false, true, 130),
            $this->pathColumn('store_name', '门店', '显示发生六维服务、收款或欠款的门店名称。', ['门店'], false, true, 140),
            $this->pathColumn('new_visit_people', '新客人数', '所选期间在本店完成所选六维分类的体验服务，且截至服务当天在当前可查看范围内还没有该分类付费购买记录的会员，按会员去重计人数。', ['见诊人数', '新客人数'], true, false, 110, [], '见诊人数', 'integer'),
            $this->pathColumn('old_visit_people', '老客人数', '所选期间在本店完成所选六维分类的体验服务，且截至服务当天在当前可查看范围内已有该分类付费购买记录的会员，按会员去重计人数。', ['见诊人数', '老客人数'], true, false, 110, [], '见诊人数', 'integer'),
            $this->pathColumn('total_visit_people', '合计见诊人数', '本店新客见诊人数加老客见诊人数。同一会员如果在所选期间先后符合两种身份，会分别计入两列。', ['合计见诊人数'], true, false, 120, [], '', 'integer'),
            $this->pathColumn('new_deal_people', '新客人数', '在本店所选期间所选六维分类的收款减退款达到1,000元，且在当前可查看范围内首次购买该分类的付费日期属于本期的会员，按会员去重计人数。', ['成交人数', '新客人数'], true, false, 110, [], '成交人数', 'integer'),
            $this->pathColumn('old_deal_people', '老客人数', '在本店所选期间所选六维分类的收款减退款达到1,000元，且在当前可查看范围内首次购买该分类的付费日期早于本期的会员，按会员去重计人数。', ['成交人数', '老客人数'], true, false, 110, [], '成交人数', 'integer'),
            $this->pathColumn('total_deal_people', '合计成交人数', '本店达到1,000元成交条件的新客人数加老客人数；不足1,000元的不计入。', ['合计成交人数'], true, false, 120, [], '', 'integer'),
            $this->pathColumn('new_deal_performance', '新客业绩', '只合计达到1,000元成交条件的新客在本店所选期间的六维实际收款，扣除本期退款；不是只取首次购买金额。', ['成交业绩', '新客业绩'], true, false, 120, $this->drill('六维'), '成交业绩'),
            $this->pathColumn('old_deal_performance', '老客业绩', '只合计达到1,000元成交条件的老客在本店所选期间的六维实际收款，扣除本期退款。', ['成交业绩', '老客业绩'], true, false, 120, $this->drill('六维'), '成交业绩'),
            $this->pathColumn('total_deal_performance', '合计成交业绩', '新客业绩加老客业绩。未达到1,000元成交条件的收款不计入此列，但仍计入实收业绩。', ['合计成交业绩'], true, false, 130, $this->drill('六维')),
            $this->pathColumn('new_deal_rate', '新客成交率', '新客成交人数除以新客见诊人数；新客见诊人数为0时显示“-”。', ['成交率', '新客成交率'], false, false, 120, [], '成交率'),
            $this->pathColumn('old_deal_rate', '老客成交率', '老客成交人数除以老客见诊人数；老客见诊人数为0时显示“-”。', ['成交率', '老客成交率'], false, false, 120, [], '成交率'),
            $this->pathColumn('debt', '历史总欠款', '历史六维销售订单截至当前仍未还清的欠款余额合计，不受所选日期限制；还款成功后余额减少。', ['历史总欠款'], true),
            $this->pathColumn('self_received_performance', '自营实收业绩', '所选期间六维中非合作分类的实际收款，扣除退款；卡项按卡内六维项目计入，不受1,000元成交条件限制。', ['自营实收业绩'], true, false, 130, $this->drill('六维 / 自营')),
            $this->pathColumn('self_to_date_performance', '自营截止今日合计业绩', '当前与本表“自营实收业绩”相同，只统计所选期间，不额外累加之前月份。', ['自营截止今日合计业绩'], true, false, 150, $this->drill('六维 / 自营')),
            $this->pathColumn('new_unit_output', '新客单产', '新客业绩除以新客成交人数；没有新客成交时显示“-”。', ['成交单产', '新客单产'], false, false, 110, [], '成交单产'),
            $this->pathColumn('old_unit_output', '老客单产', '老客业绩除以老客成交人数；没有老客成交时显示“-”。', ['成交单产', '老客单产'], false, false, 110, [], '成交单产'),
            $this->pathColumn('partner_received_performance', '合作六维实收业绩', '所选期间六维中合作分类的实际收款，扣除退款；卡项按卡内合作六维项目计入，不受1,000元成交条件限制。', ['合作六维实收业绩'], true, false, 140, $this->drill('六维 / 合作')),
            $this->pathColumn('all_received_performance', '六维全部实收业绩', '所选期间六维自营与合作实际收款之和，已扣除退款；包括未达到1,000元成交条件的收款。', ['六维全部实收业绩'], true, false, 140, $this->drill('六维')),
        ];
        // 展示解释与计算规则同源，避免把人员开关误写成门店见诊资格。
        foreach ($columns as &$column) {
            if (in_array($column['key'], ['new_visit_people', 'old_visit_people', 'total_visit_people'], true)) {
                $column['source_explanation'] .= '“不算人数人次”只影响人员统计，不影响本列；“体验”标记在本报表用于识别见诊服务，不是排除条件。';
            }
        }
        unset($column);
        return $this->result((string)$definition['name'], $columns, array_values($rows), $range, $input, ['category' => true]);
    }

    private function itemReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        if ($report === 'product_monetization_six_dimension_item_performance') return $this->sixDimensionItemReport($report, $definition, $stores, $range, $input);
        if ($report === 'product_monetization_private_item_performance') return $this->privateItemReport($definition, $stores, $range, $input);
        if ($report === 'operations_beauty_item') return $this->operationsBeautyItemReport($definition, $stores, $range, $input);
        if ($report === 'marketing_beauty_new_item') return $this->marketingBeautyNewItemReport($definition, $stores, $range, $input);
        $categoryIds = $this->categoryIds((string)($definition['category'] ?? ''), $input);
        $registeredActual = $report === 'product_monetization_beauty_item';
        $currentFacts = $categoryIds === [] ? [] : ($registeredActual
            ? $this->actualCategoryRows($stores, $range, $categoryIds)
            : $this->cashRows($stores, $range, $categoryIds));
        $rows = [];
        foreach ($currentFacts as $fact) {
            $key = (string)$fact['category_path'] . '|' . (string)$fact['item_id'];
            if (!isset($rows[$key])) $rows[$key] = [
                'category_name' => $this->leafCategory((string)$fact['category_path']), 'item_name' => (string)$fact['item_name'],
                'item_id' => (string)$fact['item_id'], 'detail_category_path' => (string)$fact['category_path'],
                '_item_key' => $key, 'cash_cents' => 0, 'quantity' => 0,
            ];
            $rows[$key]['cash_cents'] += (int)$fact['amount_cents'];
            if (!$registeredActual) $rows[$key]['quantity'] += max(0, (int)($fact['quantity'] ?? 0));
        }
        if ($registeredActual) {
            // The registered amount projection has no sale quantity; retain the established unit-price denominator only.
            foreach ($this->cashRows($stores, $range, $categoryIds) as $fact) {
                $key = (string)$fact['category_path'] . '|' . (string)$fact['item_id'];
                if (isset($rows[$key])) $rows[$key]['quantity'] += max(0, (int)($fact['quantity'] ?? 0));
            }
        }
        $previousRange = [
            'start' => date('Y-m-d', strtotime($range['start'] . ' -1 year')),
            'end' => date('Y-m-d', strtotime($range['end'] . ' -1 year')),
        ];
        $previousByItem = [];
        $previousFacts = $categoryIds === [] ? [] : ($registeredActual
            ? $this->actualCategoryRows($stores, $previousRange, $categoryIds)
            : $this->cashRows($stores, $previousRange, $categoryIds));
        foreach ($previousFacts as $fact) {
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
            $this->column('serial_no', '序号', $registeredActual ? '按本期实际业绩从高到低排序。' : '按本期现金业绩从高到低排序。', false, true, 70),
            $this->column('category_name', '品类', '取关联平台商品当前有效分类的末级名称；无有效关联时留空。', false, true, 130),
            $this->column('item_name', $itemLabel, '取成交时保存的商品名称快照；卡项按内含项目展开。', false, true, 180),
            $this->column('cash_performance', $registeredActual ? '实际业绩' : '现金业绩', $registeredActual
                ? '本商品成功现金收款减成功现金退款，按收款及退款业务日期归属。'
                : '成功收款按销售明细分摊，欠款补交和退款分别按成功日期正负计入。', true, false, 120, $this->cashDetailDrill($report, 'cash_performance')),
            $this->column('performance_share', '业绩占比', $registeredActual
                ? '本商品实际业绩除以当前报表商品范围内实际业绩合计；分母为零显示“-”。'
                : '本商品现金业绩除以当前报表商品范围内现金业绩合计；分母为零显示“-”。'),
            $this->column('unit_price', '单次价格', $registeredActual
                ? '本商品实际业绩除以成交数量；数量为零显示“-”。'
                : '本商品现金业绩除以成交数量；数量为零显示“-”。'),
            $this->column('product_cost', $costLabel, '取成交时冻结的单件成本快照；当前事实未保存成本时显示“-”。'),
            $this->column('gross_margin', '毛利率', '（单次价格－产品成本）÷ 单次价格；缺少成本或分母为零显示“-”。'),
            $this->column('gross_profit', '毛利额', '单次价格－产品成本；缺少成本时显示“-”。'),
            $this->column('last_year_performance', '去年同期业绩', $registeredActual
                ? '上一自然年同一日期范围、同商品和同分类的实际业绩。'
                : '上一自然年同一日期范围、同商品和同分类的净现金业绩。', true, false, 120, $this->cashDetailDrill($report, 'last_year_performance')),
            $this->column('last_year_share', '业绩占比', $registeredActual
                ? '去年同期业绩除以本期实际业绩；本期为零显示“-”。'
                : '去年同期业绩除以本期现金业绩，完全按原表公式；本期为零显示“-”。'),
            $this->column('difference', '差额', $registeredActual ? '本期实际业绩减去年同期业绩。' : '本期现金业绩减去年同期业绩。', true),
        ];
        $result = $this->result((string)$definition['name'], $columns, array_values($rows), $range, $input, ['category' => true]);
        if ($registeredActual) $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    /**
     * 花园品项以当前平台分类及成交名称快照合并门店副本。金额取登记实际业绩，
     * 数量只从有效销售读取一次；同名商品的资料成本按各自成交数量加权，缺失成本
     * 不得当成零。行键同时用于金额下钻，避免合并展示后只钻到其中一个商品 ID。
     */
    private function privateItemReport(array $definition, array $stores, array $range, array $input): array
    {
        $categoryIds = $this->categoryIds('花园', $input);
        $cash = $categoryIds === [] ? [] : $this->actualCategoryRows($stores, $range, $categoryIds, true);
        $priorRange = ['start' => date('Y-m-d', strtotime($range['start'] . ' -1 year')),
            'end' => date('Y-m-d', strtotime($range['end'] . ' -1 year'))];
        $prior = $categoryIds === [] ? [] : $this->actualCategoryRows($stores, $priorRange, $categoryIds, true);
        $sales = $categoryIds === [] ? [] : $this->itemSaleRows($stores, $range, $categoryIds);

        $groups = [];
        foreach ($cash as $fact) {
            $path = (string)$fact['category_path'];
            $name = trim((string)$fact['item_name']);
            $key = $this->privateItemGroupKey($path, $name);
            if (!isset($groups[$key])) $groups[$key] = [
                'item_group_key' => $key, 'detail_category_path' => $path,
                'category_name' => $this->leafCategory($path), 'item_name' => $name,
                '_cash' => 0, '_prior' => 0, '_quantity' => 0, '_cost_quantities' => [],
            ];
            $groups[$key]['_cash'] += (int)$fact['amount_cents'];
        }
        foreach ($prior as $fact) {
            $key = $this->privateItemGroupKey((string)$fact['category_path'], trim((string)$fact['item_name']));
            if (isset($groups[$key])) $groups[$key]['_prior'] += (int)$fact['amount_cents'];
        }
        foreach ($sales as $sale) {
            $key = $this->privateItemGroupKey((string)$sale['category_path'], trim((string)$sale['item_name']));
            if (!isset($groups[$key])) continue;
            $quantity = max(0, (int)$sale['quantity']);
            $groups[$key]['_quantity'] += $quantity;
            $productId = (int)$sale['platform_product_id'];
            if ($quantity > 0) {
                $groups[$key]['_cost_quantities'][$productId] = (int)($groups[$key]['_cost_quantities'][$productId] ?? 0) + $quantity;
            }
        }

        $productIds = [];
        foreach ($groups as $group) foreach (array_keys($group['_cost_quantities']) as $id) if ($id > 0) $productIds[$id] = true;
        $costs = [];
        foreach (array_chunk(array_keys($productIds), 300) as $chunk) {
            foreach (Db::name('store_product')->where('type', 0)->where('relation_id', 0)->whereIn('id', $chunk)
                ->field('id,cost')->select()->toArray() as $product) {
                if ($product['cost'] !== null && $product['cost'] !== '') $costs[(int)$product['id']] = $this->cents($product['cost']);
            }
        }

        $total = (int)array_sum(array_column($groups, '_cash'));
        foreach ($groups as &$row) {
            $cashCents = (int)$row['_cash'];
            $quantity = (int)$row['_quantity'];
            $unitCents = $quantity > 0 ? (int)round($cashCents / $quantity) : null;
            $costCents = $this->weightedItemCostCents($row['_cost_quantities'], $costs);
            $row['cash_performance'] = $this->money($cashCents);
            $row['performance_share'] = $this->ratio($cashCents, $total);
            $row['unit_price'] = $unitCents === null ? '-' : $this->money($unitCents);
            $row['product_cost'] = $costCents === null ? '-' : $this->money($costCents);
            $row['gross_profit'] = $unitCents === null || $costCents === null ? '-' : $this->money($unitCents - $costCents);
            $row['gross_margin'] = $unitCents === null || $unitCents <= 0 || $costCents === null
                ? '-' : $this->ratio($unitCents - $costCents, $unitCents);
            $row['last_year_performance'] = $this->money((int)$row['_prior']);
            $row['last_year_share'] = $this->ratio((int)$row['_prior'], $cashCents);
            $row['difference'] = $this->money($cashCents - (int)$row['_prior']);
            unset($row['_cash'], $row['_prior'], $row['_quantity'], $row['_cost_quantities']);
        }
        unset($row);
        $rows = array_values($groups);
        usort($rows, fn(array $left, array $right): int =>
            ($this->cents($right['cash_performance']) <=> $this->cents($left['cash_performance']))
                ?: strcmp((string)$left['item_name'], (string)$right['item_name']));
        foreach ($rows as $index => &$row) $row['serial_no'] = $index + 1;
        unset($row);

        $report = 'product_monetization_private_item_performance';
        $columns = [
            $this->column('serial_no', '序号', '按本期实际业绩从高到低排序。', false, true, 70),
            $this->column('category_name', '品类', '取商品资料当前花园分类的末级名称。', false, true, 130),
            $this->column('item_name', '花园商品名称', '取成交时保存的商品名称；同一分类下同名商品合并，卡项按内含项目展开。', false, true, 180),
            $this->column('cash_performance', '实际业绩', '本商品成功现金收款减成功现金退款，按各自业务日期归属。', true, false, 120, $this->cashDetailDrill($report, 'cash_performance')),
            $this->column('performance_share', '业绩占比', '该分类及名称的实际业绩除以本表实际业绩合计；分母为零显示“-”。'),
            $this->column('unit_price', '单次价格', '该行实际业绩除以本期有效成交数量；一笔销售只计一次数量，补交和退款不重复产生数量。'),
            $this->column('product_cost', '产品成本', '取各商品当前平台资料的成本单价，按本期有效成交数量加权平均；任一已成交商品缺少成本或本期无成交数量时显示“-”。'),
            $this->column('gross_margin', '毛利率', '（单次价格－加权产品成本）÷单次价格；价格不大于零或成本缺失时显示“-”。'),
            $this->column('gross_profit', '毛利额', '单次价格减加权产品成本，为单次毛利；无成交数量或成本缺失时显示“-”。'),
            $this->column('last_year_performance', '去年同期业绩', '上一自然年相同日期范围、同一分类与同名商品的实际业绩。', true, false, 120, $this->cashDetailDrill($report, 'last_year_performance')),
            $this->column('last_year_share', '业绩占比', '去年同期业绩除以本期该行实际业绩；本期为零显示“-”。'),
            $this->column('difference', '差额', '本期该行实际业绩减去年同期业绩。', true),
        ];
        foreach ($columns as &$column) {
            if (in_array($column['key'], ['cash_performance', 'unit_price', 'product_cost', 'gross_profit', 'last_year_performance', 'difference'], true)) {
                $column['value_type'] = 'money'; $column['display_precision'] = 0;
            }
        }
        unset($column);
        $result = $this->result((string)$definition['name'], $columns, $rows, $range, $input,
            ['category' => true, 'category_root' => '花园']);
        $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    /** All sold identities need a configured cost; an absent profile is unknown, while configured zero is valid. */
    private function weightedItemCostCents(array $quantities, array $costs): ?int
    {
        $totalQuantity = 0;
        $weightedCost = 0;
        foreach ($quantities as $productId => $quantity) {
            $quantity = max(0, (int)$quantity);
            if ($quantity === 0) continue;
            if (!array_key_exists((int)$productId, $costs)) return null;
            $totalQuantity += $quantity;
            $weightedCost += (int)$costs[(int)$productId] * $quantity;
        }
        return $totalQuantity > 0 ? (int)round($weightedCost / $totalQuantity) : null;
    }

    /**
     * R61 groups store copies by verified platform identity and current category.
     * Cash follows receipt dates; units follow sale dates independently, including
     * zero-cash sales. Repayments/refunds never manufacture additional sold units.
     */
    private function sixDimensionItemReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $ids = $this->categoryIds('六维', $input);
        $previousRange = ['start' => date('Y-m-d', strtotime($range['start'] . ' -1 year')),
            'end' => date('Y-m-d', strtotime($range['end'] . ' -1 year'))];
        // Platform product identity is only a grouping dimension. The registered
        // reader remains the owner of signed money, including standalone refunds.
        $current = $ids === [] ? [] : $this->currentCategoryRows($this->actualCategoryRows($stores, $range, $ids, true), $ids);
        $previous = $ids === [] ? [] : $this->currentCategoryRows($this->actualCategoryRows($stores, $previousRange, $ids, true), $ids);
        $sales = $ids === [] ? [] : $this->itemSaleRows($stores, $range, $ids);
        $groups = [];
        foreach (['previous' => $previous, 'current' => $current, 'sale' => $sales] as $kind => $facts) {
            foreach ($facts as $fact) {
                $productId = (int)$fact['platform_product_id'];
                if ($productId <= 0) continue;
                $key = (int)$fact['category_id'] . '|' . $productId;
                if (!isset($groups[$key])) $groups[$key] = [
                    'item_id' => (string)$productId, 'detail_category_path' => (string)$fact['category_path'],
                    'category_name' => $this->leafCategory((string)$fact['category_path']), 'item_name' => '',
                    '_name_key' => '', '_cash' => 0, '_previous' => 0, '_quantity' => 0, '_units' => [],
                ];
                $row = &$groups[$key];
                // Deterministically retain the latest sale-name snapshot, not a
                // catalog rename or whichever store happens to be returned first.
                $nameKey = ($kind === 'previous' ? '0' : '1') . (string)$fact['business_date'] . '|' . (string)$fact['id'];
                if (trim((string)$fact['item_name']) !== '' && strcmp($nameKey, $row['_name_key']) > 0) {
                    $row['item_name'] = (string)$fact['item_name']; $row['_name_key'] = $nameKey;
                }
                if ($kind === 'sale') {
                    if (!isset($row['_units'][(string)$fact['id']])) {
                        $row['_units'][(string)$fact['id']] = true;
                        $row['_quantity'] += max(0, (int)$fact['quantity']);
                    }
                } else $row[$kind === 'previous' ? '_previous' : '_cash'] += (int)$fact['amount_cents'];
                unset($row);
            }
        }
        // Current platform product cost is the PM-approved source. A configured
        // zero is valid; a missing profile is unknown, never silently zeroed.
        $costs = [];
        foreach (array_chunk(array_unique(array_column($groups, 'item_id')), 300) as $chunk) {
            foreach (Db::name('store_product')->where('type', 0)->where('relation_id', 0)->whereIn('id', $chunk)
                ->field('id,cost')->select()->toArray() as $product) {
                if ($product['cost'] !== null && $product['cost'] !== '') $costs[(int)$product['id']] = $this->cents($product['cost']);
            }
        }
        $total = (int)array_sum(array_column($groups, '_cash'));
        foreach ($groups as &$row) {
            $cash = (int)$row['_cash']; $prior = (int)$row['_previous'];
            $unit = $row['_quantity'] > 0 ? (int)round($cash / $row['_quantity']) : null;
            $cost = $costs[(int)$row['item_id']] ?? null;
            $row['cash_performance'] = $this->money($cash);
            $row['performance_share'] = $this->ratio($cash, $total);
            $row['unit_price'] = $unit === null ? '-' : $this->money($unit);
            $row['product_cost'] = $cost === null ? '-' : $this->money($cost);
            $row['gross_profit'] = $unit === null || $cost === null ? '-' : $this->money($unit - $cost);
            $row['gross_margin'] = $unit === null || $cost === null ? '-' : $this->ratio($unit - $cost, $unit);
            $row['last_year_performance'] = $this->money($prior);
            $row['last_year_share'] = $this->ratio($prior, $cash);
            $row['difference'] = $this->money($cash - $prior);
        }
        unset($row);
        $rows = array_values($groups);
        usort($rows, static fn(array $a, array $b): int => ($b['_cash'] <=> $a['_cash']) ?: ((int)$a['item_id'] <=> (int)$b['item_id']));
        foreach ($rows as $i => &$row) {
            $row['serial_no'] = $i + 1;
            unset($row['_cash'], $row['_previous'], $row['_quantity'], $row['_units'], $row['_name_key']);
        }
        unset($row);
        $columns = [
            $this->column('serial_no', '序号', '按本期实际业绩从高到低排序。', false, true, 70),
            $this->column('category_name', '品类', '按关联平台商品当前分类归属，只统计六维及所选下级分类。', false, true, 130),
            $this->column('item_name', '品项名称', '同一平台商品及当前分类合并各门店业绩，同名但不同商品不合并；名称取本期最近业务的成交商品快照，无本期业务时取去年同期。卡项按内含项目展开。', false, true, 180),
            $this->column('cash_performance', '实际业绩', '本期商品实际业绩按登记指标的成功现金收款减退款，按各自业务日期归属；下钻列出各店单据。', true, false, 120, $this->cashDetailDrill($report, 'cash_performance')),
            $this->column('performance_share', '业绩占比', '本商品实际业绩除以当前筛选范围的实际业绩合计；分母为零显示“-”。'),
            $this->column('unit_price', '单次价格', '本期实际业绩除以本期有效成交的商品数量或卡内项目次数；共享次数卡按每张卡发放的共享次数计算。共享次数可用于多个备选项目，各品项的次数不可相加为独立项目次数。同一成交明细只计一次，补交不重复计数，退款不虚构退回次数；无本期成交数量显示“—”。'),
            $this->column('product_cost', '单次产品成本', '取平台商品资料中当前成本价，不取门店副本或销售价；成本为零显示0，资料缺失显示“-”。成本修改后历史查询也随之更新。'),
            $this->column('gross_margin', '毛利率', '（单次价格－单次产品成本）÷单次价格；无本期成交数量、成本缺失或单次价格为零显示“-”。'),
            $this->column('gross_profit', '毛利额', '单次价格减单次产品成本，为单次毛利，不是总毛利；无本期成交数量或成本缺失显示“-”。'),
            $this->column('last_year_performance', '去年同期业绩', '上一自然年同一日期范围、同一平台商品和当前分类的实际业绩；仅去年有业务的商品仍保留。', true, false, 120, $this->cashDetailDrill($report, 'last_year_performance')),
            $this->column('last_year_share', '业绩占比', '去年同期业绩除以本期实际业绩；本期为零显示“-”。'),
            $this->column('difference', '差额', '本期实际业绩减去年同期业绩。', true),
        ];
        foreach ($columns as &$column) {
            if (in_array($column['key'], ['cash_performance', 'unit_price', 'product_cost', 'gross_profit', 'last_year_performance', 'difference'], true)) {
                $column['value_type'] = 'money'; $column['display_precision'] = 0;
            }
        }
        unset($column);
        $result = $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['category' => true, 'category_root' => '六维']);
        $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    /** Summary and drilldown share the exact scoped receipt/refund population. */
    private function sixDimensionItemCashRows(array $stores, array $range, array $categoryIds, bool $withDocument = false): array
    {
        $rows = $this->cashRows($stores, $range, $categoryIds, $withDocument, true);
        $allowed = array_fill_keys($categoryIds, true);
        foreach ($this->comparisonStandaloneRefundRows($stores, $range) as $refund) {
            if (isset($allowed[(int)$refund['category_id']])) $rows[] = $refund;
        }
        return $rows;
    }

    /** Read sale units once, independent of payment count, including unpaid/zero-cash sales. */
    private function itemSaleRows(array $stores, array $range, array $categoryIds): array
    {
        // Resolve the small item-dimension projection before lifecycle checks.
        // This avoids probing void/reversal chains for every unrelated category.
        $candidates = Db::name('cashier_v3_report_sale_dimension_fact')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('store_id', $stores)->whereBetween('business_date', [$range['start'], $range['end']])
            ->fieldRaw("sale_fact_id id,sale_fact_id,store_id,business_date,item_id,item_name_snapshot item_name,0 quantity,0 is_card")
            ->select()->toArray();
        $components = Db::name('cashier_v3_card_sale_item_allocation_fact')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('store_id', $stores)->whereBetween('business_date', [$range['start'], $range['end']])
            ->where('status', 'effective')->where('reversal_of', '')
            ->fieldRaw("allocation_fact_id id,sale_fact_id,card_receipt_id,store_id,business_date,component_product_id item_id,item_name_snapshot item_name,component_count quantity,1 is_card")
            ->select()->toArray();
        $candidates = $this->currentCategoryRows(array_merge($candidates, $components), $categoryIds);
        $saleIds = array_values(array_unique(array_column($candidates, 'sale_fact_id')));
        if ($saleIds === []) return [];
        $query = Db::name('cashier_v3_sale_fact')->alias('s')
            ->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('s.store_id', $stores)
            ->where('s.status', 'effective')->where('s.fact_direction', 'forward')
            ->whereBetween('s.business_date', [$range['start'], $range['end']]);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, 's.tenant_id', 's.order_id');
        $query->whereNotExists(function ($reversal): void {
            $reversal->name('cashier_v3_sale_fact')->alias('unit_reversal')
                ->whereRaw('unit_reversal.tenant_id=s.tenant_id AND unit_reversal.reversal_of=s.fact_id')
                ->where('unit_reversal.status', 'effective')->where('unit_reversal.fact_direction', 'reversal');
        });
        if ($this->participantEmployeeId > 0) (new StoreReportParticipantScopeServices())->applyOrder($query, 's.order_id', $this->participantEmployeeId);
        $sales = [];
        foreach (array_chunk($saleIds, 500) as $chunk) {
            foreach ((clone $query)->whereIn('s.fact_id', $chunk)->field('s.fact_id,s.source_type,s.quantity')->select()->toArray() as $sale) {
                $sales[(string)$sale['fact_id']] = $sale;
            }
        }
        // A choice-count card has one immutable shared pool per issued card,
        // while its optional projects deliberately have component_count=0.
        // Use the issued total for each item's unit-price basis, never the
        // mutable remaining balance or a sum across alternative projects.
        $sharedReceiptIds = [];
        foreach ($candidates as $candidate) {
            $sale = $sales[(string)$candidate['sale_fact_id']] ?? null;
            if ($sale !== null && $sale['source_type'] === 'card' && (int)$candidate['quantity'] === 0
                && trim((string)($candidate['card_receipt_id'] ?? '')) !== '') {
                $sharedReceiptIds[(string)$candidate['card_receipt_id']] = true;
            }
        }
        $sharedTimes = [];
        foreach (array_chunk(array_keys($sharedReceiptIds), 500) as $chunk) {
            foreach (Db::name('cashier_v3_card_rule_state')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('receipt_id', $chunk)->where('rule_type', 'choice_count')
                ->field('receipt_id,shared_total_times')->select()->toArray() as $state) {
                $sharedTimes[(string)$state['receipt_id']] = max(0, (int)$state['shared_total_times']);
            }
        }
        $rows = [];
        foreach ($candidates as $candidate) {
            $sale = $sales[(string)$candidate['sale_fact_id']] ?? null;
            if ($sale === null || ($sale['source_type'] === 'card') !== (bool)$candidate['is_card']) continue;
            if (!$candidate['is_card']) $candidate['quantity'] = (int)$sale['quantity'];
            elseif ((int)$candidate['quantity'] === 0) {
                $candidate['quantity'] = $sharedTimes[(string)($candidate['card_receipt_id'] ?? '')] ?? 0;
            }
            $rows[] = $candidate;
        }
        return $rows;
    }

    private function marketingBeautyNewItemReport(array $definition, array $stores, array $range, array $input): array
    {
        $ids = $this->categoryIds('生美', $input);
        $months = $this->months($range);
        $firstYear = substr($months[0], 0, 4);
        $lastMonth = end($months);
        $expandedRange = ['start' => $firstYear . '-01-01', 'end' => date('Y-m-t', strtotime($lastMonth . '-01'))];
        // The annual columns never use facts before the first selected year.
        // Read cash and completed services once, then reuse their scoped rows
        // for each month and item instead of issuing four queries per month.
        $allCash = $this->cashRows($stores, $expandedRange, [], false, false, true);
        $eligibleCountCash = StoreReportImportedRepaymentCountScope::exclude($allCash, $stores);
        $eligibleCountIds = [];
        foreach ($eligibleCountCash as $fact) {
            $key = !empty($fact['unallocated_repayment'])
                ? 'payment:' . (string)$fact['payment_fact_id'] : 'allocation:' . (string)$fact['id'];
            $eligibleCountIds[$key] = true;
        }
        unset($eligibleCountCash);
        $activeRows = $this->customerStatusMonthlyServices($stores, $expandedRange);
        $experienceRows = $this->itemExperienceServiceRows($stores, $expandedRange, $ids);
        $allowed = array_fill_keys(array_map('intval', $ids), true);
        $categoryCashByMonth = []; $categoryCashByGroup = [];
        foreach ($allCash as $fact) {
            $month = substr((string)$fact['business_date'], 0, 7);
            if (!isset($allowed[(int)($fact['category_id'] ?? 0)])) continue;
            // One visible row means one current two-level category and sold-name snapshot,
            // regardless of how many store-local product IDs contributed receipts.
            $fact['marketing_category_name'] = $this->marketingCategoryPath((string)($fact['category_path'] ?? ''));
            $fact['marketing_group_key'] = $this->marketingItemGroupKey(
                $fact['marketing_category_name'], trim((string)($fact['item_name'] ?? '')));
            $categoryCashByMonth[$month][] = $fact;
            $countKey = !empty($fact['unallocated_repayment'])
                ? 'payment:' . (string)$fact['payment_fact_id'] : 'allocation:' . (string)$fact['id'];
            if (isset($eligibleCountIds[$countKey]) && (string)$fact['business_date'] <= $range['end']) {
                $categoryCashByGroup[$fact['marketing_group_key']][substr($month, 0, 4)][] = $fact;
            }
        }
        unset($allCash);
        // Counts keep their established purchase-event cohort. Money is a
        // separate registered population, so refunds cannot manufacture a buyer.
        $actualByMonth = [];
        foreach ($this->actualCategoryRows($stores, $expandedRange, $ids, true) as $fact) {
            $month = substr((string)$fact['business_date'], 0, 7);
            $fact['marketing_category_name'] = $this->marketingCategoryPath((string)($fact['category_path'] ?? ''));
            $fact['marketing_group_key'] = $this->marketingItemGroupKey(
                $fact['marketing_category_name'], trim((string)($fact['item_name'] ?? '')));
            $actualByMonth[$month][] = $fact;
        }
        $actualMonthTotals = [];
        $registered = new RegisteredMetricReadServices();
        $allActual = $this->participantEmployeeId > 0
            ? $this->actualAllRows($stores, $expandedRange)
            : $registered->dailyTotals('actual_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $expandedRange);
        foreach ($allActual as $fact) {
            $month = substr((string)$fact['business_date'], 0, 7);
            $actualMonthTotals[$month] = (int)($actualMonthTotals[$month] ?? 0)
                + (int)($fact['amount_cents'] ?? $fact['metric_value'] ?? 0);
        }
        unset($allActual);
        $activeByMonth = []; $categoryServicesByMonth = [];
        foreach ($activeRows as $service) {
            $month = substr((string)$service['business_date'], 0, 7);
            $activeByMonth[$month] = (int)($activeByMonth[$month] ?? 0) + 1;
        }
        unset($activeRows);
        foreach ($experienceRows as $service) {
            $categoryServicesByMonth[substr((string)$service['business_date'], 0, 7)][] = $service;
        }
        unset($experienceRows);
        $annualCounts = [];
        foreach ($categoryCashByGroup as $groupKey => $years) {
            foreach ($years as $year => $itemFacts) {
                $byMonth = [];
                foreach ($itemFacts as $fact) $byMonth[substr((string)$fact['business_date'], 0, 7)][] = $fact;
                $members = []; $lineEvents = []; $purchaseCounts = []; $repeat = 0;
                for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++) {
                    $snapshotMonth = sprintf('%04d-%02d', (int)$year, $monthNumber);
                    foreach ((array)($byMonth[$snapshotMonth] ?? []) as $fact) {
                        $memberId = (int)($fact['member_id'] ?? 0);
                        if ($memberId <= 0) continue;
                        $members[$memberId] = true;
                        $eventKey = $memberId . '|' . (string)$fact['source_line_id'] . '|'
                            . (string)$fact['business_date'];
                        if (isset($lineEvents[$eventKey])) continue;
                        $lineEvents[$eventKey] = true;
                        $purchaseCounts[$memberId] = (int)($purchaseCounts[$memberId] ?? 0) + 1;
                        if ($purchaseCounts[$memberId] === 2) $repeat++;
                    }
                    $annualCounts[(string)$groupKey][(string)$year][$snapshotMonth] = [
                        'members' => count($members), 'repeat' => $repeat];
                }
            }
        }
        unset($categoryCashByGroup);
        $rows = [];
        foreach ($months as $month) {
            $facts = (array)($categoryCashByMonth[$month] ?? []);
            $total = (int)($actualMonthTotals[$month] ?? 0);
            $serviceRows = (array)($categoryServicesByMonth[$month] ?? []);
            $active = (int)($activeByMonth[$month] ?? 0);
            foreach ($facts as $fact) {
                $key = $month . '|' . (string)$fact['marketing_group_key'];
                if (!isset($rows[$key])) $rows[$key] = [
                    'month' => $month, 'category_name' => (string)$fact['marketing_category_name'],
                    'item_name' => trim((string)$fact['item_name']), 'item_group_key' => (string)$fact['marketing_group_key'],
                    'month_total_cents' => $total, 'cash_cents' => 0, 'members' => [],
                    'experience_members' => [], 'active_members' => $active,
                ];
                $countKey = !empty($fact['unallocated_repayment'])
                    ? 'payment:' . (string)$fact['payment_fact_id'] : 'allocation:' . (string)$fact['id'];
                if (isset($eligibleCountIds[$countKey]) && (int)$fact['member_id'] > 0) {
                    $rows[$key]['members'][(string)$fact['member_id']] = true;
                }
            }
            foreach ((array)($actualByMonth[$month] ?? []) as $fact) {
                $key = $month . '|' . (string)$fact['marketing_group_key'];
                if (!isset($rows[$key])) $rows[$key] = [
                    'month' => $month, 'category_name' => (string)$fact['marketing_category_name'],
                    'item_name' => trim((string)$fact['item_name']), 'item_group_key' => (string)$fact['marketing_group_key'],
                    'month_total_cents' => $total, 'cash_cents' => 0, 'members' => [],
                    'experience_members' => [], 'active_members' => $active,
                ];
                $rows[$key]['cash_cents'] += (int)$fact['amount_cents'];
            }
            foreach ($serviceRows as $service) {
                $key = $month . '|' . $this->marketingItemGroupKey(
                    $this->marketingCategoryPath((string)($service['category_path'] ?? '')),
                    trim((string)($service['project_name_snapshot'] ?? '')));
                if (isset($rows[$key]) && (int)$service['is_experience'] === 1 && (int)$service['member_id'] > 0) {
                    $rows[$key]['experience_members'][(string)$service['member_id']] = true;
                }
            }
        }
        foreach ($rows as &$row) {
            $people = count($row['members']);
            $annual = (array)($annualCounts[(string)$row['item_group_key']][substr((string)$row['month'], 0, 4)][(string)$row['month']] ?? []);
            $annualPeople = (int)($annual['members'] ?? 0);
            $repeatPeople = (int)($annual['repeat'] ?? 0);
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
            foreach (['month_total_cents', 'cash_cents', 'members', 'experience_members', 'active_members'] as $key) unset($row[$key]);
        }
        unset($row);
        $columns = [
            $this->column('month', '月份', '按所选年份展开的自然月。', false, true, 100),
            $this->column('category_name', '分类', '取商品资料当前生美分类，最多显示一级/二级；更深分类归入所属二级，同分类同名商品合并。', false, true, 150),
            $this->column('item_name', '品项名称', '取成交时保存的商品名称；历史卡项补交取原卡项目快照，不展示欠款占位名称；同一分类下同名商品合并。', false, true, 180),
            $this->column('month_total_performance', '月度总业绩', '当前权限范围当月全部商品分类、充值与退款的登记实际业绩。', true, false, 120, $this->cashDetailDrill('marketing_beauty_new_item', 'month_total_performance')),
            $this->column('item_performance', '品项业绩', '该分类下同名品项当月登记实际业绩；成功现金退款按退款日冲减。', true, false, 120, $this->cashDetailDrill('marketing_beauty_new_item', 'item_performance')),
            $this->column('item_share', '品项业绩占比', '品项业绩除以月度总业绩；分母为零显示“-”。'),
            $this->column('experience_people', '体验人数', '当月完成该分类下同名项目且带体验标记的不同会员数。', true, false, 120, $this->serviceDetailDrill('marketing_beauty_new_item', 'experience_people')),
            $this->column('deal_people', '成交人头', '当月购买该分类下同名品项的不同会员数；旧导入数据或旧卡录入原单的欠款补交不计人数。', true, false, 120, $this->cashDetailDrill('marketing_beauty_new_item', 'deal_people')),
            $this->column('deal_rate', '成交率', '成交人头除以体验人数；分母为零显示“-”。'),
            $this->column('deal_unit_output', '成交单产', '品项业绩除以成交人头；分母为零显示“-”。'),
            $this->column('annual_repeat_people', '年度购买≥2次人数', '截至当前月，本年度购买该分类下同名品项不少于两次的不同会员数；旧导入数据或旧卡录入原单的欠款补交不算购买次数。', true, false, 120, $this->cashDetailDrill('marketing_beauty_new_item', 'annual_repeat_people')),
            $this->column('annual_repeat_rate', '年度复购率', '年度购买≥2次人数除以年度成交人头；分母为零显示“-”。'),
            $this->column('annual_deal_people', '年度成交人头', '截至当前月，本年度购买该分类下同名品项的不同会员数；旧导入数据或旧卡录入原单的欠款补交不计人数。', true, false, 120, $this->cashDetailDrill('marketing_beauty_new_item', 'annual_deal_people')),
            $this->column('annual_active_penetration', '年度活客普及率', '年度成交人头除以当月活客数；分母为零显示“-”。'),
        ];
        foreach ($columns as $index => &$column) {
            if ($index === 0) $column['header_path'] = ['月份'];
            elseif ($index === 1) $column['header_path'] = ['分类'];
            elseif ($index === 2) $column['header_path'] = ['品项名称'];
            elseif ($index <= 9) $column['header_path'] = ['当月数据', (string)$column['label']];
            else $column['header_path'] = ['年度累计', (string)$column['label']];
            // Only display rounds to whole yuan; fact sums, drilldowns and exports retain cents.
            if (in_array((string)$column['key'], ['month_total_performance', 'item_performance', 'deal_unit_output'], true)) {
                $column['display_precision'] = 0;
            }
        }
        unset($column);
        $result = $this->result((string)$definition['name'], $columns, array_values($rows), $range, $input,
            ['year' => true, 'category' => true]);
        // Monthly total repeats on each visible item only for comparison; the
        // summary must count each selected month exactly once.
        $result['summary_row']['month_total_performance'] = $this->money(array_sum(array_map(
            static fn(string $month): int => (int)($actualMonthTotals[$month] ?? 0), $months)));
        $result['metric_version'] = $this->registeredActualMetricVersion() . '+imported-and-old-card-repayment-people-r82';
        return $result;
    }

    private function operationsBeautyItemReport(array $definition, array $stores, array $range, array $input): array
    {
        if (substr($range['start'], 0, 7) !== substr($range['end'], 0, 7)) throw new \InvalidArgumentException('运营生美品项表的查询时间不允许跨自然月');
        // The unit-price range was retired; old links must not silently narrow this report.
        $allRangeCash = $this->cashRows($stores, $range, [], false, true);
        $categoryIds = $this->categoryIds('生美', $input);
        $allowed = array_fill_keys(array_map('intval', $categoryIds), true);
        // The denominator and item amounts share one authorized actual-performance
        // population. Reuse it here instead of reopening the registered reader;
        // the category helper keeps the existing standalone-refund deduplication.
        $allActualFacts = $this->actualAllRows($stores, $range);
        $actualFacts = $categoryIds === [] ? [] : $this->currentCategoryRows(
            $this->actualCategoryRows($stores, $range, $categoryIds, true, $allActualFacts), $categoryIds);
        $rows = [];
        // Deal heads use eligible receipts; actual performance below keeps the complete cash facts.
        foreach (StoreReportImportedRepaymentCountScope::exclude($allRangeCash, $stores) as $fact) {
            if (!isset($allowed[(int)($fact['category_id'] ?? 0)])) continue;
            // The 生美 branch may contain cards. This item report counts only
            // products and projects, including projects allocated from cards.
            if (!in_array((int)($fact['product_type'] ?? -1), [0, 6], true)) continue;
            // Multiple store copies and historical product IDs can share the
            // same snapshot name. The report asks for one row per name; keep a
            // stable opaque row key so drilldown selects the same fact group.
            $name = trim((string)$fact['item_name']);
            $key = $this->beautyItemGroupKey($name);
            if (!isset($rows[$key])) $rows[$key] = ['item_name' => $name, 'item_group_key' => $key, 'cash_cents' => 0, 'members' => []];
            if ((int)$fact['member_id'] > 0) $rows[$key]['members'][(string)$fact['member_id']] = (int)($rows[$key]['members'][(string)$fact['member_id']] ?? 0) + (int)$fact['amount_cents'];
        }
        foreach ($actualFacts as $fact) {
            if (!in_array((int)($fact['product_type'] ?? -1), [0, 6], true)) continue;
            $name = trim((string)($fact['item_name'] ?? ''));
            $key = $this->beautyItemGroupKey($name);
            if (!isset($rows[$key])) $rows[$key] = ['item_name' => $name, 'item_group_key' => $key, 'cash_cents' => 0, 'members' => []];
            $rows[$key]['cash_cents'] += (int)$fact['amount_cents'];
        }
        // One natural month needs a distinct eligible-service member count,
        // not the full grouped service row payload used by annual reports.
        $allServiceMembers = $this->customerStatusMonthlyServiceMemberCount($stores, $range);
        // The denominator includes all categories, recharge and refunds; it is
        // not the subtotal of just the displayed 生美 item rows.
        $storeCash = $this->factAmount($allActualFacts);
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
        $columns = [
            $this->column('item_name', '商品名称', '只显示当前生美分类下的产品、项目；按成交时保存的同名商品合并，卡项购买按卡内项目展开。', false, true, 180),
            $this->column('cash_performance', '实际业绩', '该商品在筛选期间的登记实际业绩，成功现金收款减成功现金退款。', true, false, 120, $this->cashDetailDrill('operations_beauty_item', 'cash_performance')),
            $this->column('deal_people', '成交人头', '该商品在筛选商品分类内，剔除旧导入数据或旧卡录入原单的欠款补交后，累计现金业绩达到1,000元的不同会员数；补交金额仍计入实际业绩。', true, false, 120, $this->cashDetailDrill('operations_beauty_item', 'deal_people')),
            $this->column('deal_unit_output', '成交单产', '该商品实际业绩除以成交人头；分母为零显示“-”。'),
            $this->column('people_share', '人头占比', '成交人头除以本月完成有效服务的不同会员数；分母为零显示“-”。'),
            $this->column('performance_share', '业绩占比', '该商品实际业绩除以当前门店范围当月全部商品实际业绩；分母为零显示“-”。'),
        ];
        // Keep cents in API/export and calculation while displaying money in
        // whole yuan, as required by the shared report presentation contract.
        foreach ($columns as &$column) {
            if (in_array($column['key'], ['cash_performance', 'deal_unit_output'], true)) $column['display_precision'] = 0;
        }
        unset($column);
        $result = $this->result((string)$definition['name'], $columns, array_values($rows), $range, $input,
            ['category' => true, 'category_root' => '生美', 'single_month' => true]);
        $result['metric_version'] = $this->registeredActualMetricVersion()
            . '+imported-and-old-card-repayment-people-r83';
        return $result;
    }

    private function categoryTotalReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $category = (string)$definition['category'];
        if ($category === '生美') {
            return $this->beautyCategoryTotalReport($report, $definition, $stores, $range, $input);
        }
        if ($category === '六维') {
            return $this->sixDimensionCategoryTotalReport($report, $definition, $stores, $range, $input);
        }
        $categoryIds = $this->categoryIds($category, $input);
        $months = $this->months($range);
        // One registered actual-performance fact population serves the total and
        // every component; refunds must not disappear or be deducted twice.
        $cashFacts = $this->actualCategoryRows($stores, $range, $categoryIds);
        $totals = $this->cashTotalsFromRows($cashFacts, $categoryIds, true);
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
            '昊美' => ['昊美m', '昊美n'],
        ][$category] ?? [];
        $detailTotals = [];
        // These are fixed report components, not user filters. A component that
        // is absent from the category tree must contribute zero, never all sales.
        foreach ($detailLabels as $label) $detailTotals[$label] = $this->cashTotalsFromRows($cashFacts, $this->descendantsByPath($category . ' / ' . $label), true);
        foreach ($rows as &$row) foreach ($detailLabels as $label) $row['category_' . $this->key($label)] = $this->money((int)($detailTotals[$label][$row['month']] ?? 0));
        unset($row);
        if ($category === '花园') {
            $partnerCategories = $this->enabledSecondLevelPartnerCategories($category);
            foreach ($partnerCategories as $partnerCategory) {
                // 每列只归集其二级分类及下级；一级直接归类和未开启合作的分类仍留在总业绩。
                $componentTotals = $this->cashTotalsFromRows($cashFacts,
                    $this->descendants([(int)$partnerCategory['id']]), true);
                foreach ($rows as &$row) {
                    $row[(string)$partnerCategory['key']] = $this->money((int)($componentTotals[$row['month']] ?? 0));
                }
                unset($row);
            }
            $columns = [
                $this->column('month', '月份', '按所选年份展开1月至12月。', false, true, 100),
                $this->manualColumn('target_amount', '目标业绩', '手动输入当前权限范围该月私密总业绩目标，保存为独立报表补充记录，不覆盖订单或收款事实。'),
                $this->column('cash_performance', '私密总业绩', '商品分类“花园”及全部下级分类的实际业绩：成功现金收款减成功现金退款。', true, false, 130, $this->cashDetailDrill($report, 'cash_performance')),
                $this->column('completion_rate', '目标达成率', '私密总业绩除以目标业绩；目标为空或为零显示“-”。'),
            ];
            foreach ($partnerCategories as $partnerCategory) {
                $columns[] = $this->column((string)$partnerCategory['key'], (string)$partnerCategory['label'],
                    '当前商品分类“' . $partnerCategory['path'] . '”已开启合作；统计该二级分类及下级分类的实际业绩，不按合作方分成比例计算。',
                    true, false, 120, $this->cashDetailDrill($report, (string)$partnerCategory['key']), '合作品类业绩');
            }
            foreach ($rows as &$row) {
                $subtotal = 0;
                foreach ($partnerCategories as $partnerCategory) {
                    $subtotal += $this->cents($row[(string)$partnerCategory['key']] ?? '0');
                }
                $row['category_subtotal'] = $this->money($subtotal);
            }
            unset($row);
            $columns[] = $this->column('category_subtotal', '小计',
                '当前开启合作的花园二级分类业绩之和；一级直接归类和未开启合作的分类不计入此小计。',
                true, false, 120, [], '合作品类业绩');
            $result = $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
            $result['metric_version'] = $this->registeredActualMetricVersion();
            return $result;
        }
        $columns = [
            $this->column('month', '月份', '按所选日期范围内的自然月分组。', false, true, 100),
            $this->manualColumn('target_amount', '目标业绩', '手动输入当前权限范围该月' . $categoryLabel . '目标，保存为独立报表补充记录，不覆盖订单或收款事实。'),
            $this->column('cash_performance', $categoryLabel, '商品分类“' . $category . '”及全部下级分类的实际业绩：成功现金收款减成功现金退款。', true, false, 130, $this->cashDetailDrill($report, 'cash_performance')),
            $this->column('completion_rate', '目标达成率', $categoryLabel . '除以目标业绩；目标为空或为零显示“-”。'),
        ];
        foreach ($detailLabels as $label) {
            $columns[] = $this->column('category_' . $this->key($label), $label, '商品分类“' . $label . '”及全部下级分类的实际业绩。', true, false, 120, $this->cashDetailDrill($report, 'category_' . $this->key($label)));
        }
        if ($category === '昊美') {
            $key = 'category_subtotal';
            foreach ($rows as &$row) { $sum = 0; foreach ($detailLabels as $label) $sum += $this->cents($row['category_' . $this->key($label)] ?? '-'); $row[$key] = $this->money($sum); }
            unset($row);
            $columns[] = $this->column($key, '小计', '同一业务分组内各已列分类业绩之和。', true, false, 120);
        }
        $result = $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
        $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    /** 生美品类列随当前平台分类树变化，金额只取已登记的实际业绩分类事实。 */
    private function beautyCategoryTotalReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $structure = $this->beautyCategoryStructure();
        $allowedIds = $this->categoryIds('生美', $input);
        $totals = []; $byColumn = [];
        foreach ($this->actualCategoryRows($stores, $range, $allowedIds) as $fact) {
            $month = substr((string)$fact['business_date'], 0, 7);
            $amount = (int)$fact['amount_cents'];
            $totals[$month] = (int)($totals[$month] ?? 0) + $amount;
            $key = $structure['category_keys'][(int)$fact['category_id']] ?? null;
            if ($key !== null) $byColumn[$month][$key] = (int)($byColumn[$month][$key] ?? 0) + $amount;
        }
        $columns = [
            $this->pathColumn('month', '月份', '按所选年份展开1月至12月。', ['月份'], false, true, 100, [], '', 'text'),
            $this->pathColumn('cash_performance', '生美实际业绩', '生美商品成功收款减去成功现金退款；按商品当前分类归集，未关联生美商品的充值和退款不计入。', ['生美实际业绩'], true, false, 130, $this->cashDetailDrill($report, 'cash_performance')),
        ];
        foreach ($structure['leaves'] as $leaf) {
            $columns[] = $this->pathColumn($leaf['key'], $leaf['label'],
                '当前商品分类“' . $leaf['path'] . '”的实际业绩；成功收款减成功现金退款，无成交显示0。',
                $leaf['header_path'], true, false, 120, $this->cashDetailDrill($report, $leaf['key']));
        }
        $rows = [];
        foreach ($this->months($range) as $month) {
            $row = ['month' => $month, 'cash_performance' => $this->money((int)($totals[$month] ?? 0))];
            foreach ($structure['leaves'] as $leaf) {
                $row[$leaf['key']] = $this->money((int)($byColumn[$month][$leaf['key']] ?? 0));
            }
            $rows[] = $row;
        }
        $result = $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
        $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    /** Keep the report, popup and export tied to the same registered metric version. */
    private function registeredActualMetricVersion(): string
    {
        return 'beauty-category-actual-' . (string)(MetricDefinitionRegistry::get('actual_performance')['metric_version'] ?? '');
    }

    /** 当前二、三级分类各占一列；父级直接归类单列保留，避免和子级重复。 */
    private function beautyCategoryStructure(): array
    {
        $tree = $this->categoryTreeRows();
        $children = [];
        foreach ($tree as $node) $children[(int)$node['pid']][] = $node;
        foreach ($children as &$siblings) {
            usort($siblings, static fn(array $a, array $b): int =>
                ((int)$b['sort'] <=> (int)$a['sort']) ?: ((int)$b['id'] <=> (int)$a['id']));
        }
        unset($siblings);
        $leaves = []; $categoryKeys = [];
        $append = function (string $key, string $label, array $headerPath, string $path, array $ids) use (&$leaves, &$categoryKeys): void {
            $leaves[$key] = ['key' => $key, 'label' => $label, 'header_path' => $headerPath,
                'path' => $path, 'ids' => $ids];
            foreach ($ids as $id) $categoryKeys[(int)$id] = $key;
        };
        foreach ($children[0] ?? [] as $root) {
            if ((string)$root['cate_name'] !== '生美') continue;
            $rootId = (int)$root['id'];
            $seconds = $children[$rootId] ?? [];
            $append('beauty_direct_' . $rootId, $seconds === [] ? '生美' : '本级',
                $seconds === [] ? ['生美'] : ['生美', '本级'], '生美', [$rootId]);
            foreach ($seconds as $second) {
                $secondId = (int)$second['id'];
                $secondName = (string)$second['cate_name'];
                $thirds = $children[$secondId] ?? [];
                if ($thirds === []) {
                    $append('beauty_category_' . $secondId, $secondName, [$secondName],
                        '生美 / ' . $secondName, $this->descendants([$secondId], $tree));
                    continue;
                }
                $append('beauty_direct_' . $secondId, '本级', [$secondName, '本级'],
                    '生美 / ' . $secondName, [$secondId]);
                foreach ($thirds as $third) {
                    $thirdId = (int)$third['id'];
                    $thirdName = (string)$third['cate_name'];
                    $append('beauty_category_' . $thirdId, $thirdName, [$secondName, $thirdName],
                        '生美 / ' . $secondName . ' / ' . $thirdName, $this->descendants([$thirdId], $tree));
                }
            }
        }
        return ['leaves' => $leaves, 'category_keys' => $categoryKeys];
    }

    /** Registered net metric owns sale-linked refunds; verified standalone refunds fill missing product attribution. */
    private function actualCategoryRows(array $stores, array $range, array $allowedIds, bool $dedupeRegisteredRefund = false, ?array $preloadedActualRows = null): array
    {
        if ($allowedIds === []) return [];
        $reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
        $tenant = CashierV3ScopeResolver::TENANT_SCOPE_ID;
        // Only callers that already read the same authorized range may supply
        // its registered population; the final current-category filter remains here.
        $facts = $preloadedActualRows !== null ? $this->currentCategoryRows($preloadedActualRows, $allowedIds)
            : ($this->participantEmployeeId > 0
                ? $reader->categoryRowsForParticipant('actual_performance', $tenant, $stores, $range, $allowedIds, [], $this->participantEmployeeId)
                : $reader->categoryRows('actual_performance', $tenant, $stores, $range, $allowedIds));
        $allowed = array_fill_keys($allowedIds, true);
        // The beauty-item report must not append a refund already resolved by
        // the registered reader; keep existing behavior for other reports.
        $registeredPaymentIds = $dedupeRegisteredRefund ? array_fill_keys(array_filter(array_map(
            static fn(array $fact): string => (string)($fact['fact_id'] ?? ''), $facts)), true) : [];
        foreach ($this->comparisonStandaloneRefundRows($stores, $range) as $refund) {
            if (isset($allowed[(int)$refund['category_id']])
                && (!$dedupeRegisteredRefund
                    || !isset($registeredPaymentIds[(string)($refund['payment_fact_id'] ?? '')]))) $facts[] = $refund;
        }
        foreach ($facts as &$fact) {
            // The popup displays original document/member snapshots; these
            // labels never participate in registered metric aggregation.
            $fact['member_name'] = (string)($fact['member_name'] ?? $fact['member_name_snapshot'] ?? '');
            $fact['document_order_id'] = (string)($fact['document_order_id'] ?? $fact['order_id'] ?? '');
            $fact['document_no_snapshot'] = (string)($fact['document_no_snapshot'] ?? $fact['order_no_snapshot'] ?? '');
            // Standalone refunds lack a sale organization snapshot; current store hierarchy resolves company scope.
            $fact['organization_id'] = (string)($fact['organization_id'] ?? '');
            $fact['organization_path_snapshot'] = (string)($fact['organization_path_snapshot'] ?? '');
        }
        unset($fact);
        return $facts;
    }

    /** Full-store registered actual performance includes uncategorized recharge and refunds. */
    private function actualAllRows(array $stores, array $range): array
    {
        $reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
        $tenant = CashierV3ScopeResolver::TENANT_SCOPE_ID;
        $facts = $this->participantEmployeeId > 0
            ? $reader->categoryRowsForParticipant('actual_performance', $tenant, $stores, $range, [], [], $this->participantEmployeeId)
            : $reader->categoryRows('actual_performance', $tenant, $stores, $range);
        foreach ($facts as &$fact) {
            $fact['member_name'] = (string)($fact['member_name'] ?? $fact['member_name_snapshot'] ?? '');
            $fact['document_order_id'] = (string)($fact['document_order_id'] ?? $fact['order_id'] ?? '');
            $fact['document_no_snapshot'] = (string)($fact['document_no_snapshot'] ?? $fact['order_no_snapshot'] ?? '');
            $fact['organization_id'] = (string)($fact['organization_id'] ?? '');
            $fact['organization_path_snapshot'] = (string)($fact['organization_path_snapshot'] ?? '');
        }
        unset($fact);
        return $facts;
    }

    /** Classify an existing standalone payment once; never add a second monetary row. */
    private function actualAllClassifiedRows(array $stores, array $range): array
    {
        $facts = $this->actualAllRows($stores, $range);
        $byPayment = [];
        foreach ($this->comparisonStandaloneRefundRows($stores, $range) as $refund) {
            $id = (string)($refund['payment_fact_id'] ?? '');
            if ($id !== '') $byPayment[$id][] = $refund;
        }
        foreach ($facts as &$fact) {
            $matches = (array)($byPayment[(string)($fact['fact_id'] ?? '')] ?? []);
            // Ambiguous multi-item attribution must stay unclassified; the
            // payment amount and all-category total remain unchanged.
            if (count($matches) !== 1) continue;
            $refund = $matches[0];
            if ((int)$fact['store_id'] !== (int)$refund['store_id']
                || (int)$fact['amount_cents'] !== (int)$refund['amount_cents']) continue;
            foreach (['category_id', 'category_path', 'item_id', 'item_name', 'platform_product_id'] as $field) {
                $fact[$field] = $refund[$field];
            }
        }
        unset($fact);
        return $facts;
    }

    /**
     * 六维动态列只由当前平台分类树决定；全年零成交的分类也必须保留。
     * 根节点自身的金额只进总业绩，二级直接归类进“本级”，三级及以下各归一列。
     * 每笔已授权的净收款事实只访问一次，避免按列反复扫描全年收款。
     */
    private function sixDimensionCategoryTotalReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $structure = $this->sixDimensionCategoryStructure();
        $allowedIds = $this->categoryIds('六维', $input);
        $facts = $allowedIds === [] ? [] : $this->actualCategoryRows($stores, $range, $allowedIds, true);
        $totals = $this->sixDimensionCategoryTotals($facts, $allowedIds, $structure);
        $target = $this->manualColumn('target_amount', '目标业绩', '手动输入当前权限范围该月六维总业绩目标，保存为独立报表补充记录，不覆盖收款事实。');
        $target['header_path'] = ['目标业绩'];
        $columns = [
            $this->pathColumn('month', '月份', '按所选年份展开1月至12月。', ['月份'], false, true, 100, [], '', 'text'),
            $target,
            $this->pathColumn('cash_performance', '六维总业绩', '当前平台商品分类“六维”及全部下级分类的实际业绩；直接归在六维一级的金额也计入总业绩。', ['六维总业绩'], true, false, 130, $this->cashDetailDrill($report, 'cash_performance')),
            $this->pathColumn('completion_rate', '目标达成率', '六维总业绩除以目标业绩；目标为空或为零显示“-”。', ['目标达成率'], false, false, 110, [], '', 'percentage'),
        ];
        foreach (['self', 'partner', 'other'] as $group) {
            if ($group === 'other' && empty($structure['groups'][$group])) continue;
            foreach ($structure['groups'][$group] ?? [] as $leaf) {
                $columns[] = $this->pathColumn($leaf['key'], $leaf['label'], $leaf['explanation'], $leaf['header_path'], true, false, 120,
                    $this->cashDetailDrill($report, $leaf['key']));
            }
            $subtotalKey = $group . '_category_subtotal';
            $groupLabel = $structure['group_labels'][$group];
            $columns[] = $this->pathColumn($subtotalKey, '小计', $groupLabel . '下已列分类的实际业绩之和；直接归在六维一级的金额不计入。',
                [$groupLabel, '小计'], true, false, 120);
        }
        $rows = [];
        foreach ($this->months($range) as $month) {
            $amount = (int)($totals['total'][$month] ?? 0);
            $row = ['month' => $month, 'target_amount' => '-', 'target_amount_version' => 0,
                'cash_performance' => $this->money($amount)];
            $manual = $this->manualValue($report, $this->manualSubject($report, $month, 0), 'target_amount');
            if ($this->hasManualValue($manual)) {
                $row['target_amount'] = $this->money((int)$manual['value']);
                $row['target_amount_version'] = (int)$manual['version'];
            }
            $row['completion_rate'] = $row['target_amount'] === '-' ? '-' : $this->ratio($amount, (int)$manual['value']);
            foreach ($structure['leaves'] as $leaf) {
                $row[$leaf['key']] = $this->money((int)($totals['columns'][$month][$leaf['key']] ?? 0));
            }
            foreach (['self', 'partner', 'other'] as $group) {
                if ($group === 'other' && empty($structure['groups'][$group])) continue;
                $row[$group . '_category_subtotal'] = $this->money((int)($totals['groups'][$month][$group] ?? 0));
            }
            $rows[] = $row;
        }
        $this->attachManualSubjects($rows, $report);
        $result = $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
        $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    /** @return array{groups:array,leaves:array,category_keys:array,group_labels:array} */
    private function sixDimensionCategoryStructure(): array
    {
        $tree = $this->categoryTreeRows();
        $children = [];
        foreach ($tree as $node) $children[(int)$node['pid']][] = $node;
        foreach ($children as &$siblings) {
            usort($siblings, static fn(array $a, array $b): int =>
                ((int)$b['sort'] <=> (int)$a['sort']) ?: ((int)$b['id'] <=> (int)$a['id']));
        }
        unset($siblings);
        $groupLabels = ['self' => '自营品类业绩', 'partner' => '合作品类业绩', 'other' => '其他品类业绩'];
        $groups = ['self' => [], 'partner' => [], 'other' => []];
        $leaves = [];
        $categoryKeys = [];
        foreach ($children[0] ?? [] as $root) {
            if ((string)$root['cate_name'] !== '六维') continue;
            foreach ($children[(int)$root['id']] ?? [] as $second) {
                $secondId = (int)$second['id'];
                $secondName = (string)$second['cate_name'];
                // Unknown second-level labels remain visible in their own section,
                // rather than silently being counted as self-owned or partner sales.
                $group = str_starts_with($secondName, '自营') ? 'self'
                    : (str_starts_with($secondName, '合作') ? 'partner' : 'other');
                $thirds = $children[$secondId] ?? [];
                $definitions = $thirds === [] ? [[$second, false]] : array_merge([[$second, true]], array_map(
                    static fn(array $third): array => [$third, false], $thirds));
                foreach ($definitions as [$node, $direct]) {
                    $id = (int)$node['id'];
                    $key = ($direct ? 'six_direct_' : 'six_category_') . $id;
                    $label = $direct ? '本级' : (string)$node['cate_name'];
                    $ids = $direct ? [$id] : $this->descendants([$id], $tree);
                    $path = '六维 / ' . $secondName . ($id === $secondId ? '' : ' / ' . (string)$node['cate_name']);
                    $leaf = ['key' => $key, 'label' => $label, 'ids' => $ids, 'group' => $group,
                        'header_path' => $thirds === [] ? [$groupLabels[$group], $secondName]
                            : [$groupLabels[$group], $secondName, $label],
                        'explanation' => '当前平台分类“' . $path . '”' . ($direct ? '直接归在该二级分类' : '及其下级分类') . '的实际业绩；无成交显示0。'];
                    $groups[$group][] = $leaf;
                    $leaves[$key] = $leaf;
                    foreach ($ids as $categoryId) $categoryKeys[$categoryId] = $key;
                }
            }
        }
        return ['groups' => $groups, 'leaves' => $leaves, 'category_keys' => $categoryKeys, 'group_labels' => $groupLabels];
    }

    /** One current-category fact contributes to the total and at most one displayed leaf. */
    private function sixDimensionCategoryTotals(array $facts, array $allowedIds, array $structure): array
    {
        $allowed = array_fill_keys(array_map('intval', $allowedIds), true);
        $totals = ['total' => [], 'columns' => [], 'groups' => []];
        foreach ($facts as $fact) {
            $id = (int)($fact['category_id'] ?? 0);
            if (!isset($allowed[$id])) continue;
            $month = substr((string)$fact['business_date'], 0, 7);
            $amount = (int)$fact['amount_cents'];
            $totals['total'][$month] = (int)($totals['total'][$month] ?? 0) + $amount;
            $key = $structure['category_keys'][$id] ?? null;
            if ($key === null) continue;
            $group = $structure['leaves'][$key]['group'];
            $totals['columns'][$month][$key] = (int)($totals['columns'][$month][$key] ?? 0) + $amount;
            $totals['groups'][$month][$group] = (int)($totals['groups'][$month][$group] ?? 0) + $amount;
        }
        return $totals;
    }

    private function performanceTotalReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $months = $this->months($range);
        $categories = ['生美', '六维', '昊美', '花园'];
        // All splits use one registered actual-performance payment population;
        // classification changes only dimensions, never the signed amount.
        $cashFacts = $this->actualAllClassifiedRows($stores, $range);
        $totals = [];
        foreach ($categories as $category) $totals[$category] = $this->cashTotalsFromRows($cashFacts, $this->categoryIds($category, []), true);
        $all = $this->cashTotalsFromRows($cashFacts, []);
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
            $this->column('total_performance', '总业绩', '当前权限范围内全部商品分类、充值与退款的登记实际业绩。', true, false, 120, $this->cashDetailDrill($report, 'total_performance')),
        ];
        foreach ($categories as $category) {
            $key = $this->key($category);
            $columns[] = $this->column($key . '_performance', $category . '业绩', '商品分类“' . $category . '”及全部下级分类的登记实际业绩。', true, false, 120, $this->cashDetailDrill($report, $key . '_performance'));
            $columns[] = $this->column($key . '_share', $category . '占比', $category . '业绩除以总业绩；分母为零显示“-”。');
        }
        $columns[] = $this->column('other_performance', '其他', '总业绩扣除生美、六维、昊美和花园业绩后的余额。', true, false, 110, $this->cashDetailDrill($report, 'other_performance'));
        $columns[] = $this->column('other_share', '其他占比', '其他业绩除以总业绩；分母为零显示“-”。');
        $columns[] = $this->column('external_subtotal', '外接小计', '六维、昊美、花园和其他业绩之和；完全按原表公式。', true, false, 120, $this->cashDetailDrill($report, 'external_subtotal'));
        $result = $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
        $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    /**
     * Cash-only drilldown for whitelisted operations metrics. The caller may
     * choose a month, never a category or product ID: those are derived again
     * from the authorized parent report and column key.
     */
    private function operationsCashFactDetail(array $stores, array $range, array $input): array
    {
        $parent = trim((string)($input['parent_report'] ?? ''));
        $metric = trim((string)($input['metric_code'] ?? ''));
        $selection = $this->operationsCashDetailSelection($parent, $metric, $input);
        $month = trim((string)($input['drill_month'] ?? ''));
        if ($month !== '') {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)
                || $month < substr($range['start'], 0, 7) || $month > substr($range['end'], 0, 7)) {
                throw new \InvalidArgumentException('下钻月份不在当前统计范围内');
            }
            $range = !empty($selection['full_month'])
                ? ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01'))]
                : ['start' => max($range['start'], $month . '-01'), 'end' => min($range['end'], date('Y-m-t', strtotime($month . '-01')))];
        } elseif (!empty($selection['full_month'])) {
            $range = ['start' => substr($range['start'], 0, 7) . '-01',
                'end' => date('Y-m-t', strtotime(substr($range['end'], 0, 7) . '-01'))];
        }
        if (!empty($selection['prior_year'])) {
            // Item reports compare exact selected dates; calendar-month reports
            // have already expanded their range before shifting the year.
            $range = ['start' => date('Y-m-d', strtotime($range['start'] . ' -1 year')),
                'end' => date('Y-m-d', strtotime($range['end'] . ' -1 year'))];
        }
        if (!empty($selection['annual_member_events'])) {
            $range['start'] = substr($range['end'], 0, 4) . '-01-01';
        }
        if (isset($selection['store_id'])) {
            $selectedStore = (int)$selection['store_id'];
            if (!in_array($selectedStore, array_map('intval', $stores), true)) {
                throw new \InvalidArgumentException('会员明细门店不在当前权限范围内');
            }
            $stores = [$selectedStore];
        }
        $factRange = !empty($selection['pre_sale_metric']) ? ['end' => $range['end']] : $range;
        $sixDimensionItem = $parent === 'product_monetization_six_dimension_item_performance';
        $needsProductProfile = $sixDimensionItem || $parent === 'operations_beauty_item';
        $actualRoot = (string)($selection['actual_category_root'] ?? '');
        if ($actualRoot !== '' && !isset($factRange['start'])) {
            // The registered reader needs the same full-history boundary as the parent first-purchase cohort.
            $factRange['start'] = '1970-01-01';
        }
        $facts = !empty($selection['classified_all'])
            ? $this->actualAllClassifiedRows($stores, $factRange)
            : (!empty($selection['registered_all'])
            ? $this->actualAllRows($stores, $factRange)
            : ($actualRoot !== ''
            ? ($needsProductProfile
                ? $this->currentCategoryRows($this->actualCategoryRows($stores, $factRange, $this->categoryIds($actualRoot, $input), in_array($parent, ['operations_beauty_item', 'product_monetization_six_dimension_item_performance'], true)), $this->categoryIds($actualRoot, $input))
                : $this->actualCategoryRows($stores, $factRange, $this->categoryIds($actualRoot, $input), in_array($parent, ['product_monetization_six_dimension_performance', 'product_monetization_six_dimension_market_distribution', 'product_monetization_private_item_performance', 'marketing_beauty_new_item', 'marketing_post_sale_performance'], true)))
            : ($sixDimensionItem
                ? $this->sixDimensionItemCashRows($stores, $factRange, (array)$selection['include'], true)
                : $this->cashRows($stores, $factRange, [], true, false, $parent === 'marketing_beauty_new_item'))));
        if ($parent === 'marketing_health_data') {
            // The health report's cash scope includes eligible recharge receipts;
            // use the registered reader for the same facts as its monthly cells.
            foreach ((new RegisteredMetricReadServices())->rechargeCashFacts(
                CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $factRange, $this->participantEmployeeId
            ) as $recharge) {
                $recharge['document_order_id'] = $recharge['order_id'];
                $recharge['document_no_snapshot'] = $recharge['order_no_snapshot'];
                $facts[] = $recharge;
            }
        }
        $countMetric = ($parent === 'marketing_acquisition_pre_sale'
                && in_array($metric, ['new_customer_actual', 'transition_people'], true))
            || ($parent === 'operations_beauty_item' && $metric === 'deal_people')
            || ($parent === 'marketing_referral_pre_sale'
                && in_array($metric, ['new_customer_actual', 'transition_people'], true))
            || ($parent === 'marketing_post_sale_performance'
                && in_array($metric, ['deal_people', 'deal_visits'], true))
            || ($parent === 'marketing_beauty_new_item'
                && in_array($metric, ['deal_people', 'annual_deal_people', 'annual_repeat_people'], true))
            || (in_array($parent, ['product_monetization_six_dimension_efficiency',
                    'product_monetization_private_efficiency'], true)
                && in_array($metric, ['deal_people', 'new_deal_people'], true));
        if ($countMetric) {
            // Count drilldowns select the same eligible receipts as their cells; money drilldowns stay complete.
            $facts = StoreReportImportedRepaymentCountScope::exclude($facts, $stores);
        }
        // R61 already uses its shared net-cash reader. Supplement only refund
        // payments without an effective sale allocation, so neither comparison
        // categories nor health cash drilldowns deduct the same refund twice.
        if (!empty($selection['comparison_category_refunds']) || !empty($selection['health_cash_refunds'])) {
            $facts = array_merge($facts, $this->comparisonStandaloneRefundRows($stores, $factRange));
        }
        if (!empty($selection['suppress_zero_amount'])) {
            $amountSelection = $selection;
            $amountSelection['count_mode'] = '';
            $amountRecords = $this->cashDetailDocuments($facts, $amountSelection);
            $netCents = array_sum(array_map(fn(array $record): int => $this->cents($record['receipt_amount']), $amountRecords));
        }
        if (!empty($selection['pre_sale_metric'])) {
            // The first/second receipt event is chosen from complete member
            // history, then rendered as documents in the selected month.
            if ($parent !== 'marketing_referral_pre_sale') {
                $facts = $this->filterSourceFacts($facts, (array)$selection['source_rule']);
            }
            if (!empty($selection['team'])) {
                $allowed = array_fill_keys(array_map('intval', (array)($selection['include'] ?? [])), true);
                $facts = array_values(array_filter($facts, static function (array $fact) use ($allowed, $selection): bool {
                    if (!isset($allowed[(int)($fact['category_id'] ?? 0)])) return false;
                    $partner = str_contains((string)($fact['category_path'] ?? ''), '合作');
                    return $partner === ($selection['team'] === 'team:partner');
                }));
            }
            $cashCohort = $facts;
            $facts = $parent === 'marketing_referral_pre_sale'
                ? $this->marketingReferralDetailFacts($facts, $range, (string)$selection['pre_sale_metric'])
                : $this->preSaleDetailFacts($facts, $range, (string)$selection['pre_sale_metric'], !empty($selection['minimum_first_amount']));
            if (in_array($parent, ['marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true)
                && in_array((string)$selection['pre_sale_metric'], ['transition_amount', 'first_year_amount'], true)) {
                $actualRange = ['start' => substr($range['start'], 0, 4) . '-01-01', 'end' => $range['end']];
                $facts = $this->preSaleActualMoneyFacts($parent, $cashCohort,
                    $this->actualAllRows($stores, $actualRange), $range, (string)$selection['pre_sale_metric']);
            }
        }
        $records = isset($netCents) && $netCents === 0 ? [] : $this->cashDetailDocuments($facts, $selection);
        if (!empty($selection['annual_member_events'])) {
            $records = $this->annualItemMemberDetailRecords($facts, $selection);
        }
        if (!empty($selection['min_total_cents'])) {
            $netCents = array_sum(array_map(fn(array $record): int => $this->cents($record['receipt_amount']), $records));
            if ($netCents < (int)$selection['min_total_cents']) $records = [];
        }
        if (!empty($selection['member_count'])) {
            $seen = []; $members = []; $memberAmounts = [];
            if (!empty($selection['member_min_amount_cents'])) {
                foreach ($records as $record) {
                    $id = (int)$record['member_id'];
                    $memberAmounts[$id] = (int)($memberAmounts[$id] ?? 0) + $this->cents($record['receipt_amount']);
                }
            }
            foreach ($records as $record) {
                $memberId = (int)$record['member_id'];
                if ($memberId <= 0 || isset($seen[$memberId])
                    || (!empty($selection['member_min_amount_cents'])
                        && (int)($memberAmounts[$memberId] ?? 0) < (int)$selection['member_min_amount_cents'])) continue;
                $seen[$memberId] = true;
                $record['receipt_amount'] = 1;
                $members[] = $record;
            }
            $records = $members;
        }
        $records = StoreUnifiedReportDetailFilter::apply($records, $input);
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $visible = !empty($input['_internal_all']) ? $records : array_slice($records, ($page - 1) * $limit, $limit);
        $memberIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)$row['member_id'], $visible))));
        $phones = [];
        foreach (array_chunk($memberIds, 500) as $ids) $phones += Db::name('user')->whereIn('uid', $ids)->column('phone', 'uid');
        $isSixDimension = $parent === 'product_monetization_six_dimension_performance' || $sixDimensionItem;
        $isRegisteredActual = $actualRoot !== '' || !empty($selection['registered_all'])
            || !empty($selection['classified_all'])
            || (in_array($parent, ['marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true)
                && in_array($metric, ['transition_amount', 'first_year_amount'], true));
        $this->attachCashDetailPagePhones($records, $visible, $phones,
            !empty($input['_internal_all']) ? 0 : ($page - 1) * $limit, $isSixDimension || $isRegisteredActual);
        $countMetric = !empty($selection['member_count']) || !empty($selection['count_mode'])
            || !empty($selection['annual_member_events']);
        $columns = [
            $this->column('member_name', '会员', '取收款关联销售事实的会员姓名快照；未建档显示游客。', false, true, 130),
            $this->column('member_phone', '手机号', '只读取当前页有权限收款会员的当前登记手机号。', false, false, 145),
        ];
        if ($isSixDimension || $isRegisteredActual) {
            $columns[] = $this->column('business_date', '业务日期', '取该笔成功收款事实的业务日期；同一单据按业务日期合并。', false, false, 112);
        }
        if ($sixDimensionItem) $columns[] = $this->column('store_name', '门店', '取该笔业务发生时保存的门店名称；只显示当前授权范围。', false, false, 140);
        $columns[] = $this->column('order_no', '单据号', '取成功收款分摊事实保存的业务单据号，同单同日合并其商品分摊。', false, false, 180);
        $amountExplanation = $parent === 'operations_annual_member_consumption'
            ? '仅汇总该会员成交商品名称不为“昊美A”的有效成功收款；卡项按原销售商品判断，卡内项目分摊仍计入；退款按成功日期以负数冲减。'
            : ($countMetric ? '按母报表会员、销售明细或退款事实的去重键计数；单据号用于核验来源。'
                : (!empty($selection['refund_only']) ? '只汇总成功退款的负向分摊事实，并按报表口径展示其绝对金额。'
                    : '按该报表指标的分类与月份汇总有效成功收款，退款以负数冲减。'));
        $columns[] = $this->column('receipt_amount', $countMetric ? $this->cashCountDetailLabel($parent, $metric)
            : (!empty($selection['refund_only']) ? '退款金额'
                : (($parent === 'marketing_health_data' && $metric === 'all_actual_amount') || $isRegisteredActual
                    ? '实际业绩金额' : '收款金额')),
            $amountExplanation, true, false, 120);
        if ($countMetric) $columns[count($columns) - 1]['value_type'] = 'integer';
        $result = $this->result('运营收款事实明细', $columns, $records, $range, $input);
        if ($parent === 'operations_annual_member_consumption') $result['metric_version'] = 'annual-member-exact-sale-product-r62';
        if ($parent === 'marketing_health_data') $result['metric_version'] = 'marketing-health-cash-refund-actual-r75';
        if ($isRegisteredActual) $result['metric_version'] = $this->registeredActualMetricVersion();
        if (in_array($parent, ['marketing_beauty_new_item', 'marketing_post_sale_performance',
            'product_monetization_six_dimension_efficiency', 'product_monetization_private_efficiency'], true)) {
            $result['metric_version'] = $this->registeredActualMetricVersion() . '+imported-and-old-card-repayment-people-r82';
        }
        if ($parent === 'marketing_referral_pre_sale') {
            $result['metric_version'] = 'marketing-referral-source-cohort-r71+' . $this->registeredActualMetricVersion()
                . '+imported-and-old-card-repayment-people-r82';
        }
        return $result;
    }

    /** Count detail values remain keyed by metric code, but headers must use the parent report's business name. */
    private function cashCountDetailLabel(string $parent, string $metric): string
    {
        $labels = [
            'annual_deal_people' => '年度成交人头',
            'annual_repeat_people' => '年度购买≥2次人数',
            'consuming_members' => '消费人数',
            'deal_people' => in_array($parent, ['marketing_beauty_new_item', 'marketing_post_sale_performance', 'operations_beauty_item'], true)
                ? '成交人头' : '成交人数',
            'deal_visits' => '成交人次',
            'new_customer_actual' => '新客实际达成人数',
            'new_deal_people' => '成交人数',
            'refund_count' => '退款数量',
            'transition_people' => '售中转换人数',
        ];
        // A new count metric must not expose its internal key as user-facing text.
        return $labels[$metric] ?? '人数';
    }

    /** Enrich only authorized page rows, then write back to the array result() will page again. */
    private function attachCashDetailPagePhones(array &$records, array $visible, array $phones, int $start, bool $keepDate): void
    {
        foreach ($visible as $offset => $record) {
            $index = $start + $offset;
            $records[$index]['member_phone'] = (string)($phones[(int)$record['member_id']] ?? '');
            unset($records[$index]['member_id']);
            if (!$keepDate) unset($records[$index]['business_date']);
        }
    }

    /** Service-backed figures drill into completed service facts, never payment allocations. */
    private function operationsServiceFactDetail(array $stores, array $range, array $input): array
    {
        $parent = trim((string)($input['parent_report'] ?? ''));
        $metric = trim((string)($input['metric_code'] ?? ''));
        $health = in_array($parent, ['operations_health_data', 'marketing_health_data'], true);
        $customerStatus = in_array($parent, ['operations_customer_status_bdegh', 'marketing_customer_status'], true);
        $preSaleExperience = in_array($parent, ['marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true)
            && $metric === 'experience_actual';
        $efficiencyVisit = in_array($parent, ['product_monetization_six_dimension_efficiency',
                'product_monetization_private_efficiency'], true)
            && in_array($metric, ['visit_count', 'new_visit_count'], true);
        $postSaleService = $parent === 'marketing_post_sale_performance'
            && in_array($metric, ['active_members', 'post_sale_visits'], true);
        $itemExperience = $parent === 'marketing_beauty_new_item' && $metric === 'experience_people';
        $healthMetrics = $parent === 'operations_health_data'
            ? ['active_members', 'post_sale_visits', 'consumption_amount']
            : ['active_members', 'post_sale_visits', 'beauty_service_count', 'consumption_amount'];
        $statusMetrics = ['active_members', 'one_to_two_visits', 'three_plus_visits', 'regular_customers', 'inactive_customers'];
        if ((self::REPORTS[$parent]['family'] ?? '') !== '运营中心数据'
            || (!($health && in_array($metric, $healthMetrics, true))
                && !($customerStatus && in_array($metric, $statusMetrics, true))
                && !$preSaleExperience && !$postSaleService && !$itemExperience && !$efficiencyVisit
                && ((self::REPORTS[$parent]['kind'] ?? '') !== 'efficiency'
                    || !in_array($metric, ['service_count', 'consumption_amount'], true)))) {
            throw new \InvalidArgumentException('该指标没有可逐笔对账的服务明细');
        }
        $team = trim((string)($input['team_key'] ?? ''));
        if (!$health && !$customerStatus && !$preSaleExperience && !$postSaleService && !$itemExperience
            && !in_array($team, ['team:self', 'team:partner'], true)) {
            throw new \InvalidArgumentException('服务明细缺少有效团队');
        }
        $month = trim((string)($input['drill_month'] ?? ''));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)
            || $month < substr($range['start'], 0, 7) || $month > substr($range['end'], 0, 7)) {
            throw new \InvalidArgumentException('下钻月份不在当前统计范围内');
        }
        // Efficiency rows use complete calendar months even when the query range is partial.
        $range = ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01'))];
        if ($customerStatus) {
            $records = $this->customerStatusDetailRecords($stores, $range, $metric);
        } elseif ($preSaleExperience) {
            $records = $this->preSaleExperienceDetailRecords($this->serviceRows($stores, $range, []), $parent);
        } elseif ($postSaleService) {
            $records = $this->healthServiceDetailRecords($this->serviceRows($stores, $range, []),
                'marketing_health_data', $metric);
        } elseif ($itemExperience) {
            $groupKey = trim((string)($input['item_group_key'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/D', $groupKey)) throw new \InvalidArgumentException('品项服务明细缺少有效分组标识');
            $records = $this->itemExperienceDetailRecords($this->serviceRows($stores, $range, []),
                $groupKey, $this->categoryIds('生美', $input));
        } else {
            $categoryIds = $health ? [] : $this->categoryIds((string)self::REPORTS[$parent]['category'], $input);
            $facts = !$health && $categoryIds === [] ? [] : $this->serviceRows($stores, $range, $categoryIds,
                false, !$efficiencyVisit, $efficiencyVisit);
            if ($efficiencyVisit) {
                $firstPurchaseDates = $metric === 'new_visit_count' && $categoryIds !== []
                    ? $this->firstPositivePurchaseDates(StoreReportImportedRepaymentCountScope::exclude(
                        $this->cashRows($stores, ['end' => $range['end']], $categoryIds), $stores)) : [];
                $records = $this->efficiencyVisitDetailRecords($facts, $team, $metric, $firstPurchaseDates);
            } else {
                $records = $health ? $this->healthServiceDetailRecords($facts, $parent, $metric)
                    : $this->serviceDetailRecords($facts, $team, $metric);
            }
        }
        $records = StoreUnifiedReportDetailFilter::apply($records, $input);
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $visible = !empty($input['_internal_all']) ? $records : array_slice($records, ($page - 1) * $limit, $limit);
        $memberIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)$row['member_id'], $visible))));
        $phones = [];
        foreach (array_chunk($memberIds, 500) as $ids) $phones += Db::name('user')->whereIn('uid', $ids)->column('phone', 'uid');
        // result() pages $records again; enrich its page positions, not a detached slice.
        $start = !empty($input['_internal_all']) ? 0 : ($page - 1) * $limit;
        foreach ($visible as $offset => $record) {
            $index = $start + $offset;
            $records[$index]['member_phone'] = (string)($phones[(int)$record['member_id']] ?? '');
            unset($records[$index]['member_id']);
        }
        $count = $customerStatus || in_array($metric, ['service_count', 'active_members', 'post_sale_visits',
            'beauty_service_count', 'experience_actual', 'experience_people', 'visit_count', 'new_visit_count'], true);
        $valueLabel = ['active_members' => '活客数', 'post_sale_visits' => '售后人次',
            'experience_actual' => '体验实际达成', 'experience_people' => '体验人数',
            'visit_count' => '见诊人数', 'new_visit_count' => '新客见诊人数',
            'beauty_service_count' => '生美项目数', 'service_count' => '服务项目数',
            'one_to_two_visits' => '到店1-2次人数', 'three_plus_visits' => '到店3次以上人数',
            'regular_customers' => '常客数', 'inactive_customers' => '死客数'][$metric] ?? '耗卡金额';
        $columns = [
            $this->column('member_name', '会员', '取服务事实的会员姓名快照；未建档显示游客。', false, true, 130),
            $this->column('member_phone', '手机号', '只查询当前页有权限服务会员的当前登记手机号。', false, false, 145),
            $this->column('order_no', '单据号', '取完成服务事实的服务记录号。', false, false, 180),
            $this->column('detail_value', $valueLabel, $count
                ? ($efficiencyVisit ? '取当月已完成且带体验标签的该团队项目，按团队和会员去重；新客须截至体验业务日仍无该分类有效正金额收款，旧导入数据或旧卡录入原单的欠款补交不算购买。'
                    : '按母报表的来源、非体验及会员去重口径读取已完成服务事实。')
                : '取同一服务来源行成功记录的消耗业绩事实。', true, false, 120),
        ];
        if ($metric === 'post_sale_visits') {
            array_splice($columns, 2, 0, [
                $this->column('business_date', '业务日期', '按完成服务的业务日期归属；同一会员同店同日只计一次。', false, false, 110),
                $this->column('store_name', '门店', '取服务事实发生时的门店名称；跨店服务分别计人次。', false, false, 140),
            ]);
            foreach ($columns as &$column) {
                if ($column['key'] === 'order_no') {
                    $column['source_explanation'] = '展示该会员同店同日全部服务单号；按任一单号筛选仍返回这一人次。';
                }
            }
            unset($column);
        }
        if ($count) $columns[count($columns) - 1]['value_type'] = 'integer';
        $result = $this->result('运营服务事实明细', $columns, $records, $range, $input);
        if (in_array($parent, ['product_monetization_six_dimension_efficiency',
            'product_monetization_private_efficiency'], true)) {
            $result['metric_version'] = $this->registeredActualMetricVersion() . '+imported-and-old-card-repayment-people-r82';
        }
        return $result;
    }

    /** Keep one service fact per row so its count and consumption reconcile exactly. */
    private function serviceDetailRecords(array $facts, string $team, string $metric): array
    {
        $records = [];
        foreach ($this->teamFacts($facts, $team === 'team:partner') as $fact) {
            $records[] = [
                'member_id' => (int)$fact['member_id'],
                'member_name' => (int)$fact['member_id'] > 0 ? (string)($fact['member_name_snapshot'] ?? '') : '游客',
                'order_no' => trim((string)($fact['service_record_no'] ?? '')) ?: (trim((string)($fact['document_no_snapshot'] ?? '')) ?: (string)$fact['service_fact_id']),
                'detail_value' => $metric === 'service_count' ? max(0, (int)$fact['quantity'])
                    : $this->money((int)$fact['consumption_cents']),
            ];
        }
        return $records;
    }

    /** One completed experience service represents each member in the team's monthly consultation detail. */
    private function efficiencyVisitFacts(array $facts, array $firstPurchaseDates, bool $newOnly): array
    {
        $members = [];
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId <= 0 || (int)($fact['is_experience'] ?? 0) !== 1) continue;
            $firstDate = (string)($firstPurchaseDates[$memberId] ?? '');
            if ($newOnly && $firstDate !== '' && $firstDate <= (string)$fact['business_date']) continue;
            $members[$memberId] ??= $fact;
        }
        return array_values($members);
    }

    /** A positive six-dimension receipt on or before the service day makes that visit an existing-customer visit. */
    private function firstPositivePurchaseDates(array $facts, array $dates = []): array
    {
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            $date = (string)($fact['business_date'] ?? '');
            if ($memberId <= 0 || (int)($fact['amount_cents'] ?? 0) <= 0 || $date === '') continue;
            if (!isset($dates[$memberId]) || $date < $dates[$memberId]) $dates[$memberId] = $date;
        }
        return $dates;
    }

    /** The popup uses exactly the same member cohort as the displayed consultation count. */
    private function efficiencyVisitDetailRecords(array $facts, string $team, string $metric, array $firstPurchaseDates): array
    {
        $records = [];
        foreach ($this->efficiencyVisitFacts($this->teamFacts($facts, $team === 'team:partner'),
            $firstPurchaseDates, $metric === 'new_visit_count') as $fact) {
            $records[] = [
                'member_id' => (int)$fact['member_id'],
                'member_name' => (string)($fact['member_name_snapshot'] ?? ''),
                'order_no' => trim((string)($fact['service_record_no'] ?? ''))
                    ?: (trim((string)($fact['document_no_snapshot'] ?? '')) ?: (string)$fact['service_fact_id']),
                'detail_value' => 1,
            ];
        }
        return $records;
    }

    /** The marketing pre-sale experience cohort uses completed source-tagged services. */
    private function preSaleExperienceDetailRecords(array $facts, string $parent): array
    {
        $records = []; $seen = [];
        foreach ($this->filterSourceFacts($facts, $this->preSaleRule($parent)) as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId <= 0 || (int)($fact['is_experience'] ?? 0) !== 1 || isset($seen[$memberId])) continue;
            $seen[$memberId] = true;
            $records[] = [
                'member_id' => $memberId,
                'member_name' => (string)($fact['member_name_snapshot'] ?? ''),
                'order_no' => trim((string)($fact['service_record_no'] ?? ''))
                    ?: (trim((string)($fact['document_no_snapshot'] ?? '')) ?: (string)$fact['service_fact_id']),
                'detail_value' => 1,
            ];
        }
        return $records;
    }

    /** Match the category/name experience cohort used by marketingBeautyNewItemReport. */
    private function itemExperienceDetailRecords(array $facts, string $groupKey, array $categoryIds): array
    {
        $allowed = array_fill_keys(array_map('intval', $categoryIds), true);
        $records = []; $seen = [];
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId <= 0 || isset($seen[$memberId]) || (int)($fact['is_experience'] ?? 0) !== 1
                || $this->marketingItemGroupKey(
                    $this->marketingCategoryPath((string)($fact['category_path'] ?? '')),
                    trim((string)($fact['project_name_snapshot'] ?? ''))) !== $groupKey
                || !isset($allowed[(int)($fact['category_id'] ?? 0)])) continue;
            $seen[$memberId] = true;
            $records[] = [
                'member_id' => $memberId,
                'member_name' => (string)($fact['member_name_snapshot'] ?? ''),
                'order_no' => trim((string)($fact['service_record_no'] ?? ''))
                    ?: (trim((string)($fact['document_no_snapshot'] ?? '')) ?: (string)$fact['service_fact_id']),
                'detail_value' => 1,
            ];
        }
        return $records;
    }

    /** Member cohorts are grouped by the same completed, non-experience facts as customer-status totals. */
    private function customerStatusDetailRecords(array $stores, array $range, string $metric): array
    {
        $cutoff = date('Y-m-d', strtotime($range['end'] . ' -90 days'));
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)->where('sv.service_status', 'completed')
            ->where('sv.member_id', '>', 0);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        \app\services\query\metric\StoreCustomerVisitEligibility::apply($query, 'sv');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        }
        if ($metric === 'inactive_customers') $query->where('sv.business_date', '<=', $range['end']);
        else $query->whereBetween('sv.business_date', [$metric === 'regular_customers' ? $cutoff : $range['start'], $range['end']]);
        $members = $query->fieldRaw('sv.member_id,MAX(sv.member_name_snapshot) member_name,
            MAX(sv.service_record_no) service_no,MAX(sv.document_no_snapshot) document_no,
            MAX(sv.service_fact_id) service_fact_id,MAX(sv.business_date) last_service_date,
            COUNT(DISTINCT sv.business_date) visit_days')
            ->group('sv.member_id')->select()->toArray();
        $records = [];
        foreach ($members as $member) {
            $visitDays = (int)($member['visit_days'] ?? 0);
            if (($metric === 'one_to_two_visits' && ($visitDays < 1 || $visitDays > 2))
                || ($metric === 'three_plus_visits' && $visitDays < 3)
                || ($metric === 'regular_customers' && $visitDays !== 1)
                || ($metric === 'inactive_customers' && (string)$member['last_service_date'] >= $cutoff)) continue;
            $records[] = [
                'member_id' => (int)$member['member_id'],
                'member_name' => (string)($member['member_name'] ?? ''),
                'order_no' => trim((string)($member['service_no'] ?? ''))
                    ?: (trim((string)($member['document_no'] ?? '')) ?: (string)($member['service_fact_id'] ?? '')),
                'detail_value' => 1,
            ];
        }
        return $records;
    }

    /** Health detail rows are selected from the same completed service facts as monthly cells. */
    private function healthServiceDetailRecords(array $facts, string $parent, string $metric): array
    {
        if ($parent === 'operations_health_data' && in_array($metric, ['active_members', 'post_sale_visits'], true)) {
            $facts = $this->filterSourceFacts($facts, ['source_codes' => ['A']]);
        }
        if (in_array($metric, ['active_members', 'post_sale_visits'], true)) {
            $facts = \app\services\query\metric\StoreCustomerVisitEligibility::filter($facts);
        }
        if ($metric === 'post_sale_visits') return $this->dailyVisitDetailRecords($facts);
        if (in_array($metric, ['beauty_service_count', 'consumption_amount'], true)) {
            $facts = $this->categoryServices($facts, '生美');
        }
        $records = []; $seenMembers = [];
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($metric === 'active_members') {
                if ($memberId <= 0 || isset($seenMembers[$memberId])) continue;
                $seenMembers[$memberId] = true;
            }
            $records[] = [
                'member_id' => $memberId,
                'member_name' => $memberId > 0 ? (string)($fact['member_name_snapshot'] ?? '') : '游客',
                'order_no' => trim((string)($fact['service_record_no'] ?? ''))
                    ?: (trim((string)($fact['document_no_snapshot'] ?? '')) ?: (string)($fact['service_fact_id'] ?? '')),
                'detail_value' => $metric === 'consumption_amount'
                    ? $this->money((int)($fact['consumption_cents'] ?? 0))
                    : ($metric === 'active_members' ? 1 : max(0, (int)($fact['quantity'] ?? 0))),
            ];
        }
        return $records;
    }

    /** One detail row explains each eligible store/member/business-day visit. */
    private function dailyVisitDetailRecords(array $facts): array
    {
        $groups = [];
        foreach ($facts as $fact) {
            $key = \app\services\query\metric\StoreCustomerVisitEligibility::dailyVisitKey($fact);
            if ($key === null) continue;
            $memberId = (int)($fact['member_id'] ?? 0);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'member_id' => $memberId,
                    'member_name' => $memberId > 0 ? (string)($fact['member_name_snapshot'] ?? '') : '游客',
                    'business_date' => (string)$fact['business_date'],
                    'store_name' => (string)($fact['store_name'] ?? ''),
                    'detail_value' => 1,
                    '_order_numbers' => [],
                ];
            }
            $number = trim((string)($fact['service_record_no'] ?? ''))
                ?: (trim((string)($fact['document_no_snapshot'] ?? ''))
                    ?: (string)($fact['service_fact_id'] ?? ''));
            if ($number !== '') $groups[$key]['_order_numbers'][$number] = true;
        }
        $records = [];
        foreach ($groups as $group) {
            $numbers = array_keys($group['_order_numbers']);
            sort($numbers, SORT_STRING);
            unset($group['_order_numbers']);
            $group['order_no'] = implode('、', $numbers);
            $records[] = $group;
        }
        usort($records, static function (array $a, array $b): int {
            return [$a['business_date'], $a['store_name'], $a['member_id'], $a['order_no']]
                <=> [$b['business_date'], $b['store_name'], $b['member_id'], $b['order_no']];
        });
        return $records;
    }

    /** Return include/exclude category IDs for a genuine cash-backed column. */
    private function operationsCashDetailSelection(string $parent, string $metric, array $input = []): array
    {
        $definition = self::REPORTS[$parent] ?? [];
        if (($definition['family'] ?? '') !== '运营中心数据') throw new \InvalidArgumentException('不支持的运营报表明细');
        if (($definition['kind'] ?? '') === 'performance_total') {
            if ($metric === 'total_performance') return ['include' => [], 'exclude' => [], 'classified_all' => true];
            $roots = ['beauty' => '生美', 'six_dimension' => '六维', 'haomei' => '昊美', 'private' => '花园'];
            foreach ($roots as $key => $name) {
                if ($metric === $key . '_performance') return ['include' => $this->categoryIds($name, []),
                    'exclude' => [], 'require_include' => true, 'classified_all' => true];
            }
            if (in_array($metric, ['other_performance', 'external_subtotal'], true)) {
                $excluded = [];
                foreach ($roots as $key => $name) {
                    if ($metric === 'external_subtotal' && $key !== 'beauty') continue;
                    $excluded = array_merge($excluded, $this->categoryIds($name, []));
                }
                return ['include' => [], 'exclude' => $excluded, 'classified_all' => true];
            }
        }
        if (($definition['kind'] ?? '') === 'category_total') {
            $root = (string)($definition['category'] ?? '');
            // Parent and popup must resolve the same registered root; client metric keys only narrow it.
            if ($metric === 'cash_performance') return ['include' => $this->categoryIds($root, $input), 'exclude' => [],
                'require_include' => true, 'actual_category_root' => in_array($root, ['生美', '六维', '昊美', '花园'], true) ? $root : ''];
            if ($root === '生美' && preg_match('/^beauty_(?:category|direct)_[1-9][0-9]*$/D', $metric)) {
                // The key must still be a current declared column under the
                // authorized root; a stale/forged category ID cannot widen scope.
                $leaf = $this->beautyCategoryStructure()['leaves'][$metric] ?? null;
                $ids = $leaf === null ? [] : array_values(array_intersect($leaf['ids'], $this->categoryIds($root, $input)));
                return ['include' => $ids, 'exclude' => [], 'require_include' => true,
                    'actual_category_root' => '生美'];
            }
            if ($root === '六维' && preg_match('/^six_(?:category|direct)_[1-9][0-9]*$/D', $metric)) {
                // A clicked ID is never trusted by itself: it must still be a
                // currently declared leaf under the authorized six-dimension root.
                $leaf = $this->sixDimensionCategoryStructure()['leaves'][$metric] ?? null;
                $ids = $leaf === null ? [] : array_values(array_intersect($leaf['ids'], $this->categoryIds($root, $input)));
                return ['include' => $ids, 'exclude' => [], 'require_include' => true,
                    'actual_category_root' => '六维'];
            }
            if ($root === '花园' && preg_match('/^partner_category_([1-9][0-9]*)$/D', $metric, $matches)) {
                // Re-resolve the current switch and root for a clicked column;
                // a stale or forged category key must not widen detail access.
                foreach ($this->enabledSecondLevelPartnerCategories($root) as $partnerCategory) {
                    if ((int)$partnerCategory['id'] === (int)$matches[1]) {
                        return ['include' => $this->descendants([(int)$partnerCategory['id']]),
                            'exclude' => [], 'require_include' => true, 'actual_category_root' => $root];
                    }
                }
                return ['include' => [], 'exclude' => [], 'require_include' => true];
            }
            $labels = [
                '昊美' => ['昊美m', '昊美n'],
            ];
            foreach ($labels[$root] ?? [] as $label) {
                if ($metric === 'category_' . $this->key($label)) {
                    return ['include' => $this->descendantsByPath($root . ' / ' . $label), 'exclude' => [],
                        'require_include' => true, 'actual_category_root' => $root];
                }
            }
        }
        if (($definition['kind'] ?? '') === 'market') {
            $include = $this->categoryIds((string)$definition['category'], $input);
            $actualRoot = in_array($parent, [
                'product_monetization_beauty_market_distribution',
                'product_monetization_private_market_distribution',
                'product_monetization_six_dimension_market_distribution',
            ], true) ? (string)$definition['category'] : '';
            if ($metric === 'completed_amount') return ['include' => $include, 'exclude' => [], 'require_include' => true,
                'actual_category_root' => $actualRoot];
            if (preg_match('/^company_([1-9][0-9]*)_amount$/D', $metric, $matches)) {
                return ['include' => $include, 'exclude' => [], 'require_include' => true, 'company_id' => $matches[1],
                    'actual_category_root' => $actualRoot];
            }
        }
        if (($definition['kind'] ?? '') === 'item') {
            if ($parent === 'marketing_beauty_new_item' && $metric === 'month_total_performance') {
                return ['include' => [], 'exclude' => [], 'registered_all' => true, 'full_month' => true];
            }
            if ($parent === 'product_monetization_private_item_performance'
                && in_array($metric, ['cash_performance', 'last_year_performance'], true)) {
                // A merged category/name row must never drill into only one of
                // its store or platform product IDs. Recompute the opaque key
                // against the authorized receipt facts after category resolution.
                $groupKey = trim((string)($input['item_group_key'] ?? ''));
                if (!preg_match('/^[a-f0-9]{64}$/D', $groupKey)) throw new \InvalidArgumentException('私密品项明细缺少有效分组标识');
                return ['include' => $this->categoryIds('花园', $input), 'exclude' => [],
                    'require_include' => true, 'private_item_group_key' => $groupKey,
                    'actual_category_root' => '花园',
                    'prior_year' => $metric === 'last_year_performance'];
            }
            if ($parent === 'operations_beauty_item' && in_array($metric, ['cash_performance', 'deal_people'], true)) {
                // Recompute the product/project and exact snapshot-name group
                // against authorized facts. A client-supplied display name or
                // arbitrary list of store IDs must never define a drilldown.
                $groupKey = trim((string)($input['item_group_key'] ?? ''));
                if (!preg_match('/^[a-f0-9]{64}$/D', $groupKey)) throw new \InvalidArgumentException('品项明细缺少有效分组标识');
                // Match the all-price summary even when an old URL still carries price parameters.
                $selection = ['include' => $this->categoryIds('生美', $input), 'exclude' => [],
                    'require_include' => true, 'beauty_item_group_key' => $groupKey];
                if ($metric === 'cash_performance') $selection['actual_category_root'] = '生美';
                if ($metric === 'deal_people') {
                    $selection['member_count'] = true;
                    $selection['member_min_amount_cents'] = 100000;
                }
                return $selection;
            }
            if ($parent === 'marketing_beauty_new_item' && in_array($metric,
                ['item_performance', 'deal_people', 'annual_deal_people', 'annual_repeat_people'], true)) {
                // The row key is recomputed from authorized category/name facts;
                // a store-local item ID or client display name cannot select the group.
                $groupKey = trim((string)($input['item_group_key'] ?? ''));
                if (!preg_match('/^[a-f0-9]{64}$/D', $groupKey)) throw new \InvalidArgumentException('品项明细缺少有效分组标识');
                $selection = ['include' => $this->categoryIds('生美', $input), 'exclude' => [],
                    'require_include' => true, 'marketing_item_group_key' => $groupKey, 'full_month' => true];
                if ($metric === 'item_performance') $selection['actual_category_root'] = '生美';
                if ($metric === 'deal_people') $selection['member_count'] = true;
                if (in_array($metric, ['annual_deal_people', 'annual_repeat_people'], true)) {
                    $selection['annual_member_events'] = $metric;
                }
                return $selection;
            }
            if (in_array($metric, ['cash_performance', 'last_year_performance'], true)) {
                $itemId = trim((string)($input['item_id'] ?? ''));
                if (!preg_match('/^[1-9][0-9]*$/D', $itemId)) throw new \InvalidArgumentException('品项明细缺少有效商品ID');
                $selection = ['include' => $this->categoryIds((string)$definition['category'], $input),
                    'exclude' => [], 'require_include' => true, 'item_id' => $itemId];
                if ($parent === 'product_monetization_beauty_item') $selection['actual_category_root'] = '生美';
                if ($parent === 'product_monetization_six_dimension_item_performance') {
                    // R61 item_id is a platform identity; allowed categories and
                    // store scope still constrain both normal and refund facts.
                    unset($selection['item_id']);
                    $selection['platform_product_id'] = $itemId;
                    $selection['actual_category_root'] = '六维';
                }
                // Other item rows still use product identity plus exact current
                // category; marketing rows above use their category/name key.
                $path = trim((string)($input['detail_category_path'] ?? ''));
                if ($path === '') throw new \InvalidArgumentException('品项明细缺少有效分类路径');
                $selection['category_path'] = $path;
                if ($metric === 'last_year_performance') $selection['prior_year'] = true;
                return $selection;
            }
        }
        if (($definition['kind'] ?? '') === 'comparison') {
            if (preg_match('/^(?:(six_dimension|private|haomei|beauty)_)?(current|previous)_amount$/D', $metric, $matches)) {
                $roots = ['six_dimension' => '六维', 'private' => '花园', 'haomei' => '昊美', 'beauty' => '生美'];
                $root = $roots[$matches[1] ?? ''] ?? '';
                return ['include' => $root === '' ? [] : $this->categoryIds($root, []), 'exclude' => [],
                    'require_include' => $root !== '', 'full_month' => true, 'prior_year' => $matches[2] === 'previous',
                    'comparison_category_refunds' => $root !== ''];
            }
            if (in_array($metric, ['all_current_amount', 'all_previous_amount'], true)) {
                return ['include' => [], 'exclude' => [], 'full_month' => true,
                    'prior_year' => $metric === 'all_previous_amount'];
            }
        }
        if (($definition['kind'] ?? '') === 'health'
            && (($parent === 'marketing_health_data' && in_array($metric, ['all_current_amount', 'refund_amount', 'all_actual_amount', 'all_previous_amount'], true))
                || ($parent === 'operations_health_data' && $metric === 'consuming_members'))) {
            return ['include' => [], 'exclude' => [], 'full_month' => true,
                'prior_year' => $metric === 'all_previous_amount',
                'member_count' => $metric === 'consuming_members',
                'source_codes' => $metric === 'consuming_members' ? ['A'] : [],
                'health_cash_refunds' => $parent === 'marketing_health_data'
                    && in_array($metric, ['refund_amount', 'all_actual_amount'], true),
                'positive_only' => $parent === 'marketing_health_data'
                    && in_array($metric, ['all_current_amount', 'all_previous_amount'], true),
                'refund_only' => $parent === 'marketing_health_data' && $metric === 'refund_amount'];
        }
        if (($definition['kind'] ?? '') === 'member_consumption' && $metric === 'cash_performance') {
            $memberId = trim((string)($input['member_id'] ?? ''));
            $storeId = trim((string)($input['detail_store_id'] ?? ''));
            if (!preg_match('/^[1-9][0-9]*$/D', $memberId)
                || !preg_match('/^[1-9][0-9]*$/D', $storeId)) {
                throw new \InvalidArgumentException('会员消费明细缺少有效会员或门店ID');
            }
            // The list threshold and popup use the same registered member
            // receipts/refunds, including recharge and unallocated repayments.
            return ['include' => [], 'exclude' => [], 'registered_all' => true,
                'exclude_sale_product_name' => self::ANNUAL_MEMBER_EXCLUDED_PRODUCT_NAME,
                'member_id' => $memberId, 'store_id' => $storeId,
                'company_id' => (string)($input['company_dimension_id'] ?? ''),
                'min_total_cents' => 3000000];
        }
        if (($definition['kind'] ?? '') === 'pre_sale' && $parent !== 'operations_pre_sale_bdegh'
            && in_array($metric, ['deal_people', 'deal_amount', 'new_customer_actual', 'new_customer_amount_actual',
                'transition_people', 'transition_amount', 'first_year_amount'], true)) {
            $people = in_array($metric, ['deal_people', 'new_customer_actual', 'transition_people'], true);
            return ['include' => [], 'exclude' => [], 'full_month' => true,
                'pre_sale_metric' => $metric, 'source_rule' => $this->preSaleRule($parent),
                'member_count' => $people, 'minimum_first_amount' => in_array($metric, ['deal_people', 'deal_amount'], true)];
        }
        if (($definition['kind'] ?? '') === 'post_sale'
            && in_array($metric, ['post_sale_amount', 'deal_people', 'deal_visits'], true)) {
            return ['include' => $this->categoryIds('生美', []), 'exclude' => [], 'require_include' => true,
                'full_month' => true, 'member_count' => $metric === 'deal_people',
                'count_mode' => $metric === 'deal_visits' ? 'source_line' : '',
                'suppress_zero_amount' => $metric === 'deal_visits',
                'actual_category_root' => $metric === 'post_sale_amount' ? '生美' : ''];
        }
        if (($definition['kind'] ?? '') === 'efficiency'
            && in_array($metric, ['deal_amount', 'deal_people', 'new_deal_people', 'new_deal_amount',
                'refund_amount', 'refund_count'], true)) {
            $team = trim((string)($input['team_key'] ?? ''));
            if ($team !== '' && !in_array($team, ['team:self', 'team:partner'], true)) throw new \InvalidArgumentException('团队下钻条件无效');
            $selection = ['include' => $this->categoryIds((string)$definition['category'], $input), 'exclude' => [],
                'require_include' => true, 'full_month' => true, 'team' => $team,
                'member_count' => in_array($metric, ['deal_people', 'new_deal_people'], true),
                'refund_only' => in_array($metric, ['refund_amount', 'refund_count'], true),
                'count_mode' => $metric === 'refund_count' ? 'refund_fact' : ''];
            if (in_array($parent, ['product_monetization_six_dimension_efficiency',
                'product_monetization_private_efficiency'], true)) {
                $selection['actual_category_root'] = (string)$definition['category'];
            }
            if (in_array($metric, ['new_deal_people', 'new_deal_amount'], true)) {
                $selection['pre_sale_metric'] = $metric === 'new_deal_people' ? 'new_customer_actual' : 'new_customer_amount_actual';
                $selection['source_rule'] = [];
            }
            return $selection;
        }
        throw new \InvalidArgumentException('该指标尚无可逐单对账的收款明细');
    }

    /** Group only selected signed allocations into the four-field receipt contract. */
    private function cashDetailDocuments(array $facts, array $selection): array
    {
        $include = array_fill_keys(array_map('intval', $selection['include'] ?? []), true);
        $exclude = array_fill_keys(array_map('intval', $selection['exclude'] ?? []), true);
        $excludedSaleName = (string)($selection['exclude_sale_product_name'] ?? '');
        $excludedSaleFacts = $excludedSaleName === '' ? [] : $this->saleFactIdsByExactProductName($facts, $excludedSaleName);
        if (!empty($selection['require_include']) && $include === []) return [];
        $documents = [];
        foreach ($facts as $fact) {
            if (!empty($selection['source_codes'])) {
                $source = strtoupper(substr(trim((string)($fact['business_source_label'] ?? '')), 0, 1));
                if (!in_array($source, (array)$selection['source_codes'], true)) continue;
            }
            $categoryId = (int)($fact['category_id'] ?? 0);
            if (($include !== [] && !isset($include[$categoryId])) || isset($exclude[$categoryId])) continue;
            if ($excludedSaleName !== '' && $this->matchesExcludedSaleProduct($fact, $excludedSaleFacts, $excludedSaleName)) continue;
            if (!empty($selection['team'])) {
                $partner = str_contains((string)($fact['category_path'] ?? ''), '合作');
                if ($partner !== ($selection['team'] === 'team:partner')) continue;
            }
            if (!empty($selection['positive_only']) && (int)$fact['amount_cents'] <= 0) continue;
            if (!empty($selection['refund_only']) && (int)$fact['amount_cents'] >= 0) continue;
            if (isset($selection['beauty_item_group_key'])
                && (!in_array((int)($fact['product_type'] ?? -1), [0, 6], true)
                    || $this->beautyItemGroupKey(trim((string)($fact['item_name'] ?? '')))
                        !== (string)$selection['beauty_item_group_key'])) continue;
            if (isset($selection['private_item_group_key'])
                && $this->privateItemGroupKey((string)($fact['category_path'] ?? ''), trim((string)($fact['item_name'] ?? '')))
                    !== (string)$selection['private_item_group_key']) continue;
            if (isset($selection['marketing_item_group_key'])
                && $this->marketingItemGroupKey(
                    $this->marketingCategoryPath((string)($fact['category_path'] ?? '')),
                    trim((string)($fact['item_name'] ?? ''))) !== (string)$selection['marketing_item_group_key']) continue;
            if (isset($selection['item_id']) && (string)($fact['item_id'] ?? '') !== (string)$selection['item_id']) continue;
            if (isset($selection['platform_product_id']) && (string)($fact['platform_product_id'] ?? '') !== (string)$selection['platform_product_id']) continue;
            if (isset($selection['category_path'])
                && (string)($fact['category_path'] ?? '') !== (string)$selection['category_path']) continue;
            if (isset($selection['company_id'])) {
                $scopedFact = $fact;
                $this->organization()->project($scopedFact, (string)$fact['organization_id'], (string)$fact['organization_path_snapshot'], (string)$fact['business_date']);
                if ((string)($scopedFact['company_dimension_id'] ?? '') !== (string)$selection['company_id']) continue;
            }
            if (isset($selection['member_id']) && (string)($fact['member_id'] ?? '') !== (string)$selection['member_id']) continue;
            $documentId = trim((string)($fact['document_order_id'] ?? '')) ?: (string)$fact['order_id'];
            // Count popups retain the exact distinct key used by the parent
            // metric; money popups continue grouping allocations by document.
            $countMode = (string)($selection['count_mode'] ?? '');
            $factKey = $countMode === 'source_line' ? (string)($fact['source_line_id'] ?? $fact['id'])
                : ($countMode === 'refund_fact' ? (string)($fact['id'] ?? $fact['source_line_id']) : '');
            $key = (int)$fact['store_id'] . '|' . ($factKey !== '' ? $factKey : $documentId . '|' . (string)$fact['business_date']);
            if (!isset($documents[$key])) $documents[$key] = [
                'member_id' => (int)$fact['member_id'],
                'member_name' => (int)$fact['member_id'] > 0 ? (string)$fact['member_name'] : '游客',
                'order_no' => trim((string)($fact['document_no_snapshot'] ?? '')) ?: (trim((string)($fact['order_no_snapshot'] ?? '')) ?: $documentId),
                'business_date' => (string)$fact['business_date'],
                'store_name' => (string)($fact['store_name'] ?? ''),
                'amount_cents' => 0,
            ];
            $documents[$key]['amount_cents'] += $countMode !== '' ? 0 : (!empty($selection['refund_only'])
                ? abs((int)$fact['amount_cents']) : (int)$fact['amount_cents']);
        }
        $records = array_values($documents);
        usort($records, static fn(array $left, array $right): int => strcmp($left['business_date'] . '|' . $left['order_no'], $right['business_date'] . '|' . $right['order_no']));
        foreach ($records as &$record) {
            $record['receipt_amount'] = !empty($selection['count_mode']) ? 1 : $this->money((int)$record['amount_cents']);
            unset($record['amount_cents']);
        }
        unset($record);
        return $records;
    }

    /** Annual item cohorts count a member once, using sale-line/day events. */
    private function annualItemMemberDetailRecords(array $facts, array $selection): array
    {
        $allowed = array_fill_keys(array_map('intval', (array)($selection['include'] ?? [])), true);
        $events = [];
        if ($allowed === []) return [];
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId <= 0 || !isset($allowed[(int)($fact['category_id'] ?? 0)])
                || $this->marketingItemGroupKey(
                    $this->marketingCategoryPath((string)($fact['category_path'] ?? '')),
                    trim((string)($fact['item_name'] ?? ''))) !== (string)$selection['marketing_item_group_key']) continue;
            $eventKey = $memberId . '|' . (string)($fact['source_line_id'] ?? '') . '|'
                . (string)($fact['business_date'] ?? '');
            if (!isset($events[$memberId][$eventKey])) {
                $documentId = trim((string)($fact['document_order_id'] ?? '')) ?: (string)($fact['order_id'] ?? '');
                $events[$memberId][$eventKey] = [
                    'member_id' => $memberId,
                    'member_name' => (string)($fact['member_name'] ?? ''),
                    'order_no' => trim((string)($fact['document_no_snapshot'] ?? ''))
                        ?: (trim((string)($fact['order_no_snapshot'] ?? '')) ?: $documentId),
                    'business_date' => (string)($fact['business_date'] ?? ''),
                ];
            }
        }
        $records = [];
        foreach ($events as $memberEvents) {
            if ($selection['annual_member_events'] === 'annual_repeat_people' && count($memberEvents) < 2) continue;
            $memberEvents = array_values($memberEvents);
            usort($memberEvents, static fn(array $left, array $right): int => strcmp(
                $left['business_date'] . '|' . $left['order_no'], $right['business_date'] . '|' . $right['order_no']));
            $record = $memberEvents[0];
            if ($selection['annual_member_events'] === 'annual_repeat_people') {
                // Show both source documents that establish the repeat cohort.
                $record['order_no'] = implode('、', array_unique(array_column(array_slice($memberEvents, 0, 2), 'order_no')));
            }
            $record['receipt_amount'] = 1;
            $records[] = $record;
        }
        return $records;
    }

    private function marketReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $category = (string)$definition['category'];
        // Actual money and every company split share one scoped category population.
        $registeredActual = in_array($report, ['product_monetization_beauty_market_distribution',
            'product_monetization_private_market_distribution',
            'product_monetization_six_dimension_market_distribution'], true);
        $months = $this->months($range); $categoryIds = $this->categoryIds($category, $input);
        $companies = $this->organization()->filterSchema($range);
        $companyOptions = (array)(($companies[0]['options'] ?? []));
        $cashFacts = $categoryIds === [] ? [] : ($registeredActual
            ? $this->actualCategoryRows($stores, $range, $categoryIds, $report === 'product_monetization_six_dimension_market_distribution')
            : $this->cashRows($stores, $range, $categoryIds));
        $byMonth = $this->cashTotalsFromRows($cashFacts, $categoryIds, true);
        $byCompany = [];
        foreach ($cashFacts as $fact) {
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
            $this->column('completed_amount', '完成金额', $registeredActual
                ? $category . '及下级商品的实际业绩，即成功现金收款减成功现金退款。'
                : '商品分类“' . $category . '”及全部下级分类的净现金业绩。', true, false, 120, $this->cashDetailDrill($report, 'completed_amount'), $group),
            $this->column('completion_rate', '完成率', '完成金额除以目标金额；目标为空或为零显示“-”。', false, false, 110, [], $group),
        ];
        foreach ($companyOptions as $company) {
            $name = (string)$company['label']; $key = 'company_' . (string)$company['value'];
            $amountLabel = $category === '花园' ? '销售业绩' : '完成金额';
            if ($category === '生美') {
                $columns[] = $this->manualColumn($key . '_target_amount', '目标金额', '手动输入分公司“' . $name . '”该月生美目标，保存为独立报表补充记录。', $name);
                $columns[] = $this->column($key . '_amount', $amountLabel, '统计分公司“' . $name . '”有效下级门店当月生美商品的实际业绩，成功现金退款按退款日冲减。', true, false, 120, $this->cashDetailDrill($report, $key . '_amount'), $name);
                $columns[] = $this->column($key . '_completion_rate', '完成率', $amountLabel . '除以目标金额；分母为零显示“-”。', false, false, 110, [], $name);
                continue;
            }
            $columns[] = $this->column($key . '_amount', $amountLabel, '统计分公司“' . $name . '”有效下级门店的当月' . $category . '实际业绩，成功现金退款按退款日冲减。', true, false, 120, $this->cashDetailDrill($report, $key . '_amount'), $name);
            $columns[] = $this->column($key . '_share', '业绩占比', '该分公司' . $amountLabel . '除以集团' . $category . '完成金额；分母为零显示“-”。', false, false, 110, [], $name);
        }
        $result = $this->result((string)$definition['name'], $columns, $rows, $range, $input, ['year' => true]);
        if ($registeredActual) $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    private function efficiencyReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        // Only deal money moves to registered actual facts; completed-service consumption remains a separate metric.
        $registeredActual = in_array($report, ['product_monetization_six_dimension_efficiency',
            'product_monetization_private_efficiency'], true);
        $category = (string)$definition['category']; $ids = $this->categoryIds($category, $input); $rows = [];
        $months = $this->months($range);
        $firstMonth = $months[0]; $lastMonth = end($months);
        $expandedRange = ['start' => $firstMonth . '-01', 'end' => date('Y-m-t', strtotime($lastMonth . '-01'))];
        // The registered reader requires a bounded range; retain the legacy full-history cohort search.
        $historyRange = $registeredActual ? ['start' => '1970-01-01', 'end' => $range['end']]
            : ['end' => $range['end']];
        $cashHistory = $ids === [] ? [] : ($registeredActual
            ? $this->actualCategoryRows($stores, $historyRange, $ids)
            : $this->cashRows($stores, $historyRange, $ids));
        // Imported and old-card debt receipts remain money, but not purchase or new-customer events.
        $countHistory = $registeredActual
            ? StoreReportImportedRepaymentCountScope::exclude($cashHistory, $stores) : $cashHistory;
        $firstPurchaseDates = $this->firstPositivePurchaseDates($countHistory);
        $cashByMonth = []; $countCashByMonth = [];
        // The history scan already includes the displayed months; reuse its
        // authorized facts instead of issuing another identical cash query.
        foreach ($cashHistory as $fact) {
            $month = substr((string)$fact['business_date'], 0, 7);
            if ($month >= $firstMonth && $month <= $lastMonth) $cashByMonth[$month][] = $fact;
        }
        foreach ($countHistory as $fact) {
            $month = substr((string)$fact['business_date'], 0, 7);
            if ($month >= $firstMonth && $month <= $lastMonth) $countCashByMonth[$month][] = $fact;
        }
        if ($expandedRange['end'] > $range['end']) {
            // Partial-date queries historically show the whole closing month
            // for current cash, while the first-purchase cohort stops at the
            // requested end date. Only the missing tail needs a second read.
            $tail = ['start' => date('Y-m-d', strtotime($range['end'] . ' +1 day')),
                'end' => $expandedRange['end']];
            $tailFacts = $ids === [] ? [] : ($registeredActual
                ? $this->actualCategoryRows($stores, $tail, $ids)
                : $this->cashRows($stores, $tail, $ids));
            $countTailFacts = $registeredActual
                ? StoreReportImportedRepaymentCountScope::exclude($tailFacts, $stores) : $tailFacts;
            $firstPurchaseDates = $this->firstPositivePurchaseDates($countTailFacts, $firstPurchaseDates);
            foreach ($tailFacts as $fact) {
                $cashByMonth[substr((string)$fact['business_date'], 0, 7)][] = $fact;
            }
            foreach ($countTailFacts as $fact) {
                $countCashByMonth[substr((string)$fact['business_date'], 0, 7)][] = $fact;
            }
        }
        $historyByTeam = ['self' => $this->teamFacts($cashHistory, false), 'partner' => $this->teamFacts($cashHistory, true)];
        $countHistoryByTeam = ['self' => $this->teamFacts($countHistory, false), 'partner' => $this->teamFacts($countHistory, true)];
        unset($cashHistory);
        $metricsByTeam = [
            'self' => $this->preSaleMetricsByMonth($historyByTeam['self'], $months, false),
            'partner' => $this->preSaleMetricsByMonth($historyByTeam['partner'], $months, false),
        ];
        $countMetricsByTeam = [
            'self' => $this->preSaleMetricsByMonth($countHistoryByTeam['self'], $months, false),
            'partner' => $this->preSaleMetricsByMonth($countHistoryByTeam['partner'], $months, false),
        ];
        unset($countHistory, $countHistoryByTeam);
        $servicesByMonth = [];
        // The same service scan supplies consultations and consumption; this report does not use source labels.
        foreach ($ids === [] ? [] : $this->serviceRows($stores, $expandedRange, $ids, false, false, true) as $service) {
            $servicesByMonth[substr((string)$service['business_date'], 0, 7)][] = $service;
        }
        foreach ($months as $month) {
            $monthRange = ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01'))];
            $monthCash = (array)($cashByMonth[$month] ?? []);
            $monthCountCash = (array)($countCashByMonth[$month] ?? []);
            $monthServices = (array)($servicesByMonth[$month] ?? []);
            foreach ([['key' => 'self', 'name' => '自营', 'partner' => false], ['key' => 'partner', 'name' => '合作', 'partner' => true]] as $team) {
                $cash = $this->teamFacts($monthCash, (bool)$team['partner']);
                $countCash = $this->teamFacts($monthCountCash, (bool)$team['partner']);
                $services = $this->teamFacts($monthServices, (bool)$team['partner']);
                $amount = $this->factAmount($cash); $count = $this->memberCount($countCash);
                $metrics = (array)($metricsByTeam[(string)$team['key']][$month] ?? []);
                $countMetrics = (array)($countMetricsByTeam[(string)$team['key']][$month] ?? []);
                $refundLines = []; $refundAmount = 0;
                foreach ($cash as $fact) if ((int)$fact['amount_cents'] < 0) { $refundLines[(string)($fact['id'] ?? $fact['source_line_id'])] = true; $refundAmount += abs((int)$fact['amount_cents']); }
                $visit = count($this->efficiencyVisitFacts($services, [], false));
                $newVisit = count($this->efficiencyVisitFacts($services, $firstPurchaseDates, true));
                $newPeople = (int)$countMetrics['first_people']; $newAmount = (int)$metrics['first_amount'];
                $rows[] = [
                    'month' => $month, 'team_name' => (string)$team['name'], 'annotation_dimension_key' => 'team:' . (string)$team['key'],
                    'visit_count' => $visit,
                    'deal_people' => $count, 'deal_rate' => $this->ratio($count, $visit), 'deal_amount' => $this->money($amount), 'unit_output' => $this->moneyRatio($amount, $count),
                    'new_visit_count' => $newVisit,
                    'new_deal_people' => $newPeople, 'new_deal_rate' => $this->ratio($newPeople, $newVisit), 'new_deal_amount' => $this->money($newAmount), 'new_unit_output' => $this->moneyRatio($newAmount, $newPeople),
                    'service_count' => $this->serviceCount($services), 'consumption_amount' => $this->money($this->serviceConsumptionAmount($services)), 'service_unit_amount' => $this->moneyRatio($this->serviceConsumptionAmount($services), $this->serviceCount($services)),
                    'refund_count' => count($refundLines), 'refund_amount' => $this->money($refundAmount),
                ];
            }
        }
        $result = $this->result((string)$definition['name'], [
            $this->column('month', '月份', '按所选日期范围内的自然月分组。', false, true, 100),
            $this->column('team_name', $category . '团队', $category . '及下级平台商品当前分类路径含“合作”的归合作，其余归自营；按所选门店汇总。', false, true, 120),
            $this->column('visit_count', '见诊人数', '当月已完成且收银项目带体验标签的' . $category . '服务，按团队和会员去重计人数；未完成服务不计入。', true, false, 120, $this->serviceDetailDrill($report, 'visit_count'), '总成交数据'),
            $this->column('deal_people', '成交人数', '当月购买' . $category . '及下级商品的去重会员数；旧导入数据或旧卡录入原单的欠款补交不计人数。', true, false, 110, $this->cashDetailDrill($report, 'deal_people'), '总成交数据'),
            $this->column('deal_rate', '成交率', '成交人数除以体验见诊人数；当月无人见诊显示“-”。', false, false, 110, [], '总成交数据'),
            $this->column('deal_amount', '成交金额', $registeredActual
                ? '当月' . $category . '及下级商品的实际业绩，成功现金退款按退款日冲减。'
                : '当月' . $category . '及下级商品的净现金业绩。', true, false, 120, $this->cashDetailDrill($report, 'deal_amount'), '总成交数据'),
            $this->column('unit_output', '成交单产', '成交金额除以成交人数；分母为零显示“-”。', false, false, 110, [], '总成交数据'),
            $this->column('new_visit_count', '见诊人数', '当月已完成且带体验标签的' . $category . '服务中，截至体验业务日仍无' . $category . '正金额收款的会员，按团队和会员去重；旧导入数据或旧卡录入原单的欠款补交不算购买。', true, false, 120, $this->serviceDetailDrill($report, 'new_visit_count'), '新客成交数据'),
            $this->column('new_deal_people', '成交人数', '当月第一次购买' . $category . '及下级商品的不同会员数；旧导入数据或旧卡录入原单的欠款补交不计人数。', true, false, 110, $this->cashDetailDrill($report, 'new_deal_people'), '新客成交数据'),
            $this->column('new_deal_rate', '成交率', '首次' . $category . '收款会员数除以当月新客体验见诊人数；两者分别取收款与服务事实，未见诊也可成交，比例可能超过100%；分母为零显示“-”。', false, false, 110, [], '新客成交数据'),
            $this->column('new_deal_amount', '成交金额', $registeredActual
                ? '当月首次购买' . $category . '商品会员的实际业绩；其成功现金退款按退款日冲减。'
                : '当月新客第一次购买' . $category . '及下级商品的现金业绩。', true, false, 120, $this->cashDetailDrill($report, 'new_deal_amount'), '新客成交数据'),
            $this->column('new_unit_output', '成交单产', '新客成交金额除以新客成交人数；分母为零显示“-”。', false, false, 110, [], '新客成交数据'),
            $this->column('service_count', '服务项目数', '当月完成服务且服务项目分类为' . $category . '及下级的项目数量。', true, false, 120, $this->serviceDetailDrill($report, 'service_count'), '服务消耗'),
            $this->column('consumption_amount', '耗卡金额', '服务成功核销后形成的消耗业绩金额。', true, false, 120, $this->serviceDetailDrill($report, 'consumption_amount'), '服务消耗'),
            $this->column('service_unit_amount', '单次金额', '耗卡金额除以服务项目数；分母为零显示“-”。', false, false, 110, [], '服务消耗'),
            $this->column('refund_count', '退款数量', '当月' . $category . '商品成功退款对应的负向收款分摊笔数；同笔分摊跨卡内项目只计一次。', true, false, 110, $this->cashDetailDrill($report, 'refund_count'), '服务消耗'),
            $this->column('refund_amount', '退款金额', '当月退款成功后按项目分摊的实际退款金额，按绝对金额展示。', true, false, 120, $this->cashDetailDrill($report, 'refund_amount'), '服务消耗'),
        ], $rows, $range, $input, ['year' => true]);
        if ($registeredActual) $result['metric_version'] = $this->registeredActualMetricVersion()
            . '+imported-and-old-card-repayment-people-r82';
        return $result;
    }

    private function preSaleReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $marketing = in_array($report, ['marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true);
        $sourceDayCohort = in_array($report, ['operations_pre_sale_bdegh', 'operations_referral_beautician_pre_sale'], true);
        // Column explanations must name the persisted primary source codes;
        // a generic "this report's rule" hides which receipts enter the cohort.
        $sourceCodes = $report === 'operations_referral_beautician_pre_sale' ? 'C/F' : 'B/D/E/G/H';
        $referral = $report === 'marketing_referral_pre_sale';
        $experienceActualLabel = $referral ? '实际达成' : '体验实际达成人数';
        $newCustomerActualLabel = $referral ? '实际达成' : '新客实际达成人数';
        $newCustomerAmountTargetLabel = $referral ? '业绩目标' : '新客业绩目标';
        $specs = $marketing ? [
            $this->monthlySpec('experience_target', '体验目标', '手动输入当月体验目标人数，保存为独立报表补充记录，不覆盖体验或收款事实。', true, ['体验目标']),
            $this->monthlySpec('experience_actual', $experienceActualLabel, '当月结账购物车带体验标签且符合本表来源范围的不同会员数。', false, [$experienceActualLabel]),
            $this->monthlySpec('experience_completion_rate', '达成率', '体验实际达成人数除以体验目标；分母为零或未填写显示“-”。', false, ['达成率']),
            $this->monthlySpec('new_customer_target', '新客目标', '手动输入当月新客目标人数，保存为独立报表补充记录。', true, ['新客目标']),
            $this->monthlySpec('new_customer_actual', $newCustomerActualLabel, $referral
                ? '当月首次 C/F 来源有效成交的不同会员数；旧导入数据或旧卡录入原单的欠款补交不作为成交人数或首次成交。'
                : '当月来源符合本表规则且首次产生有效现金业绩的不同会员数；旧导入数据或旧卡录入原单的欠款补交不作为首次成交。', false, [$newCustomerActualLabel]),
            $this->monthlySpec('new_customer_completion_rate', '达成率', '新客实际达成人数除以新客目标；分母为零或未填写显示“-”。', false, ['达成率']),
            $this->monthlySpec('new_customer_amount_target', $newCustomerAmountTargetLabel, '手动输入当月新客现金业绩目标，保存为独立报表补充记录。', true, [$newCustomerAmountTargetLabel]),
            $this->monthlySpec('new_customer_amount_actual', '实际达成', '当月来源符合本表规则的新客成功收款，按销售明细、卡内项目分摊后的净现金业绩。', false, ['实际达成']),
            $this->monthlySpec('new_customer_amount_completion_rate', '达成率', '新客实际达成金额除以新客业绩目标；分母为零或未填写显示“-”。', false, ['达成率']),
            $this->monthlySpec('new_customer_deal_rate', '新客成交率', '新客实际达成人数除以体验实际达成人数；分母为零显示“-”。', false, ['新客成交率']),
            $this->monthlySpec('new_customer_unit_output', '新客成交单产', '新客实际达成金额除以新客实际达成人数；分母为零显示“-”。', false, ['新客成交单产']),
            $this->monthlySpec('transition_people', '售中转换人数', $referral
                ? '首次 C/F 来源成交的会员，后续任意来源第二次有效现金成交发生在当月的不同会员数；旧导入数据或旧卡录入原单的欠款补交不作为首次或第二次成交。'
                : '来源符合本表规则、当月发生第二次有效现金成交的不同会员数；旧导入数据或旧卡录入原单的欠款补交不作为首次或第二次成交。', false, ['售中转换人数']),
            $this->monthlySpec('transition_amount', '售中转换业绩', $referral
                ? '首次 C/F 来源成交会员的第二次成交对应已登记实际业绩，不再限定该笔订单来源；人数仍按成交事件判定。'
                : '售中转换会员当月成功收款按销售明细分摊后的净现金业绩。', false, ['售中转换业绩']),
            $this->monthlySpec('transition_rate', '售中转换率', '售中转换人数除以新客实际达成人数；分母为零显示“-”。', false, ['售中转换率']),
            $this->monthlySpec('transition_unit_output', '售中转换单产', '售中转换业绩除以售中转换人数；分母为零显示“-”。', false, ['售中转换单产']),
            $this->monthlySpec('first_year_amount', '首年业绩', $referral
                ? '本年度首次 C/F 来源成交的会员，自该笔成交起至所选月份末所有来源的累计已登记实际业绩；合计取年末累计值。'
                : '本年度首次成为本表来源会员的会员，自首次成交起至所选月份末本表来源的累计已登记实际业绩；合计取年末累计值。', false, ['首年业绩']),
        ] : [
            $this->monthlySpec('experience_people', '体验人数', '手动输入当月体验人数，保存为独立报表补充记录。', true, ['体验人数']),
            $this->monthlySpec('deal_people', '成交人数', $sourceDayCohort
                ? "同一会员同一业务日的收款合并为一次；游客按单据区分。首次 {$sourceCodes} 来源现金业绩达到500元的会员或游客单据数；旧导入数据或旧卡录入原单的欠款补交不作为成交人数或凑足门槛，现金金额仍计入金额列。"
                : '当月来源符合本表规则且第一次现金业绩达到500元的不同会员数。', false, ['成交人数']),
            $this->monthlySpec('deal_amount', '成交金额', $sourceDayCohort
                ? "{$sourceCodes} 来源会员或游客首次业务日净现金业绩，不设500元门槛；会员同日多单合并，游客按单据区分。"
                : '当月来源符合本表规则的第一次成功现金业绩，按销售明细和卡内项目分摊。', false, ['成交金额']),
            $this->monthlySpec('deal_rate', '成交率', '成交人数除以体验人数；分母为零或未填写显示“-”。', false, ['成交率']),
            $this->monthlySpec('deal_unit_output', '成交单产', '成交金额除以成交人数；分母为零显示“-”。', false, ['成交单产']),
            $this->monthlySpec('transition_people', '售中转换人数', $sourceDayCohort
                ? "{$sourceCodes} 来源在第二个正向收款业务日产生有效现金业绩的不同会员数；同日多单只算一次，旧导入数据或旧卡录入原单的欠款补交不作为成交日。"
                : '来源符合本表规则且第二次产生现金业绩的不同会员数。', false, ['售中转换人数']),
            $this->monthlySpec('transition_amount', '售中转换业绩', $sourceDayCohort
                ? "售中转换会员第二个 {$sourceCodes} 来源正向收款业务日的净现金业绩；同日多单合并，跨日分别计次。"
                : '售中转换会员第二次成功现金业绩，按销售明细和卡内项目分摊。', false, ['售中转换业绩']),
            $this->monthlySpec('transition_rate', '售中转换率', '售中转换人数除以成交人数；分母为零显示“-”。', false, ['售中转换率']),
            $this->monthlySpec('first_year_amount', '首年业绩', $report === 'operations_pre_sale_bdegh'
                ? '成交金额中的会员，汇总其本年度所有来源的净现金业绩，归属首次 B/D/E/G/H 成交月份且全年只计一次；游客按收款月份计入除 A 来源外的净现金业绩。'
                : ($sourceDayCohort
                    ? '成交金额中的会员，汇总其本年度所有来源的净现金业绩，归属首次 C/F 成交月份且全年只计一次；游客按收款月份计入 C/F 来源的净现金业绩。'
                    : '本年度首次成为本表来源会员的会员，在本年度内累计形成的净现金业绩。'), false, ['首年业绩']),
        ];
        $result = $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, $specs, $this->preSaleRule($report));
        // The BDEGH first-year cohort changed independently of other phase-four reports.
        if ($report === 'operations_pre_sale_bdegh') $result['metric_version'] = 'operations-pre-sale-bdegh-imported-and-old-card-repayment-people-r83';
        if ($report === 'operations_referral_beautician_pre_sale') $result['metric_version'] = 'operations-pre-sale-cf-imported-and-old-card-repayment-people-r83';
        if ($referral) $result['metric_version'] = 'marketing-referral-source-cohort-r71+'
            . $this->registeredActualMetricVersion() . '+imported-and-old-card-repayment-people-r82';
        return $result;
    }

    private function comparisonReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $specs = [
            $this->monthlySpec('store_count', '店面数', '当前权限范围内有效门店数量。', false, ['店面数']),
            $this->monthlySpec('full_target_amount', '目标', '汇总已生效且明确引用业绩对比报表的方案中，所选全部门店当月全店实际业绩目标；任一门店未设置时显示“—”。', false, ['全额', '目标']),
            $this->monthlySpec('all_actual_amount', '今年实际业绩', '所选年份当月，按目标方案相同的已登记实际业绩口径统计：成功现金收款减退款；统一指标覆盖起始月之前显示“—”。', false, ['全额', '今年实际业绩']),
            $this->monthlySpec('all_current_amount', '今年现金业绩', '所选年份当月，全部商品分类成功收款按销售明细分摊后的净现金业绩。独立登记的现金退款不在本列再次扣减，计入今年实际业绩及对应分类业绩；分类业绩合计因此不一定等于本列。', false, ['全额', '今年现金业绩']),
            $this->monthlySpec('all_completion_rate', '本年完成率', '今年实际业绩除以已绑定目标方案的全店实际业绩目标；目标未覆盖全部所选门店或为零时显示“—”。', false, ['全额', '本年完成率']),
            $this->monthlySpec('all_previous_amount', '去年现金业绩', '上一自然年同月，全部商品分类的净现金业绩；独立登记的现金退款在对应分类业绩中扣减，不在本列再次扣减。', false, ['全额', '去年现金业绩']),
            $this->monthlySpec('all_difference', '业绩同比增减额', '今年现金业绩减去年现金业绩。', false, ['全额', '业绩同比增减额']),
        ];
        foreach ([['six_dimension', '六维'], ['private', '花园'], ['haomei', '昊美'], ['beauty', '生美']] as [$key, $category]) {
            $explanation = '商品分类“' . $category . '”及全部下级分类的净业绩；独立卡项或单据现金退款按退款业务日期、实际退款项目的当前商品分类扣减，已作废退款、仅转入余额及零元退款不计入。已在原收款中冲减的退款不重复扣减；无法确认项目分类的退款不猜分到本分类。下钻负数对应退款单号。';
            $specs[] = $this->monthlySpec($key . '_current_amount', "今年\n{$category}业绩", '所选年份当月' . $explanation, false, [$category, "今年\n{$category}业绩"]);
            $specs[] = $this->monthlySpec($key . '_previous_amount', "去年\n{$category}业绩", '上一自然年同月' . $explanation, false, [$category, "去年\n{$category}业绩"]);
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
            $this->monthlySpec('all_current_amount', '今年现金业绩', '当月合格销售、充值及欠款补交的成功收款；收款方式为其他收款的不计入，退款不在本列扣减。', false, ['今年现金业绩']),
            $this->monthlySpec('refund_amount', '退款业绩', '当月成功现金退款金额，以正数展示；已在销售分摊中形成负向事实的退款与独立退款互不重复，按退款业务日统计。', false, ['退款业绩']),
            $this->monthlySpec('all_actual_amount', '今年实际业绩', '今年现金业绩减当月退款业绩；退款仅冲减实际业绩，明细按退款单号显示负数。', false, ['今年实际业绩']),
            $this->monthlySpec('performance_completion_rate', '去年完成率', '今年现金业绩除以业绩目标；保留原表列名，分母为零或未填写显示“-”。', false, ['去年完成率']),
            $this->monthlySpec('all_previous_amount', '去年现金业绩', '上一自然年同月合格销售、充值及欠款补交的成功收款；其他收款与退款不在本列计入。', false, ['去年现金业绩']),
            $this->monthlySpec('all_difference', '业绩同比增减额', '今年现金业绩减去年现金业绩。', false, ['业绩同比增减额']),
            $this->monthlySpec('consumption_amount', '耗卡业绩', '当月完成服务后形成的生美消耗业绩；按项目服务事实及消耗业绩事实统计。', false, ['耗卡业绩']),
            $this->monthlySpec('consumption_share', '耗卡占比', '耗卡业绩除以今年现金业绩；分母为零显示“-”。', false, ['耗卡占比']),
            $this->monthlySpec('required_staff', '员工应配人数', '手动输入当月应配置员工人数，保存为独立报表补充记录。', true, ['员工应配人数']),
            $this->monthlySpec('actual_staff', '员工实配人数', '手动输入当月实际配置员工人数，保存为独立报表补充记录。', true, ['员工实配人数']),
            $this->monthlySpec('staff_difference', '差值', '员工应配人数减员工实配人数；任一值未填写显示“-”。', false, ['差值']),
            $this->monthlySpec('beauty_service_count', '生美总消耗项目数', '当月完成服务且商品分类为生美及下级分类的项目数量。', false, ['生美总消耗项目数']),
            $this->monthlySpec('post_sale_visits', '售后人次', '当月已完成且未作废的非体验服务，同一会员同店同日多项目、多服务单只计1人次；未建档按服务单计。', false, ['售后人次']),
            $this->monthlySpec('active_members', '活客数', '当月完成有效服务的不同会员数。', false, ['活客数']),
            $this->monthlySpec('beautician_monthly_projects', '美容师月均项目', '生美总消耗项目数除以员工实配人数；分母为零或未填写显示“-”。', false, ['美容师月均项目']),
            $this->monthlySpec('beautician_monthly_visits', '美容师月均服务人次', '售后人次除以员工实配人数；分母为零或未填写显示“-”。', false, ['美容师月均服务人次']),
            $this->monthlySpec('store_average_visits', '店均售后人次', '售后人次除以门店数；分母为零显示“-”。', false, ['店均售后人次']),
            $this->monthlySpec('weak_store_count', '10万以下弱店数', '当月现金业绩低于10万元的门店数量。', false, ['10万以下弱店数']),
            $this->monthlySpec('strong_store_count', '20万以上店数', '当月现金业绩高于20万元的门店数量。', false, ['20万以上店数']),
        ] : [
            $this->monthlySpec('store_count', '门店数', '当前权限范围内有效门店数量；同一门店跨月重复出现，合计行不累加。', false, ['门店数']),
            $this->monthlySpec('active_members', '活客数', '当月来源为A老客首护、已完成且未作废的非体验服务去重会员数；月度去重人数不可跨月累加。', false, ['活客数']),
            $this->monthlySpec('consuming_members', '消费人数', '当月有老客首护成功现金业绩的不同会员数；月度去重人数不可跨月累加。', false, ['消费人数']),
            $this->monthlySpec('consumption_rate', '消费率', '消费人数除以活客数；分母为零显示“-”。', false, ['消费率']),
            $this->monthlySpec('post_sale_visits', '售后人次', '当月来源为A老客首护的有效非体验服务，同一会员同店同日多单只计1人次；未建档按服务单计。', false, ['售后人次']),
            $this->monthlySpec('store_average_visits', "店均\n售后人次", '售后人次除以门店数；分母为零显示“-”。', false, ["店均\n售后人次"]),
            $this->monthlySpec('actual_staff', '美容师人数', '手动输入当月美容师人数，保存为独立报表补充记录。', true, ['美容师人数']),
            $this->monthlySpec('staff_unit_output', '人均产值', '店均业绩除以美容师人数；分母为零或未填写显示“-”。', false, ['人均产值']),
            $this->monthlySpec('monthly_service_visits', "月均\n服务人次", '售后人次除以12；完全按原表公式。', false, ["月均\n服务人次"]),
            $this->monthlySpec('consumption_amount', '生美消耗', '当月完成服务后形成的生美消耗业绩。', false, ['生美消耗']),
            $this->monthlySpec('staff_consumption_output', '人均消耗', '生美消耗除以美容师人数；分母为零或未填写显示“-”。', false, ['人均消耗']),
            $this->monthlySpec('store_average_amount', '店均业绩', '当月登记实际业绩除以门店数；分母为零显示“-”。', false, ['店均业绩']),
        ];
        return $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, $specs, []);
    }

    private function customerStatusReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        $activeLabel = $report === 'operations_customer_status_bdegh' ? '总活客数' : '活客数';
        $specs = [
            $this->monthlySpec('active_members', $activeLabel, '当月有已完成、未作废、非体验服务的不同会员数；同日多项目不重复计人。', false, [$activeLabel]),
            $this->monthlySpec('one_to_two_visits', '当月到店1-2次', '当月有有效服务的到店日为1至2天的不同会员数；同日多项目只算一次。', false, ['当月到店1-2次']),
            $this->monthlySpec('one_to_two_visit_share', '占比', '当月到店1至2次人数除以当月总活客数；分母为零显示“-”。', false, ['占比']),
            $this->monthlySpec('three_plus_visits', '当月到店3次及以上', '当月有有效服务的到店日不少于3天的不同会员数；同日多项目只算一次。', false, ['当月到店3次及以上']),
            $this->monthlySpec('three_plus_visit_share', '占比', '当月到店3次及以上人数除以当月总活客数；分母为零显示“-”。', false, ['占比']),
            $this->monthlySpec('regular_customers', '常客数(90天以内到店1次)', '截至当月末向前90天内只有1个有效服务到店日的不同会员数；同日多项目只算一次。', false, ['常客数(90天以内到店1次)']),
            $this->monthlySpec('inactive_customers', '死客（90天以上未到店）', '截至当月末，最近有效服务日期早于90天前、且历史存在有效服务记录的不同会员数；恰好90天不计入。', false, ['死客（90天以上未到店）']),
            $this->monthlySpec('active_rate', "活客率\n(活客数/(活客数+死客数))", '活客数除以活客数与死客数之和；分母为零显示“-”。', false, ["活客率\n(活客数/(活客数+死客数))"]),
        ];
        if ($report !== 'operations_customer_status_bdegh') {
            $specs = array_values(array_filter($specs, static fn(array $spec): bool => !in_array((string)$spec['key'], ['one_to_two_visit_share', 'three_plus_visit_share'], true)));
        }
        $result = $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, $specs, []);
        // A separate version makes historical project-count exports distinguishable from visit-day results.
        $result['metric_version'] = 'customer-status-visit-day-v2';
        return $result;
    }

    private function memberConsumptionReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        // Keep the exact sold-product exclusion, but select and threshold
        // members from the registered actual-performance population.
        $facts = $this->actualAllRows($stores, $range);
        $excludedSaleFacts = $this->saleFactIdsByExactProductName($facts, self::ANNUAL_MEMBER_EXCLUDED_PRODUCT_NAME);
        $rows = [];
        foreach ($facts as $fact) {
            if ($this->matchesExcludedSaleProduct($fact, $excludedSaleFacts, self::ANNUAL_MEMBER_EXCLUDED_PRODUCT_NAME)) continue;
            $memberId = (int)($fact['member_id'] ?? 0); if ($memberId <= 0) continue;
            $row = $fact; $this->organization()->project($row, (string)$fact['organization_id'], (string)$fact['organization_path_snapshot'], (string)$fact['business_date']);
            $key = (string)($row['company_dimension_id'] ?? '') . '|' . (string)$fact['store_id'] . '|' . $memberId;
            if (!isset($rows[$key])) $rows[$key] = [
                // The organization projector exposes the current statistic dimension as `company`.
                // Preserve its ID for exact grouping and drilldown; do not read an unset display key.
                'company_name' => (string)($row['company'] ?? '未配置分公司'),
                'company_dimension_id' => (string)($row['company_dimension_id'] ?? ''),
                'store_name' => (string)$fact['store_name'], 'store_id' => (int)$fact['store_id'],
                'member_name' => (string)($fact['member_name'] ?? ('会员#' . $memberId)),
                'member_id' => $memberId, 'cash_performance_cents' => 0];
            $rows[$key]['cash_performance_cents'] += (int)$fact['amount_cents'];
        }
        $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['cash_performance_cents'] >= 3000000));
        foreach ($rows as &$row) { $row['cash_performance'] = $this->money((int)$row['cash_performance_cents']); unset($row['cash_performance_cents']); } unset($row);
        usort($rows, static fn(array $left, array $right): int => strcmp($left['company_name'] . $left['store_name'] . $left['member_name'], $right['company_name'] . $right['store_name'] . $right['member_name']));
        $result = $this->result((string)$definition['name'], [
            $this->column('company_name', '分公司', '按门店所属组织链上配置为“分公司”的统计维度读取；未配置时显示“未配置分公司”。', false, true, 130),
            $this->column('store_name', '门店', '取成功收款业务发生时保存的门店名称快照。', false, true, 150),
            $this->column('member_name', '会员', '取成功收款业务关联的会员名称快照；历史名称缺失时以会员编号定位。', false, true, 130),
            $this->column('cash_performance', '实际业绩', '筛选期间内该会员成功现金收款减成功现金退款，含充值及欠款补交；排除成交商品名称精确为“昊美A”的销售，其他昊美分类商品仍计入。仅保留实际业绩不少于30,000元的会员。', true, false, 130, $this->cashDetailDrill($report, 'cash_performance')),
        ], array_values($rows), $range, $input, []);
        $result['metric_version'] = $this->registeredActualMetricVersion();
        return $result;
    }

    /** Match the sold product's immutable name, before card cash is split among its components. */
    private function saleFactIdsByExactProductName(array $facts, string $name): array
    {
        $saleFactIds = [];
        foreach ($facts as $fact) {
            $id = (string)($fact['sale_fact_id'] ?? '');
            if ($id !== '') $saleFactIds[$id] = true;
        }
        $matches = [];
        foreach (array_chunk(array_keys($saleFactIds), 300) as $chunk) {
            foreach (Db::name('cashier_v3_report_sale_dimension_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('sale_fact_id', $chunk)->where('item_name_snapshot', $name)
                ->field('sale_fact_id,item_name_snapshot')->select()->toArray() as $sale) {
                // Database collations may ignore case; the requested name does not.
                if ((string)$sale['item_name_snapshot'] === $name) $matches[(string)$sale['sale_fact_id']] = true;
            }
        }
        return $matches;
    }

    /** Unallocated legacy repayments lack a sale fact, so use their frozen debt-item name. */
    private function matchesExcludedSaleProduct(array $fact, array $saleFactIds, string $name): bool
    {
        $saleFactId = (string)($fact['sale_fact_id'] ?? '');
        return $saleFactId !== '' ? isset($saleFactIds[$saleFactId]) : (string)($fact['item_name'] ?? '') === $name;
    }

    private function postSaleReport(string $report, array $definition, array $stores, array $range, array $input): array
    {
        return $this->monthlyProjection((string)$definition['name'], $report, $stores, $range, $input, [
            $this->monthlySpec('store_count', '店面数', '当前权限范围内有效门店数量。', false, ['店面数']),
            $this->monthlySpec('active_members', '活客数', '当月完成有效服务的不同会员数。', false, ['活客数']),
            $this->monthlySpec('post_sale_visits', '售后人次', '当月已完成且未作废的非体验服务，同一会员同店同日多项目、多服务单只计1人次；未建档按服务单计。', false, ['售后人次']),
            $this->monthlySpec('deal_visits', '成交人次', '当月购买生美卡项商品名称的会员项目人次，不以同一会员去重；旧导入数据或旧卡录入原单的欠款补交不计。', false, ['成交人次']),
            $this->monthlySpec('visit_deal_rate', '人次成交率', '成交人次除以售后人次；分母为零显示“-”。', false, ['人次成交率']),
            $this->monthlySpec('deal_people', '成交人头', '当月购买生美卡项商品名称的不同会员数；旧导入数据或旧卡录入原单的欠款补交不计。', false, ['成交人头']),
            $this->monthlySpec('people_deal_rate', '人头成交率', '成交人头除以售后人次；完全按原表公式，分母为零显示“-”。', false, ['人头成交率']),
            $this->monthlySpec('post_sale_amount', '售后业绩', '当月生美商品的登记实际业绩，成功现金收款减成功现金退款；成交人数仍按成交事件计。', false, ['售后业绩']),
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
        // This source-only report needs one authorized cash-fact scan for the
        // year. Service, prior-year and product-category queries cannot affect it.
        $sourceDayCohort = in_array($report, ['operations_pre_sale_bdegh', 'operations_referral_beautician_pre_sale'], true);
        $sourceMetrics = $sourceDayCohort
            ? $this->bdeghMonthlyMetrics($stores, $range, $rule, $report === 'operations_pre_sale_bdegh') : [];
        $requiresCashHistory = in_array($report, [
            'marketing_acquisition_pre_sale', 'marketing_referral_pre_sale',
        ], true);
        $cashHistory = $requiresCashHistory
            ? $this->cashRows($stores, ['end' => $range['end']], []) : [];
        // All source-backed columns in one report share the same frozen
        // source rule; filter and build cohorts once per month, not per cell.
        if ($requiresCashHistory && $report !== 'marketing_referral_pre_sale') {
            $cashHistory = $this->filterSourceFacts($cashHistory, $this->preSaleRule($report));
        }
        $customerStatus = in_array($report, ['operations_customer_status_bdegh', 'marketing_customer_status'], true);
        $customerWindows = $customerStatus
            ? $this->customerVisitWindowCounts($stores, $this->months($range)) : [];
        $inactiveCounts = (array)($customerWindows['inactive'] ?? []);
        $regularCounts = (array)($customerWindows['regular'] ?? []);
        $needsPreviousCash = false;
        foreach ($specs as $spec) {
            $key = (string)($spec['key'] ?? '');
            if ($key === 'all_previous_amount' || str_ends_with($key, '_previous_amount')) {
                $needsPreviousCash = true;
                break;
            }
        }
        $months = $this->months($range);
        $preSaleMonthly = $report === 'marketing_referral_pre_sale'
            ? $this->marketingReferralMetricsByMonth($cashHistory, $months)
            : ($requiresCashHistory ? $this->preSaleMetricsByMonth($cashHistory, $months, false) : []);
        if (in_array($report, ['marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true)) {
            // Money keeps every authorized receipt; only first/second person events exclude legacy repayments.
            $countHistory = StoreReportImportedRepaymentCountScope::exclude($cashHistory, $stores);
            $peopleMonthly = $report === 'marketing_referral_pre_sale'
                ? $this->marketingReferralMetricsByMonth($countHistory, $months)
                : $this->preSaleMetricsByMonth($countHistory, $months, false);
            foreach ($months as $month) {
                $preSaleMonthly[$month]['first_people'] = (int)$peopleMonthly[$month]['first_people'];
                $preSaleMonthly[$month]['transition_people'] = (int)$peopleMonthly[$month]['transition_people'];
            }
        }
        $preSaleThresholdMonthly = $preSaleMonthly;
        $healthReport = in_array($report, ['operations_health_data', 'marketing_health_data'], true);
        $firstMonth = $months[0];
        $lastMonth = end($months);
        $fullRange = ['start' => $firstMonth . '-01', 'end' => date('Y-m-t', strtotime($lastMonth . '-01'))];
        if (in_array($report, ['marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true)) {
            $actualHistory = $this->actualAllRows($stores, $fullRange);
            foreach ($months as $month) {
                $monthRange = ['start' => $month . '-01',
                    'end' => date('Y-m-t', strtotime($month . '-01'))];
                $moneyKeys = $report === 'marketing_referral_pre_sale'
                    ? ['transition_amount', 'first_year_amount'] : ['first_year_amount'];
                foreach ($moneyKeys as $moneyKey) {
                    $preSaleMonthly[$month][$moneyKey] = $this->factAmount($this->preSaleActualMoneyFacts(
                        $report, $cashHistory, $actualHistory, $monthRange, $moneyKey));
                }
            }
        }
        $comparisonTargets = []; $comparisonActual = [];
        $postSaleActual = [];
        if ($report === 'marketing_post_sale_performance') {
            // Sales money follows registered net performance; visit and deal headcounts retain their own cohorts.
            foreach ($this->actualCategoryRows($stores, $fullRange, $this->categoryIds('生美', []), true) as $fact) {
                $month = substr((string)$fact['business_date'], 0, 7);
                $postSaleActual[$month] = (int)($postSaleActual[$month] ?? 0) + (int)$fact['amount_cents'];
            }
        }
        $healthActual = [];
        if ($report === 'operations_health_data') {
            // Store productivity is an actual-performance ratio; service
            // consumption and member counts keep their own fact populations.
            $reader = new RegisteredMetricReadServices();
            $facts = $this->participantEmployeeId > 0
                ? $this->actualAllRows($stores, $fullRange)
                : $reader->dailyTotals('actual_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $fullRange);
            foreach ($facts as $fact) {
                $month = substr((string)$fact['business_date'], 0, 7);
                $healthActual[$month] = (int)($healthActual[$month] ?? 0)
                    + (int)($fact['amount_cents'] ?? $fact['metric_value'] ?? 0);
            }
            unset($facts);
        }
        if ($report === 'operations_performance_comparison') {
            $comparisonTargets = $this->comparisonTargetAmounts($stores, $months);
            $metricReader = new RegisteredMetricReadServices();
            // Completion uses the registered actual-performance fact population,
            // including refunds and recharge. Full cash retains its historical
            // sales allocation basis; category cells also net standalone refunds.
            $actualFacts = $this->participantEmployeeId > 0
                ? $metricReader->categoryRowsForParticipant('actual_performance',
                    CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $fullRange, [], [], $this->participantEmployeeId)
                : $metricReader->dailyTotals('actual_performance',
                    CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $fullRange);
            foreach ($actualFacts as $fact) {
                $month = substr((string)($fact['business_date'] ?? ''), 0, 7);
                $comparisonActual[$month] = (int)($comparisonActual[$month] ?? 0)
                    + (int)($fact['amount_cents'] ?? $fact['metric_value'] ?? 0);
            }
            unset($actualFacts);
        }
        $cashByMonth = []; $countCashByMonth = []; $servicesByMonth = []; $previousCashByMonth = []; $healthRefundByMonth = [];
        if ($sourceMetrics === []) {
            if ($report === 'operations_performance_comparison') {
                // Three-month windows cut repeated SQL setup while bounding the
                // receipt/card expansion that exhausted the full-year 128 MB process.
                foreach (array_chunk($months, 3) as $quarter) {
                    $currentStart = $quarter[0] . '-01';
                    $currentEnd = date('Y-m-t', strtotime(end($quarter) . '-01'));
                    $cashByMonth += $this->comparisonCashBucketsByMonth($stores, [
                        'start' => $currentStart, 'end' => $currentEnd,
                    ]);
                    $previousStart = date('Y-m-d', strtotime($currentStart . ' -1 year'));
                    $previousEnd = date('Y-m-t', strtotime($currentEnd . ' -1 year'));
                    $previousCashByMonth += $this->comparisonCashBucketsByMonth($stores, [
                        'start' => $previousStart, 'end' => $previousEnd,
                    ]);
                }
            } else {
                // Bucket a single fact scan per year before applying the existing
                // per-month formulas; the SQL fact filters and authorization stay unchanged.
                $yearCash = !$customerStatus && !$requiresCashHistory
                    ? $this->cashRows($stores, $fullRange, []) : [];
                if ($report === 'marketing_post_sale_performance') {
                    foreach (StoreReportImportedRepaymentCountScope::exclude($yearCash, $stores) as $fact) {
                        // Person formulas need only identity, signed cents and current category.
                        $countCashByMonth[substr((string)$fact['business_date'], 0, 7)][] = [
                            'member_id' => $fact['member_id'], 'source_line_id' => $fact['source_line_id'],
                            'id' => $fact['id'], 'amount_cents' => $fact['amount_cents'],
                            'category_path' => $fact['category_path'],
                        ];
                    }
                }
                foreach ($yearCash as $fact) {
                    // Health formulas only need these fact dimensions. Dropping the
                    // receipt/product snapshots here keeps an authorized yearly
                    // full-scope query below PHP's memory ceiling without changing facts.
                    $cashByMonth[substr((string)$fact['business_date'], 0, 7)][] = $healthReport
                        ? $this->healthCashFact($fact) : $fact;
                }
                unset($yearCash);
                if ($report === 'marketing_health_data') {
                    // Recharge has its own payment-fact grain and never appears
                    // in sale allocations. Add it once to the shared health cash
                    // buckets so current/prior gross and refund stay reconcilable.
                    foreach ((new RegisteredMetricReadServices())->rechargeCashFacts(
                        CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $fullRange, $this->participantEmployeeId
                    ) as $fact) {
                        $cashByMonth[substr((string)$fact['business_date'], 0, 7)][] = $this->healthCashFact($fact);
                    }
                    $this->appendMarketingHealthRefundBuckets($healthRefundByMonth, $stores, $months);
                }
                $needsServices = !in_array($report, ['operations_pre_sale_bdegh',
                    'operations_referral_beautician_pre_sale'], true);
                $serviceFacts = $customerStatus ? (array)($customerWindows['monthly_services'] ?? [])
                    : (!$needsServices ? [] : ($requiresCashHistory
                        ? $this->experienceServiceRows($stores, $fullRange)
                        : $this->serviceRows($stores, $fullRange, [], $healthReport,
                            !$healthReport || $report === 'operations_health_data')));
                foreach ($serviceFacts as $fact) {
                    $servicesByMonth[substr((string)$fact['business_date'], 0, 7)][] = $healthReport
                        ? $this->healthServiceFact($fact) : $fact;
                }
                unset($serviceFacts);
                if ($needsPreviousCash) {
                    $previousStart = sprintf('%04d%s-01', (int)substr($firstMonth, 0, 4) - 1, substr($firstMonth, 4));
                    $previousEnd = sprintf('%04d%s-01', (int)substr($lastMonth, 0, 4) - 1, substr($lastMonth, 4));
                    $previousRange = ['start' => $previousStart, 'end' => date('Y-m-t', strtotime($previousEnd))];
                    foreach ($this->cashRows($stores, $previousRange, []) as $fact) {
                        $previousCashByMonth[substr((string)$fact['business_date'], 0, 7)][] = $healthReport
                            ? $this->healthCashFact($fact) : $fact;
                    }
                    if ($report === 'marketing_health_data') {
                        foreach ((new RegisteredMetricReadServices())->rechargeCashFacts(
                            CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $previousRange, $this->participantEmployeeId
                        ) as $fact) {
                            $previousCashByMonth[substr((string)$fact['business_date'], 0, 7)][] = $this->healthCashFact($fact);
                        }
                    }
                }
            }
        }
        foreach ($months as $month) {
            $monthRange = ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01'))];
            $allCash = (array)($cashByMonth[$month] ?? []);
            $countCash = (array)($countCashByMonth[$month] ?? []);
            $cash = $sourceMetrics !== [] ? [] : $this->filterSourceFacts($allCash, $rule);
            $services = (array)($servicesByMonth[$month] ?? []);
            $previousMonth = date('Y-m', strtotime($month . '-01 -1 year'));
            $previousCash = (array)($previousCashByMonth[$previousMonth] ?? []);
            $healthPositiveCash = [];
            $healthGrossCents = 0; $healthRefundCents = 0; $healthPriorGrossCents = 0;
            if ($report === 'marketing_health_data') {
                // Resolve each month's signed cash population once. Standalone
                // refunds have no allocation and are disjoint from allCash.
                foreach ($allCash as $fact) {
                    $cents = (int)$fact['amount_cents'];
                    if ($cents > 0) {
                        $healthPositiveCash[] = $fact;
                        $healthGrossCents += $cents;
                    } elseif ($cents < 0) $healthRefundCents -= $cents;
                }
                foreach ((array)($healthRefundByMonth[$month] ?? []) as $refund) {
                    $healthRefundCents -= min(0, (int)$refund['amount_cents']);
                }
                foreach ($previousCash as $fact) $healthPriorGrossCents += max(0, (int)$fact['amount_cents']);
            }
            $row = ['month' => $month];
            if ($sourceDayCohort) $row['drill_month'] = $month;
            // All customer-status cells derive from the same member-day cohorts,
            // including percentages, so the table never mixes project and visit grains.
            $customerStatusValues = $customerStatus ? $this->customerStatusMonthlyValues(
                $services, (int)($regularCounts[$month] ?? 0), (int)($inactiveCounts[$month] ?? 0)) : [];
            $preSaleMetrics = (array)($preSaleMonthly[$month] ?? []);
            $preSaleThresholdMetrics = (array)($preSaleThresholdMonthly[$month] ?? []);
            $context = compact('report', 'stores', 'monthRange', 'allCash', 'countCash', 'cash', 'services', 'previousCash', 'rule',
                'cashHistory', 'preSaleMetrics', 'preSaleThresholdMetrics', 'inactiveCounts', 'regularCounts',
                'comparisonTargets', 'comparisonActual', 'healthPositiveCash', 'healthGrossCents',
                'healthRefundCents', 'healthPriorGrossCents', 'healthActual', 'postSaleActual');
            foreach ($specs as $spec) {
                $key = (string)$spec['key'];
                if (!empty($spec['manual'])) {
                    $manual = $this->manualValue($report, $this->manualSubject($report, $month, 0), $key);
                    $row[$key] = !$this->hasManualValue($manual) ? '-' : (str_contains($key, 'amount') || str_contains($key, 'performance') ? $this->money((int)$manual['value']) : (int)$manual['value']);
                    $row[$key . '_version'] = $manual === null ? 0 : (int)$manual['version'];
                    continue;
                }
                if (array_key_exists($key, (array)($sourceMetrics[$month] ?? []))) {
                    $value = (int)$sourceMetrics[$month][$key];
                    $row[$key] = in_array($key, ['deal_amount', 'transition_amount', 'first_year_amount'], true) ? $this->money($value) : $value;
                    continue;
                }
                if ($customerStatus && array_key_exists($key, $customerStatusValues)) {
                    $row[$key] = $customerStatusValues[$key];
                    continue;
                }
                $value = $this->monthlyValue($key, $row, $context);
                // C/F's newly populated unit-output cell follows the project-wide
                // integer-yuan display rule; its underlying receipt cents stay intact.
                $row[$key] = $report === 'operations_referral_beautician_pre_sale'
                    && $key === 'deal_unit_output' && is_numeric($value)
                    ? (string)(int)round((float)$value) : $value;
            }
            $rows[] = $row;
        }
        $this->attachManualSubjects($rows, $report);
        $columns = [$this->column('month', '月份', '按所选年份展开1月至12月；每月一行，切换年份后读取该年的已保存手动值。', false, true, 100)];
        $columns[0]['header_path'] = ['月份'];
        foreach ($specs as $spec) {
            $key = (string)$spec['key']; $label = (string)$spec['label']; $manual = !empty($spec['manual']);
            // Operations-health store counts and monthly distinct-member cohorts
            // cannot be added across months without duplicating stores or people.
            $summable = $this->isMonthlySummable($key, $label)
                && !($report === 'operations_health_data'
                    && in_array($key, ['store_count', 'active_members', 'consuming_members'], true));
            $column = $manual ? $this->manualColumn($key, $label, (string)$spec['explanation'])
                : $this->column($key, $label, (string)$spec['explanation'], $summable, false, 120);
            $column['header_path'] = (array)$spec['headerPath'];
            $column['value_type'] = $this->monthlyValueType($key, $label);
            // Comparison goal and actual retain cents for export and ratios,
            // while the table follows the report-wide whole-yuan display rule.
            if ($report === 'operations_performance_comparison'
                && in_array($key, ['full_target_amount', 'all_actual_amount'], true)) {
                $column['display_precision'] = 0;
            }
            if (($report === 'operations_performance_comparison'
                    && (in_array($key, ['all_current_amount', 'all_previous_amount'], true)
                        || preg_match('/^(six_dimension|private|haomei|beauty)_(current|previous)_amount$/D', $key)))
                || ($report === 'marketing_post_sale_performance' && $key === 'post_sale_amount')) {
                $column['drilldown'] = $this->cashDetailDrill($report, $key);
            }
            if (in_array($report, ['operations_health_data', 'marketing_health_data'], true)
                && in_array($key, ['active_members', 'post_sale_visits', 'beauty_service_count', 'consumption_amount'], true)) {
                $column['drilldown'] = $this->serviceDetailDrill($report, $key);
            }
            if (in_array($report, ['operations_customer_status_bdegh', 'marketing_customer_status'], true)
                && in_array($key, ['active_members', 'one_to_two_visits', 'three_plus_visits', 'regular_customers', 'inactive_customers'], true)) {
                $column['drilldown'] = $this->serviceDetailDrill($report, $key);
            }
            if (($report === 'marketing_post_sale_performance' && in_array($key, ['deal_people', 'deal_visits'], true))) {
                $column['drilldown'] = $this->cashDetailDrill($report, $key);
            }
            if ($report === 'marketing_post_sale_performance'
                && in_array($key, ['active_members', 'post_sale_visits'], true)) {
                $column['drilldown'] = $this->serviceDetailDrill($report, $key);
            }
            if (in_array($report, ['operations_referral_beautician_pre_sale', 'marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true)) {
                if ($key === 'experience_actual') $column['drilldown'] = $this->serviceDetailDrill($report, $key);
                elseif (in_array($key, ['deal_people', 'deal_amount', 'new_customer_actual', 'new_customer_amount_actual',
                    'transition_people', 'transition_amount', 'first_year_amount'], true)) {
                    $column['drilldown'] = $this->cashDetailDrill($report, $key);
                }
            }
            if (($report === 'marketing_health_data' && in_array($key, ['all_current_amount', 'refund_amount', 'all_actual_amount', 'all_previous_amount'], true))
                || ($report === 'operations_health_data' && $key === 'consuming_members')) {
                $column['drilldown'] = $this->cashDetailDrill($report, $key);
            }
            // The five cash-backed BDEGH metrics share one scoped detail query;
            // the member threshold is applied only when opening deal_people.
            if ($sourceDayCohort && in_array($key, ['deal_people', 'deal_amount', 'transition_people', 'transition_amount', 'first_year_amount'], true)) {
                $column['drilldown'] = [
                    'report' => $report === 'operations_pre_sale_bdegh'
                        ? 'operations_pre_sale_bdegh_detail' : 'operations_referral_beautician_pre_sale_detail',
                    'params' => ['metric_code' => $key],
                    'param_map' => ['drill_month' => 'drill_month'],
                ];
            }
            $columns[] = $column;
        }
        $result = $this->result($title, $columns, $rows, $range, $input, ['year' => true]);
        if (in_array($report, ['marketing_acquisition_pre_sale', 'marketing_referral_pre_sale'], true)) {
            // Monthly first-year amounts are cumulative; summing twelve rows
            // would count the same receipt repeatedly and break annual drilldown.
            $result['summary_row']['first_year_amount'] = $this->money(
                (int)($preSaleMonthly[$lastMonth]['first_year_amount'] ?? 0));
        }
        if ($report === 'operations_performance_comparison') {
            // An annual total of only configured months would masquerade as
            // the full-year target. Keep the total unknown until all months exist.
            if (count($comparisonTargets) !== count($months)) $result['summary_row']['full_target_amount'] = '-';
            if (in_array(true, array_map(static fn(string $month): bool =>
                $month . '-01' < MetricDefinitionRegistry::COVERAGE_START, $months), true)) {
                $result['summary_row']['all_actual_amount'] = '-';
            }
            $result['metric_version'] = 'operations-comparison-category-refund-r59';
        }
        if ($report === 'marketing_health_data') $result['metric_version'] = 'marketing-health-cash-refund-actual-r75';
        if ($report === 'marketing_post_sale_performance') $result['metric_version'] = $this->registeredActualMetricVersion()
            . '+imported-and-old-card-repayment-people-r82';
        if ($report === 'marketing_acquisition_pre_sale') $result['metric_version'] = $this->registeredActualMetricVersion()
            . '+imported-and-old-card-repayment-people-r83';
        if ($report === 'marketing_referral_pre_sale') $result['metric_version'] = $this->registeredActualMetricVersion();
        if ($report === 'operations_health_data') {
            $result['metric_version'] = $this->registeredActualMetricVersion();
        }
        return $result;
    }

    /**
     * Read only the actual-performance store goals explicitly bound to this
     * report. A partial store set cannot divide the full-scope actual amount.
     * Personal participation has no matching personal scheme goal.
     *
     * @return array<string,int> Complete month => target cents
     */
    private function comparisonTargetAmounts(array $stores, array $months): array
    {
        if ($this->participantEmployeeId > 0) return [];
        $bound = (new TargetSchemeServices())->effective([
            'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID,
            'store_ids' => $stores,
        ], 'operations_performance_comparison', $months);
        $version = (string)(MetricDefinitionRegistry::get('actual_performance')['metric_version'] ?? '');
        $allowedStores = array_fill_keys($stores, true);
        $byMonth = [];
        foreach ((array)($bound['lines'] ?? []) as $line) {
            if ((string)($line['metric_code'] ?? '') !== 'actual_performance'
                || (string)($line['scope_type'] ?? '') !== 'store'
                || (int)($line['scope_id'] ?? -1) !== 0) continue;
            if ((string)($line['metric_version'] ?? '') !== $version) {
                throw new \InvalidArgumentException('目标指标口径已更新，请重新核对方案后启用');
            }
            $storeId = (int)($line['store_id'] ?? 0);
            if (!isset($allowedStores[$storeId])) continue;
            $byMonth[(string)$line['month_key']][$storeId] = (int)$line['target_value'];
        }
        $targets = [];
        foreach ($months as $month) {
            if (count($byMonth[$month] ?? []) !== count($allowedStores)) continue;
            $targets[$month] = array_sum($byMonth[$month]);
        }
        return $targets;
    }

    /**
     * Preserve the comparison's signed cash and current category paths while
     * discarding document/member snapshots after each authorized month scan.
     * Empty paths remain in the all-category total, never in a named category.
     */
    private function comparisonCashBucketsByMonth(array $stores, array $range): array
    {
        $centsByMonthPath = [];
        foreach ($this->cashRows($stores, $range, []) as $fact) {
            $month = substr((string)($fact['business_date'] ?? ''), 0, 7);
            $path = (string)($fact['category_path'] ?? '');
            $centsByMonthPath[$month][$path] = (int)($centsByMonthPath[$month][$path] ?? 0) + (int)($fact['amount_cents'] ?? 0);
        }
        $bucketsByMonth = [];
        foreach ($centsByMonthPath as $month => $paths) {
            foreach ($paths as $path => $cents) {
                $bucketsByMonth[$month][] = ['category_path' => (string)$path, 'amount_cents' => $cents];
            }
        }
        // Keep standalone refunds separately marked: category cells net them,
        // but full-store cash cells must not become actual-performance cells.
        foreach ($this->comparisonStandaloneRefundRows($stores, $range) as $refund) {
            $month = substr((string)$refund['business_date'], 0, 7);
            $bucketsByMonth[$month][] = ['category_path' => $refund['category_path'],
                'amount_cents' => $refund['amount_cents'], 'comparison_category_refund' => true];
        }
        return $bucketsByMonth;
    }

    /**
     * Reuse the authorized refund reader's business date, active status and
     * verified project/category resolution. Only missing standalone payment
     * allocations are supplemented; no writes, name matching or synthetic sales.
     */
    private function comparisonStandaloneRefundRows(array $stores, array $range): array
    {
        $reader = new StoreMemberConsumptionRefundReadServices();
        $query = $reader->query($stores, $range, [], $this->participantEmployeeId)
            ->whereIn('payment.source_document_type', ['manual_refund', 'imported_card_refund'])
            ->whereNotExists(function ($allocation): void {
                $allocation->name('cashier_v3_payment_sale_allocation_fact')->alias('refund_allocation')
                    ->whereRaw('refund_allocation.tenant_id=payment.tenant_id')
                    ->whereRaw('refund_allocation.payment_fact_id=payment.fact_id')
                    ->where('refund_allocation.status', 'effective');
            });
        // The authorized refund query already joins the payment fact. Capture
        // its identity here so this report never requires a shared reader edit.
        $paymentByRefund = [];
        foreach ((clone $query)->fieldRaw('refund_operation.id,payment.fact_id payment_fact_id')->select()->toArray() as $identity) {
            $paymentByRefund[(int)$identity['id']] = (string)$identity['payment_fact_id'];
        }
        $ids = array_keys($paymentByRefund);
        $rows = [];
        foreach (array_chunk($ids, 500) as $batch) {
            foreach ($reader->rows($query, $batch, false) as $refund) {
                $rows[] = [
                    'id' => 'comparison-refund:' . $refund['id'], 'store_id' => (int)$refund['store_id'],
                    'payment_fact_id' => $paymentByRefund[(int)$refund['id']] ?? '',
                    'member_id' => (int)$refund['member_id'], 'member_name' => (string)$refund['member_name_snapshot'],
                    'order_id' => (string)$refund['order_id'], 'order_no_snapshot' => (string)$refund['order_no_snapshot'],
                    'business_date' => (string)$refund['business_date'], 'amount_cents' => (int)$refund['amount_cents'],
                    'category_id' => (int)$refund['current_category_id'], 'category_path' => (string)$refund['category_path_snapshot'],
                    // Item reports reuse this deduplicated standalone refund
                    // population without resolving product identity a second time.
                    'platform_product_id' => (int)($refund['platform_product_id'] ?? 0),
                    'item_id' => (string)$refund['current_product_id'], 'item_name' => (string)$refund['item_name_snapshot'],
                    'store_name' => (string)$refund['store_name_snapshot'],
                ];
            }
        }
        return $rows;
    }

    /** Keep health summary refunds on the same authorized, deduplicated population as its drilldown. */
    private function appendMarketingHealthRefundBuckets(array &$buckets, array $stores, array $months): void
    {
        // Quarter-sized reads bound the refund reader's category resolution
        // memory while preserving refund-completion business dates.
        foreach (array_chunk($months, 3) as $quarter) {
            $first = $quarter[0] . '-01';
            $last = end($quarter) . '-01';
            $range = ['start' => $first, 'end' => date('Y-m-t', strtotime($last))];
            foreach ($this->comparisonStandaloneRefundRows($stores, $range) as $refund) {
                $month = substr((string)$refund['business_date'], 0, 7);
                $buckets[$month][] = $this->healthCashFact($refund);
            }
        }
    }

    /** Keep only dimensions consumed by health cash formulas after source and category resolution. */
    private function healthCashFact(array $fact): array
    {
        return [
            'store_id' => $fact['store_id'] ?? 0,
            'member_id' => $fact['member_id'] ?? 0,
            'amount_cents' => $fact['amount_cents'] ?? 0,
            'business_source_label' => $fact['business_source_label'] ?? '',
        ];
    }

    /** Health service formulas use frozen source/experience plus the resolved category. */
    private function healthServiceFact(array $fact): array
    {
        return [
            'store_id' => $fact['store_id'] ?? 0,
            'member_id' => $fact['member_id'] ?? 0,
            'business_date' => $fact['business_date'] ?? '',
            'service_fact_id' => $fact['service_fact_id'] ?? '',
            'service_record_no' => $fact['service_record_no'] ?? '',
            'checkout_request_id' => $fact['checkout_request_id'] ?? '',
            'quantity' => $fact['quantity'] ?? 0,
            'is_experience' => $fact['is_experience'] ?? 0,
            'category_path' => $fact['category_path'] ?? '',
            'consumption_cents' => $fact['consumption_cents'] ?? 0,
            'business_source_label' => $fact['business_source_label'] ?? '',
        ];
    }

    private function monthlyValue(string $key, array $row, array $context)
    {
        $cash = (array)$context['cash']; $allCash = (array)$context['allCash']; $services = (array)$context['services'];
        $report = (string)($context['report'] ?? '');
        if ((self::REPORTS[$report]['kind'] ?? '') === 'pre_sale') {
            // Source cohorts use the precomputed payment events. Generic
            // member counts would silently bypass the first-¥500 rule.
            if ($key === 'experience_actual') return $this->experienceMemberCount(
                $this->filterSourceFacts($services, (array)$context['rule']));
            if (in_array($key, ['new_customer_actual', 'new_customer_amount_actual', 'deal_people',
                'deal_amount', 'first_year_amount', 'transition_people', 'transition_amount'], true)) {
                $metrics = in_array($key, ['deal_people', 'deal_amount'], true)
                    ? (array)($context['preSaleThresholdMetrics'] ?? []) : (array)($context['preSaleMetrics'] ?? []);
                if ($key === 'new_customer_actual' || $key === 'deal_people') return (int)($metrics['first_people'] ?? 0);
                if ($key === 'new_customer_amount_actual' || $key === 'deal_amount') return $this->money((int)($metrics['first_amount'] ?? 0));
                if ($key === 'first_year_amount') return $this->money((int)($metrics['first_year_amount'] ?? 0));
                if ($key === 'transition_people') return (int)($metrics['transition_people'] ?? 0);
                return $this->money((int)($metrics['transition_amount'] ?? 0));
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
        // A 老客首护由成功业务发生时冻结的来源代码 A 识别，不能用名称中的
        // 任意字母 A 做模糊匹配，也不能由当前会员资料反推历史来源。
        $healthSourceServices = $report === 'operations_health_data' ? $this->filterSourceFacts($services, ['source_codes' => ['A']]) : $services;
        // 门店到店/活客/售后人次只看非体验服务；人次按会员日去重，项目数和消耗仍读完整事实。
        $storeVisits = \app\services\query\metric\StoreCustomerVisitEligibility::filter($services);
        $healthVisits = \app\services\query\metric\StoreCustomerVisitEligibility::filter($healthSourceServices);
        $healthSourceCash = $report === 'operations_health_data' ? $this->filterSourceFacts($allCash, ['source_codes' => ['A']]) : $allCash;
        $amount = $this->factAmount($allCash); $sourceAmount = $this->factAmount($cash); $active = $this->memberCount($healthVisits);
        if ($report === 'marketing_health_data') {
            // The three displayed cash measures share signed facts but have
            // distinct meanings: receipts are positive, refunds are positive
            // presentation of negative facts, and actual is their difference.
            $amount = (int)$context['healthGrossCents'];
            if ($key === 'refund_amount') return $this->money((int)$context['healthRefundCents']);
            if ($key === 'all_actual_amount') return $this->money($amount - (int)$context['healthRefundCents']);
            if ($key === 'all_previous_amount') return $this->money((int)$context['healthPriorGrossCents']);
        }
        $beautyCash = $this->categoryAmount($allCash, '生美'); $beautyServices = $this->categoryServices($services, '生美');
        $amountByCategory = function (string $category) use ($allCash): int { return $this->categoryAmount($allCash, $category); };
        if ($report === 'operations_performance_comparison') {
            // Category rows include the missing refunds; only the full-cash
            // formulas exclude them. Registered actual performance is unchanged.
            $amount = $this->factAmount(array_values(array_filter($allCash,
                static fn(array $fact): bool => empty($fact['comparison_category_refund']))));
            $context['previousCashForTotal'] = array_values(array_filter((array)$context['previousCash'],
                static fn(array $fact): bool => empty($fact['comparison_category_refund'])));
            $month = substr((string)($context['monthRange']['start'] ?? ''), 0, 7);
            if ($key === 'full_target_amount') return array_key_exists($month, (array)$context['comparisonTargets'])
                ? $this->money((int)$context['comparisonTargets'][$month]) : '-';
            if ($key === 'all_actual_amount') return $month . '-01' < MetricDefinitionRegistry::COVERAGE_START
                ? '-' : $this->money((int)($context['comparisonActual'][$month] ?? 0));
            if ($key === 'all_completion_rate') return ($row['full_target_amount'] ?? '-') === '-'
                || ($row['all_actual_amount'] ?? '-') === '-'
                ? '-' : $this->ratio($this->cents($row['all_actual_amount'] ?? '-'), $this->cents($row['full_target_amount']));
        }
        if ($key === 'store_count') return count((array)$context['stores']);
        if (in_array($key, ['all_current_amount'], true)) return $this->money($amount);
        if (in_array($key, ['all_previous_amount'], true)) return $this->money($this->factAmount((array)($context['previousCashForTotal'] ?? $context['previousCash'])));
        if ($key === 'all_difference') return $this->money($amount - $this->cents($row['all_previous_amount'] ?? '-'));
        if ($key === 'performance_completion_rate') return $this->ratio($amount, $this->cents($row['performance_target'] ?? '-'));
        if (preg_match('/^(six_dimension|private|haomei|beauty)_(current|previous)_amount$/', $key, $m)) {
            $category = ['six_dimension' => '六维', 'private' => '花园', 'haomei' => '昊美', 'beauty' => '生美'][$m[1]];
            return $this->money($m[2] === 'current' ? $amountByCategory($category) : $this->categoryAmount((array)$context['previousCash'], $category));
        }
        if (preg_match('/^(six_dimension|private|haomei|beauty)_difference$/', $key, $m)) return $this->money($this->cents($row[$m[1] . '_current_amount'] ?? '-') - $this->cents($row[$m[1] . '_previous_amount'] ?? '-'));
        if ($key === 'active_members') return $active;
        if ($key === 'post_sale_visits') return \app\services\query\metric\StoreCustomerVisitEligibility::countDailyVisits($healthVisits);
        if ($key === 'consuming_members') return $this->memberCount($healthSourceCash);
        if ($key === 'consumption_rate') return $this->ratio((int)($row['consuming_members'] ?? 0), $active);
        if ($key === 'store_average_visits') return $this->moneyRatio(\app\services\query\metric\StoreCustomerVisitEligibility::countDailyVisits($healthVisits) * 100, count((array)$context['stores']));
        if ($key === 'monthly_service_visits') return $this->moneyRatio(\app\services\query\metric\StoreCustomerVisitEligibility::countDailyVisits($healthVisits) * 100, 12);
        if ($key === 'beauty_service_count') return $this->serviceCount($beautyServices);
        if ($key === 'consumption_amount') return $this->money($this->serviceConsumptionAmount($beautyServices));
        if ($key === 'consumption_share') return $this->ratio($this->cents($row['consumption_amount'] ?? '-'), $amount);
        if ($key === 'staff_difference') return ($row['required_staff'] ?? '-') === '-' || ($row['actual_staff'] ?? '-') === '-' ? '-' : (int)$row['required_staff'] - (int)$row['actual_staff'];
        if ($key === 'beautician_monthly_projects') return $this->moneyRatio($this->serviceCount($beautyServices) * 100, (int)($row['actual_staff'] ?? 0));
        if ($key === 'beautician_monthly_visits') return $this->moneyRatio(\app\services\query\metric\StoreCustomerVisitEligibility::countDailyVisits($storeVisits) * 100, (int)($row['actual_staff'] ?? 0));
        if ($key === 'store_average_amount') {
            $month = substr((string)($context['monthRange']['start'] ?? ''), 0, 7);
            $performance = $report === 'operations_health_data'
                ? (int)($context['healthActual'][$month] ?? 0)
                : ($report === 'marketing_post_sale_performance'
                    ? (int)($context['postSaleActual'][$month] ?? 0) : $amount);
            return $this->moneyRatio($performance, count((array)$context['stores']));
        }
        if ($key === 'staff_unit_output') return $this->moneyRatio($this->cents($row['store_average_amount'] ?? '-'), (int)($row['actual_staff'] ?? 0));
        if ($key === 'staff_consumption_output') return $this->moneyRatio($this->cents($row['consumption_amount'] ?? '-'), (int)($row['actual_staff'] ?? 0));
        if ($key === 'weak_store_count' || $key === 'strong_store_count') return $this->storeAmountBand(
            $report === 'marketing_health_data' ? (array)$context['healthPositiveCash'] : $allCash,
            $key === 'weak_store_count' ? 10000000 : 20000000, $key === 'weak_store_count');
        if ($key === 'deal_visits') {
            $countCash = $report === 'marketing_post_sale_performance' ? (array)$context['countCash'] : $allCash;
            $countBeautyCash = $report === 'marketing_post_sale_performance'
                ? $this->categoryAmount($countCash, '生美') : $beautyCash;
            return $this->factLineCount($countBeautyCash === 0 ? [] : $this->categoryFacts($countCash, '生美'));
        }
        if ($key === 'deal_people') {
            $countCash = $report === 'marketing_post_sale_performance' ? (array)$context['countCash'] : $allCash;
            return !empty($context['rule']['post_sale'])
                ? $this->memberCount($this->categoryFacts($countCash, '生美')) : $this->memberCount($cash);
        }
        if ($key === 'post_sale_amount') {
            $month = substr((string)($context['monthRange']['start'] ?? ''), 0, 7);
            return $this->money((int)($context['postSaleActual'][$month] ?? 0));
        }
        if ($key === 'visit_deal_rate') return $this->ratio((int)($row['deal_visits'] ?? 0), (int)($row['post_sale_visits'] ?? 0));
        if ($key === 'people_deal_rate') return $this->ratio((int)($row['deal_people'] ?? 0), (int)($row['post_sale_visits'] ?? 0));
        if ($key === 'visit_unit_output') return $this->moneyRatio(
            (int)($context['postSaleActual'][substr((string)($context['monthRange']['start'] ?? ''), 0, 7)] ?? 0),
            (int)($row['deal_visits'] ?? 0));
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

    /** Keep sale allocation, reversal, void and personnel scope identical for all cash projections. */
    private function cashFactBaseQuery(array $stores, array $range, bool $applyParticipantScope = true)
    {
        $base = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('p')
            ->leftJoin('cashier_v3_payment_sale_allocation_fact original', 'original.tenant_id=p.tenant_id AND original.allocation_fact_id=p.reversal_of')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.fact_id=COALESCE(original.sale_fact_id,p.sale_fact_id)')
            ->where('p.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('p.store_id', $stores)
            ->where('p.status', 'effective');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($base, 's.tenant_id', 's.order_id');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSupplementAllocations($base, 'p');
        // A refund allocation follows its original receipt's payment method.
        // Excluding both directions prevents historical other-collection cash
        // from re-entering any report through a reversal or drilldown. The
        // allocation snapshot covers older rows without a payment fact.
        $base->whereRaw("(p.payment_method IS NULL OR p.payment_method <> 'other_collection')");
        $base->whereNotExists(function ($receipt): void {
            $receipt->name('cashier_v3_payment_fact')->alias('excluded_cash_method')
                ->whereRaw('excluded_cash_method.tenant_id=p.tenant_id AND excluded_cash_method.fact_id=COALESCE(original.payment_fact_id,p.payment_fact_id)')
                ->where('excluded_cash_method.payment_method', 'other_collection');
        });
        if (isset($range['start']) && (string)$range['start'] !== '') $base->whereBetween('p.business_date', [$range['start'], $range['end']]);
        else $base->where('p.business_date', '<=', (string)$range['end']);
        if ($applyParticipantScope && $this->participantEmployeeId > 0) (new StoreReportParticipantScopeServices())->applyOrder($base, 'p.order_id', $this->participantEmployeeId);
        return $base;
    }

    /**
     * Source-only reports use the receipt's source at settlement time. Historical
     * repayments can have an effective payment fact without a sale allocation;
     * add only those missing facts so cash is neither lost nor counted twice.
     */
    private function sourceCashRows(array $stores, array $range): array
    {
        $allocated = $this->cashFactBaseQuery($stores, $range, false)
            ->leftJoin('cashier_v3_payment_fact receipt', 'receipt.tenant_id=p.tenant_id AND receipt.fact_id=p.payment_fact_id')
            ->leftJoin('cashier_v3_payment_fact original_receipt', 'original_receipt.tenant_id=original.tenant_id AND original_receipt.fact_id=original.payment_fact_id');
        $this->applyReceiptParticipantScope($allocated);
        // Refund reversals retain the original receipt's source and BJ document
        // identity, so their signed cash offsets the same cohort as the payment.
        $rows = $allocated->fieldRaw("p.id,p.member_id,CASE WHEN original_receipt.source_document_type='debt_repayment' THEN original_receipt.order_id WHEN receipt.source_document_type='debt_repayment' THEN receipt.order_id ELSE p.order_id END order_id,CASE WHEN original_receipt.source_document_type='debt_repayment' THEN original_receipt.order_no_snapshot WHEN receipt.source_document_type='debt_repayment' THEN receipt.order_no_snapshot ELSE p.order_no_snapshot END order_no_snapshot,CASE WHEN original_receipt.source_document_type='debt_repayment' THEN original_receipt.source_document_type WHEN receipt.source_document_type='debt_repayment' THEN receipt.source_document_type ELSE '' END source_document_type,CASE WHEN original_receipt.source_document_type='debt_repayment' THEN original_receipt.natural_key WHEN receipt.source_document_type='debt_repayment' THEN receipt.natural_key ELSE '' END receipt_natural_key,p.source_line_id,p.business_date,p.amount_cents,s.member_name_snapshot,s.order_no_snapshot sale_order_no_snapshot,CASE WHEN original_receipt.source_document_type='debt_repayment' THEN original_receipt.business_source_label_snapshot WHEN receipt.source_document_type='debt_repayment' THEN receipt.business_source_label_snapshot ELSE s.business_source_label_snapshot END business_source_label")
            ->select()->toArray();

        $missing = Db::name('cashier_v3_payment_fact')->alias('pf')
            ->join('cashier_v3_debt_repayment r', 'r.tenant_id=pf.tenant_id AND r.repayment_id=pf.order_id')
            ->where('pf.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('pf.store_id', $stores)
            ->where('pf.source_document_type', 'debt_repayment')
            ->where('pf.payment_method', '<>', 'other_collection')
            ->where('pf.status', 'effective')->where('pf.fact_direction', 'forward')
            ->where('pf.amount_cents', '>', 0)->where('r.status', 'succeeded')
            ->whereNotExists(function ($allocation): void {
                $allocation->name('cashier_v3_payment_sale_allocation_fact')->alias('existing_receipt_allocation')
                    ->whereRaw('existing_receipt_allocation.tenant_id=pf.tenant_id')
                    ->whereRaw('existing_receipt_allocation.payment_fact_id=pf.fact_id')
                    ->where('existing_receipt_allocation.status', 'effective');
            });
        $normalScope = new StoreReportNormalDataScopeServices();
        $normalScope->excludeVoidedSalesOrderFacts($missing, 'r.tenant_id', 'r.sales_order_id');
        $normalScope->excludeVoidedSupplementPaymentFacts($missing, 'pf.tenant_id', 'pf.store_id', 'pf.order_id');
        if (isset($range['start']) && (string)$range['start'] !== '') $missing->whereBetween('pf.business_date', [$range['start'], $range['end']]);
        else $missing->where('pf.business_date', '<=', (string)$range['end']);
        if ($this->participantEmployeeId > 0) (new StoreReportParticipantScopeServices())->applySupplement($missing, 'pf.order_id', $this->participantEmployeeId);

        foreach ($missing->fieldRaw('pf.id,pf.member_id,pf.order_id,pf.order_no_snapshot,pf.source_document_type,pf.natural_key receipt_natural_key,pf.source_line_id,pf.business_date,pf.amount_cents,pf.member_name_snapshot,r.sales_order_no_snapshot sale_order_no_snapshot,pf.business_source_label_snapshot business_source_label')->select()->toArray() as $fact) {
            $rows[] = $fact;
        }
        // Imported and old-card-entry debt is real cash, but neither creates
        // a presale person. Resolve only repayments in this authorized scan.
        $repaymentIds = [];
        $excludedRepaymentIds = [];
        foreach ($rows as $fact) {
            if ((string)($fact['source_document_type'] ?? '') !== 'debt_repayment') continue;
            $repaymentId = trim((string)($fact['order_id'] ?? ''));
            if (strpos((string)($fact['receipt_natural_key'] ?? ''), 'historical_debt_repayment:payment:') === 0) {
                if ($repaymentId !== '') $excludedRepaymentIds[$repaymentId] = true;
                continue;
            }
            if ($repaymentId !== '') $repaymentIds[$repaymentId] = true;
        }
        $excludedRepaymentIds += StoreReportImportedRepaymentCountScope::excludedSalesRepaymentIds(
            array_keys($repaymentIds), $stores);
        foreach ($rows as &$fact) {
            $fact['is_excluded_person_repayment'] = (string)($fact['source_document_type'] ?? '') === 'debt_repayment'
                && isset($excludedRepaymentIds[(string)($fact['order_id'] ?? '')]);
            unset($fact['source_document_type'], $fact['receipt_natural_key']);
        }
        unset($fact);
        return $rows;
    }

    /** A reversal inherits its original receipt's personnel scope, not the sale's. */
    private function applyReceiptParticipantScope($query): void
    {
        if ($this->participantEmployeeId <= 0) return;
        $employeeId = $this->participantEmployeeId;
        $query->where(function ($visible) use ($employeeId): void {
            $visible->where(function ($sale) use ($employeeId): void {
                $sale->whereRaw("COALESCE(receipt.source_document_type,'')<>'debt_repayment' AND COALESCE(original_receipt.source_document_type,'')<>'debt_repayment'");
                (new StoreReportParticipantScopeServices())->applyOrder($sale, 'p.order_id', $employeeId);
            })->where(function ($repayment) use ($employeeId): void {
                $repayment->where('receipt.source_document_type', 'debt_repayment');
                (new StoreReportParticipantScopeServices())->applySupplement($repayment, 'receipt.order_id', $employeeId);
            }, 'OR')->where(function ($reversal) use ($employeeId): void {
                $reversal->where('original_receipt.source_document_type', 'debt_repayment');
                (new StoreReportParticipantScopeServices())->applySupplement($reversal, 'original_receipt.order_id', $employeeId);
            }, 'OR');
        });
    }

    private function cashRows(array $stores, array $range, array $categoryIds, bool $withDocument = false, bool $singleScan = false, bool $attributeHistoricalCardRepayments = false): array
    {
        // Repayment allocations point to their original sale. Personal scope
        // must authorize the repayment's own personnel, not sale personnel.
        $base = $this->cashFactBaseQuery($stores, $range, $this->participantEmployeeId <= 0);
        if ($this->participantEmployeeId > 0) {
            $base->leftJoin('cashier_v3_payment_fact receipt', 'receipt.tenant_id=p.tenant_id AND receipt.fact_id=p.payment_fact_id')
                ->leftJoin('cashier_v3_payment_fact original_receipt', 'original_receipt.tenant_id=original.tenant_id AND original_receipt.fact_id=original.payment_fact_id');
            $this->applyReceiptParticipantScope($base);
        }
        $documentFields = '';
        if ($withDocument) {
            // Receipt identity differs from the original sale for repayments
            // and refunds; only drilldown pays for this extra fact join.
            if ($this->participantEmployeeId <= 0) {
                $base->leftJoin('cashier_v3_payment_fact receipt', 'receipt.tenant_id=p.tenant_id AND receipt.fact_id=p.payment_fact_id');
            }
            $documentFields = "p.order_no_snapshot,COALESCE(NULLIF(receipt.order_id,''),p.order_id) document_order_id,COALESCE(NULLIF(receipt.order_no_snapshot,''),p.order_no_snapshot) document_no_snapshot,";
        }

        // Direct sales use the linked sale item. Cards retain their frozen cash
        // weights, but their category is read from each contained platform item.
        $direct = clone $base;
        if ($singleScan) {
            // R61 reads the authorized receipt population once. Split cards only
            // after selection; their full component weights must remain intact.
            $all = $direct->leftJoin('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
                ->fieldRaw("p.id,p.store_id,p.member_id,p.order_id,{$documentFields}p.source_line_id,p.business_date,p.amount_cents,p.sale_amount_cents,p.organization_id,s.organization_path_snapshot,s.business_source_primary_id,s.store_name_snapshot store_name,s.member_name_snapshot member_name,s.business_source_label_snapshot business_source_label,s.fact_id sale_fact_id,s.source_type,d.item_id,d.item_name_snapshot item_name,d.category_id_snapshot category_id,d.category_path_snapshot category_path,s.quantity")
                ->select()->toArray();
            $rows = []; $payments = [];
            foreach ($all as $fact) {
                if ($fact['source_type'] === 'card') $payments[] = $fact;
                elseif ($fact['item_id'] !== null) $rows[] = $fact;
            }
            unset($all);
        } else {
            $direct->join('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
                ->where('s.source_type', '<>', 'card');
            $rows = $direct->fieldRaw("p.id,p.store_id,p.member_id,p.order_id,{$documentFields}p.source_line_id,p.business_date,p.amount_cents,p.sale_amount_cents,p.organization_id,s.organization_path_snapshot,s.business_source_primary_id,MAX(s.store_name_snapshot) store_name,MAX(s.member_name_snapshot) member_name,MAX(s.business_source_label_snapshot) business_source_label,s.fact_id sale_fact_id,d.item_id,d.item_name_snapshot item_name,d.category_id_snapshot category_id,d.category_path_snapshot category_path,s.quantity")
                ->group('p.id')->select()->toArray();
        }

        // Some imported repayments have a successful payment fact but no sale
        // allocation. A unique debt item recovers its product; ambiguous ones
        // retain their cash in the total but stay unclassified, never guessed.
        $repayments = $this->unallocatedRepayments($stores, $range);
        // This item report must credit real products, while other reports retain
        // their separately accepted attribution until they are reconciled.
        if ($attributeHistoricalCardRepayments) {
            $repayments = $this->marketingHistoricalCardRepayments($repayments);
        }
        foreach ($repayments as $repayment) $rows[] = $repayment;

        if (!$singleScan) {
            $card = clone $base;
            $payments = $card->where('s.source_type', 'card')->fieldRaw("p.id,p.store_id,p.member_id,p.order_id,{$documentFields}p.source_line_id,p.business_date,p.amount_cents,p.organization_id,s.organization_path_snapshot,s.business_source_primary_id,MAX(s.store_name_snapshot) store_name,MAX(s.member_name_snapshot) member_name,MAX(s.business_source_label_snapshot) business_source_label,s.fact_id sale_fact_id")
                ->group('p.id')->select()->toArray();
        }
        if ($payments === []) return $this->currentCategoryRows($rows, $categoryIds);
        $saleFactIds = array_values(array_unique(array_filter(array_column($payments, 'sale_fact_id'))));
        if ($saleFactIds === []) return $this->currentCategoryRows($rows, $categoryIds);
        $bySale = [];
        foreach (Db::name('cashier_v3_card_sale_item_allocation_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sale_fact_id', $saleFactIds)->where('status', 'effective')
            ->field('allocation_fact_id,sale_fact_id,component_product_id,item_name_snapshot,category_id_snapshot,category_path_snapshot,component_count,sale_amount_cents,configured_amount_cents')->select()->toArray() as $item) {
            $bySale[(string)$item['sale_fact_id']][] = $item;
        }
        foreach ($payments as $payment) {
            $items = (array)($bySale[(string)$payment['sale_fact_id']] ?? []);
            if ($items === []) continue;
            $allocated = $this->allocateSigned((int)$payment['amount_cents'], $items);
            foreach ($items as $item) {
                $rows[] = [
                    'id' => (int)$payment['id'], 'store_id' => (int)$payment['store_id'], 'member_id' => (int)$payment['member_id'],
                    'sale_fact_id' => (string)$payment['sale_fact_id'],
                    'order_id' => (string)$payment['order_id'], 'order_no_snapshot' => (string)($payment['order_no_snapshot'] ?? ''), 'document_order_id' => (string)($payment['document_order_id'] ?? ''), 'document_no_snapshot' => (string)($payment['document_no_snapshot'] ?? ''), 'source_line_id' => (string)$payment['source_line_id'], 'business_date' => (string)$payment['business_date'],
                    'amount_cents' => (int)($allocated[(string)$item['allocation_fact_id']] ?? 0), 'sale_amount_cents' => (int)($item['sale_amount_cents'] ?? 0), 'organization_id' => (string)$payment['organization_id'], 'business_source_primary_id' => (int)($payment['business_source_primary_id'] ?? 0),
                    'organization_path_snapshot' => (string)$payment['organization_path_snapshot'], 'store_name' => (string)$payment['store_name'], 'member_name' => (string)$payment['member_name'], 'business_source_label' => (string)$payment['business_source_label'],
                    'item_id' => (string)$item['component_product_id'], 'item_name' => (string)$item['item_name_snapshot'],
                    'category_id' => (int)$item['category_id_snapshot'], 'category_path' => (string)$item['category_path_snapshot'], 'quantity' => (int)$item['component_count'],
                ];
            }
        }
        return $this->currentCategoryRows($rows, $categoryIds);
    }

    /** Include every unallocated successful repayment; classify only unique product links. */
    private function unallocatedRepayments(array $stores, array $range): array
    {
        $itemsTable = Db::name('store_debt_item')->getTable();
        $query = Db::name('cashier_v3_payment_fact')->alias('pf')
            ->join('cashier_v3_debt_repayment r', 'r.tenant_id=pf.tenant_id AND r.repayment_id=pf.order_id')
            ->leftJoin('store_debt_item item', "item.debt_id=r.debt_id AND (SELECT COUNT(*) FROM {$itemsTable} one_item WHERE one_item.debt_id=r.debt_id)=1")
            ->leftJoin('store_order_cart_info cart', 'cart.id=item.cart_info_id')
            ->where('pf.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('pf.store_id', $stores)
            ->where('pf.source_document_type', 'debt_repayment')
            ->where('pf.payment_method', '<>', 'other_collection')
            ->where('pf.status', 'effective')->where('pf.fact_direction', 'forward')
            ->where('pf.amount_cents', '>', 0)->where('r.status', 'succeeded')
            ->whereNotExists(function ($allocation): void {
                $allocation->name('cashier_v3_payment_sale_allocation_fact')->alias('existing_allocation')
                    ->whereRaw('existing_allocation.tenant_id=pf.tenant_id')
                    ->whereRaw('existing_allocation.payment_fact_id=pf.fact_id')
                    ->where('existing_allocation.status', 'effective');
            });
        $normalScope = new StoreReportNormalDataScopeServices();
        $normalScope->excludeVoidedSalesOrderFacts($query, 'r.tenant_id', 'r.sales_order_id');
        $normalScope->excludeVoidedSupplementPaymentFacts($query, 'pf.tenant_id', 'pf.store_id', 'pf.order_id');
        if (isset($range['start']) && (string)$range['start'] !== '') $query->whereBetween('pf.business_date', [$range['start'], $range['end']]);
        else $query->where('pf.business_date', '<=', (string)$range['end']);
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applySupplement($query, 'pf.order_id', $this->participantEmployeeId);
        }
        return $query->fieldRaw("pf.id,pf.fact_id payment_fact_id,1 unallocated_repayment,pf.store_id,pf.member_id,r.sales_order_id order_id,pf.order_no_snapshot,
            pf.order_id document_order_id,pf.order_no_snapshot document_no_snapshot,pf.source_line_id,
            pf.business_date,pf.amount_cents,IF(cart.id IS NULL,NULL,ROUND(cart.pay_price*100)) sale_amount_cents,
            pf.organization_id,pf.organization_path_snapshot,pf.business_source_primary_id,
            pf.store_name_snapshot store_name,pf.member_name_snapshot member_name,
            pf.business_source_label_snapshot business_source_label,item.order_id original_order_record_id,item.product_id item_id,
            item.product_name item_name,item.cart_num quantity")
            ->select()->toArray();
    }

    /** Resolve imported card repayments to the original order's frozen component lines. */
    private function marketingHistoricalCardRepayments(array $repayments): array
    {
        $placeholderNames = ['历史卡项欠款' => true, 'SWXT历史卡项欠款' => true];
        $orderIds = []; $fallbackProductIds = [];
        foreach ($repayments as $row) {
            if (!isset($placeholderNames[(string)($row['item_name'] ?? '')])) continue;
            $orderId = (int)($row['original_order_record_id'] ?? 0);
            if ($orderId > 0) $orderIds[$orderId] = true;
            $productId = (int)($row['item_id'] ?? 0);
            if ($productId > 0) $fallbackProductIds[$productId] = true;
        }
        $componentsByOrder = []; $productNames = [];
        foreach (array_chunk(array_keys($orderIds), 300) as $chunk) {
            foreach (Db::name('store_order_cart_info')->alias('component')
                ->leftJoin('store_product product', 'product.id=component.product_id')
                ->whereIn('component.oid', $chunk)->where('component.product_type', 6)
                ->field('component.id,component.oid,component.product_id,component.cart_num,component.pay_price,component.total_price,component.cart_info,product.store_name current_name')
                ->order('component.id')->select()->toArray() as $component) {
                if ((int)$component['product_id'] > 0) $componentsByOrder[(int)$component['oid']][] = $component;
            }
        }
        foreach (array_chunk(array_keys($fallbackProductIds), 300) as $chunk) {
            $productNames += Db::name('store_product')->whereIn('id', $chunk)->column('store_name', 'id');
        }

        $attributed = [];
        foreach ($repayments as $repayment) {
            if (!isset($placeholderNames[(string)($repayment['item_name'] ?? '')])) {
                $attributed[] = $repayment;
                continue;
            }
            $components = $componentsByOrder[(int)($repayment['original_order_record_id'] ?? 0)] ?? [];
            if ($components === []) {
                // No project snapshot exists for this card: show its actual card
                // product when linked, never the migration's debt placeholder.
                $productId = (int)($repayment['item_id'] ?? 0);
                $repayment['item_name'] = trim((string)($productNames[$productId] ?? ''));
                if ($repayment['item_name'] === '') $repayment['item_id'] = 0;
                $attributed[] = $repayment;
                continue;
            }
            $weights = [];
            foreach ($components as $component) {
                $configured = $this->cents($component['pay_price']);
                if ($configured <= 0) $configured = $this->cents($component['total_price']);
                $weights[] = [
                    'allocation_fact_id' => (string)$component['id'],
                    'sale_amount_cents' => max(0, $configured),
                    'component_count' => max(1, (int)$component['cart_num']),
                ];
            }
            // Split each receipt once, using original configured amounts and
            // deterministic cent remainders; the parts must still sum to cash.
            $amounts = $this->allocateSigned((int)$repayment['amount_cents'], $weights);
            foreach ($components as $component) {
                $amount = (int)($amounts[(string)$component['id']] ?? 0);
                if ($amount === 0) continue;
                $snapshot = json_decode((string)($component['cart_info'] ?? ''), true);
                $name = trim((string)($snapshot['productInfo']['store_name']
                    ?? $snapshot['project_name'] ?? $component['current_name'] ?? ''));
                $attributed[] = array_replace($repayment, [
                    'amount_cents' => $amount,
                    'item_id' => $name === '' ? 0 : (int)$component['product_id'],
                    'item_name' => $name,
                    'quantity' => max(1, (int)$component['cart_num']),
                ]);
            }
        }
        return $attributed;
    }

    private function serviceRows(array $stores, array $range, array $categoryIds, bool $forHealth = false, bool $includeSourceLabel = true, bool $batchConsumption = false): array
    {
        if ($batchConsumption && $forHealth) {
            // Health's reduced projection has no source-line identity for a batched performance lookup.
            throw new \InvalidArgumentException('健康报表精简服务事实不支持批量消耗金额查询');
        }
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sv.store_id', $stores)->where('sv.service_status', 'completed');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        if (isset($range['start']) && (string)$range['start'] !== '') $query->whereBetween('sv.business_date', [$range['start'], $range['end']]);
        else $query->where('sv.business_date', '<=', (string)$range['end']);
        if ($this->participantEmployeeId > 0) (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        // Monthly health formulas need no receipt identity, member name or
        // organization snapshots. The product link remains for current-category
        // resolution before the result is projected into month buckets.
        $fields = $forHealth
            ? 'sv.service_fact_id,sv.service_record_no,sv.checkout_request_id,sv.store_id,sv.member_id,sv.business_date,sv.project_id item_id,sv.is_experience,sv.quantity'
            : 'sv.service_fact_id,sv.service_record_no,sv.checkout_request_id,sv.document_no_snapshot,sv.store_id,sv.member_id,sv.member_name_snapshot,sv.business_date,sv.organization_id,sv.organization_path_snapshot,sv.store_name_snapshot store_name,sv.project_id,sv.project_id item_id,sv.project_name_snapshot,sv.project_category_path_snapshot category_path,sv.is_experience,sv.source_line_id,sv.quantity';
        // Use the order center's event-bound authority order (sale, checkout,
        // service). Within each record the full label retains the primary A-H
        // code; a secondary display name alone cannot drive source filtering.
        // A sale-fact fallback only preserves older linked rows.
        // Pure-entitlement services have no sale fact, so that fact alone cannot
        // decide whether an A-source customer belongs in health statistics.
        if ($includeSourceLabel) {
            $query->leftJoin('cashier_v3_sales_order source_order',
                'source_order.tenant_id=sv.tenant_id AND source_order.checkout_request_id=sv.checkout_request_id');
            $query->leftJoin('cashier_v3_checkout_business_source_selection checkout_source',
                "checkout_source.tenant_id=sv.tenant_id AND checkout_source.checkout_request_id=sv.checkout_request_id AND checkout_source.checkout_kind='sale'");
        }
        $sourceField = $includeSourceLabel
            ? ",COALESCE(NULLIF(source_order.business_source_label_snapshot,''),
                NULLIF(source_order.business_source_primary_name_snapshot,''),
                NULLIF(source_order.business_source_secondary_name_snapshot,''),
                NULLIF(checkout_source.source_label_snapshot,''),
                NULLIF(checkout_source.primary_source_name_snapshot,''),
                NULLIF(checkout_source.secondary_source_name_snapshot,''),
                NULLIF(sv.business_source_label_snapshot,''),
                NULLIF(sv.business_source_primary_name_snapshot,''),
                NULLIF(sv.business_source_secondary_name_snapshot,''),
                (SELECT MAX(sf.business_source_label_snapshot) FROM eb_cashier_v3_sale_fact sf
                 WHERE sf.tenant_id=sv.tenant_id AND sf.source_line_id=sv.source_line_id
                   AND sf.fact_direction='forward' AND sf.status='effective')) business_source_label"
            : '';
        $consumptionField = $batchConsumption ? ',0 consumption_cents'
            : ",(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.tenant_id=sv.tenant_id AND pf.source_line_id=sv.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective') consumption_cents";
        $rows = $this->currentCategoryRows($query->fieldRaw($fields . $sourceField . $consumptionField)
            ->select()->toArray(), $categoryIds);
        if (!$batchConsumption || $rows === []) return $rows;

        // Resolve the selected category first, then aggregate only its service source lines once per batch.
        $lineIds = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['source_line_id'], $rows)));
        $amounts = [];
        foreach (array_chunk($lineIds, 300) as $chunk) {
            foreach (Db::name('cashier_v3_performance_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('source_line_id', $chunk)
                ->where('performance_type', 'consumption_performance_recorded')->where('status', 'effective')
                ->fieldRaw('source_line_id,SUM(amount_cents) consumption_cents')->group('source_line_id')
                ->select()->toArray() as $amount) {
                $amounts[(string)$amount['source_line_id']] = (int)$amount['consumption_cents'];
            }
        }
        foreach ($rows as &$row) $row['consumption_cents'] = (int)($amounts[(string)$row['source_line_id']] ?? 0);
        unset($row);
        return $rows;
    }

    /** Marketing pre-sale needs only completed experience identities and frozen source. */
    private function experienceServiceRows(array $stores, array $range): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)->where('sv.service_status', 'completed')
            ->where('sv.member_id', '>', 0)->where('sv.is_experience', 1)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']]);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        }
        return $query->fieldRaw("sv.member_id,sv.business_date,sv.is_experience,
            (SELECT MAX(sf.business_source_label_snapshot) FROM eb_cashier_v3_sale_fact sf
             WHERE sf.tenant_id=sv.tenant_id AND sf.source_line_id=sv.source_line_id
             AND sf.fact_direction='forward' AND sf.status='effective') business_source_label")
            ->select()->toArray();
    }

    /** Item experience counts need a current category and service-name link, not a cash fact. */
    private function itemExperienceServiceRows(array $stores, array $range, array $categoryIds): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)->where('sv.service_status', 'completed')
            ->where('sv.member_id', '>', 0)->where('sv.is_experience', 1)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']]);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        }
        $rows = $query->fieldRaw('sv.store_id,sv.member_id,sv.business_date,sv.project_id,
            sv.project_id item_id,sv.project_name_snapshot,sv.is_experience')->select()->toArray();
        return $this->currentCategoryRows($rows, $categoryIds);
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
        $result = [];
        $directDimensions = Db::name('cashier_v3_report_sale_dimension_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sale_fact_id', $saleIds)
            ->field('sale_fact_id,item_id,item_name_snapshot,category_id_snapshot,category_path_snapshot')->select()->toArray();
        foreach ($directDimensions as $dimension) {
            $sale = (array)($allocatedSales[(string)$dimension['sale_fact_id']] ?? []);
            if ($sale === [] || (string)($sale['sale']['source_type'] ?? '') === 'card') continue;
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
        if ($cardSales === []) return $this->currentCategoryRows($result, $categoryIds);
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
        return $this->currentCategoryRows($result, $categoryIds);
    }

    /** Apply one current-platform-category contract to authorized cash, service and debt fact rows. */
    private function currentCategoryRows(array $rows, array $categoryIds): array
    {
        if ($rows === []) return [];
        $links = [];
        foreach ($rows as $index => $row) {
            $links[$index] = ['store_id' => (int)$row['store_id'], 'product_id' => (int)($row['item_id'] ?? 0)];
        }
        $categories = (new StoreReportCurrentProductCategoryServices())->resolve($links, $this->categoryTreeRows());
        $allowed = array_fill_keys(array_map('intval', $categoryIds), true);
        $result = [];
        foreach ($rows as $index => $row) {
            $category = $categories[$index] ?? [];
            $row['category_id'] = (int)($category['id'] ?? 0);
            $row['category_path'] = (string)($category['path'] ?? '');
            $row['platform_product_id'] = (int)($category['platform_product_id'] ?? 0);
            $row['product_type'] = (int)($category['product_type'] ?? -1);
            if ($allowed !== [] && !isset($allowed[$row['category_id']])) continue;
            $result[] = $row;
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
            // BDEGH denotes five primary source codes, not one literal label.
            'operations_pre_sale_bdegh' => ['source_codes' => ['B', 'D', 'E', 'G', 'H']],
            'operations_referral_beautician_pre_sale' => ['source_codes' => ['C', 'F']],
            'marketing_acquisition_pre_sale' => ['tokens' => ['B导购引流']],
            'marketing_referral_pre_sale' => ['source_codes' => ['C', 'F']],
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
    /** A member's monthly band is based on distinct eligible dates, not service-line quantity. */
    private function visitBandMembers(array $services, int $min, ?int $max): int
    {
        $matched = 0;
        foreach ($services as $service) {
            if ((int)($service['member_id'] ?? 0) <= 0) continue;
            $days = (int)($service['visit_days'] ?? 0);
            if ($days >= $min && ($max === null || $days <= $max)) $matched++;
        }
        return $matched;
    }

    /** Resolve all customer-status fields once from one authorized member-month projection. */
    private function customerStatusMonthlyValues(array $services, int $regular, int $inactive): array
    {
        $active = $this->memberCount($services);
        $oneToTwo = $this->visitBandMembers($services, 1, 2);
        $threePlus = $this->visitBandMembers($services, 3, null);
        return [
            'active_members' => $active,
            'one_to_two_visits' => $oneToTwo,
            'one_to_two_visit_share' => $this->ratio($oneToTwo, $active),
            'three_plus_visits' => $threePlus,
            'three_plus_visit_share' => $this->ratio($threePlus, $active),
            'regular_customers' => $regular,
            'inactive_customers' => $inactive,
            'active_rate' => $this->ratio($active, $active + $inactive),
        ];
    }
    /** Preserve sale-event cohorts while replacing only the requested money with registered net facts. */
    private function preSaleActualMoneyFacts(string $report, array $cashHistory, array $actualFacts,
        array $monthRange, string $metric): array
    {
        $selected = $report === 'marketing_referral_pre_sale'
            ? $this->marketingReferralDetailFacts($cashHistory, $monthRange, $metric)
            : $this->preSaleDetailFacts($cashHistory, $monthRange, $metric, false);
        $firstDateByMember = []; $eventOrders = []; $eventLines = [];
        foreach ($selected as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId <= 0) continue;
            $date = (string)($fact['business_date'] ?? '');
            if (!isset($firstDateByMember[$memberId]) || $date < $firstDateByMember[$memberId]) {
                $firstDateByMember[$memberId] = $date;
            }
            $orderId = (string)($fact['order_id'] ?? '');
            $lineId = (string)($fact['source_line_id'] ?? '');
            if ($orderId !== '') $eventOrders[$memberId . '|' . $date . '|' . $orderId] = true;
            if ($lineId !== '') $eventLines[$memberId . '|' . $date . '|' . $lineId] = true;
        }
        $result = [];
        foreach ($actualFacts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            $date = (string)($fact['business_date'] ?? '');
            if (!isset($firstDateByMember[$memberId]) || $date > (string)$monthRange['end']) continue;
            if ($report === 'marketing_acquisition_pre_sale') {
                $fact['business_source_label'] = (string)($fact['source_label'] ?? '');
                if ($this->filterSourceFacts([$fact], $this->preSaleRule($report)) === []) continue;
            }
            if ($metric === 'transition_amount') {
                if ($date < (string)$monthRange['start']) continue;
                $orderKey = $memberId . '|' . $date . '|' . (string)($fact['order_id'] ?? '');
                $lineKey = $memberId . '|' . $date . '|' . (string)($fact['source_line_id'] ?? '');
                if (!isset($eventOrders[$orderKey]) && !isset($eventLines[$lineKey])) continue;
            } elseif ($date < $firstDateByMember[$memberId]) continue;
            $result[] = $fact;
        }
        return $result;
    }

    /**
     * C/F is the acquisition event, not a permanent source filter on later
     * orders. Group signed allocations by member, order and business day so a
     * multi-item sale is one purchase and a same-day reversal cannot qualify.
     */
    private function marketingReferralCohorts(array $facts, string $lastDate, bool $withFacts = false): array
    {
        $events = [];
        foreach ($facts as $position => $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            $date = (string)($fact['business_date'] ?? '');
            if ($memberId <= 0 || $date === '' || $date > $lastDate) continue;
            $orderId = trim((string)($fact['order_id'] ?? ''));
            if ($orderId === '') $orderId = 'line:' . (string)($fact['source_line_id'] ?? $fact['id'] ?? $position);
            $key = $date . '|' . $orderId;
            $factPosition = (int)($fact['id'] ?? 0);
            if ($factPosition <= 0) $factPosition = $position + 1;
            if (!isset($events[$memberId][$key])) {
                $events[$memberId][$key] = [
                    'date' => $date, 'first_position' => $factPosition,
                    'amount_cents' => 0, 'is_referral' => false,
                ];
            }
            $events[$memberId][$key]['first_position'] = min($events[$memberId][$key]['first_position'], $factPosition);
            $events[$memberId][$key]['amount_cents'] += (int)($fact['amount_cents'] ?? 0);
            if ($withFacts) $events[$memberId][$key]['facts'][] = $fact;
            $source = strtoupper(substr(trim((string)($fact['business_source_label'] ?? '')), 0, 1));
            if ($source === 'C' || $source === 'F') $events[$memberId][$key]['is_referral'] = true;
        }
        $cohorts = [];
        foreach ($events as $memberId => $memberEvents) {
            $ordered = array_values($memberEvents);
            usort($ordered, static function (array $left, array $right): int {
                $dateOrder = strcmp($left['date'], $right['date']);
                return $dateOrder !== 0 ? $dateOrder : $left['first_position'] <=> $right['first_position'];
            });
            $firstIndex = null;
            foreach ($ordered as $index => $event) {
                if ($event['is_referral'] && (int)$event['amount_cents'] > 0) {
                    $firstIndex = $index;
                    break;
                }
            }
            if ($firstIndex === null) continue;
            $afterFirst = array_slice($ordered, $firstIndex);
            $second = null;
            foreach (array_slice($afterFirst, 1) as $event) {
                if ((int)$event['amount_cents'] > 0) {
                    $second = $event;
                    break;
                }
            }
            $cohorts[$memberId] = ['first' => $afterFirst[0], 'second' => $second, 'events' => $afterFirst];
        }
        return $cohorts;
    }

    /** Monthly cells and receipt drilldown consume the same C/F acquisition cohorts. */
    private function marketingReferralMetricsByMonth(array $facts, array $months): array
    {
        $metrics = [];
        foreach ($months as $month) $metrics[$month] = [
            'first_people' => 0, 'first_amount' => 0, 'transition_people' => 0,
            'transition_amount' => 0, 'first_year_amount' => 0,
        ];
        if ($metrics === []) return [];
        $lastDate = date('Y-m-t', strtotime((string)end($months) . '-01'));
        $yearDeltas = [];
        foreach ($this->marketingReferralCohorts($facts, $lastDate) as $cohort) {
            $first = $cohort['first'];
            $firstMonth = substr($first['date'], 0, 7);
            if (isset($metrics[$firstMonth])) {
                $metrics[$firstMonth]['first_people']++;
                $metrics[$firstMonth]['first_amount'] += (int)$first['amount_cents'];
            }
            $second = $cohort['second'];
            if ($second !== null) {
                $secondMonth = substr($second['date'], 0, 7);
                if (isset($metrics[$secondMonth])) {
                    $metrics[$secondMonth]['transition_people']++;
                    $metrics[$secondMonth]['transition_amount'] += (int)$second['amount_cents'];
                }
            }
            $firstYear = substr($first['date'], 0, 4);
            foreach ($cohort['events'] as $event) {
                if (substr($event['date'], 0, 4) !== $firstYear) continue;
                $eventMonth = substr($event['date'], 0, 7);
                $yearDeltas[$firstYear][$eventMonth] = (int)($yearDeltas[$firstYear][$eventMonth] ?? 0)
                    + (int)$event['amount_cents'];
            }
        }
        foreach ($metrics as $month => &$values) {
            foreach ((array)($yearDeltas[substr($month, 0, 4)] ?? []) as $eventMonth => $amount) {
                if ($eventMonth <= $month) $values['first_year_amount'] += (int)$amount;
            }
        }
        unset($values);
        return $metrics;
    }

    /** Select exactly the purchase events used by the referral report's cells. */
    private function marketingReferralDetailFacts(array $facts, array $monthRange, string $metric): array
    {
        $selected = [];
        $year = substr((string)$monthRange['start'], 0, 4);
        foreach ($this->marketingReferralCohorts($facts, (string)$monthRange['end'], true) as $cohort) {
            $event = in_array($metric, ['new_customer_actual', 'new_customer_amount_actual'], true)
                ? $cohort['first'] : $cohort['second'];
            if (in_array($metric, ['new_customer_actual', 'new_customer_amount_actual',
                'transition_people', 'transition_amount'], true)) {
                if ($event !== null && $event['date'] >= $monthRange['start'] && $event['date'] <= $monthRange['end']) {
                    array_push($selected, ...$event['facts']);
                }
                continue;
            }
            if ($metric !== 'first_year_amount' || substr($cohort['first']['date'], 0, 4) !== $year) continue;
            foreach ($cohort['events'] as $event) {
                if ($event['date'] <= $monthRange['end']) array_push($selected, ...$event['facts']);
            }
        }
        return $selected;
    }

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

    /** Index source cash events once for every displayed month of a report. */
    private function preSaleMetricsByMonth(array $facts, array $months, bool $minimumFirstAmount): array
    {
        $metrics = [];
        foreach ($months as $month) $metrics[$month] = [
            'first_people' => 0, 'first_amount' => 0, 'transition_people' => 0,
            'transition_amount' => 0, 'first_year_amount' => 0,
        ];
        if ($metrics === []) return [];
        $lastDate = date('Y-m-t', strtotime((string)end($months) . '-01'));
        $events = [];
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            $date = (string)($fact['business_date'] ?? '');
            if ($memberId <= 0 || $date === '' || $date > $lastDate) continue;
            $line = trim((string)($fact['source_line_id'] ?? ''));
            $key = $memberId . '|' . ($line !== '' ? $line : (string)($fact['id'] ?? '')) . '|' . $date;
            if (!isset($events[$memberId][$key])) $events[$memberId][$key] = ['date' => $date, 'amount_cents' => 0];
            $events[$memberId][$key]['amount_cents'] += (int)($fact['amount_cents'] ?? 0);
        }
        $yearDeltas = [];
        foreach ($events as $memberEvents) {
            $positive = array_values(array_filter($memberEvents, static fn(array $event): bool => (int)$event['amount_cents'] > 0));
            usort($positive, static fn(array $left, array $right): int => strcmp((string)$left['date'], (string)$right['date']));
            if ($positive === []) continue;
            $first = $positive[0];
            $firstMonth = substr((string)$first['date'], 0, 7);
            if (isset($metrics[$firstMonth]) && (!$minimumFirstAmount || (int)$first['amount_cents'] >= 50000)) {
                $metrics[$firstMonth]['first_people']++;
                $metrics[$firstMonth]['first_amount'] += (int)$first['amount_cents'];
            }
            if (isset($positive[1])) {
                $second = $positive[1];
                $secondMonth = substr((string)$second['date'], 0, 7);
                if (isset($metrics[$secondMonth])) {
                    $metrics[$secondMonth]['transition_people']++;
                    $metrics[$secondMonth]['transition_amount'] += (int)$second['amount_cents'];
                }
            }
            $firstYear = substr((string)$first['date'], 0, 4);
            foreach ($memberEvents as $event) {
                $eventDate = (string)$event['date'];
                if ($eventDate < (string)$first['date'] || substr($eventDate, 0, 4) !== $firstYear) continue;
                $eventMonth = substr($eventDate, 0, 7);
                $yearDeltas[$firstYear][$eventMonth] = (int)($yearDeltas[$firstYear][$eventMonth] ?? 0)
                    + (int)$event['amount_cents'];
            }
        }
        foreach ($metrics as $month => &$values) {
            $year = substr($month, 0, 4);
            foreach ((array)($yearDeltas[$year] ?? []) as $eventMonth => $amount) {
                if ($eventMonth <= $month) $values['first_year_amount'] += (int)$amount;
            }
        }
        unset($values);
        return $metrics;
    }

    /** Select the same member/line/day events as preSaleMetrics for receipt evidence. */
    private function preSaleDetailFacts(array $facts, array $monthRange, string $metric, bool $minimumFirstAmount): array
    {
        $events = [];
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            $date = (string)($fact['business_date'] ?? '');
            if ($memberId <= 0 || $date === '' || $date > (string)$monthRange['end']) continue;
            $line = trim((string)($fact['source_line_id'] ?? ''));
            $key = $memberId . '|' . ($line !== '' ? $line : (string)($fact['id'] ?? '')) . '|' . $date;
            if (!isset($events[$memberId][$key])) $events[$memberId][$key] = ['date' => $date, 'amount_cents' => 0, 'facts' => []];
            $events[$memberId][$key]['amount_cents'] += (int)($fact['amount_cents'] ?? 0);
            $events[$memberId][$key]['facts'][] = $fact;
        }
        $selected = [];
        $year = substr((string)$monthRange['start'], 0, 4);
        foreach ($events as $memberEvents) {
            $positive = array_values(array_filter($memberEvents, static fn(array $event): bool => (int)$event['amount_cents'] > 0));
            usort($positive, static fn(array $left, array $right): int => strcmp((string)$left['date'], (string)$right['date']));
            if ($positive === []) continue;
            $first = $positive[0];
            if (in_array($metric, ['deal_people', 'deal_amount', 'new_customer_actual', 'new_customer_amount_actual'], true)) {
                if ((string)$first['date'] < (string)$monthRange['start'] || (string)$first['date'] > (string)$monthRange['end']
                    || ($minimumFirstAmount && (int)$first['amount_cents'] < 50000)) continue;
                array_push($selected, ...$first['facts']);
                continue;
            }
            if (in_array($metric, ['transition_people', 'transition_amount'], true)) {
                if (!isset($positive[1]) || (string)$positive[1]['date'] < (string)$monthRange['start']
                    || (string)$positive[1]['date'] > (string)$monthRange['end']) continue;
                array_push($selected, ...$positive[1]['facts']);
                continue;
            }
            if ($metric !== 'first_year_amount' || substr((string)$first['date'], 0, 4) !== $year) continue;
            foreach ($memberEvents as $event) {
                if ((string)$event['date'] >= (string)$first['date'] && (string)$event['date'] <= (string)$monthRange['end']) {
                    array_push($selected, ...$event['facts']);
                }
            }
        }
        return $selected;
    }

    /** BDEGH and C/F share one authorized receipt scan and the same day-level cohort rules. */
    private function bdeghMonthlyMetrics(array $stores, array $range, array $rule, bool $guestNonA = true): array
    {
        return $this->aggregateBdeghMonthlyMetrics($this->sourceCashRows($stores, ['end' => $range['end']]), $range, $rule, $guestNonA);
    }

    /**
     * Reuse the summary's first/second positive business-day cohorts for the
     * dated receipt detail. A member can have multiple orders that day;
     * the detail keeps each document separate without counting them twice.
     */
    private function bdeghDetailReport(array $stores, array $range, array $input, string $detailReport = 'operations_pre_sale_bdegh_detail'): array
    {
        // Hidden detail routes are fixed to their own source rule; a caller
        // cannot pass another parent's rule to broaden the receipt scope.
        $cf = $detailReport === 'operations_referral_beautician_pre_sale_detail';
        $parentReport = $cf ? 'operations_referral_beautician_pre_sale' : 'operations_pre_sale_bdegh';
        $metric = trim((string)($input['metric_code'] ?? ''));
        if (!in_array($metric, ['deal_people', 'deal_amount', 'transition_people', 'transition_amount', 'first_year_amount'], true)) {
            throw new \InvalidArgumentException('不支持的售前明细指标');
        }
        $month = trim((string)($input['drill_month'] ?? ''));
        if ($month !== '' && (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)
            || $month < substr($range['start'], 0, 7) || $month > substr($range['end'], 0, 7))) {
            throw new \InvalidArgumentException('下钻月份不在当前统计范围内');
        }
        $facts = $this->sourceCashRows($stores, ['end' => $range['end']]);
        $records = $this->bdeghDetailRows($facts, $range, $this->preSaleRule($parentReport), $metric, $month, !$cf);
        $records = StoreUnifiedReportDetailFilter::apply($records, $input);
        $subjects = array_unique(array_column($records, 'subject_key'));
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $visibleRecords = !empty($input['_internal_all']) ? $records : array_slice($records, ($page - 1) * $limit, $limit);
        $visibleIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)$row['member_id'], $visibleRecords), static fn(int $id): bool => $id > 0)));
        // Phone is live member data, not an aggregation input. Ordinary queries
        // look up only the current page; full export batches its authorized IDs.
        $phones = [];
        foreach (array_chunk($visibleIds, 500) as $ids) {
            $phones += Db::name('user')->whereIn('uid', $ids)->column('phone', 'uid');
        }
        foreach ($records as &$row) {
            $row['member_phone'] = (string)($phones[(int)$row['member_id']] ?? '');
            unset($row['member_id'], $row['subject_key']);
        }
        unset($row);
        $result = $this->result($cf ? 'C/F收款明细' : 'BDEGH收款明细', [
            $this->column('member_name', '会员', '取该笔成功收款的会员姓名快照；未建档的收款显示“游客”。', false, true, 130),
            $this->column('member_phone', '手机号', '只对当前有权限查看的收款会员读取其当前登记手机号。', false, false, 145),
            $this->column('order_no', '单据号', '取该笔收款事实的业务单号快照；会员同日多单及不同游客单据逐单列示。', false, false, 180),
            $this->column('business_date', '业务日期', '取成功收款或退款调整事实的业务归属日期；同一单据跨日发生时按日期分别列示。', false, false, 125),
            $this->column('receipt_amount', '收款金额', '按被点击指标的首次、第二次收款或首年业绩范围，汇总同一单据同一业务日的成功收款净额；首年业绩含入选会员全年所有来源及游客当月' . ($cf ? ' C/F 来源' : '非 A 来源') . '收款。', true, false, 120),
        ], $records, $range, $input);
        $result['member_count'] = count($subjects);
        $result['metric_version'] = $cf ? 'operations-pre-sale-cf-imported-and-old-card-repayment-people-r83' : 'operations-pre-sale-bdegh-imported-and-old-card-repayment-people-r83';
        return $result;
    }

    /** Build document rows from the same event choice as the monthly summary; no name-based matching. */
    private function bdeghDetailRows(array $facts, array $range, array $rule, string $metric, string $month, bool $guestNonA = true): array
    {
        $peopleMetric = in_array($metric, ['deal_people', 'transition_people'], true);
        $cohortFacts = $peopleMetric
            ? array_values(array_filter($facts, static fn(array $fact): bool => empty($fact['is_excluded_person_repayment'])))
            : $facts;
        $eventsByMember = $this->bdeghPositiveEvents($cohortFacts, $rule, true);
        $yearFacts = [];
        if ($metric === 'first_year_amount') {
            foreach ($facts as $fact) {
                $memberId = (int)($fact['member_id'] ?? 0);
                if ($memberId > 0) $yearFacts[substr((string)($fact['business_date'] ?? ''), 0, 4)][$memberId][] = $fact;
            }
        }
        $documents = [];
        foreach ($eventsByMember as $subjectKey => $positive) {
            $event = in_array($metric, ['transition_people', 'transition_amount'], true) ? ($positive[1] ?? null) : ($positive[0] ?? null);
            if ($event === null) continue;
            $memberId = (int)$event['member_id'];
            $date = (string)$event['date'];
            if ($date < $range['start'] || $date > $range['end'] || ($month !== '' && substr($date, 0, 7) !== $month)) continue;
            if ($metric === 'deal_people' && (int)$event['amount_cents'] < 50000) continue;
            // Registered members enter the annual cohort through deal_amount;
            // guest cash follows its own non-A monthly rule below.
            if ($metric === 'first_year_amount' && $memberId <= 0) continue;
            $selected = $metric === 'first_year_amount'
                ? (array)($yearFacts[substr($date, 0, 4)][$memberId] ?? []) : (array)$event['facts'];
            foreach ($selected as $fact) {
                $this->appendBdeghDocument($documents, (string)$subjectKey, $memberId, $fact);
            }
        }
        if ($metric === 'first_year_amount') {
            foreach ($facts as $fact) {
                $date = (string)($fact['business_date'] ?? '');
                if ($date < $range['start'] || $date > $range['end'] || ($month !== '' && substr($date, 0, 7) !== $month)) continue;
                if (!$this->eligiblePreSaleGuestCash($fact, $rule, $guestNonA)) continue;
                $orderId = trim((string)($fact['order_id'] ?? ''));
                if ($orderId === '') continue;
                $this->appendBdeghDocument($documents, 'guest-order:' . $orderId, 0, $fact);
            }
        }
        $rows = array_values($documents);
        usort($rows, static fn(array $a, array $b): int => strcmp($a['business_date'] . '|' . $a['order_no'], $b['business_date'] . '|' . $b['order_no']));
        foreach ($rows as &$row) {
            $row['receipt_amount'] = $this->money((int)$row['amount_cents']);
            unset($row['amount_cents']);
        }
        unset($row);
        return $rows;
    }

    /** Keep signed receipt facts on their own business dates, even when one document spans days. */
    private function appendBdeghDocument(array &$documents, string $subjectKey, int $memberId, array $fact): void
    {
        $orderId = trim((string)($fact['order_id'] ?? ''));
        $businessDate = (string)($fact['business_date'] ?? '');
        $key = $subjectKey . '|' . $orderId . '|' . $businessDate;
        if (!isset($documents[$key])) $documents[$key] = [
            'member_id' => $memberId,
            'subject_key' => $subjectKey,
            'member_name' => $memberId > 0 ? (string)($fact['member_name_snapshot'] ?? '') : '游客',
            'order_no' => trim((string)($fact['order_no_snapshot'] ?? '')) ?: (trim((string)($fact['sale_order_no_snapshot'] ?? '')) ?: $orderId),
            'business_date' => $businessDate,
            'amount_cents' => 0,
        ];
        $documents[$key]['amount_cents'] += (int)($fact['amount_cents'] ?? 0);
    }

    /** A is excluded by the recorded primary source code, never by guest name or product text. */
    private function isNonAGuestCash(array $fact): bool
    {
        if ((int)($fact['member_id'] ?? 0) > 0) return false;
        $source = trim((string)($fact['business_source_label'] ?? ''));
        return strtoupper(substr($source, 0, 1)) !== 'A';
    }

    /** Guests have no durable member cohort; C/F reports keep their monthly cash within C/F only. */
    private function eligiblePreSaleGuestCash(array $fact, array $rule, bool $guestNonA): bool
    {
        if ((int)($fact['member_id'] ?? 0) > 0) return false;
        return $guestNonA ? $this->isNonAGuestCash($fact) : $this->filterSourceFacts([$fact], $rule) !== [];
    }

    /** Members share a business-day event; guests have no durable identity and use order-day events. */
    private function bdeghPositiveEvents(array $facts, array $rule, bool $includeFacts = false): array
    {
        $eventsByMember = [];
        foreach ($this->filterSourceFacts($facts, $rule) as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            $date = (string)($fact['business_date'] ?? '');
            $orderId = trim((string)($fact['order_id'] ?? ''));
            if ($date === '' || ($memberId <= 0 && $orderId === '')) continue;
            $subjectKey = $memberId > 0 ? 'member:' . $memberId : 'guest-order:' . $orderId;
            $eventsByMember[$subjectKey][$date]['date'] = $date;
            $eventsByMember[$subjectKey][$date]['member_id'] = $memberId;
            $eventsByMember[$subjectKey][$date]['amount_cents'] = (int)($eventsByMember[$subjectKey][$date]['amount_cents'] ?? 0) + (int)($fact['amount_cents'] ?? 0);
            if ($includeFacts) $eventsByMember[$subjectKey][$date]['facts'][] = $fact;
        }
        $positiveByMember = [];
        foreach ($eventsByMember as $subjectKey => $events) {
            $positive = array_values(array_filter($events, static fn(array $event): bool => (int)$event['amount_cents'] > 0));
            usort($positive, static fn(array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
            if ($positive !== []) $positiveByMember[$subjectKey] = $positive;
        }
        return $positiveByMember;
    }

    /**
     * Group a member's effective receipts by business day, including distinct
     * orders on that day, before choosing the first and second positive BDEGH days.
     * A member in deal_amount enters the annual cohort in their first BDEGH
     * month; all annual sources and refunds count once. Guests have no durable
     * identity, so their non-A net cash stays in its receipt month.
     */
    private function aggregateBdeghMonthlyMetrics(array $facts, array $range, array $rule, bool $guestNonA = true): array
    {
        $months = [];
        foreach ($this->months($range) as $month) {
            $months[$month] = ['deal_people' => 0, 'deal_amount' => 0, 'transition_people' => 0, 'transition_amount' => 0, 'first_year_amount' => 0];
        }
        $annualCash = [];
        foreach ($facts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            $year = substr((string)($fact['business_date'] ?? ''), 0, 4);
            if ($this->eligiblePreSaleGuestCash($fact, $rule, $guestNonA)) {
                $guestMonth = substr((string)($fact['business_date'] ?? ''), 0, 7);
                if (isset($months[$guestMonth])) $months[$guestMonth]['first_year_amount'] += (int)($fact['amount_cents'] ?? 0);
            }
            if ($memberId <= 0 || strlen($year) !== 4) continue;
            $annualCash[$year][$memberId] = (int)($annualCash[$year][$memberId] ?? 0) + (int)($fact['amount_cents'] ?? 0);
        }
        foreach ($this->bdeghPositiveEvents($facts, $rule) as $positive) {
            $first = $positive[0];
            $memberId = (int)$first['member_id'];
            $firstMonth = substr((string)$first['date'], 0, 7);
            if (isset($months[$firstMonth])) {
                $months[$firstMonth]['deal_amount'] += (int)$first['amount_cents'];
                if ($memberId > 0) $months[$firstMonth]['first_year_amount'] += (int)($annualCash[substr((string)$first['date'], 0, 4)][$memberId] ?? 0);
            }
            if (isset($positive[1])) {
                $second = $positive[1];
                $secondMonth = substr((string)$second['date'], 0, 7);
                if (isset($months[$secondMonth])) {
                    $months[$secondMonth]['transition_amount'] += (int)$second['amount_cents'];
                }
            }
        }
        // Legacy debt settlements remain in money but cannot create a first/second person event or meet the threshold.
        $peopleFacts = array_values(array_filter($facts, static fn(array $fact): bool => empty($fact['is_excluded_person_repayment'])));
        foreach ($this->bdeghPositiveEvents($peopleFacts, $rule) as $positive) {
            $firstMonth = substr((string)$positive[0]['date'], 0, 7);
            if (isset($months[$firstMonth]) && (int)$positive[0]['amount_cents'] >= 50000) {
                $months[$firstMonth]['deal_people']++;
            }
            if (isset($positive[1])) {
                $secondMonth = substr((string)$positive[1]['date'], 0, 7);
                if (isset($months[$secondMonth])) $months[$secondMonth]['transition_people']++;
            }
        }
        return $months;
    }
    /** One authorized scan feeds month bands and rolling status without a second fact query. */
    private function customerVisitWindowCounts(array $stores, array $months): array
    {
        $lastMonth = end($months);
        $end = date('Y-m-t', strtotime($lastMonth . '-01'));
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)->where('sv.service_status', 'completed')
            ->where('sv.member_id', '>', 0)->where('sv.business_date', '<=', $end);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        \app\services\query\metric\StoreCustomerVisitEligibility::apply($query, 'sv');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        }
        return $this->visitWindowCountsFromRows($query->field('sv.member_id,sv.business_date')
            ->order('sv.business_date asc,sv.member_id asc')->cursor(), $months);
    }

    /** Customer-status columns need only member-month visit totals, not every service payload. */
    private function customerStatusMonthlyServices(array $stores, array $range): array
    {
        return $this->customerStatusMonthlyServiceQuery($stores, $range)
            ->fieldRaw('sv.member_id,MIN(sv.business_date) business_date,SUM(sv.quantity) quantity,0 is_experience')
            ->group("DATE_FORMAT(sv.business_date,'%Y-%m'),sv.member_id")->select()->toArray();
    }

    /** This single-month item report needs only the eligible distinct-member denominator. */
    private function customerStatusMonthlyServiceMemberCount(array $stores, array $range): int
    {
        $result = $this->customerStatusMonthlyServiceQuery($stores, $range)
            ->fieldRaw('COUNT(DISTINCT sv.member_id) member_count')->select()->toArray();
        return (int)($result[0]['member_count'] ?? 0);
    }

    /** Keep member eligibility and personnel scope identical for row and count projections. */
    private function customerStatusMonthlyServiceQuery(array $stores, array $range)
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)->where('sv.service_status', 'completed')
            ->where('sv.member_id', '>', 0)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']]);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        \app\services\query\metric\StoreCustomerVisitEligibility::apply($query, 'sv');
        if ($this->participantEmployeeId > 0) {
            (new StoreReportParticipantScopeServices())->applyCheckout($query, 'sv.checkout_request_id', $this->participantEmployeeId);
        }
        return $query;
    }

    /** Compatibility wrapper for the last-visit contract; the production scan also returns regular members. */
    private function inactiveCountsFromRows(iterable $facts, array $months): array
    {
        return $this->visitWindowCountsFromRows($facts, $months)['inactive'];
    }

    /** A member has at most one visit per eligible business date, even across stores or projects. */
    private function visitWindowCountsFromRows(iterable $facts, array $months): array
    {
        $inactive = []; $regular = []; $latest = []; $recent = []; $monthly = [];
        $monthSet = array_fill_keys($months, true);
        $index = 0; $monthCount = count($months);
        $monthEnds = array_map(static fn(string $month): string => date('Y-m-t', strtotime($month . '-01')), $months);
        $closeMonth = static function (string $month) use (&$inactive, &$regular, &$latest, &$recent): void {
            $monthEnd = date('Y-m-t', strtotime($month . '-01'));
            $cutoff = date('Y-m-d', strtotime($monthEnd . ' -90 days'));
            $inactive[$month] = 0;
            foreach ($latest as $last) if ($last < $cutoff) $inactive[$month]++;
            $regular[$month] = 0;
            foreach ($recent as $memberId => $visits) {
                $within = [];
                foreach ($visits as $visit) {
                    if ($visit < $cutoff) continue;
                    $within[] = $visit;
                }
                if ($within === []) unset($recent[$memberId]);
                else $recent[$memberId] = $within;
                if (count($within) === 1) $regular[$month]++;
            }
        };
        $currentDate = ''; $rollingCutoff = '';
        foreach ($facts as $fact) {
            $date = (string)$fact['business_date'];
            while ($index < $monthCount && $date > $monthEnds[$index]) {
                $closeMonth($months[$index]);
                $index++;
            }
            if ($index >= $monthCount) break;
            $memberId = (int)$fact['member_id'];
            if ($memberId <= 0) continue;
            if (($latest[$memberId] ?? '') === $date) continue;
            $latest[$memberId] = $date;
            $recent[$memberId][] = $date;
            if ($date !== $currentDate) {
                $currentDate = $date;
                $rollingCutoff = date('Y-m-d', strtotime($date . ' -90 days'));
            }
            while ($recent[$memberId] !== [] && $recent[$memberId][0] < $rollingCutoff) array_shift($recent[$memberId]);
            $month = substr($date, 0, 7);
            if (isset($monthSet[$month])) {
                if (!isset($monthly[$month][$memberId])) {
                    $monthly[$month][$memberId] = [
                        'member_id' => $memberId, 'business_date' => $date,
                        'visit_days' => 0, 'is_experience' => 0,
                    ];
                }
                $monthly[$month][$memberId]['visit_days']++;
            }
        }
        while ($index < $monthCount) {
            $closeMonth($months[$index]);
            $index++;
        }
        $monthlyServices = [];
        foreach ($monthly as $memberRows) foreach ($memberRows as $memberRow) $monthlyServices[] = $memberRow;
        return ['inactive' => $inactive, 'regular' => $regular, 'monthly_services' => $monthlyServices];
    }
    private function categoryFacts(array $facts, string $category): array { return array_values(array_filter($facts, static function (array $fact) use ($category): bool { return preg_match('/^' . preg_quote($category, '/') . '(?:\\s*\\/|$)/u', trim((string)($fact['category_path'] ?? ''))) === 1; })); }
    /** 自营/合作随关联平台商品当前分类变化，不由商品名称或人员档案反推。 */
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

    /** Sum already-authorized, current-category facts without re-reading payment tables. */
    private function cashTotalsFromRows(array $rows, array $categoryIds, bool $emptyCategoryMeansNone = false): array
    {
        if ($emptyCategoryMeansNone && $categoryIds === []) return [];
        $allowed = array_fill_keys(array_map('intval', $categoryIds), true);
        $totals = [];
        foreach ($rows as $row) {
            if ($allowed !== [] && !isset($allowed[(int)($row['category_id'] ?? 0)])) continue;
            $month = substr((string)$row['business_date'], 0, 7);
            $totals[$month] = (int)($totals[$month] ?? 0) + (int)$row['amount_cents'];
        }
        return $totals;
    }
    private function allocateSigned(int $amountCents, array $items): array { $sign=$amountCents<0?-1:1; $sales=[]; foreach($items as $item){$weight=abs((int)($item['sale_amount_cents']??0));if($weight===0)$weight=abs((int)($item['configured_amount_cents']??0));if($weight===0)$weight=max(1,(int)($item['component_count']??1));$sales[]=['fact_id'=>(string)$item['allocation_fact_id'],'sale_amount_cents'=>$sign*$weight,'debt_amount_cents'=>0];} return StoreReportPartnerCategorySnapshotServices::allocateAmountBySaleFact($amountCents,$sales); }
    /** A caller's category path can narrow, never replace, the authorized report root. */
    private function categoryIds(string $rootName, array $input): array
    {
        $requested = trim((string)($input['category_path'] ?? ''));
        if ($rootName === '') return $requested === '' ? [] : $this->descendantsByPath($requested);
        $allowed = $this->descendantsByName($rootName);
        if ($requested === '') return $allowed;
        return array_values(array_intersect($allowed, $this->descendantsByPath($requested)));
    }

    /** Read the platform category tree once; visibility only controls the catalog, not fact membership. */
    private function categoryTreeRows(): array
    {
        if ($this->categoryTreeRows === null) {
            $this->categoryTreeRows = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
                ->field('id,pid,cate_name,sort')->select()->toArray();
        }
        return $this->categoryTreeRows;
    }

    /**
     * Current cooperation switches define 花园 report columns, even when a
     * category has no sales in the selected year. Only direct children of a
     * first-level root become columns; deeper items roll up to that child.
     * The root's own switch never creates a second-level column.
     *
     * @return array<int,array{id:int,key:string,label:string,path:string}>
     */
    private function enabledSecondLevelPartnerCategories(string $rootName): array
    {
        $tree = $this->categoryTreeRows();
        $rootIds = [];
        foreach ($tree as $category) {
            if ((int)$category['pid'] === 0 && (string)$category['cate_name'] === $rootName) {
                $rootIds[(int)$category['id']] = true;
            }
        }
        if ($rootIds === []) return [];

        $children = [];
        foreach ($tree as $category) {
            if (isset($rootIds[(int)$category['pid']])) {
                $children[(int)$category['id']] = $category;
            }
        }
        if ($children === []) return [];

        $enabledIds = Db::name('cashier_v3_report_category_config')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('enabled', 1)->whereIn('category_id', array_keys($children))
            ->column('category_id');
        $enabled = array_fill_keys(array_map('intval', $enabledIds), true);
        $selected = array_values(array_filter($children,
            static fn(array $category): bool => isset($enabled[(int)$category['id']])));
        // Match the product-category settings order so a switch change does
        // not cause unrelated columns to jump between queries or exports.
        usort($selected, static fn(array $left, array $right): int =>
            ((int)$right['sort'] <=> (int)$left['sort']) ?: ((int)$right['id'] <=> (int)$left['id']));
        $definitions = [];
        foreach ($selected as $category) {
            $id = (int)$category['id'];
            $definitions[] = ['id' => $id, 'key' => 'partner_category_' . $id,
                'label' => (string)$category['cate_name'],
                'path' => $rootName . ' / ' . (string)$category['cate_name']];
        }
        return $definitions;
    }

    private function descendantsByName(string $rootName): array
    {
        $all = $this->categoryTreeRows();
        $roots = [];
        foreach ($all as $row) if ((string)$row['cate_name'] === $rootName) $roots[] = (int)$row['id'];
        return $this->descendants($roots, $all);
    }

    /** Category filters accept either slash spacing, but only platform-category IDs select facts. */
    private function descendantsByPath(string $path): array
    {
        $path = preg_replace('/\s*\/\s*/u', '/', $path);
        $all = $this->categoryTreeRows();
        $byId = [];
        foreach ($all as $row) $byId[(int)$row['id']] = $row;
        $roots = [];
        foreach ($byId as $id => $row) {
            $parts = [];
            for ($cursor = $id, $guard = 0; $cursor > 0 && isset($byId[$cursor]) && $guard++ < 32; $cursor = (int)$byId[$cursor]['pid']) {
                array_unshift($parts, (string)$byId[$cursor]['cate_name']);
            }
            if (implode('/', $parts) === $path) $roots[] = $id;
        }
        return $this->descendants($roots, $all);
    }

    private function descendants(array $roots, ?array $all = null): array
    {
        $all = $all ?? $this->categoryTreeRows();
        $children = [];
        foreach ($all as $row) $children[(int)$row['pid']][] = (int)$row['id'];
        $out = [];
        $queue = array_values(array_unique(array_map('intval', $roots)));
        for ($index = 0; $index < count($queue); $index++) {
            $id = $queue[$index];
            if ($id <= 0 || isset($out[$id])) continue;
            $out[$id] = true;
            foreach ((array)($children[$id] ?? []) as $child) $queue[] = $child;
        }
        return array_keys($out);
    }
    private function months(array $range): array { $out=[]; for($cursor=date('Y-m-01',strtotime($range['start']));$cursor<=date('Y-m-01',strtotime($range['end']));$cursor=date('Y-m-01',strtotime($cursor.' +1 month')))$out[]=substr($cursor,0,7);return $out; }
    private function range(array $range,array $input): array { $start=trim((string)($range['start']??$input['start_date']??''));$end=trim((string)($range['end']??$input['end_date']??'')); if($start===''||$end===''){ $year=max(2000,(int)($input['year']??date('Y')));$start=$year.'-01-01';$end=$year.'-12-31'; } if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end)||$start>$end)throw new \InvalidArgumentException('日期范围不正确');return ['start'=>$start,'end'=>$end]; }
    private function storeIds($ids): array { $ids=array_values(array_unique(array_filter(array_map('intval',(array)$ids))));sort($ids);return $ids; }
    private function organization(): StoreUnifiedReportOrganizationDimensionServices { static $service;return $service?:$service=new StoreUnifiedReportOrganizationDimensionServices(); }
    private function manualSubject(string $report,string $month,int $storeId,string $dimension = ''): string{return 'phase4:'.$report.':'.$month.':'.($dimension === '' ? $storeId : $dimension);}
    private function attachManualSubjects(array &$rows, string $report): void { foreach ($rows as &$row) { $month = trim((string)($row['month'] ?? '')); if ($month === '') continue; $row['annotation_subject_type'] = 'phase_four_month'; $row['annotation_subject_key'] = $this->manualSubject($report, $month, 0, trim((string)($row['annotation_dimension_key'] ?? ''))); $row['annotation_store_id'] = 0; } unset($row); }
    /** Fetch current-month manual cells once; missing cells stay missing rather than becoming zero. */
    private function manualValue(string $report, string $subject, string $field): ?array
    {
        if ($this->manualLookup === null) {
            $this->manualLookup = [];
            $subjects = [];
            foreach ($this->months($this->manualLookupRange) as $month) {
                $subjects[] = $this->manualSubject($report, $month, 0);
                $subjects[] = $this->manualSubject($report, $month, 0, 'team:self');
                $subjects[] = $this->manualSubject($report, $month, 0, 'team:partner');
            }
            foreach (array_chunk($subjects, 100) as $chunk) {
                $rows = Db::name(StoreOperationsReportAnnotationServices::ANNOTATION_TABLE)
                    ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                    ->where('report_code', $report)->where('subject_type', 'phase_four_month')
                    ->whereIn('subject_key', $chunk)->field('subject_key,field_key,field_value,version')
                    ->select()->toArray();
                foreach ($rows as $row) {
                    $this->manualLookup[(string)$row['subject_key']][(string)$row['field_key']] = [
                        'value' => (string)$row['field_value'], 'version' => (int)$row['version']];
                }
            }
        }
        return $this->manualLookup[$subject][$field] ?? null;
    }
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
            'filter_schema' => array_merge([['key' => 'organization_store_scope', 'label' => '组织 / 门店', 'type' => 'organization_store_scope'], ['key' => 'date_range', 'label' => '日期', 'type' => !empty($filters['year']) ? 'year' : 'date_range', 'inclusive' => true, 'single_natural_month' => !empty($filters['single_month'])]], !empty($filters['category']) ? [['key' => 'category_path', 'label' => '商品分类', 'type' => 'category_tree', 'root_path' => (string)($filters['category_root'] ?? ''), 'include_descendants' => true]] : []),
            'source_explanations' => array_values(array_map(static fn(array $column): array => ['key' => (string)$column['key'], 'label' => (string)$column['label'], 'source_explanation' => (string)$column['source_explanation']], $columns)),
            'drilldown_keys' => ['start_date', 'end_date', 'store_id', 'category_path', 'company_dimension_id', 'metric_code'],
            'table_layout' => ['fixed' => true, 'sticky_query' => true, 'sticky_header' => true, 'sticky_summary' => true, 'result_scroll' => true], 'metric_version' => $title === '六维数据分析表' ? 'six-dimension-analysis-current-category-v3' : self::METRIC_VERSION,
            'service_customer_rule_version' => null,
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
    /** Only explicitly supported cash metrics expose the scoped receipt popup. */
    private function cashDetailDrill(string $parent, string $metric): array
    {
        $drill = ['report' => 'operations_cash_fact_detail', 'params' => ['parent_report' => $parent, 'metric_code' => $metric]];
        // Declare the loading layout too, so the popup does not grow from four
        // columns to six after the first response arrives.
        if ($parent === 'product_monetization_six_dimension_item_performance') $drill['display_columns'] = ['business_date', 'store_name'];
        if ($parent === 'product_monetization_beauty_performance_total') $drill['display_columns'] = ['business_date'];
        if ((self::REPORTS[$parent]['kind'] ?? '') === 'item' && $metric !== 'month_total_performance') {
            $drill['required_row_keys'] = in_array($parent,
                ['operations_beauty_item', 'product_monetization_private_item_performance', 'marketing_beauty_new_item'], true)
                ? ['item_group_key'] : ['item_id', 'detail_category_path'];
        } elseif ((self::REPORTS[$parent]['kind'] ?? '') === 'member_consumption') {
            $drill['required_row_keys'] = ['member_id', 'store_id'];
        }
        return $drill;
    }
    /** Service dimensions are derived server-side from the parent report, not client filters. */
    private function serviceDetailDrill(string $parent, string $metric): array
    {
        $drill = ['report' => 'operations_service_fact_detail', 'params' => [
            'parent_report' => $parent, 'metric_code' => $metric]];
        if ((self::REPORTS[$parent]['kind'] ?? '') === 'efficiency') {
            $drill['required_row_keys'] = ['annotation_dimension_key'];
        } elseif ($parent === 'marketing_beauty_new_item') {
            $drill['required_row_keys'] = ['item_group_key'];
        }
        return $drill;
    }
    private function drill(string $category,array $params=[]):array{return ['report'=>'six_dimension_consumption_refund_detail','params'=>array_merge(['metric_code'=>'phase_four_amount','category_path'=>$category],$params)];}
    private function money(int $cents):string{$negative=$cents<0;$cents=abs($cents);$value=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);return ($negative?'-':'').rtrim(rtrim($value,'0'),'.');}
    /** Opaque identity for one exact historical product name in this report. */
    private function beautyItemGroupKey(string $name): string { return hash('sha256', 'operations_beauty_item|' . $name); }
    /** Keep the private item popup tied to the exact current category and sold-name group. */
    private function privateItemGroupKey(string $path, string $name): string
    {
        return hash('sha256', json_encode(['private_item', $path, $name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    /** The visible category is capped at two levels; deeper products roll into that parent. */
    private function marketingCategoryPath(string $path): string
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*\/\s*/u', $path) ?: []),
            static fn(string $part): bool => $part !== ''));
        return implode('/', array_slice($parts, 0, 2));
    }
    /** Bind summary and drilldown to the same category/name group without trusting display text. */
    private function marketingItemGroupKey(string $categoryPath, string $name): string
    {
        return hash('sha256', json_encode(['marketing_beauty_new_item', $categoryPath, $name],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
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
            '昊美m' => 'haomei_m', '昊美n' => 'haomei_n',
            '花园口服' => 'private_oral', '花园卡项' => 'private_card', '花园家居' => 'private_home', '花园私美' => 'private_intimate',
        ];
        if (isset($keys[$name])) return $keys[$name];
        return 'category_' . substr(hash('sha256', $name), 0, 16);
    }
}
