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
            $storeIds = $this->currentStoreScope($merchant, (array)($entry['authorizedStoreIds'] ?? []));
            $audiences = $this->audiences->list($identity);
            foreach ($audiences as &$audience) {
                if (!empty($audience['system'])) {
                    // Card totals and the member page must share one authority,
                    // one permission scope and one filter contract. The unified
                    // provider uses SQL count + bounded one-row projection for
                    // these built-in rules, so the overview never maintains a
                    // second set of totals or placeholder values.
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
        // Opening a card without an explicit organization node follows the
        // store selected in the top-left context. Organization drill-down is
        // still honored when nodeType/nodeId are supplied by the client.
        if (!$this->hasNodeSelection($input)) {
            $scopeStoreIds = $this->currentStoreScope($merchant, $scopeStoreIds);
        }
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
        if (!$this->hasNodeSelection($input)) {
            $scopeStoreIds = $this->currentStoreScope($merchant, $scopeStoreIds);
        }
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

    /** Count through the exact same server-side query used by the opened card. */
    private function systemAudienceCount(array $merchant, string $systemKey, array $storeIds): int
    {
        if ($storeIds === []) return 0;
        $page = $this->customers->querySystemAudienceForStoreIds(
            $merchant,
            $systemKey,
            ['page' => 1, 'limit' => 1],
            $storeIds
        );
        return (int)($page['total'] ?? 0);
    }

    /** @return int[] */
    private function currentStoreScope(array $merchant, array $authorizedStoreIds): array
    {
        $activeStoreId = (int)($merchant['storeId'] ?? 0);
        $authorized = array_values(array_unique(array_filter(array_map('intval', $authorizedStoreIds))));
        if ($activeStoreId > 0 && ($authorized === [] || in_array($activeStoreId, $authorized, true))) {
            return [$activeStoreId];
        }
        sort($authorized, SORT_NUMERIC);
        return $authorized;
    }

    private function hasNodeSelection(array $input): bool
    {
        return trim((string)($input['nodeType'] ?? '')) !== '' || (int)($input['nodeId'] ?? 0) > 0;
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
