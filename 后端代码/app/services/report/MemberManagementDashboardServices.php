<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\query\metric\StoreCustomerVisitEligibility;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

/**
 * Phase 7 member dashboard read model.
 *
 * This service deliberately reads the immutable V3 facts. It does not infer
 * business numbers from display text or from the legacy order tables. The
 * admin and merchant controllers pass an already-authorized store scope;
 * requested stores are intersected with that scope here as a second guard.
 */
final class MemberManagementDashboardServices
{
    public const DASHBOARD_CODE = 'member_management_dashboard';
    public const METRIC_VERSION = 'member-management-dashboard-actual-v4';

    /** @return array<string,mixed> */
    public function dashboard(array $context, array $input = []): array
    {
        $scope = $this->scope($context, $input);
        $range = $this->range($input);
        $categoryId = (int)($input['category_id'] ?? 0);
        $cash = $this->cashRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryId);
        $services = $this->serviceRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryId);
        $historicalServices = $this->historicalServiceLastRows($scope['tenant_id'], $scope['store_ids'], $range['end'], $categoryId);

        $consuming = $this->memberSet($this->withoutExperience($cash), true);
        $serviceMembers = $this->memberSet($services);
        $sleeping = $this->sleepingMembers(StoreCustomerVisitEligibility::filter($historicalServices), $range['end'], 90);
        $cashAmount = $this->sum($this->withoutExperience($cash), 'amount_cents');
        // Service counts follow completed-service dates; money follows the registered
        // actual-performance business date, so a later service does not rebook a sale.
        $serviceAmount = $cashAmount;
        $projectCount = $this->projectCount($services);
        $peopleCount = count($consuming);
        $servicePeopleCount = count($serviceMembers);

        $categories = $this->categories();
        if ($categoryId > 0) $categories = array_values(array_filter($categories, static fn(array $category): bool => (int)$category['id'] === $categoryId));
        $categoryCards = [];
        foreach ($categories as $category) {
            $categoryRows = array_values(array_filter($cash, function (array $row) use ($category): bool {
                return !$this->isExperience($row) && (int)($row['category_id'] ?? 0) === (int)$category['id'];
            }));
            $categoryRows = $this->withoutExperience($categoryRows);
            $categoryCards[] = [
                'id' => (int)$category['id'],
                'name' => (string)$category['name'],
                'people' => count($this->memberSet($categoryRows)),
                'amount_cents' => $this->sum($categoryRows, 'amount_cents'),
                'average_cents' => $this->ratio($this->sum($categoryRows, 'amount_cents'), count($this->memberSet($categoryRows))),
                'share' => $cashAmount === 0 ? null : ($this->sum($categoryRows, 'amount_cents') / $cashAmount) * 100,
                'rankings' => [
                    'projects' => $this->ranking(array_values(array_filter($categoryRows, fn(array $r): bool => !$this->isProduct($r))), 'consumption'),
                    'products' => $this->ranking(array_values(array_filter($categoryRows, fn(array $r): bool => $this->isProduct($r))), 'consumption'),
                    'consumption_projects' => $this->ranking(array_values(array_filter($categoryRows, fn(array $r): bool => !$this->isProduct($r))), 'consumption'),
                    'consumption_products' => $this->ranking(array_values(array_filter($categoryRows, fn(array $r): bool => $this->isProduct($r))), 'consumption'),
                ],
            ];
        }

        return [
            'dashboard_code' => self::DASHBOARD_CODE,
            'title' => '会员看板',
            'scope' => ['store_ids' => $scope['store_ids'], 'label' => '当前权限范围', 'range' => $range],
            'filter_schema' => [
                'periods' => ['today', 'month', 'year', 'custom'],
                'categories' => array_map(static fn(array $category): array => ['id' => (int)$category['id'], 'name' => (string)$category['name']], $this->categories()),
            ],
            'table_layout' => ['fixed' => true, 'vertical_scroll' => true, 'horizontal_scroll' => false],
            'columns' => [
                ['key' => 'member.consuming_members', 'label' => '消费会员数', 'source' => '实际业绩对应销售事实，排除体验标签，会员 ID 去重'],
                ['key' => 'member.service_members', 'label' => '服务会员数', 'source' => '有效服务事实，会员 ID 去重'],
                ['key' => 'service.amount', 'label' => '实际业绩', 'source' => '同业务日期的实际业绩事实净额；服务人数按服务完成日另计'],
                ['key' => 'consumption.amount', 'label' => '实际业绩', 'source' => '实际业绩事实净额（现金业绩减现金退款）'],
            ],
            'drilldown' => ['enabled' => false, 'reason' => '会员看板当前提供指标展示，暂无已确认的精确明细下钻契约。'],
            'export' => ['enabled' => false, 'reason' => '会员看板当前无已确认的导出列契约。'],
            'tier_settings' => ['route' => '/platform/six-dimension/consumption-tiers', 'permission' => 'setting-shop-six-dimension-consumption-tier'],
            'customer_overview' => [
                'member' => [
                    'consuming_members' => $this->metric(count($consuming), '消费会员数，按会员 ID 去重。购买卡项或项目且排除体验标签。'),
                    'service_members' => $this->metric($servicePeopleCount, '有效完成服务的会员数，按会员 ID 去重。'),
                    'sleeping_members' => $this->metric(count($sleeping), '最后一次有效服务距查询结束日达到 90 天及以上，按会员 ID 去重。'),
                ],
                'consumption' => [
                    'people' => $this->metric($peopleCount, '非体验标签的消费会员数。'),
                    'amount' => $this->moneyMetric($cashAmount, '实际业绩净额，现金收款减现金退款；仅统计非体验销售及其调整。'),
                    'average' => $this->moneyMetric($this->ratio($cashAmount, $peopleCount), '实际业绩净额除以消费人数，分母为零显示 -。'),
                ],
                'service' => [
                    'people' => $this->metric($servicePeopleCount, '有效服务会员数，按会员 ID 去重。'),
                    'amount' => $this->moneyMetric($serviceAmount, '同业务日期、同权限范围的非体验销售实际业绩净额；与服务完成次数不是同一事实。'),
                    'projects' => $this->metric($projectCount, '有效服务项目事实次数，不按会员去重。'),
                    'average_amount' => $this->moneyMetric($this->ratio($serviceAmount, $servicePeopleCount), '同期实际业绩净额除以服务人数；收款日与服务日可能不同。'),
                    'average_items' => $this->metric($this->ratio($projectCount, $servicePeopleCount), '服务项目数除以服务人数。'),
                    'unit_amount' => $this->moneyMetric($this->ratio($serviceAmount, $projectCount), '同期实际业绩净额除以服务项目数；收款日与服务日可能不同。'),
                ],
            ],
            'consumption' => [
                'people' => $this->metric($peopleCount, '非体验标签的消费会员数。'),
                'amount' => $this->moneyMetric($cashAmount, '非体验销售对应的实际业绩净额。'),
                'average' => $this->moneyMetric($this->ratio($cashAmount, $peopleCount), '实际业绩净额除以消费人数。'),
                'categories' => $categoryCards,
                'rankings' => [
                    'projects' => $this->ranking(array_values(array_filter($this->withoutExperience($cash), fn(array $r): bool => !$this->isProduct($r))), 'consumption'),
                    'products' => $this->ranking(array_values(array_filter($this->withoutExperience($cash), fn(array $r): bool => $this->isProduct($r))), 'consumption'),
                    'consumption_projects' => $this->ranking(array_values(array_filter($this->withoutExperience($cash), fn(array $r): bool => !$this->isProduct($r))), 'consumption'),
                    'consumption_products' => $this->ranking(array_values(array_filter($this->withoutExperience($cash), fn(array $r): bool => $this->isProduct($r))), 'consumption'),
                ],
            ],
            'visit_bands' => $this->visitBands($services, $historicalServices, $range['start'], $range['end']),
            'tiers' => $this->tiers($cash, $scope['tenant_id']),
            'metric_version' => self::METRIC_VERSION,
            'data_as_of' => date('Y-m-d H:i:s'),
            'aggregation_caught_up' => true,
            'aggregation_status' => '统一事实层实时读取。',
            'field_explanations' => $this->fieldExplanations(),
        ];
    }

    /** @return array{tenant_id:string,store_ids:array<int,int>} */
    private function scope(array $context, array $input): array
    {
        $tenant = trim((string)($context['tenant_id'] ?? '0'));
        $allowed = array_values(array_unique(array_filter(array_map('intval', (array)($context['store_ids'] ?? [])))));
        $requestedRaw = $input['store_ids'] ?? '';
        $requested = is_array($requestedRaw) ? $requestedRaw : preg_split('/[,\s]+/', (string)$requestedRaw, -1, PREG_SPLIT_NO_EMPTY);
        $requested = array_values(array_unique(array_filter(array_map('intval', (array)$requested))));
        if ($requested !== []) $allowed = array_values(array_intersect($allowed, $requested));
        if ($tenant === '' || $allowed === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        sort($allowed);
        return ['tenant_id' => $tenant, 'store_ids' => $allowed];
    }

    /** @return array{start:string,end:string} */
    private function range(array $input): array
    {
        $period = (string)($input['period'] ?? '');
        $today = date('Y-m-d');
        if ($period === 'month') { $start = date('Y-m-01'); $end = $today; }
        elseif ($period === 'year') { $start = date('Y-01-01'); $end = $today; }
        else { $start = trim((string)($input['start_date'] ?? '')) ?: $today; $end = trim((string)($input['end_date'] ?? '')) ?: $start; }
        $valid = static function (string $date): bool { $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date); return $d !== false && $d->format('Y-m-d') === $date; };
        if (!$valid($start) || !$valid($end) || $start > $end || strtotime($end) - strtotime($start) > 366 * 86400) throw new \InvalidArgumentException('统计日期范围无效');
        return ['start' => $start, 'end' => $end];
    }

    /** @return array<int,array<string,mixed>> */
    private function cashRows(string $tenant, array $stores, array $range, int $categoryId = 0): array
    {
        // The registered cash reader owns payment-method exclusion, reversal joins and
        // card-component allocation. Member consumption keeps its sale-only,
        // non-experience scope; recharge and unallocated repayment are not purchases.
        $rows = RegisteredMetricReadServices::withCurrentReportProductCategory()
            ->categoryRows('actual_performance', $tenant, $stores, $range, [], ['is_experience' => 0]);
        $rows = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['sale_fact_id'] ?? '') !== ''));
        $rows = $this->currentCategoryRows($rows, 'item_id', 0);
        foreach ($rows as &$row) {
            $row['is_experience'] = 0;
            $row['business_source_label_snapshot'] = (string)($row['source_label'] ?? '');
        }
        unset($row);
        return $categoryId === 0 ? $rows : array_values(array_filter($rows,
            static fn(array $row): bool => (int)$row['category_id'] === $categoryId));
    }

    /** @return array<int,array<string,mixed>> */
    private function serviceRows(string $tenant, array $stores, array $range, int $categoryId = 0): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('s')
            ->where('s.tenant_id', $tenant)->whereIn('s.store_id', $stores)->where('s.service_status', 'completed')
            ->whereBetween('s.business_date', [$range['start'], $range['end']])
            ->fieldRaw('s.service_fact_id,s.store_id,s.member_id,s.business_date,s.checkout_request_id,s.source_line_id,s.project_id,s.project_name_snapshot,s.project_category_id_snapshot category_id,s.project_category_name_snapshot category_name,s.quantity,s.is_experience');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 's');
        return $this->currentCategoryRows($query->select()->toArray(), 'project_id', $categoryId);
    }

    /** Historical recency and dormancy need only each member's latest eligible service date, not every service line. */
    private function historicalServiceLastRows(string $tenant, array $stores, string $end, int $categoryId): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('s')
            ->where('s.tenant_id', $tenant)->whereIn('s.store_id', $stores)
            ->where('s.service_status', 'completed')->where('s.member_id', '>', 0)
            ->whereBetween('s.business_date', ['1970-01-01', $end]);
        StoreCustomerVisitEligibility::apply($query, 's');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 's');
        if ($categoryId === 0) {
            return $query->fieldRaw('s.member_id,MAX(s.business_date) business_date,0 is_experience')
                ->group('s.member_id')->select()->toArray();
        }

        // Legacy category-filter callers still resolve the current platform item before
        // choosing the latest date; a member can have services in several categories.
        $rows = $query->fieldRaw('s.store_id,s.member_id,s.project_id,MAX(s.business_date) business_date,0 is_experience')
            ->group('s.store_id,s.member_id,s.project_id')->select()->toArray();
        $latest = [];
        foreach ($this->currentCategoryRows($rows, 'project_id', $categoryId) as $row) {
            $memberId = (int)$row['member_id'];
            if (!isset($latest[$memberId]) || $row['business_date'] > $latest[$memberId]['business_date']) {
                $latest[$memberId] = $row;
            }
        }
        return array_values($latest);
    }

    /** Project category grouping through platform product IDs only, then apply the dashboard's first-level category filter. */
    private function currentCategoryRows(array $rows, string $itemKey, int $categoryId): array
    {
        $links = [];
        // Resolve each store/product identity once: historical fact counts can be
        // much larger than the catalog, while category identity is the same.
        foreach ($rows as $row) {
            $storeId = (int)$row['store_id'];
            $productId = (int)($row[$itemKey] ?? 0);
            $links[$storeId . ':' . $productId] = ['store_id' => $storeId, 'product_id' => $productId];
        }
        $categories = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $result = [];
        foreach ($rows as $row) {
            $key = (int)$row['store_id'] . ':' . (int)($row[$itemKey] ?? 0);
            $category = $categories[$key] ?? [];
            $row['category_id'] = (int)($category['root_id'] ?? 0);
            $row['category_path'] = (string)($category['path'] ?? '');
            if ($categoryId === 0 || $row['category_id'] === $categoryId) $result[] = $row;
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function ranking(array $rows, string $mode): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string)($row['item_id'] ?? $row['item_name'] ?? ''); if ($key === '') continue;
            if (!isset($groups[$key])) $groups[$key] = ['name' => (string)($row['item_name'] ?? '-'), 'amount' => 0, 'orders' => [], 'people' => []];
            $groups[$key]['amount'] += (int)($row['amount_cents'] ?? 0);
            $groups[$key]['orders'][(string)($row['order_id'] ?? '')] = true;
            $member = (int)($row['member_id'] ?? 0); if ($member > 0) $groups[$key]['people'][$member] = true;
        }
        // Both visible ranking panels retain their existing response keys, but
        // money comes only from the same registered actual-performance rows.
        $out = []; foreach ($groups as $group) { $people = count($group['people']); $out[] = ['name' => $group['name'], 'cash_income_cents' => $group['amount'], 'consumption_cents' => $group['amount'], 'orders' => count(array_filter(array_keys($group['orders']))), 'people' => $people, 'average_cents' => $this->ratio($group['amount'], $people)]; }
        usort($out, static fn(array $a, array $b): int => $b['cash_income_cents'] <=> $a['cash_income_cents']); return array_slice($out, 0, 5);
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function tiers(array $cash, string $tenant): array
    {
        $tiers = $this->tierConfig($tenant);
        $members = [];
        $sourceAIds = $this->sourceAIds();
        foreach ($this->withoutExperience($cash) as $row) {
            $id = (int)($row['member_id'] ?? 0);
            if ($id <= 0) continue;
            $members[$id]['amount'] = (int)($members[$id]['amount'] ?? 0) + (int)$row['amount_cents'];
            $sourceId = (int)($row['business_source_primary_id'] ?? 0);
            $members[$id]['source_a'] = in_array($sourceId, $sourceAIds, true)
                || (string)($row['business_source_label_snapshot'] ?? '') === 'A'
                || (bool)($members[$id]['source_a'] ?? false);
        }
        return [
            'member' => $this->buildCumulativeTierRows($tiers, $members, false),
            'new_customer' => $this->buildCumulativeTierRows($tiers, $members, true),
        ];
    }

    /** A higher-tier member also reaches every lower threshold; row amounts and shares are not additive across tiers. */
    private function buildCumulativeTierRows(array $tiers, array $members, bool $excludeSourceA): array
    {
        $eligible = array_filter($members, static fn(array $member): bool => !$excludeSourceA || !$member['source_a']);
        $totalPeople = count($eligible);
        $totalAmount = array_sum(array_column($eligible, 'amount'));
        $rows = [];
        foreach ($tiers as $tier) {
            $people = 0;
            $amount = 0;
            foreach ($eligible as $member) {
                if (max(0, (int)$member['amount']) < (int)$tier['lower']) continue;
                $people++;
                $amount += (int)$member['amount'];
            }
            $rows[] = [
                'name' => $tier['name'],
                'people' => $people,
                'amount_cents' => $amount,
                'member_share' => $totalPeople ? ($people / $totalPeople) * 100 : null,
                'amount_share' => $totalAmount ? ($amount / $totalAmount) * 100 : null,
                'average_cents' => $this->ratio($amount, $people),
            ];
        }
        return $rows;
    }

    /** Primary source A is identified by the configured source name prefix. */
    private function sourceAIds(): array
    {
        try {
            $rows = Db::name('cashier_v3_business_source')->field('id,name')->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) if (preg_match('/^A/u', trim((string)($row['name'] ?? ''))) === 1) $ids[] = (int)$row['id'];
        return $ids;
    }

    /** @return array<int,array{name:string,lower:int,upper:?int}> */
    private function tierConfig(string $tenant): array
    {
        try { $rows = Db::name('cashier_v3_report_consumption_tier')->where('tenant_id', $tenant)->where('enabled', 1)->whereNull('deleted_at')->order('sort_order')->select()->toArray(); } catch (\Throwable $e) { $rows = []; }
        if ($rows === []) $rows = [['tier_name' => '0-5000', 'lower_bound_cents' => 0, 'upper_bound_cents' => 500000], ['tier_name' => '5000-10000', 'lower_bound_cents' => 500000, 'upper_bound_cents' => 1000000], ['tier_name' => '10000-30000', 'lower_bound_cents' => 1000000, 'upper_bound_cents' => 3000000], ['tier_name' => '30000-50000', 'lower_bound_cents' => 3000000, 'upper_bound_cents' => 5000000], ['tier_name' => '50000-100000', 'lower_bound_cents' => 5000000, 'upper_bound_cents' => 10000000], ['tier_name' => '100000以上', 'lower_bound_cents' => 10000000, 'upper_bound_cents' => null]];
        return array_map(static fn(array $r): array => ['name' => (string)($r['tier_name'] ?? $r['name'] ?? '-'), 'lower' => (int)($r['lower_bound_cents'] ?? 0), 'upper' => array_key_exists('upper_bound_cents', $r) && $r['upper_bound_cents'] !== null ? (int)$r['upper_bound_cents'] : null], $rows);
    }

    /** @return array{recency:array<int,array<string,mixed>>,frequency:array<int,array<string,mixed>>} */
    private function visitBands(array $currentServices, array $historicalServices, string $start, string $end): array
    {
        $last = []; $monthly = [];
        // 会员到店频次只计非体验服务；人员“不算人数人次”不改变门店到店事实。
        foreach (StoreCustomerVisitEligibility::filter($historicalServices) as $row) { $member = (int)($row['member_id'] ?? 0); if ($member <= 0) continue; $date = (string)$row['business_date']; if (!isset($last[$member]) || $date > $last[$member]) $last[$member] = $date; }
        foreach (StoreCustomerVisitEligibility::filter($currentServices) as $row) { $member = (int)($row['member_id'] ?? 0); if ($member <= 0) continue; $month = substr((string)$row['business_date'], 0, 7); $monthly[$month][$member] = ($monthly[$month][$member] ?? 0) + 1; }
        $recency = []; foreach ([30, 60, 90, 180, 360] as $days) { $cut = strtotime($end . ' -' . $days . ' days'); $count = 0; foreach ($last as $date) if (strtotime($date) <= $cut) $count++; $recency[] = ['label' => $days . '天到店', 'value' => $count]; }
        $frequency = []; $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $startMonth = substr($start, 0, 7); $endMonth = substr($end, 0, 7);
        $months = array_values(array_filter(array_keys($monthly), static fn(string $month): bool => $month >= $startMonth && $month <= $endMonth));
        foreach ($months as $month) foreach ($monthly[$month] ?? [] as $visits) { $bucket = min(5, $visits); $counts[$bucket]++; }
        foreach ($counts as $bucket => $count) $frequency[] = ['label' => $bucket === 5 ? '月服务到店超4次' : '月服务到店' . $bucket . '次', 'value' => $count]; return compact('recency', 'frequency');
    }

    /** @return array<int,array{id:int,name:string}> */
    private function categories(): array
    {
        // Use the group dashboard's platform taxonomy, including an enabled
        // orphan root; a disabled child does not erase its parent's facts.
        $rows = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name,statistics_enabled,sort')->order('pid', 'asc')->order('sort', 'desc')->order('id', 'desc')->select()->toArray();
        $ids = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $rows), true);
        $roots = [];
        foreach ($rows as $row) {
            if ((int)$row['pid'] > 0 && isset($ids[(int)$row['pid']])) continue;
            if ((int)$row['statistics_enabled'] !== 1) continue;
            $roots[] = ['id' => (int)$row['id'], 'name' => (string)$row['cate_name']];
        }
        return $roots;
    }

    private function isExperience(array $row): bool { return (int)($row['is_experience'] ?? 0) === 1; }
    private function isProduct(array $row): bool { return in_array(strtolower((string)($row['product_type_snapshot'] ?? '')), ['product', 'goods'], true); }
    private function withoutExperience(array $rows): array { return array_values(array_filter($rows, fn(array $r): bool => !$this->isExperience($r))); }
    private function memberSet(array $rows, bool $positiveOnly = false): array { $set = []; foreach ($rows as $r) { if ($positiveOnly && (int)($r['amount_cents'] ?? 0) <= 0) continue; $id = (int)($r['member_id'] ?? 0); if ($id > 0) $set[$id] = true; } return $set; }
    private function sleepingMembers(array $rows, string $end, int $days): array { $last = []; foreach ($rows as $r) { $id = (int)($r['member_id'] ?? 0); if ($id <= 0) continue; $date = (string)$r['business_date']; if (!isset($last[$id]) || $date > $last[$id]) $last[$id] = $date; } $cut = strtotime($end . ' -' . $days . ' days'); return array_filter($last, static fn(string $date): bool => strtotime($date) <= $cut); }
    private function projectCount(array $rows): int { return count($rows); }
    private function sum(array $rows, string $field): int { $sum = 0; foreach ($rows as $r) $sum += (int)($r[$field] ?? 0); return $sum; }
    private function ratio(int $amount, int $count): ?int { return $count > 0 ? (int)round($amount / $count) : null; }
    private function metric($value, string $explanation): array { return ['value' => $value, 'field_explanation' => $explanation]; }
    private function moneyMetric(?int $value, string $explanation): array { return ['value_cents' => $value, 'field_explanation' => $explanation]; }
    /** @return array<string,string> */
    private function fieldExplanations(): array
    {
        return [
            'member.consuming_members' => '非体验销售形成实际业绩事实的会员，按会员 ID 去重。',
            'member.service_members' => '查询期间完成有效服务的会员，按会员 ID 去重；取消、拒绝、爽约、作废和未完成不计入。',
            'member.sleeping_members' => '截至查询结束日最后一次非体验有效服务距今 90 天及以上且历史有符合条件到店记录的会员，按会员 ID 去重；人员开关不参与。',
            'consumption.people' => '非体验标签的消费会员数，按会员 ID 去重。',
            'consumption.amount' => '查询期间非体验销售的实际业绩净额，即现金业绩减现金退款；作废和调整按事实日期反向影响。',
            'consumption.average' => '非体验销售实际业绩净额除以消费会员数；分母为零显示 -。',
            'service.people' => '查询期间有效服务会员数，按会员 ID 去重。',
            'service.amount' => '与服务人数同日期范围的非体验销售实际业绩净额；收款、退款按各自业务日期统计，服务完成日只决定服务人数和项目数。',
            'service.projects' => '有效服务事实逐条计数，不按会员去重，不将 quantity 乘入次数。',
            'service.average_amount' => '同期实际业绩净额除以服务人数；两者来自不同业务事实，分母为零显示 -。',
            'service.average_items' => '服务项目数除以服务人数；分母为零显示 -。',
            'service.unit_amount' => '同期实际业绩净额除以服务项目数；两者来自不同业务事实，分母为零显示 -。',
            'category.people' => '按商品关联的平台商品当前一级分类归组；定制卡按卡内项目分类，跨分类按项目配置金额比例分摊。该分类下非体验标签消费会员数按会员 ID 去重，不按同名商品猜测分类。',
            'category.amount' => '该一级商品分类下非体验销售的实际业绩净额；定制卡按卡内项目配置金额比例分摊到项目分类。退款、作废和调整按事实日期反向影响。',
            'category.average' => '分类实际业绩净额除以分类消费会员数；分母为零显示 -。',
            'category.share' => '分类实际业绩净额除以全部非体验销售实际业绩净额。',
            'ranking.consumption.cash_income' => '消费排行实际业绩：该项目或产品在查询期间形成的非体验销售实际业绩净额。',
            'ranking.consumption.orders' => '消费排行成交单数：排除体验标签后，该项目或产品关联的有效成交订单数。',
            'ranking.consumption.people' => '消费排行购买人头：排除体验标签后购买该项目或产品的会员数，按会员 ID 去重。',
            'ranking.consumption.average' => '消费排行人均实际业绩：该项目或产品实际业绩净额除以购买人头。',
            'ranking.consumption_amount.amount' => '第二排行金额同样读取项目或产品的实际业绩净额，按收款及退款业务日期归属；不把服务核销业绩当作实际业绩。',
            'ranking.consumption_amount.people' => '第二排行人数为该项目或产品在同期实际业绩销售记录中的会员数，按会员 ID 去重。',
            'ranking.consumption_amount.average' => '第二排行人均金额为实际业绩净额除以同期购买人头。',
            'visit.recency' => '截至查询结束日最后一次非体验有效服务距今达到 30/60/90/180/360 天的会员数，按累计区间统计；人员开关不参与。',
            'visit.frequency' => '按非体验有效服务事实统计每个会员每月到店次数；全年按各月份会员频次桶合计，人员开关不参与。',
            'tier.people' => '达到该档金额下限的会员按会员 ID 去重；达到高档时也计入所有已达到的低档。',
            'tier.amount' => '达到该档的会员完整实际业绩净额合计；跨档重复展示，不可将各档相加。',
            'tier.member_share' => '该档累计达标人数除以本列去重顾客总人数；各档占比不可相加。',
            'tier.amount_share' => '该档达标会员完整实际业绩净额除以本列顾客实际业绩净额；各档占比不可相加。',
            'tier.average' => '该档达标会员完整实际业绩净额除以该档达标人数；分母为零显示 -。',
            'tier.new_customer' => '新客分层同样按金额下限累计达标，但排除来源 A 的会员。',
        ];
    }
}
