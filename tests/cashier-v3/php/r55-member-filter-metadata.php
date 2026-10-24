<?php
/** Real member registrar/executor contract; no application config or database. */
$backend = dirname(__DIR__, 3) . '/后端代码';
spl_autoload_register(static function ($class) use ($backend) {
    if (strpos($class, 'app\\') !== 0) return;
    $file = $backend . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require_once $file;
});
$registry = new \app\services\query\UnifiedQueryPageRegistry();
(new \app\services\query\provider\MemberUnifiedQueryPageRegistrar())->register($registry);
$registry->freeze();
$fields = $registry->fieldsForCapability('member_list', []);
$executor = new \app\services\query\UnifiedQueryExecutionServices($registry,
    new \app\services\query\StructuredExpressionValidator($registry), new \app\services\query\StructuredExpressionEvaluator());
$checks = 0;
foreach ($fields as $field) {
    if (!$field['capabilities']['filter']) continue;
    $type = $field['type'];
    $number = in_array($type, ['amount', 'integer', 'decimal'], true);
    $yes = $number ? 200 : ($type === 'date' ? '2026-10-24' : ($type === 'datetime' ? '2026-10-24 12:30:22' : 'R55_TARGET'));
    $no = $number ? 100 : ($type === 'date' ? '2026-10-23' : ($type === 'datetime' ? '2026-10-23 12:30:22' : 'R55_OTHER'));
    $query = ['topFilters' => [['field' => $field['key'], 'operator' => $number ? 'gte' : ($type === 'text' ? 'contains' : 'eq'), 'value' => $yes]]];
    $result = $executor->execute('member_list', [['member_id' => 1, $field['key'] => $yes], ['member_id' => 2, $field['key'] => $no]], [], $query, ['query_cutoff_date' => '2026-10-24'], static fn() => true);
    if (array_column($result['rows'], 'member_id') !== [1]) throw new RuntimeException('Member filter not effective: ' . $field['key']);
    $checks++;
}
$rows = []; for ($i = 1; $i <= 45; $i++) $rows[] = ['member_id' => $i, 'member_name' => 'R55_TARGET_' . $i, 'account_balance' => $i, 'store' => $i === 45 ? 'other' : 'allowed'];
$query = ['page' => 2, 'limit' => 20, 'topFilters' => [['field' => 'account_balance', 'operator' => 'gte', 'value' => 0]]];
$result = $executor->execute('member_list', $rows, [], $query, ['query_cutoff_date' => '2026-10-24'], static fn($row) => $row['store'] === 'allowed');
if ((int)$result['pagination']['total'] !== 44 || count($result['rows']) !== 20) throw new RuntimeException('Pagination/scope mismatch');
$checks++;
$export = $executor->execute('member_list', $rows, [], $query + ['export' => ['scope' => 'query', 'fields' => ['member_name']]], ['query_cutoff_date' => '2026-10-24'], static fn($row) => $row['store'] === 'allowed');
if (count($export['exportRows']) !== 44 || (int)$export['pagination']['total'] !== 44) throw new RuntimeException('Export/scope mismatch');
$checks++;
try {
    $executor->execute('member_list', $rows, [], ['topFilters' => [['field' => 'unregistered_secret', 'operator' => 'eq', 'value' => 1]]], ['query_cutoff_date' => '2026-10-24'], static fn() => true);
    throw new RuntimeException('Unknown filter was accepted');
} catch (\app\services\query\UnifiedQueryException $error) { $checks++; }
echo json_encode(['result' => 'PASS', 'checks' => $checks, 'fields' => $fields, 'databaseWrites' => 0], JSON_UNESCAPED_UNICODE), PHP_EOL;
