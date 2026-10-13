<?php

declare(strict_types=1);

// Read-only local regression. It checks the existing TK fact chain without
// creating, voiding or editing a business record.
error_reporting(E_ALL & ~E_DEPRECATED);
$database = trim((string)getenv('ROUND3_TEST_DATABASE'));
$password = (string)getenv('ROUND3_TEST_PASSWORD');
if ($database === '' || $password === '') {
    fwrite(STDERR, "Set ROUND3_TEST_DATABASE and ROUND3_TEST_PASSWORD for the local MySQL probe.\n");
    exit(2);
}
if (!function_exists('swoole_cpu_num')) {
    function swoole_cpu_num(): int { return 1; }
}
if (!class_exists('Swoole\\Table')) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}
$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
final class RefundVisibilityLocalApp extends \think\App
{
    protected $initializers = [\think\initializer\RegisterService::class, \think\initializer\BootService::class];
}
$app = new RefundVisibilityLocalApp($backend . '/');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => '127.0.0.1', 'DATABASE_HOSTNAME' => '127.0.0.1',
    'database.hostport' => '3307', 'DATABASE_HOSTPORT' => '3307',
    'database.database' => $database, 'DATABASE_DATABASE' => $database,
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => $password, 'DATABASE_PASSWORD' => $password,
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'refund_visibility_local_mysql_no_dotenv');
$app->initialize();
\think\facade\Config::set(['default' => 'file'], 'cache');
if ((string)\think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] !== $database) {
    throw new RuntimeException('Local database identity mismatch.');
}
function refundVisibilityCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

$service = new \app\services\report\StoreUnifiedReportServices();
$range = ['start_date' => '2026-10-13', 'end_date' => '2026-10-13'];
$member = $service->query(23, $range + ['report' => 'member_consumption_detail', 'page' => 1, 'page_size' => 20]);
$matching = array_values(array_filter($member['records'], static fn(array $row): bool =>
    (string)($row['order_no_snapshot'] ?? '') === 'TK2610130001'));
refundVisibilityCheck(count($matching) === 1 && $matching[0]['consume_type'] === '退款'
    && $matching[0]['payment_refund_adjustment'] === '-333'
    && $matching[0]['receipt_total'] === '-333', 'TK refund appears once as a negative member-consumption row');
refundVisibilityCheck((string)$matching[0]['original_sale_no'] === 'XS26101100001'
    && (string)$matching[0]['partner_performance'] === '0',
    'refund traces the original sale without inventing a partner share');
refundVisibilityCheck($member['summary_row']['payment_refund_adjustment'] === '-333'
    && (string)$matching[0]['category'] !== '' && (string)$matching[0]['salesperson_names'] !== '',
    'refund summary, original product category and active salespeople remain traceable');
$all = $service->query(23, $range + ['report' => 'member_consumption_detail', '_internal_all' => true]);
refundVisibilityCheck((int)$all['total'] === count($all['records'])
    && count(array_filter($all['records'], static fn(array $row): bool =>
        (string)($row['order_no_snapshot'] ?? '') === 'TK2610130001')) === 1,
    'same filtered member population powers complete export and total');
$cardOnly = $service->query(23, $range + ['report' => 'member_consumption_detail', 'product_type' => 'card']);
refundVisibilityCheck(in_array('TK2610130001', array_column($cardOnly['records'], 'order_no_snapshot'), true),
    'product-type filter retains the linked card refund');
$otherPartner = $service->query(23, $range + ['report' => 'member_consumption_detail',
    'partner_name' => '__nonexistent_partner__']);
refundVisibilityCheck((int)$otherPartner['total'] === 0, 'partner filter narrows both sale and refund sources');
$otherStore = $service->query(126, $range + ['report' => 'member_consumption_detail']);
refundVisibilityCheck(!in_array('TK2610130001', array_column($otherStore['records'], 'order_no_snapshot'), true),
    'another store cannot read the TK refund');
$historical = $service->query(145, ['start_date' => '2026-10-08', 'end_date' => '2026-10-08',
    'report' => 'member_consumption_detail', '_internal_all' => true]);
$unlinked = array_values(array_filter($historical['records'], static fn(array $row): bool =>
    (string)($row['order_no_snapshot'] ?? '') === 'TK2610100001'));
refundVisibilityCheck(count($unlinked) === 1 && (float)$unlinked[0]['receipt_total'] < 0
    && $unlinked[0]['partner_performance'] === '' && $unlinked[0]['actual_cash_performance'] === '',
    'historical refund without original sale shows cash but no invented partner split');

$period = ['start_date' => '2026-08-10', 'end_date' => '2026-10-13', 'report' => 'member_consumption_detail'];
$first = $service->query([23, 22], $period + ['page' => 1]);
$complete = $service->query([23, 22], $period + ['_internal_all' => true]);
refundVisibilityCheck((int)$first['total'] === count($complete['records'])
    && count($first['records']) === min(20, (int)$first['total']),
    'combined sales and refunds are counted before the default twenty-row page');
refundVisibilityCheck((int)$first['total'] > 20, 'read-only fixture exceeds the default page size');
$second = $service->query([23, 22], $period + ['page' => 2]);
$firstKeys = array_map(static fn(array $row): string => (string)($row['_market_group_key'] ?? $row['source_line_id'] ?? $row['order_id']), $first['records']);
$secondKeys = array_map(static fn(array $row): string => (string)($row['_market_group_key'] ?? $row['source_line_id'] ?? $row['order_id']), $second['records']);
refundVisibilityCheck(count($second['records']) === min(20, (int)$first['total'] - 20)
    && array_intersect($firstKeys, $secondKeys) === [],
    'second page keeps the same filters and does not repeat the first page');

$sales = $service->query(23, $range + ['report' => 'store_salesperson_performance']);
$deductions = [1386 => '-113', 1391 => '-109', 1425 => '-111'];
foreach ($deductions as $employeeId => $expected) {
    $person = array_values(array_filter($sales['records'], static fn(array $row): bool =>
        (int)($row['employee_id'] ?? 0) === $employeeId));
    refundVisibilityCheck(count($person) === 1 && $person[0]['day_13_performance'] === $expected,
        "employee {$employeeId} reflects own TK refund on October 13");
    $detail = $service->query(23, $range + ['report' => 'store_salesperson_performance_detail',
        'salesperson_id' => $employeeId, 'day_of_month' => 13]);
    refundVisibilityCheck(count(array_filter($detail['records'], static fn(array $row): bool =>
        (string)($row['document_no'] ?? '') === 'TK2610130001')) === 1,
        "employee {$employeeId} drilldown identifies original TK document");
    refundVisibilityCheck($detail['summary_row']['performance_amount'] === $expected,
        "employee {$employeeId} detail and matrix have the same net amount");
}

foreach (['partner_item_detail', 'partner_item_summary'] as $code) {
    $partner = $service->query(23, $range + ['report' => $code, '_internal_all' => true]);
    refundVisibilityCheck(!in_array('TK2610130001', array_column($partner['records'], 'order_no_snapshot'), true),
        "non-partner TK refund stays out of {$code}");
}
