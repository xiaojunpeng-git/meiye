<?php
declare(strict_types=1);

// Local real-data regression: exercise the entire member projection, not only
// its private service query. Server-enforced READ ONLY forbids financial writes.
$root = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
$memberId = (int)getenv('R54_MEMBER_ID');
if ($memberId <= 0) throw new RuntimeException('Explicit local member fixture required');
require $root . '/vendor/autoload.php';
(new \think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', get_class($error) . ': ' . $error->getMessage() . PHP_EOL); exit(1);
});
$config = config('database.connections.mysql');
if (!in_array($config['hostname'], ['mysql', 'mohe-mysql'], true)) throw new RuntimeException('Local Docker only');
\think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
// A PDO read-only snapshot must not masquerade as a framework financial
// transaction, which would intentionally lock the permission catalog.
$pdo = \think\facade\Db::connect()->getPdo();
$pdo->beginTransaction();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
try {
    $member = \think\facade\Db::name('user')->where('uid', $memberId)->field('uid,belong_store_id')->find();
    $staffId = \think\facade\Db::name('system_store_staff')->where('store_id', (int)$member['belong_store_id'])
        ->where('status', 1)->where('is_del', 0)->where('employee_id', '>', 0)->order('id')->value('id');
    $staff = \app\model\store\SystemStoreStaff::where('id', $staffId)->find()->toArray();
    $staff['roles'] = \app\services\cashier\v3\CashierV3PermissionSnapshotServices::normalizeRoleIds($staff['roles'] ?? []);
    $operator = (new \app\services\cashier\v3\CashierV3ScopeResolver())->operatorScope((int)$staff['store_id'], (int)$staff['id']);
    $scope = app()->make(\app\services\cashier\v3\CashierV3DataScopeFactory::class)
        ->build($operator->storeId(), $operator->operatorId(), $staff, '0', $operator->organizationId());
    $reader = new \app\services\cashier\v3\member\CashierV3MemberDetailQueryServices();
    $query = ['tab' => 'writeoff', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-24'];
    $simulateMissing = getenv('R54_SIMULATE_MISSING_DEPENDENCY') === '1';
    if ($simulateMissing) {
        // Reproduce the deployment omission without deleting canonical source
        // or changing data: fail exactly where Composer would need this file.
        spl_autoload_register(static function (string $class): void {
            if ($class === 'app\\services\\cashier\\v3\\card\\CashierV3CardReadAmountServices') {
                throw new Error('R54_MISSING_CARD_READ_DEPENDENCY');
            }
        }, true, true);
    }
    try { $detail = $reader->read($memberId, $operator, $scope, $query); }
    catch (Error $error) {
        if (!$simulateMissing || $error->getMessage() !== 'R54_MISSING_CARD_READ_DEPENDENCY') throw $error;
        echo json_encode(['result' => 'PASS', 'missingDependencyReproduced' => true,
            'fullProjectionAborted' => true, 'businessWrites' => 0]), PHP_EOL;
        return;
    }
    if ($simulateMissing) throw new RuntimeException('Missing-dependency branch was not exercised');
    $expected = \think\facade\Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')
        ->where('member_id', $memberId)->where('service_status', 'completed')
        ->where('business_date', '>=', $query['dateFrom'])->where('business_date', '<=', $query['dateTo'])->count();
    $check($expected > 0 && $expected <= 100, 'Fixture must have a nonempty bounded service history');
    $check(count($detail['serviceRecords']) === $expected, 'Full projection must retain every matching service');
    $check((int)$detail['member']['memberId'] === $memberId, 'Exact member identity retained');
    $check(class_exists(\app\services\cashier\v3\card\CashierV3CardReadAmountServices::class, false), 'Actual card read dependency was exercised');
    $check(count($detail['cards']) > 0, 'Card projection also completed');
    $check(isset($detail['summary']['principalBalance'], $detail['summary']['giftBalance']), 'Summary is authoritative, not fallback');
    $check($detail['tabStates']['writeoff']['total'] === $expected, 'Tab total matches service facts');
    $repeat = $reader->read($memberId, $operator, $scope, $query);
    $check($repeat['serviceRecords'] === $detail['serviceRecords'], 'Repeat reads are stable');
    $empty = $reader->read($memberId, $operator, $scope, array_replace($query, ['keyword' => 'R54-NO-SUCH-SERVICE-UNIQUE']));
    $check($empty['serviceRecords'] === [], 'A successful no-match query remains a real empty result');
    echo json_encode(['result' => 'PASS', 'checks' => $checks, 'records' => $expected,
        'fullProjection' => true, 'serverReadOnly' => true, 'businessWrites' => 0]), PHP_EOL;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
