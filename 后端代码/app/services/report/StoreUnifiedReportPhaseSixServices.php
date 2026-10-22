<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use app\services\query\metric\MetricDefinitionRegistry;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

/**
 * 第六阶段九张报表。
 *
 * 本服务只接收控制器已经裁剪后的门店范围，并从 V3 事实层读取数据。
 * 所有列都带业务化来源说明，前端只按返回的列契约展示。
 */
final class StoreUnifiedReportPhaseSixServices
{
    public const METRIC_VERSION = 'report-phase-six-v2';
    private const SALARY_PRESALE_KEY = 'salary_presale_cash';

    public const REPORTS = [
        'phase_six_garden_item_analysis' => ['name' => '花园品项分析表', 'both_ends' => false],
        'phase_six_monthly_featured_item' => ['name' => '月主推数据统计表', 'both_ends' => false],
        'phase_six_headquarters_acquisition' => ['name' => '总部拓客数据统计表', 'both_ends' => false],
        'phase_six_other_multi_payment' => ['name' => '其他多收款业绩表', 'both_ends' => true],
        'phase_six_salary_summary' => ['name' => '员工薪资汇总月报表', 'both_ends' => true],
        'phase_six_salary_detail' => ['name' => '员工薪资明细月报表', 'both_ends' => true],
        'phase_six_training_employee' => ['name' => '教培员工需求统计表', 'both_ends' => false],
        'phase_six_acquisition_source' => ['name' => '拓客部门客户来源数据分析表', 'both_ends' => false],
        'phase_six_human_store_health' => ['name' => '人力-院店健康报表', 'both_ends' => false],
    ];

    public static function reportCodes(): array { return array_keys(self::REPORTS); }

    public static function platformOnlyReportCodes(): array
    {
        return array_values(array_filter(self::reportCodes(), static fn(string $code): bool => empty(self::REPORTS[$code]['both_ends'])));
    }

    public static function catalogEntries(bool $platform = true): array
    {
        $rows = [];
        foreach (self::REPORTS as $code => $definition) {
            if (!$platform && empty($definition['both_ends'])) continue;
            $rows[] = [
                'folder' => '其他报表', 'code' => $code,
                'name' => $definition['name'], 'platform_only' => empty($definition['both_ends']),
            ];
        }
        return $rows;
    }

    public function supports(string $report): bool { return isset(self::REPORTS[$report]); }

    public function query(string $report, $storeIds, array $range, array $input = []): array
    {
        if (!$this->supports($report)) throw new \InvalidArgumentException('不支持的第六阶段报表类型');
        $stores = array_values(array_unique(array_filter(array_map('intval', (array)$storeIds))));
        if ($stores === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        $range = $this->range($range, $input);
        if ($report === 'phase_six_headquarters_acquisition') {
            $range = $this->headquartersYearRange($range, $input);
        }
        switch ($report) {
            case 'phase_six_garden_item_analysis': $result = $this->garden($stores, $range, $input); break;
            case 'phase_six_monthly_featured_item':
                $result = trim((string)($input['metric_code'] ?? '')) !== ''
                    ? $this->featuredDetail($stores, $range, $input)
                    : $this->featured($stores, $range);
                break;
            case 'phase_six_headquarters_acquisition':
                $result = trim((string)($input['metric_code'] ?? '')) !== ''
                    ? $this->headquartersDetail($stores, $range, $input)
                    : $this->headquarters($stores, $range);
                break;
            case 'phase_six_other_multi_payment': $result = $this->multiPayment($stores, $range, $input); break;
            case 'phase_six_salary_summary': $result = $this->salarySummary($stores, $range, $input); break;
            case 'phase_six_salary_detail': $result = $this->salaryDetail($stores, $range, $input); break;
            case 'phase_six_training_employee': $result = $this->trainingV2($stores, $range); break;
            case 'phase_six_acquisition_source':
                $result = trim((string)($input['metric_code'] ?? '')) !== ''
                    ? $this->acquisitionSourceDetail($stores, $range, $input)
                    : $this->acquisitionSource($stores, $range);
                break;
            case 'phase_six_human_store_health':
                $result = $this->healthWithStaffing($stores, $range);
                $result['metric_version'] = 'report-phase-six-current-category-v3';
                return $result;
            default: throw new \InvalidArgumentException('不支持的第六阶段报表类型');
        }
        // 事实默认值先生成，手动补充值最后投影；查询和导出共用同一读回路径。
        $result = $this->withManualAnnotations($report, $result, $stores, !empty($input['_internal_all']));
        if ($report === 'phase_six_monthly_featured_item' && trim((string)($input['metric_code'] ?? '')) === '') {
            $result = $this->featuredCompletionRates($result, $stores, !empty($input['_internal_all']));
        }
        // Only category-bearing reports change version; salary has its own accepted allocation contract.
        if (in_array($report, ['phase_six_garden_item_analysis', 'phase_six_monthly_featured_item', 'phase_six_other_multi_payment'], true)) {
            $result['metric_version'] = 'report-phase-six-current-category-v3';
        }
        // Only the garden headcount changes; other phase-six metrics retain their accepted versions.
        if ($report === 'phase_six_garden_item_analysis') {
            $result['metric_version'] = 'report-phase-six-garden-order-first-crossing-v6+actual-'
                . (string)(MetricDefinitionRegistry::get('actual_performance')['metric_version'] ?? '');
        }
        if ($report === 'phase_six_monthly_featured_item') {
            $result['metric_version'] = 'report-phase-six-featured-goals-v5+actual-' .
                (string)(MetricDefinitionRegistry::get('actual_performance')['metric_version'] ?? '');
        }
        if ($report === 'phase_six_headquarters_acquisition') {
            $result['metric_version'] = 'report-phase-six-headquarters-actual-'
                . (string)(MetricDefinitionRegistry::get('actual_performance')['metric_version'] ?? '');
        }
        if ($report === 'phase_six_acquisition_source') {
            $result['metric_version'] = 'report-phase-six-acquisition-source-original-order-r41+actual-' .
                (string)(MetricDefinitionRegistry::get('actual_performance')['metric_version'] ?? '')
                . '+global-member-day-v1';
        }
        if (in_array($report, ['phase_six_other_multi_payment', 'phase_six_salary_summary', 'phase_six_salary_detail'], true)) {
            $result['metric_version'] .= ':product-category-r2';
        }
        if ($report === 'phase_six_salary_summary') {
            // 只裁剪工资汇总中整列为零的数值指标；员工身份列、明细与事实聚合不变。
            $metricKeys = array_column(array_filter(
                $result['columns'], static fn(array $column): bool => !empty($column['summable'])
            ), 'key');
            $result = StoreReportZeroColumnVisibilityServices::hideAllZeroColumns($result, $metricKeys);
        }
        return $result;
    }

    /** Current, non-historical headcount maintenance. */
    public function staffing(array $storeIds): array
    {
        $stores = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($stores === []) return [];
        return Db::name('cashier_v3_report_beautician_establishment')->whereIn('store_id', $stores)
            ->field('store_id,establishment_count,version,updated_at')->select()->toArray();
    }

    public function saveStaffing(array $context, array $payload): array
    {
        $storeId = (int)($payload['store_id'] ?? 0);
        $count = (int)($payload['establishment_count'] ?? 0);
        if ($storeId <= 0 || $count < 0) throw new \InvalidArgumentException('门店和美容师编制人数不正确');
        if (!in_array($storeId, array_map('intval', (array)($context['store_ids'] ?? [])), true)) {
            throw new \InvalidArgumentException('无权限维护该门店编制人数');
        }
        $expected = (int)($payload['expected_version'] ?? 0);
        return Db::transaction(function () use ($storeId, $count, $expected, $context): array {
            $now = time();
            // The row lock must be acquired inside the transaction; locking a
            // read before the transaction leaves concurrent saves unprotected.
            $existing = Db::name('cashier_v3_report_beautician_establishment')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('store_id', $storeId)->lock(true)->find();
            if ($existing && $expected !== (int)$existing['version']) {
                throw new \InvalidArgumentException('编制人数已被其他人修改，请刷新后重试');
            }
            $row = [
                'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'store_id' => $storeId,
                'establishment_count' => $count, 'version' => $existing ? (int)$existing['version'] + 1 : 1,
                'updated_by' => (int)($context['admin_id'] ?? 0),
                'updated_by_name_snapshot' => (string)($context['admin_name'] ?? ''),
                'created_at' => $existing ? (int)$existing['created_at'] : $now, 'updated_at' => $now,
            ];
            if ($existing) Db::name('cashier_v3_report_beautician_establishment')->where('id', (int)$existing['id'])->update($row);
            else Db::name('cashier_v3_report_beautician_establishment')->insert($row);
            Db::name('cashier_v3_report_beautician_establishment_audit')->insert([
                'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'store_id' => $storeId,
                'before_count' => $existing ? (int)$existing['establishment_count'] : null,
                'after_count' => (int)$row['establishment_count'], 'version' => (int)$row['version'],
                'operator_id' => (int)($context['admin_id'] ?? 0), 'operator_name_snapshot' => (string)($context['admin_name'] ?? ''),
                'occurred_at' => $now,
            ]);
            return $row;
        });
    }

    /** 花园人数取原销售单首次达标事件；金额仍取原有已登记实际业绩。 */
    private function garden(array $stores, array $range, array $input): array
    {
        $facts = array_values(array_filter($this->cashFacts($stores, $range), static fn(array $r): bool => preg_match('/^花园(?:\s*\/|$)/u', trim((string)($r['category_path'] ?? ''))) === 1));
        $actualById = [];
        foreach ($this->registeredActualRows($stores, $range) as $actual) {
            if (preg_match('/^花园(?:\s*\/|$)/u', trim((string)$actual['category_path'])) !== 1) continue;
            $id = (string)$actual['allocation_id'];
            if (!isset($actualById[$id])) $actualById[$id] = ['fact' => $actual, 'cents' => 0, 'names' => []];
            $actualById[$id]['cents'] += (int)$actual['amount_cents'];
            if ((string)$actual['item_name'] !== '') $actualById[$id]['names'][(string)$actual['item_name']] = true;
        }
        $qualified = array_values(array_filter(
            (new StoreDealPeopleQualificationServices())->qualifiedDays($stores, $range, $input),
            static fn(array $day): bool => preg_match('/^花园(?:\s*\/|$)/u', trim((string)($day['category_path'] ?? ''))) === 1
        ));
        $allocationByReceiptItem = []; $matchedEvents = [];
        foreach ($facts as $fact) {
            $key = (string)json_encode([(string)($fact['payment_fact_id'] ?? ''),
                (int)$fact['store_id'], (int)$fact['item_id']]);
            $id = (string)$fact['allocation_id'];
            if (!isset($allocationByReceiptItem[$key]) || strcmp($id, $allocationByReceiptItem[$key]) < 0) {
                $allocationByReceiptItem[$key] = $id;
            }
        }
        $eventByAllocation = [];
        foreach ($qualified as $eventIndex => $event) {
            $key = (string)json_encode([(string)$event['fact_id'], (int)$event['store_id'],
                (int)($event['item_id'] ?? 0)]);
            if (isset($allocationByReceiptItem[$key])) $eventByAllocation[$eventIndex] = $allocationByReceiptItem[$key];
        }
        $allocationEvents = array_flip($eventByAllocation);
        $rows = []; $matchedActualIds = [];
        foreach ($facts as $fact) {
            $actualId = (string)$fact['allocation_row_id'];
            $actualCents = isset($matchedActualIds[$actualId]) ? 0 : (int)($actualById[$actualId]['cents'] ?? 0);
            $matchedActualIds[$actualId] = true;
            $eventIndex = $allocationEvents[(string)$fact['allocation_id']] ?? null;
            if ($eventIndex !== null) $matchedEvents[$eventIndex] = true;
            $rows[] = [
                'company_name' => (string)$fact['company_name'], 'store_name' => (string)$fact['store_name'],
                'member_id' => (int)$fact['member_id'],
                'member_name' => (string)$fact['member_name'], 'salesperson_name' => (string)$fact['salesperson_name'],
                'partner_name' => (string)$fact['partner_name'], 'experience_people' => (int)$fact['is_experience'],
                'deal_people' => $eventIndex === null ? 0 : 1,
                'item_name' => (string)$fact['item_name'], 'quantity' => (int)$fact['quantity'],
                'cash_amount' => $this->money($actualCents), 'row_key' => (string)$fact['allocation_id'],
                'store_id' => (int)$fact['store_id'], 'source_line_id' => (string)$fact['source_line_id'],
                'annotation_subject_type' => 'phase_six_row',
                'annotation_subject_key' => 'phase_six:garden:' . (string)$fact['allocation_id'],
            ];
        }
        // Card components and independently registered refunds may have no legacy garden sale row.
        // Give those signed facts their own stable row without manufacturing a sale or headcount.
        $dimensions = new StoreUnifiedReportOrganizationDimensionServices();
        foreach ($actualById as $id => $group) {
            if (isset($matchedActualIds[$id])) continue;
            $actual = $group['fact'];
            $company = $dimensions->resolve('company', (string)($actual['organization_id'] ?? ''),
                (string)($actual['organization_path_snapshot'] ?? ''), (string)$actual['business_date'],
                (int)$actual['store_id']);
            $rowKey = 'actual:' . $id;
            $rows[] = [
                'company_name' => (string)($company['name'] ?? ''), 'store_name' => (string)$actual['store_name'],
                'member_id' => (int)$actual['member_id'],
                'member_name' => (string)$actual['member_name'], 'salesperson_name' => '',
                'partner_name' => (string)($actual['partner_name_snapshot'] ?? ''),
                'experience_people' => 0, 'deal_people' => 0,
                'item_name' => implode('、', array_keys($group['names'])), 'quantity' => 0,
                'cash_amount' => $this->money((int)$group['cents']), 'row_key' => $rowKey,
                'store_id' => (int)$actual['store_id'], 'source_line_id' => (string)($actual['source_line_id'] ?? ''),
                'annotation_subject_type' => 'phase_six_row',
                'annotation_subject_key' => 'phase_six:garden:' . $rowKey,
            ];
        }
        // 跨店补交或卡内项目未形成本表普通销售明细时，资格仍归原店、达标日。
        foreach ($qualified as $eventIndex => $event) {
            if (isset($matchedEvents[$eventIndex])) continue;
            $company = $dimensions->resolve('company', (string)($event['organization_id'] ?? ''),
                (string)($event['organization_path_snapshot'] ?? ''), (string)$event['business_date'],
                (int)$event['store_id']);
            $rowKey = 'deal:' . (string)$event['fact_id'];
            $rows[] = [
                'company_name' => (string)($company['name'] ?? ''), 'store_name' => (string)$event['store_name_snapshot'],
                'member_id' => (int)$event['member_id'],
                'member_name' => (string)$event['member_name_snapshot'], 'salesperson_name' => '',
                'partner_name' => '', 'experience_people' => 0, 'deal_people' => 1,
                'item_name' => (string)($event['item_name'] ?? ''), 'quantity' => 0,
                'cash_amount' => $this->money(0), 'row_key' => $rowKey,
                'store_id' => (int)$event['store_id'], 'source_line_id' => '',
                'annotation_subject_type' => 'phase_six_row',
                'annotation_subject_key' => 'phase_six:garden:' . $rowKey,
            ];
        }
        return $this->result('花园品项分析表', [
            $this->column('company_name','分公司','销售事实发生时的组织快照。',false,true),
            $this->column('store_name','门店','销售事实发生时的门店快照。',false,true),
            $this->column('member_name','会员','销售明细对应会员或散客标识。'),
            $this->column('salesperson_name','门店销售人','销售事实中的销售人历史姓名快照。'),
            $this->column('partner_name','合作方名称','商品分类合作方名称快照。'),
            $this->column('experience_people','体验人数','购物车体验标签的有效记录。',true),
            $this->column('deal_people','成交人数','原销售单独立累计有效现金收款，首次达到其来源的可配置门槛时计1；同一会员同日跨单跨店只计1，跨店补交归原销售门店。跨分类单归达标时分摊金额最大的分类，退款不改变人数。',true),
            $this->column('item_name','成交品名','现金购买商品明细名称。'),
            $this->column('quantity','商品数量','对应销售明细数量。',true),
            $this->column('cash_amount','成交金额','花园分类已登记实际业绩：成功现金收款减成功现金退款；卡项与独立退款按已核实项目归类。',true),
            $this->manualColumn('expert_name','专家姓名','按稳定明细行键保存的手动字段.'),
        ], $rows, $range);
    }

    /** Card receipts retain their payment date and cents, but follow the card's component categories. */
    private function featuredFacts(array $stores, array $range, ?array $sales = null): array
    {
        $sales = $sales ?? $this->cashFacts($stores, $range);
        $cardFacts = $this->salaryCardCategoryAllocations($sales);
        $componentIds = [];
        foreach ($cardFacts as $components) {
            foreach ($components as $component) $componentIds[(int)$component['product_id']] = true;
        }
        $componentNames = [];
        foreach (array_chunk(array_keys($componentIds), 300) as $ids) {
            $componentNames += Db::name('store_product')->whereIn('id', $ids)->column('store_name', 'id');
        }
        $facts = [];
        foreach ($sales as $sale) {
            if ((string)($sale['source_type'] ?? '') !== 'card') {
                $facts[] = $sale;
                continue;
            }
            $components = $cardFacts[(string)$sale['sale_fact_id']] ?? [];
            foreach ($this->salarySaleCategoryAmounts($sale, $components) as $categoryId => $part) {
                $row = $sale;
                $row['category_id'] = (int)$categoryId;
                $row['category_path'] = (string)$part['path'];
                $row['amount_cents'] = (int)$part['cents'];
                $names = [];
                foreach ($components as $component) {
                    if ((int)$component['category_id'] === (int)$categoryId) {
                        $name = trim((string)($componentNames[(int)$component['product_id']] ?? ''));
                        if ($name !== '') $names[$name] = true;
                    }
                }
                // One category may contain several card projects; the detail must not claim only the first was sold.
                if ($names !== []) $row['item_name'] = implode('、', array_keys($names));
                $facts[] = $row;
            }
        }
        return $facts;
    }

    /** Read signed actual performance once; resolve current categories only where a category column needs them. */
    private function registeredActualRows(array $stores, array $range, bool $currentCategory = true): array
    {
        $reader = $currentCategory
            ? RegisteredMetricReadServices::withCurrentReportProductCategory()
            : new RegisteredMetricReadServices();
        $facts = $reader->categoryRows('actual_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID,
            $stores, $range);
        foreach ($facts as &$fact) {
            $fact['allocation_id'] = (string)($fact['id'] ?? $fact['fact_id'] ?? '');
            $fact['fact_id'] = (string)($fact['fact_id'] ?? $fact['id'] ?? '');
            $fact['member_name'] = (string)($fact['member_name'] ?? $fact['member_name_snapshot'] ?? '');
            $fact['order_no'] = (string)($fact['order_no_snapshot'] ?? $fact['order_id'] ?? '');
            $fact['item_name'] = (string)($fact['item_name'] ?? '');
            $fact['store_name'] = (string)($fact['store_name'] ?? '');
            $fact['category_path'] = (string)($fact['category_path'] ?? '');
            $fact['source_name'] = (string)($fact['source_label'] ?? '');
        }
        unset($fact);
        return $facts;
    }

    /** One scoped fact pass supplies the visible cells and their exact drilldown counts. */
    private function featured(array $stores, array $range): array
    {
        $groups = [];
        $categories = [];
        $totals = [];
        $dimensions = new StoreUnifiedReportOrganizationDimensionServices();
        // The monthly target must remain editable even when a store has no sales in the selected dates.
        foreach (Db::name('system_store')->whereIn('id', $stores)->field('id,name')->select()->toArray() as $store) {
            $storeId = (int)$store['id'];
            $company = $dimensions->resolve('company', '', '', (string)$range['end'], $storeId);
            $groups[$storeId] = ['store_id' => $storeId, 'company_name' => (string)($company['name'] ?? ''),
                'store_name' => (string)$store['name'], 'actual_amount_cents' => 0];
        }
        $sales = $this->cashFacts($stores, $range);
        foreach ($this->featuredFacts($stores, $range, $sales) as $fact) {
            $storeId = (int)$fact['store_id'];
            $category = (string)$fact['category_path'];
            if ($category === '') $category = '未分类';
            $categories[$category] = true;
            if (!isset($groups[$storeId])) {
                $groups[$storeId] = ['store_id' => $storeId, 'company_name' => $fact['company_name'],
                    'store_name' => $fact['store_name'], 'actual_amount_cents' => 0];
            }
            $bucket = &$totals[$storeId][$category];
            $allocationId = (string)$fact['allocation_id'];
            if ((int)$fact['is_experience'] === 1) $bucket['experience'][$allocationId] = true;
            if ((int)$fact['member_id'] > 0) $bucket['members'][(int)$fact['member_id']] = true;
            unset($bucket);
        }
        // Count cohorts remain sale-backed; only the two money columns use registered net facts.
        foreach ($this->registeredActualRows($stores, $range) as $fact) {
            $storeId = (int)$fact['store_id'];
            if (!isset($groups[$storeId])) continue;
            $category = (string)$fact['category_path'] ?: '未分类';
            $categories[$category] = true;
            $groups[$storeId]['actual_amount_cents'] += (int)$fact['amount_cents'];
            $totals[$storeId][$category]['cents'] = (int)($totals[$storeId][$category]['cents'] ?? 0)
                + (int)$fact['amount_cents'];
        }
        $columns = [
            $this->column('company_name', '区域', '组织权限范围。', false, true),
            $this->column('store_name', '门店', '门店权限范围。', false, true),
            $this->featuredTargetColumn('target_amount', '本月放假1天', '按门店和自然月手动填写放假1天的业绩目标；沿用原“业绩目标”已保存值。'),
            $this->featuredTargetColumn('target_holiday_2_amount', '本月放假2天', '按门店和自然月手动填写放假2天的业绩目标。'),
            $this->featuredTargetColumn('target_holiday_3_amount', '本月放假3天', '按门店和自然月手动填写放假3天的业绩目标。'),
            $this->column('actual_amount', '实际完成', '所选日期内已登记实际业绩；成功现金收款减成功现金退款，包含未归类的充值和退款。', true, false, 120, [], '业绩目标'),
            $this->column('performance_completion_rate', '完成率', '实际完成除以本月放假1天目标；目标未填或为0显示“-”。', false, false, 110, [], '业绩目标'),
            $this->featuredTargetColumn('post_sale_visit_target', '目标', '按门店和自然月手动填写售后人次目标。', '售后人次'),
            $this->column('post_sale_visit_actual', '实际完成', '所选日期内有效非体验服务，同一会员同店同日多单只计1人次；未建档按服务单计。', true, false, 110, [], '售后人次'),
            $this->column('post_sale_visit_completion_rate', '完成率', '售后人次实际完成除以目标；目标未填或为0显示“-”。', false, false, 110, [], '售后人次'),
            $this->column('previous_active_members', '上月实际', '查询结束月份的上一个完整自然月，有非体验有效服务的门店会员去重人数。', true, false, 110, [], '活客人数'),
            $this->column('current_active_members', '本月实际', '所选日期内有非体验有效服务的门店会员去重人数。', true, false, 110, [], '活客人数'),
            $this->column('active_member_completion_rate', '完成率', '本月实际活客人数除以上月实际；上月为0显示“-”。', false, false, 110, [], '活客人数'),
        ];
        foreach (array_keys($categories) as $category) {
            $key = 'featured_' . substr(sha1($category), 0, 10);
            $drilldown = ['report' => 'phase_six_monthly_featured_item'];
            $columns[] = $this->column($key . '_experience', $category . '/体验人数',
                '该分类下有体验标签的收款分摊记录数；同笔卡项的同分类项目只计一次。', true, false, 110, $drilldown, $category);
            $columns[] = $this->column($key . '_deal', $category . '/成交人数',
                '该分类下有收款分摊的会员按人去重；卡项按内含项目当前分类归属。', true, false, 110, $drilldown, $category);
            $columns[] = $this->column($key . '_cash', $category . '/成交金额',
                '该分类的已登记实际业绩；成功现金收款减成功现金退款，未归类款项留在未分类。', true, false, 120, $drilldown, $category);
        }
        foreach ($columns as &$column) {
            if (in_array((string)$column['key'], ['target_amount', 'target_holiday_2_amount',
                'target_holiday_3_amount', 'actual_amount'], true)) $column['display_precision'] = 0;
        }
        unset($column);
        $currentServices = $this->featuredServiceCounts($stores, $range);
        $previousStart = date('Y-m-01', strtotime(substr($range['end'], 0, 7) . '-01 -1 month'));
        $previousServices = $this->featuredServiceCounts($stores,
            ['start' => $previousStart, 'end' => date('Y-m-t', strtotime($previousStart))]);
        foreach ($groups as $storeId => &$row) {
            $row['actual_amount'] = $this->money((int)$row['actual_amount_cents']);
            $row['post_sale_visit_actual'] = (int)($currentServices[$storeId]['visits'] ?? 0);
            $row['previous_active_members'] = (int)($previousServices[$storeId]['members'] ?? 0);
            $row['current_active_members'] = (int)($currentServices[$storeId]['members'] ?? 0);
            foreach (array_keys($categories) as $category) {
                $key = 'featured_' . substr(sha1($category), 0, 10);
                $bucket = $totals[$storeId][$category] ?? [];
                $row[$key . '_experience'] = count($bucket['experience'] ?? []);
                $row[$key . '_deal'] = count($bucket['members'] ?? []);
                $row[$key . '_cash'] = $this->money((int)($bucket['cents'] ?? 0));
            }
            // The manually entered target belongs to the store and calendar month, not the selected day range.
            $row['annotation_subject_type'] = 'phase_six_row';
            $row['annotation_subject_key'] = 'phase_six:featured:' . $storeId . ':' . substr($range['end'], 0, 7);
        }
        unset($row);
        return $this->result('月主推数据统计表', $columns, array_values($groups), $range);
    }

    /** Match the shared member-day visit key without loading all scoped service facts into PHP. */
    private function featuredServiceCounts(array $stores, array $range): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)->whereBetween('sv.business_date', [$range['start'], $range['end']])
            ->where('sv.service_status', 'completed');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        \app\services\query\metric\StoreCustomerVisitEligibility::apply($query, 'sv');
        $visitKey = \app\services\query\metric\StoreCustomerVisitEligibility::sqlDailyVisitKey('sv');
        $counts = [];
        foreach ($query->fieldRaw('sv.store_id,COUNT(DISTINCT ' . $visitKey . ') visits,'
            . 'COUNT(DISTINCT CASE WHEN sv.member_id>0 THEN sv.member_id END) members')
            ->group('sv.store_id')->select()->toArray() as $row) {
            $counts[(int)$row['store_id']] = ['visits' => (int)$row['visits'], 'members' => (int)$row['members']];
        }
        return $counts;
    }

    /** Monthly planning targets share the existing audited annotation contract and are summable across stores. */
    private function featuredTargetColumn(string $key, string $label, string $explanation, string $group = '业绩目标'): array
    {
        $column = $this->manualColumn($key, $label, $explanation, $group);
        $column['summable'] = true;
        return $column;
    }

    /** Compute ratios after annotation readback, from saved cents rather than rounded display yuan. */
    private function featuredCompletionRates(array $result, array $stores, bool $export): array
    {
        if ($result['records'] === []) return $result;
        $keys = array_values(array_filter(array_column($result['records'], 'annotation_subject_key')));
        $targets = Db::name(StoreOperationsReportAnnotationServices::ANNOTATION_TABLE)
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('report_code', 'phase_six_monthly_featured_item')->where('subject_type', 'phase_six_row')
            ->whereIn('store_id', $stores)->whereIn('subject_key', $keys)
            ->whereIn('field_key', ['target_amount', 'target_holiday_2_amount',
                'target_holiday_3_amount', 'post_sale_visit_target'])
            ->field('store_id,subject_key,field_key,field_value')->select()->toArray();
        $byStore = [];
        foreach ($targets as $target) {
            $byStore[(int)$target['store_id'] . '|' . (string)$target['subject_key']][(string)$target['field_key']]
                = (string)$target['field_value'];
        }
        $moneyTargets = ['target_amount', 'target_holiday_2_amount', 'target_holiday_3_amount'];
        $targetTotals = array_fill_keys($moneyTargets, 0);
        $targetSeen = array_fill_keys($moneyTargets, false);
        $visitTargetTotal = 0;
        $visitTargetSeen = false;
        $allPerformanceTargets = true;
        $allVisitTargets = true;
        $actualCents = 0;
        $actualVisits = 0;
        $previousMembers = 0;
        $currentMembers = 0;
        foreach ($result['records'] as &$row) {
            $saved = $byStore[(int)$row['store_id'] . '|' . (string)$row['annotation_subject_key']] ?? [];
            foreach ($moneyTargets as $field) {
                if (($saved[$field] ?? '') === '') continue;
                $targetTotals[$field] += (int)$saved[$field];
                $targetSeen[$field] = true;
            }
            $performanceTarget = (string)($saved['target_amount'] ?? '');
            $visitTarget = (string)($saved['post_sale_visit_target'] ?? '');
            if ($performanceTarget === '' || (int)$performanceTarget <= 0) $allPerformanceTargets = false;
            if ($visitTarget === '' || (int)$visitTarget <= 0) $allVisitTargets = false;
            if ($visitTarget !== '') { $visitTargetTotal += (int)$visitTarget; $visitTargetSeen = true; }
            $actualCents += (int)$row['actual_amount_cents'];
            $actualVisits += (int)$row['post_sale_visit_actual'];
            $previousMembers += (int)$row['previous_active_members'];
            $currentMembers += (int)$row['current_active_members'];
            $row['performance_completion_rate'] = $performanceTarget !== '' && (int)$performanceTarget > 0
                ? $this->ratio((int)$row['actual_amount_cents'], (int)$performanceTarget) : '-';
            $row['post_sale_visit_completion_rate'] = $visitTarget !== '' && (int)$visitTarget > 0
                ? $this->ratio((int)$row['post_sale_visit_actual'], (int)$visitTarget) : '-';
            $row['active_member_completion_rate'] = $this->ratio((int)$row['current_active_members'],
                (int)$row['previous_active_members']);
            unset($row['actual_amount_cents']);
        }
        unset($row);
        foreach ($moneyTargets as $field) {
            $result['summary_row'][$field] = $targetSeen[$field]
                ? ($export ? \app\services\query\metric\MetricMoneyFormatter::exactYuan($targetTotals[$field])
                    : \app\services\query\metric\MetricMoneyFormatter::integerYuan($targetTotals[$field])) : '-';
        }
        $result['summary_row']['post_sale_visit_target'] = $visitTargetSeen ? $visitTargetTotal : '-';
        // A partial denominator must not make the all-store completion rate look better than it is.
        $result['summary_row']['performance_completion_rate'] = $allPerformanceTargets && $targetTotals['target_amount'] > 0
            ? $this->ratio($actualCents, $targetTotals['target_amount']) : '-';
        $result['summary_row']['post_sale_visit_completion_rate'] = $allVisitTargets && $visitTargetTotal > 0
            ? $this->ratio($actualVisits, $visitTargetTotal) : '-';
        $result['summary_row']['active_member_completion_rate'] = $this->ratio($currentMembers, $previousMembers);
        return $result;
    }

    /** The metric hash names a server-generated category; client text cannot select another category or store. */
    private function featuredDetail(array $stores, array $range, array $input): array
    {
        $metric = trim((string)$input['metric_code']);
        if (!preg_match('/^featured_([a-f0-9]{10})_(experience|deal|cash)$/D', $metric, $match)) {
            throw new \InvalidArgumentException('该指标没有可查看的明细');
        }
        $storeId = (int)($input['detail_store_id'] ?? 0);
        if ($storeId > 0 && !in_array($storeId, $stores, true)) {
            throw new \InvalidArgumentException('明细门店不在当前权限范围内');
        }
        $facts = $match[2] === 'cash'
            ? $this->registeredActualRows($stores, $range)
            : $this->featuredFacts($stores, $range);
        $categories = [];
        foreach ($facts as $fact) {
            $category = (string)$fact['category_path'];
            $categories[substr(sha1($category === '' ? '未分类' : $category), 0, 10)] = $category === '' ? '未分类' : $category;
        }
        $category = $categories[$match[1]] ?? null;
        if ($category === null) throw new \InvalidArgumentException('该分类在当前范围内没有明细');
        $selected = array_values(array_filter($facts, static fn(array $fact): bool =>
            ($storeId === 0 || (int)$fact['store_id'] === $storeId)
            && ((string)$fact['category_path'] === '' ? '未分类' : (string)$fact['category_path']) === $category));
        $kind = $match[2];
        if ($kind !== 'cash') {
            $unique = [];
            foreach ($selected as $fact) {
                if ($kind === 'experience' && (int)$fact['is_experience'] !== 1) continue;
                if ($kind === 'deal' && (int)$fact['member_id'] <= 0) continue;
                $key = $kind === 'experience'
                    ? (int)$fact['store_id'] . '|' . (string)$fact['allocation_id']
                    : (int)$fact['store_id'] . '|' . (int)$fact['member_id'];
                if (!isset($unique[$key])) $unique[$key] = $fact;
            }
            $selected = array_values($unique);
        }
        usort($selected, static fn(array $a, array $b): int =>
            strcmp((string)$a['business_date'], (string)$b['business_date'])
            ?: strcmp((string)$a['allocation_id'], (string)$b['allocation_id']));
        $selected = StoreUnifiedReportDetailFilter::apply($selected, $input,
            static fn(array $fact): string => (string)(($fact['order_no'] ?? '') ?: ($fact['order_id'] ?? '')));
        $total = count($selected);
        $totalCents = array_sum(array_map(static fn(array $fact): int => (int)$fact['amount_cents'], $selected));
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $visible = !empty($input['_internal_all']) ? $selected : array_slice($selected, ($page - 1) * $limit, $limit);
        $memberIds = array_values(array_unique(array_filter(array_map(static fn(array $fact): int => (int)$fact['member_id'], $visible))));
        $phones = [];
        foreach (array_chunk($memberIds, 500) as $ids) $phones += Db::name('user')->whereIn('uid', $ids)->column('phone', 'uid');
        $records = [];
        foreach ($visible as $fact) {
            $records[] = [
                'member_id' => (int)$fact['member_id'],
                'member_name' => (string)$fact['member_name'],
                'member_phone' => (string)($phones[(int)$fact['member_id']] ?? ''),
                'business_date' => (string)$fact['business_date'],
                'store_name' => (string)$fact['store_name'],
                'order_no' => (string)($fact['order_no'] ?: $fact['order_id']),
                'item_name' => (string)$fact['item_name'],
                'receipt_amount' => $kind === 'cash' ? $this->money((int)$fact['amount_cents']) : 1,
            ];
        }
        $columns = [
            $this->column('member_name', '会员', '销售发生时记录的会员姓名。', false, true),
            $this->column('member_phone', '手机号', '当前页会员的登记手机号。'),
            $this->column('business_date', '业务日期', '成功收款分摊的业务日期。'),
            $this->column('store_name', '门店', '发生时的门店名称。'),
            $this->column('order_no', '单据号', '对应的销售单据号。'),
            $this->column('item_name', '商品/项目', '卡项按内含项目显示。'),
            $this->column('receipt_amount', $kind === 'cash' ? '成交金额' : ($kind === 'deal' ? '成交人数' : '体验人数'),
                $kind === 'cash' ? '与上方分类已登记实际业绩逐笔对账。' : '每个去重计数对象显示1。', true),
        ];
        $result = $this->result($category . '明细', $columns, $records, $range);
        $result['summary_row']['receipt_amount'] = $kind === 'cash' ? $this->money($totalCents) : $total;
        $result['total'] = $total;
        $result['page'] = $page;
        $result['limit'] = $limit;
        return $result;
    }

    private function headquarters(array $stores, array $range): array
    {
        $saleFacts = $this->headquartersFacts($stores, $range);
        $rows = $this->headquartersRows($saleFacts, $this->headquartersAnnualFacts($stores, $range, $saleFacts));
        $drilldown = ['report' => 'phase_six_headquarters_acquisition'];
        return $this->result('总部拓客数据统计表',[
            $this->column('source','来源','会员本年度在所选门店范围内首张未作废销售单冻结的一级来源；首单为 A 或来源未标记的会员不入表，后续订单改来源不改变归属。',false,true),
            $this->column('member_count','会员人数','本自然年首单来源不是 A、且年度内有销售收款或冲销事实的会员去重人数。',true,false,120,$drilldown),
            $this->column('annual_amount','年度业绩','符合首单来源条件的会员，在所选门店范围内本自然年全部实际业绩：有效销售、充值和补交的现金收款减成功现金退款；新旧其他收款均不计入。二单以上业绩已包含在内，不另行相加。',true,false,120,$drilldown),
            $this->column('two_order_member_count','二单以上现金人数','本自然年第二张及后续销售单净现金收款大于 0 的会员去重人数；一单多商品只算一单，全额冲销不计人数。',true,false,150,$drilldown),
            $this->column('two_order_amount','二单以上实际业绩','本自然年第二张及后续未作废销售单的实际业绩：有效现金收款减成功现金退款，其他收款不计入；已成功作废的整单退出正常报表。',true,false,150,$drilldown),
        ],$rows,$range);
    }

    /** Prefer the selected year; old date links use their ending year for the same full-year cohort. */
    private function headquartersYearRange(array $range, array $input = []): array
    {
        $explicit = trim((string)($input['year'] ?? ''));
        $year = preg_match('/^20[0-9]{2}$/D', $explicit) ? $explicit : substr($range['end'], 0, 4);
        return ['start' => $year . '-01-01', 'end' => $year . '-12-31'];
    }

    /**
     * Amounts come from registered actual-performance cash facts. Sale order IDs and first-order
     * source come from the immutable sale facts; a debt repayment is not a new sale.
     * Both order ranking and sale receipts use the same full natural year and
     * authorized stores. Other cash types are read separately for that cohort.
     */
    private function headquartersFacts(array $stores, array $range): array
    {
        $query = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')
            ->leftJoin('cashier_v3_payment_sale_allocation_fact original', 'original.tenant_id=a.tenant_id AND original.allocation_fact_id=a.reversal_of')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=a.tenant_id AND s.fact_id=COALESCE(original.sale_fact_id,a.sale_fact_id)')
            ->where('a.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('a.store_id', $stores)->whereBetween('a.business_date', [$range['start'], $range['end']])
            ->where('a.status', 'effective')->where('s.status', 'effective')
            ->field('a.allocation_fact_id,a.store_id,s.member_id,s.order_id,s.order_no_snapshot order_no,a.business_date,a.amount_cents,s.member_name_snapshot member_name,s.store_name_snapshot store_name');
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query, 's.tenant_id', 's.order_id');
        $normal->excludeVoidedSupplementAllocations($query, 'a');
        $facts = $query->select()->toArray();
        $memberIds = array_values(array_unique(array_filter(array_map(
            static fn(array $fact): int => (int)$fact['member_id'], $facts
        ))));
        if ($memberIds === []) return [];
        // Keep sale-order identity from immutable sales, while the registered
        // reader alone decides whether each signed allocation counts as cash.
        $eligible = [];
        foreach ((new RegisteredMetricReadServices())->cashFactsForMembers(
            CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range, $memberIds
        ) as $cash) {
            if (($cash['cash_kind'] ?? '') !== 'sale') continue;
            $eligible[(string)$cash['allocation_fact_id']] = $cash;
        }
        $orders = $this->headquartersOrderSequence($stores, $range, $memberIds);
        $selected = [];
        foreach ($facts as $fact) {
            $cash = $eligible['sale:' . (string)$fact['allocation_fact_id']] ?? null;
            if ($cash === null) continue;
            $key = substr((string)$fact['business_date'], 0, 4) . ':' . (int)$fact['member_id'];
            $order = $orders[$key][(string)$fact['order_id']] ?? null;
            if ($order === null) continue;
            $fact['amount_cents'] = (int)$cash['amount_cents'];
            $fact['cohort_source_code'] = $order['source_code'];
            $fact['cohort_source_label'] = $order['source_label'];
            $fact['order_position'] = $order['position'];
            $selected[] = $fact;
        }
        return $selected;
    }

    /**
     * Rank normal sale orders once per member and natural year before excluding A.
     * Zero-cash sales still occupy their real order position; refunds do not create
     * another order, and a fully voided sale cannot become the first-order source.
     */
    private function headquartersOrderSequence(array $stores, array $range, array $memberIds): array
    {
        $sales = [];
        $start = substr($range['start'], 0, 4) . '-01-01';
        $normal = new StoreReportNormalDataScopeServices();
        foreach (array_chunk($memberIds, 500) as $ids) {
            $query = Db::name('cashier_v3_sale_fact')->alias('s')
                ->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('s.store_id', $stores)->whereIn('s.member_id', $ids)
                ->whereBetween('s.business_date', [$start, $range['end']])
                ->where('s.fact_direction', 'forward')->where('s.status', 'effective')
                ->field('s.member_id,s.order_id,s.order_no_snapshot,s.business_date,s.settled_at,s.business_source_primary_name_snapshot source_primary_name,s.business_source_label_snapshot source_name');
            $normal->excludeVoidedSalesOrderFacts($query, 's.tenant_id', 's.order_id');
            foreach ($query->select()->toArray() as $sale) $sales[] = $sale;
        }
        return $this->headquartersRankOrders($sales);
    }

    /** Pure ranking keeps the cohort and order boundary testable without reading or rewriting sale facts. */
    private function headquartersRankOrders(array $sales): array
    {
        $orders = [];
        foreach ($sales as $sale) {
            $memberId = (int)($sale['member_id'] ?? 0);
            $orderId = (string)($sale['order_id'] ?? '');
            if ($memberId <= 0 || $orderId === '') continue;
            $memberKey = substr((string)$sale['business_date'], 0, 4) . ':' . $memberId;
            $sort = (string)$sale['business_date'] . '|' . sprintf('%012d', (int)$sale['settled_at'])
                . '|' . (string)$sale['order_no_snapshot'] . '|' . $orderId;
            if (!isset($orders[$memberKey][$orderId]) || strcmp($sort, $orders[$memberKey][$orderId]['sort']) < 0) {
                $orders[$memberKey][$orderId] = ['sort' => $sort,
                    'source_primary_name' => trim((string)$sale['source_primary_name']),
                    'source_name' => trim((string)$sale['source_name'])];
            }
        }
        $sequence = [];
        foreach ($orders as $memberKey => $memberOrders) {
            uasort($memberOrders, static fn(array $left, array $right): int => strcmp($left['sort'], $right['sort']));
            $first = reset($memberOrders);
            $label = $first['source_primary_name'] !== '' ? $first['source_primary_name'] : $first['source_name'];
            $code = strtoupper(substr($label, 0, 1));
            // An absent source cannot be silently treated as non-A; the first
            // source is fixed before filtering so a later non-A sale cannot enter.
            if ($label === '' || $label === '系统词' || $code === 'A') continue;
            $sourceCode = preg_match('/^[B-Z]$/D', $code) ? $code : sha1($label);
            $position = 0;
            foreach ($memberOrders as $orderId => $_order) {
                $sequence[$memberKey][$orderId] = ['source_code' => $sourceCode,
                    'source_label' => $label, 'position' => ++$position];
            }
        }
        return $sequence;
    }

    /** Read all cash types only after the first-sale cohort has been established. */
    private function headquartersAnnualFacts(array $stores, array $range, array $saleFacts): array
    {
        $cohorts = [];
        foreach ($saleFacts as $fact) {
            $cohorts[(int)$fact['member_id']] = [
                'cohort_source_code' => (string)$fact['cohort_source_code'],
                'cohort_source_label' => (string)$fact['cohort_source_label'],
            ];
        }
        if ($cohorts === []) return [];
        $facts = (new RegisteredMetricReadServices())->cashFactsForMembers(
            CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range, array_keys($cohorts)
        );
        foreach ($facts as &$fact) {
            $fact += $cohorts[(int)$fact['member_id']];
        }
        unset($fact);
        return $facts;
    }

    /** Annual cash and later-sale measures have different authoritative fact populations. */
    private function headquartersGroups(array $facts, array $annualFacts): array
    {
        $groups = [];
        foreach ($facts as $fact) {
            $code = (string)($fact['cohort_source_code'] ?? '');
            if ($code === '') continue;
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId <= 0) continue;
            $cents = (int)($fact['amount_cents'] ?? 0);
            $groups[$code]['source_label'] ??= (string)($fact['cohort_source_label'] ?? $code);
            $groups[$code]['members'][$memberId] ??= $fact;
            $orderId = trim((string)($fact['order_id'] ?? ''));
            $orderNo = trim((string)($fact['order_no'] ?? ''));
            if ($orderId !== '') {
                $groups[$code]['order_numbers'][$memberId][$orderId] = $orderNo !== '' ? $orderNo : $orderId;
            }
            if ((int)($fact['order_position'] ?? 0) >= 2) {
                $groups[$code]['later_order_cents'] = (int)($groups[$code]['later_order_cents'] ?? 0) + $cents;
                $groups[$code]['later_order_facts'][] = $fact;
                if ($orderId !== '') {
                    $groups[$code]['later_orders'][$memberId][$orderId] =
                        (int)($groups[$code]['later_orders'][$memberId][$orderId] ?? 0) + $cents;
                    $groups[$code]['later_order_first_fact'][$memberId][$orderId] ??= $fact;
                }
            }
        }
        foreach ($annualFacts as $fact) {
            $code = (string)($fact['cohort_source_code'] ?? '');
            if ($code === '') continue;
            $groups[$code]['source_label'] ??= (string)($fact['cohort_source_label'] ?? $code);
            $groups[$code]['annual_facts'][] = $fact;
            $groups[$code]['annual_cents'] = (int)($groups[$code]['annual_cents'] ?? 0) + (int)$fact['amount_cents'];
        }
        foreach ($groups as &$group) {
            foreach ($group['later_orders'] ?? [] as $memberId => $laterOrders) {
                // Count a person only when all second-and-later orders have
                // positive selected-period net cash together. A reversal on
                // one order cannot be hidden by counting another positive order.
                if (array_sum($laterOrders) > 0) {
                    $group['two_order_members'][$memberId] = true;
                }
            }
        }
        unset($group);
        return $groups;
    }

    /** Keep the established zero rows and add any other explicitly marked non-A primary source. */
    private function headquartersRows(array $facts, array $annualFacts): array
    {
        $sources = ['B' => 'B 导购引流', 'E' => 'E 地推拓客', 'H' => 'H 沉睡唤醒',
            'G' => 'G 异业收客', 'F' => 'F 美带新客', 'C' => 'C 老带新客', 'D' => 'D 线上引流'];
        $groups = $this->headquartersGroups($facts, $annualFacts);
        foreach ($groups as $code => $group) {
            if (!isset($sources[$code])) $sources[$code] = $group['source_label'];
        }
        $rows = [];
        foreach ($sources as $code => $source) {
            $group = $groups[$code] ?? [];
            $rows[] = ['source' => $source, 'member_count' => count($group['members'] ?? []),
                'annual_amount' => $this->money((int)($group['annual_cents'] ?? 0)),
                'two_order_member_count' => count($group['two_order_members'] ?? []),
                'two_order_amount' => $this->money((int)($group['later_order_cents'] ?? 0)),
                'source_code' => $code];
        }
        return $rows;
    }

    /** Keep fact type unchanged; only distinguish recharge repayment in the drill-down label. */
    private function headquartersCashKindLabel(array $fact): string
    {
        $kind = (string)($fact['cash_kind'] ?? '');
        if ($kind === 'recharge') {
            return ($fact['source_document_type'] ?? '') === 'recharge_debt_repayment' ? '充值补交' : '充值';
        }
        return [
            'sale' => '消费',
            'debt_repayment' => '销售补交',
            'refund' => '退款',
        ][$kind] ?? '-';
    }

    /** The popup reuses the summary's scoped facts and cohort keys, with server-side pagination. */
    private function headquartersDetail(array $stores, array $range, array $input): array
    {
        $metric = trim((string)($input['metric_code'] ?? ''));
        $code = trim((string)($input['source_code'] ?? ''));
        if (!in_array($metric, ['member_count', 'annual_amount', 'two_order_member_count', 'two_order_amount'], true)
            || !preg_match('/^(?:[B-Z]|[a-f0-9]{40})$/D', $code)) {
            throw new \InvalidArgumentException('该指标没有可查看的明细');
        }
        $saleFacts = $this->headquartersFacts($stores, $range);
        $group = $this->headquartersGroups($saleFacts, $this->headquartersAnnualFacts($stores, $range, $saleFacts))[$code] ?? [];
        $isCount = str_ends_with($metric, '_count');
        $selected = [];
        if ($isCount) {
            foreach ($metric === 'member_count' ? ($group['members'] ?? []) : ($group['two_order_members'] ?? []) as $memberId => $entry) {
                $fact = $metric === 'member_count' ? $entry : null;
                if ($metric === 'two_order_member_count') {
                    // The count row's date must be a qualifying later receipt,
                    // rather than the member's first-order receipt date.
                    foreach ($group['later_orders'][$memberId] ?? [] as $laterOrderId => $netCents) {
                        if ($netCents > 0) {
                            $fact = $group['later_order_first_fact'][$memberId][$laterOrderId] ?? null;
                            break;
                        }
                    }
                }
                if ($fact === null) continue;
                $orderIds = $metric === 'member_count'
                    ? array_keys($group['order_numbers'][$memberId] ?? [])
                    : array_keys($group['later_orders'][$memberId] ?? []);
                $orderNumbers = array_values(array_unique(array_filter(array_map(
                    static fn(string $orderId): string => (string)($group['order_numbers'][$memberId][$orderId] ?? $orderId),
                    $orderIds
                ))));
                $fact['order_no'] = implode('、', $orderNumbers);
                $fact['receipt_amount'] = 1;
                $selected[] = $fact;
            }
        } else {
            // Signed later-order refunds remain visible even when net zero;
            // succeeded whole-order voids are excluded from normal reports.
            $amountFacts = $metric === 'annual_amount'
                ? ($group['annual_facts'] ?? []) : ($group['later_order_facts'] ?? []);
            foreach ($amountFacts as $fact) {
                $fact['receipt_amount'] = $this->money((int)$fact['amount_cents']);
                $selected[] = $fact;
            }
        }
        usort($selected, static fn(array $a, array $b): int =>
            strcmp((string)$a['business_date'], (string)$b['business_date'])
            ?: strcmp((string)$a['allocation_fact_id'], (string)$b['allocation_fact_id']));
        $selected = StoreUnifiedReportDetailFilter::apply($selected, $input,
            static fn(array $fact): string => (string)(($fact['order_no'] ?? '') ?: ($fact['order_id'] ?? '')));
        $total = count($selected);
        $totalCents = $isCount ? 0 : array_sum(array_map(static fn(array $fact): int => (int)$fact['amount_cents'], $selected));
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $visible = !empty($input['_internal_all']) ? $selected : array_slice($selected, ($page - 1) * $limit, $limit);
        $memberIds = array_values(array_unique(array_filter(array_map(static fn(array $fact): int => (int)$fact['member_id'], $visible))));
        $phones = [];
        foreach (array_chunk($memberIds, 500) as $ids) $phones += Db::name('user')->whereIn('uid', $ids)->column('phone', 'uid');
        $records = [];
        foreach ($visible as $fact) {
            $records[] = [
                'member_id' => (int)$fact['member_id'],
                'member_name' => trim((string)$fact['member_name']) ?: ((int)$fact['member_id'] <= 0 ? '游客' : '-'),
                'member_phone' => (string)($phones[(int)$fact['member_id']] ?? ''),
                'business_date' => (string)$fact['business_date'],
                'store_name' => (string)$fact['store_name'],
                'order_no' => (string)(($fact['order_no'] ?? '') ?: ($fact['order_id'] ?? '')),
                'cash_kind' => $this->headquartersCashKindLabel($fact),
                'receipt_amount' => $fact['receipt_amount'],
            ];
        }
        $columns = [
            $this->column('member_name','会员','销售发生时记录的会员姓名。',false,true),
            $this->column('member_phone','手机号','当前登记的会员手机号。'),
            $this->column('business_date','业务日期','现金事实发生的业务日期。'),
            $this->column('store_name','门店','本笔业务发生时记录的门店。'),
            $this->column('order_no','单据号','计数列显示该会员对应的销售单据号，金额列显示本笔现金业务单据号。'),
            ...($metric === 'annual_amount' ? [$this->column('cash_kind','业务类型','消费、充值、充值补交、销售补交及独立退款，按各自现金事实类型展示。')] : []),
            $this->column('receipt_amount',$isCount ? '人数' : '实际业绩','人数列每位会员为1；金额列按已登记实际业绩现金事实逐笔对账。',true),
        ];
        $result = $this->result('总部拓客数据明细',$columns,$records,$range);
        $result['summary_row']['receipt_amount'] = $isCount ? $total : $this->money($totalCents);
        $result['total'] = $total;
        $result['page'] = $page;
        $result['limit'] = $limit;
        return $result;
    }

    private function multiPayment(array $stores, array $range, array $input): array
    {
        // 已提交的结账方式决定入表资格，包括零金额；收款方式是订单级选择，
        // 商品行金额必须按结账时的应收减欠款基础分摊，不能在每行重复整笔金额。
        $query = Db::name('cashier_v3_checkout_payment_draft')->alias('p')
            ->join('cashier_v3_sales_order so', 'so.tenant_id=p.tenant_id AND so.store_id=p.store_id AND so.checkout_request_id=p.request_id AND so.checkout_request_version=p.draft_version')
            ->join('cashier_v3_sale_fact s', "s.tenant_id=so.tenant_id AND s.order_id=so.order_id AND s.fact_direction='forward' AND s.status='effective'")
            ->leftJoin('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
            ->leftJoin('cashier_v3_payment_collection c', "c.tenant_id=p.tenant_id AND c.sales_order_id=so.order_id AND c.checkout_payment_draft_id=p.payment_draft_id AND c.payment_method='other_collection' AND c.collection_direction='forward' AND c.collection_status='settled'")
            ->leftJoin('cashier_v3_payment_fact pf', "pf.tenant_id=c.tenant_id AND pf.checkout_request_id=so.checkout_request_id AND pf.source_line_id=c.collection_id AND pf.fact_type='payment_collected' AND pf.fact_direction='forward' AND pf.status='effective'")
            ->leftJoin('cashier_v3_payment_sale_allocation_fact a', "a.tenant_id=s.tenant_id AND a.order_id=so.order_id AND a.sale_fact_id=s.fact_id AND a.payment_fact_id=pf.fact_id AND a.payment_method='other_collection' AND a.status='effective' AND a.fact_direction='forward'")
            ->where('p.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('p.store_id', $stores)->where('p.payment_method', 'other_collection')
            ->where('p.draft_status', 'committed')->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('so.order_status', 'settled')
            ->where('so.order_direction', 'forward')->whereBetween('so.business_date', [$range['start'], $range['end']])
            ->field("p.payment_draft_id,COALESCE(c.amount_cents,p.amount_cents) tender_amount_cents,s.fact_id sale_fact_id,s.source_line_id,s.sale_amount_cents,s.debt_amount_cents,so.store_id,so.business_date,so.member_id,so.member_name_snapshot customer_name,s.organization_name_snapshot company_name,s.store_name_snapshot store_name,s.quantity,s.source_type,d.item_id,d.category_path_snapshot category,s.item_name_snapshot item_name,a.allocation_fact_id allocation_id,a.amount_cents allocated_amount_cents,(SELECT GROUP_CONCAT(DISTINCT employee_name_snapshot ORDER BY id SEPARATOR ', ') FROM eb_cashier_v3_performance_fact sales_person WHERE sales_person.tenant_id=s.tenant_id AND sales_person.source_line_id=s.source_line_id AND sales_person.performance_type='sales_performance_allocated' AND sales_person.status='effective') employee_name")
            ->order('p.id', 'asc')->order('s.id', 'asc')->order('a.id', 'asc');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, 'so.tenant_id', 'so.order_id');
        $facts = $query->select()->toArray();
        $links = [];
        foreach ($facts as $key => $fact) $links[$key] = array_merge($fact, ['product_id' => (int)$fact['item_id']]);
        $categories = (new StoreReportSaleCategoryServices())->resolve($links);
        // Keep the payment × sale row and its annotation key; only classify its purchased projects.
        foreach ($facts as $key => &$fact) $fact['category'] = StoreReportSaleCategoryServices::label($categories[$key] ?? []);
        unset($fact);
        $otherAmounts = $this->otherCollectionAmountsBySaleFact($facts);
        $rows = [];
        foreach ($facts as $fact) {
            // 历史实际收款延用原分摊键，保留已录入的手动值；无分摊的新口径按结账方式和销售明细建稳定键。
            $allocationId = trim((string)($fact['allocation_id'] ?? ''));
            $rowKey = $allocationId !== '' ? $allocationId : (string)$fact['payment_draft_id'] . ':' . (string)$fact['sale_fact_id'];
            $rows[] = [
                'store_id' => (int)$fact['store_id'], 'source_line_id' => (string)$fact['source_line_id'],
                'annotation_subject_type' => 'phase_six_row',
                'annotation_subject_key' => 'phase_six:payment:' . $rowKey,
                'company_name' => $fact['company_name'], 'store_name' => $fact['store_name'],
                // Carry the sale identity into the visible row so the common display layer can name true guests.
                'business_date' => $fact['business_date'], 'member_id' => (int)$fact['member_id'], 'customer_name' => $fact['customer_name'],
                'employee_name' => $fact['employee_name'], 'category' => $fact['category'],
                'item_name' => $fact['item_name'], 'quantity' => (int)$fact['quantity'],
                'receivable_amount' => $this->money((int)$fact['sale_amount_cents']),
                'other_collection_amount' => $this->money((int)($fact['allocated_amount_cents']
                    ?? $otherAmounts[(string)$fact['payment_draft_id']][(string)$fact['sale_fact_id']] ?? 0)),
                'row_key' => $rowKey, 'sale_fact_id' => (string)$fact['sale_fact_id'],
            ];
        }
        $columns = StoreReportResultRowFilterServices::declareDimensions([
            $this->column('company_name','分公司','这笔销售结账时所属的分公司。',false,true),
            $this->column('store_name','门店','这笔销售结账时所属的门店。',false,true),
            $this->column('business_date','日期','这笔订单的结账业务日期。'),
            $this->column('customer_name','顾客姓名','结账时记录的会员或顾客姓名。'),
            $this->column('employee_name','员工姓名','这件商品结账时记录的销售人。'),
            $this->column('category','商品分类','按商品 ID 关联平台商品当前有效分类；关联无效时留空，原结算金额不变。'),
            $this->column('item_name','商品名称','这笔订单中选择“其他收款”的商品名称。'),
            $this->column('quantity','数量','这笔订单中该商品的成交数量。',true),
            $this->column('receivable_amount','应收金额','该商品结账时的成交应收金额；同一商品有多笔“其他收款”时会在各笔明细重复显示，合计只算一次。',true),
            $this->column('other_collection_amount','其他收款','结账时选用“其他收款”的金额，按商品应收扣除欠款后的金额分摊；多件商品合计等于该笔结算金额。历史订单按当时实际记录的分摊金额显示；不代表现金业绩。',true),
            $this->manualColumn('领取日期','领取日期','由有权限的人员填写商品实际领取日期。'),
            $this->manualColumn('领取数量','领取数量','由有权限的人员填写商品实际领取数量。'),
            $this->manualColumn('备注','备注','由有权限的人员填写这条商品的补充说明。'),
        ], ['company_name','store_name','business_date','customer_name','employee_name','category','item_name']);
        // 同一商品可有多笔其他收款；筛后应收合计仍按销售事实去重。
        $rows = StoreReportResultRowFilterServices::filter($rows, $columns, (string)($input['row_filters'] ?? ''));
        $result = $this->result('其他多收款业绩表', $columns, $rows, $range);
        $receivableBySale = [];
        foreach ($rows as $row) $receivableBySale[(string)$row['sale_fact_id']] = $this->cents((string)$row['receivable_amount']);
        // 展示粒度是收款方式×商品，商品应收的合计粒度仍是商品，不能按收款笔数重复累计。
        $result['summary_row']['receivable_amount'] = $this->money(array_sum($receivableBySale));
        return $result;
    }

    /**
     * 为没有实际收款分摊事实的新口径结算复用相同的分摊算法；
     * 结果只用于展示原结算金额，不补写现金业绩或收款事实。
     *
     * @return array<string,array<string,int>> 结账方式 ID => 销售事实 ID => 分摊分数
     */
    private function otherCollectionAmountsBySaleFact(array $facts): array
    {
        $tenders = [];
        foreach ($facts as $fact) {
            $tenderId = (string)$fact['payment_draft_id'];
            $saleId = (string)$fact['sale_fact_id'];
            $tenders[$tenderId]['amount'] = (int)$fact['tender_amount_cents'];
            $tenders[$tenderId]['has_allocation'] = ($tenders[$tenderId]['has_allocation'] ?? false)
                || $fact['allocated_amount_cents'] !== null;
            $tenders[$tenderId]['sales'][$saleId] = [
                'fact_id' => $saleId,
                'sale_amount_cents' => (int)$fact['sale_amount_cents'],
                'debt_amount_cents' => (int)$fact['debt_amount_cents'],
            ];
        }
        $allocated = [];
        foreach ($tenders as $tenderId => $tender) {
            if ($tender['has_allocation']) continue;
            $allocated[$tenderId] = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(
                (int)$tender['amount'], array_values($tender['sales'])
            );
        }
        return $allocated;
    }

    private function salaryDetail(array $stores,array $range,array $input):array
    {
        $employeeName=$this->salaryEmployeeName($input);
        $employeeId=$this->salaryDrilldownId($input,'salary_employee_id');
        $storeId=$this->salaryDrilldownId($input,'salary_store_id');
        $categoryKey=trim((string)($input['salary_category_key']??''));
        $metricKey=trim((string)($input['salary_metric_key']??''));
        $documentNo=trim((string)($input['document_no']??''));
        if(mb_strlen($documentNo)>64)throw new \InvalidArgumentException('单据号最多输入64个字');
        if($metricKey!==''&&!in_array($metricKey,['project_count','service_visit_count','service_people_count','consumption_amount'],true))
            throw new \InvalidArgumentException('工资指标下钻条件不正确');
        if($metricKey!==''&&$categoryKey!=='')throw new \InvalidArgumentException('工资指标与商品分类不能同时下钻');
        if($metricKey!==''&&($employeeId===0||$storeId===0))
            throw new \InvalidArgumentException('请从员工薪资汇总表选择员工和门店后查看指标明细');
        if($categoryKey!=='' && $categoryKey!==self::SALARY_PRESALE_KEY
            && !preg_match('/^salary_category_cash_(?:\d+|historical_\d+|unclassified)$/D',$categoryKey))
            throw new \InvalidArgumentException('商品分类筛选条件不正确');
        if($categoryKey!=='' && ($employeeId===0 || $storeId===0))
            throw new \InvalidArgumentException('请从员工薪资汇总表选择员工和门店后查看分类明细');
        // 下钻使用服务端许可门店内的稳定 ID，不能用同名员工或页面文字扩大查询范围。
        if($storeId>0 && !in_array($storeId,$stores,true))throw new \InvalidArgumentException('无权限查看该门店的工资明细');
        $facts=$this->filterSalaryFactsByEmployeeName($this->salaryFactsWithReportingDimensions($stores,$range),$employeeName);
        if($employeeId>0)$facts=array_values(array_filter($facts,static fn(array $fact):bool=>(int)$fact['employee_id']===$employeeId));
        if($storeId>0)$facts=array_values(array_filter($facts,static fn(array $fact):bool=>(int)$fact['store_id']===$storeId));
        $orderReferences=$this->salaryOrderReferences($facts);
        $categories=$categoryKey!==''?$this->salaryCategoryDefinitions($this->observedSalaryCategories($facts)):null;
        $categoryLabel=$categoryKey===self::SALARY_PRESALE_KEY?'售前':'';
        if($categoryKey!=='')foreach($categories['columns'] as $column){
            if($column['key']===$categoryKey){$categoryLabel=(string)$column['label'];break;}
        }
        $visitRequests=[];
        $visitMemberDays=[];
        if($metricKey==='service_visit_count')foreach($facts as $fact){
            $requestId=trim((string)($fact['checkout_request_id']??''));
            if($requestId!==''&&trim((string)($fact['service_fact_id']??''))!==''
                &&(int)($fact['service_visit_tenths']??0)!==0){
                $visitRequests[$requestId]=true;
                $memberDay=$this->salaryVisitMemberDayKey($fact);
                if($memberDay!=='')$visitMemberDays[$memberDay]=true;
            }
        }
        $rows = [];
        foreach ($facts as $f) {
            $orderReference=$orderReferences[(int)$f['store_id'].'|'.(string)$f['checkout_request_id']]??[];
            if($documentNo!==''&&mb_stripos((string)($orderReference['number']??''),$documentNo)===false)continue;
            $cashCents=(int)($f['cash_cents']??0);
            if($categoryKey!==''){
                // 卡项可跨多个二级分类；明细只展示所点分类的分摊金额，合计才与汇总单元格相等。
                $projected=$this->salaryCategoryAmountsByColumn($f,$categories['targets']);
                $cashCents=(int)($projected[$categoryKey]??0);
                if($cashCents===0)continue;
            }
            // 同单服务及同员工、同门店、同日的会员本人跨单服务都应可核对；朋友/游客不跨单归组。
            // 补充的服务行只展示原有零人次，客数分摊仍由共享读取器决定。
            if($metricKey==='project_count'&&$this->projectCountMicros((string)$f['project_count'])===0)continue;
            if($metricKey==='service_visit_count'&&(int)($f['service_visit_tenths']??0)===0){
                $memberDay=$this->salaryVisitMemberDayKey($f);
                if(trim((string)($f['service_fact_id']??''))===''
                    ||(!isset($visitRequests[(string)($f['checkout_request_id']??'')])
                        &&($memberDay===''||!isset($visitMemberDays[$memberDay]))))continue;
            }
            if($metricKey==='service_people_count'&&(int)($f['service_people_tenths']??0)===0)continue;
            if($metricKey==='consumption_amount'&&(int)$f['consumption_cents']===0)continue;
            $detailRow = [
                'business_date' => $f['business_date'], 'company_name' => $f['company_name'],
                'store_name' => $f['store_name'], 'employee_name' => $f['employee_name'], 'position_name' => $f['position_name'],
                'member_id' => (int)$f['member_id'],
                'member_name' => $f['member_name'],
                'document_no' => (string)($orderReference['number']??'-'),
                'document_order_id' => (string)($orderReference['id']??''),
                'performance_category' => $categoryKey!==''?$categoryLabel:($this->salaryIsPresale($f)?'售前':$f['category_path']), 'detail' => $f['item_name'],
                'project_count' => (string)$f['project_count'],
                'consumption_amount' => $this->money((int)$f['consumption_cents']),
                'cash_amount' => $this->money($cashCents), 'cash_amount_cents' => $cashCents,
                'labor_fee' => $this->money((int)$f['labor_fee_cents']), 'row_key' => $f['row_key'],
                'store_id' => (int)$f['store_id'], 'employee_id' => (int)$f['employee_id'],
                'source_line_id' => (string)$f['source_line_id'],
                'annotation_subject_type' => 'phase_six_row',
                'annotation_subject_key' => 'phase_six:salary:' . (string)$f['row_key'],
            ];
            if($metricKey==='service_visit_count')$detailRow['service_visit_count']=$this->tenth((int)$f['service_visit_tenths']);
            if($metricKey==='service_people_count')$detailRow['service_people_count']=$this->tenth((int)$f['service_people_tenths']);
            $rows[]=$detailRow;
        }
        $columns=[
            $this->column('business_date','日期','服务完成、项目核销、销售结账或退款登记归属的业务日期；后来已作废的销售或服务不再统计。'),
            $this->column('company_name','分公司','按业务门店当前所属组织查找已配置的分公司统计维度；未配置时显示“未配置分公司”。',false,true),
            $this->column('store_name','门店','该笔业务实际发生的门店。',false,true),
            $this->column('employee_name','员工','该笔项目的服务员工，或销售/退款业绩分配到的员工；显示业务记账时记录的姓名。',false,true),
            $this->column('position_name','岗位','显示该员工当前有效岗位；跨组织参与人员依次读取业务门店任职、直属岗位和其他有效任职。已无有效岗位时显示“-”。岗位只供查看，不重新分配历史业绩。',false,true),
            $this->column('member_name','会员','显示该笔服务或销售记录中的会员、顾客姓名；游客显示“游客”，已建档会员未记录姓名时显示“-”。'),
            $this->column('document_no','单据号','显示订单中心对应的订单编号；点击可查看该订单详情。没有对应订单时显示“-”。',false,false,180,[
                'report'=>'order_center_order_detail','param_map'=>['order_id'=>'document_order_id','order_no'=>'document_no'],
            ]),
            $this->column('performance_category','商品分类','售前销售人分得的业绩显示“售前”；其他销售和服务读取关联商品的当前分类。卡项按卡内项目当前分类拆分，分类下钻只显示所点分类。'),
            $this->column('detail','明细','显示该笔业务记录的服务项目、卡项或产品名称。'),
            $this->column('project_count','项目数','该员工分得的服务项目数；多人服务按实际分配份额计算，后续人工调整按调整后的有效结果显示。',true),
        ];
        // Service visits and people were removed from the ordinary detail view;
        // reveal only the clicked metric so its period total can be audited.
        if($metricKey==='service_visit_count')$columns[]=$this->column('service_visit_count','服务人次','会员本人在同一门店同一天服务多项目只计一次；朋友和无会员顾客按每笔有效服务计算。同员工服务该会员的其他项目即使跨单也显示，未分得人次的项目为 0。',true);
        if($metricKey==='service_people_count')$columns[]=$this->column('service_people_count','服务人数','所选日期内，同一会员在同一门店的合格服务多天只计一次；原权益或项目结账含其他收款的服务不计人数，分得的人数只在一条合格明细显示。',true);
        $columns=array_merge($columns,[
            $this->column('consumption_amount','消耗业绩','服务完成或卡项核销后分配给该员工的消耗金额；后来作废的记录不再统计。',true),
            $this->column('cash_amount','现金业绩','销售分配金额减去退款日分配给该员工的退款业绩；售前分配单独归入售前，其他分配按商品分类。原销售分配不回写。',true),
            $this->column('labor_fee','手工费','该笔服务最终分配给员工的手工费；后续人员调整按有效结果显示，作废后不再统计。',true),
            $this->manualColumn('备注','备注','有权限的用户手动填写，保存后刷新页面仍会显示。')
        ]);
        return $this->salaryResult('员工薪资明细月报表',$columns,$rows,$range,$employeeName,$categoryKey!=='',$documentNo,true,$input);
    }

    /**
     * Match the order center's user-visible header: a settled sale takes precedence;
     * pure entitlement checkouts use the first completed service record number.
     * Read only immutable same-tenant, same-store identities from the salary facts in scope.
     */
    private function salaryOrderReferences(array $facts): array
    {
        $requestsByStore=[];
        foreach($facts as $fact){
            $requestId=trim((string)($fact['checkout_request_id']??''));
            $storeId=(int)($fact['store_id']??0);
            if($requestId!=='' && $storeId>0)$requestsByStore[$storeId][$requestId]=true;
        }
        $references=[];
        foreach($requestsByStore as $storeId=>$requests){
            foreach(array_chunk(array_keys($requests),300) as $requestIds){
                $sales=Db::name('cashier_v3_sales_order')
                    ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                    ->where('store_id',(int)$storeId)->whereIn('checkout_request_id',$requestIds)
                    ->where('order_status','settled')->where('order_direction','forward')
                    ->field('checkout_request_id,order_id,order_no')->select()->toArray();
                $withSale=[];
                foreach($sales as $sale){
                    $requestId=(string)$sale['checkout_request_id'];
                    $number=trim((string)$sale['order_no']);
                    $orderId=trim((string)$sale['order_id']);
                    if($number==='' || $orderId==='')continue;
                    $references[$storeId.'|'.$requestId]=['number'=>$number,'id'=>$orderId];
                    $withSale[$requestId]=true;
                }
                $serviceRequests=array_values(array_filter($requestIds,static fn(string $id):bool=>!isset($withSale[$id])));
                if($serviceRequests===[])continue;
                $services=Db::name('cashier_v3_entitlement_service_fact')
                    ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                    ->where('store_id',(int)$storeId)->whereIn('checkout_request_id',$serviceRequests)
                    ->where('service_status','completed')
                    ->fieldRaw("checkout_request_id,MIN(COALESCE(NULLIF(service_record_no,''),service_fact_id)) order_no")
                    ->group('checkout_request_id')->select()->toArray();
                foreach($services as $service){
                    $requestId=(string)$service['checkout_request_id'];
                    $number=trim((string)$service['order_no']);
                    if($requestId==='' || $number==='')continue;
                    $references[$storeId.'|'.$requestId]=['number'=>$number,'id'=>'service:'.$requestId];
                }
                // 补交业绩使用补交单自己的请求号，不得冒充原销售单。
                // 报表只显示可审计单号；该单不是销售订单，因此不生成销售详情链接。
                foreach ([
                    ['table'=>'cashier_v3_debt_repayment','type'=>'debt_repayment'],
                    ['table'=>'cashier_v3_recharge_debt_repayment','type'=>'recharge_debt_repayment'],
                ] as $supplementSource) {
                    $supplements=Db::name($supplementSource['table'])
                        ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                        ->where('store_id',(int)$storeId)->whereIn('repayment_id',$serviceRequests)
                        ->where('status','succeeded')->field('repayment_id,repayment_no')->select()->toArray();
                    foreach($supplements as $supplement){
                        $requestId=(string)$supplement['repayment_id'];
                        $number=trim((string)$supplement['repayment_no']);
                        if($requestId!==''&&$number!=='')$references[$storeId.'|'.$requestId]=['number'=>$number,'id'=>''];
                    }
                }
                // 退款工资行属于独立退款单，不冒充原销售单；明细展示退款单号。
                $refunds=Db::name('cashier_v3_order_lifecycle_operation')
                    ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                    ->where('store_id',(int)$storeId)->whereIn('operation_id',$serviceRequests)
                    ->where('operation_type','refund')->where('status','succeeded')
                    ->field('operation_id,operation_no')->select()->toArray();
                foreach($refunds as $refund)$references[$storeId.'|'.(string)$refund['operation_id']]=[
                    'number'=>(string)$refund['operation_no'],'id'=>'refund:'.(string)$refund['operation_id']];
            }
        }
        return $references;
    }

    /**
     * Give the target ranking the salary report's employee-level totals before
     * display rounding or category-column projection. The caller supplies its
     * already-authorized store scope; this read never changes salary facts.
     */
    public function salaryRankingRows(array $stores,array $range):array
    {
        return $this->salarySummary($stores,$range,[],true);
    }

    private function salarySummary(array $stores,array $range,array $input,bool $forRanking=false):array
    {
        $employeeName=$this->salaryEmployeeName($input);
        $rows=[];$observedCategories=[];
        foreach($this->filterSalaryFactsByEmployeeName(
            $this->salaryFactsWithReportingDimensions($stores,$range),$employeeName
        ) as $f){
            $key=(int)$f['store_id'].'|'.(int)$f['employee_id'];
            if(!isset($rows[$key]))$rows[$key]=['store_id'=>(int)$f['store_id'],'employee_id'=>(int)$f['employee_id'],'company_name'=>$f['company_name'],'store_name'=>$f['store_name'],'employee_name'=>$f['employee_name'],'position_name'=>$f['position_name'],'project_count_micros'=>0,'service_visit_tenths'=>0,'service_people_tenths'=>0,'consumption_cents'=>0,'cash_cents'=>0,'presale_cash_cents'=>0,'labor_fee_cents'=>0];
            // 工资项目数是人员服务份额，不是销售数量；用百万分位整数累加，保留入库 DECIMAL(20,6) 的精度。
            $rows[$key]['project_count_micros']+=$this->projectCountMicros((string)$f['project_count']);
            $rows[$key]['service_visit_tenths']+=(int)($f['service_visit_tenths'] ?? 0);
            $rows[$key]['consumption_cents']+=(int)$f['consumption_cents'];
            $rows[$key]['cash_cents']+=(int)($f['cash_cents'] ?? 0);
            $rows[$key]['presale_cash_cents']+=(int)($f['presale_cash_cents']??0);
            // Sales performance belongs to the employee and sale line; card
            // amounts have already been split by their contained projects. Presale
            // belongs to the selected salesperson, not the product category.
            $categoryAmounts=(array)($f['sales_category_cents']??[]);
            $unclassified=(int)($f['cash_cents']??0)-(int)($f['presale_cash_cents']??0)-array_sum($categoryAmounts);
            if($unclassified!==0)$categoryAmounts[0]=($categoryAmounts[0]??0)+$unclassified;
            foreach($categoryAmounts as $categoryId=>$cents){
                $rows[$key]['sales_category_cents'][$categoryId]=($rows[$key]['sales_category_cents'][$categoryId]??0)+(int)$cents;
                $observedCategories[$categoryId]=(string)($f['sales_category_paths'][$categoryId]??'');
            }
            $rows[$key]['labor_fee_cents']+=(int)$f['labor_fee_cents'];
        }
        // Service people is a range-level deduplicated metric.  It must come
        // from the common reader once per store/employee, rather than by
        // adding detail rows (which would turn a multi-day member into many
        // people).
        $summaryMetrics = $this->serviceCustomerMetrics($stores, $range)['summary_by_store_employee'];
        if($forRanking){
            $ranking=[];
            foreach($rows as $key=>$r){
                $ranking[]=[
                    'store_id'=>(int)$r['store_id'],'employee_id'=>(int)$r['employee_id'],
                    'employee_name'=>(string)$r['employee_name'],
                    'cash_cents'=>(int)$r['cash_cents'],'consumption_cents'=>(int)$r['consumption_cents'],
                    'labor_fee_cents'=>(int)$r['labor_fee_cents'],
                    'project_count_micros'=>(int)$r['project_count_micros'],
                    'service_visit_tenths'=>(int)$r['service_visit_tenths'],
                    'service_people_tenths'=>(int)($summaryMetrics[$key]['people_tenths']??0),
                ];
            }
            return $ranking;
        }
        $categories=$this->salaryCategoryDefinitions($observedCategories);
        foreach($rows as $key=>&$r){
            $r['service_people_tenths']=(int)($summaryMetrics[$key]['people_tenths'] ?? 0);
            $r['project_count']=$this->projectCountText((int)$r['project_count_micros']);
            $r['service_visit_count']=$this->tenth((int)$r['service_visit_tenths']);
            $r['service_people_count']=$this->tenth((int)$r['service_people_tenths']);
            $r['consumption_amount']=$this->money($r['consumption_cents']);$r['labor_fee']=$this->money($r['labor_fee_cents']);
            $r[self::SALARY_PRESALE_KEY]=$this->money((int)$r['presale_cash_cents']);
            foreach($categories['columns'] as $categoryColumn)$r[$categoryColumn['key']]='0';
            $projectedCents=$this->salaryCategoryAmountsByColumn($r,$categories['targets']);
            foreach($projectedCents as $columnKey=>$cents)$r[$columnKey]=$this->money($cents);
            unset($r['project_count_micros'],$r['service_visit_tenths'],$r['service_people_tenths'],$r['consumption_cents'],$r['cash_cents'],$r['presale_cash_cents'],$r['labor_fee_cents'],$r['sales_category_cents']);
        }unset($r);
        $columns=[
            $this->column('company_name','分公司','按业务门店当前所属组织查找已配置的分公司统计维度；未配置时显示“未配置分公司”。',false,true),
            $this->column('store_name','门店','该员工业务实际发生的门店。',false,true),
            $this->column('employee_name','员工','按门店和员工汇总所选日期内的有效业务，显示业务记账时记录的姓名；页面可输入姓名中的任意文字进行筛选。',false,true),
            $this->column('position_name','岗位','显示该员工当前有效岗位；跨组织参与人员依次读取业务门店任职、直属岗位和其他有效任职。已无有效岗位时显示“-”。岗位只供查看，不重新分配历史业绩。',false,true),
            $this->column('project_count','项目数','所选日期内该员工分得的服务项目数之和；多人服务及人工调整可产生小数，点击可查看对应明细。',true,false,120,$this->salaryMetricDrilldown('project_count')),
            $this->column('service_visit_count','服务人次','会员本人在同一门店同一天服务多项目只计一次，朋友和无会员顾客按每笔有效服务计算；普通员工分摊，独立核算岗位分别计算。点击可查看明细。',true,false,120,$this->salaryMetricDrilldown('service_visit_count')),
            $this->column('service_people_count','服务人数','所选日期内，同一会员在同一门店的合格服务多天只计一次；朋友及无会员的顾客按每次合格服务计算。原权益或项目结账含其他收款、不计人数人次的服务均排除；普通员工分摊，独立核算岗位分别计算。点击可查看明细。',true,false,120,$this->salaryMetricDrilldown('service_people_count')),
            $this->column('consumption_amount','消耗','所选日期内服务完成或卡项核销后分配给该员工的消耗金额之和；作废后不再统计，点击可查看明细。',true,false,120,$this->salaryMetricDrilldown('consumption_amount')),
            $this->column('labor_fee','手工','所选日期内最终分配给该员工的手工费之和；后续人员调整按有效结果显示，作废后不再统计。',true),
            $this->column(self::SALARY_PRESALE_KEY,'售前业绩','结账、补交或退款时，该员工被选为售前销售人的业绩净额；只计该员工分得的金额，退款按退款业务日扣减，不再重复计入商品分类。',true,false,140,[
                'report'=>'phase_six_salary_detail',
                'params'=>['employee_name'=>'','salary_category_key'=>self::SALARY_PRESALE_KEY],
                'param_map'=>['salary_employee_id'=>'employee_id','salary_store_id'=>'store_id'],
            ]),
        ];
        foreach($categories['columns'] as $categoryColumn){
            // 分类列自己声明精确下钻条件；页面仅传递 ID 与列键，不推断名称或重算金额。
            $categoryColumn['drilldown']=[
                'report'=>'phase_six_salary_detail',
                'params'=>['employee_name'=>'','salary_category_key'=>$categoryColumn['key']],
                'param_map'=>['salary_employee_id'=>'employee_id','salary_store_id'=>'store_id'],
            ];
            $columns[]=$categoryColumn;
        }
        return $this->salaryResult('员工薪资汇总月报表',$columns,array_values($rows),$range,$employeeName,false,'',false,$input);
    }

    /** Employee-name filtering always uses the immutable fact snapshot shown in the result. */
    private function salaryEmployeeName(array $input): string
    {
        $name=trim((string)($input['employee_name']??''));
        if(mb_strlen($name)>64)throw new \InvalidArgumentException('员工姓名最多输入64个字');
        return $name;
    }

    /** Validate stable drilldown IDs before applying them within the already authorized store scope. */
    private function salaryDrilldownId(array $input,string $key): int
    {
        $raw=trim((string)($input[$key]??''));
        if($raw==='' || $raw==='0')return 0;
        if(!ctype_digit($raw) || (int)$raw<=0)throw new \InvalidArgumentException('员工或门店筛选条件不正确');
        return (int)$raw;
    }

    /** Exact metric drilldown uses the same authorized store and employee IDs as category amounts. */
    private function salaryMetricDrilldown(string $metricKey): array
    {
        return [
            'report'=>'phase_six_salary_detail',
            'params'=>['employee_name'=>'','salary_metric_key'=>$metricKey],
            'param_map'=>['salary_employee_id'=>'employee_id','salary_store_id'=>'store_id'],
        ];
    }

    /** Keep the same observed category mapping for summary columns and category-specific detail. */
    private function observedSalaryCategories(array $facts): array
    {
        $observed=[];
        foreach($facts as $fact){
            if($this->salaryIsPresale($fact))continue;
            $amounts=(array)($fact['sales_category_cents']??[]);
            $unclassified=(int)($fact['cash_cents']??0)-(int)($fact['presale_cash_cents']??0)-array_sum($amounts);
            if($unclassified!==0)$amounts[0]=($amounts[0]??0)+$unclassified;
            foreach($amounts as $id=>$cents)$observed[$id]=(string)($fact['sales_category_paths'][$id]??'');
        }
        return $observed;
    }

    /** Project one salary fact by the same current/historical category targets used in the summary. */
    private function salaryCategoryAmountsByColumn(array $fact,array $targets): array
    {
        if($this->salaryIsPresale($fact))return [self::SALARY_PRESALE_KEY=>(int)($fact['presale_cash_cents']??0)];
        $amounts=(array)($fact['sales_category_cents']??[]);
        $unclassified=(int)($fact['cash_cents']??0)-(int)($fact['presale_cash_cents']??0)-array_sum($amounts);
        if($unclassified!==0)$amounts[0]=($amounts[0]??0)+$unclassified;
        $projected=[];
        foreach($amounts as $id=>$cents){
            $key=$targets[$id]??'salary_category_cash_unclassified';
            $projected[$key]=($projected[$key]??0)+(int)$cents;
        }
        return $projected;
    }

    /** Only the persisted per-person allocation role can move cash out of product categories. */
    private function salaryIsPresale(array $fact): bool
    {
        return (string)($fact['role_snapshot']??'')==='salesperson:presale';
    }

    /**
     * Apply the same partial-name match before either salary projection is
     * aggregated, so detail rows, summary rows, totals and exports stay equal.
     */
    private function filterSalaryFactsByEmployeeName(array $facts,string $employeeName): array
    {
        if($employeeName==='')return $facts;
        return array_values(array_filter($facts,static fn(array $fact):bool=>
            mb_stripos((string)($fact['employee_name']??''),$employeeName)!==false
        ));
    }

    /** Add the shared text filter contract without making the frontend infer it from report names. */
    private function salaryResult(string $title,array $columns,array $records,array $range,string $employeeName,bool $categoryDrilldown=false,string $documentNo='',bool $documentFilter=false,array $input=[]): array
    {
        $dimensionKeys = $categoryDrilldown || $documentFilter
            ? ['business_date','company_name','store_name','employee_name','position_name','member_name','document_no','performance_category','detail']
            : ['company_name','store_name','employee_name','position_name'];
        $columns = StoreReportResultRowFilterServices::declareDimensions($columns, $dimensionKeys);
        $records = StoreReportResultRowFilterServices::filter($records, $columns, (string)($input['row_filters'] ?? ''));
        $result=$this->result($title,$columns,$records,$range);
        if($categoryDrilldown){
            // Money is rounded only for display; the clicked category's total stays cent-accurate internally.
            $result['summary_row']['cash_amount']=$this->money(array_sum(array_map(
                static fn(array $row):int=>(int)($row['cash_amount_cents']??0),$records
            )));
        }
        $result['filter_schema']=[[
            // 页面隐藏重复的“员工”可见标题，但保留语义标题供读屏和键盘操作识别。
            'key'=>'employee_name','label'=>'员工','show_label'=>false,'aria_label'=>'员工姓名',
            'type'=>'text','placeholder'=>'输入员工姓名',
        ]];
        if($documentFilter)$result['filter_schema'][]=[
            'key'=>'document_no','label'=>'单据号','show_label'=>false,'aria_label'=>'单据号',
            'type'=>'text','placeholder'=>'输入单据号',
        ];
        // 薪资汇总和下钻共享版本；岗位只更新当前展示维度，不回写原服务、消耗或收款事实。
        $result['metric_version']=self::METRIC_VERSION.':salary-presale-allocation-v1:other-collection-people-v1:current-position-v2';
        $result['filters']['employee_name']=$employeeName;
        if($documentFilter)$result['filters']['document_no']=$documentNo;
        return $result;
    }

    private function training(array $stores,array $range):array{$rows=Db::name('employee')->where('is_del',0)->where('status',1)->field('id,name,entry_time,store_id')->select()->toArray();$allowed=array_flip($stores);$out=[];$i=1;foreach($rows as $r){if(isset($r['store_id'])&&!isset($allowed[(int)$r['store_id']]))continue;$out[]=['sequence'=>$i++,'company_name'=>'','store_name'=>'','beautician_name'=>(string)$r['name'],'entry_time'=>(string)$r['entry_time'],'mentor_name'=>'','entry_cycle'=>'','entry_level'=>'','exam_apply_time'=>'','exam_level'=>'','passed_level'=>'','skill_score'=>'','professional_score'=>'','in_service_3'=>'-','in_service_6'=>'-','in_service_9'=>'-','in_service_12'=>'-','in_service_over_year'=>'-'];}return $this->result('教培员工需求统计表',[$this->column('sequence','序号','页面稳定行号，不作为业务主键。'),$this->column('company_name','分公司','组织中的分公司统计维度。'),$this->column('store_name','门店','员工组织归属。'),$this->column('beautician_name','美容师','员工档案。'),$this->column('entry_time','入职时间','员工正式入职日期。'),$this->manualColumn('mentor_name','师傅','门店分配师傅关系。'),$this->column('entry_cycle','入职周期','按查询日期和每月15日规则计算。'),$this->column('entry_level','入职等级分类','按入职周期分级。'),$this->manualColumn('exam_apply_time','员工申请考试时间','员工手动输入。'),$this->manualColumn('exam_level','员工申请考试级别','员工手动输入。'),$this->manualColumn('passed_level','对应已考等级','教培人员手动输入。'),$this->manualColumn('skill_score','技能分数','教培人员手动输入。'),$this->manualColumn('professional_score','专业分数','教培人员手动输入。'),$this->column('in_service_3','3月是否在职','对应观察时点的历史员工状态。'),$this->column('in_service_6','6月是否在职','对应观察时点的历史员工状态.'),$this->column('in_service_9','9月是否在职','对应观察时点的历史员工状态.'),$this->column('in_service_12','12月是否在职','对应观察时点的历史员工状态.'),$this->column('in_service_over_year','1年以上是否在职','对应观察时点的历史员工状态.')],$out,$range);}

    private function acquisitionSource(array $stores,array $range):array
    {
        $map=[];
        // 成交只取原单首次达标事件；实际业绩仍读取独立的正负财务事实。
        foreach($this->acquisitionQualifiedFacts($stores,$range) as $fact){
            $source=$this->acquisitionSourceLabel($fact,false);
            $map[$source]??=['category'=>$source,'amount_cents'=>0,'members'=>[],'deal_members'=>[]];
            $memberId=(int)$fact['member_id'];
            if($memberId>0)$map[$source]['deal_members'][(string)$fact['business_date'].'|'.$memberId]=true;
        }
        foreach($this->acquisitionActualFacts($stores,$range) as $fact){
            $source=$this->acquisitionSourceLabel($fact,false);
            $map[$source]??=['category'=>$source,'amount_cents'=>0,'members'=>[],'deal_members'=>[]];
            $map[$source]['amount_cents']+=(int)$fact['amount_cents'];
        }
        foreach($this->acquisitionVisitFacts($stores,$range) as $fact){
            $source=$this->acquisitionSourceLabel($fact,true);
            $map[$source]??=['category'=>$source,'amount_cents'=>0,'members'=>[],'deal_members'=>[]];
            $memberId=(int)$fact['member_id'];
            if($memberId>0)$map[$source]['members'][$memberId]=true;
        }
        foreach($map as &$row){
            $row['source_code']=sha1($row['category']);
            $row['visit_count']=count($row['members']);$row['deal_count']=count($row['deal_members']);
            $row['amount']=$this->money((int)$row['amount_cents']);
            $row['deal_rate']=$this->ratio($row['deal_count'],$row['visit_count']);
            $row['unit_output']=$row['deal_count']?$this->money((int)round($row['amount_cents']/$row['deal_count'])):'-';
            unset($row['members'],$row['deal_members'],$row['amount_cents']);
        }
        unset($row);
        $drilldown=['report'=>'phase_six_acquisition_source'];
        return $this->result('拓客部门客户来源数据分析表',[$this->column('category','类别','进店按完成服务时保存的来源；历史服务未保存时取同明细销售来源，仍无来源显示“来源未标记”。成交按原销售单来源；A 来源不参与本表。',false,true),$this->column('visit_count','进店人数','已完成且未作废的非体验服务会员按非 A 来源去重；人员“不算人数人次”不影响门店进店。',true,false,120,$drilldown),$this->column('deal_count','成交人数','非 A 来源原销售单独立累计有效现金收款，首次达到平台设置的非 A 门槛时按收款业务日计1；跨店补交归原店，会员同日跨单跨店只计1，多日可累计。旧导入和旧卡录入原单补交不推动门槛，游客与退款不参与人数资格。',true,false,120,$drilldown),$this->column('amount','金额','非 A 来源已登记实际业绩，成功现金收款减成功现金退款；体验不排除金额。',true,false,120,$drilldown),$this->column('deal_rate','成交率','成交人数/进店人数，分母为0显示-。'),$this->column('unit_output','成交单产','实际业绩/成交人数，分母为0显示-。')],array_values($map),$range);
    }

    /** Money uses the registered signed population; source filtering uses each fact's frozen source. */
    private function acquisitionActualFacts(array $stores, array $range): array
    {
        return $this->acquisitionWithoutSourceA($this->registeredActualRows($stores, $range, false), false);
    }

    /** 成交明细与汇总共用一次达标的原单事件，不能从商品收款行重复生成会员。 */
    private function acquisitionQualifiedFacts(array $stores,array $range):array
    {
        $days=(new StoreDealPeopleQualificationServices())->qualifiedDays($stores,$range);
        $rows=[];
        foreach($days as $day){
            $row=$day;
            $row['order_id']=(string)$day['sales_order_id'];
            $row['order_no']=(string)$day['order_no_snapshot'];
            $row['member_name']=(string)$day['member_name_snapshot'];
            $row['store_name']=(string)$day['store_name_snapshot'];
            $row['source_name']=(string)$day['business_source_primary_name_snapshot'];
            if(!str_starts_with($this->acquisitionSourceLabel($row,false),'A'))$rows[]=$row;
        }
        return $rows;
    }

    /** Completed non-experience service snapshots drive visits; fill only historical blank sources in batches. */
    private function acquisitionVisitFacts(array $stores,array $range):array
    {
        $query=Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id',$stores)->whereBetween('sv.business_date',[$range['start'],$range['end']])
            ->where('sv.service_status','completed')
            ->field('sv.service_fact_id fact_id,sv.store_id,sv.member_id,sv.member_name_snapshot member_name,sv.business_date,sv.source_line_id,sv.service_record_no,sv.document_no_snapshot,sv.store_name_snapshot store_name,sv.business_source_label_snapshot service_source_name');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query,'sv');
        \app\services\query\metric\StoreCustomerVisitEligibility::apply($query,'sv');
        $facts=$query->select()->toArray();$lineIds=[];
        foreach($facts as $fact){
            if(trim((string)$fact['service_source_name'])==='' && (string)$fact['source_line_id']!=='')
                $lineIds[(string)$fact['source_line_id']]=true;
        }
        $sources=[];
        foreach(array_chunk(array_keys($lineIds),500) as $ids){
            $sourceRows=Db::name('cashier_v3_sale_fact')
                ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('source_line_id',$ids)->where('fact_direction','forward')->where('status','effective')
                ->fieldRaw('source_line_id,MAX(business_source_label_snapshot) source_name')
                ->group('source_line_id')->select()->toArray();
            foreach($sourceRows as $sourceRow)$sources[(string)$sourceRow['source_line_id']]=(string)$sourceRow['source_name'];
        }
        foreach($facts as &$fact)$fact['source_name']=(string)($sources[(string)$fact['source_line_id']]??'');
        unset($fact);
        return $this->acquisitionWithoutSourceA($facts,true);
    }

    /** Drilldown rechecks the source hash inside authorized facts; deal rows are unique member-days. */
    private function acquisitionSourceDetail(array $stores,array $range,array $input):array
    {
        $metric=trim((string)($input['metric_code']??''));
        $code=trim((string)($input['source_code']??''));
        if(!in_array($metric,['visit_count','deal_count','amount'],true)||!preg_match('/^[a-f0-9]{40}$/D',$code))
            throw new \InvalidArgumentException('该指标没有可查看的明细');
        $isVisit=$metric==='visit_count';$isCount=$metric!=='amount';
        $facts=$isVisit?$this->acquisitionVisitFacts($stores,$range)
            :($metric==='amount'?$this->acquisitionActualFacts($stores,$range)
                :$this->acquisitionQualifiedFacts($stores,$range));
        $selected=[];$amountCents=0;
        foreach($facts as $fact){
            if(sha1($this->acquisitionSourceLabel($fact,$isVisit))!==$code)continue;
            $memberId=(int)$fact['member_id'];
            if($isCount){
                $personKey=$metric==='deal_count'?(string)$fact['business_date'].'|'.$memberId:(string)$memberId;
                if($memberId>0 && !isset($selected[$personKey]))$selected[$personKey]=$fact;
            }else{
                $selected[]=$fact;$amountCents+=(int)$fact['amount_cents'];
            }
        }
        $selected=array_values($selected);
        usort($selected,static fn(array $a,array $b):int=>
            strcmp((string)$a['business_date'],(string)$b['business_date'])
            ?:strcmp((string)$a['fact_id'],(string)$b['fact_id']));
        $selected=StoreUnifiedReportDetailFilter::apply($selected,$input,
            static function(array $fact) use ($isVisit):string {
                return $isVisit
                    ? (trim((string)($fact['document_no_snapshot']??''))?:trim((string)($fact['service_record_no']??'')))
                    : (trim((string)($fact['order_no']??''))?:trim((string)($fact['order_id']??'')));
            });
        $amountCents=$isCount?0:array_sum(array_map(static fn(array $fact):int=>(int)$fact['amount_cents'],$selected));
        $total=count($selected);$page=max(1,(int)($input['page']??1));
        $limit=min(100,max(10,(int)($input['limit']??20)));
        $visible=!empty($input['_internal_all'])?$selected:array_slice($selected,($page-1)*$limit,$limit);
        $memberIds=array_values(array_unique(array_filter(array_map(static fn(array $fact):int=>(int)$fact['member_id'],$visible))));
        $phones=[];
        foreach(array_chunk($memberIds,500) as $ids)$phones+=Db::name('user')->whereIn('uid',$ids)->column('phone','uid');
        $records=[];
        foreach($visible as $fact){
            $memberId=(int)$fact['member_id'];
            $document=$isVisit
                ?(trim((string)$fact['document_no_snapshot'])?:trim((string)$fact['service_record_no']))
                :(trim((string)$fact['order_no'])?:trim((string)$fact['order_id']));
            $records[]=['member_name'=>trim((string)$fact['member_name'])?:($memberId>0?'-':'游客'),
                'member_id'=>$memberId,
                'member_phone'=>(string)($phones[$memberId]??''),'business_date'=>(string)$fact['business_date'],
                'store_name'=>(string)$fact['store_name'],'order_no'=>$document,
                'receipt_amount'=>$isCount?1:$this->money((int)$fact['amount_cents'])];
        }
        $columns=[$this->column('member_name','会员','事实发生时冻结的会员名称。',false,true),
            $this->column('member_phone','手机号','会员当前登记的手机号。'),
            $this->column('business_date','业务日期','进店取完成服务日，成交取原销售单首次达标的收款业务日，金额取财务事实业务日。'),
            $this->column('store_name','门店','事实发生时冻结的门店。'),
            $this->column('order_no','单据号','进店取服务关联单据号，成交取销售单据号。'),
            $this->column('receipt_amount',$isCount?'人数':'实际业绩','进店人数按会员去重；成交人数按首次达标的会员业务日计1，金额逐笔展示已登记实际业绩。',true)];
        $result=$this->result('拓客来源明细',$columns,$records,$range);
        $result['summary_row']['receipt_amount']=$isCount?$total:$this->money($amountCents);
        $result['total']=$total;$result['page']=$page;$result['limit']=$limit;
        return $result;
    }

    /** Preserve historical source snapshots; an absent or obsolete placeholder is not a business source. */
    private function acquisitionSourceLabel(array $fact, bool $serviceFact): string
    {
        $source = $serviceFact ? trim((string)($fact['service_source_name'] ?? '')) : '';
        if ($source === '') $source = trim((string)($fact['source_name'] ?? ''));
        return $source === '' || $source === '系统词' ? '来源未标记' : $source;
    }

    /** Filter the frozen A source before summary and detail share these facts; historical service fallback uses the same label. */
    private function acquisitionWithoutSourceA(array $facts, bool $serviceFact): array
    {
        return array_values(array_filter($facts, function (array $fact) use ($serviceFact): bool {
            return !str_starts_with($this->acquisitionSourceLabel($fact,$serviceFact),'A');
        }));
    }

    private function health(array $stores,array $range):array
    {
        $facts=$this->cashFacts($stores,$range);$services=$this->serviceFacts($stores,$range);$rows=[];
        $beauticianPositionId=(int)Db::name('position')->where('name','美容师')->where('status',1)->value('id');
        $est=$beauticianPositionId>0
            ? Db::name('staffing_quota')->where('tenant_id','0')->where('scope_type','store')->whereIn('scope_id',$stores)->where('position_id',$beauticianPositionId)->column('quota_count','scope_id')
            : [];
        foreach($stores as $storeId){
            $storeFacts=array_values(array_filter($facts,static fn(array $f):bool=>(int)$f['store_id']===$storeId));
            $storeServices=array_values(array_filter($services,static fn(array $f):bool=>(int)$f['store_id']===$storeId));
            $storeCash=0;$allMembers=[];$categoryTotals=[];$categoryMembers=[];$categoryServices=[];$categoryConsumption=[];$categoryVisitMembers=[];
            foreach($storeFacts as $f){$storeCash+=(int)$f['amount_cents'];$id=(int)$f['member_id'];if($id>0)$allMembers[$id]=true;$cat=$this->topCategory((string)$f['category_path']);$categoryTotals[$cat]=($categoryTotals[$cat]??0)+(int)$f['amount_cents'];if($id>0)$categoryMembers[$cat][$id]=true;}
            foreach($storeServices as $s){$cat=$this->topCategory((string)$s['category_path']);$categoryConsumption[$cat]=($categoryConsumption[$cat]??0)+(int)$s['consumption_cents'];$id=(int)$s['member_id'];if($id>0)$categoryMembers[$cat][$id]=true;if(\app\services\query\metric\StoreCustomerVisitEligibility::allows($s)){$categoryServices[$cat]=($categoryServices[$cat]??0)+max(0,(int)$s['quantity']);if($id>0)$categoryVisitMembers[$cat][$id]=true;}}
            // 只有体验服务但产生消耗时也保留品项行，体验只归零到店，不得抹掉消耗。
            $categories=array_values(array_unique(array_merge(array_keys($categoryTotals),array_keys($categoryServices),array_keys($categoryConsumption))));if($categories===[])$categories=['全部品项'];
            $beauticians=(int)Db::name('system_store_staff')->where('store_id',$storeId)->where('status',1)->where('is_del',0)->where('cashier_craftsman_enabled',1)->count();$staffing=(int)($est[$storeId]??0);$employeeCount=(int)Db::name('system_store_staff')->where('store_id',$storeId)->where('status',1)->where('is_del',0)->count();$storeName=(string)(Db::name('system_store')->where('id',$storeId)->value('name')??('门店'.$storeId));$company='';foreach($storeFacts as $f){if(trim((string)$f['company_name'])!==''){$company=(string)$f['company_name'];break;}}
            // 活客与服务人次只受体验标记控制；销售人头、现金和消耗保持各自事实口径。
            foreach($categories as $cat){$catCash=(int)($categoryTotals[$cat]??0);$members=count((array)($categoryMembers[$cat]??[]));$servicesCount=(int)($categoryServices[$cat]??0);$consumption=(int)($categoryConsumption[$cat]??0);$rows[]=['store_id'=>$storeId,'company_name'=>$company,'store_name'=>$storeName,'item_category'=>$cat,'employee_count'=>$employeeCount,'beautician_establishment_count'=>$staffing,'beautician_fill_rate'=>$staffing>0?$this->ratio($beauticians,$staffing):'-','beautician_turnover_rate'=>'-','active_customer_count'=>count((array)($categoryVisitMembers[$cat]??[])),'service_count'=>$servicesCount,'average_order_value'=>$members?$this->money((int)round($catCash/$members)):'-','sales_efficiency'=>$employeeCount?$this->money((int)round($catCash/$employeeCount)):'-','ten_thousand_customer_ratio'=>$this->ratio($this->countMembersAtLeast($storeFacts,$cat,1000000),$members),'cash_amount'=>$this->money($catCash),'consumption_amount'=>$this->money($consumption),'consumption_efficiency'=>$employeeCount?$this->money((int)round($consumption/$employeeCount)):'-','effective_sales_headcount'=>$members,'sales_ratio'=>$this->ratio($catCash,$storeCash)];}
        }
        return $this->result('人力-院店健康报表',[$this->column('company_name','分公司','组织中的分公司统计维度。',false,true),$this->column('store_name','门店','门店权限范围。',false,true),$this->column('item_category','品项','按关联平台商品当前有效分类归入一级品项。',false,true),$this->column('employee_count','员工人数','查询截止日有效员工人数。',true),$this->column('beautician_establishment_count','美容师编制人数','每门店唯一当前编制值，不分时间。',true),$this->column('beautician_fill_rate','美容师满岗率','实际美容师人数/当前编制人数，编制为0显示-。'),$this->column('beautician_turnover_rate','美容师离职率','期间离职美容师人数/对应期间在职人数。'),$this->column('active_customer_count','活跃客','区间内非体验有效服务会员去重；人员“不算人数人次”不影响门店活客。',true),$this->column('service_count','服务人次','区间内非体验有效服务项目数量；人员开关不参与，体验服务仍保留消耗业绩。',true),$this->column('average_order_value','客单价','销售业绩/不重复消费人头数。'),$this->column('sales_efficiency','销售人效','销售业绩/员工人数。'),$this->column('ten_thousand_customer_ratio','万元客比','达到1万元门槛客户数/有效客户数。'),$this->column('cash_amount','现金业绩（减退款）','现金业绩净额。',true),$this->column('consumption_amount','消耗业绩','服务或核销消耗业绩。',true),$this->column('consumption_efficiency','消耗人效','消耗业绩/员工人数。'),$this->column('effective_sales_headcount','有效销售人头数','有效销售会员或顾客去重人数。',true),$this->column('sales_ratio','销售比','对应品项业绩/门店现金业绩总额。')],$rows,$range);
    }

    /** 可选读取真实收款来源供花园会员日匹配；其他报表的金额与原销售快照不变。 */
    private function cashFacts(array $stores,array $range):array
    {
        $query = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')
            ->leftJoin('cashier_v3_report_sale_dimension_fact d','d.tenant_id=a.tenant_id AND d.sale_fact_id=a.sale_fact_id')
            ->leftJoin('cashier_v3_sale_fact s','s.tenant_id=a.tenant_id AND s.fact_id=a.sale_fact_id')
            ->where('a.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('a.store_id',$stores)->whereBetween('a.business_date',[$range['start'],$range['end']])->where('a.status','effective')
            ->field("a.id allocation_row_id,a.allocation_fact_id allocation_id,a.payment_fact_id,a.store_id,a.member_id,a.order_id,a.source_line_id,a.business_date,a.amount_cents,a.payment_method,d.item_id,d.item_name_snapshot item_name,d.category_path_snapshot category_path,d.partner_name_snapshot partner_name,d.is_experience,s.fact_id sale_fact_id,s.source_type,s.order_no_snapshot order_no,s.quantity,s.member_name_snapshot member_name,s.organization_name_snapshot company_name,s.organization_path_snapshot organization_path_snapshot,s.store_name_snapshot store_name,s.business_source_label_snapshot source_name,(SELECT GROUP_CONCAT(DISTINCT pf.employee_name_snapshot ORDER BY pf.id SEPARATOR ', ') FROM eb_cashier_v3_performance_fact pf WHERE pf.tenant_id=a.tenant_id AND pf.source_line_id=a.source_line_id AND pf.performance_type='sales_performance_allocated' AND pf.status='effective') salesperson_name");
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query, 'a.tenant_id', 'a.order_id');
        $normal->excludeVoidedSupplementAllocations($query, 'a');
        return $this->currentCategoryPaths($query->select()->toArray(), 'item_id', 'category_path');
    }

    private function serviceFacts(array $stores,array $range):array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sv.store_id',$stores)
            ->whereBetween('sv.business_date',[$range['start'],$range['end']])->where('sv.service_status','completed')
            ->field("sv.service_fact_id fact_id,sv.service_record_no,sv.checkout_request_id,sv.store_id,sv.member_id,sv.business_date,sv.source_line_id,sv.quantity,sv.project_count,sv.project_id item_id,sv.project_name_snapshot item_name,sv.project_category_path_snapshot category_path,sv.store_name_snapshot store_name,sv.organization_path_snapshot organization_path_snapshot,sv.is_experience,sv.business_source_label_snapshot service_source_name,COALESCE(sv.labor_fee_amount_cents,0) labor_fee_cents,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.tenant_id=sv.tenant_id AND pf.checkout_request_id=sv.checkout_request_id AND pf.source_line_id=sv.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective') consumption_cents,(SELECT MAX(sf.business_source_label_snapshot) FROM eb_cashier_v3_sale_fact sf WHERE sf.tenant_id=sv.tenant_id AND sf.source_line_id=sv.source_line_id AND sf.fact_direction='forward' AND sf.status='effective') source_name");
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        return $this->currentCategoryPaths($query->select()->toArray(), 'item_id', 'category_path');
    }

    /** Keep the original financial facts while displaying the linked platform product's current category. */
    private function currentCategoryPaths(array $rows, string $productKey, string $pathKey): array
    {
        if ($rows === []) return [];
        $links = [];
        foreach ($rows as $index => $row) {
            $links[$index] = ['store_id' => (int)$row['store_id'], 'product_id' => (int)($row[$productKey] ?? 0)];
        }
        $categories = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        foreach ($rows as $index => &$row) $row[$pathKey] = (string)($categories[$index]['path'] ?? '');
        unset($row);
        return $rows;
    }

    /**
     * Retain immutable card-component monetary weights, but resolve every component's
     * category through its actual product ID. The card's outer category never replaces projects.
     */
    private function salaryCardCategoryAllocations(array $salesRows): array
    {
        $salesRows = array_values(array_filter($salesRows, static fn(array $row): bool => ($row['source_type'] ?? '') === 'card'));
        $resolved = (new StoreReportSaleCategoryServices())->resolve($salesRows);
        $bySale = [];
        foreach ($salesRows as $key => $sale) {
            foreach ($resolved[$key] ?? [] as $category) {
                $bySale[(string)$sale['sale_fact_id']][] = array_merge($category, [
                    'category_id' => (int)$category['id'], 'category_path' => (string)$category['path']]);
            }
        }
        return $bySale;
    }

    /**
     * Allocate one employee's sales-performance cents to sale categories.
     * The final stable category receives the integer-cent remainder; a card
     * lacking component facts is reported as unclassified, not as 卡项.
     */
    private function salarySaleCategoryAmounts(array $sale,array $cardFacts): array
    {
        $amount=(int)($sale['amount_cents']??0);
        if((string)($sale['source_type']??'')!=='card'){
            $id=(int)($sale['category_id']??0);
            return [$id=>['cents'=>$amount,'path'=>(string)($sale['category_path']??'')]];
        }
        if($cardFacts===[])return [0=>['cents'=>$amount,'path'=>'未分类']];
        $weights=[];$paths=[];
        foreach(['cash_performance_amount_cents','sale_amount_cents','configured_amount_cents'] as $weightField){
            $weights=[];
            foreach($cardFacts as $fact){
                $id=(int)($fact['category_id']??0);
                $weights[$id]=($weights[$id]??0)+abs((int)($fact[$weightField]??0));
                $paths[$id]=(string)($fact['category_path']??'');
            }
            if(array_sum($weights)>0)break;
        }
        ksort($weights,SORT_NUMERIC);
        $total=array_sum($weights);
        if($total===0){foreach($weights as &$weight)$weight=1;unset($weight);$total=count($weights);}
        $remaining=abs($amount);$sign=$amount<0?-1:1;$lastId=array_key_last($weights);$result=[];
        foreach($weights as $id=>$weight){
            $part=$id===$lastId?$remaining:(int)bcdiv(bcmul((string)abs($amount),(string)$weight,0),(string)$total,0);
            $remaining-=$part;
            $result[$id]=['cents'=>$sign*$part,'path'=>$paths[$id]??''];
        }
        return $result;
    }

    /**
     * Enrich salesperson facts created by debt repayments. These facts correctly
     * carry employee and cash amounts but intentionally have no synthetic sale line;
     * product dimensions must therefore be reconstructed from the immutable repayment
     * authority and debt items without writing back or inventing a sale fact.
     */
    private function salarySupplementSalesRows(array $rows): array
    {
        $salesIds=[];$rechargeIds=[];
        foreach($rows as $row){
            if(trim((string)($row['sale_fact_id']??''))!=='')continue;
            $id=trim((string)($row['order_id']??''));
            if($id==='')continue;
            $type=(string)($row['source_document_type']??'');
            if($type==='debt_repayment')$salesIds[$id]=true;
            elseif($type==='recharge_debt_repayment')$rechargeIds[$id]=true;
        }
        if($salesIds===[]&&$rechargeIds===[])return $rows;

        $dimensions=[];$debtIds=[];
        if($salesIds!==[]){
            $repayments=Db::name('cashier_v3_debt_repayment')
                ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('repayment_id',array_keys($salesIds))->where('status','succeeded')
                ->field('repayment_id,debt_id,repayment_amount_cents,debt_repaid_before_cents,debt_repaid_after_cents')
                ->select()->toArray();
            foreach($repayments as $repayment){
                $id=(string)$repayment['repayment_id'];$debtId=(int)$repayment['debt_id'];
                if(!isset($salesIds[$id])||$debtId<=0)continue;
                $dimensions[$id]=['debt_id'=>$debtId,'amount_cents'=>(int)$repayment['repayment_amount_cents'],
                    'before_cents'=>(int)$repayment['debt_repaid_before_cents'],'after_cents'=>(int)$repayment['debt_repaid_after_cents']];
                $debtIds[$debtId]=true;
            }
        }
        if($rechargeIds!==[]){
            $repayments=Db::name('cashier_v3_recharge_debt_repayment')
                ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('repayment_id',array_keys($rechargeIds))->where('status','succeeded')
                ->field('repayment_id,amount_cents')->select()->toArray();
            foreach($repayments as $repayment){
                $id=(string)$repayment['repayment_id'];
                if(isset($rechargeIds[$id]))$dimensions[$id]=['item_name'=>'储值充值',
                    'category_weights'=>[0=>max(1,(int)$repayment['amount_cents'])],'category_paths'=>[0=>'']];
            }
        }
        if($debtIds!==[])$dimensions=$this->salarySalesDebtSupplementDimensions($dimensions,array_keys($debtIds));

        foreach($rows as &$row){
            $id=trim((string)($row['order_id']??''));
            $dimension=$dimensions[$id]??[];
            if($dimension===[]||trim((string)($row['sale_fact_id']??''))!=='')continue;
            // The performance fact owns repayment-store/member snapshots. Debt items
            // only provide product identity and cannot move this business to its source store.
            $row['member_id']=(int)($row['pf_member_id']??0);
            $row['member_name']=(string)($row['pf_member_name']??'');
            $row['store_name']=(string)($row['pf_store_name']??'');
            $row['company_name']=(string)($row['pf_company_name']??'');
            $row['source_type']=(string)($row['source_document_type']??'');
            $row['product_id']=0;
            $row['item_name']=(string)($dimension['item_name']??'销售欠款补交');
            $row['supplement_category_weights']=(array)($dimension['category_weights']??[0=>1]);
            $row['supplement_category_paths']=(array)($dimension['category_paths']??[0=>'']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Reconstruct one sales-debt repayment's item proportions from its cumulative
     * before/after amounts. Historical cards use their real component products;
     * a card's outer category must not replace the categories of its projects.
     */
    private function salarySalesDebtSupplementDimensions(array $dimensions,array $debtIds):array
    {
        // Debt items keep historical store-product IDs even after catalog deletion;
        // the platform link is still authoritative for the repayment's category.
        $items=Db::name('store_debt_item')->alias('i')
            ->join('store_debt debt','debt.id=i.debt_id')
            ->whereIn('i.debt_id',$debtIds)
            ->field('i.id,i.debt_id,i.order_id,i.product_id,i.product_type,i.debt_amount,debt.store_id,debt.uid member_id')
            ->order('i.id asc')->select()->toArray();
        $itemsByDebt=[];$categoryLinks=[];$cardOrderIds=[];$cardItemsByOrder=[];
        foreach($items as $item){
            $debtId=(int)$item['debt_id'];$itemId=(int)$item['id'];
            $itemsByDebt[$debtId][]=$item;
            if((int)$item['product_type']===5){
                $orderId=(int)$item['order_id'];
                if($orderId>0){$cardOrderIds[$orderId]=true;$cardItemsByOrder[$orderId]=($cardItemsByOrder[$orderId]??0)+1;}
            }elseif((int)$item['product_id']>0){
                $categoryLinks['item:'.$itemId]=['product_id'=>(int)$item['product_id'],
                    'store_id'=>(int)$item['store_id']];
            }
        }
        $componentsByOrder=[];$orderOwners=[];$cardHeaders=[];
        foreach($items as $item)if((int)$item['product_type']===5){
            $orderOwners[(int)$item['order_id']][(int)$item['store_id'].':'.(int)$item['member_id']]=true;
        }
        if($cardOrderIds!==[]){
            $components=Db::name('store_order_cart_info')->alias('c')
                ->join('store_order o','o.id=c.oid')
                ->whereIn('c.oid',array_keys($cardOrderIds))->where('c.cart_type',2)->where('c.product_type',6)
                ->field('c.id,c.oid,c.product_id,c.total_price,c.pay_price,o.store_id,o.uid member_id')
                ->order('c.id asc')->select()->toArray();
            foreach($components as $component){
                if(!isset($orderOwners[(int)$component['oid']][(int)$component['store_id'].':'.(int)$component['member_id']]))continue;
                $componentsByOrder[(int)$component['oid']][]=$component;
                $categoryLinks['component:'.(int)$component['id']]=['product_id'=>(int)$component['product_id'],
                    'store_id'=>(int)$component['store_id']];
            }
        }
        // Match the one original card header, not merely every project under an order.
        if($cardOrderIds!==[]){
            foreach(Db::name('store_order_cart_info')->whereIn('oid',array_keys($cardOrderIds))
                ->where('product_type',5)->where('cart_type','<>',2)->field('oid,product_id')->select()->toArray() as $header)
                $cardHeaders[(int)$header['oid']][]=(int)$header['product_id'];
            foreach($items as $item){
                if((int)$item['product_type']!==5)continue;
                $orderId=(int)$item['order_id'];$headers=$cardHeaders[$orderId]??[];
                if(count($headers)>1 || ($headers!==[] && $headers[0] !== (int)$item['product_id']))unset($componentsByOrder[$orderId]);
            }
        }
        // Historical debt order IDs can differ from the actual card issuance order.
        // Query issued holders only for cards without an unambiguous original project link.
        $unresolvedCardItems=[];
        foreach($items as $item){
            if((int)$item['product_type']!==5)continue;
            $orderId=(int)$item['order_id'];
            if(($cardItemsByOrder[$orderId]??0)!==1 || ($componentsByOrder[$orderId]??[])===[])
                $unresolvedCardItems[]=$item;
        }
        $issuedComponents=(new CashierV3MemberDebtProjectionServices())->issuedCardComponentsForDebtItems($unresolvedCardItems);
        foreach($issuedComponents as $components){
            foreach($components as $component){
                $categoryLinks['component:'.(int)$component['id']]=['product_id'=>(int)$component['product_id'],
                    'store_id'=>(int)$component['store_id']];
            }
        }
        $categories=$this->salaryCurrentProductCategories($categoryLinks);
        $names=(new CashierV3MemberDebtProjectionServices())->summariesForDebts($debtIds);

        // New repayments freeze the exact cash share per item; legacy cumulative reconstruction
        // remains read-only fallback only for historical rows without a settlement snapshot.
        $settlementItems=[];
        foreach(Db::name(\app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices::TABLE)
            ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('repayment_id',array_keys($dimensions))
            ->field('repayment_id,debt_item_id,amount_cents,balance_amount_cents')->select()->toArray() as $settlement){
            $settlementItems[(string)$settlement['repayment_id']][(int)$settlement['debt_item_id']]
                =(int)$settlement['amount_cents']-(int)$settlement['balance_amount_cents'];
        }

        foreach($dimensions as $repaymentId=>&$dimension){
            $debtId=(int)($dimension['debt_id']??0);
            if($debtId<=0)continue;
            $debtItems=$itemsByDebt[$debtId]??[];
            if($debtItems===[]){$dimension['item_name']=$names[$debtId]??'销售欠款补交';$dimension['category_weights']=[0=>1];$dimension['category_paths']=[0=>''];continue;}
            $before=CashierV3DebtRepaymentServices::proportionalCumulativeAllocation((int)$dimension['before_cents'],$debtItems);
            $after=CashierV3DebtRepaymentServices::proportionalCumulativeAllocation((int)$dimension['after_cents'],$debtItems);
            $weights=[];$paths=[];
            foreach($debtItems as $item){
                $itemId=(int)$item['id'];$itemAmount=isset($settlementItems[$repaymentId])
                    ?(int)($settlementItems[$repaymentId][$itemId]??0):(int)($after[$itemId]??0)-(int)($before[$itemId]??0);
                if($itemAmount<=0)continue;
                if((int)$item['product_type']!==5){
                    $category=$categories['item:'.$itemId]??[];$categoryId=(int)($category['id']??0);
                    $weights[$categoryId]=($weights[$categoryId]??0)+$itemAmount;$paths[$categoryId]=(string)($category['path']??'');
                    continue;
                }
                $orderId=(int)$item['order_id'];
                // An old order containing several cards has no immutable component-to-card
                // link. Keep it unclassified instead of assigning another card's projects.
                $components=($cardItemsByOrder[$orderId]??0)===1?($componentsByOrder[$orderId]??[]):[];
                if($components===[])$components=$issuedComponents[$itemId]??[];
                $componentWeights=[];$componentPaths=[];
                foreach($components as $component){
                    $key='component:'.(int)$component['id'];$category=$categories[$key]??[];
                    $categoryId=(int)($category['id']??0);
                    $weight=$this->cents((string)($component['total_price']??'0'));
                    if($weight<=0)$weight=$this->cents((string)($component['pay_price']??'0'));
                    if($weight<=0)$weight=1;
                    $componentWeights[$categoryId]=($componentWeights[$categoryId]??0)+$weight;
                    $componentPaths[$categoryId]=(string)($category['path']??'');
                }
                $parts=$this->salaryCategoryAllocationFromWeights($itemAmount,$componentWeights,$componentPaths);
                foreach($parts as $categoryId=>$part){
                    $weights[$categoryId]=($weights[$categoryId]??0)+(int)$part['cents'];
                    $paths[$categoryId]=(string)$part['path'];
                }
            }
            $dimension['item_name']=$names[$debtId]??'销售欠款补交';
            $dimension['category_weights']=$weights?:[0=>max(1,(int)($dimension['amount_cents']??1))];
            $dimension['category_paths']=$paths?:[0=>''];
        }
        unset($dimension);
        return $dimensions;
    }

    /** Allocate signed employee cents by stable category weights; every cent remains auditable. */
    private function salaryCategoryAllocationFromWeights(int $amount,array $weights,array $paths):array
    {
        $weights=array_filter(array_map('intval',$weights),static fn(int $weight):bool=>$weight>0);
        if($weights===[])return [0=>['cents'=>$amount,'path'=>'']];
        ksort($weights,SORT_NUMERIC);$total=array_sum($weights);$absolute=abs($amount);$sign=$amount<0?-1:1;
        $allocated=0;$remainders=[];$result=[];
        foreach($weights as $categoryId=>$weight){
            $product=$absolute*$weight;$part=intdiv($product,$total);$allocated+=$part;
            $remainders[(int)$categoryId]=$product%$total;
            $result[(int)$categoryId]=['cents'=>$sign*$part,'path'=>(string)($paths[$categoryId]??'')];
        }
        $ids=array_keys($weights);
        usort($ids,static function(int $left,int $right)use($remainders):int{
            $cmp=$remainders[$right]<=>$remainders[$left];return $cmp!==0?$cmp:$left<=>$right;
        });
        for($index=0;$index<$absolute-$allocated;$index++)$result[$ids[$index]]['cents']+=$sign;
        ksort($result,SORT_NUMERIC);return $result;
    }

    /**
     * Any platform category still in the database defines current columns,
     * regardless of catalogue visibility. No historical snapshot supplies classification.
     *
     * @return array{columns:array<int,array>,targets:array<int|string,string>}
     */
    private function salaryCategoryDefinitions(array $observed): array
    {
        $configured=Db::name('store_product_category')->where('type',0)->where('relation_id',0)
            ->field('id,pid,cate_name')->select()->toArray();
        return $this->buildSalaryCategoryDefinitions($configured,$observed);
    }

    /** Pure category projection shared by the query and its regression test. */
    private function buildSalaryCategoryDefinitions(array $configured,array $observed): array
    {
        $byId=[];$visible=[];$visibleChildren=[];
        foreach($configured as $category){
            $id=(int)($category['id']??0);if($id<=0)continue;
            $byId[$id]=$category;
            // is_show affects catalog presentation, not classification of historical sales.
            $visible[$id]=true;
        }
        foreach(array_keys($visible) as $id){
            $parent=(int)($byId[$id]['pid']??0);
            if($parent>0 && (int)($byId[$parent]['pid']??-1)===0)$visibleChildren[$parent]=true;
        }
        $definitions=[];$targets=[];
        foreach(array_keys($visible) as $id){
            $chain=$this->salaryCategoryChain($id,$byId);
            if($chain===[])continue;
            $root=(int)$chain[0]['id'];$second=(int)($chain[1]['id']??0);
            if($second===0 && isset($visibleChildren[$root]))continue;
            $effective=$second>0 && isset($visible[$second])?$second:$root;
            if(!isset($visible[$effective]) || isset($definitions[$effective]))continue;
            $label=$this->salaryCategoryLabel($chain,$effective);
            $definitions[$effective]=['key'=>'salary_category_cash_'.$effective,'label'=>$label,'id'=>$effective];
        }
        foreach($observed as $rawId=>$path){
            $id=(int)$rawId;$chain=$id>0?$this->salaryCategoryChain($id,$byId):[];
            // 现行商品维度不恢复已经失效的旧分类名；未知/断链 ID 一律归入未分类。
            if($chain===[]){
                $key='salary_category_cash_unclassified';
                $definitions[$key]=['key'=>$key,'label'=>'未分类','id'=>0];$targets[$rawId]=$key;
                continue;
            }
            $root=(int)($chain[0]['id']??0);$second=(int)($chain[1]['id']??0);
            $effective=$second>0 && isset($visible[$second])?$second:$root;
            if($id>0 && $effective>0 && isset($definitions[$effective])
                && !($second===0 && isset($visibleChildren[$root]))){
                $targets[$rawId]=$definitions[$effective]['key'];continue;
            }
            $label=$this->salaryTwoLevelPath(implode('/',array_column($chain,'cate_name')));
            if($second===0 && $root>0 && isset($visibleChildren[$root]))$label.='/未细分';
            $key=$id>0?'salary_category_cash_historical_'.$id:'salary_category_cash_unclassified';
            $definitions[$key]=['key'=>$key,'label'=>$label,'id'=>$id];$targets[$rawId]=$key;
        }
        uasort($definitions,static fn(array $a,array $b):int=>strcmp($a['label'],$b['label'])?:strcmp($a['key'],$b['key']));
        $columns=[];
        foreach($definitions as $definition){
            $explanation=$definition['key']==='salary_category_cash_unclassified'
                ? '关联的平台商品没有有效当前分类的销售业绩及退款扣减；不按名称或旧快照猜填。'
                : '按关联平台商品当前分类统计销售业绩减退款扣减；卡项按卡内项目的平台商品分类和原有金额比例拆分，原始金额不变。';
            $columns[]=$this->column($definition['key'],$definition['label'],$explanation,true,false,140,[],'商品分类业绩');
        }
        return ['columns'=>$columns,'targets'=>$targets];
    }

    /** Build a bounded current ancestry path; broken or cyclic configuration is not a valid category. */
    private function salaryCategoryChain(int $id,array $byId): array
    {
        $chain=[];$seen=[];
        while($id>0 && isset($byId[$id]) && !isset($seen[$id]) && count($chain)<12){
            $seen[$id]=true;$chain[]=$byId[$id];$id=(int)($byId[$id]['pid']??0);
        }
        return $id===0?array_reverse($chain):[];
    }

    private function salaryCategoryLabel(array $chain,int $effective): string
    {
        if($chain===[])return '';
        $root=trim((string)($chain[0]['cate_name']??''));
        if($effective===(int)($chain[0]['id']??0))return $root;
        $second=$chain[1]??null;
        return $second && $effective===(int)$second['id']?$root.'/'.trim((string)$second['cate_name']):'';
    }

    private function salaryTwoLevelPath(string $path): string
    {
        $parts=array_values(array_filter(array_map('trim',preg_split('/\s*\/\s*/u',$path)?:[]),static fn(string $part):bool=>$part!==''));
        return implode('/',array_slice($parts,0,2));
    }

    private function salaryFacts(array $stores,array $range):array
    {
        $query=Db::name('cashier_v3_performance_fact')->alias('pf')
            ->leftJoin('cashier_v3_entitlement_service_fact sv','sv.tenant_id=pf.tenant_id AND sv.checkout_request_id=pf.checkout_request_id AND sv.source_line_id=pf.source_line_id AND sv.service_status=\'completed\'')
            ->where('pf.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('pf.store_id',$stores)->whereBetween('pf.business_date',[$range['start'],$range['end']])
            ->where('pf.performance_type','labor_performance_allocated')->where('pf.status','effective')
            ->field('pf.id,pf.fact_id,pf.reversal_of,pf.fact_id row_key,pf.store_id,pf.employee_id,pf.employee_name_snapshot employee_name,pf.business_date,pf.checkout_request_id,pf.source_line_id,pf.amount_cents,pf.labor_fee_amount_cents,pf.project_count_half_units,pf.project_count_decimal,pf.rule_code_snapshot,pf.fact_direction,sv.service_fact_id,sv.member_id,sv.service_object,sv.member_name_snapshot member_name,sv.store_name_snapshot store_name,sv.organization_name_snapshot company_name,sv.quantity,sv.project_count,sv.project_name_snapshot item_name,sv.project_category_name_snapshot category_path,sv.source_document_type source_type')
            ->order('pf.id','asc');
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query, 'pf.tenant_id', 'pf.order_id');
        $normal->excludeVoidedSalesOrderServices($query, 'pf');
        $rows=$query->select()->toArray();
        $reversed=$this->reversedPerformanceFactIds($rows);
        $grouped=[];
        foreach($rows as $row){
            if((string)$row['fact_direction']!=='forward'||isset($reversed[(string)$row['fact_id']]))continue;
            $key=(int)$row['store_id'].'|'.(int)$row['employee_id'].'|'.(string)$row['checkout_request_id'].'|'.(string)$row['source_line_id'];
            if(!isset($grouped[$key])){
                $grouped[$key]=$row;
                $grouped[$key]['amount_cents']=0;
                $grouped[$key]['labor_fee_amount_cents']=0;
                $grouped[$key]['project_count_half_units']=0;
                $grouped[$key]['project_count_decimal_micros']=0;
                $grouped[$key]['legacy_project_count_half_units']=0;
                $grouped[$key]['has_project_count_decimal']=false;
                $grouped[$key]['has_explicit_project_count']=false;
            }
            $grouped[$key]['amount_cents']+=(int)$row['amount_cents'];
            $grouped[$key]['labor_fee_amount_cents']+=(int)$row['labor_fee_amount_cents'];
            $grouped[$key]['project_count_half_units']+=(int)$row['project_count_half_units'];
            if($row['project_count_decimal']!==null&&(string)$row['project_count_decimal']!==''){
                // 新事实以员工服务项目数小数快照为准；即使明确保存 0，也不能退回旧半项目数或销售数量。
                $grouped[$key]['project_count_decimal_micros']+=$this->projectCountMicros((string)$row['project_count_decimal']);
                $grouped[$key]['has_project_count_decimal']=true;
            }else{
                // 同一服务行混有新旧事实时，仅无小数快照的旧事实使用半项目数，避免覆盖或重复计算。
                $grouped[$key]['legacy_project_count_half_units']+=(int)$row['project_count_half_units'];
            }
            $grouped[$key]['has_explicit_project_count']=$grouped[$key]['has_explicit_project_count']
                ||(string)$row['rule_code_snapshot']==='SERVICE-RECORD-CRAFTSMAN-ADJUST-V1'
                ||(int)$row['project_count_half_units']!==0
                ||($row['project_count_decimal']!==null&&(string)$row['project_count_decimal']!=='');
            foreach(['row_key','service_fact_id','employee_name','member_id','service_object','member_name','store_name','company_name','item_name','category_path','source_type'] as $field)$grouped[$key][$field]=$row[$field]??$grouped[$key][$field]??'';
        }
        $lineGroups=[];
        foreach($grouped as $key=>$row){$lineKey=(int)$row['store_id'].'|'.(string)$row['checkout_request_id'].'|'.(string)$row['source_line_id'];$lineGroups[$lineKey][]=$key;}
        foreach($lineGroups as $keys){
            $hasExplicitProjectCount=false;
            foreach($keys as $key)$hasExplicitProjectCount=$hasExplicitProjectCount||!empty($grouped[$key]['has_explicit_project_count']);
            if($hasExplicitProjectCount)continue;
            $sample=$grouped[$keys[0]];
            $legacyProjectCount=(int)($sample['project_count'] ?? 0);
            if($legacyProjectCount<=0)$legacyProjectCount=(int)($sample['quantity'] ?? 0);
            $totalHalfUnits=max(0,$legacyProjectCount)*2;
            $base=intdiv($totalHalfUnits,count($keys));$remainder=$totalHalfUnits-$base*count($keys);
            foreach($keys as $index=>$key)$grouped[$key]['project_count_half_units']=$base+($index>=count($keys)-$remainder?1:0);
        }
        // 现金业绩与服务/手工事实分开记账：纯销售订单没有 labor 行，不能因此从薪资明细中消失。
        // 先按同一业务行、同一员工合并销售事实；若该员工同时参与服务，则与已有 labor 行合并，避免重复展示。
        // 销售事实只提供实际商品 ID 和历史金额；薪资分类统一读取该商品当前配置，不再读分类快照。
        $salesQuery=Db::name('cashier_v3_performance_fact')->alias('pf')
            ->leftJoin('cashier_v3_sale_fact s','s.tenant_id=pf.tenant_id AND s.checkout_request_id=pf.checkout_request_id AND s.source_line_id=pf.source_line_id AND s.fact_direction=\'forward\' AND s.status=\'effective\'')
            ->leftJoin('cashier_v3_report_sale_dimension_fact d','d.tenant_id=pf.tenant_id AND d.sale_fact_id=s.fact_id')
            ->where('pf.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('pf.store_id',$stores)->whereBetween('pf.business_date',[$range['start'],$range['end']])
            ->where('pf.performance_type','sales_performance_allocated')->where('pf.status','effective')
            ->where('pf.employee_id','>',0)
            ->field('pf.id,pf.fact_id,pf.reversal_of,pf.fact_direction,pf.role_snapshot,pf.store_id,pf.employee_id,pf.employee_name_snapshot employee_name,pf.business_date,pf.checkout_request_id,pf.order_id,pf.source_document_type,pf.source_line_id,pf.amount_cents,pf.member_id pf_member_id,pf.member_name_snapshot pf_member_name,pf.store_name_snapshot pf_store_name,pf.organization_name_snapshot pf_company_name,s.fact_id sale_fact_id,s.member_id,s.member_name_snapshot member_name,s.store_name_snapshot store_name,s.organization_name_snapshot company_name,s.source_type,s.item_id product_id,d.item_name_snapshot item_name')
            ->order('pf.id','asc');
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($salesQuery, 'pf.tenant_id', 'pf.order_id');
        $normal->excludeVoidedRechargeOrderFacts($salesQuery, 'pf.tenant_id', 'pf.order_id');
        $normal->excludeVoidedSupplementPaymentFacts($salesQuery, 'pf.tenant_id', 'pf.store_id', 'pf.order_id');
        $salesRows=$this->salarySupplementSalesRows($salesQuery->select()->toArray());
        $salesRows=$this->salaryCurrentSaleCategories($salesRows);
        $cardAllocations=$this->salaryCardCategoryAllocations($salesRows);
        $salesReversed=$this->reversedPerformanceFactIds($salesRows);
        $salesGrouped=[];
        foreach($salesRows as $sale){
            if((string)$sale['fact_direction']!=='forward'||isset($salesReversed[(string)$sale['fact_id']]))continue;
            $isPresale=$this->salaryIsPresale($sale);
            $allocation=$isPresale?[]:(isset($sale['supplement_category_weights'])
                ? $this->salaryCategoryAllocationFromWeights(
                    (int)$sale['amount_cents'],
                    (array)$sale['supplement_category_weights'],
                    (array)($sale['supplement_category_paths']??[])
                )
                : $this->salarySaleCategoryAmounts($sale,$cardAllocations[(string)($sale['sale_fact_id']??'')]??[]));
            if(!$isPresale&&((string)$sale['source_type']==='card'||isset($sale['supplement_category_weights'])))
                $sale['category_path']=$this->salaryAllocationCategoryPath($allocation);
            // A presale salesperson's cash is an independent reporting component.
            // Keep it off the service row so service counts and consumption retain their product category.
            $salesKey=(int)$sale['store_id'].'|'.(int)$sale['employee_id'].'|'.(string)$sale['checkout_request_id'].'|'.(string)$sale['source_line_id'].($isPresale?'|presale':'');
            if(!isset($salesGrouped[$salesKey])){$salesGrouped[$salesKey]=$sale;$salesGrouped[$salesKey]['amount_cents']=0;$salesGrouped[$salesKey]['presale_cash_cents']=0;$salesGrouped[$salesKey]['sales_category_cents']=[];$salesGrouped[$salesKey]['sales_category_paths']=[];}
            $salesGrouped[$salesKey]['amount_cents']+=(int)$sale['amount_cents'];
            if($isPresale)$salesGrouped[$salesKey]['presale_cash_cents']+=(int)$sale['amount_cents'];
            foreach($allocation as $categoryId=>$part){
                $salesGrouped[$salesKey]['sales_category_cents'][$categoryId]=($salesGrouped[$salesKey]['sales_category_cents'][$categoryId]??0)+(int)$part['cents'];
                $salesGrouped[$salesKey]['sales_category_paths'][$categoryId]=(string)$part['path'];
            }
        }
        foreach($salesGrouped as $salesKey=>$sale){
            if(isset($grouped[$salesKey])){
                $grouped[$salesKey]['cash_cents']=($grouped[$salesKey]['cash_cents']??0)+(int)$sale['amount_cents'];
                $grouped[$salesKey]['sales_category_cents']=$sale['sales_category_cents'];
                $grouped[$salesKey]['sales_category_paths']=$sale['sales_category_paths'];
                continue;
            }
            $sale['row_key']=(string)($sale['fact_id']??'');
            $sale['labor_fee_amount_cents']=0;$sale['project_count_half_units']=0;$sale['project_count_decimal_micros']=0;$sale['has_project_count_decimal']=false;$sale['has_explicit_project_count']=true;
            // 销售事实只产生现金业绩，不产生服务消耗；不能把销售金额复用为消耗金额。
            $sale['consumption_cents']=0;
            $sale['cash_cents']=(int)$sale['amount_cents'];$sale['member_id']=(int)($sale['member_id']??0);
            $sale['member_name']=(string)($sale['member_name']??'');$sale['store_name']=(string)($sale['store_name']??'');
            $sale['company_name']=(string)($sale['company_name']??'');$sale['item_name']=(string)($sale['item_name']??'');
            $sale['category_path']=(string)($sale['category_path']??'');$sale['source_type']=(string)($sale['source_type']??'');
            $grouped[$salesKey]=$sale;
        }
        // 退款分配是独立负向业绩，按退款业务日计入员工薪资；不冲销或改写原销售人的正向分配。
        foreach($this->salaryRefundSalespersonFacts($stores,$range) as $refund){
            $key='refund|'.(string)$refund['row_key'];
            $grouped[$key]=$refund;
        }
        $out=[];
        foreach($grouped as $row){
            $halfUnits=(int)$row['project_count_half_units'];
            $row['project_count']=$this->projectCountText(!empty($row['has_project_count_decimal'])
                ? (int)$row['project_count_decimal_micros']+(int)$row['legacy_project_count_half_units']*500000
                : $halfUnits*500000);
            $row['consumption_cents']=array_key_exists('consumption_cents',$row)
                ? (int)$row['consumption_cents']
                : (int)$row['amount_cents'];
            $row['labor_fee_cents']=(int)$row['labor_fee_amount_cents'];
            $row['cash_cents']=(int)($row['cash_cents']??0);
            $row['item_name']=(string)($row['item_name']??'');$row['category_path']=(string)($row['category_path']??'');
            // A completed service with a zero amount still contributes to
            // service visits/people.  Pure empty sales rows remain hidden.
            if((int)$row['consumption_cents']===0&&(int)$row['labor_fee_cents']===0&&(float)$row['project_count']===0.0&&(int)$row['cash_cents']===0&&trim((string)($row['service_fact_id'] ?? ''))==='')continue;
            $out[]=$row;
        }
        return $out;
    }

    /** 退款人员事实按退款操作定位；分类复用已核实的退款项目身份，无法核实时保留未分类。 */
    private function salaryRefundSalespersonFacts(array $stores,array $range):array
    {
        $rows=Db::name('cashier_v3_performance_fact')->alias('pf')
            ->leftJoin('cashier_v3_order_lifecycle_operation refund_op',
                "refund_op.tenant_id=pf.tenant_id AND refund_op.store_id=pf.store_id AND refund_op.operation_id=pf.order_id AND refund_op.operation_type='refund' AND refund_op.status='succeeded'")
            ->leftJoin('cashier_v3_refund_adjustment ra','ra.tenant_id=pf.tenant_id AND ra.operation_id=pf.order_id AND ra.status=\'effective\'')
            ->leftJoin('cashier_v3_order_lifecycle_operation op',"op.tenant_id=pf.tenant_id AND op.operation_id=pf.order_id AND op.source_type='imported_card' AND op.operation_type='refund' AND op.status='succeeded'")
            ->where('pf.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('pf.store_id',$stores)->whereBetween('pf.business_date',[$range['start'],$range['end']])
            ->where('pf.performance_type','refund_sales_deduction')->where('pf.fact_direction','forward')
            ->where('pf.status','effective')->where('pf.employee_id','>',0)
            ->whereRaw('(ra.id IS NOT NULL OR op.id IS NOT NULL)')
            ->field('pf.order_id refund_operation_id,refund_op.source_type refund_source_type,refund_op.source_order_id refund_source_order_id,pf.fact_id,pf.fact_direction,pf.reversal_of,pf.role_snapshot,pf.store_id,pf.employee_id,pf.employee_name_snapshot employee_name,pf.business_date,pf.checkout_request_id,pf.source_line_id,pf.amount_cents,pf.member_id,pf.member_name_snapshot member_name,pf.store_name_snapshot store_name,ra.target_name_snapshot item_name,ra.source_sales_order_id,ra.source_sales_line_id,ra.target_type,ra.catalog_item_id,ra.catalog_kind,ra.card_holder_id,ra.card_component_id,op.request_json imported_request_json,op.source_order_no_snapshot imported_source_no,op.operation_id imported_operation_id')
            ->order('pf.id','asc')->select()->toArray();
        $reversed=$this->reversedPerformanceFactIds($rows);
        $saleLineIds=array_values(array_unique(array_filter(array_column($rows,'source_sales_line_id'))));
        $saleDimensions=[];$cardFacts=[];
        foreach(array_chunk($saleLineIds,300) as $lineIds){
            $sales=Db::name('cashier_v3_sale_fact')->alias('s')
                ->where('s.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('s.store_id',$stores)->whereIn('s.source_line_id',$lineIds)->where('s.fact_direction','forward')->where('s.status','effective')
                ->field('s.fact_id sale_fact_id,s.store_id,s.order_id,s.source_line_id,s.source_type,s.item_id product_id')
                ->select()->toArray();
            $sales=$this->salaryCurrentSaleCategories($sales);
            foreach($sales as $sale)$saleDimensions[(string)$sale['order_id'].'|'.(string)$sale['source_line_id']]=$sale;
            $cardFacts=array_merge($cardFacts,$this->salaryCardCategoryAllocations($sales));
        }
        $catalogCategories=$this->salaryRefundCatalogCategories($rows,$stores);
        $refundCardCategories=$this->salaryRefundCardCategories($rows);
        $out=[];
        foreach($rows as $row){
            if(isset($reversed[(string)$row['fact_id']]))continue;
            $isPresale=$this->salaryIsPresale($row);
            if((string)($row['imported_operation_id']??'')!==''){
                $request=json_decode((string)($row['imported_request_json']??''),true);
                $row['item_name']=(string)(is_array($request)?($request['cardName']??''):'');
                if($row['item_name']==='')$row['item_name']=(string)($row['imported_source_no']??'');
            }
            $source=$saleDimensions[(string)$row['source_sales_order_id'].'|'.(string)$row['source_sales_line_id']]??[];
            // 无原单退款的 catalog_item_id 是 SKU ID，不是商品 ID；不能用同号商品或退款名称猜分类。
            // 原销售事实同样已经过当前商品分类读取；来源不同不能产生另一套分类口径。
            if($source===[]){
                $category=$catalogCategories[(int)$row['store_id'].'|'.(int)$row['catalog_item_id']]??[];
                if($category!==[])$source=['source_type'=>'product','category_id'=>$category['id'],'category_path'=>$category['path']];
            }
            $sale=$source+['amount_cents'=>(int)$row['amount_cents'],'source_type'=>'','category_id'=>0,'category_path'=>''];
            $sale['amount_cents']=(int)$row['amount_cents'];
            $allocation=$isPresale?[]:$this->salarySaleCategoryAmounts($sale,$cardFacts[(string)($source['sale_fact_id']??'')]??[]);
            if (!$isPresale && ($row['target_type'] ?? '') === 'card') {
                // A component refund cannot inherit every category of the original card sale.
                $parts = $refundCardCategories[(string)$row['refund_operation_id']] ?? [];
                $allocation = $this->salaryVerifiedRefundAllocation((int)$row['amount_cents'], $parts);
            }
            $categoryCents=[];$categoryPaths=[];
            foreach($allocation as $categoryId=>$part){$categoryCents[$categoryId]=(int)$part['cents'];$categoryPaths[$categoryId]=(string)$part['path'];}
            $row['row_key']=(string)$row['fact_id'];
            $row['service_fact_id']='';$row['service_object']='';
            $row['source_type']=(string)($row['imported_operation_id']??'')!==''?'imported_card_refund':'manual_refund';
            $row['category_path']=$this->salaryAllocationCategoryPath($allocation);
            $row['sales_category_cents']=$categoryCents;$row['sales_category_paths']=$categoryPaths;
            $row['presale_cash_cents']=$isPresale?(int)$row['amount_cents']:0;
            $row['cash_cents']=(int)$row['amount_cents'];$row['consumption_cents']=0;
            $row['labor_fee_amount_cents']=0;$row['project_count_half_units']=0;
            $row['project_count_decimal_micros']=0;$row['legacy_project_count_half_units']=0;
            $row['has_project_count_decimal']=false;$row['has_explicit_project_count']=true;
            $out[]=$row;
        }
        return $out;
    }

    /** 无原单产品/项目退款只通过所选 SKU 关联本门店商品；卡项不能用外层分类替代卡内项目分摊。 */
    private function salaryRefundCatalogCategories(array $rows,array $stores):array
    {
        $links=[];
        foreach($rows as $row){
            $id=(int)($row['catalog_item_id']??0);$store=(int)($row['store_id']??0);
            if(($row['target_type']??'')!=='catalog' || !in_array($row['catalog_kind']??'',['产品','项目'],true)
                || $id<=0 || !in_array($store,$stores,true))continue;
            $links[$store.'|'.$id]=['store_id'=>$store,'sku_id'=>$id];
        }
        // Soft deletion removes an item from sale, not its historical identity or refund category.
        return array_filter((new StoreReportCurrentProductCategoryServices())->resolveSkus($links),
            static fn(array $category): bool => in_array((int)$category['product_type'], [0,4,6], true));
    }

    /** Verify the same holder/member/store/original-order chain used by order and member reports. */
    private function salaryRefundCardCategories(array $rows): array
    {
        $links = [];
        foreach ($rows as $row) {
            if (($row['target_type'] ?? '') !== 'card') continue;
            $links[] = ['operation_id' => (string)$row['refund_operation_id'],
                'source_type' => (string)($row['refund_source_type'] ?? ''),
                'source_order_id' => (string)($row['refund_source_order_id'] ?? ''),
                'store_id' => (int)$row['store_id'], 'member_id' => (int)$row['member_id'],
                'refund_card_holder_id' => (int)$row['card_holder_id'],
                'card_component_id' => (int)$row['card_component_id']];
        }
        return (new StoreRefundCurrentCategoryServices())->resolve($links);
    }

    /** Preserve each employee's negative cents; multi-category refunds need verified original weights. */
    private function salaryVerifiedRefundAllocation(int $amount, array $categories): array
    {
        if (count($categories) === 1) return [(int)$categories[0]['id'] =>
            ['cents' => $amount, 'path' => (string)$categories[0]['path']]];
        $parts = [];
        foreach ($categories as $category) {
            if ((int)($category['weight_cents'] ?? 0) <= 0) return [0 => ['cents' => $amount, 'path' => '未分类']];
            $parts[] = ['category_id' => (int)$category['id'], 'category_path' => (string)$category['path'],
                'sale_amount_cents' => (int)$category['weight_cents']];
        }
        return $this->salarySaleCategoryAmounts(['source_type' => 'card', 'amount_cents' => $amount], $parts);
    }

    /** 销售来源只提供实际商品 ID；充值/迁入等没有商品的事实保持空分类，不借旧快照。 */
    private function salaryCurrentSaleCategories(array $rows):array
    {
        $links=[];
        foreach($rows as $index=>$row){
            if(in_array((string)($row['source_type']??''),['project','product'],true))$links[$index]=$row;
        }
        $categories=$this->salaryCurrentProductCategories($links);
        foreach($rows as $index=>&$row){
            $row['category_id']=(int)($categories[$index]['id']??0);
            $row['category_path']=(string)($categories[$index]['path']??'');
        }
        unset($row);
        return $rows;
    }

    /** 一行卡项可含多个实际项目分类；展示已有分摊的当前路径，不读取卡项外层分类。 */
    private function salaryAllocationCategoryPath(array $allocation):string
    {
        $paths=[];
        foreach($allocation as $part){
            $path=trim((string)($part['path']??''));
            if($path!=='' && $path!=='未分类')$paths[$path]=true;
        }
        return implode('、',array_keys($paths));
    }

    /**
     * Resolve terminal reversals independently of the selected report dates.
     * A salary query for the original sales day must not revive performance
     * from an order that was successfully voided on a later day.  Candidate
     * fact IDs are tenant-scoped first, so the unrestricted reversal date
     * lookup cannot cross customer instances or widen report permissions.
     *
     * @return array<string,bool> original fact ID => reversed
     */
    private function reversedPerformanceFactIds(array $candidateRows): array
    {
        $forwardIds=[];
        foreach($candidateRows as $row){
            $factId=trim((string)($row['fact_id']??''));
            if((string)($row['fact_direction']??'')==='forward'&&$factId!=='')$forwardIds[$factId]=true;
        }
        if($forwardIds===[])return [];
        $reversed=[];
        foreach(Db::name('cashier_v3_performance_fact')
            ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('fact_direction','reversal')->where('status','effective')
            ->whereIn('reversal_of',array_keys($forwardIds))->field('reversal_of')->select()->toArray() as $row){
            $original=trim((string)($row['reversal_of']??''));
            if($original!=='')$reversed[$original]=true;
        }
        return $reversed;
    }

    /**
     * Salary detail and summary share the current company reporting dimension.
     * This only changes the displayed grouping label; business facts, store scope,
     * employee attribution and historical monetary values remain untouched.
     */
    private function salaryFactsWithReportingDimensions(array $stores, array $range): array
    {
        $facts=$this->salaryFactsWithServiceCustomerMetrics($stores,$range);
        // 所有服务（含已有旧分类）均使用实际项目当前分类；现金分类分摊由同一商品读取器产生。
        $facts=$this->salaryServiceProductCategories($facts,$stores);
        $positions=(new EmployeeCurrentPositionDimensionServices())->resolve(array_map(static function(array $fact):array{
            return ['store_id'=>(int)($fact['store_id']??0),'employee_id'=>(int)($fact['employee_id']??0)];
        },$facts));
        $dimensions=new StoreUnifiedReportOrganizationDimensionServices();
        foreach($facts as &$fact){
            $company=$dimensions->resolve('company','','',(string)$range['end'],(int)($fact['store_id']??0));
            $fact['company_name']=$company['name'];
            $fact['position_name']=$positions[(int)$fact['store_id'].'|'.(int)$fact['employee_id']]??'-';
        }
        unset($fact);
        return $facts;
    }

    /**
     * 所有服务按稳定项目 ID 关联现行商品资料；旧分类快照仅保留在事实中，不参与薪资分类展示。
     * 只更新查询投影，不能写回事实或改变金额/次数；批量门店校验防止跨店同名商品借分类。
     */
    private function salaryServiceProductCategories(array $facts,array $stores):array
    {
        $serviceIds=[];
        foreach($facts as $fact){
            $id=trim((string)($fact['service_fact_id']??''));
            if($id!=='' && in_array((int)($fact['store_id']??0),$stores,true))$serviceIds[$id]=true;
        }
        if($serviceIds===[])return $facts;
        $services=[];
        foreach(array_chunk(array_keys($serviceIds),300) as $chunk){
            $rows=Db::name('cashier_v3_entitlement_service_fact')
                ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('store_id',$stores)
                ->whereIn('service_fact_id',$chunk)->where('service_status','completed')
                ->field('service_fact_id,store_id,project_id product_id')->select()->toArray();
            foreach($rows as $row){
                $services[(int)$row['store_id'].'|'.(string)$row['service_fact_id']]=$row;
            }
        }
        $categories=$this->salaryCurrentProductCategories($services);
        foreach($facts as &$fact){
            if(trim((string)($fact['service_fact_id']??''))==='')continue;
            $key=(int)($fact['store_id']??0).'|'.(string)($fact['service_fact_id']??'');
            $fact['category_path']=$categories[$key]['path']??'';
        }
        unset($fact);
        return $facts;
    }

    /** 薪资全部业务只沿有效商品 ID 读取平台商品当前分类；缺失分类留给独立数据修复。 */
    private function salaryCurrentProductCategories(array $links):array
    {
        return (new StoreReportCurrentProductCategoryServices())->resolve($links);
    }

    /**
     * Attach the centrally calculated customer-service metrics to the
     * employee-level salary rows.  This class deliberately does not recalculate
     * service-object rules: the shared reader is the only owner of member,
     * guest, friend-counting and employee-split semantics.
     */
    private function salaryFactsWithServiceCustomerMetrics(array $stores, array $range): array
    {
        $metrics = $this->serviceCustomerMetrics($stores, $range);
        $byLaborKey = $metrics['detail_by_labor_key'];
        $peopleByLaborKey = $metrics['people_by_labor_key'];
        $facts = $this->salaryFacts($stores, $range);
        $present = [];
        foreach ($facts as &$fact) {
            $laborKey = $this->laborMetricKey($fact);
            $present[$laborKey] = true;
            $metric = $byLaborKey[$laborKey] ?? 0;
            // The detail map uses a scalar because each stable labor line can
            // receive at most one accumulated tenth-unit visit share.
            $fact['service_visit_tenths'] = is_array($metric)
                ? (int)($metric['visit_tenths'] ?? 0)
                : (int)$metric;
            $fact['service_people_tenths'] = (int)($peopleByLaborKey[$laborKey] ?? 0);
        }
        unset($fact);
        // Zero-price completed services intentionally have no performance
        // amount/fact.  The shared reader supplies their immutable service
        // context, so they still appear as a zero-amount salary-detail row
        // carrying the service-person share.
        foreach ($metrics['detail_context_by_labor_key'] as $laborKey => $context) {
            if (isset($present[$laborKey])) continue;
            $service = (array)($context['service'] ?? []);
            $facts[] = [
                'row_key' => 'service-customer:' . (string)($service['service_fact_id'] ?? $laborKey) . ':' . (int)($context['employee_id'] ?? 0),
                'store_id' => (int)($context['store_id'] ?? 0),
                'employee_id' => (int)($context['employee_id'] ?? 0),
                'employee_name' => (string)($context['employee_name'] ?? ''),
                'business_date' => (string)($context['business_date'] ?? ''),
                'checkout_request_id' => (string)($service['request_id'] ?? ''),
                'source_line_id' => (string)($service['source_line_id'] ?? ''),
                'service_fact_id' => (string)($service['service_fact_id'] ?? ''),
                'member_id' => (int)($service['member_id'] ?? 0), 'member_name' => (string)($service['member_name'] ?? ''),
                'service_object' => strpos((string)($service['subject'] ?? ''), 'member:') === 0 ? 'self' : '',
                'store_name' => (string)($service['store_name'] ?? ''), 'company_name' => (string)($service['company_name'] ?? ''),
                'item_name' => (string)($service['item_name'] ?? ''), 'category_path' => (string)($service['category_path'] ?? ''),
                'source_type' => (string)($service['source_type'] ?? ''),
                'project_count' => 0.0, 'consumption_cents' => 0, 'cash_cents' => 0, 'labor_fee_cents' => 0,
                'service_visit_tenths' => (int)($byLaborKey[$laborKey] ?? 0),
                'service_people_tenths' => (int)($peopleByLaborKey[$laborKey] ?? 0),
            ];
        }
        return $facts;
    }

    /**
     * @return array{
     *   detail_by_labor_key:array<string,int>,
     *   people_by_labor_key:array<string,int>,
     *   detail_context_by_labor_key:array<string,array<string,mixed>>,
     *   summary_by_store_employee:array<string,array{visit_tenths:int,people_tenths:int}>
     * }
     */
    private function serviceCustomerMetrics(array $stores, array $range): array
    {
        /** @var \app\services\query\metric\ServiceCustomerMetricReadServices $reader */
        $reader = app()->make(\app\services\query\metric\ServiceCustomerMetricReadServices::class);
        $metrics = $reader->employeeMetrics(CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range);
        if (!is_array($metrics)) throw new \LogicException('服务客数读取器返回结果无效');
        return [
            'detail_by_labor_key' => is_array($metrics['detail_by_labor_key'] ?? null) ? $metrics['detail_by_labor_key'] : [],
            'people_by_labor_key' => is_array($metrics['people_by_labor_key'] ?? null) ? $metrics['people_by_labor_key'] : [],
            'detail_context_by_labor_key' => is_array($metrics['detail_context_by_labor_key'] ?? null) ? $metrics['detail_context_by_labor_key'] : [],
            'summary_by_store_employee' => is_array($metrics['summary_by_store_employee'] ?? null) ? $metrics['summary_by_store_employee'] : [],
        ];
    }

    /** Keep the report key identical to ServiceCustomerMetricReadServices. */
    private function laborMetricKey(array $fact): string
    {
        return (int)($fact['store_id'] ?? 0) . '|'
            . (int)($fact['employee_id'] ?? 0) . '|'
            . (string)($fact['checkout_request_id'] ?? '') . '|'
            . (string)($fact['source_line_id'] ?? '');
    }

    /** Only completed self-member service facts may join a visit across checkouts; IDs keep the scope exact. */
    private function salaryVisitMemberDayKey(array $fact): string
    {
        if(trim((string)($fact['service_fact_id']??''))===''
            ||strtolower(trim((string)($fact['service_object']??'')))!=='self'
            ||(int)($fact['member_id']??0)<=0)return '';
        return (int)$fact['store_id'].'|'.(int)$fact['employee_id'].'|'
            .(string)$fact['business_date'].'|'.(int)$fact['member_id'];
    }

    private function topCategory(string $path): string
    {
        $path=trim($path);if($path==='')return '未分类';$parts=preg_split('/\s*\/\s*/u',$path);return trim((string)($parts[0]??$path))?:'未分类';
    }

    private function countMembersAtLeast(array $facts,string $category,int $thresholdCents): int
    {
        $totals=[];foreach($facts as $f){if($this->topCategory((string)($f['category_path']??''))!==$category)continue;$id=(int)($f['member_id']??0);if($id>0)$totals[$id]=($totals[$id]??0)+(int)($f['amount_cents']??0);}return count(array_filter($totals,static fn(int $amount):bool=>$amount>=$thresholdCents));
    }
    private function range(array $range,array $input):array{$start=trim((string)($range['start']??$input['start_date']??''));$end=trim((string)($range['end']??$input['end_date']??''));if($start===''||$end===''){ $year=max(2000,(int)($input['year']??date('Y')));$start=$year.'-01-01';$end=$year.'-12-31'; }if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||$start>$end)throw new \InvalidArgumentException('日期范围不正确');return['start'=>$start,'end'=>$end];}
    /** 工资项目数按入库 DECIMAL(20,6) 转为百万分位整数，不能沿用旧 0.5 步长或浮点合计。 */
    private function projectCountMicros(string $value): int
    {
        $value=trim($value);
        if(!preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/D',$value,$match))throw new \InvalidArgumentException('工资项目数精度不正确');
        $whole=(int)$match[2];
        $fraction=(int)str_pad($match[3]??'',6,'0');
        $micros=$whole*1000000+$fraction;
        return $match[1]==='-'?-$micros:$micros;
    }

    /** 项目数保留实际小数位，旧半项目数、明确的 0 和冲销值使用同一输出口径。 */
    private function projectCountText(int $micros): string
    {
        $sign=$micros<0?'-':'';
        $absolute=abs($micros);
        $fraction=rtrim(str_pad((string)($absolute%1000000),6,'0',STR_PAD_LEFT),'0');
        return $sign.intdiv($absolute,1000000).($fraction!==''?'.'.$fraction:'');
    }

    private function result(string $title,array $columns,array $records,array $range):array
    {
        $summary=[];
        foreach($columns as $c){
            $key=(string)$c['key'];$summary[$key]='-';
            if(empty($c['summable']))continue;
            $sum=0;$has=false;
            $moneyKey=(bool)preg_match('/amount|cash|fee|labor|consumption|performance|target|unit_output|average_order|sales_efficiency/i',$key);
            $projectCountKey=$key==='project_count';
            $tenthUnitKey=in_array($key,['service_visit_count','service_people_count'],true);
            foreach($records as $r){
                $v=$r[$key]??null;
                if($moneyKey&&is_string($v)&&preg_match('/^-?\d+(?:\.\d{1,2})?$/',$v)){$sum+=$this->cents($v);$has=true;}
                elseif($projectCountKey&&is_numeric($v)){
                    // 页面合计必须与员工汇总同精度；逐行四舍五入到 0.5 会把 0.3 + 0.4 算成 1。
                    $sum+=$this->projectCountMicros((string)$v);$has=true;
                }
                elseif($tenthUnitKey&&is_numeric($v)){$sum+=(int)round((float)$v*10);$has=true;}
                elseif(!$moneyKey&&is_numeric($v)){$sum+=(int)$v;$has=true;}
            }
            $summary[$key]=$has?($moneyKey?$this->money($sum):($projectCountKey?$this->projectCountText($sum):($tenthUnitKey?$this->tenth($sum):(string)$sum))):($tenthUnitKey?'0.0':'0');
        }
        return ['title'=>$title,'columns'=>$columns,'records'=>array_values($records),'summary_row'=>$summary,'column_groups'=>[],'metric_version'=>self::METRIC_VERSION,'data_as_of'=>date('c'),'aggregation_status'=>'caught_up','filters'=>['start_date'=>$range['start'],'end_date'=>$range['end']],'table_layout'=>['fixed'=>true,'summary_fixed'=>true]];
    }
    private function column(string $key,string $label,string $explanation,bool $summable=false,bool $fixed=false,int $width=120,array $drilldown=[],string $group=''):array{$c=['key'=>$key,'label'=>$label,'source_explanation'=>$explanation,'summable'=>$summable,'width'=>$width];if($fixed)$c['fixed']='left';if($group!=='')$c['group_label']=$group;if($drilldown)$c['drilldown']=$drilldown;return$c;}
    /** 声明与保存接口一致的字段单位，金额由页面元转分，非金额保持原类型。 */
    private function manualColumn(string $key,string $label,string $explanation,string $group=''):array
    {
        $types = ['target_amount' => 'integer_cents', 'target_holiday_2_amount' => 'integer_cents',
            'target_holiday_3_amount' => 'integer_cents', 'post_sale_visit_target' => 'integer',
            '领取日期' => 'date', '领取数量' => 'nonnegative_integer'];
        $column = $this->column($key,$label,$explanation,false,false,120,[],$group);
        $column['manual_input'] = ['subject_type'=>'phase_six_row','field_key'=>$key,'value_type'=>$types[$key] ?? 'text'];
        return $column;
    }

    /** 按门店和明细键读回独立补充记录；空串也覆盖默认值，且不改写业务事实。 */
    private function withManualAnnotations(string $report, array $result, array $stores, bool $export): array
    {
        $manualColumns = [];
        foreach ((array)($result['columns'] ?? []) as $column) {
            if (!empty($column['manual_input'])) $manualColumns[(string)$column['key']] = $column['manual_input'];
        }
        if (!$manualColumns || empty($result['records'])) return $result;
        $keys = [];
        foreach ($result['records'] as $row) {
            $key = (string)($row['annotation_subject_key'] ?? '');
            if ($key !== '') $keys[$key] = true;
        }
        if (!$keys) return $result;
        $saved = Db::name(StoreOperationsReportAnnotationServices::ANNOTATION_TABLE)
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('report_code', $report)->where('subject_type', 'phase_six_row')
            ->whereIn('store_id', $stores)->whereIn('subject_key', array_keys($keys))
            ->field('store_id,subject_key,field_key,field_value,version')->select()->toArray();
        return $this->projectManualRows($result, $manualColumns, $saved, $export);
    }

    /** 仅用同门店同明细的白名单值覆盖，版本随行读回供并发写入校验。 */
    private function projectManualRows(array $result, array $manualColumns, array $saved, bool $export = false): array
    {
        $byRow = [];
        foreach ($saved as $item) {
            $key = (int)$item['store_id'] . '|' . (string)$item['subject_key'];
            $field = (string)$item['field_key'];
            if (isset($manualColumns[$field])) $byRow[$key][$field] = $item;
        }
        foreach ($result['records'] as &$row) {
            $key = (int)($row['store_id'] ?? 0) . '|' . (string)($row['annotation_subject_key'] ?? '');
            foreach ($manualColumns as $field => $definition) {
                $item = $byRow[$key][$field] ?? null;
                if (!array_key_exists($field, $row)) $row[$field] = '';
                $row[$field . '_version'] = $item === null ? 0 : (int)$item['version'];
                if ($item !== null) {
                    $value = (string)$item['field_value'];
                    $row[$field] = $this->manualDisplayValue($definition, $value, $export);
                }
            }
        }
        unset($row);
        return $result;
    }

    /** 金额仅在报表投影按整数元展示；无效历史文本保持原样以便排查，不能伪造 0。 */
    private function manualDisplayValue(array $definition, string $value, bool $export = false): string
    {
        if ($value === '' || ($definition['value_type'] ?? '') !== 'integer_cents'
            || !preg_match('/^-?\d+$/D', $value)) return $value;
        return $export
            ? \app\services\query\metric\MetricMoneyFormatter::exactYuan((int)$value)
            : \app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$value);
    }
    private function money(int $cents):string{$negative=$cents<0;$cents=abs($cents);$value=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);$value=rtrim(rtrim($value,'0'),'.');return($negative?'-':'').($value===''?'0':$value);}
    private function tenth(int $tenths): string { return number_format($tenths / 10, 1, '.', ''); }
    private function ratio(int $num,int $den):string{return $den===0?'-':(string)round($num*100/$den,2).'%';}
    private function cents($value):int{$text=trim((string)$value);if($text===''||$text==='-')return 0;$negative=substr($text,0,1)==='-';$text=ltrim($text,'+-');$parts=explode('.',$text,2);$whole=(int)($parts[0]??0);$fraction=str_pad(substr((string)($parts[1]??''),0,2),2,'0');$result=$whole*100+(int)$fraction;return $negative?-abs($result):$result;}
    private function healthWithStaffing(array $stores, array $range): array
    {
        $result = $this->health($stores, $range);
        $beauticianPositionId=(int)Db::name('position')->where('name','美容师')->where('status',1)->value('id');
        $versions = $beauticianPositionId>0
            ? Db::name('staffing_quota')->where('tenant_id','0')->where('scope_type','store')->whereIn('scope_id',$stores)->where('position_id',$beauticianPositionId)->column('version','scope_id')
            : [];
        foreach ($result['records'] as &$row) {
            $storeId = (int)($row['store_id'] ?? 0);
            $row['store_id'] = $storeId;
            $row['annotation_subject_type'] = 'phase_six_row';
            $row['annotation_subject_key'] = 'phase_six:health:' . $storeId;
            $row['beautician_establishment_count_version'] = (int)($versions[$storeId] ?? 0);
        }
        unset($row);
        foreach ($result['columns'] as &$column) {
            if ((string)($column['key'] ?? '') === 'beautician_establishment_count') {
                $column = $this->manualColumn('beautician_establishment_count', '美容师编制人数', '每门店唯一当前编制值，不分时间。');
            }
        }
        unset($column);
        return $result;
    }

    private function trainingV2(array $stores, array $range): array
    {
        $rows = Db::name('system_store_staff')->alias('ss')
            ->leftJoin('employee e', 'e.id=ss.employee_id')
            ->leftJoin('system_store st', 'st.id=ss.store_id')
            ->whereIn('ss.store_id', $stores)->where('ss.status', 1)->where('ss.is_del', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->field('e.id,e.name,ss.join_date,ss.mentor_employee_id,ss.store_id,st.name AS store_name')
            ->order('ss.store_id', 'asc')->order('e.id', 'asc')->select()->toArray();
        $mentors = [];
        $mentorIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['mentor_employee_id'] ?? 0), $rows))));
        if ($mentorIds) $mentors = Db::name('employee')->whereIn('id', $mentorIds)->column('name', 'id');
        $out = []; $sequence = 1; $queryEnd = (string)$range['end'];
        foreach ($rows as $row) {
            $joinDate = (string)($row['join_date'] ?? '');
            $months = $joinDate !== '' ? max(0, ((int)date('Y', strtotime($queryEnd)) - (int)date('Y', strtotime($joinDate))) * 12 + (int)date('m', strtotime($queryEnd)) - (int)date('m', strtotime($joinDate))) : 0;
            if ($joinDate !== '' && (int)date('d', strtotime($joinDate)) > 15) $months = max(0, $months - 1);
            $out[] = [
                'sequence' => $sequence++, 'store_id' => (int)$row['store_id'], 'company_name' => '',
                'store_name' => (string)$row['store_name'], 'beautician_name' => (string)$row['name'],
                'entry_time' => $joinDate, 'mentor_name' => (string)($mentors[(int)($row['mentor_employee_id'] ?? 0)] ?? ''),
                'entry_cycle' => $months . '个月',
                'entry_level' => $months < 3 ? '实习' : ($months < 6 ? '初级' : ($months < 12 ? '中级' : '高级')),
                'exam_apply_time' => '', 'exam_level' => '', 'passed_level' => '', 'skill_score' => '', 'professional_score' => '',
                'in_service_3' => '-', 'in_service_6' => '-', 'in_service_9' => '-', 'in_service_12' => '-', 'in_service_over_year' => '-',
                'annotation_subject_type' => 'phase_six_row', 'annotation_subject_key' => 'phase_six:training:' . (int)$row['id'],
            ];
        }
        return $this->result('教培员工需求统计表', [
            $this->column('sequence','序号','页面稳定行号，不作为业务主键。'), $this->column('company_name','分公司','组织中的分公司统计维度。'),
            $this->column('store_name','门店','员工组织归属。'), $this->column('beautician_name','美容师','员工档案。'),
            $this->column('entry_time','入职时间','员工正式入职日期。'), $this->manualColumn('mentor_name','师傅','门店分配师傅关系。'),
            $this->column('entry_cycle','入职周期','按查询日期和每月15日规则计算。'), $this->column('entry_level','入职等级分类','按入职周期分级。'),
            $this->manualColumn('exam_apply_time','员工申请考试时间','员工手动输入。'), $this->manualColumn('exam_level','员工申请考试级别','员工手动输入。'),
            $this->manualColumn('passed_level','对应已考等级','教培人员手动输入。'), $this->manualColumn('skill_score','技能分数','教培人员手动输入。'),
            $this->manualColumn('professional_score','专业分数','教培人员手动输入。'), $this->column('in_service_3','3月是否在职','对应观察时点的历史员工状态。'),
            $this->column('in_service_6','6月是否在职','对应观察时点的历史员工状态。'), $this->column('in_service_9','9月是否在职','对应观察时点的历史员工状态。'),
            $this->column('in_service_12','12月是否在职','对应观察时点的历史员工状态。'), $this->column('in_service_over_year','1年以上是否在职','对应观察时点的历史员工状态。'),
        ], $out, $range);
    }
}
