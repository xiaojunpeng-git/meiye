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
    public const METRIC_VERSION = 'member-management-dashboard-consumption-ranking-v8';

    /** @return array<string,mixed> */
    public function dashboard(array $context, array $input = []): array
    {
        $scope = $this->scope($context, $input);
        $range = $this->range($input);
        $categoryId = (int)($input['category_id'] ?? 0);
        // Consumption ranks are completed-project performance, not a second copy
        // of cash/actual sales. Reduce them before loading the other populations.
        $consumptionRanks = $this->consumptionRankings($scope, $range, $categoryId);
        $cash = $this->cashRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryId);
        $services = $this->serviceRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryId);
        $historicalServices = $this->historicalServiceLastRows($scope['tenant_id'], $scope['store_ids'], $range['end'], $categoryId);

        $consuming = $this->memberSet($cash);
        $serviceMembers = $this->memberSet($services);
        $sleeping = $this->sleepingMembers(StoreCustomerVisitEligibility::filter($historicalServices), $range['end'], 90);
        $cashAmount = $this->sum($cash, 'amount_cents');
        // Consumption and the main tier table count the same unique subjects:
        // a member ID or, where no member exists, one traceable guest order.
        $tierRows = $this->tiers($cash, $scope['tenant_id']);
        $tierTotals = [
            'member' => $this->tierTotal($tierRows['member']),
            'new_customer' => $this->tierTotal($tierRows['new_customer']),
        ];
        if ($tierTotals['member']['amount_cents'] !== $cashAmount) {
            throw new \RuntimeException('消费分层实际业绩与概况金额不一致');
        }
        // Service counts follow completed-service dates; money follows the registered
        // actual-performance business date, so a later service does not rebook a sale.
        $serviceAmount = $cashAmount;
        $projectCount = $this->projectCount($services);
        $peopleCount = $tierTotals['member']['people'];
        $servicePeopleCount = count($serviceMembers);

        $categories = $this->categories();
        if ($categoryId > 0) $categories = array_values(array_filter($categories, static fn(array $category): bool => (int)$category['id'] === $categoryId));
        $categoryCards = [];
        foreach ($categories as $category) {
            $categoryRows = array_values(array_filter($cash, function (array $row) use ($category): bool {
                return (int)($row['category_id'] ?? 0) === (int)$category['id'];
            }));
            $categoryCards[] = [
                'id' => (int)$category['id'],
                'name' => (string)$category['name'],
                'people' => count($this->memberSet($categoryRows, true)),
                'amount_cents' => $this->sum($categoryRows, 'amount_cents'),
                'average_cents' => $this->ratio($this->sum($categoryRows, 'amount_cents'), count($this->memberSet($categoryRows, true))),
                'share' => $cashAmount === 0 ? null : ($this->sum($categoryRows, 'amount_cents') / $cashAmount) * 100,
                'rankings' => [
                    'projects' => $this->ranking(array_values(array_filter($categoryRows, fn(array $r): bool => $this->isProject($r))), 'consumption'),
                    'products' => $this->ranking(array_values(array_filter($categoryRows, fn(array $r): bool => $this->isProduct($r))), 'consumption'),
                    'consumption_projects' => $consumptionRanks['categories'][(int)$category['id']] ?? [],
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
                ['key' => 'member.consuming_members', 'label' => '消费会员数', 'source' => '有实际业绩事实的会员 ID 去重，含仅有退款记录的会员'],
                ['key' => 'member.service_members', 'label' => '服务会员数', 'source' => '有效服务事实，会员 ID 去重'],
                ['key' => 'service.amount', 'label' => '实际业绩', 'source' => '同业务日期的实际业绩事实净额；服务人数按服务完成日另计'],
                ['key' => 'consumption.amount', 'label' => '实际业绩', 'source' => '实际业绩事实净额（现金业绩减现金退款）'],
            ],
            'drilldown' => ['enabled' => false, 'reason' => '会员看板当前提供指标展示，暂无已确认的精确明细下钻契约。'],
            'export' => ['enabled' => false, 'reason' => '会员看板当前无已确认的导出列契约。'],
            'tier_settings' => ['route' => '/platform/six-dimension/consumption-tiers', 'permission' => 'setting-shop-six-dimension-consumption-tier'],
            'customer_overview' => [
                'member' => [
                    'consuming_members' => $this->metric(count($consuming), '有实际业绩事实的消费会员数，按会员 ID 去重；游客单据另计入消费人数。'),
                    'service_members' => $this->metric($servicePeopleCount, '有效完成服务的会员数，按会员 ID 去重。'),
                    'sleeping_members' => $this->metric(count($sleeping), '最后一次有效服务距查询结束日达到 90 天及以上，按会员 ID 去重。'),
                ],
                'consumption' => [
                    'people' => $this->metric($peopleCount, '实际业绩关联顾客数：会员按 ID 去重，游客按可追溯单据计数，含负向净额。'),
                    'amount' => $this->moneyMetric($cashAmount, '完整实际业绩净额，包含体验销售及独立调整；退款在事实业务日抵减。'),
                    'average' => $this->moneyMetric($this->ratio($cashAmount, $peopleCount), '实际业绩净额除以会员与游客单据合计人数，分母为零显示 -。'),
                ],
                'service' => [
                    'people' => $this->metric($servicePeopleCount, '有效服务会员数，按会员 ID 去重。'),
                    'amount' => $this->moneyMetric($serviceAmount, '同业务日期、同权限范围的完整实际业绩净额；与服务完成次数不是同一事实。'),
                    'projects' => $this->metric($projectCount, '有效服务项目事实次数，不按会员去重。'),
                    'average_amount' => $this->moneyMetric($this->ratio($serviceAmount, $servicePeopleCount), '同期实际业绩净额除以服务人数；收款日与服务日可能不同。'),
                    'average_items' => $this->metric($this->ratio($projectCount, $servicePeopleCount), '服务项目数除以服务人数。'),
                    'unit_amount' => $this->moneyMetric($this->ratio($serviceAmount, $projectCount), '同期实际业绩净额除以服务项目数；收款日与服务日可能不同。'),
                ],
            ],
            'consumption' => [
                'people' => $this->metric($peopleCount, '会员按 ID、游客按单据去重后的实际业绩关联顾客数。'),
                'amount' => $this->moneyMetric($cashAmount, '完整实际业绩净额。'),
                'average' => $this->moneyMetric($this->ratio($cashAmount, $peopleCount), '实际业绩净额除以消费人数。'),
                'categories' => $categoryCards,
                'rankings' => [
                    'projects' => $this->ranking(array_values(array_filter($cash, fn(array $r): bool => $this->isProject($r))), 'consumption'),
                    'products' => $this->ranking(array_values(array_filter($cash, fn(array $r): bool => $this->isProduct($r))), 'consumption'),
                    'consumption_projects' => $consumptionRanks['all'],
                ],
            ],
            'visit_bands' => $this->visitBands($services, $historicalServices, $range['start'], $range['end']),
            'tiers' => $tierRows,
            'tier_totals' => $tierTotals,
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
        // The registered reader owns the complete actual-performance population,
        // including experience sales and standalone adjustments. Removing rows by
        // sale linkage or experience would make this dashboard disagree with the
        // group dashboard even when date and authorized store scope are identical.
        $rows = RegisteredMetricReadServices::withCurrentReportProductCategory()
            ->categoryRows('actual_performance', $tenant, $stores, $range);
        $rows = $this->currentCategoryRows($rows, 'item_id', 0);
        foreach ($rows as &$row) {
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
    private function currentCategoryRows(array $rows, string $itemKey, int $categoryId, bool $verifiedServiceItems = false): array
    {
        $links = [];
        // Resolve each store/product identity once: historical fact counts can be
        // much larger than the catalog, while category identity is the same.
        foreach ($rows as $row) {
            $storeId = (int)$row['store_id'];
            $productId = (int)($row[$itemKey] ?? 0);
            $links[$storeId . ':' . $productId] = ['store_id' => $storeId, 'product_id' => $productId];
        }
        // Completed services can consume an entitlement sold at another store.
        // Only the registered, execution-store-authorized service population may
        // use that cross-store identity path; cash rows keep their existing guard.
        $resolver = new StoreReportCurrentProductCategoryServices();
        $categories = $verifiedServiceItems ? $resolver->resolveVerifiedFactItems($links) : $resolver->resolve($links);
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
            // Group by the same display name as the registered group-dashboard
            // ranking. Separate platform IDs sharing a name must appear as one
            // visible rank while category identity still comes only from IDs.
            $key = trim((string)($row['item_name'] ?? '')); if ($key === '') continue;
            if (!isset($groups[$key])) $groups[$key] = ['name' => $key, 'amount' => 0, 'orders' => [], 'people' => []];
            $amount = (int)($row['amount_cents'] ?? 0);
            $groups[$key]['amount'] += $amount;
            // Refund and reversal amounts reduce the rank, but only positive
            // actual-performance facts establish a buyer or completed order.
            if ($amount > 0) {
                $order = (string)($row['order_id'] ?? '');
                if ($order !== '') $groups[$key]['orders'][$order] = true;
                $member = (int)($row['member_id'] ?? 0);
                if ($member > 0) $groups[$key]['people'][$member] = true;
            }
        }
        // The upper sales ranking retains its registered actual-performance
        // amount and positive buyer/order semantics, independently of service use.
        $out = []; foreach ($groups as $group) { $people = count($group['people']); $out[] = ['name' => $group['name'], 'cash_income_cents' => $group['amount'], 'consumption_cents' => $group['amount'], 'orders' => count(array_filter(array_keys($group['orders']))), 'people' => $people, 'average_cents' => $this->ratio($group['amount'], $people)]; }
        usort($out, static fn(array $a, array $b): int => $b['cash_income_cents'] <=> $a['cash_income_cents']); return array_slice($out, 0, 5);
    }

    /** Bound the registered fact reader to disjoint seven-day windows. Keep
     * only amounts and member sets across windows, never top-five subtotals:
     * both cross-window member deduplication and the final ranking must be exact. */
    private function consumptionRankings(array $scope, array $range, int $categoryId): array
    {
        $all = [];
        $categories = [];
        $reader = new RegisteredMetricReadServices();
        $start = new \DateTimeImmutable($range['start']);
        $end = new \DateTimeImmutable($range['end']);
        while ($start <= $end) {
            $until = min($start->modify('+6 days'), $end);
            $rows = $reader->categoryRows('consume_amount', $scope['tenant_id'], $scope['store_ids'],
                ['start' => $start->format('Y-m-d'), 'end' => $until->format('Y-m-d')]);
            $rows = $this->currentCategoryRows($rows, 'item_id', $categoryId, true);
            foreach ($rows as $row) {
                $this->addConsumptionRow($all, $row);
                $id = (int)($row['category_id'] ?? 0);
                if (!isset($categories[$id])) $categories[$id] = [];
                $this->addConsumptionRow($categories[$id], $row);
            }
            unset($rows);
            $start = $until->modify('+1 day');
        }
        foreach ($categories as $id => $groups) $categories[$id] = $this->rankConsumptionGroups($groups);
        return ['all' => $this->rankConsumptionGroups($all), 'categories' => $categories];
    }

    /** Project consumption uses signed performance and distinct served members,
     * including zero-amount services. No sales/receipt condition establishes a
     * service member, and guest rows retain money without inventing member IDs. */
    private function addConsumptionRow(array &$groups, array $row): void
    {
        $name = trim((string)($row['item_name'] ?? ''));
        if ($name === '') return;
        if (!isset($groups[$name])) $groups[$name] = ['name' => $name, 'amount' => 0, 'people' => []];
        $groups[$name]['amount'] += (int)($row['amount_cents'] ?? 0);
        $member = (int)($row['member_id'] ?? 0);
        if ($member > 0) $groups[$name]['people'][$member] = true;
    }

    /** Sort only after the complete authorized population has been reduced. */
    private function rankConsumptionGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $people = count($group['people']);
            $out[] = ['name' => $group['name'], 'consumption_cents' => $group['amount'],
                'people' => $people, 'average_cents' => $this->ratio($group['amount'], $people)];
        }
        usort($out, static function (array $a, array $b): int {
            return ($b['consumption_cents'] <=> $a['consumption_cents']) ?: strcmp($a['name'], $b['name']);
        });
        return array_slice($out, 0, 5);
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function tiers(array $cash, string $tenant): array
    {
        $tiers = $this->tierConfig($tenant);
        $subjects = $this->tierSubjects($cash, $this->sourceAIds());
        return [
            'member' => $this->buildExclusiveTierRows($tiers, $subjects, false),
            'new_customer' => $this->buildExclusiveTierRows($tiers, $subjects, true),
        ];
    }

    /**
     * Every signed actual-performance row belongs to one traceable subject.
     * Members have a durable ID; guests have no durable identity, so their
     * stable order is the narrowest defensible counting and refund boundary.
     * A fact without either identity is kept under its own immutable fact key.
     */
    private function tierSubjects(array $cash, array $sourceAIds): array
    {
        $subjects = [];
        foreach ($cash as $row) {
            $id = (int)($row['member_id'] ?? 0);
            $orderId = trim((string)($row['order_id'] ?? ''));
            $factId = '';
            foreach (['sale_fact_id', 'fact_id', 'id'] as $factKey) {
                $factId = trim((string)($row[$factKey] ?? ''));
                if ($factId !== '') break;
            }
            if ($id <= 0 && $orderId === '' && $factId === '') {
                throw new \RuntimeException('游客实际业绩缺少可追溯单据或事实身份');
            }
            $subjectKey = $id > 0 ? 'member:' . $id
                : 'guest:' . (int)($row['store_id'] ?? 0) . ':' . ($orderId !== '' ? 'order:' . $orderId : 'fact:' . $factId);
            $subjects[$subjectKey]['amount'] = (int)($subjects[$subjectKey]['amount'] ?? 0) + (int)$row['amount_cents'];
            $sourceId = (int)($row['business_source_primary_id'] ?? 0);
            $subjects[$subjectKey]['source_a'] = in_array($sourceId, $sourceAIds, true)
                || (string)($row['business_source_label_snapshot'] ?? '') === 'A'
                || (bool)($subjects[$subjectKey]['source_a'] ?? false);
        }
        return $subjects;
    }

    /** Server-owned totals use the already exclusive rows; the UI never re-adds rounded yuan. */
    private function tierTotal(array $rows): array
    {
        $people = array_sum(array_column($rows, 'people'));
        $amount = array_sum(array_column($rows, 'amount_cents'));
        return ['people' => $people, 'amount_cents' => $amount,
            'member_share' => $people > 0 ? 100.0 : null,
            'amount_share' => $amount !== 0 ? 100.0 : null,
            'average_cents' => $this->ratio($amount, $people)];
    }

    /** Each member or guest order's signed net belongs to exactly one configured [lower, upper) tier. */
    private function buildExclusiveTierRows(array $tiers, array $subjects, bool $excludeSourceA): array
    {
        $eligible = array_filter($subjects, static fn(array $subject): bool => !$excludeSourceA || !$subject['source_a']);
        $totalPeople = count($eligible);
        $totalAmount = array_sum(array_column($eligible, 'amount'));
        $rows = array_map(static fn(array $tier): array => [
            'name' => $tier['name'], 'people' => 0, 'amount_cents' => 0,
        ], $tiers);
        // Match once per subject, so even overlapping config cannot duplicate
        // either the counted subject or its signed performance net.
        foreach ($eligible as $subject) {
            // Negative refund-adjusted net stays in the zero tier while its
            // signed amount remains visible exactly once.
            $net = max(0, (int)$subject['amount']);
            $matched = false;
            foreach ($tiers as $index => $tier) {
                if ($net < (int)$tier['lower']
                    || ($tier['upper'] !== null && $net >= (int)$tier['upper'])) continue;
                $rows[$index]['people']++;
                $rows[$index]['amount_cents'] += (int)$subject['amount'];
                $matched = true;
                break;
            }
            if (!$matched) throw new \RuntimeException('消费分层区间未覆盖顾客实际业绩');
        }
        foreach ($rows as &$row) {
            $row['member_share'] = $totalPeople ? ($row['people'] / $totalPeople) * 100 : null;
            $row['amount_share'] = $totalAmount ? ($row['amount_cents'] / $totalAmount) * 100 : null;
            $row['average_cents'] = $this->ratio($row['amount_cents'], $row['people']);
        }
        unset($row);
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

    /** Ranking item types mirror the registered group dashboard; unknown fact types contribute to totals only. */
    private function isProduct(array $row): bool { return (string)($row['product_type_snapshot'] ?? '') === 'product'; }
    private function isProject(array $row): bool { return in_array((string)($row['product_type_snapshot'] ?? ''), ['project', 'card_component'], true); }
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
            'member.consuming_members' => '查询期间有实际业绩事实的会员，按会员 ID 去重；含仅有负向记录的会员，不含游客单据。',
            'member.service_members' => '查询期间完成有效服务的会员，按会员 ID 去重；取消、拒绝、爽约、作废和未完成不计入。',
            'member.sleeping_members' => '截至查询结束日最后一次非体验有效服务距今 90 天及以上且历史有符合条件到店记录的会员，按会员 ID 去重；人员开关不参与。',
            'consumption.people' => '查询期间实际业绩关联的顾客数：会员按 ID 去重，游客因无长期身份按门店单据计数；仅有退款的会员或游客单据也计入，且与会员分层人数合计相等。',
            'consumption.amount' => '查询期间完整实际业绩净额；体验销售和独立调整均计入，退款及作废按事实业务日抵减。',
            'consumption.average' => '完整实际业绩净额除以会员和游客单据的合计人数；分母为零显示 -。',
            'service.people' => '查询期间有效服务会员数，按会员 ID 去重。',
            'service.amount' => '与服务人数同日期范围的完整实际业绩净额；收款、退款按各自业务日期统计，服务完成日只决定服务人数和项目数。',
            'service.projects' => '有效服务事实逐条计数，不按会员去重，不将 quantity 乘入次数。',
            'service.average_amount' => '同期实际业绩净额除以服务人数；两者来自不同业务事实，分母为零显示 -。',
            'service.average_items' => '服务项目数除以服务人数；分母为零显示 -。',
            'service.unit_amount' => '同期实际业绩净额除以服务项目数；两者来自不同业务事实，分母为零显示 -。',
            'category.people' => '按商品关联的平台商品当前一级分类归组；定制卡按卡内项目分类，跨分类按项目配置金额比例分摊。该分类下完整实际业绩正向事实关联的会员按 ID 去重，不按同名商品猜测分类。',
            'category.amount' => '该一级商品分类下完整实际业绩净额；定制卡按卡内项目配置金额比例分摊到项目分类。体验销售、独立调整、退款及作废均按事实业务日计入。',
            'category.average' => '分类实际业绩净额除以分类消费会员数；分母为零显示 -。',
            'category.share' => '分类实际业绩净额除以全部完整实际业绩净额。',
            'ranking.consumption.cash_income' => '消费排行实际业绩：该项目或产品在查询期间的完整实际业绩净额，同名商品展示金额合并。',
            'ranking.consumption.orders' => '消费排行成交单数：同名商品关联的有效成交订单数，按订单 ID 去重。',
            'ranking.consumption.people' => '消费排行购买人头：同名商品关联的会员数，按会员 ID 去重。',
            'ranking.consumption.average' => '消费排行人均实际业绩：该项目或产品实际业绩净额除以购买人头。',
            'ranking.consumption_amount.amount' => '仅统计已完成项目的有效消耗业绩，按消耗业绩业务日期归属，排除已作废服务；按金额由高到低显示前五名，不使用销售收款或劳动业绩。',
            'ranking.consumption_amount.people' => '该项目有效消耗业绩对应的服务会员数，按会员 ID 去重；零金额服务仍计会员，游客金额保留但不虚构会员人数。',
            'ranking.consumption_amount.average' => '项目消耗业绩除以对应的去重服务会员数，分母为零显示 -。',
            'visit.recency' => '截至查询结束日最后一次非体验有效服务距今达到 30/60/90/180/360 天的会员数，按累计区间统计；人员开关不参与。',
            'visit.frequency' => '按非体验有效服务事实统计每个会员每月到店次数；全年按各月份会员频次桶合计，人员开关不参与。',
            'tier.people' => '会员按 ID、游客按门店单据各归入唯一金额区间，负净额归最低档；会员分层总人数与概况消费人数一致。',
            'tier.amount' => '先按会员或游客单据汇总查询期间完整实际业绩净额，再把净额归入唯一层级；六档金额合计等于概况实际业绩。',
            'tier.member_share' => '该档人数除以本列顾客或游客单据总数；各档人数占比可合计。',
            'tier.amount_share' => '该档实际业绩净额除以本列实际业绩净额；负向档金额占比可能为负。',
            'tier.average' => '该档实际业绩净额除以该档人数；分母为零显示 -。',
            'tier.new_customer' => '新客分层同样将会员或游客单据归入唯一金额区间，但排除来源 A 的对象。',
        ];
    }
}
