<?php

namespace app\services\query\metric;

/**
 * 唯一指标读取注册表。
 *
 * 这里只登记“指标是什么、由哪一种已实现读取策略提供、支持什么粒度”，不保存
 * 页面字段、SQL、DAO 或用户问法。运行期按 reader_strategy 分派，禁止再按指标代码
 * 写 if/switch。页面旧代码只允许通过 aliases 迁移到规范指标代码。
 */
final class MetricDefinitionRegistry
{
    // v3 introduces source-owned analysis-dimension contracts.  Bumping the
    // mapping identity prevents a plan frozen against the older registry from
    // being mistaken for one that carries those object contracts.
    public const VERSION = 'unified-metric-registry-v9';
    public const COVERAGE_START = '2026-08-10';

    /**
     * Visible subject for a registry-backed overview. Non-store subjects are
     * taken from their declared analysis dimension, so a newly registered
     * object needs no renderer branch. Store remains the source-owned default
     * business subject because it is the base grain rather than a dimension.
     */
    public static function overviewObjectLabel(string $objectKind): ?string
    {
        if ($objectKind==='store') return '经营';
        $labels=[];
        foreach (self::all() as $definition) {
            foreach (self::analysisDimensionContracts($definition) as $dimension) {
                if (!is_array($dimension) || ($dimension['object_kind']??null)!==$objectKind
                    || !is_string($dimension['object_label']??null) || $dimension['object_label']==='') continue;
                $labels[$dimension['object_label']]=true;
            }
        }
        return count($labels)===1 ? array_key_first($labels) : null;
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            'cash_performance' => self::amount('cash_positive', 'cash-collected-recharge-inclusive-v3', ['summary', 'comparison', 'trend', 'ranking']) + [
                'overview' => [['object_kind' => 'store', 'section' => '收款结果', 'order' => 10]],
                'dimensions' => [
                    'operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot'],
                    // 会员付款能力排行复用这一已登记收款指标；维度仅决定
                    // Reader 如何分组，不会新建另一套金额或公式。
                    'member' => [
                        'id' => 'member_id', 'name' => 'member_name_snapshot',
                        // This opt-in is the source-owned contract which makes
                        // a registered fact dimension an AI analysis object.
                        // Merely having an id/name column never does so.
                        'analysis_object_kind' => 'member',
                        'analysis_object_label' => '会员',
                        'analysis_relation_role' => 'member_metric_total',
                        // A dimension contract describes both the object and
                        // the business action it can truthfully answer.  The
                        // AI may not substitute another action merely because
                        // it happens to share the same object dimension.
                        // Same registered cash fact: it can truthfully answer
                        // both member payment-strength and collection ranking.
                        // This remains a single metric/reader, not a second
                        // formula inferred by the AI.
                        'analysis_action_codes' => ['payment','revenue'],
                    ],
                ],
                'default_ranking_dimension' => 'operator',
                'category_reader' => ['strategy' => 'cash_sale_allocation', 'mode' => 'positive'],
            ],
            'refund_performance' => self::amount('cash_refund', 'actual-cash-refund-v1', ['summary', 'comparison', 'trend', 'ranking']) + [
                'overview' => [['object_kind' => 'store', 'section' => '收款结果', 'order' => 20]],
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
                'default_ranking_dimension' => 'operator',
                'category_reader' => ['strategy' => 'cash_sale_allocation', 'mode' => 'refund'],
            ],
            'actual_performance' => self::amount('derived_subtract', 'cash-minus-actual-cash-refund-v1', ['summary', 'comparison', 'trend', 'ranking']) + [
                'overview' => [['object_kind' => 'store', 'section' => '收款结果', 'order' => 30]],
                'derivation' => ['operator' => 'subtract', 'left_metric' => 'cash_performance', 'right_metric' => 'refund_performance'],
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
                'default_ranking_dimension' => 'operator',
                'category_reader' => ['strategy' => 'cash_sale_allocation', 'mode' => 'net'],
            ],
            'consume_amount' => self::amount('fact_sum', 'consumption-completed-service-facts-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_performance_fact', 'amount' => 'amount_cents',
                'filters' => ['status' => 'effective', 'performance_type' => 'consumption_performance_recorded'],
                'normal_scope' => 'facts', 'requires_completed_service' => true,
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
                'category_reader' => ['strategy' => 'completed_service_performance'],
            ]) + [
                'default_ranking_dimension' => 'operator',
                'overview' => [['object_kind' => 'store', 'section' => '服务消耗', 'order' => 10]],
            ],
            'staff_sales_yeji' => self::amount('personnel_fact_sum', 'sales-performance-allocated-person-v1', ['summary', 'ranking'], [
                'table' => 'cashier_v3_performance_fact', 'amount' => 'amount_cents',
                'filters' => ['status' => 'effective', 'performance_type' => 'sales_performance_allocated'],
                'normal_scope' => 'facts', 'dimensions' => ['employee' => [
                    'id' => 'employee_id', 'name' => 'employee_name_snapshot',
                    'analysis_object_kind' => 'person', 'analysis_object_label' => '人员',
                    'analysis_relation_role' => 'allocated_employee', 'analysis_action_codes' => ['sales'],
                    'analysis_filter_keys' => ['selection_ref'],
                ]],
            ], 'person', ['selection_ref']) + [
                'default_ranking_dimension' => 'employee',
                // This registered fact is assigned only to the platform's
                // sales-qualified people. A broad people-ranking may use that
                // factual cohort as its labelled first view; it does not infer
                // a cohort from the customer's wording.
                'analysis_default_selection_ref' => 'role:salesperson',
            ],
            'staff_labor_yeji' => self::amount('personnel_fact_sum', 'labor-performance-allocated-person-v1', ['summary', 'ranking'], [
                'table' => 'cashier_v3_performance_fact', 'amount' => 'amount_cents',
                'filters' => ['status' => 'effective', 'performance_type' => 'labor_performance_allocated'],
                'normal_scope' => 'facts', 'dimensions' => ['employee' => [
                    'id' => 'employee_id', 'name' => 'employee_name_snapshot',
                    'analysis_object_kind' => 'person', 'analysis_object_label' => '人员',
                    'analysis_relation_role' => 'allocated_employee', 'analysis_action_codes' => ['service'],
                    'analysis_filter_keys' => ['selection_ref'],
                ]],
            ], 'person', ['selection_ref']) + [
                'default_ranking_dimension' => 'employee',
                // Labour-performance facts use the same qualification source
                // as cashier performance allocation and personnel authority.
                'analysis_default_selection_ref' => 'role:craftsman',
            ],
            // 工资项目数不是销售数量：它是一次已完成服务中分给手艺人的
            // 最终项目数。使用百万分之一的整数读值，保证 0.3、6.6 等手工
            // 分配值在聚合、排行和导出中不因浮点计算而漂移。
            'staff_project_num' => self::projectCount('personnel_fact_sum', 'staff-service-project-count-v2', ['summary', 'ranking'], [
                'table' => 'cashier_v3_performance_fact',
                'amount' => 'CAST(ROUND(COALESCE(p.project_count_decimal, p.project_count_half_units / 2) * 1000000, 0) AS SIGNED)',
                'filters' => ['status' => 'effective', 'performance_type' => 'labor_performance_allocated'],
                'normal_scope' => 'facts', 'dimensions' => ['employee' => [
                    'id' => 'employee_id', 'name' => 'employee_name_snapshot',
                    'analysis_object_kind' => 'person', 'analysis_object_label' => '人员',
                    'analysis_relation_role' => 'serving_employee', 'analysis_action_codes' => ['service'],
                    'analysis_filter_keys' => ['selection_ref'],
                ]],
            ], 'person', ['selection_ref']) + [
                'default_ranking_dimension' => 'employee',
                'analysis_default_selection_ref' => 'role:craftsman',
            ],
            // "客数" retains its established code, but its former source was
            // the legacy write-off table.  New V3 reads are bound to completed
            // service facts plus active labour allocations instead.
            'staff_service_num' => self::tenthCount('service_customer_personnel', 'v3-service-customer-visit-person-v1', ['summary', 'ranking'], [
                'table' => 'cashier_v3_entitlement_service_fact + cashier_v3_performance_fact',
                'filters' => ['service_status' => 'completed', 'performance_type' => 'labor_performance_allocated', 'performance_status' => 'effective'],
                'normal_scope' => 'services',
                'dimensions' => ['employee' => [
                    'id' => 'employee_id', 'name' => 'employee_name_snapshot',
                    'analysis_object_kind' => 'person', 'analysis_object_label' => '人员',
                    'analysis_relation_role' => 'serving_employee', 'analysis_action_codes' => ['service'],
                    'analysis_filter_keys' => ['selection_ref'],
                ]],
            ], 'person', ['selection_ref']) + [
                'default_ranking_dimension' => 'employee',
                'analysis_default_selection_ref' => 'role:craftsman',
            ],
            'service_people' => self::tenthCount('service_customer_personnel', 'v3-service-customer-period-people-person-v1', ['summary', 'ranking'], [
                'table' => 'cashier_v3_entitlement_service_fact + cashier_v3_performance_fact',
                'filters' => ['service_status' => 'completed', 'performance_type' => 'labor_performance_allocated', 'performance_status' => 'effective'],
                'normal_scope' => 'services',
                'dimensions' => ['employee' => [
                    'id' => 'employee_id', 'name' => 'employee_name_snapshot',
                    'analysis_object_kind' => 'person', 'analysis_object_label' => '人员',
                    'analysis_relation_role' => 'serving_employee', 'analysis_action_codes' => ['service'],
                    'analysis_filter_keys' => ['selection_ref'],
                ]],
            ], 'person', ['selection_ref']) + [
                'default_ranking_dimension' => 'employee',
                'analysis_default_selection_ref' => 'role:craftsman',
            ],
            'sales_amount' => self::amount('fact_sum', 'v3-sale-completed-lines-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_sale_fact', 'amount' => 'sale_amount_cents',
                'filters' => ['status' => 'effective'], 'normal_scope' => 'facts',
                'dimensions' => self::saleAmountDimensions(),
            ]) + [
                'default_ranking_dimension' => 'operator',
                'overview' => [
                    ['object_kind' => 'store', 'section' => '销售结果', 'order' => 10],
                    ['object_kind' => 'project', 'section' => '项目销售', 'order' => 10],
                    ['object_kind' => 'product', 'section' => '产品销售', 'order' => 10],
                ],
                // Direct sales use their frozen sales dimension; card sales use
                // the immutable component allocation.  The reader owns the
                // choice so category reports never reopen sale facts to sum it.
                'category_reader' => ['strategy' => 'sale_completed_allocation'],
            ],
            // 销售收款与销售成交额是两个不同的业务事实。该指标只读取销售
            // 收款分摊事实，不混入充值或历史欠款补交；退款沿同一有符号分摊链
            // 回冲。后续会员累计门槛查询也只能声明性地复用这一事实合同。
            'sales_collected_amount' => self::amount('sales_payment_collected', 'v3-sale-payment-collected-net-v1', ['summary', 'comparison', 'trend', 'ranking', 'threshold_count'], [
                'dimensions' => [
                    'member' => [
                        'id' => 'member_id', 'name' => 'member_name_snapshot',
                        'analysis_object_kind' => 'member',
                        'analysis_object_label' => '会员',
                        'analysis_relation_role' => 'member_sales_collection_total',
                        'analysis_action_codes' => ['sales', 'payment'],
                    ],
                ],
                'threshold_count' => [
                    'subject_dimension' => 'member',
                    'aggregation' => 'period_total',
                    'operators' => ['gte', 'gt', 'lte', 'lt', 'eq'],
                ],
            ]) + [
                'default_ranking_dimension' => 'member',
                'overview' => [['object_kind' => 'store', 'section' => '销售结果', 'order' => 20]],
            ],
            'sales_quantity' => self::count('fact_sum', 'v3-sale-completed-line-quantity-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_sale_fact', 'amount' => 'quantity',
                // A refund adjusts payment amount only. Sold quantity remains
                // the original completed-sale quantity until a separately
                // registered return-quantity fact is introduced.
                'filters' => ['status' => 'effective'], 'normal_scope' => 'facts',
                'dimensions' => self::saleItemDimensions(),
            ]) + [
                'default_ranking_dimension' => 'operator',
                'overview' => [
                    ['object_kind' => 'store', 'section' => '经营动作', 'order' => 10],
                    ['object_kind' => 'project', 'section' => '项目销售', 'order' => 20],
                    ['object_kind' => 'product', 'section' => '产品销售', 'order' => 20],
                ],
            ],
            'balance_deduction_amount' => self::amount('fact_sum', 'v3-balance-order-payment-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_balance_fact', 'amount' => '-(principal_delta_cents + bonus_delta_cents)',
                'filters' => ['status' => 'effective', 'balance_change_type' => 'order_payment'], 'normal_scope' => 'facts',
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
            ]) + [
                'default_ranking_dimension' => 'operator',
                'overview' => [['object_kind' => 'store', 'section' => '资金变动', 'order' => 20]],
            ],
            'recharge_amount' => self::amount('fact_sum', 'v3-recharge-principal-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_balance_fact', 'amount' => 'principal_delta_cents',
                'filters' => ['status' => 'effective', 'balance_change_type' => 'recharge_credit'], 'normal_scope' => 'facts',
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
            ]) + [
                'default_ranking_dimension' => 'operator',
                'overview' => [['object_kind' => 'store', 'section' => '资金变动', 'order' => 10]],
            ],
            'completed_service_item_count' => self::count('fact_sum', 'v3-completed-service-quantity-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_entitlement_service_fact', 'amount' => 'quantity',
                'filters' => ['service_status' => 'completed'], 'normal_scope' => 'services',
                'dimensions' => [
                    'project' => [
                        'id' => 'project_id', 'name' => 'project_name_snapshot',
                        'analysis_object_kind' => 'project',
                        'analysis_object_label' => '项目',
                        'analysis_relation_role' => 'project_metric_total',
                        'analysis_action_codes' => ['service'],
                    ],
                    'operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot'],
                ],
                'category_reader' => ['strategy' => 'completed_service_quantity'],
            ]) + [
                'default_ranking_dimension' => 'operator',
                'overview' => [
                    ['object_kind' => 'store', 'section' => '经营动作', 'order' => 20],
                    ['object_kind' => 'project', 'section' => '服务完成', 'order' => 10],
                ],
            ],
            'customer_active' => self::count('distinct_member', 'v3-completed-service-active-member-v1', ['summary', 'comparison', 'trend'], [
                'table' => 'cashier_v3_entitlement_service_fact', 'distinct' => 'member_id',
                'filters' => ['service_status' => 'completed'], 'where_gt' => ['member_id' => 0], 'normal_scope' => 'services',
            ]),
        ];
    }

    /** 页面兼容别名只负责迁移标识，不改变指标口径。 */
    public static function aliases(): array
    {
        return [
            'balance_deduction' => 'balance_deduction_amount',
            'service_count' => 'completed_service_item_count',
            // 服务客次与员工服务人次使用同一完成服务事实口径；员工维度
            // 只是这一个指标的已登记分配维度，不再保留旧核销表的第二套算法。
            'service_visit' => 'staff_service_num',
            'consumption_performance' => 'consume_amount',
            'labor_performance' => 'staff_labor_yeji',
        ];
    }

    public static function canonical(string $code): string
    {
        $aliases = self::aliases();
        return $aliases[$code] ?? $code;
    }

    public static function get(string $code): array
    {
        $code = self::canonical($code);
        $all = self::all();
        if (!isset($all[$code])) {
            throw new MetricQueryContractException('METRIC_NOT_REGISTERED', '当前指标尚未注册。');
        }
        $item=$all[$code];
        // Readers and capability snapshots consume the same derived dimension
        // contract.  Keep it on the authoritative definition too so execution
        // never relies on a separately reconstructed object mapping.
        $item['analysis_dimension_contracts']=self::analysisDimensionContracts($item);
        return ['metric_code' => $code] + $item;
    }

    /** AI 与报表共享这一份能力声明；注册不等于绕过报表数据权限。 */
    public static function capabilities(): array
    {
        $dictionary = new \app\services\metric\MetricDictionaryServices();
        $out = [];
        foreach (self::all() as $code => $item) {
            $definition = $dictionary->getByCode($code);
            if (!is_array($definition) || ($definition['user_ready'] ?? false) !== true) {
                throw new MetricQueryContractException('METRIC_DICTIONARY_NOT_READY', '指标字典未就绪。');
            }
            // A read view carries its storage unit all the way through evidence,
            // deterministic rendering and the guarded export projection.  Counts
            // are therefore a first-class registered result, not a failed attempt
            // to masquerade as cents.
            $aiReady = in_array($item['storage_unit'], ['fen', 'count', 'project_count_micro', 'customer_tenth'], true);
            $out[$code] = [
                'metric_code' => $code, 'name' => (string)$definition['name'], 'ai_query_ready' => $aiReady,
                'metric_version' => $item['metric_version'], 'mapping_version' => self::VERSION,
                'source_metric_code' => $code, 'source_metric_version' => $item['metric_version'],
                'query_shapes' => $item['query_shapes'], 'coverage_start' => self::COVERAGE_START,
                'filter_grain' => $item['filter_grain'], 'business_filters' => $item['business_filters'],
                'storage_unit' => $item['storage_unit'],
                // 可分析对象由指标维度声明派生；Skill 不能自行把任意对象变成
                // 可执行查询。
                'analysis_dimensions' => self::analysisDimensions($item),
                'analysis_dimension_contracts' => self::analysisDimensionContracts($item),
                'analysis_default_selection_ref' => $item['analysis_default_selection_ref'] ?? null,
                'overview' => self::overviewContracts($item),
                // A threshold contract is deliberately narrow: it describes
                // the only permitted aggregate predicate for this metric,
                // never arbitrary field filtering.
                'threshold_count' => $item['source']['threshold_count'] ?? null,
                'derivation' => $item['derivation'] ?? null,
                'readiness_reasons' => $aiReady ? [] : ['AI_STORAGE_UNIT_UNSUPPORTED'],
            ];
        }
        return $out;
    }

    private static function amount(string $strategy, string $version, array $shapes, array $source = [], string $grain = 'store', array $filters = []): array
    {
        return self::definition($strategy, $version, $shapes, $source, $grain, $filters, 'fen');
    }

    private static function count(string $strategy, string $version, array $shapes, array $source): array
    {
        return self::definition($strategy, $version, $shapes, $source, 'store', [], 'count');
    }

    private static function tenthCount(string $strategy, string $version, array $shapes, array $source, string $grain = 'store', array $filters = []): array
    {
        return self::definition($strategy, $version, $shapes, $source, $grain, $filters, 'customer_tenth');
    }

    /** Project counts use integer millionths so arbitrary manual decimals remain exact. */
    private static function projectCount(string $strategy, string $version, array $shapes, array $source, string $grain = 'store', array $filters = []): array
    {
        return self::definition($strategy, $version, $shapes, $source, $grain, $filters, 'project_count_micro');
    }

    private static function definition(string $strategy, string $version, array $shapes, array $source, string $grain, array $filters, string $unit): array
    {
        return [
            'reader_strategy' => $strategy, 'metric_version' => $version,
            'query_shapes' => $shapes, 'source' => $source, 'filter_grain' => $grain,
            'business_filters' => $filters, 'storage_unit' => $unit,
        ];
    }

    /**
     * Overview membership is a source-owned business declaration. It never
     * grants a dimension, query shape, or permission that the reader contract
     * has not already registered.
     *
     * @return array<int,array{object_kind:string,section:string,order:int}>
     */
    private static function overviewContracts(array $item): array
    {
        $out=[];
        foreach ((array)($item['overview'] ?? []) as $entry) {
            if (!is_array($entry) || array_keys($entry)!==['object_kind','section','order']
                || !is_string($entry['object_kind']) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$entry['object_kind'])
                || !is_string($entry['section']) || trim($entry['section'])==='' || mb_strlen($entry['section'],'UTF-8')>40
                || !is_int($entry['order']) || $entry['order']<1 || $entry['order']>9999) {
                throw new MetricQueryContractException('METRIC_OVERVIEW_CONTRACT_INVALID', '指标概览声明无效。');
            }
            $out[]=['object_kind'=>$entry['object_kind'],'section'=>$entry['section'],'order'=>$entry['order']];
        }
        usort($out,static function(array $left,array $right): int { return [$left['object_kind'],$left['order'],$left['section']] <=> [$right['object_kind'],$right['order'],$right['section']]; });
        if (count(array_unique(array_map(static function(array $item): string { return $item['object_kind']; },$out)))!==count($out)) {
            throw new MetricQueryContractException('METRIC_OVERVIEW_CONTRACT_INVALID', '同一指标不能重复声明同一种概览对象。');
        }
        return $out;
    }

    /** Sales item dimensions are shared by sales amount and sold quantity. */
    private static function saleItemDimensions(): array
    {
        return [
            'operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot'],
            // A sale fact freezes the sold line's type and item snapshot. This
            // makes product/project ranking a registered fact read, never an
            // AI-side table query.
            'project' => [
                'id' => 'item_id', 'name' => 'item_name_snapshot',
                'analysis_object_kind' => 'project', 'analysis_object_label' => '项目',
                'analysis_relation_role' => 'sold_item', 'analysis_action_codes' => ['sales'],
                'analysis_source_filters' => ['source_type' => 'project'],
            ],
            'product' => [
                'id' => 'item_id', 'name' => 'item_name_snapshot',
                'analysis_object_kind' => 'product', 'analysis_object_label' => '产品',
                'analysis_relation_role' => 'sold_item', 'analysis_action_codes' => ['sales'],
                'analysis_source_filters' => ['source_type' => 'product'],
            ],
        ];
    }

    /**
     * Guide and sales-manager facts currently establish only an associated
     * order-sales amount; they do not establish a quantity attribution.
     */
    private static function saleAmountDimensions(): array
    {
        return self::saleItemDimensions() + [
            'guide' => [
                'analysis_object_kind' => 'guide', 'analysis_object_label' => '导购',
                'analysis_relation_role' => 'introduced_order', 'analysis_action_codes' => ['sales'],
                'analysis_relation_source' => [
                    'table' => 'cashier_v3_customer_guide_round_fact',
                    'employee_id' => 'guide_employee_id',
                    'employee_name' => 'guide_employee_name_snapshot',
                ],
            ],
            'sales_manager' => [
                'analysis_object_kind' => 'sales_manager', 'analysis_object_label' => '销售经理',
                'analysis_relation_role' => 'assisted_order', 'analysis_action_codes' => ['sales'],
                'analysis_relation_source' => [
                    'table' => 'cashier_v3_sales_manager_fact',
                    'employee_id' => 'sales_manager_employee_id',
                    'employee_name' => 'sales_manager_name_snapshot',
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $item @return array<int,string> */
    private static function analysisDimensions(array $item): array
    {
        $dimensions = array_values(array_unique(array_merge(['store'], array_keys((array)($item['dimensions'] ?? ($item['source']['dimensions'] ?? []))))));
        sort($dimensions, SORT_STRING);
        return $dimensions;
    }

    /**
     * Explicit opt-in contracts for dimensions that may be exposed as an
     * analysis object.  This is deliberately stricter than `dimensions`: a
     * source column alone is not an AI capability or a permission grant.
     *
     * @return array<int,array{dimension:string,object_kind:string,object_label:string,relation_role:string,action_codes:array<int,string>,filter_keys:array<int,string>}>
     */
    private static function analysisDimensionContracts(array $item): array
    {
        $dimensions=(array)($item['dimensions'] ?? ($item['source']['dimensions'] ?? []));
        $out=[];
        foreach ($dimensions as $dimension=>$contract) {
            if (!is_array($contract) || !isset($contract['analysis_object_kind'])) continue;
            $kind=$contract['analysis_object_kind']; $label=$contract['analysis_object_label']??null;
            $role=$contract['analysis_relation_role']??null;
            $actions=$contract['analysis_action_codes']??null;$filterKeys=$contract['analysis_filter_keys']??[];
            if (!is_string($dimension) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$dimension)
                || !is_string($kind) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$kind)
                || !is_string($label) || trim($label)==='' || !is_string($role)
                || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$role)
                || !is_array($actions) || $actions===[] || count($actions)>8 || !is_array($filterKeys) || count($filterKeys)>8) {
                throw new MetricQueryContractException('METRIC_DIMENSION_CONTRACT_INVALID', '指标对象维度合同无效。');
            }
            $actions=array_values(array_unique($actions));
            foreach ($actions as $action) if (!is_string($action) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$action)) {
                throw new MetricQueryContractException('METRIC_DIMENSION_CONTRACT_INVALID', '指标对象维度合同无效。');
            }
            $filterKeys=array_values(array_unique($filterKeys));
            foreach ($filterKeys as $key) if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$key)) {
                throw new MetricQueryContractException('METRIC_DIMENSION_CONTRACT_INVALID', '指标对象维度合同无效。');
            }
            sort($actions, SORT_STRING);
            sort($filterKeys, SORT_STRING);
            $out[]=['dimension'=>$dimension,'object_kind'=>$kind,'object_label'=>$label,
                'relation_role'=>$role,'action_codes'=>$actions,'filter_keys'=>$filterKeys];
        }
        usort($out,static function(array $left,array $right): int { return [$left['object_kind'],$left['dimension']] <=> [$right['object_kind'],$right['dimension']]; });
        return $out;
    }
}
