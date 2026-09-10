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
    public const VERSION = 'unified-metric-registry-v2';
    public const COVERAGE_START = '2026-08-10';

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            'cash_performance' => self::amount('cash_positive', 'cash-collected-recharge-inclusive-v3', ['summary', 'comparison', 'trend', 'ranking']) + [
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
                'default_ranking_dimension' => 'operator',
                'category_reader' => ['strategy' => 'cash_sale_allocation', 'mode' => 'positive'],
            ],
            'refund_performance' => self::amount('cash_refund', 'actual-cash-refund-v1', ['summary', 'comparison', 'trend', 'ranking']) + [
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
                'default_ranking_dimension' => 'operator',
                'category_reader' => ['strategy' => 'cash_sale_allocation', 'mode' => 'refund'],
            ],
            'actual_performance' => self::amount('derived_subtract', 'cash-minus-actual-cash-refund-v1', ['summary', 'comparison', 'trend', 'ranking']) + [
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
            ]) + ['default_ranking_dimension' => 'operator'],
            'staff_sales_yeji' => self::amount('personnel_fact_sum', 'sales-performance-allocated-person-v1', ['summary', 'ranking'], [
                'table' => 'cashier_v3_performance_fact', 'amount' => 'amount_cents',
                'filters' => ['status' => 'effective', 'performance_type' => 'sales_performance_allocated'],
                'normal_scope' => 'facts', 'dimensions' => ['employee' => ['id' => 'employee_id', 'name' => 'employee_name_snapshot']],
            ], 'person', ['selection_ref']) + ['default_ranking_dimension' => 'employee'],
            'staff_labor_yeji' => self::amount('personnel_fact_sum', 'labor-performance-allocated-person-v1', ['summary', 'ranking'], [
                'table' => 'cashier_v3_performance_fact', 'amount' => 'amount_cents',
                'filters' => ['status' => 'effective', 'performance_type' => 'labor_performance_allocated'],
                'normal_scope' => 'facts', 'dimensions' => ['employee' => ['id' => 'employee_id', 'name' => 'employee_name_snapshot']],
            ], 'person', ['selection_ref']) + ['default_ranking_dimension' => 'employee'],
            'sales_amount' => self::amount('fact_sum', 'v3-sale-completed-lines-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_sale_fact', 'amount' => 'sale_amount_cents',
                'filters' => ['status' => 'effective'], 'normal_scope' => 'facts',
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
            ]) + [
                'default_ranking_dimension' => 'operator',
                // Direct sales use their frozen sales dimension; card sales use
                // the immutable component allocation.  The reader owns the
                // choice so category reports never reopen sale facts to sum it.
                'category_reader' => ['strategy' => 'sale_completed_allocation'],
            ],
            'balance_deduction_amount' => self::amount('fact_sum', 'v3-balance-order-payment-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_balance_fact', 'amount' => '-(principal_delta_cents + bonus_delta_cents)',
                'filters' => ['status' => 'effective', 'balance_change_type' => 'order_payment'], 'normal_scope' => 'facts',
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
            ]) + ['default_ranking_dimension' => 'operator'],
            'recharge_amount' => self::amount('fact_sum', 'v3-recharge-principal-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_balance_fact', 'amount' => 'principal_delta_cents',
                'filters' => ['status' => 'effective', 'balance_change_type' => 'recharge_credit'], 'normal_scope' => 'facts',
                'dimensions' => ['operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot']],
            ]) + ['default_ranking_dimension' => 'operator'],
            'completed_service_item_count' => self::count('fact_sum', 'v3-completed-service-quantity-v1', ['summary', 'comparison', 'trend', 'ranking'], [
                'table' => 'cashier_v3_entitlement_service_fact', 'amount' => 'quantity',
                'filters' => ['service_status' => 'completed'], 'normal_scope' => 'services',
                'dimensions' => [
                    'project' => ['id' => 'project_id', 'name' => 'project_name_snapshot'],
                    'operator' => ['id' => 'operator_id', 'name' => 'operator_name_snapshot'],
                ],
                'category_reader' => ['strategy' => 'completed_service_quantity'],
            ]) + ['default_ranking_dimension' => 'operator'],
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
        return ['metric_code' => $code] + $all[$code];
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
            $aiReady = in_array($item['storage_unit'], ['fen', 'count'], true);
            $out[$code] = [
                'metric_code' => $code, 'name' => (string)$definition['name'], 'ai_query_ready' => $aiReady,
                'metric_version' => $item['metric_version'], 'mapping_version' => self::VERSION,
                'source_metric_code' => $code, 'source_metric_version' => $item['metric_version'],
                'query_shapes' => $item['query_shapes'], 'coverage_start' => self::COVERAGE_START,
                'filter_grain' => $item['filter_grain'], 'business_filters' => $item['business_filters'],
                'storage_unit' => $item['storage_unit'],
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

    private static function definition(string $strategy, string $version, array $shapes, array $source, string $grain, array $filters, string $unit): array
    {
        return [
            'reader_strategy' => $strategy, 'metric_version' => $version,
            'query_shapes' => $shapes, 'source' => $source, 'filter_grain' => $grain,
            'business_filters' => $filters, 'storage_unit' => $unit,
        ];
    }
}
