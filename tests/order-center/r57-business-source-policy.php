<?php
/** 订单来源开关纯合同：策略读取由内存替身提供，不启动应用、不读写业务数据库。 */
namespace app\services\cashier\v3\config {
    class CashierV3BusinessSourcePolicyServices {
        public static $enabled = true;
        public function read(): array { return ['enabled' => self::$enabled]; }
    }
}
namespace {
    $backend = dirname(__DIR__, 2) . '/后端代码';
    spl_autoload_register(static function ($class) use ($backend) {
        if (strpos($class, 'app\\') !== 0) return;
        $path = $backend . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($path)) require_once $path;
    });
    use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices as Policy;
    use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract as Contract;
    use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryRegistrar as Registrar;
    use app\services\cashier\v3\order\CashierV3OrderCenterQueryExecutionServices as Executor;
    use app\services\query\UnifiedQueryPageRegistry as Registry;
    use app\services\query\StructuredExpressionValidator;
    use app\services\query\StructuredExpressionEvaluator;
    $checks = 0;
    function check($actual, $expected, string $label): void {
        global $checks;
        if ($actual !== $expected) throw new \RuntimeException($label . ': ' . json_encode($actual));
        $checks++;
    }
    $result = [];
    foreach ([true, false] as $enabled) {
        Policy::$enabled = $enabled;
        $registry = Registry::fromRegistrars([], [new Registrar()]);
        $execution = new Executor($registry, new StructuredExpressionValidator($registry), new StructuredExpressionEvaluator());
        foreach (Contract::PAGE_BY_TYPE as $type => $pageCode) {
            $fields = $registry->fieldsForCapability($pageCode, []);
            $keys = array_column($fields, 'key');
            $original = Contract::definition($pageCode);
            check(in_array('source', $keys, true), $enabled && in_array('source', array_column($original['fields'], 'key'), true), $type . ' metadata');
            foreach (['source_order_no', 'source_type', 'gift_source', 'entitlement_source', 'source_card', 'source_card_no'] as $technical) {
                check(in_array($technical, $keys, true), in_array($technical, array_column($original['fields'], 'key'), true), $type . ' technical ' . $technical);
            }
            $identifier = $original['keywordFields'][0];
            $rows = [['record_id' => 'fixture:1', $identifier => '订单1', 'source' => '来源A'], ['record_id' => 'fixture:2', $identifier => '订单2', 'source' => '来源B']];
            $query = ['filters' => [['field' => $identifier, 'operator' => 'contains', 'value' => '订单']], 'filterRelation' => 'any',
                'topFilters' => [['field' => 'source', 'operator' => 'eq', 'value' => '来源A']],
                'visibleFields' => [$identifier, 'source'], 'export' => ['scope' => 'query', 'fields' => [$identifier, 'source']]];
            if ($enabled && !in_array('source', $keys, true)) $query = Executor::withoutBusinessSource($query);
            $before = $query;
            $executed = $execution->execute($pageCode, $rows, [], $query, ['query_cutoff_date' => '2026-10-25'], static function () { return true; });
            $expected = $enabled && in_array('source', $keys, true) ? 1 : 2;
            check($executed['pagination']['total'], $expected, $type . ' hidden filter result');
            check(count($executed['exportRows']), $expected, $type . ' export/list parity');
            check(array_key_exists('source', $executed['exportRows'][0]), $enabled && in_array('source', $keys, true), $type . ' export fields');
            check($query, $before, $type . ' saved preferences unchanged');
            $plan = $execution->validatedPlan($pageCode, [], $query, ['query_cutoff_date' => '2026-10-25']);
            check(in_array('source', $plan['visible_fields'], true), $enabled && in_array('source', $keys, true), $type . ' frozen plan columns');
            check(count($plan['top_filters']), $enabled && in_array('source', $keys, true) ? 1 : 0, $type . ' frozen plan filters');
            $result[$pageCode][$enabled ? 'enabled' : 'disabled'] = $fields;
        }
    }
    $legacy = ['topFilters' => [['fieldKey' => 'source'], ['field' => 'source_card_no']], 'filters' => [['field_key' => 'source'], ['field' => 'gift_source']],
        'filterRelation' => 'any', 'sorts' => [['field' => 'source'], ['field' => 'source_order_no']], 'groupBy' => ['source', 'source_type'],
        'quickFilters' => [['key' => 'source']], 'summaries' => [['field' => 'source']], 'keywordFilters' => [['field' => 'source']],
        'querySettings' => ['filters' => [['field' => 'source']], 'visibleFields' => ['source', 'source_card']], 'export' => ['fields' => ['source', 'source_card_no']]];
    $clean = Executor::withoutBusinessSource($legacy);
    check($clean['filterRelation'], 'any', 'OR unchanged');
    check($clean['groupBy'], ['source_type'], 'technical groups retained');
    check($clean['querySettings']['visibleFields'], ['source_card'], 'nested preferences sanitized');
    check($clean['export']['fields'], ['source_card_no'], 'nested export sanitized');
    check($legacy['querySettings']['visibleFields'], ['source', 'source_card'], 'input not mutated');
    echo json_encode(['status' => 'passed', 'checks' => $checks, 'pages' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
