<?php
/** R51真实Runtime注册与本地空范围SQL联调；不改业务数据，不以空结果代替金额业务验证。 */
$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root . '/vendor/autoload.php';
(new \think\App())->initialize();
use app\services\report\BasicReportHostServices as Host;
use app\services\report\BasicReportCatalogServices as Catalog;
use app\services\report\BasicBusinessReportServices as Business;
use app\services\report\query\BasicBusinessQueryProvider as Provider;
use app\services\query\UnifiedQueryRuntime;
use think\facade\Db;
$config = config('database.connections.mysql');
if (!in_array($config['hostname'], ['mohe-mysql', 'mysql'], true)) throw new RuntimeException('Local-only test');
set_exception_handler(static function ($e) { file_put_contents('php://stderr', (string)$e . PHP_EOL); exit(1); });
$checks = 0;
$check = static function ($ok, $label) use (&$checks) { ++$checks; if (!$ok) throw new RuntimeException($label); };
$context = Host::context([999999999], 'platform_admin', 999901, 'platform', false);
$check($context['basic_scope_mode'] === 'stores', 'Platform authenticated mode narrows to explicit stores');
$runtime = UnifiedQueryRuntime::runtime();
$reports = Catalog::entries();
$results = [];
foreach ($reports as $report) {
    $code = $report['code'];
    $check(in_array($code, $runtime['providers']->pageCodes(), true), 'Registered provider: ' . $code);
    if ($code === 'basic_brush_registration') continue;
    $fields = Business::fields($code);
    $check(count(array_column($fields, 'key')) === count(array_unique(array_column($fields, 'key'))), 'Unique field keys: ' . $code);
    $check(in_array('row_id', array_column($fields, 'key'), true), 'Stable row key: ' . $code);
    try {
        // 实时表始终使用服务端当天；周期夹具保持固定，跨午夜重跑不能被误判成历史实时请求。
        $cutoff = $report['time_mode'] === 'current' ? $context['query_cutoff_date'] : '2026-10-23';
        $result = Host::query($context, ['pageCode' => $code, 'start_date' => '2026-10-01',
            'end_date' => $cutoff, 'as_of_date' => $cutoff]);
        $check(isset($result['pagination']['total'], $result['queryFields'], $result['field_sources']), 'Shared result contract: ' . $code);
        $check($result['pagination']['total'] === 0 && count($result['rows']) === 0, 'No foreign-store rows: ' . $code);
        $results[] = ['code' => $code, 'query' => 'pass', 'rows' => 0];
    } catch (Throwable $e) {
        $results[] = ['code' => $code, 'query' => 'fail', 'exception' => get_class($e), 'message' => $e->getMessage()];
    }
}
foreach ([['start_date' => '2026-02-30'], ['start_date' => '2026-10-24'], ['end_date' => '2026-10-25'], ['start_date' => '2024-01-01']] as $bad) {
    try { Provider::dates($bad, '2026-10-23'); throw new RuntimeException('Invalid date allowed'); }
    catch (InvalidArgumentException $e) { $check(true, 'Strict date and bounded-range validation'); }
}
// 当天实时范围由产品明确确认；接口同样拒绝隐藏控件绕过后的历史请求。
foreach (['basic_unconsumed_as_of_detail', 'basic_customer_entitlements'] as $code) {
    try {
        Business::read($code, [999999999], ['as_of_date' => '2026-10-01'], $context);
        throw new RuntimeException('Historical request accepted by live report');
    } catch (InvalidArgumentException $e) {
        $check(strpos($e->getMessage(), '当天') !== false, 'Live assets reject historical cutoff: ' . $code);
    }
}
$failures = array_values(array_filter($results, static function ($row) { return $row['query'] !== 'pass'; }));
echo json_encode(['pass' => !$failures, 'checks' => $checks, 'reports' => $results,
    'limitation' => 'Real Runtime/SQL empty-scope integration only; monetary fixtures are verified in each domain test.',
    'persistent_business_writes' => false], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
exit($failures ? 1 : 0);
