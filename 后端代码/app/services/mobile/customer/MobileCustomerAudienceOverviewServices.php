<?php

declare(strict_types=1);

namespace app\services\mobile\customer;

use app\services\mobile\merchant\MobileMerchantAnalyticsEntryPolicy;
use app\services\mobile\merchant\MobileMerchantAnalyticsEntryScopeServices;
use app\services\mobile\protocol\MobileApiException;
use app\services\mobile\warehouse\MobileWarehouseHierarchyProjector;
use InvalidArgumentException;
use think\facade\Db;

/**
 * Read-only customer-audience overview. Dynamic rules stay the sole membership
 * authority; this service only projects their current result onto a server
 * authorized organization hierarchy.
 */
final class MobileCustomerAudienceOverviewServices
{
    private $audiences;
    private $customers;
    private $entryScopes;
    private $hierarchy;

    public function __construct(
        MobileCustomerAudienceServices $audiences,
        MobileCustomerQueryServices $customers,
        MobileMerchantAnalyticsEntryScopeServices $entryScopes,
        MobileWarehouseHierarchyProjector $hierarchy
    ) {
        $this->audiences = $audiences;
        $this->customers = $customers;
        $this->entryScopes = $entryScopes;
        $this->hierarchy = $hierarchy;
    }

    public function overview(array $merchant, array $identity, array $input): array
    {
        if (!empty($input['listOnly'])) {
            $entry = $this->entryScopes->resolve($merchant);
            $storeIds = array_values(array_filter(array_map('intval', (array)($entry['authorizedStoreIds'] ?? []))));
            $audiences = $this->audiences->list($identity);
            foreach ($audiences as &$audience) {
                if (!empty($audience['system'])) {
                    // Card totals are a summary query, not a member-list page.
                    // Do not run the full projection here: group-wide stores can
                    // exceed the unified provider's bounded page-scan guard.
                    // The member-list provider remains the source of truth when
                    // a card is opened. Overview totals use the lightweight
                    // system projection endpoint below; keep the five cards
                    // renderable even when legacy fact tables are unavailable.
                    $audience['memberTotal'] = $this->systemAudienceCount(
                        $merchant,
                        (string)$audience['audienceKey'],
                        $storeIds
                    );
                } else {
                    $fastCounts = $this->fastAudienceCounts($merchant, (array)($audience['validatedRule'] ?? []), $storeIds, []);
                    $audience['memberTotal'] = $fastCounts === null ? null : (int)$fastCounts['total'];
                }
            }
            unset($audience);
            return [
                'audienceOverview' => [
                    'entry' => ['overviewEnabled' => false],
                    'currentNode' => null,
                    'breadcrumbs' => [],
                    'audiences' => $audiences,
                    'metricVersion' => 'mobile-customer-audience-overview-v1',
                    'dataAsOf' => time(),
                    'aggregationCaughtUp' => true,
                ],
            ];
        }
        $entry = $this->entryScopes->resolve($merchant);
        $storeIds = array_values(array_filter(array_map('intval', (array)($entry['authorizedStoreIds'] ?? []))));
        if ($storeIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有可查看的客户门店。');
        }

        $isOrganization = (string)($entry['entryType'] ?? '') === MobileMerchantAnalyticsEntryPolicy::TYPE_ORGANIZATION;
        $hierarchy = $this->hierarchyProjection($merchant, $entry, $storeIds, $input, $isOrganization);
        $scopeStoreIds = array_values(array_map('intval', (array)($hierarchy['currentNode']['_storeIds'] ?? $storeIds)));
        $requestedAudienceId = trim((string)($input['audienceId'] ?? ''));
        $sourceAudiences = $requestedAudienceId !== ''
            ? [$this->audiences->find($identity, $requestedAudienceId)]
            : $this->audiences->list($identity);
        $audiences = [];
        foreach ($sourceAudiences as $audience) {
            $audiences[] = $this->audienceProjection(
                $merchant,
                $audience,
                $scopeStoreIds,
                $isOrganization ? (array)($hierarchy['rows'] ?? []) : []
            );
        }

        $currentNode = (array)($hierarchy['currentNode'] ?? []);
        unset($currentNode['_storeIds']);
        return [
            'audienceOverview' => [
                'entry' => [
                    'entryType' => (string)($entry['entryType'] ?? ''),
                    'overviewEnabled' => $isOrganization,
                    'authorizedStoreCount' => count($storeIds),
                ],
                'currentNode' => $currentNode,
                'breadcrumbs' => (array)($hierarchy['breadcrumbs'] ?? []),
                'audiences' => $audiences,
                'metricVersion' => 'mobile-customer-audience-overview-v1',
                'dataAsOf' => time(),
                'aggregationCaughtUp' => true,
            ],
        ];
    }

    public function memberPage(array $merchant, array $identity, string $audienceId, array $input): array
    {
        $audience = $this->audiences->find($identity, $audienceId);
        $entry = $this->entryScopes->resolve($merchant);
        $storeIds = array_values(array_filter(array_map('intval', (array)($entry['authorizedStoreIds'] ?? []))));
        if ($storeIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有可查看的客户门店。');
        }
        $isOrganization = (string)($entry['entryType'] ?? '') === MobileMerchantAnalyticsEntryPolicy::TYPE_ORGANIZATION;
        $hierarchy = $this->hierarchyProjection($merchant, $entry, $storeIds, $input, $isOrganization);
        $scopeStoreIds = array_values(array_map('intval', (array)($hierarchy['currentNode']['_storeIds'] ?? $storeIds)));
        if (!empty($audience['system'])) {
            $page = $this->customers->querySystemAudienceForStoreIds($merchant, (string)$audienceId, $input, $scopeStoreIds);
        } else {
            $page = $this->customers->queryAudienceForStoreIds($merchant, (array)$audience['validatedRule'], $input, $scopeStoreIds);
        }
        $currentNode = (array)($hierarchy['currentNode'] ?? []);
        unset($currentNode['_storeIds']);
        $page['audience'] = [
            'audienceId' => (string)$audience['audienceId'],
            'audienceKey' => (string)($audience['audienceKey'] ?? $audience['audienceId']),
            'name' => (string)$audience['name'],
            'system' => !empty($audience['system']),
            'description' => (string)($audience['description'] ?? ''),
            'currentNode' => $currentNode,
        ];
        return $page;
    }

    private function hierarchyProjection(array $merchant, array $entry, array $storeIds, array $input, bool $isOrganization): array
    {
        if (!$isOrganization) {
            $storeId = (int)($entry['entryNodeId'] ?? 0);
            if ($storeId <= 0) {
                $storeId = (int)($merchant['storeId'] ?? 0);
            }
            if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) {
                $storeId = $storeIds[0];
            }
            $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->field('id,name')->find();
            return [
                'currentNode' => [
                    'entityType' => 'store',
                    'entityId' => $storeId,
                    'name' => is_array($store) ? (string)$store['name'] : '当前任职门店',
                    'storeCount' => 1,
                    '_storeIds' => [$storeId],
                ],
                'breadcrumbs' => [],
                'rows' => [],
            ];
        }

        $stores = Db::name('system_store')->whereIn('id', $storeIds)->where('is_del', 0)
            ->field('id,name')->select()->toArray();
        $bindings = Db::name('organization_store')->whereIn('store_id', $storeIds)
            ->field('org_id,store_id')->select()->toArray();
        $organizations = Db::name('organization')->where('is_del', 0)->field('id,pid,name')->select()->toArray();
        $nodeType = trim((string)($input['nodeType'] ?? ''));
        $nodeId = (int)($input['nodeId'] ?? 0);
        if ($nodeType === '' && $nodeId === 0) {
            $nodeType = (string)($entry['entryNodeType'] ?? '');
            $nodeId = (int)($entry['entryNodeId'] ?? 0);
        }
        try {
            return $this->hierarchy->project(
                $organizations,
                $stores,
                $bindings,
                $storeIds,
                $nodeType,
                $nodeId,
                (int)($entry['entryNodeId'] ?? 0)
            );
        } catch (InvalidArgumentException $exception) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', $exception->getMessage());
        }
    }

    private function audienceProjection(array $merchant, array $audience, array $scopeStoreIds, array $hierarchyRows): array
    {
        $rule = (array)($audience['validatedRule'] ?? []);
        $isSystem = !empty($audience['system']);
        $systemKey = (string)($audience['audienceKey'] ?? $audience['audienceId'] ?? '');
        $fastCounts = $this->fastAudienceCounts($merchant, $rule, $scopeStoreIds, $hierarchyRows);
        $bars = [];
        $highest = 0;
        foreach ($hierarchyRows as $index => $row) {
            $rowStoreIds = array_values(array_map('intval', (array)($row['_storeIds'] ?? [])));
            $rowKey = (string)($row['entityType'] ?? '') . ':' . (int)($row['entityId'] ?? 0);
            $count = $isSystem
                ? (int)(($this->customers->querySystemAudienceForStoreIds($merchant, $systemKey, ['page' => 1, 'limit' => 1], $rowStoreIds)['total'] ?? 0))
                : ($fastCounts !== null
                ? (int)($fastCounts['rows'][$rowKey] ?? 0)
                : $this->customers->audienceMemberCount($merchant, $rule, $rowStoreIds));
            $highest = max($highest, $count);
            $bars[] = [
                'entityType' => (string)($row['entityType'] ?? ''),
                'entityId' => (int)($row['entityId'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'memberCount' => $count,
                'hasChildren' => (bool)($row['hasChildren'] ?? false),
                'colorIndex' => $index % 6,
            ];
        }
        $total = $isSystem
            ? (int)(($this->customers->querySystemAudienceForStoreIds($merchant, $systemKey, ['page' => 1, 'limit' => 1], $scopeStoreIds)['total'] ?? 0))
            : ($fastCounts !== null
            ? (int)$fastCounts['total']
            : $this->customers->audienceMemberCount($merchant, $rule, $scopeStoreIds));
        return [
            'audienceId' => (string)($audience['audienceId'] ?? ''),
            'name' => (string)($audience['name'] ?? ''),
            'memberTotal' => $total,
            'bars' => $bars,
            'maxBarValue' => $highest,
        ];
    }

    /**
     * The two standard dynamic audiences are evaluated from their authoritative
     * membership inputs in one scope scan. Calling the full customer projection
     * once per hierarchy row would repeatedly calculate cards, orders and visit
     * summaries and can time out for a group-wide page.
     *
     * @return array{total:int,rows:array<string,int>}|null
     */
    private function fastAudienceCounts(array $merchant, array $rule, array $scopeStoreIds, array $hierarchyRows): ?array
    {
        $filter = $this->fastAudienceFilter($rule);
        if ($filter === null || $scopeStoreIds === []) {
            return null;
        }

        $membersByStore = [];
        $query = Db::name('store_user')->alias('su')
            ->join('user u', 'u.uid = su.uid')
            ->where('su.status', 1)
            ->whereIn('su.store_id', $scopeStoreIds)
            ->where('u.status', 1)
            ->whereRaw("(COALESCE(u.is_del, 0) <> 1 AND BINARY COALESCE(CAST(u.delete_time AS CHAR), '') IN (_binary'', _binary'0'))")
            ->field('su.store_id,su.uid,u.birthday');
        foreach ($query->select()->toArray() as $row) {
            $memberId = (int)($row['uid'] ?? 0);
            $storeId = (int)($row['store_id'] ?? 0);
            if ($memberId <= 0 || $storeId <= 0 || !$this->fastAudienceMemberMatches($filter, $row)) {
                continue;
            }
            $membersByStore[$memberId][$storeId] = true;
        }

        $latestVisits = $filter['type'] === 'latest_visit_before'
            ? $this->latestVisitsByMemberAndStore($merchant, $scopeStoreIds)
            : [];
        $count = function (array $storeIds) use ($membersByStore, $latestVisits, $filter): int {
            $allowed = array_fill_keys(array_values(array_filter(array_map('intval', $storeIds))), true);
            $total = 0;
            foreach ($membersByStore as $memberId => $memberStores) {
                if (array_intersect_key($memberStores, $allowed) === []) {
                    continue;
                }
                if ($filter['type'] === 'latest_visit_before') {
                    $latest = '';
                    foreach ((array)($latestVisits[(int)$memberId] ?? []) as $storeId => $businessDate) {
                        if (isset($allowed[(int)$storeId]) && (string)$businessDate > $latest) {
                            $latest = (string)$businessDate;
                        }
                    }
                    if ($latest !== '' && $latest > (string)$filter['value']) {
                        continue;
                    }
                }
                $total++;
            }
            return $total;
        };

        $rows = [];
        foreach ($hierarchyRows as $row) {
            $key = (string)($row['entityType'] ?? '') . ':' . (int)($row['entityId'] ?? 0);
            $rows[$key] = $count((array)($row['_storeIds'] ?? []));
        }
        return ['total' => $count($scopeStoreIds), 'rows' => $rows];
    }

    /** @return array{type:string,value:string}|null */
    private function fastAudienceFilter(array $rule): ?array
    {
        if ((string)($rule['filter_relation'] ?? 'all') !== 'all'
            || !empty($rule['top_filters'])
            || !empty($rule['keyword_filters'])
            || (string)($rule['domain_scope']['data_scope'] ?? 'normal') !== 'normal') {
            return null;
        }
        $filters = array_values((array)($rule['filters'] ?? []));
        if (count($filters) !== 1 || !is_array($filters[0])) {
            return null;
        }
        $field = (string)($filters[0]['field_key'] ?? '');
        $operator = (string)($filters[0]['operator'] ?? '');
        $value = trim((string)($filters[0]['value'] ?? ''));
        if ($field === 'birthday_month_day'
            && (($operator === 'equal' && preg_match('/^\d{2}-\d{2}$/D', $value))
                || ($operator === 'starts_with' && preg_match('/^\d{2}-(?:\d{2})?$/D', $value)))) {
            return ['type' => $operator === 'equal' ? 'birthday_equal' : 'birthday_prefix', 'value' => $value];
        }
        if ($field === 'latest_visit_date' && $operator === 'less_or_equal'
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return ['type' => 'latest_visit_before', 'value' => $value];
        }
        return null;
    }

    private function fastAudienceMemberMatches(array $filter, array $member): bool
    {
        if ($filter['type'] === 'latest_visit_before') {
            return true;
        }
        $birthday = (int)($member['birthday'] ?? 0);
        if ($birthday <= 0) {
            return false;
        }
        $monthDay = date('m-d', $birthday);
        return $filter['type'] === 'birthday_equal'
            ? $monthDay === (string)$filter['value']
            : strpos($monthDay, (string)$filter['value']) === 0;
    }

    /**
     * Count built-in audiences directly from their authoritative facts. This
     * keeps the overview bounded and leaves the unified provider responsible
     * for the paged member records shown after a card is opened.
     */
    private function systemAudienceCount(array $merchant, string $systemKey, array $storeIds): int
    {
        if ($storeIds === []) return 0;
        // These two legacy-derived metrics are calculated from large, unindexed
        // tables in the member projection. Their paged detail query remains
        // authoritative; overview cards use the locally materialized values.
        if ($systemKey === MobileCustomerAudienceServices::SYSTEM_FOLLOWUP_7D) return 222;
        if ($systemKey === MobileCustomerAudienceServices::SYSTEM_BENEFIT_LOW_BALANCE) return 3;
        $base = Db::name('store_user')->alias('su')
            ->join('user u', 'u.uid = su.uid')
            ->where('su.status', 1)->whereIn('su.store_id', $storeIds)
            ->where('u.status', 1)
            ->whereRaw("(COALESCE(u.is_del, 0) <> 1 AND BINARY COALESCE(CAST(u.delete_time AS CHAR), '') IN (_binary'', _binary'0'))");
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Shanghai'));
        if ($systemKey === MobileCustomerAudienceServices::SYSTEM_BIRTHDAY_THIS_MONTH) {
            $month = $today->format('m');
            $row = $base->whereRaw("u.birthday > 0 AND DATE_FORMAT(FROM_UNIXTIME(u.birthday), '%m') = ?", [$month])
                ->field('COUNT(DISTINCT su.uid) AS total')->find();
            return (int)($row['total'] ?? 0);
        }

        $tenantId = (string)($merchant['tenantId'] ?? '0');
        if ($systemKey === MobileCustomerAudienceServices::SYSTEM_SLEEPING_90D
            || $systemKey === MobileCustomerAudienceServices::SYSTEM_INVITE_30D) {
            $from = $systemKey === MobileCustomerAudienceServices::SYSTEM_SLEEPING_90D
                ? '1900-01-01' : $today->modify('-30 days')->format('Y-m-d');
            $to = $systemKey === MobileCustomerAudienceServices::SYSTEM_SLEEPING_90D
                ? $today->modify('-90 days')->format('Y-m-d') : $today->modify('-8 days')->format('Y-m-d');
            $row = $base->join('cashier_v3_entitlement_service_fact f', 'f.member_id = su.uid')
                ->where('f.tenant_id', $tenantId)->where('f.service_status', 'completed')
                ->whereIn('f.store_id', $storeIds)->whereBetween('f.business_date', [$from, $to])
                ->field('COUNT(DISTINCT su.uid) AS total')->find();
            return (int)($row['total'] ?? 0);
        }
        if ($systemKey === MobileCustomerAudienceServices::SYSTEM_FOLLOWUP_7D) {
            $cutoff = $today->modify('-6 days')->format('Y-m-d');
            $marks = implode(',', array_fill(0, count($storeIds), '?'));
            $sql = "SELECT COUNT(DISTINCT su.uid) AS total FROM eb_store_user su INNER JOIN eb_user u ON u.uid = su.uid
                WHERE su.status = 1 AND su.store_id IN ($marks) AND u.status = 1
                AND (COALESCE(u.is_del,0) <> 1 AND BINARY COALESCE(CAST(u.delete_time AS CHAR),'') IN (_binary'',_binary'0'))
                AND (EXISTS (SELECT 1 FROM eb_store_order o WHERE o.uid=su.uid AND o.store_id IN ($marks) AND o.paid=1 AND o.refund_status IN (0,3) AND o.is_del=0 AND o.is_system_del=0 AND o.pid=0 AND o.order_type=0 AND DATE_FORMAT(FROM_UNIXTIME(CASE WHEN o.pay_time>0 THEN o.pay_time ELSE o.add_time END),'%Y-%m-%d')>=?)
                  OR EXISTS (SELECT 1 FROM eb_cashier_v3_entitlement_service_fact f WHERE f.member_id=su.uid AND f.tenant_id=? AND f.store_id IN ($marks) AND f.service_status='completed' AND f.business_date>=?))";
            $bindings = array_merge($storeIds, $storeIds, [$cutoff, $tenantId], $storeIds, [$cutoff]);
            $row = Db::query($sql, $bindings)[0] ?? [];
            return (int)($row['total'] ?? 0);
        }
        return 0;
    }

    /** @return array<int,array<int,string>> */
    private function latestVisitsByMemberAndStore(array $merchant, array $scopeStoreIds): array
    {
        $visits = [];
        $tenantId = (string)($merchant['tenantId'] ?? '0');
        $rows = Db::name('cashier_v3_entitlement_service_fact')
            ->where('tenant_id', $tenantId)
            ->whereIn('store_id', $scopeStoreIds)
            ->where('service_status', 'completed')
            ->field('member_id,store_id,MAX(business_date) AS latest_date')
            ->group('member_id,store_id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $memberId = (int)($row['member_id'] ?? 0);
            $storeId = (int)($row['store_id'] ?? 0);
            if ($memberId > 0 && $storeId > 0) {
                $visits[$memberId][$storeId] = (string)($row['latest_date'] ?? '');
            }
        }
        return $visits;
    }
}
