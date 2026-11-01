<?php

namespace app\services\cashier\v3\order;

use app\services\query\UnifiedQueryCustomFieldKeyCollector;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\UnifiedQueryProvider;
use think\facade\Db;
use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices;

/**
 * 将订单中心现有的权威只读投影接入统一查询与导出。
 *
 * 列表按实时可信范围、导出 worker 按重建的范围读取有界权威候选，再共用筛选和分页。
 * 超过安全窗口明确失败，不对截断候选返回误导性总数；不相信客户端保存的门店权限。
 */
abstract class AbstractCashierV3OrderCenterUnifiedQueryProvider implements UnifiedQueryProvider
{
    /** @var UnifiedQueryExecutionServices */
    protected $execution;
    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;
    /** @var UnifiedQueryPreferenceServices */
    protected $preferences;
    /** @var CashierV3SalesOrderQueryServices */
    protected $salesQueries;
    /** @var CashierV3OrderCenterRecordQueryServices */
    protected $recordQueries;
    /** 请求内可信权限与首批分页元数据；不从浏览器反序列化。 */
    private $liveScopes;
    private $sourcePayload = [];
    private $sourcePage = [];

    public function __construct(
        CashierV3OrderCenterQueryExecutionServices $execution,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryPreferenceServices $preferences
    ) {
        $this->execution = $execution;
        $this->customFields = $customFields;
        $this->preferences = $preferences;
        // 统一查询注册器由容器按类型创建 provider。若把订单查询服务也声明为
        // 构造参数，容器会继续递归构造游标签名器（其 secret 不是容器依赖），
        // 从而让登录后的工作台初始化失败。订单中心既有模块也是在服务内部创建
        // 这两个只读查询服务，保持相同边界即可。
        $this->salesQueries = new CashierV3SalesOrderQueryServices();
        $this->recordQueries = new CashierV3OrderCenterRecordQueryServices();
    }

    abstract public function pageCode(): string;

    /** 列表与导出复用同一执行路径；仅替换只读结果，不接触写命令和资源版本。 */
    public function queryPage(array $context, array $payload, $operatorScope, $dataScope): array
    {
        $this->liveScopes = [$operatorScope, $dataScope];
        try {
            $definitions = $this->definitionsForQuery($context, $payload);
            $direct = $this->directOrdinaryPage($payload, $definitions, $operatorScope, $dataScope);
            if ($direct !== null) {
                return $direct;
            }
            $result = $this->run($context, $payload, $definitions);
            return array_replace($this->sourcePage, [
                'records' => $this->publicRows($result['rows']),
                'total' => (int)$result['pagination']['total'],
                'page' => (int)$result['pagination']['page'],
                'pageSize' => (int)$result['pagination']['limit'],
                'paginationMode' => 'offset', 'paginationCursor' => ['current' => '', 'next' => ''],
                'hasMore' => (int)$result['pagination']['page'] * (int)$result['pagination']['limit'] < (int)$result['pagination']['total'],
            ]);
        } finally {
            $this->liveScopes = null;
            $this->sourcePayload = [];
            $this->sourcePage = [];
        }
    }

    /**
     * 普通日期/状态/门店列表直接使用领域 Reader 的数据库分页。
     *
     * 只有没有自定义字段、组合筛选、分组、汇总、关键词或导出的普通列表才可
     * 进入本路径；任何高级查询都回退现有统一执行器，不能为提速改变筛选口径。
     * 单类页只组装当前页，全部订单按窄索引归并选页，派生筛选按有界批次执行。
     */
    private function directOrdinaryPage(
        array $payload,
        array $definitions,
        $operatorScope,
        $dataScope
    ) {
        if (!$this->isOrdinaryPageQuery($payload, $definitions)) {
            return null;
        }
        $policy = (new CashierV3BusinessSourcePolicyServices())->read();
        if (($policy['enabled'] ?? true) === false) {
            $payload = CashierV3OrderCenterQueryExecutionServices::withoutBusinessSource($payload);
        }
        $this->sourcePayload = $this->sourceQuery($payload);
        $type = CashierV3OrderCenterUnifiedQueryContract::typeForPage($this->pageCode());
        if ($type === 'all') {
            return $this->directAllOrderPage($payload, $operatorScope, $dataScope);
        }
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(UnifiedQueryExecutionServices::MAX_PAGE_SIZE, max(1, (int)($payload['pageSize'] ?? $payload['limit'] ?? 20)));
        $source = array_replace($this->sourcePayload, [
            'recordType' => $type,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
        if ($type === 'sales') {
            $source['status'] = ($source['dataScope'] ?? 'normal') === 'normal'
                ? 'normal' : ($source['businessStatus'] ?? '');
            $result = $this->directSalesSourcePage($source, $operatorScope, $dataScope);
            if ($result === null) return null;
            $records = $this->salesQueries->attachCurrentItemCategories((array)($result['records'] ?? []));
        } else {
            $result = $this->recordQueries->queryRecords($source, $operatorScope, $dataScope);
            if ($result === null) return null;
            $records = (array)($result['records'] ?? []);
        }
        $rows = (new CashierV3OrderCenterQueryIdentities())->attach(
            $type,
            $this->projectRows($type, $records),
            (string)$operatorScope->tenantId()
        );
        return array_replace($result, [
            'records' => $this->publicRows($rows),
            'total' => (int)($result['total'] ?? 0),
            'page' => $page,
            'pageSize' => $pageSize,
            'paginationMode' => 'offset',
            'paginationCursor' => ['current' => '', 'next' => ''],
            'hasMore' => $page * $pageSize < (int)($result['total'] ?? 0),
        ]);
    }

    /** 普通查询只允许已下推的日期、状态和门店条件；其他条件继续走统一执行器。 */
    private function isOrdinaryPageQuery(array $payload, array $definitions): bool
    {
        if ($definitions || trim((string)($payload['keyword'] ?? '')) !== '') return false;
        foreach (['filters','quickFilters','keywordFilters','groups','groupBy','summaries'] as $key) {
            if (!empty($payload[$key])) return false;
        }
        if (isset($payload['export'])) return false;
        if (!in_array((string)($payload['filterRelation'] ?? 'all'), ['', 'all'], true)) return false;
        $type = CashierV3OrderCenterUnifiedQueryContract::typeForPage($this->pageCode());
        $allowedTopFields = $type === 'all' ? ['business_date', 'business_type'] : ['business_date'];
        foreach (['topFilters','topFilterConditions'] as $key) {
            foreach ((array)($payload[$key] ?? []) as $filter) {
                if (!is_array($filter)) return false;
                $field = (string)($filter['field'] ?? $filter['field_key'] ?? $filter['fieldKey'] ?? '');
                $operator = (string)($filter['operator'] ?? '');
                if (!in_array($field, $allowedTopFields, true)
                    || !in_array($operator, ['eq','equal','gte','greater_or_equal','lte','less_or_equal'], true)) {
                    return false;
                }
            }
        }
        $sorts = (array)($payload['sorts'] ?? []);
        $expected = self::defaultOrderSorts($this->pageCode());
        if (count($sorts) > count($expected)) return false;
        foreach ($sorts as $i => $sort) {
            $field = (string)($sort['field'] ?? $sort['field_key'] ?? $sort['fieldKey'] ?? '');
            if ($field !== $expected[$i]['field'] || strtolower((string)($sort['direction'] ?? '')) !== 'desc') return false;
        }
        return true;
    }

    /** 冻结导出和列表采用相同时间倒序及稳定身份倒序，不让通用引擎改成 ID 升序。 */
    public static function defaultOrderSorts(string $pageCode): array
    {
        $dates = ['all'=>'event_completed_at','sales'=>'payment_completed_at','recharge'=>'payment_completed_at','refund'=>'refund_completed_at',
            'debt'=>'created_at','service'=>'service_completed_at','supplement'=>'payment_completed_at','gift'=>'created_at','card_operation'=>'completed_at'];
        return [['field'=>$dates[CashierV3OrderCenterUnifiedQueryContract::typeForPage($pageCode)], 'direction'=>'desc'],
            ['field'=>'record_id', 'direction'=>'desc']];
    }

    /** 所有页共用同一稳定索引排序；只装配当前页，避免同秒订单在首页/深页之间换序。 */
    private function directSalesSourcePage(array $source, $operatorScope, $dataScope)
    {
        if (!empty($source['salesPerformanceDrilldown'])) return null;
        return $this->deepBusinessPage($source, $operatorScope, $dataScope, false);
    }

    /** 混合订单各页统一使用时间和领域稳定身份归并，平台与门店完全共用。 */
    private function directAllOrderPage(array $payload, $operatorScope, $dataScope)
    {
        return $this->deepBusinessPage(array_replace($this->sourcePayload, $payload), $operatorScope, $dataScope, true);
    }

    /**
     * 深页按窄订单索引归并，只为选中的销售单装配商品/人员/收款快照。
     * 非销售单继续使用现有有界Reader；结果临时文件私有，不物化另一份业务事实。
     */
    private function deepBusinessPage(array $source, $operator, $scope, bool $mixed): array
    {
        $page = max(1, (int)($source['page'] ?? 1)); $size = min(200, max(1, (int)($source['pageSize'] ?? 20)));
        $source['status'] = ($source['dataScope'] ?? 'normal') === 'normal' ? 'normal' : ($source['businessStatus'] ?? '');
        $index = new \app\services\query\UnifiedQueryExternalRows();
        $labels = ['sales'=>'消费','recharge'=>'充值','supplement'=>'补交','refund'=>'退款'];
        $allowed = static function (string $type) use ($source, $labels): bool {
            return !isset($source['_allOrderTypes']) || in_array($labels[$type], $source['_allOrderTypes'], true);
        };
        if ($allowed('sales')) foreach ($this->salesQueries->scanIndexKeys($source, $operator, $scope) as $key) {
            $index->append(['type'=>'sales','time'=>(int)$key['settled_at'],'record_id'=>'sales:'.$key['order_id'],'key'=>$key]);
        }
        if ($mixed) foreach (['recharge','supplement','refund'] as $type) {
            if (!$allowed($type)) continue;
            foreach ($this->recordBatches($type, $source, $operator, $scope) as $records) foreach ($records as $record) {
                $time = '';
                foreach (['paymentCompletedAt','refundCompletedAt','completedAt','serviceCompletedAt','createdAt'] as $key) {
                    if ($time === '' && !empty($record[$key])) $time = (string)$record[$key];
                }
                $index->append(['type'=>$type,'time'=>$time === '' ? 0 : (new \DateTimeImmutable($time, new \DateTimeZone(CashierV3SalesOrderQueryServices::BUSINESS_TIMEZONE)))->getTimestamp(),'record_id'=>$type.':'.$record['id'],'record'=>$record]);
            }
        }
        $index->sort(static function (array $a, array $b): int {
            return ($b['time'] <=> $a['time']) ?: strcmp(strtolower($b['record_id']), strtolower($a['record_id']));
        });
        $selected = []; $keys = []; $position = 0; $offset = ($page - 1) * $size;
        foreach ($index as $key) {
            if ($position++ < $offset) continue;
            if (count($selected) >= $size) break;
            $selected[] = $key; if ($key['type'] === 'sales') $keys[] = $key['key'];
        }
        $sales = $this->salesQueries->hydrateIndexKeys($keys, $source, $operator, $scope);
        $byId = []; foreach ($sales as $record) $byId['sales:'.$record['id']] = $record;
        if (!$mixed) {
            $records = []; foreach ($selected as $key) if (isset($byId[$key['record_id']])) $records[] = $byId[$key['record_id']];
        } else {
            $identities = new CashierV3OrderCenterQueryIdentities(); $projected = [];
            foreach (array_keys($labels) as $type) {
                $raw = [];
                foreach ($selected as $key) if ($key['type'] === $type) {
                    $record = $type === 'sales' ? ($byId[$key['record_id']] ?? null) : $key['record'];
                    if ($record !== null) $raw[] = $record;
                }
                if ($type === 'sales') $raw = $this->salesQueries->attachCurrentItemCategories($raw);
                foreach ($identities->attach($type, $this->projectRows($type, $raw), (string)$operator->tenantId()) as $row) {
                    $row['recordType'] = $type; $row['business_type'] = $labels[$type];
                    $row['order_no'] = $row[$type === 'sales' ? 'sales_order_no' : $type.'_order_no'];
                    $row['event_completed_at'] = $row[$type === 'refund' ? 'refund_completed_at' : 'payment_completed_at'];
                    $projected[$row['record_id']] = self::mixedDisplayFields($type, $row);
                }
            }
            $records = []; foreach ($selected as $key) if (isset($projected[$key['record_id']])) $records[] = $projected[$key['record_id']];
            $records = $this->publicRows($records);
        }
        if (count($records) !== count($selected)) throw new \RuntimeException('order_page_changed_retry');
        $metadata = $mixed ? ['recordType'=>'all','dataStatus'=>'ready','dataAsOf'=>date('c'),'statusOptions'=>[]]
            : $this->salesQueries->indexPageMetadata($source, $operator, $scope);
        return array_replace($metadata, [
            'records'=>$records,'total'=>count($index),'page'=>$page,'pageSize'=>$size,'paginationMode'=>'offset',
            'paginationCursor'=>['current'=>'','next'=>''],'hasMore'=>$page*$size<count($index)]);
    }

    public function query(array $context, array $payload): array
    {
        $request = clone $this;
        $result = $request->run($context, $payload, $this->definitionsForQuery($context, $payload));
        return [
            'records' => $this->publicRows($result['rows']),
            'total' => (int)$result['pagination']['total'],
            'page' => (int)$result['pagination']['page'],
            'pageSize' => (int)$result['pagination']['limit'],
            'querySettings' => $this->preferences->load($context, $this->pageCode()),
            'summaries' => $result['summaries'],
            'groups' => $result['groups'],
            'queryCutoffDate' => $result['queryCutoffDate'],
            'dataAsOf' => $result['dataAsOf'],
            'metricVersion' => $result['schemaVersion'],
            'aggregationCaughtUp' => true,
            'consistencyFingerprint' => $result['consistencyFingerprint'],
        ];
    }

    public function executeFrozenPlan(
        array $context,
        array $plan,
        string $exportScope,
        array $fieldKeys
    ): array {
        if ((string)($plan['page_code'] ?? '') !== $this->pageCode()) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PLAN_INVALID', '导出查询计划与订单记录不匹配。', []);
        }
        $query = [
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
            'export' => [
                'scope' => $exportScope,
                'fields' => array_values(array_unique(array_map('strval', $fieldKeys))),
            ],
        ];
        // Worker context 已取创建时组织范围与当前权限的交集；将它传给同一个
        // 领域 Reader，使分页和全量导出均不扩大到管理员的其他门店。
        if (array_key_exists('visible_store_ids', $context) && $context['visible_store_ids'] !== null) {
            $query['storeIds'] = $context['visible_store_ids'];
        }
        $request = clone $this;
        $definitions = (array)($plan['custom_definitions'] ?? []);
        // 当前页导出与列表使用同一个数据库分页 Reader；不能为了导出 20 行先加载
        // 整月几万行。后台任务重新解析登录身份和数据范围，字段仍由统一执行器校验。
        if ($exportScope === 'page') {
            $pageQuery = $query;
            unset($pageQuery['export']);
            [$operatorScope, $dataScope] = $request->authoritativeScopes($context);
            $direct = $request->directOrdinaryPage($pageQuery, $definitions, $operatorScope, $dataScope);
            if ($direct !== null) {
                // Reader 已选定目标页；执行器只投影导出字段，不能再对第 2 页重复偏移。
                unset($query['storeIds']);
                $query['page'] = 1;
                $query['export']['scope'] = 'query';
                return $request->execution->execute(
                    $request->pageCode(), (array)$direct['records'], $definitions,
                    $query, $context, static function (array $row, array $scope): bool { return true; }
                );
            }
        }
        return $request->run($context, $query, $definitions);
    }

    /** @return array<string,mixed> */
    protected function run(array $context, array $payload, array $definitions): array
    {
        // 在领域 Reader 下推前撤下关闭的来源筛选，不能只在最终分页引擎中移除。
        $policy = (new CashierV3BusinessSourcePolicyServices())->read();
        if (($policy['enabled'] ?? true) === false) $payload = CashierV3OrderCenterQueryExecutionServices::withoutBusinessSource($payload);
        $payload['pageCode'] = $this->pageCode();
        unset($payload['queryCutoffDate'], $payload['query_cutoff_date']);
        $memberInitials = $this->memberInitialKeyword($payload);
        $this->sourcePayload = $this->sourceQuery($payload);
        // 领域日期/权限/下钻参数由源 Reader 消费；不传入通用表达式白名单。
        $payload = array_intersect_key($payload, array_flip([
            'pageCode','page','pageSize','limit','keyword','topFilters','filters','filterRelation',
            'sorts','groups','groupBy','summaries','dataScope','businessStatus','quickFilters',
            'visibleFields','export','fieldVersions','keywordFilters','topFilterConditions',
        ]));
        $rows = $this->authorizedSourceBatches($context, $memberInitials);
        $payload = $this->identityFilters($payload);
        if (empty($payload['sorts'])) {
            $payload['sorts'] = self::defaultOrderSorts($this->pageCode());
        }
        // 仅在没有自定义聚合字段时下推恒为AND的订单号条件。聚合字段的基数必须保持原授权行集。
        // 先验证完整计划；OR/人员/金额等派生条件仍由同一个执行器最终判定。
        if (!$definitions) {
            $plan = $this->execution->validatedPlan($this->pageCode(), $definitions, $payload, $context);
            $conditions = array_merge($plan['top_filters'], $plan['quick_filters'],
                $plan['filter_relation'] === 'all' ? $plan['filters'] : []);
            $numberField = $this->pageCode() === 'order_center_all' ? 'order_no' : 'sales_order_no';
            foreach ($conditions as $condition) {
                if (($condition['field_key'] ?? '') === $numberField
                    && in_array($condition['operator'] ?? '', ['equal','contains','starts_with'], true)
                    && is_scalar($condition['value'] ?? null)) {
                    $this->sourcePayload['_orderNumberConditions'][] = $condition;
                }
            }
        }
        return $this->execution->executeBatches(
            $this->pageCode(),
            $rows,
            $definitions,
            $payload,
            $context,
            static function (array $row, array $scope): bool {
                // Rows are loaded only through the authoritative domain services with
                // a freshly rebuilt DataScope. This final gate keeps status selection
                // consistent without allowing an exported task to broaden that scope.
                // 状态代码已在权威 Reader 中执行，不能再与中文展示标签比较。
                return true;
            },
            $memberInitials === '' ? null : static function (array $row) use ($memberInitials): bool {
                return strncmp((string)($row['_member_name_initials'] ?? ''), $memberInitials, strlen($memberInitials)) === 0;
            }
        );
    }

    /** 只解释综合查询的标准关键词组，不把高级筛选或客户端自造字段当成会员搜索。 */
    protected function memberInitialKeyword(array $payload): string
    {
        $keyword = trim((string)($payload['keyword'] ?? ''));
        $filters = (array)($payload['keywordFilters'] ?? []);
        if ($keyword !== '' && $filters) {
            return '';
        }
        if ($keyword === '' && $filters) {
            $policy = (new CashierV3BusinessSourcePolicyServices())->read();
            $expected = (array)CashierV3OrderCenterUnifiedQueryContract::definition($this->pageCode(), ($policy['enabled'] ?? true) !== false, (int)sys_config('cashier_debt_pay_switch', 0) === 1)['keywordFields'];
            if (count($filters) !== count($expected)) {
                return '';
            }
            $values = [];
            foreach ($filters as $filter) {
                if (!is_array($filter) || (string)($filter['operator'] ?? '') !== 'contains'
                    || !is_scalar($filter['value'] ?? null)) {
                    return '';
                }
                $field = (string)($filter['field_key'] ?? ($filter['fieldKey'] ?? ($filter['field'] ?? '')));
                if (!in_array($field, $expected, true) || isset($values[$field])) {
                    return '';
                }
                $values[$field] = (string)$filter['value'];
            }
            if (count($values) !== count($expected) || count(array_unique(array_values($values), SORT_STRING)) !== 1) {
                return '';
            }
            $keyword = trim((string)reset($values));
        }
        return preg_match('/^[A-Za-z]{1,60}$/D', $keyword) === 1 ? strtoupper($keyword) : '';
    }

    /** 候选订单已被权威 Reader 按门店裁剪；只按这些会员 ID 批读可重建索引，不改历史姓名快照。 */
    protected function attachMemberInitials(array $rows, string $keyword): array
    {
        if ($keyword === '' || !$rows) {
            return $rows;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'memberId')))));
        $initials = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $initials += Db::name('member_name_initial_search')->whereIn('uid', $batch)->column('initials', 'uid');
        }
        foreach ($rows as &$row) {
            $row['_member_name_initials'] = (string)($initials[(int)($row['memberId'] ?? 0)] ?? '');
        }
        unset($row);
        return $rows;
    }

    /** 每批先做领域权限筛选，再投影和补人员身份；各端及导出消费同一批次源。 */
    protected function authorizedSourceBatches(array $context, string $memberInitials = ''): \Generator
    {
        [$operator, $scope] = $this->liveScopes ?? $this->authoritativeScopes($context);
        $pageType = CashierV3OrderCenterUnifiedQueryContract::typeForPage($this->pageCode());
        $labels = ['sales'=>'消费','recharge'=>'充值','supplement'=>'补交','refund'=>'退款'];
        $types = $pageType === 'all' ? array_keys($labels) : [$pageType];
        $identities = new CashierV3OrderCenterQueryIdentities();
        foreach ($types as $type) {
            if ($pageType === 'all' && isset($this->sourcePayload['_allOrderTypes'])
                && !in_array($labels[$type], $this->sourcePayload['_allOrderTypes'], true)) continue;
            $source = array_replace($this->sourcePayload, ['recordType'=>$type]);
            $source['status'] = ($source['dataScope'] ?? 'normal') === 'normal' ? 'normal' : ($source['businessStatus'] ?? '');
            if ($pageType === 'sales') $this->sourcePage = $this->salesQueries->indexPageMetadata($source, $operator, $scope);
            $batches = $type === 'sales' ? $this->salesQueries->scanBatches($source, $operator, $scope)
                : $this->recordBatches($type, $source, $operator, $scope);
            foreach ($batches as $records) {
                if ($type === 'sales') $records = $this->salesQueries->attachCurrentItemCategories($records);
                $rows = $identities->attach($type, $this->projectRows($type, $records), (string)$operator->tenantId());
                if ($pageType === 'all') foreach ($rows as &$row) {
                    $row['recordType'] = $type; $row['business_type'] = $labels[$type];
                    $row['order_no'] = $row[$type === 'sales' ? 'sales_order_no' : $type . '_order_no'];
                    $row['event_completed_at'] = $row[$type === 'refund' ? 'refund_completed_at' : 'payment_completed_at'];
                    $row = self::mixedDisplayFields($type, $row);
                }
                unset($row);
                yield $this->attachMemberInitials($rows, $memberInitials);
            }
        }
        if ($pageType === 'all' || !$this->sourcePage) $this->sourcePage = [
            'recordType'=>$pageType,'dataStatus'=>'ready','dataAsOf'=>date('c'),'statusOptions'=>[]];
    }

    /** 混合页的通用列与其列表展示读取同一业务字段；专用列及原始金额保持不变。 */
    private static function mixedDisplayFields(string $type, array $row): array
    {
        if ($type === 'sales') return $row;
        $nameField = $type === 'recharge' ? 'recharge_plan' : 'product_name';
        $amountField = $type . '_amount';
        $row['item_name'] = $row[$nameField] ?? null;
        $row['line_amount'] = $row[$amountField] ?? null;
        if ($type === 'refund') {
            $row['payment_method'] = $row['refund_method'] ?? null;
            $row['order_status'] = $row['refund_status'] ?? null;
        } elseif ($type === 'supplement') {
            $row['order_status'] = $row['payment_status'] ?? null;
        }
        return $row;
    }

    /** 旧单据空串或“—”统一为查询空值；各订单页筛选/导出共用，不回写历史记录。 */
    protected static function filterableAllOrderSource($source): ?string
    {
        $value = trim((string)($source ?? ''));
        return $value === '' || $value === '—' ? null : $value;
    }

    /** 记录Reader已按数据库分页；逐批交给统一筛选，禁止合成全量records数组。 */
    private function recordBatches(string $type, array $source, $operator, $scope): \Generator
    {
        $page = 1; $read = 0;
        do {
            $result = $this->recordQueries->queryRecords(array_replace($source, ['recordType'=>$type,'page'=>$page,'pageSize'=>100]), $operator, $scope);
            if ($page === 1) $this->sourcePage = $result;
            $records = (array)($result['records'] ?? []);
            if (!$records) break;
            yield $records; $read += count($records); ++$page;
        } while ($read < (int)$result['total']);

    }

    /** 只下推恒为 AND 的业务日期/授权范围；组合 OR 不能提前裁剪丢失候选。 */
    private function sourceQuery(array $payload): array
    {
        $source = array_intersect_key($payload, array_flip(['storeIds','store_ids','memberId','member_id',
            'dateFrom','dateTo','businessDateFrom','businessDateTo','salesPerformanceDrilldown','servicePerformanceDrilldown']));
        foreach (($payload['topFilters'] ?? $payload['topFilterConditions'] ?? []) as $filter) {
            $key = $filter['field'] ?? $filter['field_key'] ?? $filter['fieldKey'] ?? '';
            if ($key === 'business_type' && in_array($filter['operator'] ?? '', ['eq', 'equal'], true)
                && is_scalar($filter['value'] ?? null)) {
                // 仅下推严格等值，保留统一引擎的原始过滤作最终判定；高级 OR 条件不提前裁剪。
                $allowed = $source['_allOrderTypes'] ?? ['消费', '充值', '补交', '退款'];
                $source['_allOrderTypes'] = array_values(array_intersect($allowed, [(string)$filter['value']]));
            }
            if ($key !== 'business_date') continue;
            $op = $filter['operator'] ?? '';
            if (in_array($op, ['gte','greater_or_equal','eq','equal'], true)) $source['dateFrom'] = $filter['value'];
            if (in_array($op, ['lte','less_or_equal','eq','equal'], true)) $source['dateTo'] = $filter['value'];
        }
        $source['dataScope'] = $payload['dataScope'] ?? 'normal';
        $source['businessStatus'] = $payload['businessStatus'] ?? '';
        if (CashierV3OrderCenterUnifiedQueryContract::typeForPage($this->pageCode()) === 'sales') {
            $source['status'] = $source['dataScope'] === 'normal' ? 'normal' : $source['businessStatus'];
        }
        return $source;
    }

    /** 数字选择值只能解释为身份；姓名文本仍支持旧组合文本条件，绝不反查姓名成 ID。 */
    private function identityFilters(array $payload): array
    {
        foreach (['topFilters','topFilterConditions','filters','quickFilters'] as $group) {
            if (!isset($payload[$group])) continue;
            foreach ($payload[$group] as &$filter) {
                $keyName = isset($filter['fieldKey']) ? 'fieldKey' : (isset($filter['field_key']) ? 'field_key' : 'field');
                $field = $filter[$keyName] ?? '';
                if (!in_array($field, ['salesperson','cashier','operator','craftsman','sales_manager','guide','void_operator','store'], true)) continue;
                $values = (array)($filter['value'] ?? []);
                if (!$values) continue;
                $numeric = true;
                foreach ($values as $value) if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string)$value)) $numeric = false;
                if (!$numeric) continue;
                if (!in_array($filter['operator'] ?? '', ['eq','equal','neq','not_equal','in'], true)) {
                    throw new UnifiedQueryException('UNIFIED_QUERY_PERSON_OPERATOR_INVALID', '人员和门店请选择等于、不等于或多选条件。', []);
                }
                $filter[$keyName] = $field . '_query_ids';
            }
            unset($filter);
        }
        return $payload;
    }

    protected function projectRows(string $type, array $records): array
    {
        $aliases = [
            'sales_order_no' => ['salesOrderNo', 'orderNo'], 'business_date' => ['businessDate'], 'member_name' => ['memberName'],
            'store' => ['storeName', 'businessStoreName'], 'item_summary' => ['itemSummary'], 'item_count' => ['itemCount'],
            'receivable_amount' => ['receivableAmount', 'payableAmount'], 'discount_amount' => ['discountAmount'], 'debt_amount' => ['debtAmount', 'outstandingDebtAmount'],
            'coupon_discount_amount' => ['couponDiscountAmount'],
            'actual_received_amount' => ['actualReceivedAmount'], 'payment_method' => ['paymentSummary', 'paymentMethod'], 'salesperson' => ['salespersonSummary', 'salespersonName'],
            'cashier' => ['cashierName', 'operatorName'], 'source' => ['source', 'sourceLabel', 'sourceSecondary', 'sourcePrimary'],
            'payment_status' => ['paymentStatus'], 'order_status' => ['orderStatus', 'statusLabel'], 'supplement' => ['supplementLabel'],
            'occurred_at' => ['occurredAt'], 'payment_completed_at' => ['paymentCompletedAt', 'completedAt'], 'recharge_order_no' => ['rechargeOrderNo', 'orderNo'],
            'recharge_plan' => ['rechargePlan', 'planName'], 'recharge_amount' => ['rechargeAmount'], 'gift_amount' => ['giftAmount'],
            'operator' => ['operatorName', 'operator'], 'refund_order_no' => ['refundOrderNo', 'refundNo'], 'source_order_no' => ['sourceOrderNo'],
            'refund_summary' => ['refundSummary', 'summary'], 'product_category' => ['productCategory'],
            'refund_amount' => ['refundAmount'], 'refund_attribution' => ['refundAttributionSummary'], 'refund_method' => ['refundMethod'],
            'supplement_attribution' => ['supplementAttributionSummary'],
            'refund_status' => ['refundStatus', 'statusLabel'], 'refund_completed_at' => ['refundCompletedAt', 'completedAt'],
            'debt_no' => ['debtNo'], 'source_type' => ['sourceType'], 'product_name' => ['productName'], 'original_debt_amount' => ['originalDebtAmount', 'totalDebtAmount'],
            'repaid_amount' => ['repaidAmount'], 'remaining_amount' => ['remainingAmount'], 'debt_status' => ['debtStatus', 'statusLabel'], 'created_at' => ['createdAt'],
            'service_record_no' => ['serviceRecordNo', 'serviceFactId'], 'service_project' => ['serviceProject', 'projectName'],
            'entitlement_source' => ['entitlementSource'], 'source_card' => ['sourceCard', 'sourceCardName'], 'source_card_no' => ['sourceCardNo', 'sourceCode'],
            'used_times' => ['usedTimes', 'quantity'], 'craftsman' => ['craftsmenSummary', 'craftsmen'],
            'labor_fee_amount' => ['laborFeeAmount', 'manualLaborFeeAmount'],
            'labor_performance_type' => ['laborPerformanceTypeLabel', 'laborPerformanceType'],
            'labor_performance_ratio' => ['laborPerformanceRatio'],
            'consumption_performance_amount' => ['consumptionPerformanceAmount'],
            'labor_performance_amount' => ['laborPerformanceAmount'],
            'service_status' => ['serviceStatus'], 'service_completed_at' => ['serviceCompletedAt', 'completedAt'],
            'voided_at' => ['voidedAt'], 'void_reason' => ['voidReason'], 'void_operator' => ['voidOperatorName'],
            'supplement_order_no' => ['supplementOrderNo', 'repayNo', 'orderNo'], 'debt_summary' => ['debtSummary', 'summary'],
            'supplement_amount' => ['supplementAmount', 'repayAmount'], 'gift_record_no' => ['giftRecordNo', 'giftNo'], 'gift_source' => ['giftSource'],
            'gift_type' => ['giftType', 'typeLabel'], 'gift_content' => ['giftContent', 'contentSummary'], 'gift_quantity' => ['giftQuantity', 'quantity'],
            'effective_at' => ['effectiveAt'], 'expires_at' => ['expiresAt'], 'gift_status' => ['giftStatus', 'statusLabel'], 'gift_reason' => ['giftReason', 'reason'],
            'card_operation_no' => ['cardOperationNo', 'operationNo'], 'operation_type' => ['operationTypeLabel', 'operationType'],
            'target_content' => ['targetContent', 'targetCardName', 'targetProjectName'], 'operation_amount' => ['operationAmount', 'amount'],
            'record_status' => ['recordStatus', 'statusLabel', 'status'], 'operation_reason' => ['operationReason', 'reason'], 'completed_at' => ['completedAt'],
            'project_count' => ['projectCount'], 'detail_remark' => ['detailRemark'],
        ];
        // Reuse the current field contract for row projection and export; hiding
        // debt never edits the source record or its historical settlement values.
        $definition = CashierV3OrderCenterUnifiedQueryContract::definition($this->pageCode(), true, (int)sys_config('cashier_debt_pay_switch', 0) === 1);
        $fields = array_column($definition['fields'], 'key');
        $types = array_column($definition['fields'], 'type', 'key');
        $rows = [];
        foreach ($records as $index => $record) {
            $row = $record;
            foreach ($fields as $field) {
                $row[$field] = $this->firstValue($record, array_merge($aliases[$field] ?? [], [$field]));
                // 未就绪的金额/日期不是零，保留空值语义，避免区间筛选把缺失值当零。
                if ($row[$field] === '' && in_array($types[$field], ['integer','decimal','amount','date','datetime'], true)) $row[$field] = null;
            }
            // 来源控件在各页签共用 is_null；必须在统一投影归一，而不能只处理混合页。
            if (array_key_exists('source', $row)) $row['source'] = self::filterableAllOrderSource($row['source']);
            if ($type === 'sales') {
                // 直接使用表格的权威明细，不重算价格、不把权益价值计入整单收款。
                foreach (['item_name','product_category','unit_price','quantity','line_amount','craftsman','sales_manager','guide'] as $key) $row[$key] = [];
                foreach ($record['items'] ?? [] as $item) {
                    foreach (['item_name'=>'name','unit_price'=>'unitPrice','quantity'=>'quantity'] as $key=>$source) {
                        if (isset($item[$source]) && $item[$source] !== '') $row[$key][] = $item[$source];
                    }
                    $row['product_category'][] = (string)($item['category'] ?? '未分类');
                    // 导出与表格同取用券前金额；权益行仍展示独立核销价值。
                    $amount = $item[($item['businessTag'] ?? '') === '权益' ? 'entitlementAmount' : 'amountBeforeCoupon'] ?? null;
                    if ($amount !== null) $row['line_amount'][] = $amount;
                    foreach (['craftsman'=>'craftsmenListAllocations','sales_manager'=>'salesManagers','guide'=>'guides'] as $key=>$source) {
                        $people = $item[$source] ?? ($key === 'craftsman' ? ($item['craftsmen'] ?? []) : []);
                        foreach ($people as $person) {
                            $name = $person['employeeName'] ?? $person['name'] ?? '';
                            if ($name !== '') $row[$key][] = $name;
                        }
                    }
                }
                if (is_array($row['items'] ?? null)) {
                    foreach ($row['items'] as &$item) unset($item['_categoryProductIds']);
                    unset($item);
                }
            }
            if (in_array($type, ['refund', 'supplement'], true)) {
                foreach (['guide', 'sales_manager'] as $role) {
                    $row[$role] = [];
                    foreach ($record['attributionPeople'] ?? [] as $person) {
                        if (($person['role'] ?? '') !== $role) continue;
                        $name = (string)($person['employee_name_snapshot'] ?? '');
                        $round = (int)($person['guide_round_no'] ?? 0);
                        $row[$role][] = $name . ($role === 'guide' && $round > 0 ? '（第' . $round . '轮）' : '');
                    }
                }
            }
            if (($row['supplement'] ?? null) === null) $row['supplement'] = ($record['isSupplement'] ?? null) === true ? '补单' : (($record['isSupplement'] ?? null) === false ? '正常办理' : '');
            $row['record_id'] = $type . ':' . (string)($record['id'] ?? $row[$definition['keywordFields'][0]] ?? $index + 1);
            $row['_business_status'] = $this->firstValue($row, ['order_status', 'refund_status', 'debt_status', 'service_status', 'payment_status', 'gift_status', 'record_status']) ?: '';
            $rows[] = $row;
        }
        return $rows;
    }

    protected function publicRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            foreach (array_keys($row) as $key) if (strpos((string)$key, '_') === 0 || substr((string)$key, -10) === '_query_ids') unset($row[$key]);
            return $row;
        }, $rows);
    }

    protected function firstValue(array $record, array $keys)
    {
        foreach ($keys as $key) if (array_key_exists($key, $record) && $record[$key] !== null && $record[$key] !== '') return $record[$key];
        return '';
    }

    protected function definitionsForQuery(array $context, array $payload): array
    {
        $candidate = $payload;
        unset($candidate['fieldVersions'], $candidate['field_versions']);
        $keys = (new UnifiedQueryCustomFieldKeyCollector())->collectFromQuery($candidate);
        if (!$keys) return [];
        $preference = $this->preferences->load($context, $this->pageCode());
        $pinned = (array)($preference['customFieldVersions'] ?? []);
        $visible = [];
        foreach ($this->customFields->listVisible($context, $this->pageCode(), true) as $field) $visible[(string)$field['key']] = $field;
        $definitions = [];
        foreach ($keys as $fieldKey) {
            if (!isset($visible[$fieldKey])) throw new UnifiedQueryException('UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE', '查询使用的自定义字段已不可用，请重新设置。', ['field_key' => $fieldKey]);
            $definition = $this->customFields->versionDefinition($context, $this->pageCode(), $fieldKey, (int)($pinned[$fieldKey] ?? $visible[$fieldKey]['version'] ?? 0));
            $definition['page_code'] = $this->pageCode();
            $definitions[] = $definition;
        }
        return $definitions;
    }

    protected function authoritativeScopes(array $context): array
    {
        // 列表使用请求内的可信 scope；后台任务必须按领取任务时识别出的同一
        // 员工/选店会话再构建 Reader scope，避免数字 ID 碰撞导致空文件。
        return (new CashierV3OrderCenterUnifiedQueryWorkerContextResolver())->scopesForContext($context);
    }
}
