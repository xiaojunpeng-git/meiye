<?php
declare(strict_types=1);

/** 覆盖真实 HTTP 入参白名单后再调用报表，避免仅服务直调通过而漏掉接口吞参；所有数据操作限制在只读事务。 */
if (getenv('R13_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit read-only test flag required');
$root = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $root . '/vendor/autoload.php';
$app = new think\App($root . '/');
$app->initialize();
use think\facade\Db;

$checks = 0;
function inputCheck(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
$failure = null;
try {
    $sample = Db::name('cashier_v3_payment_fact')->where('order_no_snapshot', 'BJ26100200015')->where('status', 'effective')->find();
    inputCheck((bool)$sample, 'sample exists');
    $service = new app\services\report\StoreUnifiedReportServices();
    $query = ['report' => 'cross_industry_customer_detail', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        'member_id' => (int)$sample['member_id'], 'include_followup_cash' => 1, 'store_ids' => (string)$sample['store_id']];
    foreach ([app\controller\cashier\v3\Report::class, app\controller\admin\v1\report\UnifiedReport::class] as $class) {
        // 不构造登录态或绕过线上会话；这里只验证控制器的静态入参契约，门店范围由测试显式限制为样本门店。
        $reflection = new ReflectionClass($class);
        $rules = $reflection->getMethod('inputRules');
        $rules->setAccessible(true);
        $definitions = $rules->invoke($reflection->newInstanceWithoutConstructor());
        $request = new app\Request();
        $request->withGet($query);
        $parsed = $request->getMore($definitions);
        inputCheck((int)($parsed['member_id'] ?? 0) === (int)$query['member_id'], $class . ' preserves member');
        inputCheck((int)($parsed['include_followup_cash'] ?? 0) === 1, $class . ' preserves followup');
        $parsed['_report_scope'] = ['mode' => 'stores'];
        $actual = $service->query([(int)$sample['store_id']], $parsed);
        inputCheck(count($actual['records']) === 1, $class . ' has only the selected repayment');
        inputCheck($actual['records'][0]['document_no'] === 'BJ26100200015', $class . ' selected document');
        inputCheck((string)$actual['summary_row']['full_payment'] === '1200', $class . ' summary equals selected value');
        $export = $service->export([(int)$sample['store_id']], $parsed);
        inputCheck($export['records'] === $actual['records'] && $export['summary_row'] === $actual['summary_row'], $class . ' export same scope');
        $defaultRequest = new app\Request();
        $defaultRequest->withGet([]);
        $defaults = $defaultRequest->getMore($definitions);
        inputCheck((int)$defaults['member_id'] === 0 && (int)$defaults['include_followup_cash'] === 0, $class . ' defaults do not expand source');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    Db::rollback();
}
if ($failure) {
    fwrite(STDERR, 'FAIL ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo json_encode(['checks' => $checks, 'writes' => 0, 'result' => 'PASS']) . PHP_EOL;
