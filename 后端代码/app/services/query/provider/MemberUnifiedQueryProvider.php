<?php

namespace app\services\query\provider;

use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryCustomFieldKeyCollector;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPreferenceServices;
use think\facade\Db;

/**
 * 会员列表权威投影。先在 SQL 中注入 DataScope，再补齐业务字段并计算派生字段。
 */
class MemberUnifiedQueryProvider
{
    public const PAGE_CODE = 'member_list';
    public const BUSINESS_TIME_ZONE = 'Asia/Shanghai';

    /** @var UnifiedQueryExecutionServices */
    protected $execution;

    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;

    /** @var UnifiedQueryPreferenceServices */
    protected $preferences;

    /** @var array<string,array<string,bool>> */
    protected $columns = [];

    public function __construct(
        UnifiedQueryExecutionServices $execution,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryPreferenceServices $preferences
    ) {
        $this->execution = $execution;
        $this->customFields = $customFields;
        $this->preferences = $preferences;
    }

    public function query(array $context, array $payload): array
    {
        $payload['pageCode'] = self::PAGE_CODE;
        $context['query_cutoff_date'] = $this->trustedCutoffDate($context);
        $context['data_as_of'] = (int)($context['data_as_of'] ?? time());
        $definitions = $this->definitionsForQuery($context, $payload);
        // 先把 UI 输入完整校验成无 SQL 的规范化计划，再允许它影响 SQL 安全下推。
        // 执行器仍会二次校验并执行同一口径，避免下推与内存计算分叉。
        $validatedPlan = $this->execution->validatedPlan(
            self::PAGE_CODE,
            $definitions,
            $this->executionPayload($payload),
            $context
        );
        $normalizedQuery = $this->queryFromPlan($validatedPlan);
        $result = $this->sqlPageFastPath($context, $validatedPlan, $normalizedQuery);
        if ($result === null) {
            $sourceRows = $this->authorizedSourceRows($context, $normalizedQuery);
            $result = $this->execution->execute(
                self::PAGE_CODE,
                $sourceRows,
                $definitions,
                $this->executionPayload($payload),
                $context,
                function (array $row, array $scope): bool {
                    return $this->rowAllowed($row, $scope);
                }
            );
        }

        $preference = $this->preferences->load($context, self::PAGE_CODE);
        return [
            'records' => array_map(function (array $row): array {
                return $this->presentRow($row);
            }, $result['rows']),
            'total' => (int)$result['pagination']['total'],
            'page' => (int)$result['pagination']['page'],
            'pageSize' => (int)$result['pagination']['limit'],
            'isLoading' => false,
            'statusOptions' => $this->statusOptions(),
            'querySettings' => $preference,
            'summaries' => $result['summaries'],
            'groups' => $result['groups'],
            'queryCutoffDate' => $result['queryCutoffDate'],
            'dataAsOf' => $result['dataAsOf'],
            'metricVersion' => $result['schemaVersion'],
            'aggregationCaughtUp' => true,
            'consistencyFingerprint' => $result['consistencyFingerprint'],
            'security' => $result['security'],
        ];
    }

    /**
     * Worker 只消费创建任务时保存的规范化计划和字段定义。
     */
    public function executeFrozenPlan(
        array $context,
        array $plan,
        string $exportScope,
        array $fieldKeys
    ): array {
        $context['query_cutoff_date'] = $this->trustedCutoffDate($context);
        if ((string)($plan['page_code'] ?? '') !== self::PAGE_CODE) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                '导出查询计划与页面不匹配。',
                []
            );
        }
        if ((string)($plan['query_cutoff_date'] ?? '') !== $context['query_cutoff_date']
            || empty($plan['permission_must_be_injected_before_calculation'])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                '导出查询计划的统计时点或权限顺序已损坏。',
                []
            );
        }
        $query = $this->queryFromPlan($plan);
        $query['export'] = [
            'scope' => $exportScope,
            'fields' => array_values(array_unique(array_map('strval', $fieldKeys))),
        ];
        $sourceRows = $this->authorizedSourceRows($context, $query);
        return $this->execution->execute(
            self::PAGE_CODE,
            $sourceRows,
            (array)($plan['custom_definitions'] ?? []),
            $query,
            $context,
            function (array $row, array $scope): bool {
                return $this->rowAllowed($row, $scope);
            }
        );
    }

    protected function queryFromPlan(array $plan): array
    {
        return [
            'page' => (int)($plan['pagination']['page'] ?? 1),
            'limit' => (int)($plan['pagination']['limit'] ?? 20),
            'filters' => (array)($plan['filters'] ?? []),
            'topFilterConditions' => (array)($plan['top_filters'] ?? []),
            'keywordFilters' => (array)($plan['keyword_filters'] ?? []),
            'filterRelation' => (string)($plan['filter_relation'] ?? 'all'),
            'sorts' => (array)($plan['sorts'] ?? []),
            'groupBy' => (array)($plan['groups'] ?? []),
            'summaries' => (array)($plan['summaries'] ?? []),
            'dataScope' => (string)($plan['domain_scope']['data_scope'] ?? 'normal'),
            'businessStatus' => (string)($plan['domain_scope']['business_status'] ?? ''),
            'quickFilters' => (array)($plan['quick_filters'] ?? []),
            'visibleFields' => (array)($plan['visible_fields'] ?? []),
        ];
    }

    protected function definitionsForQuery(array $context, array $payload): array
    {
        $candidate = $payload;
        unset($candidate['fieldVersions'], $candidate['field_versions']);
        $keys = (new UnifiedQueryCustomFieldKeyCollector())->collectFromQuery($candidate);
        if (!$keys) {
            return [];
        }
        $preference = $this->preferences->load($context, self::PAGE_CODE);
        $pinned = (array)($preference['customFieldVersions'] ?? []);
        $visible = [];
        foreach ($this->customFields->listVisible($context, self::PAGE_CODE, true) as $field) {
            $visible[(string)$field['key']] = $field;
        }
        $definitions = [];
        foreach ($keys as $fieldKey) {
            if (!isset($visible[$fieldKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE',
                    '查询使用的自定义字段已不可用，请重新设置。',
                    ['field_key' => $fieldKey]
                );
            }
            $version = (int)($pinned[$fieldKey] ?? $visible[$fieldKey]['version'] ?? 0);
            $definition = $this->customFields->versionDefinition(
                $context,
                self::PAGE_CODE,
                $fieldKey,
                $version
            );
            $definition['page_code'] = self::PAGE_CODE;
            $definitions[] = $definition;
        }
        return $definitions;
    }

    protected function authorizedSourceRows(array $context, array $payload): array
    {
        list($query, $visibleStoreIds, $allStores) =
            $this->authorizedUserQuery($context, $payload, true);
        if ($query === null) {
            return [];
        }
        $rows = $query
            ->order('u.uid', 'asc')
            ->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)
            ->select()
            ->toArray();
        if (count($rows) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE',
                '当前数据范围超过安全计算上限，请先用会员关键词或状态缩小范围。',
                [
                    'max_rows' => UnifiedQueryExecutionServices::MAX_SOURCE_ROWS,
                    'safe_pushdown' => ['data_scope', 'business_status', 'member_keyword'],
                ]
            );
        }
        return $this->projectSourceRows(
            $context,
            $rows,
            $visibleStoreIds,
            $allStores
        );
    }

    /**
     * 大商户的基础列表使用 SQL COUNT + 稳定分页，只补当前页的派生投影。
     * 高级筛选、自定义字段、分组、合计和导出仍走有界统一执行器并 fail-closed。
     */
    protected function sqlPageFastPath(
        array $context,
        array $plan,
        array $normalizedQuery
    ): ?array {
        if (!$this->supportsSqlPageFastPath($plan, $normalizedQuery)) {
            return null;
        }
        list($query, $visibleStoreIds, $allStores) =
            $this->authorizedUserQuery($context, $normalizedQuery, true);
        if ($query === null) {
            $rows = [];
            $total = 0;
        } else {
            $total = (int)(clone $query)->count('u.uid');
            foreach ((array)$normalizedQuery['sorts'] as $sort) {
                $fieldKey = (string)($sort['field_key'] ?? '');
                $column = $fieldKey === 'created_at' ? 'u.add_time' : 'u.uid';
                $query->order($column, (string)$sort['direction']);
            }
            $page = max(1, (int)$normalizedQuery['page']);
            $limit = (int)$normalizedQuery['limit'];
            $rows = $query->page($page, $limit)->select()->toArray();
        }
        $projected = $this->projectSourceRows(
            $context,
            $rows,
            $visibleStoreIds,
            $allStores
        );
        $pageQuery = $normalizedQuery;
        $pageQuery['page'] = 1;
        $result = $this->execution->execute(
            self::PAGE_CODE,
            $projected,
            [],
            $pageQuery,
            $context,
            function (array $row, array $scope): bool {
                return $this->rowAllowed($row, $scope);
            }
        );
        $page = max(1, (int)$normalizedQuery['page']);
        $limit = (int)$normalizedQuery['limit'];
        $result['pagination'] = [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int)ceil($total / $limit),
        ];
        $result['security']['sqlPaginatedAfterDataScope'] = true;
        $result['security']['authorizedRowCount'] = $total;
        return $result;
    }

    protected function supportsSqlPageFastPath(array $plan, array $query): bool
    {
        foreach ([
            'custom_definitions',
            'filters',
            'top_filters',
            'groups',
            'summaries',
            'quick_filters',
        ] as $key) {
            if (!empty($plan[$key])) {
                return false;
            }
        }
        if (!empty($query['keywordFilters']) && $this->pushdownKeyword($query) === '') {
            return false;
        }
        if ((string)($query['dataScope'] ?? 'normal') === 'all') {
            $businessStatus = trim((string)($query['businessStatus'] ?? ''));
            if ($businessStatus !== '' && !in_array($businessStatus, [
                '正常', 'normal',
                '已停用', 'inactive', 'disabled',
                '已注销', 'cancelled', 'deleted',
            ], true)) {
                return false;
            }
        }
        $sorts = (array)($query['sorts'] ?? []);
        if (count($sorts) === 1) {
            return (string)($sorts[0]['field_key'] ?? '') === 'member_id'
                && (string)($sorts[0]['direction'] ?? '') === 'asc';
        }
        return count($sorts) === 2
            && (string)($sorts[0]['field_key'] ?? '') === 'created_at'
            && in_array((string)($sorts[0]['direction'] ?? ''), ['asc', 'desc'], true)
            && (string)($sorts[1]['field_key'] ?? '') === 'member_id'
            && (string)($sorts[1]['direction'] ?? '') === 'asc';
    }

    /**
     * @return array{0:mixed,1:array,2:bool}
     */
    protected function authorizedUserQuery(
        array $context,
        array $payload,
        bool $exactStatus
    ): array {
        $allStores = !empty($context['all_stores'])
            || (array_key_exists('visible_store_ids', $context)
                && $context['visible_store_ids'] === null);
        $visibleStoreIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($context['visible_store_ids'] ?? [])
        ))));
        if (!$allStores && !$visibleStoreIds) {
            return [null, $visibleStoreIds, $allStores];
        }

        $userColumns = $this->tableColumns('user');
        foreach (['uid', 'nickname', 'real_name', 'phone', 'bar_code', 'belong_store_id', 'status', 'is_del', 'delete_time', 'add_time', 'level'] as $required) {
            if (!isset($userColumns[$required])) {
                throw new \RuntimeException('会员权威表缺少统一查询字段：' . $required);
            }
        }
        $selectedFields = [
            'u.uid', 'u.nickname', 'u.real_name', 'u.phone', 'u.bar_code',
            'u.belong_store_id', 'u.status', 'u.is_del', 'u.delete_time',
            'u.add_time', 'u.level',
        ];
        if (isset($userColumns['now_money'])) {
            $selectedFields[] = 'u.now_money';
        }
        $query = Db::name('user')->alias('u')->field($selectedFields);
        if (!$allStores) {
            $storeUserColumns = $this->tableColumns('store_user');
            if (!isset($storeUserColumns['uid'], $storeUserColumns['store_id'], $storeUserColumns['status'])) {
                throw new \RuntimeException('会员门店关系表缺少数据权限字段');
            }
            $query->whereIn('u.uid', function ($subQuery) use ($visibleStoreIds) {
                $subQuery->name('store_user')
                    ->where('status', 1)
                    ->whereIn('store_id', $visibleStoreIds)
                    ->field('uid');
            });
        }
        $this->applyStatusPushdown($query, $payload, $exactStatus);
        $keyword = $this->pushdownKeyword($payload);
        if ($keyword !== '') {
            $needle = function_exists('mb_strtolower')
                ? mb_strtolower($keyword, 'UTF-8')
                : strtolower($keyword);
            $query->whereRaw(
                "(LOCATE(?,LOWER(CASE WHEN TRIM(IFNULL(u.real_name,''))<>'' THEN TRIM(u.real_name) WHEN TRIM(IFNULL(u.nickname,''))<>'' THEN TRIM(u.nickname) ELSE '未命名会员' END))>0 OR LOCATE(?,LOWER(IFNULL(u.phone,'')))>0 OR LOCATE(?,LOWER(IFNULL(u.bar_code,'')))>0)",
                [$needle, $needle, $needle]
            );
        }
        return [$query, $visibleStoreIds, $allStores];
    }

    protected function projectSourceRows(
        array $context,
        array $rows,
        array $visibleStoreIds,
        bool $allStores
    ): array {
        if (!$rows) {
            return [];
        }

        $uids = array_values(array_unique(array_map('intval', array_column($rows, 'uid'))));
        $relations = $this->storeRelations($uids, $visibleStoreIds, $allStores);
        $displayStoreIds = $this->displayStoreIds($rows, $relations, $context);
        $stores = $this->storeNames(array_values(array_unique(array_values($displayStoreIds))));
        $levels = $this->levelNames($rows);
        $labels = $this->memberLabels($uids);
        $exclusive = $this->exclusiveServicePeople($uids, $visibleStoreIds, $allStores);
        $cards = $this->cardSummaries($uids, $visibleStoreIds, $allStores);
        $debts = $this->debtSummaries($uids, $visibleStoreIds, $allStores);
        $purchases = $this->purchaseSummaries($uids, $visibleStoreIds, $allStores);
        $visits = $this->visitSummaries($uids, $visibleStoreIds, $allStores);

        $result = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $state = $this->memberState($row);
            // trim 只用于判定历史脏数据是否为空。投影本身必须保留权威原文，
            // 否则列表与导出会悄悄改写姓名中的制表符、换行等字符；XLSX 层会
            // 统一以显式字符串写入，既保持原值又避免把数据当作公式执行。
            $realName = (string)($row['real_name'] ?? '');
            $nickname = (string)($row['nickname'] ?? '');
            $name = trim($realName) !== '' ? $realName : $nickname;
            if (trim($name) === '') {
                $name = '';
            }
            $tagList = $labels[$uid] ?? [];
            $result[] = [
                'member_id' => $uid,
                'member_name' => $name !== '' ? $name : '未命名会员',
                'phone' => (string)($row['phone'] ?? ''),
                'member_no' => (string)($row['bar_code'] ?? ''),
                'member_status' => $state['label'],
                'member_level' => (string)($levels[(int)($row['level'] ?? 0)] ?? '普通会员'),
                'member_tag' => implode('、', $tagList),
                'store' => (string)($stores[$displayStoreIds[$uid] ?? 0] ?? ''),
                'exclusive_service_staff' => (string)($exclusive[$uid] ?? ''),
                'account_balance' => $this->amount($row['now_money'] ?? 0),
                'active_card_count' => (int)($cards[$uid]['active_card_count'] ?? 0),
                'remaining_project_times' => (int)($cards[$uid]['remaining_project_times'] ?? 0),
                'remaining_project_amount' => (string)($cards[$uid]['remaining_project_amount'] ?? '0.00'),
                'debt_amount' => (string)($debts[$uid] ?? '0.00'),
                'total_consumption_amount' => (string)($purchases[$uid]['total_amount'] ?? '0.00'),
                'visit_count' => (int)($visits[$uid]['visit_count'] ?? 0),
                'latest_purchase_date' => (string)($purchases[$uid]['latest_date'] ?? ''),
                'last_service_staff' => (string)($visits[$uid]['last_staff_name'] ?? ''),
                'latest_visit_date' => (string)($visits[$uid]['latest_date'] ?? ''),
                'created_at' => $this->dateTime($row['add_time'] ?? 0),
                '_member_tags' => $tagList,
                '_member_status_code' => $state['code'],
                '_scope_store_ids' => $relations[$uid] ?? [],
                '_display_store_id' => (int)($displayStoreIds[$uid] ?? 0),
            ];
        }
        return $result;
    }

    protected function rowAllowed(array $row, array $scope): bool
    {
        $allStores = !empty($scope['all_stores'])
            || (array_key_exists('visible_store_ids', $scope)
                && $scope['visible_store_ids'] === null);
        $visible = array_values(array_unique(array_map(
            'intval',
            (array)($scope['visible_store_ids'] ?? [])
        )));
        if (!$allStores && !array_intersect($visible, (array)($row['_scope_store_ids'] ?? []))) {
            return false;
        }
        $statusCode = (string)($row['_member_status_code'] ?? '');
        if ((string)($scope['requested_data_scope'] ?? 'normal') === 'normal') {
            return $statusCode === 'normal';
        }
        $requested = trim((string)($scope['requested_business_status'] ?? ''));
        if ($requested === '') {
            return true;
        }
        $map = [
            '正常' => 'normal', 'normal' => 'normal',
            '已停用' => 'inactive', 'inactive' => 'inactive', 'disabled' => 'inactive',
            '已注销' => 'cancelled', 'cancelled' => 'cancelled', 'deleted' => 'cancelled',
        ];
        return ($map[$requested] ?? '') === $statusCode;
    }

    protected function presentRow(array $row): array
    {
        $customValues = [];
        foreach ($row as $key => $value) {
            if (strpos((string)$key, 'cf_') === 0) {
                $customValues[$key] = $value;
            }
        }
        return [
            'id' => (string)$row['member_id'],
            'memberId' => (int)$row['member_id'],
            'name' => (string)$row['member_name'],
            'phone' => (string)$row['phone'],
            'memberNo' => (string)$row['member_no'],
            'status' => (string)$row['member_status'],
            'level' => (string)$row['member_level'],
            'tags' => (array)($row['_member_tags'] ?? []),
            'storeId' => (int)($row['_display_store_id'] ?? 0),
            'storeName' => (string)$row['store'],
            'exclusiveServiceStaff' => (string)$row['exclusive_service_staff'],
            'accountBalance' => (string)$row['account_balance'],
            'activeCardCount' => (int)$row['active_card_count'],
            'remainingProjectTimes' => (int)$row['remaining_project_times'],
            'remainingProjectAmount' => (string)$row['remaining_project_amount'],
            'debtAmount' => (string)$row['debt_amount'],
            'totalConsumptionAmount' => (string)$row['total_consumption_amount'],
            'visitCount' => (int)$row['visit_count'],
            'latestPurchaseDate' => (string)$row['latest_purchase_date'],
            'lastServiceStaff' => (string)$row['last_service_staff'],
            'latestVisitDate' => (string)$row['latest_visit_date'],
            'createdAt' => (string)$row['created_at'],
            'queryFieldValues' => $customValues,
            'queryDisplayValues' => $customValues,
        ];
    }

    protected function storeRelations(array $uids, array $visible, bool $allStores): array
    {
        $columns = $this->tableColumns('store_user');
        if (!isset($columns['uid'], $columns['store_id'], $columns['status'])) {
            return [];
        }
        $query = Db::name('store_user')->whereIn('uid', $uids)->where('status', 1);
        if (!$allStores) {
            $query->whereIn('store_id', $visible);
        }
        $result = [];
        foreach ($query->field('uid,store_id')->select()->toArray() as $row) {
            $uid = (int)$row['uid'];
            $storeId = (int)$row['store_id'];
            if ($storeId > 0) {
                $result[$uid][] = $storeId;
            }
        }
        foreach ($result as &$storeIds) {
            $storeIds = array_values(array_unique($storeIds));
        }
        unset($storeIds);
        return $result;
    }

    protected function displayStoreIds(array $rows, array $relations, array $context): array
    {
        $current = (int)($context['store_id'] ?? 0);
        $result = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $candidate = (array)($relations[$uid] ?? []);
            $belong = (int)($row['belong_store_id'] ?? 0);
            if ($current > 0 && in_array($current, $candidate, true)) {
                $result[$uid] = $current;
            } elseif ($belong > 0 && (in_array($belong, $candidate, true) || !$candidate)) {
                $result[$uid] = $belong;
            } else {
                sort($candidate, SORT_NUMERIC);
                $result[$uid] = (int)($candidate[0] ?? 0);
            }
            if (!$candidate && $result[$uid] > 0) {
                $relations[$uid] = [$result[$uid]];
            }
        }
        return $result;
    }

    protected function storeNames(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $columns = $this->tableColumns('system_store');
        return $storeIds && isset($columns['id'], $columns['name'])
            ? Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id')
            : [];
    }

    protected function levelNames(array $rows): array
    {
        $columns = $this->tableColumns('system_user_level');
        if (!isset($columns['id'], $columns['name'])) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'level')))));
        return $ids ? Db::name('system_user_level')->whereIn('id', $ids)->column('name', 'id') : [];
    }

    protected function memberLabels(array $uids): array
    {
        $relationColumns = $this->tableColumns('user_label_relation');
        $labelColumns = $this->tableColumns('user_label');
        if (!isset($relationColumns['id'], $relationColumns['uid'], $relationColumns['label_id'])
            || !isset($labelColumns['id'], $labelColumns['label_name'])) {
            return [];
        }
        $rows = Db::name('user_label_relation')->alias('r')
            ->join('user_label l', 'l.id = r.label_id')
            ->whereIn('r.uid', $uids)
            ->field('r.uid,l.label_name')
            ->order('r.id', 'asc')
            ->select()
            ->toArray();
        $result = [];
        foreach ($rows as $row) {
            $label = trim((string)$row['label_name']);
            if ($label !== '') {
                $result[(int)$row['uid']][] = $label;
            }
        }
        return $result;
    }

    protected function exclusiveServicePeople(array $uids, array $visible, bool $allStores): array
    {
        $columns = $this->tableColumns('member_exclusive_service');
        if (!isset($columns['member_id'], $columns['status'], $columns['staff_name'])
            || (!$allStores && !isset($columns['store_id']))) {
            return [];
        }
        $query = Db::name('member_exclusive_service')
            ->whereIn('member_id', $uids)
            ->where('status', 1);
        if (!$allStores) {
            $query->whereIn('store_id', $visible);
        }
        return $query->column('staff_name', 'member_id');
    }

    protected function cardSummaries(array $uids, array $visible, bool $allStores): array
    {
        $holderColumns = $this->tableColumns('user_card_holder');
        $orderColumns = $this->tableColumns('store_order');
        if (!isset(
            $holderColumns['uid'],
            $holderColumns['oid'],
            $holderColumns['write_surplus_times'],
            $holderColumns['is_del'],
            $orderColumns['id'],
            $orderColumns['paid'],
            $orderColumns['is_del'],
            $orderColumns['is_system_del'],
            $orderColumns['refund_status']
        ) || (!$allStores && !isset($orderColumns['store_id']))) {
            return [];
        }
        $query = Db::name('user_card_holder')->alias('h')
            ->join('store_order o', 'o.id = h.oid')
            ->whereIn('h.uid', $uids)
            ->where('h.is_del', 0)
            ->where('h.write_surplus_times', '>', 0)
            ->where('o.paid', 1)
            ->where('o.is_del', 0)
            ->where('o.is_system_del', 0)
            ->where('o.refund_status', 0);
        if (isset($orderColumns['terminal_action'])) {
            $query->where('o.terminal_action', 0);
        }
        if (isset($orderColumns['card_upgrade_use_oid'])) {
            $query->where('o.card_upgrade_use_oid', 0);
        }
        if (!$allStores) {
            $query->whereIn('o.store_id', $visible);
        }
        $rows = $query->field('h.uid,h.oid,h.write_surplus_times')
            ->select()
            ->toArray();
        $result = [];
        $oids = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $result[$uid]['holder_ids'][(int)$row['oid']] = true;
            $result[$uid]['remaining_project_times'] = (int)($result[$uid]['remaining_project_times'] ?? 0)
                + max(0, (int)$row['write_surplus_times']);
            $result[$uid]['remaining_project_amount'] = '0.00';
            $oids[(int)$row['oid']] = $uid;
        }
        $cartColumns = $this->tableColumns('store_order_cart_info');
        if ($oids && isset($cartColumns['oid'], $cartColumns['pay_price'], $cartColumns['write_times'], $cartColumns['write_surplus_times'])) {
            $cartQuery = Db::name('store_order_cart_info')
                ->whereIn('oid', array_keys($oids))
                ->where('write_surplus_times', '>', 0);
            if (isset($cartColumns['cart_type'])) {
                $cartQuery->where('cart_type', 2);
            }
            if (isset($cartColumns['product_type'])) {
                $cartQuery->where('product_type', 6);
            }
            if (isset($cartColumns['is_writeoff'])) {
                $cartQuery->where('is_writeoff', 0);
            }
            foreach ($cartQuery->field('oid,pay_price,write_times,write_surplus_times')->select()->toArray() as $cart) {
                $uid = (int)($oids[(int)$cart['oid']] ?? 0);
                $times = (int)($cart['write_times'] ?? 0);
                if ($uid <= 0 || $times <= 0) {
                    continue;
                }
                $remaining = max(0, (int)$cart['write_surplus_times']);
                $unit = bcdiv($this->amount($cart['pay_price'] ?? 0), (string)$times, 8);
                $amount = bcmul($unit, (string)$remaining, 2);
                $result[$uid]['remaining_project_amount'] = bcadd(
                    (string)$result[$uid]['remaining_project_amount'],
                    $amount,
                    2
                );
            }
        }
        foreach ($result as &$summary) {
            $summary['active_card_count'] = count((array)($summary['holder_ids'] ?? []));
            unset($summary['holder_ids']);
        }
        unset($summary);
        return $result;
    }

    protected function debtSummaries(array $uids, array $visible, bool $allStores): array
    {
        $columns = $this->tableColumns('store_debt');
        if (!isset($columns['uid'], $columns['total_debt'], $columns['repaid_debt'], $columns['status'])
            || (!$allStores && !isset($columns['store_id']))) {
            return [];
        }
        $query = Db::name('store_debt')
            ->whereIn('uid', $uids)
            ->where('status', 0);
        if (!$allStores) {
            $query->whereIn('store_id', $visible);
        }
        $rows = $query->field('uid,total_debt,repaid_debt')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $pending = bcsub($this->amount($row['total_debt'] ?? 0), $this->amount($row['repaid_debt'] ?? 0), 2);
            if (bccomp($pending, '0', 2) > 0) {
                $uid = (int)$row['uid'];
                $result[$uid] = bcadd((string)($result[$uid] ?? '0.00'), $pending, 2);
            }
        }
        return $result;
    }

    protected function purchaseSummaries(array $uids, array $visible, bool $allStores): array
    {
        $columns = $this->tableColumns('store_order');
        foreach (['uid', 'paid', 'pay_price', 'refund_status', 'is_del', 'is_system_del', 'pid', 'order_type', 'store_id', 'add_time', 'pay_time'] as $required) {
            if (!isset($columns[$required])) {
                return [];
            }
        }
        $base = function () use ($uids, $visible, $allStores, $columns) {
            $query = Db::name('store_order')->alias('o')
                ->whereIn('o.uid', $uids)
                ->where('o.paid', 1)
                ->whereIn('o.refund_status', [0, 3])
                ->where('o.is_del', 0)
                ->where('o.is_system_del', 0)
                ->where('o.pid', 0)
                ->where('o.order_type', 0);
            // 补交、作废和卡升级来源单不是新的购买事实，避免重复累计。
            if (isset($columns['is_debt_repay'])) {
                $query->where('o.is_debt_repay', 0);
            }
            if (isset($columns['terminal_action'])) {
                $query->where('o.terminal_action', 0);
            }
            if (isset($columns['card_upgrade_use_oid'])) {
                $query->where('o.card_upgrade_use_oid', 0);
            }
            if (!$allStores) {
                $query->whereIn('o.store_id', $visible);
            }
            return $query;
        };
        $latest = $base()
            ->field('o.uid,MAX(CASE WHEN o.pay_time > 0 THEN o.pay_time ELSE o.add_time END) AS latest_time')
            ->group('o.uid')
            ->select()
            ->toArray();
        $result = [];
        foreach ($latest as $row) {
            $timestamp = (int)($row['latest_time'] ?? 0);
            $result[(int)$row['uid']] = [
                'latest_date' => $this->businessDate($timestamp),
                'total_amount' => '0.00',
            ];
        }
        foreach ($base()
            // 总消费金额是有效成交的实付总额，包含现金与余额，不能用现金业绩代替。
            ->field('o.uid,SUM(o.pay_price) AS total_amount')
            ->group('o.uid')
            ->select()
            ->toArray() as $row) {
            $uid = (int)$row['uid'];
            if (!isset($result[$uid])) {
                $result[$uid] = ['latest_date' => '', 'total_amount' => '0.00'];
            }
            $result[$uid]['total_amount'] = $this->amount($row['total_amount'] ?? 0);
        }
        return $result;
    }

    protected function visitSummaries(array $uids, array $visible, bool $allStores): array
    {
        $columns = $this->tableColumns('store_order_writeoff');
        foreach (['id', 'uid', 'relation_id', 'add_time', 'status', 'staff_id'] as $required) {
            if (!isset($columns[$required])) {
                return [];
            }
        }
        $query = Db::name('store_order_writeoff')
            ->whereIn('uid', $uids)
            ->where('status', 0);
        if (!$allStores) {
            $query->whereIn('relation_id', $visible);
        }
        // add_time 是 Unix 秒；不用 FROM_UNIXTIME，避免 MySQL session time_zone 与应用业务时区
        // 不一致时把同一核销同时计入前一天、却显示为当天。字面基准兼容 MySQL 5.6。
        $businessDay = "DATE(DATE_ADD('1970-01-01 08:00:00', INTERVAL add_time SECOND))";
        $rows = $query
            ->field("uid,COUNT(DISTINCT {$businessDay}) AS visit_count,MAX(add_time) AS latest_time,SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY add_time DESC,id DESC), ',', 1) AS latest_writeoff_id,SUBSTRING_INDEX(GROUP_CONCAT(staff_id ORDER BY add_time DESC,id DESC), ',', 1) AS last_staff_id")
            ->group('uid')
            ->select()
            ->toArray();
        $latestWriteoffIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_column($rows, 'latest_writeoff_id')
        ))));
        $snapshotNames = $this->writeoffStaffSnapshots($latestWriteoffIds);
        $staffIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'last_staff_id')))));
        $staffNames = [];
        $staffColumns = $this->tableColumns('system_store_staff');
        if ($staffIds && isset($staffColumns['id'], $staffColumns['staff_name'])) {
            $staffNames = Db::name('system_store_staff')->whereIn('id', $staffIds)->column('staff_name', 'id');
        }
        $result = [];
        foreach ($rows as $row) {
            $timestamp = (int)($row['latest_time'] ?? 0);
            $writeoffId = (int)($row['latest_writeoff_id'] ?? 0);
            $snapshot = (string)($snapshotNames[$writeoffId] ?? '');
            $staffId = (int)($row['last_staff_id'] ?? 0);
            $result[(int)$row['uid']] = [
                'visit_count' => (int)($row['visit_count'] ?? 0),
                'latest_date' => $this->businessDate($timestamp),
                'last_staff_name' => $snapshot !== '' ? $snapshot : (string)($staffNames[$staffId] ?? ''),
            ];
        }
        return $result;
    }

    protected function businessDate(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(self::BUSINESS_TIME_ZONE))
            ->format('Y-m-d');
    }

    /**
     * 核销时选择的历史服务人员以 staff_yeji 劳动业绩快照为准。
     * 同一次核销允许多人，按记录 id 稳定排序、去重后拼接；没有快照时调用方
     * 才回退当前员工档案名称，避免员工改名导致历史展示漂移。
     *
     * @param int[] $writeoffIds
     * @return array<int,string>
     */
    protected function writeoffStaffSnapshots(array $writeoffIds): array
    {
        if (!$writeoffIds) {
            return [];
        }
        $columns = $this->tableColumns('staff_yeji');
        foreach (['id', 'link_id', 'type', 'status', 'staff_name'] as $required) {
            if (!isset($columns[$required])) {
                return [];
            }
        }
        $rows = Db::name('staff_yeji')
            ->whereIn('link_id', $writeoffIds)
            ->where('type', 3)
            ->where('status', 0)
            ->field('id,link_id,staff_name')
            ->order('link_id', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $names = [];
        foreach ($rows as $row) {
            $linkId = (int)($row['link_id'] ?? 0);
            $name = trim((string)($row['staff_name'] ?? ''));
            if ($linkId > 0 && $name !== '') {
                $names[$linkId][$name] = true;
            }
        }
        $result = [];
        foreach ($names as $linkId => $items) {
            $result[(int)$linkId] = implode('、', array_keys($items));
        }
        return $result;
    }

    protected function memberState(array $row): array
    {
        if ((int)($row['is_del'] ?? 0) === 1 || !empty($row['delete_time'])) {
            return ['code' => 'cancelled', 'label' => '已注销'];
        }
        if ((int)($row['status'] ?? 0) !== 1) {
            return ['code' => 'inactive', 'label' => '已停用'];
        }
        return ['code' => 'normal', 'label' => '正常'];
    }

    protected function statusOptions(): array
    {
        return [
            ['value' => '正常', 'label' => '正常', 'normal' => true],
            ['value' => '已停用', 'label' => '已停用', 'normal' => false],
            ['value' => '已注销', 'label' => '已注销', 'normal' => false],
        ];
    }

    /**
     * 这里只下推会员状态的必要条件，最终仍由 rowAllowed 统一判定。
     * 采用“超集下推”可缩小 10k 安全窗口，同时兼容历史脏数据而不漏合法结果。
     */
    protected function applyStatusPushdown(
        $query,
        array $payload,
        bool $exact = false
    ): void
    {
        $scope = (string)($payload['dataScope'] ?? 'normal');
        $requested = $scope === 'normal'
            ? 'normal'
            : trim((string)($payload['businessStatus'] ?? ''));
        $map = [
            '正常' => 'normal', 'normal' => 'normal',
            '已停用' => 'inactive', 'inactive' => 'inactive', 'disabled' => 'inactive',
            '已注销' => 'cancelled', 'cancelled' => 'cancelled', 'deleted' => 'cancelled',
        ];
        $status = $map[$requested] ?? '';
        if ($status === 'normal') {
            // 所有最终“正常”会员必定 status=1；删除标记由最终判定继续过滤。
            $query->where('u.status', 1);
            if ($exact) {
                $query->where('u.is_del', '<>', 1)->whereNull('u.delete_time');
            }
        } elseif ($status === 'inactive') {
            $query->where(function ($where) {
                $where->where('u.status', '<>', 1)->whereOr(function ($empty) {
                    $empty->whereNull('u.status');
                });
            });
            if ($exact) {
                $query->where('u.is_del', '<>', 1)->whereNull('u.delete_time');
            }
        } elseif ($status === 'cancelled') {
            // delete_time 在不同历史库可能是 timestamp/int/varchar；IS NOT NULL 只会
            // 多取空值形态，不会漏掉最终 cancelled，再由 rowAllowed 精确收口。
            $query->where(function ($where) {
                $where->where('u.is_del', 1)->whereOr(function ($deleted) {
                    $deleted->whereNotNull('u.delete_time');
                });
            });
        }
    }

    /**
     * 只识别 validatedPlan 生成的标准三字段关键字 OR，禁止从任意筛选猜测 SQL。
     */
    protected function pushdownKeyword(array $payload): string
    {
        $filters = $payload['keywordFilters'] ?? [];
        if (!is_array($filters) || count($filters) !== 3) {
            return '';
        }
        $values = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)
                || (string)($filter['operator'] ?? '') !== 'contains'
                || !is_scalar($filter['value'] ?? null)) {
                return '';
            }
            $fieldKey = (string)($filter['field_key']
                ?? ($filter['fieldKey'] ?? ($filter['field'] ?? '')));
            if (!in_array($fieldKey, ['member_name', 'phone', 'member_no'], true)
                || isset($values[$fieldKey])) {
                return '';
            }
            $values[$fieldKey] = (string)$filter['value'];
        }
        if (array_keys($values) !== ['member_name', 'phone', 'member_no']) {
            ksort($values);
            $expected = ['member_name' => true, 'member_no' => true, 'phone' => true];
            if (array_fill_keys(array_keys($values), true) !== $expected) {
                return '';
            }
        }
        if (count(array_unique(array_values($values), SORT_STRING)) !== 1) {
            return '';
        }
        return trim((string)reset($values));
    }

    protected function executionPayload(array $payload): array
    {
        unset($payload['queryCutoffDate'], $payload['query_cutoff_date']);
        return $payload;
    }

    protected function trustedCutoffDate(array $context): string
    {
        $value = (string)($context['query_cutoff_date'] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUTOFF_DATE_INVALID',
                '查询截止日期无效，请刷新后重试。',
                []
            );
        }
        return $value;
    }

    protected function tableColumns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $fullTable = (string)Db::name($table)->getTable();
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $fullTable)) {
            throw new \RuntimeException('数据表名称不合法');
        }
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $fullTable . '`');
        } catch (\Throwable $exception) {
            return $this->columns[$table] = [];
        }
        $columns = [];
        foreach ($rows as $row) {
            $field = (string)($row['Field'] ?? ($row['field'] ?? ''));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
        return $this->columns[$table] = $columns;
    }

    protected function amount($value): string
    {
        if (!function_exists('bcadd')) {
            throw new \RuntimeException('统一查询金额计算需要 ext-bcmath');
        }
        return bcadd((string)($value === '' || $value === null ? 0 : $value), '0', 2);
    }

    protected function dateTime($value): string
    {
        if (is_numeric($value) && (int)$value > 0) {
            return date('Y-m-d H:i:s', (int)$value);
        }
        $timestamp = strtotime((string)$value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
