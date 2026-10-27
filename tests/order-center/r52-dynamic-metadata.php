<?php
/** 真实订单字段注册与执行器纯合同测试；不启动应用、不读取配置、不连接数据库。 */
$backend = dirname(__DIR__, 2) . '/后端代码';
spl_autoload_register(static function ($class) use ($backend) {
    if (strpos($class, 'app\\') !== 0) return;
    $path = $backend . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) require_once $path;
});

use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\StructuredExpressionValidator;
use app\services\query\StructuredExpressionEvaluator;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract;
use app\services\cashier\v3\order\AbstractCashierV3OrderCenterUnifiedQueryProvider;

$pageCode = $argv[1] ?? 'order_center_all';
$definition = CashierV3OrderCenterUnifiedQueryContract::definition($pageCode);
$registry = new UnifiedQueryPageRegistry();
$registry->registerPage($pageCode, $definition['label'], $definition['fields'], 'record_id', ['keywordFields' => $definition['keywordFields']]);
$registry->freeze();
$fields = $registry->fieldsForCapability($pageCode, []);
$execution = new UnifiedQueryExecutionServices($registry, new StructuredExpressionValidator($registry), new StructuredExpressionEvaluator());
$checks = 0;
// 跳过 provider 构造器和运行服务，仅验证真实投影；不创建任何数据库依赖。
class R52ProjectionProbe extends AbstractCashierV3OrderCenterUnifiedQueryProvider {
    public $testPageCode;
    public function pageCode(): string { return $this->testPageCode; }
    /** 只暴露内存投影供测试，不调用读取或写入服务。 */
    public function projectForTest(string $type, array $records): array { return $this->projectRows($type, $records); }
}
$probe = (new ReflectionClass(R52ProjectionProbe::class))->newInstanceWithoutConstructor();
$probe->testPageCode = $pageCode;
if (in_array('source', array_column($fields, 'key'), true)) {
    foreach ([null, '', '  ', '—', 'D线上引流 / 抖音'] as $source) {
        $projected = $probe->projectForTest($definition['type'] === 'all' ? 'recharge' : $definition['type'], [['id' => 'fixture', 'source' => $source]]);
        $expected = $source === 'D线上引流 / 抖音' ? $source : null;
        if ($projected[0]['source'] !== $expected) throw new RuntimeException('空来源投影不一致：' . $pageCode);
        $checks++;
    }
}
foreach ($fields as $field) {
    if (!$field['capabilities']['filter']) continue;
    $type = $field['type'];
    $value = in_array($type, ['amount', 'integer', 'decimal'], true) ? 200 : ($type === 'date' ? '2026-10-24' : ($type === 'datetime' ? '2026-10-24 10:30:22' : '目标内容'));
    $other = in_array($type, ['amount', 'integer', 'decimal'], true) ? 100 : ($type === 'date' ? '2026-10-23' : ($type === 'datetime' ? '2026-10-23 10:30:22' : '其他内容'));
    $operator = in_array($type, ['amount', 'integer', 'decimal'], true) ? 'gte' : ($type === 'text' ? 'contains' : 'eq');
    $result = $execution->execute($pageCode, [
        ['record_id' => 'fixture:yes', $field['key'] => $value],
        ['record_id' => 'fixture:no', $field['key'] => $other],
    ], [], ['topFilters' => [['field' => $field['key'], 'operator' => $operator, 'value' => $value]]], ['query_cutoff_date' => '2026-10-24'], static fn() => true);
    if (array_column($result['rows'], 'record_id') !== ['fixture:yes']) throw new RuntimeException('动态字段未生效：' . $field['key']);
    $checks++;
}
echo json_encode(['status' => 'passed', 'pageCode' => $pageCode, 'pageName' => $definition['label'], 'checks' => $checks, 'fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
